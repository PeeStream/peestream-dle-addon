<?php
/*
=============================================================================
PeeStream Video Player & Tools - DataLife Engine Addon
Module: Hardened Auto-Publish Webhook Receiver (Series & Movies)
File: engine/ajax/peestream_webhook.php
Version: 1.0.4
=============================================================================
*/

@error_reporting( 0 );
@ini_set( 'display_errors', false );
@ini_set( 'html_errors', false );

define( 'DATALIFEENGINE', true );
define( 'ROOT_DIR', substr( dirname( __FILE__ ), 0, -12 ) );
define( 'ENGINE_DIR', ROOT_DIR . '/engine' );

header( 'Content-Type: application/json; charset=utf-8' );

// 1. Enforce POST method only
if ( isset( $_SERVER['REQUEST_METHOD'] ) && strtoupper( $_SERVER['REQUEST_METHOD'] ) !== 'POST' ) {
	http_response_code( 405 );
	echo json_encode( array( 'success' => false, 'error' => 'Method Not Allowed. Webhook only accepts POST requests.' ) );
	exit;
}

// 2. Load DLE configuration & Database engine
require_once ( ENGINE_DIR . '/data/dbconfig.php' );
require_once ( ENGINE_DIR . '/classes/mysql.php' );
require_once ( ENGINE_DIR . '/data/config.php' );
require_once ( ENGINE_DIR . '/inc/peestream_autopublish.php' );

if ( file_exists( ENGINE_DIR . '/classes/plugins.class.php' ) ) {
	include_once ENGINE_DIR . '/classes/plugins.class.php';
}

$inc_func = ENGINE_DIR . '/inc/include/functions.inc.php';
if ( file_exists( $inc_func ) ) {
	require_once ( $inc_func );
}

// 3. Load PeeStream configuration
$conf_file = ENGINE_DIR . '/data/peestream.conf.php';
if ( file_exists( $conf_file ) ) {
	require_once ( $conf_file );
} else {
	$peestream_config = array();
}

$cfg = array_merge( array(
	'player_domain'     => 'https://peeplayer.com',
	'xfield_name'       => 'peestream',
	'xfield_imdb'       => 'imdb',
	'xfield_tmdb'       => 'tmdb',
	'xfield_tmdb_series'=> 'tmdb_series_id',
	'xfield_season'     => 'season',
	'xfield_episode'    => 'episode',
	'xfield_player'     => '',
	'xfield_source_code'=> 'peestream_code',
	'webhook_secret'    => '',
	'auto_create_news'  => '0',
	'default_category'  => '1',
	'moderate_new_posts'=> '1',
	'site_id'           => '',
), isset( $peestream_config ) ? $peestream_config : array() );

// 4. Strict Security: Reject if webhook secret is not configured or too weak (< 16 chars)
$configured_secret = trim( (string)$cfg['webhook_secret'] );
if ( empty( $configured_secret ) || strlen( $configured_secret ) < 16 ) {
	http_response_code( 403 );
	echo json_encode( array(
		'success' => false,
		'error'   => 'Webhook rejected. Secure secret key is not configured or does not meet minimum strength requirements (at least 16 characters).'
	) );
	exit;
}

// 5. Authenticate the exact raw request bytes. Query-string secrets are never accepted.
$raw_input = file_get_contents( 'php://input' );
$signature = isset( $_SERVER['HTTP_X_PEESTREAM_SIGNATURE'] ) ? trim( (string)$_SERVER['HTTP_X_PEESTREAM_SIGNATURE'] ) : '';
if ( stripos( $signature, 'sha256=' ) === 0 ) {
	$signature = substr( $signature, 7 );
}
$expected_signature = hash_hmac( 'sha256', $raw_input, $configured_secret );
if ( empty( $signature ) || ! hash_equals( $expected_signature, $signature ) ) {
	http_response_code( 401 );
	echo json_encode( array( 'success' => false, 'error' => 'Invalid webhook signature.' ) );
	exit;
}

// 6. Rate Limiting & Replay Protection Table
$client_ip = '';
if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
	$client_ip = trim( $_SERVER['HTTP_CF_CONNECTING_IP'] );
} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
	$ips = explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] );
	$client_ip = trim( $ips[0] );
} else {
	$client_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( $_SERVER['REMOTE_ADDR'] ) : '0.0.0.0';
}
$client_ip = substr( preg_replace( '/[^0-9a-fA-F:.]/', '', $client_ip ), 0, 45 );

// Reliable affected rows reader across MySQL drivers (fail-closed if unknown)
if ( ! function_exists( 'peestream_get_affected_rows' ) ) {
	function peestream_get_affected_rows( $db ) {
		if ( method_exists( $db, 'get_affected_rows' ) ) {
			return (int) $db->get_affected_rows();
		}
		if ( isset( $db->db_id ) ) {
			if ( is_object( $db->db_id ) && isset( $db->db_id->affected_rows ) ) {
				return (int) $db->db_id->affected_rows;
			}
			if ( is_resource( $db->db_id ) || is_object( $db->db_id ) ) {
				return (int) @mysqli_affected_rows( $db->db_id );
			}
		}
		return 0; // Fail closed if affected rows cannot be verified
	}
}

// 6. Rate Limiting & Replay Protection Table
$audit_table = PREFIX . "_peestream_webhooks";
$db->query( "CREATE TABLE IF NOT EXISTS {$audit_table} (
	id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
	file_code VARCHAR(64) NOT NULL,
	event VARCHAR(32) NOT NULL DEFAULT 'video_ready',
	post_id INT UNSIGNED NOT NULL DEFAULT 0,
	ip VARCHAR(45) NOT NULL DEFAULT '',
	received_at DATETIME NOT NULL,
	status VARCHAR(32) NOT NULL DEFAULT 'processed',
	UNIQUE KEY uq_fc_event (file_code, event),
	INDEX idx_ip_time (ip, received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", false );

// Rate Limiting: Max 60 requests per minute per IP
$safe_ip = $db->safesql( $client_ip );
$rate_check = $db->super_query( "SELECT COUNT(*) as cnt FROM {$audit_table} WHERE ip = '{$safe_ip}' AND received_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)" );
if ( $rate_check && intval( $rate_check['cnt'] ) > 60 ) {
	http_response_code( 429 );
	echo json_encode( array( 'success' => false, 'error' => 'Too Many Requests. Rate limit exceeded (max 60 req/min).' ) );
	exit;
}

// 7. Parse & Validate Payload
if ( empty( $raw_input ) ) {
	http_response_code( 400 );
	echo json_encode( array( 'success' => false, 'error' => 'Empty request payload.' ) );
	exit;
}

$data = json_decode( $raw_input, true );
if ( ! is_array( $data ) ) {
	http_response_code( 400 );
	echo json_encode( array( 'success' => false, 'error' => 'Malformed JSON payload.' ) );
	exit;
}

$event = isset( $data['event'] ) ? substr( trim( (string)$data['event'] ), 0, 32 ) : 'video_ready';
if ( 'site.test' === $event ) {
	echo json_encode( array( 'status' => 'ok' ) );
	exit;
}
if ( ! in_array( $event, array( 'media.ready', 'video_ready', 'new_episode_ready', 'movie_ready' ), true ) ) {
	echo json_encode( array( 'success' => true ) );
	exit;
}

$configured_site_id = trim( (string)$cfg['site_id'] );
$incoming_site_id = isset( $data['site_id'] ) ? trim( (string)$data['site_id'] ) : '';
if ( 'media.ready' === $event && ( '' === $configured_site_id || '' === $incoming_site_id || ! hash_equals( $configured_site_id, $incoming_site_id ) ) ) {
	http_response_code( 403 );
	echo json_encode( array( 'success' => false, 'error' => 'Invalid site_id.' ) );
	exit;
}
if ( 'media.ready' === $event ) {
	$event_id = isset( $data['event_id'] ) ? trim( (string)$data['event_id'] ) : '';
	if ( '' === $event_id || strlen( $event_id ) > 191 ) {
		http_response_code( 400 );
		echo json_encode( array( 'success' => false, 'error' => 'Missing or invalid event_id.' ) );
		exit;
	}
	$occurred_at = isset( $data['occurred_at'] ) ? strtotime( (string)$data['occurred_at'] ) : false;
	if ( false !== $occurred_at && $occurred_at < time() - ( 72 * 3600 ) ) {
		echo json_encode( array( 'success' => true, 'action' => 'expired_ignored' ) );
		exit;
	}
	$event_table = PREFIX . '_peestream_event_ids';
	$db->query( "CREATE TABLE IF NOT EXISTS {$event_table} (event_id VARCHAR(191) NOT NULL PRIMARY KEY, received_at DATETIME NOT NULL, KEY idx_received_at (received_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", false );
	$safe_event_id = $db->safesql( $event_id );
	$reserved_event = $db->query( "INSERT IGNORE INTO {$event_table} (event_id, received_at) VALUES ('{$safe_event_id}', NOW())", false );
	if ( ! $reserved_event || 0 === peestream_get_affected_rows( $db ) ) {
		$dupe = $db->super_query( "SELECT event_id FROM {$event_table} WHERE event_id = '{$safe_event_id}' LIMIT 1" );
		if ( ! empty( $dupe['event_id'] ) ) {
			echo json_encode( array( 'success' => true, 'action' => 'duplicate_ignored' ) );
			exit;
		}
		http_response_code( 500 );
		echo json_encode( array( 'success' => false, 'error' => 'Could not reserve event_id.' ) );
		exit;
	}
}

// Extract & Validate file_code (PeeStream sends file_code)
$file_code = '';
if ( ! empty( $data['file_code'] ) ) {
	$file_code = trim( (string)$data['file_code'] );
} elseif ( ! empty( $data['filecode'] ) ) {
	$file_code = trim( (string)$data['filecode'] );
}

if ( empty( $file_code ) || ! preg_match( '/^[a-zA-Z0-9_\-]+$/', $file_code ) || strlen( $file_code ) > 64 ) {
	http_response_code( 400 );
	echo json_encode( array( 'success' => false, 'error' => 'Invalid or missing file_code parameter.' ) );
	exit;
}


$identity = peestream_autopublish_parse_identity( $data );
if ( false === $identity ) {
	http_response_code( 400 );
	echo json_encode( array( 'success' => false, 'error' => 'Invalid content identity or season/episode values.' ) );
	exit;
}
$imdb_id = $identity['imdb_id'];
$tmdb_id = $identity['tmdb_id'];
$kind = $identity['kind'];
$season = $identity['season'];
$episode = $identity['episode'];
$title = isset( $data['title'] ) ? trim( strip_tags( (string)$data['title'] ) ) : 'Video';
$poster_url = '';
if ( ! empty( $data['poster'] ) && preg_match( '#^https?://#i', $data['poster'] ) ) {
	$poster_url = trim( (string)$data['poster'] );
} elseif ( ! empty( $data['poster_url'] ) && preg_match( '#^https?://#i', $data['poster_url'] ) ) {
	$poster_url = trim( (string)$data['poster_url'] );
}

// 8. Atomic Reservation & Idempotency Check (Prevents TOCTOU Race Conditions)
$safe_fc = $db->safesql( $file_code );
$event_key = 'media.ready' === $event ? peestream_autopublish_event_key( $event_id ) : $event;
$safe_event = $db->safesql( $event_key );
$now_date = date( 'Y-m-d H:i:s' );

// Attempt atomic reservation before modifying any post content
$reserved = $db->query( "INSERT INTO {$audit_table} (file_code, event, post_id, ip, received_at, status) 
			VALUES ('{$safe_fc}', '{$safe_event}', 0, '{$safe_ip}', '{$now_date}', 'processing')", false );

$has_lock = false;
if ( $reserved ) {
	$has_lock = true;
} else {
	// Key already exists or DB error - inspect current state
	$existing = $db->super_query( "SELECT id, post_id, status, received_at FROM {$audit_table} WHERE file_code = '{$safe_fc}' AND event = '{$safe_event}' LIMIT 1" );
	if ( $existing ) {
		if ( $existing['status'] === 'processed' ) {
			echo json_encode( array(
				'success'   => true,
				'action'    => 'replay_ignored',
				'post_id'   => intval( $existing['post_id'] ),
				'file_code' => $file_code,
				'msg'       => 'Duplicate webhook event received. Video has already been processed.'
			) );
			exit;
		} elseif ( $existing['status'] === 'processing' ) {
			$diff = time() - strtotime( $existing['received_at'] );
			if ( $diff < 60 ) {
				echo json_encode( array(
					'success'   => true,
					'action'    => 'concurrent_ignored',
					'file_code' => $file_code,
					'msg'       => 'Webhook event is currently being processed concurrently. Replay ignored.'
				) );
				exit;
			}
			// Timed out processing lock (>60s) - take over reservation with atomic CAS
			$safe_id = intval( $existing['id'] );
			$safe_prev_time = $db->safesql( $existing['received_at'] );
			$db->query( "UPDATE {$audit_table} SET ip = '{$safe_ip}', received_at = '{$now_date}', status = 'processing' WHERE id = '{$safe_id}' AND status = 'processing' AND received_at = '{$safe_prev_time}'", false );
			$aff = peestream_get_affected_rows( $db );
			if ( $aff > 0 ) {
				$has_lock = true;
			} else {
				echo json_encode( array(
					'success'   => true,
					'action'    => 'concurrent_ignored',
					'file_code' => $file_code,
					'msg'       => 'Webhook event takeover failed: concurrent worker won race. Replay ignored.'
				) );
				exit;
			}
		} elseif ( in_array( $existing['status'], array( 'failed', 'ambiguous', 'ignored_no_match' ), true ) ) {
			// Controlled retry for failed, ambiguous, or ignored_no_match events with atomic CAS
			$safe_id = intval( $existing['id'] );
			$safe_prev_time = $db->safesql( $existing['received_at'] );
			$safe_prev_status = $db->safesql( $existing['status'] );
			$db->query( "UPDATE {$audit_table} SET ip = '{$safe_ip}', received_at = '{$now_date}', status = 'processing' WHERE id = '{$safe_id}' AND status = '{$safe_prev_status}' AND received_at = '{$safe_prev_time}'", false );
			$aff = peestream_get_affected_rows( $db );
			if ( $aff > 0 ) {
				$has_lock = true;
			} else {
				echo json_encode( array(
					'success'   => true,
					'action'    => 'concurrent_ignored',
					'file_code' => $file_code,
					'msg'       => 'Webhook retry takeover failed: concurrent worker won race. Replay ignored.'
				) );
				exit;
			}
		}
	}
}

if ( ! $has_lock ) {
	if ( 'media.ready' === $event ) peestream_autopublish_release_event( $db, $event_table, $event_id );
	http_response_code( 500 );
	echo json_encode( array(
		'success' => false,
		'error'   => 'Could not acquire lock or reservation for webhook event. Processing aborted.'
	) );
	exit;
}

$xf_name = ! empty( $cfg['xfield_name'] ) ? $cfg['xfield_name'] : 'peestream';
$table = PREFIX . '_post';

// 9. Strictly match configured identifiers; never match by title.
$matched_post = null;
	$xf_imdb = ! empty( $cfg['xfield_imdb'] ) ? strtolower( trim( (string)$cfg['xfield_imdb'] ) ) : 'imdb';
	$xf_tmdb = ! empty( $cfg['xfield_tmdb'] ) ? strtolower( trim( (string)$cfg['xfield_tmdb'] ) ) : 'tmdb';
	$xf_tmdb_series = ! empty( $cfg['xfield_tmdb_series'] ) ? strtolower( trim( (string)$cfg['xfield_tmdb_series'] ) ) : 'tmdb_series_id';
	$xf_season = ! empty( $cfg['xfield_season'] ) ? strtolower( trim( (string)$cfg['xfield_season'] ) ) : 'season';
	$xf_episode = ! empty( $cfg['xfield_episode'] ) ? strtolower( trim( (string)$cfg['xfield_episode'] ) ) : 'episode';
	$is_episode = $identity['is_episode'];
	$identity_values = array();
	if ( $is_episode && '' !== $tmdb_id ) $identity_values[] = $tmdb_id;
	if ( ! $is_episode && '' !== $imdb_id ) $identity_values[] = $imdb_id;
	if ( ! $is_episode && '' !== $tmdb_id ) $identity_values[] = $tmdb_id;
if ( ! empty( $identity_values ) ) {
	$like_clauses = array();
	foreach ( $identity_values as $identity_value ) $like_clauses[] = "xfields LIKE '%" . $db->safesql( $identity_value ) . "%'";
	$cand_query = $db->query( "SELECT id, title, xfields, short_story, full_story, category FROM {$table} WHERE (" . implode( ' OR ', $like_clauses ) . ") ORDER BY id DESC LIMIT 500" );
	
	$strictly_matched_posts = array();
		while( $cand_row = $db->get_row( $cand_query ) ) {
		$xf_arr = function_exists( 'xfieldsdataload' ) ? xfieldsdataload( $cand_row['xfields'] ) : array();
		if ( empty( $xf_arr ) || ! is_array( $xf_arr ) ) {
			$parts = explode( '||', (string)$cand_row['xfields'] );
			$candidate_fields = array();
			foreach ( $parts as $p ) {
				$pair = explode( '|', $p );
				if ( isset( $pair[0], $pair[1] ) ) $candidate_fields[strtolower( trim( $pair[0] ) )] = trim( $pair[1] );
			}
			$xf_arr = $candidate_fields;
		}
		$found_exact = peestream_autopublish_identity_matches( $xf_arr, $data, $cfg );
		
		if ( $found_exact ) {
			$strictly_matched_posts[] = $cand_row;
		}
	}
	
	if ( count( $strictly_matched_posts ) === 1 ) {
		$matched_post = $strictly_matched_posts[0];
	} elseif ( count( $strictly_matched_posts ) > 1 ) {
		// Ambiguous match: multiple posts share this IMDb ID. Do NOT update randomly!
		peestream_autopublish_release_event( $db, $event_table, $event_id );
		$db->query( "UPDATE {$audit_table} SET status = 'ambiguous' WHERE file_code = '{$safe_fc}' AND event = '{$safe_event}'" );
		http_response_code( 409 );
		echo json_encode( array(
			'success' => false,
			'error'   => sprintf( 'Ambiguous match: %d posts matched IMDb ID %s. Manual review required.', count( $strictly_matched_posts ), htmlspecialchars( $imdb_id, ENT_QUOTES, 'UTF-8' ) ),
			'action'  => 'ambiguous_match_manual_review'
		) );
		exit;
	}
}

// 10. Update Verified Matching Post
if ( ! empty( $matched_post['id'] ) ) {
	$post_id = intval( $matched_post['id'] );
	$upd_xf = $matched_post['xfields'];

	if ( function_exists( 'xfieldsdataload' ) && function_exists( 'xfieldsdatasave' ) ) {
		$xf_arr = peestream_autopublish_map_xfields( xfieldsdataload( $upd_xf ), $data, array_merge( $cfg, array( 'xfield_name' => $xf_name ) ) );
		if ( $is_episode ) {
			$ep_key = sprintf( 's%02de%02d', $season, $episode );
			$xf_arr[$xf_name . '_' . $ep_key] = $file_code;
		}
		$upd_xf = xfieldsdatasave( $xf_arr );
	} else {
		$upd_xf = preg_replace( '#' . preg_quote( $xf_name ) . '\|[^\|]*#', $xf_name . '|' . $file_code, $upd_xf );
	}

	$safe_xf = $db->safesql( $upd_xf );
	$upd_res = $db->query( "UPDATE {$table} SET xfields = '{$safe_xf}', date = '{$now_date}' WHERE id = '{$post_id}'", false );

	if ( ! $upd_res ) {
		if ( 'media.ready' === $event ) peestream_autopublish_release_event( $db, $event_table, $event_id );
		$db->query( "UPDATE {$audit_table} SET status = 'failed', received_at = '{$now_date}' WHERE file_code = '{$safe_fc}' AND event = '{$safe_event}'" );
		http_response_code( 500 );
		echo json_encode( array( 'success' => false, 'error' => 'Database error: failed to update post with video code.' ) );
		exit;
	}

	if ( function_exists( 'clear_cache' ) ) {
		clear_cache( array( 'news_' ) );
	}

	// Finalize audit record to processed
	$db->query( "UPDATE {$audit_table} SET post_id = '{$post_id}', status = 'processed', received_at = '{$now_date}' 
				WHERE file_code = '{$safe_fc}' AND event = '{$safe_event}'" );

	echo json_encode( array(
		'success'   => true,
		'action'    => 'updated',
		'post_id'   => $post_id,
		'file_code' => $file_code,
		'event'     => $event,
		'msg'       => 'Existing post ID ' . $post_id . ' strictly verified by unpacked IMDb and updated with new video code.'
	) );
	exit;
}

// 11. Auto-create news if enabled, but with strict safety (Approved or Unapproved moderation queue)
if ( ! empty( $cfg['auto_create_news'] ) && $cfg['auto_create_news'] == '1' ) {
	// Re-check if post already exists from an earlier attempt (e.g. crash after post insert but before audit update)
	$existing_post_id = 0;
	if ( ! empty( $existing['post_id'] ) && intval( $existing['post_id'] ) > 0 ) {
		$check_p = $db->super_query( "SELECT id FROM {$table} WHERE id = '" . intval( $existing['post_id'] ) . "' LIMIT 1" );
		if ( ! empty( $check_p['id'] ) ) {
			$existing_post_id = intval( $check_p['id'] );
		}
	}
	if ( ! $existing_post_id ) {
		$check_fc = $db->super_query( "SELECT id FROM {$table} WHERE xfields LIKE '%" . $db->safesql( $file_code ) . "%' LIMIT 1" );
		if ( ! empty( $check_fc['id'] ) ) {
			$existing_post_id = intval( $check_fc['id'] );
		}
	}

	if ( $existing_post_id > 0 ) {
		// Existing post found: avoid duplicate post creation on retry
		$db->query( "UPDATE {$audit_table} SET post_id = '{$existing_post_id}', status = 'processed', received_at = '{$now_date}' 
					WHERE file_code = '{$safe_fc}' AND event = '{$safe_event}'" );
		echo json_encode( array(
			'success'   => true,
			'action'    => 'reused_existing',
			'post_id'   => $existing_post_id,
			'file_code' => $file_code,
			'event'     => $event,
			'msg'       => 'Re-used existing post ID ' . $existing_post_id . ' on webhook retry. Duplicate post creation prevented.'
		) );
		exit;
	}

	$new_title = $title;
	if ( $season > 0 && $episode > 0 ) {
		$new_title .= sprintf( ' (Season %d, Episode %d)', $season, $episode );
	}

	$cat_id = intval( $cfg['default_category'] ) ?: 1;
	$alt_name = function_exists( 'totranslit' ) ? totranslit( $new_title, true, false ) : preg_replace( '/[^a-z0-9_\-]/i', '', strtolower( $new_title ) );

	$new_xf = peestream_autopublish_map_xfields( array(), $data, array_merge( $cfg, array( 'xfield_name' => $xf_name ) ) );
	if ( $is_episode ) $new_xf[$xf_name . '_' . sprintf( 's%02de%02d', $season, $episode )] = $file_code;
	if ( ! empty( $poster_url ) ) {
		$new_xf['poster'] = $poster_url;
	}
	$xf_str = function_exists( 'xfieldsdatasave' ) ? xfieldsdatasave( $new_xf ) : ( $xf_name . '|' . $file_code );

	$short_story = '<p>Watch ' . htmlspecialchars( $new_title, ENT_QUOTES, 'UTF-8' ) . ' online in full HD quality.</p>';
	$safe_title = $db->safesql( $new_title );
	$safe_short = $db->safesql( $short_story );
	$safe_xf = $db->safesql( $xf_str );
	$safe_alt = $db->safesql( $alt_name );

	// Moderation check: 1 if moderation is disabled, 0 if post needs admin review
	$approve_state = peestream_autopublish_approval_state( $cfg );

	$ins_res = $db->query( "INSERT INTO {$table} (autor, date, short_story, full_story, xfields, title, descr, keywords, category, alt_name, approve, allow_comm, allow_main) 
				VALUES ('PeeStream Auto', '{$now_date}', '{$safe_short}', '{$safe_short}', '{$safe_xf}', '{$safe_title}', '{$safe_title}', '{$safe_title}', '{$cat_id}', '{$safe_alt}', {$approve_state}, 1, 1)", false );

	$inserted_id = intval( $db->insert_id() );
	if ( ! $ins_res || ! $inserted_id ) {
		if ( 'media.ready' === $event ) peestream_autopublish_release_event( $db, $event_table, $event_id );
		$db->query( "UPDATE {$audit_table} SET status = 'failed', received_at = '{$now_date}' WHERE file_code = '{$safe_fc}' AND event = '{$safe_event}'" );
		http_response_code( 500 );
		echo json_encode( array( 'success' => false, 'error' => 'Database error: failed to create new post.' ) );
		exit;
	}

	if ( function_exists( 'clear_cache' ) ) {
		clear_cache( array( 'news_' ) );
	}

	$db->query( "UPDATE {$audit_table} SET post_id = '{$inserted_id}', status = 'processed', received_at = '{$now_date}' 
				WHERE file_code = '{$safe_fc}' AND event = '{$safe_event}'" );

	echo json_encode( array(
		'success'   => true,
		'action'    => 'created',
		'post_id'   => $inserted_id,
		'approved'  => $approve_state,
		'file_code' => $file_code,
		'event'     => $event,
		'msg'       => ( $approve_state ? 'Auto-created new approved post ID ' : 'Auto-created draft news (pending admin moderation) ID ' ) . $inserted_id
	) );
	exit;
}

// 12. No IMDb match and auto-create disabled -> Log as ignored for review
$db->query( "UPDATE {$audit_table} SET status = 'ignored_no_match', received_at = '{$now_date}' 
			WHERE file_code = '{$safe_fc}' AND event = '{$safe_event}'" );
if ( 'media.ready' === $event ) peestream_autopublish_release_event( $db, $event_table, $event_id );

echo json_encode( array(
	'success'   => true,
	'action'    => 'ignored',
	'file_code' => $file_code,
	'event'     => $event,
	'msg'       => 'Video received and logged. No verified DLE post matched by IMDb ID (' . htmlspecialchars( $imdb_id, ENT_QUOTES, 'UTF-8' ) . ') and auto-create is disabled.'
) );
exit;
?>

<?php
/*
=============================================================================
PeeStream Video Player & Tools - DataLife Engine Addon
Module: Hardened Auto-Publish Webhook Receiver (Series & Movies)
File: engine/ajax/peestream_webhook.php
Version: 1.0.1
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
	'webhook_secret'    => '',
	'auto_create_news'  => '0',
	'default_category'  => '1',
	'moderate_new_posts'=> '1',
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

// 5. Secret authentication check
$incoming_secret = '';
if ( isset( $_GET['secret'] ) ) {
	$incoming_secret = trim( (string)$_GET['secret'] );
} elseif ( isset( $_SERVER['HTTP_X_WEBHOOK_SECRET'] ) ) {
	$incoming_secret = trim( (string)$_SERVER['HTTP_X_WEBHOOK_SECRET'] );
}

if ( empty( $incoming_secret ) || ! hash_equals( $configured_secret, $incoming_secret ) ) {
	http_response_code( 403 );
	echo json_encode( array( 'success' => false, 'error' => 'Access denied. Invalid webhook secret token.' ) );
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" );

// Runtime ALTER TABLE migration: ensure UNIQUE index exists on older tables
$idx_check = $db->query( "SHOW INDEX FROM {$audit_table} WHERE Key_name = 'uq_fc_event'", false );
if ( ! $idx_check || ( method_exists( $db, 'num_rows' ) ? ! $db->num_rows( $idx_check ) : false ) ) {
	$db->query( "ALTER TABLE {$audit_table} ADD UNIQUE KEY uq_fc_event (file_code, event)", false );
}

// Rate Limiting: Max 60 requests per minute per IP
$safe_ip = $db->safesql( $client_ip );
$rate_check = $db->super_query( "SELECT COUNT(*) as cnt FROM {$audit_table} WHERE ip = '{$safe_ip}' AND received_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)" );
if ( $rate_check && intval( $rate_check['cnt'] ) > 60 ) {
	http_response_code( 429 );
	echo json_encode( array( 'success' => false, 'error' => 'Too Many Requests. Rate limit exceeded (max 60 req/min).' ) );
	exit;
}

// 7. Parse & Validate Payload
$raw_input = file_get_contents( 'php://input' );
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

// Validate Allowed Event Types
$allowed_events = array( 'video_ready', 'new_episode_ready', 'movie_ready' );
$event = isset( $data['event'] ) ? substr( trim( (string)$data['event'] ), 0, 32 ) : 'video_ready';
if ( ! in_array( $event, $allowed_events, true ) ) {
	http_response_code( 400 );
	echo json_encode( array( 'success' => false, 'error' => sprintf( 'Unsupported event: %s', htmlspecialchars( $event, ENT_QUOTES, 'UTF-8' ) ) ) );
	exit;
}

$imdb_id = isset( $data['imdb_id'] ) ? trim( (string)$data['imdb_id'] ) : ( isset( $data['imdb'] ) ? trim( (string)$data['imdb'] ) : '' );
if ( ! empty( $imdb_id ) && ! preg_match( '/^tt\d{5,12}$/i', $imdb_id ) ) {
	$imdb_id = ''; // Discard invalid IMDb formats
}

$season = isset( $data['season'] ) ? max( 0, intval( $data['season'] ) ) : 0;
$episode = isset( $data['episode'] ) ? max( 0, intval( $data['episode'] ) ) : 0;
$title = isset( $data['title'] ) ? trim( strip_tags( (string)$data['title'] ) ) : 'Video';
$poster_url = '';
if ( ! empty( $data['poster'] ) && preg_match( '#^https?://#i', $data['poster'] ) ) {
	$poster_url = trim( (string)$data['poster'] );
} elseif ( ! empty( $data['poster_url'] ) && preg_match( '#^https?://#i', $data['poster_url'] ) ) {
	$poster_url = trim( (string)$data['poster_url'] );
}

// 8. Atomic Reservation & Idempotency Check (Prevents TOCTOU Race Conditions)
$safe_fc = $db->safesql( $file_code );
$safe_event = $db->safesql( $event );
$now_date = date( 'Y-m-d H:i:s' );

// Attempt atomic reservation before modifying any post content
$reserved = $db->query( "INSERT INTO {$audit_table} (file_code, event, post_id, ip, received_at, status) 
			VALUES ('{$safe_fc}', '{$safe_event}', 0, '{$safe_ip}', '{$now_date}', 'processing')", false );

if ( ! $reserved ) {
	// Key already exists - inspect current state
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
			// Timed out processing lock (>60s) - take over reservation
			$db->query( "UPDATE {$audit_table} SET ip = '{$safe_ip}', received_at = '{$now_date}', status = 'processing' WHERE id = '{$existing['id']}'" );
		} elseif ( $existing['status'] === 'failed' ) {
			// Controlled retry for failed events
			$db->query( "UPDATE {$audit_table} SET ip = '{$safe_ip}', received_at = '{$now_date}', status = 'processing' WHERE id = '{$existing['id']}'" );
		}
	}
}

$xf_name = ! empty( $cfg['xfield_name'] ) ? $cfg['xfield_name'] : 'peestream';
$table = PREFIX . '_post';

// 9. Match News Item via Strict IMDb ID ONLY (Unpack xfields, restrict to identifier fields)
$matched_post = null;
if ( ! empty( $imdb_id ) ) {
	$safe_imdb = $db->safesql( $imdb_id );
	// Look for candidate posts containing the string in xfields (search up to 500 candidates)
	$cand_query = $db->query( "SELECT id, title, xfields, short_story, full_story, category FROM {$table} WHERE xfields LIKE '%{$safe_imdb}%' ORDER BY id DESC LIMIT 500" );
	
	$strictly_matched_posts = array();
	$allowed_imdb_fields = array( 'imdb', 'imdb_id', 'imdbid', 'id_imdb', 'imdb_code' );
	if( !empty( $cfg['xfield_imdb'] ) ) {
		$allowed_imdb_fields[] = strtolower( trim( (string)$cfg['xfield_imdb'] ) );
	}

	while( $cand_row = $db->get_row( $cand_query ) ) {
		$xf_arr = function_exists( 'xfieldsdataload' ) ? xfieldsdataload( $cand_row['xfields'] ) : array();
		$found_exact = false;
		
		if ( ! empty( $xf_arr ) && is_array( $xf_arr ) ) {
			foreach ( $xf_arr as $xf_k => $xf_v ) {
				if ( in_array( strtolower( (string)$xf_k ), $allowed_imdb_fields, true ) && strcasecmp( trim( (string)$xf_v ), $imdb_id ) === 0 ) {
					$found_exact = true;
					break;
				}
			}
		} else {
			// Manual parser fallback
			$parts = explode( '||', (string)$cand_row['xfields'] );
			foreach ( $parts as $p ) {
				$pair = explode( '|', $p );
				if ( isset( $pair[0] ) && in_array( strtolower( trim( $pair[0] ) ), $allowed_imdb_fields, true ) && isset( $pair[1] ) && strcasecmp( trim( $pair[1] ), $imdb_id ) === 0 ) {
					$found_exact = true;
					break;
				}
			}
		}
		
		if ( $found_exact ) {
			$strictly_matched_posts[] = $cand_row;
		}
	}
	
	if ( count( $strictly_matched_posts ) === 1 ) {
		$matched_post = $strictly_matched_posts[0];
	} elseif ( count( $strictly_matched_posts ) > 1 ) {
		// Ambiguous match: multiple posts share this IMDb ID. Do NOT update randomly!
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
		$xf_arr = xfieldsdataload( $upd_xf );
		if ( $season > 0 && $episode > 0 ) {
			$ep_key = sprintf( 's%02de%02d', $season, $episode );
			$xf_arr[$xf_name . '_' . $ep_key] = $file_code;
			$xf_arr[$xf_name] = $file_code;
			if ( ! empty( $imdb_id ) && empty( $xf_arr['imdb'] ) ) {
				$xf_arr['imdb'] = $imdb_id;
			}
		} else {
			$xf_arr[$xf_name] = $file_code;
		}
		$upd_xf = xfieldsdatasave( $xf_arr );
	} else {
		$upd_xf = preg_replace( '#' . preg_quote( $xf_name ) . '\|[^\|]*#', $xf_name . '|' . $file_code, $upd_xf );
	}

	$safe_xf = $db->safesql( $upd_xf );
	$db->query( "UPDATE {$table} SET xfields = '{$safe_xf}', date = '{$now_date}' WHERE id = '{$post_id}'" );

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
	$new_title = $title;
	if ( $season > 0 && $episode > 0 ) {
		$new_title .= sprintf( ' (Season %d, Episode %d)', $season, $episode );
	}

	$cat_id = intval( $cfg['default_category'] ) ?: 1;
	$alt_name = function_exists( 'totranslit' ) ? totranslit( $new_title, true, false ) : preg_replace( '/[^a-z0-9_\-]/i', '', strtolower( $new_title ) );

	$new_xf = array( $xf_name => $file_code );
	if ( ! empty( $imdb_id ) ) {
		$new_xf['imdb'] = $imdb_id;
	}
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
	$approve_state = ( ! empty( $cfg['moderate_new_posts'] ) && $cfg['moderate_new_posts'] == '1' ) ? 0 : 1;

	$db->query( "INSERT INTO {$table} (autor, date, short_story, full_story, xfields, title, descr, keywords, category, alt_name, approve, allow_comm, allow_main) 
				VALUES ('PeeStream Auto', '{$now_date}', '{$safe_short}', '{$safe_short}', '{$safe_xf}', '{$safe_title}', '{$safe_title}', '{$safe_title}', '{$cat_id}', '{$safe_alt}', {$approve_state}, 1, 1)" );

	$inserted_id = intval( $db->insert_id() );

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

echo json_encode( array(
	'success'   => true,
	'action'    => 'ignored',
	'file_code' => $file_code,
	'event'     => $event,
	'msg'       => 'Video received and logged. No verified DLE post matched by IMDb ID (' . htmlspecialchars( $imdb_id, ENT_QUOTES, 'UTF-8' ) . ') and auto-create is disabled.'
) );
exit;
?>

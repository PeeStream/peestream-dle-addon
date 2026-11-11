<?php
/*
=============================================================================
PeeStream Video Player & Tools - DataLife Engine Addon
File: engine/inc/peestream.php
Version: 1.0.1
Author: PeeStream Team
Official Website: https://peestream.com | Player: https://peeplayer.com
=============================================================================
*/

if( !defined( 'DATALIFEENGINE' ) OR !defined( 'LOGGED_IN' ) ) {
	header( "HTTP/1.1 403 Forbidden" );
	header( 'Location: ../../' );
	die( "Hacking attempt!" );
}

if( $member_id['user_group'] != 1 ) {
	msg( "error", $lang['opt_denied'], $lang['opt_denied'] );
}

global $db, $dle_login_hash;

$conf_file = ENGINE_DIR . '/data/peestream.conf.php';
if( file_exists( $conf_file ) ) {
	require_once ( $conf_file );
} else {
	$peestream_config = array();
}

// Default configuration settings
$cfg = array_merge( array(
	'api_key' => '',
	'player_domain' => 'https://peeplayer.com',
	'api_domain' => 'https://peestream.com',
	'default_folder_id' => '0',
	'responsive' => '1',
	'width' => '100%',
	'height' => '500',
	'aspect_ratio' => '16:9',
	'autoplay' => '0',
	'allow_fullscreen' => '1',
	'xfield_name' => 'peestream',
	'webhook_secret' => '',
	'auto_create_news' => '0',
	'default_category' => '1',
	'moderate_new_posts' => '1',
), $peestream_config );

// Ensure webhook secret is automatically initialized if missing or < 16 chars
if( empty( $cfg['webhook_secret'] ) || strlen( $cfg['webhook_secret'] ) < 16 ) {
	$cfg['webhook_secret'] = bin2hex( random_bytes( 24 ) );
	$con_file = "<?PHP\n\n// PeeStream DLE Addon Configuration\n\n\$peestream_config = " . var_export( $cfg, true ) . ";\n\n?>";
	@file_put_contents( $conf_file, $con_file, LOCK_EX );
	@chmod( $conf_file, 0644 );
}

// Helper function for secure outbound cURL requests to PeeStream Cloud API
function peestream_api_call( $endpoint, $api_key, $api_domain, $params = array() ) {
	if( empty( $api_key ) ) {
		return array( 'success' => false, 'error' => 'PeeStream API key is not configured.' );
	}
	
	$params['key'] = $api_key;
	$url = rtrim( $api_domain, '/' ) . '/' . ltrim( $endpoint, '/' ) . '?' . http_build_query( $params );
	
	$ch = curl_init();
	curl_setopt( $ch, CURLOPT_URL, $url );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $ch, CURLOPT_TIMEOUT, 25 );
	curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 8 );
	curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, true );
	curl_setopt( $ch, CURLOPT_USERAGENT, 'PeeStream-DLE-Plugin/1.0.1' );
	
	$response = curl_exec( $ch );
	$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	$curl_err = curl_error( $ch );
	curl_close( $ch );
	
	if( $curl_err ) {
		return array( 'success' => false, 'error' => 'cURL Error: ' . htmlspecialchars( $curl_err, ENT_QUOTES, 'UTF-8' ) );
	}
	
	$data = json_decode( $response, true );
	if( !is_array( $data ) ) {
		return array( 'success' => false, 'error' => 'Invalid server response (HTTP ' . $http_code . ').' );
	}
	
	if( isset( $data['status'] ) && (int)$data['status'] !== 200 ) {
		return array( 'success' => false, 'error' => isset( $data['msg'] ) ? $data['msg'] : ( 'API Error (' . $data['status'] . ')' ) );
	}
	
	return array( 'success' => true, 'data' => isset( $data['result'] ) ? $data['result'] : $data );
}

// Verified competitor video hosters supported by PeeStream engine
function peestream_get_supported_hosters() {
	return array(
		'Streamtape'  => '#https?://(?:www\.)?(?:streamtape\.(?:com|to|net|xyz)|streamta\.pe|strtape\.cloud)/(?:v|e)/([a-zA-Z0-9_\-]+)#i',
		'MixDrop'     => '#https?://(?:www\.)?mixdrop\.(?:co|to|sx|bz|ch|ag|vc|club|download)/(?:f|e)/([a-zA-Z0-9_\-]+)#i',
		'VOE'         => '#https?://(?:www\.)?(?:voe\.sx|voe-network\.net|reputationsheriffgrey\.com)/(?:e/)?([a-zA-Z0-9_\-]+)#i',
		'StreamWish'  => '#https?://(?:www\.)?(?:streamwish\.(?:to|com)|wishembed\.pro|wishfast\.top)/(?:e/|f/)?([a-zA-Z0-9_\-]+)#i',
		'EarnVids'    => '#https?://(?:www\.)?(?:earnvids\.com|vidhide\.com|vidhidepro\.com|vidhidevip\.com)/(?:e/|v/|f/)?([a-zA-Z0-9_\-]+)#i',
		'PixelDrain'  => '#https?://(?:www\.)?pixeldrain\.com/(?:u|l)/([a-zA-Z0-9_\-]+)#i',
	);
}

// Find competitor links in text
function peestream_find_competitors( $text ) {
	$found = array();
	$patterns = peestream_get_supported_hosters();
	
	foreach( $patterns as $hoster => $regex ) {
		if( preg_match_all( $regex, (string)$text, $m ) ) {
			foreach( $m[0] as $url ) {
				$found[] = array( 'hoster' => $hoster, 'url' => $url );
			}
		}
	}
	return $found;
}

// Create backup and queue tables if not exist
$backup_table = PREFIX . "_peestream_backups";
$db->query( "CREATE TABLE IF NOT EXISTS {$backup_table} (
	id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
	post_id INT UNSIGNED NOT NULL,
	original_xfields MEDIUMTEXT,
	original_short MEDIUMTEXT,
	original_full MEDIUMTEXT,
	migrated_url VARCHAR(500) NOT NULL DEFAULT '',
	new_code VARCHAR(64) NOT NULL DEFAULT '',
	backup_time DATETIME NOT NULL,
	status VARCHAR(32) NOT NULL DEFAULT 'completed',
	INDEX idx_post_id (post_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" );

$queue_table = PREFIX . "_peestream_queue";
$db->query( "CREATE TABLE IF NOT EXISTS {$queue_table} (
	id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
	post_id INT UNSIGNED NOT NULL,
	original_url VARCHAR(500) NOT NULL,
	filecode VARCHAR(64) NOT NULL DEFAULT '',
	state VARCHAR(32) NOT NULL DEFAULT 'pending',
	original_xfields MEDIUMTEXT,
	original_short MEDIUMTEXT,
	original_full MEDIUMTEXT,
	created_at DATETIME NOT NULL,
	updated_at DATETIME NOT NULL,
	status VARCHAR(32) NOT NULL DEFAULT 'queued',
	INDEX idx_post_id (post_id),
	INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" );

$action = isset( $_REQUEST['action'] ) ? trim( $_REQUEST['action'] ) : '';

// -------------------------------------------------------------
// AJAX: Scan database posts for verified competitor links
// -------------------------------------------------------------
if( $action === 'migrator_scan' ) {
	header( 'Content-Type: application/json; charset=utf-8' );
	if( empty( $_REQUEST['user_hash'] ) || !hash_equals( (string)$dle_login_hash, (string)$_REQUEST['user_hash'] ) ) {
		echo json_encode( array( 'success' => false, 'error' => 'Invalid security token.' ) );
		exit;
	}
	
	$table = PREFIX . "_post";
	$sql = "SELECT id, title, xfields, short_story, full_story FROM {$table} 
			WHERE xfields LIKE '%streamtape%' OR xfields LIKE '%mixdrop%' OR xfields LIKE '%voe.%' OR xfields LIKE '%streamwish%' OR xfields LIKE '%earnvids%' OR xfields LIKE '%vidhide%' OR xfields LIKE '%pixeldrain%'
			   OR short_story LIKE '%streamtape%' OR short_story LIKE '%mixdrop%' OR short_story LIKE '%voe.%' OR short_story LIKE '%streamwish%' OR short_story LIKE '%earnvids%' OR short_story LIKE '%vidhide%' OR short_story LIKE '%pixeldrain%'
			   OR full_story LIKE '%streamtape%' OR full_story LIKE '%mixdrop%' OR full_story LIKE '%voe.%' OR full_story LIKE '%streamwish%' OR full_story LIKE '%earnvids%' OR full_story LIKE '%vidhide%' OR full_story LIKE '%pixeldrain%'
			ORDER BY id DESC LIMIT 2000";
	
	$query = $db->query( $sql );
	$items = array();
	
	while( $row = $db->get_row( $query ) ) {
		$combined = $row['xfields'] . ' ' . $row['short_story'] . ' ' . $row['full_story'];
		$urls = peestream_find_competitors( $combined );
		
		// Filter out links that have already been converted to PeeStream
		$unmigrated = array();
		foreach( $urls as $u ) {
			if( strpos( $combined, '/e/' . $u['url'] ) === false ) {
				$unmigrated[] = $u;
			}
		}
		
		if( !empty( $unmigrated ) ) {
			$items[] = array(
				'id' => intval( $row['id'] ),
				'title' => htmlspecialchars( $row['title'], ENT_QUOTES, 'UTF-8' ),
				'hoster' => $unmigrated[0]['hoster'],
				'url' => $unmigrated[0]['url'],
				'total_urls' => count( $unmigrated )
			);
		}
	}
	
	echo json_encode( array(
		'success' => true,
		'total' => count( $items ),
		'items' => $items
	) );
	exit;
}

// -------------------------------------------------------------
// AJAX: Migrate single post with persistent queue & canplay=1 verification
// -------------------------------------------------------------
if( $action === 'migrator_process_one' ) {
	header( 'Content-Type: application/json; charset=utf-8' );
	if( empty( $_REQUEST['user_hash'] ) || !hash_equals( (string)$dle_login_hash, (string)$_REQUEST['user_hash'] ) ) {
		echo json_encode( array( 'success' => false, 'error' => 'Invalid security token.' ) );
		exit;
	}
	
	$post_id = intval( $_POST['post_id'] );
	$old_url = trim( (string)$_POST['url'] );
	$is_dry_run = ( isset( $_POST['dry_run'] ) && $_POST['dry_run'] == '1' );
	
	if( !$post_id || empty( $old_url ) ) {
		echo json_encode( array( 'success' => false, 'error' => 'Missing post ID or URL parameter.' ) );
		exit;
	}
	
	$table = PREFIX . "_post";
	$row = $db->super_query( "SELECT id, title, xfields, short_story, full_story FROM {$table} WHERE id='{$post_id}'" );
	if( empty( $row['id'] ) ) {
		echo json_encode( array( 'success' => false, 'error' => 'Post ID ' . $post_id . ' not found in database.' ) );
		exit;
	}
	
	// Dry-run mode: Only simulate
	if( $is_dry_run ) {
		echo json_encode( array(
			'success' => true,
			'post_id' => $post_id,
			'dry_run' => true,
			'filecode' => 'SIMULATED_CODE',
			'msg' => '[Dry-Run] Simulation only: Found ' . htmlspecialchars( $old_url, ENT_QUOTES, 'UTF-8' ) . '. No database changes applied.'
		) );
		exit;
	}
	
	$safe_old_url = $db->safesql( $old_url );
	$now_date = date( 'Y-m-d H:i:s' );
	
	// Check if already in persistent queue
	$q_item = $db->super_query( "SELECT * FROM {$queue_table} WHERE post_id='{$post_id}' AND original_url='{$safe_old_url}' LIMIT 1" );
	
	$new_code = '';
	$is_ready = false;
	
	if( !empty( $q_item['id'] ) ) {
		$new_code = $q_item['filecode'];
		if( $q_item['status'] === 'completed' ) {
			echo json_encode( array(
				'success' => true,
				'already_completed' => true,
				'post_id' => $post_id,
				'filecode' => $new_code,
				'msg' => '[SKIP] Post #' . $post_id . ' already converted to PeeStream (' . $new_code . ').'
			) );
			exit;
		}
		
		// Poll status from PeeStream API
		$info_res = peestream_api_call( 'api/file/info', $cfg['api_key'], $cfg['api_domain'], array( 'file_code' => $new_code ) );
		if( $info_res['success'] && isset( $info_res['data']['canplay'] ) && intval( $info_res['data']['canplay'] ) === 1 ) {
			$is_ready = true;
		} elseif( $info_res['success'] && isset( $info_res['data']['state'] ) && $info_res['data']['state'] === 'failed' ) {
			$db->query( "UPDATE {$queue_table} SET state='failed', status='failed', updated_at='{$now_date}' WHERE id='{$q_item['id']}'" );
			// SAFEGUARD: Original link remains 100% untouched!
			echo json_encode( array(
				'success' => false,
				'error' => '[FAILED] Post #' . $post_id . ': PeeStream transcoding failed. Original link preserved.'
			) );
			exit;
		} else {
			$cur_state = ( $info_res['success'] && isset( $info_res['data']['state'] ) ) ? $info_res['data']['state'] : 'pending';
			$safe_state = $db->safesql( $cur_state );
			$db->query( "UPDATE {$queue_table} SET state='{$safe_state}', updated_at='{$now_date}' WHERE id='{$q_item['id']}'" );
			// SAFEGUARD: Original link remains 100% untouched!
			echo json_encode( array(
				'success' => true,
				'pending' => true,
				'post_id' => $post_id,
				'filecode' => $new_code,
				'msg' => '[PROCESSING] Post #' . $post_id . ': Video is still encoding (' . $cur_state . '). Original link untouched.'
			) );
			exit;
		}
	} else {
		// Step 1: Call PeeStream Remote API to queue download and transcode
		$res = peestream_api_call( 'api/upload/url', $cfg['api_key'], $cfg['api_domain'], array(
			'url' => $old_url,
			'new_title' => $row['title'],
			'fld_id' => intval( $cfg['default_folder_id'] )
		) );
		
		if( !$res['success'] || empty( $res['data']['filecode'] ) ) {
			$err = isset( $res['error'] ) ? $res['error'] : 'PeeStream API failed to queue video from competitor host.';
			// SAFEGUARD: Original link remains 100% untouched!
			echo json_encode( array( 'success' => false, 'error' => $err ) );
			exit;
		}
		
		$new_code = preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string)$res['data']['filecode'] );
		$safe_new_code = $db->safesql( $new_code );
		$safe_orig_xf = $db->safesql( $row['xfields'] );
		$safe_orig_short = $db->safesql( $row['short_story'] );
		$safe_orig_full = $db->safesql( $row['full_story'] );
		
		// Insert into persistent queue
		$db->query( "INSERT INTO {$queue_table} (post_id, original_url, filecode, state, original_xfields, original_short, original_full, created_at, updated_at, status)
					VALUES ('{$post_id}', '{$safe_old_url}', '{$safe_new_code}', 'pending', '{$safe_orig_xf}', '{$safe_orig_short}', '{$safe_orig_full}', '{$now_date}', '{$now_date}', 'pending')" );
		$queue_id = intval( $db->insert_id() );
		
		// Immediately query file status (in case file is instantly available/cached)
		$info_res = peestream_api_call( 'api/file/info', $cfg['api_key'], $cfg['api_domain'], array( 'file_code' => $new_code ) );
		if( $info_res['success'] && isset( $info_res['data']['canplay'] ) && intval( $info_res['data']['canplay'] ) === 1 ) {
			$is_ready = true;
		} else {
			$cur_state = ( $info_res['success'] && isset( $info_res['data']['state'] ) ) ? $info_res['data']['state'] : 'pending';
			// SAFEGUARD: Original link remains 100% untouched until video is ready!
			echo json_encode( array(
				'success' => true,
				'pending' => true,
				'post_id' => $post_id,
				'filecode' => $new_code,
				'msg' => '[QUEUED] Post #' . $post_id . ': Queued on PeeStream (' . $cur_state . '). Original link preserved until ready.'
			) );
			exit;
		}
	}
	
	// Step 2: Apply replacement ONLY when canplay === 1
	if( $is_ready ) {
		$new_embed = rtrim( $cfg['player_domain'], '/' ) . '/e/' . $new_code;
		$safe_orig_xf = $db->safesql( $row['xfields'] );
		$safe_orig_short = $db->safesql( $row['short_story'] );
		$safe_orig_full = $db->safesql( $row['full_story'] );
		$safe_new_code = $db->safesql( $new_code );
		
		// Pre-Migration Backup of Original Post Content
		$db->query( "INSERT INTO {$backup_table} (post_id, original_xfields, original_short, original_full, migrated_url, new_code, backup_time, status)
					VALUES ('{$post_id}', '{$safe_orig_xf}', '{$safe_orig_short}', '{$safe_orig_full}', '{$safe_old_url}', '{$safe_new_code}', '{$now_date}', 'completed')" );
		$backup_id = intval( $db->insert_id() );
		
		// Precise Surgical Replacement
		$xf_name = !empty( $cfg['xfield_name'] ) ? $cfg['xfield_name'] : 'peestream';
		$upd_xf = $row['xfields'];
		$upd_short = $row['short_story'];
		$upd_full = $row['full_story'];
		
		if( strpos( $upd_xf, $old_url ) !== false ) {
			$upd_xf = str_replace( $old_url, $new_code, $upd_xf );
		}
		
		if( function_exists( 'xfieldsdataload' ) && function_exists( 'xfieldsdatasave' ) ) {
			$xf_arr = xfieldsdataload( $upd_xf );
			$xf_arr[$xf_name] = $new_code;
			$upd_xf = xfieldsdatasave( $xf_arr );
		}
		
		if( strpos( $upd_full, $old_url ) !== false ) {
			$upd_full = str_replace( $old_url, $new_embed, $upd_full );
		}
		if( strpos( $upd_short, $old_url ) !== false ) {
			$upd_short = str_replace( $old_url, $new_embed, $upd_short );
		}
		
		$safe_xf = $db->safesql( $upd_xf );
		$safe_short = $db->safesql( $upd_short );
		$safe_full = $db->safesql( $upd_full );
		
		$db->query( "UPDATE {$table} SET xfields='{$safe_xf}', short_story='{$safe_short}', full_story='{$safe_full}' WHERE id='{$post_id}'" );
		$db->query( "UPDATE {$queue_table} SET state='ready', status='completed', updated_at='{$now_date}' WHERE post_id='{$post_id}' AND original_url='{$safe_old_url}'" );
		
		if( function_exists( 'clear_cache' ) ) {
			clear_cache( array( 'news_' ) );
		}
		
		echo json_encode( array(
			'success' => true,
			'post_id' => $post_id,
			'backup_id' => $backup_id,
			'filecode' => $new_code,
			'msg' => '[READY] Video is ready (canplay=1)! Backed up and replaced with PeeStream code: ' . $new_code
		) );
		exit;
	}
}

// -------------------------------------------------------------
// AJAX: Check status of pending queue encodings
// -------------------------------------------------------------
if( $action === 'migrator_check_queue' ) {
	header( 'Content-Type: application/json; charset=utf-8' );
	if( empty( $_REQUEST['user_hash'] ) || !hash_equals( (string)$dle_login_hash, (string)$_REQUEST['user_hash'] ) ) {
		echo json_encode( array( 'success' => false, 'error' => 'Invalid security token.' ) );
		exit;
	}

	$q_res = $db->query( "SELECT * FROM {$queue_table} WHERE status IN ('pending', 'queued') ORDER BY id ASC LIMIT 25" );
	$now_date = date( 'Y-m-d H:i:s' );
	$ready_count = 0;
	$checked_count = 0;
	$logs = array();
	$table = PREFIX . "_post";

	while( $item = $db->get_row( $q_res ) ) {
		$checked_count++;
		$pid = intval( $item['post_id'] );
		$fc = $item['filecode'];
		$old_url = $item['original_url'];

		$info = peestream_api_call( 'api/file/info', $cfg['api_key'], $cfg['api_domain'], array( 'file_code' => $fc ) );
		if( $info['success'] && isset( $info['data']['canplay'] ) && intval( $info['data']['canplay'] ) === 1 ) {
			// Ready! Apply update
			$post = $db->super_query( "SELECT id, title, xfields, short_story, full_story FROM {$table} WHERE id='{$pid}'" );
			if( !empty( $post['id'] ) ) {
				$embed_url = rtrim( $cfg['player_domain'], '/' ) . '/e/' . $fc;
				$safe_orig_xf = $db->safesql( $post['xfields'] );
				$safe_orig_short = $db->safesql( $post['short_story'] );
				$safe_orig_full = $db->safesql( $post['full_story'] );
				$safe_old_url = $db->safesql( $old_url );
				$safe_fc = $db->safesql( $fc );

				// Backup
				$db->query( "INSERT INTO {$backup_table} (post_id, original_xfields, original_short, original_full, migrated_url, new_code, backup_time, status)
							 VALUES ('{$pid}', '{$safe_orig_xf}', '{$safe_orig_short}', '{$safe_orig_full}', '{$safe_old_url}', '{$safe_fc}', '{$now_date}', 'completed')" );

				// Replace
				$xf_name = !empty( $cfg['xfield_name'] ) ? $cfg['xfield_name'] : 'peestream';
				$upd_xf = $post['xfields'];
				$upd_short = $post['short_story'];
				$upd_full = $post['full_story'];

				if( strpos( $upd_xf, $old_url ) !== false ) $upd_xf = str_replace( $old_url, $fc, $upd_xf );
				if( function_exists( 'xfieldsdataload' ) && function_exists( 'xfieldsdatasave' ) ) {
					$xf_arr = xfieldsdataload( $upd_xf );
					$xf_arr[$xf_name] = $fc;
					$upd_xf = xfieldsdatasave( $xf_arr );
				}
				if( strpos( $upd_full, $old_url ) !== false ) $upd_full = str_replace( $old_url, $embed_url, $upd_full );
				if( strpos( $upd_short, $old_url ) !== false ) $upd_short = str_replace( $old_url, $embed_url, $upd_short );

				$safe_xf = $db->safesql( $upd_xf );
				$safe_short = $db->safesql( $upd_short );
				$safe_full = $db->safesql( $upd_full );

				$db->query( "UPDATE {$table} SET xfields='{$safe_xf}', short_story='{$safe_short}', full_story='{$safe_full}' WHERE id='{$pid}'" );
				$db->query( "UPDATE {$queue_table} SET state='ready', status='completed', updated_at='{$now_date}' WHERE id='{$item['id']}'" );
				$ready_count++;
				$logs[] = "[READY] Post #" . $pid . ": Transcoding complete (canplay=1)! PeeStream player attached.";
			}
		} elseif( $info['success'] && isset( $info['data']['state'] ) && $info['data']['state'] === 'failed' ) {
			$db->query( "UPDATE {$queue_table} SET state='failed', status='failed', updated_at='{$now_date}' WHERE id='{$item['id']}'" );
			$logs[] = "[FAILED] Post #" . $pid . ": Remote transcode failed on PeeStream. Original link preserved.";
		}
	}

	if( $ready_count > 0 && function_exists( 'clear_cache' ) ) {
		clear_cache( array( 'news_' ) );
	}

	echo json_encode( array(
		'success' => true,
		'checked_count' => $checked_count,
		'ready_count' => $ready_count,
		'logs' => $logs
	) );
	exit;
}

// -------------------------------------------------------------
// AJAX: Surgical Rollback Migration from Backup
// -------------------------------------------------------------
if( $action === 'migrator_rollback' ) {
	header( 'Content-Type: application/json; charset=utf-8' );
	if( empty( $_REQUEST['user_hash'] ) || !hash_equals( (string)$dle_login_hash, (string)$_REQUEST['user_hash'] ) ) {
		echo json_encode( array( 'success' => false, 'error' => 'Invalid security token.' ) );
		exit;
	}
	
	$post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
	$table = PREFIX . "_post";
	$xf_name = !empty( $cfg['xfield_name'] ) ? $cfg['xfield_name'] : 'peestream';

	$rollback_single = function( $b ) use ( $db, $table, $cfg, $xf_name, $backup_table ) {
		$pid = intval( $b['post_id'] );
		$curr = $db->super_query( "SELECT id, title, xfields, short_story, full_story FROM {$table} WHERE id='{$pid}'" );
		if( empty( $curr['id'] ) ) {
			$db->query( "UPDATE {$backup_table} SET status='rolled_back' WHERE id='{$b['id']}'" );
			return false;
		}

		$new_code = trim( (string)$b['new_code'] );
		$orig_url = trim( (string)$b['migrated_url'] );
		$embed_url = rtrim( $cfg['player_domain'], '/' ) . '/e/' . $new_code;

		// Check if current post still contains this PeeStream replacement
		$has_peestream = ( strpos( $curr['xfields'], $new_code ) !== false || strpos( $curr['short_story'], $new_code ) !== false || strpos( $curr['full_story'], $new_code ) !== false || strpos( $curr['short_story'], $embed_url ) !== false || strpos( $curr['full_story'], $embed_url ) !== false );

		if( $has_peestream ) {
			// SURGICAL REVERSION: Revert only the PeeStream replacement to preserve any newer editorial text
			$upd_short = str_replace( array( $embed_url, $new_code ), $orig_url, $curr['short_story'] );
			$upd_full  = str_replace( array( $embed_url, $new_code ), $orig_url, $curr['full_story'] );
			$upd_xf    = str_replace( $new_code, $orig_url, $curr['xfields'] );

			if( function_exists( 'xfieldsdataload' ) && function_exists( 'xfieldsdatasave' ) ) {
				$xf_arr = xfieldsdataload( $upd_xf );
				if( isset( $xf_arr[$xf_name] ) && ( $xf_arr[$xf_name] === $new_code || $xf_arr[$xf_name] === $orig_url ) ) {
					$xf_arr[$xf_name] = $orig_url;
					$upd_xf = xfieldsdatasave( $xf_arr );
				}
			}

			$safe_xf = $db->safesql( $upd_xf );
			$safe_short = $db->safesql( $upd_short );
			$safe_full = $db->safesql( $upd_full );

			$db->query( "UPDATE {$table} SET xfields='{$safe_xf}', short_story='{$safe_short}', full_story='{$safe_full}' WHERE id='{$pid}'" );
		}
		$db->query( "UPDATE {$backup_table} SET status='rolled_back' WHERE id='{$b['id']}'" );
		return true;
	};

	if( $post_id > 0 ) {
		$b = $db->super_query( "SELECT * FROM {$backup_table} WHERE post_id='{$post_id}' AND status='completed' ORDER BY id DESC LIMIT 1" );
		if( !empty( $b['id'] ) ) {
			$rollback_single( $b );
			if( function_exists( 'clear_cache' ) ) clear_cache( array( 'news_' ) );
			echo json_encode( array( 'success' => true, 'msg' => 'Post #' . $post_id . ' surgically reverted to original video link. Newer editorial text preserved.' ) );
		} else {
			echo json_encode( array( 'success' => false, 'error' => 'No active backup found for post #' . $post_id ) );
		}
	} else {
		// Rollback all
		$all_b = $db->query( "SELECT * FROM {$backup_table} WHERE status='completed' ORDER BY id DESC" );
		$count = 0;
		while( $b = $db->get_row( $all_b ) ) {
			if( $rollback_single( $b ) ) {
				$count++;
			}
		}
		if( function_exists( 'clear_cache' ) ) clear_cache( array( 'news_' ) );
		echo json_encode( array( 'success' => true, 'count' => $count, 'msg' => 'Successfully restored ' . $count . ' articles. Editorial changes preserved.' ) );
	}
	exit;
}

// -------------------------------------------------------------
// POST: Save settings
// -------------------------------------------------------------
$msg_alert = '';
if( $action === 'save' ) {
	if( empty( $_REQUEST['user_hash'] ) || !hash_equals( (string)$dle_login_hash, (string)$_REQUEST['user_hash'] ) ) {
		die( "Hacking attempt! Invalid security token." );
	}
	
	$secret = trim( strip_tags( (string)$_POST['webhook_secret'] ) );
	if( !empty( $secret ) && strlen( $secret ) < 16 ) {
		$msg_alert = '<div class="alert alert-danger alert-styled-left alert-arrow-left alert-bordered"><b>Error!</b> Webhook secret key must be at least 16 characters long for security.</div>';
	} else {
		$save_cfg = array(
			'api_key' => trim( strip_tags( (string)$_POST['api_key'] ) ),
			'player_domain' => rtrim( trim( strip_tags( (string)$_POST['player_domain'] ) ), '/' ),
			'api_domain' => rtrim( trim( strip_tags( (string)$_POST['api_domain'] ) ), '/' ),
			'default_folder_id' => intval( $_POST['default_folder_id'] ),
			'responsive' => isset( $_POST['responsive'] ) ? '1' : '0',
			'width' => trim( strip_tags( (string)$_POST['width'] ) ),
			'height' => trim( strip_tags( (string)$_POST['height'] ) ),
			'aspect_ratio' => trim( strip_tags( (string)$_POST['aspect_ratio'] ) ),
			'autoplay' => isset( $_POST['autoplay'] ) ? '1' : '0',
			'allow_fullscreen' => isset( $_POST['allow_fullscreen'] ) ? '1' : '0',
			'xfield_name' => preg_replace( '/[^a-zA-Z0-9_\-]/', '', strtolower( trim( (string)$_POST['xfield_name'] ) ) ),
			'webhook_secret' => $secret,
			'auto_create_news' => isset( $_POST['auto_create_news'] ) ? '1' : '0',
			'default_category' => intval( $_POST['default_category'] ) ?: 1,
			'moderate_new_posts' => isset( $_POST['moderate_new_posts'] ) ? '1' : '0',
		);
		
		if( empty( $save_cfg['player_domain'] ) ) $save_cfg['player_domain'] = 'https://peeplayer.com';
		if( empty( $save_cfg['api_domain'] ) ) $save_cfg['api_domain'] = 'https://peestream.com';
		if( empty( $save_cfg['xfield_name'] ) ) $save_cfg['xfield_name'] = 'peestream';
		if( empty( $save_cfg['aspect_ratio'] ) ) $save_cfg['aspect_ratio'] = '16:9';
		
		$conf_content = "<?PHP\n\n// PeeStream DLE Addon Configuration\n// Last updated: " . date( "d.m.Y H:i:s" ) . "\n\n\$peestream_config = array (\n";
		foreach( $save_cfg as $k => $v ) {
			$conf_content .= "\t'" . addslashes( $k ) . "' => '" . addslashes( (string)$v ) . "',\n";
		}
		$conf_content .= ");\n\n?>";
		
		$fp = @fopen( $conf_file, "w" );
		if( $fp ) {
			fwrite( $fp, $conf_content );
			fclose( $fp );
			@chmod( $conf_file, 0644 ); // Enforce minimal permissions
			$cfg = $save_cfg;
			$msg_alert = '<div class="alert alert-success alert-styled-left alert-arrow-left alert-bordered"><b>Success!</b> PeeStream module settings have been saved successfully.</div>';
		} else {
			// Minimal security notice without recommending 0777/0666
			$msg_alert = '<div class="alert alert-danger alert-styled-left alert-arrow-left alert-bordered"><b>Error!</b> Unable to write to configuration file. Please ensure the web server process has write access to the <code>engine/data/</code> directory (recommended permissions: 0644).</div>';
		}
	}
}

// Check API status using official PeeStream endpoints
$api_status_html = '';
$folders_options = '<option value="0">-- Main Directory (Root) --</option>';

if( !empty( $cfg['api_key'] ) ) {
	// 1. Official endpoint: /api/account/info
	$info_res = peestream_api_call( 'api/account/info', $cfg['api_key'], $cfg['api_domain'] );
	if( $info_res['success'] && is_array( $info_res['data'] ) ) {
		$u_data = $info_res['data'];
		$usr_name  = htmlspecialchars( isset( $u_data['login'] ) ? $u_data['login'] : ( isset( $u_data['email'] ) ? $u_data['email'] : 'Uploader' ), ENT_QUOTES, 'UTF-8' );
		$usr_bal   = htmlspecialchars( isset( $u_data['balance'] ) ? number_format( (float)$u_data['balance'], 2 ) : '0.00', ENT_QUOTES, 'UTF-8' );
		$usr_files = htmlspecialchars( isset( $u_data['files_total'] ) ? (string)$u_data['files_total'] : '0', ENT_QUOTES, 'UTF-8' );
		$usr_pend  = htmlspecialchars( isset( $u_data['pending'] ) ? number_format( (float)$u_data['pending'], 2 ) : '0.00', ENT_QUOTES, 'UTF-8' );
		
		$api_status_html = '<div class="alert alert-success alert-styled-left alert-arrow-left alert-bordered" style="margin-bottom:20px;">'
			. '<h6 class="alert-heading text-semibold">Connected to PeeStream Account: <b>' . $usr_name . '</b></h6>'
			. '<span>Available Balance: <b>$' . $usr_bal . ' USD</b> | Pending Balance: <b>$' . $usr_pend . ' USD</b> | Total Cloud Videos: <b>' . $usr_files . '</b></span>'
			. '</div>';
			
		// 2. Official endpoint: /api/folder/list
		$folders_res = peestream_api_call( 'api/folder/list', $cfg['api_key'], $cfg['api_domain'] );
		if( $folders_res['success'] && is_array( $folders_res['data'] ) ) {
			$f_list = isset( $folders_res['data']['folders'] ) ? $folders_res['data']['folders'] : $folders_res['data'];
			if( is_array( $f_list ) ) {
				foreach( $f_list as $fld ) {
					$fid = intval( isset( $fld['fld_id'] ) ? $fld['fld_id'] : ( isset( $fld['id'] ) ? $fld['id'] : 0 ) );
					$fnm = htmlspecialchars( isset( $fld['name'] ) ? $fld['name'] : ( 'Folder #' . $fid ), ENT_QUOTES, 'UTF-8' );
					$sel = ( $cfg['default_folder_id'] == $fid ) ? ' selected' : '';
					$folders_options .= '<option value="' . $fid . '"' . $sel . '>' . $fnm . ' (ID: ' . $fid . ')</option>';
				}
			}
		}
	} else {
		$err_msg = htmlspecialchars( isset( $info_res['error'] ) ? $info_res['error'] : 'Authentication failed', ENT_QUOTES, 'UTF-8' );
		$api_status_html = '<div class="alert alert-warning alert-styled-left alert-arrow-left alert-bordered" style="margin-bottom:20px;">'
			. '<h6 class="alert-heading text-semibold">PeeStream API Notice:</h6>'
			. '<span>' . $err_msg . '. Please verify your API key at <a href="https://peestream.com/?op=my_account" target="_blank" rel="noopener">peestream.com</a>.</span>'
			. '</div>';
	}
} else {
	$api_status_html = '<div class="alert alert-info alert-styled-left alert-arrow-left alert-bordered" style="margin-bottom:20px;">'
		. '<b>Setup Required:</b> Enter your <b>PeeStream API key</b> below to unlock video imports, folder selection and auto-migration.'
		. '</div>';
}

$chk_responsive = ( $cfg['responsive'] == '1' ) ? ' checked' : '';
$style_custom_size = ( $cfg['responsive'] == '1' ) ? 'display:none;' : '';
$chk_autoplay = ( $cfg['autoplay'] == '1' ) ? ' checked' : '';
$chk_fullscreen = ( $cfg['allow_fullscreen'] == '1' ) ? ' checked' : '';
$chk_auto_create = ( !empty( $cfg['auto_create_news'] ) && $cfg['auto_create_news'] == '1' ) ? ' checked' : '';
$chk_moderation = ( !empty( $cfg['moderate_new_posts'] ) && $cfg['moderate_new_posts'] == '1' ) ? ' checked' : '';

$val_api_key = htmlspecialchars( $cfg['api_key'], ENT_QUOTES, 'UTF-8' );
$val_player_domain = htmlspecialchars( $cfg['player_domain'], ENT_QUOTES, 'UTF-8' );
$val_api_domain = htmlspecialchars( $cfg['api_domain'], ENT_QUOTES, 'UTF-8' );
$val_xfield_name = htmlspecialchars( $cfg['xfield_name'], ENT_QUOTES, 'UTF-8' );
$val_width = htmlspecialchars( $cfg['width'], ENT_QUOTES, 'UTF-8' );
$val_height = htmlspecialchars( $cfg['height'], ENT_QUOTES, 'UTF-8' );
$val_webhook_secret = htmlspecialchars( $cfg['webhook_secret'], ENT_QUOTES, 'UTF-8' );

$webhook_public_url = $config['http_home_url'] . 'engine/ajax/peestream_webhook.php?secret=' . $val_webhook_secret;

echoheader( "PeeStream Player &amp; Tools", "Official video player and migration suite for DataLife Engine" );

echo <<<HTML
{$msg_alert}
{$api_status_html}

<!-- Navigation Tabs -->
<div class="navbar navbar-default navbar-component navbar-xs" style="margin-bottom:20px;">
	<ul class="nav navbar-nav">
		<li class="active"><a href="#tab_settings" data-toggle="tab"><i class="fa fa-sliders position-left"></i> 1. Settings &amp; API</a></li>
		<li><a href="#tab_migrator" data-toggle="tab"><i class="fa fa-exchange position-left"></i> 2. Auto-Migrator (Safe Replacement)</a></li>
		<li><a href="#tab_webhook" data-toggle="tab"><i class="fa fa-plug position-left"></i> 3. Webhook Receiver</a></li>
		<li><a href="#tab_docs" data-toggle="tab"><i class="fa fa-book position-left"></i> 4. Template Tags Guide</a></li>
	</ul>
</div>

<div class="tab-content">
	<!-- TAB 1: SETTINGS -->
	<div class="tab-pane active" id="tab_settings">
		<div class="panel panel-default">
			<div class="panel-heading">
				<h6 class="panel-title text-semibold"><i class="fa fa-play-circle position-left"></i> PeeStream Cloud Configuration</h6>
			</div>
			<div class="panel-body">
				<form method="post" action="" class="form-horizontal">
					<input type="hidden" name="action" value="save">
					<input type="hidden" name="user_hash" value="{$dle_login_hash}">

					<div class="form-group">
						<label class="control-label col-sm-3 text-semibold">PeeStream API Key:</label>
						<div class="col-sm-9">
							<input type="password" name="api_key" id="pst_api_key_field" value="{$val_api_key}" class="form-control" placeholder="Enter your private API key">
							<button type="button" class="btn btn-default btn-xs" style="margin-top:5px;" onclick="pstToggleSecret('pst_api_key_field')">Show/Hide API Key</button>
							<span class="help-block text-muted">Obtain your private API key from your PeeStream Uploader Panel at <b>Settings &gt; API</b>.</span>
						</div>
					</div>

					<div class="form-group">
						<label class="control-label col-sm-3 text-semibold">Player Domain:</label>
						<div class="col-sm-9">
							<input type="text" name="player_domain" value="{$val_player_domain}" class="form-control" placeholder="https://peeplayer.com">
							<span class="help-block text-muted">Default domain is <code>https://peeplayer.com</code>. Custom CNAME domains are supported.</span>
						</div>
					</div>

					<div class="form-group">
						<label class="control-label col-sm-3 text-semibold">PeeStream API Domain:</label>
						<div class="col-sm-9">
							<input type="text" name="api_domain" value="{$val_api_domain}" class="form-control" placeholder="https://peestream.com">
							<span class="help-block text-muted">Official endpoint is <code>https://peestream.com</code>.</span>
						</div>
					</div>

					<div class="form-group">
						<label class="control-label col-sm-3 text-semibold">Default Cloud Folder:</label>
						<div class="col-sm-9">
							<select name="default_folder_id" class="form-control">
								{$folders_options}
							</select>
							<span class="help-block text-muted">Target folder on your PeeStream account where remote imports will be organized.</span>
						</div>
					</div>

					<div class="form-group">
						<label class="control-label col-sm-3 text-semibold">DLE Extra Field (XField):</label>
						<div class="col-sm-9">
							<input type="text" name="xfield_name" value="{$val_xfield_name}" class="form-control" placeholder="peestream">
							<span class="help-block text-muted">The name of the custom field (xfield) used to store the PeeStream video code (e.g. <code>peestream</code>).</span>
						</div>
					</div>

					<hr>
					<h6 class="text-semibold"><i class="fa fa-television position-left"></i> Player Dimensions &amp; Playback Defaults</h6>

					<div class="form-group">
						<label class="control-label col-sm-3 text-semibold">Fluid Responsive Scaling (16:9):</label>
						<div class="col-sm-9">
							<div class="checkbox">
								<label><input type="checkbox" name="responsive" value="1" id="chk_responsive"{$chk_responsive}> Automatically scale player with 100% fluid 16:9 width</label>
							</div>
						</div>
					</div>

					<div class="form-group" id="block_custom_size" style="{$style_custom_size}">
						<label class="control-label col-sm-3 text-semibold">Fixed Dimensions:</label>
						<div class="col-sm-4">
							<input type="text" name="width" value="{$val_width}" class="form-control" placeholder="100% or 800px">
							<span class="help-block text-muted">Width</span>
						</div>
						<div class="col-sm-4">
							<input type="text" name="height" value="{$val_height}" class="form-control" placeholder="500px">
							<span class="help-block text-muted">Height</span>
						</div>
					</div>

					<div class="form-group">
						<label class="control-label col-sm-3 text-semibold">Autoplay Video:</label>
						<div class="col-sm-9">
							<div class="checkbox">
								<label><input type="checkbox" name="autoplay" value="1"{$chk_autoplay}> Autoplay on page load (muted by default per browser policies)</label>
							</div>
						</div>
					</div>

					<div class="form-group">
						<label class="control-label col-sm-3 text-semibold">Fullscreen Mode:</label>
						<div class="col-sm-9">
							<div class="checkbox">
								<label><input type="checkbox" name="allow_fullscreen" value="1"{$chk_fullscreen}> Allow fullscreen playback (allowfullscreen enabled)</label>
							</div>
						</div>
					</div>

					<div class="form-group" style="margin-top:25px;">
						<div class="col-sm-9 col-sm-offset-3">
							<button type="submit" class="btn btn-primary"><i class="fa fa-floppy-o position-left"></i> Save Configuration</button>
						</div>
					</div>
				</form>
			</div>
		</div>
	</div>

	<!-- TAB 2: AUTO-MIGRATOR -->
	<div class="tab-pane" id="tab_migrator">
		<div class="panel panel-default">
			<div class="panel-heading">
				<h6 class="panel-title text-semibold"><i class="fa fa-exchange position-left"></i> Safe Auto-Migrator (Batch Link Replacement Engine)</h6>
			</div>
			<div class="panel-body">
				<div class="alert alert-info" style="margin-bottom:20px;">
					<i class="fa fa-shield position-left"></i>
					<b>Verified Hosters Only:</b> This tool scans articles for links from confirmed supported hosters: <b>Streamtape</b>, <b>MixDrop</b>, <b>VOE</b>, <b>StreamWish</b>, <b>EarnVids/VidHide</b>, and <b>PixelDrain</b>. 
					Unverified hosters (e.g. Doodstream with captcha, Filemoon with SPA) are excluded to ensure 100% transfer success. 
					<br><br>
					<b>Safety First:</b> Every modified article is automatically backed up in the database. If any import fails, the original link remains untouched. You can perform a <b>Dry-Run</b> to preview changes or click <b>Rollback</b> at any time.
				</div>

				<div class="row" style="margin-bottom:20px;">
					<div class="col-sm-6">
						<div class="checkbox" style="margin-top:8px;">
							<label style="font-weight:600;color:#0284c7;">
								<input type="checkbox" id="mgr_dry_run_chk"> Dry-Run Mode (Simulation only, do not modify database)
							</label>
						</div>
					</div>
					<div class="col-sm-6 text-right">
						<button type="button" class="btn btn-default btn-xs" onclick="mgrRollbackAll()">
							<i class="fa fa-history position-left"></i> Rollback All Previous Migrations
						</button>
					</div>
				</div>

				<div style="display:flex;gap:15px;align-items:center;flex-wrap:wrap;margin-bottom:20px;">
					<button type="button" id="btn_migrator_scan" class="btn btn-info" onclick="mgrStartScan()">
						<i class="fa fa-search position-left"></i> Scan Database for Candidate Links
					</button>
					<button type="button" id="btn_migrator_start" class="btn btn-success" style="display:none;" onclick="mgrStartBatch()">
						<i class="fa fa-play position-left"></i> Start Safe Batch Migration
					</button>
					<button type="button" id="btn_migrator_pause" class="btn btn-warning" style="display:none;" onclick="mgrPauseBatch()">
						<i class="fa fa-pause position-left"></i> Pause
					</button>
					<button type="button" id="btn_migrator_check_queue" class="btn btn-default" onclick="mgrCheckPendingQueue()">
						<i class="fa fa-refresh position-left"></i> Check Pending Encodings
					</button>
				</div>

				<!-- Scanner Stats -->
				<div class="row" id="mgr_stats_row" style="display:none;margin-bottom:20px;">
					<div class="col-sm-3">
						<div class="panel panel-body bg-slate-800 text-center" style="padding:15px;">
							<h3 class="no-margin text-semibold" id="cnt_found">0</h3>
							<div class="text-muted text-size-small">Found to Replace</div>
						</div>
					</div>
					<div class="col-sm-3">
						<div class="panel panel-body bg-success-600 text-center" style="padding:15px;">
							<h3 class="no-margin text-semibold" id="cnt_migrated">0</h3>
							<div class="text-muted text-size-small">Successfully Processed</div>
						</div>
					</div>
					<div class="col-sm-3">
						<div class="panel panel-body bg-danger-600 text-center" style="padding:15px;">
							<h3 class="no-margin text-semibold" id="cnt_errors">0</h3>
							<div class="text-muted text-size-small">Failed (Untouched)</div>
						</div>
					</div>
					<div class="col-sm-3">
						<div class="panel panel-body bg-primary-600 text-center" style="padding:15px;">
							<h3 class="no-margin text-semibold" id="cnt_progress">0%</h3>
							<div class="text-muted text-size-small">Progress</div>
						</div>
					</div>
				</div>

				<!-- Progress bar -->
				<div class="progress" id="mgr_progress_bar_box" style="display:none;height:18px;margin-bottom:20px;">
					<div class="progress-bar progress-bar-striped active progress-bar-success" id="mgr_progress_bar" style="width:0%;">0%</div>
				</div>

				<!-- Live Event Log -->
				<h6 class="text-semibold"><i class="fa fa-list position-left"></i> Live Migration Log:</h6>
				<div id="mgr_log_box" style="background:#0f172a;color:#f8fafc;font-family:monospace;font-size:12px;padding:15px;border-radius:6px;height:240px;overflow-y:auto;border:1px solid #1e293b;">
					<div style="color:#94a3b8;">Click "Scan Database for Candidate Links" to begin.</div>
				</div>
			</div>
		</div>
	</div>

	<!-- TAB 3: WEBHOOK RECEIVER -->
	<div class="tab-pane" id="tab_webhook">
		<div class="panel panel-default">
			<div class="panel-heading">
				<h6 class="panel-title text-semibold"><i class="fa fa-plug position-left"></i> Automated TV Series &amp; Movie Webhook Sync</h6>
			</div>
			<div class="panel-body">
				<p>PeeStream automatically notifies your DLE website when a followed TV episode or movie finishes transcoding.</p>
				<form method="post" action="" class="form-horizontal">
					<input type="hidden" name="action" value="save">
					<input type="hidden" name="user_hash" value="{$dle_login_hash}">
					<!-- Preserve other settings -->
					<input type="hidden" name="api_key" value="{$val_api_key}">
					<input type="hidden" name="player_domain" value="{$val_player_domain}">
					<input type="hidden" name="api_domain" value="{$val_api_domain}">
					<input type="hidden" name="default_folder_id" value="{$cfg['default_folder_id']}">
					<input type="hidden" name="xfield_name" value="{$val_xfield_name}">

					<div class="form-group">
						<label class="control-label col-sm-3 text-semibold">Webhook Secret Key (min 16 chars):</label>
						<div class="col-sm-9">
							<div class="input-group">
								<input type="text" name="webhook_secret" id="pst_wh_secret" value="{$val_webhook_secret}" class="form-control" placeholder="Strong random token (at least 16 characters)">
								<span class="input-group-btn">
									<button type="button" class="btn btn-default" onclick="pstGenerateSecret()"><i class="fa fa-magic"></i> Generate</button>
								</span>
							</div>
							<span class="help-block text-muted">Incoming requests are strictly rejected if this secret token does not match.</span>
						</div>
					</div>

					<div class="form-group">
						<label class="control-label col-sm-3 text-semibold">Your Webhook URL:</label>
						<div class="col-sm-9">
							<input type="text" readonly value="{$webhook_public_url}" class="form-control" style="background:#f8fafc;" onclick="this.select()">
							<span class="help-block text-muted">Copy this URL into your PeeStream Account Webhook / Followed Series settings.</span>
						</div>
					</div>

					<div class="form-group">
						<label class="control-label col-sm-3 text-semibold">Auto-Create News:</label>
						<div class="col-sm-9">
							<div class="checkbox">
								<label><input type="checkbox" name="auto_create_news" value="1"{$chk_auto_create}> Automatically create new article if no matching post is found by IMDb ID</label>
							</div>
						</div>
					</div>

					<div class="form-group">
						<label class="control-label col-sm-3 text-semibold">Moderation Queue:</label>
						<div class="col-sm-9">
							<div class="checkbox">
								<label><input type="checkbox" name="moderate_new_posts" value="1"{$chk_moderation}> Require administrator approval before publishing auto-created articles (approve=0)</label>
							</div>
						</div>
					</div>

					<div class="form-group" style="margin-top:20px;">
						<div class="col-sm-9 col-sm-offset-3">
							<button type="submit" class="btn btn-primary"><i class="fa fa-floppy-o position-left"></i> Save Webhook Settings</button>
						</div>
					</div>
				</form>
			</div>
		</div>
	</div>

	<!-- TAB 4: TEMPLATE GUIDE -->
	<div class="tab-pane" id="tab_docs">
		<div class="panel panel-info">
			<div class="panel-heading">
				<h6 class="panel-title text-semibold"><i class="fa fa-info-circle position-left"></i> DLE Template Tags Guide</h6>
			</div>
			<div class="panel-body">
				<p>To display the responsive PeeStream player in your theme articles, open <code>templates/{your_theme}/fullstory.tpl</code> or <code>shortstory.tpl</code> and insert:</p>
				<pre><code>[xfgiven_{$val_xfield_name}]
&lt;div class="peestream-embed-container"&gt;
    {peestream_player}
&lt;/div&gt;
[/xfgiven_{$val_xfield_name}]</code></pre>

				<p style="margin-top:15px;">Available template tags in DLE:</p>
				<ul>
					<li><code>{peestream_player}</code> - Renders the complete 16:9 responsive HLS video player.</li>
					<li><code>[peestream] ... [/peestream]</code> - Conditional block displayed when an article has a valid video.</li>
					<li><code>[!peestream] ... [/!peestream]</code> - Conditional block displayed when no video is attached.</li>
					<li><code>{peestream_code}</code> - Outputs raw video code (e.g. <code>eK7g1xY9</code>).</li>
					<li><code>{peestream_url}</code> - Outputs player URL (e.g. <code>https://peeplayer.com/e/eK7g1xY9</code>).</li>
				</ul>
			</div>
		</div>
	</div>
</div>

<script>
var DLE_HASH = "{$dle_login_hash}";
var mgrQueue = [];
var mgrTotal = 0;
var mgrDone = 0;
var mgrErrors = 0;
var mgrRunning = false;

function pstToggleSecret(id) {
	var el = document.getElementById(id);
	if(el) {
		el.type = (el.type === "password") ? "text" : "password";
	}
}

function pstGenerateSecret() {
	var arr = new Uint8Array(24);
	if (window.crypto && window.crypto.getRandomValues) {
		window.crypto.getRandomValues(arr);
	} else {
		for (var i = 0; i < 24; i++) {
			arr[i] = Math.floor(Math.random() * 256);
		}
	}
	var res = Array.from(arr, function(b) { return ('0' + b.toString(16)).slice(-2); }).join('');
	var el = document.getElementById("pst_wh_secret");
	if (el) {
		el.value = res;
	}
}

function mgrLog(msg, color) {
	var box = document.getElementById("mgr_log_box");
	if (!box) return;
	var d = document.createElement("div");
	d.style.color = color || "#f8fafc";
	d.style.marginBottom = "3px";
	var timeSpan = document.createElement("span");
	timeSpan.style.color = "#64748b";
	timeSpan.textContent = "[" + new Date().toLocaleTimeString() + "] ";
	d.appendChild(timeSpan);
	d.appendChild(document.createTextNode(String(msg)));
	box.appendChild(d);
	box.scrollTop = box.scrollHeight;
}

function pstSetBtn(btn, iconClass, text) {
	if (!btn) return;
	btn.textContent = "";
	var i = document.createElement("i");
	i.className = iconClass + " position-left";
	btn.appendChild(i);
	btn.appendChild(document.createTextNode(" " + text));
}

function mgrStartScan() {
	var btn = document.getElementById("btn_migrator_scan");
	btn.disabled = true;
	pstSetBtn(btn, "fa fa-spinner fa-spin", "Scanning Database...");
	mgrLog("Starting database analysis for verified competitor links...", "#38bdf8");
	
	fetch("?mod=peestream&action=migrator_scan&user_hash=" + DLE_HASH)
		.then(function(r) { return r.json(); })
		.then(function(data) {
			btn.disabled = false;
			pstSetBtn(btn, "fa fa-search", "Scan Again");
			if(data.success) {
				mgrQueue = data.items || [];
				mgrTotal = mgrQueue.length;
				mgrDone = 0;
				mgrErrors = 0;
				
				document.getElementById("cnt_found").innerText = mgrTotal;
				document.getElementById("cnt_migrated").innerText = "0";
				document.getElementById("cnt_errors").innerText = "0";
				document.getElementById("cnt_progress").innerText = "0%";
				document.getElementById("mgr_stats_row").style.display = "flex";
				
				if(mgrTotal > 0) {
					mgrLog("Scan completed! Found " + mgrTotal + " articles with verified competitor hoster links.", "#4ade80");
					document.getElementById("btn_migrator_start").style.display = "inline-block";
				} else {
					mgrLog("No competitor links found in your database. All videos are clean!", "#a3e635");
				}
			} else {
				mgrLog("Scan failed: " + (data.error || "Unknown error"), "#f87171");
			}
		})
		.catch(function(err) {
			btn.disabled = false;
			pstSetBtn(btn, "fa fa-search", "Scan Again");
			mgrLog("Network error: " + err, "#f87171");
		});
}

function mgrCheckPendingQueue() {
	var btn = document.getElementById("btn_migrator_check_queue");
	if (btn) {
		btn.disabled = true;
		pstSetBtn(btn, "fa fa-spinner fa-spin", "Checking Queue...");
	}
	mgrLog("Checking transcode status of pending queue items in PeeStream API...", "#38bdf8");
	var fd = new FormData();
	fd.append("action", "migrator_check_queue");
	fd.append("user_hash", DLE_HASH);

	fetch("?mod=peestream", { method: "POST", body: fd })
		.then(function(r) { return r.json(); })
		.then(function(data) {
			if (btn) {
				btn.disabled = false;
				pstSetBtn(btn, "fa fa-refresh", "Check Pending Encodings");
			}
			if (data.success) {
				mgrLog(data.msg + " (Pending: " + data.pending + ", Ready: " + data.ready + ", Errors: " + data.errors + ")", "#4ade80");
			} else {
				mgrLog("Queue check failed: " + (data.error || "Unknown error"), "#f87171");
			}
		})
		.catch(function(err) {
			if (btn) {
				btn.disabled = false;
				pstSetBtn(btn, "fa fa-refresh", "Check Pending Encodings");
			}
			mgrLog("Network error checking queue: " + err, "#f87171");
		});
}

function mgrStartBatch() {
	if(mgrQueue.length === 0) return;
	mgrRunning = true;
	document.getElementById("btn_migrator_start").style.display = "none";
	document.getElementById("btn_migrator_pause").style.display = "inline-block";
	document.getElementById("mgr_progress_bar_box").style.display = "block";
	var isDry = document.getElementById("mgr_dry_run_chk").checked;
	mgrLog("Starting " + (isDry ? "DRY-RUN SIMULATION" : "SAFE BATCH MIGRATION") + "...", "#38bdf8");
	mgrProcessNext();
}

function mgrPauseBatch() {
	mgrRunning = false;
	document.getElementById("btn_migrator_pause").style.display = "none";
	document.getElementById("btn_migrator_start").style.display = "inline-block";
	mgrLog("Migration paused by user. Progress saved.", "#fbbf24");
}

function mgrProcessNext() {
	if(!mgrRunning) return;
	if(mgrQueue.length === 0) {
		mgrRunning = false;
		document.getElementById("btn_migrator_pause").style.display = "none";
		document.getElementById("btn_migrator_start").style.display = "none";
		mgrLog("ALL ARTICLES PROCESSED! Safe migration finished.", "#4ade80");
		return;
	}
	
	var isDry = document.getElementById("mgr_dry_run_chk").checked ? 1 : 0;
	var item = mgrQueue.shift();
	var fd = new FormData();
	fd.append("action", "migrator_process_one");
	fd.append("user_hash", DLE_HASH);
	fd.append("post_id", item.id);
	fd.append("url", item.url);
	fd.append("dry_run", isDry);
	
	fetch("?mod=peestream", { method: "POST", body: fd })
		.then(function(r) { return r.json(); })
		.then(function(data) {
			if(data.success) {
				mgrDone++;
				document.getElementById("cnt_migrated").innerText = mgrDone;
				mgrLog("Post #" + item.id + " ('" + item.title + "') -> " + data.msg, "#4ade80");
			} else {
				mgrErrors++;
				document.getElementById("cnt_errors").innerText = mgrErrors;
				mgrLog("Post #" + item.id + " ('" + item.title + "') -> Error: " + (data.error || "Failed. Original post kept untouched."), "#f87171");
			}
			
			var pct = Math.round(((mgrDone + mgrErrors) / mgrTotal) * 100);
			document.getElementById("cnt_progress").innerText = pct + "%";
			var pb = document.getElementById("mgr_progress_bar");
			pb.style.width = pct + "%";
			pb.innerText = pct + "%";
			
			if(mgrRunning) {
				setTimeout(mgrProcessNext, 400);
			}
		})
		.catch(function(err) {
			mgrErrors++;
			document.getElementById("cnt_errors").innerText = mgrErrors;
			mgrLog("Post #" + item.id + " -> Network error: " + err, "#f87171");
			if(mgrRunning) {
				setTimeout(mgrProcessNext, 800);
			}
		});
}

function mgrRollbackAll() {
	if(!confirm("Are you sure you want to rollback all previously migrated articles to their original backup state?")) {
		return;
	}
	mgrLog("Restoring original articles from database backup table...", "#fbbf24");
	var fd = new FormData();
	fd.append("action", "migrator_rollback");
	fd.append("user_hash", DLE_HASH);
	
	fetch("?mod=peestream", { method: "POST", body: fd })
		.then(function(r) { return r.json(); })
		.then(function(data) {
			if(data.success) {
				mgrLog("ROLLBACK COMPLETE: " + data.msg, "#4ade80");
				alert(data.msg);
			} else {
				mgrLog("Rollback error: " + (data.error || "Failed"), "#f87171");
			}
		})
		.catch(function(err) {
			mgrLog("Rollback network error: " + err, "#f87171");
		});
}

document.addEventListener("DOMContentLoaded", function() {
	var chk = document.getElementById("chk_responsive");
	var box = document.getElementById("block_custom_size");
	if(chk && box) {
		chk.addEventListener("change", function() {
			box.style.display = chk.checked ? "none" : "block";
		});
	}
});
</script>
HTML;

echofooter();
?>

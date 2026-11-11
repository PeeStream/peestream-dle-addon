<?php
/*
=============================================================================
PeeStream Video Transcode Queue Worker - DataLife Engine Addon
File: engine/modules/peestream_cron.php
Version: 1.0.1
Author: PeeStream Team
Official Website: https://peestream.com | Player: https://peeplayer.com
=============================================================================
Can be executed via CLI:
  php -f /path/to/engine/modules/peestream_cron.php
Or via HTTP / crontab curl:
  curl -s "https://example.com/engine/modules/peestream_cron.php?secret=YOUR_WEBHOOK_SECRET"
=============================================================================
*/

if( !defined( 'DATALIFEENGINE' ) ) {
	define( 'DATALIFEENGINE', true );
}

$is_cli = ( php_sapi_name() === 'cli' );

// Locate DLE root directory
$root_dir = realpath( dirname( __FILE__ ) . '/../..' );
if( !defined( 'ROOT_DIR' ) ) {
	define( 'ROOT_DIR', $root_dir );
}
if( !defined( 'ENGINE_DIR' ) ) {
	define( 'ENGINE_DIR', ROOT_DIR . '/engine' );
}

// Load database configuration
$db_config_file = ENGINE_DIR . '/data/dbconfig.php';
if( file_exists( $db_config_file ) ) {
	require_once( $db_config_file );
} else {
	if( $is_cli ) {
		fwrite( STDERR, "Error: dbconfig.php not found.\n" );
	} else {
		http_response_code( 500 );
		echo json_encode( array( 'success' => false, 'error' => 'Database configuration not found.' ) );
	}
	exit( 1 );
}

// Load database driver if not already loaded
if( !isset( $db ) && file_exists( ENGINE_DIR . '/classes/mysql.php' ) ) {
	require_once( ENGINE_DIR . '/classes/mysql.php' );
}

// Load DLE configuration
if( !isset( $config ) && file_exists( ENGINE_DIR . '/data/config.php' ) ) {
	require_once( ENGINE_DIR . '/data/config.php' );
}

// Load PeeStream configuration
$c_file = ENGINE_DIR . '/data/peestream.conf.php';
$peestream_config = array();
if( file_exists( $c_file ) ) {
	require_once( $c_file );
}

$cfg = array_merge( array(
	'api_key' => '',
	'player_domain' => 'https://peeplayer.com',
	'api_domain' => 'https://peestream.com',
	'xfield_name' => 'peestream',
	'webhook_secret' => '',
), is_array( $peestream_config ) ? $peestream_config : array() );

// Security Check for HTTP calls
if( !$is_cli ) {
	header( 'Content-Type: application/json; charset=utf-8' );
	$incoming_secret = isset( $_GET['secret'] ) ? trim( (string)$_GET['secret'] ) : ( isset( $_POST['secret'] ) ? trim( (string)$_POST['secret'] ) : '' );
	if( empty( $cfg['webhook_secret'] ) || empty( $incoming_secret ) || !hash_equals( (string)$cfg['webhook_secret'], (string)$incoming_secret ) ) {
		http_response_code( 403 );
		echo json_encode( array( 'success' => false, 'error' => 'Forbidden: Invalid or missing secret token.' ) );
		exit;
	}
}

// Concurrency locking (non-blocking)
$lock_file = ENGINE_DIR . '/cache/peestream_cron.lock';
$fp_lock = @fopen( $lock_file, 'c+' );
if( !$fp_lock || !@flock( $fp_lock, LOCK_EX | LOCK_NB ) ) {
	$msg = 'PeeStream cron worker is already running in another process. Skipped.';
	if( $is_cli ) {
		echo $msg . "\n";
	} else {
		echo json_encode( array( 'success' => true, 'skipped' => true, 'message' => $msg ) );
	}
	exit( 0 );
}

// Load API call & helper functions from peestream.php if not available
if( !function_exists( 'peestream_extract_file_info' ) ) {
	function peestream_extract_file_info( $info_res, $target_filecode = '' ) {
		if( !is_array( $info_res ) || empty( $info_res['success'] ) || empty( $info_res['data'] ) ) {
			return false;
		}
		$data = $info_res['data'];
		if( is_array( $data ) && isset( $data[0] ) && is_array( $data[0] ) ) {
			foreach( $data as $item ) {
				if( is_array( $item ) ) {
					$fc = isset( $item['filecode'] ) ? $item['filecode'] : ( isset( $item['file_code'] ) ? $item['file_code'] : '' );
					if( empty( $target_filecode ) || $fc === $target_filecode ) {
						return $item;
					}
				}
			}
			return false;
		}
		if( is_array( $data ) ) {
			$fc = isset( $data['filecode'] ) ? $data['filecode'] : ( isset( $data['file_code'] ) ? $data['file_code'] : '' );
			if( empty( $target_filecode ) || $fc === $target_filecode ) {
				if( isset( $data['canplay'] ) || isset( $data['filecode'] ) || isset( $data['state'] ) ) {
					return $data;
				}
			}
		}
		return false;
	}
}

if( !function_exists( 'peestream_api_call' ) ) {
	function peestream_api_call( $endpoint, $api_key, $api_domain, $params = array() ) {
		if( empty( $api_key ) ) {
			return array( 'success' => false, 'error' => 'PeeStream API key is not configured.' );
		}
		$base_url = rtrim( (string)$api_domain, '/' );
		$url = $base_url . '/' . ltrim( (string)$endpoint, '/' );
		$params['key'] = $api_key;
		
		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $url . '?' . http_build_query( $params ) );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 10 );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 30 );
		curl_setopt( $ch, CURLOPT_USERAGENT, 'PeeStream-DLE-Cron/1.0.1' );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, true );
		
		$resp = curl_exec( $ch );
		$err = curl_error( $ch );
		$code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );
		
		if( $err ) {
			return array( 'success' => false, 'error' => 'cURL connection failure: ' . $err );
		}
		if( $code !== 200 ) {
			return array( 'success' => false, 'error' => 'PeeStream API returned HTTP ' . $code );
		}
		$data = json_decode( $resp, true );
		if( !is_array( $data ) ) {
			return array( 'success' => false, 'error' => 'Invalid JSON from PeeStream API: ' . substr( $resp, 0, 100 ) );
		}
		return array( 'success' => true, 'data' => isset( $data['result'] ) ? $data['result'] : $data );
	}
}

$queue_table = PREFIX . "_peestream_queue";
$backup_table = PREFIX . "_peestream_backups";
$table = PREFIX . "_post";

$q_res = $db->query( "SELECT * FROM {$queue_table} WHERE status IN ('pending', 'queued') ORDER BY id ASC LIMIT 25" );
$now_date = date( 'Y-m-d H:i:s' );
$ready_count = 0;
$checked_count = 0;
$conflict_count = 0;
$failed_count = 0;
$logs = array();

while( $item = $db->get_row( $q_res ) ) {
	$checked_count++;
	$pid = intval( $item['post_id'] );
	$fc = $item['filecode'];
	$old_url = $item['original_url'];

	$info = peestream_api_call( 'api/file/info', $cfg['api_key'], $cfg['api_domain'], array( 'file_code' => $fc ) );
	$f_info = peestream_extract_file_info( $info, $fc );
	
	if( $f_info && isset( $f_info['canplay'] ) && intval( $f_info['canplay'] ) === 1 ) {
		// Video ready: check post content and conflict
		$post = $db->super_query( "SELECT id, title, xfields, short_story, full_story FROM {$table} WHERE id='{$pid}'" );
		if( !empty( $post['id'] ) ) {
			$in_xf = ( strpos( $post['xfields'], $old_url ) !== false );
			$in_short = ( strpos( $post['short_story'], $old_url ) !== false );
			$in_full = ( strpos( $post['full_story'], $old_url ) !== false );

			if( !$in_xf && !$in_short && !$in_full ) {
				$safe_err = $db->safesql( 'Conflict: target original URL was removed or modified by editor.' );
				$db->query( "UPDATE {$queue_table} SET state='conflict', status='conflict', error_msg='{$safe_err}', updated_at='{$now_date}' WHERE id='{$item['id']}'" );
				$conflict_count++;
				$logs[] = "[CONFLICT] Post #" . $pid . ": Target original URL (" . $old_url . ") was removed or modified by editor. Replacement skipped.";
				continue;
			}

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
	} elseif( $f_info && isset( $f_info['state'] ) && $f_info['state'] === 'failed' ) {
		$db->query( "UPDATE {$queue_table} SET state='failed', status='failed', updated_at='{$now_date}' WHERE id='{$item['id']}'" );
		$failed_count++;
		$logs[] = "[FAILED] Post #" . $pid . ": Remote transcode failed on PeeStream. Original link preserved.";
	} else {
		$cur_state = ( $f_info && isset( $f_info['state'] ) ) ? $f_info['state'] : 'pending';
		$safe_state = $db->safesql( $cur_state );
		$db->query( "UPDATE {$queue_table} SET state='{$safe_state}', updated_at='{$now_date}' WHERE id='{$item['id']}'" );
	}
}

if( $ready_count > 0 && function_exists( 'clear_cache' ) ) {
	clear_cache( array( 'news_' ) );
}

// Release lock
@flock( $fp_lock, LOCK_UN );
@fclose( $fp_lock );

$result = array(
	'success'        => true,
	'checked_count'  => $checked_count,
	'ready_count'    => $ready_count,
	'conflict_count' => $conflict_count,
	'failed_count'   => $failed_count,
	'logs'           => $logs,
);

if( $is_cli ) {
	echo sprintf( "PeeStream Cron Finished. Checked: %d, Ready: %d, Conflicts: %d, Failed: %d\n", $checked_count, $ready_count, $conflict_count, $failed_count );
	foreach( $logs as $l ) {
		echo " - " . $l . "\n";
	}
} else {
	echo json_encode( $result );
}

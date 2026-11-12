<?php
/*
=============================================================================
PeeStream Video Player & Tools - DataLife Engine Addon
File: engine/inc/peestream.php
Version: 1.0.4
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
	'cron_secret' => '',
	'auto_create_news' => '0',
	'default_category' => '1',
	'moderate_new_posts' => '1',
), $peestream_config );

// In-memory initialization if missing, too short, or matching compromised placeholder
if( empty( $cfg['webhook_secret'] ) || strlen( $cfg['webhook_secret'] ) < 16 || $cfg['webhook_secret'] === '529c675e950e1f3b3e3d27415b8437d54923b5c21f331241' ) {
	$cfg['webhook_secret'] = bin2hex( random_bytes( 24 ) );
}
if( empty( $cfg['cron_secret'] ) || strlen( $cfg['cron_secret'] ) < 16 || $cfg['cron_secret'] === $cfg['webhook_secret'] ) {
	$cfg['cron_secret'] = bin2hex( random_bytes( 24 ) );
}

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

// Full 256-bit binary-safe SHA-256 hash helper for case-sensitive, collision-free URL indexing
if ( ! function_exists( 'peestream_url_hash' ) ) {
	function peestream_url_hash( $url ) {
		return hash( 'sha256', trim( (string)$url ) );
	}
}

// Unified atomic queue item claim by ID (with owner_token fencing and stale lock recovery)
if ( ! function_exists( 'peestream_claim_queue_item' ) ) {
	function peestream_claim_queue_item( $db, $queue_id, $owner_token = '', $timeout_seconds = 300 ) {
		$qid = intval( $queue_id );
		if ( $qid <= 0 ) return false;
		$now_date = date( 'Y-m-d H:i:s' );
		$stale_time = date( 'Y-m-d H:i:s', time() - $timeout_seconds );
		$safe_token = $db->safesql( $owner_token );
		$queue_table = PREFIX . "_peestream_queue";
		$token_set = !empty( $owner_token ) ? ", owner_token='{$safe_token}'" : "";
		$sql = "UPDATE {$queue_table} 
		        SET status='checking'{$token_set}, updated_at='{$now_date}' 
		        WHERE id='{$qid}' 
		          AND (status IN ('pending', 'queued') OR (status='checking' AND updated_at < '{$stale_time}'))";
		$res = $db->query( $sql, false );
		if ( ! $res ) return false;
		return ( peestream_get_affected_rows( $db ) > 0 );
	}
}

// Unified atomic task claim by (post_id, original_url) with owner_token fencing and url_hash check
if ( ! function_exists( 'peestream_claim_task' ) ) {
	function peestream_claim_task( $db, $post_id, $original_url, $owner_token = '', $timeout_seconds = 300 ) {
		$pid = intval( $post_id );
		if ( $pid <= 0 || empty( $original_url ) ) return false;
		$url_hash = peestream_url_hash( $original_url );
		$safe_url = $db->safesql( $original_url );
		$safe_token = $db->safesql( $owner_token );
		$now_date = date( 'Y-m-d H:i:s' );
		$stale_time = date( 'Y-m-d H:i:s', time() - $timeout_seconds );
		$queue_table = PREFIX . "_peestream_queue";
		$token_set = !empty( $owner_token ) ? ", owner_token='{$safe_token}'" : "";
		$sql = "UPDATE {$queue_table} 
		        SET status='checking'{$token_set}, updated_at='{$now_date}' 
		        WHERE post_id='{$pid}' AND (url_hash='{$url_hash}' OR (url_hash='' AND BINARY original_url='{$safe_url}')) 
		          AND (status IN ('pending', 'queued') OR (status='checking' AND updated_at < '{$stale_time}'))";
		$res = $db->query( $sql, false );
		if ( ! $res ) return false;
		return ( peestream_get_affected_rows( $db ) > 0 );
	}
}

// Release claimed task with specific status, error message, and strict owner_token check (no unowned leakage)
if ( ! function_exists( 'peestream_release_task' ) ) {
	function peestream_release_task( $db, $queue_id, $owner_token = '', $new_status = 'pending', $error_msg = '' ) {
		$qid = intval( $queue_id );
		if ( $qid <= 0 ) return false;
		$now_date = date( 'Y-m-d H:i:s' );
		$safe_status = $db->safesql( $new_status );
		$safe_err = $db->safesql( $error_msg );
		$safe_token = $db->safesql( $owner_token );
		$queue_table = PREFIX . "_peestream_queue";
		$where_token = !empty( $owner_token ) ? " AND owner_token='{$safe_token}'" : "";
		$sql = "UPDATE {$queue_table} SET status='{$safe_status}', owner_token='', error_msg='{$safe_err}', updated_at='{$now_date}' WHERE id='{$qid}'{$where_token}";
		$res = $db->query( $sql, false );
		return ( $res && peestream_get_affected_rows( $db ) > 0 );
	}
}

// Optimistic post updater: serializes concurrent modifications on the same post without data loss
if ( ! function_exists( 'peestream_apply_post_replacement' ) ) {
	function peestream_apply_post_replacement( $db, $post_id, $old_url, $new_code, $cfg, &$backup_id = 0 ) {
		$pid = intval( $post_id );
		if ( $pid <= 0 || empty( $old_url ) || empty( $new_code ) ) {
			return array( 'success' => false, 'error' => 'Invalid parameters for post replacement.' );
		}

		$table = PREFIX . "_post";
		$backup_table = PREFIX . "_peestream_backups";
		$xf_name = !empty( $cfg['xfield_name'] ) ? $cfg['xfield_name'] : 'peestream';
		$player_domain = !empty( $cfg['player_domain'] ) ? rtrim( $cfg['player_domain'], '/' ) : 'https://peeplayer.com';
		$new_embed = $player_domain . '/e/' . $new_code;
		$safe_new_code = $db->safesql( $new_code );
		$safe_old_url = $db->safesql( $old_url );
		$max_retries = 3;

		for ( $attempt = 1; $attempt <= $max_retries; $attempt++ ) {
			$post = $db->super_query( "SELECT id, title, xfields, short_story, full_story FROM {$table} WHERE id='{$pid}'" );
			if ( empty( $post['id'] ) ) {
				return array( 'success' => false, 'error' => "Post #{$pid} not found in database." );
			}

			// Conflict check: Ensure original_url still exists in post
			$in_xf = ( strpos( $post['xfields'], $old_url ) !== false );
			$in_short = ( strpos( $post['short_story'], $old_url ) !== false );
			$in_full = ( strpos( $post['full_story'], $old_url ) !== false );

			if ( ! $in_xf && ! $in_short && ! $in_full ) {
				return array(
					'success' => false,
					'conflict' => true,
					'error' => "Conflict: Target original link (" . htmlspecialchars( $old_url, ENT_QUOTES, 'UTF-8' ) . ") was removed or modified by editor."
				);
			}

			// Pre-migration backup of current snapshot
			$now_date = date( 'Y-m-d H:i:s' );
			$safe_orig_xf = $db->safesql( $post['xfields'] );
			$safe_orig_short = $db->safesql( $post['short_story'] );
			$safe_orig_full = $db->safesql( $post['full_story'] );

			$ins_b = $db->query( "INSERT INTO {$backup_table} (post_id, original_xfields, original_short, original_full, migrated_url, new_code, backup_time, status)
						VALUES ('{$pid}', '{$safe_orig_xf}', '{$safe_orig_short}', '{$safe_orig_full}', '{$safe_old_url}', '{$safe_new_code}', '{$now_date}', 'in_progress')", false );
			$cur_backup_id = intval( $db->insert_id() );
			if ( ! $ins_b || $cur_backup_id <= 0 ) {
				return array( 'success' => false, 'error' => "Pre-migration backup failed to save. Article modification aborted for safety." );
			}

			// Perform surgical replacement on current snapshot
			$upd_xf = $post['xfields'];
			$upd_short = $post['short_story'];
			$upd_full = $post['full_story'];

			if ( function_exists( 'xfieldsdataload' ) && function_exists( 'xfieldsdatasave' ) ) {
				$xf_arr = xfieldsdataload( $upd_xf );
				$target_field_found = false;
				foreach ( $xf_arr as $k => $v ) {
					if ( strpos( (string)$v, $old_url ) !== false ) {
						$xf_arr[$k] = str_replace( $old_url, $new_code, $v );
						$target_field_found = true;
					}
				}
				// Primary player assignment:
				// If the targeted field was $xf_name, it was already updated above.
				// If targeted field was not $xf_name, set $xf_name ONLY if it's currently empty (do not clobber existing primary player)
				if ( empty( $xf_arr[$xf_name] ) ) {
					$xf_arr[$xf_name] = $new_code;
				}
				$upd_xf = xfieldsdatasave( $xf_arr );
			} else {
				if ( strpos( $upd_xf, $old_url ) !== false ) {
					$upd_xf = str_replace( $old_url, $new_code, $upd_xf );
				}
			}

			if ( strpos( $upd_full, $old_url ) !== false ) {
				$upd_full = str_replace( $old_url, $new_embed, $upd_full );
			}
			if ( strpos( $upd_short, $old_url ) !== false ) {
				$upd_short = str_replace( $old_url, $new_embed, $upd_short );
			}

			$safe_upd_xf = $db->safesql( $upd_xf );
			$safe_upd_short = $db->safesql( $upd_short );
			$safe_upd_full = $db->safesql( $upd_full );

			// Optimistic atomic update: check that post was not modified concurrently
			$upd_sql = "UPDATE {$table} 
			            SET xfields='{$safe_upd_xf}', short_story='{$safe_upd_short}', full_story='{$safe_upd_full}' 
			            WHERE id='{$pid}' 
			              AND xfields='{$safe_orig_xf}' 
			              AND short_story='{$safe_orig_short}' 
			              AND full_story='{$safe_orig_full}'";
			$upd_post = $db->query( $upd_sql, false );
			$aff = peestream_get_affected_rows( $db );

			if ( $upd_post && $aff > 0 ) {
				// Success! Promote backup to completed and strictly verify persistence
				$upd_b = $db->query( "UPDATE {$backup_table} SET status='completed' WHERE id='{$cur_backup_id}'", false );
				$chk_b = $db->super_query( "SELECT status FROM {$backup_table} WHERE id='{$cur_backup_id}'" );
				if ( ! $upd_b || empty( $chk_b['status'] ) || $chk_b['status'] !== 'completed' ) {
					return array( 'success' => false, 'error' => "Post was updated, but backup finalization verification failed for backup #{$cur_backup_id}." );
				}
				$backup_id = $cur_backup_id;
				return array( 'success' => true, 'backup_id' => $backup_id, 'filecode' => $new_code );
			}

			// Concurrent modification occurred between read and update!
			// Mark temporary in_progress backup as aborted and retry
			$db->query( "UPDATE {$backup_table} SET status='aborted' WHERE id='{$cur_backup_id}'", false );
			usleep( 10000 ); // 10ms backoff
		}

		return array( 'success' => false, 'error' => "Optimistic post update lock contention on post #{$pid} after {$max_retries} attempts. Deferring task." );
	}
}

// Interrupted task recovery: handles process crashes between post UPDATE and backup/queue finalization
if ( ! function_exists( 'peestream_recover_interrupted_tasks' ) ) {
	function peestream_recover_interrupted_tasks( $db, $cfg = array() ) {
		$backup_table = PREFIX . "_peestream_backups";
		$queue_table  = PREFIX . "_peestream_queue";
		$post_table   = PREFIX . "_post";
		$cutoff = date( 'Y-m-d H:i:s', time() - 120 ); // in_progress older than 2 minutes

		$stale = $db->query( "SELECT * FROM {$backup_table} WHERE status='in_progress' AND backup_time < '{$cutoff}' LIMIT 25", false );
		if( !$stale ) return 0;

		$player_domain = !empty( $cfg['player_domain'] ) ? rtrim( $cfg['player_domain'], '/' ) : 'https://peeplayer.com';
		$xf_name = !empty( $cfg['xfield_name'] ) ? $cfg['xfield_name'] : 'peestream';

		$recovered_count = 0;
		while( $b = $db->get_row( $stale ) ) {
			$pid = intval( $b['post_id'] );
			$new_code = trim( (string)$b['new_code'] );
			$orig_url = trim( (string)$b['migrated_url'] );
			$url_hash = peestream_url_hash( $orig_url );
			$safe_orig_url = $db->safesql( $orig_url );
			$embed_url = $player_domain . '/e/' . $new_code;

			$post = $db->super_query( "SELECT id, xfields, short_story, full_story FROM {$post_table} WHERE id='{$pid}'" );
			if( empty( $post['id'] ) ) {
				$db->query( "UPDATE {$backup_table} SET status='post_deleted' WHERE id='{$b['id']}'", false );
				continue;
			}

			// Concrete verification that THIS specific video migration was finished:
			// In multi-video posts, the original URL for this video must no longer be present,
			// AND the specific replacement (new_code in xfields or embed_url in stories) must be proven present.
			$orig_in_current_post = ( ( !empty( $orig_url ) && strpos( $post['xfields'], $orig_url ) !== false ) ||
			                          ( !empty( $orig_url ) && strpos( $post['short_story'], $orig_url ) !== false ) ||
			                          ( !empty( $orig_url ) && strpos( $post['full_story'], $orig_url ) !== false ) );

			$migration_proven = false;
			if( ! $orig_in_current_post && ! empty( $new_code ) ) {
				$snap_xf_str = isset( $b['original_xfields'] ) ? (string)$b['original_xfields'] : '';
				$snap_short  = isset( $b['original_short'] ) ? (string)$b['original_short'] : '';
				$snap_full   = isset( $b['original_full'] ) ? (string)$b['original_full'] : '';

				$in_snap_short = ( !empty( $orig_url ) && strpos( $snap_short, $orig_url ) !== false );
				$in_snap_full  = ( !empty( $orig_url ) && strpos( $snap_full, $orig_url ) !== false );
				$in_snap_xf    = ( !empty( $orig_url ) && strpos( $snap_xf_str, $orig_url ) !== false );

				if( $in_snap_short && ( strpos( $post['short_story'], $embed_url ) !== false || strpos( $post['short_story'], $new_code ) !== false ) ) {
					$migration_proven = true;
				}
				if( $in_snap_full && ( strpos( $post['full_story'], $embed_url ) !== false || strpos( $post['full_story'], $new_code ) !== false ) ) {
					$migration_proven = true;
				}
				if( $in_snap_xf ) {
					if( function_exists( 'xfieldsdataload' ) ) {
						$snap_arr = xfieldsdataload( $snap_xf_str );
						$curr_arr = xfieldsdataload( $post['xfields'] );
						foreach( $snap_arr as $k => $v ) {
							if( strpos( (string)$v, $orig_url ) !== false ) {
								if( isset( $curr_arr[$k] ) && ( strpos( (string)$curr_arr[$k], $new_code ) !== false || strpos( (string)$curr_arr[$k], $embed_url ) !== false ) ) {
									$migration_proven = true;
									break;
								}
							}
						}
						// Also check primary xfield if assigned
						if( ! $migration_proven && isset( $curr_arr[$xf_name] ) && strpos( (string)$curr_arr[$xf_name], $new_code ) !== false ) {
							$migration_proven = true;
						}
					} else {
						if( strpos( $post['xfields'], $new_code ) !== false ) {
							$migration_proven = true;
						}
					}
				}

				// Fallback if snapshots were not populated: prove replacement is present
				if( ! $in_snap_short && ! $in_snap_full && ! $in_snap_xf ) {
					if( strpos( $post['short_story'], $embed_url ) !== false ||
					    strpos( $post['full_story'], $embed_url ) !== false ||
					    strpos( $post['xfields'], $new_code ) !== false ) {
						$migration_proven = true;
					}
				}
			}

			$now_date = date( 'Y-m-d H:i:s' );
			if( $migration_proven ) {
				// Article modification actually completed before process was interrupted!
				$db->query( "UPDATE {$backup_table} SET status='completed' WHERE id='{$b['id']}'", false );
				// Only update queue task if it is either unowned or stale: do NOT overwrite active worker lease!
				$db->query( "UPDATE {$queue_table} SET state='ready', status='completed', owner_token='', updated_at='{$now_date}' WHERE post_id='{$pid}' AND (url_hash='{$url_hash}' OR (url_hash='' AND BINARY original_url='{$safe_orig_url}')) AND (owner_token='' OR updated_at < '{$cutoff}')", false );
				$recovered_count++;
			} else {
				// Article modification was interrupted or failed before save: mark backup as apply_failed and queue as pending for safe retry
				$db->query( "UPDATE {$backup_table} SET status='apply_failed' WHERE id='{$b['id']}'", false );
				// Only update queue task if it is either unowned or stale: do NOT overwrite active worker lease!
				$db->query( "UPDATE {$queue_table} SET status='pending', state='pending', owner_token='', error_msg='Recovered from interrupted partial update', updated_at='{$now_date}' WHERE post_id='{$pid}' AND (url_hash='{$url_hash}' OR (url_hash='' AND BINARY original_url='{$safe_orig_url}')) AND (owner_token='' OR updated_at < '{$cutoff}')", false );
				$recovered_count++;
			}
		}
		return $recovered_count;
	}
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
	curl_setopt( $ch, CURLOPT_USERAGENT, 'PeeStream-DLE-Plugin/1.0.4' );
	
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

// Parse PeeStream /api/file/info response correctly
// PeeStream API returns result as an array of objects: [ { "filecode": "...", "canplay": 1, ... } ]
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

// Canonical Table Initializations, Archive Storage and Runtime Migrations
if ( ! function_exists( 'peestream_ensure_schema' ) ) {
	function peestream_ensure_schema( $db, $force = false ) {
		static $checked = false;
		if ( $checked && ! $force ) return true;

		$backup_table  = PREFIX . "_peestream_backups";
		$queue_table   = PREFIX . "_peestream_queue";
		$audit_table   = PREFIX . "_peestream_webhooks";
		$archive_table = PREFIX . "_peestream_queue_archive";

		// 1. Backups Table
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
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", false );

		// 2. Active Queue Table
		$db->query( "CREATE TABLE IF NOT EXISTS {$queue_table} (
			id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			post_id INT UNSIGNED NOT NULL,
			original_url VARCHAR(500) NOT NULL,
			url_hash VARCHAR(64) NOT NULL DEFAULT '',
			filecode VARCHAR(64) NOT NULL DEFAULT '',
			state VARCHAR(32) NOT NULL DEFAULT 'pending',
			original_xfields MEDIUMTEXT,
			original_short MEDIUMTEXT,
			original_full MEDIUMTEXT,
			status VARCHAR(32) NOT NULL DEFAULT 'pending',
			owner_token VARCHAR(64) NOT NULL DEFAULT '',
			retry_count INT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			error_msg VARCHAR(255) NOT NULL DEFAULT '',
			UNIQUE KEY uq_post_urlhash (post_id, url_hash),
			INDEX idx_post_id (post_id),
			INDEX idx_status (status)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", false );

		// 3. Webhook Audit Table
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

		// 4. Archive Table for Safely Preserving Historical Duplicates
		$db->query( "CREATE TABLE IF NOT EXISTS {$archive_table} (
			id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			original_queue_id INT UNSIGNED NOT NULL DEFAULT 0,
			post_id INT UNSIGNED NOT NULL,
			original_url VARCHAR(500) NOT NULL,
			url_hash VARCHAR(64) NOT NULL DEFAULT '',
			filecode VARCHAR(64) NOT NULL DEFAULT '',
			state VARCHAR(32) NOT NULL DEFAULT 'pending',
			original_xfields MEDIUMTEXT,
			original_short MEDIUMTEXT,
			original_full MEDIUMTEXT,
			status VARCHAR(32) NOT NULL DEFAULT 'pending',
			owner_token VARCHAR(64) NOT NULL DEFAULT '',
			retry_count INT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			archived_at DATETIME NOT NULL,
			error_msg VARCHAR(255) NOT NULL DEFAULT '',
			INDEX idx_post_id (post_id)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", false );

		// 5. Column Verification and Migration on Active Queue Table
		$col_query = $db->query( "SHOW COLUMNS FROM {$queue_table}", false );
		if ( ! $col_query ) {
			return false; // Fail closed if SHOW COLUMNS query fails
		}
		$existing_cols = array();
		while ( $col = $db->get_row( $col_query ) ) {
			$existing_cols[] = $col['Field'];
		}
		if ( ! in_array( 'original_url', $existing_cols, true ) ) {
			if ( in_array( 'url', $existing_cols, true ) ) {
				$db->query( "ALTER TABLE {$queue_table} CHANGE COLUMN `url` `original_url` VARCHAR(500) NOT NULL", false );
			} else {
				$db->query( "ALTER TABLE {$queue_table} ADD COLUMN `original_url` VARCHAR(500) NOT NULL AFTER `post_id`", false );
			}
		}
		if ( ! in_array( 'url_hash', $existing_cols, true ) ) {
			$db->query( "ALTER TABLE {$queue_table} ADD COLUMN `url_hash` VARCHAR(64) NOT NULL DEFAULT '' AFTER `original_url`", false );
		}
		if ( ! in_array( 'filecode', $existing_cols, true ) ) {
			if ( in_array( 'file_code', $existing_cols, true ) ) {
				$db->query( "ALTER TABLE {$queue_table} CHANGE COLUMN `file_code` `filecode` VARCHAR(64) NOT NULL DEFAULT ''", false );
			} else {
				$db->query( "ALTER TABLE {$queue_table} ADD COLUMN `filecode` VARCHAR(64) NOT NULL DEFAULT '' AFTER `url_hash`", false );
			}
		}
		if ( ! in_array( 'state', $existing_cols, true ) ) {
			$db->query( "ALTER TABLE {$queue_table} ADD COLUMN `state` VARCHAR(32) NOT NULL DEFAULT 'pending' AFTER `filecode`", false );
		}
		if ( ! in_array( 'original_xfields', $existing_cols, true ) ) {
			$db->query( "ALTER TABLE {$queue_table} ADD COLUMN `original_xfields` MEDIUMTEXT AFTER `state`", false );
		}
		if ( ! in_array( 'original_short', $existing_cols, true ) ) {
			$db->query( "ALTER TABLE {$queue_table} ADD COLUMN `original_short` MEDIUMTEXT AFTER `original_xfields`", false );
		}
		if ( ! in_array( 'original_full', $existing_cols, true ) ) {
			$db->query( "ALTER TABLE {$queue_table} ADD COLUMN `original_full` MEDIUMTEXT AFTER `original_short`", false );
		}
		if ( ! in_array( 'owner_token', $existing_cols, true ) ) {
			$db->query( "ALTER TABLE {$queue_table} ADD COLUMN `owner_token` VARCHAR(64) NOT NULL DEFAULT '' AFTER `status`", false );
		}
		if ( ! in_array( 'retry_count', $existing_cols, true ) ) {
			$db->query( "ALTER TABLE {$queue_table} ADD COLUMN `retry_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `owner_token`", false );
		}
		if ( ! in_array( 'error_msg', $existing_cols, true ) ) {
			$db->query( "ALTER TABLE {$queue_table} ADD COLUMN `error_msg` VARCHAR(255) NOT NULL DEFAULT '' AFTER `updated_at`", false );
		}

		// Ensure all existing rows have valid 64-char SHA-256 url_hash
		$db->query( "UPDATE {$queue_table} SET url_hash = SHA2(original_url, 256) WHERE url_hash = '' OR url_hash IS NULL", false );

		// 6. Ensure Collision-Free, Full-Length UNIQUE KEY on (post_id, url_hash)
		$idx_hash_q = $db->query( "SHOW INDEX FROM {$queue_table} WHERE Key_name = 'uq_post_urlhash'", false );
		if ( ! $idx_hash_q ) {
			return false; // Fail closed if SHOW INDEX query fails
		}
		$has_uq_hash = ( method_exists( $db, 'num_rows' ) ? $db->num_rows( $idx_hash_q ) > 0 : true );
		if ( ! $has_uq_hash ) {
			// Check how many historical duplicates exist using strict SHA-256 identity and BINARY exact URL comparison
			$dup_check = method_exists( $db, 'super_query' )
				? $db->super_query( "SELECT COUNT(DISTINCT q1.id) AS cnt 
				                     FROM {$queue_table} q1 
				                     INNER JOIN {$queue_table} q2 
				                     WHERE q1.id < q2.id AND q1.post_id = q2.post_id 
				                       AND ( (q1.url_hash != '' AND q2.url_hash != '' AND q1.url_hash = q2.url_hash) OR (BINARY q1.original_url = BINARY q2.original_url) )" )
				: array();
			$expected_dups = ( !empty( $dup_check['cnt'] ) ) ? intval( $dup_check['cnt'] ) : 0;

			$archive_ok = true;
			if ( $expected_dups > 0 ) {
				$now_date = date( 'Y-m-d H:i:s' );
				// Safely preserve historical duplicates in archive table prior to adding unique key
				$ins_res = $db->query( "INSERT IGNORE INTO {$archive_table} (original_queue_id, post_id, original_url, url_hash, filecode, state, original_xfields, original_short, original_full, status, owner_token, retry_count, created_at, updated_at, archived_at, error_msg)
				             SELECT q1.id, q1.post_id, q1.original_url, q1.url_hash, q1.filecode, q1.state, q1.original_xfields, q1.original_short, q1.original_full, q1.status, q1.owner_token, q1.retry_count, q1.created_at, q1.updated_at, '{$now_date}', q1.error_msg
				             FROM {$queue_table} q1
				             INNER JOIN {$queue_table} q2
				             WHERE q1.id < q2.id AND q1.post_id = q2.post_id AND ( (q1.url_hash != '' AND q2.url_hash != '' AND q1.url_hash = q2.url_hash) OR (BINARY q1.original_url = BINARY q2.original_url) )", false );

				// Strictly verify all duplicates were successfully archived before deleting active rows
				$arch_verify = method_exists( $db, 'super_query' )
					? $db->super_query( "SELECT COUNT(DISTINCT original_queue_id) AS cnt 
					                     FROM {$archive_table} 
					                     WHERE original_queue_id IN (
					                         SELECT q1.id FROM {$queue_table} q1 
					                         INNER JOIN {$queue_table} q2 
					                         WHERE q1.id < q2.id AND q1.post_id = q2.post_id 
					                           AND ( (q1.url_hash != '' AND q2.url_hash != '' AND q1.url_hash = q2.url_hash) OR (BINARY q1.original_url = BINARY q2.original_url) )
					                     )" )
					: array();
				$archived_cnt = ( !empty( $arch_verify['cnt'] ) ) ? intval( $arch_verify['cnt'] ) : 0;
				if ( ! $ins_res || $archived_cnt < $expected_dups ) {
					// Archiving failed or did not match: ABORT deletion to keep active queue rows untouched!
					$archive_ok = false;
				}
			}

			if ( $archive_ok ) {
				if ( $expected_dups > 0 ) {
					// Remove duplicate older entries from active queue ONLY AFTER verified archive
					$db->query( "DELETE q1 FROM {$queue_table} q1
					             INNER JOIN {$queue_table} q2
					             WHERE q1.id < q2.id AND q1.post_id = q2.post_id AND ( (q1.url_hash != '' AND q2.url_hash != '' AND q1.url_hash = q2.url_hash) OR (BINARY q1.original_url = BINARY q2.original_url) )", false );
				}

				// Drop prefix-truncated legacy index if present
				$db->query( "ALTER TABLE {$queue_table} DROP INDEX uq_post_orig", false );
				// Add full 256-bit binary-safe unique key
				$db->query( "ALTER TABLE {$queue_table} ADD UNIQUE KEY uq_post_urlhash (post_id, url_hash)", false );
			} else {
				return false; // Archiving duplicates failed: cannot safely proceed to unique key creation
			}
		}

		// 7. Ensure UNIQUE KEY on Webhooks table
		$idx_q = $db->query( "SHOW INDEX FROM {$audit_table} WHERE Key_name = 'uq_fc_event'", false );
		if ( ! $idx_q ) {
			return false; // Fail closed if SHOW INDEX query fails
		}
		$has_unique_webhook_idx = ( method_exists( $db, 'num_rows' ) ? $db->num_rows( $idx_q ) > 0 : true );
		if ( ! $has_unique_webhook_idx ) {
			$db->query( "DELETE w1 FROM {$audit_table} w1 INNER JOIN {$audit_table} w2 WHERE w1.id < w2.id AND w1.file_code = w2.file_code AND w1.event = w2.event", false );
			$db->query( "ALTER TABLE {$audit_table} ADD UNIQUE KEY uq_fc_event (file_code, event)", false );
		}

		// 8. Strict Schema Verification: Verify all mandatory columns and UNIQUE indexes exist before declaring success
		$verif_cols_q = $db->query( "SHOW COLUMNS FROM {$queue_table}", false );
		if ( ! $verif_cols_q ) {
			return false; // Fail closed if SHOW COLUMNS verification query fails
		}
		$final_cols = array();
		while( $c = $db->get_row( $verif_cols_q ) ) {
			$final_cols[] = $c['Field'];
		}
		if ( ! empty( $final_cols ) ) {
			$required_cols = array( 'original_url', 'url_hash', 'filecode', 'owner_token', 'retry_count' );
			foreach ( $required_cols as $req_c ) {
				if ( ! in_array( $req_c, $final_cols, true ) ) {
					return false; // Mandatory column missing
				}
			}
		}

		$verif_idx_q = $db->query( "SHOW INDEX FROM {$queue_table} WHERE Key_name = 'uq_post_urlhash'", false );
		if ( ! $verif_idx_q ) {
			return false; // Fail closed if SHOW INDEX verification query fails
		}
		$has_final_uq = ( method_exists( $db, 'num_rows' ) ? $db->num_rows( $verif_idx_q ) > 0 : true );
		if ( ! $has_final_uq ) {
			return false; // Mandatory unique key missing
		}

		$checked = true;
		return true;
	}
}

$backup_table = PREFIX . "_peestream_backups";
$queue_table  = PREFIX . "_peestream_queue";
$audit_table  = PREFIX . "_peestream_webhooks";

// Ensure schema is fully migrated and fail-closed if verification fails
if ( ! peestream_ensure_schema( $db ) ) {
	if ( isset( $_REQUEST['action'] ) && ! empty( $_REQUEST['action'] ) ) {
		header( 'Content-Type: application/json; charset=utf-8' );
		echo json_encode( array( 'success' => false, 'error' => 'Database schema verification failed. Operation aborted.' ) );
		exit;
	} else {
		if ( function_exists( 'msg' ) ) {
			msg( "error", "PeeStream Error", "PeeStream database schema verification failed. Please verify database permissions and tables." );
		}
		return;
	}
}
peestream_recover_interrupted_tasks( $db, $cfg );

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
	
	$page = isset( $_REQUEST['page'] ) ? max( 1, intval( $_REQUEST['page'] ) ) : 1;
	$limit = isset( $_REQUEST['limit'] ) ? min( 2000, max( 10, intval( $_REQUEST['limit'] ) ) ) : 500;
	$offset = ( $page - 1 ) * $limit;

	$table = PREFIX . "_post";
	$sql = "SELECT id, title, xfields, short_story, full_story FROM {$table} 
			WHERE xfields LIKE '%streamtape%' OR xfields LIKE '%mixdrop%' OR xfields LIKE '%voe.%' OR xfields LIKE '%streamwish%' OR xfields LIKE '%earnvids%' OR xfields LIKE '%vidhide%' OR xfields LIKE '%pixeldrain%'
			   OR short_story LIKE '%streamtape%' OR short_story LIKE '%mixdrop%' OR short_story LIKE '%voe.%' OR short_story LIKE '%streamwish%' OR short_story LIKE '%earnvids%' OR short_story LIKE '%vidhide%' OR short_story LIKE '%pixeldrain%'
			   OR full_story LIKE '%streamtape%' OR full_story LIKE '%mixdrop%' OR full_story LIKE '%voe.%' OR full_story LIKE '%streamwish%' OR full_story LIKE '%earnvids%' OR full_story LIKE '%vidhide%' OR full_story LIKE '%pixeldrain%'
			ORDER BY id DESC LIMIT {$offset}, {$limit}";
	
	$query = $db->query( $sql );
	$items = array();
	$raw_count = 0;
	
	while( $row = $db->get_row( $query ) ) {
		$raw_count++;
		$combined = $row['xfields'] . ' ' . $row['short_story'] . ' ' . $row['full_story'];
		$urls = peestream_find_competitors( $combined );
		
		// Filter out links that have already been converted to PeeStream, deduplicate per post
		$seen_in_post = array();
		$unmigrated = array();
		foreach( $urls as $u ) {
			$u_url = trim( $u['url'] );
			if( empty( $u_url ) || isset( $seen_in_post[$u_url] ) ) {
				continue;
			}
			$seen_in_post[$u_url] = true;
			if( strpos( $combined, '/e/' . $u_url ) === false ) {
				$unmigrated[] = $u;
			}
		}
		
		$tot = count( $unmigrated );
		foreach( $unmigrated as $u ) {
			$items[] = array(
				'id' => intval( $row['id'] ),
				'title' => htmlspecialchars( $row['title'], ENT_QUOTES, 'UTF-8' ),
				'hoster' => $u['hoster'],
				'url' => $u['url'],
				'total_urls' => $tot
			);
		}
	}
	
	echo json_encode( array(
		'success' => true,
		'page' => $page,
		'limit' => $limit,
		'total_page_items' => count( $items ),
		'has_more' => ( $raw_count >= $limit ),
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
	$url_hash = peestream_url_hash( $old_url );
	$now_date = date( 'Y-m-d H:i:s' );
	$owner_token = bin2hex( random_bytes( 16 ) );
	$safe_token = $db->safesql( $owner_token );
	
	// Check if already in persistent queue by post_id and url_hash (binary-safe)
	$q_item = $db->super_query( "SELECT * FROM {$queue_table} WHERE post_id='{$post_id}' AND (url_hash='{$url_hash}' OR (url_hash='' AND BINARY original_url='{$safe_old_url}')) LIMIT 1" );
	
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

		// Handle Bounded Retry for failed uploads where filecode was never acquired
		if( $q_item['status'] === 'failed' && empty( $q_item['filecode'] ) ) {
			$retry_cnt = isset( $q_item['retry_count'] ) ? intval( $q_item['retry_count'] ) : 0;
			if( $retry_cnt >= 3 ) {
				echo json_encode( array(
					'success' => false,
					'error' => '[FAILED] Post #' . $post_id . ': Max upload retries reached (' . $retry_cnt . '/3). Original link preserved.'
				) );
				exit;
			}
			// Atomically claim failed record for retry
			$claimed_retry = $db->query( "UPDATE {$queue_table} 
			                              SET status='checking', state='uploading', owner_token='{$safe_token}', retry_count=retry_count+1, updated_at='{$now_date}' 
			                              WHERE id='{$q_item['id']}' AND status='failed' AND (filecode='' OR filecode IS NULL) AND retry_count < 3", false );
			if( ! $claimed_retry || peestream_get_affected_rows( $db ) <= 0 ) {
				echo json_encode( array(
					'success' => true,
					'pending' => true,
					'post_id' => $post_id,
					'msg' => '[BUSY] Post #' . $post_id . ': Task currently being retried by another worker. Skipped.'
				) );
				exit;
			}
			$queue_id = intval( $q_item['id'] );
			// Call PeeStream Remote API to retry queuing upload
			$res = peestream_api_call( 'api/upload/url', $cfg['api_key'], $cfg['api_domain'], array(
				'url' => $old_url,
				'new_title' => $row['title'],
				'fld_id' => intval( $cfg['default_folder_id'] ?? 0 )
			) );
			if( !$res['success'] || empty( $res['data']['filecode'] ) ) {
				$err = isset( $res['error'] ) ? $res['error'] : 'PeeStream API failed to queue video from competitor host.';
				$safe_err = $db->safesql( $err );
				$db->query( "UPDATE {$queue_table} SET state='failed', status='failed', owner_token='', error_msg='{$safe_err}', updated_at='{$now_date}' WHERE id='{$queue_id}' AND owner_token='{$safe_token}'", false );
				echo json_encode( array( 'success' => false, 'error' => $err ) );
				exit;
			}
			$new_code = preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string)$res['data']['filecode'] );
			$safe_new_code = $db->safesql( $new_code );
			$db->query( "UPDATE {$queue_table} SET filecode='{$safe_new_code}', state='pending', updated_at='{$now_date}' WHERE id='{$queue_id}' AND owner_token='{$safe_token}'", false );
			$q_item['filecode'] = $new_code;

			// Immediately query file status
			$info_res = peestream_api_call( 'api/file/info', $cfg['api_key'], $cfg['api_domain'], array( 'file_code' => $new_code ) );
			$f_info = peestream_extract_file_info( $info_res, $new_code );
			if( $f_info && isset( $f_info['canplay'] ) && intval( $f_info['canplay'] ) === 1 ) {
				$is_ready = true;
			} else {
				$cur_state = ( $f_info && isset( $f_info['state'] ) ) ? $f_info['state'] : 'pending';
				peestream_release_task( $db, $queue_id, $owner_token, 'pending' );
				echo json_encode( array(
					'success' => true,
					'pending' => true,
					'post_id' => $post_id,
					'filecode' => $new_code,
					'msg' => '[QUEUED] Post #' . $post_id . ': Queued on PeeStream (' . $cur_state . '). Original link preserved until ready.'
				) );
				exit;
			}
		} else {
			// Atomic task claim with owner token
			$claimed = peestream_claim_task( $db, $post_id, $old_url, $owner_token );
			if( !$claimed ) {
				echo json_encode( array(
					'success' => true,
					'pending' => true,
					'post_id' => $post_id,
					'filecode' => $new_code,
					'msg' => '[BUSY] Post #' . $post_id . ': Task currently being processed by another worker. Skipped.'
				) );
				exit;
			}
			
			// Poll status from PeeStream API
			$info_res = peestream_api_call( 'api/file/info', $cfg['api_key'], $cfg['api_domain'], array( 'file_code' => $new_code ) );
			$f_info = peestream_extract_file_info( $info_res, $new_code );
			if( $f_info && isset( $f_info['canplay'] ) && intval( $f_info['canplay'] ) === 1 ) {
				$is_ready = true;
			} elseif( $f_info && isset( $f_info['state'] ) && $f_info['state'] === 'failed' ) {
				$db->query( "UPDATE {$queue_table} SET state='failed', status='failed', owner_token='', updated_at='{$now_date}' WHERE id='{$q_item['id']}' AND owner_token='{$safe_token}'", false );
				// SAFEGUARD: Original link remains 100% untouched!
				echo json_encode( array(
					'success' => false,
					'error' => '[FAILED] Post #' . $post_id . ': PeeStream transcoding failed. Original link preserved.'
				) );
				exit;
			} else {
				$cur_state = ( $f_info && isset( $f_info['state'] ) ) ? $f_info['state'] : 'pending';
				$safe_state = $db->safesql( $cur_state );
				$db->query( "UPDATE {$queue_table} SET state='{$safe_state}', updated_at='{$now_date}' WHERE id='{$q_item['id']}' AND owner_token='{$safe_token}'", false );
				peestream_release_task( $db, $q_item['id'], $owner_token, 'pending' );
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
		}
	} else {
		// IDEMPOTENT PRE-RESERVATION BEFORE CALLING api/upload/url
		// Insert reservation with status 'checking', state 'uploading', and owner_token
		$safe_orig_xf = $db->safesql( $row['xfields'] );
		$safe_orig_short = $db->safesql( $row['short_story'] );
		$safe_orig_full = $db->safesql( $row['full_story'] );
		
		$ins_res = $db->query( "INSERT INTO {$queue_table} (post_id, original_url, url_hash, filecode, state, original_xfields, original_short, original_full, created_at, updated_at, status, owner_token, retry_count)
					VALUES ('{$post_id}', '{$safe_old_url}', '{$url_hash}', '', 'uploading', '{$safe_orig_xf}', '{$safe_orig_short}', '{$safe_orig_full}', '{$now_date}', '{$now_date}', 'checking', '{$safe_token}', 0)", false );
		
		if( !$ins_res ) {
			// Concurrent request inserted the same (post_id, url_hash) first!
			$existing_q = $db->super_query( "SELECT * FROM {$queue_table} WHERE post_id='{$post_id}' AND url_hash='{$url_hash}' LIMIT 1" );
			echo json_encode( array(
				'success' => true,
				'pending' => true,
				'post_id' => $post_id,
				'filecode' => !empty( $existing_q['filecode'] ) ? $existing_q['filecode'] : '',
				'msg' => '[BUSY] Post #' . $post_id . ': Task already queued by concurrent request. Skipped duplicate upload.'
			) );
			exit;
		}
		
		$queue_id = intval( $db->insert_id() );
		$q_item = array( 'id' => $queue_id, 'filecode' => '' );

		// Step 1: Call PeeStream Remote API to queue download and transcode
		$res = peestream_api_call( 'api/upload/url', $cfg['api_key'], $cfg['api_domain'], array(
			'url' => $old_url,
			'new_title' => $row['title'],
			'fld_id' => intval( $cfg['default_folder_id'] )
		) );
		
		if( !$res['success'] || empty( $res['data']['filecode'] ) ) {
			$err = isset( $res['error'] ) ? $res['error'] : 'PeeStream API failed to queue video from competitor host.';
			$safe_err = $db->safesql( $err );
			$db->query( "UPDATE {$queue_table} SET state='failed', status='failed', owner_token='', error_msg='{$safe_err}', updated_at='{$now_date}' WHERE id='{$queue_id}' AND owner_token='{$safe_token}'", false );
			// SAFEGUARD: Original link remains 100% untouched!
			echo json_encode( array( 'success' => false, 'error' => $err ) );
			exit;
		}
		
		$new_code = preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string)$res['data']['filecode'] );
		$safe_new_code = $db->safesql( $new_code );
		$db->query( "UPDATE {$queue_table} SET filecode='{$safe_new_code}', state='pending', updated_at='{$now_date}' WHERE id='{$queue_id}' AND owner_token='{$safe_token}'", false );
		$q_item['filecode'] = $new_code;

		// Immediately query file status (in case file is instantly available/cached)
		$info_res = peestream_api_call( 'api/file/info', $cfg['api_key'], $cfg['api_domain'], array( 'file_code' => $new_code ) );
		$f_info = peestream_extract_file_info( $info_res, $new_code );
		if( $f_info && isset( $f_info['canplay'] ) && intval( $f_info['canplay'] ) === 1 ) {
			$is_ready = true;
		} else {
			$cur_state = ( $f_info && isset( $f_info['state'] ) ) ? $f_info['state'] : 'pending';
			peestream_release_task( $db, $queue_id, $owner_token, 'pending' );
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
		$backup_id = 0;
		$repl_res = peestream_apply_post_replacement( $db, $post_id, $old_url, $new_code, $cfg, $backup_id );
		if( !$repl_res['success'] ) {
			if( !empty( $repl_res['conflict'] ) ) {
				$safe_err = $db->safesql( $repl_res['error'] );
				$db->query( "UPDATE {$queue_table} SET state='conflict', status='conflict', owner_token='', error_msg='{$safe_err}', updated_at='{$now_date}' WHERE id='{$q_item['id']}' AND owner_token='{$safe_token}'", false );
				echo json_encode( array(
					'success' => false,
					'conflict' => true,
					'post_id' => $post_id,
					'filecode' => $new_code,
					'error' => '[CONFLICT] Post #' . $post_id . ': ' . $repl_res['error']
				) );
				exit;
			}
			peestream_release_task( $db, $q_item['id'], $owner_token, 'failed', $repl_res['error'] );
			echo json_encode( array(
				'success' => false,
				'error' => '[ERROR] Post #' . $post_id . ': ' . $repl_res['error']
			) );
			exit;
		}

		$upd_final = $db->query( "UPDATE {$queue_table} SET state='ready', status='completed', owner_token='', updated_at='{$now_date}' WHERE id='{$q_item['id']}' AND owner_token='{$safe_token}'", false );
		$aff_final = peestream_get_affected_rows( $db );
		if( ! $upd_final || $aff_final <= 0 ) {
			echo json_encode( array(
				'success' => false,
				'error' => '[FENCING ERROR] Post #' . $post_id . ': Task lock lease expired before completion could be finalized.'
			) );
			exit;
		}
		
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

	$stale_time = date( 'Y-m-d H:i:s', time() - 300 );
	$q_res = $db->query( "SELECT * FROM {$queue_table} WHERE status IN ('pending', 'queued') OR (status='checking' AND updated_at < '{$stale_time}') ORDER BY id ASC LIMIT 25" );
	$now_date = date( 'Y-m-d H:i:s' );
	$ready_count = 0;
	$checked_count = 0;
	$conflict_count = 0;
	$failed_count = 0;
	$logs = array();
	$table = PREFIX . "_post";

	while( $item = $db->get_row( $q_res ) ) {
		$worker_token = bin2hex( random_bytes( 16 ) );
		$safe_token = $db->safesql( $worker_token );

		// Atomic claim check with owner_token fencing: ensure no concurrent worker processes this exact item
		if( ! peestream_claim_queue_item( $db, $item['id'], $worker_token ) ) {
			continue;
		}

		$checked_count++;
		$pid = intval( $item['post_id'] );
		$fc = $item['filecode'];
		$old_url = $item['original_url'];

		$info = peestream_api_call( 'api/file/info', $cfg['api_key'], $cfg['api_domain'], array( 'file_code' => $fc ) );
		$f_info = peestream_extract_file_info( $info, $fc );
		if( $f_info && isset( $f_info['canplay'] ) && intval( $f_info['canplay'] ) === 1 ) {
			// Video is verified READY (canplay=1)! Apply optimistic post replacement
			$backup_id = 0;
			$repl_res = peestream_apply_post_replacement( $db, $pid, $old_url, $fc, $cfg, $backup_id );
			if( $repl_res['success'] ) {
				$upd_q = $db->query( "UPDATE {$queue_table} SET state='ready', status='completed', owner_token='', updated_at='{$now_date}' WHERE id='{$item['id']}' AND owner_token='{$safe_token}'", false );
				$aff_q = peestream_get_affected_rows( $db );
				if( $upd_q && $aff_q > 0 ) {
					$ready_count++;
					$logs[] = "[READY] Post #" . $pid . ": Transcoding complete (canplay=1)! PeeStream player attached.";
				} else {
					$logs[] = "[FENCING ERROR] Post #" . $pid . ": Task lock lease lost before status could be finalized.";
				}
			} else {
				if( !empty( $repl_res['conflict'] ) ) {
					$safe_err = $db->safesql( $repl_res['error'] );
					$db->query( "UPDATE {$queue_table} SET state='conflict', status='conflict', owner_token='', error_msg='{$safe_err}', updated_at='{$now_date}' WHERE id='{$item['id']}' AND owner_token='{$safe_token}'", false );
					$conflict_count++;
					$logs[] = "[CONFLICT] Post #" . $pid . ": Target original URL (" . $old_url . ") was removed or modified by editor. Replacement skipped.";
				} else {
					peestream_release_task( $db, $item['id'], $worker_token, 'failed', $repl_res['error'] );
					$failed_count++;
					$logs[] = "[ERROR] Post #" . $pid . ": " . $repl_res['error'];
				}
			}
		} elseif( $f_info && isset( $f_info['state'] ) && $f_info['state'] === 'failed' ) {
			$db->query( "UPDATE {$queue_table} SET state='failed', status='failed', owner_token='', updated_at='{$now_date}' WHERE id='{$item['id']}' AND owner_token='{$safe_token}'", false );
			$failed_count++;
			$logs[] = "[FAILED] Post #" . $pid . ": Remote transcode failed on PeeStream. Original link preserved.";
		} else {
			$cur_state = ( $f_info && isset( $f_info['state'] ) ) ? $f_info['state'] : 'pending';
			$safe_state = $db->safesql( $cur_state );
			$db->query( "UPDATE {$queue_table} SET state='{$safe_state}', updated_at='{$now_date}' WHERE id='{$item['id']}' AND owner_token='{$safe_token}'", false );
			peestream_release_task( $db, $item['id'], $worker_token, 'pending' );
		}
	}

	if( $ready_count > 0 && function_exists( 'clear_cache' ) ) {
		clear_cache( array( 'news_' ) );
	}

	echo json_encode( array(
		'success'        => true,
		'checked_count'  => $checked_count,
		'ready_count'    => $ready_count,
		'conflict_count' => $conflict_count,
		'failed_count'   => $failed_count,
		'logs'           => $logs,
		'msg'            => sprintf( "Queue check finished. Checked: %d, Ready: %d, Conflicts: %d, Failed: %d", $checked_count, $ready_count, $conflict_count, $failed_count )
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
	
	$backup_id = isset( $_POST['backup_id'] ) ? intval( $_POST['backup_id'] ) : 0;
	$post_id   = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
	$table     = PREFIX . "_post";
	$xf_name   = !empty( $cfg['xfield_name'] ) ? $cfg['xfield_name'] : 'peestream';

	$rollback_single = function( $b ) use ( $db, $table, $cfg, $xf_name, $backup_table ) {
		$pid = intval( $b['post_id'] );
		$new_code = trim( (string)$b['new_code'] );
		$orig_url = trim( (string)$b['migrated_url'] );
		$embed_url = rtrim( $cfg['player_domain'], '/' ) . '/e/' . $new_code;
		$max_retries = 3;

		for ( $attempt = 1; $attempt <= $max_retries; $attempt++ ) {
			$curr = $db->super_query( "SELECT id, title, xfields, short_story, full_story FROM {$table} WHERE id='{$pid}'" );
			if( empty( $curr['id'] ) ) {
				return false;
			}

			// Check where this specific PeeStream video is in the current post
			$has_peestream_embed = ( strpos( $curr['short_story'], $embed_url ) !== false || strpos( $curr['full_story'], $embed_url ) !== false );
			$has_peestream_xf = false;

			$orig_xf_arr = array();
			if( function_exists( 'xfieldsdataload' ) && !empty( $b['original_xfields'] ) ) {
				$orig_xf_arr = xfieldsdataload( $b['original_xfields'] );
			}

			$curr_xf_arr = array();
			if( function_exists( 'xfieldsdataload' ) ) {
				$curr_xf_arr = xfieldsdataload( $curr['xfields'] );
			}

			// Precise field-level check: find only fields that match this specific new_code / embed_url
			$fields_to_revert = array();
			if( !empty( $curr_xf_arr ) ) {
				foreach ( $curr_xf_arr as $k => $cur_v ) {
					if ( strpos( (string)$cur_v, $new_code ) !== false || strpos( (string)$cur_v, $embed_url ) !== false ) {
						$has_peestream_xf = true;
						$orig_val = isset( $orig_xf_arr[$k] ) ? $orig_xf_arr[$k] : '';
						$fields_to_revert[$k] = $orig_val;
					}
				}
			} else {
				if( strpos( $curr['xfields'], $new_code ) !== false ) {
					$has_peestream_xf = true;
				}
			}

			// Check if original URL is actually present in post
			$has_orig_in_post = ( strpos( $curr['short_story'], $orig_url ) !== false || strpos( $curr['full_story'], $orig_url ) !== false );
			if( ! $has_orig_in_post && ! empty( $curr_xf_arr ) ) {
				foreach ( $curr_xf_arr as $cur_v ) {
					if ( strpos( (string)$cur_v, $orig_url ) !== false ) {
						$has_orig_in_post = true;
						break;
					}
				}
			}
			if( ! $has_orig_in_post && strpos( $curr['xfields'], $orig_url ) !== false ) {
				$has_orig_in_post = true;
			}

			if( ! $has_peestream_embed && ! $has_peestream_xf ) {
				// PeeStream embed/field is not present in post.
				// Do NOT falsely declare success if original link is also missing!
				if ( $has_orig_in_post ) {
					$db->query( "UPDATE {$backup_table} SET status='rolled_back' WHERE id='{$b['id']}'" );
					return true;
				}
				// Original link is neither present nor restored: report conflict
				return false;
			}

			// SURGICAL REVERSION: Revert exclusively this video's field and embed
			$upd_short = str_replace( $embed_url, $orig_url, $curr['short_story'] );
			$upd_full  = str_replace( $embed_url, $orig_url, $curr['full_story'] );
			$upd_xf    = $curr['xfields'];

			if( !empty( $curr_xf_arr ) && function_exists( 'xfieldsdatasave' ) ) {
				foreach ( $fields_to_revert as $k => $orig_val ) {
					if ( empty( $orig_val ) && $k === $xf_name && ( !isset( $orig_xf_arr[$xf_name] ) || $orig_xf_arr[$xf_name] === '' ) ) {
						unset( $curr_xf_arr[$k] );
					} else {
						$curr_xf_arr[$k] = $orig_val;
					}
				}
				$upd_xf = xfieldsdatasave( $curr_xf_arr );
			} else {
				$upd_xf = str_replace( $new_code, $orig_url, $upd_xf );
			}

			$safe_xf = $db->safesql( $upd_xf );
			$safe_short = $db->safesql( $upd_short );
			$safe_full = $db->safesql( $upd_full );

			$safe_orig_xf = $db->safesql( $curr['xfields'] );
			$safe_orig_short = $db->safesql( $curr['short_story'] );
			$safe_orig_full = $db->safesql( $curr['full_story'] );

			// Optimistic atomic update
			$upd_sql = "UPDATE {$table} 
			            SET xfields='{$safe_xf}', short_story='{$safe_short}', full_story='{$safe_full}' 
			            WHERE id='{$pid}' 
			              AND xfields='{$safe_orig_xf}' 
			              AND short_story='{$safe_orig_short}' 
			              AND full_story='{$safe_orig_full}'";
			$upd_res = $db->query( $upd_sql, false );
			$aff = peestream_get_affected_rows( $db );

			if( $upd_res && $aff > 0 ) {
				// Verify that original link is indeed present/restored in post after update
				$verify_curr = $db->super_query( "SELECT xfields, short_story, full_story FROM {$table} WHERE id='{$pid}'" );
				$verified_orig = ( strpos( $verify_curr['short_story'], $orig_url ) !== false || 
				                   strpos( $verify_curr['full_story'], $orig_url ) !== false || 
				                   strpos( $verify_curr['xfields'], $orig_url ) !== false );
				if ( $verified_orig ) {
					$db->query( "UPDATE {$backup_table} SET status='rolled_back' WHERE id='{$b['id']}'" );
					return true;
				}
			}

			usleep( 10000 );
		}

		// Optimistic lock contention after retries: do NOT mark rolled_back, preserve backup as completed and return false
		return false;
	};

	if( $backup_id > 0 ) {
		// Branch 1: Rollback specific single backup task
		$b = $db->super_query( "SELECT * FROM {$backup_table} WHERE id='{$backup_id}' AND status='completed' LIMIT 1" );
		if( !empty( $b['id'] ) ) {
			$ok = $rollback_single( $b );
			if( $ok ) {
				if( function_exists( 'clear_cache' ) ) clear_cache( array( 'news_' ) );
				echo json_encode( array( 'success' => true, 'backup_id' => $backup_id, 'msg' => 'Backup #' . $backup_id . ' surgically reverted to original link. Newer editorial text preserved.' ) );
			} else {
				echo json_encode( array( 'success' => false, 'error' => 'Failed to revert backup #' . $backup_id . ' due to persistent concurrent modification conflict or missing post.' ) );
			}
		} else {
			echo json_encode( array( 'success' => false, 'error' => 'No active backup found for backup ID #' . $backup_id ) );
		}
		exit;
	} elseif( $post_id > 0 ) {
		// Branch 2: Rollback all completed backups for a single post in reverse chronological order
		$backups_res = $db->query( "SELECT * FROM {$backup_table} WHERE post_id='{$post_id}' AND status='completed' ORDER BY id DESC" );
		$rolled_cnt = 0;
		$failed_cnt = 0;
		$total_cnt = 0;
		while( $b = $db->get_row( $backups_res ) ) {
			$total_cnt++;
			if( $rollback_single( $b ) ) {
				$rolled_cnt++;
			} else {
				$failed_cnt++;
			}
		}
		if( function_exists( 'clear_cache' ) && $rolled_cnt > 0 ) clear_cache( array( 'news_' ) );

		if( $total_cnt === 0 ) {
			echo json_encode( array( 'success' => false, 'error' => 'No active backups found for post #' . $post_id ) );
		} elseif( $failed_cnt === 0 ) {
			echo json_encode( array( 'success' => true, 'post_id' => $post_id, 'count' => $rolled_cnt, 'msg' => 'Post #' . $post_id . ': ' . $rolled_cnt . ' video links surgically reverted. Newer editorial text preserved.' ) );
		} else {
			// Report real partial failure count, never false complete success
			echo json_encode( array( 'success' => false, 'partial' => true, 'post_id' => $post_id, 'count' => $rolled_cnt, 'failed_count' => $failed_cnt, 'error' => 'Post #' . $post_id . ': ' . $rolled_cnt . ' reverted, but ' . $failed_cnt . ' failed due to concurrent modification.' ) );
		}
		exit;
	} elseif( !empty( $_POST['all'] ) || !empty( $_POST['rollback_all'] ) ) {
		// Branch 3: Global rollback with explicit confirmation of admin intent
		$all_b = $db->query( "SELECT * FROM {$backup_table} WHERE status='completed' ORDER BY id DESC" );
		$count = 0;
		$failed_cnt = 0;
		$total_cnt = 0;
		while( $b = $db->get_row( $all_b ) ) {
			$total_cnt++;
			if( $rollback_single( $b ) ) {
				$count++;
			} else {
				$failed_cnt++;
			}
		}
		if( function_exists( 'clear_cache' ) && $count > 0 ) clear_cache( array( 'news_' ) );

		if( $total_cnt === 0 ) {
			echo json_encode( array( 'success' => true, 'count' => 0, 'msg' => 'No active backups found to revert.' ) );
		} elseif( $failed_cnt === 0 ) {
			echo json_encode( array( 'success' => true, 'count' => $count, 'msg' => 'Successfully restored ' . $count . ' articles. Editorial changes preserved.' ) );
		} else {
			// Report real partial failure count, never false complete success
			echo json_encode( array( 'success' => false, 'partial' => true, 'count' => $count, 'failed_count' => $failed_cnt, 'error' => 'Restored ' . $count . ' articles, but ' . $failed_cnt . ' failed due to concurrent modification.' ) );
		}
		exit;
	} else {
		// Branch 4: Reject request without target
		echo json_encode( array( 'success' => false, 'error' => 'Invalid rollback request. Specify backup_id, post_id, or confirm global rollback.' ) );
		exit;
	}
}

// -------------------------------------------------------------
// POST: Save settings
// -------------------------------------------------------------
$msg_alert = '';
if( !empty( $config['version_id'] ) && version_compare( (string)$config['version_id'], '17.3', '>' ) ) {
	$msg_alert .= '<div class="alert alert-info alert-styled-left alert-arrow-left alert-bordered" style="margin-bottom:15px;"><b>Notice:</b> DataLife Engine ' . htmlspecialchars( (string)$config['version_id'], ENT_QUOTES, 'UTF-8' ) . ' is newer than the highest officially verified release (17.3). PeeStream is operating in backward-compatible mode.</div>';
}
if( $action === 'save' ) {
	if( empty( $_REQUEST['user_hash'] ) || !hash_equals( (string)$dle_login_hash, (string)$_REQUEST['user_hash'] ) ) {
		die( "Hacking attempt! Invalid security token." );
	}
	
	$secret = trim( strip_tags( (string)$_POST['webhook_secret'] ) );
	$cron_sec = isset( $_POST['cron_secret'] ) ? trim( strip_tags( (string)$_POST['cron_secret'] ) ) : '';

	if( !empty( $secret ) && strlen( $secret ) < 16 ) {
		$msg_alert = '<div class="alert alert-danger alert-styled-left alert-arrow-left alert-bordered"><b>Error!</b> Webhook secret key must be at least 16 characters long for security.</div>';
	} elseif( !empty( $cron_sec ) && strlen( $cron_sec ) < 16 ) {
		$msg_alert = '<div class="alert alert-danger alert-styled-left alert-arrow-left alert-bordered"><b>Error!</b> Cron secret token must be at least 16 characters long for security.</div>';
	} elseif( !empty( $cron_sec ) && !empty( $secret ) && $cron_sec === $secret ) {
		$msg_alert = '<div class="alert alert-danger alert-styled-left alert-arrow-left alert-bordered"><b>Error!</b> Cron secret token must be different from Webhook secret token for security isolation.</div>';
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
			'cron_secret' => $cron_sec,
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
$val_cron_secret = htmlspecialchars( !empty($cfg['cron_secret']) ? $cfg['cron_secret'] : '', ENT_QUOTES, 'UTF-8' );

$webhook_public_url = $config['http_home_url'] . 'engine/ajax/peestream_webhook.php?secret=' . $val_webhook_secret;
$cron_cli_cmd = 'php -f ' . ENGINE_DIR . '/modules/peestream_cron.php';
$cron_http_url = $config['http_home_url'] . 'engine/modules/peestream_cron.php';

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
					<input type="hidden" name="webhook_secret" value="{$val_webhook_secret}">
					<input type="hidden" name="cron_secret" value="{$val_cron_secret}">
					<input type="hidden" name="auto_create_news" value="{$cfg['auto_create_news']}">
					<input type="hidden" name="default_category" value="{$cfg['default_category']}">
					<input type="hidden" name="moderate_new_posts" value="{$cfg['moderate_new_posts']}">

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
						<label class="control-label col-sm-3 text-semibold">Cron Worker Secret (min 16 chars):</label>
						<div class="col-sm-9">
							<div class="input-group">
								<input type="text" name="cron_secret" id="pst_cron_secret" value="{$val_cron_secret}" class="form-control" placeholder="Strong random token (isolated from webhook secret)">
								<span class="input-group-btn">
									<button type="button" class="btn btn-default" onclick="pstGenerateSecret('pst_cron_secret')"><i class="fa fa-magic"></i> Generate</button>
								</span>
							</div>
							<span class="help-block text-muted">Dedicated token for background queue processing. Must be different from the Webhook Secret.</span>
						</div>
					</div>

					<div class="form-group">
						<label class="control-label col-sm-3 text-semibold">Cron Worker Execution:</label>
						<div class="col-sm-9">
							<p><strong>Recommended (CLI Crontab):</strong></p>
							<input type="text" readonly value="{$cron_cli_cmd}" class="form-control" style="background:#f8fafc;font-family:monospace;" onclick="this.select()">
							<span class="help-block text-muted">Example crontab entry (runs every 2 minutes): <code>*/2 * * * * {$cron_cli_cmd} &gt;/dev/null 2&gt;&amp;1</code></span>
							<p style="margin-top:10px;"><strong>Alternative (HTTP via POST curl):</strong></p>
							<input type="text" readonly value="curl -s -X POST -d 'secret={$val_cron_secret}' '{$cron_http_url}'" class="form-control" style="background:#f8fafc;font-family:monospace;" onclick="this.select()">
							<span class="help-block text-muted">HTTP execution requires POST method and valid <code>cron_secret</code>. GET requests are strictly rejected.</span>
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

function pstGenerateSecret(targetId) {
	if (!window.crypto || !window.crypto.getRandomValues) {
		alert("Cryptographically secure random number generator (window.crypto) is not available in your browser. Please use a modern browser over HTTPS.");
		return;
	}
	var arr = new Uint8Array(24);
	window.crypto.getRandomValues(arr);
	var res = Array.from(arr, function(b) { return ('0' + b.toString(16)).slice(-2); }).join('');
	var el = document.getElementById(targetId || "pst_wh_secret");
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
	
	mgrQueue = [];
	mgrTotal = 0;
	mgrDone = 0;
	mgrErrors = 0;

	function fetchScanPage(page) {
		fetch("?mod=peestream&action=migrator_scan&page=" + page + "&limit=500&user_hash=" + DLE_HASH)
			.then(function(r) { return r.json(); })
			.then(function(data) {
				if(data.success) {
					var pageItems = data.items || [];
					mgrQueue = mgrQueue.concat(pageItems);
					mgrTotal = mgrQueue.length;
					document.getElementById("cnt_found").innerText = mgrTotal;

					if(data.has_more) {
						mgrLog("Page " + page + " scanned (" + pageItems.length + " links found). Fetching next batch...", "#38bdf8");
						fetchScanPage(page + 1);
					} else {
						btn.disabled = false;
						pstSetBtn(btn, "fa fa-search", "Scan Again");
						document.getElementById("cnt_migrated").innerText = "0";
						document.getElementById("cnt_errors").innerText = "0";
						document.getElementById("cnt_progress").innerText = "0%";
						document.getElementById("mgr_stats_row").style.display = "flex";
						
						if(mgrTotal > 0) {
							mgrLog("Scan completed! Found total " + mgrTotal + " competitor hoster links across database.", "#4ade80");
							document.getElementById("btn_migrator_start").style.display = "inline-block";
						} else {
							mgrLog("No competitor links found in your database. All videos are clean!", "#a3e635");
						}
					}
				} else {
					btn.disabled = false;
					pstSetBtn(btn, "fa fa-search", "Scan Again");
					mgrLog("Scan failed: " + (data.error || "Unknown error"), "#f87171");
				}
			})
			.catch(function(err) {
				btn.disabled = false;
				pstSetBtn(btn, "fa fa-search", "Scan Again");
				mgrLog("Network error: " + err, "#f87171");
			});
	}

	fetchScanPage(1);
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
				var checked = (typeof data.checked_count !== "undefined") ? data.checked_count : 0;
				var ready = (typeof data.ready_count !== "undefined") ? data.ready_count : 0;
				var conflicts = (typeof data.conflict_count !== "undefined") ? data.conflict_count : 0;
				var failed = (typeof data.failed_count !== "undefined") ? data.failed_count : 0;
				var summary = "Queue check finished. Checked: " + checked + ", Ready: " + ready;
				if (conflicts > 0) summary += ", Conflicts: " + conflicts;
				if (failed > 0) summary += ", Failed: " + failed;
				mgrLog(summary, "#4ade80");
				if (Array.isArray(data.logs)) {
					data.logs.forEach(function(l) {
						var col = "#e2e8f0";
						if (l.indexOf("[CONFLICT]") !== -1) col = "#fbbf24";
						else if (l.indexOf("[FAILED]") !== -1) col = "#f87171";
						else if (l.indexOf("[READY]") !== -1) col = "#4ade80";
						mgrLog(l, col);
					});
				}
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
	fd.append("all", "1");
	
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

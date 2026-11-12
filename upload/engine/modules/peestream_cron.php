<?php
/*
=============================================================================
PeeStream Video Transcode Queue Worker - DataLife Engine Addon
File: engine/modules/peestream_cron.php
Version: 1.0.3
Author: PeeStream Team
Official Website: https://peestream.com | Player: https://peeplayer.com
=============================================================================
Can be executed via CLI (Recommended):
  php -f /path/to/engine/modules/peestream_cron.php
Or via HTTP POST (requires isolated cron_secret):
  curl -s -X POST -d "secret=YOUR_CRON_SECRET" "https://example.com/engine/modules/peestream_cron.php"
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

// Load DLE helper functions (admin and frontend)
if( file_exists( ENGINE_DIR . '/inc/include/functions.inc.php' ) ) {
	require_once( ENGINE_DIR . '/inc/include/functions.inc.php' );
}
if( file_exists( ENGINE_DIR . '/modules/functions.php' ) ) {
	require_once( ENGINE_DIR . '/modules/functions.php' );
}

// Compliant fallbacks for xfields functions if not defined
if( !function_exists( 'xfieldsdataload' ) ) {
	function xfieldsdataload( $str ) {
		if( empty( $str ) ) return array();
		$res = array();
		$fields = explode( "||", (string)$str );
		foreach( $fields as $f ) {
			if( empty( $f ) ) continue;
			$pair = explode( "|", $f );
			if( isset( $pair[0] ) && $pair[0] !== '' ) {
				$res[ $pair[0] ] = isset( $pair[1] ) ? $pair[1] : '';
			}
		}
		return $res;
	}
}

if( !function_exists( 'xfieldsdatasave' ) ) {
	function xfieldsdatasave( $data ) {
		if( !is_array( $data ) || empty( $data ) ) return '';
		$out = array();
		foreach( $data as $k => $v ) {
			$out[] = $k . '|' . $v;
		}
		return implode( '||', $out );
	}
}

if( !function_exists( 'clear_cache' ) ) {
	function clear_cache( $cache_areas = array() ) {
		// No-op if cache engine not initialized
		return true;
	}
}

// Load PeeStream configuration
$c_file = ENGINE_DIR . '/data/peestream.conf.php';
$peestream_config = array();
if( file_exists( $c_file ) ) {
	require_once( $c_file );
}

$cfg = array_merge( array(
	'api_key'        => '',
	'player_domain'  => 'https://peeplayer.com',
	'api_domain'     => 'https://peestream.com',
	'xfield_name'    => 'peestream',
	'webhook_secret' => '',
	'cron_secret'    => '',
), is_array( $peestream_config ) ? $peestream_config : array() );

// Strict Security Check for HTTP execution
if( !$is_cli ) {
	header( 'Content-Type: application/json; charset=utf-8' );

	if( isset( $_SERVER['REQUEST_METHOD'] ) && strtoupper( $_SERVER['REQUEST_METHOD'] ) !== 'POST' ) {
		http_response_code( 405 );
		echo json_encode( array( 'success' => false, 'error' => 'Method Not Allowed. HTTP cron execution strictly requires POST.' ) );
		exit;
	}

	$cron_secret = !empty( $cfg['cron_secret'] ) ? trim( (string)$cfg['cron_secret'] ) : '';
	if( empty( $cron_secret ) || strlen( $cron_secret ) < 16 ) {
		http_response_code( 403 );
		echo json_encode( array( 'success' => false, 'error' => 'HTTP cron is disabled or cron_secret is not configured (minimum 16 characters). Use CLI: php -f engine/modules/peestream_cron.php' ) );
		exit;
	}

	// Webhook secret cannot be reused as cron secret for security isolation
	if( !empty( $cfg['webhook_secret'] ) && hash_equals( (string)$cfg['webhook_secret'], $cron_secret ) ) {
		http_response_code( 403 );
		echo json_encode( array( 'success' => false, 'error' => 'Security error: cron_secret must not be identical to webhook_secret.' ) );
		exit;
	}

	$incoming_secret = isset( $_POST['secret'] ) ? trim( (string)$_POST['secret'] ) : ( isset( $_SERVER['HTTP_X_CRON_SECRET'] ) ? trim( (string)$_SERVER['HTTP_X_CRON_SECRET'] ) : '' );
	if( empty( $incoming_secret ) || !hash_equals( $cron_secret, $incoming_secret ) ) {
		http_response_code( 403 );
		echo json_encode( array( 'success' => false, 'error' => 'Forbidden: Invalid or missing cron secret token.' ) );
		exit;
	}
}

// Process-level Concurrency locking (non-blocking)
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

// Reliable affected rows reader across MySQL drivers (fail-closed if unknown)
if( !function_exists( 'peestream_get_affected_rows' ) ) {
	function peestream_get_affected_rows( $db ) {
		if( method_exists( $db, 'get_affected_rows' ) ) {
			return (int) $db->get_affected_rows();
		}
		if( isset( $db->db_id ) ) {
			if( is_object( $db->db_id ) && isset( $db->db_id->affected_rows ) ) {
				return (int) $db->db_id->affected_rows;
			}
			if( is_resource( $db->db_id ) || is_object( $db->db_id ) ) {
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
if( !function_exists( 'peestream_claim_queue_item' ) ) {
	function peestream_claim_queue_item( $db, $queue_id, $owner_token = '', $timeout_seconds = 300 ) {
		$qid = intval( $queue_id );
		if( $qid <= 0 ) return false;
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
		if( !$res ) return false;
		return ( peestream_get_affected_rows( $db ) > 0 );
	}
}

// Release claimed task with specific status, error message, and strict owner_token check (no unowned leakage)
if( !function_exists( 'peestream_release_task' ) ) {
	function peestream_release_task( $db, $queue_id, $owner_token = '', $new_status = 'pending', $error_msg = '' ) {
		$qid = intval( $queue_id );
		if( $qid <= 0 ) return false;
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
if( !function_exists( 'peestream_apply_post_replacement' ) ) {
	function peestream_apply_post_replacement( $db, $post_id, $old_url, $new_code, $cfg, &$backup_id = 0 ) {
		$pid = intval( $post_id );
		if( $pid <= 0 || empty( $old_url ) || empty( $new_code ) ) {
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

		for( $attempt = 1; $attempt <= $max_retries; $attempt++ ) {
			$post = $db->super_query( "SELECT id, title, xfields, short_story, full_story FROM {$table} WHERE id='{$pid}'" );
			if( empty( $post['id'] ) ) {
				return array( 'success' => false, 'error' => "Post #{$pid} not found in database." );
			}

			// Conflict check: Ensure original_url still exists in post
			$in_xf = ( strpos( $post['xfields'], $old_url ) !== false );
			$in_short = ( strpos( $post['short_story'], $old_url ) !== false );
			$in_full = ( strpos( $post['full_story'], $old_url ) !== false );

			if( !$in_xf && !$in_short && !$in_full ) {
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
			if( !$ins_b || $cur_backup_id <= 0 ) {
				return array( 'success' => false, 'error' => "Pre-migration backup failed to save. Article modification aborted for safety." );
			}

			// Perform surgical replacement on current snapshot
			$upd_xf = $post['xfields'];
			$upd_short = $post['short_story'];
			$upd_full = $post['full_story'];

			if( function_exists( 'xfieldsdataload' ) && function_exists( 'xfieldsdatasave' ) ) {
				$xf_arr = xfieldsdataload( $upd_xf );
				$target_field_found = false;
				foreach( $xf_arr as $k => $v ) {
					if( strpos( (string)$v, $old_url ) !== false ) {
						$xf_arr[$k] = str_replace( $old_url, $new_code, $v );
						$target_field_found = true;
					}
				}
				// Primary player assignment:
				// If the targeted field was $xf_name, it was already updated above.
				// If targeted field was not $xf_name, set $xf_name ONLY if it's currently empty (do not clobber existing primary player)
				if( empty( $xf_arr[$xf_name] ) ) {
					$xf_arr[$xf_name] = $new_code;
				}
				$upd_xf = xfieldsdatasave( $xf_arr );
			} else {
				if( strpos( $upd_xf, $old_url ) !== false ) {
					$upd_xf = str_replace( $old_url, $new_code, $upd_xf );
				}
			}

			if( strpos( $upd_full, $old_url ) !== false ) {
				$upd_full = str_replace( $old_url, $new_embed, $upd_full );
			}
			if( strpos( $upd_short, $old_url ) !== false ) {
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

			if( $upd_post && $aff > 0 ) {
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
				$db->query( "UPDATE {$queue_table} SET state='ready', status='completed', owner_token='', updated_at='{$now_date}' WHERE post_id='{$pid}' AND (url_hash='{$url_hash}' OR original_url='{$safe_orig_url}') AND (owner_token='' OR updated_at < '{$cutoff}')", false );
				$recovered_count++;
			} else {
				// Article modification was interrupted or failed before save: mark backup as apply_failed and queue as pending for safe retry
				$db->query( "UPDATE {$backup_table} SET status='apply_failed' WHERE id='{$b['id']}'", false );
				// Only update queue task if it is either unowned or stale: do NOT overwrite active worker lease!
				$db->query( "UPDATE {$queue_table} SET status='pending', state='pending', owner_token='', error_msg='Recovered from interrupted partial update', updated_at='{$now_date}' WHERE post_id='{$pid}' AND (url_hash='{$url_hash}' OR original_url='{$safe_orig_url}') AND (owner_token='' OR updated_at < '{$cutoff}')", false );
				$recovered_count++;
			}
		}
		return $recovered_count;
	}
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

// Load API call & helper functions if not available
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
		curl_setopt( $ch, CURLOPT_USERAGENT, 'PeeStream-DLE-Cron/1.0.3' );
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

// Canonical schema check & interrupted recovery prior to processing
if ( ! peestream_ensure_schema( $db ) ) {
	echo json_encode( array( 'success' => false, 'error' => 'Database schema verification failed. Cron processing aborted.' ) );
	exit;
}
peestream_recover_interrupted_tasks( $db, $cfg );

$queue_table = PREFIX . "_peestream_queue";
$backup_table = PREFIX . "_peestream_backups";
$table = PREFIX . "_post";

$stale_time = date( 'Y-m-d H:i:s', time() - 300 );
$q_res = $db->query( "SELECT * FROM {$queue_table} WHERE status IN ('pending', 'queued') OR (status='checking' AND updated_at < '{$stale_time}') ORDER BY id ASC LIMIT 25" );
$now_date = date( 'Y-m-d H:i:s' );
$ready_count = 0;
$checked_count = 0;
$conflict_count = 0;
$failed_count = 0;
$logs = array();

while( $item = $db->get_row( $q_res ) ) {
	$worker_token = bin2hex( random_bytes( 16 ) );
	$safe_token = $db->safesql( $worker_token );

	// Atomic claim check with owner_token fencing: ensure no concurrent worker (cron or manual AJAX) processes this item
	if( ! peestream_claim_queue_item( $db, $item['id'], $worker_token ) ) {
		continue;
	}

	$checked_count++;
	$pid = intval( $item['post_id'] );
	$fc = trim( (string)$item['filecode'] );
	$old_url = $item['original_url'];

	// Bounded retry handling if remote upload had failed or filecode is missing
	if( empty( $fc ) ) {
		$retry_count = isset( $item['retry_count'] ) ? intval( $item['retry_count'] ) : 0;
		if( $retry_count < 3 ) {
			$up_res = peestream_api_call( 'api/upload/url', $cfg['api_key'], $cfg['api_domain'], array( 'url' => $old_url ) );
			if( $up_res['success'] && !empty( $up_res['data']['filecode'] ) ) {
				$fc = $up_res['data']['filecode'];
				$safe_fc = $db->safesql( $fc );
				$db->query( "UPDATE {$queue_table} SET filecode='{$safe_fc}', status='pending', retry_count=retry_count+1, owner_token='', updated_at='{$now_date}' WHERE id='{$item['id']}' AND owner_token='{$safe_token}'" );
				$logs[] = "[RETRY OK] Post #" . $pid . ": Upload succeeded on retry, assigned filecode " . $fc;
				continue;
			} else {
				$err_msg = !empty( $up_res['error'] ) ? $up_res['error'] : 'Upload retry failed.';
				$safe_err = $db->safesql( $err_msg );
				$new_st = ( $retry_count + 1 >= 3 ) ? 'failed' : 'pending';
				$db->query( "UPDATE {$queue_table} SET status='{$new_st}', retry_count=retry_count+1, owner_token='', error_msg='{$safe_err}', updated_at='{$now_date}' WHERE id='{$item['id']}' AND owner_token='{$safe_token}'" );
				$failed_count++;
				$logs[] = "[RETRY FAIL] Post #" . $pid . ": " . $err_msg;
				continue;
			}
		} else {
			$db->query( "UPDATE {$queue_table} SET status='failed', owner_token='', error_msg='Max retry count (3) exceeded for URL upload.', updated_at='{$now_date}' WHERE id='{$item['id']}' AND owner_token='{$safe_token}'" );
			$failed_count++;
			continue;
		}
	}

	$info = peestream_api_call( 'api/file/info', $cfg['api_key'], $cfg['api_domain'], array( 'file_code' => $fc ) );
	$f_info = peestream_extract_file_info( $info, $fc );
	
	if( $f_info && isset( $f_info['canplay'] ) && intval( $f_info['canplay'] ) === 1 ) {
		// Video is verified READY (canplay=1)! Apply optimistic post replacement
		$backup_id = 0;
		$repl_res = peestream_apply_post_replacement( $db, $pid, $old_url, $fc, $cfg, $backup_id );
		if( $repl_res['success'] ) {
			$upd_q = $db->query( "UPDATE {$queue_table} SET state='ready', status='completed', owner_token='', updated_at='{$now_date}' WHERE id='{$item['id']}' AND owner_token='{$safe_token}'" );
			if( $upd_q && peestream_get_affected_rows( $db ) > 0 ) {
				$ready_count++;
				$logs[] = "[READY] Post #" . $pid . ": Transcoding complete (canplay=1)! PeeStream player attached.";
			} else {
				$logs[] = "[FENCING ERROR] Post #" . $pid . ": Worker lost ownership of task #{$item['id']} before completion.";
			}
		} else {
			if( !empty( $repl_res['conflict'] ) ) {
				$safe_err = $db->safesql( $repl_res['error'] );
				$db->query( "UPDATE {$queue_table} SET state='conflict', status='conflict', owner_token='', error_msg='{$safe_err}', updated_at='{$now_date}' WHERE id='{$item['id']}' AND owner_token='{$safe_token}'" );
				$conflict_count++;
				$logs[] = "[CONFLICT] Post #" . $pid . ": Target original URL (" . $old_url . ") was removed or modified by editor. Replacement skipped.";
			} else {
				peestream_release_task( $db, $item['id'], $worker_token, 'failed', $repl_res['error'] );
				$failed_count++;
				$logs[] = "[ERROR] Post #" . $pid . ": " . $repl_res['error'];
			}
		}
	} elseif( $f_info && isset( $f_info['state'] ) && $f_info['state'] === 'failed' ) {
		$db->query( "UPDATE {$queue_table} SET state='failed', status='failed', owner_token='', updated_at='{$now_date}' WHERE id='{$item['id']}' AND owner_token='{$safe_token}'" );
		$failed_count++;
		$logs[] = "[FAILED] Post #" . $pid . ": Remote transcode failed on PeeStream. Original link preserved.";
	} else {
		$cur_state = ( $f_info && isset( $f_info['state'] ) ) ? $f_info['state'] : 'pending';
		$safe_state = $db->safesql( $cur_state );
		$db->query( "UPDATE {$queue_table} SET state='{$safe_state}', updated_at='{$now_date}' WHERE id='{$item['id']}' AND owner_token='{$safe_token}'" );
		peestream_release_task( $db, $item['id'], $worker_token, 'pending' );
	}
}

if( $ready_count > 0 && function_exists( 'clear_cache' ) ) {
	clear_cache( array( 'news_' ) );
}

// Release process lock
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

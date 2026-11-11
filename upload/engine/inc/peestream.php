<?php
/*
=============================================================================
PeeStream Video Player & Tools - DataLife Engine Addon
Version: 1.0.0
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
), $peestream_config );

// Helper function for secure outbound cURL requests to PeeStream API
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
	curl_setopt( $ch, CURLOPT_USERAGENT, 'PeeStream-DLE-Plugin/1.0' );
	
	$response = curl_exec( $ch );
	$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	$curl_err = curl_error( $ch );
	curl_close( $ch );
	
	if( $curl_err ) {
		return array( 'success' => false, 'error' => 'cURL Error: ' . htmlspecialchars( $curl_err ) );
	}
	
	$data = json_decode( $response, true );
	if( !$data ) {
		return array( 'success' => false, 'error' => 'Invalid server response (HTTP ' . $http_code . ').' );
	}
	
	if( isset( $data['status'] ) && $data['status'] != 200 ) {
		return array( 'success' => false, 'error' => isset( $data['msg'] ) ? $data['msg'] : 'API Error (' . $data['status'] . ')' );
	}
	
	return array( 'success' => true, 'data' => isset( $data['result'] ) ? $data['result'] : $data );
}

// Helper function to detect competitor video hoster links
function peestream_find_competitors( $text ) {
	$found = array();
	$patterns = array(
		'MixDrop' => '#https?://(?:www\.)?mixdrop\.(?:co|to|sx|bz|ch|ag|vc|club|download)/(?:f|e)/([a-zA-Z0-9_\-]+)#i',
		'Doodstream' => '#https?://(?:www\.)?(?:doodstream\.com|dood\.(?:to|so|la|ws|sh|watch|pm|wf|re|cx)|ds2play\.com)/(?:d|e)/([a-zA-Z0-9_\-]+)#i',
		'Streamtape' => '#https?://(?:www\.)?(?:streamtape\.(?:com|to|net|xyz)|streamta\.pe|strtape\.cloud)/(?:v|e)/([a-zA-Z0-9_\-]+)#i',
		'Filemoon' => '#https?://(?:www\.)?filemoon\.(?:sx|to|wf|nl)/(?:d|e)/([a-zA-Z0-9_\-]+)#i',
		'VOE' => '#https?://(?:www\.)?(?:voe\.sx|voe-network\.net)/(?:e/)?([a-zA-Z0-9_\-]+)#i',
		'Vidguard' => '#https?://(?:www\.)?(?:vidguard\.to|vgfplay\.com)/(?:d|e)/([a-zA-Z0-9_\-]+)#i',
		'Luluvido' => '#https?://(?:www\.)?luluvido\.(?:net|com)/(?:d|e)/([a-zA-Z0-9_\-]+)#i',
		'Upstream' => '#https?://(?:www\.)?upstream\.to/([a-zA-Z0-9_\-]+)#i',
	);
	
	foreach( $patterns as $hoster => $regex ) {
		if( preg_match_all( $regex, (string)$text, $m ) ) {
			foreach( $m[0] as $url ) {
				$found[] = array( 'hoster' => $hoster, 'url' => $url );
			}
		}
	}
	return $found;
}

$action = isset( $_REQUEST['action'] ) ? trim( $_REQUEST['action'] ) : '';

// -------------------------------------------------------------
// AJAX: Scan database posts for competitor links
// -------------------------------------------------------------
if( $action == 'migrator_scan' ) {
	header( 'Content-Type: application/json; charset=utf-8' );
	if( empty( $_REQUEST['user_hash'] ) || $_REQUEST['user_hash'] != $dle_login_hash ) {
		echo json_encode( array( 'success' => false, 'error' => 'Invalid security token.' ) );
		exit;
	}
	
	$table = PREFIX . "_post";
	$sql = "SELECT id, title, xfields, short_story, full_story FROM {$table} 
			WHERE xfields LIKE '%mixdrop%' OR xfields LIKE '%dood%' OR xfields LIKE '%streamtape%' OR xfields LIKE '%filemoon%' OR xfields LIKE '%voe.%' OR xfields LIKE '%vidguard%' OR xfields LIKE '%luluvido%' OR xfields LIKE '%upstream%'
			   OR short_story LIKE '%mixdrop%' OR short_story LIKE '%dood%' OR short_story LIKE '%streamtape%' OR short_story LIKE '%filemoon%'
			   OR full_story LIKE '%mixdrop%' OR full_story LIKE '%dood%' OR full_story LIKE '%streamtape%' OR full_story LIKE '%filemoon%'
			ORDER BY id DESC LIMIT 1000";
	
	$query = $db->query( $sql );
	$items = array();
	
	while( $row = $db->get_row( $query ) ) {
		$combined = $row['xfields'] . ' ' . $row['short_story'] . ' ' . $row['full_story'];
		$urls = peestream_find_competitors( $combined );
		if( !empty( $urls ) ) {
			$items[] = array(
				'id' => intval( $row['id'] ),
				'title' => htmlspecialchars( $row['title'] ),
				'hoster' => $urls[0]['hoster'],
				'url' => $urls[0]['url'],
				'total_urls' => count( $urls )
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
// AJAX: Migrate single post (Batch worker step)
// -------------------------------------------------------------
if( $action == 'migrator_process_one' ) {
	header( 'Content-Type: application/json; charset=utf-8' );
	if( empty( $_REQUEST['user_hash'] ) || $_REQUEST['user_hash'] != $dle_login_hash ) {
		echo json_encode( array( 'success' => false, 'error' => 'Invalid security token.' ) );
		exit;
	}
	
	$post_id = intval( $_POST['post_id'] );
	$old_url = trim( $_POST['url'] );
	
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
	
	// 1. Call PeeStream Remote API to queue download and transcode
	$res = peestream_api_call( 'api/upload/url', $cfg['api_key'], $cfg['api_domain'], array(
		'url' => $old_url,
		'new_title' => $row['title'],
		'fld_id' => intval( $cfg['default_folder_id'] )
	) );
	
	if( !$res['success'] || empty( $res['data']['filecode'] ) ) {
		$err = isset( $res['error'] ) ? $res['error'] : 'PeeStream API failed to queue video.';
		echo json_encode( array( 'success' => false, 'error' => $err ) );
		exit;
	}
	
	$new_code = $res['data']['filecode'];
	$new_embed = rtrim( $cfg['player_domain'], '/' ) . '/e/' . $new_code;
	
	// 2. Replace old URL with new PeeStream code/embed
	$xf_name = !empty( $cfg['xfield_name'] ) ? $cfg['xfield_name'] : 'peestream';
	$upd_xf = $row['xfields'];
	$upd_short = $row['short_story'];
	$upd_full = $row['full_story'];
	
	// Replace in xfields if old URL is present
	if( strpos( $upd_xf, $old_url ) !== false ) {
		$upd_xf = str_replace( $old_url, $new_code, $upd_xf );
	}
	
	// Set designated xfield
	if( function_exists( 'xfieldsdataload' ) && function_exists( 'xfieldsdatasave' ) ) {
		$xf_arr = xfieldsdataload( $upd_xf );
		$xf_arr[$xf_name] = $new_code;
		$upd_xf = xfieldsdatasave( $xf_arr );
	}
	
	// Replace in full story or short story text
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
	
	if( function_exists( 'clear_cache' ) ) {
		clear_cache( array( 'news_' ) );
	}
	
	echo json_encode( array(
		'success' => true,
		'post_id' => $post_id,
		'filecode' => $new_code,
		'msg' => 'Successfully replaced with PeeStream code: ' . $new_code
	) );
	exit;
}

// -------------------------------------------------------------
// POST: Save settings
// -------------------------------------------------------------
$msg_alert = '';
if( $action == 'save' ) {
	if( $_REQUEST['user_hash'] == "" OR $_REQUEST['user_hash'] != $dle_login_hash ) {
		die( "Hacking attempt! Invalid security token." );
	}
	
	$save_cfg = array(
		'api_key' => trim( strip_tags( $_POST['api_key'] ) ),
		'player_domain' => rtrim( trim( strip_tags( $_POST['player_domain'] ) ), '/' ),
		'api_domain' => rtrim( trim( strip_tags( $_POST['api_domain'] ) ), '/' ),
		'default_folder_id' => intval( $_POST['default_folder_id'] ),
		'responsive' => isset( $_POST['responsive'] ) ? '1' : '0',
		'width' => trim( strip_tags( $_POST['width'] ) ),
		'height' => trim( strip_tags( $_POST['height'] ) ),
		'aspect_ratio' => trim( strip_tags( $_POST['aspect_ratio'] ) ),
		'autoplay' => isset( $_POST['autoplay'] ) ? '1' : '0',
		'allow_fullscreen' => isset( $_POST['allow_fullscreen'] ) ? '1' : '0',
		'xfield_name' => preg_replace( '/[^a-zA-Z0-9_\-]/', '', strtolower( trim( $_POST['xfield_name'] ) ) ),
		'webhook_secret' => trim( strip_tags( $_POST['webhook_secret'] ) ),
	);
	
	if( empty( $save_cfg['player_domain'] ) ) $save_cfg['player_domain'] = 'https://peeplayer.com';
	if( empty( $save_cfg['api_domain'] ) ) $save_cfg['api_domain'] = 'https://peestream.com';
	if( empty( $save_cfg['xfield_name'] ) ) $save_cfg['xfield_name'] = 'peestream';
	if( empty( $save_cfg['aspect_ratio'] ) ) $save_cfg['aspect_ratio'] = '16:9';
	
	$conf_content = "<?PHP\n\n// PeeStream DLE Addon Configuration\n// Last updated: " . date( "d.m.Y H:i:s" ) . "\n\n\$peestream_config = array (\n";
	foreach( $save_cfg as $k => $v ) {
		$conf_content .= "\t'" . addslashes( $k ) . "' => '" . addslashes( $v ) . "',\n";
	}
	$conf_content .= ");\n\n?>";
	
	$fp = @fopen( $conf_file, "w" );
	if( $fp ) {
		fwrite( $fp, $conf_content );
		fclose( $fp );
		$cfg = $save_cfg;
		$msg_alert = '<div class="alert alert-success alert-styled-left alert-arrow-left alert-bordered"><b>Success!</b> PeeStream module settings have been saved successfully.</div>';
	} else {
		$msg_alert = '<div class="alert alert-danger alert-styled-left alert-arrow-left alert-bordered"><b>Error!</b> Unable to write to ' . htmlspecialchars( $conf_file ) . '. Please check file permissions (CHMOD 0666 or 0777 required).</div>';
	}
}

// Check API status and retrieve account information
$api_status_html = '';
$folders_options = '<option value="0">-- Main Directory (Root) --</option>';

if( !empty( $cfg['api_key'] ) ) {
	$info_res = peestream_api_call( 'api/info', $cfg['api_key'], $cfg['api_domain'] );
	if( $info_res['success'] ) {
		$u_data = $info_res['data'];
		$usr_name = htmlspecialchars( isset( $u_data['username'] ) ? $u_data['username'] : ( isset( $u_data['email'] ) ? $u_data['email'] : 'Uploader' ) );
		$usr_bal = htmlspecialchars( isset( $u_data['balance'] ) ? $u_data['balance'] : '0.00' );
		$usr_vids = htmlspecialchars( isset( $u_data['total_videos'] ) ? $u_data['total_videos'] : ( isset( $u_data['videos'] ) ? $u_data['videos'] : '0' ) );
		$usr_views = htmlspecialchars( isset( $u_data['total_views'] ) ? $u_data['total_views'] : ( isset( $u_data['views'] ) ? $u_data['views'] : '0' ) );
		
		$api_status_html = '<div class="alert alert-success alert-styled-left alert-arrow-left alert-bordered" style="margin-bottom:20px;">'
			. '<h6 class="alert-heading text-semibold">Connected to PeeStream Account: <b>' . $usr_name . '</b></h6>'
			. '<span>Current Balance: <b>$' . $usr_bal . '</b> | Total Videos: <b>' . $usr_vids . '</b> | Total Views: <b>' . $usr_views . '</b></span>'
			. '</div>';
			
		$folders_res = peestream_api_call( 'api/folderi', $cfg['api_key'], $cfg['api_domain'] );
		if( $folders_res['success'] && is_array( $folders_res['data'] ) ) {
			$f_list = isset( $folders_res['data']['folders'] ) ? $folders_res['data']['folders'] : $folders_res['data'];
			foreach( $f_list as $fld ) {
				$fid = intval( isset( $fld['id'] ) ? $fld['id'] : 0 );
				$fnm = htmlspecialchars( isset( $fld['name'] ) ? $fld['name'] : ( 'Folder #' . $fid ) );
				$sel = ( $cfg['default_folder_id'] == $fid ) ? ' selected' : '';
				$folders_options .= '<option value="' . $fid . '"' . $sel . '>' . $fnm . ' (ID: ' . $fid . ')</option>';
			}
		}
	} else {
		$api_status_html = '<div class="alert alert-warning alert-styled-left alert-arrow-left alert-bordered" style="margin-bottom:20px;">'
			. '<h6 class="alert-heading text-semibold">PeeStream API Warning:</h6>'
			. '<span>' . htmlspecialchars( $info_res['error'] ) . ' Please verify your API key.</span>'
			. '</div>';
	}
} else {
	$api_status_html = '<div class="alert alert-info alert-styled-left alert-arrow-left alert-bordered" style="margin-bottom:20px;">'
		. '<b>Notice:</b> Enter your <b>PeeStream API key</b> to enable video player integration, library imports and auto-migration.'
		. '</div>';
}

$chk_responsive = ( $cfg['responsive'] == '1' ) ? ' checked' : '';
$style_custom_size = ( $cfg['responsive'] == '1' ) ? 'display:none;' : '';
$chk_autoplay = ( $cfg['autoplay'] == '1' ) ? ' checked' : '';
$chk_fullscreen = ( $cfg['allow_fullscreen'] == '1' ) ? ' checked' : '';

$val_api_key = htmlspecialchars( $cfg['api_key'] );
$val_player_domain = htmlspecialchars( $cfg['player_domain'] );
$val_api_domain = htmlspecialchars( $cfg['api_domain'] );
$val_xfield_name = htmlspecialchars( $cfg['xfield_name'] );
$val_width = htmlspecialchars( $cfg['width'] );
$val_height = htmlspecialchars( $cfg['height'] );
$val_webhook_secret = htmlspecialchars( $cfg['webhook_secret'] );

echoheader( "PeeStream Player &amp; Tools", "Settings and integration tools for PeeStream on DataLife Engine" );

echo <<<HTML
{$msg_alert}
{$api_status_html}

<!-- Navigation Tabs -->
<div class="navbar navbar-default navbar-component navbar-xs" style="margin-bottom:20px;">
	<ul class="nav navbar-nav">
		<li class="active"><a href="#tab_settings" data-toggle="tab"><i class="fa fa-sliders position-left"></i> 1. Settings &amp; Status</a></li>
		<li><a href="#tab_migrator" data-toggle="tab"><i class="fa fa-exchange position-left"></i> 2. Auto-Migrator (MixDrop/Dood Replacement)</a></li>
		<li><a href="#tab_docs" data-toggle="tab"><i class="fa fa-book position-left"></i> 3. Template Guide</a></li>
	</ul>
</div>

<div class="tab-content">
	<!-- TAB 1: SETTINGS -->
	<div class="tab-pane active" id="tab_settings">
		<div class="panel panel-default">
			<div class="panel-heading">
				<h6 class="panel-title text-semibold"><i class="fa fa-play-circle position-left"></i> PeeStream Configuration &amp; Integration</h6>
			</div>
			<div class="panel-body">
				<form method="post" action="" class="form-horizontal">
					<input type="hidden" name="action" value="save">
					<input type="hidden" name="user_hash" value="{$dle_login_hash}">

					<div class="form-group">
						<label class="control-label col-sm-3 text-semibold">PeeStream API Key:</label>
						<div class="col-sm-9">
							<input type="text" name="api_key" value="{$val_api_key}" class="form-control" placeholder="Enter your private API key">
							<span class="help-block text-muted">Obtain your private API key from your PeeStream Uploader Panel at <b>Settings &gt; API</b>.</span>
						</div>
					</div>

					<div class="form-group">
						<label class="control-label col-sm-3 text-semibold">Player Domain:</label>
						<div class="col-sm-9">
							<input type="text" name="player_domain" value="{$val_player_domain}" class="form-control" placeholder="https://peeplayer.com">
							<span class="help-block text-muted">Default domain is <code>https://peeplayer.com</code>. If you use a custom branded CNAME domain, enter it here.</span>
						</div>
					</div>

					<div class="form-group">
						<label class="control-label col-sm-3 text-semibold">PeeStream API Domain:</label>
						<div class="col-sm-9">
							<input type="text" name="api_domain" value="{$val_api_domain}" class="form-control" placeholder="https://peestream.com">
							<span class="help-block text-muted">Default is <code>https://peestream.com</code>.</span>
						</div>
					</div>

					<div class="form-group">
						<label class="control-label col-sm-3 text-semibold">Default Upload Folder:</label>
						<div class="col-sm-9">
							<select name="default_folder_id" class="form-control">
								{$folders_options}
							</select>
							<span class="help-block text-muted">Folder on your PeeStream account where imported and uploaded videos will be placed.</span>
						</div>
					</div>

					<div class="form-group">
						<label class="control-label col-sm-3 text-semibold">DLE Extra Field (XField Name):</label>
						<div class="col-sm-9">
							<input type="text" name="xfield_name" value="{$val_xfield_name}" class="form-control" placeholder="peestream">
							<span class="help-block text-muted">The name of the custom post field (xfield) used to store the PeeStream video code (e.g., <code>peestream</code> or <code>video_player</code>).</span>
						</div>
					</div>

					<hr>
					<h6 class="text-semibold"><i class="fa fa-television position-left"></i> Player Appearance &amp; Playback</h6>

					<div class="form-group">
						<label class="control-label col-sm-3 text-semibold">Responsive Player (Fluid 16:9):</label>
						<div class="col-sm-9">
							<div class="checkbox">
								<label><input type="checkbox" name="responsive" value="1" id="chk_responsive"{$chk_responsive}> Automatic fluid scaling (recommended, 100% width with 16:9 aspect ratio)</label>
							</div>
						</div>
					</div>

					<div class="form-group" id="block_custom_size" style="{$style_custom_size}">
						<label class="control-label col-sm-3 text-semibold">Fixed Width and Height:</label>
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
								<label><input type="checkbox" name="autoplay" value="1"{$chk_autoplay}> Start video automatically on page load (muted by default in modern browsers)</label>
							</div>
						</div>
					</div>

					<div class="form-group">
						<label class="control-label col-sm-3 text-semibold">Allow Fullscreen Mode:</label>
						<div class="col-sm-9">
							<div class="checkbox">
								<label><input type="checkbox" name="allow_fullscreen" value="1"{$chk_fullscreen}> Allow fullscreen playback (allowfullscreen)</label>
							</div>
						</div>
					</div>

					<div class="form-group">
						<label class="control-label col-sm-3 text-semibold">Webhook Secret Key:</label>
						<div class="col-sm-9">
							<input type="text" name="webhook_secret" value="{$val_webhook_secret}" class="form-control" placeholder="Random secret token to protect webhook endpoint">
							<span class="help-block text-muted">Used to authenticate incoming notifications about new TV episodes directly from PeeStream servers.</span>
						</div>
					</div>

					<div class="form-group" style="margin-top:25px;">
						<div class="col-sm-9 col-sm-offset-3">
							<button type="submit" class="btn btn-primary"><i class="fa fa-floppy-o position-left"></i> Save Settings</button>
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
				<h6 class="panel-title text-semibold"><i class="fa fa-exchange position-left"></i> Auto-Migrator: 1-Click Replacement of Broken MixDrop, Doodstream &amp; Streamtape Links</h6>
			</div>
			<div class="panel-body">
				<div class="alert alert-info" style="margin-bottom:20px;">
					<i class="fa fa-info-circle position-left"></i>
					This tool scans your article database (content &amp; custom xfields) and identifies links from dying hosters (MixDrop, Doodstream, Streamtape, Filemoon, VOE, etc.). 
					PeeStream automatically fetches the videos in the background and replaces them with clean, ultra-fast <b>PeeStream</b> players without traffic loss!
				</div>

				<div style="display:flex;gap:15px;align-items:center;margin-bottom:20px;">
					<button type="button" id="btn_migrator_scan" class="btn btn-info" onclick="mgrStartScan()">
						<i class="fa fa-search position-left"></i> Scan Database for Broken Links
					</button>
					<button type="button" id="btn_migrator_start" class="btn btn-success" style="display:none;" onclick="mgrStartBatch()">
						<i class="fa fa-play position-left"></i> Start Batch Migration
					</button>
					<button type="button" id="btn_migrator_pause" class="btn btn-warning" style="display:none;" onclick="mgrPauseBatch()">
						<i class="fa fa-pause position-left"></i> Pause
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
							<div class="text-muted text-size-small">Successfully Replaced</div>
						</div>
					</div>
					<div class="col-sm-3">
						<div class="panel panel-body bg-danger-600 text-center" style="padding:15px;">
							<h3 class="no-margin text-semibold" id="cnt_errors">0</h3>
							<div class="text-muted text-size-small">Import Errors</div>
						</div>
					</div>
					<div class="col-sm-3">
						<div class="panel panel-body bg-primary-600 text-center" style="padding:15px;">
							<h3 class="no-margin text-semibold" id="cnt_progress">0%</h3>
							<div class="text-muted text-size-small">Total Progress</div>
						</div>
					</div>
				</div>

				<!-- Progress bar -->
				<div class="progress" id="mgr_progress_bar_box" style="display:none;height:18px;margin-bottom:20px;">
					<div class="progress-bar progress-bar-striped active progress-bar-success" id="mgr_progress_bar" style="width:0%;">0%</div>
				</div>

				<!-- Live Event Log -->
				<h6 class="text-semibold"><i class="fa fa-list position-left"></i> Real-time Migration Event Log:</h6>
				<div id="mgr_log_box" style="background:#0f172a;color:#f8fafc;font-family:monospace;font-size:12px;padding:15px;border-radius:6px;height:240px;overflow-y:auto;border:1px solid #1e293b;">
					<div style="color:#94a3b8;">Click "Scan Database for Broken Links" to start analyzing your posts.</div>
				</div>
			</div>
		</div>
	</div>

	<!-- TAB 3: TEMPLATE GUIDE -->
	<div class="tab-pane" id="tab_docs">
		<div class="panel panel-info">
			<div class="panel-heading">
				<h6 class="panel-title text-semibold"><i class="fa fa-info-circle position-left"></i> Template Integration Guide (Template Tags)</h6>
			</div>
			<div class="panel-body">
				<p>To display the PeeStream video player in your website articles, open your active theme template file <code>templates/{your_theme}/fullstory.tpl</code> and insert the following code where you want the player to appear:</p>
				<pre><code>[xfgiven_{$val_xfield_name}]
&lt;div class="peestream-embed-container"&gt;
    {peestream_player}
&lt;/div&gt;
[/xfgiven_{$val_xfield_name}]</code></pre>

				<p style="margin-top:15px;">Available template tags:</p>
				<ul>
					<li><code>{peestream_player}</code> - Generates the complete responsive player with controls, subtitles, and ad optimization.</li>
					<li><code>[peestream] ... [/peestream]</code> - Conditional block displayed only if the article contains a valid PeeStream video.</li>
					<li><code>[!peestream] ... [/!peestream]</code> - Conditional block displayed if the article DOES NOT have a video.</li>
					<li><code>{peestream_code}</code> - Outputs raw video code (useful for custom multi-server tabbed interfaces).</li>
					<li><code>{peestream_url}</code> - Outputs direct embed URL.</li>
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

function mgrLog(msg, color) {
	var box = document.getElementById("mgr_log_box");
	var d = document.createElement("div");
	d.style.color = color || "#f8fafc";
	d.style.marginBottom = "3px";
	var timeStr = new Date().toLocaleTimeString();
	d.innerHTML = "<span style='color:#64748b;'>[" + timeStr + "]</span> " + msg;
	box.appendChild(d);
	box.scrollTop = box.scrollHeight;
}

function mgrStartScan() {
	var btn = document.getElementById("btn_migrator_scan");
	btn.disabled = true;
	btn.innerHTML = "<i class='fa fa-spinner fa-spin position-left'></i> Scanning Database...";
	mgrLog("Starting database analysis (table dle_post)...", "#38bdf8");
	
	fetch("?mod=peestream&action=migrator_scan&user_hash=" + DLE_HASH)
		.then(function(r) { return r.json(); })
		.then(function(data) {
			btn.disabled = false;
			btn.innerHTML = "<i class='fa fa-search position-left'></i> Scan Again";
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
					mgrLog("Scan completed! Found <b>" + mgrTotal + "</b> articles containing competitor hoster links.", "#4ade80");
					document.getElementById("btn_migrator_start").style.display = "inline-block";
				} else {
					mgrLog("Congratulations! No competitor links found in your database.", "#a3e635");
				}
			} else {
				mgrLog("Scan failed: " + (data.error || "Unknown error"), "#f87171");
			}
		})
		.catch(function(err) {
			btn.disabled = false;
			btn.innerHTML = "<i class='fa fa-search position-left'></i> Scan Again";
			mgrLog("Network error: " + err, "#f87171");
		});
}

function mgrStartBatch() {
	if(mgrQueue.length === 0) return;
	mgrRunning = true;
	document.getElementById("btn_migrator_start").style.display = "none";
	document.getElementById("btn_migrator_pause").style.display = "inline-block";
	document.getElementById("mgr_progress_bar_box").style.display = "block";
	mgrLog("Starting batch link migration to PeeStream...", "#38bdf8");
	mgrProcessNext();
}

function mgrPauseBatch() {
	mgrRunning = false;
	document.getElementById("btn_migrator_pause").style.display = "none";
	document.getElementById("btn_migrator_start").style.display = "inline-block";
	mgrLog("Migration paused by user.", "#fbbf24");
}

function mgrProcessNext() {
	if(!mgrRunning) return;
	if(mgrQueue.length === 0) {
		mgrRunning = false;
		document.getElementById("btn_migrator_pause").style.display = "none";
		document.getElementById("btn_migrator_start").style.display = "none";
		mgrLog("ALL ARTICLES PROCESSED! Migration completed successfully.", "#4ade80");
		return;
	}
	
	var item = mgrQueue.shift();
	var fd = new FormData();
	fd.append("action", "migrator_process_one");
	fd.append("user_hash", DLE_HASH);
	fd.append("post_id", item.id);
	fd.append("url", item.url);
	
	fetch("?mod=peestream", { method: "POST", body: fd })
		.then(function(r) { return r.json(); })
		.then(function(data) {
			if(data.success) {
				mgrDone++;
				document.getElementById("cnt_migrated").innerText = mgrDone;
				mgrLog("Post #" + item.id + " ('" + item.title + "') -> [" + item.hoster + "] replaced with PeeStream code: <b>" + data.filecode + "</b>", "#4ade80");
			} else {
				mgrErrors++;
				document.getElementById("cnt_errors").innerText = mgrErrors;
				mgrLog("Post #" + item.id + " ('" + item.title + "') -> Error: " + (data.error || "Failed"), "#f87171");
			}
			
			var pct = Math.round(((mgrDone + mgrErrors) / mgrTotal) * 100);
			document.getElementById("cnt_progress").innerText = pct + "%";
			var pb = document.getElementById("mgr_progress_bar");
			pb.style.width = pct + "%";
			pb.innerText = pct + "%";
			
			if(mgrRunning) {
				setTimeout(mgrProcessNext, 400); // 400ms interval for server stability
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

<?php
/*
=============================================================================
PeeStream Video Player & Tools - DataLife Engine Addon
Module: AJAX Editor Modal & Video Inserter (Hardened)
File: engine/ajax/peestream_modal.php
Version: 1.0.4
=============================================================================
*/

@error_reporting( E_ALL ^ E_WARNING ^ E_NOTICE );
@ini_set( 'display_errors', false );
@ini_set( 'html_errors', false );

define( 'DATALIFEENGINE', true );
define( 'ROOT_DIR', substr( dirname( __FILE__ ), 0, -12 ) );
define( 'ENGINE_DIR', ROOT_DIR . '/engine' );

// Load DLE Core & Plugins
if ( file_exists( ENGINE_DIR . '/classes/plugins.class.php' ) ) {
	include_once ENGINE_DIR . '/classes/plugins.class.php';
}

$inc_func = ENGINE_DIR . '/inc/include/functions.inc.php';
if ( file_exists( $inc_func ) ) {
	require_once ( $inc_func );
}

if ( function_exists( 'dle_session' ) ) {
	dle_session();
}

$sitelogin = ENGINE_DIR . '/modules/sitelogin.php';
if ( file_exists( $sitelogin ) ) {
	require_once ( $sitelogin );
}

// 1. User authentication & authorization check
if ( empty( $is_logged ) || ! $is_logged ) {
	header( 'Content-Type: application/json; charset=utf-8' );
	http_response_code( 401 );
	echo json_encode( array( 'success' => false, 'error' => 'Access denied. You must be logged in to DLE.' ) );
	exit;
}

// Permission to add/edit news: Group 1 = Admin, 2 = Chief Editor, 3 = Editor/Journalist
$user_group = isset( $member_id['user_group'] ) ? intval( $member_id['user_group'] ) : 5;
if ( $user_group > 3 && $user_group !== 1 ) {
	header( 'Content-Type: application/json; charset=utf-8' );
	http_response_code( 403 );
	echo json_encode( array( 'success' => false, 'error' => 'Permission denied. Your user group cannot publish videos.' ) );
	exit;
}

// 2. Load PeeStream Configuration
$conf_file = ENGINE_DIR . '/data/peestream.conf.php';
if ( file_exists( $conf_file ) ) {
	require_once ( $conf_file );
} else {
	$peestream_config = array();
}

$cfg = array_merge( array(
	'api_key'           => '',
	'player_domain'     => 'https://peeplayer.com',
	'api_domain'        => 'https://peestream.com',
	'default_folder_id' => '0',
	'xfield_name'       => 'peestream',
), isset( $peestream_config ) ? $peestream_config : array() );

$action = isset( $_REQUEST['action'] ) ? trim( $_REQUEST['action'] ) : 'ui';

// 3. CSRF Verification for state-changing AJAX actions
$dle_hash = ! empty( $dle_login_hash ) ? $dle_login_hash : ( ! empty( $_SESSION['dle_login_hash'] ) ? $_SESSION['dle_login_hash'] : '' );

if ( $action !== 'ui' ) {
	header( 'Content-Type: application/json; charset=utf-8' );
	$req_hash = isset( $_REQUEST['user_hash'] ) ? trim( $_REQUEST['user_hash'] ) : '';

	if ( empty( $dle_hash ) || empty( $req_hash ) || ! hash_equals( (string)$dle_hash, (string)$req_hash ) ) {
		http_response_code( 403 );
		echo json_encode( array( 'success' => false, 'error' => 'Security token invalid (CSRF failure). Please refresh the page.' ) );
		exit;
	}
}

// Helper: Secure cURL call to PeeStream Cloud API
function peestream_ajax_api( $endpoint, $params, $cfg ) {
	if ( empty( $cfg['api_key'] ) ) {
		return array( 'success' => false, 'error' => 'PeeStream API key is not configured in Admin Panel.' );
	}

	$params['key'] = $cfg['api_key'];
	$url = rtrim( $cfg['api_domain'], '/' ) . '/' . ltrim( $endpoint, '/' ) . '?' . http_build_query( $params );

	$ch = curl_init();
	curl_setopt( $ch, CURLOPT_URL, $url );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $ch, CURLOPT_TIMEOUT, 20 );
	curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 8 );
	curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, true );
	curl_setopt( $ch, CURLOPT_USERAGENT, 'PeeStream-DLE-Modal/1.0.4' );

	$response = curl_exec( $ch );
	$curl_err = curl_error( $ch );
	curl_close( $ch );

	if ( $curl_err ) {
		return array( 'success' => false, 'error' => 'Network error (cURL): ' . htmlspecialchars( $curl_err, ENT_QUOTES, 'UTF-8' ) );
	}

	$data = json_decode( $response, true );
	if ( ! is_array( $data ) ) {
		return array( 'success' => false, 'error' => 'Invalid JSON response from PeeStream server.' );
	}

	if ( isset( $data['status'] ) && (int)$data['status'] !== 200 ) {
		$msg = isset( $data['msg'] ) ? $data['msg'] : ( 'API error: ' . $data['status'] );
		return array( 'success' => false, 'error' => $msg );
	}

	return array( 'success' => true, 'result' => isset( $data['result'] ) ? $data['result'] : $data );
}

// -------------------------------------------------------------
// ACTION: Remote URL Upload (/api/upload/url)
// -------------------------------------------------------------
if ( $action === 'remote_upload' ) {
	$video_url = isset( $_POST['url'] ) ? trim( $_POST['url'] ) : '';
	$title     = isset( $_POST['title'] ) ? trim( strip_tags( $_POST['title'] ) ) : '';
	$fld_id    = isset( $_POST['fld_id'] ) ? intval( $_POST['fld_id'] ) : intval( $cfg['default_folder_id'] );

	if ( empty( $video_url ) || ! preg_match( '#^https?://#i', $video_url ) ) {
		echo json_encode( array( 'success' => false, 'error' => 'Please enter a valid HTTP or HTTPS video URL.' ) );
		exit;
	}

	$res = peestream_ajax_api( 'api/upload/url', array(
		'url'       => $video_url,
		'new_title' => $title,
		'fld_id'    => $fld_id,
	), $cfg );

	if ( $res['success'] && ! empty( $res['result']['filecode'] ) ) {
		$code = preg_replace( '/[^a-zA-Z0-9_\-]/', '', $res['result']['filecode'] );
		echo json_encode( array(
			'success'   => true,
			'filecode'  => $code,
			'embed_url' => rtrim( $cfg['player_domain'], '/' ) . '/e/' . $code,
			'msg'       => 'Video successfully queued for download and encoding!'
		) );
	} else {
		echo json_encode( array( 'success' => false, 'error' => isset( $res['error'] ) ? $res['error'] : 'Failed to import video.' ) );
	}
	exit;
}

// -------------------------------------------------------------
// ACTION: IMDb Import (/api/upload/imdb)
// -------------------------------------------------------------
if ( $action === 'imdb_upload' ) {
	$imdb_id = isset( $_POST['imdb'] ) ? trim( $_POST['imdb'] ) : '';
	$season  = isset( $_POST['season'] ) ? max( 0, intval( $_POST['season'] ) ) : 0;
	$episode = isset( $_POST['episode'] ) ? max( 0, intval( $_POST['episode'] ) ) : 0;
	$fld_id  = isset( $_POST['fld_id'] ) ? intval( $_POST['fld_id'] ) : intval( $cfg['default_folder_id'] );

	if ( empty( $imdb_id ) || ! preg_match( '/^tt\d{5,12}$/i', $imdb_id ) ) {
		echo json_encode( array( 'success' => false, 'error' => 'Please enter a valid IMDb ID (e.g. tt1234567).' ) );
		exit;
	}

	$res = peestream_ajax_api( 'api/upload/imdb', array(
		'imdb'    => $imdb_id,
		'season'  => $season,
		'episode' => $episode,
		'fld_id'  => $fld_id,
	), $cfg );

	if ( $res['success'] && ! empty( $res['result']['filecode'] ) ) {
		$code = preg_replace( '/[^a-zA-Z0-9_\-]/', '', $res['result']['filecode'] );
		echo json_encode( array(
			'success'   => true,
			'filecode'  => $code,
			'embed_url' => rtrim( $cfg['player_domain'], '/' ) . '/e/' . $code,
			'msg'       => 'IMDb title reserved and queued successfully!'
		) );
	} else {
		echo json_encode( array( 'success' => false, 'error' => isset( $res['error'] ) ? $res['error'] : 'Failed to import IMDb title.' ) );
	}
	exit;
}

// -------------------------------------------------------------
// ACTION: List My Videos (/api/file/list)
// -------------------------------------------------------------
if ( $action === 'list_videos' ) {
	$page   = isset( $_POST['page'] ) ? max( 1, intval( $_POST['page'] ) ) : 1;
	$search = isset( $_POST['search'] ) ? trim( strtolower( $_POST['search'] ) ) : '';

	// Official PeeStream endpoint is /api/file/list
	$res = peestream_ajax_api( 'api/file/list', array( 'page' => $page, 'per_page' => 50 ), $cfg );
	if ( ! $res['success'] ) {
		echo json_encode( array( 'success' => false, 'error' => $res['error'] ) );
		exit;
	}

	$files = isset( $res['result']['files'] ) ? $res['result']['files'] : ( is_array( $res['result'] ) ? $res['result'] : array() );
	$items = array();

	foreach ( $files as $f ) {
		$code = isset( $f['file_code'] ) ? $f['file_code'] : ( isset( $f['code'] ) ? $f['code'] : '' );
		$code = preg_replace( '/[^a-zA-Z0-9_\-]/', '', $code );
		if ( empty( $code ) ) continue;

		$title = isset( $f['title'] ) ? (string)$f['title'] : 'Untitled';

		if ( ! empty( $search ) && stripos( $title, $search ) === false && stripos( $code, $search ) === false ) {
			continue;
		}

		$created = isset( $f['uploaded'] ) ? substr( (string)$f['uploaded'], 0, 10 ) : ( isset( $f['created_at'] ) ? substr( (string)$f['created_at'], 0, 10 ) : '' );
		$views   = isset( $f['views'] ) ? intval( $f['views'] ) : 0;
		$state   = isset( $f['state'] ) ? (string)$f['state'] : ( ( ! empty( $f['canplay'] ) ) ? 'ready' : 'processing' );

		$items[] = array(
			'code'      => $code,
			'title'     => $title,
			'views'     => $views,
			'state'     => $state,
			'created'   => $created,
			'embed_url' => rtrim( $cfg['player_domain'], '/' ) . '/e/' . $code,
		);
	}

	echo json_encode( array( 'success' => true, 'videos' => $items ) );
	exit;
}

// -------------------------------------------------------------
// DEFAULT ACTION: Render Modal UI with XSS Protection
// -------------------------------------------------------------
header( 'Content-Type: text/html; charset=utf-8' );
$xf_name = htmlspecialchars( ! empty( $cfg['xfield_name'] ) ? $cfg['xfield_name'] : 'peestream', ENT_QUOTES, 'UTF-8' );
?>
<div id="peestream-modal-wrapper" style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#333;font-size:13px;">
	<style>
		#peestream-modal-wrapper .pst-tabs { display:flex; border-bottom:2px solid #e2e8f0; margin-bottom:15px; }
		#peestream-modal-wrapper .pst-tab-btn { padding:10px 16px; border:none; background:none; cursor:pointer; font-weight:600; font-size:13px; color:#64748b; border-bottom:2px solid transparent; margin-bottom:-2px; outline:none; transition:all 0.2s; }
		#peestream-modal-wrapper .pst-tab-btn.active { color:#0284c7; border-bottom-color:#0284c7; }
		#peestream-modal-wrapper .pst-tab-content { display:none; }
		#peestream-modal-wrapper .pst-tab-content.active { display:block; }
		#peestream-modal-wrapper .pst-form-group { margin-bottom:14px; }
		#peestream-modal-wrapper label { display:block; font-weight:600; margin-bottom:5px; font-size:12px; color:#475569; }
		#peestream-modal-wrapper .pst-input { width:100%; box-sizing:border-box; padding:8px 12px; border:1px solid #cbd5e1; border-radius:5px; font-size:13px; outline:none; }
		#peestream-modal-wrapper .pst-input:focus { border-color:#0284c7; box-shadow:0 0 0 2px rgba(2,132,199,0.15); }
		#peestream-modal-wrapper .pst-btn { padding:8px 18px; border-radius:5px; font-weight:600; font-size:13px; cursor:pointer; border:none; transition:background 0.2s; }
		#peestream-modal-wrapper .pst-btn-primary { background:#0284c7; color:#fff; }
		#peestream-modal-wrapper .pst-btn-primary:hover { background:#0369a1; }
		#peestream-modal-wrapper .pst-btn-success { background:#10b981; color:#fff; }
		#peestream-modal-wrapper .pst-btn-success:hover { background:#059669; }
		#peestream-modal-wrapper .pst-table { width:100%; border-collapse:collapse; margin-top:10px; }
		#peestream-modal-wrapper .pst-table th, #peestream-modal-wrapper .pst-table td { padding:8px 10px; border-bottom:1px solid #e2e8f0; text-align:left; font-size:12px; }
		#peestream-modal-wrapper .pst-table th { background:#f8fafc; font-weight:600; color:#475569; }
		#peestream-modal-wrapper .pst-alert { padding:10px 14px; border-radius:5px; margin-bottom:12px; font-size:12px; display:none; }
		#peestream-modal-wrapper .pst-alert-success { background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; }
		#peestream-modal-wrapper .pst-alert-danger { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }
		#peestream-modal-wrapper .pst-badge-ready { background:#dcfce7; color:#15803d; padding:2px 6px; border-radius:4px; font-size:10px; font-weight:700; }
		#peestream-modal-wrapper .pst-badge-wait { background:#fef3c7; color:#b45309; padding:2px 6px; border-radius:4px; font-size:10px; font-weight:700; }
	</style>

	<div id="pst_msg_box" class="pst-alert"></div>

	<!-- Navigation Tabs -->
	<div class="pst-tabs">
		<button type="button" class="pst-tab-btn active" onclick="pstSwitchTab('quick')"><i class="fa fa-keyboard-o"></i> 1. Quick Code</button>
		<button type="button" class="pst-tab-btn" onclick="pstSwitchTab('remote')"><i class="fa fa-cloud-upload"></i> 2. Remote URL Upload</button>
		<button type="button" class="pst-tab-btn" onclick="pstSwitchTab('imdb')"><i class="fa fa-film"></i> 3. IMDb Import</button>
		<button type="button" class="pst-tab-btn" onclick="pstSwitchTab('library')"><i class="fa fa-list"></i> 4. My Library</button>
	</div>

	<!-- TAB 1: Quick Code Insert -->
	<div id="pst_tab_quick" class="pst-tab-content active">
		<div class="pst-form-group">
			<label>PeeStream Video Code or Embed URL:</label>
			<input type="text" id="pst_quick_code" class="pst-input" placeholder="e.g. eK7g1xY9 or https://peeplayer.com/e/eK7g1xY9">
			<small style="color:#64748b;font-size:11px;">Enter the video code from your PeeStream dashboard or the full player embed link.</small>
		</div>
		<div style="margin-top:18px;">
			<button type="button" class="pst-btn pst-btn-primary" onclick="pstApplyCode(document.getElementById('pst_quick_code').value)">
				<i class="fa fa-check"></i> Insert into Post
			</button>
		</div>
	</div>

	<!-- TAB 2: Remote URL Upload -->
	<div id="pst_tab_remote" class="pst-tab-content">
		<div class="pst-form-group">
			<label>Direct Video Link (MP4 / Confirmed Hosters: MixDrop, VOE, Streamtape, StreamWish):</label>
			<input type="text" id="pst_remote_url" class="pst-input" placeholder="https://example.com/video.mp4 or verified hoster URL">
		</div>
		<div class="pst-form-group">
			<label>Video Title (Optional):</label>
			<input type="text" id="pst_remote_title" class="pst-input" placeholder="Movie or Episode title">
		</div>
		<div style="margin-top:18px;">
			<button type="button" id="pst_btn_remote" class="pst-btn pst-btn-primary" onclick="pstSubmitRemote()">
				<i class="fa fa-cloud-upload"></i> Send to PeeStream & Get Code
			</button>
		</div>
	</div>

	<!-- TAB 3: IMDb Import -->
	<div id="pst_tab_imdb" class="pst-tab-content">
		<div class="pst-form-group">
			<label>IMDb Identifier (ID):</label>
			<input type="text" id="pst_imdb_id" class="pst-input" placeholder="e.g. tt0111161 or tt0903747">
		</div>
		<div style="display:flex;gap:15px;">
			<div class="pst-form-group" style="flex:1;">
				<label>Season (for TV shows):</label>
				<input type="number" id="pst_imdb_season" class="pst-input" placeholder="0 = Movie" value="0" min="0">
			</div>
			<div class="pst-form-group" style="flex:1;">
				<label>Episode (for TV shows):</label>
				<input type="number" id="pst_imdb_episode" class="pst-input" placeholder="0 = Movie" value="0" min="0">
			</div>
		</div>
		<div style="margin-top:18px;">
			<button type="button" id="pst_btn_imdb" class="pst-btn pst-btn-primary" onclick="pstSubmitImdb()">
				<i class="fa fa-search"></i> Reserve IMDb Video & Get Code
			</button>
		</div>
	</div>

	<!-- TAB 4: My Library -->
	<div id="pst_tab_library" class="pst-tab-content">
		<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
			<input type="text" id="pst_lib_search" class="pst-input" style="max-width:250px;" placeholder="Filter by title..." onkeyup="pstFilterLibrary()">
			<button type="button" class="pst-btn" style="background:#e2e8f0;color:#334155;" onclick="pstLoadLibrary()"><i class="fa fa-refresh"></i> Refresh</button>
		</div>
		<div id="pst_lib_list" style="max-height:220px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:5px;">
			<div style="text-align:center;padding:25px;color:#94a3b8;">Loading videos...</div>
		</div>
	</div>
</div>

<script>
var PST_XFIELD = "<?php echo $xf_name; ?>";
var PST_USER_HASH = "<?php echo htmlspecialchars( $dle_hash, ENT_QUOTES, 'UTF-8' ); ?>";

// Safe text escaping function to prevent XSS
function pstEscapeHtml(str) {
	if (str == null) return "";
	return String(str)
		.replace(/&/g, "&amp;")
		.replace(/</g, "&lt;")
		.replace(/>/g, "&gt;")
		.replace(/"/g, "&quot;")
		.replace(/'/g, "&#039;");
}

function pstShowMsg(msg, isSuccess) {
	var box = document.getElementById("pst_msg_box");
	box.className = "pst-alert " + (isSuccess ? "pst-alert-success" : "pst-alert-danger");
	// Clean safely
	box.textContent = msg;
	box.style.display = "block";
}

function pstSwitchTab(tabName) {
	var tabs = ["quick", "remote", "imdb", "library"];
	for (var i = 0; i < tabs.length; i++) {
		var btn = document.querySelectorAll(".pst-tab-btn")[i];
		var pane = document.getElementById("pst_tab_" + tabs[i]);
		if (tabs[i] === tabName) {
			btn.classList.add("active");
			pane.classList.add("active");
			if (tabName === "library") pstLoadLibrary();
		} else {
			btn.classList.remove("active");
			pane.classList.remove("active");
		}
	}
}

function pstExtractCode(input) {
	input = (input || "").trim();
	var m = input.match(/\/(?:e|v|embed)\/([a-zA-Z0-9_\-]+)/i);
	if (m && m[1]) return m[1];
	var m2 = input.match(/^[a-zA-Z0-9_\-]+$/);
	return m2 ? m2[0] : "";
}

function pstApplyCode(rawCode) {
	var code = pstExtractCode(rawCode);
	if (!code) {
		pstShowMsg("Please enter a valid alphanumeric video code or embed URL.", false);
		return;
	}

	var appliedToXfield = false;

	// 1. Populate DLE custom field (XField)
	var xfInput = document.querySelector('input[name="xfield[' + PST_XFIELD + ']"], textarea[name="xfield[' + PST_XFIELD + ']"], #xfield_' + PST_XFIELD);
	if (xfInput) {
		xfInput.value = code;
		appliedToXfield = true;
	}

	// 2. Insert tag into active editor
	if (typeof window.peestreamInsertIntoEditor === "function") {
		window.peestreamInsertIntoEditor(code);
	}

	var successText = "Video code " + code + " inserted successfully!" + (appliedToXfield ? " (In field xfield[" + PST_XFIELD + "])" : "");
	pstShowMsg(successText, true);

	setTimeout(function() {
		if (typeof window.peestreamCloseModal === "function") {
			window.peestreamCloseModal();
		}
	}, 1200);
}

function pstSubmitRemote() {
	var url = document.getElementById("pst_remote_url").value;
	var title = document.getElementById("pst_remote_title").value;
	var btn = document.getElementById("pst_btn_remote");

	if (!url) {
		pstShowMsg("Please enter a video URL.", false);
		return;
	}

	btn.disabled = true;
	btn.innerHTML = "<i class='fa fa-spinner fa-spin'></i> Submitting to PeeStream...";

	var fd = new FormData();
	fd.append("action", "remote_upload");
	fd.append("user_hash", PST_USER_HASH);
	fd.append("url", url);
	fd.append("title", title);

	fetch("engine/ajax/peestream_modal.php", { method: "POST", body: fd })
		.then(function(r) { return r.json(); })
		.then(function(data) {
			btn.disabled = false;
			btn.innerHTML = "<i class='fa fa-cloud-upload'></i> Send to PeeStream & Get Code";
			if (data.success && data.filecode) {
				pstApplyCode(data.filecode);
			} else {
				pstShowMsg(data.error || "Failed to upload video.", false);
			}
		})
		.catch(function(err) {
			btn.disabled = false;
			btn.innerHTML = "<i class='fa fa-cloud-upload'></i> Send to PeeStream & Get Code";
			pstShowMsg("Network error: " + err, false);
		});
}

function pstSubmitImdb() {
	var imdb = document.getElementById("pst_imdb_id").value;
	var s = document.getElementById("pst_imdb_season").value;
	var ep = document.getElementById("pst_imdb_episode").value;
	var btn = document.getElementById("pst_btn_imdb");

	if (!imdb) {
		pstShowMsg("Please enter an IMDb ID (e.g. tt0111161).", false);
		return;
	}

	btn.disabled = true;
	btn.innerHTML = "<i class='fa fa-spinner fa-spin'></i> Checking IMDb catalog...";

	var fd = new FormData();
	fd.append("action", "imdb_upload");
	fd.append("user_hash", PST_USER_HASH);
	fd.append("imdb", imdb);
	fd.append("season", s);
	fd.append("episode", ep);

	fetch("engine/ajax/peestream_modal.php", { method: "POST", body: fd })
		.then(function(r) { return r.json(); })
		.then(function(data) {
			btn.disabled = false;
			btn.innerHTML = "<i class='fa fa-search'></i> Reserve IMDb Video & Get Code";
			if (data.success && data.filecode) {
				pstApplyCode(data.filecode);
			} else {
				pstShowMsg(data.error || "Failed to import IMDb title.", false);
			}
		})
		.catch(function(err) {
			btn.disabled = false;
			btn.innerHTML = "<i class='fa fa-search'></i> Reserve IMDb Video & Get Code";
			pstShowMsg("Network error: " + err, false);
		});
}

var pstCachedVideos = [];
function pstLoadLibrary() {
	var listDiv = document.getElementById("pst_lib_list");
	listDiv.innerHTML = "<div style='text-align:center;padding:25px;color:#94a3b8;'><i class='fa fa-spinner fa-spin'></i> Loading from PeeStream account...</div>";

	var fd = new FormData();
	fd.append("action", "list_videos");
	fd.append("user_hash", PST_USER_HASH);

	fetch("engine/ajax/peestream_modal.php", { method: "POST", body: fd })
		.then(function(r) { return r.json(); })
		.then(function(data) {
			if (data.success && data.videos) {
				pstCachedVideos = data.videos;
				pstRenderLibraryTable(pstCachedVideos);
			} else {
				listDiv.textContent = data.error || "Could not load video library.";
			}
		})
		.catch(function(err) {
			listDiv.textContent = "Error: " + err;
		});
}

// XSS-Safe DOM construction for Library Table
function pstRenderLibraryTable(videos) {
	var listDiv = document.getElementById("pst_lib_list");
	listDiv.innerHTML = ""; // Clear existing

	if (!videos || videos.length === 0) {
		var emptyMsg = document.createElement("div");
		emptyMsg.style.cssText = "padding:20px;text-align:center;color:#64748b;";
		emptyMsg.textContent = "No videos found on your account.";
		listDiv.appendChild(emptyMsg);
		return;
	}

	var table = document.createElement("table");
	table.className = "pst-table";

	var thead = document.createElement("thead");
	var trHead = document.createElement("tr");
	["Title", "Status", "Views", "Date", "Action"].forEach(function(thText) {
		var th = document.createElement("th");
		th.textContent = thText;
		trHead.appendChild(th);
	});
	thead.appendChild(trHead);
	table.appendChild(thead);

	var tbody = document.createElement("tbody");
	for (var i = 0; i < videos.length; i++) {
		var v = videos[i];
		var tr = document.createElement("tr");

		// Title cell
		var tdTitle = document.createElement("td");
		tdTitle.style.cssText = "font-weight:600;max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;";
		tdTitle.textContent = v.title || "Untitled";
		tdTitle.title = v.title || "Untitled";
		tr.appendChild(tdTitle);

		// Status cell
		var tdStatus = document.createElement("td");
		var badge = document.createElement("span");
		if (v.state === "ready") {
			badge.className = "pst-badge-ready";
			badge.textContent = "READY";
		} else {
			badge.className = "pst-badge-wait";
			badge.textContent = (v.state || "PENDING").toUpperCase();
		}
		tdStatus.appendChild(badge);
		tr.appendChild(tdStatus);

		// Views cell
		var tdViews = document.createElement("td");
		tdViews.textContent = v.views || 0;
		tr.appendChild(tdViews);

		// Date cell
		var tdDate = document.createElement("td");
		tdDate.textContent = v.created || "-";
		tr.appendChild(tdDate);

		// Action cell
		var tdAction = document.createElement("td");
		var btn = document.createElement("button");
		btn.type = "button";
		btn.className = "pst-btn pst-btn-success";
		btn.style.cssText = "padding:4px 10px;font-size:11px;";
		btn.innerHTML = "<i class='fa fa-plus'></i> Insert";
		btn.setAttribute("data-code", v.code);
		btn.onclick = function() {
			pstApplyCode(this.getAttribute("data-code"));
		};
		tdAction.appendChild(btn);
		tr.appendChild(tdAction);

		tbody.appendChild(tr);
	}
	table.appendChild(tbody);
	listDiv.appendChild(table);
}

function pstFilterLibrary() {
	var q = document.getElementById("pst_lib_search").value.toLowerCase();
	if (!q) {
		pstRenderLibraryTable(pstCachedVideos);
		return;
	}
	var filtered = pstCachedVideos.filter(function(v) {
		return (v.title || "").toLowerCase().indexOf(q) !== -1 || (v.code || "").toLowerCase().indexOf(q) !== -1;
	});
	pstRenderLibraryTable(filtered);
}
</script>

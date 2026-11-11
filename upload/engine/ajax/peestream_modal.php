<?php
/*
=============================================================================
PeeStream Video Player & Tools - DataLife Engine Addon
Module: AJAX Editor Modal & Video Inserter
File: engine/ajax/peestream_modal.php
Version: 1.0.0
=============================================================================
*/

@error_reporting( E_ALL ^ E_WARNING ^ E_NOTICE );
@ini_set( 'display_errors', true );
@ini_set( 'html_errors', false );

define( 'DATALIFEENGINE', true );
define( 'ROOT_DIR', substr( dirname( __FILE__ ), 0, -12 ) );
define( 'ENGINE_DIR', ROOT_DIR . '/engine' );

// Učitavanje DLE jezgra
if( file_exists( ENGINE_DIR . '/classes/plugins.class.php' ) ) {
	include_once ENGINE_DIR . '/classes/plugins.class.php';
}

$inc_func = ENGINE_DIR . '/inc/include/functions.inc.php';
if( file_exists( $inc_func ) ) {
	require_once ( $inc_func );
}

if( function_exists( 'dle_session' ) ) {
	dle_session();
}

$sitelogin = ENGINE_DIR . '/modules/sitelogin.php';
if( file_exists( $sitelogin ) ) {
	require_once ( $sitelogin );
}

// Provjera autorizacije korisnika
if( empty( $is_logged ) || !$is_logged ) {
	echo json_encode( array( 'success' => false, 'error' => 'Pristup odbijen. Morate biti prijavljeni.' ) );
	exit;
}

// Provjera prava za dodavanje/uređivanje vijesti
$user_group = isset( $member_id['user_group'] ) ? intval( $member_id['user_group'] ) : 5;
if( $user_group > 3 && $user_group != 1 ) {
	// Grupa 1 = Administrator, 2 = Glavni urednik, 3 = Urednik/Novinar
	echo json_encode( array( 'success' => false, 'error' => 'Nemate dozvolu za objavljivanje video zapisa.' ) );
	exit;
}

// Učitavanje konfiguracije PeeStream modula
$conf_file = ENGINE_DIR . '/data/peestream.conf.php';
if( file_exists( $conf_file ) ) {
	require_once ( $conf_file );
} else {
	$peestream_config = array();
}

$cfg = array_merge( array(
	'api_key' => '',
	'player_domain' => 'https://peeplayer.com',
	'api_domain' => 'https://peestream.com',
	'default_folder_id' => '0',
	'xfield_name' => 'peestream',
), isset( $peestream_config ) ? $peestream_config : array() );

$action = isset( $_REQUEST['action'] ) ? trim( $_REQUEST['action'] ) : 'ui';

// Pomoćna funkcija za siguran cURL poziv ka PeeStream API-ju
function peestream_ajax_api( $endpoint, $params, $cfg ) {
	if( empty( $cfg['api_key'] ) ) {
		return array( 'success' => false, 'error' => 'PeeStream API ključ nije podešen u admin panelu.' );
	}
	
	$params['key'] = $cfg['api_key'];
	$url = rtrim( $cfg['api_domain'], '/' ) . '/' . ltrim( $endpoint, '/' ) . '?' . http_build_query( $params );
	
	$ch = curl_init();
	curl_setopt( $ch, CURLOPT_URL, $url );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $ch, CURLOPT_TIMEOUT, 20 );
	curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 8 );
	curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, true );
	curl_setopt( $ch, CURLOPT_USERAGENT, 'PeeStream-DLE-Modal/1.0' );
	
	$response = curl_exec( $ch );
	$curl_err = curl_error( $ch );
	curl_close( $ch );
	
	if( $curl_err ) {
		return array( 'success' => false, 'error' => 'Mrežna greška (cURL): ' . htmlspecialchars( $curl_err ) );
	}
	
	$data = json_decode( $response, true );
	if( !$data ) {
		return array( 'success' => false, 'error' => 'Neispravan odgovor sa PeeStream servera.' );
	}
	
	if( isset( $data['status'] ) && $data['status'] != 200 ) {
		return array( 'success' => false, 'error' => isset( $data['msg'] ) ? $data['msg'] : ( 'API greška: ' . $data['status'] ) );
	}
	
	return array( 'success' => true, 'result' => isset( $data['result'] ) ? $data['result'] : $data );
}

// -------------------------------------------------------------
// AJAX AKCIJA: Remote URL Upload (/api/upload/url)
// -------------------------------------------------------------
if( $action == 'remote_upload' ) {
	header( 'Content-Type: application/json; charset=utf-8' );
	$video_url = isset( $_POST['url'] ) ? trim( $_POST['url'] ) : '';
	$title = isset( $_POST['title'] ) ? trim( strip_tags( $_POST['title'] ) ) : '';
	$fld_id = isset( $_POST['fld_id'] ) ? intval( $_POST['fld_id'] ) : intval( $cfg['default_folder_id'] );
	
	if( empty( $video_url ) || !preg_match( '#^https?://#i', $video_url ) ) {
		echo json_encode( array( 'success' => false, 'error' => 'Unesite ispravan HTTP ili HTTPS link videa.' ) );
		exit;
	}
	
	$res = peestream_ajax_api( 'api/upload/url', array(
		'url' => $video_url,
		'new_title' => $title,
		'fld_id' => $fld_id
	), $cfg );
	
	if( $res['success'] && !empty( $res['result']['filecode'] ) ) {
		echo json_encode( array(
			'success' => true,
			'filecode' => $res['result']['filecode'],
			'embed_url' => rtrim( $cfg['player_domain'], '/' ) . '/e/' . $res['result']['filecode'],
			'msg' => 'Video je uspješno poslat u red za obradu!'
		) );
	} else {
		echo json_encode( array( 'success' => false, 'error' => isset( $res['error'] ) ? $res['error'] : 'Neuspješan uvoz videa.' ) );
	}
	exit;
}

// -------------------------------------------------------------
// AJAX AKCIJA: IMDb / TMDb uvoz (/api/upload/imdb)
// -------------------------------------------------------------
if( $action == 'imdb_upload' ) {
	header( 'Content-Type: application/json; charset=utf-8' );
	$imdb_id = isset( $_POST['imdb'] ) ? trim( $_POST['imdb'] ) : '';
	$season = isset( $_POST['season'] ) ? intval( $_POST['season'] ) : 0;
	$episode = isset( $_POST['episode'] ) ? intval( $_POST['episode'] ) : 0;
	$fld_id = isset( $_POST['fld_id'] ) ? intval( $_POST['fld_id'] ) : intval( $cfg['default_folder_id'] );
	
	if( empty( $imdb_id ) ) {
		echo json_encode( array( 'success' => false, 'error' => 'Unesite IMDb identifikator (npr. tt1234567).' ) );
		exit;
	}
	
	$res = peestream_ajax_api( 'api/upload/imdb', array(
		'imdb' => $imdb_id,
		'season' => $season,
		'episode' => $episode,
		'fld_id' => $fld_id
	), $cfg );
	
	if( $res['success'] && !empty( $res['result']['filecode'] ) ) {
		echo json_encode( array(
			'success' => true,
			'filecode' => $res['result']['filecode'],
			'embed_url' => rtrim( $cfg['player_domain'], '/' ) . '/e/' . $res['result']['filecode'],
			'msg' => 'IMDb naslov je uspješno rezervisan i dodat u red!'
		) );
	} else {
		echo json_encode( array( 'success' => false, 'error' => isset( $res['error'] ) ? $res['error'] : 'Neuspješan IMDb uvoz.' ) );
	}
	exit;
}

// -------------------------------------------------------------
// AJAX AKCIJA: Lista mojih videa (/api/lista)
// -------------------------------------------------------------
if( $action == 'list_videos' ) {
	header( 'Content-Type: application/json; charset=utf-8' );
	$page = isset( $_POST['page'] ) ? max( 1, intval( $_POST['page'] ) ) : 1;
	$search = isset( $_POST['search'] ) ? trim( strtolower( $_POST['search'] ) ) : '';
	
	$res = peestream_ajax_api( 'api/lista', array( 'page' => $page, 'per_page' => 20 ), $cfg );
	if( !$res['success'] ) {
		echo json_encode( array( 'success' => false, 'error' => $res['error'] ) );
		exit;
	}
	
	$files = isset( $res['result']['files'] ) ? $res['result']['files'] : ( is_array( $res['result'] ) ? $res['result'] : array() );
	$items = array();
	
	foreach( $files as $f ) {
		$code = isset( $f['file_code'] ) ? $f['file_code'] : ( isset( $f['code'] ) ? $f['code'] : '' );
		$title = isset( $f['title'] ) ? $f['title'] : 'Bez naslova';
		if( empty( $code ) ) continue;
		
		if( !empty( $search ) && stripos( $title, $search ) === false && stripos( $code, $search ) === false ) {
			continue;
		}
		
		$items[] = array(
			'code' => $code,
			'title' => $title,
			'views' => isset( $f['views'] ) ? intval( $f['views'] ) : 0,
			'created' => isset( $f['created_at'] ) ? substr( $f['created_at'], 0, 10 ) : '',
			'embed_url' => rtrim( $cfg['player_domain'], '/' ) . '/e/' . $code,
		);
	}
	
	echo json_encode( array( 'success' => true, 'videos' => $items ) );
	exit;
}

// -------------------------------------------------------------
// DEFAULT AKCIJA: Prikaz korisničkog interfejsa (Modal UI)
// -------------------------------------------------------------
header( 'Content-Type: text/html; charset=utf-8' );
$xf_name = htmlspecialchars( !empty( $cfg['xfield_name'] ) ? $cfg['xfield_name'] : 'peestream' );
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
	</style>

	<div id="pst_msg_box" class="pst-alert"></div>

	<!-- Tabovi navigacije -->
	<div class="pst-tabs">
		<button type="button" class="pst-tab-btn active" onclick="pstSwitchTab('quick')"><i class="fa fa-keyboard-o"></i> 1. Brzi unos koda</button>
		<button type="button" class="pst-tab-btn" onclick="pstSwitchTab('remote')"><i class="fa fa-cloud-upload"></i> 2. Remote URL Upload</button>
		<button type="button" class="pst-tab-btn" onclick="pstSwitchTab('imdb')"><i class="fa fa-film"></i> 3. IMDb uvoz</button>
		<button type="button" class="pst-tab-btn" onclick="pstSwitchTab('library')"><i class="fa fa-list"></i> 4. Moja biblioteka</button>
	</div>

	<!-- TAB 1: Brzi unos koda -->
	<div id="pst_tab_quick" class="pst-tab-content active">
		<div class="pst-form-group">
			<label>PeeStream kod videa ili embed link:</label>
			<input type="text" id="pst_quick_code" class="pst-input" placeholder="npr. eK7g1xY9 ili https://peeplayer.com/e/eK7g1xY9">
			<small style="color:#64748b;font-size:11px;">Unesite kod videa sa vašeg PeeStream panela ili kompletan embed link.</small>
		</div>
		<div style="margin-top:18px;">
			<button type="button" class="pst-btn pst-btn-primary" onclick="pstApplyCode(document.getElementById('pst_quick_code').value)">
				<i class="fa fa-check"></i> Ubaci u vijest
			</button>
		</div>
	</div>

	<!-- TAB 2: Remote URL Upload -->
	<div id="pst_tab_remote" class="pst-tab-content">
		<div class="pst-form-group">
			<label>Direktan link videa (MP4 / drugi video hoster):</label>
			<input type="text" id="pst_remote_url" class="pst-input" placeholder="https://example.com/video.mp4 ili Dood/Streamtape link">
		</div>
		<div class="pst-form-group">
			<label>Naslov videa (opciono):</label>
			<input type="text" id="pst_remote_title" class="pst-input" placeholder="Naziv filma ili epizode">
		</div>
		<div style="margin-top:18px;">
			<button type="button" id="pst_btn_remote" class="pst-btn pst-btn-primary" onclick="pstSubmitRemote()">
				<i class="fa fa-cloud-upload"></i> Pošalji na PeeStream i preuzmi kod
			</button>
		</div>
	</div>

	<!-- TAB 3: IMDb uvoz -->
	<div id="pst_tab_imdb" class="pst-tab-content">
		<div class="pst-form-group">
			<label>IMDb identifikator (ID):</label>
			<input type="text" id="pst_imdb_id" class="pst-input" placeholder="npr. tt0111161 ili tt0903747">
		</div>
		<div style="display:flex;gap:15px;">
			<div class="pst-form-group" style="flex:1;">
				<label>Sezona (za serije):</label>
				<input type="number" id="pst_imdb_season" class="pst-input" placeholder="0 = Film" value="0">
			</div>
			<div class="pst-form-group" style="flex:1;">
				<label>Epizoda (za serije):</label>
				<input type="number" id="pst_imdb_episode" class="pst-input" placeholder="0 = Film" value="0">
			</div>
		</div>
		<div style="margin-top:18px;">
			<button type="button" id="pst_btn_imdb" class="pst-btn pst-btn-primary" onclick="pstSubmitImdb()">
				<i class="fa fa-search"></i> Rezerviši IMDb video i preuzmi kod
			</button>
		</div>
	</div>

	<!-- TAB 4: Moja biblioteka -->
	<div id="pst_tab_library" class="pst-tab-content">
		<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
			<input type="text" id="pst_lib_search" class="pst-input" style="max-width:250px;" placeholder="Filtriraj po naslovu..." onkeyup="pstFilterLibrary()">
			<button type="button" class="pst-btn" style="background:#e2e8f0;color:#334155;" onclick="pstLoadLibrary()"><i class="fa fa-refresh"></i> Osvježi</button>
		</div>
		<div id="pst_lib_list" style="max-height:220px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:5px;">
			<div style="text-align:center;padding:25px;color:#94a3b8;">Učitavanje vaših video zapisa...</div>
		</div>
	</div>
</div>

<script>
var PST_XFIELD = "<?php echo $xf_name; ?>";

function pstShowMsg(msg, isSuccess) {
	var box = document.getElementById("pst_msg_box");
	box.className = "pst-alert " + (isSuccess ? "pst-alert-success" : "pst-alert-danger");
	box.innerHTML = msg;
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
		pstShowMsg("Molimo unesite validan alfanumerički kod ili embed link videa.", false);
		return;
	}
	
	var appliedToXfield = false;
	
	// 1. Pokušaj popunjavanja dodatnog polja (XField) u DLE formi
	var xfInput = document.querySelector('input[name="xfield[' + PST_XFIELD + ']"], textarea[name="xfield[' + PST_XFIELD + ']"], #xfield_' + PST_XFIELD);
	if (xfInput) {
		xfInput.value = code;
		appliedToXfield = true;
	}
	
	// 2. Ako postoji editor (Froala, TinyMCE ili klasični BBCode), ponudi ili ubaci tag
	if (typeof window.peestreamInsertIntoEditor === "function") {
		window.peestreamInsertIntoEditor(code);
	}
	
	pstShowMsg("Video kod <b>" + code + "</b> je uspješno ubačen!" + (appliedToXfield ? " (U polje xfield[" + PST_XFIELD + "])" : ""), true);
	
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
		pstShowMsg("Unesite link videa.", false);
		return;
	}
	
	btn.disabled = true;
	btn.innerHTML = "<i class='fa fa-spinner fa-spin'></i> Slanje na PeeStream...";
	
	var fd = new FormData();
	fd.append("action", "remote_upload");
	fd.append("url", url);
	fd.append("title", title);
	
	fetch("engine/ajax/peestream_modal.php", { method: "POST", body: fd })
		.then(function(r) { return r.json(); })
		.then(function(data) {
			btn.disabled = false;
			btn.innerHTML = "<i class='fa fa-cloud-upload'></i> Pošalji na PeeStream i preuzmi kod";
			if (data.success && data.filecode) {
				pstApplyCode(data.filecode);
			} else {
				pstShowMsg(data.error || "Greška pri uvozu.", false);
			}
		})
		.catch(function(err) {
			btn.disabled = false;
			btn.innerHTML = "<i class='fa fa-cloud-upload'></i> Pošalji na PeeStream i preuzmi kod";
			pstShowMsg("Mrežna greška: " + err, false);
		});
}

function pstSubmitImdb() {
	var imdb = document.getElementById("pst_imdb_id").value;
	var s = document.getElementById("pst_imdb_season").value;
	var ep = document.getElementById("pst_imdb_episode").value;
	var btn = document.getElementById("pst_btn_imdb");
	
	if (!imdb) {
		pstShowMsg("Unesite IMDb ID (npr. tt0111161).", false);
		return;
	}
	
	btn.disabled = true;
	btn.innerHTML = "<i class='fa fa-spinner fa-spin'></i> Provjera IMDb kataloga...";
	
	var fd = new FormData();
	fd.append("action", "imdb_upload");
	fd.append("imdb", imdb);
	fd.append("season", s);
	fd.append("episode", ep);
	
	fetch("engine/ajax/peestream_modal.php", { method: "POST", body: fd })
		.then(function(r) { return r.json(); })
		.then(function(data) {
			btn.disabled = false;
			btn.innerHTML = "<i class='fa fa-search'></i> Rezerviši IMDb video i preuzmi kod";
			if (data.success && data.filecode) {
				pstApplyCode(data.filecode);
			} else {
				pstShowMsg(data.error || "Greška pri IMDb uvozu.", false);
			}
		})
		.catch(function(err) {
			btn.disabled = false;
			btn.innerHTML = "<i class='fa fa-search'></i> Rezerviši IMDb video i preuzmi kod";
			pstShowMsg("Mrežna greška: " + err, false);
		});
}

var pstCachedVideos = [];
function pstLoadLibrary() {
	var listDiv = document.getElementById("pst_lib_list");
	listDiv.innerHTML = "<div style='text-align:center;padding:25px;color:#94a3b8;'><i class='fa fa-spinner fa-spin'></i> Učitavanje sa PeeStream naloga...</div>";
	
	var fd = new FormData();
	fd.append("action", "list_videos");
	
	fetch("engine/ajax/peestream_modal.php", { method: "POST", body: fd })
		.then(function(r) { return r.json(); })
		.then(function(data) {
			if (data.success && data.videos) {
				pstCachedVideos = data.videos;
				pstRenderLibraryTable(pstCachedVideos);
			} else {
				listDiv.innerHTML = "<div style='padding:15px;color:#dc2626;'>" + (data.error || "Nije uspjelo učitavanje biblioteke.") + "</div>";
			}
		})
		.catch(function(err) {
			listDiv.innerHTML = "<div style='padding:15px;color:#dc2626;'>Greška: " + err + "</div>";
		});
}

function pstRenderLibraryTable(videos) {
	var listDiv = document.getElementById("pst_lib_list");
	if (!videos || videos.length === 0) {
		listDiv.innerHTML = "<div style='padding:20px;text-align:center;color:#64748b;'>Nema pronađenih video zapisa na vašem nalogu.</div>";
		return;
	}
	
	var html = "<table class='pst-table'><thead><tr><th>Naslov</th><th>Pregledi</th><th>Datum</th><th>Akcija</th></tr></thead><tbody>";
	for (var i = 0; i < videos.length; i++) {
		var v = videos[i];
		html += "<tr>";
		html += "<td style='font-weight:600;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;'>" + (v.title || "Bez naslova") + "</td>";
		html += "<td>" + (v.views || 0) + "</td>";
		html += "<td>" + (v.created || "-") + "</td>";
		html += "<td><button type='button' class='pst-btn pst-btn-success' style='padding:4px 10px;font-size:11px;' onclick=\"pstApplyCode('" + v.code + "')\"><i class='fa fa-plus'></i> Ubaci</button></td>";
		html += "</tr>";
	}
	html += "</tbody></table>";
	listDiv.innerHTML = html;
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

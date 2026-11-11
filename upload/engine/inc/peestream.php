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

// Podrazumijevane vrijednosti
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

// Pomoćna funkcija za siguran cURL poziv ka PeeStream API-ju
function peestream_api_call( $endpoint, $api_key, $api_domain, $params = array() ) {
	if( empty( $api_key ) ) {
		return array( 'success' => false, 'error' => 'API ključ nije unesen.' );
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
		return array( 'success' => false, 'error' => 'cURL greška: ' . htmlspecialchars( $curl_err ) );
	}
	
	$data = json_decode( $response, true );
	if( !$data ) {
		return array( 'success' => false, 'error' => 'Neispravan odgovor servera (HTTP ' . $http_code . ').' );
	}
	
	if( isset( $data['status'] ) && $data['status'] != 200 ) {
		return array( 'success' => false, 'error' => isset( $data['msg'] ) ? $data['msg'] : 'API greška (' . $data['status'] . ')' );
	}
	
	return array( 'success' => true, 'data' => isset( $data['result'] ) ? $data['result'] : $data );
}

// Pomoćna funkcija za detekciju linkova konkurentskih video servisa
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
// AJAX: Skeniranje baze vijesti za konkurentske linkove
// -------------------------------------------------------------
if( $action == 'migrator_scan' ) {
	header( 'Content-Type: application/json; charset=utf-8' );
	if( empty( $_REQUEST['user_hash'] ) || $_REQUEST['user_hash'] != $dle_login_hash ) {
		echo json_encode( array( 'success' => false, 'error' => 'Nevažeći sigurnosni token.' ) );
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
// AJAX: Migracija pojedinačne vijesti (Batch korak)
// -------------------------------------------------------------
if( $action == 'migrator_process_one' ) {
	header( 'Content-Type: application/json; charset=utf-8' );
	if( empty( $_REQUEST['user_hash'] ) || $_REQUEST['user_hash'] != $dle_login_hash ) {
		echo json_encode( array( 'success' => false, 'error' => 'Nevažeći sigurnosni token.' ) );
		exit;
	}
	
	$post_id = intval( $_POST['post_id'] );
	$old_url = trim( $_POST['url'] );
	
	if( !$post_id || empty( $old_url ) ) {
		echo json_encode( array( 'success' => false, 'error' => 'Nedostaju identifikatori vijesti ili linka.' ) );
		exit;
	}
	
	$table = PREFIX . "_post";
	$row = $db->super_query( "SELECT id, title, xfields, short_story, full_story FROM {$table} WHERE id='{$post_id}'" );
	if( empty( $row['id'] ) ) {
		echo json_encode( array( 'success' => false, 'error' => 'Vijest ID ' . $post_id . ' ne postoji u bazi.' ) );
		exit;
	}
	
	// 1. Poziv ka PeeStream API za prenos linka
	$res = peestream_api_call( 'api/upload/url', $cfg['api_key'], $cfg['api_domain'], array(
		'url' => $old_url,
		'new_title' => $row['title'],
		'fld_id' => intval( $cfg['default_folder_id'] )
	) );
	
	if( !$res['success'] || empty( $res['data']['filecode'] ) ) {
		$err = isset( $res['error'] ) ? $res['error'] : 'PeeStream API nije vratio kod za obradu.';
		echo json_encode( array( 'success' => false, 'error' => $err ) );
		exit;
	}
	
	$new_code = $res['data']['filecode'];
	$new_embed = rtrim( $cfg['player_domain'], '/' ) . '/e/' . $new_code;
	
	// 2. Zamjena starog linka novim PeeStream kodom u bazi
	$xf_name = !empty( $cfg['xfield_name'] ) ? $cfg['xfield_name'] : 'peestream';
	$upd_xf = $row['xfields'];
	$upd_short = $row['short_story'];
	$upd_full = $row['full_story'];
	
	// Zamijeni u xfields ako sadrži stari link
	if( strpos( $upd_xf, $old_url ) !== false ) {
		$upd_xf = str_replace( $old_url, $new_code, $upd_xf );
	}
	
	// Ažuriraj namensko xfield polje
	if( function_exists( 'xfieldsdataload' ) && function_exists( 'xfieldsdatasave' ) ) {
		$xf_arr = xfieldsdataload( $upd_xf );
		$xf_arr[$xf_name] = $new_code;
		$upd_xf = xfieldsdatasave( $xf_arr );
	}
	
	// Zamijeni u tekstu vijesti
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
		'msg' => 'Uspješno zamijenjeno kodom ' . $new_code
	) );
	exit;
}

// -------------------------------------------------------------
// POST: Čuvanje konfiguracije
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
	
	$conf_content = "<?PHP\n\n// PeeStream DLE Addon Configuration\n// Posljednja izmjena: " . date( "d.m.Y H:i:s" ) . "\n\n\$peestream_config = array (\n";
	foreach( $save_cfg as $k => $v ) {
		$conf_content .= "\t'" . addslashes( $k ) . "' => '" . addslashes( $v ) . "',\n";
	}
	$conf_content .= ");\n\n?>";
	
	$fp = @fopen( $conf_file, "w" );
	if( $fp ) {
		fwrite( $fp, $conf_content );
		fclose( $fp );
		$cfg = $save_cfg;
		$msg_alert = '<div class="alert alert-success alert-styled-left alert-arrow-left alert-bordered"><b>Uspjeh!</b> Podešavanja PeeStream modula su uspješno sačuvana.</div>';
	} else {
		$msg_alert = '<div class="alert alert-danger alert-styled-left alert-arrow-left alert-bordered"><b>Greška!</b> Nije uspjelo upisivanje u fajl ' . htmlspecialchars( $conf_file ) . '. Provjerite CHMOD dozvole (potrebno 0666 ili 0777).</div>';
	}
}

// Provjera API statusa i dohvatanje podataka o nalogu
$api_status_html = '';
$folders_options = '<option value="0">-- Glavni direktorijum (Root) --</option>';

if( !empty( $cfg['api_key'] ) ) {
	$info_res = peestream_api_call( 'api/info', $cfg['api_key'], $cfg['api_domain'] );
	if( $info_res['success'] ) {
		$u_data = $info_res['data'];
		$usr_name = htmlspecialchars( isset( $u_data['username'] ) ? $u_data['username'] : ( isset( $u_data['email'] ) ? $u_data['email'] : 'Uploader' ) );
		$usr_bal = htmlspecialchars( isset( $u_data['balance'] ) ? $u_data['balance'] : '0.00' );
		$usr_vids = htmlspecialchars( isset( $u_data['total_videos'] ) ? $u_data['total_videos'] : ( isset( $u_data['videos'] ) ? $u_data['videos'] : '0' ) );
		$usr_views = htmlspecialchars( isset( $u_data['total_views'] ) ? $u_data['total_views'] : ( isset( $u_data['views'] ) ? $u_data['views'] : '0' ) );
		
		$api_status_html = '<div class="alert alert-success alert-styled-left alert-arrow-left alert-bordered" style="margin-bottom:20px;">'
			. '<h6 class="alert-heading text-semibold">Povezano sa PeeStream nalogom: <b>' . $usr_name . '</b></h6>'
			. '<span>Trenutni balans: <b>$' . $usr_bal . '</b> | Ukupno videa: <b>' . $usr_vids . '</b> | Ukupno pregleda: <b>' . $usr_views . '</b></span>'
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
			. '<h6 class="alert-heading text-semibold">PeeStream API upozorenje:</h6>'
			. '<span>' . htmlspecialchars( $info_res['error'] ) . ' Molimo provjerite uneseni API ključ.</span>'
			. '</div>';
	}
} else {
	$api_status_html = '<div class="alert alert-info alert-styled-left alert-arrow-left alert-bordered" style="margin-bottom:20px;">'
		. '<b>Obavještenje:</b> Unesite vaš <b>PeeStream API ključ</b> kako biste omogućili automatsku integraciju plejera i uvoz video zapisa.'
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

echoheader( "PeeStream Player &amp; Tools", "Podešavanja zvaničnog PeeStream video modula za DataLife Engine" );

echo <<<HTML
{$msg_alert}
{$api_status_html}

<!-- Navigacioni Tabovi -->
<div class="navbar navbar-default navbar-component navbar-xs" style="margin-bottom:20px;">
	<ul class="nav navbar-nav">
		<li class="active"><a href="#tab_settings" data-toggle="tab"><i class="fa fa-sliders position-left"></i> 1. Podešavanja i status</a></li>
		<li><a href="#tab_migrator" data-toggle="tab"><i class="fa fa-exchange position-left"></i> 2. Auto-Migrator (MixDrop/Dood zamjena)</a></li>
		<li><a href="#tab_docs" data-toggle="tab"><i class="fa fa-book position-left"></i> 3. Uputstvo za šablon</a></li>
	</ul>
</div>

<div class="tab-content">
	<!-- TAB 1: PODEŠAVANJA -->
	<div class="tab-pane active" id="tab_settings">
		<div class="panel panel-default">
			<div class="panel-heading">
				<h6 class="panel-title text-semibold"><i class="fa fa-play-circle position-left"></i> PeeStream Modul - Konfiguracija i integracija</h6>
			</div>
			<div class="panel-body">
				<form method="post" action="" class="form-horizontal">
					<input type="hidden" name="action" value="save">
					<input type="hidden" name="user_hash" value="{$dle_login_hash}">

					<div class="form-group">
						<label class="control-label col-sm-3 text-semibold">PeeStream API ključ:</label>
						<div class="col-sm-9">
							<input type="text" name="api_key" value="{$val_api_key}" class="form-control" placeholder="Unesite vaš privatni API ključ">
							<span class="help-block text-muted">Vaš lični API ključ možete pronaći u vašem PeeStream uploader panelu na stranici <b>Settings &gt; API</b>.</span>
						</div>
					</div>

					<div class="form-group">
						<label class="control-label col-sm-3 text-semibold">Domen plejera (Player Domain):</label>
						<div class="col-sm-9">
							<input type="text" name="player_domain" value="{$val_player_domain}" class="form-control" placeholder="https://peeplayer.com">
							<span class="help-block text-muted">Podrazumijevani domen je <code>https://peeplayer.com</code>. Ako koristite sopstveni brendirani CNAME plejer domen, unesite ga ovdje.</span>
						</div>
					</div>

					<div class="form-group">
						<label class="control-label col-sm-3 text-semibold">PeeStream API domen:</label>
						<div class="col-sm-9">
							<input type="text" name="api_domain" value="{$val_api_domain}" class="form-control" placeholder="https://peestream.com">
							<span class="help-block text-muted">Podrazumijevano <code>https://peestream.com</code>.</span>
						</div>
					</div>

					<div class="form-group">
						<label class="control-label col-sm-3 text-semibold">Podrazumijevani folder za uvoz:</label>
						<div class="col-sm-9">
							<select name="default_folder_id" class="form-control">
								{$folders_options}
							</select>
							<span class="help-block text-muted">Folder na vašem PeeStream nalogu u koji će se automatski svrstavati uvezeni filmovi i epizode.</span>
						</div>
					</div>

					<div class="form-group">
						<label class="control-label col-sm-3 text-semibold">Naziv DLE dodatnog polja (XField):</label>
						<div class="col-sm-9">
							<input type="text" name="xfield_name" value="{$val_xfield_name}" class="form-control" placeholder="peestream">
							<span class="help-block text-muted">Naziv dodatnog polja vijesti (xfield) u koje se upisuje PeeStream kod videa (npr. <code>peestream</code> ili <code>video_player</code>).</span>
						</div>
					</div>

					<hr>
					<h6 class="text-semibold"><i class="fa fa-television position-left"></i> Izgled i ponašanje plejera</h6>

					<div class="form-group">
						<label class="control-label col-sm-3 text-semibold">Responzivni plejer (Fluid 16:9):</label>
						<div class="col-sm-9">
							<div class="checkbox">
								<label><input type="checkbox" name="responsive" value="1" id="chk_responsive"{$chk_responsive}> Automatsko prilagođavanje širine (preporučeno, 100% širina ekrana sa 16:9 odnosom stranica)</label>
							</div>
						</div>
					</div>

					<div class="form-group" id="block_custom_size" style="{$style_custom_size}">
						<label class="control-label col-sm-3 text-semibold">Fiksna širina i visina:</label>
						<div class="col-sm-4">
							<input type="text" name="width" value="{$val_width}" class="form-control" placeholder="100% ili 800px">
							<span class="help-block text-muted">Širina (Width)</span>
						</div>
						<div class="col-sm-4">
							<input type="text" name="height" value="{$val_height}" class="form-control" placeholder="500px">
							<span class="help-block text-muted">Visina (Height)</span>
						</div>
					</div>

					<div class="form-group">
						<label class="control-label col-sm-3 text-semibold">Omogući automatsku reprodukciju (Autoplay):</label>
						<div class="col-sm-9">
							<div class="checkbox">
								<label><input type="checkbox" name="autoplay" value="1"{$chk_autoplay}> Pokreni video automatski pri učitavanju stranice (uz prigušen zvuk u modernim pregledačima)</label>
							</div>
						</div>
					</div>

					<div class="form-group">
						<label class="control-label col-sm-3 text-semibold">Omogući Fullscreen režim:</label>
						<div class="col-sm-9">
							<div class="checkbox">
								<label><input type="checkbox" name="allow_fullscreen" value="1"{$chk_fullscreen}> Dozvoli pregled preko cijelog ekrana (allowfullscreen)</label>
							</div>
						</div>
					</div>

					<div class="form-group">
						<label class="control-label col-sm-3 text-semibold">Sigurnosni token za Webhook (Secret):</label>
						<div class="col-sm-9">
							<input type="text" name="webhook_secret" value="{$val_webhook_secret}" class="form-control" placeholder="Proizvoljan tajni ključ za zaštitu webhook-a">
							<span class="help-block text-muted">Služi za autorizaciju dolaznih obavještenja o novim epizodama serija sa PeeStream servera.</span>
						</div>
					</div>

					<div class="form-group" style="margin-top:25px;">
						<div class="col-sm-9 col-sm-offset-3">
							<button type="submit" class="btn btn-primary"><i class="fa fa-floppy-o position-left"></i> Sačuvaj podešavanja</button>
						</div>
					</div>
				</form>
			</div>
		</div>
	</div>

	<!-- TAB 2: AUTO-MIGRATOR (KILLER FEATURE) -->
	<div class="tab-pane" id="tab_migrator">
		<div class="panel panel-default">
			<div class="panel-heading">
				<h6 class="panel-title text-semibold"><i class="fa fa-exchange position-left"></i> Auto-Migrator: Zamjena mrtvih MixDrop, Doodstream i Streamtape linkova</h6>
			</div>
			<div class="panel-body">
				<div class="alert alert-info" style="margin-bottom:20px;">
					<i class="fa fa-info-circle position-left"></i>
					Ovaj alat automatski skenira bazu vaših vijesti (tekst i dodatna polja) i pronalazi linkove sa konkurentskih servisa koji propadaju ili brišu fajlove (MixDrop, Doodstream, Streamtape, Filemoon, VOE, itd.). 
					PeeStream ih u pozadini preuzima i zamjenjuje funkcionalnim <b>PeeStream</b> plejerom!
				</div>

				<div style="display:flex;gap:15px;align-items:center;margin-bottom:20px;">
					<button type="button" id="btn_migrator_scan" class="btn btn-info" onclick="mgrStartScan()">
						<i class="fa fa-search position-left"></i> Skeniraj bazu vijesti
					</button>
					<button type="button" id="btn_migrator_start" class="btn btn-success" style="display:none;" onclick="mgrStartBatch()">
						<i class="fa fa-play position-left"></i> Pokreni automatsku migraciju
					</button>
					<button type="button" id="btn_migrator_pause" class="btn btn-warning" style="display:none;" onclick="mgrPauseBatch()">
						<i class="fa fa-pause position-left"></i> Pauziraj
					</button>
				</div>

				<!-- Statistika skenera -->
				<div class="row" id="mgr_stats_row" style="display:none;margin-bottom:20px;">
					<div class="col-sm-3">
						<div class="panel panel-body bg-slate-800 text-center" style="padding:15px;">
							<h3 class="no-margin text-semibold" id="cnt_found">0</h3>
							<div class="text-muted text-size-small">Pronađeno za zamjenu</div>
						</div>
					</div>
					<div class="col-sm-3">
						<div class="panel panel-body bg-success-600 text-center" style="padding:15px;">
							<h3 class="no-margin text-semibold" id="cnt_migrated">0</h3>
							<div class="text-muted text-size-small">Uspješno zamijenjeno</div>
						</div>
					</div>
					<div class="col-sm-3">
						<div class="panel panel-body bg-danger-600 text-center" style="padding:15px;">
							<h3 class="no-margin text-semibold" id="cnt_errors">0</h3>
							<div class="text-muted text-size-small">Greške pri uvozu</div>
						</div>
					</div>
					<div class="col-sm-3">
						<div class="panel panel-body bg-primary-600 text-center" style="padding:15px;">
							<h3 class="no-margin text-semibold" id="cnt_progress">0%</h3>
							<div class="text-muted text-size-small">Ukupan napredak</div>
						</div>
					</div>
				</div>

				<!-- Progress bar -->
				<div class="progress" id="mgr_progress_bar_box" style="display:none;height:18px;margin-bottom:20px;">
					<div class="progress-bar progress-bar-striped active progress-bar-success" id="mgr_progress_bar" style="width:0%;">0%</div>
				</div>

				<!-- Dnevnik događaja (Live Event Log) -->
				<h6 class="text-semibold"><i class="fa fa-list position-left"></i> Dnevnik migracije u realnom vremenu:</h6>
				<div id="mgr_log_box" style="background:#0f172a;color:#f8fafc;font-family:monospace;font-size:12px;padding:15px;border-radius:6px;height:240px;overflow-y:auto;border:1px solid #1e293b;">
					<div style="color:#94a3b8;">Kliknite na dugme "Skeniraj bazu vijesti" kako biste provjerili stanje linkova.</div>
				</div>
			</div>
		</div>
	</div>

	<!-- TAB 3: UPUTSTVO ZA ŠABLON -->
	<div class="tab-pane" id="tab_docs">
		<div class="panel panel-info">
			<div class="panel-heading">
				<h6 class="panel-title text-semibold"><i class="fa fa-info-circle position-left"></i> Uputstvo za postavljanje plejera u šablonu (Template Tags)</h6>
			</div>
			<div class="panel-body">
				<p>Kako biste prikazali PeeStream plejer u vijestima vašeg sajta, otvorite fajl vašeg šablona <code>templates/{vaš_šablon}/fullstory.tpl</code> i postavite sljedeći kod na mjesto gdje želite da se prikaže video:</p>
				<pre><code>[xfgiven_{$val_xfield_name}]
&lt;div class="peestream-embed-container"&gt;
    {peestream_player}
&lt;/div&gt;
[/xfgiven_{$val_xfield_name}]</code></pre>

				<p style="margin-top:15px;">Dostupne oznake šablona:</p>
				<ul>
					<li><code>{peestream_player}</code> - Generiše kompletan responzivni plejer sa uključenim kontrolama, prevodima i opcijama.</li>
					<li><code>[peestream] ... [/peestream]</code> - Prikazuje blok samo ukoliko članak posjeduje validan PeeStream video.</li>
					<li><code>[!peestream] ... [/!peestream]</code> - Prikazuje blok samo ukoliko članak NEMA video.</li>
					<li><code>{peestream_code}</code> - Prikazuje samo sirovi kod videa (pogodno za sajtove sa prilagođenim tabovima ili eksternim plejer listama).</li>
					<li><code>{peestream_url}</code> - Prikazuje puni direktni embed URL.</li>
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
	btn.innerHTML = "<i class='fa fa-spinner fa-spin position-left'></i> Skeniram bazu...";
	mgrLog("Pokrećem analizu baze vijesti (dle_post)...", "#38bdf8");
	
	fetch("?mod=peestream&action=migrator_scan&user_hash=" + DLE_HASH)
		.then(function(r) { return r.json(); })
		.then(function(data) {
			btn.disabled = false;
			btn.innerHTML = "<i class='fa fa-search position-left'></i> Ponovo skeniraj";
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
					mgrLog("Skeniranje završeno! Pronađeno ukupno <b>" + mgrTotal + "</b> članaka sa linkovima konkurentskih hostera.", "#4ade80");
					document.getElementById("btn_migrator_start").style.display = "inline-block";
				} else {
					mgrLog("Čestitamo! U vašoj bazi vijesti nema pronađenih konkurentskih linkova za migraciju.", "#a3e635");
				}
			} else {
				mgrLog("Greška pri skeniranju: " + (data.error || "Nepoznata greška"), "#f87171");
			}
		})
		.catch(function(err) {
			btn.disabled = false;
			btn.innerHTML = "<i class='fa fa-search position-left'></i> Ponovo skeniraj";
			mgrLog("Greška mrežnog zahtjeva: " + err, "#f87171");
		});
}

function mgrStartBatch() {
	if(mgrQueue.length === 0) return;
	mgrRunning = true;
	document.getElementById("btn_migrator_start").style.display = "none";
	document.getElementById("btn_migrator_pause").style.display = "inline-block";
	document.getElementById("mgr_progress_bar_box").style.display = "block";
	mgrLog("Započinjem batch migraciju linkova na PeeStream...", "#38bdf8");
	mgrProcessNext();
}

function mgrPauseBatch() {
	mgrRunning = false;
	document.getElementById("btn_migrator_pause").style.display = "none";
	document.getElementById("btn_migrator_start").style.display = "inline-block";
	mgrLog("Migracija je pauzirana na zahtjev korisnika.", "#fbbf24");
}

function mgrProcessNext() {
	if(!mgrRunning) return;
	if(mgrQueue.length === 0) {
		mgrRunning = false;
		document.getElementById("btn_migrator_pause").style.display = "none";
		document.getElementById("btn_migrator_start").style.display = "none";
		mgrLog("SVI ČLANCI SU USPJEŠNO OBRADJENI! Migracija je u potpunosti završena.", "#4ade80");
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
				mgrLog("Vijest #" + item.id + " ('" + item.title + "') -> [" + item.hoster + "] zamijenjen sa PeeStream kodom: <b>" + data.filecode + "</b>", "#4ade80");
			} else {
				mgrErrors++;
				document.getElementById("cnt_errors").innerText = mgrErrors;
				mgrLog("Vijest #" + item.id + " ('" + item.title + "') -> Greška: " + (data.error || "Nije uspjelo"), "#f87171");
			}
			
			var pct = Math.round(((mgrDone + mgrErrors) / mgrTotal) * 100);
			document.getElementById("cnt_progress").innerText = pct + "%";
			var pb = document.getElementById("mgr_progress_bar");
			pb.style.width = pct + "%";
			pb.innerText = pct + "%";
			
			if(mgrRunning) {
				setTimeout(mgrProcessNext, 400); // 400ms razmaka između zahtjeva radi stabilnosti
			}
		})
		.catch(function(err) {
			mgrErrors++;
			document.getElementById("cnt_errors").innerText = mgrErrors;
			mgrLog("Vijest #" + item.id + " -> Mrežna greška: " + err, "#f87171");
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

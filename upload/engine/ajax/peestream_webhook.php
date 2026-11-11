<?php
/*
=============================================================================
PeeStream Video Player & Tools - DataLife Engine Addon
Module: Auto-Publish Webhook Receiver (Series & Movies)
File: engine/ajax/peestream_webhook.php
Version: 1.0.0
=============================================================================
*/

@error_reporting( E_ALL ^ E_WARNING ^ E_NOTICE );
@ini_set( 'display_errors', false );
@ini_set( 'html_errors', false );

define( 'DATALIFEENGINE', true );
define( 'ROOT_DIR', substr( dirname( __FILE__ ), 0, -12 ) );
define( 'ENGINE_DIR', ROOT_DIR . '/engine' );

// Učitavanje konfiguracije baze i DLE jezgra
require_once ( ENGINE_DIR . '/data/dbconfig.php' );
require_once ( ENGINE_DIR . '/classes/mysql.php' );
require_once ( ENGINE_DIR . '/data/config.php' );

if( file_exists( ENGINE_DIR . '/classes/plugins.class.php' ) ) {
	include_once ENGINE_DIR . '/classes/plugins.class.php';
}

$inc_func = ENGINE_DIR . '/inc/include/functions.inc.php';
if( file_exists( $inc_func ) ) {
	require_once ( $inc_func );
}

// Učitavanje konfiguracije PeeStream modula
$conf_file = ENGINE_DIR . '/data/peestream.conf.php';
if( file_exists( $conf_file ) ) {
	require_once ( $conf_file );
} else {
	$peestream_config = array();
}

$cfg = array_merge( array(
	'player_domain' => 'https://peeplayer.com',
	'xfield_name' => 'peestream',
	'webhook_secret' => '',
	'auto_create_news' => '0',
	'default_category' => '1',
), isset( $peestream_config ) ? $peestream_config : array() );

header( 'Content-Type: application/json; charset=utf-8' );

// 1. Sigurnosna verifikacija dolaznog Webhook zahtjeva
$incoming_secret = '';
if( isset( $_GET['secret'] ) ) {
	$incoming_secret = trim( $_GET['secret'] );
} elseif( isset( $_SERVER['HTTP_X_WEBHOOK_SECRET'] ) ) {
	$incoming_secret = trim( $_SERVER['HTTP_X_WEBHOOK_SECRET'] );
}

if( !empty( $cfg['webhook_secret'] ) ) {
	if( empty( $incoming_secret ) || !hash_equals( (string)$cfg['webhook_secret'], (string)$incoming_secret ) ) {
		http_response_code( 403 );
		echo json_encode( array( 'success' => false, 'error' => 'Pristup odbijen. Neispravan tajni token (secret).' ) );
		exit;
	}
}

// 2. Čitanje JSON tijela zahtjeva
$raw_input = file_get_contents( 'php://input' );
if( empty( $raw_input ) ) {
	http_response_code( 400 );
	echo json_encode( array( 'success' => false, 'error' => 'Prazno tijelo zahtjeva.' ) );
	exit;
}

$data = json_decode( $raw_input, true );
if( !is_array( $data ) || empty( $data['file_code'] ) ) {
	http_response_code( 400 );
	echo json_encode( array( 'success' => false, 'error' => 'Neispravan JSON format ili nedostaje file_code.' ) );
	exit;
}

$event = isset( $data['event'] ) ? trim( $data['event'] ) : 'video_ready';
$file_code = trim( $data['file_code'] );
$imdb_id = isset( $data['imdb_id'] ) ? trim( $data['imdb_id'] ) : '';
$title = isset( $data['title'] ) ? trim( strip_tags( $data['title'] ) ) : 'Video';
$season = isset( $data['season'] ) ? intval( $data['season'] ) : 0;
$episode = isset( $data['episode'] ) ? intval( $data['episode'] ) : 0;
$poster_url = isset( $data['poster'] ) ? trim( $data['poster'] ) : ( isset( $data['poster_url'] ) ? trim( $data['poster_url'] ) : '' );
$embed_url = rtrim( $cfg['player_domain'], '/' ) . '/e/' . $file_code;

$xf_name = !empty( $cfg['xfield_name'] ) ? $cfg['xfield_name'] : 'peestream';
$table = PREFIX . "_post";

// 3. Pronalaženje odgovarajuće vijesti u DLE bazi
$matched_post = null;

if( !empty( $imdb_id ) ) {
	$safe_imdb = $db->safesql( $imdb_id );
	$matched_post = $db->super_query( "SELECT id, title, xfields, short_story, full_story, category FROM {$table} WHERE xfields LIKE '%{$safe_imdb}%' ORDER BY id DESC LIMIT 1" );
}

// Fallback: Ako nije pronađeno po IMDb-u, potraži po naslovu serije/filma
if( empty( $matched_post['id'] ) && !empty( $title ) ) {
	$clean_title = preg_replace( '/\s*(?:Season|Sezona|S\d+|E\d+|\d+x\d+).*/i', '', $title );
	$safe_title = $db->safesql( trim( $clean_title ) );
	if( strlen( $safe_title ) > 3 ) {
		$matched_post = $db->super_query( "SELECT id, title, xfields, short_story, full_story, category FROM {$table} WHERE title LIKE '%{$safe_title}%' ORDER BY id DESC LIMIT 1" );
	}
}

// 4. Ažuriranje postojeće vijesti
if( !empty( $matched_post['id'] ) ) {
	$post_id = intval( $matched_post['id'] );
	$upd_xf = $matched_post['xfields'];
	$upd_full = $matched_post['full_story'];
	
	// Ažuriranje DLE dodatnih polja (xfields)
	if( function_exists( 'xfieldsdataload' ) && function_exists( 'xfieldsdatasave' ) ) {
		$xf_arr = xfieldsdataload( $upd_xf );
		
		// Ako je serija sa sezonama i epizodama, sačuvaj i epizodni tag
		if( $season > 0 && $episode > 0 ) {
			$ep_key = sprintf( "s%02de%02d", $season, $episode );
			$xf_arr[$xf_name . '_' . $ep_key] = $file_code;
			// Ažuriraj i glavno polje najnovijim kodom
			$xf_arr[$xf_name] = $file_code;
			if( !empty( $imdb_id ) && empty( $xf_arr['imdb'] ) ) {
				$xf_arr['imdb'] = $imdb_id;
			}
		} else {
			// Film ili pojedinačni video
			$xf_arr[$xf_name] = $file_code;
		}
		
		$upd_xf = xfieldsdatasave( $xf_arr );
	} else {
		// Klasična zamjena u sirovom nizu
		$upd_xf = preg_replace( "#" . preg_quote( $xf_name ) . "\|[^\|]*#", $xf_name . "|" . $file_code, $upd_xf );
	}
	
	$safe_xf = $db->safesql( $upd_xf );
	
	// Ažuriraj datum objave/izmjene kako bi vijest isplivala na vrh
	$now_date = date( "Y-m-d H:i:s" );
	$db->query( "UPDATE {$table} SET xfields='{$safe_xf}', date='{$now_date}' WHERE id='{$post_id}'" );
	
	if( function_exists( 'clear_cache' ) ) {
		clear_cache( array( 'news_' ) );
	}
	
	echo json_encode( array(
		'success' => true,
		'action' => 'updated',
		'post_id' => $post_id,
		'file_code' => $file_code,
		'event' => $event,
		'msg' => 'Postojeća vijest ID ' . $post_id . ' je uspješno ažurirana novim kodom.'
	) );
	exit;
}

// 5. Automatsko kreiranje nove vijesti (ako je omogućeno u podešavanjima)
if( !empty( $cfg['auto_create_news'] ) && $cfg['auto_create_news'] == '1' ) {
	$new_title = $title;
	if( $season > 0 && $episode > 0 ) {
		$new_title .= sprintf( " (Sezona %d, Epizoda %d)", $season, $episode );
	}
	
	$cat_id = intval( $cfg['default_category'] ) ?: 1;
	$now_date = date( "Y-m-d H:i:s" );
	$alt_name = totranslit( $new_title, true, false );
	
	// Priprema xfields
	$new_xf = array(
		$xf_name => $file_code,
	);
	if( !empty( $imdb_id ) ) {
		$new_xf['imdb'] = $imdb_id;
	}
	if( !empty( $poster_url ) ) {
		$new_xf['poster'] = $poster_url;
	}
	$xf_str = function_exists( 'xfieldsdatasave' ) ? xfieldsdatasave( $new_xf ) : ( $xf_name . "|" . $file_code );
	
	$short_story = '<p>Gledajte ' . htmlspecialchars( $new_title ) . ' online sa prevodom u punom kvalitetu.</p>';
	
	$safe_title = $db->safesql( $new_title );
	$safe_short = $db->safesql( $short_story );
	$safe_full = $safe_short;
	$safe_xf = $db->safesql( $xf_str );
	$safe_alt = $db->safesql( $alt_name );
	
	$db->query( "INSERT INTO {$table} (autor, date, short_story, full_story, xfields, title, descr, keywords, category, alt_name, approve, allow_comm, allow_main) 
				VALUES ('PeeStream Auto', '{$now_date}', '{$safe_short}', '{$safe_full}', '{$safe_xf}', '{$safe_title}', '{$safe_title}', '{$safe_title}', '{$cat_id}', '{$safe_alt}', 1, 1, 1)" );
				
	$inserted_id = $db->insert_id();
	
	if( function_exists( 'clear_cache' ) ) {
		clear_cache( array( 'news_' ) );
	}
	
	echo json_encode( array(
		'success' => true,
		'action' => 'created',
		'post_id' => $inserted_id,
		'file_code' => $file_code,
		'event' => $event,
		'msg' => 'Automatski je kreirana nova vijest ID ' . $inserted_id . ' za primljeni naslov.'
	) );
	exit;
}

// 6. Nije pronađeno i nije uključeno auto-kreiranje
echo json_encode( array(
	'success' => true,
	'action' => 'ignored',
	'file_code' => $file_code,
	'event' => $event,
	'msg' => 'Video je primljen, ali u DLE bazi nije pronađena odgovarajuća vijest sa IMDb ID: ' . $imdb_id . ' (Auto-kreiranje je isključeno).'
) );
exit;
?>

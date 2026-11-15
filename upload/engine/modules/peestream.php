<?php
/*
=============================================================================
PeeStream Video Player & Tools - DataLife Engine Addon
Module: Frontend Template Parser
File: engine/modules/peestream.php
Version: 1.0.4
=============================================================================
*/

if( !defined( 'DATALIFEENGINE' ) ) {
	header( "HTTP/1.1 403 Forbidden" );
	header( 'Location: ../../' );
	die( "Hacking attempt!" );
}

// Load configuration if not already loaded
if( !isset( $peestream_config ) ) {
	$conf_file = ENGINE_DIR . '/data/peestream.conf.php';
	if( file_exists( $conf_file ) ) {
		require_once ( $conf_file );
	} else {
		$peestream_config = array();
	}
}

$cfg = array_merge( array(
	'player_domain' => 'https://peeplayer.com',
	'responsive' => '1',
	'width' => '100%',
	'height' => '500',
	'aspect_ratio' => '16:9',
	'autoplay' => '0',
	'allow_fullscreen' => '1',
	'xfield_name' => 'peestream',
), isset( $peestream_config ) ? $peestream_config : array() );

// Helper function to sanitize and extract video code
if( !function_exists( 'peestream_clean_code' ) ) {
	function peestream_clean_code( $raw_input ) {
		$raw_input = trim( strip_tags( (string)$raw_input ) );
		if( empty( $raw_input ) ) {
			return '';
		}
		
		// If a full URL is provided (e.g., https://peeplayer.com/e/abc123xyz)
		if( preg_match( '#/(?:e|v|embed)/([a-zA-Z0-9_\-]+)#i', $raw_input, $m ) ) {
			return $m[1];
		}
		
		// If raw video code is provided
		if( preg_match( '#^[a-zA-Z0-9_\-]+$#', $raw_input ) ) {
			return $raw_input;
		}
		
		return '';
	}
}

// Helper function to generate player HTML markup
if( !function_exists( 'peestream_render_player' ) ) {
	function peestream_render_player( $video_code, $cfg ) {
		$domain = rtrim( $cfg['player_domain'], '/' );
		$embed_url = $domain . '/e/' . urlencode( $video_code );
		
		$params = array();
		if( !empty( $cfg['autoplay'] ) && $cfg['autoplay'] == '1' ) {
			$params['autoplay'] = '1';
		}
		if( !empty( $params ) ) {
			$embed_url .= '?' . http_build_query( $params );
		}
		
		$allow_fs = ( !empty( $cfg['allow_fullscreen'] ) && $cfg['allow_fullscreen'] == '1' ) ? ' allowfullscreen webkitallowfullscreen mozallowfullscreen' : '';
		$allow_attr = 'allow="autoplay; fullscreen; picture-in-picture; encrypted-media"';
		
		if( !empty( $cfg['responsive'] ) && $cfg['responsive'] == '1' ) {
			// Fluid 16:9 responsive container
			$pb = '56.25%'; // Default 16:9 aspect ratio
			if( !empty( $cfg['aspect_ratio'] ) ) {
				$parts = explode( ':', $cfg['aspect_ratio'] );
				if( count( $parts ) == 2 && intval( $parts[0] ) > 0 && intval( $parts[1] ) > 0 ) {
					$pb = round( ( intval( $parts[1] ) / intval( $parts[0] ) ) * 100, 4 ) . '%';
				}
			}
			
			$html = '<div class="peestream-responsive-container" style="position:relative;width:100%;padding-bottom:' . $pb . ';height:0;overflow:hidden;border-radius:6px;background:#000;box-shadow:0 4px 15px rgba(0,0,0,0.3);">'
				. '<iframe src="' . htmlspecialchars( $embed_url ) . '" style="position:absolute;top:0;left:0;width:100%;height:100%;border:0;" frameborder="0"' . $allow_fs . ' ' . $allow_attr . '></iframe>'
				. '</div>';
		} else {
			// Fixed dimensions
			$w = !empty( $cfg['width'] ) ? htmlspecialchars( $cfg['width'] ) : '100%';
			$h = !empty( $cfg['height'] ) ? htmlspecialchars( $cfg['height'] ) : '500px';
			if( is_numeric( $w ) ) $w .= 'px';
			if( is_numeric( $h ) ) $h .= 'px';
			
			$html = '<div class="peestream-fixed-container" style="max-width:100%;overflow:hidden;border-radius:6px;background:#000;">'
				. '<iframe src="' . htmlspecialchars( $embed_url ) . '" width="' . $w . '" height="' . $h . '" style="border:0;display:block;max-width:100%;" frameborder="0"' . $allow_fs . ' ' . $allow_attr . '></iframe>'
				. '</div>';
		}
		
		return $html;
	}
}

// Locate video code within article
$video_code = '';
$xf_name = !empty( $cfg['xfield_name'] ) ? $cfg['xfield_name'] : 'peestream';

// 1. Check unpacked extra fields array ($xfieldsdata)
if( isset( $xfieldsdata ) && is_array( $xfieldsdata ) && !empty( $xfieldsdata[$xf_name] ) ) {
	$video_code = peestream_clean_code( $xfieldsdata[$xf_name] );
}

// 2. If not found in $xfieldsdata, inspect raw field $row['xfields']
if( empty( $video_code ) && isset( $row['xfields'] ) && !empty( $row['xfields'] ) ) {
	if( function_exists( 'xfieldsdataload' ) ) {
		$parsed_xf = xfieldsdataload( $row['xfields'] );
		if( !empty( $parsed_xf[$xf_name] ) ) {
			$video_code = peestream_clean_code( $parsed_xf[$xf_name] );
		}
	}
}

// 3. Fallback: Check for BBCode tag [peestream]CODE[/peestream] in article story content
if( empty( $video_code ) ) {
	$story_text = '';
	if( isset( $row['full_story'] ) ) $story_text .= ' ' . $row['full_story'];
	if( isset( $row['short_story'] ) ) $story_text .= ' ' . $row['short_story'];
	
	if( preg_match( '#\[peestream\](.*?)\[/peestream\]#is', $story_text, $match_tag ) ) {
		$video_code = peestream_clean_code( $match_tag[1] );
	}
}

// Replace template tags in DLE template ($tpl object)
if( isset( $tpl ) && is_object( $tpl ) ) {
	if( !empty( $video_code ) ) {
		$player_html = peestream_render_player( $video_code, $cfg );
		$embed_url = rtrim( $cfg['player_domain'], '/' ) . '/e/' . urlencode( $video_code );
		
		// Replace primary template tags
		$tpl->set( '{peestream_player}', $player_html );
		$tpl->set( '{peestream_code}', $video_code );
		$tpl->set( '{peestream_url}', $embed_url );
		
		// Enable [peestream]...[/peestream] block
		$tpl->set_block( "'\\[peestream\\](.*?)\\[/peestream\\]'si", "\\1" );
		// Hide [!peestream]...[/!peestream] block
		$tpl->set_block( "'\\[!peestream\\](.*?)\\[/!peestream\\]'si", "" );
	} else {
		// No video attached - clear tags
		$tpl->set( '{peestream_player}', '' );
		$tpl->set( '{peestream_code}', '' );
		$tpl->set( '{peestream_url}', '' );
		
		// Hide [peestream]...[/peestream] block
		$tpl->set_block( "'\\[peestream\\](.*?)\\[/peestream\\]'si", "" );
		// Show [!peestream]...[/!peestream] block
		$tpl->set_block( "'\\[!peestream\\](.*?)\\[/!peestream\\]'si", "\\1" );
	}
}
?>

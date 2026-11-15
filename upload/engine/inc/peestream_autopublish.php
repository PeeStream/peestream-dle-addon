<?php
/**
 * Shared, side-effect-free DLE AutoPublish identity and xfield mapping helpers.
 */
if ( ! function_exists( 'peestream_autopublish_identity_matches' ) ) {
	function peestream_autopublish_parse_identity( $payload ) {
		if ( ! is_array( $payload ) ) return false;
		$imdb = isset( $payload['imdb_id'] ) ? trim( (string)$payload['imdb_id'] ) : ( isset( $payload['imdb'] ) ? trim( (string)$payload['imdb'] ) : '' );
		if ( '' !== $imdb && ! preg_match( '/^tt\d{5,12}$/i', $imdb ) ) return false;
		$tmdb_raw = isset( $payload['tmdb_id'] ) ? trim( (string)$payload['tmdb_id'] ) : '';
		if ( '' !== $tmdb_raw && ! ctype_digit( $tmdb_raw ) ) return false;
		$kind = isset( $payload['kind'] ) ? strtolower( trim( (string)$payload['kind'] ) ) : '';
		if ( '' !== $kind && ! in_array( $kind, array( 'movie', 'series', 'episode' ), true ) ) return false;
		$numbers = array();
		foreach ( array( 'season', 'episode' ) as $key ) {
			if ( ! array_key_exists( $key, $payload ) || '' === trim( (string)$payload[$key] ) ) {
				$numbers[$key] = 0;
			} elseif ( ! preg_match( '/^\d+$/', trim( (string)$payload[$key] ) ) ) {
				return false;
			} else {
				$numbers[$key] = (int)$payload[$key];
			}
		}
		$is_episode = 'episode' === $kind || ( $numbers['season'] > 0 && $numbers['episode'] > 0 );
		if ( $is_episode && ( '' === $tmdb_raw || 0 === $numbers['episode'] ) ) return false;
		return array(
			'imdb_id' => $imdb,
			'tmdb_id' => '' === $tmdb_raw ? '' : (string)(int)$tmdb_raw,
			'kind' => $kind,
			'season' => $numbers['season'],
			'episode' => $numbers['episode'],
			'is_episode' => $is_episode,
		);
	}

	function peestream_autopublish_identity_matches( $fields, $payload, $config ) {
		$fields = is_array( $fields ) ? $fields : array();
		$identity = peestream_autopublish_parse_identity( $payload );
		if ( false === $identity ) return false;
		$season = $identity['season'];
		$episode = $identity['episode'];
		$tmdb = $identity['tmdb_id'];
		$imdb = $identity['imdb_id'];
		$names = array(
			'imdb' => ! empty( $config['xfield_imdb'] ) ? strtolower( (string)$config['xfield_imdb'] ) : 'imdb',
			'tmdb' => ! empty( $config['xfield_tmdb'] ) ? strtolower( (string)$config['xfield_tmdb'] ) : 'tmdb',
			'series' => ! empty( $config['xfield_tmdb_series'] ) ? strtolower( (string)$config['xfield_tmdb_series'] ) : 'tmdb_series_id',
			'season' => ! empty( $config['xfield_season'] ) ? strtolower( (string)$config['xfield_season'] ) : 'season',
			'episode' => ! empty( $config['xfield_episode'] ) ? strtolower( (string)$config['xfield_episode'] ) : 'episode',
		);
		$is_episode = $identity['is_episode'];
		if ( $is_episode ) {
			return '' !== $tmdb && isset( $fields[$names['series']], $fields[$names['season']], $fields[$names['episode']] )
				&& (string)$fields[$names['series']] === $tmdb
				&& (string)$fields[$names['season']] === (string)$season
				&& (string)$fields[$names['episode']] === (string)$episode;
		}
		return ( '' !== $imdb && isset( $fields[$names['imdb']] ) && strcasecmp( trim( (string)$fields[$names['imdb']] ), $imdb ) === 0 )
			|| ( '' !== $tmdb && isset( $fields[$names['tmdb']] ) && (string)$fields[$names['tmdb']] === $tmdb );
	}
}

if ( ! function_exists( 'peestream_autopublish_event_key' ) ) {
	function peestream_autopublish_event_key( $event_id ) {
		return 'v2_' . substr( hash( 'sha256', (string)$event_id ), 0, 28 );
	}
}

if ( ! function_exists( 'peestream_autopublish_release_event' ) ) {
	function peestream_autopublish_release_event( $db, $table, $event_id ) {
		if ( ! is_object( $db ) || ! method_exists( $db, 'query' ) || ! method_exists( $db, 'safesql' ) ) return false;
		$safe_id = $db->safesql( (string)$event_id );
		return (bool)$db->query( "DELETE FROM {$table} WHERE event_id = '{$safe_id}'", false );
	}
}

if ( ! function_exists( 'peestream_autopublish_approval_state' ) ) {
	function peestream_autopublish_approval_state( $config ) {
		return ! empty( $config['moderate_new_posts'] ) && '1' === (string)$config['moderate_new_posts'] ? 0 : 1;
	}
}

if ( ! function_exists( 'peestream_autopublish_map_xfields' ) ) {
	function peestream_autopublish_map_xfields( $fields, $payload, $config ) {
		$fields = is_array( $fields ) ? $fields : array();
		$identity = peestream_autopublish_parse_identity( $payload );
		if ( false === $identity ) return $fields;
		$code = isset( $payload['file_code'] ) ? trim( (string)$payload['file_code'] ) : '';
		if ( ! preg_match( '/^[a-zA-Z0-9_-]{1,64}$/', $code ) ) return $fields;
		$names = array(
			'imdb' => ! empty( $config['xfield_imdb'] ) ? strtolower( (string)$config['xfield_imdb'] ) : 'imdb',
			'tmdb' => ! empty( $config['xfield_tmdb'] ) ? strtolower( (string)$config['xfield_tmdb'] ) : 'tmdb',
			'series' => ! empty( $config['xfield_tmdb_series'] ) ? strtolower( (string)$config['xfield_tmdb_series'] ) : 'tmdb_series_id',
			'season' => ! empty( $config['xfield_season'] ) ? strtolower( (string)$config['xfield_season'] ) : 'season',
			'episode' => ! empty( $config['xfield_episode'] ) ? strtolower( (string)$config['xfield_episode'] ) : 'episode',
		);
		$player = ! empty( $config['xfield_player'] ) ? strtolower( (string)$config['xfield_player'] ) : ( ! empty( $config['xfield_name'] ) ? strtolower( (string)$config['xfield_name'] ) : 'peestream' );
		$source = ! empty( $config['xfield_source_code'] ) ? strtolower( (string)$config['xfield_source_code'] ) : 'peestream_code';
		$domain = ! empty( $config['player_domain'] ) ? rtrim( (string)$config['player_domain'], '/' ) : 'https://peeplayer.com';
		$url = isset( $payload['player_url'] ) && 0 === strpos( (string)$payload['player_url'], 'https://' )
			? (string)$payload['player_url'] : $domain . '/e/' . rawurlencode( $code );
		$fields[$source] = $code;
		$fields[$player] = ! empty( $config['xfield_player'] )
			? '<iframe src="' . htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' ) . '" loading="lazy" allowfullscreen style="width:100%;aspect-ratio:16/9;border:0"></iframe>'
			: $code;
		$legacy = ! empty( $config['xfield_name'] ) ? strtolower( (string)$config['xfield_name'] ) : 'peestream';
		if ( $legacy !== $player && $legacy !== $source ) $fields[$legacy] = $code;
		$imdb = $identity['imdb_id'];
		$tmdb = $identity['tmdb_id'];
		if ( '' !== $imdb && empty( $fields[$names['imdb']] ) ) $fields[$names['imdb']] = $imdb;
		$is_episode = $identity['is_episode'];
		if ( '' !== $tmdb ) $fields[$is_episode ? $names['series'] : $names['tmdb']] = $tmdb;
		if ( $is_episode ) {
			$fields[$names['season']] = (string)$identity['season'];
			$fields[$names['episode']] = (string)$identity['episode'];
		}
		return $fields;
	}
}

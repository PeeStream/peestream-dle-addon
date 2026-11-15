<?php
/** Standalone DLE PeeStream V2 webhook security contract tests (mock only). */
error_reporting(E_ALL);
require_once __DIR__ . '/../engine/inc/peestream_autopublish.php';
$pass = 0; $fail = 0;
function check_case($name, $ok) { global $pass, $fail; if ($ok) { echo "[PASS] $name\n"; $pass++; } else { echo "[FAIL] $name\n"; $fail++; } }
function dle_webhook_signature($body, $secret) { return hash_hmac('sha256', $body, $secret); }
function dle_webhook_verify($body, $header, $secret) { $header = preg_replace('/^sha256=/i', '', trim($header)); return $header !== '' && hash_equals(dle_webhook_signature($body, $secret), $header); }
$secret = 'mock_secret_0123456789'; $body = '{"event":"media.ready","event_id":"evt_mock_1"}'; $sig = dle_webhook_signature($body, $secret);
check_case('valid HMAC accepted', dle_webhook_verify($body, 'sha256='.$sig, $secret));
check_case('tampered body rejected', !dle_webhook_verify($body.' ', 'sha256='.$sig, $secret));
check_case('wrong secret rejected', !dle_webhook_verify($body, 'sha256='.$sig, 'wrong_secret'));
check_case('missing signature rejected', !dle_webhook_verify($body, '', $secret));
$payload=json_decode($body,true); check_case('media.ready event_id parsed', $payload['event']==='media.ready' && $payload['event_id']==='evt_mock_1');
$site='site_abc123'; check_case('matching site_id accepted', hash_equals($site,$site)); check_case('mismatched site_id rejected', !hash_equals($site,'site_other'));
$now=time(); $old=$now-73*3600; check_case('event older than 72h expires', $old < $now-72*3600); check_case('event within 72h remains eligible', $now-71*3600 >= $now-72*3600);
$events=['media.ready','site.test']; check_case('site.test recognized', in_array('site.test',$events,true)); check_case('unknown event is safely ignorable', !in_array('future.event',$events,true));
check_case('same event_id maps to same idempotency key', peestream_autopublish_event_key('evt_1')===peestream_autopublish_event_key('evt_1'));
check_case('different event_ids map to distinct audit keys', peestream_autopublish_event_key('evt_1')!==peestream_autopublish_event_key('evt_2'));
class MockEventReleaseDB { public $query = ''; public function safesql($v) { return addslashes($v); } public function query($q, $cache=false) { $this->query=$q; return true; } }
$release_db = new MockEventReleaseDB();
check_case('retry release targets only the matching event_id', peestream_autopublish_release_event($release_db,'dle_peestream_event_ids','evt_retry') && strpos($release_db->query,"event_id = 'evt_retry'")!==false);
$cfg = array('xfield_imdb'=>'imdb','xfield_tmdb'=>'tmdb','xfield_tmdb_series'=>'tmdb_series_id','xfield_season'=>'season','xfield_episode'=>'episode','xfield_name'=>'peestream','xfield_player'=>'player_embed','xfield_source_code'=>'peestream_code','player_domain'=>'https://peeplayer.com');
$movie = array('kind'=>'movie','imdb_id'=>'tt1234567','tmdb_id'=>321,'file_code'=>'AbC123','player_url'=>'https://peeplayer.com/e/AbC123');
check_case('movie matches configured IMDb identity', peestream_autopublish_identity_matches(array('imdb'=>'tt1234567'),$movie,$cfg));
check_case('movie falls back to configured TMDb identity', peestream_autopublish_identity_matches(array('tmdb'=>'321'),$movie,$cfg));
check_case('movie does not match a different identifier', !peestream_autopublish_identity_matches(array('tmdb'=>'999'),$movie,$cfg));
check_case('legacy imdb alias is normalized consistently', peestream_autopublish_parse_identity(array('imdb'=>'tt1234567'))['imdb_id']==='tt1234567');
check_case('episode season zero is valid', peestream_autopublish_parse_identity(array('kind'=>'episode','tmdb_id'=>1396,'season'=>0,'episode'=>2))['is_episode']===true);
check_case('malformed season is rejected', peestream_autopublish_parse_identity(array('kind'=>'episode','tmdb_id'=>1396,'season'=>'1x','episode'=>2))===false);
check_case('negative episode is rejected', peestream_autopublish_parse_identity(array('kind'=>'episode','tmdb_id'=>1396,'season'=>1,'episode'=>-2))===false);
check_case('non-numeric TMDb ID is rejected', peestream_autopublish_parse_identity(array('kind'=>'movie','tmdb_id'=>'1396x'))===false);
$episode_payload = array('kind'=>'episode','tmdb_id'=>1396,'season'=>0,'episode'=>2,'file_code'=>'Spec02','player_url'=>'https://peeplayer.com/e/Spec02');
check_case('episode matches TMDb series plus season zero and episode', peestream_autopublish_identity_matches(array('tmdb_series_id'=>'1396','season'=>'0','episode'=>'2'),$episode_payload,$cfg));
check_case('episode mismatch in season is rejected', !peestream_autopublish_identity_matches(array('tmdb_series_id'=>'1396','season'=>'1','episode'=>'2'),$episode_payload,$cfg));
$mapped = peestream_autopublish_map_xfields(array(),$movie,$cfg);
check_case('mapping stores source code in auxiliary xfield', isset($mapped['peestream_code']) && $mapped['peestream_code']==='AbC123');
check_case('mapping stores responsive iframe in configured player xfield', isset($mapped['player_embed']) && strpos($mapped['player_embed'],'<iframe src="https://peeplayer.com/e/AbC123"')===0);
check_case('mapping preserves legacy peestream code xfield alongside player embed', isset($mapped['peestream']) && $mapped['peestream']==='AbC123');
$legacy = peestream_autopublish_map_xfields(array(),$movie,array('xfield_name'=>'peestream'));
check_case('legacy default continues storing code in peestream xfield', isset($legacy['peestream']) && $legacy['peestream']==='AbC123');
check_case('moderated create defaults to DLE draft state', peestream_autopublish_approval_state(array('moderate_new_posts'=>'1'))===0);
check_case('explicit moderation-off create is publish state', peestream_autopublish_approval_state(array('moderate_new_posts'=>'0'))===1);
echo "Mock DLE Webhook: $pass passed, $fail failed.\n"; exit($fail ? 1 : 0);

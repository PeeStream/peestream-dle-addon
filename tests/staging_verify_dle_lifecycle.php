<?php
/**
 * Staging Verification: DLE Clean Installation, Lifecycle & Secret Persistence
 * File: staging_verify_dle_lifecycle.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$test_dir = '/srv/build/tmp/staging_dle_test';
if (is_dir($test_dir)) {
    exec("rm -rf " . escapeshellarg($test_dir));
}
mkdir($test_dir . '/engine/data', 0755, true);

define('DATALIFEENGINE', true);
define('ENGINE_DIR', $test_dir . '/engine');

$passed = 0;
$failed = 0;

function it($desc, $cond) {
    global $passed, $failed;
    if ($cond) {
        echo " [PASS] " . $desc . "\n";
        $passed++;
    } else {
        echo " [FAIL] " . $desc . "\n";
        $failed++;
    }
}

echo "=========================================================\n";
echo "DLE STAGING LIFECYCLE & SECRET PERSISTENCE VERIFICATION\n";
echo "=========================================================\n\n";

// --- 1. Clean Installation without peestream.conf.php ---
echo "--- 1. Testing Clean Installation (no peestream.conf.php) ---\n";
$conf_path = ENGINE_DIR . '/data/peestream.conf.php';
it("peestream.conf.php initially does not exist", !file_exists($conf_path));

// Extract <phpinstall> from peestream.xml
$xml_content = file_get_contents('/srv/build/dle-peestream-addon/peestream.xml');
$xml = simplexml_load_string($xml_content);
$phpinstall_code = (string)$xml->phpinstall;

it("peestream.xml contains non-empty phpinstall block", !empty($phpinstall_code));

// Execute <phpinstall>
eval($phpinstall_code);

it("phpinstall created peestream.conf.php on disk", file_exists($conf_path));
$perms = substr(sprintf('%o', fileperms($conf_path)), -4);
it("peestream.conf.php has secure permissions 0644", $perms === '0644');

include($conf_path);
it("peestream_config array is created", isset($peestream_config) && is_array($peestream_config));
$initial_webhook_secret = $peestream_config['webhook_secret'] ?? '';
$initial_cron_secret = $peestream_config['cron_secret'] ?? '';

it("webhook_secret is generated with 48 hex chars", strlen($initial_webhook_secret) === 48 && ctype_xdigit($initial_webhook_secret));
it("cron_secret is generated with 48 hex chars", strlen($initial_cron_secret) === 48 && ctype_xdigit($initial_cron_secret));
it("cron_secret is strictly different from webhook_secret", $initial_cron_secret !== $initial_webhook_secret);
it("Default player_domain is https://peeplayer.com", ($peestream_config['player_domain'] ?? '') === 'https://peeplayer.com');
it("Default api_domain is https://peestream.com", ($peestream_config['api_domain'] ?? '') === 'https://peestream.com');
it("Default auto_create_news is disabled (0)", ($peestream_config['auto_create_news'] ?? '') === '0');

// --- 2. Testing Persistence Across Fresh Process / Reboot ---
echo "\n--- 2. Testing Persistence Across Fresh Process Boot ---\n";
// Run a separate PHP CLI process to load the file fresh
$test_script = $test_dir . '/test_boot.php';
file_put_contents($test_script, "<?php
define('DATALIFEENGINE', true);
define('ENGINE_DIR', '{$test_dir}/engine');
require_once ENGINE_DIR . '/data/peestream.conf.php';
echo json_encode(\$peestream_config);
");

$boot_output = shell_exec("php " . escapeshellarg($test_script));
$boot_config = json_decode($boot_output, true);

it("Fresh process loads peestream.conf.php cleanly", is_array($boot_config));
it("Loaded webhook_secret matches initial secret exactly", ($boot_config['webhook_secret'] ?? '') === $initial_webhook_secret);
it("Loaded cron_secret matches initial secret exactly", ($boot_config['cron_secret'] ?? '') === $initial_cron_secret);
it("Secrets remained 100% persistent on disk without rotation", ($boot_config['webhook_secret'] ?? '') === $initial_webhook_secret && ($boot_config['cron_secret'] ?? '') === $initial_cron_secret);

// --- 3. Testing Admin Settings Save ---
echo "\n--- 3. Testing Admin Settings Modification & Secret Preservation ---\n";
$save_cfg = $boot_config;
$save_cfg['api_key'] = 'pst_live_key_999888';
$save_cfg['player_domain'] = 'https://custom.peeplayer.com';

$conf_content = "<?PHP\n\n// PeeStream DLE Addon Configuration\n// Last updated: " . date("d.m.Y H:i:s") . "\n\n\$peestream_config = array (\n";
foreach ($save_cfg as $k => $v) {
    $conf_content .= "\t'" . addslashes($k) . "' => '" . addslashes((string)$v) . "',\n";
}
$conf_content .= ");\n\n?>";
file_put_contents($conf_path, $conf_content);
chmod($conf_path, 0644);

$boot_output2 = shell_exec("php " . escapeshellarg($test_script));
$boot_config2 = json_decode($boot_output2, true);

it("Admin settings saved successfully", ($boot_config2['api_key'] ?? '') === 'pst_live_key_999888');
it("Custom player domain preserved", ($boot_config2['player_domain'] ?? '') === 'https://custom.peeplayer.com');
it("Webhook secret preserved after settings save", ($boot_config2['webhook_secret'] ?? '') === $initial_webhook_secret);
it("Cron secret preserved after settings save", ($boot_config2['cron_secret'] ?? '') === $initial_cron_secret);

// --- 4. Testing Upgrade (<phpupgrade>) ---
echo "\n--- 4. Testing Upgrade (<phpupgrade>) Secret Preservation ---\n";
$phpupgrade_code = (string)$xml->phpupgrade;
it("peestream.xml contains non-empty phpupgrade block", !empty($phpupgrade_code));

// Execute <phpupgrade>
eval($phpupgrade_code);

$boot_output3 = shell_exec("php " . escapeshellarg($test_script));
$boot_config3 = json_decode($boot_output3, true);

it("Upgrade preserves existing user API key", ($boot_config3['api_key'] ?? '') === 'pst_live_key_999888');
it("Upgrade preserves existing custom player domain", ($boot_config3['player_domain'] ?? '') === 'https://custom.peeplayer.com');
it("Upgrade preserves existing webhook secret", ($boot_config3['webhook_secret'] ?? '') === $initial_webhook_secret);
it("Upgrade preserves existing cron secret", ($boot_config3['cron_secret'] ?? '') === $initial_cron_secret);

// --- 5. Testing Upgrade with Weak / Compromised Secrets ---
echo "\n--- 5. Testing Upgrade Rotation of Weak/Compromised Secrets ---\n";
$compromised_cfg = $boot_config3;
$compromised_cfg['webhook_secret'] = '529c675e950e1f3b3e3d27415b8437d54923b5c21f331241'; // known compromised placeholder
$compromised_cfg['cron_secret'] = 'too_short'; // < 16 chars

$conf_content = "<?PHP\n\n\$peestream_config = " . var_export($compromised_cfg, true) . ";\n\n?>";
file_put_contents($conf_path, $conf_content);

eval($phpupgrade_code);

$boot_output4 = shell_exec("php " . escapeshellarg($test_script));
$boot_config4 = json_decode($boot_output4, true);

it("Upgrade rotates compromised placeholder webhook secret", ($boot_config4['webhook_secret'] ?? '') !== '529c675e950e1f3b3e3d27415b8437d54923b5c21f331241');
it("Rotated webhook secret is valid 48 hex chars", strlen($boot_config4['webhook_secret'] ?? '') === 48);
it("Upgrade rotates short cron secret", ($boot_config4['cron_secret'] ?? '') !== 'too_short');
it("Rotated cron secret is valid 48 hex chars", strlen($boot_config4['cron_secret'] ?? '') === 48);
it("Rotated cron secret is different from rotated webhook secret", ($boot_config4['cron_secret'] ?? '') !== ($boot_config4['webhook_secret'] ?? ''));

// Cleanup
exec("rm -rf " . escapeshellarg($test_dir));

echo "\n=========================================================\n";
echo "STAGING LIFECYCLE RESULTS: Total: " . ($passed + $failed) . " | Passed: {$passed} | Failed: {$failed}\n";
echo "=========================================================\n";

if ($failed > 0) exit(1);

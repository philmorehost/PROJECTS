<?php
require_once __DIR__ . '/../includes/debug-log.php';

$n = 0;
function wlh_ok($ok, $msg) { global $n; if (!$ok) throw new RuntimeException($msg); $n++; }

$payhubRoot = dirname(__DIR__);

// 1. Unauthenticated log dumper & webroot plaintext PII logs must be absent
foreach (['dump_webhook_logs.php', 'webhook_debug.log', 'payout_debug.log', 'va_debug.log'] as $badFile) {
    wlh_ok(!file_exists($payhubRoot . '/' . $badFile), "removed exposed webroot file PAYHUB/$badFile");
}

// 2. .htaccess guards exist
wlh_ok(is_file($payhubRoot . '/.htaccess') && strpos(file_get_contents($payhubRoot . '/.htaccess'), 'Require all denied') !== false, 'PAYHUB/.htaccess denies log/sql/internal dirs');
wlh_ok(is_file($payhubRoot . '/logs/.htaccess') && strpos(file_get_contents($payhubRoot . '/logs/.htaccess'), 'Require all denied') !== false, 'PAYHUB/logs/.htaccess denies direct access');

// 3. No PHP source writes to webroot debug logs
foreach (['webhook-paystack.php', 'verify.php', 'includes/functions.php', 'admin/settlements.php'] as $rel) {
    $src = (string)file_get_contents($payhubRoot . '/' . $rel);
    wlh_ok(!preg_match('/file_put_contents\s*\([^)]*(webhook_debug|payout_debug|va_debug)\.log/', $src), "no webroot debug log write in $rel");
}

// 4. payhub_debug_log disabled by default and writes only inside logs/ when enabled
putenv('PAYHUB_DEBUG_LOG_ENABLED');
wlh_ok(payhub_debug_log_enabled() === false, 'debug log disabled by default');
wlh_ok(payhub_debug_log('webhook_debug', 'should not write') === false, 'no write when disabled');

$tmpDir = sys_get_temp_dir() . '/ph_log_test_' . bin2hex(random_bytes(4));
mkdir($tmpDir, 0750, true);
putenv('PAYHUB_DEBUG_LOG_ENABLED=1');
wlh_ok(payhub_debug_log('../evil_channel', "line1\nline2", $tmpDir) === true, 'writes inside logs subdir when enabled');
$files = glob($tmpDir . '/logs/*.log');
wlh_ok(is_array($files) && count($files) === 1 && basename($files[0]) === 'evil_channel.log', 'sanitizes channel name against traversal');
$written = (string)file_get_contents($files[0]);
wlh_ok(strpos($written, "line1 line2") !== false && substr_count($written, "\n") === 1, 'normalizes single log line');
putenv('PAYHUB_DEBUG_LOG_ENABLED');
@unlink($files[0]);
@rmdir($tmpDir . '/logs');
@rmdir($tmpDir);

echo "PASS $n PayHub webroot log hardening checks\n";

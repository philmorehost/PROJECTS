<?php
/**
 * Performance Diagnostic Probe — DGV7.0
 * Run from CLI:  php cron/perf_report.php
 *
 * Reports PHP / OPcache / MySQL state and times the bootstrap phase (the code that
 * runs on EVERY page request) so you can see exactly what adds latency on a
 * slow-loading page — and whether OPcache is the missing piece on your VPS.
 */
define('CRON_CLI', true);

$marks = [];
function perf_mark(string $label): void { global $marks; $marks[] = [$label, microtime(true)]; }
perf_mark('start');

require_once __DIR__ . '/../func/bc-connect.php';
perf_mark('bc-connect.php (db + PHPMailer + bc-func + integrity)');

$bootstrap_ms = (microtime(true) - $marks[0][1]) * 1000;
$peak_mb      = round(memory_get_peak_usage(true) / 1024 / 1024, 1);
$opcache_on   = extension_loaded('Zend OPcache') && (int)ini_get('opcache.enable') === 1;

echo "=====================================================\n";
echo "  DGV Performance Probe\n";
echo "=====================================================\n";
echo "PHP version     : " . PHP_VERSION . "\n";
echo "PHP SAPI        : " . PHP_SAPI . "\n";
echo "OPcache         : " . ($opcache_on ? "ON  (memory=" . ini_get('opcache.memory_consumption') . "M, files=" . ini_get('opcache.max_accelerated_files') . ")" : "OFF / NOT INSTALLED  ⚠️") . "\n";
echo "MySQL server    : " . (function_exists('mysqli_get_server_info') && !empty($GLOBALS['connection_server']) ? mysqli_get_server_info($GLOBALS['connection_server']) : 'n/a') . "\n";
echo "Peak memory     : {$peak_mb} MB\n";
echo "Bootstrap time  : " . round($bootstrap_ms, 1) . " ms\n";
echo "-----------------------------------------------------\n";
foreach ($marks as $i => $m) {
    if ($i === 0) continue;
    printf("  %-46s +%8.1f ms\n", $m[0], ($m[1] - $marks[$i - 1][1]) * 1000);
}
echo "=====================================================\n";

// Integrity cache state (the thing that can stall a request when stale).
$ic = __DIR__ . '/../func/cache/bc-core.cache';
echo "License cache   : " . (is_file($ic) ? "present (" . round((time() - filemtime($ic)) / 3600, 1) . "h old)" : "MISSING (first check will hit the license API)") . "\n";

echo "\nTuning checklist:\n";
if (!$opcache_on) {
    echo "  [1] OPcache is OFF — the #1 cause of slow PHP on a VPS. In php.ini set:\n";
    echo "        opcache.enable=1\n        opcache.memory_consumption=256\n        opcache.max_accelerated_files=20000\n        opcache.revalidate_freq=60\n        opcache.jit=1255   (PHP 8 only)\n";
} else {
    echo "  [1] OPcache is ON — good. If load is still slow, check MySQL next.\n";
}
echo "  [2] MySQL: ensure innodb_buffer_pool_size >= 256M (VPS) and log slow queries:\n";
echo "        slow_query_log=1, long_query_time=1\n";
echo "  [3] Confirm func/cache/bc-core.cache exists (license) so the API isn't hit per request.\n";
echo "  [4] For a quick browser check, load the site and look at the Network tab TTFB.\n";
echo "\nDone.\n";

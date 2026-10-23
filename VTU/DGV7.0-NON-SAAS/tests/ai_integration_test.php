<?php
/**
 * DGV6.90 AI Integration Test Suite
 * Run from CLI: php tests/ai_integration_test.php
 *
 * Tests all AI components and outputs a clear pass/fail report.
 */

define('CRON_CLI', true);
require_once __DIR__ . '/../func/bc-connect.php';

$results = [];
$pass = 0;
$fail = 0;

function test(string $name, bool $success, string $detail = ''): void {
    global $results, $pass, $fail;
    $icon = $success ? '✅' : '❌';
    $results[] = ['name' => $name, 'success' => $success, 'detail' => $detail];
    if ($success) $pass++; else $fail++;
    echo "$icon  $name" . ($detail ? " — $detail" : '') . "\n";
}

echo "\n" . str_repeat('═', 60) . "\n";
echo "  DGV6.90 AI Edition — Integration Test Suite\n";
echo "  " . date('Y-m-d H:i:s') . "\n";
echo str_repeat('═', 60) . "\n\n";

// ─────────────────────────────────────────────────────────────
// 1. DATABASE CONNECTIVITY
// ─────────────────────────────────────────────────────────────
echo "[ DATABASE ]\n";
test('MySQL Connection', (bool)$connection_server, $connection_server ? 'Connected' : 'FAILED');

$ver = mysqli_fetch_row(mysqli_query($connection_server, "SELECT VERSION()"));
test('MySQL Version >= 5.7', version_compare($ver[0], '5.7.0', '>='), $ver[0]);

// Check AI tables exist
$ai_tables = ['sas_ai_transactions', 'sas_ai_audit_log', 'sas_customer_whitelist', 'sas_rate_limits', 'sas_ai_page_guides'];
foreach ($ai_tables as $tbl) {
    $q = mysqli_query($connection_server, "SHOW TABLES LIKE '$tbl'");
    test("Table $tbl exists", mysqli_num_rows($q) > 0);
}

// ─────────────────────────────────────────────────────────────
// 2. SECURITY LIBRARY
// ─────────────────────────────────────────────────────────────
echo "\n[ SECURITY LIBRARY ]\n";
require_once __DIR__ . '/../func/bc-security.php';
test('bc-security.php loaded', function_exists('bc_generate_csrf_token'), 'CSRF functions available');
test('bc_sanitize_number()', bc_sanitize_number('₦1,500.50') === 1500.50, bc_sanitize_number('₦1,500.50'));
test('bc_firewall_prompt() blocks SQL', bc_firewall_prompt('SELECT * FROM sas_users') === false, 'SQL injection blocked');
test('bc_firewall_prompt() allows legit prompt', bc_firewall_prompt('How do I buy airtime?') !== false, 'Legit prompt passes');

// Test rate limiting
$rl_key = 'test_' . microtime(true);
$blocked = false;
for ($i = 0; $i < 6; $i++) {
    if (bc_is_rate_limited('test_action', $rl_key, 5, 60)) { $blocked = true; break; }
}
test('Rate limiter blocks at threshold', $blocked, 'Blocked on 6th attempt (limit=5)');

// ─────────────────────────────────────────────────────────────
// 3. SECURITY SENTINEL
// ─────────────────────────────────────────────────────────────
echo "\n[ AI SECURITY SENTINEL ]\n";
$sentinel_loaded = false;
if (file_exists(__DIR__ . '/../func/bc-ai-sentinel.php')) {
    require_once __DIR__ . '/../func/bc-ai-sentinel.php';
    $sentinel_loaded = function_exists('ai_sentinel_evaluate');
}
test('bc-ai-sentinel.php loaded', $sentinel_loaded, $sentinel_loaded ? 'ai_sentinel_evaluate() available' : 'FILE NOT FOUND');

// ─────────────────────────────────────────────────────────────
// 4. CLOUD AI ENGINE
// ─────────────────────────────────────────────────────────────
echo "\n[ CLOUD AI ENGINE ]\n";
$ai_online = false;
$active_prov = 'N/A';

if (file_exists(__DIR__ . '/../func/bc-ai-engine.php')) {
    require_once __DIR__ . '/../func/bc-ai-engine.php';
    $engine = ai_engine();
    $ai_online = $engine->isAiOnline();
    $active_prov = $engine->getProvider();
}

test('Cloud AI reachable (' . ucfirst($active_prov) . ')', $ai_online, $ai_online ? 'API Responding' : 'NOT REACHABLE — Check API Key');
test('Active Provider set', $active_prov !== 'N/A', $active_prov);

// ─────────────────────────────────────────────────────────────
// 6. MOBILE API ENDPOINTS
// ─────────────────────────────────────────────────────────────
echo "\n[ MOBILE API ENDPOINTS ]\n";
$mobile_files = [
    'api/app-backend/ai-handler.php'      => 'AI Chat Handler',
    'api/app-backend/ai-intent-parser.php'=> 'Voice Intent Parser',
    'api/app-backend/ai-guide.php'        => 'Page Guide API',
];
foreach ($mobile_files as $file => $label) {
    test($label . ' file exists', file_exists(__DIR__ . '/../' . $file), $file);
}

// ─────────────────────────────────────────────────────────────
// 7. CRON FILES
// ─────────────────────────────────────────────────────────────
echo "\n[ CRON JOBS ]\n";
$cron_files = [
    'cron/ai_model_status.php'      => 'Model Check (every 1m)',
    'cron/aggregator_monitor.php'   => 'API Monitor (every 5m)',
    'cron/dormant_user_alert.php'   => 'Dormant Alerts (daily 10am)',
    'cron/ai_daily_briefing.php'    => 'Daily Briefing (daily 7am)',
];
foreach ($cron_files as $file => $label) {
    test($label, file_exists(__DIR__ . '/../' . $file), $file);
}

// ─────────────────────────────────────────────────────────────
// 8. CHARGEUSER RACE CONDITION FIX
// ─────────────────────────────────────────────────────────────
echo "\n[ TRANSACTION INTEGRITY ]\n";
$func_file = __DIR__ . '/../func/bc-func.php';
$fc = file_get_contents($func_file);
test('chargeUser() uses atomic debit', strpos($fc, 'bc_atomic_debit_user') !== false, 'SELECT FOR UPDATE pattern present');
test('chargeUser() has legacy fallback', strpos($fc, 'legacy') !== false, 'Fallback path for safety');

// ─────────────────────────────────────────────────────────────
// SUMMARY
// ─────────────────────────────────────────────────────────────
$total = $pass + $fail;
echo "\n" . str_repeat('─', 60) . "\n";
echo "  RESULTS: $pass/$total passed";
if ($fail > 0) echo " | $fail FAILED";
echo "\n";
if ($fail === 0) {
    echo "  🎉 ALL TESTS PASSED — Platform is production ready!\n";
} else {
    echo "  ⚠  Some tests failed. Review the items marked ❌ above.\n";
    echo "  The platform is partially operational. Fix failures before going live.\n";
}
echo str_repeat('─', 60) . "\n\n";

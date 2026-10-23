<?php
/**
 * Contract test for the app referral / coins API surface.
 *
 * Run with:  C:\xampp\php\php.exe -n tests\app_referral_api_test.php
 *
 *   php -n matters: without an ini there is no mysqli extension loaded, which is what lets these
 *   fakes define mysqli_query()/mysqli_prepare()/... at all.
 *
 * Loads the SHIPPED endpoints (web/api/referral.php, web/api/points-history.php) and the REAL
 * bc_resolve_referral_id() brace-extracted from func/bc-func.php, with mysqli faked and routed by
 * SQL text. The point is that these tests exercise the shipped files, not a reimplementation.
 */

$api_dir  = __DIR__ . '/../web/api';
$func_file = __DIR__ . '/../func/bc-func.php';

// ── Extract the real bc_resolve_referral_id() (brace-matched, not regex-guessed) ──
$bcFunc = file_get_contents($func_file);
$start = strpos($bcFunc, 'function bc_resolve_referral_id');
if ($start === false) { fwrite(STDERR, "bc_resolve_referral_id not found\n"); exit(1); }
$brace = strpos($bcFunc, '{', $start);
$depth = 0; $end = $brace;
for (; $end < strlen($bcFunc); $end++) {
    if ($bcFunc[$end] === '{') { $depth++; }
    elseif ($bcFunc[$end] === '}') { $depth--; if ($depth === 0) { break; } }
}
$real_resolver = substr($bcFunc, $start, $end - $start + 1);

$fails = 0; $checks = 0;
function bc_assert($label, $actual, $expected) {
    global $fails, $checks;
    $checks++;
    if ($actual !== $expected) {
        $fails++;
        echo "FAIL  $label\n        expected " . var_export($expected, true) . "\n        got      " . var_export($actual, true) . "\n";
    } else {
        echo "ok    $label\n";
    }
}

/** Strip the DB bootstrap and the CLI-hostile bits so the shipped endpoint can be included. */
function endpoint_source($file) {
    $src = file_get_contents($file);
    $src = preg_replace('#include_once\("\.\./\.\./func/bc-connect\.php"\);#', '', $src, 1);
    $src = str_replace('header("Content-Type: application/json");', '', $src);
    $src = str_replace('session_start();', '', $src);
    $src = str_replace('exit;', 'return;', $src);
    $src = preg_replace('#^<\?php#', '', $src, 1);
    return $src;
}

$stub = <<<'STUB'
<?php
// ── fakes: routed by SQL substring, most specific first ──
// Guarded because the endpoint file is included once per case in the same process.
if (!function_exists('__route_rows')) {
    function __route_rows() {
        $sql = $GLOBALS['__cur_sql'] ?? '';
        foreach ($GLOBALS['__routes'] as $needle => $rows) {
            if (stripos($sql, $needle) !== false) { return $rows; }
        }
        return [];
    }
    function __load_cursor() { $GLOBALS['__cursor'] = __route_rows(); $GLOBALS['__cursor_i'] = 0; }

    function mysqli_query($c, $sql) { $GLOBALS['__cur_sql'] = $sql; $GLOBALS['__sql_seen'][] = $sql; __load_cursor(); return 'QR'; }
    function mysqli_prepare($c, $sql) { $GLOBALS['__cur_sql'] = $sql; $GLOBALS['__sql_seen'][] = $sql; __load_cursor(); return 'ST'; }
    function mysqli_stmt_bind_param($st, $types, ...$args) { $GLOBALS['__params'] = $args; return true; }
    function mysqli_stmt_execute($st) { return true; }
    function mysqli_stmt_get_result($st) { return 'QR'; }
    function mysqli_num_rows($r) { return count($GLOBALS['__cursor'] ?? []); }
    function mysqli_fetch_assoc($r) {
        $rows = $GLOBALS['__cursor'] ?? []; $i = $GLOBALS['__cursor_i'] ?? 0;
        if ($i >= count($rows)) return null;
        $GLOBALS['__cursor_i'] = $i + 1; return $rows[$i];
    }
    function mysqli_fetch_array($r) { return mysqli_fetch_assoc($r); }
    function mysqli_real_escape_string($c, $s) { return addslashes((string) $s); }
    function mysqli_close($c) { return true; }
    function mysqli_error($c) { return ''; }
}

if (!function_exists('resolveVendorID')) { function resolveVendorID($f = false) { return 1; } }
if (!function_exists('bc_safe_host'))    { function bc_safe_host() { return 'shop.example.com'; } }
if (!function_exists('isServiceEnabled')){ function isServiceEnabled($n, $v = null) { return true; } }
if (!function_exists('get_user_vtu_details')) {
    function get_user_vtu_details($u) {
        return ['total_points' => 1250, 'streak_day' => 3, 'is_eligible' => false, 'next_bonus_time' => '08:00 AM on Monday'];
    }
}
STUB;

/**
 * Run a shipped endpoint with a given route table and input.
 * $routes: [ sqlSubstring => [row,...] ]
 */
function run_endpoint($src, $stub, $real_resolver, $routes, $input, $host) {
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dgv7_ref_test';
    @mkdir($tmp . DIRECTORY_SEPARATOR . 'api', 0777, true);
    $file = $tmp . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'ep.php';

    $head = $stub . "\n"
        . "if (!function_exists('bc_resolve_referral_id')) {\n" . $real_resolver . "\n}\n"
        . "\$GLOBALS['__routes'] = " . var_export($routes, true) . ";\n"
        . "\$connection_server = true;\n"
        . "\$web_http_host = 'https://shop.example.com';\n"
        . "\$get_all_site_details = ['site_title' => 'JIKABIZ'];\n"
        . "\$api_post_info_from_app = " . var_export($input, true) . ";\n"
        . "\$_SERVER['HTTP_HOST'] = " . var_export($host, true) . ";\n";

    file_put_contents($file, $head . $src);
    ob_start();
    include $file;
    $out = ob_get_clean();
    @unlink($file);
    return json_decode($out, true);
}

$user_row = ['id' => 42, 'username' => 'johndoe', 'status' => 1, 'vendor_id' => 1];

$base_routes = [
    'SUM(CASE WHEN referral_bonus_awarded' => [['total' => 3, 'qualified' => 2]],
    "log_type = 'REFERRAL_BONUS'"          => [['coins' => 200]],
    'ORDER BY id DESC LIMIT 50'            => [
        ['username' => 'alice', 'firstname' => 'Alice', 'lastname' => 'A', 'referral_bonus_awarded' => 1, 'reg_date' => '2026-01-02 10:00:00'],
        ['username' => 'bob',   'firstname' => 'Bob',   'lastname' => 'B', 'referral_bonus_awarded' => 0, 'reg_date' => '2026-02-03 11:00:00'],
    ],
    'first_purchase_bonus FROM sas_loyalty_bonus_settings' => [['first_purchase_bonus' => 100]],
    'FROM sas_vendors'                     => [['id' => 1]],
    "api_key="                             => [$user_row],
];

// ── referral.php ─────────────────────────────────────────────────────────────
$ref_src = endpoint_source($api_dir . '/referral.php');

$r = run_endpoint($ref_src, $stub, $real_resolver, $base_routes, ['api_key' => 'K'], 'shop.example.com');
$d = $r['data'] ?? [];
bc_assert('referral: status success',                 $r['status'] ?? null, 'success');
bc_assert('referral: code IS the username (web parity)', $d['referral_code'] ?? null, 'johndoe');
bc_assert('referral: link matches web/Dashboard.php format', $d['referral_link'] ?? null,
          'https://shop.example.com/web/Register.php?referral=johndoe');
bc_assert('referral: total referrals',                $d['total_referrals'] ?? null, 3);
bc_assert('referral: qualified referrals',            $d['qualified_referrals'] ?? null, 2);
bc_assert('referral: pending is total minus qualified', $d['pending_referrals'] ?? null, 1);
bc_assert('referral: coins earned from referrals',    $d['coins_from_referrals'] ?? null, 200);
bc_assert('referral: bonus amount from loyalty settings', $d['referral_bonus'] ?? null, 100);
bc_assert('referral: coin balance from vtu details',  $d['coins_balance'] ?? null, 1250);
bc_assert('referral: coins_enabled reported',         $d['coins_enabled'] ?? null, true);
bc_assert('referral: referred user list returned',    count($d['referred_users'] ?? []), 2);
bc_assert('referral: qualified flag per user',        $d['referred_users'][0]['qualified'] ?? null, 'Yes');
bc_assert('referral: unqualified user flagged',       $d['referred_users'][1]['qualified'] ?? null, 'No');

// ── auth failures ────────────────────────────────────────────────────────────
$r = run_endpoint($ref_src, $stub, $real_resolver, $base_routes, [], 'shop.example.com');
bc_assert('referral: missing api_key rejected', $r['status'] ?? null, 'error');
bc_assert('referral: missing api_key message',  $r['message'] ?? null, 'API Key is required');

$no_user = $base_routes; $no_user["api_key="] = [];
$r = run_endpoint($ref_src, $stub, $real_resolver, $no_user, ['api_key' => 'BAD'], 'shop.example.com');
bc_assert('referral: invalid api_key rejected', $r['status'] ?? null, 'error');
bc_assert('referral: invalid api_key message',  $r['message'] ?? null, 'Invalid API Key');

$inactive = $base_routes; $inactive["api_key="] = [['id' => 42, 'username' => 'johndoe', 'status' => 0, 'vendor_id' => 1]];
$r = run_endpoint($ref_src, $stub, $real_resolver, $inactive, ['api_key' => 'K'], 'shop.example.com');
bc_assert('referral: inactive account rejected', $r['message'] ?? null, 'Account is not active');

// ── points-history.php ───────────────────────────────────────────────────────
$hist_routes = $base_routes;
$hist_routes['sas_points_log'] = [
    ['id' => 9, 'point_amount' => 100, 'log_type' => 'REFERRAL_BONUS', 'date' => '2026-02-01 09:00:00'],
    ['id' => 7, 'point_amount' => 20,  'log_type' => 'DAILY_PURCHASE_BONUS', 'date' => '2026-01-30 09:00:00'],
    ['id' => 3, 'point_amount' => -100, 'log_type' => 'CONVERSION', 'date' => '2026-01-20 09:00:00'],
];
$hist_src = endpoint_source($api_dir . '/points-history.php');
$r = run_endpoint($hist_src, $stub, $real_resolver, $hist_routes, ['api_key' => 'K'], 'shop.example.com');
$hd = $r['data'] ?? [];
bc_assert('history: status success',            $r['status'] ?? null, 'success');
bc_assert('history: entries returned',          count($hd['entries'] ?? []), 3);
bc_assert('history: label is prettified',       $hd['entries'][0]['label'] ?? null, 'Referral Bonus');
bc_assert('history: credit direction',          $hd['entries'][0]['direction'] ?? null, 'in');
bc_assert('history: debit direction',           $hd['entries'][2]['direction'] ?? null, 'out');
bc_assert('history: balance included',          $hd['points_balance'] ?? null, 1250);
bc_assert('history: streak included',           $hd['streak_day'] ?? null, 3);
// The daily-bonus collapse and the vendor scoping are the two things a naive rewrite would lose.
bc_assert('history: query collapses daily bonuses via MAX(id)', 
          (bool) preg_match('/MAX\(id\)\s+as\s+max_id/i', implode("\n", $GLOBALS['__sql_seen'])), true);
bc_assert('history: query is scoped to the tenant',
          (bool) preg_match('/vendor_id\s*=\s*\?/', implode("\n", $GLOBALS['__sql_seen'])), true);

$r = run_endpoint($hist_src, $stub, $real_resolver, $hist_routes, [], 'shop.example.com');
bc_assert('history: missing api_key rejected', $r['status'] ?? null, 'error');

// ── bc_resolve_referral_id() ─────────────────────────────────────────────────
// Load the real resolver once so it can be called directly below.
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dgv7_ref_test';
@mkdir($tmp, 0777, true);
$helper_file = $tmp . DIRECTORY_SEPARATOR . 'helper.php';
file_put_contents($helper_file, $stub . "\nif (!function_exists('bc_resolve_referral_id')) {\n" . $real_resolver . "\n}\n");
include $helper_file;

$GLOBALS['__routes'] = ['FROM sas_users' => [['id' => 77]]];
$GLOBALS['__cur_sql'] = ''; __load_cursor();

bc_assert('resolver: plain username resolves',        bc_resolve_referral_id(true, 1, 'johndoe'), '77');
bc_assert('resolver: base64 legacy code resolves',    bc_resolve_referral_id(true, 1, base64_encode('johndoe')), '77');
bc_assert('resolver: mixed case is normalised',       bc_resolve_referral_id(true, 1, 'JohnDoe'), '77');
bc_assert('resolver: whitespace trimmed',             bc_resolve_referral_id(true, 1, '  johndoe  '), '77');
bc_assert('resolver: empty code returns empty',       bc_resolve_referral_id(true, 1, ''), '');
bc_assert('resolver: null code returns empty',        bc_resolve_referral_id(true, 1, null), '');
bc_assert('resolver: no db returns empty',            bc_resolve_referral_id(false, 1, 'johndoe'), '');

$GLOBALS['__routes'] = ['FROM sas_users' => []];
bc_assert('resolver: unknown code returns empty',     bc_resolve_referral_id(true, 1, 'nobody'), '');
// "nobody" is not valid base64, so it must not be mangled into a bogus username.
bc_assert('resolver: non-base64 code not decoded',    bc_resolve_referral_id(true, 1, 'not valid base64!!'), '');

@unlink($helper_file);
@rmdir($tmp . DIRECTORY_SEPARATOR . 'api');
@rmdir($tmp);

echo "\n$checks checks, $fails failures\n";
exit($fails === 0 ? 0 : 1);

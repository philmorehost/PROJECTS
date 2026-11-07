<?php
/**
 * Regression test: the referral endpoint must never die with an HTTP 500.
 *
 * Run with:  C:\xampp\php\php.exe -n tests\app_referral_resilience_test.php
 *
 *   php -n matters: without an ini the mysqli extension is absent, which is what lets this file
 *   declare the userland mysqli_stmt / mysqli_result classes below.
 *
 * The bug this locks down
 * -----------------------
 * /web/api/* only loads func/bc-connect.php, which does NOT include func/bc-tables.php. The
 * sas_users column migration therefore never ran for app traffic, so an install upgraded from a
 * build that predates "Refer and Earn" had no referral_id / referral_bonus_awarded column (and
 * CREATE TABLE IF NOT EXISTS in bc-tables.php cannot add them to an existing table). Every referral
 * query then failed, and because the endpoint used
 *
 *     mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))
 *
 * PHP 8 raised "Argument #1 ($result) must be of type mysqli_result, bool given" as an uncaught
 * TypeError. That is a fatal, so the app only ever saw HTTP 500 and showed
 * "The server returned an error (HTTP 500). Please try again shortly."
 *
 * The endpoint now (a) self-heals the two columns, (b) routes every fetch through a helper that
 * returns null on failure, and (c) wraps the auxiliary coin helpers in try/catch. This test proves
 * the same broken schema now yields HTTP 200 JSON instead of a fatal.
 */

$endpoint = __DIR__ . '/../web/api/referral.php';
if (!is_file($endpoint)) { fwrite(STDERR, "endpoint not found: $endpoint\n"); exit(1); }

$fails = 0; $checks = 0;
function bc_assert($label, $condition, $detail = '') {
    global $fails, $checks;
    $checks++;
    if ($condition) {
        echo "ok    $label\n";
    } else {
        $fails++;
        echo "FAIL  $label" . ($detail !== '' ? "\n        $detail" : '') . "\n";
    }
}

// ── The endpoint is included per scenario, so build each run in its own child process. ──────────
$runner = <<<'RUNNER'
<?php
// Real mysqli signatures, so handing `false` raises the same TypeError PHP's extension does.
class mysqli_stmt {}
class mysqli_result {}

$GLOBALS['__cursor'] = []; $GLOBALS['__i'] = 0; $GLOBALS['__sql'] = [];

$SCHEMA = $argv[2];      // "broken" | "healthy"

function __rows_for($sql) {
    if (stripos($sql, 'FROM sas_vendors') !== false) return [['id' => 1]];
    if (stripos($sql, 'api_key=') !== false)
        return [['id' => 42, 'username' => 'johndoe', 'status' => 1, 'vendor_id' => 1, 'api_key' => 'k']];
    if (stripos($sql, 'SHOW COLUMNS FROM sas_users') !== false) {
        $cols = [['Field'=>'id'],['Field'=>'username'],['Field'=>'status'],['Field'=>'vendor_id'],['Field'=>'api_key']];
        if ($GLOBALS['SCHEMA'] === 'healthy') {
            $cols[] = ['Field' => 'referral_id'];
            $cols[] = ['Field' => 'referral_bonus_awarded'];
            $cols[] = ['Field' => 'reg_date'];
        }
        return $cols;
    }
    if (stripos($sql, 'referral_bonus_awarded') !== false || stripos($sql, 'referral_id') !== false) {
        if ($GLOBALS['SCHEMA'] === 'healthy') {
            return [ stripos($sql, 'COUNT(*)') !== false
                ? ['total' => 3, 'qualified' => 2]
                : ['username'=>'bob','firstname'=>'Bob','lastname'=>'B','referral_bonus_awarded'=>1,'reg_date'=>'2026-01-01 10:00:00'] ];
        }
        return [];
    }
    if (stripos($sql, 'sas_points_log') !== false) return [['coins' => 250, 'total_points' => 900]];
    if (stripos($sql, 'sas_loyalty_bonus_settings') !== false) return [['first_purchase_bonus' => 150]];
    return [];
}
function __load($sql) { $GLOBALS['__cursor'] = __rows_for($sql); $GLOBALS['__i'] = 0; }

function mysqli_query($c, $sql) { $GLOBALS['__sql'][] = $sql; __load($sql); return new mysqli_result(); }
function mysqli_prepare($c, $sql) {
    $GLOBALS['__sql'][] = $sql;
    if ($GLOBALS['SCHEMA'] !== 'healthy') {
        // Simulates the pre-migration install: these queries cannot run at all.
        if (stripos($sql,'referral_bonus_awarded') !== false || stripos($sql,'referral_id') !== false
            || stripos($sql,'sas_points_log') !== false || stripos($sql,'sas_loyalty_bonus_settings') !== false) {
            return false;
        }
    }
    __load($sql); return new mysqli_stmt();
}
function mysqli_stmt_bind_param(mysqli_stmt $st, $types, ...$a) { return true; }
function mysqli_stmt_execute(mysqli_stmt $st) { return true; }
function mysqli_stmt_get_result(mysqli_stmt $st) { return new mysqli_result(); }
function mysqli_num_rows(mysqli_result $r) { return count($GLOBALS['__cursor']); }
function mysqli_fetch_assoc(mysqli_result $r) {
    $rows = $GLOBALS['__cursor']; $i = $GLOBALS['__i'];
    if ($i >= count($rows)) return null;
    $GLOBALS['__i'] = $i + 1; return $rows[$i];
}
function mysqli_fetch_array(mysqli_result $r) { return mysqli_fetch_assoc($r); }
function mysqli_real_escape_string($c, $s) { return addslashes((string) $s); }
function mysqli_close($c) { return true; }
function mysqli_error($c) { return 'Unknown column'; }

function resolveVendorID($f = false) { return 1; }
function bc_safe_host() { return 'shop.example.com'; }
function isServiceEnabled($n, $v = null) { return true; }
function get_user_vtu_details($u) { return ['total_points' => 900]; }

$GLOBALS['SCHEMA'] = $argv[2];
$connection_server = true;
$web_http_host = 'https://shop.example.com';
$get_all_site_details = ['site_title' => 'TEST'];
$api_post_info_from_app = ['api_key' => 'k'];

$src = file_get_contents($argv[1]);
$src = str_replace('include_once("../../func/bc-connect.php");', '', $src);
$src = str_replace('header("Content-Type: application/json");', '', $src);
$src = str_replace('session_start();', '', $src);
$src = str_replace('exit;', 'return;', $src);
$tmp = sys_get_temp_dir() . '/bc_ref_res_' . getmypid() . '.php';
file_put_contents($tmp, $src);

ob_start();
try {
    include $tmp;
    $out = ob_get_clean();
} catch (Throwable $e) {
    ob_end_clean();
    @unlink($tmp);
    echo json_encode(['__fatal' => get_class($e) . ': ' . $e->getMessage()]);
    exit(0);
}
@unlink($tmp);

$json = json_decode($out, true);
if ($json === null) {
    echo json_encode(['__nonjson' => substr(trim($out), 0, 200)]);
    exit(0);
}
$json['__alters'] = array_values(array_filter($GLOBALS['__sql'], fn($q) => stripos($q, 'ALTER TABLE sas_users') !== false));
echo json_encode($json);
RUNNER;

$tmpRunner = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bc_ref_runner.php';
file_put_contents($tmpRunner, $runner);

function run_scenario($runner, $endpoint, $schema) {
    $cmd = escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg($runner) . ' '
         . escapeshellarg($endpoint) . ' ' . escapeshellarg($schema) . ' 2>&1';
    $raw = shell_exec($cmd);
    return json_decode((string) $raw, true);
}

// ── Scenario 1: the reported failure — referral columns/aux tables missing ─────────────────────
$broken = run_scenario($tmpRunner, $endpoint, 'broken');
bc_assert('broken schema: no PHP fatal', !isset($broken['__fatal']),
    isset($broken['__fatal']) ? $broken['__fatal'] . ' (this fatal is what returned HTTP 500)' : '');
bc_assert('broken schema: JSON body returned', !isset($broken['__nonjson']),
    $broken['__nonjson'] ?? '');
bc_assert('broken schema: status=success', ($broken['status'] ?? null) === 'success',
    'got ' . var_export($broken['status'] ?? null, true));
bc_assert('broken schema: counts degrade to 0 instead of dying',
    ($broken['data']['total_referrals'] ?? null) === 0
    && ($broken['data']['qualified_referrals'] ?? null) === 0);
bc_assert('broken schema: bonus falls back to the 100 default',
    ($broken['data']['referral_bonus'] ?? null) === 100);
bc_assert('broken schema: referred list is empty, not missing',
    ($broken['data']['referred_users'] ?? null) === []);
bc_assert('broken schema: self-heal issued both ALTER TABLE statements',
    count($broken['__alters'] ?? []) === 2,
    'issued: ' . json_encode($broken['__alters'] ?? []));

// ── Scenario 2: a correctly migrated install still returns real data ───────────────────────────
$healthy = run_scenario($tmpRunner, $endpoint, 'healthy');
bc_assert('healthy schema: no PHP fatal', !isset($healthy['__fatal']), $healthy['__fatal'] ?? '');
bc_assert('healthy schema: status=success', ($healthy['status'] ?? null) === 'success');
bc_assert('healthy schema: total referrals reported',
    ($healthy['data']['total_referrals'] ?? null) === 3,
    'got ' . var_export($healthy['data']['total_referrals'] ?? null, true));
bc_assert('healthy schema: qualified referrals reported',
    ($healthy['data']['qualified_referrals'] ?? null) === 2);
bc_assert('healthy schema: pending = total - qualified',
    ($healthy['data']['pending_referrals'] ?? null) === 1);
bc_assert('healthy schema: coins from referrals from the ledger',
    ($healthy['data']['coins_from_referrals'] ?? null) === 250);
bc_assert('healthy schema: bonus from loyalty settings',
    ($healthy['data']['referral_bonus'] ?? null) === 150);
bc_assert('healthy schema: no ALTER needed once columns exist',
    count($healthy['__alters'] ?? []) === 0,
    'issued: ' . json_encode($healthy['__alters'] ?? []));

@unlink($tmpRunner);

echo "\n$checks checks, $fails failures\n";
exit($fails === 0 ? 0 : 1);

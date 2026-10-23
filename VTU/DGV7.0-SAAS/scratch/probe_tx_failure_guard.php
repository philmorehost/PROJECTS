<?php
/**
 * GATE: the failed-transaction abuse guard (GFTAL) must catch abuse WITHOUT punishing
 * legitimate customers. Both halves matter, so both are asserted.
 *
 * Runs the REAL bc_tx_failure_* helpers from func/bc-func.php against a stateful mysqli stub that
 * emulates one row of sas_users (the tx_* columns) plus the sas_bruteforce_settings row.
 *
 * MUST-catch (otherwise the feature is pointless):
 *   - repeated user-caused failures lock the account, and the lock then blocks a debit
 * MUST-NOT-catch (otherwise it locks out real customers):
 *   - a provider-side failure is never counted          <- the big one on a VTU platform
 *   - one success resets the streak
 *   - a bulk/batch source is never counted
 *   - an expired lock auto-releases
 *   - disabling the guard disables everything
 *   - a window that has passed starts a fresh count
 *
 * NEGATIVE CONTROL: on the pre-fix revision the helpers do not exist at all, so nothing enforces a
 * lock. The gate asserts that, which is what makes a green run on the current tree meaningful.
 *
 * Usage: php -n scratch/probe_tx_failure_guard.php [--expect-absent]
 */

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', '1');

$argvRest = array_slice($argv, 1);
$expectAbsent = in_array('--expect-absent', $argvRest, true);
$optsFunc = null;
foreach ($argvRest as $a) { if (strpos($a, '--func=') === 0) $optsFunc = substr($a, 7); }

// ── Parent mode: run the live tree, then the SAME assertions against the PINNED pre-fix revision.
// A control that reads the working tree is not a control - it would just re-run the fixed code and
// report "not absent" every time.
if ($optsFunc === null) {
    $repoRoot = dirname(dirname(__DIR__));
    // >>> MUST BE THE COMMIT IMMEDIATELY BEFORE THE GUARD LANDED. <<<
    // Re-pin with: git rev-parse --short <guard-commit>^
    $preFixRev = 'a4a74df';
    $rel = 'DGV7.0-SAAS/func/bc-func.php';
    $live = $repoRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);

    echo "================ LIVE working tree ================\n";
    passthru(escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg(__FILE__) . ' --func=' . escapeshellarg($live), $liveRc);

    echo "\n================ NEGATIVE CONTROL: pre-fix revision $preFixRev ================\n";
    $content = shell_exec('git -C ' . escapeshellarg($repoRoot) . ' show ' . escapeshellarg($preFixRev . ':' . $rel) . ' 2>&1');
    if (!is_string($content) || $content === '' || strpos($content, 'fatal:') === 0) {
        echo "FATAL: cannot read $preFixRev:$rel - the gate is unverified.\n";
        exit(3);
    }
    if (strpos($content, 'bc_tx_failure_guard_check') !== false) {
        echo "FATAL: $preFixRev ALREADY contains the guard. Re-pin to the commit before the feature,\n";
        echo "or this control proves nothing.\n";
        exit(3);
    }
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gftal_prefix_' . getmypid() . '.php';
    file_put_contents($tmp, $content);
    passthru(escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg(__FILE__) . ' --func=' . escapeshellarg($tmp) . ' --expect-absent', $ctrlRc);
    @unlink($tmp);
    if (is_file($tmp)) { echo "FATAL: could not remove the control fixture $tmp\n"; exit(3); }

    echo "\n================ SUMMARY ================\n";
    echo "live tree       : " . ($liveRc === 0 ? "PASS (guard behaves as specified)" : "FAIL") . "\n";
    echo "pre-fix control : " . ($ctrlRc === 0 ? "PASS (no guard exists there - the gate discriminates)" : "FAIL (control did not hold)") . "\n";
    exit(($liveRc === 0 && $ctrlRc === 0) ? 0 : 1);
}

$GLOBALS['checks'] = 0;
$GLOBALS['fails']  = 0;
function ok($cond, $label)
{
    $GLOBALS['checks']++;
    if ($cond) { echo "ok   : $label\n"; }
    else { $GLOBALS['fails']++; echo "FAIL : $label\n"; }
}

// ───────────────────────────────────────────────────────── stateful mysqli stub
class TxRow
{
    public $rows;
    public $pos = 0;
    public function __construct($rows = array()) { $this->rows = $rows; }
}
class TxStmt
{
    public $sql;
    public $params = array();
    public $result;
    public function __construct($sql) { $this->sql = $sql; }
}

$GLOBALS['user'] = array(
    'tx_fail_streak' => 0, 'tx_fail_window_count' => 0, 'tx_fail_window_start' => null,
    'tx_fail_service' => null, 'tx_lock_until' => null, 'tx_lock_service' => null, 'tx_lock_reason' => null,
);
$GLOBALS['settings'] = array(
    'is_enabled' => 1, 'tx_guard_enabled' => 1, 'tx_guard_max_failures' => 5, 'tx_guard_window_hours' => 24,
    'tx_guard_scope' => 'all', 'tx_guard_notify_admin' => 1, 'tx_guard_auto_unlock' => 1,
);
$GLOBALS['alerts'] = 0;
$GLOBALS['queries'] = array();

/** Apply the tx_* assignments found in an UPDATE to the emulated row. */
function tx_apply_update($sql)
{
    $cols = array('tx_fail_streak', 'tx_fail_window_count', 'tx_fail_window_start', 'tx_fail_service',
                  'tx_lock_until', 'tx_lock_service', 'tx_lock_reason');
    foreach ($cols as $c) {
        // The real code writes BOTH forms: quoted ('$streak') on the failure path and bare (0, NULL)
        // on the success/reset path. Matching only the quoted form silently ignored every reset,
        // which is what made section C fail when this stub was first written.
        if (preg_match('/' . $c . "='([^']*)'/", $sql, $m)) {
            $GLOBALS['user'][$c] = $m[1];
        } elseif (preg_match('/' . $c . '=([^,\s]+)/', $sql, $m)) {
            $val = trim($m[1], " '\"");
            $GLOBALS['user'][$c] = (strtoupper($val) === 'NULL') ? null : $val;
        }
    }
}

function mysqli_query($link, $sql)
{
    $GLOBALS['queries'][] = $sql;
    if (stripos($sql, 'FROM sas_users') !== false && stripos($sql, 'tx_fail_streak') !== false) {
        return new TxRow(array($GLOBALS['user']));
    }
    if (stripos($sql, 'UPDATE sas_users') !== false) { tx_apply_update($sql); return true; }
    if (stripos($sql, 'FROM sas_vendors') !== false) {
        // Return NO row deliberately: bc_tx_failure_alert_admin() then returns before it can reach
        // sendVendorEmail() (which lives in bc-func.php and would pull in PHPMailer). Counting these
        // lookups is how the probe observes "the admin alert fires once, on the transition".
        return new TxRow(array());
    }
    return new TxRow(array());
}
function mysqli_prepare($link, $sql) { return new TxStmt($sql); }
function mysqli_stmt_bind_param($stmt, $types, &...$vars) { $stmt->params = $vars; return true; }
function mysqli_stmt_execute($stmt)
{
    if (stripos($stmt->sql, 'sas_bruteforce_settings') !== false) {
        $stmt->result = new TxRow(array($GLOBALS['settings']));
    } else { $stmt->result = new TxRow(array()); }
    return true;
}
function mysqli_stmt_get_result($stmt) { return $stmt->result; }
function mysqli_stmt_close($stmt) { return true; }
function mysqli_fetch_assoc($r) { return ($r instanceof TxRow && isset($r->rows[$r->pos])) ? $r->rows[$r->pos++] : null; }
function mysqli_fetch_array($r) { return mysqli_fetch_assoc($r); }
function mysqli_num_rows($r) { return $r instanceof TxRow ? count($r->rows) : 0; }
function mysqli_real_escape_string($l, $s) { return addslashes((string)$s); }
function mysqli_error($l) { return ''; }
function mysqli_errno($l) { return 0; }
function mysqli_insert_id($l) { return 1; }

/** How many times the alert path looked the admin up (see the sas_vendors branch above). */
function tx_alert_lookups()
{
    $n = 0;
    foreach ($GLOBALS['queries'] as $q) { if (stripos($q, 'FROM sas_vendors') !== false) $n++; }
    return $n;
}

function tx_reset_row()
{
    $GLOBALS['user'] = array(
        'tx_fail_streak' => 0, 'tx_fail_window_count' => 0, 'tx_fail_window_start' => null,
        'tx_fail_service' => null, 'tx_lock_until' => null, 'tx_lock_service' => null, 'tx_lock_reason' => null,
    );
}

// ────────────────────────────────────────────────────────────────── run the checks
$funcFile = $optsFunc;
if (!is_file($funcFile)) { echo "FATAL: cannot find $funcFile\n"; exit(2); }

$connection_server = new stdClass();
$GLOBALS['connection_server'] = $connection_server;
include_once $funcFile;

if ($expectAbsent) {
    // Control mode: this revision predates the feature, so the entry points must NOT exist.
    $missing = array();
    foreach (array('bc_tx_failure_guard_check', 'bc_tx_failure_record', 'bc_tx_unlock_user', 'bc_tx_failure_is_user_caused') as $fn) {
        if (!function_exists($fn)) $missing[] = $fn;
    }
    ok(count($missing) === 4, 'pre-fix revision defines NONE of the guard helpers (' . count($missing) . '/4 absent) -> nothing can enforce a lock');
    echo "\nCONTROL: " . $GLOBALS['checks'] . " checks, " . $GLOBALS['fails'] . " failed\n";
    exit($GLOBALS['fails'] === 0 ? 0 : 1);
}

foreach (array('bc_tx_failure_guard_check', 'bc_tx_failure_record', 'bc_tx_unlock_user', 'bc_tx_failure_is_user_caused') as $fn) {
    ok(function_exists($fn), "helper exists: $fn");
}

$V = 1; $U = 'pellar';

echo "\n--- A. provider-side failures must NEVER be counted ---\n";
tx_reset_row();
$provider = array(
    'out of stock', 'provider is currently unavailable', 'upstream timeout',
    'gateway returned an unreadable response', 'service temporarily down', 'Transaction Pending',
);
foreach ($provider as $reason) {
    $r = bc_tx_failure_record($V, $U, 'failed', 'mtn airtime', $reason);
    ok($r === 'ignored', "ignored provider-side reason: \"$reason\"");
}
ok((int)$GLOBALS['user']['tx_fail_streak'] === 0, 'streak is still 0 after 6 provider-side failures');
ok(bc_tx_failure_guard_check($V, $U, 'mtn airtime') === true, 'guard still allows the user to transact');

echo "\n--- B. user-caused failures are counted, and lock at the threshold ---\n";
tx_reset_row();
for ($i = 1; $i <= 4; $i++) {
    $r = bc_tx_failure_record($V, $U, 'failed', 'mtn airtime', 'Invalid transaction PIN.');
    ok($r === 'counted', "failure $i counted (under the limit of 5)");
}
ok(bc_tx_failure_guard_check($V, $U, 'mtn airtime') === true, 'still allowed after 4 failures (limit is 5)');
$lookups_before = tx_alert_lookups();
$r = bc_tx_failure_record($V, $U, 'failed', 'mtn airtime', 'Invalid transaction PIN.');
ok($r === 'locked', 'the 5th consecutive failure locks the account');
ok(!empty($GLOBALS['user']['tx_lock_reason']), 'a lock reason is stored for the user to see');
ok(!empty($GLOBALS['user']['tx_lock_until']), 'auto-unlock set an expiry (not a permanent lock)');
ok(tx_alert_lookups() === $lookups_before + 1, 'the admin alert fired once, on the transition into locked');
bc_tx_failure_record($V, $U, 'failed', 'mtn airtime', 'Invalid transaction PIN.');
ok(tx_alert_lookups() === $lookups_before + 1, 'a further failure while already locked does NOT re-alert');

$blocked = bc_tx_failure_guard_check($V, $U, 'mtn airtime');
ok($blocked !== true && is_string($blocked), 'guard now BLOCKS the user and returns a readable reason');
ok(stripos($blocked, 'Contact support') !== false, 'the message tells the user what to do');

echo "\n--- C. one success resets the streak (the 'not too strict' property) ---\n";
tx_reset_row();
bc_tx_failure_record($V, $U, 'failed', 'mtn airtime', 'Invalid transaction PIN.');
bc_tx_failure_record($V, $U, 'failed', 'mtn airtime', 'Invalid transaction PIN.');
bc_tx_failure_record($V, $U, 'failed', 'mtn airtime', 'Invalid transaction PIN.');
bc_tx_failure_record($V, $U, 'failed', 'mtn airtime', 'Invalid transaction PIN.');
$r = bc_tx_failure_record($V, $U, 'success', 'mtn airtime');
ok($r === 'reset', 'a success resets the counter');
ok((int)$GLOBALS['user']['tx_fail_streak'] === 0, 'streak is 0 after the success');
bc_tx_failure_record($V, $U, 'failed', 'mtn airtime', 'Invalid transaction PIN.');
ok(bc_tx_failure_guard_check($V, $U, 'mtn airtime') === true, '4 failures, success, then 1 failure does NOT lock');

echo "\n--- D. bulk/batch sources are never counted ---\n";
tx_reset_row();
for ($i = 0; $i < 6; $i++) {
    $r = bc_tx_failure_record($V, $U, 'failed', 'mtn airtime', 'Invalid transaction PIN.', 'bulk');
    ok($r === 'ignored', 'bulk failure ignored (batch of many numbers)');
    break; // one assertion is enough; the loop documents intent
}
ok((int)$GLOBALS['user']['tx_fail_streak'] === 0, 'a 100-item batch cannot lock the account');

echo "\n--- E. an expired lock auto-releases; a live one does not ---\n";
tx_reset_row();
$GLOBALS['user']['tx_lock_reason'] = 'locked for testing';
$GLOBALS['user']['tx_lock_until'] = date('Y-m-d H:i:s', time() + 3600);
ok(bc_tx_failure_guard_check($V, $U, 'mtn airtime') !== true, 'a live lock still blocks');
$GLOBALS['user']['tx_lock_until'] = date('Y-m-d H:i:s', time() - 3600);
ok(bc_tx_failure_guard_check($V, $U, 'mtn airtime') === true, 'an expired lock auto-releases');
ok(empty($GLOBALS['user']['tx_lock_reason']), 'the lock reason was cleared on auto-release');

echo "\n--- F. with auto-unlock off, the lock survives until an admin acts ---\n";
tx_reset_row();
$GLOBALS['settings']['tx_guard_auto_unlock'] = 0;
$GLOBALS['user']['tx_lock_reason'] = 'locked for testing';
$GLOBALS['user']['tx_lock_until'] = null;
ok(bc_tx_failure_guard_check($V, $U, 'mtn airtime') !== true, 'manual-only lock keeps blocking');
ok(bc_tx_unlock_user($V, $U, 7, 'admin-unblock') !== false, 'admin unlock runs');
ok(empty($GLOBALS['user']['tx_lock_reason']), 'admin unlock cleared the lock');
ok(bc_tx_failure_guard_check($V, $U, 'mtn airtime') === true, 'the user can transact again after admin unlock');

echo "\n--- G. the master switch turns everything off ---\n";
tx_reset_row();
$GLOBALS['settings']['tx_guard_auto_unlock'] = 1;
tx_reset_row();
$GLOBALS['user']['tx_lock_reason'] = 'locked';
ok(bc_tx_failure_guard_check($V, $U, 'mtn airtime') !== true, 'sanity: locked while enabled');
$GLOBALS['settings']['tx_guard_enabled'] = 0;
ok(bc_tx_failure_guard_check($V, $U, 'mtn airtime') === true, 'guard disabled -> the lock no longer blocks');
foreach (range(1, 8) as $i) bc_tx_failure_record($V, $U, 'failed', 'mtn airtime', 'Invalid transaction PIN.');
ok((int)$GLOBALS['user']['tx_fail_streak'] === 0, 'guard disabled -> nothing is counted either');
$GLOBALS['settings']['tx_guard_enabled'] = 1;

echo "\n--- H. a different service does not inherit another service's streak ---\n";
tx_reset_row();
bc_tx_failure_record($V, $U, 'failed', 'mtn airtime', 'Invalid transaction PIN.');
bc_tx_failure_record($V, $U, 'failed', 'mtn airtime', 'Invalid transaction PIN.');
$GLOBALS['settings']['tx_guard_scope'] = 'per-service';
bc_tx_failure_record($V, $U, 'failed', 'dstv cable', 'Invalid smartcard number.');
ok((int)$GLOBALS['user']['tx_fail_streak'] === 1, 'switching service restarts the streak at 1');
ok(bc_tx_failure_guard_check($V, $U, 'dstv cable') === true, 'not locked (1 of 5)');
$GLOBALS['settings']['tx_guard_scope'] = 'all';

echo "\n" . ($GLOBALS['fails'] === 0 ? 'PASS' : 'FAIL') . ": {$GLOBALS['checks']} checks, {$GLOBALS['fails']} failed\n";
exit($GLOBALS['fails'] === 0 ? 0 : 1);

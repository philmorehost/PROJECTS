<?php
/**
 * GATE: the crypto "mint money from nothing" hole (user "pellar", 2026-09-26).
 *
 * WHAT WENT WRONG (reconstructed from the production dump ueozmmmx_vtu.sql):
 *   1. web/CryptoHub.php `action=swap` never checked that the amount was positive.
 *   2. `($wallets['BTC']['balance'] ?? 0) < $amount`  ->  0 < -10  ->  FALSE, so it passed.
 *   3. updateUserCryptoBalance(..., 'debit', -10)  ->  balance = 0 - (-10) = +10 BTC.
 *   4. gross = -10 * 1e8 = -1e9, fee -17.5m, net -982.5m.
 *   5. chargeOtherUser(..., "credit", -1e9, -982.5m, ...) stripped the minus sign and
 *      credited +982,500,000 Naira.
 *   Net result: +10 BTC AND +N982,500,000 out of nothing, then spent/spread.
 *
 * This probe runs the REAL guard code from a given tree and asserts:
 *   - updateUserCryptoBalance() refuses a non-positive / non-finite amount and writes no SQL
 *   - updateUserCryptoBalance() still performs a normal debit for a positive amount
 *   - chargeOtherUser() rejects a negative amount WITHOUT touching the database
 *   - the swap/withdraw branches in web/CryptoHub.php and web/api/crypto.php reject <= 0
 *
 * It then re-runs the SAME assertions against a PINNED pre-fix revision read out of git as a
 * NEGATIVE CONTROL: a gate nobody has seen fail is a gate nobody should trust. If the control
 * cannot reproduce the bug the run is reported as meaningless and exits non-zero.
 *
 * Usage:
 *   php -n probe_money_hole_guard.php [--edition=SAAS|NON-SAAS]
 * (the mysqli stubs below are illegal when the real extension is loaded, hence -n)
 */

// Warnings/notices are noise here (the pre-fix code path walks through unrelated helpers);
// fatals must still surface, because a fatal means the code did something unexpected.
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', '1');

$opts = array('root' => null, 'expect-broken' => false, 'edition' => 'SAAS');
foreach (array_slice($argv, 1) as $a) {
    if (strpos($a, '--root=') === 0)            $opts['root'] = substr($a, 7);
    elseif ($a === '--expect-broken')           $opts['expect-broken'] = true;
    elseif (strpos($a, '--edition=') === 0)     $opts['edition'] = substr($a, 10);
}

$GLOBALS['checks'] = 0;
$GLOBALS['fails'] = 0;

function ok($cond, $label)
{
    $GLOBALS['checks']++;
    if ($cond) {
        echo "ok   : $label\n";
    } else {
        $GLOBALS['fails']++;
        echo "FAIL : $label\n";
    }
}

/** Classify an outcome against the mode we are running in. */
function assert_guard($guardHolds, $label)
{
    if ($GLOBALS['expectBroken']) {
        ok(!$guardHolds, 'negative control (bug reproduced): ' . $label);
    } else {
        ok($guardHolds, $label);
    }
}

$GLOBALS['expectBroken'] = $opts['expect-broken'];

// ---------------------------------------------------------------- mysqli stubs
$GLOBALS['issued_sql'] = array();

class MH_Result
{
    public $rows;
    public $pos = 0;
    public function __construct($rows = array()) { $this->rows = $rows; }
}

function mysqli_query($link, $sql)
{
    $GLOBALS['issued_sql'][] = $sql;
    // The guard must fire BEFORE any statement, so the fake rows never really matter.
    if (stripos($sql, 'FROM sas_vendors') !== false) {
        return new MH_Result(array(array(
            'id' => 1, 'vendor_id' => 1, 'username' => 'pellar', 'email' => 'nura001y@gmail.com',
            'firstname' => 'Abdurasheed', 'lastname' => 'Gambari', 'balance' => '982499140',
            'status' => '1', 'login_otp_enabled' => null,
        )));
    }
    if (stripos($sql, 'FROM sas_users') !== false) {
        return new MH_Result(array(array(
            'id' => 91, 'vendor_id' => 1, 'username' => 'pellar', 'email' => 'nura001y@gmail.com',
            'firstname' => 'Abdurasheed', 'lastname' => 'Gambari', 'balance' => '0', 'status' => '1',
        )));
    }
    return new MH_Result(array());
}
function mysqli_fetch_assoc($res) { return ($res instanceof MH_Result && isset($res->rows[$res->pos])) ? $res->rows[$res->pos++] : null; }
function mysqli_fetch_array($res) { return mysqli_fetch_assoc($res); }
function mysqli_num_rows($res)    { return $res instanceof MH_Result ? count($res->rows) : 0; }
function mysqli_real_escape_string($link, $s) { return addslashes((string)$s); }
function mysqli_error($link)  { return ''; }
function mysqli_errno($link)  { return 0; }
function mysqli_insert_id($link) { return 1; }
// resolveVendorID() / getSuperAdminOption() / sendVendorEmail() etc. are declared by the real
// func/bc-func.php - do NOT stub them, or PHP refuses to include it. Vendor context is supplied
// the same way the app supplies it: through the $GLOBALS['vendor_id'] fallback.

// ------------------------------------------------------------------ run checks
function run_probe($root, $expectBroken)
{
    $GLOBALS['expectBroken'] = $expectBroken;

    $cryptoFunc = $root . '/func/bc-crypto-func.php';
    $funcFile   = $root . '/func/bc-func.php';
    $cryptoHub  = $root . '/web/CryptoHub.php';
    $apiCrypto  = $root . '/web/api/crypto.php';

    foreach (array($cryptoFunc, $funcFile) as $f) {
        if (!is_file($f)) {
            echo "FATAL: missing $f\n";
            exit(2);
        }
    }

    $connection_server = new stdClass();      // truthy handle for the stubs
    $GLOBALS['connection_server'] = $connection_server;
    $GLOBALS['vendor_id'] = 1;                // the fallback resolveVendorID() reads
    $_SESSION = array();

    include_once $cryptoFunc;
    include_once $funcFile;

    // Email/decimal helpers are only reached by the PRE-FIX code (the fixed code returns before
    // them), and they live in files the probe does not load - declare them only if still absent.
    if (!function_exists('getUserEmailTemplate')) { function getUserEmailTemplate($slug, $part) { return ''; } }
    if (!function_exists('sendVendorEmail'))       { function sendVendorEmail($to, $subject, $body) { return true; } }
    if (!function_exists('toDecimal'))             { function toDecimal($v, $dp = 2) { return number_format((float)$v, $dp, '.', ''); } }
    // sendVendorEmail() in the pre-fix path renders a template through this helper, which lives in
    // a file this probe does not load. The credit has already been applied by then - only the
    // control needs to get past it to observe the vulnerable return value.
    if (!function_exists('mailDesignTemplate'))   { function mailDesignTemplate(...$args) { return ''; } }
    if (!function_exists('customBCMailSender'))    { function customBCMailSender(...$args) { return true; } }
    if (!function_exists('getSMTPUserForHeaders')) { function getSMTPUserForHeaders($link = null) { return 'no-reply@example.test'; } }

    // ---- 1. updateUserCryptoBalance: the single writer of crypto balances
    $GLOBALS['issued_sql'] = array();
    $r = updateUserCryptoBalance(1, 'pellar', 'BTC', -10, 'debit');
    assert_guard(
        ($r === false && count(array_filter($GLOBALS['issued_sql'], function ($s) { return stripos($s, 'UPDATE sas_user_crypto_wallets') !== false; })) === 0),
        'updateUserCryptoBalance refuses a negative debit and issues no UPDATE'
    );

    $GLOBALS['issued_sql'] = array();
    $r = updateUserCryptoBalance(1, 'pellar', 'BTC', '-10', 'credit');
    assert_guard(
        ($r === false && count($GLOBALS['issued_sql']) === 0),
        'updateUserCryptoBalance refuses a negative amount supplied as a string'
    );

    $GLOBALS['issued_sql'] = array();
    $r = updateUserCryptoBalance(1, 'pellar', 'BTC', NAN, 'credit');
    assert_guard($r === false, 'updateUserCryptoBalance refuses a non-finite amount (NAN)');

    $GLOBALS['issued_sql'] = array();
    $r = updateUserCryptoBalance(1, 'pellar', 'BTC', 0, 'credit');
    assert_guard($r === false, 'updateUserCryptoBalance refuses a zero amount');

    // Normal use must still work: a positive debit subtracts.
    $GLOBALS['issued_sql'] = array();
    $r = updateUserCryptoBalance(1, 'pellar', 'BTC', 10, 'debit');
    $debitSql = '';
    foreach ($GLOBALS['issued_sql'] as $s) {
        if (stripos($s, 'UPDATE sas_user_crypto_wallets') !== false) $debitSql = $s;
    }
    $okNormal = ($r !== false && strpos($debitSql, 'balance - 10') !== false && strpos($debitSql, '+ -') === false);
    if ($expectBroken) {
        // Pre-fix code accepted it too, so this check is informational in control mode.
        echo "info : (control) normal debit path -> " . ($okNormal ? 'still correct' : 'broken') . "\n";
    } else {
        ok($okNormal, 'updateUserCryptoBalance still debits a positive amount correctly');
    }

    // ---- 2. chargeOtherUser: must refuse negatives before its sanitiser strips the sign
    $GLOBALS['issued_sql'] = array();
    $res1 = chargeOtherUser('pellar', 'credit', 'crypto_swap', 'Crypto Swap', 'SWP_1', 'INTERNAL',
        -1000000000, -982500000, 'Swap -10 BTC to Naira', 'WEB', 'host.test', 1);
    $touchedDb1 = count($GLOBALS['issued_sql']);
    assert_guard(
        ($res1 === 'failed' && $touchedDb1 === 0),
        'chargeOtherUser rejects a negative amount without touching the database'
    );

    $GLOBALS['issued_sql'] = array();
    $res2 = chargeOtherUser('pellar', 'credit', 'crypto_swap', 'Crypto Swap', 'SWP_2', 'INTERNAL',
        '-1000000000', '-982500000', 'Swap -10 BTC to Naira', 'WEB', 'host.test', 1);
    assert_guard(
        ($res2 === 'failed' && count($GLOBALS['issued_sql']) === 0),
        'chargeOtherUser rejects negative amounts supplied as strings'
    );

    // ---- 3. Static: the request guards that stop the negative ever reaching step 1
    if (is_file($cryptoHub)) {
        $src = file_get_contents($cryptoHub);
        $swap = extract_block($src, "action'] == 'swap'", "action'] == 'withdraw'");
        $wd   = extract_block($src, "action'] == 'withdraw'", null);
        assert_guard(block_rejects_non_positive($swap), 'web/CryptoHub.php swap branch rejects <= 0');
        assert_guard(block_rejects_non_positive($wd),   'web/CryptoHub.php withdraw branch rejects <= 0');
    }
    if (is_file($apiCrypto)) {
        $src  = file_get_contents($apiCrypto);
        $wd   = extract_block($src, "=== 'withdraw'", null);
        assert_guard(block_rejects_non_positive($wd),   'web/api/crypto.php withdraw branch rejects <= 0');
    }
}

function extract_block($src, $fromNeedle, $toNeedle)
{
    $start = strpos($src, $fromNeedle);
    if ($start === false) return '';
    if ($toNeedle === null) return substr($src, $start);
    $end = strpos($src, $toNeedle, $start + 1);
    return $end === false ? substr($src, $start) : substr($src, $start, $end - $start);
}

function block_rejects_non_positive($block)
{
    return (bool)preg_match('/is_finite\s*\([^)]*\)[^;]*\|\|\s*\$amount\s*<=\s*0/s', $block);
}

// ------------------------------------------------------------------- dispatch
if ($opts['root'] !== null) {
    run_probe($opts['root'], $opts['expect-broken']);
    echo ($GLOBALS['fails'] === 0 ? "PASS" : "FAIL") . ": {$GLOBALS['checks']} checks, {$GLOBALS['fails']} failed\n";
    exit($GLOBALS['fails'] === 0 ? 0 : 1);
}

// ---- parent: working tree first, then the pinned pre-fix revision as a control
//
// >>> $preFixRev MUST BE THE COMMIT IMMEDIATELY BEFORE THE FIX. <<<
// A control read out of HEAD stops being a control the moment the fix is committed, because
// HEAD *becomes* the fixed file: the run then still fails, but for the wrong reason and with no
// signal left in it. Re-pin with:  git rev-parse --short <fix-commit>^
$preFixRev = 'aa0406c';   // parent of 3bfb018 ("fix(security): close the crypto money hole...")

$repoRoot   = dirname(dirname(__DIR__));
$editionDirName = 'DGV7.0-' . ($opts['edition'] === 'NON-SAAS' ? 'NON-SAAS' : 'SAAS');
$editionDir = $repoRoot . DIRECTORY_SEPARATOR . $editionDirName;

echo "================ LIVE working tree: $editionDirName ================\n";
$cmd = escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg(__FILE__) . ' --root=' . escapeshellarg($editionDir) . ' --edition=' . escapeshellarg($opts['edition']);
passthru($cmd, $liveRc);

echo "\n================ NEGATIVE CONTROL: pre-fix revision $preFixRev ================\n";
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mh_prefix_' . $editionDirName . '_' . getmypid();
@mkdir($tmp . '/func', 0777, true);
@mkdir($tmp . '/web/api', 0777, true);

$rel = array('func/bc-crypto-func.php', 'func/bc-func.php', 'web/CryptoHub.php', 'web/api/crypto.php');
$extracted = 0;
foreach ($rel as $r) {
    $spec = $preFixRev . ':' . $editionDirName . '/' . $r;
    $content = shell_exec('git -C ' . escapeshellarg($repoRoot) . ' show ' . escapeshellarg($spec) . ' 2>&1');
    if ($content === null || strpos($content, 'fatal:') === 0 || strpos($content, "path '") === 0) {
        echo "SKIP control: cannot read $spec\n";
        continue;
    }
    file_put_contents($tmp . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $r), $content);
    $extracted++;
}

if ($extracted !== count($rel)) {
    echo "\nNEGATIVE CONTROL ABORTED: only $extracted/" . count($rel) . " pre-fix files extracted from $preFixRev.\n";
    echo "The gate is unverified - do not treat a green live run as meaningful.\n";
    exit(3);
}

// Prove the fixture really is the pre-fix revision. Without this the control can silently
// degenerate into comparing the fix against itself and calling that proof.
$fixMarker = 'Reject negatives before sanitising';   // only the FIXED file contains this
$bugTell   = 'str_shuffle("0123456789")';            // only the PRE-FIX file contains this
$fixtureOk = true;
foreach ($rel as $r) {
    $body = (string)@file_get_contents($tmp . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $r));
    if (strpos($body, $fixMarker) !== false) $fixtureOk = false;
}
if (strpos((string)@file_get_contents($tmp . '/func/bc-func.php'), $bugTell) === false) $fixtureOk = false;

if (!$fixtureOk) {
    echo "\nNEGATIVE CONTROL ABORTED: $preFixRev is NOT the pre-fix revision.\n";
    echo "  expected it to lack: $fixMarker\n";
    echo "  expected it to still contain: $bugTell\n";
    echo "Re-pin \$preFixRev to the commit immediately before the fix. The gate is unverified.\n";
    exit(3);
}

$cmd2 = escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg(__FILE__) . ' --root=' . escapeshellarg($tmp) . ' --edition=' . escapeshellarg($opts['edition']) . ' --expect-broken';
passthru($cmd2, $controlRc);

echo "\n================ SUMMARY ================\n";
echo "live tree            : " . ($liveRc === 0 ? "PASS (guards hold)" : "FAIL (guard missing or ineffective)") . "\n";
if ($controlRc === 0) {
    echo "pre-fix control      : PASS (the bug IS reproduced without the fix - the gate discriminates)\n";
} else {
    echo "pre-fix control      : FAIL (bug NOT reproduced - this gate proves nothing)\n";
}
exit(($liveRc === 0 && $controlRc === 0) ? 0 : 1);

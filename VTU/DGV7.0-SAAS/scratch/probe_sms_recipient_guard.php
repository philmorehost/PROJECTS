<?php
/**
 * GATE: an SMS recipient list containing an undeliverable number (2026-09-30).
 *
 * WHAT WENT WRONG
 *   web/func/sms.php filtered recipients on string LENGTH alone:
 *       if (in_array(strlen($sms_phone_no), array("11"))) { ... }
 *   So "00000000000" was accepted as a recipient. The batch was charged and handed to the
 *   provider, the provider rejected it, and every rejection is refunded by design - and that
 *   refund cycle is the lever that minted wallet funds. airtime.php and data.php already
 *   refuse an impossible number; the SMS path was the one that was missed.
 *
 * WIRING CHECKS (static, on the real file)
 *   - the recipient filter calls bc_valid_mobile_phone()
 *   - the length-only test is gone
 *   - the guard appears BEFORE chargeUser() / the gateway include, so a bad list is refused
 *     before it can be charged
 *
 * BEHAVIOUR CHECKS (the real sms.php executed with mysqli/DB stubs)
 *   - a list of only impossible numbers is refused with the invalid-number message,
 *     chargeUser() is never called and no SQL is issued
 *   - a list with ONE impossible number is refused as a whole
 *   - a list of deliverable numbers is NOT blocked by the phone guard (it reaches the
 *     balance branch), so the guard cannot lock legitimate customers out
 *
 * The phone rule itself is lifted verbatim out of the tree's func/bc-func.php, so this probe
 * cannot drift away from the code it is checking.
 *
 * NEGATIVE CONTROL (a gate nobody has seen fail is a gate nobody should trust):
 *   git worktree add /tmp/pfx <pre-fix-rev>
 *   php -n probe_sms_recipient_guard.php --root=/tmp/pfx/DGV7.0-SAAS --expect-broken
 * In that mode the harness FIRST proves the tree really is pre-fix (guard absent); if it is
 * not, the comparison would prove nothing and the run exits non-zero.
 *
 * Usage: php -n probe_sms_recipient_guard.php [--root=<edition dir>] [--expect-broken]
 * (the mysqli stubs below are illegal when the real extension is loaded, hence -n)
 */

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', '1');

$opts = array('root' => null, 'expect-broken' => false);
foreach (array_slice($argv, 1) as $a) {
    if (strpos($a, '--root=') === 0)        $opts['root'] = substr($a, 7);
    elseif ($a === '--expect-broken')       $opts['expect-broken'] = true;
}

$root = $opts['root'] !== null
    ? rtrim(str_replace('\\', '/', $opts['root']), '/')
    : str_replace('\\', '/', dirname(__DIR__));
$smsFile = $root . '/web/func/sms.php';

if (!is_file($smsFile)) {
    fwrite(STDERR, "not found: $smsFile\n");
    exit(2);
}

$GLOBALS['checks'] = 0;
$GLOBALS['fails']  = 0;
$GLOBALS['expectBroken'] = $opts['expect-broken'];

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

echo "tree: $root\n";
echo $GLOBALS['expectBroken'] ? "mode: NEGATIVE CONTROL (pre-fix revision)\n" : "mode: guard\n";
echo "\n";

$src = file_get_contents($smsFile);
$guardCall = 'bc_valid_mobile_phone($sms_phone_no)';
$hasGuard    = strpos($src, $guardCall) !== false;
$hasLenOnly  = preg_match('/in_array\(strlen\(\$sms_phone_no\),\s*array\("11"\)\)/', $src) === 1;
$guardAt     = strpos($src, $guardCall);
$chargeAt    = strpos($src, 'chargeUser(');
$gatewayAt   = strpos($src, '/func/api-gateway/');

// ---------------------------------------------------------------- fixture identity
if ($GLOBALS['expectBroken']) {
    ok(!$hasGuard, 'negative control: this revision is genuinely pre-fix (no phone guard)');
    if ($hasGuard) {
        echo "\nFATAL: --expect-broken was pointed at a tree that already contains the fix.\n";
        echo "       The comparison would prove nothing, so this run is meaningless.\n";
        exit(2);
    }
}

// ---------------------------------------------------------------- wiring
assert_guard($hasGuard, 'the recipient filter calls bc_valid_mobile_phone()');
assert_guard(!$hasLenOnly, 'the length-only test (11000000000 passed it) is gone');
assert_guard($hasGuard && $chargeAt !== false && $guardAt < $chargeAt,
    'the guard is reached before chargeUser(): a bad list cannot be charged');
assert_guard($hasGuard && $gatewayAt !== false && $guardAt < $gatewayAt,
    'the guard is reached before the gateway include: a bad list never leaves the server');

// ---------------------------------------------------------------- the tree's real phone rule
function probe_extract_function($src, $name)
{
    $start = strpos($src, 'function ' . $name . '(');
    if ($start === false) return null;
    $i = strpos($src, '{', $start);
    if ($i === false) return null;
    $depth = 0;
    $len = strlen($src);
    for ($p = $i; $p < $len; $p++) {
        if ($src[$p] === '{') $depth++;
        elseif ($src[$p] === '}') { $depth--; if ($depth === 0) return substr($src, $start, $p - $start + 1); }
    }
    return null;
}

$bcFuncFile = $root . '/func/bc-func.php';
$bcSrc = is_file($bcFuncFile) ? file_get_contents($bcFuncFile) : '';
foreach (array('sanitize_phone_number', 'bc_valid_mobile_phone') as $fn) {
    $body = probe_extract_function($bcSrc, $fn);
    if ($body === null) {
        ok(false, "could not lift $fn() out of func/bc-func.php");
    } else {
        eval($body);
    }
}

// ---------------------------------------------------------------- stubs
class Probe_Result
{
    public $rows;
    public $pos = 0;
    public function __construct($rows = array()) { $this->rows = $rows; }
}

/**
 * The fake database hands out ONE plausible row per query (then null, like a real cursor),
 * so a code path that decides whether to charge gets all the way to chargeUser(). That is what
 * makes the "chargeUser() was never called" check meaningful: on the fixed tree a bad list is
 * refused before the charge, and on the pre-fix tree the same list IS charged.
 */
$GLOBALS['probe_row'] = array(
    'id' => 1, 'api_id' => 1, 'api_key' => 'probe-key', 'status' => 1,
    'product_name' => 'mtn', 'api_base_url' => 'localhost',
    'val_1' => 'standard_sms', 'val_2' => '4',
);

function mysqli_query($link, $sql) { $GLOBALS['probe_sql'][] = $sql; return new Probe_Result(array()); }
function mysqli_num_rows($result) { return 1; }
function mysqli_fetch_array($result, $mode = null)
{
    if (!($result instanceof Probe_Result) || $result->pos > 0) return null;
    $result->pos++;
    return $GLOBALS['probe_row'];
}
function mysqli_fetch_assoc($result) { return mysqli_fetch_array($result); }
function mysqli_real_escape_string($link, $s) { return $s; }
function mysqli_error($link) { return ''; }

function chargeUser() { $GLOBALS['probe_charge_calls'][] = func_get_args(); return 'success'; }
function userBalance($level) { return $GLOBALS['probe_balance']; }

/**
 * Execute the REAL web/func/sms.php in API mode with a deliberately empty database.
 * `return;` inside the included file hands control back here, so the response the file
 * built is readable from the caller's scope.
 */
function probe_run_sms($root, array $apiPost, $balance)
{
    $connection_server = null;
    $purchase_method = 'API';
    $get_api_post_info = $apiPost;
    $get_logged_user_details = array('vendor_id' => 1, 'username' => 'probe', 'account_level' => 1, 'status' => 1);
    $get_vendor_details = array();
    $json_response_array = null;
    $json_response_encode = null;
    $_SERVER['HTTP_HOST'] = 'probe.local';
    $_SERVER['DOCUMENT_ROOT'] = $root;
    $GLOBALS['probe_balance'] = $balance;
    $GLOBALS['probe_charge_calls'] = array();
    $GLOBALS['probe_sql'] = array();
    $GLOBALS['probe_include_error'] = null;

    try {
        include $root . '/web/func/sms.php';
    } catch (Throwable $e) {
        $GLOBALS['probe_include_error'] = get_class($e) . ': ' . $e->getMessage();
    }

    return array(
        'desc'  => is_array($json_response_array) ? ($json_response_array['desc'] ?? '') : '',
        'json'  => $json_response_encode,
        'error' => $GLOBALS['probe_include_error'],
    );
}

function probe_post($numbers)
{
    return array(
        'network'      => 'mtn',
        'phone_number' => $numbers,
        'sender_id'    => 'PROBEID',
        'message'      => 'Probe message',
        'type'         => 'standard_sms',
    );
}

// ---------------------------------------------------------------- behaviour
/** Print what the tree actually answered - the only way to explain a red check. */
function probe_trace($label, $r)
{
    $desc = $r['desc'] === '' ? '(no response built)' : $r['desc'];
    if ($r['error'] !== null) $desc .= ' [include threw: ' . $r['error'] . ']';
    echo "       $label -> \"$desc\" (chargeUser calls: " . count($GLOBALS['probe_charge_calls'])
        . ", SQL statements: " . count($GLOBALS['probe_sql']) . ")\n";
}

// 1. A list of only impossible numbers.
$r = probe_run_sms($root, probe_post('00000000000'), 5000);
probe_trace('only 00000000000', $r);
assert_guard(strpos($r['desc'], 'Invalid phone number') !== false,
    'an impossible number is refused with the invalid-number message');
assert_guard(count($GLOBALS['probe_charge_calls']) === 0,
    'and chargeUser() was never called for it');
assert_guard(count($GLOBALS['probe_sql']) === 0,
    'and no SQL was issued for it');

// 2. One impossible number poisons the batch.
$r = probe_run_sms($root, probe_post('08031234567,00000000000'), 5000);
probe_trace('08031234567 + 00000000000', $r);
assert_guard(strpos($r['desc'], 'Invalid phone number') !== false,
    'a list containing one impossible number is refused as a whole');

// 3. A deliverable list must NOT be blocked by the phone guard.
//    (Balance 0 on purpose: "Balance is LOW" proves the request got past the guard.)
$r = probe_run_sms($root, probe_post('08031234567,08099998888'), 0);
probe_trace('two deliverable numbers', $r);
ok(strpos($r['desc'], 'Invalid phone number') === false,
    'a valid recipient list is not refused by the phone guard');
ok(strpos($r['desc'], 'Balance is LOW') !== false,
    'a valid recipient list still reaches the balance branch (guard does not over-block)');

// 4. The rule itself, as the tree defines it.
ok(bc_valid_mobile_phone('08031234567') === true,  "the tree's rule accepts 08031234567");
ok(bc_valid_mobile_phone('2348031234567') === true, "the tree's rule accepts 2348031234567");
ok(bc_valid_mobile_phone('00000000000') === false, 'the tree\'s rule rejects 00000000000');
ok(bc_valid_mobile_phone('08000000000') === false, 'the tree\'s rule rejects 08000000000');
ok(bc_valid_mobile_phone('0701234567') === false,  'the tree\'s rule rejects a 10-digit number');
ok(bc_valid_mobile_phone('03012345678') === false, 'the tree\'s rule rejects an 030 prefix');

// ---------------------------------------------------------------- summary
echo "\n{$GLOBALS['checks']} checks, {$GLOBALS['fails']} failed\n";
if ($GLOBALS['fails'] > 0) {
    echo $GLOBALS['expectBroken']
        ? "RESULT: the tree is NOT pre-fix, or the bug does not reproduce\n"
        : "RESULT: FAIL\n";
    exit(1);
}
echo $GLOBALS['expectBroken']
    ? "RESULT: negative control reproduced the bug\n"
    : "RESULT: PASS\n";
exit(0);

<?php
/**
 * Regression test for the transaction-PIN gate — DGV7.0-SAAS.
 *
 * THE RULE UNDER TEST
 *   The vendor's "Enforce Transaction PIN for all Users" toggle (sas_vendors.force_security_pin)
 *   applies to a PERSON buying: our own website ("WEB") and the mobile app ("APP"). It does NOT
 *   apply to an external API integration ("API") - one merchant api_key serves that merchant's own
 *   customers, so there is no single PIN to ask for. Before this rule existed, an outside merchant
 *   calling e.g. web/api/exam.php got {"status":"failed","desc":"Invalid transaction PIN."} for
 *   every purchase.
 *
 * WHAT THIS DOES
 *   It does not re-implement the gate. It extracts the real verifyUserPIN()/requireTransactionPin()
 *   source out of func/bc-func.php and exercises that, so what is asserted here is what ships. If
 *   someone changes the gate, this fails.
 *
 * HOW TO RUN (from the DGV7.0-SAAS folder)
 *   php -n tests/pin_gate_test.php        (-n keeps ext-mysqli out so the query can be stubbed)
 *   php    tests/pin_gate_test.php        (works too: the vendor row is then passed in directly)
 *
 * Exit code 0 = all assertions passed, 1 = at least one failed.
 */

$app  = 'DGV7.0-SAAS';
$path = dirname(__DIR__) . '/func/bc-func.php';

$stub = <<<'PHP'
<?php
// --- stubs: only usable with `php -n` (no mysqli extension loaded) ---
if (!isset($GLOBALS['connection_server'])) $GLOBALS['connection_server'] = 'stub-connection';
$GLOBALS['__fake_vendor_row'] = null;
$GLOBALS['__query_count']     = 0;
if (!function_exists('mysqli_query')) {
    function mysqli_query($link, $sql) {
        $GLOBALS['__query_count']++;
        // Simulates: SELECT * FROM sas_vendors WHERE id=N
        return $GLOBALS['__fake_vendor_row'] ? true : false;
    }
}
if (!function_exists('mysqli_fetch_assoc')) {
    function mysqli_fetch_assoc($result) { return $GLOBALS['__fake_vendor_row']; }
}
PHP;

$assertions = 0;
$failures   = 0;

function check(string $label, $got, $expected): void
{
    global $assertions, $failures;
    $assertions++;
    if ($got === $expected) {
        echo "  ok   - $label\n";
    } else {
        $failures++;
        echo "  FAIL - $label (expected " . var_export($expected, true) . ", got " . var_export($got, true) . ")\n";
    }
}

echo "=== $app PIN gate ===\n";

$src = file_get_contents($path);
if ($src === false) { fwrite(STDERR, "cannot read $path\n"); exit(1); }

$start = strpos($src, 'function verifyUserPIN');
$end   = strpos($src, 'function base64url_decode');
if ($start === false || $end === false || $end <= $start) {
    fwrite(STDERR, "cannot locate verifyUserPIN()/requireTransactionPin() in $path\n");
    exit(1);
}

$tmp = tempnam(sys_get_temp_dir(), 'pingate') . '.php';
file_put_contents($tmp, $stub . "\n" . substr($src, $start, $end - $start));
require $tmp;
unlink($tmp);

$vendor_off = ['id' => 1, 'force_security_pin' => 0];
$vendor_on  = ['id' => 1, 'force_security_pin' => 1];

$user_pin_plain  = ['vendor_id' => 1, 'username' => 'u', 'transaction_pin' => '1234', 'security_pin' => ''];
$user_pin_hashed = ['vendor_id' => 1, 'username' => 'u', 'transaction_pin' => null,
                    'security_pin' => password_hash('4321', PASSWORD_DEFAULT)];
$user_no_pin     = ['vendor_id' => 1, 'username' => 'u', 'transaction_pin' => null, 'security_pin' => ''];

$err = '';

// Toggle OFF -> no PIN required, whatever the caller sends.
check('toggle off, no pin passed', requireTransactionPin($vendor_off, $user_pin_plain, [], $err), true);

// Toggle ON, correct PIN (legacy BIGINT column and the modern hash).
check('toggle on, correct legacy pin', requireTransactionPin($vendor_on, $user_pin_plain, ['pin' => '1234'], $err), true);
check('toggle on, correct hashed pin', requireTransactionPin($vendor_on, $user_pin_hashed, ['pin' => '4321'], $err), true);

// Toggle ON, missing / wrong PIN.
$err = '';
check('toggle on, missing pin -> blocked', requireTransactionPin($vendor_on, $user_pin_plain, [], $err), false);
check('   ... message names the invalid pin', $err, 'Invalid transaction PIN.');

$err = '';
check('toggle on, wrong pin -> blocked', requireTransactionPin($vendor_on, $user_pin_plain, ['pin' => '9999'], $err), false);

// Toggle ON but the account has no PIN at all -> tell them to set one.
$err = '';
check('toggle on, account without pin -> blocked', requireTransactionPin($vendor_on, $user_no_pin, ['pin' => '1234'], $err), false);
check('   ... message asks them to set a PIN', $err, 'Transaction PIN required. Please set a PIN in Security Settings first.');

// Partial vendor row (web purchase pages render from sas_site_details) -> the gate resolves it itself.
$GLOBALS['__fake_vendor_row'] = $vendor_on;
$GLOBALS['__query_count'] = 0;
$err = '';
check('partial row, toggle on, correct pin', requireTransactionPin([], $user_pin_plain, ['pin' => '1234'], $err), true);
check('   ... it looked the vendor up', $GLOBALS['__query_count'] > 0, true);

$GLOBALS['__fake_vendor_row'] = $vendor_on;
$err = '';
check('partial row, toggle on, no pin -> blocked', requireTransactionPin([], $user_pin_plain, [], $err), false);

$GLOBALS['__fake_vendor_row'] = $vendor_off;
$err = '';
check('partial row, toggle off -> allowed', requireTransactionPin([], $user_pin_plain, [], $err), true);

// Unresolvable / garbage input must not fatal, and must not block a sale it cannot judge.
$GLOBALS['__fake_vendor_row'] = null;
$err = '';
check('unresolvable vendor row -> allowed', requireTransactionPin([], ['vendor_id' => 0], [], $err), true);
check('non-array vendor row -> allowed', requireTransactionPin('not-an-array', $user_pin_plain, [], $err), true);
check('missing pin key in input -> blocked when forced', requireTransactionPin($vendor_on, $user_pin_plain, ['something' => 1], $err), false);

echo "ASSERTIONS: $assertions | FAILURES: $failures\n";
exit($failures === 0 ? 0 : 1);

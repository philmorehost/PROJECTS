<?php
/**
 * PayHub wallet crediting: "Payment confirmed but crediting failed".
 *
 * Run with:  C:\xampp\php\php.exe -n tests/payhub_credit_test.php
 *
 * `-n` matters: the stubs below stand in for the mysqli extension, so it must be absent.
 * (Without `-n` the extension owns those names and the guards skip the stubs.)
 *
 * Why this exists: the customer paid, PayHub reported the payment as a success, and the wallet was
 * never funded. The only path that produced that exact message is processPayhubSuccess() returning
 * false, and on a verified, resolved user payment the only way it could do so was the amount check:
 *
 *     if (abs($candidate[0] - $expected_naira) < 0.1) { ... }
 *     if ($amount_paid === null) { return false; }   // "BLOCKED amount mismatch"
 *
 * That demanded the amount that SETTLED be equal to the amount the reference was created for. But a
 * payer is charged the gateway's fee ON TOP at the moment of payment, so a record created for N100
 * settles as N101.53 - and equality read a legitimate payment as manipulation. The money had already
 * left the payer's account, so the funding was lost and the customer had to be refunded by hand.
 *
 * The rule is now one-sided (bc_gateway_amount_covers): a settled figure that COVERS the recorded
 * amount is a real payment and is credited - for the RECORDED amount, never the inflated settled
 * figure, so the gateway's fee is not credited to the customer. A settled figure that falls SHORT is
 * still refused, which is the property the check was written for: the amount is rendered into the
 * page that drives the payment, so it can be rewritten there (or the gateway called directly with
 * the same reference) to settle a token sum while the record still holds the larger figure.
 *
 * Section A exercises the shipped helper. Section B runs the shipped processPayhubSuccess() against a
 * stubbed database. Section C asserts the shipped wiring in BOTH editions, because they drift apart.
 */

$ED = array(
    'SAAS'     => __DIR__ . '/..',
    'NON-SAAS' => __DIR__ . '/../../DGV7.0-NON-SAAS',
);

$fails = 0;
$checks = 0;
function bc_assert($label, $actual, $expected) {
    global $fails, $checks;
    $checks++;
    if ($actual === $expected) {
        echo "  ok   $label\n";
    } else {
        $fails++;
        echo "  FAIL $label\n";
        echo "         expected: " . var_export($expected, true) . "\n";
        echo "         actual  : " . var_export($actual, true) . "\n";
    }
}
function bc_assert_true($label, $cond) { bc_assert($label, (bool)$cond, true); }

/* ==========================================================================
 * A. The shipped helper
 * ========================================================================== */
echo "== A. bc_gateway_amount_covers() (shipped func/bc-gateway.php) ==\n";
$helper = realpath(__DIR__ . '/../func/bc-gateway.php');
if (!$helper) { fwrite(STDERR, "cannot find func/bc-gateway.php\n"); exit(1); }
require_once($helper);

bc_assert_true('exact amount is covered', bc_gateway_amount_covers(100, 100));
// The reported case: the gateway added its fee on top (a N100 record settles as N101.53).
bc_assert_true('fee charged on top is covered (N100 -> N101.53)', bc_gateway_amount_covers(100, 101.53));
bc_assert_true('a much larger settlement is covered', bc_gateway_amount_covers(100, 250));
// The property the old check existed for: an underpayment must still be refused.
bc_assert_true('token sum does not cover a large record (N102.54 vs N10175)',
    !bc_gateway_amount_covers(10175, 102.54));
bc_assert_true('a penny short does not cover', !bc_gateway_amount_covers(100, 99.5));
bc_assert_true('one kobo short is still covered (rounding noise)',
    bc_gateway_amount_covers(100, 99.99));
bc_assert_true('zero settled against a real record does not cover', !bc_gateway_amount_covers(100, 0));
// Float drift on values that are not representable in binary must not create a mismatch.
bc_assert_true('binary float drift is absorbed (0.1 + 0.2)',
    bc_gateway_amount_covers(0.1 + 0.2, 0.3));
bc_assert_true('kobo-precision record is covered by its own figure',
    bc_gateway_amount_covers(1234.56, 1234.56));
bc_assert_true('a kobo-precision fee on top is covered', bc_gateway_amount_covers(1234.56, 1250.09));
// ...and the rule this replaced would have refused the very same payment, which is the bug.
bc_assert_true('the old strict "within 10 kobo" rule would have refused it', abs(100 - 101.53) >= 0.1);

/* ==========================================================================
 * B. The shipped processPayhubSuccess(), against a stubbed database
 *
 * bc-func.php defines only functions and is include-safe, so the REAL crediting code runs here.
 * Every statement it issues is recorded, which is how the balance update is inspected.
 * ========================================================================== */
echo "\n== B. processPayhubSuccess() crediting (shipped func/bc-func.php) ==\n";

if (!class_exists('BcTestResult')) {
    class BcTestResult {
        public $rows; public $i = 0;
        public function __construct($rows) { $this->rows = is_array($rows) ? array_values($rows) : array(); }
    }
}
if (!class_exists('BcTestLink')) { class BcTestLink {} }

$GLOBALS['bc_test_sql']  = array();
$GLOBALS['bc_test_rows'] = array();
$GLOBALS['connection_server'] = new BcTestLink();
// Collaborators read the host (for the derived PayHub email) - give them one so the run is quiet.
// Deliberately a neutral domain: a real vendor host here would put one operator's domain in the
// shipped tree for no benefit, and nothing in the payment path depends on which host it is.
$_SERVER['HTTP_HOST'] = 'example.com';

// Guarded so the file still PARSES (`php -l`) with mysqli loaded; `-n` is what makes them real.
if (!function_exists('mysqli_real_escape_string')) {
    function mysqli_real_escape_string($link, $s = '') { return str_replace(array("\\", "'"), array("\\\\", "\\'"), (string)$s); }
}
if (!function_exists('mysqli_error')) {
    function mysqli_error($link = null) { return ''; }
}
if (!function_exists('mysqli_query')) {
    function mysqli_query($link, $sql) {
        $GLOBALS['bc_test_sql'][] = $sql;
        foreach ($GLOBALS['bc_test_rows'] as $fragment => $rows) {
            if (strpos($sql, $fragment) !== false) { return new BcTestResult($rows); }
        }
        return new BcTestResult(array());
    }
}
if (!function_exists('mysqli_fetch_assoc')) {
    function mysqli_fetch_assoc($q) {
        if (!($q instanceof BcTestResult)) return null;
        return isset($q->rows[$q->i]) ? $q->rows[$q->i++] : null;
    }
}
if (!function_exists('mysqli_num_rows')) {
    function mysqli_num_rows($q) { return ($q instanceof BcTestResult) ? count($q->rows) : 0; }
}
if (!function_exists('mysqli_fetch_array')) {
    function mysqli_fetch_array($q) { return mysqli_fetch_assoc($q); }
}

require_once(__DIR__ . '/../func/bc-func.php');

// processPayhubSuccess logs to func/logs/payhub_webhook.log (a real file). Keep the working tree
// clean: remember whether it existed, and remove it at the end if this test created it.
$log_file = __DIR__ . '/../func/logs/payhub_webhook.log';
$log_dir  = __DIR__ . '/../func/logs';
$log_dir_existed = is_dir($log_dir);

function bc_payhub_log_size() {
    $file = __DIR__ . '/../func/logs/payhub_webhook.log';
    return is_file($file) ? (int)@filesize($file) : 0;
}
function bc_payhub_log_since($offset) {
    $file = __DIR__ . '/../func/logs/payhub_webhook.log';
    if (!is_file($file)) return '';
    $fh = fopen($file, 'rb');
    fseek($fh, $offset);
    $out = stream_get_contents($fh);
    fclose($fh);
    return (string)$out;
}

/** Runs the shipped function once with a scripted database. Returns [result, log produced]. */
function bc_payhub_run($data, $username = 'john', $vendor_id = 1, $row_overrides = array()) {
    $rows = array(
        // The amount this reference was created for.
        'SELECT amount FROM sas_transactions'                 => array(array('amount' => 100)),
        // The pending record created when the customer started the payment.
        'SELECT id, reference, status FROM sas_transactions'  => array(array('id' => 7, 'reference' => '17898451881435', 'status' => 2)),
        // Current wallet balance.
        'SELECT balance FROM sas_users'                       => array(array('balance' => 5000)),
    );
    $GLOBALS['bc_test_sql']  = array();
    $GLOBALS['bc_test_rows'] = array_replace($rows, $row_overrides);

    $data['metadata'] = array(
        'vendor_id' => $vendor_id,
        'username'  => $username,
        'target'    => 'user',
        'reference' => '17898451881435',
    );
    // No customer email: the virtual-account sync that follows a credit is a network call, so it is
    // left to bail out early. It is not what this test covers.
    unset($data['email']);
    if (isset($data['customer']['email'])) unset($data['customer']['email']);

    $before = bc_payhub_log_size();
    $result = processPayhubSuccess($vendor_id, $data['reference'], $data, array('percentage' => 0), $username);
    return array($result, bc_payhub_log_since($before));
}

function bc_payhub_sql_containing($fragment) {
    foreach ($GLOBALS['bc_test_sql'] as $sql) {
        if (strpos($sql, $fragment) !== false) return $sql;
    }
    return null;
}
function bc_payhub_credited_balance() {
    $sql = bc_payhub_sql_containing('UPDATE sas_users SET balance');
    if ($sql === null) return null;
    return preg_match("/balance='([0-9.]+)'/", $sql, $m) ? (float)$m[1] : null;
}

// --- B1: the reported case. PayHub settled N101.53 (gateway_amount is in kobo) for an N100 record.
list($ref, $log) = bc_payhub_run(array(
    'status' => 'success', 'reference' => 'PH_3c142', 'amount' => 10000,
    'domain' => 'live', 'gateway_amount' => 10153, 'channel' => 'card', 'customer' => array(),
));
bc_assert_true('fee charged on top: the payment is credited (was false -> "crediting failed")', !empty($ref));
bc_assert('fee charged on top: credited the RECORDED amount, not the inflated figure', bc_payhub_credited_balance(), 5100.0);
bc_assert_true('fee charged on top: the difference is recorded in the log', strpos($log, 'FEE ON TOP') !== false);
bc_assert_true('fee charged on top: the record is marked paid',
    strpos((string)bc_payhub_sql_containing('UPDATE sas_transactions SET status=1'), 'status=1') !== false);

// --- B2: exact settlement is unchanged.
list($ref, $log) = bc_payhub_run(array(
    'status' => 'success', 'reference' => 'PH_exact', 'amount' => 10000,
    'domain' => 'live', 'gateway_amount' => 10000, 'channel' => 'card', 'customer' => array(),
));
bc_assert_true('exact amount: still credited', !empty($ref));
bc_assert('exact amount: credited the recorded amount', bc_payhub_credited_balance(), 5100.0);

// --- B3: the token-sum attack the strict check was written for: N102.54 settled for a N10175 record.
list($ref, $log) = bc_payhub_run(array(
    'status' => 'success', 'reference' => 'PH_attack', 'amount' => 1017500,
    'domain' => 'live', 'gateway_amount' => 10254, 'channel' => 'card', 'customer' => array(),
), 'john', 1, array('SELECT amount FROM sas_transactions' => array(array('amount' => 10175))));
bc_assert('underpayment: refused (returns false)', $ref, false);
bc_assert('underpayment: the wallet is NOT touched', bc_payhub_credited_balance(), null);
bc_assert_true('underpayment: the reason is logged', strpos($log, 'BLOCKED amount short') !== false);

// --- B4: an older PayHub build that sends no gateway_amount. Our own requested figure (in kobo) is
//        echoed back, so it matches by construction and must still credit.
list($ref, $log) = bc_payhub_run(array(
    'status' => 'success', 'reference' => 'PH_legacy', 'amount' => 10000,
    'domain' => 'live', 'channel' => 'card', 'customer' => array(),
));
bc_assert_true('legacy payload (no gateway_amount): credited', !empty($ref));
bc_assert('legacy payload (no gateway_amount): credited the recorded amount', bc_payhub_credited_balance(), 5100.0);

// --- B5: a legacy payload whose echoed amount falls short is still refused.
list($ref, $log) = bc_payhub_run(array(
    'status' => 'success', 'reference' => 'PH_legacy_short', 'amount' => 5000,
    'domain' => 'live', 'channel' => 'card', 'customer' => array(),
));
bc_assert('legacy payload that falls short: refused', $ref, false);
bc_assert('legacy payload that falls short: the wallet is NOT touched', bc_payhub_credited_balance(), null);

// --- B6: sandbox money never funds a real wallet.
list($ref, $log) = bc_payhub_run(array(
    'status' => 'success', 'reference' => 'PH_test', 'amount' => 10000,
    'domain' => 'test', 'gateway_amount' => 10000, 'channel' => 'card', 'customer' => array(),
));
bc_assert('test/sandbox payment: refused', $ref, false);

// --- B7: an already-credited record is not credited twice.
list($ref, $log) = bc_payhub_run(array(
    'status' => 'success', 'reference' => 'PH_3c142', 'amount' => 10000,
    'domain' => 'live', 'gateway_amount' => 10153, 'channel' => 'card', 'customer' => array(),
), 'john', 1, array('SELECT id, reference, status FROM sas_transactions' => array(array('id' => 7, 'reference' => '17898451881435', 'status' => 1))));
bc_assert('already credited: reports the local reference', $ref, '17898451881435');
bc_assert('already credited: no second balance update', bc_payhub_credited_balance(), null);

// --- B8: a paid reference with no local pending row still credits (new credit record path).
list($ref, $log) = bc_payhub_run(array(
    'status' => 'success', 'reference' => 'PH_fresh', 'amount' => 10000,
    'domain' => 'live', 'gateway_amount' => 10000, 'channel' => 'card', 'customer' => array(),
), 'john', 1, array('SELECT id, reference, status FROM sas_transactions' => array()));
bc_assert_true('no local pending row: still credited through chargeOtherUser()', !empty($ref));

// Leave no trace of the test in the working tree.
if (!$log_dir_existed && is_file($log_file)) { @unlink($log_file); @rmdir($log_dir); }

/* ==========================================================================
 * C. Wiring in both editions
 * ========================================================================== */
echo "\n== C. shipped wiring (both editions) ==\n";

foreach ($ED as $edition => $root) {
    $func = @file_get_contents($root . '/func/bc-func.php');
    $gw   = @file_get_contents($root . '/func/bc-gateway.php');
    if ($func === false || $gw === false) { bc_assert_true("$edition: source files readable", false); continue; }

    bc_assert_true("$edition: the settled amount is judged with bc_gateway_amount_covers()",
        strpos($func, 'bc_gateway_amount_covers($expected_naira, $settled_seen)') !== false);
    bc_assert_true("$edition: the strict equality check is gone from the settled branch",
        strpos($func, 'BLOCKED amount mismatch') === false);
    bc_assert_true("$edition: an underpayment is still refused",
        strpos($func, 'BLOCKED amount short') !== false && strpos($func, 'Refusing to credit.') !== false);
    bc_assert_true("$edition: the credited figure is the recorded amount, not the settled one",
        strpos($func, '$amount_paid = ($settled_naira > 0) ? $expected_naira : $settled_seen;') !== false);

    // The vendor branch read a column its own SELECT never fetched.
    bc_assert_true("$edition: vendor lookup fetches product_unique_id (undefined-key warning)",
        strpos($func, 'SELECT id, reference, status, product_unique_id FROM sas_vendor_transactions') !== false);

    // Every success page that actually credits must label the account it is crediting. The NON-SAAS
    // pages are still static (no verify/credit step there), so this covers them the day they gain
    // the flow instead of asserting the gap as if it were correct.
    foreach (array('/web/payhub-success.php' => 'user', '/bc-admin/payhub-success.php' => 'vendor') as $rel => $target) {
        $page = @file_get_contents($root . $rel);
        if ($page === false) { bc_assert_true("$edition$rel readable", false); continue; }
        if (strpos($page, 'processPayhubSuccess') === false) {
            echo "  note $edition$rel does not credit yet (static page) - target check skipped\n";
            continue;
        }
        bc_assert_true("$edition$rel credits target '$target'",
            strpos($page, "'target'    => '$target'") !== false);
    }
}

$saas_gw = @file_get_contents($ED['SAAS'] . '/func/bc-gateway.php');
$non_gw  = @file_get_contents($ED['NON-SAAS'] . '/func/bc-gateway.php');
bc_assert_true('func/bc-gateway.php is identical in both editions', $saas_gw === $non_gw && $saas_gw !== false);

// The two editions' processPayhubSuccess differ (the NON-SAAS one keeps a silent log stub), so the
// drift guard is on the decision block itself - the part that decides whether a customer is paid.
$decision_block = function ($src) {
    if ($src === false) return null;
    $start = strpos($src, '$matches = ($settled_naira > 0)');
    if ($start === false) return null;
    $end = strpos($src, '$log("Match Found:', $start);
    if ($end === false) return null;
    return substr($src, $start, $end - $start);
};
$saas_decision = $decision_block(@file_get_contents($ED['SAAS'] . '/func/bc-func.php'));
$non_decision  = $decision_block(@file_get_contents($ED['NON-SAAS'] . '/func/bc-func.php'));
bc_assert_true('the amount decision is identical in both editions',
    $saas_decision !== null && $saas_decision === $non_decision);

/* ==========================================================================
 * D. NON-SAAS parity: the card flow must exist there too
 *
 * NON-SAAS used to be a whole release behind: its success pages were static
 * ("Payment Received!" unconditionally, no verify, no credit), its
 * gateway_redirect sent the local reference PayHub ignores, it captured no
 * PayHub reference, it had no payhub_status poll, and its create_checkout
 * INSERT omitted the NOT NULL api_website column. Crediting there depended
 * entirely on the webhook. These assertions keep the two editions in step.
 * ========================================================================== */
echo "\n== D. NON-SAAS funding-flow parity ==\n";

foreach ($ED as $edition => $root) {
    $ajax = @file_get_contents($root . '/web/finance-ajax.php');
    $fund = @file_get_contents($root . '/web/Fund.php');
    if ($ajax === false || $fund === false) { bc_assert_true("$edition: funding files readable", false); continue; }

    // The server reference PayHub will verify against must be THEIRS, not ours.
    bc_assert_true("$edition: gateway_redirect captures PayHub's own reference",
        strpos($ajax, "\$inner['data']['reference']") !== false && strpos($ajax, '$payhub_ref = trim((string)$payhub_ref);') !== false);
    bc_assert_true("$edition: the PayHub reference is returned to the front-end",
        strpos($ajax, "'checkout_url' => \$url, 'payhub_ref' => \$payhub_ref") !== false);
    bc_assert_true("$edition: the local transaction is mapped to the PayHub reference",
        strpos($ajax, "UPDATE sas_transactions SET api_reference='\$ph_ref_esc' WHERE reference='\$loc_ref_esc' AND vendor_id='\$vid'") !== false);
    bc_assert_true("$edition: a checkout row is keyed by the PayHub reference",
        strpos($ajax, "INSERT INTO sas_user_payment_checkouts (vendor_id, username, reference, status) VALUES ('\$vid'") !== false);
    // PayHub ignores a top-level reference/callback_url, so sending them is misleading - but the
    // local reference must still reach PayHub inside metadata. One occurrence each, not two.
    $init_start = strpos($ajax, 'makePayhubRequest("POST", "api/transaction/initialize", [');
    $init_end   = ($init_start === false) ? false : strpos($ajax, '], $vid, $is_vendor_funding);', $init_start);
    $init_block = ($init_start === false || $init_end === false) ? null : substr($ajax, $init_start, $init_end - $init_start);
    bc_assert("$edition: the local reference is sent ONLY inside metadata",
        $init_block === null ? -1 : substr_count($init_block, '"reference" =>'), 1);
    bc_assert("$edition: callback_url is sent ONLY inside metadata",
        $init_block === null ? -1 : substr_count($init_block, '"callback_url" =>'), 1);
    bc_assert("$edition: the initialize payload still carries metadata",
        $init_block === null ? -1 : substr_count($init_block, '"metadata" =>'), 1);
    bc_assert_true("$edition: create_checkout supplies api_website (NOT NULL, was a silent failure)",
        strpos($ajax, "description, mode, api_website, status) VALUES") !== false);

    // Without the poll the modal often just resets and the customer is never credited.
    bc_assert_true("$edition: a payhub_status poll endpoint exists", strpos($ajax, "\$action == 'payhub_status'") !== false);
    bc_assert_true("$edition: the poll verifies a payment before crediting (not the top-level boolean)",
        strpos($ajax, "if (\$is_paid)") !== false || strpos($ajax, "\$is_paid = (\$tx_status == 'success'") !== false);
    bc_assert_true("$edition: the poll credits through processPayhubSuccess()",
        strpos($ajax, "processPayhubSuccess(\$vid, \$tx_data['reference'], \$tx_data, \$payhub_keys, \$username)") !== false);
    bc_assert_true("$edition: the Fund page polls payhub_status",
        strpos($fund, "finance-ajax.php?action=payhub_status&reference=") !== false);
    bc_assert_true("$edition: the Fund page carries the PayHub reference into the poll and redirect",
        strpos($fund, "&payhub_ref=' + encodeURIComponent(payhubRef)") !== false);
    bc_assert_true("$edition: the Fund page recognises PayHub's postMessage shape",
        strpos($fund, "msg.type === 'payhub_success'") !== false);
}

foreach (array('/web/payhub-success.php', '/bc-admin/payhub-success.php', '/web/api/payhub-checkout.php') as $rel) {
    $saas = @file_get_contents($ED['SAAS'] . $rel);
    $non  = @file_get_contents($ED['NON-SAAS'] . $rel);
    bc_assert_true("$rel is identical in both editions", $saas !== false && $saas === $non);
}

/* ==========================================================================
 * E. A definitive gateway FAILURE must not read as "still being confirmed"
 *
 * PayHub's own integrity checks can block fulfilment - it then reports
 * data.status = 'failed' and marks the transaction failed with
 * failure_reason = 'amount_mismatch'. That is a verdict, not a delay, and on
 * 2026-09-19 one such block (settled N103.15 against a record for N101.60 - the
 * 1.5% card fee added on top, i.e. the very case amount_covers() accepts) left the
 * customer's page saying "Payment is still being confirmed. Your wallet will be
 * credited automatically." for a payment that had already been taken and would
 * never be credited by PayHub. Both were reported as 'pending', so the Fund page
 * also polled for its full ~10-minute window and then gave up silently.
 * ========================================================================== */
echo "\n== E. definitive failure is reported as failed ==\n";

bc_assert('a failed status word is a failure', bc_gateway_provider_verdict(array('status' => 'failed')), 'failed');
bc_assert('a declined status word is a failure', bc_gateway_provider_verdict(array('status' => 'declined')), 'failed');
bc_assert('a reversed status word is a failure', bc_gateway_provider_verdict(array('status' => 'reversed')), 'failed');
bc_assert('pending stays pending', bc_gateway_provider_verdict(array('status' => 'pending')), 'pending');
bc_assert('an unknown word is not a failure', bc_gateway_provider_verdict(array('status' => 'weird')), null);

foreach ($ED as $edition => $root) {
    foreach (array('/web/payhub-success.php', '/bc-admin/payhub-success.php') as $rel) {
        $page = @file_get_contents($root . $rel);
        if ($page === false) { bc_assert_true("$edition$rel readable", false); continue; }
        bc_assert_true("$edition$rel separates a definitive failure from a delay",
            strpos($page, "bc_gateway_provider_verdict(array('status' => \$tx_status)) === 'failed'") !== false);
        bc_assert_true("$edition$rel still reassures while it is only unconfirmed",
            strpos($page, 'Payment is still being confirmed.') !== false);
        bc_assert_true("$edition$rel tells the payer what to do about a failure",
            strpos($page, 'contact support with reference') !== false);
    }

    $ajax = @file_get_contents($root . '/web/finance-ajax.php');
    $fund = @file_get_contents($root . '/web/Fund.php');
    bc_assert_true("$edition: the poll answers 'failed' for a failure",
        $ajax !== false && strpos($ajax, "(\$verdict === 'failed') ? 'failed' : 'pending'") !== false);
    bc_assert_true("$edition: the poll keeps 'pending' for an unresolved payment",
        $ajax !== false && strpos($ajax, "echo json_encode(['status' => 'pending', 'payhub_ref' => \$verify_ref]);") !== false);
    bc_assert_true("$edition: the Fund page stops polling on a failure",
        $fund !== false && strpos($fund, "data.status === 'failed'") !== false);
}

echo "\n----------------------------------------\n";
echo ($checks - $fails) . "/$checks checks passed" . ($fails ? " - $fails FAILED" : "") . "\n";
exit($fails ? 1 : 0);

<?php
/** Run: php -n VTU/tests/gateway_verdict_test.php
 * §32 (2026-11) provider-verdict + receipt checks:
 *  - bc_gateway_word_verdict vocabulary/phrase mapping (unknown => NULL)
 *  - bc_gateway_refund_is_safe: only DEFINITIVE failures may refund
 *  - bc_gateway_settle_purchase: a mislabelled "failed" gets a second opinion
 *    (provider said delivered => successful; unknown => pending, never refund)
 *  - edition parity of the §32 helpers (SAAS/NON-SAAS behave identically)
 *  - every PayBills service still routes through settle + refund guard
 *  - transaction receipt: showTransactionDetails defined in both shells,
 *    scoped JSON endpoints exist, user payloads expose no provider internals.
 * Pure functions + source assertions only; no DB needed.
 */
$root = dirname(__DIR__);
$editions = array('DGV7.0-SAAS', 'DGV7.0-NON-SAAS');

function gw_src($root, $ed, $rel) {
    $p = $root . '/' . $ed . '/' . $rel;
    if (!is_file($p)) throw new RuntimeException("missing $p");
    return file_get_contents($p);
}
function gw_fn_body($src, $fn) {
    $pos = strpos($src, 'function ' . $fn . '(');
    if ($pos === false) return null;
    $brace = strpos($src, '{', $pos);
    $depth = 0; $i = $brace; $len = strlen($src);
    while ($i < $len) {
        if ($src[$i] === '{') $depth++;
        elseif ($src[$i] === '}') { $depth--; if ($depth === 0) return substr($src, $pos, $i - $pos + 1); }
        $i++;
    }
    return null;
}

$count = 0;
function gw_check($label, $actual, $expected) {
    global $count;
    $count++;
    if ($actual !== $expected) {
        throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
    echo "ok $label\n";
}
function gw_true($label, $actual) { gw_check($label, (bool)$actual, true); }
function gw_false($label, $actual) { gw_check($label, (bool)$actual, false); }

// ── edition parity of the §32 helpers ─────────────────────────────────────
$saas_gw = gw_src($root, 'DGV7.0-SAAS', 'func/bc-gateway.php');
$ns_gw = gw_src($root, 'DGV7.0-NON-SAAS', 'func/bc-gateway.php');
foreach (array('bc_gateway_word_verdict', 'bc_gateway_failure_needs_operator', 'bc_gateway_notify_issue', 'bc_gateway_refund_is_safe', 'bc_gateway_settle_purchase') as $fn) {
    gw_check("$fn identical across editions", gw_fn_body($ns_gw, $fn), gw_fn_body($saas_gw, $fn));
    gw_true("$fn defined in SAAS gateway", gw_fn_body($saas_gw, $fn) !== null);
}

require $root . '/DGV7.0-SAAS/func/bc-gateway.php';

// ── word verdict: exact tokens ────────────────────────────────────────────
gw_check('verdict successful', bc_gateway_word_verdict('successful'), 'successful');
gw_check('verdict success', bc_gateway_word_verdict('success'), 'successful');
gw_check('verdict COMPLETED', bc_gateway_word_verdict('COMPLETED'), 'successful');
gw_check('verdict delivered', bc_gateway_word_verdict('delivered'), 'successful');
gw_check('verdict failed', bc_gateway_word_verdict('failed'), 'failed');
gw_check('verdict declined', bc_gateway_word_verdict('declined'), 'failed');
gw_check('verdict pending', bc_gateway_word_verdict('pending'), 'pending');
gw_check('verdict processing', bc_gateway_word_verdict('Processing'), 'pending');
gw_check('verdict transport sentinel 1', bc_gateway_word_verdict('1'), null);
gw_check('verdict empty', bc_gateway_word_verdict(''), null);

// ── word verdict: sentences ───────────────────────────────────────────────
gw_check('verdict vtpass success sentence', bc_gateway_word_verdict('Transaction was successful'), 'successful');
gw_check('verdict order completed phrase', bc_gateway_word_verdict('ORDER COMPLETED'), 'successful');
gw_check('verdict credited phrase', bc_gateway_word_verdict('Customer credited successfully'), 'successful');
gw_check('verdict insufficient balance', bc_gateway_word_verdict('Insufficient balance, try again'), 'failed');
gw_check('verdict invalid number', bc_gateway_word_verdict('Invalid phone number'), 'failed');
gw_check('verdict not successful is failure', bc_gateway_word_verdict('Your request was not successful'), 'failed');
gw_check('verdict reversed beats completed', bc_gateway_word_verdict('Completed but reversed by issuer'), 'failed');
gw_check('verdict order received is pending', bc_gateway_word_verdict('ORDER RECEIVED'), 'pending');
gw_check('verdict gibberish is unknown', bc_gateway_word_verdict('XYZ9 channel seven'), null);
gw_check('verdict processed successfully', bc_gateway_word_verdict('Processed successfully'), 'successful');
gw_check('verdict completed unsuccessfully is failure', bc_gateway_word_verdict('Request completed unsuccessfully'), 'failed');
gw_check('verdict neutral sentence unknown', bc_gateway_word_verdict('Request logged via channel 7'), null);

// ── refund safety: definitive failures only ───────────────────────────────
$r = 'failed'; $t = 'Insufficient Balance';  gw_true('refund allowed: insufficient balance', bc_gateway_refund_is_safe($r, $t));
$r = 'failed'; $t = 'Transaction Failed | invalid number'; gw_true('refund allowed: failed/invalid wording', bc_gateway_refund_is_safe($r, $t));
$r = 'failed'; $t = 'Provider declined this request'; gw_true('refund allowed: declined', bc_gateway_refund_is_safe($r, $t));
$r = 'failed'; $t = 'success';           gw_false('refund blocked: provider said success', bc_gateway_refund_is_safe($r, $t));
$r = 'failed'; $t = 'COMPLETED';         gw_false('refund blocked: provider said completed', bc_gateway_refund_is_safe($r, $t));
$r = 'failed'; $t = '';                  gw_false('refund blocked: empty text', bc_gateway_refund_is_safe($r, $t));
$r = 'failed'; $t = '1';                 gw_false('refund blocked: transport sentinel', bc_gateway_refund_is_safe($r, $t));
$r = 'failed'; $t = 'gibberish words';   gw_false('refund blocked: unknown wording', bc_gateway_refund_is_safe($r, $t));
$r = 'successful'; $t = 'failed';        gw_false('refund blocked: not a failure outcome', bc_gateway_refund_is_safe($r, $t));
$r = 'pending'; $t = 'failed';           gw_false('refund blocked: pending outcome', bc_gateway_refund_is_safe($r, $t));

// ── settle: second opinion on "failed" ────────────────────────────────────
$r = 'failed'; $t = 'success'; $d = 'Transaction Failed | x'; $s = 3;
gw_true('settle corrects delivered', bc_gateway_settle_purchase($r, $t, $d, $s, '', ''));
gw_check('settle corrected outcome', $r, 'successful');
gw_check('settle corrected status', $s, 1);

$r = 'failed'; $t = 'Insufficient Balance'; $d = 'Transaction Failed | x'; $s = 3;
gw_false('settle leaves definitive failure', bc_gateway_settle_purchase($r, $t, $d, $s, '', ''));
gw_check('definitive failure stands', $r, 'failed');
gw_check('definitive failure status stands', $s, 3);

$r = 'failed'; $t = 'mystery wording'; $d = 'Transaction Failed | x'; $s = 3;
gw_true('settle parks unknown failure', bc_gateway_settle_purchase($r, $t, $d, $s, '', ''));
gw_check('unknown failure becomes pending', $r, 'pending');
gw_check('unknown failure pending status', $s, 2);

$r = 'failed'; $t = 'Pending'; $d = ''; $s = 3;
gw_true('settle honours provider pending', bc_gateway_settle_purchase($r, $t, $d, $s, '', ''));
gw_check('provider pending outcome', $r, 'pending');

$r = null; $t = null; $d = ''; $s = null;
gw_true('settle parks unclassified reply', bc_gateway_settle_purchase($r, $t, $d, $s, '', ''));
gw_check('unclassified becomes pending', $r, 'pending');

$r = 'successful'; $t = 'successful'; $d = ''; $s = 1;
gw_false('settle never overrides success', bc_gateway_settle_purchase($r, $t, $d, $s, '', ''));
gw_check('success untouched', $r, 'successful');

// operator-attention + notifier safety without a DB
gw_true('operator needed: insufficient', bc_gateway_failure_needs_operator('Insufficient balance'));
gw_true('operator needed: unauthorized', bc_gateway_failure_needs_operator('Unauthorized: bad key'));
gw_false('operator not needed: invalid number', bc_gateway_failure_needs_operator('Invalid phone number'));
gw_false('notifier is safe without DB', bc_gateway_notify_issue('test', 'a=>b', 'text', '', ''));

// ── every PayBills service routes through the gates (both editions) ───────
foreach ($editions as $ed) {
    foreach (array('airtime', 'betting', 'cable', 'card', 'data', 'electric', 'exam', 'sms', 'requery-transaction') as $svc) {
        $body = gw_src($root, $ed, 'web/func/' . $svc . '.php');
        gw_true("$ed $svc settles via gateway helper", strpos($body, 'bc_gateway_settle_purchase') !== false);
        gw_true("$ed $svc guards refunds", strpos($body, 'bc_gateway_refund_is_safe') !== false);
    }
}

// ── transaction receipt restoration ───────────────────────────────────────
// The row onclick + post-purchase "View Receipt" both call
// showTransactionDetails(ref[, isAdmin]); it must exist in both shells.
foreach ($editions as $ed) {
    gw_true("$ed app shell defines showTransactionDetails", strpos(gw_src($root, $ed, 'func/bc-plate-app.php'), 'showTransactionDetails = function') !== false);
    gw_true("$ed admin shell defines showTransactionDetails", strpos(gw_src($root, $ed, 'func/bc-plate-admin.php'), 'showTransactionDetails = function') !== false);
    gw_true("$ed shared receipt builder exists", is_file($root . '/' . $ed . '/func/bc-transaction-receipt.php'));
    gw_true("$ed user receipt endpoint exists", is_file($root . '/' . $ed . '/web/transaction-details.php'));
    gw_true("$ed admin receipt endpoint exists", is_file($root . '/' . $ed . '/bc-admin/transaction-details.php'));
    // user endpoint scopes by the signed-in customer
    $ue = gw_src($root, $ed, 'web/transaction-details.php');
    gw_true("$ed user endpoint is session-scoped", strpos($ue, 'get_logged_user_details') !== false);
    gw_true("$ed user endpoint builder is shared", strpos($ue, 'bc-transaction-receipt.php') !== false);
    $ae = gw_src($root, $ed, 'bc-admin/transaction-details.php');
    gw_true("$ed admin endpoint is vendor-scoped", strpos($ae, 'get_logged_admin_details') !== false);
}
gw_true('SAAS spadmin receipt endpoint exists', is_file($root . '/DGV7.0-SAAS/bc-spadmin/transaction-details.php'));
gw_true('SAAS spadmin endpoint is super-admin scoped', strpos(gw_src($root, 'DGV7.0-SAAS', 'bc-spadmin/transaction-details.php'), 'get_logged_spadmin_details') !== false);

// provider internals (gateway host / provider reference / raw description)
// are admin-only in the receipt payload
$builder = gw_src($root, 'DGV7.0-SAAS', 'func/bc-transaction-receipt.php');
gw_true('receipt builder gates provider fields behind admin flag', strpos($builder, "if (\$admin)") !== false);
gw_true('receipt builder never sends api key material', strpos($builder, 'api_key') === false);

echo "$count gateway_verdict checks passed\n";

<?php
/**
 * Reseller chain (parent <-> child) money-path guards.
 *
 * Run with:  C:\xampp\php\php.exe -n tests/gateway_response_test.php
 *
 * Why this exists: a vendor whose upstream is ANOTHER DGV install (a reseller of a reseller) could
 * end up charged with nothing delivered and no refund, because
 *   1. a gateway that could not classify the upstream reply left $api_response NULL, so none of the
 *      handler's status branches matched and the refund branch never ran;
 *   2. the parent answered a requery for an already-refunded transaction with status "success"
 *      ("Account Refunded Already"), which the child's reseller gateway read as "purchase
 *      successful" — so the reversal never propagated and the end customer was never refunded;
 *   3. the child's requery queue only rechecked status='2' (pending), so a purchase the parent
 *      reversed AFTER reporting success was never re-examined on the child at all.
 *
 * Section A exercises the NEW shared helper for real (the shipped file, not a copy).
 * Section B asserts the shipped wiring in BOTH editions, because the two editions drift apart.
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

echo "== A. func/bc-gateway.php behaviour (shipped file) ==\n";
$helper = realpath(__DIR__ . '/../func/bc-gateway.php');
if (!$helper) { fwrite(STDERR, "cannot find func/bc-gateway.php\n"); exit(1); }
require_once($helper);

// A clean JSON body is decoded as before.
$r = bc_gateway_json_decode('{"status":"success","ref":"DGV123"}');
bc_assert('clean JSON: status read', $r['status'] ?? null, 'success');
bc_assert('clean JSON: ref read', $r['ref'] ?? null, 'DGV123');

// A remote host with display_errors on wraps its JSON in deprecations — the old code saw this as
// "no response at all" and silently skipped every branch (no refund).
$noisy = "<br />\n<b>Deprecated</b>: Function curl_close() is deprecated in /home/x/func/api-gateway/cg-data-hdkdata-com.php on line 94<br />\n"
       . '{"status":"failed","desc":"Insufficient balance"}';
$r = bc_gateway_json_decode($noisy);
bc_assert('noise before JSON: status recovered', $r['status'] ?? null, 'failed');
bc_assert('noise before JSON: desc recovered', $r['desc'] ?? null, 'Insufficient balance');

$r = bc_gateway_json_decode(trim($noisy) . "\n<!-- trailing -->");
bc_assert('noise both sides: status recovered', $r['status'] ?? null, 'failed');

bc_assert('unparseable body returns empty array', bc_gateway_json_decode('502 Bad Gateway'), array());
bc_assert('empty body returns empty array', bc_gateway_json_decode(''), array());

// A transport error is only safe to fail when the request provably never left us.
bc_assert('DNS failure never sent the request -> failed', bc_gateway_curl_outcome(6), 'failed');
bc_assert('connection refused never sent the request -> failed', bc_gateway_curl_outcome(7), 'failed');
bc_assert('timeout may have been processed -> pending', bc_gateway_curl_outcome(28), 'pending');
bc_assert('empty reply may have been processed -> pending', bc_gateway_curl_outcome(52), 'pending');
bc_assert('receive error may have been processed -> pending', bc_gateway_curl_outcome(56), 'pending');

// The provider's own status word is the only verdict we accept.
bc_assert('provider "successful"', bc_gateway_provider_verdict(array('Status' => 'successful'), 'Status'), 'successful');
bc_assert('provider "Success" (mixed case)', bc_gateway_provider_verdict(array('Status' => ' Success '), 'Status'), 'successful');
bc_assert('provider "pending"', bc_gateway_provider_verdict(array('Status' => 'pending'), 'Status'), 'pending');
bc_assert('provider "failed"', bc_gateway_provider_verdict(array('Status' => 'failed'), 'Status'), 'failed');
bc_assert('lowercase status key', bc_gateway_provider_verdict(array('status' => 'failed')), 'failed');
bc_assert('an auth error body is NOT a verdict', bc_gateway_provider_verdict(array('detail' => 'Invalid token.'), 'Status'), null);
bc_assert('an empty body is NOT a verdict', bc_gateway_provider_verdict(array(), 'Status'), null);
bc_assert('a word we do not know is NOT a verdict', bc_gateway_provider_verdict(array('Status' => 'weird'), 'Status'), null);

// Only a definitive provider failure may be refunded.
$r = 'failed'; $t = 'failed';
bc_assert('definitive provider failure is refundable', bc_gateway_refund_is_safe($r, $t), true);
$r = 'failed'; $t = '';
bc_assert('a failure with no provider word is not refundable', bc_gateway_refund_is_safe($r, $t), false);
$r = 'failed'; $t = 1;
bc_assert('the transport sentinel is not refundable', bc_gateway_refund_is_safe($r, $t), false);
$r = 'pending'; $t = 'failed';
bc_assert('a pending outcome is never refundable', bc_gateway_refund_is_safe($r, $t), false);
$r = 'successful'; $t = 'successful';
bc_assert('a successful outcome is never refundable', bc_gateway_refund_is_safe($r, $t), false);

// An unsettled outcome must never survive - and must never become a refund on its own, because
// the provider debits its own side as soon as it accepts the request.
$api_response = null; $api_response_text = null; $api_response_description = null; $api_response_status = null;
$forced = bc_gateway_settle_purchase($api_response, $api_response_text, $api_response_description, $api_response_status, '', '');
bc_assert('NULL outcome is settled', $forced, true);
bc_assert('NULL outcome becomes pending (never a refund)', $api_response, 'pending');
bc_assert('NULL outcome gets status 2', $api_response_status, 2);
bc_assert_true('NULL outcome gets a description', strlen((string)$api_response_description) > 0);

// A provider-specific state the handler does not understand ("queued") is unsettled too.
$api_response = 'queued'; $api_response_text = ''; $api_response_description = ''; $api_response_status = 9;
bc_assert('unknown state is settled', bc_gateway_settle_purchase($api_response, $api_response_text, $api_response_description, $api_response_status, 'x', ''), true);
bc_assert('unknown state becomes pending by default', $api_response, 'pending');

// The explicit opt-in is still available for a caller that knows failure is the right default.
$api_response = 'queued'; $api_response_text = ''; $api_response_description = ''; $api_response_status = 9;
bc_gateway_settle_purchase($api_response, $api_response_text, $api_response_description, $api_response_status, 'x', '', 'failed');
bc_assert('unknown state can be forced to failed on request', $api_response, 'failed');

// A classified outcome is never overridden.
foreach (array('successful' => 1, 'pending' => 2, 'failed' => 3) as $state => $status_code) {
    $api_response = $state; $api_response_text = $state; $api_response_description = 'keep me'; $api_response_status = $status_code;
    $forced = bc_gateway_settle_purchase($api_response, $api_response_text, $api_response_description, $api_response_status, 'x', '');
    bc_assert("$state is left alone", $forced, false);
    bc_assert("$state keeps its status", $api_response_status, $status_code);
    bc_assert("$state keeps its description", $api_response_description, 'keep me');
}

echo "\n== B. shipped wiring (both editions) ==\n";
foreach ($ED as $edition => $root) {
    echo "-- $edition\n";

    // B1/B2: every reseller purchase gateway decodes tolerantly, settles, and never calls
    // curl_close() on a handle that the early "service not available" branches never created
    // (on PHP 8 that is a fatal TypeError -> the request dies BEFORE the refund code runs).
    $gateways = glob($root . '/func/api-gateway/*-localserver.php');
    bc_assert_true("$edition: reseller purchase gateways found (" . count($gateways) . ')', count($gateways) >= 12);
    $missing_decode = array(); $missing_settle = array(); $close_left = array();
    foreach ($gateways as $gw) {
        $src = file_get_contents($gw);
        if (strpos($src, 'bc_gateway_json_decode') === false) $missing_decode[] = basename($gw);
        if (strpos($src, 'bc_gateway_settle_purchase') === false) $missing_settle[] = basename($gw);
        if (preg_match('/\bcurl_close\s*\(/', $src)) $close_left[] = basename($gw);
    }
    bc_assert('unsettled-response bailout exists in every gateway', $missing_settle, array());
    bc_assert('tolerant decode used in every gateway', $missing_decode, array());
    bc_assert('no unconditional curl_close() left in gateways', $close_left, array());

    // B2b: the purchase handlers normalise the outcome right after the gateway include, so a
    // provider gateway *without* its own bailout still cannot leave the purchase unresolved, and
    // they refuse to refund anything that is not a definitive provider failure.
    $handlers = array('data.php', 'airtime.php', 'betting.php', 'cable.php', 'card.php', 'electric.php', 'exam.php', 'sms.php');
    $no_net = array();
    $no_gate = array();
    foreach ($handlers as $h) {
        $src = file_get_contents($root . '/web/func/' . $h);
        if (strpos($src, 'bc_gateway_settle_purchase') === false) $no_net[] = $h;
        if (strpos($src, 'bc_gateway_refund_is_safe') === false) $no_gate[] = $h;
    }
    bc_assert('purchase handlers settle the outcome', $no_net, array());
    bc_assert('purchase handlers only refund a definitive provider failure', $no_gate, array());

    // B2c: the HDK DATA gateways are the ones the wallet is debited at, so they must not fail a
    // purchase on a transport error or on a reply they cannot read.
    foreach (array('cg', 'sme', 'shared') as $service) {
        $buy = $root . '/func/api-gateway/' . $service . '-data-hdkdata-com.php';
        $src = file_get_contents($buy);
        bc_assert_true("$edition HDK/$service buy: uses the provider verdict", strpos($src, 'bc_gateway_provider_verdict') !== false);
        bc_assert_true("$edition HDK/$service buy: no blind catch-all failure", strpos($src, '!in_array($curl_json_result["Status"],array("successful","pending"))') === false);
        bc_assert_true("$edition HDK/$service buy: transport outcome classified", strpos($src, 'bc_gateway_curl_outcome') !== false);
        bc_assert_true("$edition HDK/$service buy: key normalised", strpos($src, 'str_ireplace("Token "') !== false);
        bc_assert_true("$edition HDK/$service buy: no unconditional curl_close", preg_match('/\bcurl_close\s*\(/', $src) === 0);

        $re = $root . '/func/api-gateway/requery/' . $service . '-data-hdkdata-com.php';
        $src = file_get_contents($re);
        bc_assert_true("$edition HDK/$service requery: uses the provider verdict", strpos($src, 'bc_gateway_provider_verdict') !== false);
        bc_assert_true("$edition HDK/$service requery: no blind catch-all failure", strpos($src, '!in_array($curl_json_result["Status"],array("successful","pending"))') === false);
        bc_assert_true("$edition HDK/$service requery: base url normalised", strpos($src, "preg_replace('#^https?://#'") !== false);
        bc_assert_true("$edition HDK/$service requery: key normalised", strpos($src, 'str_ireplace("Token "') !== false);
        bc_assert_true("$edition HDK/$service requery: double-space Token header gone", strpos($src, 'Token  "') === false);
        bc_assert_true("$edition HDK/$service requery: has a timeout", strpos($src, 'CURLOPT_TIMEOUT') !== false);
        bc_assert_true("$edition HDK/$service requery: no unconditional curl_close", preg_match('/\bcurl_close\s*\(/', $src) === 0);
    }

    // B3: a purchase the upstream has since reversed (status 1) must be refundable — the atomic
    // claim has to cover status 1 as well as a stuck pending 2, and only ever fire once.
    $requery = file_get_contents($root . '/web/func/requery-transaction.php');
    bc_assert_true("$edition: refund claim covers reversed successes", (bool)preg_match("/status IN \('2','1'\)/", $requery));
    bc_assert_true("$edition: refund claim stays atomic", strpos($requery, 'mysqli_affected_rows($connection_server) > 0') !== false);
    bc_assert_true("$edition: requery settles an unclassified reply", strpos($requery, 'bc_gateway_settle_purchase') !== false);
    bc_assert_true("$edition: requery only refunds a definitive failure", strpos($requery, 'bc_gateway_refund_is_safe') !== false);
    bc_assert_true("$edition: pending never downgrades a success", strpos($requery, 'Never downgrade a delivered purchase') !== false);

    // B4: "Account Refunded Already" means the transaction FAILED and was reversed. Reporting it
    // as "success" made the child re-mark its own customer's purchase successful.
    bc_assert_true(
        "$edition: already-refunded transaction reports failed",
        (bool)preg_match('/\$json_response_array = array\("status" => "failed", "desc" => "Account Refunded Already"\);/', $requery)
    );

    // B5: the queue rechecks recent SUCCESSFUL purchases through an upstream API, bounded by
    // requery_count, so a late reversal reaches this install instead of being invisible.
    $cron_file = ($edition === 'SAAS') ? '/cron/process_requery_queue.php' : '/automated-cron-requery.php';
    $cron = file_get_contents($root . $cron_file);
    bc_assert_true("$edition: queue rechecks successful purchases", strpos($cron, "status='1' AND api_id > 0 AND api_reference <> '' AND requery_count < 3") !== false);
    bc_assert_true("$edition: recheck of a success is counted", strpos($cron, 'requery_count = requery_count + 1') !== false);
    bc_assert_true("$edition: stuck pending still processed first", strpos($cron, "ORDER BY (status='2') DESC") !== false);

    // B6: the app/API requery path read its request payload with an inverted test, so
    // $get_api_post_info was a non-array and every requery answered "Incomplete Parameters".
    $api_requery = file_get_contents($root . '/web/api/requery.php');
    bc_assert_true("$edition: api/requery.php accepts an array payload", (bool)preg_match('/if \(isset\(\$api_post_info_from_app\) && is_array\(\$api_post_info_from_app\)\) \{/', $api_requery));
    bc_assert_true("$edition: inverted payload test gone", strpos($api_requery, 'isset($api_post_info_from_app) && !is_array($api_post_info_from_app)') === false);

    // B7: the recheck needs the column, and the schema gate must be re-run for it to be created.
    $tables = file_get_contents($root . '/func/bc-tables.php');
    bc_assert_true("$edition: requery_count column declared", strpos($tables, "ADD COLUMN requery_count INT UNSIGNED NOT NULL DEFAULT 0") !== false);
    // Read the declared version instead of hard-coding it, so a later bump for unrelated DDL does
    // not break this test - only a version OLDER than the one that adds requery_count must fail.
    preg_match("/BC_TABLES_VERSION',\s*'([^']+)'/", $tables, $bc_v);
    $declared = str_replace('-', '.', $bc_v[1] ?? '0');
    bc_assert_true("$edition: schema version is at least the one that adds requery_count (" . ($bc_v[1] ?? '?)') . ')', version_compare($declared, '2026.09.19.2', '>='));

    // B8: the helper has to be loaded everywhere a gateway can run.
    bc_assert_true("$edition: bc-gateway.php included by bootstrap", strpos(file_get_contents($root . '/func/bc-connect.php'), 'bc-gateway.php') !== false);
}

echo "\n----------------------------------------\n";
echo ($checks - $fails) . "/$checks checks passed" . ($fails ? " — $fails FAILED" : '') . "\n";
exit($fails ? 1 : 0);

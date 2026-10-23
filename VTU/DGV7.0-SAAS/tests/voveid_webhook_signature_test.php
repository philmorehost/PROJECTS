<?php
/**
 * VoveID webhook signature verification.
 *
 * Run with:  C:\xampp\php\php.exe -n tests/voveid_webhook_signature_test.php
 *
 * Why this exists: web/api/voveid-webhook.php changes a user's KYC status, and it applied ANY post it
 * received - the client's verifyWebhook() literally returned true and was never called. Anyone who
 * knew a refId (or a user id) could post {"status":"successful","refId":"<id>"} and self-approve.
 * The webhook now refuses anything it cannot prove came from VoveID.
 *
 * Section A runs the SHIPPED verifier against real HMAC vectors.
 * Section B asserts the endpoint cannot reach the status update without passing it.
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

echo "== A. the shipped verifier ==\n";
require_once(__DIR__ . '/../func/voveid-client.php');
bc_assert_true('verifier exists in the shipped client', function_exists('voveid_verify_webhook_signature'));

$secret = 'whsec_test_123';
$body   = '{"refId":"u-9","status":"successful","sessionId":"s-1"}';

bc_assert('hex digest of the body is accepted', voveid_verify_webhook_signature($secret, $body, hash_hmac('sha256', $body, $secret)), true);
bc_assert('base64 digest of the body is accepted', voveid_verify_webhook_signature($secret, $body, base64_encode(hash_hmac('sha256', $body, $secret, true))), true);
bc_assert('a "sha256=" prefix is accepted', voveid_verify_webhook_signature($secret, $body, 'sha256=' . hash_hmac('sha256', $body, $secret)), true);
bc_assert('surrounding whitespace is tolerated', voveid_verify_webhook_signature($secret, $body, '  ' . hash_hmac('sha256', $body, $secret) . "\n"), true);

// Some providers sign "timestamp.body" instead of the body alone.
$ts = (string)time();
bc_assert('timestamp.body scheme is accepted', voveid_verify_webhook_signature($secret, $body, hash_hmac('sha256', $ts . '.' . $body, $secret), $ts), true);

// The whole point: anything unproven must fail.
bc_assert('the wrong secret does not verify', voveid_verify_webhook_signature('whsec_other', $body, hash_hmac('sha256', $body, $secret)), false);
bc_assert('a tampered body does not verify', voveid_verify_webhook_signature($secret, $body . ' ', hash_hmac('sha256', $body, $secret)), false);
bc_assert('a signature for a different payload does not verify', voveid_verify_webhook_signature($secret, '{"status":"successful","refId":"u-1"}', hash_hmac('sha256', $body, $secret)), false);
bc_assert('a missing signature does not verify', voveid_verify_webhook_signature($secret, $body, ''), false);
bc_assert('an empty secret NEVER verifies', voveid_verify_webhook_signature('', $body, hash_hmac('sha256', $body, $secret)), false);
bc_assert('a null-ish secret never verifies', voveid_verify_webhook_signature(null, $body, hash_hmac('sha256', $body, '')), false);
bc_assert('a made-up signature does not verify', voveid_verify_webhook_signature($secret, $body, 'deadbeef'), false);

// Replay protection when the sender supplies a signing timestamp.
$old = (string)(time() - 3600);
bc_assert('a stale timestamp is refused', voveid_verify_webhook_signature($secret, $body, hash_hmac('sha256', $body, $secret), $old), false);
bc_assert('a stale timestamp.body signature is refused too', voveid_verify_webhook_signature($secret, $body, hash_hmac('sha256', $old . '.' . $body, $secret), $old), false);
bc_assert('no timestamp is still acceptable (body-only signing)', voveid_verify_webhook_signature($secret, $body, hash_hmac('sha256', $body, $secret), ''), true);

// Header extraction.
$_SERVER['HTTP_X_VOVEID_SIGNATURE'] = ' abc123 ';
$sig = voveid_webhook_signature_from_request();
bc_assert('signature header is read and trimmed', $sig[0], 'abc123');
unset($_SERVER['HTTP_X_VOVEID_SIGNATURE']);
$_SERVER['HTTP_X_SIGNATURE'] = 'fallback-sig';
$sig = voveid_webhook_signature_from_request();
bc_assert('alternate header names are accepted', $sig[0], 'fallback-sig');
unset($_SERVER['HTTP_X_SIGNATURE']);
bc_assert('no headers means no signature', voveid_webhook_signature_from_request(), array('', ''));

echo "\n== B. the endpoint cannot bypass verification ==\n";
foreach ($ED as $edition => $root) {
    echo "-- $edition\n";

    $hook = file_get_contents($root . '/web/api/voveid-webhook.php');
    $verify_at   = strpos($hook, 'voveid_verify_webhook_signature($secret, $payload, $signature, $timestamp)');
    $apply_at    = strpos($hook, '$result = voveid_process_webhook($data);');
    bc_assert_true("$edition: the endpoint verifies a signature", $verify_at !== false);
    bc_assert_true("$edition: the status update runs only after verification", $verify_at !== false && $apply_at !== false && $verify_at < $apply_at);
    bc_assert_true("$edition: a rejection path exists before the update", strpos($hook, 'function voveid_webhook_reject') !== false && strpos($hook, 'voveid_webhook_reject(') > strpos($hook, 'function voveid_webhook_reject'));
    bc_assert_true("$edition: it loads the vendor's secret", strpos($hook, 'voveid_webhook_secret($vendor_id)') !== false);
    bc_assert_true("$edition: it reads the signature from the request", strpos($hook, 'voveid_webhook_signature_from_request()') !== false);
    bc_assert_true("$edition: no secret means no action", strpos($hook, "if (\$secret === '')") !== false);
    bc_assert_true("$edition: rejections are logged with the caller", strpos($hook, 'REJECTED') !== false && strpos($hook, 'REMOTE_ADDR') !== false);

    $client = file_get_contents($root . '/func/voveid-client.php');
    bc_assert_true("$edition: the client no longer auto-passes webhooks", strpos($client, "// Implementation depends on VoveID's webhook signing method") === false);
    bc_assert_true("$edition: comparison is constant-time", strpos($client, 'hash_equals(') !== false);
    bc_assert_true("$edition: the client uses the key the settings page saves", strpos($client, "foreach (['voveid_api_key', 'voveid_secret_key', 'voveid_public_key']") !== false);
    bc_assert_true("$edition: a webhook secret loader exists", strpos($client, 'function voveid_webhook_secret(') !== false);

    // The VoveID settings card lives with the other KYC integrations (bc-admin/IdentityAPI.php), not in
    // the Account Settings security tab: everything a vendor can pay for is configured on one page.
    $identity_page = file_get_contents($root . '/bc-admin/IdentityAPI.php');
    bc_assert_true("$edition: IdentityAPI hosts the VoveID card", strpos($identity_page, 'VoveID Identity Verification (KYC)') !== false && strpos($identity_page, 'name="update-voveid-kyc"') !== false);
    bc_assert_true("$edition: IdentityAPI collects the API/secret key", strpos($identity_page, 'name="voveid_secret_key"') !== false);
    bc_assert_true("$edition: IdentityAPI saves all six settings", strpos($identity_page, "'voveid_secret_key'     => \$voveid_secret_key") !== false || strpos($identity_page, "'voveid_secret_key' => \$voveid_secret_key") !== false);
    bc_assert_true("$edition: IdentityAPI warns when the webhook secret is missing", strpos($identity_page, 'Automatic approval is off') !== false);
    bc_assert_true("$edition: the webhook secret is required, not optional", strpos($identity_page, 'VoveID Webhook Secret (Optional)') === false);

    $settings = file_get_contents($root . '/bc-admin/AccountSettings.php');
    // Assert on the form fields, not the words: the page keeps a one-line pointer comment naming VoveID.
    bc_assert_true("$edition: Account Settings no longer hosts the VoveID card", strpos($settings, 'name="voveid_enabled"') === false && strpos($settings, 'name="voveid_public_key"') === false);
    bc_assert_true("$edition: Account Settings no longer saves VoveID keys", strpos($settings, 'voveid_settings') === false && strpos($settings, 'voveid_secret_key') === false);
    bc_assert_true("$edition: it points at the new location instead", strpos($settings, 'configured in IdentityAPI.php') !== false);
}

echo "\n== C. editions stay in sync ==\n";
foreach (array('func/voveid-client.php', 'web/api/voveid-webhook.php') as $rel) {
    bc_assert("$rel is identical in both editions", md5_file($ED['SAAS'] . '/' . $rel), md5_file($ED['NON-SAAS'] . '/' . $rel));
}

echo "\n----------------------------------------\n";
echo ($checks - $fails) . "/$checks checks passed" . ($fails ? " — $fails FAILED" : '') . "\n";
exit($fails ? 1 : 0);

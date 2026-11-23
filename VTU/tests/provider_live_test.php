<?php
/**
 * DGV7 §26 behaviour tests — live provider endpoints wired from the official
 * docs (Klasha, Flutterwave, Platnova, 1GLOBAL Connect).
 *
 * Runs with `php -n` (no mysqli, no network): a minimal fake gateway table
 * feeds the REAL credential reader, and the HTTP transport seam captures every
 * request, so the shipped adapters are exercised end to end without a socket.
 *
 * Run: php -n VTU/tests/provider_live_test.php
 */

$root = dirname(__DIR__);
$checks = 0;
error_reporting(E_ALL);
ini_set('display_errors', '1');

function lcheck($label, $actual, $expected) {
    global $checks;
    $checks++;
    if ($actual !== $expected) {
        throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
    echo "ok $label\n";
}
function ltrue($label, $actual) { lcheck($label, (bool)$actual, true); }
function lfalse($label, $actual) { lcheck($label, (bool)$actual, false); }

// ─────────────────────────── minimal fake DB ───────────────────────────
// Only what bc_fintech_provider_credentials() runs: one SELECT by
// (table, vendor_id, gateway_name). Rows keyed "table:vendor:gateway".
//
// The shipped sources are loaded through the same eval shim as
// fintech_gate_test.php: `instanceof mysqli` / `mysqli_*` are rewritten to
// LiveFakeDb / fake_mysqli_*. Declaring a real `class mysqli` or `mysqli_*`
// function here would collide with the loaded extension when CI syntax-checks
// this file with extensions enabled (php -l), and would fatally redeclare
// under any runtime that has mysqli loaded.
class LiveFakeDb {}
class LiveFakeResult implements Countable {
    public $rows; public $pos = 0;
    public function __construct(array $rows) { $this->rows = array_values($rows); }
    public function count(): int { return count($this->rows); }
}
$GLOBALS['db'] = ['gateways' => [], 'options' => []];
$connection_server = new LiveFakeDb();

function fake_mysqli_real_escape_string($db, $value) { return addslashes((string)$value); }
function fake_mysqli_query($db, $sql) {
    if (preg_match("/FROM `([a-z0-9_]+)` WHERE vendor_id='(\\d+)' AND gateway_name='([^']*)'/", (string)$sql, $m)) {
        $row = $GLOBALS['db']['gateways'][$m[1] . ':' . $m[2] . ':' . $m[3]] ?? null;
        return new LiveFakeResult($row ? [$row] : []);
    }
    return new LiveFakeResult([]);
}
function fake_mysqli_num_rows($result) { return $result instanceof LiveFakeResult ? count($result) : 0; }
function fake_mysqli_fetch_assoc($result) {
    if ($result instanceof LiveFakeResult) {
        if (!isset($result->rows[$result->pos])) return null;
        return $result->rows[$result->pos++];
    }
    return null;
}
function getVendorOption($vendor_id, $name, $default = '') {
    return $GLOBALS['db']['options'][(int)$vendor_id . ':' . $name] ?? $default;
}

// Explicit entitlement/storage boundaries for the adapter-only fixture.
// Real entitlement SQL and encrypted storage have separate native MySQL tests.
$GLOBALS['live_klasha_entitled'] = false;
$GLOBALS['live_klasha_saved'] = null;
function bc_gateway_api_entitled($db,$vendor,$slug) { return $slug === 'klasha' && $GLOBALS['live_klasha_entitled']; }
function bc_gateway_api_read($db,$vendor,$slug) { return $GLOBALS['live_klasha_saved']; }

/** Loads one shipped source with the fake DB (see the note above). */
function live_load($path) {
    $source = file_get_contents($path);
    if ($source === false) throw new RuntimeException('Cannot read ' . $path);
    $source = preg_replace('/^<\\?php/', '', $source, 1);
    $source = preg_replace("#\\s*require_once\\s*\\(?\\s*__DIR__\\s*\\.\\s*'/bc-[a-z0-9-]+\\.php'\\s*\\)?\\s*;#", '', $source);
    $source = str_replace(['instanceof mysqli', 'mysqli_'], ['instanceof LiveFakeDb', 'fake_mysqli_'], $source);
    eval($source);
}

foreach ([
    'func/bc-fintech-providers.php',
    'func/bc-fintech-klasha.php',
    'func/bc-fintech-flutterwave.php',
    'func/bc-fintech-platnova.php',
    'func/bc-fintech-oneglobal.php',
    'func/bc-virtualcard-flutterwave.php',
] as $live_file) {
    live_load($root . '/DGV7.0-SAAS/' . $live_file);
}

// ─────────────────────────── registry (documented values) ───────────────────────────
$registry = bc_fintech_provider_registry();
lcheck('four providers registered', bc_fintech_provider_list(), ['klasha', 'flutterwave', 'platnova', 'oneglobal']);
lcheck('platnova base URL', $registry['platnova']['base_url'], 'https://api.platnova.com');
lcheck('platnova auth is the X-API-Key header only', $registry['platnova']['auth'], ['header' => 'X-API-Key', 'prefix' => '']);
lcheck('platnova payments endpoint', $registry['platnova']['endpoints']['payments_send'], '/v1/payments/send');
lcheck('platnova cards endpoint', $registry['platnova']['endpoints']['cards_create'], '/v1/cards/create');
lcheck('platnova partner esim plans endpoint', $registry['platnova']['endpoints']['esim_plans'], '/v1/partner/esim/plans');
lcheck('oneglobal base URL', $registry['oneglobal']['base_url'], 'https://services.truphone.com/connect-api/v2');
lcheck('oneglobal orders endpoint', $registry['oneglobal']['endpoints']['orders'], '/orders');
lcheck('oneglobal catalogue stays unconfirmed', $registry['oneglobal']['endpoints']['catalogue'], '');
lcheck('klasha login endpoint', $registry['klasha']['endpoints']['login'], '/auth/account/v2/login');
lcheck('klasha CNY payout path carries the businessId segment', $registry['klasha']['endpoints']['cny_payout'], '/wallet/merchant/{businessId}/bank/transfer/v2/request');

// ─────────────────────────── URL builder placeholders ───────────────────────────
lcheck('flutterwave bill purchase URL substitutes path params',
    bc_fintech_provider_url('flutterwave', 'bills', 0, ['biller_code' => 'BIL123', 'item_code' => 'ITM9'])['url'],
    'https://api.flutterwave.com/v3/billers/BIL123/items/ITM9/payment');
lcheck('a missing path param refuses instead of calling a literal brace URL',
    bc_fintech_provider_url('flutterwave', 'bills', 0, ['biller_code' => 'BIL123'])['reason'],
    'endpoint_parameter_missing');
lcheck('platnova banks URL substitutes the country',
    bc_fintech_provider_url('platnova', 'banks', 0, ['country' => 'NG'])['url'],
    'https://api.platnova.com/v1/banks/NG');

// ─────────────────────────── documented ciphers ───────────────────────────
// Known-answer vectors generated with an independent implementation of the
// same documented algorithms (OpenSSL EVP_BytesToKey/MD5; 3DES per Klasha's
// official PHP snippet). Where the runtime has no OpenSSL (the local wasm
// harness), the ciphers must refuse cleanly instead — CI's PHP has OpenSSL
// and exercises the vectors for real.
$has_openssl = function_exists('openssl_encrypt') && function_exists('openssl_decrypt');
if ($has_openssl) {
    lcheck('AES OpenSSL-salted decrypt matches the known-answer vector',
        bc_klasha_decrypt_openssl_aes('U2FsdGVkX18xMjM0NTY3OB3LEbtc2R7+0R4VK/xKvUM=', 'klasha-test-key'),
        'Hello Klasha!');
    $aes = bc_klasha_encrypt_openssl_aes('{"amount":100}', 'klasha-test-key');
    ltrue('AES ciphertext carries the Salted__ prefix', strpos((string)base64_decode((string)$aes, true), 'Salted__') === 0);
    lcheck('AES round-trips', bc_klasha_decrypt_openssl_aes($aes, 'klasha-test-key'), '{"amount":100}');
    lcheck('a wrong password does not decrypt', bc_klasha_decrypt_openssl_aes($aes, 'wrong-key') === '{"amount":100}', false);
} else {
    lcheck('AES refuses without OpenSSL', bc_klasha_encrypt_openssl_aes('x', 'k'), '');
    lcheck('AES decrypt refuses without OpenSSL', bc_klasha_decrypt_openssl_aes('U2FsdGVkX18xMjM0NTY3OA==', 'k'), '');
}
if ($has_openssl && in_array('des-ede3-cbc', openssl_get_cipher_methods(), true)) {
    lcheck('3DES decrypt matches the known-answer vector',
        bc_klasha_decrypt_3des('d7GWdByn73P80oLM8P4JZw==', '123456789012345678901234'),
        '{"amount":100}');
    lcheck('3DES round-trips',
        bc_klasha_decrypt_3des(bc_klasha_encrypt_3des('{"amount":100}', '123456789012345678901234'), '123456789012345678901234'),
        '{"amount":100}');
} else {
    // OpenSSL 3 without the legacy provider: the cipher must refuse, never
    // fall back to plaintext.
    lcheck('3DES refuses when the cipher is unavailable', bc_klasha_encrypt_3des('x', '123456789012345678901234'), '');
}
lcheck('an undocumented scheme refuses to encrypt', bc_klasha_payload_encrypt('{"a":1}', 'some-key', 'rot13'), '');
lcheck('the one-argument seam stays TLS-only', bc_klasha_payload_encrypt('{"a":1}'), '{"a":1}');

// ─────────────────────────── Klasha login + authenticated calls ───────────────────────────
$GLOBALS['db']['gateways']['sas_bank_transfer_gateways:8:klasha'] = [
    'vendor_id' => 8, 'gateway_name' => 'klasha', 'public_key' => 'pubkey',
    'secret_key' => 'pw123', 'encrypt_key' => 'klasha-test-key', 'status' => 1,
];
$GLOBALS['db']['options']['8:klasha_login_email'] = 'merchant@example.com';
$GLOBALS['db']['options']['8:klasha_business_id'] = '999';

$calls = [];
$GLOBALS['bc_fintech_http_transport'] = function ($provider, $method, $url, $headers, $payload) use (&$calls) {
    $calls[] = ['provider' => $provider, 'method' => $method, 'url' => $url, 'headers' => $headers, 'payload' => $payload];
    if (strpos($url, '/auth/account/v2/login') !== false) {
        return ['ok' => true, 'status' => 200, 'body' => '{}', 'json' => ['data' => ['token' => 'eyJfresh']], 'error' => ''];
    }
    return ['ok' => true, 'status' => 200, 'body' => '{}', 'json' => ['data' => ['id' => 472]], 'error' => ''];
};
unset($GLOBALS['bc_klasha_bearer_tokens']);
lcheck('unentitled Klasha refuses login', bc_klasha_bearer_token(8), '');
lcheck('unentitled Klasha makes no HTTP calls', count($calls), 0);
$GLOBALS['live_klasha_saved'] = ['public_key'=>'sealed-public-fixture','secret_key'=>'sealed-secret-fixture'];
lfalse('unentitled Klasha cannot expose saved credentials', bc_fintech_provider_credentials(8,'klasha')['found']);
$GLOBALS['live_klasha_entitled'] = true;
lcheck('entitled Klasha prefers encrypted storage boundary', bc_fintech_provider_credentials(8,'klasha')['public_key'], 'sealed-public-fixture');
lcheck('entitled Klasha marks encrypted credential source', bc_fintech_provider_credentials(8,'klasha')['source'], 'sas_gateway_api_credentials');
$GLOBALS['live_klasha_saved'] = null; // Exercise the entitled legacy-credential compatibility path below.
lcheck('klasha login exchanges email + password for a token', bc_klasha_bearer_token(8), 'eyJfresh');
lcheck('the login token is cached per request', bc_klasha_bearer_token(8), 'eyJfresh');
lcheck('exactly one login call was made', count($calls), 1);
lcheck('login posts the documented credentials', $calls[0]['payload'], ['username' => 'merchant@example.com', 'password' => 'pw123']);
lcheck('login sends the merchant public key as x-auth-token', $calls[0]['headers']['x-auth-token'], 'pubkey');

if ($has_openssl) {
    $calls = [];
    $quote = bc_klasha_cny_quote(8, ['service' => 'WALLET', 'serviceCode' => 'ALIPAY', 'destinationCurrency' => 'CNY', 'destinationAmount' => '10']);
    ltrue('klasha CNY quote succeeds through the seam', $quote['ok']);
    lcheck('CNY quote URL', $calls[0]['url'], 'https://gate.klasapps.com/pay/wallet/merchant/quotation/v2');
    lcheck('CNY quote carries the Bearer token', $calls[0]['headers']['Authorization'], 'Bearer eyJfresh');
    lcheck('CNY quote carries x-auth-token', $calls[0]['headers']['x-auth-token'], 'pubkey');
    $sent = json_decode((string)bc_klasha_decrypt_openssl_aes((string)$calls[0]['payload']['message'], 'klasha-test-key'), true);
    lcheck('CNY quote body is the encrypted documented payload', $sent['serviceCode'], 'ALIPAY');

    $calls = [];
    $payout = bc_klasha_cny_payout(8, ['quotationId' => 472, 'requestId' => 'REQ-1', 'accountNumber' => '8613699262597']);
    ltrue('klasha CNY payout succeeds through the seam', $payout['ok']);
    lcheck('CNY payout URL takes businessId from the vendor option', $calls[0]['url'], 'https://gate.klasapps.com/pay/wallet/merchant/999/bank/transfer/v2/request');
    $sent = json_decode((string)bc_klasha_decrypt_openssl_aes((string)$calls[0]['payload']['message'], 'klasha-test-key'), true);
    lcheck('businessId travels in the URL, not the body', isset($sent['businessId']), false);
    lcheck('CNY payout body keeps the quotation id', $sent['quotationId'], 472);
} else {
    // No OpenSSL in this runtime: encrypted wallet calls must refuse BEFORE
    // any network activity rather than send plaintext.
    $calls = [];
    $quote = bc_klasha_cny_quote(8, ['service' => 'WALLET', 'serviceCode' => 'ALIPAY']);
    lcheck('encrypted klasha calls refuse without OpenSSL', $quote['reason'], 'encryption_unavailable');
    lcheck('the refusal happens before any HTTP call', count($calls), 0);
}

// A stored JWT is used as-is: no login round-trip.
$GLOBALS['db']['gateways']['sas_bank_transfer_gateways:9:klasha'] = [
    'vendor_id' => 9, 'gateway_name' => 'klasha', 'public_key' => 'pub9',
    'secret_key' => 'eyJ-stored-bearer-token', 'encrypt_key' => 'k', 'status' => 1,
];
unset($GLOBALS['bc_klasha_bearer_tokens']);
lcheck('a stored JWT is used without logging in', bc_klasha_bearer_token(9), 'eyJ-stored-bearer-token');

// ─────────────────────────── Platnova calls ───────────────────────────
$GLOBALS['db']['gateways']['sas_payment_gateways:8:platnova'] = [
    'vendor_id' => 8, 'gateway_name' => 'platnova', 'public_key' => '',
    'secret_key' => 'pn_live_key', 'encrypt_key' => '', 'status' => 1,
];
$calls = [];
$GLOBALS['bc_fintech_http_transport'] = function ($provider, $method, $url, $headers, $payload) use (&$calls) {
    $calls[] = ['provider' => $provider, 'method' => $method, 'url' => $url, 'headers' => $headers, 'payload' => $payload];
    return ['ok' => true, 'status' => 200, 'body' => '{}', 'json' => [['plan_id' => 'esim_1GB_7D_GB_V2', 'amount_minor' => 650000]], 'error' => ''];
};
$plans = bc_platnova_esim_plans(8, 'GB');
ltrue('platnova esim plans call succeeds', $plans['ok']);
lcheck('platnova plans URL', $calls[0]['url'], 'https://api.platnova.com/v1/partner/esim/plans');
lcheck('platnova sends X-API-Key', $calls[0]['headers']['X-API-Key'], 'pn_live_key');
lcheck('platnova never sends a Bearer key', isset($calls[0]['headers']['Authorization']), false);
lcheck('platnova plan query travels as payload', $calls[0]['payload'], ['country' => 'GB']);

$calls = [];
$order = bc_platnova_esim_order(8, ['partner_reference' => 'SVC-ESI-1', 'plan_id' => 'esim_1GB_7D_GB_V2', 'amount_minor' => 650000]);
ltrue('platnova esim order succeeds', $order['ok']);
lcheck('esim order is idempotent by partner_reference', $calls[0]['headers']['Idempotency-Key'], 'SVC-ESI-1');
lcheck('esim order refuses without the documented fields', bc_platnova_esim_order(8, ['plan_id' => 'x'])['reason'], 'missing_payload');
lfalse('platnova refuses with no stored key', bc_platnova_esim_plans(7)['ok']);
lcheck('platnova refusal reason without credentials', bc_platnova_esim_plans(7)['reason'], 'credentials_not_configured');

// ─────────────────────────── 1GLOBAL calls ───────────────────────────
$GLOBALS['db']['gateways']['sas_payment_gateways:8:oneglobal'] = [
    'vendor_id' => 8, 'gateway_name' => 'oneglobal', 'public_key' => '',
    'secret_key' => 'oauth-token', 'encrypt_key' => '', 'status' => 1,
];
$calls = [];
$GLOBALS['bc_fintech_http_transport'] = function ($provider, $method, $url, $headers, $payload) use (&$calls) {
    $calls[] = ['provider' => $provider, 'method' => $method, 'url' => $url, 'headers' => $headers, 'payload' => $payload];
    return ['ok' => true, 'status' => 201, 'body' => '{}', 'json' => ['id' => 'ord-1', 'status' => 'ACCEPTED'], 'error' => ''];
};
$created = bc_oneglobal_create_order(8, ['product_id' => 'prod-9', 'email' => 'john@doe.com', 'country' => 'GB']);
ltrue('1global order succeeds', $created['ok']);
lcheck('1global order URL', $calls[0]['url'], 'https://services.truphone.com/connect-api/v2/orders');
lcheck('1global sends the OAuth Bearer token', $calls[0]['headers']['Authorization'], 'Bearer oauth-token');
ltrue('1global sends a correlation id', !empty($calls[0]['headers']['X-Correlation-ID']));
lcheck('1global order body follows the recipe', $calls[0]['payload']['operation_type'], 'ACTIVATION');
lcheck('1global order refuses without product + email', bc_oneglobal_create_order(8, ['email' => 'a@b.c'])['reason'], 'missing_payload');

// The documented completed-order shape yields install credentials.
$details = bc_oneglobal_order_details([
    'status' => 'COMPLETED',
    'output' => ['matching_id' => '6C-DA326D-K86D', 'iccid' => '8944000013283712983321', 'subscription_id' => 'sub-1'],
    '_metadata' => ['links' => [
        '_lpa' => '1$rsp.truphone.com$6C-DA326D-K86D',
        '_qrcode' => 'https://services.truphone.com/connect/qrcode/v1/esim/LPA:1$rsp.truphone.com$6C-DA326D-K86D',
        '_smdp' => 'rsp.truphone.com',
    ]],
]);
lcheck('order details expose the ICCID', $details['iccid'], '8944000013283712983321');
lcheck('order details build the LPA install string', $details['lpa'], 'LPA:1$rsp.truphone.com$6C-DA326D-K86D');
lcheck('order details expose the QR image URL', $details['qrcode'], 'https://services.truphone.com/connect/qrcode/v1/esim/LPA:1$rsp.truphone.com$6C-DA326D-K86D');
lcheck('an unfinished order yields no credentials', bc_oneglobal_order_details(['status' => 'ACCEPTED'])['lpa'], null);

// ─────────────────────────── fail-closed stays fail-closed ───────────────────────────
$GLOBALS['db']['gateways']['sas_payment_gateways:8:flutterwave'] = [
    'vendor_id' => 8, 'gateway_name' => 'flutterwave', 'public_key' => 'pk',
    'secret_key' => 'sk', 'encrypt_key' => 'hash', 'status' => 1,
];
$card = bc_vc_flw_call(8, 'virtual_cards', 'POST', ['currency' => 'USD']);
lcheck('flutterwave virtual cards remain held for secure settlement', $card['reason'], 'security_review_required');
$pos = bc_flw_request_pos(8, ['business' => 'Shop']);
lcheck('flutterwave POS requests still refuse (not documented)', $pos['reason'], 'endpoint_not_configured');

// ─────────────────────────── page/admin wiring (both editions) ───────────────────────────
foreach (['DGV7.0-SAAS', 'DGV7.0-NON-SAAS'] as $ed) {
    $esim = file_get_contents($root . '/' . $ed . '/web/ESIM.php');
    ltrue("$ed ESIM page lists live Platnova plans when configured", strpos($esim, 'bc_platnova_esim_plans') !== false);
    $intl = file_get_contents($root . '/' . $ed . '/web/IntlTransfer.php');
    ltrue("$ed IntlTransfer shows a live Klasha quote", strpos($intl, 'bc_klasha_fx_quote') !== false);
    ltrue("$ed IntlTransfer shows live Flutterwave transfer rates", strpos($intl, 'transfer_rates') !== false);
    $sc = file_get_contents($root . '/' . $ed . '/bc-admin/ServiceControl.php');
    ltrue("$ed ServiceControl saves provider API credentials", strpos($sc, 'save-provider-credentials') !== false);
    ltrue("$ed ServiceControl stores the Klasha login helpers", strpos($sc, 'klasha_login_email') !== false && strpos($sc, 'klasha_business_id') !== false);
    foreach (['func/bc-fintech-providers.php', 'func/bc-fintech-klasha.php', 'func/bc-fintech-flutterwave.php', 'func/bc-fintech-platnova.php', 'func/bc-fintech-oneglobal.php', 'func/bc-virtualcard-flutterwave.php'] as $f) {
        lcheck("$ed parity: $f", md5_file($root . '/DGV7.0-SAAS/' . $f), md5_file($root . '/' . $ed . '/' . $f));
    }
}

echo "provider_live_test: $checks checks passed\n";

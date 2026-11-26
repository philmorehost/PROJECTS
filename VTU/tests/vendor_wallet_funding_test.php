<?php
require_once __DIR__ . '/../DGV7.0-SAAS/func/bc-payment-gateway-settlement.php';

$n = 0;
function vcheck($ok, $msg) {
    global $n;
    if (!$ok) {
        throw new RuntimeException($msg);
    }
    $n++;
}

// 1. Platform markup clamp [0, 10] and fee calculation parity.
vcheck(bc_pg_clamp_platform_markup(null, 0.0) === 0.0, 'empty platform markup defaults to 0');
vcheck(bc_pg_clamp_platform_markup('-5', 0.0) === 0.0, 'negative platform markup clamped to 0');
vcheck(bc_pg_clamp_platform_markup('2.5', 0.0) === 2.5, 'platform markup above 1% preserved up to 10%');
vcheck(bc_pg_clamp_platform_markup('25', 0.0) === 10.0, 'platform markup capped at 10%');

$bd_vendor = bc_pg_calculate_fee_breakdown(null, 'paystack', 10000.0, 2.0, 'customer_pays', 10.0);
vcheck(abs($bd_vendor['wallet_credit'] - 10000.0) < 0.01, 'vendor customer_pays preserves target credit');
vcheck(abs($bd_vendor['vendor_markup'] - 200.0) < 0.01, 'vendor markup 2% applied when max_markup=10');
vcheck($bd_vendor['charge_amount'] > 10200.0, 'vendor charge_amount covers gateway fee + platform markup');

$bd_user = bc_pg_calculate_fee_breakdown(null, 'paystack', 10000.0, 2.0, 'customer_pays');
vcheck(abs($bd_user['vendor_markup'] - 100.0) < 0.01, 'user fee breakdown still caps markup at 1% by default');

$settled_vendor = bc_pg_resolve_settlement_amounts(
    null,
    'paystack',
    10456.85,
    2.0,
    ['target' => 'vendor', 'credit_amount' => 10000.0, 'amount' => 10456.85, 'fee_mode' => 'customer_pays', 'vendor_markup' => 200.0],
    ['target' => 'vendor', 'credit_amount' => 10000.0, 'fee_on_top' => 1],
    0.0
);
vcheck(abs($settled_vendor['credit_amount'] - 10000.0) < 0.01, 'vendor settlement resolves exact credit amount');
vcheck(abs($settled_vendor['vendor_markup'] - 200.0) < 0.01, 'vendor settlement preserves intent markup');

$settled_deduct = bc_pg_resolve_settlement_amounts(null, 'payvessel', 10000.0, 2.0, null, ['target' => 'vendor'], 9950.0);
vcheck(abs($settled_deduct['vendor_markup'] - 199.0) < 0.01, 'vendor deduct settlement uses platform markup clamp up to 10%');
vcheck(abs($settled_deduct['credit_amount'] - 9751.0) < 0.01, 'vendor deduct settlement subtracts platform markup from settled');

// 2. Fail-closed checks on vendor checkout / settlement helpers.
$r_inactive = bc_pg_init_vendor_gateway_checkout(null, ['id' => 5, 'status' => 2], 'paystack', 5000);
vcheck(empty($r_inactive['ok']) && ($r_inactive['code'] ?? '') === 'UNAUTHENTICATED', 'inactive vendor rejected');

$r_bad_gw = bc_pg_init_vendor_gateway_checkout(null, ['id' => 5, 'status' => 1], 'unknown_gw', 5000);
vcheck(empty($r_bad_gw['ok']) && ($r_bad_gw['code'] ?? '') === 'UNSUPPORTED_GATEWAY', 'unsupported vendor gateway rejected');

$r_bad_settle = bc_pg_verify_and_settle_vendor(null, 0, 'paystack', 'bad');
vcheck(empty($r_bad_settle['ok']), 'verify_and_settle_vendor rejects missing DB/invalid vendor');

$r_bad_credit = bc_pg_settle_vendor_credit(null, 0, 'paystack', '', '', 0, 0);
vcheck(empty($r_bad_credit['ok']), 'settle_vendor_credit rejects invalid payload');

// 3. bc-admin/Fund.php contract checks.
$saas = __DIR__ . '/../DGV7.0-SAAS';
$non_saas = __DIR__ . '/../DGV7.0-NON-SAAS';

$admin_fund = file_get_contents($saas . '/bc-admin/Fund.php');
vcheck($admin_fund !== false, 'bc-admin/Fund.php exists');
vcheck(strpos($admin_fund, 'bc_pg_list_platform_gateways(') !== false, 'bc-admin/Fund.php lists platform gateways');
vcheck(strpos($admin_fund, 'bc_pg_init_vendor_gateway_checkout(') !== false, 'bc-admin/Fund.php calls server checkout init');
vcheck(strpos($admin_fund, 'bc_pg_verify_and_settle_vendor(') !== false, 'bc-admin/Fund.php verifies returning payments');
vcheck(strpos($admin_fund, 'name="_csrf_token"') !== false && strpos($admin_fund, 'hash_equals(') !== false, 'bc-admin/Fund.php validates CSRF');
vcheck(strpos($admin_fund, 'id="fundProceedBtn" type="submit"') !== false, 'CONTINUE TO PAYMENT is a real submit button');
vcheck(strpos($admin_fund, 'bc-wallet-funding.js') !== false, 'bc-admin/Fund.php loads bc-wallet-funding.js');
vcheck(strpos($admin_fund, 'Cache-Control: no-store') !== false, 'bc-admin/Fund.php sets no-store header');
foreach (['vtickPaymentGateway', 'vcheckPaymentGatewayDetails', 'random-vpaid.php', 'pointer-events: none', 'sdk.monnify.com', 'checkout.flutterwave.com', 'js.paystack.co'] as $legacy) {
    vcheck(strpos($admin_fund, $legacy) === false, 'bc-admin/Fund.php removes broken/legacy wiring: ' . $legacy);
}

// 4. bc-admin/payhub-success.php checks.
$admin_ph = file_get_contents($saas . '/bc-admin/payhub-success.php');
vcheck($admin_ph !== false, 'bc-admin/payhub-success.php exists');
vcheck(strpos($admin_ph, 'bc_pg_verify_and_settle_vendor(') !== false, 'bc-admin/payhub-success.php settles via bc_pg_verify_and_settle_vendor');
vcheck(strpos($admin_ph, 'processPayhubSuccess(') === false, 'bc-admin/payhub-success.php no longer calls retired processPayhubSuccess');
vcheck(strpos($admin_ph, 'cdn.jsdelivr.net') === false, 'bc-admin/payhub-success.php uses local SweetAlert2');
vcheck(strpos($admin_ph, '../assets-2/vendor/sweetalert2/sweetalert2.all.min.js') !== false, 'bc-admin/payhub-success.php loads local SweetAlert2');

// 5. bc-admin/PaymentGateway.php initiatePayoutActivation check.
$pg_page = file_get_contents($saas . '/bc-admin/PaymentGateway.php');
vcheck(strpos($pg_page, 'function initiatePayoutActivation(') !== false, 'PaymentGateway.php defines initiatePayoutActivation');
vcheck(strpos($pg_page, 'Fund.php?purpose=payout_activation') !== false, 'PaymentGateway.php redirects to Fund.php?purpose=payout_activation');
vcheck(strpos($pg_page, 'finance-ajax.php') === false, 'PaymentGateway.php does not call paused finance-ajax.php');

// 6. Vendor webhook routes & target isolation checks.
$vendor_routes = [
    'vendors-paystack.php' => 'bc_pg_handle_vendor_paystack_webhook',
    'vendors-flutterwave.php' => 'bc_pg_handle_vendor_flutterwave_webhook',
    'vendors-monnify.php' => 'bc_pg_handle_vendor_monnify_webhook',
    'vendors-payvessel.php' => 'bc_pg_handle_vendor_payvessel_webhook',
    'vendors-payhub.php' => 'bc_pg_try_handle_payhub_checkout_webhook',
];
foreach ($vendor_routes as $route => $handler) {
    $src = file_get_contents($saas . '/' . $route);
    vcheck($src !== false && strpos($src, $handler) !== false, "$route invokes $handler");
    vcheck(strpos($src, 'LEGACY_SETTLEMENT_HOLD') === false, "$route no longer returns LEGACY_SETTLEMENT_HOLD");
}

$settlement_src = file_get_contents($saas . '/func/bc-payment-gateway-settlement.php');
vcheck(strpos($settlement_src, 'bc_pg_payhub_base_url(') !== false, 'PayHub verification supports direct HTTP fallback without bc-func.php');
vcheck(strpos($settlement_src, "(\$intent['target'] ?? 'user') !== 'user'") !== false, 'user settlement/webhooks isolate against vendor intents');
vcheck(strpos($settlement_src, "(\$intent['target'] ?? 'user') !== 'vendor'") !== false, 'vendor settlement/webhooks isolate against user intents');

// 7. Edition parity for shared settlement + JS files.
foreach (['func/bc-payment-gateway-settlement.php', 'jsfile/bc-wallet-funding.js'] as $rel) {
    vcheck(file_get_contents($saas . '/' . $rel) === file_get_contents($non_saas . '/' . $rel), "edition parity for $rel");
}

// 8. HTTP method guard execution for vendor webhook handlers (GET -> 405).
foreach ([
    'bc_pg_handle_vendor_paystack_webhook',
    'bc_pg_handle_vendor_flutterwave_webhook',
    'bc_pg_handle_vendor_monnify_webhook',
    'bc_pg_handle_vendor_payvessel_webhook',
] as $fn) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    http_response_code(200);
    ob_start();
    $fn(null);
    $out = json_decode(ob_get_clean(), true);
    vcheck(http_response_code() === 405 && ($out['code'] ?? '') === 'METHOD_NOT_ALLOWED', "$fn rejects GET with 405");
}

echo "PASS $n vendor wallet funding checks\n";

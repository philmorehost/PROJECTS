<?php
/**
 * Regression test covering:
 *  1. Atomic manual deposit & fund-transfer approvals (503 pause removed, CAS, idempotency keys, PIN-only reject)
 *  2. Super-admin Transactions / UserTransactions / receipt SQL schema fix (no v.business_name)
 *  3. Apps API / Web API / Legacy app-backend / APIDocs hardening across SAAS and NON-SAAS
 */

$passed = 0;
function check_rem(bool $ok, string $msg): void {
    global $passed;
    if (!$ok) {
        fwrite(STDERR, "FAIL: $msg\n");
        exit(1);
    }
    $passed++;
}

$root = dirname(__DIR__);
$editions = ['DGV7.0-SAAS', 'DGV7.0-NON-SAAS'];

// ── 1. Manual deposit & fund-transfer approvals ─────────────────────────────
$approvalFiles = [
    'DGV7.0-SAAS/bc-spadmin/PaymentOrders.php' => ['VPO_', 'vendor_payment_approve', 'sec30_verify_spadmin_pin_only'],
    'DGV7.0-SAAS/bc-admin/PaymentOrders.php' => ['POAPP_', 'payment_order_approve', 'sec29_verify_admin_pin_only'],
    'DGV7.0-NON-SAAS/bc-admin/PaymentOrders.php' => ['POAPP_', 'payment_order_approve', 'sec29_verify_admin_pin_only'],
    'DGV7.0-SAAS/bc-admin/FundTransferRequests.php' => ['FTAPR_', 'fund_transfer_approve', 'sec29_verify_admin_pin_only'],
    'DGV7.0-NON-SAAS/bc-admin/FundTransferRequests.php' => ['FTAPR_', 'fund_transfer_approve', 'sec29_verify_admin_pin_only'],
];
foreach ($approvalFiles as $rel => [$keyPrefix, $stepupCtx, $pinHelper]) {
    $src = file_get_contents("$root/$rel");
    check_rem($src !== false, "$rel readable");
    check_rem(strpos($src, 'http_response_code(503)') === false, "$rel has no 503 pause");
    check_rem(strpos($src, "AND status='2'") !== false && strpos($src, 'mysqli_affected_rows($connection_server)') !== false, "$rel uses atomic CAS");
    check_rem(strpos($src, $keyPrefix) !== false, "$rel uses deterministic reference prefix $keyPrefix");
    check_rem(strpos($src, $stepupCtx) !== false, "$rel requires step-up context $stepupCtx");
    check_rem(strpos($src, $pinHelper) !== false, "$rel uses rate-limited PIN-only reject helper");
    check_rem(strpos($src, 'pin_check_only') === false, "$rel does not pass ignored pin_check_only");
}

// ── 2. v.business_name removed from sas_vendors queries ─────────────────────
foreach ([
    'DGV7.0-SAAS/bc-spadmin/Transactions.php',
    'DGV7.0-SAAS/bc-spadmin/UserTransactions.php',
    'DGV7.0-SAAS/func/bc-transaction-receipt.php',
    'DGV7.0-NON-SAAS/func/bc-transaction-receipt.php',
] as $rel) {
    $src = file_get_contents("$root/$rel");
    check_rem($src !== false && strpos($src, 'v.business_name') === false, "$rel has no invalid v.business_name");
}

// ── 3. Fintech gate parity & runtime behaviour ──────────────────────────────
check_rem(
    md5_file("$root/DGV7.0-SAAS/func/bc-fintech-gate.php") === md5_file("$root/DGV7.0-NON-SAAS/func/bc-fintech-gate.php"),
    'bc-fintech-gate.php is byte-identical across SAAS and NON-SAAS'
);
require_once "$root/DGV7.0-SAAS/func/bc-fintech-gate.php";
$_SERVER['HTTP_X_APP_SOURCE'] = 'dgv6-android';
$GLOBALS['bc_api_auth_mode'] = 'api_key';
check_rem(bc_api_is_app_session(false) === false, 'bc_api_is_app_session rejects spoofed X-App-Source');
$GLOBALS['bc_api_auth_mode'] = 'app_token';
check_rem(bc_api_is_app_session(false) === true, 'bc_api_is_app_session accepts verified app_token');
check_rem(bc_api_is_app_session(true) === true, 'bc_api_is_app_session accepts in-process app flag');

$gateSrc = file_get_contents("$root/DGV7.0-SAAS/func/bc-fintech-gate.php");
check_rem(strpos($gateSrc, 'API_NOT_APPROVED') !== false, 'bc-fintech-gate enforces api_status == 1');

// ── 4. Web API endpoints & APIDocs across both editions ─────────────────────
foreach ($editions as $ed) {
    $apiFiles = glob("$root/$ed/web/api/*.php");
    check_rem(count($apiFiles) > 30, "$ed web/api/*.php enumerated");
    foreach ($apiFiles as $f) {
        $c = file_get_contents($f);
        $bn = basename($f);
        check_rem(strpos($c, 'HTTP_X_APP_SOURCE') === false, "$ed/web/api/$bn has no HTTP_X_APP_SOURCE");
        check_rem(strpos($c, '$input["UserID"]') === false, "$ed/web/api/$bn has no dead UserID branch");
    }
    $regDev = file_get_contents("$root/$ed/web/api/register-device.php");
    check_rem(strpos($regDev, 'bc_fintech_api_credential(') !== false, "$ed register-device.php enforces bc_fintech_api_credential");
    $bio = file_get_contents("$root/$ed/web/api/biometric.php");
    check_rem(strpos($bio, 'bc_api_is_app_session(') !== false, "$ed biometric.php requires app_token session");

    $docs = file_get_contents("$root/$ed/web/APIDocs.php");
    check_rem(strpos($docs, 'bc_validate_csrf') !== false && strpos($docs, 'bc_csrf_field') !== false, "$ed APIDocs.php protects POST actions with CSRF");
    check_rem(strpos($docs, 'kyc_submit.php') === false && strpos($docs, '/web/api/kyc.php') !== false, "$ed APIDocs.php points to /web/api/kyc.php");

    $spec = file_get_contents("$root/$ed/web/api/MOBILE_APP_SPEC.md");
    check_rem(strpos($spec, 'app_token') !== false && strpos($spec, 'disabled/hardcoded') === false, "$ed MOBILE_APP_SPEC.md reflects app_token & active KYC");
}

// ── 5. Legacy api/app-backend & NON-SAAS .htaccess parity ───────────────────
check_rem(
    is_file("$root/DGV7.0-NON-SAAS/api/.htaccess") &&
    md5_file("$root/DGV7.0-SAAS/api/.htaccess") === md5_file("$root/DGV7.0-NON-SAAS/api/.htaccess"),
    'NON-SAAS api/.htaccess exists and matches SAAS'
);
foreach ($editions as $ed) {
    foreach (glob("$root/$ed/api/app-backend/*.php") as $f) {
        $c = file_get_contents($f);
        $bn = basename($f);
        // No uncommented fwrite(fopen(...))
        $uncommentedFwrite = preg_match('/^[ \t]*fwrite\(fopen\(/m', $c);
        check_rem($uncommentedFwrite === 0, "$ed/api/app-backend/$bn has no active fwrite(fopen(...))");
    }
    foreach (['airtime.php', 'data.php', 'cable.php', 'electric.php', 'betting.php', 'card.php', 'education.php', 'bank-transfer.php'] as $txf) {
        $c = file_get_contents("$root/$ed/api/app-backend/$txf");
        check_rem(strpos($c, 'bc_fintech_app_access') !== false, "$ed/api/app-backend/$txf enforces bc_fintech_app_access");
    }
    $appInfo = file_get_contents("$root/$ed/api/app-backend/app-info.php");
    check_rem(strpos($appInfo, '$app_username') !== false && strpos($appInfo, 'payment-gateway-secret-key" => array()') !== false, "$ed app-info.php escapes SQL and strips gateway secrets");
    $fetchPlans = file_get_contents("$root/$ed/api/app-backend/fetch-dgv7-plans.php");
    check_rem(strpos($fetchPlans, "api_status") !== false, "$ed fetch-dgv7-plans.php enforces api_status == 1");
    $aiVis = file_get_contents("$root/$ed/api/app-backend/ai-vision-parser.php");
    check_rem(strpos($aiVis, 'Unauthorized') !== false, "$ed ai-vision-parser.php requires authentication");
    $aiKyc = file_get_contents("$root/$ed/api/app-backend/ai-kyc-interview.php");
    check_rem(strpos($aiKyc, "\$body['user_id']") === false && strpos($aiKyc, 'Unauthorized') !== false, "$ed ai-kyc-interview.php derives identity from session/token");
}

echo "PASS $passed security audit remediation checks\n";

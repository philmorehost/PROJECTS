<?php
/**
 * Comprehensive verification suite for:
 *  1) Admin PaymentOrders / SweetAlert2 / §34 submitter fixes
 *  2) Shared server-verified payment gateway settlement & webhooks
 *  3) Service Control Center enforcement across web, API, and virtual banks
 *  4) Edition parity + MANIFEST.json integrity
 *  5) Android FundWalletFragment.kt checkout/callback contracts
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$root = dirname(__DIR__);
$checks = 0;
function pcheck($cond, $label) {
    global $checks;
    $checks++;
    if (!$cond) {
        throw new RuntimeException("FAIL: $label");
    }
}

// ── 1. Edition parity on shared files ─────────────────────────────────────────
$parityFiles = [
    'func/bc-payment-gateway-settlement.php',
    'func/bc-plate-admin.php',
    'bc-admin/PaymentOrders.php',
    'func/bc-wallet-funding-page.php',
    'web/Fund.php',
    'jsfile/bc-wallet-funding.js',
    'web/payhub-success.php',
    'web/ajax-sync-accounts.php',
    'web/api/create-checkout.php',
    'web/api/payhub-checkout.php',
    'web/api/verify-funding.php',
    'web/api/funding-config.php',
    'web/api/virtual-banks.php',
    'web/api/platform-banks.php',
    'web/api/fund-manual.php',
    'web/api/paystack-webhook.php',
    'web/api/monnify-webhook.php',
    'web/api/beewave-webhook.php',
    'users-paystack.php',
    'users-monnify.php',
    'users-beewave.php',
    'users-payvessel.php',
    'users-payvessel-webhook.php',
    'users-payhub.php',
    'api/app-backend/app-info.php',
];

foreach ($parityFiles as $rel) {
    $saas = @file_get_contents("$root/DGV7.0-SAAS/$rel");
    $non  = @file_get_contents("$root/DGV7.0-NON-SAAS/$rel");
    pcheck($saas !== false && $non !== false && $saas === $non, "Edition parity: $rel");
}

// ── 2. MANIFEST.json pinned helper SHA-256 verification (when mounted) ────────
$manifestPath = "$root/migrations/MANIFEST.json";
if (is_file($manifestPath)) {
    $manifest = json_decode(file_get_contents($manifestPath), true);
    pcheck(is_array($manifest) && !empty($manifest['paired_helpers']), 'MANIFEST.json loaded');
    foreach ($manifest['paired_helpers'] as $helper) {
        $hname = $helper['name'];
        $expectedHash = $helper['sha256'];
        foreach (['DGV7.0-SAAS', 'DGV7.0-NON-SAAS'] as $ed) {
            $actualHash = hash_file('sha256', "$root/$ed/func/$hname");
            pcheck($actualHash === $expectedHash, "MANIFEST sha256 intact for $ed/func/$hname");
        }
    }
}

// ── 3. Admin flash, §34 submitter, and PaymentOrders ──────────────────────────
$plate = file_get_contents("$root/DGV7.0-SAAS/func/bc-plate-admin.php");
pcheck(strpos($plate, 'cdn.jsdelivr.net/npm/sweetalert2') === false, 'bc-plate-admin: CDN SweetAlert2 removed');
pcheck(strpos($plate, '../assets-2/vendor/sweetalert2/sweetalert2.all.min.js') !== false, 'bc-plate-admin: local SweetAlert2 loaded');
pcheck(strpos($plate, 'DOMContentLoaded') !== false && strpos($plate, 'showFallbackBanner') !== false, 'bc-plate-admin: flash waits for DOMContentLoaded and has DOM fallback');
pcheck(strpos($plate, '_bcLastSubmitter') !== false, 'bc-plate-admin: §34 submitter mirror captures _bcLastSubmitter');

foreach (['DGV7.0-SAAS', 'DGV7.0-NON-SAAS'] as $ed) {
    $ht = file_get_contents("$root/$ed/func/history-table.php");
    pcheck(strpos($ht, 'isset($inline_approve_page)') !== false && strpos($ht, '$show_actions = ($is_admin && ((isset($is_payment_order)') !== false, "$ed history-table: inline_approve_page enables action column");
    pcheck(strpos($ht, 'min-width:68px') !== false, "$ed history-table: PIN and 2FA inputs remain visible/usable");
}

$po = file_get_contents("$root/DGV7.0-SAAS/bc-admin/PaymentOrders.php");
pcheck(strpos($po, 'unset($is_payment_order);') !== false, 'PaymentOrders: unsets $is_payment_order before App Pending');
pcheck(strpos($po, '$po_redirect_url') !== false && strpos($po, 'HTTP_REFERER') !== false, 'PaymentOrders: preserves tab/searchq across redirects');
pcheck(strpos($po, "bc_charge_failure_reason") !== false && strpos($po, 'bc_ensure_wallet_transactional_schema') !== false, 'PaymentOrders: ensures wallet schema and surfaces bc_charge_failure_reason');
pcheck(strpos($po, "\$GLOBALS['vendor_id'] = \$admin_vid") !== false, 'PaymentOrders: pins vendor_id to logged admin before chargeOtherUser');

require_once "$root/DGV7.0-SAAS/func/bc-incident-wallet.php";
if (!function_exists('bc_normalize_mysql_decimal')) {
    function bc_normalize_mysql_decimal($value) {
        if (!is_string($value)) return $value;
        $trimmed = trim($value);
        if (preg_match('/^([0-9]+)\.([0-9]+)$/', $trimmed, $m)) {
            $frac = rtrim($m[2], '0');
            $whole = ltrim($m[1], '0');
            if ($whole === '') $whole = '0';
            return $frac === '' ? $whole : ($whole . '.' . $frac);
        }
        return $value;
    }
}
pcheck(bc_incident_decimal(bc_normalize_mysql_decimal('1000.000000000000000000000000000000')) === '1000', 'DECIMAL(65,30) 1000.000...30 accepted by bc_incident_decimal');
pcheck(bc_incident_decimal(bc_normalize_mysql_decimal('1250.500000000000000000000000000000')) === '1250.5', 'DECIMAL(65,30) 1250.500...30 accepted by bc_incident_decimal');
pcheck(bc_incident_decimal(bc_normalize_mysql_decimal('0.0000000001')) === null, 'Excess precision 0.0000000001 still refused');
foreach (['DGV7.0-SAAS', 'DGV7.0-NON-SAAS'] as $ed) {
    $fnSrc = file_get_contents("$root/$ed/func/bc-func.php");
    pcheck(strpos($fnSrc, 'bc_normalize_mysql_decimal($amount)') !== false, "$ed bc-func normalizes DECIMAL(65,30) amounts before wallet post");
    $tblSrc = file_get_contents("$root/$ed/func/bc-tables.php");
    pcheck(strpos($tblSrc, 'function bc_ensure_wallet_transactional_schema(') !== false, "$ed bc-tables defines bc_ensure_wallet_transactional_schema");
}

// ── 4. Functional unit checks on bc-payment-gateway-settlement.php ────────────
require_once "$root/DGV7.0-SAAS/func/bc-payment-gateway-settlement.php";

pcheck(bc_pg_infer_bank_gateway(['gateway_name' => 'Monnify', 'bank_name' => 'Other']) === 'monnify', 'infer preserves explicit gateway_name');
pcheck(bc_pg_infer_bank_gateway(['bank_code' => 'PayHub', 'bank_name' => 'PayHub MFB']) === 'payhub', 'infer detects PayHub');
pcheck(bc_pg_infer_bank_gateway(['bank_code' => '110072', 'bank_name' => 'Some Bank']) === 'beewave', 'infer detects BeeWave 110072');
pcheck(bc_pg_infer_bank_gateway(['bank_code' => '120001', 'bank_name' => '9PSB']) === 'payvessel', 'infer detects PayVessel 120001');
pcheck(bc_pg_infer_bank_gateway(['bank_name' => 'Globus Bank']) === 'payvessel', 'infer detects PayVessel Globus');
pcheck(bc_pg_infer_bank_gateway(['bank_name' => 'Wema Bank']) === 'monnify', 'infer detects Monnify Wema');
pcheck(bc_pg_infer_bank_gateway(['bank_name' => 'Paystack Titan']) === 'paystack', 'infer detects Paystack');

pcheck(bc_pg_normalize_amount('100')['minor'] === 10000, 'normalize accepts 100');
pcheck(bc_pg_normalize_amount('1500.50')['decimal'] === '1500.50', 'normalize formats 1500.50');
pcheck(bc_pg_normalize_amount(1000000)['minor'] === 100000000, 'normalize accepts max 1000000');
foreach ([0, -50, '99.99', '1000000.01', '1e4', '100.001', 'NaN', ''] as $badAmt) {
    pcheck(bc_pg_normalize_amount($badAmt) === null, 'normalize rejects invalid amount: ' . var_export($badAmt, true));
}

pcheck(bc_pg_sanitize_reference('CHK_abcdef123456') === 'CHK_abcdef123456', 'sanitize accepts valid ref');
pcheck(bc_pg_sanitize_reference('../etc/passwd') === '', 'sanitize rejects path traversal');
pcheck(bc_pg_sanitize_reference('bad ref') === '', 'sanitize rejects spaces');

$rawBody = '{"event":"charge.success","data":{"reference":"CHK_123"}}';
$goodSig512 = hash_hmac('sha512', $rawBody, 'sk_live_secret');
$goodSig256 = hash_hmac('sha256', $rawBody, 'wh_secret');
pcheck(bc_pg_verify_hmac_candidates('sha512', $rawBody, $goodSig512, ['', 'sk_live_secret']) === true, 'HMAC sha512 accepts valid sig');
pcheck(bc_pg_verify_hmac_candidates('sha512', $rawBody, 'bad_sig', ['sk_live_secret']) === false, 'HMAC sha512 rejects forged sig');
pcheck(bc_pg_verify_hmac_candidates('sha512', $rawBody, '', ['sk_live_secret']) === false, 'HMAC sha512 rejects empty sig');
pcheck(bc_pg_verify_hmac_candidates('sha256', $rawBody, $goodSig256, ['wh_secret']) === true, 'HMAC sha256 accepts valid PayHub sig');
pcheck(bc_pg_verify_hmac_candidates('sha256', $rawBody, $goodSig256, ['']) === false, 'HMAC rejects empty secret');

// ── 5. Service Control & Virtual Bank enforcement ─────────────────────────────
foreach (['DGV7.0-SAAS', 'DGV7.0-NON-SAAS'] as $ed) {
    $fn = file_get_contents("$root/$ed/func/bc-func.php");
    pcheck(strpos($fn, "isServiceEnabled('virtual_bank_display', \$vid)") !== false, "$ed bc-func getUserVirtualBank enforces virtual_bank_display");
    pcheck(strpos($fn, "SELECT status FROM sas_payment_gateways") !== false, "$ed bc-func getUserVirtualBank checks sas_payment_gateways.status=1");
    pcheck((bool)preg_match('/function processPayhubSuccess\([^\n]+\) \{\s*\/\/[^\n]+\n\s*return false;/', $fn), "$ed processPayhubSuccess stays retired");
}

$vbApi = file_get_contents("$root/DGV7.0-SAAS/web/api/virtual-banks.php");
pcheck(strpos($vbApi, "isServiceEnabled('virtual_bank_display'") !== false && strpos($vbApi, 'bc_pg_bank_account_allowed(') !== false, 'api/virtual-banks enforces virtual_bank_display & gateway switch');

$pbApi = file_get_contents("$root/DGV7.0-SAAS/web/api/platform-banks.php");
$fmApi = file_get_contents("$root/DGV7.0-SAAS/web/api/fund-manual.php");
pcheck(strpos($pbApi, "isServiceEnabled('manual_funding'") !== false, 'api/platform-banks enforces manual_funding');
pcheck(strpos($fmApi, "isServiceEnabled('manual_funding'") !== false, 'api/fund-manual enforces manual_funding');

$syncAjax = file_get_contents("$root/DGV7.0-SAAS/web/ajax-sync-accounts.php");
pcheck(strpos($syncAjax, "isServiceEnabled('virtual_bank_display'") !== false && strpos($syncAjax, "bc_pg_is_gateway_enabled(\$connection_server, \$vendor_id, 'payhub')") !== false, 'ajax-sync-accounts enforces Service Control on sync');

$appInfo = file_get_contents("$root/DGV7.0-SAAS/api/app-backend/app-info.php");
pcheck(strpos($appInfo, 'bc_pg_is_gateway_enabled(') !== false && strpos($appInfo, 'bc_pg_bank_account_allowed(') !== false, 'app-info enforces Service Control on gateways & virtual banks');
pcheck(strpos($appInfo, '$payment_gateway["secret_key"]') === false, 'app-info never leaks gateway secret_key');

// ── 6. Android FundWalletFragment.kt fixes across all 3 apps (when mounted) ───
$androidApps = [
    "$root/ANDROID - iOS/DG7-Android/app/src/main/java/com/datagifting/app/ui/wallet/FundWalletFragment.kt",
    "$root/ANDROID - iOS/JIKABIZ-Android/app/src/main/java/com/jikabiz/app/ui/wallet/FundWalletFragment.kt",
    "$root/ANDROID - iOS/MZEEVTU-Android/app/src/main/java/com/mzeevtu/ui/wallet/FundWalletFragment.kt",
];
if (is_file($androidApps[0])) {
    foreach ($androidApps as $ktPath) {
        $kt = file_get_contents($ktPath);
        pcheck($kt !== false, "Found $ktPath");
        pcheck(strpos($kt, 'if (url.contains("payhub-success.php"))') !== false, "Android allows payhub-success.php to execute ($ktPath)");
        pcheck(strpos($kt, '"gateway" to gatewayName') !== false, "Android sends gateway in createCheckout ($ktPath)");
        pcheck(strpos($kt, 'reference: \'$reference\'') !== false, "Android passes reference to BeeWave checkout ($ktPath)");
    }
}

echo "PASS $checks payment orders, gateway settlement, Service Control, and mobile checks\n";

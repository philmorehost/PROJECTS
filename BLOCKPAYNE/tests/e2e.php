<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Http\Controllers\AuthController;
use App\Http\Controllers\GatewayController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\PrefundController;
use App\Http\Controllers\SecurityController;
use App\Http\Middleware\BruteForceProtection;
use App\Livewire\DashboardManager;
use App\Services\BlockchainNodeService;
use App\Services\LicenseService;
use App\Support\Database;
use App\Support\Env;
use App\Support\Icons;
use App\Support\Settings;

$passed = 0;
$failed = 0;

function e2e_assert(string $name, bool $cond, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "PASS [e2e] {$name}\n";
    } else {
        $failed++;
        echo "FAIL [e2e] {$name}" . ($detail !== '' ? " :: {$detail}" : '') . "\n";
    }
}

// Configure isolated PGlite database & test HTTP transport for LicenseService
Env::setRuntime('DB_CONNECTION', 'pglite');
Env::setRuntime('DB_DATABASE', 'memory://');
Env::setRuntime('BP_PREBOUND_PDO', '1');

LicenseService::setTransport(static function (string $key, string $domain): array {
    if ($key === 'BP-PROD-E2E-KEY-2026' && $domain === 'gateway.blockpayne.com') {
        return ['code' => 200, 'body' => '{"status":1,"message":"License is valid."}', 'error' => ''];
    }
    return ['code' => 200, 'body' => '{"status":0,"message":"Invalid license key or domain."}', 'error' => ''];
});

$pdo = new PDO('pgsql:memory://', 'postgres', 'postgres', [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
]);
Database::setConnection($pdo);

@unlink(BP_STORAGE . '/installed');
$_SERVER['HTTP_HOST'] = 'gateway.blockpayne.com';
$_SERVER['REMOTE_ADDR'] = '102.89.33.10';

// 1. Run all 4 stages of the Dynamic Web Installer (Live contract)!
$installer = new InstallController();
$badLic = $installer->submitStage(1, ['license_key' => 'WRONG-KEY-000000']);
e2e_assert('Installer Stage 1 rejects unauthorized license key', $badLic['ok'] === false && $badLic['stage'] === 1);

$s1 = $installer->submitStage(1, ['license_key' => 'BP-PROD-E2E-KEY-2026']);
e2e_assert('Installer Stage 1 verifies system & active license key', $s1['ok'] === true && $s1['stage'] === 2, json_encode($s1));

$s2 = $installer->submitStage(2, [
    'db_driver'   => 'pglite',
    'db_database' => '/tmp/bp_e2e_db',
]);
e2e_assert('Installer Stage 2 writes .env & runs migrations + 250 ISO countries', $s2['ok'] === true && $s2['stage'] === 3, json_encode($s2));

$countryCount = (int) $pdo->query('SELECT COUNT(*) FROM country_firewall')->fetchColumn();
$ngRule = (string) $pdo->query("SELECT action FROM country_firewall WHERE country_code = 'NG'")->fetchColumn();
e2e_assert('All 250 ISO countries seeded with Nigeria Whitelisted', $countryCount === 250 && $ngRule === 'Whitelisted', "count={$countryCount}, NG={$ngRule}");

$s3 = $installer->submitStage(3, [
    'site_title'          => 'BlockPayne',
    'brand_color'         => '#10b981',
    'gateway_fee_percent' => '1.00',
    'admin_name'          => 'Platform Admin',
    'admin_email'         => 'admin@blockpayne.com',
    'admin_password'      => 'AdminPass123!',
]);
e2e_assert('Installer Stage 3 creates Super Admin, caches SVG favicon & writes storage/installed lock', $s3['ok'] === true && bp_installed() && is_file(BP_STORAGE . '/cache/favicon.svg'), json_encode($s3));

// 2. Register a Merchant & Verify Welcome Credit ($2.00)
$auth = new AuthController();
$reg = $auth->register([
    'name'     => 'Lagos Gadget Hub',
    'email'    => 'merchant@gadgethub.ng',
    'password' => 'MerchantPass123!',
]);
e2e_assert('Merchant registration succeeds & issues bp_live_ API key', $reg['ok'] === true && str_starts_with($reg['user']['api_key'], 'bp_live_'), json_encode($reg));
$merchantId = (int) $reg['user']['id'];

$mStmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$mStmt->execute([$merchantId]);
$merchant = $mStmt->fetch();

$balStmt = $pdo->prepare("SELECT balance FROM merchant_balances WHERE user_id = ? AND currency = 'USD'");
$balStmt->execute([$merchantId]);
$initBal = (float) $balStmt->fetchColumn();
e2e_assert('Merchant receives $2.00 starter prefund credit', abs($initBal - 2.00) < 0.0001, "balance={$initBal}");

// 3. Connect Non-Custodial BTC zPub Wallet & Create Deterministic Invoices (with redirect_url)
$gw = new GatewayController();
$zpub = 'zpub6rFR7y4Q2AijBEqTUquhVz398htDFrtymD9xYYfG1m4wAcvPhXNfE3EfH1r1ADqtfSdVCToUG868RvUUkgDKf31mGDtKsAYz2oz2AGutZYs';
$wRes = $gw->addWallet($merchant, [
    'coin_symbol'  => 'BTC',
    'wallet_label' => 'Ledger Nano BTC',
    'xpub_key'     => $zpub,
]);
e2e_assert('Connecting BTC zPub wallet derives canonical index 0 address', $wRes['ok'] === true && $wRes['sample_address'] === 'bc1qcr8te4kr609gcawutmrza0j4xv80jy8z306fyu');

$inv1 = $gw->createInvoice($merchantId, [
    'fiat_amount'   => 100.00,
    'fiat_currency' => 'USD',
    'currency'      => 'BTC',
    'order_id'      => 'ORD-1',
    'redirect_url'  => 'https://gadgethub.ng/orders/ORD-1/complete',
], false);
$inv2 = $gw->createInvoice($merchantId, ['fiat_amount' => 50.00, 'fiat_currency' => 'USD', 'currency' => 'BTC', 'order_id' => 'ORD-2'], false);

e2e_assert(
    'Consecutive invoices derive unique deterministic addresses (index 0 and index 1) & store redirect_url',
    $inv1['ok'] && $inv2['ok']
    && $inv1['deposit_address'] === 'bc1qcr8te4kr609gcawutmrza0j4xv80jy8z306fyu'
    && $inv2['deposit_address'] === 'bc1qnjg0jd8228aq7egyzacy8cys3knf9xvrerkf9g'
    && $inv1['redirect_url'] === 'https://gadgethub.ng/orders/ORD-1/complete'
);

$listed = $gw->listMerchantInvoices($merchantId, ['order_id' => 'ORD-1']);
e2e_assert(
    'REST listMerchantInvoices filters by order_id and includes live explorer_url',
    count($listed) === 1 && str_contains($listed[0]['explorer_url'], 'mempool.space'),
    json_encode($listed)
);

// 4. Confirm Invoice #1 ($100) & Verify Atomic 1% ($1.00) Prefund Fee Deduction + Idempotency!
$gw->markInvoiceConfirmed($inv1['invoice_id'], $inv1['crypto_amount'], 2, '7a91e3f4b2c8d90123456789abcdef0123456789abcdef0123456789abcdef01');
$gw->markInvoiceConfirmed($inv1['invoice_id'], $inv1['crypto_amount'], 3, '7a91e3f4b2c8d90123456789abcdef0123456789abcdef0123456789abcdef01'); // Replay test!

$balStmt->execute([$merchantId]);
$afterFeeBal = (float) $balStmt->fetchColumn();
e2e_assert('Confirming $100 invoice deducts 1% ($1.00) fee exactly once ($2.00 -> $1.00)', abs($afterFeeBal - 1.00) < 0.0001, "balance={$afterFeeBal}");

// 5. Live Production Payhub Guard + Signed X-Payhub-Signature Webhook!
$pf = new PrefundController();
Settings::set('payhub_secret_key', '');
$phUnconfig = $pf->initializePayhub($merchant, ['usd_amount' => 25.00]);
e2e_assert('Payhub rejects unconfigured secret key cleanly (zero simulation mode)', $phUnconfig['ok'] === false);

// Insert a real pending Payhub reference & verify signed X-Payhub-Signature webhook idempotency
$liveRef = 'PH_BP_LIVE99001122';
$pdo->prepare(
    'INSERT INTO transactions (user_id, type, amount, currency, tx_hash, gateway, description, status, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([$merchantId, 'prefund_deposit', '25.00000000', 'USD', $liveRef, 'payhub', 'Payhub Live Top-Up ($25.00)', 'pending', bp_now()]);

Settings::set('payhub_secret_key', 'sk_live_payhub_e2e_secret');
$webhookJson = json_encode([
    'event' => 'charge.success',
    'data'  => [
        'reference' => $liveRef,
        'amount'    => 4000000,
        'status'    => 'success',
        'currency'  => 'NGN',
    ],
]);
$sig = hash_hmac('sha256', (string) $webhookJson, 'sk_live_payhub_e2e_secret');
$whRes1 = $pf->handlePayhubWebhook((string) $webhookJson, $sig);
$whRes2 = $pf->handlePayhubWebhook((string) $webhookJson, $sig);

$balStmt->execute([$merchantId]);
$afterPayhubBal = (float) $balStmt->fetchColumn();
e2e_assert('Signed Payhub webhook credits $25.00 idempotently ($1.00 -> $26.00)', $whRes1['ok'] && $whRes2['ok'] && abs($afterPayhubBal - 26.00) < 0.0001, "balance={$afterPayhubBal}");

// 6. Live Crypto Prefund Top-Up Invoice ($10 USDT-TRC20)
Settings::set('admin_usdt_trc20_address', 'TCMgrALZwWyW5FM3DkeqnxSoc2kDCSZiW3');
$cryptoTop = $pf->createCryptoTopUp($merchant, ['usd_amount' => 10.00, 'coin_symbol' => 'USDT-TRC20']);
e2e_assert('Crypto prefund top-up creates TOPUP- invoice against Admin live wallet', $cryptoTop['ok'] === true && str_starts_with($cryptoTop['invoice_id'], 'TOPUP-'));

$gw->handleBlockBeeCallback([
    'invoice_id'    => $cryptoTop['invoice_id'],
    'value_coin'    => '10.00',
    'pending'       => 0,
    'confirmations' => 19,
    'txid_in'       => 'tron_tx_999',
]);
$balStmt->execute([$merchantId]);
$afterCryptoTopBal = (float) $balStmt->fetchColumn();
e2e_assert('Confirming Crypto Top-Up via BlockBee callback credits $10.00 ($26.00 -> $36.00)', abs($afterCryptoTopBal - 36.00) < 0.0001, "balance={$afterCryptoTopBal}");

// 7. cPHulk Security Engine: Account Lockout, Admin Privilege Exception & 5-Session Smart King Whitelisting!
$attackerIp = '198.51.100.77';
for ($i = 0; $i < 5; $i++) {
    BruteForceProtection::recordFailedLogin('merchant@gadgethub.ng', $attackerIp, 'RU');
}
$mStmt->execute([$merchantId]);
$lockedMerchant = $mStmt->fetch();
e2e_assert('Merchant account auto-locks after 5 failed login attempts', ($lockedMerchant['status'] ?? '') === 'locked');

for ($i = 0; $i < 5; $i++) {
    BruteForceProtection::recordFailedLogin('admin@blockpayne.com', $attackerIp, 'RU');
}
$admRow = $pdo->query("SELECT status FROM users WHERE email = 'admin@blockpayne.com'")->fetch();
$ipRuleRow = $pdo->prepare('SELECT rule_type FROM ip_firewall WHERE ip_address = ?');
$ipRuleRow->execute([$attackerIp]);
$attackerRule = (string) $ipRuleRow->fetchColumn();

e2e_assert('Privilege Exception keeps default admin account active while blocking attacker IP at 10 failures', ($admRow['status'] ?? '') === 'active' && $attackerRule === 'block', "admin={$admRow['status']}, ip={$attackerRule}");

// Unlock merchant via Admin SecurityController & test 5-clean-session Smart King Whitelisting
$adminFull = $pdo->query("SELECT * FROM users WHERE role = 'admin' LIMIT 1")->fetch();
$secCtrl = new SecurityController();
$secCtrl->setMerchantStatus($adminFull, ['user_id' => $merchantId, 'status' => 'active']);
$secCtrl->updateCountryRule($adminFull, ['country_code' => 'KP', 'action' => 'Blacklisted']);

$mStmt->execute([$merchantId]);
$unlockedMerchant = $mStmt->fetch();
$cleanIp = '197.210.55.12';
$lastWlState = ['is_whitelisted' => false, 'clean_sessions' => 0];
unset($_SESSION['_bp_had_fail']);
for ($s = 1; $s <= 5; $s++) {
    $lastWlState = BruteForceProtection::recordSuccessfulLogin($unlockedMerchant, $cleanIp, 'NG', 'distinct-sess-' . $s);
}
e2e_assert(
    '5 clean logins from distinct sessions automatically grant King Crown green whitelist',
    $lastWlState['is_whitelisted'] === true && $lastWlState['clean_sessions'] === 5,
    json_encode($lastWlState)
);

// 8. SPA DashboardManager State, Menu Icons & Multi-Language API Docs Rendering
$spa = new DashboardManager();
$adminState = $spa->buildState($adminFull, true);
e2e_assert(
    'SPA DashboardManager builds full reactive state with 250 countries & King security logs (zero demo flags)',
    !array_key_exists('demo_enabled', $adminState)
    && isset($adminState['admin']['countries'])
    && count($adminState['admin']['countries']) === 250
    && count($adminState['admin']['security_logs']) > 0
);

$menuSvg = Icons::svg('wallet', 18);
e2e_assert('Menu Icons helper renders crisp stroke-based inline SVG icons', str_contains($menuSvg, '<svg class="bp-icon"') && str_contains($menuSvg, 'viewBox="0 0 24 24"'));

ob_start();
require BP_ROOT . '/resources/views/api-docs.php';
$docsHtml = (string) ob_get_clean();
e2e_assert(
    'API Documentation page renders 8 languages, PHP frameworks, 10 platform plugins & HMAC webhooks',
    str_contains($docsHtml, 'Merchant API &amp; Platform Integrations')
    && str_contains($docsHtml, 'data-lang="php"')
    && str_contains($docsHtml, 'data-lang="node"')
    && str_contains($docsHtml, 'data-lang="python"')
    && str_contains($docsHtml, 'data-lang="curl"')
    && str_contains($docsHtml, 'data-lang="go"')
    && str_contains($docsHtml, 'data-lang="csharp"')
    && str_contains($docsHtml, 'data-lang="ruby"')
    && str_contains($docsHtml, 'data-lang="java"')
    && str_contains($docsHtml, 'data-lang="fw-laravel"')
    && str_contains($docsHtml, 'WHMCS')
    && str_contains($docsHtml, 'WooCommerce')
    && str_contains($docsHtml, 'FOSSBilling')
    && str_contains($docsHtml, 'PrestaShop')
    && str_contains($docsHtml, 'Joomla')
    && str_contains($docsHtml, 'Shopify')
    && str_contains($docsHtml, 'Wix')
    && str_contains($docsHtml, 'Invoice Ninja v5')
    && str_contains($docsHtml, 'Telegram Shop Bot')
    && str_contains($docsHtml, 'X-BlockPayne-Signature')
);

// 9. AUTO Coin Invoice + Metadata + Live Buyer Coin Switching (BTC -> LTC)
$gw->addWallet($unlockedMerchant, [
    'coin_symbol'  => 'LTC',
    'wallet_label' => 'Litecoin SegWit',
    'xpub_key'     => $zpub,
]);
$autoInv = $gw->createInvoice($merchantId, [
    'amount'        => 35.00,
    'fiat_currency' => 'USD',
    'currency'      => 'AUTO',
    'order_id'      => 'WHMCS-9001',
    'metadata'      => ['telegram_chat_id' => 778899, 'platform' => 'whmcs'],
], false);
e2e_assert(
    'AUTO currency invoice selects active wallet & persists JSON metadata',
    $autoInv['ok'] === true
    && $autoInv['currency'] === 'BTC'
    && ($autoInv['metadata']['telegram_chat_id'] ?? 0) === 778899
);

$switched = $gw->switchInvoiceCoin($autoInv['invoice_id'], 'LTC', false);
e2e_assert(
    'Buyer switchInvoiceCoin switches pending invoice from BTC to LTC Bech32 & regenerates QR SVG',
    $switched['ok'] === true
    && $switched['invoice']['currency'] === 'LTC'
    && $switched['invoice']['deposit_address'] === 'ltc1qcr8te4kr609gcawutmrza0j4xv80jy8z4nqduv'
    && str_contains($switched['invoice']['qr_svg'], '<svg')
);

// 10. All 10 Official Integration .zip Kits Build Valid PKZIP Archives
$kitService = new \App\Services\IntegrationKitService();
$slugs = ['whmcs', 'woocommerce', 'fossbilling', 'prestashop', 'joomla', 'shopify', 'wix', 'invoiceninja', 'telegram-bot', 'php-sdk'];
$allZipsValid = true;
foreach ($slugs as $slug) {
    $pkg = $kitService->buildZip($slug);
    if ($pkg === null || !str_starts_with($pkg['binary'], "PK\x03\x04") || !str_contains($pkg['binary'], "PK\x05\x06")) {
        $allZipsValid = false;
        break;
    }
}
e2e_assert('All 10 downloadable integration .zip packages build valid PKZIP archives', $allZipsValid);

echo sprintf("\nE2E Summary: %d passed, %d failed\n", $passed, $failed);
if ($failed > 0) {
    exit(1);
}

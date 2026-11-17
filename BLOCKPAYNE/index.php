<?php
declare(strict_types=1);

/**
 * BLOCKPAYNE — Front Controller (`index.php`).
 * Automatically invokes the 4-stage installer if `storage/installed` is missing.
 */

require_once __DIR__ . '/app/bootstrap.php';

use App\Http\Controllers\AuthController;
use App\Http\Controllers\GatewayController;
use App\Http\Middleware\BruteForceProtection;
use App\Http\Middleware\CheckInstalled;
use App\Livewire\DashboardManager;
use App\Services\LicenseService;
use App\Support\Database;
use App\Support\DynamicBrand;
use App\Support\Security;
use App\Support\Settings;

$uriPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$basePath = bp_base_path();
if ($basePath !== '' && str_starts_with($uriPath, $basePath)) {
    $uriPath = substr($uriPath, strlen($basePath));
}
$route = trim($uriPath, '/');
if ($route === 'index.php') {
    $route = '';
}

// Dynamic SVG Favicon from First Letter of Site Title
if ($route === 'favicon.svg' || ($_GET['route'] ?? '') === 'favicon') {
    header('Content-Type: image/svg+xml; charset=UTF-8');
    header('Cache-Control: public, max-age=3600');
    $cachedSvg = BP_STORAGE . '/cache/favicon.svg';
    if (bp_installed() && is_file($cachedSvg)) {
        bp_halt((string) file_get_contents($cachedSvg), 200);
    }
    bp_halt(DynamicBrand::faviconSvg(bp_installed() ? Settings::get('site_title', 'BlockPayne') : 'BlockPayne'), 200);
}

// Forward /install and /api/* when rewritten through index.php on Apache/LiteSpeed
if ($route === 'install' || str_starts_with($route, 'install/')) {
    require __DIR__ . '/install/index.php';
    bp_halt();
}
if ($route === 'api' || str_starts_with($route, 'api/')) {
    require __DIR__ . '/api/index.php';
    bp_halt();
}

// Intercept requests if `storage/installed` lockfile is missing
CheckInstalled::handle(false);

Security::startSession();
Security::sendHeaders();
BruteForceProtection::enforce();

// Verify License Manager runtime status (6h cache + 24h outage grace)
$licState = (new LicenseService())->checkRuntime((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
if (!$licState['valid']) {
    http_response_code(503);
    $bodyContent = '<main class="bp-container" style="max-width:480px;padding-top:64px;"><div class="bp-card" style="text-align:center;">'
        . '<div class="bp-badge bp-badge-danger bp-mb-2">License Verification Required</div>'
        . '<h1 style="margin:6px 0 8px;font-size:21px;">Domain Not Activated</h1>'
        . '<p class="bp-muted" style="font-size:14px;margin:0 0 14px;">' . bp_e($licState['message']) . '</p>'
        . '<p class="bp-subtle" style="font-size:12.5px;">Domain: <code class="bp-mono">' . bp_e($licState['domain']) . '</code></p></div></main>';
    $pageTitle = 'License Required';
    require __DIR__ . '/resources/views/layout.php';
    bp_halt();
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$user = AuthController::user();

// Maintenance mode guard (allows admins through)
if (Settings::bool('maintenance_mode') && ($user === null || ($user['role'] ?? '') !== 'admin') && !in_array($route, ['login'], true)) {
    http_response_code(503);
    $bodyContent = '<main class="bp-container" style="max-width:440px;padding-top:72px;"><div class="bp-card" style="text-align:center;">'
        . '<h1 style="margin:0 0 8px;font-size:21px;">Scheduled Maintenance</h1>'
        . '<p class="bp-muted" style="margin:0;">We are upgrading the gateway. Please check back shortly.</p></div></main>';
    $pageTitle = 'Maintenance';
    require __DIR__ . '/resources/views/layout.php';
    bp_halt();
}

// Landing Page
if ($route === '') {
    require __DIR__ . '/resources/views/landing.php';
    bp_halt();
}

// Merchant & Developer Multi-Language API Documentation (`/api-docs`)
if ($route === 'api-docs') {
    require __DIR__ . '/resources/views/api-docs.php';
    bp_halt();
}

// Downloadable E-Commerce, CMS, Billing, Bot & PHP SDK Integration Packages (`/integrations/download/{slug}`)
if (preg_match('#^integrations/download/([a-z0-9\-]+)$#', $route, $dm)) {
    $kit = new \App\Services\IntegrationKitService();
    $pkg = $kit->buildZip($dm[1]);
    if ($pkg === null) {
        bp_halt('Integration package not found.', 404);
    }
    bp_download_binary($pkg['filename'], $pkg['binary'], 'application/zip');
}

// Signed Hosted Checkout Form Endpoint (`POST /pay/hosted`) — used by WHMCS, FOSSBilling, CMS & HTML/PHP forms
if ($route === 'pay/hosted' && $method === 'POST') {
    $apiKey        = trim((string) ($_POST['api_key'] ?? ''));
    $amount        = number_format((float) ($_POST['amount'] ?? 0), 2, '.', '');
    $fiatCurrency  = strtoupper(trim((string) ($_POST['fiat_currency'] ?? 'USD')));
    $currency      = strtoupper(trim((string) ($_POST['currency'] ?? 'AUTO')));
    $orderId       = trim((string) ($_POST['order_id'] ?? ''));
    $title         = trim((string) ($_POST['title'] ?? ''));
    $buyerEmail    = trim((string) ($_POST['buyer_email'] ?? ''));
    $callbackUrl   = trim((string) ($_POST['callback_url'] ?? ''));
    $redirectUrl   = trim((string) ($_POST['redirect_url'] ?? ''));
    $timestamp     = trim((string) ($_POST['timestamp'] ?? ''));
    $signature     = strtolower(trim((string) ($_POST['signature'] ?? '')));

    $pdo = Database::connection();
    $mStmt = $pdo->prepare("SELECT * FROM users WHERE api_key = ? AND status = 'active' LIMIT 1");
    $mStmt->execute([$apiKey]);
    $merchant = $mStmt->fetch();

    if (!is_array($merchant) || $signature === '' || abs(time() - (int) $timestamp) > 86400) {
        bp_halt('Invalid or expired hosted checkout signature.', 401);
    }

    $expectedSig = hash_hmac(
        'sha256',
        $apiKey . '|' . $amount . '|' . $fiatCurrency . '|' . $orderId . '|' . $timestamp,
        (string) ($merchant['webhook_secret'] ?? '')
    );
    if (!hash_equals($expectedSig, $signature)) {
        bp_halt('Hosted checkout HMAC signature verification failed.', 401);
    }

    $gw = new GatewayController();
    $res = $gw->createInvoice((int) $merchant['id'], [
        'fiat_amount'   => (float) $amount,
        'fiat_currency' => $fiatCurrency,
        'currency'      => $currency ?: 'AUTO',
        'order_id'      => $orderId,
        'title'         => $title ?: ($orderId !== '' ? 'Order #' . $orderId : 'Crypto Payment'),
        'buyer_email'   => $buyerEmail,
        'callback_url'  => $callbackUrl,
        'redirect_url'  => $redirectUrl,
    ]);

    if (!empty($res['ok']) && !empty($res['checkout_path'])) {
        bp_redirect((string) $res['checkout_path'], 303);
    }
    bp_halt('Unable to initialize hosted crypto checkout: ' . bp_e((string) ($res['error'] ?? 'Unknown error')), 422);
}

// Login & Register & Logout
if ($route === 'login' || $route === 'register') {
    if ($user !== null) {
        bp_redirect(bp_url('dashboard'));
    }
    $mode = $route;
    $error = null;
    if ($method === 'POST') {
        Security::requireCsrf();
        $auth = new AuthController();
        $res = ($route === 'register') ? $auth->register($_POST) : $auth->login($_POST);
        if ($res['ok']) {
            bp_redirect((string) ($res['redirect'] ?? bp_url('dashboard')));
        }
        $error = $res['error'] ?? 'Authentication failed.';
    }
    require __DIR__ . '/resources/views/auth.php';
    bp_halt();
}

if ($route === 'logout' && $method === 'POST') {
    Security::requireCsrf();
    (new AuthController())->logout();
    bp_redirect(bp_url('login'));
}

// SPA Dashboard (Merchant & Admin)
if ($route === 'dashboard') {
    if ($user === null) {
        bp_redirect(bp_url('login'));
    }
    $payhubRef = trim((string) ($_GET['payhub_ref'] ?? $_GET['reference'] ?? $_GET['trxref'] ?? ''));
    if ($payhubRef !== '') {
        (new \App\Http\Controllers\PrefundController())->confirmPayhubReference($payhubRef, false);
    }
    $includeCountries = (($_GET['tab'] ?? '') === 'admin_countries');
    $initialState = (new DashboardManager())->buildState($user, $includeCountries);
    require __DIR__ . '/resources/views/dashboard.php';
    bp_halt();
}

// Hosted Buyer Invoice Checkout (`/pay/{invoice_id}`)
if (preg_match('#^pay/([A-Za-z0-9\-]+)$#', $route, $m)) {
    $gw = new GatewayController();
    $invoice = $gw->getAndPollInvoice($m[1], true);
    if ($invoice === null) {
        http_response_code(404);
        $bodyContent = '<main class="bp-container" style="max-width:440px;padding-top:64px;"><div class="bp-card" style="text-align:center;">'
            . '<h1 style="margin:0 0 8px;font-size:20px;">Invoice Not Found</h1>'
            . '<p class="bp-muted" style="margin:0 0 16px;">Check the payment URL and try again.</p>'
            . '<a href="' . bp_e(bp_url('')) . '" class="bp-btn bp-btn-secondary">Back Home</a></div></main>';
        $pageTitle = 'Not Found';
        require __DIR__ . '/resources/views/layout.php';
        bp_halt();
    }
    $pdo = Database::connection();
    $uIdStmt = $pdo->prepare('SELECT user_id FROM invoices WHERE invoice_id = ? LIMIT 1');
    $uIdStmt->execute([$m[1]]);
    $invOwnerId = (int) $uIdStmt->fetchColumn();
    $merchantCoins = ($invOwnerId > 0 && empty($invoice['is_prefund']))
        ? $gw->getMerchantActiveCoins($invOwnerId)
        : [];
    require __DIR__ . '/resources/views/checkout.php';
    bp_halt();
}

// Hosted Reusable Payment Link (`/link/{slug}`)
if (preg_match('#^link/([A-Za-z0-9\-]+)$#', $route, $m)) {
    $pdo = Database::connection();
    $stmt = $pdo->prepare("SELECT * FROM payment_links WHERE slug = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$m[1]]);
    $paymentLink = $stmt->fetch();
    if (!is_array($paymentLink)) {
        bp_halt('Payment link not found or inactive.', 404);
    }

    // Find which coins this merchant actually has active wallets for
    $wStmt = $pdo->prepare("SELECT DISTINCT coin_symbol FROM merchant_wallets WHERE user_id = ? AND status = 'active'");
    $wStmt->execute([(int) $paymentLink['user_id']]);
    $merchantCoins = array_column($wStmt->fetchAll(), 'coin_symbol');
    $accepted = array_filter(array_map('trim', explode(',', (string) $paymentLink['accepted_coins'])));
    $availableCoins = !empty($merchantCoins) ? array_values(array_intersect($accepted, $merchantCoins)) : $accepted;
    if (empty($availableCoins)) {
        $availableCoins = !empty($merchantCoins) ? $merchantCoins : ['BTC'];
    }

    $linkError = null;
    if ($method === 'POST') {
        Security::requireCsrf();
        $amt = !empty($paymentLink['allow_custom_amount'])
            ? (float) ($_POST['fiat_amount'] ?? 0)
            : (float) $paymentLink['fiat_amount'];
        $coin = (string) ($_POST['currency'] ?? $availableCoins[0]);
        $gw = new GatewayController();
        $res = $gw->createInvoice((int) $paymentLink['user_id'], [
            'fiat_amount'   => $amt,
            'fiat_currency' => $paymentLink['fiat_currency'],
            'currency'      => $coin,
            'title'         => $paymentLink['title'],
        ]);
        if ($res['ok'] && !empty($res['checkout_path'])) {
            bp_redirect((string) $res['checkout_path']);
        }
        $linkError = $res['error'] ?? 'Unable to generate invoice.';
    }

    require __DIR__ . '/resources/views/checkout.php';
    bp_halt();
}

http_response_code(404);
$bodyContent = '<main class="bp-container" style="max-width:440px;padding-top:64px;"><div class="bp-card" style="text-align:center;">'
    . '<h1 style="margin:0 0 8px;font-size:21px;">404 — Page Not Found</h1>'
    . '<a href="' . bp_e(bp_url('')) . '" class="bp-btn bp-btn-primary bp-mt-4">Go Home</a></div></main>';
$pageTitle = '404 Not Found';
require __DIR__ . '/resources/views/layout.php';
bp_halt();

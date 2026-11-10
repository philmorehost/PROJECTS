<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Http\Controllers\AuthController;
use App\Http\Controllers\GatewayController;
use App\Http\Controllers\PrefundController;
use App\Http\Middleware\BruteForceProtection;
use App\Http\Middleware\CheckInstalled;
use App\Livewire\DashboardManager;
use App\Services\BlockchainNodeService;
use App\Support\Database;
use App\Support\Security;
use App\Support\Settings;

CheckInstalled::handle(false);
Security::startSession();
Security::sendHeaders();
BruteForceProtection::enforce();

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$uriPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/api', PHP_URL_PATH);
$basePath = bp_base_path();
if ($basePath !== '' && str_starts_with($uriPath, $basePath)) {
    $uriPath = substr($uriPath, strlen($basePath));
}
$subRoute = trim((string) preg_replace('#^/api(/index\.php)?#', '', $uriPath), '/');

// 1. Cryptographically Signed External Webhooks (Payhub & BlockBee)
if ($subRoute === 'webhooks/payhub' && $method === 'POST') {
    $rawBody = isset($GLOBALS['BP_RAW_BODY']) ? (string) $GLOBALS['BP_RAW_BODY'] : (string) file_get_contents('php://input');
    $sig = (string) ($_SERVER['HTTP_X_PAYHUB_SIGNATURE'] ?? '');
    $pf = new PrefundController();
    $res = $pf->handlePayhubWebhook($rawBody, $sig);
    bp_json(['ok' => $res['ok'], 'message' => $res['message']], $res['status_code']);
}

if ($subRoute === 'webhooks/blockbee') {
    $params = array_merge($_GET, Security::input());
    $gw = new GatewayController();
    $res = $gw->handleBlockBeeCallback($params);
    if ($res['ok']) {
        header('Content-Type: text/plain; charset=UTF-8');
        bp_halt('*ok*', 200);
    }
    bp_json($res, 400);
}

// 2. Live Merchant REST API v1 (`Authorization: Bearer bp_live_...` or `X-API-Key: bp_live_...`)
if (str_starts_with($subRoute, 'v1/')) {
    if ($subRoute === 'v1/rates' && $method === 'GET') {
        $node = new BlockchainNodeService();
        $usdRates = $node->getUsdRates(true);
        $ngnRate = max(1.0, Settings::float('usd_ngn_rate', 1600.0));
        bp_json([
            'ok'            => true,
            'base_currency' => 'USD',
            'rates_usd'     => $usdRates,
            'fiat_per_usd'  => [
                'USD' => 1.00,
                'NGN' => $ngnRate,
                'EUR' => 0.9259,
                'GBP' => 0.7874,
            ],
            'timestamp'     => time(),
        ]);
    }

    $authHeader = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    $xApiKey = trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? ''));
    $apiKey = '';

    if (preg_match('/^Bearer\s+(bp_live_[a-f0-9]+)$/i', $authHeader, $m)) {
        $apiKey = $m[1];
    } elseif (preg_match('/^(bp_live_[a-f0-9]+)$/i', $xApiKey, $m2)) {
        $apiKey = $m2[1];
    }

    if ($apiKey === '') {
        bp_json([
            'ok'    => false,
            'error' => 'Missing or invalid API credentials. Pass Authorization: Bearer bp_live_... or X-API-Key: bp_live_...',
        ], 401);
    }

    $pdo = Database::connection();
    $uStmt = $pdo->prepare("SELECT * FROM users WHERE api_key = ? AND status = 'active' LIMIT 1");
    $uStmt->execute([$apiKey]);
    $merchant = $uStmt->fetch();
    if (!is_array($merchant)) {
        bp_json(['ok' => false, 'error' => 'Invalid, suspended, or revoked API key.'], 401);
    }

    $merchantId = (int) $merchant['id'];

    if ($subRoute === 'v1/balance' && $method === 'GET') {
        $balStmt = $pdo->prepare("SELECT balance FROM merchant_balances WHERE user_id = ? AND currency = 'USD' LIMIT 1");
        $balStmt->execute([$merchantId]);
        $bal = (float) ($balStmt->fetchColumn() ?: 0.0);

        $wStmt = $pdo->prepare("SELECT coin_symbol, wallet_label, derivation_mode, derived_index FROM merchant_wallets WHERE user_id = ? AND status = 'active' ORDER BY id ASC");
        $wStmt->execute([$merchantId]);
        $wallets = $wStmt->fetchAll();

        bp_json([
            'ok'                  => true,
            'prefund_balance_usd' => number_format($bal, 2, '.', ''),
            'fee_percent'         => Settings::get('gateway_fee_percent', '1.00'),
            'active_wallets'      => $wallets,
        ]);
    }

    if ($subRoute === 'v1/invoices' && $method === 'POST') {
        $gw = new GatewayController();
        $res = $gw->createInvoice($merchantId, Security::input(), true);
        bp_json($res, $res['ok'] ? 201 : 422);
    }

    if ($subRoute === 'v1/invoices' && $method === 'GET') {
        $gw = new GatewayController();
        $list = $gw->listMerchantInvoices($merchantId, $_GET);
        bp_json([
            'ok'       => true,
            'count'    => count($list),
            'invoices' => $list,
        ]);
    }

    if (preg_match('#^v1/invoices/([A-Za-z0-9\-]+)$#', $subRoute, $im) && $method === 'GET') {
        $ownChk = $pdo->prepare('SELECT user_id FROM invoices WHERE invoice_id = ? LIMIT 1');
        $ownChk->execute([$im[1]]);
        $ownerId = $ownChk->fetchColumn();
        if ($ownerId === false || ((int) $ownerId !== $merchantId && ($merchant['role'] ?? '') !== 'admin')) {
            bp_json(['ok' => false, 'error' => 'Invoice not found.'], 404);
        }

        $gw = new GatewayController();
        $inv = $gw->getAndPollInvoice($im[1], true);
        if ($inv === null) {
            bp_json(['ok' => false, 'error' => 'Invoice not found.'], 404);
        }
        bp_json(['ok' => true, 'invoice' => $inv]);
    }

    bp_json(['ok' => false, 'error' => 'Unknown REST API v1 endpoint. Visit ' . bp_abs_url('api-docs') . ' for documentation.'], 404);
}

// 3. Live Blockchain Address / xPub Inspector & Checkout Invoice Watcher
if ($subRoute === 'lookup' && $method === 'GET') {
    $q = (string) ($_GET['q'] ?? '');
    $node = new BlockchainNodeService();
    bp_json($node->lookupAddressSummary($q));
}

if ($subRoute === 'invoice-status' && $method === 'GET') {
    $invId = (string) ($_GET['invoice_id'] ?? '');
    $gw = new GatewayController();
    $inv = $gw->getAndPollInvoice($invId, true);
    if ($inv === null) {
        bp_json(['ok' => false, 'error' => 'Invoice not found.'], 404);
    }
    bp_json(['ok' => true, 'invoice' => $inv]);
}

if ($subRoute === 'switch-coin' && $method === 'POST') {
    Security::requireCsrf();
    $payload = Security::input();
    $invId = (string) ($payload['invoice_id'] ?? '');
    $coin  = (string) ($payload['currency'] ?? '');
    $gw = new GatewayController();
    $res = $gw->switchInvoiceCoin($invId, $coin, true);
    bp_json($res, ($res['ok'] ?? false) ? 200 : 422);
}

// 4. Zero-Reload SPA Reactive Dispatcher (`POST /api/spa`)
if ($subRoute === 'spa' && $method === 'POST') {
    Security::requireCsrf();
    $user = AuthController::user();
    if ($user === null) {
        bp_json(['ok' => false, 'error' => 'Session expired. Please sign in again.', 'redirect' => bp_url('login')], 401);
    }
    $payload = Security::input();
    $action = (string) ($payload['action'] ?? 'refresh');
    $manager = new DashboardManager();
    $res = $manager->dispatch($user, $action, $payload);
    bp_json($res, ($res['ok'] ?? false) ? 200 : 422);
}

bp_json(['ok' => false, 'error' => 'Endpoint not found.'], 404);

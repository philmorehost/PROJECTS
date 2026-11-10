<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Settings;
use App\Support\ZipBuilder;

/**
 * Generates ready-to-install e-commerce, billing, CMS, bot, and PHP SDK integration packages (.zip)
 * pre-configured with this BlockPayne instance's live base URL.
 *
 * Supported ecosystems:
 *  - php-sdk      : Universal PHP 8+ SDK + Vanilla PHP, Laravel, Symfony, CodeIgniter 4 & Slim examples
 *  - whmcs        : WHMCS 8.x Payment Gateway Module + HMAC Callback
 *  - woocommerce  : WordPress / WooCommerce Payment Gateway Plugin (`WC_Payment_Gateway`)
 *  - fossbilling  : FOSSBilling Payment Adapter (`Payment_Adapter_BlockPayne`)
 *  - prestashop   : PrestaShop 1.7 / 8.x Payment Module (`PaymentModule`)
 *  - joomla       : Joomla 4/5 VirtueMart Plugin + Standalone Joomla/HikaShop Bridge
 *  - telegram-bot : Telegram Crypto Shop Bot (PHP cPanel Webhook Bot + Python 3 FastAPI Bot)
 *  - invoiceninja : Invoice Ninja v5 Crypto Checkout & Payment Auto-Recorder Bridge
 *  - shopify      : Shopify Custom Payment Method + Admin API Auto-Capture Webhook Bridge
 *  - wix          : Wix eCommerce / Wix Velo Backend & HTTP Webhook Integration
 */
final class IntegrationKitService
{
    /**
     * Catalog of all official downloadable integrations for UI cards & docs.
     *
     * @return list<array{slug:string,name:string,category:string,summary:string,filename:string,steps:list<string>}>
     */
    public static function catalog(): array
    {
        return [
            [
                'slug'     => 'whmcs',
                'name'     => 'WHMCS',
                'category' => 'Web Hosting & Billing',
                'summary'  => 'Drop-in WHMCS 8.x gateway module with multi-coin checkout & signed callback auto-activation.',
                'filename' => 'blockpayne-whmcs-gateway.zip',
                'steps'    => [
                    'Extract `modules/gateways/blockpayne.php` and `modules/gateways/callback/blockpayne.php` into your WHMCS root.',
                    'In WHMCS Admin -> System Settings -> Payment Gateways, activate BlockPayne Crypto Gateway.',
                    'Paste your `bp_live_...` API Key and Webhook Secret, then save.',
                ],
            ],
            [
                'slug'     => 'woocommerce',
                'name'     => 'WordPress (WooCommerce)',
                'category' => 'E-Commerce CMS',
                'summary'  => 'Installable WordPress plugin (`WC_Payment_Gateway`) with automatic order completion via HMAC webhook.',
                'filename' => 'blockpayne-woocommerce.zip',
                'steps'    => [
                    'In WordPress Admin -> Plugins -> Add New -> Upload Plugin, upload `blockpayne-woocommerce.zip` and Activate.',
                    'Open WooCommerce -> Settings -> Payments -> BlockPayne Crypto and click Manage.',
                    'Enter your API Key and Webhook Secret; webhook URL `/?wc-api=wc_gateway_blockpayne` is configured automatically.',
                ],
            ],
            [
                'slug'     => 'fossbilling',
                'name'     => 'FOSSBilling',
                'category' => 'Web Hosting & Billing',
                'summary'  => 'Native `Payment_Adapter_BlockPayne` class for FOSSBilling 0.6+ with instant invoice settlement.',
                'filename' => 'blockpayne-fossbilling.zip',
                'steps'    => [
                    'Upload `library/Payment/Adapter/BlockPayne.php` into your FOSSBilling installation directory.',
                    'In FOSSBilling Admin -> System -> Payment Gateways -> New Payment Gateway, enable BlockPayne.',
                    'Enter your BlockPayne API Key and Webhook Secret.',
                ],
            ],
            [
                'slug'     => 'prestashop',
                'name'     => 'PrestaShop 1.7 / 8',
                'category' => 'E-Commerce CMS',
                'summary'  => 'Standard PrestaShop `PaymentModule` with checkout PaymentOption, redirect controller & webhook validator.',
                'filename' => 'blockpayne-prestashop.zip',
                'steps'    => [
                    'In PrestaShop Back Office -> Modules -> Module Manager -> Upload a module, select `blockpayne-prestashop.zip`.',
                    'Click Configure and enter your BlockPayne API Key and Webhook Secret.',
                    'Enable desired currencies under Payment -> Preferences.',
                ],
            ],
            [
                'slug'     => 'joomla',
                'name'     => 'Joomla (VirtueMart & Bridge)',
                'category' => 'CMS & E-Commerce',
                'summary'  => 'Joomla 4/5 VirtueMart payment plugin XML/PHP package plus a universal standalone Joomla/HikaShop bridge.',
                'filename' => 'blockpayne-joomla.zip',
                'steps'    => [
                    'For VirtueMart: System -> Install -> Extensions -> upload the `plg_vmpayment_blockpayne` folder or zip, then enable plugin.',
                    'For HikaShop / J2Store / Custom Joomla articles: drop `blockpayne-joomla-bridge.php` in your site root and set your API keys.',
                ],
            ],
            [
                'slug'     => 'shopify',
                'name'     => 'Shopify',
                'category' => 'Hosted Commerce',
                'summary'  => 'Custom Manual Payment Method + lightweight PHP/Admin-API Webhook Bridge that marks Shopify orders Paid.',
                'filename' => 'blockpayne-shopify-bridge.zip',
                'steps'    => [
                    'In Shopify Admin -> Settings -> Payments -> Manual payment methods, create "Pay with Crypto (BlockPayne)".',
                    'Host `shopify-bridge.php` (on the same cPanel as BlockPayne or your bridge domain) with your Shopify Admin API token & BlockPayne keys.',
                    'Add the Customer Events / Thank-You redirect snippet from `README.md` so unpaid crypto orders launch BlockPayne and auto-mark Paid.',
                ],
            ],
            [
                'slug'     => 'wix',
                'name'     => 'Wix (Velo / eCommerce)',
                'category' => 'Hosted Commerce',
                'summary'  => 'Wix Velo backend web module (`blockpayne.web.js`) + `http-functions.js` HMAC webhook endpoint.',
                'filename' => 'blockpayne-wix-velo.zip',
                'steps'    => [
                    'Enable Dev Mode (Velo) in Wix Editor and store `BLOCKPAYNE_API_KEY` & `BLOCKPAYNE_WEBHOOK_SECRET` in Wix Secrets Manager.',
                    'Copy `backend/blockpayne.web.js` and `backend/http-functions.js` into your Wix `backend/` folder.',
                    'Call `startCryptoCheckout()` from your Wix checkout/pay button; Wix receives confirmed webhooks at `/_functions/blockpayneWebhook`.',
                ],
            ],
            [
                'slug'     => 'invoiceninja',
                'name'     => 'Invoice Ninja v5',
                'category' => 'Invoicing & ERP',
                'summary'  => 'Self-hosted & cloud Invoice Ninja v5 crypto checkout bridge that records confirmed payments via `/api/v1/payments`.',
                'filename' => 'blockpayne-invoiceninja-v5.zip',
                'steps'    => [
                    'Create an API Token in Invoice Ninja v5 (Settings -> Account Management -> API Tokens).',
                    'Upload `invoiceninja-bridge.php` and configure your Invoice Ninja URL/token and BlockPayne keys.',
                    'Add the "Pay with Crypto" link (`?invoice_id=$invoice.id`) to your Invoice Ninja email/portal template.',
                ],
            ],
            [
                'slug'     => 'telegram-bot',
                'name'     => 'Telegram Shop Bot',
                'category' => 'Messaging & Bots',
                'summary'  => 'Complete automated Telegram digital-store bot in BOTH PHP (cPanel zero-VPS) and Python 3 (FastAPI/httpx).',
                'filename' => 'blockpayne-telegram-shop-bot.zip',
                'steps'    => [
                    'Create a bot via @BotFather on Telegram to obtain your `TELEGRAM_BOT_TOKEN`.',
                    'Choose `php-cpanel-bot/bot.php` (runs on shared cPanel!) or `python-bot/bot.py` and enter your BlockPayne keys.',
                    'Register your Telegram webhook URL; buyers browse products, pay in BTC/USDT/LTC/ETH, and receive instant automated delivery in chat!',
                ],
            ],
            [
                'slug'     => 'php-sdk',
                'name'     => 'PHP SDK (Laravel, Symfony, CI4, Core PHP)',
                'category' => 'Developer SDK',
                'summary'  => 'Zero-dependency PHP 8+ `BlockPayneClient.php` SDK with copy-ready controllers for Laravel, Symfony, CodeIgniter 4 & Vanilla PHP.',
                'filename' => 'blockpayne-php-sdk.zip',
                'steps'    => [
                    'Drop `src/BlockPayneClient.php` into your PHP project (zero Composer dependencies required).',
                    'Initialize `new BlockPayneClient($apiKey, $webhookSecret, $gatewayUrl)` to create invoices, poll status, or build signed HTML checkout forms.',
                    'See `examples/` for Laravel 11/12, Symfony, CodeIgniter 4, and Vanilla PHP controllers.',
                ],
            ],
        ];
    }

    /**
     * Build a binary `.zip` package for a given integration slug.
     *
     * @return array{filename:string,binary:string}|null
     */
    public function buildZip(string $slug): ?array
    {
        $slug = strtolower(trim($slug));
        $gatewayUrl = rtrim(bp_abs_url(''), '/');
        $siteTitle = Settings::get('site_title', 'BlockPayne');
        $zip = new ZipBuilder();

        switch ($slug) {
            case 'php-sdk':
                $this->populatePhpSdk($zip, $gatewayUrl, $siteTitle);
                return ['filename' => 'blockpayne-php-sdk.zip', 'binary' => $zip->build()];

            case 'whmcs':
                $this->populateWhmcs($zip, $gatewayUrl, $siteTitle);
                return ['filename' => 'blockpayne-whmcs-gateway.zip', 'binary' => $zip->build()];

            case 'woocommerce':
                $this->populateWooCommerce($zip, $gatewayUrl, $siteTitle);
                return ['filename' => 'blockpayne-woocommerce.zip', 'binary' => $zip->build()];

            case 'fossbilling':
                $this->populateFossBilling($zip, $gatewayUrl, $siteTitle);
                return ['filename' => 'blockpayne-fossbilling.zip', 'binary' => $zip->build()];

            case 'prestashop':
                $this->populatePrestaShop($zip, $gatewayUrl, $siteTitle);
                return ['filename' => 'blockpayne-prestashop.zip', 'binary' => $zip->build()];

            case 'joomla':
                $this->populateJoomla($zip, $gatewayUrl, $siteTitle);
                return ['filename' => 'blockpayne-joomla.zip', 'binary' => $zip->build()];

            case 'telegram-bot':
                $this->populateTelegramBot($zip, $gatewayUrl, $siteTitle);
                return ['filename' => 'blockpayne-telegram-shop-bot.zip', 'binary' => $zip->build()];

            case 'invoiceninja':
                $this->populateInvoiceNinja($zip, $gatewayUrl, $siteTitle);
                return ['filename' => 'blockpayne-invoiceninja-v5.zip', 'binary' => $zip->build()];

            case 'shopify':
                $this->populateShopify($zip, $gatewayUrl, $siteTitle);
                return ['filename' => 'blockpayne-shopify-bridge.zip', 'binary' => $zip->build()];

            case 'wix':
                $this->populateWix($zip, $gatewayUrl, $siteTitle);
                return ['filename' => 'blockpayne-wix-velo.zip', 'binary' => $zip->build()];

            default:
                return null;
        }
    }

    private function populatePhpSdk(ZipBuilder $zip, string $gatewayUrl, string $siteTitle): void
    {
        $clientPhp = <<<PHP
<?php
declare(strict_types=1);

namespace BlockPayne;

use RuntimeException;

/**
 * {$siteTitle} Universal PHP 8.0+ SDK (Zero external dependencies).
 * Compatible with Vanilla PHP, Laravel, Symfony, CodeIgniter 4, CakePHP, Yii, and Slim.
 */
final class BlockPayneClient
{
    private string \$apiKey;
    private string \$webhookSecret;
    private string \$baseUrl;

    public function __construct(string \$apiKey, string \$webhookSecret = '', string \$baseUrl = '{$gatewayUrl}')
    {
        \$this->apiKey = trim(\$apiKey);
        \$this->webhookSecret = trim(\$webhookSecret);
        \$this->baseUrl = rtrim(trim(\$baseUrl), '/');
    }

    /**
     * Create a non-custodial crypto invoice.
     * Pass `'currency' => 'AUTO'` to let the customer switch between all active coins on checkout.
     *
     * @param array<string, mixed> \$params
     * @return array<string, mixed>
     */
    public function createInvoice(array \$params): array
    {
        return \$this->request('POST', '/api/v1/invoices', \$params);
    }

    /** @return array<string, mixed> */
    public function getInvoice(string \$invoiceId): array
    {
        return \$this->request('GET', '/api/v1/invoices/' . rawurlencode(\$invoiceId));
    }

    /**
     * @param array{status?:string,order_id?:string,limit?:int} \$filters
     * @return array<string, mixed>
     */
    public function listInvoices(array \$filters = []): array
    {
        \$qs = \$filters ? ('?' . http_build_query(\$filters)) : '';
        return \$this->request('GET', '/api/v1/invoices' . \$qs);
    }

    /** @return array<string, mixed> */
    public function getRates(): array
    {
        return \$this->request('GET', '/api/v1/rates');
    }

    /** @return array<string, mixed> */
    public function getBalance(): array
    {
        return \$this->request('GET', '/api/v1/balance');
    }

    /**
     * Verify `X-BlockPayne-Signature` HMAC-SHA256 header against raw request body.
     *
     * @return array<string, mixed> Verified decoded JSON payload
     */
    public function verifyWebhook(string \$rawBody, string \$signatureHeader): array
    {
        if (\$this->webhookSecret === '') {
            throw new RuntimeException('Webhook secret is required to verify signatures.');
        }
        \$expected = hash_hmac('sha256', \$rawBody, \$this->webhookSecret);
        if (!hash_equals(\$expected, strtolower(trim(\$signatureHeader)))) {
            throw new RuntimeException('Invalid X-BlockPayne-Signature.');
        }
        \$decoded = json_decode(\$rawBody, true);
        if (!is_array(\$decoded)) {
            throw new RuntimeException('Malformed JSON webhook body.');
        }
        return \$decoded;
    }

    /**
     * Render an HMAC-signed HTML POST form that redirects the customer directly to `/pay/hosted`
     * without making a server-to-server cURL call first.
     *
     * @param array<string, mixed> \$fields
     */
    public function renderHostedForm(array \$fields, string \$buttonLabel = 'Pay with Crypto'): string
    {
        \$amount = number_format((float) (\$fields['amount'] ?? 0), 2, '.', '');
        \$fiat   = strtoupper((string) (\$fields['fiat_currency'] ?? 'USD'));
        \$order  = (string) (\$fields['order_id'] ?? '');
        \$ts     = (string) time();
        \$sig    = hash_hmac('sha256', \$this->apiKey . '|' . \$amount . '|' . \$fiat . '|' . \$order . '|' . \$ts, \$this->webhookSecret);

        \$inputs = [
            'api_key'       => \$this->apiKey,
            'amount'        => \$amount,
            'fiat_currency' => \$fiat,
            'currency'      => (string) (\$fields['currency'] ?? 'AUTO'),
            'order_id'      => \$order,
            'title'         => (string) (\$fields['title'] ?? ('Order #' . \$order)),
            'buyer_email'   => (string) (\$fields['buyer_email'] ?? ''),
            'callback_url'  => (string) (\$fields['callback_url'] ?? ''),
            'redirect_url'  => (string) (\$fields['redirect_url'] ?? ''),
            'timestamp'     => \$ts,
            'signature'     => \$sig,
        ];

        \$html = '<form method="post" action="' . htmlspecialchars(\$this->baseUrl . '/pay/hosted', ENT_QUOTES, 'UTF-8') . '">';
        foreach (\$inputs as \$k => \$v) {
            \$html .= '<input type="hidden" name="' . htmlspecialchars(\$k, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars(\$v, ENT_QUOTES, 'UTF-8') . '">';
        }
        \$html .= '<button type="submit">' . htmlspecialchars(\$buttonLabel, ENT_QUOTES, 'UTF-8') . '</button></form>';
        return \$html;
    }

    /**
     * @param array<string, mixed>|null \$body
     * @return array<string, mixed>
     */
    private function request(string \$method, string \$path, ?array \$body = null): array
    {
        \$ch = curl_init(\$this->baseUrl . \$path);
        if (\$ch === false) {
            throw new RuntimeException('Unable to initialize cURL.');
        }
        \$headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . \$this->apiKey,
        ];
        \$opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => \$method,
            CURLOPT_TIMEOUT        => 20,
        ];
        if (\$body !== null) {
            \$headers[] = 'Content-Type: application/json';
            \$opts[CURLOPT_POSTFIELDS] = json_encode(\$body, JSON_UNESCAPED_SLASHES);
        }
        \$opts[CURLOPT_HTTPHEADER] = \$headers;
        curl_setopt_array(\$ch, \$opts);
        \$raw = curl_exec(\$ch);
        \$err = curl_error(\$ch);
        curl_close(\$ch);

        if (!is_string(\$raw) || \$raw === '') {
            throw new RuntimeException('Gateway connection error: ' . \$err);
        }
        \$data = json_decode(\$raw, true);
        if (!is_array(\$data)) {
            throw new RuntimeException('Invalid JSON response from gateway.');
        }
        return \$data;
    }
}
PHP;

        $vanillaPhp = <<<PHP
<?php
require_once __DIR__ . '/../src/BlockPayneClient.php';

use BlockPayne\BlockPayneClient;

\$client = new BlockPayneClient(
    getenv('BLOCKPAYNE_API_KEY') ?: 'bp_live_your_api_key',
    getenv('BLOCKPAYNE_WEBHOOK_SECRET') ?: 'your_webhook_secret',
    '{$gatewayUrl}'
);

\$invoice = \$client->createInvoice([
    'amount'        => 49.99,
    'fiat_currency' => 'USD',
    'currency'      => 'AUTO', // Buyer can switch between BTC, USDT-TRC20, ETH, LTC, BCH on checkout
    'order_id'      => 'ORD-1001',
    'title'         => 'Pro Annual Plan',
    'callback_url'  => 'https://yourwebsite.com/webhook.php',
    'redirect_url'  => 'https://yourwebsite.com/thank-you.php?order=ORD-1001',
    'metadata'      => ['customer_id' => 42],
]);

if (!empty(\$invoice['ok'])) {
    header('Location: ' . \$invoice['checkout_url']);
    exit;
}
echo 'Error: ' . (\$invoice['error'] ?? 'Unable to create invoice');
PHP;

        $laravelPhp = <<<PHP
<?php
// app/Http/Controllers/CryptoPaymentController.php (Laravel 10 / 11 / 12)
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class CryptoPaymentController extends Controller
{
    public function checkout(Request \$request)
    {
        \$response = Http::withToken(config('services.blockpayne.key'))
            ->post('{$gatewayUrl}/api/v1/invoices', [
                'amount'        => \$request->input('amount', 49.99),
                'fiat_currency' => 'USD',
                'currency'      => 'AUTO',
                'order_id'      => (string) \$request->input('order_id'),
                'callback_url'  => route('webhooks.blockpayne'),
                'redirect_url'  => route('orders.complete', ['order' => \$request->input('order_id')]),
                'metadata'      => ['user_id' => \$request->user()?->id],
            ])->json();

        abort_unless(!empty(\$response['ok']), 422, \$response['error'] ?? 'Gateway error');
        return redirect()->away(\$response['checkout_url']);
    }

    public function webhook(Request \$request)
    {
        \$raw = \$request->getContent();
        \$sig = (string) \$request->header('X-BlockPayne-Signature', '');
        \$expected = hash_hmac('sha256', \$raw, (string) config('services.blockpayne.webhook_secret'));

        abort_unless(hash_equals(\$expected, strtolower(\$sig)), 401, 'Invalid signature');

        \$payload = \$request->json()->all();
        if ((\$payload['event'] ?? '') === 'invoice.confirmed' && (\$payload['status'] ?? '') === 'confirmed') {
            // Order::where('number', \$payload['order_id'])->update(['status' => 'paid', 'tx_hash' => \$payload['tx_hash']]);
        }

        return response()->json(['received' => true]);
    }
}
PHP;

        $ci4Php = <<<PHP
<?php
// app/Controllers/CryptoPay.php (CodeIgniter 4)
namespace App\Controllers;

use CodeIgniter\Controller;

class CryptoPay extends Controller
{
    public function pay()
    {
        \$http = \Config\Services::curlrequest();
        \$res = \$http->post('{$gatewayUrl}/api/v1/invoices', [
            'headers' => ['Authorization' => 'Bearer ' . env('BLOCKPAYNE_API_KEY')],
            'json'    => [
                'amount'        => 29.00,
                'fiat_currency' => 'USD',
                'currency'      => 'AUTO',
                'order_id'      => 'CI4-5001',
                'callback_url'  => site_url('cryptopay/webhook'),
                'redirect_url'  => site_url('cryptopay/success'),
            ],
        ]);
        \$data = json_decode(\$res->getBody(), true);
        return redirect()->to(\$data['checkout_url']);
    }

    public function webhook()
    {
        \$raw = \$this->request->getBody();
        \$sig = \$this->request->getHeaderLine('X-BlockPayne-Signature');
        \$expected = hash_hmac('sha256', \$raw, (string) env('BLOCKPAYNE_WEBHOOK_SECRET'));
        if (!hash_equals(\$expected, strtolower(trim(\$sig)))) {
            return \$this->response->setStatusCode(401)->setJSON(['error' => 'Invalid signature']);
        }
        \$payload = json_decode(\$raw, true);
        if ((\$payload['status'] ?? '') === 'confirmed') {
            // Mark order \$payload['order_id'] paid
        }
        return \$this->response->setJSON(['received' => true]);
    }
}
PHP;

        $symfonyPhp = <<<PHP
<?php
// src/Controller/BlockPayneController.php (Symfony 6 / 7)
namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class BlockPayneController extends AbstractController
{
    #[Route('/pay/crypto/{orderId}', name: 'crypto_pay', methods: ['POST'])]
    public function pay(string \$orderId, HttpClientInterface \$http): RedirectResponse
    {
        \$res = \$http->request('POST', '{$gatewayUrl}/api/v1/invoices', [
            'auth_bearer' => \$_ENV['BLOCKPAYNE_API_KEY'],
            'json'        => [
                'amount'        => 75.00,
                'fiat_currency' => 'USD',
                'currency'      => 'AUTO',
                'order_id'      => \$orderId,
                'callback_url'  => 'https://yourdomain.com/webhooks/blockpayne',
                'redirect_url'  => 'https://yourdomain.com/orders/' . \$orderId,
            ],
        ])->toArray();

        return new RedirectResponse(\$res['checkout_url']);
    }

    #[Route('/webhooks/blockpayne', name: 'crypto_webhook', methods: ['POST'])]
    public function webhook(Request \$request): JsonResponse
    {
        \$raw = \$request->getContent();
        \$sig = (string) \$request->headers->get('X-BlockPayne-Signature', '');
        \$expected = hash_hmac('sha256', \$raw, \$_ENV['BLOCKPAYNE_WEBHOOK_SECRET'] ?? '');

        if (!hash_equals(\$expected, strtolower(trim(\$sig)))) {
            return new JsonResponse(['error' => 'Invalid signature'], 401);
        }

        \$data = json_decode(\$raw, true) ?: [];
        if ((\$data['status'] ?? '') === 'confirmed') {
            // Fulfill \$data['order_id']
        }
        return new JsonResponse(['received' => true]);
    }
}
PHP;

        $zip->addFile('blockpayne-php-sdk/src/BlockPayneClient.php', $clientPhp);
        $zip->addFile('blockpayne-php-sdk/examples/vanilla-php-checkout.php', $vanillaPhp);
        $zip->addFile('blockpayne-php-sdk/examples/LaravelController.php', $laravelPhp);
        $zip->addFile('blockpayne-php-sdk/examples/CodeIgniter4Controller.php', $ci4Php);
        $zip->addFile('blockpayne-php-sdk/examples/SymfonyController.php', $symfonyPhp);
        $zip->addFile('blockpayne-php-sdk/README.md', "# {$siteTitle} PHP SDK\n\nGateway Base URL: `{$gatewayUrl}`\nFull API Reference: `{$gatewayUrl}/api-docs`\n");
    }

    private function populateWhmcs(ZipBuilder $zip, string $gatewayUrl, string $siteTitle): void
    {
        $gatewayFile = <<<PHP
<?php
/**
 * {$siteTitle} Non-Custodial Crypto Payment Gateway Module for WHMCS 8.x
 * Upload to: /modules/gateways/blockpayne.php
 */
if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

function blockpayne_MetaData(): array
{
    return [
        'DisplayName'                 => '{$siteTitle} Cryptocurrency Gateway (BTC, USDT, ETH, LTC, BCH)',
        'APIVersion'                  => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage'            => false,
    ];
}

function blockpayne_config(): array
{
    return [
        'FriendlyName' => [
            'Type'  => 'System',
            'Value' => 'Cryptocurrency (BTC, USDT, ETH, LTC, BCH)',
        ],
        'gatewayUrl' => [
            'FriendlyName' => 'Gateway URL',
            'Type'         => 'text',
            'Size'         => '60',
            'Default'      => '{$gatewayUrl}',
            'Description'  => 'Your {$siteTitle} installation URL (no trailing slash).',
        ],
        'apiKey' => [
            'FriendlyName' => 'Live API Key',
            'Type'         => 'password',
            'Size'         => '60',
            'Description'  => 'Starts with bp_live_... from Dashboard -> API & Webhooks.',
        ],
        'webhookSecret' => [
            'FriendlyName' => 'Webhook Secret',
            'Type'         => 'password',
            'Size'         => '60',
            'Description'  => 'Used to verify X-BlockPayne-Signature HMAC-SHA256 callbacks.',
        ],
        'defaultCoin' => [
            'FriendlyName' => 'Initial Coin',
            'Type'         => 'dropdown',
            'Options'      => [
                'AUTO'       => 'Customer Selects Coin on Checkout (Recommended)',
                'USDT-TRC20' => 'USDT (TRC-20)',
                'BTC'        => 'Bitcoin (BTC)',
                'ETH'        => 'Ethereum (ETH)',
                'LTC'        => 'Litecoin (LTC)',
                'BCH'        => 'Bitcoin Cash (BCH)',
                'USDT-ERC20' => 'USDT (ERC-20)',
            ],
            'Default'      => 'AUTO',
        ],
    ];
}

function blockpayne_link(array \$params): string
{
    \$baseUrl       = rtrim(trim((string) (\$params['gatewayUrl'] ?: '{$gatewayUrl}')), '/');
    \$apiKey        = trim((string) (\$params['apiKey'] ?? ''));
    \$webhookSecret = trim((string) (\$params['webhookSecret'] ?? ''));
    \$coin          = trim((string) (\$params['defaultCoin'] ?: 'AUTO'));

    \$invoiceId   = (string) \$params['invoiceid'];
    \$amount      = number_format((float) \$params['amount'], 2, '.', '');
    \$currency    = strtoupper((string) \$params['currency']);
    \$systemUrl   = rtrim((string) \$params['systemurl'], '/');
    \$callbackUrl = \$systemUrl . '/modules/gateways/callback/blockpayne.php';
    \$returnUrl   = (string) \$params['returnurl'];
    \$email       = (string) (\$params['clientdetails']['email'] ?? '');
    \$description = (string) (\$params['description'] ?? ('Invoice #' . \$invoiceId));
    \$ts          = (string) time();

    // Signed Hosted POST Form -> creates live non-custodial invoice & redirects buyer immediately
    \$sig = hash_hmac('sha256', \$apiKey . '|' . \$amount . '|' . \$currency . '|' . \$invoiceId . '|' . \$ts, \$webhookSecret);

    \$fields = [
        'api_key'       => \$apiKey,
        'amount'        => \$amount,
        'fiat_currency' => \$currency,
        'currency'      => \$coin,
        'order_id'      => \$invoiceId,
        'title'         => \$description,
        'buyer_email'   => \$email,
        'callback_url'  => \$callbackUrl,
        'redirect_url'  => \$returnUrl,
        'timestamp'     => \$ts,
        'signature'     => \$sig,
    ];

    \$html = '<form method="post" action="' . htmlspecialchars(\$baseUrl . '/pay/hosted', ENT_QUOTES, 'UTF-8') . '">';
    foreach (\$fields as \$k => \$v) {
        \$html .= '<input type="hidden" name="' . htmlspecialchars(\$k, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars(\$v, ENT_QUOTES, 'UTF-8') . '">';
    }
    \$html .= '<button type="submit" class="btn btn-success">' . htmlspecialchars(\$params['langpaynow'] ?? 'Pay with Crypto', ENT_QUOTES, 'UTF-8') . '</button>';
    \$html .= '</form>';
    return \$html;
}
PHP;

        $callbackFile = <<<PHP
<?php
/**
 * {$siteTitle} Signed Webhook Callback for WHMCS 8.x
 * Upload to: /modules/gateways/callback/blockpayne.php
 */
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

\$gatewayModuleName = 'blockpayne';
\$gatewayParams = getGatewayVariables(\$gatewayModuleName);
if (empty(\$gatewayParams['type'])) {
    http_response_code(503);
    die('Module Not Activated');
}

\$rawBody = (string) file_get_contents('php://input');
\$sigHeader = (string) (\$_SERVER['HTTP_X_BLOCKPAYNE_SIGNATURE'] ?? '');
\$expected = hash_hmac('sha256', \$rawBody, trim((string) (\$gatewayParams['webhookSecret'] ?? '')));

if (\$sigHeader === '' || !hash_equals(\$expected, strtolower(trim(\$sigHeader)))) {
    logTransaction(\$gatewayParams['name'], \$rawBody, 'Invalid HMAC Signature');
    http_response_code(401);
    die('Invalid signature');
}

\$data = json_decode(\$rawBody, true);
if (!is_array(\$data)) {
    http_response_code(400);
    die('Invalid JSON');
}

\$event     = (string) (\$data['event'] ?? '');
\$status    = (string) (\$data['status'] ?? '');
\$invoiceId = (int) (\$data['order_id'] ?? 0);
\$txId      = (string) (\$data['tx_hash'] ?: \$data['invoice_id']);
\$amount    = (float) (\$data['fiat_amount'] ?? 0);

if (\$event === 'invoice.confirmed' && \$status === 'confirmed' && \$invoiceId > 0) {
    \$invoiceId = checkCbInvoiceID(\$invoiceId, \$gatewayParams['name']);
    checkCbTransID(\$txId);
    logTransaction(\$gatewayParams['name'], \$data, 'Confirmed');
    addInvoicePayment(\$invoiceId, \$txId, \$amount, 0.00, \$gatewayModuleName);
}

header('Content-Type: application/json');
echo json_encode(['received' => true]);
PHP;

        $zip->addFile('modules/gateways/blockpayne.php', $gatewayFile);
        $zip->addFile('modules/gateways/callback/blockpayne.php', $callbackFile);
        $zip->addFile('README.md', "# {$siteTitle} for WHMCS\n\n1. Upload `modules/` into your WHMCS root directory.\n2. Activate **{$siteTitle} Cryptocurrency Gateway** in **System Settings -> Payment Gateways**.\n3. Enter Gateway URL `{$gatewayUrl}`, your `bp_live_...` API Key, and your Webhook Secret.\n");
    }

    private function populateWooCommerce(ZipBuilder $zip, string $gatewayUrl, string $siteTitle): void
    {
        $wooPlugin = <<<PHP
<?php
/**
 * Plugin Name: {$siteTitle} Crypto Payment Gateway for WooCommerce
 * Description: Accept non-custodial BTC, BCH, LTC, ETH, USDT (TRC-20 & ERC-20) directly to your wallet via {$siteTitle}.
 * Version: 1.0.0
 * Author: {$siteTitle}
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('plugins_loaded', function () {
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    class WC_Gateway_BlockPayne extends WC_Payment_Gateway
    {
        public string \$gateway_url;
        public string \$api_key;
        public string \$webhook_secret;
        public string \$default_coin;

        public function __construct()
        {
            \$this->id                 = 'blockpayne';
            \$this->has_fields         = false;
            \$this->method_title       = '{$siteTitle} Crypto Gateway';
            \$this->method_description = 'Non-custodial cryptocurrency payments (BTC, USDT-TRC20, ETH, LTC, BCH) settled directly to your wallet.';

            \$this->init_form_fields();
            \$this->init_settings();

            \$this->title          = (string) \$this->get_option('title', 'Pay with Cryptocurrency (BTC, USDT, ETH, LTC)');
            \$this->description    = (string) \$this->get_option('description', 'Pay securely with Bitcoin, USDT, Ethereum, Litecoin, or Bitcoin Cash.');
            \$this->gateway_url    = rtrim((string) \$this->get_option('gateway_url', '{$gatewayUrl}'), '/');
            \$this->api_key        = trim((string) \$this->get_option('api_key', ''));
            \$this->webhook_secret = trim((string) \$this->get_option('webhook_secret', ''));
            \$this->default_coin   = (string) \$this->get_option('default_coin', 'AUTO');

            add_action('woocommerce_update_options_payment_gateways_' . \$this->id, [\$this, 'process_admin_options']);
            add_action('woocommerce_api_wc_gateway_blockpayne', [\$this, 'handle_webhook']);
        }

        public function init_form_fields(): void
        {
            \$this->form_fields = [
                'enabled' => [
                    'title'   => 'Enable/Disable',
                    'type'    => 'checkbox',
                    'label'   => 'Enable {$siteTitle} Crypto Payments',
                    'default' => 'yes',
                ],
                'title' => [
                    'title'   => 'Checkout Title',
                    'type'    => 'text',
                    'default' => 'Cryptocurrency (BTC, USDT, ETH, LTC, BCH)',
                ],
                'description' => [
                    'title'   => 'Description',
                    'type'    => 'textarea',
                    'default' => 'Instant QR & direct wallet payment via {$siteTitle}.',
                ],
                'gateway_url' => [
                    'title'   => 'Gateway URL',
                    'type'    => 'text',
                    'default' => '{$gatewayUrl}',
                ],
                'api_key' => [
                    'title'       => 'Live API Key',
                    'type'        => 'password',
                    'description' => 'Paste your bp_live_... key from Dashboard -> API & Webhooks.',
                ],
                'webhook_secret' => [
                    'title'       => 'Webhook Secret',
                    'type'        => 'password',
                    'description' => 'Used to verify X-BlockPayne-Signature callbacks.',
                ],
                'default_coin' => [
                    'title'   => 'Default Coin',
                    'type'    => 'select',
                    'default' => 'AUTO',
                    'options' => [
                        'AUTO'       => 'Let Buyer Choose Coin on Checkout (Recommended)',
                        'USDT-TRC20' => 'USDT (TRC-20)',
                        'BTC'        => 'Bitcoin (BTC)',
                        'ETH'        => 'Ethereum (ETH)',
                        'LTC'        => 'Litecoin (LTC)',
                        'BCH'        => 'Bitcoin Cash (BCH)',
                        'USDT-ERC20' => 'USDT (ERC-20)',
                    ],
                ],
            ];
        }

        public function process_payment(\$order_id): array
        {
            \$order = wc_get_order(\$order_id);
            \$payload = [
                'amount'        => (float) \$order->get_total(),
                'fiat_currency' => strtoupper((string) \$order->get_currency()),
                'currency'      => \$this->default_coin,
                'order_id'      => (string) \$order->get_id(),
                'title'         => get_bloginfo('name') . ' Order #' . \$order->get_order_number(),
                'buyer_email'   => (string) \$order->get_billing_email(),
                'callback_url'  => WC()->api_request_url('WC_Gateway_BlockPayne'),
                'redirect_url'  => \$this->get_return_url(\$order),
                'metadata'      => ['order_key' => \$order->get_order_key()],
            ];

            \$res = wp_remote_post(\$this->gateway_url . '/api/v1/invoices', [
                'timeout' => 20,
                'headers' => [
                    'Authorization' => 'Bearer ' . \$this->api_key,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ],
                'body' => wp_json_encode(\$payload),
            ]);

            if (is_wp_error(\$res)) {
                wc_add_notice('Payment gateway error: ' . \$res->get_error_message(), 'error');
                return ['result' => 'fail'];
            }

            \$data = json_decode((string) wp_remote_retrieve_body(\$res), true);
            if (empty(\$data['ok']) || empty(\$data['checkout_url'])) {
                wc_add_notice('Unable to initialize crypto checkout: ' . (\$data['error'] ?? 'Unknown error'), 'error');
                return ['result' => 'fail'];
            }

            \$order->update_status('on-hold', 'Awaiting {$siteTitle} blockchain payment (' . \$data['invoice_id'] . ').');
            \$order->update_meta_data('_blockpayne_invoice_id', \$data['invoice_id']);
            \$order->save();

            WC()->cart->empty_cart();
            return [
                'result'   => 'success',
                'redirect' => \$data['checkout_url'],
            ];
        }

        public function handle_webhook(): void
        {
            \$raw = (string) file_get_contents('php://input');
            \$sig = isset(\$_SERVER['HTTP_X_BLOCKPAYNE_SIGNATURE']) ? sanitize_text_field(wp_unslash(\$_SERVER['HTTP_X_BLOCKPAYNE_SIGNATURE'])) : '';
            \$expected = hash_hmac('sha256', \$raw, \$this->webhook_secret);

            if (\$sig === '' || !hash_equals(\$expected, strtolower(trim(\$sig)))) {
                status_header(401);
                exit('Invalid signature');
            }

            \$data = json_decode(\$raw, true);
            \$order = isset(\$data['order_id']) ? wc_get_order((int) \$data['order_id']) : false;
            if (\$order && (\$data['status'] ?? '') === 'confirmed') {
                if (!\$order->is_paid()) {
                    \$txHash = (string) (\$data['tx_hash'] ?: \$data['invoice_id']);
                    \$order->add_order_note('{$siteTitle} payment confirmed on-chain. TX: ' . \$txHash);
                    \$order->payment_complete(\$txHash);
                }
            } elseif (\$order && (\$data['event'] ?? '') === 'invoice.detected') {
                \$order->add_order_note('{$siteTitle}: Payment detected in mempool (0-conf), awaiting confirmation.');
            }

            wp_send_json(['received' => true]);
        }
    }

    add_filter('woocommerce_payment_gateways', function (array \$methods): array {
        \$methods[] = 'WC_Gateway_BlockPayne';
        return \$methods;
    });
});
PHP;

        $zip->addFile('blockpayne-woocommerce/blockpayne-woocommerce.php', $wooPlugin);
        $zip->addFile('blockpayne-woocommerce/README.txt', "{$siteTitle} for WordPress / WooCommerce\n\n1. Upload this ZIP in WordPress Admin -> Plugins -> Add New -> Upload Plugin.\n2. Activate the plugin and configure your API Key & Webhook Secret under WooCommerce -> Settings -> Payments.\n");
    }

    private function populateFossBilling(ZipBuilder $zip, string $gatewayUrl, string $siteTitle): void
    {
        $adapter = <<<PHP
<?php
/**
 * {$siteTitle} Payment Adapter for FOSSBilling 0.6+
 * Upload to: /library/Payment/Adapter/BlockPayne.php
 */
class Payment_Adapter_BlockPayne implements \FOSSBilling\InjectionAwareInterface
{
    protected ?\Pimple\Container \$di = null;
    private array \$config;

    public function __construct(array \$config)
    {
        \$this->config = \$config;
    }

    public function setDi(\Pimple\Container \$di): void
    {
        \$this->di = \$di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return \$this->di;
    }

    public static function getConfig(): array
    {
        return [
            'supports_one_time_payments' => true,
            'description'                => 'Accept BTC, USDT-TRC20, ETH, LTC, and BCH directly to your wallet via {$siteTitle}.',
            'form'                       => [
                'gateway_url' => [
                    'text', [
                        'label' => 'Gateway URL',
                        'value' => '{$gatewayUrl}',
                    ],
                ],
                'api_key' => [
                    'password', [
                        'label' => 'Live API Key (bp_live_...)',
                    ],
                ],
                'webhook_secret' => [
                    'password', [
                        'label' => 'Webhook Secret',
                    ],
                ],
            ],
        ];
    }

    public function getHtml(\$api_admin, \$invoice_id, \$subscription): string
    {
        \$invoiceModel = \$this->di['db']->load('Invoice', \$invoice_id);
        \$invoiceService = \$this->di['mod_service']('Invoice');
        \$total = number_format((float) \$invoiceService->getTotalWithTax(\$invoiceModel), 2, '.', '');
        \$currency = strtoupper((string) \$invoiceModel->currency);

        \$baseUrl = rtrim((string) (\$this->config['gateway_url'] ?? '{$gatewayUrl}'), '/');
        \$apiKey  = trim((string) (\$this->config['api_key'] ?? ''));
        \$secret  = trim((string) (\$this->config['webhook_secret'] ?? ''));
        \$ts      = (string) time();
        \$orderId = (string) \$invoice_id;
        \$sig     = hash_hmac('sha256', \$apiKey . '|' . \$total . '|' . \$currency . '|' . \$orderId . '|' . \$ts, \$secret);

        \$fields = [
            'api_key'       => \$apiKey,
            'amount'        => \$total,
            'fiat_currency' => \$currency,
            'currency'      => 'AUTO',
            'order_id'      => \$orderId,
            'title'         => 'Invoice #' . \$invoiceModel->nr,
            'buyer_email'   => (string) \$invoiceModel->buyer_email,
            'callback_url'  => \$this->config['notify_url'] ?? '',
            'redirect_url'  => \$this->config['thankyou_url'] ?? '',
            'timestamp'     => \$ts,
            'signature'     => \$sig,
        ];

        \$html = '<form method="post" action="' . htmlspecialchars(\$baseUrl . '/pay/hosted', ENT_QUOTES, 'UTF-8') . '">';
        foreach (\$fields as \$k => \$v) {
            \$html .= '<input type="hidden" name="' . htmlspecialchars(\$k, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars((string) \$v, ENT_QUOTES, 'UTF-8') . '">';
        }
        \$html .= '<button type="submit" class="btn btn-primary">Pay with Cryptocurrency</button></form>';
        return \$html;
    }

    public function processTransaction(\$api_admin, \$id, \$data, \$gateway_id): void
    {
        \$raw = (string) (\$data['http_raw_post_data'] ?? file_get_contents('php://input'));
        \$sig = (string) (\$_SERVER['HTTP_X_BLOCKPAYNE_SIGNATURE'] ?? '');
        \$expected = hash_hmac('sha256', \$raw, trim((string) (\$this->config['webhook_secret'] ?? '')));

        if (\$sig === '' || !hash_equals(\$expected, strtolower(trim(\$sig)))) {
            throw new \FOSSBilling\Exception('Invalid X-BlockPayne-Signature');
        }

        \$payload = json_decode(\$raw, true) ?: [];
        if ((\$payload['status'] ?? '') !== 'confirmed') {
            return;
        }

        \$tx = \$this->di['db']->getExistingModelById('Transaction', \$id);
        \$invoice = \$this->di['db']->getExistingModelById('Invoice', (int) \$payload['order_id']);

        \$tx->invoice_id = \$invoice->id;
        \$tx->txn_id     = (string) (\$payload['tx_hash'] ?: \$payload['invoice_id']);
        \$tx->txn_status = 'complete';
        \$tx->amount     = (float) \$payload['fiat_amount'];
        \$tx->currency   = (string) \$payload['fiat_currency'];
        \$tx->status     = 'processed';
        \$tx->updated_at = date('Y-m-d H:i:s');
        \$this->di['db']->store(\$tx);

        \$clientService = \$this->di['mod_service']('client');
        \$client = \$this->di['db']->getExistingModelById('Client', \$invoice->client_id);
        \$clientService->addFunds(\$client, \$tx->amount, '{$siteTitle} Crypto Payment #' . \$tx->txn_id, []);

        \$invoiceService = \$this->di['mod_service']('Invoice');
        \$invoiceService->payInvoiceWithCredits(\$invoice);
    }
}
PHP;

        $zip->addFile('library/Payment/Adapter/BlockPayne.php', $adapter);
        $zip->addFile('README.md', "# {$siteTitle} for FOSSBilling\n\n1. Copy `library/Payment/Adapter/BlockPayne.php` into your FOSSBilling root.\n2. Enable **BlockPayne** under **System -> Payment Gateways** and paste your API Key & Webhook Secret.\n");
    }

    private function populatePrestaShop(ZipBuilder $zip, string $gatewayUrl, string $siteTitle): void
    {
        $mainModule = <<<PHP
<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

class Blockpayne extends PaymentModule
{
    public function __construct()
    {
        \$this->name = 'blockpayne';
        \$this->tab = 'payments_gateways';
        \$this->version = '1.0.0';
        \$this->author = '{$siteTitle}';
        \$this->controllers = ['redirect', 'webhook'];
        \$this->currencies = true;
        \$this->currencies_mode = 'checkbox';
        \$this->bootstrap = true;
        parent::__construct();

        \$this->displayName = '{$siteTitle} Crypto Payment Gateway';
        \$this->description = 'Accept non-custodial BTC, USDT-TRC20, ETH, LTC & BCH directly to your wallet.';
    }

    public function install(): bool
    {
        Configuration::updateValue('BP_GATEWAY_URL', '{$gatewayUrl}');
        return parent::install() && \$this->registerHook('paymentOptions');
    }

    public function getContent(): string
    {
        \$out = '';
        if (Tools::isSubmit('submitBlockpayne')) {
            Configuration::updateValue('BP_GATEWAY_URL', rtrim((string) Tools::getValue('BP_GATEWAY_URL'), '/'));
            Configuration::updateValue('BP_API_KEY', trim((string) Tools::getValue('BP_API_KEY')));
            Configuration::updateValue('BP_WEBHOOK_SECRET', trim((string) Tools::getValue('BP_WEBHOOK_SECRET')));
            \$out .= \$this->displayConfirmation('Settings updated.');
        }

        \$url = htmlspecialchars((string) Configuration::get('BP_GATEWAY_URL'), ENT_QUOTES, 'UTF-8');
        \$key = htmlspecialchars((string) Configuration::get('BP_API_KEY'), ENT_QUOTES, 'UTF-8');
        \$sec = htmlspecialchars((string) Configuration::get('BP_WEBHOOK_SECRET'), ENT_QUOTES, 'UTF-8');

        return \$out . '<form method="post" class="defaultForm form-horizontal"><div class="panel"><div class="panel-heading">{$siteTitle} Settings</div>'
            . '<div class="form-group"><label class="control-label col-lg-3">Gateway URL</label><div class="col-lg-6"><input type="text" name="BP_GATEWAY_URL" value="' . \$url . '" class="form-control"></div></div>'
            . '<div class="form-group"><label class="control-label col-lg-3">Live API Key</label><div class="col-lg-6"><input type="password" name="BP_API_KEY" value="' . \$key . '" class="form-control"></div></div>'
            . '<div class="form-group"><label class="control-label col-lg-3">Webhook Secret</label><div class="col-lg-6"><input type="password" name="BP_WEBHOOK_SECRET" value="' . \$sec . '" class="form-control"></div></div>'
            . '<div class="panel-footer"><button type="submit" name="submitBlockpayne" class="btn btn-default pull-right">Save</button></div></div></form>';
    }

    public function hookPaymentOptions(array \$params): array
    {
        if (!\$this->active) {
            return [];
        }
        \$option = new PaymentOption();
        \$option->setModuleName(\$this->name)
            ->setCallToActionText('Pay with Cryptocurrency (BTC, USDT, ETH, LTC, BCH)')
            ->setAction(\$this->context->link->getModuleLink(\$this->name, 'redirect', [], true));
        return [\$option];
    }
}
PHP;

        $redirectCtrl = <<<PHP
<?php
class BlockpayneRedirectModuleFrontController extends ModuleFrontController
{
    public function postProcess(): void
    {
        \$cart = \$this->context->cart;
        \$customer = new Customer(\$cart->id_customer);
        \$currency = new Currency(\$cart->id_currency);
        \$amount = (float) \$cart->getOrderTotal(true, Cart::BOTH);

        \$this->module->validateOrder(
            (int) \$cart->id,
            (int) Configuration::get('PS_OS_BANKWIRE'),
            \$amount,
            \$this->module->displayName,
            null,
            [],
            (int) \$currency->id,
            false,
            \$customer->secure_key
        );

        \$orderId = (int) \$this->module->currentOrder;
        \$gatewayUrl = rtrim((string) Configuration::get('BP_GATEWAY_URL'), '/');
        \$apiKey = (string) Configuration::get('BP_API_KEY');

        \$payload = json_encode([
            'amount'        => \$amount,
            'fiat_currency' => strtoupper((string) \$currency->iso_code),
            'currency'      => 'AUTO',
            'order_id'      => (string) \$orderId,
            'buyer_email'   => (string) \$customer->email,
            'callback_url'  => \$this->context->link->getModuleLink('blockpayne', 'webhook', [], true),
            'redirect_url'  => \$this->context->link->getPageLink('order-confirmation', true, null, [
                'id_cart'   => (int) \$cart->id,
                'id_module' => (int) \$this->module->id,
                'id_order'  => \$orderId,
                'key'       => \$customer->secure_key,
            ]),
        ]);

        \$ch = curl_init(\$gatewayUrl . '/api/v1/invoices');
        curl_setopt_array(\$ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => \$payload,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . \$apiKey, 'Content-Type: application/json'],
        ]);
        \$res = json_decode((string) curl_exec(\$ch), true);
        curl_close(\$ch);

        if (!empty(\$res['checkout_url'])) {
            Tools::redirectLink(\$res['checkout_url']);
        }
        Tools::redirect('index.php?controller=order');
    }
}
PHP;

        $webhookCtrl = <<<PHP
<?php
class BlockpayneWebhookModuleFrontController extends ModuleFrontController
{
    public function postProcess(): void
    {
        \$raw = (string) file_get_contents('php://input');
        \$sig = (string) (\$_SERVER['HTTP_X_BLOCKPAYNE_SIGNATURE'] ?? '');
        \$expected = hash_hmac('sha256', \$raw, (string) Configuration::get('BP_WEBHOOK_SECRET'));

        if (\$sig === '' || !hash_equals(\$expected, strtolower(trim(\$sig)))) {
            http_response_code(401);
            die('Invalid signature');
        }

        \$data = json_decode(\$raw, true) ?: [];
        if ((\$data['status'] ?? '') === 'confirmed' && !empty(\$data['order_id'])) {
            \$order = new Order((int) \$data['order_id']);
            if (Validate::isLoadedObject(\$order)) {
                \$order->setCurrentState((int) Configuration::get('PS_OS_PAYMENT'));
            }
        }

        header('Content-Type: application/json');
        die(json_encode(['received' => true]));
    }
}
PHP;

        $zip->addFile('blockpayne/blockpayne.php', $mainModule);
        $zip->addFile('blockpayne/controllers/front/redirect.php', $redirectCtrl);
        $zip->addFile('blockpayne/controllers/front/webhook.php', $webhookCtrl);
        $zip->addFile('blockpayne/README.md', "# {$siteTitle} for PrestaShop 1.7 / 8\n\nUpload this ZIP directly in **PrestaShop Back Office -> Modules -> Module Manager -> Upload a module**.\n");
    }

    private function populateJoomla(ZipBuilder $zip, string $gatewayUrl, string $siteTitle): void
    {
        $xml = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<extension version="4.0" type="plugin" group="vmpayment" method="upgrade">
    <name>VM Payment - {$siteTitle} Crypto Gateway</name>
    <version>1.0.0</version>
    <author>{$siteTitle}</author>
    <description>Accept non-custodial BTC, USDT-TRC20, ETH, LTC &amp; BCH in Joomla VirtueMart.</description>
    <files>
        <filename plugin="blockpayne">blockpayne.php</filename>
    </files>
    <vmconfig>
        <fields name="params">
            <fieldset name="payment" addfieldpath="/administrator/components/com_virtuemart/fields">
                <field name="gateway_url" type="text" label="Gateway URL" default="{$gatewayUrl}" />
                <field name="api_key" type="password" label="Live API Key (bp_live_...)" />
                <field name="webhook_secret" type="password" label="Webhook Secret" />
            </fieldset>
        </fields>
    </vmconfig>
</extension>
XML;

        $vmPlugin = <<<PHP
<?php
defined('_JEXEC') or die('Restricted access');

if (!class_exists('vmPSPlugin')) {
    require JPATH_VM_PLUGINS . '/vmpsplugin.php';
}

class plgVmPaymentBlockpayne extends vmPSPlugin
{
    public function __construct(&\$subject, \$config)
    {
        parent::__construct(\$subject, \$config);
        \$this->setConfigParameterable(\$this->_configTableFieldName, [
            'gateway_url'    => ['{$gatewayUrl}', 'char'],
            'api_key'        => ['', 'char'],
            'webhook_secret' => ['', 'char'],
        ]);
    }

    public function plgVmConfirmedOrder(\$cart, \$order)
    {
        \$method = \$this->getVmPluginMethod(\$order['details']['BT']->virtuemart_paymentmethod_id);
        if (!\$method || !\$this->selectedThisElement(\$method->payment_element)) {
            return null;
        }

        \$baseUrl = rtrim((string) (\$method->gateway_url ?: '{$gatewayUrl}'), '/');
        \$apiKey  = trim((string) \$method->api_key);
        \$orderNo = (string) \$order['details']['BT']->order_number;
        \$amount  = number_format((float) \$order['details']['BT']->order_total, 2, '.', '');

        \$root = rtrim(\Joomla\CMS\Uri\Uri::root(), '/');
        \$cbUrl = \$root . '/index.php?option=com_virtuemart&view=pluginresponse&task=pluginnotification&tmpl=component&pm=' . \$order['details']['BT']->virtuemart_paymentmethod_id;
        \$retUrl = \$root . '/index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&on=' . rawurlencode(\$orderNo);

        \$ch = curl_init(\$baseUrl . '/api/v1/invoices');
        curl_setopt_array(\$ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([
                'amount'        => (float) \$amount,
                'fiat_currency' => 'USD',
                'currency'      => 'AUTO',
                'order_id'      => \$orderNo,
                'buyer_email'   => (string) \$order['details']['BT']->email,
                'callback_url'  => \$cbUrl,
                'redirect_url'  => \$retUrl,
            ]),
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . \$apiKey, 'Content-Type: application/json'],
        ]);
        \$res = json_decode((string) curl_exec(\$ch), true);
        curl_close(\$ch);

        if (!empty(\$res['checkout_url'])) {
            \$cart->_confirmDone = false;
            \$cart->_dataValidated = false;
            \$cart->setCartIntoSession();
            \Joomla\CMS\Factory::getApplication()->redirect(\$res['checkout_url']);
        }
        return false;
    }

    public function plgVmOnPaymentNotification()
    {
        \$pmId = (int) (\$_GET['pm'] ?? 0);
        \$method = \$this->getVmPluginMethod(\$pmId);
        if (!\$method || !\$this->selectedThisElement(\$method->payment_element)) {
            return null;
        }

        \$raw = (string) file_get_contents('php://input');
        \$sig = (string) (\$_SERVER['HTTP_X_BLOCKPAYNE_SIGNATURE'] ?? '');
        \$expected = hash_hmac('sha256', \$raw, trim((string) \$method->webhook_secret));
        if (\$sig === '' || !hash_equals(\$expected, strtolower(trim(\$sig)))) {
            http_response_code(401);
            exit('Invalid signature');
        }

        \$data = json_decode(\$raw, true) ?: [];
        if ((\$data['status'] ?? '') === 'confirmed' && !empty(\$data['order_id'])) {
            \$virtuemartOrderId = VirtueMartModelOrders::getOrderIdByOrderNumber((string) \$data['order_id']);
            if (\$virtuemartOrderId) {
                \$modelOrder = VmModel::getModel('orders');
                \$modelOrder->updateStatusForOneOrder(\$virtuemartOrderId, ['order_status' => 'C', 'customer_notified' => 1], true);
            }
        }
        exit('OK');
    }
}
PHP;

        $joomlaBridge = <<<PHP
<?php
/**
 * Universal Joomla 4/5 Standalone Bridge (works with HikaShop, J2Store, RSForm, or custom Joomla buttons).
 * Place in your Joomla root and link buttons to: /blockpayne-joomla-bridge.php?amount=49.99&order_id=JML-101
 */
\$gatewayUrl    = '{$gatewayUrl}';
\$apiKey        = getenv('BLOCKPAYNE_API_KEY') ?: 'bp_live_replace_me';
\$webhookSecret = getenv('BLOCKPAYNE_WEBHOOK_SECRET') ?: 'replace_webhook_secret';

if ((\$_GET['action'] ?? '') === 'webhook') {
    \$raw = (string) file_get_contents('php://input');
    \$sig = (string) (\$_SERVER['HTTP_X_BLOCKPAYNE_SIGNATURE'] ?? '');
    if (!hash_equals(hash_hmac('sha256', \$raw, \$webhookSecret), strtolower(trim(\$sig)))) {
        http_response_code(401);
        exit('Invalid signature');
    }
    \$payload = json_decode(\$raw, true) ?: [];
    // Update your Joomla table here when \$payload['status'] === 'confirmed'
    header('Content-Type: application/json');
    echo json_encode(['received' => true]);
    exit;
}

\$amount  = max(1.0, (float) (\$_GET['amount'] ?? 25.00));
\$orderId = preg_replace('/[^A-Za-z0-9\\-_]/', '', (string) (\$_GET['order_id'] ?? ('JML-' . time())));
\$scheme  = (!empty(\$_SERVER['HTTPS']) && \$_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
\$selfUrl = \$scheme . '://' . (\$_SERVER['HTTP_HOST'] ?? 'localhost') . (\$_SERVER['SCRIPT_NAME'] ?? '/blockpayne-joomla-bridge.php');

\$ch = curl_init(\$gatewayUrl . '/api/v1/invoices');
curl_setopt_array(\$ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode([
        'amount'        => \$amount,
        'fiat_currency' => 'USD',
        'currency'      => 'AUTO',
        'order_id'      => \$orderId,
        'callback_url'  => \$selfUrl . '?action=webhook',
        'redirect_url'  => \$scheme . '://' . (\$_SERVER['HTTP_HOST'] ?? 'localhost') . '/',
    ]),
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . \$apiKey, 'Content-Type: application/json'],
]);
\$res = json_decode((string) curl_exec(\$ch), true);
curl_close(\$ch);

header('Location: ' . (\$res['checkout_url'] ?? '/'));
exit;
PHP;

        $zip->addFile('plg_vmpayment_blockpayne/blockpayne.xml', $xml);
        $zip->addFile('plg_vmpayment_blockpayne/blockpayne.php', $vmPlugin);
        $zip->addFile('joomla-bridge/blockpayne-joomla-bridge.php', $joomlaBridge);
        $zip->addFile('README.md', "# {$siteTitle} for Joomla 4/5\n\nIncludes:\n1. `plg_vmpayment_blockpayne/` — Installable Joomla VirtueMart payment plugin.\n2. `joomla-bridge/blockpayne-joomla-bridge.php` — Standalone drop-in bridge for HikaShop, J2Store, RSForm!Pro, or Joomla articles.\n");
    }

    private function populateTelegramBot(ZipBuilder $zip, string $gatewayUrl, string $siteTitle): void
    {
        $phpBot = <<<PHP
<?php
declare(strict_types=1);

/**
 * {$siteTitle} Zero-VPS Telegram Crypto Shop Bot (PHP 8.1+ — runs on any cPanel HTTPS domain!)
 *
 * 1. Set your bot token & {$siteTitle} keys below (or via environment variables).
 * 2. Register Telegram webhook once in browser:
 *    https://api.telegram.org/bot<TELEGRAM_BOT_TOKEN>/setWebhook?url=https://yourdomain.com/bot.php?route=telegram
 */

\$telegramToken = getenv('TELEGRAM_BOT_TOKEN') ?: '123456:ABC-DEF_replace_with_botfather_token';
\$bpApiKey      = getenv('BLOCKPAYNE_API_KEY') ?: 'bp_live_replace_me';
\$bpWebhookSec  = getenv('BLOCKPAYNE_WEBHOOK_SECRET') ?: 'replace_webhook_secret';
\$bpGatewayUrl  = '{$gatewayUrl}';

// Digital product catalog (edit prices & delivery content!)
\$products = [
    'vip_monthly' => [
        'title'    => 'VIP Signal Channel (30 Days)',
        'price'    => 29.00,
        'delivery' => "Your VIP Access Key: VIP-" . strtoupper(bin2hex(random_bytes(4))) . "\nJoin link: https://t.me/+YourPrivateInvite",
    ],
    'pro_license' => [
        'title'    => 'Pro Software License Key',
        'price'    => 59.00,
        'delivery' => "Your Pro License Key: LIC-" . strtoupper(bin2hex(random_bytes(6))),
    ],
];

function tg_send(string \$token, string \$method, array \$payload): void
{
    \$ch = curl_init("https://api.telegram.org/bot{\$token}/{\$method}");
    curl_setopt_array(\$ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode(\$payload),
        CURLOPT_TIMEOUT        => 10,
    ]);
    curl_exec(\$ch);
    curl_close(\$ch);
}

\$route = \$_GET['route'] ?? 'telegram';

// A) Handle signed {$siteTitle} Payment Confirmed Webhook -> Auto-Deliver Product in Telegram!
if (\$route === 'blockpayne_webhook') {
    \$raw = (string) file_get_contents('php://input');
    \$sig = (string) (\$_SERVER['HTTP_X_BLOCKPAYNE_SIGNATURE'] ?? '');
    \$expected = hash_hmac('sha256', \$raw, \$bpWebhookSec);

    if (\$sig === '' || !hash_equals(\$expected, strtolower(trim(\$sig)))) {
        http_response_code(401);
        exit('Invalid signature');
    }

    \$data = json_decode(\$raw, true) ?: [];
    \$meta = is_array(\$data['metadata'] ?? null) ? \$data['metadata'] : [];
    \$chatId = \$meta['chat_id'] ?? null;
    \$sku    = (string) (\$meta['sku'] ?? '');

    if ((\$data['event'] ?? '') === 'invoice.detected' && \$chatId) {
        tg_send(\$telegramToken, 'sendMessage', [
            'chat_id' => \$chatId,
            'text'    => "Payment detected on blockchain for {\$data['invoice_id']}! Waiting for confirmation...",
        ]);
    }

    if ((\$data['event'] ?? '') === 'invoice.confirmed' && (\$data['status'] ?? '') === 'confirmed' && \$chatId && isset(\$products[\$sku])) {
        \$item = \$products[\$sku];
        tg_send(\$telegramToken, 'sendMessage', [
            'chat_id' => \$chatId,
            'text'    => "Payment Confirmed!\n\nProduct: {\$item['title']}\nTX: {\$data['tx_hash']}\n\n" . \$item['delivery'],
        ]);
    }

    header('Content-Type: application/json');
    echo json_encode(['received' => true]);
    exit;
}

// B) Handle Incoming Telegram Updates (/start + Buy Buttons)
\$update = json_decode((string) file_get_contents('php://input'), true) ?: [];

if (isset(\$update['message']['text'])) {
    \$chatId = \$update['message']['chat']['id'];
    \$buttons = [];
    foreach (\$products as \$sku => \$p) {
        \$buttons[] = [['text' => "Buy {\$p['title']} — \$" . number_format(\$p['price'], 2), 'callback_data' => 'buy:' . \$sku]];
    }
    tg_send(\$telegramToken, 'sendMessage', [
        'chat_id'      => \$chatId,
        'text'         => "Welcome to our Automated Crypto Shop!\nSelect a product below to pay with BTC, USDT-TRC20, ETH, LTC, or BCH:",
        'reply_markup' => ['inline_keyboard' => \$buttons],
    ]);
}

if (isset(\$update['callback_query'])) {
    \$cb     = \$update['callback_query'];
    \$chatId = \$cb['message']['chat']['id'];
    \$data   = (string) (\$cb['data'] ?? '');

    if (str_starts_with(\$data, 'buy:')) {
        \$sku = substr(\$data, 4);
        if (isset(\$products[\$sku])) {
            \$item = \$products[\$sku];
            \$selfUrl = 'https://' . (\$_SERVER['HTTP_HOST'] ?? 'localhost') . (\$_SERVER['SCRIPT_NAME'] ?? '/bot.php');

            \$ch = curl_init(\$bpGatewayUrl . '/api/v1/invoices');
            curl_setopt_array(\$ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . \$bpApiKey, 'Content-Type: application/json'],
                CURLOPT_POSTFIELDS     => json_encode([
                    'amount'        => \$item['price'],
                    'fiat_currency' => 'USD',
                    'currency'      => 'AUTO',
                    'order_id'      => 'TG-' . \$chatId . '-' . time(),
                    'title'         => \$item['title'],
                    'callback_url'  => \$selfUrl . '?route=blockpayne_webhook',
                    'metadata'      => ['chat_id' => \$chatId, 'sku' => \$sku],
                ]),
            ]);
            \$inv = json_decode((string) curl_exec(\$ch), true) ?: [];
            curl_close(\$ch);

            if (!empty(\$inv['ok'])) {
                tg_send(\$telegramToken, 'sendMessage', [
                    'chat_id'      => \$chatId,
                    'text'         => "Order Ready: {\$item['title']} (\$" . number_format(\$item['price'], 2) . ")\n\nSend {\$inv['crypto_amount']} {\$inv['currency']} to:\n`{\$inv['deposit_address']}`\n\nOr tap below to switch coin / scan QR:",
                    'parse_mode'   => 'Markdown',
                    'reply_markup' => [
                        'inline_keyboard' => [
                            [['text' => 'Open Crypto Checkout (Switch Coin / QR)', 'url' => \$inv['checkout_url']]],
                        ],
                    ],
                ]);
            }
        }
    }
}
echo 'OK';
PHP;

        $pyBot = <<<PY
"""
{$siteTitle} Automated Telegram Crypto Shop Bot (Python 3.10+ / FastAPI + httpx)
Run: uvicorn bot:app --host 0.0.0.0 --port 8000
"""
import hashlib
import hmac
import os
import time
import httpx
from fastapi import FastAPI, Header, HTTPException, Request

TELEGRAM_TOKEN = os.getenv("TELEGRAM_BOT_TOKEN", "replace_me")
BP_API_KEY = os.getenv("BLOCKPAYNE_API_KEY", "bp_live_replace_me")
BP_WEBHOOK_SECRET = os.getenv("BLOCKPAYNE_WEBHOOK_SECRET", "replace_secret")
BP_GATEWAY_URL = os.getenv("BLOCKPAYNE_URL", "{$gatewayUrl}").rstrip("/")
PUBLIC_BASE_URL = os.getenv("PUBLIC_BASE_URL", "https://bot.yourdomain.com").rstrip("/")

PRODUCTS = {
    "vip_monthly": {"title": "VIP Membership (30 Days)", "price": 29.00, "secret": "https://t.me/+YourVIPChannel"},
    "pro_key": {"title": "Pro Software Key", "price": 59.00, "secret": "PRO-KEY-2026-ACTIVATED"},
}

app = FastAPI()


async def tg(method: str, payload: dict):
    async with httpx.AsyncClient(timeout=10) as client:
        await client.post(f"https://api.telegram.org/bot{TELEGRAM_TOKEN}/{method}", json=payload)


@app.post("/telegram/webhook")
async def telegram_webhook(req: Request):
    upd = await req.json()
    if "message" in upd and "text" in upd["message"]:
        chat_id = upd["message"]["chat"]["id"]
        kb = [[{"text": f"Buy {p['title']} (\${p['price']:.2f})", "callback_data": f"buy:{sku}"}] for sku, p in PRODUCTS.items()]
        await tg("sendMessage", {
            "chat_id": chat_id,
            "text": "Select an item to pay with BTC, USDT-TRC20, ETH, LTC, or BCH:",
            "reply_markup": {"inline_keyboard": kb},
        })
    elif "callback_query" in upd:
        cb = upd["callback_query"]
        chat_id = cb["message"]["chat"]["id"]
        data = cb.get("data", "")
        if data.startswith("buy:"):
            sku = data.split(":", 1)[1]
            item = PRODUCTS.get(sku)
            if item:
                async with httpx.AsyncClient(timeout=15) as client:
                    r = await client.post(
                        f"{BP_GATEWAY_URL}/api/v1/invoices",
                        headers={"Authorization": f"Bearer {BP_API_KEY}"},
                        json={
                            "amount": item["price"],
                            "fiat_currency": "USD",
                            "currency": "AUTO",
                            "order_id": f"TG-{chat_id}-{int(time.time())}",
                            "title": item["title"],
                            "callback_url": f"{PUBLIC_BASE_URL}/blockpayne/webhook",
                            "metadata": {"chat_id": chat_id, "sku": sku},
                        },
                    )
                    inv = r.json()
                if inv.get("ok"):
                    await tg("sendMessage", {
                        "chat_id": chat_id,
                        "text": f"Invoice {inv['invoice_id']} created! Tap below to scan QR or pick your preferred coin:",
                        "reply_markup": {"inline_keyboard": [[{"text": "Pay with Crypto", "url": inv["checkout_url"]}]]},
                    })
    return {"ok": True}


@app.post("/blockpayne/webhook")
async def blockpayne_webhook(req: Request, x_blockpayne_signature: str = Header(default="")):
    raw = await req.body()
    expected = hmac.new(BP_WEBHOOK_SECRET.encode(), raw, hashlib.sha256).hexdigest()
    if not hmac.compare_digest(expected, x_blockpayne_signature.strip().lower()):
        raise HTTPException(status_code=401, detail="Invalid signature")

    data = await req.json()
    meta = data.get("metadata") or {}
    chat_id = meta.get("chat_id")
    sku = meta.get("sku")
    if data.get("event") == "invoice.confirmed" and data.get("status") == "confirmed" and chat_id and sku in PRODUCTS:
        await tg("sendMessage", {
            "chat_id": chat_id,
            "text": f"Payment Confirmed! TX: {data.get('tx_hash')}\\n\\nDelivery: {PRODUCTS[sku]['secret']}",
        })
    return {"received": True}
PY;

        $zip->addFile('php-cpanel-bot/bot.php', $phpBot);
        $zip->addFile('python-bot/bot.py', $pyBot);
        $zip->addFile('python-bot/requirements.txt', "fastapi>=0.110.0\nuvicorn>=0.29.0\nhttpx>=0.27.0\n");
        $zip->addFile('README.md', "# {$siteTitle} Automated Telegram Crypto Shop Bot\n\nIncludes both:\n- `php-cpanel-bot/bot.php` — Runs directly on any shared cPanel HTTPS hosting (zero VPS required).\n- `python-bot/bot.py` — Async Python 3 FastAPI + `httpx` bot.\n");
    }

    private function populateInvoiceNinja(ZipBuilder $zip, string $gatewayUrl, string $siteTitle): void
    {
        $bridge = <<<PHP
<?php
declare(strict_types=1);

/**
 * {$siteTitle} Bridge for Invoice Ninja v5 (Self-Hosted & Cloud)
 *
 * Usage:
 * 1. Set INVOICENINJA_URL, INVOICENINJA_TOKEN, BLOCKPAYNE_API_KEY, BLOCKPAYNE_WEBHOOK_SECRET.
 * 2. Add a "Pay with Crypto" link in your Invoice Ninja email/portal template:
 *    https://yourdomain.com/invoiceninja-bridge.php?invoice_id=\$invoice.id
 * 3. When the customer pays, {$siteTitle} fires a signed webhook to `?action=webhook` which
 *    automatically records the payment in Invoice Ninja v5 via `POST /api/v1/payments`!
 */

\$ninjaUrl      = rtrim(getenv('INVOICENINJA_URL') ?: 'https://invoicing.co', '/');
\$ninjaToken    = getenv('INVOICENINJA_TOKEN') ?: 'replace_with_invoice_ninja_api_token';
\$bpGatewayUrl  = '{$gatewayUrl}';
\$bpApiKey      = getenv('BLOCKPAYNE_API_KEY') ?: 'bp_live_replace_me';
\$bpWebhookSec  = getenv('BLOCKPAYNE_WEBHOOK_SECRET') ?: 'replace_webhook_secret';

function ninja_api(string \$method, string \$url, string \$token, ?array \$body = null): array
{
    \$ch = curl_init(\$url);
    \$headers = ['X-Api-Token: ' . \$token, 'X-Requested-With: XMLHttpRequest', 'Accept: application/json'];
    \$opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => \$method, CURLOPT_TIMEOUT => 20];
    if (\$body !== null) {
        \$headers[] = 'Content-Type: application/json';
        \$opts[CURLOPT_POSTFIELDS] = json_encode(\$body);
    }
    \$opts[CURLOPT_HTTPHEADER] = \$headers;
    curl_setopt_array(\$ch, \$opts);
    \$res = json_decode((string) curl_exec(\$ch), true) ?: [];
    curl_close(\$ch);
    return \$res;
}

if ((\$_GET['action'] ?? '') === 'webhook') {
    \$raw = (string) file_get_contents('php://input');
    \$sig = (string) (\$_SERVER['HTTP_X_BLOCKPAYNE_SIGNATURE'] ?? '');
    if (!hash_equals(hash_hmac('sha256', \$raw, \$bpWebhookSec), strtolower(trim(\$sig)))) {
        http_response_code(401);
        exit('Invalid signature');
    }

    \$data = json_decode(\$raw, true) ?: [];
    \$meta = is_array(\$data['metadata'] ?? null) ? \$data['metadata'] : [];
    if ((\$data['status'] ?? '') === 'confirmed' && !empty(\$meta['ninja_invoice_id']) && !empty(\$meta['ninja_client_id'])) {
        ninja_api('POST', \$ninjaUrl . '/api/v1/payments', \$ninjaToken, [
            'client_id'             => \$meta['ninja_client_id'],
            'amount'                => (float) \$data['fiat_amount'],
            'transaction_reference' => (string) (\$data['tx_hash'] ?: \$data['invoice_id']),
            'invoices'              => [
                ['invoice_id' => \$meta['ninja_invoice_id'], 'amount' => (float) \$data['fiat_amount']],
            ],
        ]);
    }
    header('Content-Type: application/json');
    echo json_encode(['received' => true]);
    exit;
}

\$ninjaInvoiceId = preg_replace('/[^A-Za-z0-9]/', '', (string) (\$_GET['invoice_id'] ?? ''));
if (\$ninjaInvoiceId === '') {
    http_response_code(400);
    exit('Missing invoice_id');
}

\$invResp = ninja_api('GET', \$ninjaUrl . '/api/v1/invoices/' . \$ninjaInvoiceId, \$ninjaToken);
\$invData = \$invResp['data'] ?? null;
if (!is_array(\$invData)) {
    http_response_code(404);
    exit('Invoice Ninja invoice not found.');
}

\$balance = (float) (\$invData['balance'] ?? \$invData['amount'] ?? 0);
if (\$balance <= 0) {
    exit('This invoice is already paid.');
}

\$scheme  = (!empty(\$_SERVER['HTTPS']) && \$_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
\$selfUrl = \$scheme . '://' . (\$_SERVER['HTTP_HOST'] ?? 'localhost') . (\$_SERVER['SCRIPT_NAME'] ?? '/invoiceninja-bridge.php');

\$ch = curl_init(\$bpGatewayUrl . '/api/v1/invoices');
curl_setopt_array(\$ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . \$bpApiKey, 'Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode([
        'amount'        => \$balance,
        'fiat_currency' => 'USD',
        'currency'      => 'AUTO',
        'order_id'      => (string) (\$invData['number'] ?? \$ninjaInvoiceId),
        'title'         => 'Invoice #' . (\$invData['number'] ?? \$ninjaInvoiceId),
        'callback_url'  => \$selfUrl . '?action=webhook',
        'metadata'      => [
            'ninja_invoice_id' => \$invData['id'],
            'ninja_client_id'  => \$invData['client_id'],
        ],
    ]),
]);
\$bpInv = json_decode((string) curl_exec(\$ch), true) ?: [];
curl_close(\$ch);

if (!empty(\$bpInv['checkout_url'])) {
    header('Location: ' . \$bpInv['checkout_url']);
    exit;
}
echo 'Unable to create crypto invoice: ' . htmlspecialchars((string) (\$bpInv['error'] ?? 'Unknown error'));
PHP;

        $zip->addFile('invoiceninja-bridge.php', $bridge);
        $zip->addFile('README.md', "# {$siteTitle} for Invoice Ninja v5\n\n1. Create an API Token in Invoice Ninja v5 (**Settings -> Account Management -> API Tokens**).\n2. Host `invoiceninja-bridge.php` and configure your tokens.\n3. Add `https://yourdomain.com/invoiceninja-bridge.php?invoice_id=\$invoice.id` to your invoice email template.\n");
    }

    private function populateShopify(ZipBuilder $zip, string $gatewayUrl, string $siteTitle): void
    {
        $shopifyBridge = <<<PHP
<?php
declare(strict_types=1);

/**
 * {$siteTitle} Crypto Checkout & Auto-Capture Bridge for Shopify
 *
 * 1. Create a Shopify Custom App in Shopify Admin -> Settings -> Apps and sales channels -> Develop apps
 *    with `read_orders` and `write_orders` scopes, and copy your `shpat_...` Admin API Access Token.
 * 2. In Shopify Admin -> Settings -> Payments -> Manual payment methods, add:
 *    "Pay with Cryptocurrency (BTC, USDT, ETH, LTC)"
 * 3. Host this single file on HTTPS (e.g. on the same cPanel server as {$siteTitle}).
 */

\$shopifyDomain = getenv('SHOPIFY_SHOP_DOMAIN') ?: 'your-store.myshopify.com';
\$shopifyToken  = getenv('SHOPIFY_ADMIN_TOKEN') ?: 'shpat_replace_me';
\$bpGatewayUrl  = '{$gatewayUrl}';
\$bpApiKey      = getenv('BLOCKPAYNE_API_KEY') ?: 'bp_live_replace_me';
\$bpWebhookSec  = getenv('BLOCKPAYNE_WEBHOOK_SECRET') ?: 'replace_webhook_secret';

function shopify_rest(string \$method, string \$shop, string \$token, string \$endpoint, ?array \$body = null): array
{
    \$ch = curl_init("https://{\$shop}/admin/api/2025-01/{\$endpoint}");
    \$headers = ['X-Shopify-Access-Token: ' . \$token, 'Accept: application/json'];
    \$opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => \$method, CURLOPT_TIMEOUT => 20];
    if (\$body !== null) {
        \$headers[] = 'Content-Type: application/json';
        \$opts[CURLOPT_POSTFIELDS] = json_encode(\$body);
    }
    \$opts[CURLOPT_HTTPHEADER] = \$headers;
    curl_setopt_array(\$ch, \$opts);
    \$res = json_decode((string) curl_exec(\$ch), true) ?: [];
    curl_close(\$ch);
    return \$res;
}

// A) Signed {$siteTitle} Webhook -> Marks Shopify Order as PAID via Admin Transactions API!
if ((\$_GET['action'] ?? '') === 'webhook') {
    \$raw = (string) file_get_contents('php://input');
    \$sig = (string) (\$_SERVER['HTTP_X_BLOCKPAYNE_SIGNATURE'] ?? '');
    if (!hash_equals(hash_hmac('sha256', \$raw, \$bpWebhookSec), strtolower(trim(\$sig)))) {
        http_response_code(401);
        exit('Invalid signature');
    }

    \$data = json_decode(\$raw, true) ?: [];
    \$meta = is_array(\$data['metadata'] ?? null) ? \$data['metadata'] : [];
    \$shopifyOrderId = \$meta['shopify_order_id'] ?? null;

    if ((\$data['status'] ?? '') === 'confirmed' && \$shopifyOrderId) {
        shopify_rest('POST', \$shopifyDomain, \$shopifyToken, "orders/{\$shopifyOrderId}/transactions.json", [
            'transaction' => [
                'kind'          => 'sale',
                'status'        => 'success',
                'amount'        => (string) \$data['fiat_amount'],
                'currency'      => (string) \$data['fiat_currency'],
                'gateway'       => '{$siteTitle} Crypto',
                'authorization' => (string) (\$data['tx_hash'] ?: \$data['invoice_id']),
            ],
        ]);
    }
    header('Content-Type: application/json');
    echo json_encode(['received' => true]);
    exit;
}

// B) Redirect Buyer from Shopify Order Status / Thank-You Page -> Live {$siteTitle} Checkout!
\$orderId = preg_replace('/[^0-9]/', '', (string) (\$_GET['shopify_order_id'] ?? ''));
if (\$orderId === '') {
    http_response_code(400);
    exit('Missing shopify_order_id');
}

\$orderResp = shopify_rest('GET', \$shopifyDomain, \$shopifyToken, "orders/{\$orderId}.json");
\$order = \$orderResp['order'] ?? null;
if (!is_array(\$order)) {
    http_response_code(404);
    exit('Shopify order not found.');
}
if ((\$order['financial_status'] ?? '') === 'paid') {
    header('Location: ' . (\$order['order_status_url'] ?? "https://{\$shopifyDomain}"));
    exit;
}

\$scheme  = (!empty(\$_SERVER['HTTPS']) && \$_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
\$selfUrl = \$scheme . '://' . (\$_SERVER['HTTP_HOST'] ?? 'localhost') . (\$_SERVER['SCRIPT_NAME'] ?? '/shopify-bridge.php');

\$ch = curl_init(\$bpGatewayUrl . '/api/v1/invoices');
curl_setopt_array(\$ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . \$bpApiKey, 'Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode([
        'amount'        => (float) \$order['total_price'],
        'fiat_currency' => strtoupper((string) (\$order['currency'] ?? 'USD')),
        'currency'      => 'AUTO',
        'order_id'      => (string) (\$order['name'] ?? \$orderId),
        'title'         => 'Order ' . (\$order['name'] ?? \$orderId),
        'buyer_email'   => (string) (\$order['email'] ?? ''),
        'callback_url'  => \$selfUrl . '?action=webhook',
        'redirect_url'  => (string) (\$order['order_status_url'] ?? "https://{\$shopifyDomain}"),
        'metadata'      => ['shopify_order_id' => \$orderId],
    ]),
]);
\$inv = json_decode((string) curl_exec(\$ch), true) ?: [];
curl_close(\$ch);

header('Location: ' . (\$inv['checkout_url'] ?? "https://{\$shopifyDomain}"));
exit;
PHP;

        $snippet = <<<HTML
<!-- Paste in Shopify Order Status / Custom Pixel / Thank-You Page for "Pay with Cryptocurrency" orders -->
<script>
  (function () {
    if (window.Shopify && Shopify.checkout && Shopify.checkout.order_id) {
      var btn = document.createElement('a');
      btn.href = 'https://YOUR-BRIDGE-DOMAIN.com/shopify-bridge.php?shopify_order_id=' + Shopify.checkout.order_id;
      btn.textContent = 'Complete Crypto Payment Now (BTC / USDT / ETH / LTC)';
      btn.style.cssText = 'display:inline-block;margin:14px 0;padding:12px 20px;background:#10b981;color:#052e1c;font-weight:700;border-radius:8px;text-decoration:none;';
      document.body.prepend(btn);
    }
  })();
</script>
HTML;

        $zip->addFile('shopify-bridge.php', $shopifyBridge);
        $zip->addFile('checkout-thankyou-snippet.html', $snippet);
        $zip->addFile('README.md', "# {$siteTitle} for Shopify\n\n1. Add Manual Payment Method **Pay with Cryptocurrency** in Shopify.\n2. Deploy `shopify-bridge.php` with your `shpat_...` Admin API token and {$siteTitle} keys.\n3. Add `checkout-thankyou-snippet.html` so buyers launch crypto checkout and confirmed payments mark the Shopify order **Paid** automatically.\n");
    }

    private function populateWix(ZipBuilder $zip, string $gatewayUrl, string $siteTitle): void
    {
        $webJs = <<<JS
/**
 * Wix Velo Backend Web Module: backend/blockpayne.web.js
 * Store `BLOCKPAYNE_API_KEY` in Wix Secrets Manager!
 */
import { Permissions, webMethod } from 'wix-web-module';
import { getSecret } from 'wix-secrets-backend';
import { fetch } from 'wix-fetch';

const GATEWAY_URL = '{$gatewayUrl}';

export const startCryptoCheckout = webMethod(Permissions.Anyone, async ({ amount, currency = 'USD', orderId, buyerEmail, returnUrl, siteBaseUrl }) => {
  const apiKey = await getSecret('BLOCKPAYNE_API_KEY');

  const response = await fetch(`\${GATEWAY_URL}/api/v1/invoices`, {
    method: 'POST',
    headers: {
      'Authorization': `Bearer \${apiKey}`,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      amount: Number(amount),
      fiat_currency: currency,
      currency: 'AUTO',
      order_id: String(orderId),
      buyer_email: buyerEmail || '',
      callback_url: `\${siteBaseUrl.replace(/\\/$/, '')}/_functions/blockpayneWebhook`,
      redirect_url: returnUrl,
      metadata: { wix_order_id: String(orderId) },
    }),
  });

  const data = await response.json();
  if (!data.ok) {
    throw new Error(data.error || 'Unable to create crypto invoice');
  }
  return { checkoutUrl: data.checkout_url, invoiceId: data.invoice_id };
});
JS;

        $httpFns = <<<JS
/**
 * Wix Velo Webhook Receiver: backend/http-functions.js
 * Endpoint URL: https://www.yourwixsite.com/_functions/blockpayneWebhook
 * Store `BLOCKPAYNE_WEBHOOK_SECRET` in Wix Secrets Manager!
 */
import { ok, forbidden, badRequest } from 'wix-http-functions';
import { getSecret } from 'wix-secrets-backend';
import wixData from 'wix-data';
import crypto from 'crypto';

export async function post_blockpayneWebhook(request) {
  const rawBody = await request.body.text();
  const sigHeader = (request.headers['x-blockpayne-signature'] || '').trim().toLowerCase();
  const secret = await getSecret('BLOCKPAYNE_WEBHOOK_SECRET');

  const expected = crypto.createHmac('sha256', secret).update(rawBody).digest('hex');
  if (!sigHeader || sigHeader.length !== expected.length || !crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(sigHeader))) {
    return forbidden({ body: { error: 'Invalid X-BlockPayne-Signature' } });
  }

  let payload;
  try {
    payload = JSON.parse(rawBody);
  } catch {
    return badRequest({ body: { error: 'Malformed JSON' } });
  }

  if (payload.event === 'invoice.confirmed' && payload.status === 'confirmed') {
    await wixData.insert('CryptoPayments', {
      orderId: payload.order_id,
      invoiceId: payload.invoice_id,
      txHash: payload.tx_hash,
      amount: payload.fiat_amount,
      coin: payload.currency,
      confirmedAt: new Date(),
    }, { suppressAuth: true });
  }

  return ok({
    headers: { 'Content-Type': 'application/json' },
    body: { received: true },
  });
}
JS;

        $frontJs = <<<JS
// Frontend Wix Page Code Example (e.g. Pay Button `#payCryptoBtn`)
import { startCryptoCheckout } from 'backend/blockpayne.web';
import wixLocationFrontend from 'wix-location-frontend';

\$w.onReady(function () {
  \$w('#payCryptoBtn').onClick(async () => {
    const { checkoutUrl } = await startCryptoCheckout({
      amount: 49.99,
      currency: 'USD',
      orderId: 'WIX-' + Date.now(),
      returnUrl: wixLocationFrontend.baseUrl + '/thank-you',
      siteBaseUrl: wixLocationFrontend.baseUrl,
    });
    wixLocationFrontend.to(checkoutUrl);
  });
});
JS;

        $zip->addFile('backend/blockpayne.web.js', $webJs);
        $zip->addFile('backend/http-functions.js', $httpFns);
        $zip->addFile('frontend-page-example.js', $frontJs);
        $zip->addFile('README.md', "# {$siteTitle} for Wix (Velo / eCommerce)\n\n1. Enable **Dev Mode (Velo)** in Wix Editor.\n2. Save `BLOCKPAYNE_API_KEY` and `BLOCKPAYNE_WEBHOOK_SECRET` in **Wix Secrets Manager**.\n3. Add `backend/blockpayne.web.js` and `backend/http-functions.js`.\n");
    }
}

<?php
declare(strict_types=1);

use App\Http\Controllers\AuthController;
use App\Services\IntegrationKitService;
use App\Support\DynamicBrand;
use App\Support\Icons;
use App\Support\Settings;

/**
 * Developer & Merchant Multi-Language API + E-Commerce / CMS / Bot Integration Hub (`/api-docs`).
 */
$siteTitle = Settings::get('site_title', 'BlockPayne');
$user = AuthController::user();
$baseUrl = rtrim(bp_abs_url(''), '/');
$apiBase = $baseUrl . '/api/v1';

$placeholderKey = 'bp_live_your_api_key_here';
$placeholderSecret = 'your_webhook_secret_here';
$liveKey = $user['api_key'] ?? '';
$liveSecret = $user['webhook_secret'] ?? '';
$catalog = IntegrationKitService::catalog();

ob_start();
?>
<header class="bp-topbar">
  <div class="bp-container bp-topbar-inner">
    <a href="<?= bp_e(bp_url('')) ?>" class="bp-brand-row">
      <?= DynamicBrand::renderLogoBadge($siteTitle) ?>
      <span><?= bp_e($siteTitle) ?></span>
      <span class="bp-badge bp-badge-info" style="margin-left:4px;">API &amp; Plugins</span>
    </a>

    <div class="bp-flex-gap">
      <?php if ($user): ?>
        <a href="<?= bp_e(bp_url('dashboard?tab=api')) ?>" class="bp-btn bp-btn-secondary bp-btn-sm">
          <?= Icons::svg('key', 15) ?>
          <span>API Keys</span>
        </a>
        <a href="<?= bp_e(bp_url('dashboard')) ?>" class="bp-btn bp-btn-primary bp-btn-sm">
          <?= Icons::svg('grid', 15) ?>
          <span>Dashboard</span>
        </a>
      <?php else: ?>
        <a href="<?= bp_e(bp_url('login')) ?>" class="bp-btn bp-btn-ghost bp-btn-sm">Sign In</a>
        <a href="<?= bp_e(bp_url('register')) ?>" class="bp-btn bp-btn-primary bp-btn-sm">Get API Key</a>
      <?php endif; ?>
    </div>
  </div>
</header>

<main class="bp-container bp-docs-shell">
  <!-- Sticky Left Navigation -->
  <aside class="bp-docs-nav">
    <div class="bp-nav-group-label">Developer Reference</div>
    <a href="#overview" class="bp-nav-item active"><?= Icons::svg('zap', 16) ?><span>Overview &amp; Auth</span></a>
    <a href="#plugins" class="bp-nav-item"><?= Icons::svg('package', 16) ?><span>CMS, Billing &amp; Bots</span></a>
    <a href="#php-frameworks" class="bp-nav-item"><?= Icons::svg('server', 16) ?><span>All PHP Frameworks</span></a>
    <a href="#create-invoice" class="bp-nav-item"><?= Icons::svg('receipt', 16) ?><span>8-Language REST API</span></a>
    <a href="#hosted-form" class="bp-nav-item"><?= Icons::svg('link', 16) ?><span>Hosted HTML Form</span></a>
    <a href="#webhooks" class="bp-nav-item"><?= Icons::svg('shield', 16) ?><span>Webhooks (HMAC)</span></a>
    <a href="#endpoints" class="bp-nav-item"><?= Icons::svg('code', 16) ?><span>Endpoints Reference</span></a>
  </aside>

  <!-- Documentation Content -->
  <div class="bp-docs-main">

    <!-- 1. OVERVIEW & AUTH -->
    <section id="overview" class="bp-card bp-mb-4">
      <div class="bp-row-between bp-mb-2">
        <div>
          <span class="bp-badge bp-badge-success">REST API v1 + 10 Official Plugins</span>
          <h1 style="margin:8px 0 4px;font-size:24px;">Merchant API &amp; Platform Integrations</h1>
        </div>
        <?php if ($liveKey !== ''): ?>
          <label class="bp-docs-key-toggle">
            <input type="checkbox" id="bp-docs-inject-keys"
                   data-live-key="<?= bp_e($liveKey) ?>"
                   data-live-secret="<?= bp_e($liveSecret) ?>"
                   data-placeholder-key="<?= bp_e($placeholderKey) ?>"
                   data-placeholder-secret="<?= bp_e($placeholderSecret) ?>">
            <span>Insert my live API keys into code</span>
          </label>
        <?php endif; ?>
      </div>

      <p class="bp-muted" style="margin:0 0 16px;font-size:14.5px;">
        Accept non-custodial <strong>BTC</strong>, <strong>BCH</strong>, <strong>LTC</strong>, <strong>ETH</strong>, <strong>USDT (TRC-20)</strong>, and <strong>USDT (ERC-20)</strong> directly to your wallet. Connect via REST API in any programming language, or install ready-made plugins for <strong>WHMCS</strong>, <strong>WordPress / WooCommerce</strong>, <strong>FOSSBilling</strong>, <strong>PrestaShop</strong>, <strong>Joomla</strong>, <strong>Shopify</strong>, <strong>Wix</strong>, <strong>Invoice Ninja v5</strong>, and <strong>Telegram Shop Bots</strong>.
      </p>

      <div class="bp-grid-2">
        <div class="bp-card-compact">
          <div class="bp-subtle" style="font-size:11.5px;text-transform:uppercase;font-weight:700;">Base Endpoint</div>
          <div class="bp-mono" style="font-size:13.5px;margin-top:4px;word-break:break-all;color:#34d399;"><?= bp_e($apiBase) ?></div>
        </div>
        <div class="bp-card-compact">
          <div class="bp-subtle" style="font-size:11.5px;text-transform:uppercase;font-weight:700;">Authentication Header</div>
          <div class="bp-mono" style="font-size:13px;margin-top:4px;word-break:break-all;">Authorization: Bearer <span class="bp-doc-token"><?= bp_e($placeholderKey) ?></span></div>
        </div>
      </div>

      <div class="bp-card-compact bp-mt-4" style="border-color:rgba(16,185,129,0.3);background:rgba(16,185,129,0.06);">
        <strong style="font-size:13.5px;color:#34d399;">Multi-Coin Buyer Selector (`"currency": "AUTO"`):</strong>
        <span class="bp-muted" style="font-size:13px;">
          Pass <code class="bp-mono">"currency": "AUTO"</code> when creating an invoice and buyers can switch between any of your active wallets (BTC, USDT-TRC20, ETH, LTC, BCH, USDT-ERC20) directly on the hosted QR checkout page!
        </span>
      </div>
    </section>

    <!-- 2. READY-TO-INSTALL PLUGINS & BRIDGES (WHMCS, WOOCOMMERCE, SHOPIFY, WIX, JOOMLA, PRESTASHOP, FOSSBILLING, INVOICE NINJA, TELEGRAM) -->
    <section id="plugins" class="bp-card bp-mb-4">
      <div class="bp-row-between bp-mb-2">
        <div>
          <span class="bp-badge bp-badge-info">1-Click Downloadable Kits (.zip)</span>
          <h2 style="margin:6px 0 4px;font-size:19px;">E-Commerce, Billing, CMS &amp; Telegram Bot Integrations</h2>
        </div>
      </div>
      <p class="bp-muted" style="margin:0 0 16px;font-size:13.5px;">
        Every package below is dynamically pre-configured with your gateway URL (<code class="bp-mono"><?= bp_e($baseUrl) ?></code>) and verifies <code class="bp-mono">X-BlockPayne-Signature</code> HMAC-SHA256 webhooks automatically.
      </p>

      <div class="bp-grid-2">
        <?php foreach ($catalog as $item): ?>
          <div class="bp-card-compact" style="display:flex;flex-direction:column;justify-content:space-between;gap:12px;">
            <div>
              <div class="bp-row-between bp-mb-2">
                <strong style="font-size:15px;"><?= bp_e($item['name']) ?></strong>
                <span class="bp-badge bp-badge-neutral"><?= bp_e($item['category']) ?></span>
              </div>
              <p class="bp-muted" style="margin:0 0 10px;font-size:12.8px;line-height:1.5;"><?= bp_e($item['summary']) ?></p>
              <ol style="margin:0;padding-left:18px;color:#cbd5e1;font-size:12px;line-height:1.55;">
                <?php foreach ($item['steps'] as $step): ?>
                  <li><?= bp_e($step) ?></li>
                <?php endforeach; ?>
              </ol>
            </div>
            <div class="bp-row-between" style="padding-top:8px;border-top:1px solid #1e293b;">
              <span class="bp-subtle bp-mono" style="font-size:11.5px;"><?= bp_e($item['filename']) ?></span>
              <a href="<?= bp_e(bp_url('integrations/download/' . $item['slug'])) ?>" class="bp-btn bp-btn-primary bp-btn-sm">
                <?= Icons::svg('download', 14) ?>
                <span>Download .zip</span>
              </a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

    <!-- 3. ALL PHP FRAMEWORKS & CORE PHP SDK -->
    <section id="php-frameworks" class="bp-card bp-mb-4">
      <div class="bp-row-between bp-mb-2">
        <div>
          <h2 style="margin:0 0 4px;font-size:18.5px;">PHP Ecosystem (Core PHP, Laravel, Symfony, CodeIgniter 4)</h2>
          <p class="bp-muted" style="margin:0;font-size:13.5px;">
            Works with every PHP 8+ framework and custom script — or download the zero-dependency <a href="<?= bp_e(bp_url('integrations/download/php-sdk')) ?>" style="color:#34d399;text-decoration:underline;">BlockPayneClient.php SDK (.zip)</a>.
          </p>
        </div>
      </div>

      <div class="bp-code-tabs bp-mt-2" data-code-group="php-fw">
        <div class="bp-code-tabbar" role="tablist">
          <button type="button" class="bp-code-tab active" data-lang="fw-sdk">PHP SDK</button>
          <button type="button" class="bp-code-tab" data-lang="fw-laravel">Laravel 11/12</button>
          <button type="button" class="bp-code-tab" data-lang="fw-symfony">Symfony 6/7</button>
          <button type="button" class="bp-code-tab" data-lang="fw-ci4">CodeIgniter 4</button>
          <button type="button" class="bp-code-tab" data-lang="fw-core">Core / Vanilla PHP</button>
        </div>

        <div class="bp-code-pane" data-pane="fw-sdk">
          <div class="bp-code-header">
            <span>BlockPayneClient.php — Universal Zero-Dependency SDK</span>
            <button type="button" class="bp-btn bp-btn-ghost bp-btn-sm" data-copy-code><?= Icons::svg('copy', 14) ?><span>Copy</span></button>
          </div>
          <pre class="bp-code-pre"><code class="bp-doc-code">&lt;?php
require_once 'BlockPayneClient.php';

use BlockPayne\BlockPayneClient;

$bp = new BlockPayneClient(
    '<?= bp_e($placeholderKey) ?>',
    '<?= bp_e($placeholderSecret) ?>',
    '<?= bp_e($baseUrl) ?>'
);

// 1. Create an invoice (pass 'AUTO' so customer can switch coin on checkout)
$inv = $bp-&gt;createInvoice([
    'amount'        =&gt; 49.99,
    'fiat_currency' =&gt; 'USD',
    'currency'      =&gt; 'AUTO',
    'order_id'      =&gt; 'ORD-1001',
    'callback_url'  =&gt; 'https://yourwebsite.com/webhook.php',
    'redirect_url'  =&gt; 'https://yourwebsite.com/thank-you?order=ORD-1001',
    'metadata'      =&gt; ['user_id' =&gt; 42, 'plan' =&gt; 'pro'],
]);

header('Location: ' . $inv['checkout_url']);
exit;</code></pre>
        </div>

        <div class="bp-code-pane bp-hidden" data-pane="fw-laravel">
          <div class="bp-code-header">
            <span>Laravel 10 / 11 / 12 — Http Facade + Webhook Controller</span>
            <button type="button" class="bp-btn bp-btn-ghost bp-btn-sm" data-copy-code><?= Icons::svg('copy', 14) ?><span>Copy</span></button>
          </div>
          <pre class="bp-code-pre"><code class="bp-doc-code">&lt;?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class CryptoCheckoutController extends Controller
{
    public function create(Request $request)
    {
        $res = Http::withToken('<?= bp_e($placeholderKey) ?>')
            -&gt;post('<?= bp_e($apiBase) ?>/invoices', [
                'amount'        =&gt; 49.99,
                'fiat_currency' =&gt; 'USD',
                'currency'      =&gt; 'AUTO',
                'order_id'      =&gt; 'LAR-' . $request-&gt;input('order_id'),
                'callback_url'  =&gt; route('webhooks.blockpayne'),
                'redirect_url'  =&gt; route('checkout.complete'),
                'metadata'      =&gt; ['user_id' =&gt; $request-&gt;user()?-&gt;id],
            ])-&gt;json();

        return redirect()-&gt;away($res['checkout_url']);
    }

    public function webhook(Request $request)
    {
        $expected = hash_hmac('sha256', $request-&gt;getContent(), '<?= bp_e($placeholderSecret) ?>');
        abort_unless(hash_equals($expected, strtolower((string) $request-&gt;header('X-BlockPayne-Signature'))), 401);

        if ($request-&gt;input('status') === 'confirmed') {
            // Mark order $request->input('order_id') paid!
        }
        return response()-&gt;json(['received' =&gt; true]);
    }
}</code></pre>
        </div>

        <div class="bp-code-pane bp-hidden" data-pane="fw-symfony">
          <div class="bp-code-header">
            <span>Symfony 6 / 7 — HttpClientInterface Controller</span>
            <button type="button" class="bp-btn bp-btn-ghost bp-btn-sm" data-copy-code><?= Icons::svg('copy', 14) ?><span>Copy</span></button>
          </div>
          <pre class="bp-code-pre"><code class="bp-doc-code">&lt;?php
$response = $httpClient-&gt;request('POST', '<?= bp_e($apiBase) ?>/invoices', [
    'auth_bearer' =&gt; '<?= bp_e($placeholderKey) ?>',
    'json' =&gt; [
        'amount'        =&gt; 75.00,
        'fiat_currency' =&gt; 'USD',
        'currency'      =&gt; 'AUTO',
        'order_id'      =&gt; 'SYM-204',
        'callback_url'  =&gt; 'https://yourwebsite.com/webhooks/blockpayne',
        'redirect_url'  =&gt; 'https://yourwebsite.com/orders/SYM-204',
    ],
])-&gt;toArray();

return new RedirectResponse($response['checkout_url']);</code></pre>
        </div>

        <div class="bp-code-pane bp-hidden" data-pane="fw-ci4">
          <div class="bp-code-header">
            <span>CodeIgniter 4 — CURLRequest Service</span>
            <button type="button" class="bp-btn bp-btn-ghost bp-btn-sm" data-copy-code><?= Icons::svg('copy', 14) ?><span>Copy</span></button>
          </div>
          <pre class="bp-code-pre"><code class="bp-doc-code">&lt;?php
$client = \Config\Services::curlrequest();
$res = $client-&gt;post('<?= bp_e($apiBase) ?>/invoices', [
    'headers' =&gt; ['Authorization' =&gt; 'Bearer <?= bp_e($placeholderKey) ?>'],
    'json'    =&gt; [
        'amount'        =&gt; 29.00,
        'fiat_currency' =&gt; 'USD',
        'currency'      =&gt; 'AUTO',
        'order_id'      =&gt; 'CI4-901',
        'callback_url'  =&gt; site_url('payment/webhook'),
        'redirect_url'  =&gt; site_url('payment/thankyou'),
    ],
]);
$invoice = json_decode($res-&gt;getBody(), true);
return redirect()-&gt;to($invoice['checkout_url']);</code></pre>
        </div>

        <div class="bp-code-pane bp-hidden" data-pane="fw-core">
          <div class="bp-code-header">
            <span>Vanilla PHP (file_get_contents stream — works even without cURL!)</span>
            <button type="button" class="bp-btn bp-btn-ghost bp-btn-sm" data-copy-code><?= Icons::svg('copy', 14) ?><span>Copy</span></button>
          </div>
          <pre class="bp-code-pre"><code class="bp-doc-code">&lt;?php
$ctx = stream_context_create([
    'http' =&gt; [
        'method'  =&gt; 'POST',
        'header'  =&gt; "Authorization: Bearer <?= bp_e($placeholderKey) ?>\r\nContent-Type: application/json\r\n",
        'content' =&gt; json_encode([
            'amount'        =&gt; 49.99,
            'fiat_currency' =&gt; 'USD',
            'currency'      =&gt; 'AUTO',
            'order_id'      =&gt; 'PHP-1001',
            'callback_url'  =&gt; 'https://yourwebsite.com/webhook.php',
            'redirect_url'  =&gt; 'https://yourwebsite.com/complete.php',
        ]),
    ],
]);
$invoice = json_decode((string) file_get_contents('<?= bp_e($apiBase) ?>/invoices', false, $ctx), true);
header('Location: ' . $invoice['checkout_url']);
exit;</code></pre>
        </div>
      </div>
    </section>

    <!-- 4. MULTI-LANGUAGE CREATE & POLL INVOICE (8 LANGUAGES) -->
    <section id="create-invoice" class="bp-card bp-mb-4">
      <h2 style="margin:0 0 6px;font-size:18.5px;">Create &amp; Poll Invoices in 8 Programming Languages</h2>
      <p class="bp-muted" style="margin:0 0 14px;font-size:13.5px;">
        Send <code class="bp-mono">POST /api/v1/invoices</code> from any backend stack to lock a 30-minute exchange rate and derive a unique buyer address.
      </p>

      <div class="bp-code-tabs" data-code-group="create-invoice">
        <div class="bp-code-tabbar" role="tablist">
          <button type="button" class="bp-code-tab active" data-lang="php">PHP</button>
          <button type="button" class="bp-code-tab" data-lang="node">Node.js</button>
          <button type="button" class="bp-code-tab" data-lang="python">Python</button>
          <button type="button" class="bp-code-tab" data-lang="curl">cURL</button>
          <button type="button" class="bp-code-tab" data-lang="go">Go</button>
          <button type="button" class="bp-code-tab" data-lang="csharp">C# (.NET)</button>
          <button type="button" class="bp-code-tab" data-lang="ruby">Ruby</button>
          <button type="button" class="bp-code-tab" data-lang="java">Java 17+</button>
        </div>

        <!-- PHP -->
        <div class="bp-code-pane" data-pane="php">
          <div class="bp-code-header">
            <span>PHP 8+ (cURL)</span>
            <button type="button" class="bp-btn bp-btn-ghost bp-btn-sm" data-copy-code><?= Icons::svg('copy', 14) ?><span>Copy</span></button>
          </div>
          <pre class="bp-code-pre"><code class="bp-doc-code">&lt;?php
$apiKey = '<?= bp_e($placeholderKey) ?>';

$payload = [
    'amount'        =&gt; 49.99,
    'fiat_currency' =&gt; 'USD',          // USD | NGN | EUR | GBP
    'currency'      =&gt; 'AUTO',         // AUTO | BTC | BCH | LTC | ETH | USDT-TRC20 | USDT-ERC20
    'order_id'      =&gt; 'ORD-10042',
    'title'         =&gt; 'Order #10042',
    'buyer_email'   =&gt; 'customer@example.com',
    'callback_url'  =&gt; 'https://yourwebsite.com/webhooks/blockpayne',
    'redirect_url'  =&gt; 'https://yourwebsite.com/orders/10042/complete',
    'metadata'      =&gt; ['cart_id' =&gt; 'c_9981'],
];

$ch = curl_init('<?= bp_e($apiBase) ?>/invoices');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER =&gt; true,
    CURLOPT_POST           =&gt; true,
    CURLOPT_POSTFIELDS     =&gt; json_encode($payload),
    CURLOPT_HTTPHEADER     =&gt; [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
    ],
]);

$invoice = json_decode((string) curl_exec($ch), true);
curl_close($ch);

header('Location: ' . $invoice['checkout_url']);
exit;</code></pre>
        </div>

        <!-- Node.js -->
        <div class="bp-code-pane bp-hidden" data-pane="node">
          <div class="bp-code-header">
            <span>JavaScript / TypeScript (Node.js 18+ Fetch)</span>
            <button type="button" class="bp-btn bp-btn-ghost bp-btn-sm" data-copy-code><?= Icons::svg('copy', 14) ?><span>Copy</span></button>
          </div>
          <pre class="bp-code-pre"><code class="bp-doc-code">const API_KEY = '<?= bp_e($placeholderKey) ?>';
const BASE_URL = '<?= bp_e($apiBase) ?>';

export async function createCryptoInvoice(order) {
  const res = await fetch(`${BASE_URL}/invoices`, {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${API_KEY}`,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      amount: order.total,
      fiat_currency: 'USD',
      currency: 'AUTO',
      order_id: String(order.id),
      buyer_email: order.email,
      callback_url: 'https://yourwebsite.com/api/webhooks/blockpayne',
      redirect_url: `https://yourwebsite.com/orders/${order.id}/thank-you`,
      metadata: { source: 'nodejs' },
    }),
  });

  const data = await res.json();
  if (!data.ok) throw new Error(data.error);
  return data; // { invoice_id, deposit_address, crypto_amount, checkout_url, ... }
}</code></pre>
        </div>

        <!-- Python -->
        <div class="bp-code-pane bp-hidden" data-pane="python">
          <div class="bp-code-header">
            <span>Python 3 (requests / Django / FastAPI / Flask)</span>
            <button type="button" class="bp-btn bp-btn-ghost bp-btn-sm" data-copy-code><?= Icons::svg('copy', 14) ?><span>Copy</span></button>
          </div>
          <pre class="bp-code-pre"><code class="bp-doc-code">import requests

API_KEY = "<?= bp_e($placeholderKey) ?>"
BASE_URL = "<?= bp_e($apiBase) ?>"

resp = requests.post(
    f"{BASE_URL}/invoices",
    headers={"Authorization": f"Bearer {API_KEY}"},
    json={
        "amount": 49.99,
        "fiat_currency": "USD",
        "currency": "AUTO",
        "order_id": "ORD-10042",
        "callback_url": "https://yourwebsite.com/webhooks/blockpayne",
        "redirect_url": "https://yourwebsite.com/orders/10042/complete",
        "metadata": {"customer_id": 882},
    },
    timeout=15,
)
resp.raise_for_status()
invoice = resp.json()
print("Redirect buyer to:", invoice["checkout_url"])</code></pre>
        </div>

        <!-- cURL -->
        <div class="bp-code-pane bp-hidden" data-pane="curl">
          <div class="bp-code-header">
            <span>cURL CLI</span>
            <button type="button" class="bp-btn bp-btn-ghost bp-btn-sm" data-copy-code><?= Icons::svg('copy', 14) ?><span>Copy</span></button>
          </div>
          <pre class="bp-code-pre"><code class="bp-doc-code">curl -X POST "<?= bp_e($apiBase) ?>/invoices" \
  -H "Authorization: Bearer <?= bp_e($placeholderKey) ?>" \
  -H "Content-Type: application/json" \
  -d '{
    "amount": 49.99,
    "fiat_currency": "USD",
    "currency": "AUTO",
    "order_id": "ORD-10042",
    "callback_url": "https://yourwebsite.com/webhooks/blockpayne",
    "redirect_url": "https://yourwebsite.com/orders/10042/complete"
  }'</code></pre>
        </div>

        <!-- Go -->
        <div class="bp-code-pane bp-hidden" data-pane="go">
          <div class="bp-code-header">
            <span>Go (net/http)</span>
            <button type="button" class="bp-btn bp-btn-ghost bp-btn-sm" data-copy-code><?= Icons::svg('copy', 14) ?><span>Copy</span></button>
          </div>
          <pre class="bp-code-pre"><code class="bp-doc-code">package main

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http"
)

func main() {
	body, _ := json.Marshal(map[string]any{
		"amount":        49.99,
		"fiat_currency": "USD",
		"currency":      "AUTO",
		"order_id":      "ORD-10042",
		"callback_url":  "https://yourwebsite.com/webhooks/blockpayne",
		"redirect_url":  "https://yourwebsite.com/orders/10042/complete",
	})

	req, _ := http.NewRequest("POST", "<?= bp_e($apiBase) ?>/invoices", bytes.NewBuffer(body))
	req.Header.Set("Authorization", "Bearer <?= bp_e($placeholderKey) ?>")
	req.Header.Set("Content-Type", "application/json")

	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		panic(err)
	}
	defer resp.Body.Close()

	var result map[string]any
	json.NewDecoder(resp.Body).Decode(&amp;result)
	fmt.Println("Checkout URL:", result["checkout_url"])
}</code></pre>
        </div>

        <!-- C# -->
        <div class="bp-code-pane bp-hidden" data-pane="csharp">
          <div class="bp-code-header">
            <span>C# (.NET 8+ HttpClient)</span>
            <button type="button" class="bp-btn bp-btn-ghost bp-btn-sm" data-copy-code><?= Icons::svg('copy', 14) ?><span>Copy</span></button>
          </div>
          <pre class="bp-code-pre"><code class="bp-doc-code">using System.Net.Http.Headers;
using System.Net.Http.Json;

using var http = new HttpClient();
http.DefaultRequestHeaders.Authorization =
    new AuthenticationHeaderValue("Bearer", "<?= bp_e($placeholderKey) ?>");

var response = await http.PostAsJsonAsync("<?= bp_e($apiBase) ?>/invoices", new {
    amount = 49.99m,
    fiat_currency = "USD",
    currency = "AUTO",
    order_id = "ORD-10042",
    callback_url = "https://yourwebsite.com/webhooks/blockpayne",
    redirect_url = "https://yourwebsite.com/orders/10042/complete"
});

var json = await response.Content.ReadAsStringAsync();
Console.WriteLine(json);</code></pre>
        </div>

        <!-- Ruby -->
        <div class="bp-code-pane bp-hidden" data-pane="ruby">
          <div class="bp-code-header">
            <span>Ruby (Rails / Net::HTTP)</span>
            <button type="button" class="bp-btn bp-btn-ghost bp-btn-sm" data-copy-code><?= Icons::svg('copy', 14) ?><span>Copy</span></button>
          </div>
          <pre class="bp-code-pre"><code class="bp-doc-code">require "net/http"
require "json"

uri = URI("<?= bp_e($apiBase) ?>/invoices")
req = Net::HTTP::Post.new(uri)
req["Authorization"] = "Bearer <?= bp_e($placeholderKey) ?>"
req["Content-Type"] = "application/json"
req.body = {
  amount: 49.99,
  fiat_currency: "USD",
  currency: "AUTO",
  order_id: "ORD-10042",
  callback_url: "https://yourwebsite.com/webhooks/blockpayne",
  redirect_url: "https://yourwebsite.com/orders/10042/complete"
}.to_json

res = Net::HTTP.start(uri.hostname, uri.port, use_ssl: uri.scheme == "https") { |h| h.request(req) }
puts JSON.parse(res.body)["checkout_url"]</code></pre>
        </div>

        <!-- Java -->
        <div class="bp-code-pane bp-hidden" data-pane="java">
          <div class="bp-code-header">
            <span>Java 17+ (Spring Boot / java.net.http.HttpClient)</span>
            <button type="button" class="bp-btn bp-btn-ghost bp-btn-sm" data-copy-code><?= Icons::svg('copy', 14) ?><span>Copy</span></button>
          </div>
          <pre class="bp-code-pre"><code class="bp-doc-code">import java.net.URI;
import java.net.http.*;

var client = HttpClient.newHttpClient();
var json = """
  {"amount":49.99,"fiat_currency":"USD","currency":"AUTO","order_id":"ORD-10042",
   "callback_url":"https://yourwebsite.com/webhooks/blockpayne"}
  """;

var req = HttpRequest.newBuilder(URI.create("<?= bp_e($apiBase) ?>/invoices"))
    .header("Authorization", "Bearer <?= bp_e($placeholderKey) ?>")
    .header("Content-Type", "application/json")
    .POST(HttpRequest.BodyPublishers.ofString(json))
    .build();

var res = client.send(req, HttpResponse.BodyHandlers.ofString());
System.out.println(res.body());</code></pre>
        </div>
      </div>

      <!-- Sample 201 JSON Response -->
      <div class="bp-mt-4">
        <div class="bp-subtle bp-mb-2" style="font-size:12px;font-weight:700;text-transform:uppercase;">201 Created — JSON Response</div>
        <pre class="bp-code-pre"><code>{
  "ok": true,
  "invoice_id": "INV-9F82C1B04E12",
  "order_id": "ORD-10042",
  "fiat_amount": "49.99",
  "fiat_currency": "USD",
  "crypto_amount": "0.00056486",
  "currency": "BTC",
  "available_coins": ["BTC", "LTC", "USDT-TRC20"],
  "deposit_address": "bc1qcr8te4kr609gcawutmrza0j4xv80jy8z306fyu",
  "payment_uri": "bitcoin:bc1qcr8te4kr609gcawutmrza0j4xv80jy8z306fyu?amount=0.00056486",
  "checkout_url": "<?= bp_e($baseUrl) ?>/pay/INV-9F82C1B04E12",
  "redirect_url": "https://yourwebsite.com/orders/10042/complete",
  "metadata": { "cart_id": "c_9981" },
  "status": "pending",
  "expires_at": "2026-11-10 15:30:00"
}</code></pre>
      </div>
    </section>

    <!-- 5. SIGNED HOSTED HTML FORM REDIRECT (`POST /pay/hosted`) -->
    <section id="hosted-form" class="bp-card bp-mb-4">
      <h2 style="margin:0 0 6px;font-size:18.5px;">Signed Hosted Form Redirect (<code class="bp-mono">POST /pay/hosted</code>)</h2>
      <p class="bp-muted" style="margin:0 0 12px;font-size:13.5px;">
        Don't want to make a background server-to-server HTTP request? Render an HMAC-signed HTML form on any PHP/CMS checkout page — submitting the form creates the live invoice and redirects the buyer straight to <code class="bp-mono">/pay/{invoice_id}</code>. (For static no-code buttons, use <strong>Dashboard &rarr; Payment Links</strong>.)
      </p>
      <div class="bp-code-header" style="border:1px solid #1e293b;border-bottom:none;border-radius:10px 10px 0 0;">
        <span>HMAC-Signed HTML Form (PHP / CMS Template)</span>
        <button type="button" class="bp-btn bp-btn-ghost bp-btn-sm" data-copy-code><?= Icons::svg('copy', 14) ?><span>Copy</span></button>
      </div>
      <pre class="bp-code-pre" style="border:1px solid #1e293b;border-radius:0 0 10px 10px;"><code class="bp-doc-code">&lt;?php
$apiKey = '<?= bp_e($placeholderKey) ?>';
$secret = '<?= bp_e($placeholderSecret) ?>';
$amount = '49.99';
$fiat   = 'USD';
$order  = 'ORD-10042';
$ts     = (string) time();
$sig    = hash_hmac('sha256', "{$apiKey}|{$amount}|{$fiat}|{$order}|{$ts}", $secret);
?&gt;
&lt;form method="post" action="<?= bp_e($baseUrl) ?>/pay/hosted"&gt;
  &lt;input type="hidden" name="api_key" value="&lt;?= htmlspecialchars($apiKey) ?&gt;"&gt;
  &lt;input type="hidden" name="amount" value="&lt;?= $amount ?&gt;"&gt;
  &lt;input type="hidden" name="fiat_currency" value="&lt;?= $fiat ?&gt;"&gt;
  &lt;input type="hidden" name="currency" value="AUTO"&gt;
  &lt;input type="hidden" name="order_id" value="&lt;?= $order ?&gt;"&gt;
  &lt;input type="hidden" name="callback_url" value="https://yourwebsite.com/webhook.php"&gt;
  &lt;input type="hidden" name="redirect_url" value="https://yourwebsite.com/thank-you"&gt;
  &lt;input type="hidden" name="timestamp" value="&lt;?= $ts ?&gt;"&gt;
  &lt;input type="hidden" name="signature" value="&lt;?= $sig ?&gt;"&gt;
  &lt;button type="submit"&gt;Pay with Cryptocurrency&lt;/button&gt;
&lt;/form&gt;</code></pre>
    </section>

    <!-- 6. WEBHOOK SIGNATURE VERIFICATION -->
    <section id="webhooks" class="bp-card bp-mb-4">
      <h2 style="margin:0 0 6px;font-size:18.5px;">Verify Signed Webhooks (<code class="bp-mono">HMAC-SHA256</code>)</h2>
      <p class="bp-muted" style="margin:0 0 14px;font-size:13.5px;">
        When a transaction is seen in the mempool (<code class="bp-mono">invoice.detected</code>) and when confirmed on-chain (<code class="bp-mono">invoice.confirmed</code>), <?= bp_e($siteTitle) ?> sends a <code class="bp-mono">POST</code> to your <code class="bp-mono">callback_url</code> with <code class="bp-mono">X-BlockPayne-Event</code> and <code class="bp-mono">X-BlockPayne-Signature</code>. Always verify using constant-time comparison before fulfilling an order:
      </p>

      <div class="bp-code-tabs" data-code-group="webhooks">
        <div class="bp-code-tabbar" role="tablist">
          <button type="button" class="bp-code-tab active" data-lang="wh-php">PHP</button>
          <button type="button" class="bp-code-tab" data-lang="wh-node">Node.js (Express)</button>
          <button type="button" class="bp-code-tab" data-lang="wh-python">Python (Flask/FastAPI)</button>
          <button type="button" class="bp-code-tab" data-lang="wh-go">Go</button>
          <button type="button" class="bp-code-tab" data-lang="wh-csharp">C# (ASP.NET)</button>
          <button type="button" class="bp-code-tab" data-lang="wh-ruby">Ruby (Rails)</button>
        </div>

        <div class="bp-code-pane" data-pane="wh-php">
          <div class="bp-code-header">
            <span>PHP Webhook Endpoint</span>
            <button type="button" class="bp-btn bp-btn-ghost bp-btn-sm" data-copy-code><?= Icons::svg('copy', 14) ?><span>Copy</span></button>
          </div>
          <pre class="bp-code-pre"><code class="bp-doc-code">&lt;?php
$webhookSecret = '<?= bp_e($placeholderSecret) ?>';
$rawBody       = (string) file_get_contents('php://input');
$sigHeader     = (string) ($_SERVER['HTTP_X_BLOCKPAYNE_SIGNATURE'] ?? '');

$expected = hash_hmac('sha256', $rawBody, $webhookSecret);
if (!hash_equals($expected, strtolower(trim($sigHeader)))) {
    http_response_code(401);
    exit('Invalid signature');
}

$event = json_decode($rawBody, true);
if (($event['event'] ?? '') === 'invoice.confirmed' &amp;&amp; ($event['status'] ?? '') === 'confirmed') {
    $orderId  = $event['order_id'];
    $metadata = $event['metadata'] ?? [];
    // Mark $orderId as PAID in your database (idempotently)!
}

http_response_code(200);
echo json_encode(['received' =&gt; true]);</code></pre>
        </div>

        <div class="bp-code-pane bp-hidden" data-pane="wh-node">
          <div class="bp-code-header">
            <span>Node.js / Express Webhook Handler</span>
            <button type="button" class="bp-btn bp-btn-ghost bp-btn-sm" data-copy-code><?= Icons::svg('copy', 14) ?><span>Copy</span></button>
          </div>
          <pre class="bp-code-pre"><code class="bp-doc-code">import crypto from 'node:crypto';
import express from 'express';

const app = express();
const WEBHOOK_SECRET = '<?= bp_e($placeholderSecret) ?>';

app.post('/webhooks/blockpayne', express.raw({ type: 'application/json' }), (req, res) =&gt; {
  const sig = (req.get('X-BlockPayne-Signature') || '').trim().toLowerCase();
  const expected = crypto.createHmac('sha256', WEBHOOK_SECRET).update(req.body).digest('hex');

  if (sig.length !== expected.length || !crypto.timingSafeEqual(Buffer.from(sig), Buffer.from(expected))) {
    return res.status(401).send('Invalid signature');
  }

  const payload = JSON.parse(req.body.toString('utf8'));
  if (payload.event === 'invoice.confirmed' &amp;&amp; payload.status === 'confirmed') {
    // Fulfill order: payload.order_id, payload.metadata, payload.tx_hash
  }
  res.json({ received: true });
});</code></pre>
        </div>

        <div class="bp-code-pane bp-hidden" data-pane="wh-python">
          <div class="bp-code-header">
            <span>Python (Flask / FastAPI)</span>
            <button type="button" class="bp-btn bp-btn-ghost bp-btn-sm" data-copy-code><?= Icons::svg('copy', 14) ?><span>Copy</span></button>
          </div>
          <pre class="bp-code-pre"><code class="bp-doc-code">import hmac, hashlib, json
from flask import Flask, request, abort

app = Flask(__name__)
WEBHOOK_SECRET = b"<?= bp_e($placeholderSecret) ?>"

@app.post("/webhooks/blockpayne")
def blockpayne_webhook():
    raw = request.get_data()
    sig = request.headers.get("X-BlockPayne-Signature", "").strip().lower()
    expected = hmac.new(WEBHOOK_SECRET, raw, hashlib.sha256).hexdigest()

    if not hmac.compare_digest(expected, sig):
        abort(401)

    payload = json.loads(raw)
    if payload.get("event") == "invoice.confirmed" and payload.get("status") == "confirmed":
        order_id = payload["order_id"]
        # Mark order_id as paid!

    return {"received": True}, 200</code></pre>
        </div>

        <div class="bp-code-pane bp-hidden" data-pane="wh-go">
          <div class="bp-code-header">
            <span>Go Webhook Handler</span>
            <button type="button" class="bp-btn bp-btn-ghost bp-btn-sm" data-copy-code><?= Icons::svg('copy', 14) ?><span>Copy</span></button>
          </div>
          <pre class="bp-code-pre"><code class="bp-doc-code">func webhookHandler(w http.ResponseWriter, r *http.Request) {
	raw, _ := io.ReadAll(r.Body)
	mac := hmac.New(sha256.New, []byte("<?= bp_e($placeholderSecret) ?>"))
	mac.Write(raw)
	expected := hex.EncodeToString(mac.Sum(nil))
	sig := strings.ToLower(strings.TrimSpace(r.Header.Get("X-BlockPayne-Signature")))

	if !hmac.Equal([]byte(expected), []byte(sig)) {
		http.Error(w, "Invalid signature", http.StatusUnauthorized)
		return
	}
	w.WriteHeader(http.StatusOK)
	w.Write([]byte(`{"received":true}`))
}</code></pre>
        </div>

        <div class="bp-code-pane bp-hidden" data-pane="wh-csharp">
          <div class="bp-code-header">
            <span>C# ASP.NET Core Minimal API</span>
            <button type="button" class="bp-btn bp-btn-ghost bp-btn-sm" data-copy-code><?= Icons::svg('copy', 14) ?><span>Copy</span></button>
          </div>
          <pre class="bp-code-pre"><code class="bp-doc-code">app.MapPost("/webhooks/blockpayne", async (HttpRequest req) =&gt; {
    using var reader = new StreamReader(req.Body);
    var raw = await reader.ReadToEndAsync();
    var sig = req.Headers["X-BlockPayne-Signature"].ToString().Trim().ToLowerInvariant();

    using var hmac = new System.Security.Cryptography.HMACSHA256(
        System.Text.Encoding.UTF8.GetBytes("<?= bp_e($placeholderSecret) ?>"));
    var expected = Convert.ToHexString(hmac.ComputeHash(System.Text.Encoding.UTF8.GetBytes(raw))).ToLowerInvariant();

    if (!System.Security.Cryptography.CryptographicOperations.FixedTimeEquals(
            System.Text.Encoding.ASCII.GetBytes(expected),
            System.Text.Encoding.ASCII.GetBytes(sig)))
        return Results.Unauthorized();

    return Results.Ok(new { received = true });
});</code></pre>
        </div>

        <div class="bp-code-pane bp-hidden" data-pane="wh-ruby">
          <div class="bp-code-header">
            <span>Ruby / Sinatra / Rails</span>
            <button type="button" class="bp-btn bp-btn-ghost bp-btn-sm" data-copy-code><?= Icons::svg('copy', 14) ?><span>Copy</span></button>
          </div>
          <pre class="bp-code-pre"><code class="bp-doc-code">post "/webhooks/blockpayne" do
  raw = request.body.read
  sig = request.env["HTTP_X_BLOCKPAYNE_SIGNATURE"].to_s.strip.downcase
  expected = OpenSSL::HMAC.hexdigest("SHA256", "<?= bp_e($placeholderSecret) ?>", raw)

  halt 401, "Invalid signature" unless Rack::Utils.secure_compare(expected, sig)
  payload = JSON.parse(raw)
  # Fulfill payload["order_id"] when payload["status"] == "confirmed"
  content_type :json
  { received: true }.to_json
end</code></pre>
        </div>
      </div>
    </section>

    <!-- 7. ENDPOINTS REFERENCE TABLE -->
    <section id="endpoints" class="bp-card">
      <h2 style="margin:0 0 10px;font-size:18.5px;">Endpoints Reference</h2>
      <div class="bp-table-wrap">
        <table class="bp-table">
          <thead>
            <tr>
              <th>Method</th>
              <th>Endpoint</th>
              <th>Auth</th>
              <th>Description</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td><span class="bp-badge bp-badge-success">POST</span></td>
              <td class="bp-mono">/api/v1/invoices</td>
              <td>Bearer / X-API-Key</td>
              <td>Create invoice (<code class="bp-mono">amount</code>, <code class="bp-mono">fiat_currency</code>, <code class="bp-mono">currency: "AUTO"|...</code>, <code class="bp-mono">order_id</code>, <code class="bp-mono">callback_url</code>, <code class="bp-mono">redirect_url</code>, <code class="bp-mono">metadata</code>).</td>
            </tr>
            <tr>
              <td><span class="bp-badge bp-badge-info">GET</span></td>
              <td class="bp-mono">/api/v1/invoices/{invoice_id}</td>
              <td>Bearer / X-API-Key</td>
              <td>Poll live blockchain status, confirmations, <code class="bp-mono">tx_hash</code>, <code class="bp-mono">metadata</code>, and <code class="bp-mono">explorer_url</code>.</td>
            </tr>
            <tr>
              <td><span class="bp-badge bp-badge-info">GET</span></td>
              <td class="bp-mono">/api/v1/invoices</td>
              <td>Bearer / X-API-Key</td>
              <td>List merchant invoices (<code class="bp-mono">?status=confirmed&amp;order_id=ORD-1&amp;limit=25</code>).</td>
            </tr>
            <tr>
              <td><span class="bp-badge bp-badge-info">GET</span></td>
              <td class="bp-mono">/api/v1/rates</td>
              <td>Public / Key</td>
              <td>Live USD &amp; fiat exchange rates for <code class="bp-mono">BTC</code>, <code class="bp-mono">BCH</code>, <code class="bp-mono">LTC</code>, <code class="bp-mono">ETH</code>, <code class="bp-mono">USDT-TRC20</code>, <code class="bp-mono">USDT-ERC20</code>.</td>
            </tr>
            <tr>
              <td><span class="bp-badge bp-badge-info">GET</span></td>
              <td class="bp-mono">/api/v1/balance</td>
              <td>Bearer / X-API-Key</td>
              <td>Check merchant USD prefund credit balance &amp; active connected wallets.</td>
            </tr>
            <tr>
              <td><span class="bp-badge bp-badge-success">POST</span></td>
              <td class="bp-mono">/pay/hosted</td>
              <td>HMAC Form</td>
              <td>Zero-cURL HMAC-signed HTML form redirect for WHMCS, FOSSBilling, CMSs &amp; custom PHP checkouts.</td>
            </tr>
            <tr>
              <td><span class="bp-badge bp-badge-info">GET</span></td>
              <td class="bp-mono">/integrations/download/{slug}</td>
              <td>Public</td>
              <td>Download <code class="bp-mono">.zip</code> integration kits (<code class="bp-mono">whmcs</code>, <code class="bp-mono">woocommerce</code>, <code class="bp-mono">fossbilling</code>, <code class="bp-mono">prestashop</code>, <code class="bp-mono">joomla</code>, <code class="bp-mono">shopify</code>, <code class="bp-mono">wix</code>, <code class="bp-mono">invoiceninja</code>, <code class="bp-mono">telegram-bot</code>, <code class="bp-mono">php-sdk</code>).</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

  </div>
</main>
<?php
$bodyContent = (string) ob_get_clean();
$pageTitle = 'API & Platform Integrations';
require __DIR__ . '/layout.php';

<?php
declare(strict_types=1);

use App\Services\BitcoinHDWalletService;
use App\Support\DynamicBrand;
use App\Support\Icons;
use App\Support\Security;
use App\Support\Settings;

/**
 * Zero-Reload SPA Merchant & Admin Dashboard View
 * Expects: $user (array), $initialState (array)
 */
$siteTitle = Settings::get('site_title', 'BlockPayne');
$isAdmin = (($user['role'] ?? '') === 'admin');
$coins = BitcoinHDWalletService::supportedCoins();
$admSettings = $initialState['admin']['settings'] ?? Settings::all();
$apiCreateEndpoint = bp_abs_url('api/v1/invoices');
$liveApiKey = (string) ($user['api_key'] ?? '');

ob_start();
?>
<header class="bp-topbar">
  <div class="bp-topbar-inner" style="padding:0 16px;max-width:1280px;margin:0 auto;">
    <div class="bp-flex-gap">
      <button type="button" class="bp-btn bp-btn-ghost bp-btn-sm bp-mobile-menu-btn" data-toggle-drawer aria-label="Open Navigation Menu">
        <?= Icons::svg('menu', 18) ?>
      </button>
      <a href="<?= bp_e(bp_url('')) ?>" class="bp-brand-row">
        <?= DynamicBrand::renderLogoBadge($siteTitle) ?>
        <span><?= bp_e($siteTitle) ?></span>
      </a>
    </div>

    <div class="bp-flex-gap">
      <button type="button" class="bp-btn bp-btn-ghost bp-btn-sm" data-open-cmd title="Command Palette (Ctrl+K)">
        <?= Icons::svg('search', 15) ?>
        <span class="bp-hide-xs">Search</span>
        <span class="bp-kbd bp-hide-xs">Ctrl+K</span>
      </button>

      <button type="button" class="bp-btn bp-btn-secondary bp-btn-sm" data-spa-tab="prefund" title="Prefund Fee Credit">
        <?= Icons::svg('zap', 15) ?>
        <span class="bp-hide-xs">Credit:</span>
        <strong data-bind="prefund_balance" style="color:#34d399;">$<?= bp_e($initialState['prefund_balance']) ?></strong>
      </button>

      <a href="<?= bp_e(bp_url('api-docs')) ?>" class="bp-btn bp-btn-ghost bp-btn-sm bp-hide-xs" title="Developer API Documentation">
        <?= Icons::svg('book', 15) ?>
        <span>API Docs</span>
      </a>

      <button type="button" class="bp-btn bp-btn-primary bp-btn-sm" data-open-modal="bp-modal-new-invoice">
        <?= Icons::svg('plus', 16) ?>
        <span>Invoice</span>
      </button>

      <form method="post" action="<?= bp_e(bp_url('logout')) ?>" style="margin:0;">
        <input type="hidden" name="_token" value="<?= bp_e(Security::csrfToken()) ?>">
        <button type="submit" class="bp-btn bp-btn-ghost bp-btn-sm" title="Sign Out">
          <?= Icons::svg('logout', 16) ?>
          <span class="bp-hide-xs">Sign Out</span>
        </button>
      </form>
    </div>
  </div>
</header>

<div id="bp-drawer-overlay" class="bp-drawer-overlay bp-hidden" data-close-drawer></div>

<div class="bp-app-shell">
  <!-- Desktop Sidebar + Mobile Slide-Out Navigation Drawer -->
  <aside id="bp-sidebar-drawer" class="bp-sidebar" aria-label="Dashboard Navigation">
    <div class="bp-drawer-head">
      <span style="font-weight:700;font-size:15px;">Navigation</span>
      <button type="button" class="bp-btn bp-btn-ghost bp-btn-sm" data-close-drawer aria-label="Close Menu">
        <?= Icons::svg('close', 17) ?>
      </button>
    </div>

    <div class="bp-nav-section-label">Merchant</div>
    <button type="button" class="bp-nav-item active" data-spa-tab="overview">
      <?= Icons::svg('grid', 18) ?><span>Overview</span>
    </button>
    <button type="button" class="bp-nav-item" data-spa-tab="invoices">
      <?= Icons::svg('receipt', 18) ?><span>Invoices</span>
    </button>
    <button type="button" class="bp-nav-item" data-spa-tab="wallets">
      <?= Icons::svg('wallet', 18) ?><span>Wallets (xPub)</span>
    </button>
    <button type="button" class="bp-nav-item" data-spa-tab="links">
      <?= Icons::svg('link', 18) ?><span>Payment Links</span>
    </button>
    <button type="button" class="bp-nav-item" data-spa-tab="prefund">
      <?= Icons::svg('zap', 18) ?><span>Prefund Credit</span>
    </button>
    <button type="button" class="bp-nav-item" data-spa-tab="api">
      <?= Icons::svg('code', 18) ?><span>API &amp; Webhooks</span>
    </button>
    <a href="<?= bp_e(bp_url('api-docs')) ?>" class="bp-nav-item">
      <?= Icons::svg('book', 18) ?><span>API Documentation</span>
    </a>
    <button type="button" class="bp-nav-item" data-spa-tab="settings">
      <?= Icons::svg('sliders', 18) ?><span>Account Settings</span>
    </button>

    <?php if ($isAdmin): ?>
      <div class="bp-nav-section-label">Admin &amp; Security</div>
      <button type="button" class="bp-nav-item" data-spa-tab="admin_merchants">
        <?= Icons::svg('users', 18) ?><span>Merchants</span>
      </button>
      <button type="button" class="bp-nav-item" data-spa-tab="admin_security">
        <?= Icons::svg('shield', 18) ?><span>Security &amp; cPHulk</span>
      </button>
      <button type="button" class="bp-nav-item" data-spa-tab="admin_countries">
        <?= Icons::svg('globe', 18) ?><span>Country Firewall</span>
      </button>
      <button type="button" class="bp-nav-item" data-spa-tab="admin_platform">
        <?= Icons::svg('server', 18) ?><span>Gateway Setup</span>
      </button>
    <?php endif; ?>
  </aside>

  <!-- Main SPA Workspace -->
  <main class="bp-main">

    <!-- 1. OVERVIEW PANEL -->
    <section data-spa-panel="overview">
      <div class="bp-row-between bp-mb-4">
        <div>
          <h1 style="margin:0;font-size:22px;">Hi, <?= bp_e($user['name']) ?></h1>
          <div class="bp-muted" style="font-size:13.5px;">Direct-to-wallet payments &middot; <?= bp_e($initialState['fee_percent']) ?>% fee</div>
        </div>
        <div class="bp-flex-gap">
          <button type="button" class="bp-btn bp-btn-secondary bp-btn-sm" data-open-modal="bp-modal-add-wallet">
            <?= Icons::svg('wallet', 15) ?><span>Connect Wallet</span>
          </button>
          <button type="button" class="bp-btn bp-btn-primary bp-btn-sm" data-open-modal="bp-modal-new-invoice">
            <?= Icons::svg('plus', 15) ?><span>New Invoice</span>
          </button>
        </div>
      </div>

      <div class="bp-grid-4 bp-mb-4">
        <div class="bp-card-compact">
          <div class="bp-subtle" style="font-size:12px;">Volume Received</div>
          <div class="bp-kpi-val" data-bind="confirmed_volume">$<?= bp_e($initialState['kpis']['confirmed_volume_usd']) ?></div>
        </div>
        <div class="bp-card-compact">
          <div class="bp-subtle" style="font-size:12px;">Paid Invoices</div>
          <div class="bp-kpi-val" data-bind="confirmed_count"><?= (int) $initialState['kpis']['confirmed_count'] ?></div>
        </div>
        <div class="bp-card-compact">
          <div class="bp-subtle" style="font-size:12px;">Active Wallets</div>
          <div class="bp-kpi-val" data-bind="wallets_count"><?= (int) $initialState['kpis']['wallets_count'] ?></div>
        </div>
        <div class="bp-card-compact">
          <div class="bp-subtle" style="font-size:12px;">Fee Credit</div>
          <div class="bp-kpi-val" style="color:#34d399;" data-bind="prefund_balance">$<?= bp_e($initialState['prefund_balance']) ?></div>
        </div>
      </div>

      <?php if ($isAdmin): ?>
        <div class="bp-card-compact bp-mb-4 bp-row-between" style="border-color:rgba(56,189,248,0.35);">
          <div style="font-size:13.5px;"><strong>Admin Controls:</strong> Manage merchants, cPHulk brute-force rules &amp; country firewall.</div>
          <div class="bp-flex-gap">
            <button type="button" class="bp-btn bp-btn-secondary bp-btn-sm" data-spa-tab="admin_security">
              <?= Icons::svg('shield', 14) ?><span>cPHulk Security</span>
            </button>
            <button type="button" class="bp-btn bp-btn-ghost bp-btn-sm" data-spa-tab="admin_countries">
              <?= Icons::svg('globe', 14) ?><span>Country Firewall</span>
            </button>
            <button type="button" class="bp-btn bp-btn-ghost bp-btn-sm" data-spa-tab="admin_platform">
              <?= Icons::svg('server', 14) ?><span>Gateway Setup</span>
            </button>
          </div>
        </div>
      <?php endif; ?>

      <div class="bp-card">
        <div class="bp-row-between bp-mb-2">
          <h2 style="margin:0;font-size:16px;">Recent Invoices</h2>
          <button type="button" class="bp-btn bp-btn-ghost bp-btn-sm" data-spa-tab="invoices">View All</button>
        </div>
        <div class="bp-table-wrap">
          <table class="bp-table">
            <thead>
              <tr><th>Invoice</th><th>Amount</th><th>Crypto</th><th>Status</th><th></th></tr>
            </thead>
            <tbody id="bp-recent-invoices-tbody"></tbody>
          </table>
        </div>
      </div>
    </section>

    <!-- 2. INVOICES PANEL -->
    <section data-spa-panel="invoices" class="bp-hidden">
      <div class="bp-row-between bp-mb-4">
        <h1 style="margin:0;font-size:21px;">Invoices</h1>
        <button type="button" class="bp-btn bp-btn-primary bp-btn-sm" data-open-modal="bp-modal-new-invoice">
          <?= Icons::svg('plus', 15) ?><span>New Invoice</span>
        </button>
      </div>
      <div class="bp-card">
        <div class="bp-table-wrap">
          <table class="bp-table">
            <thead>
              <tr><th>Invoice / Order</th><th>Fiat</th><th>Crypto</th><th>Status</th><th></th></tr>
            </thead>
            <tbody id="bp-all-invoices-tbody"></tbody>
          </table>
        </div>
      </div>
    </section>

    <!-- 3. WALLETS (xPub / Address) PANEL -->
    <section data-spa-panel="wallets" class="bp-hidden">
      <div class="bp-row-between bp-mb-4">
        <div>
          <h1 style="margin:0;font-size:21px;">Non-Custodial Wallets</h1>
          <div class="bp-muted" style="font-size:13.5px;">Funds route straight to your external wallet. Private keys never touch this server.</div>
        </div>
        <button type="button" class="bp-btn bp-btn-primary bp-btn-sm" data-open-modal="bp-modal-add-wallet">
          <?= Icons::svg('plus', 15) ?><span>Connect Wallet</span>
        </button>
      </div>
      <div id="bp-wallets-list" style="display:flex;flex-direction:column;gap:10px;"></div>
    </section>

    <!-- 4. PAYMENT LINKS PANEL -->
    <section data-spa-panel="links" class="bp-hidden">
      <h1 style="margin:0 0 14px;font-size:21px;">Payment Links &amp; Buttons</h1>
      <div class="bp-card bp-mb-4">
        <h3 style="margin:0 0 12px;font-size:15.5px;">Create Shareable Checkout Link</h3>
        <form id="bp-form-new-link">
          <div class="bp-grid-3">
            <div class="bp-field">
              <label class="bp-label">Product / Service Title</label>
              <input type="text" name="title" class="bp-input" placeholder="Pro Plan Subscription" required>
            </div>
            <div class="bp-field">
              <label class="bp-label">Amount (USD, 0 = Buyer Decides)</label>
              <input type="number" step="0.01" min="0" name="fiat_amount" class="bp-input" value="29.00">
            </div>
            <div class="bp-field" style="display:flex;align-items:flex-end;">
              <button type="submit" class="bp-btn bp-btn-primary bp-btn-block">
                <?= Icons::svg('link', 15) ?><span>Create Link</span>
              </button>
            </div>
          </div>
        </form>
      </div>
      <div id="bp-links-list"></div>
    </section>

    <!-- 5. PREFUND CREDIT PANEL (Live Payhub + Crypto Address) -->
    <section data-spa-panel="prefund" class="bp-hidden">
      <div class="bp-row-between bp-mb-4">
        <div>
          <h1 style="margin:0;font-size:21px;">Prefund Fee Credit</h1>
          <div class="bp-muted" style="font-size:13.5px;">
            Because buyers pay your wallet directly, the <?= bp_e($initialState['fee_percent']) ?>% gateway fee is deducted from this credit balance.
          </div>
        </div>
        <div class="bp-badge bp-badge-success" style="font-size:15px;padding:6px 14px;">
          Balance: <span data-bind="prefund_balance">$<?= bp_e($initialState['prefund_balance']) ?></span>
        </div>
      </div>

      <div class="bp-grid-2 bp-mb-4">
        <!-- Option A: Live Payhub Top-Up -->
        <div class="bp-card">
          <div class="bp-badge bp-badge-info bp-mb-2">Instant Card / Bank Transfer</div>
          <h3 style="margin:4px 0 6px;font-size:16.5px;">Top Up with Payhub</h3>
          <p class="bp-muted" style="margin:0 0 14px;font-size:13px;">
            Fund your USD credit in NGN via live Payhub checkout (Rate: &#8358;<?= bp_e($initialState['usd_ngn_rate']) ?>/$1).
          </p>
          <form id="bp-form-payhub-topup">
            <div class="bp-field">
              <label class="bp-label">Credit Amount (USD)</label>
              <input type="number" step="1" min="1" max="50000" name="usd_amount" class="bp-input" value="10" required>
            </div>
            <button type="submit" class="bp-btn bp-btn-primary bp-btn-block">
              <span>Pay via Payhub</span>
              <?= Icons::svg('arrow-right', 15) ?>
            </button>
          </form>
        </div>

        <!-- Option B: Live Crypto Address Top-Up -->
        <div class="bp-card">
          <div class="bp-badge bp-badge-success bp-mb-2">On-Chain Crypto</div>
          <h3 style="margin:4px 0 6px;font-size:16.5px;">Top Up with Crypto</h3>
          <p class="bp-muted" style="margin:0 0 14px;font-size:13px;">
            Send BTC, LTC, BCH, ETH, or USDT to auto-credit your balance on confirmation.
          </p>
          <form id="bp-form-crypto-topup">
            <div class="bp-grid-2">
              <div class="bp-field">
                <label class="bp-label">USD Value</label>
                <input type="number" step="1" min="2" max="50000" name="usd_amount" class="bp-input" value="15" required>
              </div>
              <div class="bp-field">
                <label class="bp-label">Coin</label>
                <select name="coin_symbol" class="bp-select">
                  <option value="USDT-TRC20">USDT (TRC-20)</option>
                  <option value="BTC">Bitcoin (BTC)</option>
                  <option value="LTC">Litecoin (LTC)</option>
                  <option value="BCH">Bitcoin Cash (BCH)</option>
                  <option value="ETH">Ethereum (ETH)</option>
                  <option value="USDT-ERC20">USDT (ERC-20)</option>
                </select>
              </div>
            </div>
            <button type="submit" class="bp-btn bp-btn-secondary bp-btn-block">
              <span>Generate Deposit Address</span>
              <?= Icons::svg('arrow-right', 15) ?>
            </button>
          </form>
        </div>
      </div>

      <div class="bp-card">
        <h3 style="margin:0 0 10px;font-size:15.5px;">Credit &amp; Fee Ledger</h3>
        <div class="bp-table-wrap">
          <table class="bp-table">
            <thead><tr><th>Description</th><th>Source</th><th>Amount</th><th>Status</th><th>Time</th></tr></thead>
            <tbody id="bp-transactions-tbody"></tbody>
          </table>
        </div>
      </div>
    </section>

    <!-- 6. API & WEBHOOKS PANEL -->
    <section data-spa-panel="api" class="bp-hidden">
      <div class="bp-row-between bp-mb-4">
        <div>
          <h1 style="margin:0;font-size:21px;">API, Plugins &amp; Webhooks</h1>
          <div class="bp-muted" style="font-size:13.5px;">Integrate direct-to-wallet crypto payments on any website, CMS, billing system, or Telegram bot.</div>
        </div>
        <div class="bp-flex-gap">
          <a href="<?= bp_e(bp_url('api-docs')) ?>" class="bp-btn bp-btn-primary bp-btn-sm">
            <?= Icons::svg('book', 15) ?><span>Full API &amp; Plugin Docs</span>
          </a>
          <button type="button" id="bp-btn-regen-apikey" class="bp-btn bp-btn-ghost bp-btn-sm">
            <?= Icons::svg('refresh', 14) ?><span>Rotate Keys</span>
          </button>
        </div>
      </div>

      <div class="bp-card bp-mb-4">
        <div class="bp-field">
          <label class="bp-label">Live Merchant API Key (<code class="bp-mono">Authorization: Bearer ...</code>)</label>
          <div style="display:flex;gap:8px;">
            <input id="bp-api-key-inp" data-bind="api_key" type="text" readonly class="bp-input bp-mono" value="<?= bp_e($liveApiKey) ?>">
            <button type="button" class="bp-btn bp-btn-secondary" data-copy-target="bp-api-key-inp">
              <?= Icons::svg('copy', 15) ?><span>Copy</span>
            </button>
          </div>
        </div>

        <div class="bp-field" style="margin-bottom:0;">
          <label class="bp-label">Webhook Signing Secret (<code class="bp-mono">X-BlockPayne-Signature</code> HMAC-SHA256)</label>
          <div style="display:flex;gap:8px;">
            <input id="bp-wh-sec-inp" data-bind="webhook_secret" type="text" readonly class="bp-input bp-mono" value="<?= bp_e($user['webhook_secret'] ?? '') ?>">
            <button type="button" class="bp-btn bp-btn-secondary" data-copy-target="bp-wh-sec-inp">
              <?= Icons::svg('copy', 15) ?><span>Copy</span>
            </button>
          </div>
        </div>
      </div>

      <!-- Ready-to-Install Platform Plugins & SDKs -->
      <div class="bp-card bp-mb-4">
        <div class="bp-row-between bp-mb-2">
          <div>
            <h3 style="margin:0;font-size:15.5px;">Official Plugins, Bridges &amp; PHP SDK (1-Click Download)</h3>
            <div class="bp-subtle" style="font-size:12.5px;">Pre-configured with your gateway URL (<code class="bp-mono"><?= bp_e(rtrim(bp_abs_url(''), '/')) ?></code>).</div>
          </div>
          <a href="<?= bp_e(bp_url('api-docs#plugins')) ?>" style="color:#34d399;font-size:13px;font-weight:600;">Setup Guides &rarr;</a>
        </div>
        <div class="bp-grid-2" style="gap:10px;">
          <?php foreach (\App\Services\IntegrationKitService::catalog() as $kitItem): ?>
            <div class="bp-card-compact bp-row-between" style="gap:10px;">
              <div>
                <div style="font-weight:700;font-size:13.5px;"><?= bp_e($kitItem['name']) ?></div>
                <div class="bp-subtle" style="font-size:11.5px;"><?= bp_e($kitItem['category']) ?></div>
              </div>
              <a href="<?= bp_e(bp_url('integrations/download/' . $kitItem['slug'])) ?>" class="bp-btn bp-btn-secondary bp-btn-sm">
                <?= Icons::svg('download', 14) ?><span>.zip</span>
              </a>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Quick Multi-Language Snippet Switcher right inside Dashboard -->
      <div class="bp-card">
        <div class="bp-row-between bp-mb-2">
          <h3 style="margin:0;font-size:15.5px;">Quick Integration Snippet</h3>
          <a href="<?= bp_e(bp_url('api-docs#webhooks')) ?>" style="color:#34d399;font-size:13px;font-weight:600;">Webhook Verification Guide &rarr;</a>
        </div>

        <div class="bp-code-tabs" data-code-group="dash-api">
          <div class="bp-code-tabbar" role="tablist">
            <button type="button" class="bp-code-tab active" data-lang="d-php">PHP</button>
            <button type="button" class="bp-code-tab" data-lang="d-node">Node.js</button>
            <button type="button" class="bp-code-tab" data-lang="d-python">Python</button>
            <button type="button" class="bp-code-tab" data-lang="d-curl">cURL</button>
          </div>

          <div class="bp-code-pane" data-pane="d-php">
            <div class="bp-code-header">
              <span>Create Invoice (PHP cURL)</span>
              <button type="button" class="bp-btn bp-btn-ghost bp-btn-sm" data-copy-code><?= Icons::svg('copy', 14) ?><span>Copy</span></button>
            </div>
            <pre class="bp-code-pre"><code>&lt;?php
$ch = curl_init('<?= bp_e($apiCreateEndpoint) ?>');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode([
        'amount'        => 49.99,
        'fiat_currency' => 'USD',
        'currency'      => 'BTC',
        'order_id'      => 'ORD-1001',
        'redirect_url'  => 'https://yourwebsite.com/thank-you',
    ]),
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer <?= bp_e($liveApiKey) ?>',
        'Content-Type: application/json',
    ],
]);
$res = json_decode((string) curl_exec($ch), true);
header('Location: ' . $res['checkout_url']);</code></pre>
          </div>

          <div class="bp-code-pane bp-hidden" data-pane="d-node">
            <div class="bp-code-header">
              <span>Create Invoice (Node.js / Fetch)</span>
              <button type="button" class="bp-btn bp-btn-ghost bp-btn-sm" data-copy-code><?= Icons::svg('copy', 14) ?><span>Copy</span></button>
            </div>
            <pre class="bp-code-pre"><code>const res = await fetch('<?= bp_e($apiCreateEndpoint) ?>', {
  method: 'POST',
  headers: {
    'Authorization': 'Bearer <?= bp_e($liveApiKey) ?>',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({
    amount: 49.99,
    fiat_currency: 'USD',
    currency: 'USDT-TRC20',
    order_id: 'ORD-1001'
  })
});
const { checkout_url } = await res.json();</code></pre>
          </div>

          <div class="bp-code-pane bp-hidden" data-pane="d-python">
            <div class="bp-code-header">
              <span>Create Invoice (Python requests)</span>
              <button type="button" class="bp-btn bp-btn-ghost bp-btn-sm" data-copy-code><?= Icons::svg('copy', 14) ?><span>Copy</span></button>
            </div>
            <pre class="bp-code-pre"><code>import requests

res = requests.post(
    "<?= bp_e($apiCreateEndpoint) ?>",
    headers={"Authorization": "Bearer <?= bp_e($liveApiKey) ?>"},
    json={"amount": 49.99, "fiat_currency": "USD", "currency": "BTC", "order_id": "ORD-1001"},
    timeout=15,
).json()
print(res["checkout_url"])</code></pre>
          </div>

          <div class="bp-code-pane bp-hidden" data-pane="d-curl">
            <div class="bp-code-header">
              <span>Create Invoice (cURL)</span>
              <button type="button" class="bp-btn bp-btn-ghost bp-btn-sm" data-copy-code><?= Icons::svg('copy', 14) ?><span>Copy</span></button>
            </div>
            <pre class="bp-code-pre"><code>curl -X POST "<?= bp_e($apiCreateEndpoint) ?>" \
  -H "Authorization: Bearer <?= bp_e($liveApiKey) ?>" \
  -H "Content-Type: application/json" \
  -d '{"amount": 49.99, "fiat_currency": "USD", "currency": "BTC", "order_id": "ORD-1001"}'</code></pre>
          </div>
        </div>
      </div>
    </section>

    <!-- 7. PERSONAL SETTINGS PANEL -->
    <section data-spa-panel="settings" class="bp-hidden">
      <h1 style="margin:0 0 14px;font-size:21px;">Account Settings</h1>
      <div class="bp-card" style="max-width:560px;">
        <form id="bp-form-profile">
          <div class="bp-field">
            <label class="bp-label">Merchant / Store Name</label>
            <input type="text" name="name" class="bp-input" value="<?= bp_e($user['name']) ?>" required>
          </div>

          <div class="bp-field">
            <label class="bp-label">Default Webhook Callback URL</label>
            <input type="url" name="callback_url" class="bp-input bp-mono" value="<?= bp_e($user['callback_url'] ?? '') ?>" placeholder="https://yourwebsite.com/webhooks/blockpayne">
          </div>

          <div class="bp-field">
            <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
              <input type="checkbox" name="enable_login_notifications" value="1" <?= !empty($user['enable_login_notifications']) ? 'checked' : '' ?>>
              <span>Email me security alerts on unrecognized IP logins</span>
            </label>
          </div>

          <hr style="border:none;border-top:1px solid #1e293b;margin:16px 0;">
          <div class="bp-subtle bp-mb-2" style="font-size:12.5px;">Optional Password Change</div>
          <div class="bp-grid-2">
            <div class="bp-field">
              <label class="bp-label">Current Password</label>
              <input type="password" name="current_password" class="bp-input">
            </div>
            <div class="bp-field">
              <label class="bp-label">New Password</label>
              <input type="password" name="new_password" class="bp-input" minlength="8">
            </div>
          </div>

          <button type="submit" class="bp-btn bp-btn-primary">Save Settings</button>
        </form>
      </div>
    </section>

    <?php if ($isAdmin): ?>
      <!-- 8. ADMIN: MERCHANTS PANEL -->
      <section data-spa-panel="admin_merchants" class="bp-hidden">
        <div class="bp-row-between bp-mb-4">
          <h1 style="margin:0;font-size:21px;">Platform Merchants</h1>
        </div>
        <div class="bp-grid-4 bp-mb-4">
          <div class="bp-card-compact"><div class="bp-subtle" style="font-size:12px;">Merchants</div><div class="bp-kpi-val" data-bind="adm_merchants">0</div></div>
          <div class="bp-card-compact"><div class="bp-subtle" style="font-size:12px;">Platform Volume</div><div class="bp-kpi-val" data-bind="adm_volume">$0.00</div></div>
          <div class="bp-card-compact"><div class="bp-subtle" style="font-size:12px;">Fees Earned</div><div class="bp-kpi-val" data-bind="adm_fees">$0.00</div></div>
          <div class="bp-card-compact"><div class="bp-subtle" style="font-size:12px;">Blocked Countries</div><div class="bp-kpi-val" data-bind="adm_blocked_countries">0</div></div>
        </div>
        <div class="bp-card">
          <div class="bp-table-wrap">
            <table class="bp-table">
              <thead><tr><th>Merchant</th><th>Prefund Credit</th><th>Paid</th><th>Status</th><th></th></tr></thead>
              <tbody id="bp-admin-merchants-tbody"></tbody>
            </table>
          </div>
        </div>
      </section>

      <!-- 9. ADMIN: cPHulk SECURITY & BRUTE-FORCE MONITOR -->
      <section data-spa-panel="admin_security" class="bp-hidden">
        <h1 style="margin:0 0 14px;font-size:21px;">cPHulk Brute-Force &amp; Firewall Engine</h1>

        <div class="bp-grid-2 bp-mb-4">
          <div class="bp-card">
            <h3 style="margin:0 0 12px;font-size:15.5px;">Brute-Force Protection Policy</h3>
            <form id="bp-form-bf-settings">
              <div class="bp-grid-2">
                <div class="bp-field">
                  <label class="bp-label">Window (Minutes)</label>
                  <input type="number" name="bf_period_minutes" class="bp-input" value="<?= bp_e($admSettings['bf_period_minutes'] ?? '15') ?>" required>
                </div>
                <div class="bp-field">
                  <label class="bp-label">IP Block Duration</label>
                  <select name="bf_ip_block_duration" class="bp-select">
                    <?php foreach (['1_day' => '1 Day Block', '1_week' => '1 Week Block', '1_month' => '1 Month Block', '1_year' => '1 Year Block'] as $dk => $dl): ?>
                      <option value="<?= bp_e($dk) ?>" <?= ($admSettings['bf_ip_block_duration'] ?? '1_day') === $dk ? 'selected' : '' ?>><?= bp_e($dl) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
              <div class="bp-grid-2">
                <div class="bp-field">
                  <label class="bp-label">Max Account Failures</label>
                  <input type="number" name="bf_max_failures_account" class="bp-input" value="<?= bp_e($admSettings['bf_max_failures_account'] ?? '5') ?>" required>
                </div>
                <div class="bp-field">
                  <label class="bp-label">Max IP Failures</label>
                  <input type="number" name="bf_max_failures_ip" class="bp-input" value="<?= bp_e($admSettings['bf_max_failures_ip'] ?? '10') ?>" required>
                </div>
              </div>

              <div class="bp-field">
                <label style="display:flex;align-items:center;gap:8px;font-size:13.5px;">
                  <input type="checkbox" name="firewall_enabled" value="1" <?= ($admSettings['firewall_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
                  <span>Enable Active Firewall &amp; Brute-Force Engine</span>
                </label>
              </div>
              <div class="bp-field">
                <label style="display:flex;align-items:center;gap:8px;font-size:13.5px;">
                  <input type="checkbox" name="bf_lock_account_on_fail" value="1" <?= ($admSettings['bf_lock_account_on_fail'] ?? '1') === '1' ? 'checked' : '' ?>>
                  <span>Lock merchant accounts on repeated login failures</span>
                </label>
              </div>
              <div class="bp-field">
                <label style="display:flex;align-items:center;gap:8px;font-size:13.5px;">
                  <input type="checkbox" name="bf_protect_default_admin" value="1" <?= ($admSettings['bf_protect_default_admin'] ?? '0') === '1' ? 'checked' : '' ?>>
                  <span>Lock default <code class="bp-mono">admin</code>/<code class="bp-mono">administrator</code> accounts too (Privilege Exception off)</span>
                </label>
              </div>

              <button type="submit" class="bp-btn bp-btn-primary bp-btn-sm">Save Protection Rules</button>
            </form>
          </div>

          <div class="bp-card">
            <h3 style="margin:0 0 12px;font-size:15.5px;">Manual IP Block / King Whitelist</h3>
            <form id="bp-form-ip-rule" class="bp-mb-4">
              <div class="bp-grid-2">
                <div class="bp-field">
                  <label class="bp-label">IP Address</label>
                  <input type="text" name="ip_address" class="bp-input bp-mono" placeholder="203.0.113.42" required>
                </div>
                <div class="bp-field">
                  <label class="bp-label">Action</label>
                  <select name="operation" class="bp-select">
                    <option value="block">Block IP</option>
                    <option value="whitelist">Whitelist (Trusted King Badge)</option>
                  </select>
                </div>
              </div>
              <div class="bp-grid-2">
                <div class="bp-field">
                  <label class="bp-label">Duration</label>
                  <select name="duration" class="bp-select">
                    <option value="1_day">1 Day</option>
                    <option value="1_week">1 Week</option>
                    <option value="1_month">1 Month</option>
                    <option value="1_year">1 Year</option>
                  </select>
                </div>
                <div class="bp-field" style="display:flex;align-items:flex-end;">
                  <button type="submit" class="bp-btn bp-btn-secondary bp-btn-block">Apply IP Rule</button>
                </div>
              </div>
            </form>

            <div class="bp-table-wrap" style="max-height:200px;overflow-y:auto;">
              <table class="bp-table">
                <thead><tr><th>IP</th><th>Status</th><th>Reason</th><th></th></tr></thead>
                <tbody id="bp-admin-iprules-tbody"></tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="bp-card">
          <div class="bp-row-between bp-mb-2">
            <div>
              <h3 style="margin:0;font-size:16px;">Security Monitor &amp; Login History</h3>
              <div class="bp-subtle" style="font-size:12.5px;">IPs with 5+ clean sessions automatically earn the green King Crown trusted badge.</div>
            </div>
          </div>
          <div class="bp-table-wrap">
            <table class="bp-table">
              <thead><tr><th>IP Address</th><th>Account</th><th>Event</th><th>Details</th><th>Time</th></tr></thead>
              <tbody id="bp-admin-seclogs-tbody"></tbody>
            </table>
          </div>
        </div>
      </section>

      <!-- 10. ADMIN: COUNTRY FIREWALL -->
      <section data-spa-panel="admin_countries" class="bp-hidden">
        <div class="bp-row-between bp-mb-4">
          <div>
            <h1 style="margin:0;font-size:21px;">Global Country Firewall</h1>
            <div class="bp-muted" style="font-size:13.5px;">Instant filter across 250 ISO countries. Blacklisted regions are blocked immediately.</div>
          </div>
          <span id="bp-country-match-count" class="bp-badge bp-badge-neutral">250 countries</span>
        </div>

        <div class="bp-card bp-mb-4">
          <div class="bp-grid-3">
            <div style="grid-column: span 2;">
              <input id="bp-country-search" type="text" class="bp-input" placeholder="Search country name or ISO code (e.g. Nigeria, US, GB)…">
            </div>
            <div>
              <select id="bp-country-filter-action" class="bp-select">
                <option value="ALL">All Statuses</option>
                <option value="Whitelisted">Whitelisted Only</option>
                <option value="Blacklisted">Blacklisted Only</option>
                <option value="Not Specified">Not Specified</option>
              </select>
            </div>
          </div>
        </div>

        <div class="bp-card">
          <div class="bp-table-wrap" style="max-height:540px;overflow-y:auto;">
            <table class="bp-table">
              <thead><tr><th>ISO</th><th>Country</th><th style="text-align:right;">Firewall Rule</th></tr></thead>
              <tbody id="bp-countries-tbody"></tbody>
            </table>
          </div>
        </div>
      </section>

      <!-- 11. ADMIN: LIVE GATEWAY, PAYHUB & BLOCKCHAIN NODES -->
      <section data-spa-panel="admin_platform" class="bp-hidden">
        <h1 style="margin:0 0 14px;font-size:21px;">Gateway, Payhub &amp; Live Blockchain APIs</h1>
        <form id="bp-form-platform-settings" class="bp-card">
          <h3 style="margin:0 0 12px;font-size:15.5px;">Branding &amp; Operational Fee</h3>
          <div class="bp-grid-3">
            <div class="bp-field">
              <label class="bp-label">Site Title</label>
              <input type="text" name="site_title" class="bp-input" value="<?= bp_e($admSettings['site_title'] ?? 'BlockPayne') ?>" required>
            </div>
            <div class="bp-field">
              <label class="bp-label">Gateway Fee (%)</label>
              <input type="number" step="0.01" name="gateway_fee_percent" class="bp-input" value="<?= bp_e($admSettings['gateway_fee_percent'] ?? '1.00') ?>">
            </div>
            <div class="bp-field">
              <label class="bp-label">Starter Welcome Credit ($)</label>
              <input type="number" step="0.50" name="welcome_credit_usd" class="bp-input" value="<?= bp_e($admSettings['welcome_credit_usd'] ?? '2.00') ?>">
            </div>
          </div>

          <div class="bp-grid-3">
            <div class="bp-field">
              <label class="bp-label">Custom Logo URL (blank = First-Letter Badge)</label>
              <input type="text" name="custom_logo_url" class="bp-input" value="<?= bp_e($admSettings['custom_logo_url'] ?? '') ?>" placeholder="Leave blank for dynamic badge">
            </div>
            <div class="bp-field">
              <label class="bp-label">Accent Color</label>
              <input type="color" name="brand_color" class="bp-input" value="<?= bp_e($admSettings['brand_color'] ?? '#10b981') ?>" style="height:44px;padding:4px;">
            </div>
            <div class="bp-field">
              <label class="bp-label">USD / NGN Rate (Payhub)</label>
              <input type="number" step="1" name="usd_ngn_rate" class="bp-input" value="<?= bp_e($admSettings['usd_ngn_rate'] ?? '1600') ?>">
            </div>
          </div>

          <hr style="border:none;border-top:1px solid #1e293b;margin:16px 0;">
          <h3 style="margin:0 0 12px;font-size:15.5px;">Live Payhub Fiat Top-Up Integration</h3>
          <div class="bp-grid-3">
            <div class="bp-field">
              <label class="bp-label">Payhub Base URL</label>
              <input type="url" name="payhub_base_url" class="bp-input bp-mono" value="<?= bp_e($admSettings['payhub_base_url'] ?? 'https://merchant.payhub.com.ng') ?>">
            </div>
            <div class="bp-field">
              <label class="bp-label">Payhub Live Public Key</label>
              <input type="text" name="payhub_public_key" class="bp-input bp-mono" value="<?= bp_e($admSettings['payhub_public_key'] ?? '') ?>" placeholder="pk_live_...">
            </div>
            <div class="bp-field">
              <label class="bp-label">Payhub Live Secret Key</label>
              <input type="password" name="payhub_secret_key" class="bp-input bp-mono" value="<?= bp_e($admSettings['payhub_secret_key'] ?? '') ?>" placeholder="sk_live_...">
            </div>
          </div>
          <div class="bp-subtle bp-mb-4" style="font-size:12px;">
            Payhub Webhook URL: <code class="bp-mono"><?= bp_e(bp_abs_url('api/webhooks/payhub')) ?></code>
          </div>

          <hr style="border:none;border-top:1px solid #1e293b;margin:16px 0;">
          <h3 style="margin:0 0 12px;font-size:15.5px;">Admin Live Crypto Treasury Addresses (for Merchant Prefund Deposits)</h3>
          <div class="bp-grid-2">
            <div class="bp-field">
              <label class="bp-label">Admin USDT (TRC-20) Address</label>
              <input type="text" name="admin_usdt_trc20_address" class="bp-input bp-mono" value="<?= bp_e($admSettings['admin_usdt_trc20_address'] ?? '') ?>" placeholder="T...">
            </div>
            <div class="bp-field">
              <label class="bp-label">Admin Bitcoin (BTC) Address</label>
              <input type="text" name="admin_btc_address" class="bp-input bp-mono" value="<?= bp_e($admSettings['admin_btc_address'] ?? '') ?>" placeholder="bc1q...">
            </div>
            <div class="bp-field">
              <label class="bp-label">Admin Litecoin (LTC) Address</label>
              <input type="text" name="admin_ltc_address" class="bp-input bp-mono" value="<?= bp_e($admSettings['admin_ltc_address'] ?? '') ?>" placeholder="ltc1q...">
            </div>
            <div class="bp-field">
              <label class="bp-label">Admin Bitcoin Cash (BCH) Address</label>
              <input type="text" name="admin_bch_address" class="bp-input bp-mono" value="<?= bp_e($admSettings['admin_bch_address'] ?? '') ?>" placeholder="bitcoincash:q...">
            </div>
            <div class="bp-field">
              <label class="bp-label">Admin Ethereum / ERC-20 Address</label>
              <input type="text" name="admin_eth_address" class="bp-input bp-mono" value="<?= bp_e($admSettings['admin_eth_address'] ?? '') ?>" placeholder="0x...">
            </div>
            <div class="bp-field">
              <label class="bp-label">BlockBee API Key (Optional Live Forwarding)</label>
              <input type="text" name="blockbee_api_key" class="bp-input bp-mono" value="<?= bp_e($admSettings['blockbee_api_key'] ?? '') ?>" placeholder="api.blockbee.io live key">
            </div>
          </div>

          <hr style="border:none;border-top:1px solid #1e293b;margin:16px 0;">
          <h3 style="margin:0 0 12px;font-size:15.5px;">Live Blockchain RPC / Explorer Endpoints &amp; Alerts</h3>
          <div class="bp-grid-2">
            <div class="bp-field">
              <label class="bp-label">BTC Esplora / Mempool API</label>
              <input type="url" name="btc_rpc_or_api_url" class="bp-input bp-mono" value="<?= bp_e($admSettings['btc_rpc_or_api_url'] ?? 'https://mempool.space/api') ?>">
            </div>
            <div class="bp-field">
              <label class="bp-label">Ethereum JSON-RPC URL</label>
              <input type="url" name="eth_rpc_url" class="bp-input bp-mono" value="<?= bp_e($admSettings['eth_rpc_url'] ?? 'https://cloudflare-eth.com') ?>">
            </div>
            <div class="bp-field">
              <label class="bp-label">Sender Email (Mail From)</label>
              <input type="email" name="mail_from_address" class="bp-input bp-mono" value="<?= bp_e($admSettings['mail_from_address'] ?? '') ?>" placeholder="security@yourdomain.com">
            </div>
            <div class="bp-field">
              <label class="bp-label">Admin Security Alert Email</label>
              <input type="email" name="admin_alert_email" class="bp-input bp-mono" value="<?= bp_e($admSettings['admin_alert_email'] ?? '') ?>" placeholder="alerts@yourdomain.com">
            </div>
          </div>

          <button type="submit" class="bp-btn bp-btn-primary">Save Live Gateway Settings</button>
        </form>
      </section>
    <?php endif; ?>

  </main>
</div>

<!-- Mobile-First Sticky Bottom Navigation Bar (Crisp Vector Icons) -->
<nav class="bp-mobile-nav" aria-label="Primary Mobile Navigation">
  <button type="button" class="bp-mobile-tab active" data-spa-tab="overview">
    <?= Icons::svg('grid', 20) ?><span>Overview</span>
  </button>
  <button type="button" class="bp-mobile-tab" data-spa-tab="invoices">
    <?= Icons::svg('receipt', 20) ?><span>Invoices</span>
  </button>
  <button type="button" class="bp-mobile-fab" data-open-modal="bp-modal-new-invoice" aria-label="New Invoice">
    <?= Icons::svg('plus', 22) ?>
  </button>
  <button type="button" class="bp-mobile-tab" data-spa-tab="wallets">
    <?= Icons::svg('wallet', 20) ?><span>Wallets</span>
  </button>
  <button type="button" class="bp-mobile-tab" data-spa-tab="prefund">
    <?= Icons::svg('zap', 20) ?><span>Credit</span>
  </button>
</nav>

<!-- MODAL: + New Invoice -->
<div id="bp-modal-new-invoice" class="bp-backdrop bp-hidden">
  <div class="bp-modal" style="padding:22px;">
    <div class="bp-row-between bp-mb-4">
      <h3 style="margin:0;font-size:18px;">Create Crypto Invoice</h3>
      <button type="button" class="bp-btn bp-btn-ghost bp-btn-sm" data-close-modal aria-label="Close">
        <?= Icons::svg('close', 17) ?>
      </button>
    </div>
    <form id="bp-form-new-invoice">
      <div class="bp-grid-2">
        <div class="bp-field">
          <label class="bp-label">Amount</label>
          <input type="number" step="0.01" min="0.50" name="fiat_amount" class="bp-input" value="25.00" required>
        </div>
        <div class="bp-field">
          <label class="bp-label">Fiat Currency</label>
          <select name="fiat_currency" class="bp-select">
            <option value="USD">USD ($)</option>
            <option value="NGN">NGN (&#8358;)</option>
            <option value="EUR">EUR (&euro;)</option>
            <option value="GBP">GBP (&pound;)</option>
          </select>
        </div>
      </div>

      <div class="bp-field">
        <label class="bp-label">Accept Coin</label>
        <select name="currency" class="bp-select">
          <?php foreach ($coins as $sym => $meta): ?>
            <option value="<?= bp_e($sym) ?>"><?= bp_e($meta['name']) ?> (<?= bp_e($sym) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="bp-grid-2">
        <div class="bp-field">
          <label class="bp-label">Order ID (Optional)</label>
          <input type="text" name="order_id" class="bp-input" placeholder="ORD-1024">
        </div>
        <div class="bp-field">
          <label class="bp-label">Title / Item</label>
          <input type="text" name="title" class="bp-input" placeholder="Order Payment">
        </div>
      </div>

      <button type="submit" class="bp-btn bp-btn-primary bp-btn-block">
        <span>Generate Payment Page</span>
        <?= Icons::svg('arrow-right', 16) ?>
      </button>
    </form>
  </div>
</div>

<!-- MODAL: + Connect Wallet (xPub / Address) -->
<div id="bp-modal-add-wallet" class="bp-backdrop bp-hidden">
  <div class="bp-modal" style="padding:22px;">
    <div class="bp-row-between bp-mb-4">
      <h3 style="margin:0;font-size:18px;">Connect Non-Custodial Wallet</h3>
      <button type="button" class="bp-btn bp-btn-ghost bp-btn-sm" data-close-modal aria-label="Close">
        <?= Icons::svg('close', 17) ?>
      </button>
    </div>
    <form id="bp-form-add-wallet">
      <div class="bp-grid-2">
        <div class="bp-field">
          <label class="bp-label">Cryptocurrency</label>
          <select id="bp-wallet-coin-select" name="coin_symbol" class="bp-select">
            <?php foreach ($coins as $sym => $meta): ?>
              <option value="<?= bp_e($sym) ?>"><?= bp_e($sym) ?> — <?= bp_e($meta['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="bp-field">
          <label class="bp-label">Label</label>
          <input type="text" name="wallet_label" class="bp-input" placeholder="Ledger / Electrum / Trust Wallet">
        </div>
      </div>

      <div class="bp-field">
        <label class="bp-label">Extended Public Key (<code class="bp-mono">zpub</code>/<code class="bp-mono">xpub</code>) or Receiving Address</label>
        <textarea id="bp-wallet-xpub-input" name="xpub_key" rows="3" class="bp-textarea bp-mono" style="font-size:12.5px;" placeholder="Paste your wallet zpub6... / xpub6... OR receiving address (bc1q..., ltc1q..., 0x..., T...)" required></textarea>
      </div>

      <div id="bp-wallet-preview-box" class="bp-card-compact bp-hidden bp-mb-4" style="background:#020617;"></div>

      <div class="bp-grid-2">
        <button type="button" id="bp-btn-preview-derivation" class="bp-btn bp-btn-secondary">Verify Derived Addresses</button>
        <button type="submit" class="bp-btn bp-btn-primary">Save Live Wallet</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL: Admin Credit Adjustment -->
<?php if ($isAdmin): ?>
<div id="bp-modal-admin-credit" class="bp-backdrop bp-hidden">
  <div class="bp-modal" style="padding:22px;max-width:400px;">
    <div class="bp-row-between bp-mb-4">
      <h3 style="margin:0;font-size:17px;">Credit <span id="bp-adm-credit-name">Merchant</span></h3>
      <button type="button" class="bp-btn bp-btn-ghost bp-btn-sm" data-close-modal aria-label="Close">
        <?= Icons::svg('close', 17) ?>
      </button>
    </div>
    <form id="bp-form-admin-credit">
      <input type="hidden" id="bp-adm-credit-uid" name="user_id" value="">
      <div class="bp-field">
        <label class="bp-label">USD Amount (negative to debit)</label>
        <input type="number" step="0.50" name="amount" class="bp-input" value="20.00" required>
      </div>
      <div class="bp-field">
        <label class="bp-label">Note</label>
        <input type="text" name="note" class="bp-input" value="Manual admin credit top-up">
      </div>
      <button type="submit" class="bp-btn bp-btn-primary bp-btn-block">Apply Credit</button>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- MODAL: Command Palette (Ctrl+K) -->
<div id="bp-cmd-palette" class="bp-backdrop bp-hidden">
  <div class="bp-modal">
    <div style="padding:12px 14px;border-bottom:1px solid #1e293b;display:flex;align-items:center;gap:10px;">
      <?= Icons::svg('search', 17) ?>
      <input id="bp-cmd-input" type="text" class="bp-input" style="border:none;padding:4px 0;min-height:32px;box-shadow:none;" placeholder="Search tabs, actions, API docs, or invoice ID… (Esc to close)">
    </div>
    <div id="bp-cmd-list" style="max-height:340px;overflow-y:auto;"></div>
  </div>
</div>

<script>
  window.BP_SPA_STATE = <?= json_encode($initialState, JSON_UNESCAPED_SLASHES) ?>;
</script>
<?php
$bodyContent = (string) ob_get_clean();
$pageTitle = 'Dashboard';
require __DIR__ . '/layout.php';

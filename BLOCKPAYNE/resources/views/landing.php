<?php
declare(strict_types=1);

use App\Http\Controllers\AuthController;
use App\Services\BlockchainNodeService;
use App\Support\DynamicBrand;
use App\Support\Icons;
use App\Support\Settings;

$user = AuthController::user();
$siteTitle = Settings::get('site_title', 'BlockPayne');
$feePct = Settings::get('gateway_fee_percent', '1.00');
$rates = (new BlockchainNodeService())->getUsdRates(true);

ob_start();
?>
<header class="bp-topbar">
  <div class="bp-container bp-topbar-inner">
    <a href="<?= bp_e(bp_url('')) ?>" class="bp-brand-row">
      <?= DynamicBrand::renderLogoBadge($siteTitle) ?>
      <span><?= bp_e($siteTitle) ?></span>
    </a>
    <nav class="bp-flex-gap" aria-label="Main Navigation">
      <a href="<?= bp_e(bp_url('api-docs')) ?>" class="bp-btn bp-btn-ghost bp-btn-sm">
        <?= Icons::svg('code', 16) ?>
        <span>API Docs</span>
      </a>
      <?php if ($user): ?>
        <a href="<?= bp_e(bp_url('dashboard')) ?>" class="bp-btn bp-btn-primary bp-btn-sm">
          <?= Icons::svg('grid', 16) ?>
          <span>Dashboard</span>
        </a>
      <?php else: ?>
        <a href="<?= bp_e(bp_url('login')) ?>" class="bp-btn bp-btn-ghost bp-btn-sm">Sign In</a>
        <a href="<?= bp_e(bp_url('register')) ?>" class="bp-btn bp-btn-primary bp-btn-sm">
          <span>Get Started</span>
          <?= Icons::svg('arrow-right', 15) ?>
        </a>
      <?php endif; ?>
    </nav>
  </div>
</header>

<main class="bp-container" style="padding-top:44px;padding-bottom:64px;">
  <!-- Clean Hero -->
  <section style="max-width:680px;margin:0 auto;text-align:center;">
    <span class="bp-badge bp-badge-success bp-mb-4">
      <?= Icons::svg('shield', 14) ?>
      <span>Non-Custodial &middot; Direct to Your Wallet</span>
    </span>
    <h1 style="font-size:clamp(28px, 5vw, 46px);line-height:1.12;margin:0 0 14px;letter-spacing:-0.03em;">
      Accept Crypto Directly to Your Wallet.
    </h1>
    <p class="bp-muted" style="font-size:16.5px;margin:0 auto 24px;max-width:520px;">
      BTC, LTC, BCH, ETH &amp; USDT via your own <code class="bp-mono">xPub</code> or wallet address. Zero custody. Simple <?= bp_e($feePct) ?>% fee.
    </p>

    <div class="bp-flex-gap" style="justify-content:center;margin-bottom:28px;">
      <a href="<?= bp_e($user ? bp_url('dashboard') : bp_url('register')) ?>" class="bp-btn bp-btn-primary">
        <?= Icons::svg('wallet', 17) ?>
        <span><?= $user ? 'Open Merchant Dashboard' : 'Create Merchant Account' ?></span>
      </a>
      <a href="<?= bp_e(bp_url('api-docs')) ?>" class="bp-btn bp-btn-secondary">
        <?= Icons::svg('code', 17) ?>
        <span>Integrate API (7 Languages)</span>
      </a>
    </div>

    <!-- Blockonomics-Style Live Blockchain Wallet / xPub / Invoice Watcher Bar -->
    <div class="bp-card" style="text-align:left;padding:14px;">
      <form id="bp-lookup-form" style="display:flex;gap:8px;flex-wrap:wrap;">
        <input
          id="bp-lookup-input"
          type="text"
          class="bp-input bp-mono"
          style="flex:1;min-width:200px;"
          placeholder="Paste BTC/LTC/BCH/ETH/USDT address, xPub/zPub, or Invoice ID…"
          aria-label="Lookup crypto address, xPub, or invoice"
        >
        <button type="submit" class="bp-btn bp-btn-secondary">
          <?= Icons::svg('search', 16) ?>
          <span>Inspect</span>
        </button>
      </form>
      <div id="bp-lookup-result" class="bp-hidden bp-mt-4" style="padding-top:10px;border-top:1px solid #1e293b;"></div>
    </div>
  </section>

  <!-- Supported Coins & Live Market Rates Strip -->
  <section style="max-width:860px;margin:32px auto 0;">
    <div class="bp-grid-3" style="grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;">
      <?php foreach ([
          ['BTC', '$' . number_format($rates['BTC'] ?? 88500, 0)],
          ['LTC', '$' . number_format($rates['LTC'] ?? 96, 2)],
          ['BCH', '$' . number_format($rates['BCH'] ?? 460, 2)],
          ['ETH', '$' . number_format($rates['ETH'] ?? 3150, 0)],
          ['USDT-TRC20', '$' . number_format($rates['USDT-TRC20'] ?? 1.0, 2)],
          ['USDT-ERC20', '$' . number_format($rates['USDT-ERC20'] ?? 1.0, 2)],
      ] as [$sym, $price]): ?>
        <div class="bp-card-compact" style="text-align:center;">
          <div style="font-weight:700;font-size:14px;"><?= bp_e($sym) ?></div>
          <div class="bp-subtle bp-mono" style="font-size:12px;"><?= bp_e($price) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- 3 Simple Pillars (Minimal Text) -->
  <section class="bp-grid-3" style="margin-top:36px;">
    <div class="bp-card">
      <div class="bp-badge bp-badge-info bp-mb-2"><?= Icons::svg('wallet', 13) ?><span>1. Connect Wallet</span></div>
      <h3 style="margin:6px 0 6px;font-size:17px;">Zero Custody</h3>
      <p class="bp-muted" style="margin:0;font-size:14px;">
        Paste your <code class="bp-mono">zPub</code>/<code class="bp-mono">xPub</code> or wallet address. Buyer funds land directly in your wallet.
      </p>
    </div>

    <div class="bp-card">
      <div class="bp-badge bp-badge-success bp-mb-2"><?= Icons::svg('code', 13) ?><span>2. Payment Links &amp; API</span></div>
      <h3 style="margin:6px 0 6px;font-size:17px;">Instant 0-Conf Detection</h3>
      <p class="bp-muted" style="margin:0;font-size:14px;">
        Clean QR checkout, hosted payment links, and signed REST webhooks with instant mempool detection.
      </p>
    </div>

    <div class="bp-card">
      <div class="bp-badge bp-badge-warn bp-mb-2"><?= Icons::svg('zap', 13) ?><span>3. <?= bp_e($feePct) ?>% Prefund Fee</span></div>
      <h3 style="margin:6px 0 6px;font-size:17px;">Payhub + Crypto Top-Up</h3>
      <p class="bp-muted" style="margin:0;font-size:14px;">
        Start with welcome credit. Top up anytime via Payhub (Card/Bank) or on-chain Crypto.
      </p>
    </div>
  </section>
</main>

<footer style="border-top:1px solid #1e293b;padding:22px 16px;text-align:center;" class="bp-subtle">
  <div class="bp-container bp-row-between" style="font-size:13px;">
    <span>&copy; <?= gmdate('Y') ?> <?= bp_e($siteTitle) ?> &middot; Non-Custodial Crypto Gateway</span>
    <div class="bp-flex-gap">
      <a href="<?= bp_e(bp_url('api-docs')) ?>">API Documentation</a>
      <span>&middot;</span>
      <a href="<?= bp_e(bp_url('login')) ?>">Merchant Login</a>
      <span>&middot;</span>
      <a href="<?= bp_e(bp_url('register')) ?>">Create Account</a>
    </div>
  </div>
</footer>
<?php
$bodyContent = (string) ob_get_clean();
$pageTitle = 'Direct-to-Wallet Crypto Payments';
require __DIR__ . '/layout.php';

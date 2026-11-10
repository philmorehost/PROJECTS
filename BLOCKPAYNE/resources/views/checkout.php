<?php
declare(strict_types=1);

use App\Support\DynamicBrand;
use App\Support\Icons;
use App\Support\QrCode;
use App\Support\Security;
use App\Support\Settings;

/**
 * Live Buyer QR Checkout & Hosted Payment Link View.
 * Expects either:
 * - $invoice (formatted invoice array)
 * - OR $paymentLink (link row array) + $availableCoins (list<string>) + $linkError (?string)
 */
$siteTitle = Settings::get('site_title', 'BlockPayne');

ob_start();
?>
<header class="bp-topbar">
  <div class="bp-container bp-topbar-inner" style="max-width:520px;">
    <a href="<?= bp_e(bp_url('')) ?>" class="bp-brand-row">
      <?= DynamicBrand::renderLogoBadge($siteTitle) ?>
      <span><?= bp_e($siteTitle) ?></span>
    </a>
    <span class="bp-badge bp-badge-neutral">
      <?= Icons::svg('shield', 13) ?>
      <span>Direct Wallet Pay</span>
    </span>
  </div>
</header>

<main class="bp-container" style="max-width:480px;padding-top:28px;padding-bottom:60px;">
  <?php if (isset($paymentLink) && is_array($paymentLink)): ?>
    <!-- Hosted Payment Link Coin & Amount Picker -->
    <div class="bp-card">
      <h1 style="margin:0 0 6px;font-size:21px;"><?= bp_e($paymentLink['title']) ?></h1>
      <?php if (!empty($paymentLink['description'])): ?>
        <p class="bp-muted" style="margin:0 0 16px;font-size:14px;"><?= bp_e($paymentLink['description']) ?></p>
      <?php endif; ?>

      <?php if (!empty($linkError)): ?>
        <div class="bp-card-compact bp-mb-4" style="border-color:rgba(239,68,68,0.45);background:rgba(239,68,68,0.1);color:#fca5a5;">
          <?= bp_e($linkError) ?>
        </div>
      <?php endif; ?>

      <form method="post" action="<?= bp_e(bp_url('link/' . $paymentLink['slug'])) ?>">
        <input type="hidden" name="_token" value="<?= bp_e(Security::csrfToken()) ?>">

        <?php if (!empty($paymentLink['allow_custom_amount'])): ?>
          <div class="bp-field">
            <label class="bp-label">Amount (<?= bp_e($paymentLink['fiat_currency']) ?>)</label>
            <input type="number" step="0.01" min="1" name="fiat_amount" class="bp-input" value="<?= ((float) $paymentLink['fiat_amount']) > 0 ? bp_e($paymentLink['fiat_amount']) : '25.00' ?>" required>
          </div>
        <?php else: ?>
          <div class="bp-card-compact bp-mb-4 bp-row-between">
            <span class="bp-muted">Total Due</span>
            <strong style="font-size:20px;">$<?= bp_e($paymentLink['fiat_amount']) ?> <?= bp_e($paymentLink['fiat_currency']) ?></strong>
          </div>
        <?php endif; ?>

        <div class="bp-field">
          <label class="bp-label">Pay With Cryptocurrency</label>
          <select name="currency" class="bp-select" required>
            <?php foreach (($availableCoins ?? ['BTC']) as $c): ?>
              <option value="<?= bp_e($c) ?>"><?= bp_e($c) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <button type="submit" class="bp-btn bp-btn-primary bp-btn-block">
          <span>Continue to Payment</span>
          <?= Icons::svg('arrow-right', 16) ?>
        </button>
      </form>
    </div>

  <?php elseif (isset($invoice) && is_array($invoice)): ?>
    <?php
      $st = $invoice['status'];
      $remMin = str_pad((string) intdiv((int) $invoice['remaining_seconds'], 60), 2, '0', STR_PAD_LEFT);
      $remSec = str_pad((string) (((int) $invoice['remaining_seconds']) % 60), 2, '0', STR_PAD_LEFT);
      $qrSvg = QrCode::svg($invoice['payment_uri'], 210);
      $returnUrl = !empty($invoice['redirect_url'])
          ? (string) $invoice['redirect_url']
          : ($invoice['is_prefund'] ? bp_url('dashboard?tab=prefund') : bp_url(''));
      $returnLabel = !empty($invoice['redirect_url'])
          ? 'Continue to Merchant Website'
          : ($invoice['is_prefund'] ? 'Return to Prefund Balance' : 'Return Home');
    ?>
    <div class="bp-card">
      <div class="bp-row-between bp-mb-2">
        <div>
          <div class="bp-subtle bp-mono" style="font-size:12px;"><?= bp_e($invoice['invoice_id']) ?></div>
          <div style="font-weight:700;font-size:16px;"><?= bp_e($invoice['title']) ?></div>
        </div>
        <div style="text-align:right;">
          <div style="font-weight:800;font-size:20px;">$<?= bp_e($invoice['fiat_amount']) ?> <span class="bp-subtle" style="font-size:12px;"><?= bp_e($invoice['fiat_currency']) ?></span></div>
          <?php if ($st === 'pending' || $st === 'detected'): ?>
            <div class="bp-subtle bp-mono" style="font-size:12px;display:inline-flex;align-items:center;gap:4px;">
              <?= Icons::svg('clock', 13) ?>
              <span id="bp-checkout-timer"><?= bp_e($remMin . ':' . $remSec) ?></span>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Status Pill -->
      <div class="bp-row-between bp-mt-2">
        <span id="bp-checkout-status-banner" class="bp-badge <?= $st === 'confirmed' ? 'bp-badge-success' : ($st === 'detected' ? 'bp-badge-info' : ($st === 'expired' ? 'bp-badge-danger' : 'bp-badge-warn')) ?>">
          <?= $st === 'confirmed' ? 'Payment Confirmed' : ($st === 'detected' ? 'Detected in Mempool (0-Conf)' : ($st === 'expired' ? 'Invoice Expired' : 'Awaiting Blockchain Payment')) ?>
        </span>
        <span id="bp-checkout-coin-pill" class="bp-badge bp-badge-neutral"><?= bp_e($invoice['currency']) ?></span>
      </div>

      <?php if ($st === 'pending' && empty($invoice['is_prefund']) && isset($merchantCoins) && count($merchantCoins) > 1): ?>
        <!-- Live Buyer Multi-Coin Switcher (for WHMCS, WooCommerce, Shopify, Wix, Telegram, etc.) -->
        <div id="bp-checkout-coin-switcher" class="bp-mt-2" style="padding:10px 12px;background:#0b1120;border:1px solid #1e293b;border-radius:10px;">
          <div class="bp-subtle" style="font-size:11.5px;margin-bottom:6px;font-weight:600;">Switch Payment Network:</div>
          <div style="display:flex;flex-wrap:wrap;gap:6px;">
            <?php foreach ($merchantCoins as $mc): ?>
              <button type="button"
                      class="bp-code-tab bp-coin-switch-btn <?= $mc === $invoice['currency'] ? 'active' : '' ?>"
                      data-switch-coin="<?= bp_e($mc) ?>">
                <?= bp_e($mc) ?>
              </button>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <!-- 3-Step Live Progress -->
      <div class="bp-steps">
        <div id="bp-step-pending" class="bp-step <?= $st === 'pending' ? 'active' : 'done' ?>">1. Send</div>
        <div id="bp-step-detected" class="bp-step <?= $st === 'detected' ? 'active' : ($st === 'confirmed' ? 'done' : '') ?>">2. Detected</div>
        <div id="bp-step-confirmed" class="bp-step <?= $st === 'confirmed' ? 'done' : '' ?>">3. Confirmed</div>
      </div>

      <!-- Confirmed State -->
      <div id="bp-checkout-done-box" class="<?= $st === 'confirmed' ? '' : 'bp-hidden' ?>" style="text-align:center;padding:20px 8px;">
        <div style="width:56px;height:56px;border-radius:50%;background:rgba(16,185,129,0.2);color:#34d399;display:inline-flex;align-items:center;justify-content:center;margin-bottom:12px;">
          <?= Icons::svg('check', 28) ?>
        </div>
        <h2 style="margin:0 0 6px;font-size:20px;">Payment Confirmed</h2>
        <p class="bp-muted" style="margin:0 0 16px;font-size:14px;">
          <?= bp_e($invoice['crypto_amount']) ?> <?= bp_e($invoice['currency']) ?> verified on-chain.
        </p>
        <a href="<?= bp_e($returnUrl) ?>" class="bp-btn bp-btn-primary bp-btn-block">
          <span><?= bp_e($returnLabel) ?></span>
          <?= Icons::svg('arrow-right', 16) ?>
        </a>
      </div>

      <!-- Active QR & Copy Box -->
      <div id="bp-checkout-pay-box" class="<?= $st === 'confirmed' ? 'bp-hidden' : '' ?>">
        <div style="display:flex;justify-content:center;margin:16px 0;">
          <div id="bp-checkout-qr-wrap" style="background:#ffffff;padding:10px;border-radius:14px;display:inline-flex;">
            <?= $qrSvg ?>
          </div>
        </div>

        <div class="bp-field">
          <label id="bp-checkout-amt-label" class="bp-label">Send Exact Amount (<?= bp_e($invoice['currency']) ?>)</label>
          <div style="display:flex;gap:8px;">
            <input id="bp-copy-amt" type="text" readonly class="bp-input bp-mono" value="<?= bp_e($invoice['crypto_amount']) ?>" style="font-weight:700;">
            <button type="button" class="bp-btn bp-btn-secondary" onclick="BP.copyText(document.getElementById('bp-copy-amt').value, 'Amount')">
              <?= Icons::svg('copy', 15) ?>
              <span>Copy</span>
            </button>
          </div>
        </div>

        <div class="bp-field">
          <label id="bp-checkout-addr-label" class="bp-label"><?= bp_e($invoice['currency']) ?> Deposit Address</label>
          <div style="display:flex;gap:8px;">
            <input id="bp-copy-addr" type="text" readonly class="bp-input bp-mono" value="<?= bp_e($invoice['deposit_address']) ?>" style="font-size:13px;">
            <button type="button" class="bp-btn bp-btn-secondary" onclick="BP.copyText(document.getElementById('bp-copy-addr').value, 'Address')">
              <?= Icons::svg('copy', 15) ?>
              <span>Copy</span>
            </button>
          </div>
        </div>

        <a id="bp-checkout-uri-btn" href="<?= bp_e($invoice['payment_uri']) ?>" class="bp-btn bp-btn-primary bp-btn-block bp-mt-2">
          <?= Icons::svg('wallet', 17) ?>
          <span>Open in Wallet App</span>
        </a>

        <?php if ($st !== 'expired'): ?>
          <div class="bp-grid-2 bp-mt-4" style="gap:8px;">
            <button type="button" id="bp-btn-check-chain" class="bp-btn bp-btn-secondary bp-btn-sm">
              <?= Icons::svg('refresh', 14) ?>
              <span>Check Blockchain Now</span>
            </button>
            <a id="bp-checkout-explorer-link" href="<?= bp_e($invoice['explorer_url']) ?>" target="_blank" rel="noopener" class="bp-btn bp-btn-ghost bp-btn-sm">
              <?= Icons::svg('external', 14) ?>
              <span>View on Explorer</span>
            </a>
          </div>
        <?php endif; ?>
      </div>

      <div id="bp-checkout-tx" class="bp-mono bp-subtle bp-mt-4 <?= empty($invoice['tx_hash']) ? 'bp-hidden' : '' ?>" style="font-size:11.5px;word-break:break-all;">
        <?= !empty($invoice['tx_hash']) ? ('TX: ' . bp_e($invoice['tx_hash'])) : '' ?>
      </div>
    </div>

    <script>
      window.BP_CHECKOUT = <?= json_encode([
          'invoice_id'        => $invoice['invoice_id'],
          'status'            => $invoice['status'],
          'remaining_seconds' => $invoice['remaining_seconds'],
          'explorer_url'      => $invoice['explorer_url'],
          'redirect_url'      => $invoice['redirect_url'],
      ], JSON_UNESCAPED_SLASHES) ?>;
    </script>
  <?php endif; ?>
</main>
<?php
$bodyContent = (string) ob_get_clean();
$pageTitle = isset($invoice) ? ('Pay ' . $invoice['invoice_id']) : 'Checkout';
require __DIR__ . '/layout.php';

<?php
declare(strict_types=1);

use App\Support\DynamicBrand;
use App\Support\Icons;
use App\Support\Security;
use App\Support\Settings;

/**
 * Simple Mobile-Friendly Login & Registration View
 * Expects: $mode ('login' | 'register'), $error (?string)
 */
$siteTitle = Settings::get('site_title', 'BlockPayne');
$isRegister = ($mode ?? 'login') === 'register';

ob_start();
?>
<header class="bp-topbar">
  <div class="bp-container bp-topbar-inner">
    <a href="<?= bp_e(bp_url('')) ?>" class="bp-brand-row">
      <?= DynamicBrand::renderLogoBadge($siteTitle) ?>
      <span><?= bp_e($siteTitle) ?></span>
    </a>
    <div class="bp-flex-gap">
      <a href="<?= bp_e(bp_url('api-docs')) ?>" class="bp-btn bp-btn-ghost bp-btn-sm">
        <?= Icons::svg('code', 15) ?>
        <span>API Docs</span>
      </a>
      <a href="<?= bp_e(bp_url($isRegister ? 'login' : 'register')) ?>" class="bp-btn bp-btn-secondary bp-btn-sm">
        <?= $isRegister ? 'Sign In' : 'Create Account' ?>
      </a>
    </div>
  </div>
</header>

<main class="bp-container" style="max-width:430px;padding-top:48px;padding-bottom:60px;">
  <div class="bp-card">
    <h1 style="margin:0 0 6px;font-size:22px;">
      <?= $isRegister ? 'Create Merchant Account' : 'Welcome Back' ?>
    </h1>
    <p class="bp-muted" style="margin:0 0 20px;font-size:13.5px;">
      <?= $isRegister ? 'Accept BTC, LTC, BCH, ETH & USDT directly to your wallet.' : 'Sign in to manage your wallets & invoices.' ?>
    </p>

    <?php if (!empty($error)): ?>
      <div class="bp-card-compact bp-mb-4" style="border-color:rgba(239,68,68,0.45);background:rgba(239,68,68,0.1);color:#fca5a5;font-size:13.5px;">
        <?= bp_e($error) ?>
      </div>
    <?php endif; ?>

    <form method="post" action="<?= bp_e(bp_url($isRegister ? 'register' : 'login')) ?>">
      <input type="hidden" name="_token" value="<?= bp_e(Security::csrfToken()) ?>">

      <?php if ($isRegister): ?>
        <div class="bp-field">
          <label class="bp-label">Business or Store Name</label>
          <input type="text" name="name" class="bp-input" placeholder="Acme Store" required autofocus>
        </div>
      <?php endif; ?>

      <div class="bp-field">
        <label class="bp-label">Email Address</label>
        <input type="email" name="email" class="bp-input" placeholder="you@business.com" required <?= !$isRegister ? 'autofocus' : '' ?>>
      </div>

      <div class="bp-field">
        <label class="bp-label">Password</label>
        <input type="password" name="password" class="bp-input" placeholder="<?= $isRegister ? 'At least 8 characters' : '••••••••' ?>" minlength="<?= $isRegister ? '8' : '1' ?>" required>
      </div>

      <button type="submit" class="bp-btn bp-btn-primary bp-btn-block bp-mt-2">
        <span><?= $isRegister ? 'Create Account' : 'Sign In' ?></span>
        <?= Icons::svg('arrow-right', 16) ?>
      </button>
    </form>

    <div style="text-align:center;margin-top:18px;font-size:13.5px;" class="bp-muted">
      <?php if ($isRegister): ?>
        Already have an account? <a href="<?= bp_e(bp_url('login')) ?>" style="color:#34d399;font-weight:600;">Sign In</a>
      <?php else: ?>
        New merchant? <a href="<?= bp_e(bp_url('register')) ?>" style="color:#34d399;font-weight:600;">Create Account</a>
      <?php endif; ?>
    </div>
  </div>
</main>
<?php
$bodyContent = (string) ob_get_clean();
$pageTitle = $isRegister ? 'Create Account' : 'Sign In';
require __DIR__ . '/layout.php';

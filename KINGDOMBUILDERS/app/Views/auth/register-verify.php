<?php use function KB\{e,url}; use KB\Security; ?>
<section class="auth-card panel otp-card">
  <span class="eyebrow">ONE LAST STEP</span>
  <h1>Check your email.</h1>
  <p class="muted">We sent a six-digit code to <strong><?= e($email) ?></strong>. Enter it below to confirm the address and open your account. The code expires in ten minutes.</p>
  <?php if($error): ?><div class="notice error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form class="stack" method="post" autocomplete="off">
    <?= Security::csrfField() ?>
    <label>Six-digit code
      <input class="otp-input" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" minlength="6" required autofocus placeholder="000000" aria-describedby="code-help">
    </label>
    <small class="muted" id="code-help">The code works once. If it has not arrived, check the spam folder, then request a new one below.</small>
    <button class="button full">Confirm and continue <span aria-hidden="true">→</span></button>
  </form>
  <div class="otp-actions">
    <form method="post"><?= Security::csrfField() ?><button name="action" value="resend" class="inline-button">Email me a new code</button></form>
    <a class="text-link" href="<?= e(url('register')) ?>">Use a different address →</a>
  </div>
</section>

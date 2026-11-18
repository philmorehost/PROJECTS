<?php use function KB\{e,url}; use KB\Security; ?>
<section class="auth-card panel otp-card">
  <span class="eyebrow">ONE MORE CHECK</span>
  <h1>Confirm it’s you.</h1>
  <p class="muted">We sent a six-digit sign-in code to <strong><?= e($email) ?></strong>. Enter it to finish signing in. The code expires in ten minutes.</p>
  <?php if($error): ?><div class="notice error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form class="stack" method="post" autocomplete="off">
    <?= Security::csrfField() ?>
    <label>Six-digit code
      <input class="otp-input" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" minlength="6" required autofocus placeholder="000000" aria-describedby="code-help">
    </label>
    <small class="muted" id="code-help">Never share this code. Staff will never ask you for it.</small>
    <label class="check-label"><input type="checkbox" name="remember" value="1" checked> Remember this device for 30 days and skip this step next time</label>
    <button class="button full">Finish signing in <span aria-hidden="true">→</span></button>
  </form>
  <div class="otp-actions">
    <form method="post"><?= Security::csrfField() ?><button name="action" value="resend" class="inline-button">Email me a new code</button></form>
    <a class="text-link" href="<?= e(url('login')) ?>">Start over →</a>
  </div>
</section>

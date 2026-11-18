<?php
use function KB\{e,url,post};
use KB\{Security,Settings,Captcha};
$delivery = (bool) Settings::get('smtp_enabled');
$code = (bool) Settings::get('otp_register');
?>
<section class="auth-layout">
  <div class="auth-story">
    <span class="eyebrow">THERE’S A PLACE FOR YOU HERE</span>
    <h1>Start small.<br><em>Grow deep.</em></h1>
    <p>Join a community exploring the Bible, one thoughtful question at a time.</p>
    <span class="story-reference">YOUR JOURNEY IS JUST BEGINNING.</span>
  </div>
  <div class="panel auth-panel">
    <h2>Create your account.</h2>
    <p class="muted">A few details, and you’re on your way.</p>
    <?php if($error): ?><div class="notice error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <?php if($notice): ?><div class="notice success" role="status"><?= e($notice) ?> <a href="<?= e(url('login')) ?>">Return to sign in.</a></div><?php endif; ?>
    <?php if($code && !$delivery): ?>
      <div class="notice warning" role="status"><p><strong>Sign-up is not open yet.</strong> Confirmation emails cannot be sent because email delivery is not configured. Please contact the administrator.</p></div>
    <?php endif; ?>
    <?php $mode='register'; require __DIR__.'/_google.php'; ?>
    <form class="stack" method="post" autocomplete="on">
      <?= Security::csrfField() ?>
      <input type="hidden" name="form_seal" value="<?= e(Security::seal(['t'=>time()])) ?>">
      <div class="hp-field" aria-hidden="true">
        <label>Website<input name="website" type="text" tabindex="-1" autocomplete="off" value=""></label>
      </div>
      <label>Full name<input name="name" value="<?= e(post('name')) ?>" required minlength="2" maxlength="120" autocomplete="name"></label>
      <label>Email address<input type="email" name="email" value="<?= e(post('email')) ?>" required maxlength="190" autocomplete="email"></label>
      <div class="form-grid">
        <label>Password<input type="password" name="password" minlength="12" maxlength="72" required autocomplete="new-password"></label>
        <label>Confirm password<input type="password" name="password_confirm" minlength="12" maxlength="72" required autocomplete="new-password"></label>
      </div>
      <small class="muted">Use at least 12 characters. A unique passphrase is best.</small>
      <label class="check-label"><input type="checkbox" name="accept" value="1" required> I agree to use this community respectfully and understand that my learning progress is visible to its administrators.</label>
      <?php require __DIR__.'/_recaptcha.php'; ?>
      <button class="button full">Create account <span aria-hidden="true">↗</span></button>
    </form>
    <?php if($code): ?>
      <p class="form-note">Next: we email you a six-digit code. Enter it once and your account is ready — no link to hunt for in your inbox.</p>
    <?php endif; ?>
    <p class="form-footer">Already part of the community? <a href="<?= e(url('login')) ?>">Sign in</a></p>
  </div>
</section>

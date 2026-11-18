<?php
use function KB\{e};
use KB\Captcha;
/**
 * The reCAPTCHA widget. Rendered only when an administrator has switched the
 * check on and saved both keys; the matching CSP allowances live in
 * header_security() and are added under the same condition.
 */
if (!Captcha::enabled()) return;
?>
<div class="captcha-field">
  <script src="https://www.google.com/recaptcha/api.js" async defer></script>
  <div class="g-recaptcha" data-sitekey="<?= e(Captcha::siteKey()) ?>" data-theme="light"></div>
  <small class="muted">Protected by reCAPTCHA — Google’s
    <a href="https://policies.google.com/privacy" target="_blank" rel="noopener noreferrer">Privacy Policy</a> and
    <a href="https://policies.google.com/terms" target="_blank" rel="noopener noreferrer">Terms</a> apply.</small>
</div>

<?php
declare(strict_types=1);

use RISE63\Core\Security;

use function RISE63\{e, site_title, url};
?><!doctype html>
<html lang="en" data-theme="system">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= e(site_title()) ?></title>
<?php
// A transactional page: no preview card, and never indexed.
$shareTitle   = null;
$shareNoIndex = true;
require __DIR__ . '/../partials/share.php';
?>
<meta name="robots" content="noindex">
<link rel="icon" type="image/svg+xml" href="<?= e(url('favicon.svg')) ?>">
<link rel="stylesheet" href="<?= e(RISE63\asset('app.css')) ?>">
</head>
<body class="guest">
<main class="page">
  <div class="auth-shell">
    <div class="card">
      <h1 style="font-size:1.5rem">Password help</h1>

      <?php if (!empty($sent)): ?>
        <p class="auth-lead"><?= e(RISE63\Core\PasswordReset::NEUTRAL_MESSAGE) ?></p>
        <div class="notice">
          <strong>Nothing in your inbox?</strong>
          Check spam, make sure the address is the one on your account, and wait a minute before trying
          again. Requests are limited to three an hour per address.
        </div>
        <a class="btn btn-secondary btn-block" style="margin-top:16px" href="<?= e(url('login')) ?>">Back to sign in</a>
      <?php elseif (empty($canEmail)): ?>
        <p class="auth-lead">Self-service reset is not available because this installation has no mail
          delivery configured. A reset is completed by the province office so that every change is
          attributable to a named person.</p>
        <ol class="list-plain">
          <li><strong>1. Contact your parish CSR representative</strong> or the province office.</li>
          <li><strong>2. Confirm your identity</strong> — full name, parish and the e-mail on your account.</li>
          <li><strong>3. Receive a temporary password</strong> through a trusted channel, sign in, then choose a new one.</li>
        </ol>
        <a class="btn btn-secondary btn-block" style="margin-top:16px" href="<?= e(url('login')) ?>">Back to sign in</a>
      <?php else: ?>
        <p class="auth-lead">Enter the e-mail address on your account. If it matches a RISE 63 account we
          will send a link to choose a new password. The link works once and expires in 30 minutes.</p>

        <form method="post" action="<?= e(url('')) ?>" data-guard-submit>
          <?= Security::csrfField() ?>
          <input type="hidden" name="action" value="password-reset-request">
          <div class="field">
            <label for="email">E-mail address</label>
            <input type="email" id="email" name="email" autocomplete="email" inputmode="email"
                   required maxlength="150" autocapitalize="none" spellcheck="false">
          </div>
          <button class="btn btn-primary btn-block" type="submit">Send reset link</button>
        </form>

        <details style="margin-top:16px">
          <summary class="faint">No access to that address?</summary>
          <p class="faint" style="margin-top:8px">Ask your parish CSR representative or the province office to
            issue a temporary password. You will be asked to choose a new one at your next sign-in.</p>
        </details>
      <?php endif; ?>
    </div>
  </div>
</main>
<script src="<?= e(RISE63\asset('app.js')) ?>" defer></script>
</body>
</html>

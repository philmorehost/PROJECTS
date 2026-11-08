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
<meta name="robots" content="noindex">
<link rel="icon" type="image/svg+xml" href="<?= e(url('favicon.svg')) ?>">
<link rel="stylesheet" href="<?= e(RISE63\asset('app.css')) ?>">
</head>
<body class="guest">
<main class="page">
  <div class="auth-shell">
    <div class="card">
      <?php if (empty($valid)): ?>
        <h1 style="font-size:1.5rem">That link has expired</h1>
        <p class="auth-lead">Reset links work once and last 30 minutes. Request a fresh one — the previous
          link stops working as soon as a new one is issued.</p>
        <a class="btn btn-primary btn-block" href="<?= e(url('forgot-password')) ?>">Request a new link</a>
        <a class="btn btn-secondary btn-block" style="margin-top:8px" href="<?= e(url('login')) ?>">Back to sign in</a>
      <?php else: ?>
        <h1 style="font-size:1.5rem">Choose a new password</h1>
        <p class="auth-lead">Signing in with it will sign out every other device on your account.</p>

        <form method="post" action="<?= e(url('')) ?>" data-guard-submit>
          <?= Security::csrfField() ?>
          <input type="hidden" name="action" value="password-reset-complete">
          <input type="hidden" name="token" value="<?= e((string) $token) ?>">

          <div class="field">
            <label for="new_password">New password</label>
            <input type="password" id="new_password" name="new_password" autocomplete="new-password"
                   required minlength="10" maxlength="200" aria-describedby="pw-help">
            <p class="faint" id="pw-help">At least 10 characters. A short phrase is better than a single word.</p>
          </div>
          <div class="field">
            <label for="new_password_confirm">Repeat the password</label>
            <input type="password" id="new_password_confirm" name="new_password_confirm" autocomplete="new-password"
                   required minlength="10" maxlength="200">
          </div>

          <p class="faint" id="caps-hint" hidden>Caps Lock is on.</p>
          <button class="btn btn-primary btn-block" type="submit">Save and sign in</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</main>
<script src="<?= e(RISE63\asset('app.js')) ?>" defer></script>
</body>
</html>

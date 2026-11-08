<?php
declare(strict_types=1);

use RISE63\Core\Security;

use function RISE63\{e, url};

/**
 * Full-page password change. Reached two ways: forced (temporary password issued by an
 * administrator) or voluntary from My account. The forced variant adds the banner and hides the
 * "cancel" path, because every other page redirects back here until the change is made.
 */
$forced = (bool) ($forced ?? false);
?><!doctype html>
<html lang="en" data-theme="<?= e((string) ($account['theme_preference'] ?? 'system')) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= e(RISE63\site_title()) ?></title>
<meta name="robots" content="noindex">
<link rel="icon" type="image/svg+xml" href="<?= e(url('favicon.svg')) ?>">
<link rel="stylesheet" href="<?= e(RISE63\asset('app.css')) ?>">
</head>
<body class="guest">
<main class="page">
  <div class="auth-shell">
    <div class="card">
      <?php if ($forced): ?>
        <div class="notice notice-warn" role="status">
          <strong>Set your own password to continue</strong>
          The password you just used was issued by the province office. Choose a new one — it must be
          yours alone and nobody else will be told it.
        </div>
      <?php endif; ?>

      <h1 style="font-size:1.5rem"><?= $forced ? 'Choose your password' : 'Change your password' ?></h1>

      <?php if (!$forced): ?>
        <section class="card" style="padding:0;border:0;box-shadow:none">
          <h2 style="font-size:1rem">A password manager is fine</h2>
          <p class="faint">Neither the province office nor any parish representative will ever ask you for
            this password or your transaction PIN.</p>
        </section>
      <?php endif; ?>

      <form method="post" action="<?= e(url('')) ?>" data-guard-submit>
        <?= Security::csrfField() ?>
        <input type="hidden" name="action" value="password-change">
        <input type="hidden" name="return_to" value="change-password">

        <div class="field">
          <label for="current_password"><?= $forced ? 'Temporary password' : 'Current password' ?></label>
          <input type="password" id="current_password" name="current_password" autocomplete="current-password"
                 required maxlength="200">
        </div>
        <div class="field">
          <label for="new_password">New password</label>
          <input type="password" id="new_password" name="new_password" autocomplete="new-password"
                 required minlength="10" maxlength="200" aria-describedby="pw-help">
          <p class="faint" id="pw-help">At least 10 characters. Avoid your name, your e-mail and any word you
            use elsewhere.</p>
        </div>
        <div class="field">
          <label for="new_password_confirm">Repeat the new password</label>
          <input type="password" id="new_password_confirm" name="new_password_confirm" autocomplete="new-password"
                 required minlength="10" maxlength="200">
        </div>

        <p class="faint" id="caps-hint" hidden>Caps Lock is on.</p>

        <button class="btn btn-primary btn-block" type="submit">
          <?= $forced ? 'Save and continue' : 'Save new password' ?>
        </button>
      </form>

      <div class="row" style="margin-top:12px;justify-content:space-between;gap:8px">
        <form method="post" action="<?= e(url('')) ?>">
          <?= Security::csrfField() ?>
          <input type="hidden" name="action" value="logout">
          <button class="btn btn-quiet btn-sm" type="submit">Sign out instead</button>
        </form>
        <?php if (!$forced): ?>
          <a class="btn btn-quiet btn-sm" href="<?= e(url('profile')) ?>">Cancel</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</main>
<script src="<?= e(RISE63\asset('app.js')) ?>" defer></script>
</body>
</html>

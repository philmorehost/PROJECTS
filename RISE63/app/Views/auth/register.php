<?php
declare(strict_types=1);

use RISE63\Core\Security;

use function RISE63\{e, site_title, url};

?><!doctype html>
<html lang="en" data-theme="system">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Create your account · <?= e(site_title()) ?></title>
<link rel="icon" type="image/svg+xml" href="<?= e(url('favicon.svg')) ?>">
<link rel="manifest" href="<?= e(url('manifest.webmanifest')) ?>">
<link rel="stylesheet" href="<?= e(RISE63\asset('app.css')) ?>">
</head>
<body class="guest">
<main class="page">
  <div class="auth-shell">
    <div class="card">
      <h1 style="font-size:1.5rem">Create your RISE 63 account</h1>
      <p class="auth-lead">Your parish representative may have given you an account already — check before registering.</p>

      <?php foreach ($flashes as $flash): ?>
        <div class="flash flash-<?= e($flash['type']) ?>" role="alert"><?= e($flash['message']) ?></div>
      <?php endforeach; ?>

      <form method="post" action="<?= e(url('')) ?>" autocomplete="on">
        <?= Security::csrfField() ?>
        <input type="hidden" name="action" value="register">

        <div class="field">
          <label for="full_name">Full name</label>
          <input type="text" id="full_name" name="full_name" required maxlength="150" autocomplete="name">
        </div>
        <div class="field">
          <label for="email">E-mail address</label>
          <input type="email" id="email" name="email" required maxlength="150" autocomplete="email"
                 inputmode="email" autocapitalize="none" spellcheck="false">
        </div>
        <div class="field">
          <label for="phone">Phone number <span class="faint">(optional)</span></label>
          <input type="tel" id="phone" name="phone" maxlength="25" autocomplete="tel" inputmode="tel">
        </div>

        <div class="field" data-combobox id="parish-combo" data-endpoint="<?= e(url('api/index.php')) ?>">
          <label for="parish-search">Your parish</label>
          <div class="combo">
            <input type="search" id="parish-search" role="combobox" aria-expanded="false"
                   aria-autocomplete="list" aria-controls="parish-list" autocomplete="off"
                   placeholder="Type a parish, area or zone name">
            <input type="hidden" name="parish_id" value="">
            <ul class="combo-list" id="parish-list" role="listbox" hidden></ul>
          </div>
          <span class="hint">Start typing — the list filters as you type. You can change this later.</span>
          <noscript>
            <select name="parish_id" aria-label="Your parish">
              <option value="">I am not sure yet</option>
            </select>
          </noscript>
        </div>

        <div class="field">
          <label for="password">Password</label>
          <input type="password" id="password" name="password" required minlength="12" maxlength="72" autocomplete="new-password">
          <span class="hint">At least 12 characters. Avoid your name, your e-mail and common words.</span>
        </div>
        <div class="field">
          <label for="password_confirm">Repeat password</label>
          <input type="password" id="password_confirm" name="password_confirm" required minlength="12" maxlength="72" autocomplete="new-password">
        </div>

        <div class="form-actions">
          <button class="btn btn-primary btn-block" type="submit">Create account</button>
        </div>
      </form>
      <p class="auth-alt">Already registered? <a href="<?= e(url('login')) ?>">Sign in</a></p>
    </div>
  </div>
</main>
<script src="<?= e(RISE63\asset('app.js')) ?>" defer></script>
</body>
</html>

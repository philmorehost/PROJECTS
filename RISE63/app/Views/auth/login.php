<?php
declare(strict_types=1);

use RISE63\Core\Security;

use function RISE63\{e, favicon_letter, site_title, title_hue, url};

$letter = favicon_letter();
$hue    = title_hue(site_title());
?><!doctype html>
<html lang="en" data-theme="system">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Sign in · <?= e(site_title()) ?></title>
<meta name="theme-color" content="#2563eb">
<link rel="icon" type="image/svg+xml" href="<?= e(url('favicon.svg')) ?>">
<link rel="manifest" href="<?= e(url('manifest.webmanifest')) ?>">
<link rel="stylesheet" href="<?= e(RISE63\asset('app.css')) ?>">
</head>
<body class="guest">
<main class="page">
  <div class="auth-shell">
    <div class="card">
      <div class="row" style="gap:12px;margin-bottom:18px">
        <span class="brand-mark" style="--brand-hue:<?= e((string) $hue) ?>"><?= e($letter) ?></span>
        <div>
          <h1 style="margin:0;font-size:1.5rem"><?= e(site_title()) ?></h1>
          <p class="faint" style="margin:2px 0 0">LP63 YAYA CSR &amp; Young Professionals</p>
        </div>
      </div>

      <?php foreach ($flashes as $flash): ?>
        <div class="flash flash-<?= e($flash['type']) ?>" role="<?= $flash['type'] === 'error' ? 'alert' : 'status' ?>">
          <?= e($flash['message']) ?>
        </div>
      <?php endforeach; ?>

      <h2>Welcome back</h2>
      <p class="auth-lead">Sign in to apply for programs, track your application or manage the province.</p>

      <form method="post" action="<?= e(url('')) ?>">
        <?= Security::csrfField() ?>
        <input type="hidden" name="action" value="login">
        <div class="field">
          <label for="email">E-mail address</label>
          <input type="email" id="email" name="email" required autocomplete="username"
                 inputmode="email" autocapitalize="none" spellcheck="false" maxlength="150">
        </div>
        <div class="field">
          <label for="password">Password</label>
          <input type="password" id="password" name="password" required autocomplete="current-password" maxlength="1024">
        </div>
        <div class="form-actions">
          <button class="btn btn-primary btn-block" type="submit">Sign in</button>
        </div>
      </form>

      <p class="auth-alt">
        New to RISE 63? <a href="<?= e(url('register')) ?>">Create an account</a><br>
        <a href="<?= e(url('forgot-password')) ?>">Forgot your password?</a>
      </p>
    </div>
    <p class="faint center" style="margin-top:16px">
      Protected sign-in · repeated failures lock the account and the network
    </p>
  </div>
</main>
</body>
</html>

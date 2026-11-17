<?php
declare(strict_types=1);

use function RISE63\{e, site_title, url};
http_response_code(503);
header('Retry-After: 900');
?><!doctype html>
<html lang="en" data-theme="system">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Brief maintenance · <?= e(site_title()) ?></title>
<link rel="icon" type="image/svg+xml" href="<?= e(url('favicon.svg')) ?>">
<link rel="stylesheet" href="<?= e(RISE63\asset('app.css')) ?>">
</head>
<body class="guest">
<main class="page">
  <div class="auth-shell">
    <div class="card">
      <h1>Back shortly</h1>
      <p><?= e(site_title()) ?> is being updated. Your saved drafts and submissions are unaffected — nothing you have entered is lost.</p>
      <p class="faint">Administrators can still sign in to finish the work.</p>
      <a class="btn btn-secondary btn-block" href="<?= e(url('login')) ?>">Administrator sign-in</a>
    </div>
  </div>
</main>
</body>
</html>

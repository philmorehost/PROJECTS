<?php
declare(strict_types=1);

use function RISE63\{e, url};
?><!doctype html>
<html lang="en" data-theme="system">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'Temporary problem') ?></title>
<link rel="icon" type="image/svg+xml" href="<?= e(url('favicon.svg')) ?>">
<link rel="stylesheet" href="<?= e(RISE63\asset('app.css')) ?>">
</head>
<body class="guest">
<main class="page">
  <div class="auth-shell">
    <div class="card">
      <h1><?= e($title ?? 'Temporary problem') ?></h1>
      <p><?= e($message ?? 'Please try again in a moment.') ?></p>
      <a class="btn btn-primary btn-block" href="<?= e(url('')) ?>">Try again</a>
    </div>
  </div>
</main>
</body>
</html>

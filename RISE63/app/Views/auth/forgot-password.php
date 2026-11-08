<?php
declare(strict_types=1);

use function RISE63\{e, site_title, url};
?><!doctype html>
<html lang="en" data-theme="system">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Password help · <?= e(site_title()) ?></title>
<link rel="icon" type="image/svg+xml" href="<?= e(url('favicon.svg')) ?>">
<link rel="stylesheet" href="<?= e(RISE63\asset('app.css')) ?>">
</head>
<body class="guest">
<main class="page">
  <div class="auth-shell">
    <div class="card">
      <h1 style="font-size:1.5rem">Password help</h1>
      <p class="auth-lead">RISE 63 does not e-mail password reset links. A reset is completed by the province office so that
        every change is attributable to a named person.</p>
      <ol class="list-plain">
        <li><strong>1. Contact your parish CSR representative</strong> or the province office using the number on your parish page.</li>
        <li><strong>2. Confirm your identity</strong> — the office will ask for your full name, parish and the e-mail on your account.</li>
        <li><strong>3. Receive a temporary password</strong> through a trusted channel, sign in, then change it immediately from My account.</li>
      </ol>
      <div class="notice" style="margin-top:16px">
        <strong>Account locked after failed sign-ins?</strong>
        Repeated failures suspend the account and may block the network for a day. Only a provincial administrator can clear a
        suspension, so ask them to check Security → Suspended accounts.
      </div>
      <a class="btn btn-secondary btn-block" style="margin-top:16px" href="<?= e(url('login')) ?>">Back to sign in</a>
    </div>
  </div>
</main>
</body>
</html>

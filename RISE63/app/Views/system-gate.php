<?php
declare(strict_types=1);

use function RISE63\{e, url};
http_response_code(503);
header('Retry-After: 3600');
?><!doctype html>
<html lang="en" data-theme="system">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Activation required</title>
<meta name="robots" content="noindex">
<link rel="icon" type="image/svg+xml" href="<?= e(url('favicon.svg')) ?>">
<link rel="stylesheet" href="<?= e(RISE63\asset('app.css')) ?>">
</head>
<body class="guest">
<main class="page">
  <div class="auth-shell">
    <div class="card">
      <h1>Activation required</h1>
      <p><?= e($activationState['message'] ?? 'This installation is not activated.') ?></p>
      <div class="notice">
        <strong>For the site operator</strong>
        This installation runs on a licence locked to a domain. Check that the key in
        <code>config/activation-key.php</code> belongs to <code><?= e($domain ?? 'this domain') ?></code>,
        and that the host allows outbound HTTPS to <code>manager.pmhserver.name.ng</code>.
        The licence service must answer at least once every 24 hours.
      </div>
      <ul class="list-plain" style="margin-top:12px">
        <li><a href="<?= e(RISE63\Core\Activation::MANAGER_ORDER) ?>" target="_blank" rel="noopener noreferrer">Request a licence for this domain</a></li>
        <li><a href="<?= e(RISE63\Core\Activation::MANAGER_SIGNIN) ?>" target="_blank" rel="noopener noreferrer">Sign in to copy or move your key</a></li>
      </ul>
      <form method="post" action="<?= e(url('')) ?>" style="margin-top:16px">
        <?= \RISE63\Core\Security::csrfField() ?>
        <input type="hidden" name="action" value="activation-retry">
        <button class="btn btn-secondary btn-block" type="submit">Try again now</button>
      </form>
      <p class="hint">Re-checks the licence with the provider straight away instead of waiting for the
        cached answer to expire. If it still fails here, run <code>php bin/license-check.php --probe</code>
        on the server — it says whether the key, the domain or the host's outbound HTTPS is at fault.</p>
    </div>
  </div>
</main>
</body>
</html>

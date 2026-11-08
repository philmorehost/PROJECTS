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
      <a class="btn btn-secondary btn-block" style="margin-top:16px" href="<?= e(url('')) ?>">Try again</a>
    </div>
  </div>
</main>
</body>
</html>

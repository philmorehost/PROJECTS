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
        Confirm the system token in <code>config/system-token.php</code>, that this domain is authorized in the manager,
        and that outbound HTTPS to <code>manager.pmhserver.name.ng</code> is allowed by the host.
        A reachable manager is required at least once every 24 hours.
      </div>
      <a class="btn btn-secondary btn-block" style="margin-top:16px" href="<?= e(url('')) ?>">Try again</a>
    </div>
  </div>
</main>
</body>
</html>

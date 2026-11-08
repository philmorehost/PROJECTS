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
      <?php $diagnosis = \RISE63\Core\ActivationDiagnosis::of(is_array($activationState ?? null) ? $activationState : []); ?>
      <p><strong><?= e($diagnosis['headline']) ?></strong></p>
      <p class="muted"><?= e($activationState['message'] ?? 'This installation is not activated.') ?></p>
      <table class="table" style="margin-top:8px">
        <tbody>
          <tr><th scope="row">Domain sent</th><td><code><?= e($diagnosis['detail']['domain'] ?: '(none)') ?></code></td></tr>
          <tr><th scope="row">Key on file</th><td><code><?= e($diagnosis['detail']['key']) ?></code></td></tr>
        </tbody>
      </table>
      <div class="notice">
        <strong>For the site operator</strong>
        <span class="block">The provider says only “invalid key or domain” for every kind of mismatch,
          so check these in order — the first one that is wrong is the cause:</span>
        <ol class="list-plain" style="margin-top:8px; list-style:decimal; padding-left:20px">
          <li>Is the key complete? It is copied from the provider console; a missing digit or a stray
            space makes it an unknown key.</li>
          <li>Is it <em>this</em> licence? A key belonging to another project or another site is
            “unknown” to this one.</li>
          <li>Is the domain on the licence exactly <code><?= e($diagnosis['detail']['domain'] ?: ($domain ?? 'this domain')) ?></code>?
            Domains are matched with <code>www.</code> ignored, but a different subdomain is a
            different licence.</li>
          <li>Is the licence <em>active</em> in the provider console? A suspended one is refused
            the same way.</li>
          <li>The key actually in use is the one in <code>config/activation-key.php</code> — shown
            above, masked. If it is not the key you expect, that is the whole problem.</li>
          <li>Is this installation fully updated? A fault in older releases sent the key in a form
            the provider could never match, so <em>every</em> key was refused however correct it
            was. <code>php bin/update.php --check</code> reports the release you are on.</li>
          <li>Can this host reach <code>manager.pmhserver.name.ng</code> over HTTPS at all?</li>
        </ol>
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
      <p class="hint">Re-checks now instead of waiting for the 6-hour cache to expire. Nothing else on
        this screen changes until the provider accepts the key, so run the checker on the server:
        it is the only thing that can tell the four faults apart.</p>
      <div class="notice" style="margin-top:12px">
        <strong>On the server, in the installation folder</strong>
        <code class="block" style="margin-top:6px">php bin/license-check.php --probe</code>
        <span class="hint">Prints the masked key, the domain, the <strong>exact request body</strong>
          it sends, the provider's DNS, and its literal answer for each plausible domain spelling —
          so a malformed request is visible instead of looking like a wrong key.</span>
      </div>
    </div>
  </div>
</main>
</body>
</html>

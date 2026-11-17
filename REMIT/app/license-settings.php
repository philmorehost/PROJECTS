<?php
declare(strict_types=1);
requireRole($user, ['provincial_admin']);
$licenseService = new LicenseManager(ROOT);
$credentials = $licenseService->credentials();
$updates = new UpdateManager(ROOT);
$offer = $updates->read('offer'); $pending = $updates->read('pending'); $last = $updates->read('last');
try { $installedVersion = $updates->current()['version']; } catch (RuntimeException) { $installedVersion = 'Unknown'; }
?>
<section class="panel form-panel">
 <h2>Licence &amp; activation</h2>
 <p class="muted">Server-side validation through manager.pmhserver.name.ng. No operator setup token is needed.</p>
 <div class="notice"><?= e($licenseState['message'] ?? 'Licence status not checked.') ?><br>Website domain: <strong><?= e(LicenseManager::domain($_SERVER['HTTP_HOST'] ?? '')) ?></strong><br>Licence key: <?= empty($credentials['key']) ? 'Not configured' : 'Stored privately (hidden)' ?></div>
 <form method="post" class="form-grid">
  <?php hiddenAction('license_save') ?>
  <label>Activate or replace licence key<input name="license_key" type="password" required maxlength="255" autocomplete="off"></label>
  <button class="primary">Validate &amp; save licence</button>
 </form>
 <form method="post"><?php hiddenAction('license_recheck') ?><button class="secondary">Recheck licence now</button></form>
</section>
<section class="panel form-panel">
 <h2>Software updates</h2>
 <p class="muted">Installed version: <strong><?= e($installedVersion) ?></strong> · Updates are pulled from License Manager, not directly from GitHub. Only releases published to your licence’s LM tier are returned.</p>
 <div class="notice">Before installation, take an external database and application backup. The updater backs up changed code files, preserves configuration and receipts, and briefly pauses portal requests. Releases requiring SQL migrations or file deletions must be deployed manually.</div>
 <form method="post" class="inline-form"><?php hiddenAction('update_check') ?><span><?= empty($offer) ? 'No update check yet.' : 'Last checked: '.e($offer['checked_at'] ?? '').' · Latest: '.e($offer['version'] ?? '') ?></span><button class="secondary">Check for updates</button></form>
 <?php if (!empty($offer['available'])): ?>
 <h3>Release <?= e($offer['version']) ?></h3><p class="muted"><?= nl2br(e($offer['changelog'] ?? '')) ?></p>
 <form method="post"><?php hiddenAction('update_pull') ?><button class="primary">Pull &amp; verify release</button></form>
 <?php endif ?>
 <?php if (!empty($pending)): ?>
 <h3>Ready to install: <?= e($pending['version']) ?></h3>
 <p class="muted"><?= (int)$pending['files'] ?> checked files · Pulled <?= e($pending['pulled_at']) ?>. The checksum and package are checked again before installation.</p>
 <form method="post" class="form-grid">
  <?php hiddenAction('update_install') ?>
  <label>Transaction PIN<input type="password" name="pin" inputmode="numeric" pattern="[0-9]{4}" autocomplete="off" required></label>
  <label>Backup confirmation<select name="backup_confirmed" required><option value="">Select…</option><option value="1">I have a current external database and application backup</option></select></label>
  <button class="primary">Install verified release</button>
 </form>
 <?php endif ?>
 <?php if (!empty($last)): ?><p class="muted small">Last installation: <?= e($last['version']) ?> · <?= e($last['installed_at']) ?> · Private backup ID: <?= e($last['backup']) ?></p><?php endif ?>
 <p class="muted small">PHP cURL and ZIP extensions, writable code directories and private storage are required. The checksum is provided by the trusted manager over HTTPS; it is not an independent digital signature.</p>
</section>

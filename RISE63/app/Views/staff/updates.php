<?php
declare(strict_types=1);

use RISE63\Core\Activation;
use RISE63\Core\Updater;

use function RISE63\{e, human_date, site_title, url};

/** @var string $title */
/** @var string $currentVersion */
/** @var array<string,mixed>|null $check */
/** @var array<string,mixed>|null $plan */
/** @var list<array<string,mixed>> $history */
/** @var array<string,mixed>|null $result */
/** @var string $manager */
$manager = Activation::MANAGER_SITE;
$uptodate = $check !== null && ($check['ok'] ?? false) === true
    && version_compare((string) $check['version'], (string) $currentVersion, '<=');

$bytes = static function (int $value): string {
    if ($value >= 1048576) {
        return number_format($value / 1048576, 1) . ' MB';
    }
    if ($value >= 1024) {
        return number_format($value / 1024, 1) . ' KB';
    }
    return $value . ' B';
};
?>
<section class="stack">
  <div class="card">
    <div class="row" style="justify-content:space-between">
      <div>
        <h1>Updates</h1>
        <p class="muted">Pull a published release from the licence server onto this installation.</p>
      </div>
      <span class="badge">Installed <?= e($currentVersion) ?></span>
    </div>
  </div>

  <?php if ($result !== null): ?>
    <?php if (!empty($result['ok'])): ?>
      <div class="flash flash-success">
        <strong>Updated to <?= e((string) ($result['version'] ?? '')) ?>.</strong>
        <?= (int) ($result['written'] ?? 0) ?> file(s) written<?= (int) ($result['removed'] ?? 0) > 0 ? ', ' . (int) $result['removed'] . ' removed' : '' ?>.
        <?php if ((int) ($result['sql_run'] ?? 0) > 0): ?>
          <?= (int) $result['sql_run'] ?> database statement(s) applied.
        <?php endif; ?>
        <?php if (($result['backup'] ?? '') !== ''): ?>
          Previous files backed up to <code class="wrap"><?= e(str_replace(\RISE63\ROOT . '/', '', (string) $result['backup'])) ?></code>.
        <?php endif; ?>
        <span class="block" style="margin-top:6px">Sign out and back in to be sure you are running the new code.</span>
      </div>
    <?php else: ?>
      <div class="flash flash-error">
        <strong>The update was not applied.</strong>
        <?php foreach ((array) ($result['errors'] ?? []) as $error): ?>
          <span class="block"><?= e((string) $error) ?></span>
        <?php endforeach; ?>
        <?php if (($result['errors'] ?? []) === []): ?>
          <span class="block">Nothing on disk was changed.</span>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ((int) ($result['sql_failed'] ?? 0) > 0): ?>
      <div class="flash flash-error">
        <strong><?= (int) $result['sql_failed'] ?> database statement(s) failed.</strong>
        The files were installed, but the schema did not fully follow. Send the lines below to support
        before the site is used.
        <ul class="list-plain" style="margin-top:8px">
          <?php foreach ((array) ($result['sql_errors'] ?? []) as $failure): ?>
            <li><code class="wrap"><?= e((string) $failure) ?></code></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <div class="card">
    <h2>Check for an update</h2>
    <p class="muted">
      This asks <a href="<?= e($manager) ?>" target="_blank" rel="noopener noreferrer">the licence server</a>
      which release it has published for this licence. The server pulls from the project's repository,
      so this is the only place an installation should take code from — do not copy files from GitHub
      by hand, or the version you are on stops matching what support can see.
    </p>

    <form method="post" action="<?= e(url('')) ?>">
      <?= \RISE63\Core\Security::csrfField() ?>
      <input type="hidden" name="action" value="update-check">
      <input type="hidden" name="return_to" value="updates">
      <button class="btn btn-primary" type="submit">Check now</button>
    </form>

    <?php if ($check !== null): ?>
      <?php if ($check['ok'] ?? false): ?>
        <div class="notice" style="margin-top:16px">
          <strong><?= $uptodate ? 'This installation is up to date.' : 'Version ' . e((string) $check['version']) . ' is available.' ?></strong>
          <?php if (($check['tier'] ?? '') !== ''): ?>
            <span class="block muted">Licence tier: <?= e((string) $check['tier']) ?></span>
          <?php endif; ?>
          <?php if (trim((string) ($check['changelog'] ?? '')) !== ''): ?>
            <div class="block" style="margin-top:8px; white-space:pre-wrap"><?= e((string) $check['changelog']) ?></div>
          <?php endif; ?>
          <?php if (!$uptodate): ?>
            <form method="post" action="<?= e(url('')) ?>" style="margin-top:12px">
              <?= \RISE63\Core\Security::csrfField() ?>
              <input type="hidden" name="action" value="update-plan">
              <input type="hidden" name="return_to" value="updates">
              <button class="btn btn-primary" type="submit">Download and review what changes</button>
            </form>
            <span class="hint block" style="margin-top:6px">Nothing is written yet — the next screen lists every file.</span>
          <?php endif; ?>
        </div>
      <?php elseif ($check['unreachable'] ?? false): ?>
        <div class="flash flash-error" style="margin-top:16px">
          <strong>The licence server could not be reached.</strong>
          <?= e((string) $check['message']) ?>
          <span class="block hint" style="margin-top:6px">
            This is a hosting question, not a licence one. Ask the host to allow outbound HTTPS to
            <code>manager.pmhserver.name.ng</code>, then check again.
          </span>
        </div>
      <?php else: ?>
        <div class="flash flash-error" style="margin-top:16px">
          <?= e((string) $check['message']) ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <?php if ($plan !== null): ?>
    <div class="card">
      <h2>Review release <?= e((string) ($plan['version'] ?? '') ?: 'without a version number') ?></h2>
      <?php foreach ((array) ($plan['problems'] ?? []) as $problem): ?>
        <div class="flash flash-error"><?= e((string) $problem) ?></div>
      <?php endforeach; ?>
      <?php foreach ((array) ($plan['warnings'] ?? []) as $warning): ?>
        <div class="flash flash-info"><?= e((string) $warning) ?></div>
      <?php endforeach; ?>

      <div class="grid-2">
        <div class="stat"><b><?= count((array) ($plan['add'] ?? [])) ?></b><span>New files</span></div>
        <div class="stat"><b><?= count((array) ($plan['replace'] ?? [])) ?></b><span>Replaced</span></div>
        <div class="stat"><b><?= count((array) ($plan['remove'] ?? [])) ?></b><span>Removed</span></div>
        <div class="stat"><b><?= count((array) ($plan['sql'] ?? [])) ?></b><span>SQL statements</span></div>
      </div>

      <?php
      $unchanged = array_values(array_filter((array) ($plan['replace'] ?? []), static fn (array $row): bool => ($row['same'] ?? false) === true));
      ?>
      <?php if ($unchanged !== []): ?>
        <p class="hint"><?= count($unchanged) ?> of the replaced files are byte-identical to the ones already here.</p>
      <?php endif; ?>

      <?php if ((array) ($plan['add'] ?? []) !== []): ?>
        <details open>
          <summary><strong>New files</strong> (<?= count((array) $plan['add']) ?>)</summary>
          <ul class="list-plain small">
            <?php foreach ((array) $plan['add'] as $row): ?>
              <li><code><?= e((string) $row['path']) ?></code> <span class="muted"><?= e($bytes((int) $row['bytes'])) ?></span></li>
            <?php endforeach; ?>
          </ul>
        </details>
      <?php endif; ?>

      <?php if ((array) ($plan['replace'] ?? []) !== []): ?>
        <details>
          <summary><strong>Replaced files</strong> (<?= count((array) $plan['replace']) ?>)</summary>
          <ul class="list-plain small">
            <?php foreach ((array) $plan['replace'] as $row): ?>
              <li>
                <code><?= e((string) $row['path']) ?></code>
                <span class="muted"><?= e($bytes((int) $row['bytes'])) ?></span>
                <?php if (($row['same'] ?? false) === true): ?><span class="badge badge-info">unchanged</span><?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        </details>
      <?php endif; ?>

      <?php if ((array) ($plan['remove'] ?? []) !== []): ?>
        <details>
          <summary><strong>Files the release deletes</strong> (<?= count((array) $plan['remove']) ?>)</summary>
          <ul class="list-plain small">
            <?php foreach ((array) $plan['remove'] as $path): ?>
              <li><code><?= e((string) $path) ?></code></li>
            <?php endforeach; ?>
          </ul>
        </details>
      <?php endif; ?>

      <?php if ((array) ($plan['sql'] ?? []) !== []): ?>
        <details>
          <summary><strong>Database statements</strong> (<?= count((array) $plan['sql']) ?>)</summary>
          <ol class="list-plain small">
            <?php foreach ((array) $plan['sql'] as $statement): ?>
              <li><code class="wrap"><?= e((string) $statement) ?></code></li>
            <?php endforeach; ?>
          </ol>
          <p class="hint">These run after the files are in place. Take a database backup first if the
            release changes the schema.</p>
        </details>
      <?php endif; ?>

      <?php if (($plan['ok'] ?? false) === true): ?>
        <form method="post" action="<?= e(url('')) ?>" style="margin-top:16px">
          <?= \RISE63\Core\Security::csrfField() ?>
          <input type="hidden" name="action" value="update-apply">
          <input type="hidden" name="return_to" value="updates">
          <button class="btn btn-primary" type="submit">Apply this release</button>
        </form>
        <p class="hint" style="margin-top:6px">
          Files are backed up first. If any file fails to write, everything written up to that point
          is restored.
        </p>
      <?php else: ?>
        <p class="hint" style="margin-top:12px">This release cannot be applied until the problems above are resolved.</p>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="card">
    <h2>Applied updates</h2>
    <?php if ($history === []): ?>
      <p class="muted">No update has been applied since this installation was created. It was built as
        version <?= e(\RISE63\VERSION) ?>.</p>
    <?php else: ?>
      <table class="table">
        <thead><tr><th>Version</th><th>Applied</th><th>Files</th><th>SQL</th></tr></thead>
        <tbody>
        <?php foreach (array_reverse($history) as $row): ?>
          <tr>
            <td><strong><?= e((string) ($row['version'] ?? '—')) ?></strong>
              <?php if (($row['tier'] ?? '') !== ''): ?><span class="badge"><?= e((string) $row['tier']) ?></span><?php endif; ?>
            </td>
            <td><?= e(human_date((string) ($row['applied_at'] ?? ''), true)) ?></td>
            <td><?= (int) ($row['written'] ?? 0) ?> written<?= (int) ($row['removed'] ?? 0) > 0 ? ', ' . (int) $row['removed'] . ' removed' : '' ?></td>
            <td><?= (int) ($row['sql_run'] ?? 0) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p class="hint" style="margin-top:8px">Backups live in <code>storage/updates/backup/</code>.
        Prune old folders once the site has been stable for a few days.</p>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>If an update goes wrong</h2>
    <ul class="list-plain">
      <li>Every replaced file is copied to a timestamped folder under <code>storage/updates/backup/</code>
        before it is overwritten. Copy them back over the installation to roll back by hand.</li>
      <li>The installed version is recorded in <code>config/update-state.json</code>. Deleting that file
        makes the installer-facing version report read <?= e(\RISE63\VERSION) ?> again.</li>
      <li>Run <code>php bin/doctor.php</code> after an update; it checks the result rather than assuming it.</li>
    </ul>
  </div>
</section>

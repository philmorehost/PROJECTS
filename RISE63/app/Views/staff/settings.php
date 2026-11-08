<?php
declare(strict_types=1);

use RISE63\Core\Security;
use RISE63\Core\Settings;

use function RISE63\{e, human_date, relative_time, url};

$groups = [
    'brand' => ['Brand &amp; identity', 'These values appear across the app, the install prompt and the dynamic icon.'],
    'session' => ['Sessions &amp; alerts', 'How long a session may sit idle and who receives sign-in alerts.'],
    'programs' => ['Programs &amp; funds', 'Cycle year, Academy graduation gate and Seed Grant limits.'],
];
?>
<section class="card">
  <h1>Settings</h1>
  <p class="muted">Every value is validated when saved, so an invalid number or e-mail can never enter the database.</p>
</section>

<?php foreach ($groups as $group => [$heading, $blurb]): ?>
  <section class="card">
    <h2><?= $heading ?></h2>
    <p class="muted"><?= $blurb ?></p>
    <form method="post" action="<?= e(url('')) ?>">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="settings-save">
      <input type="hidden" name="group" value="<?= e($group) ?>">
      <input type="hidden" name="return_to" value="settings">
      <?php foreach (Settings::definitionsFor($group) as $key => $definition): ?>
        <?php $current = (string) ($settings[$key] ?? ''); ?>
        <?php if ($definition['type'] === 'bool'): ?>
          <div class="check">
            <input type="checkbox" id="<?= e($key) ?>" name="settings_bool[<?= e($key) ?>]" value="1" <?= $current === '1' ? 'checked' : '' ?>>
            <label for="<?= e($key) ?>" style="font-weight:400"><?= e((string) $definition['label']) ?></label>
          </div>
        <?php elseif ($definition['type'] === 'enum'): ?>
          <div class="field">
            <label for="<?= e($key) ?>"><?= e((string) $definition['label']) ?></label>
            <select id="<?= e($key) ?>" name="settings[<?= e($key) ?>]">
              <?php foreach ($definition['values'] as $value): ?>
                <option value="<?= e($value) ?>" <?= $current === $value ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $value))) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php else: ?>
          <div class="field">
            <label for="<?= e($key) ?>"><?= e((string) $definition['label']) ?></label>
            <input type="<?= $definition['type'] === 'email' ? 'email' : ($definition['type'] === 'int' ? 'number' : 'text') ?>"
                   id="<?= e($key) ?>" name="settings[<?= e($key) ?>]" value="<?= e($current) ?>"
                   <?= isset($definition['min']) ? 'min="' . (int) $definition['min'] . '"' : '' ?>
                   <?= isset($definition['max']) ? 'max="' . (int) $definition['max'] . '"' : '' ?>
                   <?= isset($definition['max']) && $definition['type'] === 'text' ? 'maxlength="' . (int) $definition['max'] . '"' : '' ?>
                   <?= $definition['type'] === 'color' ? 'pattern="#[0-9a-fA-F]{6}"' : '' ?>
                   <?= $definition['type'] === 'money' ? 'step="0.01" min="0"' : '' ?>>
            <?php if ($definition['type'] === 'color'): ?><span class="hint">Six-digit hex, for example #2563eb.</span><?php endif; ?>
            <?php if ($key === 'site_title'): ?><span class="hint">The first letter becomes the app icon.</span><?php endif; ?>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>
      <button class="btn btn-primary" type="submit">Save <?= e(strtolower(strip_tags($heading))) ?></button>
    </form>
  </section>
<?php endforeach; ?>

<section class="card">
  <h2>Notifications</h2>
  <div class="grid grid-3">
    <div class="stat"><b><?= (int) $queue['pending'] ?></b><span>Queued</span></div>
    <div class="stat"><b><?= (int) $queue['failed'] ?></b><span>Failed</span></div>
    <div class="stat"><b><?= count($outbox) ?></b><span>Recent</span></div>
  </div>
  <p class="muted" style="margin-top:12px">Mail is delivered by <code>php bin/mail-worker.php</code> from cron. Queued messages do not leave the server until that worker runs.</p>
  <form method="post" action="<?= e(url('')) ?>">
    <?= Security::csrfField() ?>
    <input type="hidden" name="action" value="queue-test">
    <input type="hidden" name="return_to" value="settings">
    <button class="btn btn-secondary" type="submit">Queue a test message to me</button>
  </form>
  <ul class="list-plain" style="margin-top:12px">
    <?php foreach ($outbox as $message): ?>
      <li class="row row-end">
        <span>
          <strong><?= e((string) $message['subject']) ?></strong>
          <br><span class="faint"><?= e((string) $message['recipient']) ?> · <?= e(relative_time((string) $message['created_at'])) ?></span>
        </span>
        <span class="badge <?= (string) $message['status'] === 'sent' ? 'badge-ok' : ((string) $message['status'] === 'failed' ? 'badge-danger' : 'badge-warn') ?>">
          <?= e((string) $message['status']) ?>
        </span>
      </li>
    <?php endforeach; ?>
    <?php if ($outbox === []): ?><li class="faint">The queue is empty.</li><?php endif; ?>
  </ul>
</section>

<section class="card">
  <h2>Audit trail</h2>
  <div class="table-wrap">
    <table class="table-stack">
      <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Entity</th><th>Summary</th></tr></thead>
      <tbody>
        <?php foreach ($audit as $event): ?>
          <tr>
            <td data-label="When"><?= e(human_date((string) $event['created_at'], true)) ?></td>
            <td data-label="Who"><?= e((string) ($event['actor_email'] ?? 'system')) ?></td>
            <td data-label="Action" class="mono"><?= e((string) $event['action']) ?></td>
            <td data-label="Entity"><?= e((string) $event['entity']) ?><?= $event['entity_id'] !== null ? ' #' . e((string) $event['entity_id']) : '' ?></td>
            <td data-label="Summary"><?= e((string) ($event['summary'] ?? '')) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if ($audit === []): ?><tr><td colspan="5" class="faint" style="padding:16px">No audit events yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

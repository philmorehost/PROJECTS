<?php
declare(strict_types=1);

use RISE63\Core\Security;
use RISE63\Core\Settings;

use function RISE63\{e, human_date, relative_time, url};

$groups = [
    'brand' => ['Brand &amp; identity', 'These values appear across the app, the install prompt and the dynamic icon.'],
    'session' => ['Sessions &amp; alerts', 'How long a session may sit idle and who receives sign-in alerts.'],
    'programs' => ['Programs &amp; funds', 'The documented figures: an 80% Academy attendance gate before a Seed Grant, ₦100,000 per month into the pool, and a per-application ceiling. Pools are set on the Treasury page, where the ledger is.'],
];
?>
<section class="card">
  <h1>Settings</h1>
  <p class="muted">Every value is validated when saved, so an invalid number or e-mail can never enter the database.</p>
</section>

<?php
// ── Program year ────────────────────────────────────────────────────────────────
// The whole point: nobody has to remember to advance this on 1 January. In automatic mode the year
// is the calendar year, full stop, and the rollover happens on the first request after midnight on
// 1 January — no cron entry, no operator action, no chance of last year's cycle quietly accepting
// this year's applications. Manual exists for the case where a province needs to keep a cycle open
// longer, and it is deliberately a two-step change: switch to manual, then say which year.
?>
<section class="card">
  <div class="card-head">
    <div>
      <h2 style="margin:0">Program year</h2>
      <p class="faint" style="margin:2px 0 0">Which cycle new applications and Seed Grant pools belong to.</p>
    </div>
    <span class="badge <?= $yearMode === 'auto' ? 'badge-ok' : 'badge-warn' ?>">
      <?= $yearMode === 'auto' ? 'Automatic' : 'Fixed by an administrator' ?>
    </span>
  </div>

  <p class="program-year-figure">
    <strong><?= (int) $cycleYear ?></strong>
    <span class="faint">
      <?php if ($yearMode === 'auto'): ?>
        — the current calendar year. It advances by itself on 1 January.
      <?php else: ?>
        — held at this year until an administrator releases it. The calendar year is <?= (int) $autoYear ?>.
      <?php endif; ?>
    </span>
  </p>

  <form method="post" action="<?= e(url('')) ?>">
    <?= Security::csrfField() ?>
    <input type="hidden" name="action" value="settings-save">
    <input type="hidden" name="group" value="programs">
    <input type="hidden" name="return_to" value="settings">
    <div class="grid grid-2">
      <div class="field">
        <label for="cycle_year_mode">Behaviour</label>
        <select id="cycle_year_mode" name="settings[cycle_year_mode]">
          <option value="auto" <?= $yearMode === 'auto' ? 'selected' : '' ?>>Follow the calendar year (recommended)</option>
          <option value="manual" <?= $yearMode === 'manual' ? 'selected' : '' ?>>Hold a year I choose</option>
        </select>
      </div>
      <div class="field">
        <label for="current_cycle_year">Year to hold</label>
        <input type="number" id="current_cycle_year" name="settings[current_cycle_year]"
               value="<?= $yearMode === 'manual' ? (int) $cycleYear : (int) $autoYear ?>"
               min="2024" max="2100" step="1"
               <?= $yearMode === 'auto' ? 'disabled' : '' ?>>
        <span class="hint">
          <?= $yearMode === 'auto'
              ? 'Not used while the year follows the calendar — shown for reference.'
              : 'Applications and pool totals will be filed against this year until you change it.' ?>
        </span>
      </div>
    </div>
    <button class="btn btn-primary" type="submit">Save program year</button>
  </form>
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
        <?php
        // Two keys are bookkeeping rather than things an operator should type: the year the pool
        // was set for is written by Fund::setPool, and the program year itself gets its own panel
        // below (auto or manual) because a bare number field invites the mistake this change fixes.
        if (in_array($key, ['seed_grant_opening_balance_year', 'current_cycle_year'], true)) {
            continue;
        }
        $current = (string) ($settings[$key] ?? '');
        ?>
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
  <div class="card-head">
    <div>
      <h2 style="margin:0">Audit trail</h2>
      <p class="faint" style="margin:2px 0 0">
        <?= (int) $auditTotal ?> event<?= $auditTotal === 1 ? '' : 's' ?> recorded.
        Newest first; page through with the controls below — they work without JavaScript.
      </p>
    </div>
    <a class="btn btn-secondary btn-sm" href="<?= e(url('audit')) ?>">Full activity report</a>
  </div>

  <?php
  // Filters are a GET form, so a filtered view is a URL an operator can bookmark or send to
  // somebody else — which is what actually happens when an incident is being discussed.
  $kindLabels = [
      ''          => 'Everything',
      'admins'    => 'Administrators only',
      'financial' => 'Money movements',
      'accounts'  => 'Account changes',
  ];
  ?>
  <form method="get" action="<?= e(url('settings')) ?>" class="filter-row" style="margin-top:12px">
    <div class="field" style="margin:0">
      <label for="audit-kind">Show</label>
      <select id="audit-kind" name="kind">
        <?php foreach ($kindLabels as $value => $text): ?>
          <option value="<?= e($value) ?>" <?= ($auditFilters['kind'] ?? '') === $value ? 'selected' : '' ?>><?= e($text) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field" style="margin:0">
      <label for="audit-actor">Who</label>
      <select id="audit-actor" name="actor">
        <option value="">Anyone</option>
        <?php foreach ($auditActors as $person): ?>
          <option value="<?= (int) $person['id'] ?>" <?= (string) ($auditFilters['actor'] ?? '') === (string) $person['id'] ? 'selected' : '' ?>>
            <?= e((string) $person['full_name']) ?><?= $person['role'] !== 'youth_member' ? ' · ' . e((string) $person['role']) : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field" style="margin:0">
      <label for="audit-do">Action</label>
      <select id="audit-do" name="do">
        <option value="">Any action</option>
        <?php foreach ($auditActions as $action): ?>
          <option value="<?= e($action) ?>" <?= ($auditFilters['action'] ?? '') === $action ? 'selected' : '' ?>><?= e($action) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field grow" style="margin:0">
      <label for="audit-q">Search</label>
      <input type="search" id="audit-q" name="q" value="<?= e($auditFilters['q'] ?? '') ?>" placeholder="Summary, e-mail or id">
    </div>
    <button class="btn btn-secondary" type="submit">Apply</button>
    <?php if (array_filter($auditFilters) !== []): ?>
      <a class="btn btn-quiet" href="<?= e(url('settings', ['per_page' => (string) $auditPager->perPage])) ?>">Clear</a>
    <?php endif; ?>
  </form>

  <div class="table-wrap" style="margin-top:12px">
    <table class="table-stack">
      <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Entity</th><th>Summary</th></tr></thead>
      <tbody>
        <?php foreach ($audit as $event): ?>
          <tr>
            <td data-label="When">
              <?= e(human_date((string) $event['created_at'], true)) ?>
              <br><span class="faint"><?= e(relative_time((string) $event['created_at'])) ?></span>
            </td>
            <td data-label="Who">
              <?php if (!empty($event['actor_name'])): ?>
                <strong><?= e((string) $event['actor_name']) ?></strong><br>
                <span class="faint"><?= e((string) ($event['actor_role'] ?? '')) ?></span>
              <?php else: ?>
                <span class="faint"><?= e((string) ($event['actor_email'] ?? 'system')) ?></span>
              <?php endif; ?>
            </td>
            <td data-label="Action" class="mono"><?= e((string) $event['action']) ?></td>
            <td data-label="Entity"><?= e((string) $event['entity']) ?><?= $event['entity_id'] !== null ? ' #' . e((string) $event['entity_id']) : '' ?></td>
            <td data-label="Summary"><?= e((string) ($event['summary'] ?? '')) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if ($audit === []): ?><tr><td colspan="5" class="faint" style="padding:16px">No events match these filters.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php
  $rows  = $audit;
  $pager = $auditPager;
  $label = 'events';
  require __DIR__ . '/../partials/pager.php';
  ?>
</section>

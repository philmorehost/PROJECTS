<?php
declare(strict_types=1);

use RISE63\Core\Security;
use RISE63\Core\Settings;

use function RISE63\{e, human_date, relative_time, url};

/** The SMTP panel's data, defaulted so this template still renders if a caller omits it. */
$smtp = array_merge([
    'host' => '', 'port' => 587, 'encryption' => 'tls', 'username' => '', 'auth' => 'login',
    'from_email' => '', 'from_name' => '', 'helo' => '', 'configured' => false, 'has_password' => false,
], $smtp ?? []);

/** Migration state, defaulted so the template renders if a caller omits it. */
$schemaState = array_merge(
    ['known' => false, 'error' => '', 'pending' => [], 'applied' => [], 'modified' => [], 'count' => 0],
    $schemaState ?? []
);

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

<section class="card" id="mail">
  <div class="card-head">
    <div>
      <h2 style="margin:0">Mail (SMTP)</h2>
      <p class="faint" style="margin:2px 0 0">How members receive password-reset links and how sign-in alerts leave the server.</p>
    </div>
    <span class="badge <?= $smtp['configured'] ? 'badge-ok' : 'badge-warn' ?>">
      <?= $smtp['configured'] ? 'Configured' : 'Not configured — self-service reset is off' ?>
    </span>
  </div>

  <?php if (!$smtp['configured']): ?>
    <p class="muted" style="margin-top:10px">
      Until this is filled in, <strong>Forgot password?</strong> tells members to contact the province office
      instead of e-mailing a link. Nothing is broken by leaving it empty — but self-service reset does not exist.
    </p>
  <?php endif; ?>

  <form method="post" action="<?= e(url('')) ?>" style="margin-top:12px" autocomplete="off">
    <?= Security::csrfField() ?>
    <input type="hidden" name="action" value="smtp-save">
    <input type="hidden" name="return_to" value="settings">

    <div class="grid grid-2">
      <div class="field">
        <label for="smtp_host">Server address</label>
        <input type="text" id="smtp_host" name="host" required maxlength="190"
               value="<?= e((string) $smtp['host']) ?>" placeholder="smtp.gmail.com"
               autocapitalize="none" autocorrect="off" spellcheck="false">
        <span class="hint">A hostname only — no <code>https://</code> and no port. Your provider&rsquo;s documentation calls this the SMTP or outgoing server.</span>
      </div>
      <div class="field">
        <label for="smtp_port">Port</label>
        <input type="number" id="smtp_port" name="port" required min="1" max="65535" step="1"
               value="<?= (int) $smtp['port'] ?>">
        <span class="hint">587 for STARTTLS, 465 for implicit TLS.</span>
      </div>
      <div class="field">
        <label for="smtp_encryption">Encryption</label>
        <select id="smtp_encryption" name="encryption">
          <?php foreach (RISE63\Core\Smtp::ENCRYPTIONS as $value => $label): ?>
            <option value="<?= e($value) ?>" <?= (string) $smtp['encryption'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <span class="hint">The port has to agree with this choice, or mail never leaves the server.</span>
      </div>
      <div class="field">
        <label for="smtp_auth">Authentication</label>
        <select id="smtp_auth" name="auth">
          <?php foreach (RISE63\Core\Smtp::AUTH_METHODS as $value => $label): ?>
            <option value="<?= e($value) ?>" <?= (string) $smtp['auth'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <span class="hint">Leave as AUTH LOGIN unless your provider says otherwise.</span>
      </div>
      <div class="field">
        <label for="smtp_username">Username</label>
        <input type="text" id="smtp_username" name="username" maxlength="190"
               value="<?= e((string) $smtp['username']) ?>" placeholder="notifications@yourdomain.org"
               autocapitalize="none" autocorrect="off" spellcheck="false">
        <span class="hint">Usually the full e-mail address of the sending mailbox.</span>
      </div>
      <div class="field">
        <label for="smtp_password">Password</label>
        <input type="password" id="smtp_password" name="password" maxlength="255"
               placeholder="<?= $smtp['has_password'] ? 'Saved — leave blank to keep it' : 'Enter the mailbox password' ?>"
               autocomplete="new-password" spellcheck="false">
        <span class="hint">
          <?php /* Deliberately never rendered. Printing the stored password into HTML puts it in
                   the page cache, the browser history and any screenshot of this screen. */ ?>
          <?php if ($smtp['has_password']): ?>
            A password is saved and is never shown again. Leave this blank to keep it, or tick
            <em>remove the saved password</em> below.
          <?php else: ?>
            Gmail and most providers need an <em>app password</em> here, not your normal login password.
          <?php endif; ?>
        </span>
      </div>
      <div class="field">
        <label for="smtp_from_email">Send mail from</label>
        <input type="email" id="smtp_from_email" name="from_email" required maxlength="150" inputmode="email"
               value="<?= e((string) $smtp['from_email']) ?>" placeholder="no-reply@yourdomain.org">
        <span class="hint">Members see this as the sender. It must be a domain you can publish SPF and DKIM for.</span>
      </div>
      <div class="field">
        <label for="smtp_from_name">Sender name</label>
        <input type="text" id="smtp_from_name" name="from_name" maxlength="120"
               value="<?= e((string) $smtp['from_name']) ?>" placeholder="RISE 63">
      </div>
    </div>

    <details style="margin-top:6px">
      <summary class="faint" style="cursor:pointer">Advanced</summary>
      <div class="grid grid-2" style="margin-top:8px">
        <div class="field">
          <label for="smtp_helo">EHLO name</label>
          <input type="text" id="smtp_helo" name="helo" maxlength="190" value="<?= e((string) $smtp['helo']) ?>"
                 autocapitalize="none" placeholder="yourdomain.org">
          <span class="hint">The name this server introduces itself with. Some relays reject a mismatch with your sending domain.</span>
        </div>
      </div>
    </details>

    <?php if ($smtp['has_password']): ?>
      <div class="check" style="margin-top:8px">
        <input type="checkbox" id="smtp_remove_password" name="remove_password" value="1">
        <label for="smtp_remove_password" style="font-weight:400">Remove the saved password</label>
      </div>
    <?php endif; ?>

    <div class="row" style="gap:8px;flex-wrap:wrap;margin-top:14px">
      <button class="btn btn-primary" type="submit">Save mail settings</button>
    </div>
  </form>

  <?php /* Separate forms: each is one POST with one action, so a browser that re-submits after a
           test can never accidentally re-save the settings alongside it. */ ?>
  <?php if ($smtp['configured']): ?>
    <div class="row" style="gap:8px;flex-wrap:wrap;margin-top:12px;padding-top:12px;border-top:1px solid var(--border)">
      <form method="post" action="<?= e(url('')) ?>">
        <?= Security::csrfField() ?>
        <input type="hidden" name="action" value="smtp-test">
        <input type="hidden" name="return_to" value="settings">
        <button class="btn btn-secondary btn-sm" type="submit">Test the connection</button>
      </form>
      <form method="post" action="<?= e(url('')) ?>">
        <?= Security::csrfField() ?>
        <input type="hidden" name="action" value="smtp-send-test">
        <input type="hidden" name="return_to" value="settings">
        <button class="btn btn-secondary btn-sm" type="submit">Send a test message to me</button>
      </form>
      <form method="post" action="<?= e(url('')) ?>"
            onsubmit="return confirm('Remove the mail settings? Self-service password reset will stop working until they are entered again.');">
        <?= Security::csrfField() ?>
        <input type="hidden" name="action" value="smtp-remove">
        <input type="hidden" name="return_to" value="settings">
        <button class="btn btn-quiet btn-sm" type="submit">Remove</button>
      </form>
    </div>
    <p class="faint" style="margin-top:10px">
      <strong>Test the connection</strong> opens the server, signs in and disconnects without sending anything —
      it catches a wrong password or port immediately. <strong>Send a test message</strong> proves delivery
      (and whether it lands in spam), which only a real message can.
    </p>
  <?php endif; ?>
</section>

<section class="card" id="database-updates">
  <div class="card-head">
    <div>
      <h2 style="margin:0">Database updates</h2>
      <p class="faint" style="margin:2px 0 0">Schema changes that ship with each release, and whether this server has them.</p>
    </div>
    <?php if (!$schemaState['known']): ?>
      <span class="badge badge-warn">Cannot read</span>
    <?php elseif ($schemaState['pending'] === [] && $schemaState['modified'] === []): ?>
      <span class="badge badge-ok">Up to date</span>
    <?php else: ?>
      <span class="badge badge-warn"><?= count($schemaState['pending']) ?> waiting</span>
    <?php endif; ?>
  </div>

  <?php if (!$schemaState['known']): ?>
    <p class="muted" style="margin-top:10px">
      The migration state could not be read: <?= e($schemaState['error'] !== '' ? $schemaState['error'] : 'unknown reason') ?>.
      That is usually a database that cannot be reached from here.
    </p>
  <?php else: ?>

    <?php if ($schemaState['pending'] !== []): ?>
      <?php /* This is the panel that was missing when the People page started returning a 500: the
               page read a column from migration 0002 and the database had never been given it. */ ?>
      <p class="muted" style="margin-top:10px">
        <strong><?= count($schemaState['pending']) ?> database update<?= count($schemaState['pending']) === 1 ? ' is' : 's are' ?> waiting.</strong>
        Until they run, any page that uses the new columns will fail. This is not applied from the
        browser on purpose: a schema change is the one operation that can leave a database
        half-modified, and it needs a shell and a backup. Run it from the server:
      </p>
      <pre style="margin:10px 0;padding:10px 12px;overflow-x:auto;background:var(--surface-2,#111827);border-radius:8px"><code>cd <?= e(RISE63\ROOT) ?>

php bin/migrate.php --status
php bin/migrate.php</code></pre>
      <p class="faint">Check with <code>--status</code> first, then apply. Each file is applied once and checksummed.</p>

      <ul class="list-plain" style="margin-top:10px">
        <?php foreach ($schemaState['pending'] as $migration): ?>
          <li class="row row-end" style="padding:8px 0;border-bottom:1px solid var(--border)">
            <span><strong><?= e($migration['summary']) ?></strong></span>
            <span class="badge badge-warn">waiting</span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php elseif ($schemaState['applied'] !== []): ?>
      <p class="muted" style="margin-top:10px">
        All <?= count($schemaState['applied']) ?> database update<?= count($schemaState['applied']) === 1 ? ' has' : 's have' ?>
        been applied to this server.
      </p>
      <details>
        <summary class="faint" style="cursor:pointer">Show what has been applied</summary>
        <ul class="list-plain" style="margin-top:10px">
          <?php foreach ($schemaState['applied'] as $migration): ?>
            <li class="row row-end" style="padding:6px 0;border-bottom:1px solid var(--border)">
              <span class="faint"><?= e($migration['summary']) ?></span>
              <span class="badge badge-ok">applied</span>
            </li>
          <?php endforeach; ?>
        </ul>
      </details>
    <?php else: ?>
      <p class="muted" style="margin-top:10px">
        No database updates are recorded yet. On a fresh installation the schema is created complete,
        and the first migration is applied by <code>php bin/migrate.php</code>.
      </p>
    <?php endif; ?>

    <?php if ($schemaState['modified'] !== []): ?>
      <div style="margin-top:12px;padding:10px 12px;border:1px solid var(--warn);background:var(--warn-soft);border-radius:8px">
        <strong>A shipped migration file no longer matches what this database recorded.</strong>
        <p class="muted" style="margin:6px 0 0">
          The migration runner refuses to continue in this state, because the code would then assume a
          schema nobody applied. Do not edit an applied migration — add the next number instead.
        </p>
        <ul class="list-plain" style="margin-top:8px">
          <?php foreach ($schemaState['modified'] as $problem): ?>
            <li class="faint"><?= e($problem) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

  <?php endif; ?>
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

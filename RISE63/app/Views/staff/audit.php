<?php
declare(strict_types=1);

use RISE63\Core\Money;

use function RISE63\{e, human_date, relative_time, url};

$kindLabels = [
    ''          => 'Everything',
    'admins'    => 'Administrators &amp; treasurers',
    'financial' => 'Money movements',
    'accounts'  => 'Account changes',
];
?>
<section class="card">
  <div class="card-head">
    <div>
      <p class="faint" style="margin:0">Administration</p>
      <h1 style="margin:0">Activity report</h1>
    </div>
    <a class="btn btn-secondary btn-sm" href="<?= e(url('settings')) ?>">Settings</a>
  </div>
  <p class="muted" style="margin-top:8px">
    Every action recorded against every account — administrators, treasurers, representatives and
    members alike. Nothing here is calculated: these are the rows the platform wrote as it worked,
    with who did it, when, and against which record.
  </p>

  <div class="grid grid-4" style="margin-top:12px">
    <div class="stat"><b><?= (int) $total ?></b><span>Events on record</span></div>
    <div class="stat"><b><?= (int) $adminEvents ?></b><span>Done by administrators</span></div>
    <div class="stat"><b><?= (int) $financial ?></b><span>Money &amp; permission changes</span></div>
    <div class="stat"><b><?= (int) $cycleYear ?></b><span>Program year</span></div>
  </div>
</section>

<section class="card">
  <div class="card-head">
    <div>
      <h2 style="margin:0">Who has been doing what</h2>
      <p class="faint" style="margin:2px 0 0">One line per account, busiest first.</p>
    </div>
  </div>
  <div class="table-wrap" style="margin-top:12px">
    <table class="table-stack">
      <thead><tr><th>Account</th><th>Role</th><th class="num">Events</th><th class="num">Money</th><th class="num">Accounts</th><th>Last action</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($summary as $row): ?>
          <tr>
            <td data-label="Account">
              <strong><?= e((string) $row['actor_name']) ?></strong><br>
              <span class="faint"><?= e((string) $row['actor_email']) ?></span>
            </td>
            <td data-label="Role"><?= e(ucwords(str_replace('_', ' ', (string) ($row['actor_role'] ?? 'unknown')))) ?></td>
            <td data-label="Events" class="num"><?= (int) $row['event_count'] ?></td>
            <td data-label="Money" class="num"><?= (int) $row['money_count'] ?></td>
            <td data-label="Accounts" class="num"><?= (int) $row['account_count'] ?></td>
            <td data-label="Last action"><?= e(relative_time((string) $row['last_seen'])) ?></td>
            <td>
              <a class="btn btn-quiet btn-sm" href="<?= e(url('audit', ['actor' => (string) $row['actor_id']])) ?>">Their events</a>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if ($summary === []): ?>
          <tr><td colspan="7" class="faint" style="padding:16px">No activity recorded yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="card">
  <div class="card-head">
    <div>
      <h2 style="margin:0">Event log</h2>
      <p class="faint" style="margin:2px 0 0">Newest first. Filters are in the address bar, so a filtered view can be bookmarked or sent on.</p>
    </div>
  </div>

  <form method="get" action="<?= e(url('audit')) ?>" class="filter-row" style="margin-top:12px">
    <div class="field" style="margin:0">
      <label for="f-kind">Show</label>
      <select id="f-kind" name="kind">
        <?php foreach ($kindLabels as $value => $text): ?>
          <option value="<?= e($value) ?>" <?= ($filters['kind'] ?? '') === $value ? 'selected' : '' ?>><?= $text ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field" style="margin:0">
      <label for="f-actor">Who</label>
      <select id="f-actor" name="actor">
        <option value="">Anyone</option>
        <?php foreach ($actors as $person): ?>
          <option value="<?= (int) $person['id'] ?>" <?= (string) ($filters['actor'] ?? '') === (string) $person['id'] ? 'selected' : '' ?>>
            <?= e((string) $person['full_name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field" style="margin:0">
      <label for="f-do">Action</label>
      <select id="f-do" name="do">
        <option value="">Any action</option>
        <?php foreach ($actions as $action): ?>
          <option value="<?= e($action) ?>" <?= ($filters['action'] ?? '') === $action ? 'selected' : '' ?>><?= e($action) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field grow" style="margin:0">
      <label for="f-q">Search</label>
      <input type="search" id="f-q" name="q" value="<?= e($filters['q'] ?? '') ?>" placeholder="Summary, e-mail or record id">
    </div>
    <button class="btn btn-secondary" type="submit">Apply</button>
    <?php if (array_filter($filters) !== []): ?>
      <a class="btn btn-quiet" href="<?= e(url('audit', ['per_page' => (string) $pager->perPage])) ?>">Clear</a>
    <?php endif; ?>
  </form>

  <div class="table-wrap" style="margin-top:12px">
    <table class="table-stack">
      <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Record</th><th>Detail</th></tr></thead>
      <tbody>
        <?php foreach ($events as $event): ?>
          <tr>
            <td data-label="When">
              <?= e(human_date((string) $event['created_at'], true)) ?>
              <br><span class="faint"><?= e(relative_time((string) $event['created_at'])) ?></span>
            </td>
            <td data-label="Who">
              <?php if (!empty($event['actor_name'])): ?>
                <strong><?= e((string) $event['actor_name']) ?></strong>
                <?php if ((int) ($event['actor_suspended'] ?? 0) === 1): ?><span class="badge badge-danger">suspended</span><?php endif; ?>
                <br><span class="faint"><?= e((string) ($event['actor_role'] ?? '')) ?></span>
              <?php else: ?>
                <span class="faint"><?= e((string) ($event['actor_email'] ?? 'system')) ?></span>
              <?php endif; ?>
            </td>
            <td data-label="Action" class="mono"><?= e((string) $event['action']) ?></td>
            <td data-label="Record"><?= e((string) $event['entity']) ?><?= $event['entity_id'] !== null ? ' #' . e((string) $event['entity_id']) : '' ?></td>
            <td data-label="Detail"><?= e((string) ($event['summary'] ?? '')) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if ($events === []): ?>
          <tr><td colspan="5" class="faint" style="padding:16px">No events match these filters.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php
  $rows  = $events;
  $label = 'events';
  require __DIR__ . '/../partials/pager.php';
  ?>
</section>

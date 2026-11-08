<?php
declare(strict_types=1);

use RISE63\Core\Money;
use RISE63\Core\Program;

use function RISE63\{e, human_date, relative_time, url};
?>
<section class="card">
  <div class="card-head">
    <div>
      <p class="faint" style="margin:0">Province overview</p>
      <h1 style="margin:0">Dashboard</h1>
    </div>
    <a class="btn btn-secondary btn-sm" href="<?= e(url('applications')) ?>">Queue</a>
  </div>
  <p class="muted" style="margin-top:8px">Signed in as <?= e((string) $account['full_name']) ?>. Figures below are read from the live ledger and application records.</p>
</section>

<?php if ($attention > 0 || $slaBreach > 0): ?>
  <div class="flash flash-info" role="status">
    <?= (int) $attention ?> application(s) awaiting review<?= $slaBreach > 0 ? ' · ' . (int) $slaBreach . ' past the service window' : '' ?>.
  </div>
<?php endif; ?>

<div class="grid grid-3">
  <div class="stat"><b><?= e((string) $totals['parishes']) ?></b><span>Active parishes</span></div>
  <div class="stat"><b><?= e((string) $totals['areas']) ?></b><span>Areas across <?= (int) $totals['zones'] ?> zones</span></div>
  <div class="stat"><b><?= e(number_format($totals['youth'])) ?></b><span>Youth recorded</span></div>
  <div class="stat"><b><?= e((string) $summary['total']) ?></b><span>Applications</span></div>
  <div class="stat"><b><?= e((string) $summary['pending']) ?></b><span>In review</span></div>
  <div class="stat"><b><?= e($ledger['disbursed']->short()) ?></b><span>Disbursed to date</span></div>
</div>

<div class="grid grid-2" style="margin-top:16px">
  <section class="card">
    <h2>Recent applications</h2>
    <ul class="list-plain">
      <?php foreach ($recent as $row): ?>
        <li>
          <a href="<?= e(url('application', ['id' => (int) $row['id']])) ?>" style="font-weight:650">
            <?= e((string) $row['full_name']) ?>
          </a>
          <br>
          <span class="faint">
            <?= e(Program::short((string) $row['program_type'])) ?> ·
            <?= e(Program::statusLabel((string) $row['status'])) ?> ·
            <?= e($row['parish_name'] !== null ? (string) $row['parish_name'] : 'No parish') ?> ·
            <?= e(relative_time((string) $row['updated_at'])) ?>
          </span>
        </li>
      <?php endforeach; ?>
      <?php if ($recent === []): ?><li class="faint">No applications yet.</li><?php endif; ?>
    </ul>
  </section>

  <section class="card">
    <h2>Funds</h2>
    <ul class="list-plain">
      <li class="row row-end"><span class="muted">Disbursed</span><strong class="money"><?= e($ledger['disbursed']->format()) ?></strong></li>
      <li class="row row-end"><span class="muted">Awaiting confirmation</span><strong class="money"><?= e($ledger['pending']->format()) ?></strong></li>
      <li class="row row-end"><span class="muted">Seed grant pool remaining</span><strong class="money"><?= e($ledger['pool']->format()) ?></strong></li>
    </ul>
    <?php if (\RISE63\Core\Auth::isTreasurer($account)): ?>
      <a class="btn btn-secondary btn-sm" style="margin-top:12px" href="<?= e(url('treasury')) ?>">Open treasury</a>
    <?php endif; ?>
  </section>
</div>

<section class="card">
  <div class="card-head">
    <h2 style="margin:0">Latest security events</h2>
    <?php if (\RISE63\Core\Auth::isAdmin($account)): ?>
      <a class="btn btn-secondary btn-sm" href="<?= e(url('security')) ?>">Security centre</a>
    <?php endif; ?>
  </div>
  <ul class="list-plain">
    <?php foreach ($security as $log): ?>
      <li>
        <strong><?= e(str_replace('_', ' ', (string) $log['event_type'])) ?></strong>
        <span class="badge <?= (string) $log['event_type'] === 'success' ? 'badge-ok' : 'badge-warn' ?>"><?= e((string) $log['ip_address']) ?></span>
        <br>
        <span class="faint"><?= e(human_date((string) $log['created_at'], true)) ?><?= !empty($log['username_attempted']) ? ' · ' . e((string) $log['username_attempted']) : '' ?></span>
      </li>
    <?php endforeach; ?>
    <?php if ($security === []): ?><li class="faint">No security events recorded.</li><?php endif; ?>
  </ul>
</section>

<?php
declare(strict_types=1);

use RISE63\Core\Money;
use RISE63\Core\Program;

use function RISE63\{e, human_date, relative_time, setting, url};

$firstName = explode(' ', trim((string) $account['full_name']))[0] ?? 'friend';
?>
<section class="card">
  <div class="card-head">
    <div>
      <p class="faint" style="margin:0">Welcome back</p>
      <h1 style="margin:0"><?= e($firstName) ?></h1>
    </div>
    <a class="btn btn-secondary btn-sm" href="<?= e(url('profile')) ?>">My account</a>
  </div>
  <p class="muted" style="margin-top:8px">
    Programme year <?= e((string) setting('current_cycle_year', date('Y'))) ?>.
    <?= $account['parish_id'] === null ? 'Add your parish in My account so your representative can support you.' : 'Your representative can see your applications.' ?>
  </p>
</section>

<?php if ($summary['total'] === 0): ?>
  <section class="card">
    <h2>Start with a program</h2>
    <p class="muted">Pick the one that fits where you are today. You can apply to more than one across the year.</p>
    <div class="grid grid-2" style="margin-top:12px">
      <?php foreach ($eligibility as $type => $info): ?>
        <article class="card card-flat program-card">
          <h3><?= e($info['label']) ?></h3>
          <p><?= e(Program::summary($type)) ?></p>
          <?php if ($info['available']): ?>
            <a class="btn btn-primary btn-sm" href="<?= e(url('programs', ['program' => $type])) ?>">Apply</a>
          <?php else: ?>
            <span class="badge"><?= e((string) $info['reason']) ?></span>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
  </section>
<?php else: ?>
  <div class="grid grid-3" style="margin-bottom:16px">
    <div class="stat"><b><?= e((string) $summary['total']) ?></b><span>Applications</span></div>
    <div class="stat"><b><?= e((string) $summary['pending']) ?></b><span>In review</span></div>
    <div class="stat"><b><?= e(Money::fromCents($summary['requested'])->short()) ?></b><span>Requested in total</span></div>
  </div>

  <h2 class="section-title">Your applications</h2>
  <?php foreach ($applications as $application): ?>
    <article class="card">
      <div class="card-head">
        <div>
          <h3 style="margin:0"><?= e(Program::label((string) $application['program_type'])) ?></h3>
          <p class="faint" style="margin:2px 0 0">
            Applied <?= e(human_date((string) ($application['submitted_at'] ?? $application['created_at']))) ?>
            · <?= e(Money::fromDecimal($application['requested_amount'])->format()) ?>
          </p>
        </div>
        <?php $status = (string) $application['status']; require __DIR__ . '/../partials/status-badge.php'; ?>
      </div>
      <?php
        $tracker = Program::tracker((string) $application['program_type'], (string) $application['status'], (string) ($application['updated_at'] ?? ''));
        require __DIR__ . '/../partials/tracker.php';
      ?>
      <?php if ((string) $application['status'] === 'documents_requested'): ?>
        <div class="notice" style="margin-top:12px">
          <strong>Documents needed</strong>
          <?= e((string) ($application['decision_note'] ?? 'Please upload the requested documents.')) ?>
        </div>
      <?php endif; ?>
      <div class="row" style="margin-top:12px">
        <a class="btn btn-secondary btn-sm" href="<?= e(url('application', ['id' => (int) $application['id']])) ?>">Open</a>
        <span class="faint">Updated <?= e(relative_time((string) $application['updated_at'])) ?></span>
      </div>
    </article>
  <?php endforeach; ?>
<?php endif; ?>

<section class="card">
  <h2>Programs open to you</h2>
  <ul class="list-plain">
    <?php foreach ($eligibility as $type => $info): ?>
      <li class="row row-end" style="align-items:flex-start">
        <span>
          <strong><?= e($info['label']) ?></strong><br>
          <span class="faint"><?= e($info['available'] ? Program::summary($type) : (string) $info['reason']) ?></span>
        </span>
        <?php if ($info['available']): ?>
          <a class="btn btn-secondary btn-sm" href="<?= e(url('programs', ['program' => $type])) ?>">Apply</a>
        <?php else: ?>
          <span class="badge">Not open</span>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
</section>

<?php
declare(strict_types=1);

use RISE63\Core\Money;
use RISE63\Core\Program;

use function RISE63\{e, relative_time, url};
?>
<section class="card">
  <h1>Applications</h1>
  <p class="muted">Filter the queue, then open an application to review it. Only legal next steps are offered on each record.</p>
  <form method="get" action="<?= e(url('applications')) ?>" class="row" style="align-items:flex-end">
    <div class="field grow" style="margin:0">
      <label for="type">Program</label>
      <select id="type" name="type">
        <option value="">All programs</option>
        <?php foreach ($programs as $key => $label): ?>
          <option value="<?= e($key) ?>" <?= $filterType === $key ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field grow" style="margin:0">
      <label for="status">Status</label>
      <select id="status" name="status">
        <option value="">Any status</option>
        <?php foreach ($statuses as $key => $label): ?>
          <option value="<?= e($key) ?>" <?= $filterStatus === $key ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="btn btn-secondary" type="submit">Apply filters</button>
  </form>
</section>

<section class="card card-flat" style="padding:0">
  <?php if ($applications === []): ?>
    <p class="muted" style="padding:18px">No applications match these filters.</p>
  <?php else: ?>
    <div class="table-wrap" style="border:0">
      <table class="table-stack">
        <thead>
          <tr>
            <th>Applicant</th><th>Program</th><th>Status</th><th class="num">Requested</th><th>Updated</th><th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($applications as $row): ?>
            <tr>
              <td data-label="Applicant">
                <strong><?= e((string) $row['full_name']) ?></strong><br>
                <span class="faint"><?= e((string) ($row['parish_name'] ?? 'No parish')) ?></span>
              </td>
              <td data-label="Program"><?= e(Program::short((string) $row['program_type'])) ?></td>
              <td data-label="Status"><?php $status = (string) $row['status']; require __DIR__ . '/../partials/status-badge.php'; ?></td>
              <td data-label="Requested" class="num"><?= e(Money::fromDecimal($row['requested_amount'])->format()) ?></td>
              <td data-label="Updated"><?= e(relative_time((string) $row['updated_at'])) ?></td>
              <td><a class="btn btn-secondary btn-sm" href="<?= e(url('application', ['id' => (int) $row['id']])) ?>">Open</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

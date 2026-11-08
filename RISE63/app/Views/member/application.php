<?php
declare(strict_types=1);

use RISE63\Core\Auth;
use RISE63\Core\Money;
use RISE63\Core\Program;
use RISE63\Core\Security;

use function RISE63\{e, human_date, hours_since, relative_time, setting, url};

$status  = (string) $application['status'];
$isOwner = (int) $application['user_id'] === (int) $account['id'];
$slaHours = (int) setting('education_bridge_sla_hours', 72);
$ageHours = hours_since((string) ($application['submitted_at'] ?? $application['created_at']));
?>
<section class="card">
  <a class="faint" href="<?= e(url($isOwner ? 'my-rise' : 'applications')) ?>">← Back</a>
  <div class="card-head" style="margin-top:10px">
    <div>
      <h1 style="margin:0;font-size:1.375rem"><?= e(Program::label((string) $application['program_type'])) ?></h1>
      <p class="faint" style="margin:2px 0 0">
        #<?= (int) $application['id'] ?> ·
        <?= e($application['full_name'] ?? '') ?><?= !empty($application['parish_name']) ? ' · ' . e((string) $application['parish_name']) : '' ?>
      </p>
    </div>
    <?php require __DIR__ . '/../partials/status-badge.php'; ?>
  </div>
  <?php require __DIR__ . '/../partials/tracker.php'; ?>
  <?php if ($ageHours !== null && $ageHours > $slaHours && in_array($status, ['submitted', 'under_review'], true)): ?>
    <div class="notice" style="margin-top:12px">
      <strong>Past the service window</strong>
      This application has been waiting <?= e((string) round($ageHours)) ?> hours; the target is <?= (int) $slaHours ?> hours.
      The review team has been flagged.
    </div>
  <?php endif; ?>
  <?php if (trim((string) ($application['decision_note'] ?? '')) !== ''): ?>
    <div class="notice" style="margin-top:12px">
      <strong>Reviewer’s note</strong><?= e((string) $application['decision_note']) ?>
    </div>
  <?php endif; ?>
</section>

<div class="grid grid-2">
  <section class="card">
    <h2>Details</h2>
    <ul class="list-plain">
      <li class="row row-end"><span class="muted">Amount requested</span><strong class="money"><?= e(Money::fromDecimal($application['requested_amount'])->format()) ?></strong></li>
      <?php if (Money::fromDecimal($application['approved_amount'])->cents() > 0): ?>
        <li class="row row-end"><span class="muted">Amount approved</span><strong class="money"><?= e(Money::fromDecimal($application['approved_amount'])->format()) ?></strong></li>
      <?php endif; ?>
      <?php if ((string) $application['program_type'] === 'education_bridge'): ?>
        <li class="row row-end"><span class="muted">Level</span><span><?= e(Program::educationLabel((string) $application['education_level'])) ?></span></li>
        <li class="row row-end"><span class="muted">School</span><span><?= e((string) $application['school_name']) ?></span></li>
        <?php if (!empty($application['school_class'])): ?>
          <li class="row row-end"><span class="muted">Class</span><span><?= e((string) $application['school_class']) ?></span></li>
        <?php endif; ?>
      <?php endif; ?>
      <li class="row row-end"><span class="muted">Submitted</span><span><?= e(human_date((string) ($application['submitted_at'] ?? $application['created_at']), true)) ?></span></li>
      <li class="row row-end"><span class="muted">Last update</span><span><?= e(relative_time((string) $application['updated_at'])) ?></span></li>
      <li class="row row-end"><span class="muted">Cycle</span><span><?= (int) $application['cycle_year'] ?></span></li>
    </ul>
  </section>

  <section class="card">
    <h2>Your answers</h2>
    <?php if ($answers === []): ?>
      <p class="faint">No extra questions apply to this program.</p>
    <?php else: ?>
      <ul class="list-plain">
        <?php foreach (Program::questions((string) $application['program_type']) as $key => $question): ?>
          <li>
            <span class="faint"><?= e($question['label']) ?></span><br>
            <?= e($answers[$key] ?? '—') ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>

<section class="card">
  <h2>Documents</h2>
  <?php if ($documents === []): ?>
    <p class="faint">No documents attached yet.</p>
  <?php else: ?>
    <ul class="list-plain">
      <?php foreach ($documents as $document): ?>
        <li class="row row-end">
          <span>
            <strong><?= e((string) ($document['name'] ?? 'Document')) ?></strong><br>
            <span class="faint"><?= e((string) ($document['type'] ?? 'supporting document')) ?> ·
              <?= e(number_format(((int) ($document['size'] ?? 0)) / 1024, 0)) ?> KB</span>
          </span>
          <a class="btn btn-secondary btn-sm"
             href="<?= e(url('', ['action' => 'document-download', 'id' => (int) $application['id'], 'file' => (string) ($document['stored'] ?? '')])) ?>">Open</a>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <?php if ($isOwner && in_array($status, ['draft', 'submitted', 'under_review', 'documents_requested'], true)): ?>
    <form method="post" action="<?= e(url('')) ?>" enctype="multipart/form-data" class="stack" style="margin-top:14px">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="application-document">
      <input type="hidden" name="application_id" value="<?= (int) $application['id'] ?>">
      <input type="hidden" name="return_to" value="application">
      <div class="field">
        <label for="document_type">What is this document?</label>
        <select id="document_type" name="document_type">
          <option value="school fee invoice">School fee invoice</option>
          <option value="result or transcript">Result or transcript</option>
          <option value="bank statement">Bank statement</option>
          <option value="business plan">Business plan</option>
          <option value="other supporting document">Other supporting document</option>
        </select>
      </div>
      <div class="field">
        <label for="document">Choose a file</label>
        <input type="file" id="document" name="document" accept="application/pdf,image/jpeg,image/png,image/webp" required>
        <span class="hint">PDF, JPG, PNG or WEBP · up to 4 MB.</span>
      </div>
      <button class="btn btn-secondary" type="submit">Upload document</button>
    </form>
  <?php endif; ?>
</section>

<?php if ((string) $application['program_type'] === 'academy'): ?>
  <section class="card">
    <h2>RISE Academy attendance</h2>
    <?php if (($academy ?? null) !== null): ?>
      <p class="row row-end" style="margin-bottom:8px">
        <span class="muted"><?= (int) $academy['sessions_attended'] ?> of <?= (int) $academy['sessions_held'] ?> sessions</span>
        <span class="badge <?= $academy['graduated'] ? 'badge-ok' : 'badge-warn' ?>">
          <?= (int) $academy['percent'] ?>%<?= $academy['graduated'] ? ' · graduated' : '' ?>
        </span>
      </p>
      <?php if (!empty($academy['mentor_note'])): ?>
        <div class="notice"><strong>Mentor note</strong><?= e((string) $academy['mentor_note']) ?></div>
      <?php endif; ?>
    <?php else: ?>
      <p class="faint">No attendance recorded yet. The graduation gate for Seed Grants is
        <?= (int) setting('academy_graduation_percent', 80) ?>% of sessions attended.</p>
    <?php endif; ?>

    <?php if ($canRecord): ?>
      <form method="post" action="<?= e(url('')) ?>" style="margin-top:12px">
        <?= Security::csrfField() ?>
        <input type="hidden" name="action" value="academy-save">
        <input type="hidden" name="application_id" value="<?= (int) $application['id'] ?>">
        <div class="grid grid-2">
          <div class="field">
            <label for="sessions_held">Sessions held</label>
            <input type="number" id="sessions_held" name="sessions_held" min="0" max="60" required
                   value="<?= (int) ($academy['sessions_held'] ?? 0) ?>">
          </div>
          <div class="field">
            <label for="sessions_attended">Sessions attended</label>
            <input type="number" id="sessions_attended" name="sessions_attended" min="0" max="60" required
                   value="<?= (int) ($academy['sessions_attended'] ?? 0) ?>">
          </div>
        </div>
        <div class="field">
          <label for="mentor_note">Mentor note <span class="faint">(visible to the applicant)</span></label>
          <textarea id="mentor_note" name="mentor_note" maxlength="500"><?= e((string) ($academy['mentor_note'] ?? '')) ?></textarea>
        </div>
        <button class="btn btn-secondary" type="submit">Save attendance</button>
      </form>
    <?php endif; ?>
  </section>
<?php endif; ?>

<?php if ($isOwner && Program::memberCanWithdraw($status)): ?>
  <section class="card">
    <h2>Withdraw</h2>
    <p class="muted">Withdrawing closes this application. You may apply again in a later cycle.</p>
    <form method="post" action="<?= e(url('')) ?>" onsubmit="return confirm('Withdraw this application?')">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="application-withdraw">
      <input type="hidden" name="application_id" value="<?= (int) $application['id'] ?>">
      <button class="btn btn-danger" type="submit">Withdraw application</button>
    </form>
  </section>
<?php endif; ?>

<?php if (!empty($canReview) && Auth::isReviewer($account)): ?>
  <section class="card">
    <h2>Review decision</h2>
    <p class="muted">Only legal next steps are offered. Every change is written to the timeline and mailed to the applicant.</p>
    <form method="post" action="<?= e(url('')) ?>">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="application-transition">
      <input type="hidden" name="application_id" value="<?= (int) $application['id'] ?>">
      <input type="hidden" name="return_to" value="application">
      <div class="field">
        <label for="to_status">Move to</label>
        <select id="to_status" name="to_status" required>
          <option value="">Choose the next step</option>
          <?php foreach (Program::allowedFrom($status) as $next): ?>
            <?php if (!Program::reviewerCanSet($next)) { continue; } ?>
            <option value="<?= e($next) ?>"><?= e(Program::statusLabel($next)) ?><?= Program::requiresReason($next) ? ' (reason required)' : '' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="note">Note to the applicant</label>
        <textarea id="note" name="note" maxlength="500" placeholder="Explain the decision in one or two sentences."></textarea>
        <span class="hint">Required when rejecting or requesting documents.</span>
      </div>
      <button class="btn btn-primary" type="submit">Save decision</button>
    </form>
  </section>
<?php endif; ?>

<section class="card">
  <h2>History</h2>
  <ul class="timeline">
    <?php foreach (array_reverse($events) as $event): ?>
      <li>
        <strong><?= e(Program::statusLabel((string) $event['to_status'])) ?></strong>
        <?php if (!empty($event['note'])): ?><br><span class="muted"><?= e((string) $event['note']) ?></span><?php endif; ?>
        <time datetime="<?= e((string) $event['created_at']) ?>">
          <?= e(human_date((string) $event['created_at'], true)) ?>
          <?= !empty($event['actor_name']) ? ' · ' . e((string) $event['actor_name']) : ' · system' ?>
        </time>
      </li>
    <?php endforeach; ?>
  </ul>
</section>

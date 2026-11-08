<?php
declare(strict_types=1);

use RISE63\Core\Program;
use RISE63\Core\Security;

use function RISE63\{e, url};

$type   = (string) $programType;
$levels = Program::educationLevels($type);
$isEducation = $type === 'education_bridge';
?>
<section class="card">
  <a class="faint" href="<?= e(url('programs')) ?>">← All programs</a>
  <h1 style="margin-top:10px"><?= e(Program::label($type)) ?></h1>
  <p class="muted"><?= e(Program::summary($type)) ?></p>
</section>

<form method="post" action="<?= e(url('')) ?>" id="apply-form" data-autosave data-offline-queue enctype="multipart/form-data" novalidate>
  <?= Security::csrfField() ?>
  <input type="hidden" name="action" value="application-submit">
  <input type="hidden" name="program_type" value="<?= e($type) ?>">
  <input type="hidden" name="idempotency_key" value="">
  <input type="hidden" name="return_to" value="programs">

  <section class="card">
    <h2>Your details</h2>
    <?php if ($isEducation): ?>
      <div class="field">
        <label for="education_level">Level of study</label>
        <select id="education_level" name="education_level" required>
          <option value="">Choose one</option>
          <?php foreach ($levels as $level): ?>
            <option value="<?= e($level) ?>"><?= e(Program::educationLabel($level)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="school_name">School or institution</label>
        <input type="text" id="school_name" name="school_name" required maxlength="200" autocomplete="organization">
      </div>
      <div class="field">
        <label for="school_class">Class <span class="faint">(Super Teens only)</span></label>
        <select id="school_class" name="school_class">
          <option value="">Not applicable</option>
          <option value="SS1">SS1</option>
          <option value="SS2">SS2</option>
          <option value="SS3">SS3</option>
        </select>
      </div>
    <?php elseif ($type !== 'cooperative'): ?>
      <input type="hidden" name="education_level" value="none">
    <?php else: ?>
      <input type="hidden" name="education_level" value="none">
    <?php endif; ?>

    <div class="field">
      <label for="requested_amount">Amount requested (₦)</label>
      <input type="number" id="requested_amount" name="requested_amount" min="0" step="100"
             value="<?= $type === 'academy' ? '0' : '' ?>"
             inputmode="decimal" <?= $type === 'academy' ? 'readonly' : 'required' ?>>
      <span class="hint">
        <?php if ($type === 'academy'): ?>
          RISE Academy is free — enter 0.
        <?php elseif ($type === 'seed_grant'): ?>
          Maximum <?= e($maxGrant->format()) ?>. Requests are ranked against the monthly pool.
        <?php else: ?>
          Enter the amount you need; the review team may adjust it.
        <?php endif; ?>
      </span>
    </div>
  </section>

  <?php if ($questions !== []): ?>
    <section class="card">
      <h2>A few questions</h2>
      <?php foreach ($questions as $key => $question): ?>
        <div class="field">
          <label for="q-<?= e($key) ?>"><?= e($question['label']) ?><?= !empty($question['required']) ? ' *' : '' ?></label>
          <?php if ($question['type'] === 'textarea'): ?>
            <textarea id="q-<?= e($key) ?>" name="answers[<?= e($key) ?>]" maxlength="<?= (int) $question['max'] ?>"
                      <?= !empty($question['required']) ? 'required' : '' ?>></textarea>
          <?php elseif ($question['type'] === 'yesno'): ?>
            <select id="q-<?= e($key) ?>" name="answers[<?= e($key) ?>]" required>
              <option value="">Choose one</option>
              <option value="yes">Yes</option>
              <option value="no">No</option>
            </select>
          <?php elseif ($question['type'] === 'money'): ?>
            <input type="number" id="q-<?= e($key) ?>" name="answers[<?= e($key) ?>]" min="0" step="50" inputmode="decimal" required>
          <?php else: ?>
            <input type="text" id="q-<?= e($key) ?>" name="answers[<?= e($key) ?>]" maxlength="<?= (int) $question['max'] ?>"
                   <?= !empty($question['required']) ? 'required' : '' ?>>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>

  <section class="card">
    <h2>Supporting document</h2>
    <p class="muted">Optional at this stage. PDF, JPG, PNG or WEBP up to 4 MB. You can also upload later if documents are requested.</p>
    <div class="field">
      <label for="document">Attach a document</label>
      <input type="file" id="document" name="document" accept="application/pdf,image/jpeg,image/png,image/webp">
    </div>
    <div class="check">
      <input type="checkbox" id="truthful" required>
      <label for="truthful" style="font-weight:400">I confirm that the information I have given is true and that I will upload genuine documents.</label>
    </div>
  </section>

  <div class="form-actions">
    <button class="btn btn-primary btn-block" type="submit">Submit application</button>
    <a class="btn btn-secondary btn-block" href="<?= e(url('programs')) ?>">Cancel</a>
  </div>
  <p class="faint center" data-draft-status aria-live="polite" style="margin-top:10px"></p>
</form>

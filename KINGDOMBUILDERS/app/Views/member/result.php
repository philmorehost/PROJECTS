<?php
use function KB\{e,url};
$settings = json_decode($attempt['settings_snapshot'], true);
$percentage = (float) $attempt['percentage'];
$passMark = (int) $settings['pass_mark'];
$passed = $percentage >= $passMark;
$answered = 0;
foreach ($items as $item) if ($item['answer'] !== null) $answered++;
?>
<section class="result-hero <?= $passed ? 'is-pass' : '' ?>">
  <div class="result-hero-copy">
    <span class="eyebrow"><?= $passed ? 'WELL DONE' : 'EVERY STEP COUNTS' ?></span>
    <h1><?= $passed ? 'A strong finish.' : 'Keep growing.' ?></h1>
    <p>Your answers were saved and scored on the server. Nothing can change them now.</p>
    <div class="result-actions">
      <a class="button" href="<?= e(url('dashboard')) ?>">Back to dashboard →</a>
    </div>
  </div>
  <div class="result-score">
    <div class="score-ring <?= $passed ? 'is-pass' : '' ?>" role="img" aria-label="<?= e($percentage) ?>% — <?= (int)$attempt['score'] ?> of <?= (int)$attempt['total_questions'] ?> correct">
      <svg viewBox="0 0 120 120" aria-hidden="true">
        <circle cx="60" cy="60" r="52" class="score-ring-track"/>
        <circle cx="60" cy="60" r="52" class="score-ring-value" pathLength="100" stroke-dasharray="<?= max(0, min(100, $percentage)) ?> 100"/>
      </svg>
      <div class="score-ring-text"><strong><?= round($percentage) ?>%</strong><span><?= (int)$attempt['score'] ?> / <?= (int)$attempt['total_questions'] ?> correct</span></div>
    </div>
    <div class="result-meta">
      <span class="badge <?= $passed ? 'green' : 'neutral' ?>"><?= $passed ? 'Passed' : 'Below pass mark' ?></span>
      <span><?= $answered ?> of <?= count($items) ?> answered</span>
      <span>Pass mark <?= $passMark ?>%</span>
    </div>
  </div>
</section>

<div class="review-summary">
  <div><strong><?= (int)$attempt['score'] ?></strong><span>Correct</span></div>
  <div><strong><?= count($items) - $answered ?></strong><span>Unanswered</span></div>
  <div><strong><?= count($items) - (int)$attempt['score'] ?></strong><span>To review</span></div>
  <div><strong><?= e($attempt['submitted_at']) ?></strong><span>Submitted (UTC)</span></div>
</div>

<section class="review-stack">
  <div class="section-heading"><div><span class="eyebrow">YOUR ANSWERS</span><h2>Review and reflect</h2></div><span class="badge neutral">Answers are final</span></div>
  <?php foreach ($items as $i => $item): $chosen = $item['answer'] === null ? null : (int)$item['answer']; $correct = (int)$item['correct_option']; $right = $chosen === $correct; ?>
    <article class="panel review-card <?= $right ? 'is-correct' : 'is-review' ?>">
      <header class="review-head">
        <span class="review-number"><?= $i + 1 ?></span>
        <span class="badge <?= $right ? 'green' : ($chosen === null ? 'neutral' : 'red') ?>"><?= $right ? 'Correct' : ($chosen === null ? 'Not answered' : 'Review this one') ?></span>
      </header>
      <h2><?= e($item['question_text']) ?></h2>
      <div class="review-options">
        <?php foreach (json_decode($item['options_snapshot'], true) as $j => $option): ?>
          <div class="<?= $j === $correct ? 'correct' : ($j === $chosen ? 'incorrect' : '') ?>">
            <span><?= chr(65 + $j) ?></span>
            <em><?= e($option) ?></em>
            <?php if ($j === $correct): ?><small>Correct answer</small><?php elseif ($j === $chosen): ?><small>Your answer</small><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if ($item['reference'] || $item['explanation']): ?>
        <footer class="review-note">
          <?php if ($item['reference']): ?><strong><?= e($item['reference']) ?></strong><?php endif; ?>
          <?php if ($item['explanation']): ?><span><?= e($item['explanation']) ?></span><?php endif; ?>
        </footer>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>
</section>

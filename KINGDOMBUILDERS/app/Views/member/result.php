<?php
use function KB\{e,url};
$review = $review ?? false;
$summary = $summary ?? ['answered' => 0, 'skipped' => 0];
$settings = json_decode($attempt['settings_snapshot'], true);
$percentage = (float) $attempt['percentage'];
$passMark = (int) $settings['pass_mark'];
$passed = $percentage >= $passMark;
$total = (int) $attempt['total_questions'];
$score = (int) $attempt['score'];
$answered = $review
    ? count(array_filter($items, static fn(array $item): bool => $item['answer'] !== null))
    : (int) ($summary['answered'] ?? 0);
$skipped = $review
    ? count(array_filter($items, static fn(array $item): bool => $item['answer'] === null))
    : (int) ($summary['skipped'] ?? max(0, $total - $answered));
$missed = max(0, $total - $score);
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
    <div class="score-ring <?= $passed ? 'is-pass' : '' ?>" role="img" aria-label="<?= e($percentage) ?>% — <?= $score ?> of <?= $total ?> correct">
      <svg viewBox="0 0 120 120" aria-hidden="true">
        <circle cx="60" cy="60" r="52" class="score-ring-track"/>
        <circle cx="60" cy="60" r="52" class="score-ring-value" pathLength="100" stroke-dasharray="<?= max(0, min(100, $percentage)) ?> 100"/>
      </svg>
      <div class="score-ring-text"><strong><?= round($percentage) ?>%</strong><span><?= $score ?> / <?= $total ?> correct</span></div>
    </div>
    <div class="result-meta">
      <span class="badge <?= $passed ? 'green' : 'neutral' ?>"><?= $passed ? 'Passed' : 'Below pass mark' ?></span>
      <span><?= $answered ?> of <?= $total ?> answered</span>
      <span>Pass mark <?= $passMark ?>%</span>
    </div>
  </div>
</section>

<div class="review-summary">
  <div><strong><?= $score ?></strong><span>Correct</span></div>
  <div><strong><?= $skipped ?></strong><span>Unanswered</span></div>
  <div><strong><?= $missed ?></strong><span>Incorrect</span></div>
  <div><strong><?= e($attempt['submitted_at']) ?></strong><span>Submitted (UTC)</span></div>
</div>

<?php if (!$review): ?>
  <section class="panel scorecard-panel">
    <div class="section-heading">
      <div><span class="eyebrow">YOUR SCORE</span><h2>That is the complete result.</h2></div>
      <span class="badge neutral">Questions are not shown</span>
    </div>
    <p class="scorecard-lead">Your assessment has been scored and saved. This community shows the score for a completed quiz rather than the questions, so the question bank stays fair for everyone taking it after you.</p>
    <ul class="scorecard-list">
      <li><strong><?= $score ?> of <?= $total ?></strong><span>Correct answers</span></li>
      <li><strong><?= $percentage ?>%</strong><span>Final percentage</span></li>
      <li><strong><?= $passMark ?>%</strong><span>Pass mark for this assessment</span></li>
      <li><strong><?= $missed === 0 ? 'None' : $missed ?></strong><span>Incorrect<?= $missed === 1 ? ' answer' : ' answers' ?></span></li>
    </ul>
    <p class="muted scorecard-note">If you think a question was unfair or you need to go through your answers with an administrator, please get in touch — they can look at your paper with you.</p>
    <div class="result-actions">
      <a class="button secondary" href="<?= e(url('dashboard')) ?>">Return to your dashboard</a>
    </div>
  </section>
<?php else: ?>
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
<?php endif; ?>

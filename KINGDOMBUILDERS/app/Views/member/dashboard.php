<?php use function KB\{e,url}; use KB\Security; use KB\Settings; ?>
<section class="page-heading dashboard-heading">
  <div><span class="eyebrow">YOUR NEXT CHAPTER</span><h1>Keep growing, <?= e(explode(' ',$user['name'])[0]) ?>.</h1><p>A little time in the Word can make a lasting difference.</p></div>
  <a class="button secondary" href="<?= e(url('account')) ?>">Account settings</a>
</section>

<div class="member-stats stats-grid">
  <div class="stat-card"><span>Available questions <b aria-hidden="true">📖</b></span><strong><?= number_format($count) ?></strong><small>Ready for your next quiz</small></div>
  <div class="stat-card"><span>Attempts completed <b aria-hidden="true">✓</b></span><strong><?= number_format($memberStats['completed']) ?></strong><small>Steps forward in the Word</small></div>
  <div class="stat-card"><span>Best score <b aria-hidden="true">★</b></span><strong><?= $memberStats['best'] !== null ? e($memberStats['best']).'%' : '—' ?></strong><small>Your highest percentage</small></div>
  <div class="stat-card"><span>Average score <b aria-hidden="true">📈</b></span><strong><?= $memberStats['average'] !== null ? e($memberStats['average']).'%' : '—' ?></strong><small>Across completed attempts</small></div>
</div>

<div class="dashboard-grid member-dashboard-grid">
  <section class="quiz-start-card modern-quiz-card">
    <span class="eyebrow">THE BIBLE QUIZ</span>
    <h2>What will you<br>discover today?</h2>
    <p>Explore the Scriptures through thoughtful, multiple-choice questions. Take your place in the story.</p>
    <div class="quiz-facts">
      <span><strong><?= min($count,(int)Settings::get('questions_per_attempt')) ?></strong>questions</span>
      <span><strong><?= Settings::get('timer_enabled')?(int)Settings::get('seconds_per_question').'s':'∞' ?></strong>per question, total budget</span>
      <span><strong><?= (int)Settings::get('pass_mark') ?>%</strong>pass mark</span>
    </div>
    <?php if($active): ?>
      <a class="button light" href="<?= e(url('quiz',['id'=>$active['id']])) ?>">Resume your quiz →</a>
    <?php elseif(Settings::get('quiz_open')): ?>
      <form method="post" action="<?= e(url('quiz/start')) ?>"><?= Security::csrfField() ?><button class="button light">Begin your quiz <span aria-hidden="true">↗</span></button></form>
    <?php else: ?>
      <span class="badge neutral">Assessments are currently closed</span>
    <?php endif; ?>
    <small>By starting, you agree to complete the quiz honestly. Focus changes may be recorded.</small>
  </section>
  <section class="panel member-note modern-member-note">
    <span class="feature-number">A MOMENT TO REMEMBER</span>
    <div class="large-quote">“</div>
    <h2>Let the Word<br>take root.</h2>
    <p>Learning isn’t only about the score. It’s about the truth you carry with you when the quiz is over.</p>
    <span class="eyebrow">ONE QUESTION. ONE STEP. ONE DAY AT A TIME.</span>
  </section>
</div>

<section class="panel">
  <div class="section-heading"><div><span class="eyebrow">YOUR JOURNEY SO FAR</span><h2>Recent results</h2></div><a class="text-link" href="<?= e(url('account')) ?>">Account settings →</a></div>
  <?php if(!$history): ?>
    <div class="empty"><h3>Your story starts with a single step.</h3><p>Complete your first quiz and your results will appear here.</p></div>
  <?php else: ?>
    <div class="table-wrap"><table><thead><tr><th>Completed</th><th>Score</th><th>Progress</th><th></th></tr></thead><tbody>
    <?php foreach($history as $row): $passMark=(int)(json_decode($row['settings_snapshot'],true)['pass_mark']??50); $passed=(float)$row['percentage']>=$passMark; ?>
      <tr><td><?= e($row['submitted_at']) ?> UTC<small><?= e($row['status']) ?></small></td><td><strong><?= (int)$row['score'] ?> / <?= (int)$row['total_questions'] ?></strong><small><?= e($row['percentage']) ?>%</small></td><td><span class="badge <?= $passed?'green':'neutral' ?>"><?= $passed?'Passed':'Keep growing' ?></span></td><td><a class="text-link" href="<?= e(url('result',['id'=>$row['id']])) ?>">Review answers →</a></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
</section>

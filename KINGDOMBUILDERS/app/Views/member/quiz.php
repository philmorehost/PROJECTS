<?php use function KB\{e,url}; ?>
<div id="quiz-app"
     data-id="<?= e($attempt['id']) ?>"
     data-endpoint="<?= e(url('api/quiz',['id'=>$attempt['id']])) ?>"
     data-result="<?= e(url('result',['id'=>$attempt['id']])) ?>"
     data-dashboard="<?= e(url('dashboard')) ?>">
  <section class="quiz-loading">
    <span class="eyebrow">A MOMENT TO FOCUS</span>
    <h1>Your quiz is ready.</h1>
    <p>Loading your saved progress securely…</p>
    <noscript><p class="notice info">JavaScript is needed for the interactive quiz. Your results are calculated securely on the server. Enable JavaScript and reload this page.</p></noscript>
  </section>
</div>

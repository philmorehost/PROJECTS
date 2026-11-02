<?php use function KB\{e,url}; use KB\Settings; ?>
<section class="home-hero modern-hero">
  <div class="hero-glow" aria-hidden="true"></div>
  <div class="hero-copy">
    <span class="eyebrow hero-badge"><span class="status-dot"></span> A PLACE TO LEARN. A REASON TO GROW.</span>
    <h1>Build your knowledge.<br><em>Deepen your faith.</em></h1>
    <p class="hero-lead"><?= e(Settings::get('site_tagline')) ?></p>
    <div class="hero-actions">
      <a class="button button-lg" href="<?= e(url('register')) ?>">Start your journey <span aria-hidden="true">↗</span></a>
      <a class="button button-lg secondary" href="<?= e(url('login')) ?>">I’m already a member</a>
    </div>
    <ul class="hero-trust" aria-label="What you can expect">
      <li>✓ Scripture-centred questions</li>
      <li>✓ Instant, saved progress</li>
      <li>✓ Learn at your own pace</li>
    </ul>
  </div>
  <div class="hero-visual" aria-label="A preview of the Bible quiz experience">
    <div class="orbit orbit-one" aria-hidden="true"></div>
    <div class="orbit orbit-two" aria-hidden="true"></div>
    <span class="art-star star-one" aria-hidden="true">✦</span>
    <span class="art-star star-two" aria-hidden="true">✧</span>
    <div class="quiz-preview-card">
      <div class="quiz-preview-top"><span class="badge green">BIBLE QUIZ</span><span class="quiz-preview-timer">00:30</span></div>
      <div class="progress-track"><div class="progress-fill preview-progress"></div></div>
      <p class="quiz-preview-question">“Thy word is a lamp unto my feet, and a light unto my path.”</p>
      <span class="quiz-preview-reference">PSALM 119:105</span>
      <div class="quiz-preview-options" aria-hidden="true">
        <span class="preview-option picked"><b>A</b> A lamp and a light</span>
        <span class="preview-option"><b>B</b> A sword and a shield</span>
        <span class="preview-option"><b>C</b> A rock and a fortress</span>
      </div>
      <div class="quiz-preview-footer"><span>Question 1 of 15</span><span class="preview-next">Next →</span></div>
    </div>
    <div class="verse-card floating-verse"><span class="verse-icon">✧</span><div><small>GROW A LITTLE EVERY DAY</small><p>“Thy word is a lamp unto my feet,<br>and a light unto my path.”</p><span>PSALM 119:105</span></div></div>
  </div>
</section>

<section class="home-stats" aria-label="Community progress">
  <div><strong><?= number_format($homeStats['questions']) ?></strong><span>Active questions</span></div>
  <div><strong><?= number_format($homeStats['members']) ?></strong><span>Members learning</span></div>
  <div><strong><?= number_format($homeStats['completed']) ?></strong><span>Quizzes completed</span></div>
  <div><strong><?= (int)Settings::get('pass_mark') ?>%</strong><span>Pass mark</span></div>
</section>

<section class="home-section" id="how-it-works">
  <div class="section-intro"><span class="eyebrow">HOW IT WORKS</span><h2>A simple path to deeper understanding</h2><p>Whether you are opening the Scriptures for the first time or revisiting familiar passages, each quiz helps you notice, remember and reflect.</p></div>
  <div class="steps-grid">
    <article class="step-card"><span class="step-number">01</span><h3>Create your account</h3><p>Join the community, confirm your email and set up your learning profile in a few minutes.</p></article>
    <article class="step-card"><span class="step-number">02</span><h3>Take a Bible quiz</h3><p>Answer thoughtful multiple-choice questions. Your progress is saved securely as you go.</p></article>
    <article class="step-card"><span class="step-number">03</span><h3>Review and grow</h3><p>See your score, revisit the Scriptures and carry what you learn into everyday life.</p></article>
  </div>
</section>

<section class="home-section features-modern" aria-label="Learning together">
  <div class="section-intro"><span class="eyebrow">WHY LEARN WITH US</span><h2>Knowledge that matters. Progress you can see.</h2></div>
  <div class="features-grid">
    <article class="feature-card"><span class="feature-icon" aria-hidden="true">📖</span><h3>Rooted in Scripture</h3><p>Questions invite you to look closer at the Bible, with references to guide your study.</p></article>
    <article class="feature-card"><span class="feature-icon" aria-hidden="true">⏱️</span><h3>Your pace, your path</h3><p>Timed or untimed, one question at a time. Pause and return without losing your place.</p></article>
    <article class="feature-card"><span class="feature-icon" aria-hidden="true">📈</span><h3>Meaningful results</h3><p>Instant scoring, answer review and a history of your attempts help you keep growing.</p></article>
    <article class="feature-card"><span class="feature-icon" aria-hidden="true">🤝</span><h3>A caring community</h3><p>Administrators support members, manage access and keep the learning space healthy.</p></article>
  </div>
</section>

<section class="home-section verse-banner">
  <div class="verse-banner-inner"><span class="eyebrow">A MOMENT TO REMEMBER</span><h2>“Let the Word take root.”</h2><p>Learning isn’t only about the score. It’s about the truth you carry with you when the quiz is over.</p><a class="button light" href="<?= e(url('register')) ?>">Begin your first quiz <span aria-hidden="true">↗</span></a></div>
</section>

<section class="community-banner modern-cta"><div><span class="eyebrow">EVERY JOURNEY STARTS WITH A STEP</span><h2>There’s a place for you here.</h2><p>Create your free account and start building a deeper understanding of God’s Word today.</p></div><a class="button light button-lg" href="<?= e(url('register')) ?>">Create your account <span aria-hidden="true">↗</span></a></section>

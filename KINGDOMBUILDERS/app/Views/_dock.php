<?php
/**
 * Mobile quick navigation ("dock").
 * Rendered only for signed-in people and only on small screens (CSS controls
 * visibility). Members get Home / Quiz / Profile; administrators get
 * Overview / Members / Profile because they cannot take assessments.
 */
use function KB\{e, url};
use KB\Security;

$dockAttempt = $dockAttempt ?? null;
$dockQuizOpen = $dockQuizOpen ?? false;
$dockVerified = $dockVerified ?? true;
$dockHome = $isAdmin ? 'admin' : 'dashboard';
$dockHomeActive = $isAdmin ? $current === 'admin' : in_array($current, ['', 'dashboard'], true);
$dockQuizActive = str_starts_with($current, 'quiz');
$dockMembersActive = str_starts_with($current, 'admin/users');
$dockProfileActive = $current === 'account';
?>
<nav class="mobile-dock" aria-label="Quick navigation">
  <div class="mobile-dock-inner">
    <a class="dock-item<?= $dockHomeActive ? ' is-active' : '' ?>" href="<?= e(url($isAdmin ? 'admin' : 'dashboard')) ?>"<?= $dockHomeActive ? ' aria-current="page"' : '' ?>>
      <span class="dock-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3.5 10.5 12 3.5l8.5 7"/><path d="M5.5 9.5V20h13V9.5"/><path d="M9.75 20v-5.25h4.5V20"/></svg>
      </span>
      <span class="dock-label"><?= $isAdmin ? 'Overview' : 'Home' ?></span>
    </a>

    <?php if ($isAdmin): ?>
      <a class="dock-item<?= $dockMembersActive ? ' is-active' : '' ?>" href="<?= e(url('admin/users')) ?>"<?= $dockMembersActive ? ' aria-current="page"' : '' ?>>
        <span class="dock-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.25"/><path d="M3.5 20c0-3.1 2.5-5.25 5.5-5.25s5.5 2.15 5.5 5.25"/><path d="M16 5.5a3 3 0 0 1 0 5.6"/><path d="M17.5 14.2c1.8.6 3 2.2 3 4.3"/></svg>
        </span>
        <span class="dock-label">Members</span>
      </a>
    <?php else: ?>
      <?php if ($dockAttempt !== null): ?>
        <a class="dock-item dock-item-quiz is-accent<?= $dockQuizActive ? ' is-active' : '' ?>" href="<?= e(url('quiz', ['id' => $dockAttempt])) ?>"<?= $dockQuizActive ? ' aria-current="page"' : '' ?>>
          <span class="dock-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H12v16.5H6.5A2.5 2.5 0 0 0 4 22z"/><path d="M20 5.5A2.5 2.5 0 0 0 17.5 3H12v16.5h5.5A2.5 2.5 0 0 1 20 22z"/></svg>
            <span class="dock-ping" aria-hidden="true"></span>
          </span>
          <span class="dock-label">Resume quiz</span>
        </a>
      <?php elseif (!$dockVerified): ?>
        <a class="dock-item dock-item-quiz is-accent" href="<?= e(url('dashboard')) ?>">
          <span class="dock-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3.25" y="5.25" width="17.5" height="13.5" rx="2.5"/><path d="m3.8 6.6 8.2 6.1 8.2-6.1"/></svg>
          </span>
          <span class="dock-label">Verify email</span>
        </a>
      <?php elseif ($dockQuizOpen): ?>
        <form class="dock-form" method="post" action="<?= e(url('quiz/start')) ?>">
          <?= Security::csrfField() ?>
          <button type="submit" class="dock-item dock-item-quiz is-accent<?= $dockQuizActive ? ' is-active' : '' ?>">
            <span class="dock-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H12v16.5H6.5A2.5 2.5 0 0 0 4 22z"/><path d="M20 5.5A2.5 2.5 0 0 0 17.5 3H12v16.5h5.5A2.5 2.5 0 0 1 20 22z"/></svg>
            </span>
            <span class="dock-label">Start quiz</span>
          </button>
        </form>
      <?php else: ?>
        <span class="dock-item dock-item-quiz is-disabled" aria-disabled="true">
          <span class="dock-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H12v16.5H6.5A2.5 2.5 0 0 0 4 22z"/><path d="M20 5.5A2.5 2.5 0 0 0 17.5 3H12v16.5h5.5A2.5 2.5 0 0 1 20 22z"/></svg>
          </span>
          <span class="dock-label">Quiz closed</span>
        </span>
      <?php endif; ?>
    <?php endif; ?>

    <a class="dock-item<?= $dockProfileActive ? ' is-active' : '' ?>" href="<?= e(url('account')) ?>"<?= $dockProfileActive ? ' aria-current="page"' : '' ?>>
      <span class="dock-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8.25" r="3.75"/><path d="M4.5 20.5c0-3.6 3.35-6 7.5-6s7.5 2.4 7.5 6"/></svg>
      </span>
      <span class="dock-label">Profile</span>
    </a>
  </div>
</nav>

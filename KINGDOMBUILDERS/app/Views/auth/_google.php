<?php
use function KB\{e, url};
$mode = ($mode ?? 'login') === 'register' ? 'register' : 'login';
$label = $mode === 'register' ? 'Sign up with Google' : 'Continue with Google';
$state = $mode === 'register' ? 'Create your account in one click — no password to remember.' : 'One click with your Google account. No password needed.';
?>
<?php if (KB\Google::configured()): ?>
  <div class="oauth-area">
    <a class="button google-button full" href="<?= e(url('auth/google', ['from' => $mode])) ?>" rel="nofollow">
      <span class="google-mark" aria-hidden="true">
        <svg width="20" height="20" viewBox="0 0 48 48" focusable="false" aria-hidden="true">
          <path fill="#4285F4" d="M45.1 24.5c0-1.6-.1-3.1-.4-4.5H24v8.5h11.8c-.5 2.7-2 5-4.3 6.6v5.5h6.9c4-3.7 6.7-9.2 6.7-16.1z"/>
          <path fill="#34A853" d="M24 46c5.9 0 10.9-2 14.5-5.4l-6.9-5.5c-2 1.3-4.5 2.1-7.6 2.1-5.8 0-10.7-3.9-12.5-9.2H4.4v5.7C8 41.1 15.4 46 24 46z"/>
          <path fill="#FBBC05" d="M11.5 27.9c-.5-1.4-.8-2.9-.8-4.4s.3-3 .8-4.4v-5.7H4.4A22 22 0 0 0 2 23.5c0 3.6.9 6.9 2.4 9.9l7.1-5.5z"/>
          <path fill="#EA4335" d="M24 9.4c3.3 0 6.2 1.1 8.5 3.3l6.1-6.1C34.9 3.1 29.9 1 24 1 15.4 1 8 5.9 4.4 13.4l7.1 5.7c1.8-5.3 6.7-9.7 12.5-9.7z"/>
        </svg>
      </span>
      <span><?= e($label) ?></span>
    </a>
    <p class="oauth-note"><?= e($state) ?></p>
  </div>
  <div class="auth-divider"><span>or use your email</span></div>
<?php endif; ?>

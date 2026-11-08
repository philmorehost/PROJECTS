<?php
declare(strict_types=1);

use RISE63\Core\Auth;
use RISE63\Core\Security;

use function RISE63\{asset, e, favicon_letter, site_title, title_hue, url};

/** @var string $content */
$account  = $account ?? null;
$isStaff  = $account !== null && (Auth::isAdmin($account) || Auth::isTreasurer($account) || (string) ($account['role'] ?? '') === 'csr_rep');
$theme    = (string) ($account['theme_preference'] ?? ($_COOKIE['rise63_theme'] ?? 'system'));
$large    = (int) ($account['large_text'] ?? 0) === 1;
$letter   = favicon_letter();
$hue      = title_hue(site_title());
$brand    = (string) (\RISE63\setting('theme_color', '#2563eb'));
$flashes  = $flashes ?? [];
$navItems = $account === null ? [] : ($isStaff
    ? [
        'dashboard'    => ['Dashboard', '▤'],
        'applications' => ['Applications', '▦'],
        'parishes'     => ['Parish Connect', '⌂'],
        'treasury'     => ['Treasury', '₦'],
    ]
    : [
        'my-rise'  => ['My RISE', '★'],
        'programs' => ['Programs', '◈'],
        'parishes' => ['Parish Connect', '⌂'],
    ]);
if ($account !== null && !Auth::isTreasurer($account)) {
    unset($navItems['treasury']);
}
if ($account !== null && (string) $account['role'] === 'youth_member') {
    $navItems['parishes'] = ['Parish Connect', '⌂'];
}
?>
<!doctype html>
<html lang="en" data-theme="<?= e($theme) ?>"<?= $large ? ' class="text-large"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($title ?? 'RISE 63') ?> · <?= e(site_title()) ?></title>
<meta name="description" content="<?= e(\RISE63\setting('site_tagline', 'RPCG Lagos Province 63 empowerment platform')) ?>">
<meta name="theme-color" content="<?= e($brand) ?>">
<meta name="csrf-token" content="<?= e(Security::csrf()) ?>">
<link rel="icon" type="image/svg+xml" href="<?= e(url('favicon.svg')) ?>?v=<?= e(substr(hash('sha256', $letter . site_title()), 0, 8)) ?>">
<link rel="apple-touch-icon" href="<?= e(url('public/icons/icon-192.png')) ?>">
<link rel="manifest" href="<?= e(url('manifest.webmanifest')) ?>">
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
<script>
  // Apply a stored client-side preference before first paint to avoid a theme flash.
  (function () {
    try {
      var stored = localStorage.getItem('rise63-theme');
      if (stored === 'light' || stored === 'dark') { document.documentElement.dataset.theme = stored; }
    } catch (err) { /* private mode */ }
  }());
</script>
</head>
<body class="<?= $account === null ? 'guest' : 'signed-in' ?><?= $large ? ' text-large' : '' ?>">
<a class="skip-link" href="#main">Skip to main content</a>

<div class="offline-banner" id="offline-banner" role="status" hidden>
  <span aria-hidden="true">◍</span>
  <p>You are offline. Changes will sync when you reconnect.</p>
</div>

<?php if ($account !== null): ?>
<header class="topbar">
  <div class="topbar-inner">
    <a class="brand" href="<?= e(url($isStaff ? 'dashboard' : 'my-rise')) ?>">
      <span class="brand-mark" style="--brand-hue: <?= e((string) $hue) ?>"><?= e($letter) ?></span>
      <span class="brand-text">
        <strong><?= e(site_title()) ?></strong>
        <small>LP63 YAYA · Young Professionals</small>
      </span>
    </a>
    <div class="topbar-actions">
      <button type="button" class="icon-btn" id="theme-toggle" aria-label="Switch appearance" title="Switch appearance">
        <span class="theme-icon" aria-hidden="true">◑</span>
      </button>
      <a class="icon-btn" href="<?= e(url('profile')) ?>" aria-label="My account" title="My account">
        <span aria-hidden="true"><?= e(\RISE63\initials((string) $account['full_name'])) ?></span>
      </a>
    </div>
  </div>
</header>
<?php endif; ?>

<main id="main" class="page<?= $account !== null ? ' has-bottom-nav' : '' ?>" tabindex="-1">
  <?php foreach ($flashes as $flash): ?>
    <div class="flash flash-<?= e($flash['type']) ?>" role="<?= $flash['type'] === 'error' ? 'alert' : 'status' ?>">
      <?= e($flash['message']) ?>
    </div>
  <?php endforeach; ?>
  <?php echo $content; ?>
</main>

<?php if ($account !== null): ?>
<nav class="bottom-nav" aria-label="Primary">
  <?php foreach ($navItems as $key => [$label, $icon]): ?>
    <a href="<?= e(url($key)) ?>" class="<?= $page === $key ? 'active' : '' ?>" <?= $page === $key ? 'aria-current="page"' : '' ?>>
      <span class="nav-icon" aria-hidden="true"><?= e($icon) ?></span>
      <span class="nav-label"><?= e($label) ?></span>
    </a>
  <?php endforeach; ?>
  <?php if ($isStaff && (Auth::isAdmin($account) || (string) $account['role'] === 'csr_rep')): ?>
    <a href="<?= e(url('security')) ?>" class="<?= $page === 'security' ? 'active' : '' ?>">
      <span class="nav-icon" aria-hidden="true">◈</span><span class="nav-label">Security</span>
    </a>
  <?php endif; ?>
  <?php if (Auth::isAdmin($account)): ?>
    <a href="<?= e(url('settings')) ?>" class="<?= $page === 'settings' ? 'active' : '' ?>">
      <span class="nav-icon" aria-hidden="true">⚙</span><span class="nav-label">Settings</span>
    </a>
  <?php endif; ?>
  <form method="post" action="<?= e(url('')) ?>" class="nav-logout">
    <?= Security::csrfField() ?>
    <input type="hidden" name="action" value="logout">
    <button type="submit"><span class="nav-icon" aria-hidden="true">⏻</span><span class="nav-label">Sign out</span></button>
  </form>
</nav>
<?php endif; ?>

<footer class="site-footer">
  <p>© <?= e(date('Y')) ?> <?= e(site_title()) ?> · RCCG Lagos Province 63</p>
  <p class="muted">Rise. Build. Give back.</p>
</footer>

<div class="install-prompt" id="install-prompt" hidden>
  <div class="install-body">
    <strong>Install RISE 63</strong>
    <p id="install-hint">Add the app to your home screen for instant access.</p>
  </div>
  <div class="install-actions">
    <button type="button" class="btn btn-primary btn-sm" id="install-accept">Install</button>
    <button type="button" class="btn btn-ghost btn-sm" id="install-dismiss" aria-label="Not now">Not now</button>
  </div>
</div>

<script src="<?= e(asset('app.js')) ?>" defer></script>
</body>
</html>

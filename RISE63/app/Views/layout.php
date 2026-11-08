<?php
declare(strict_types=1);

use RISE63\Core\Auth;
use RISE63\Core\Security;

use function RISE63\{asset, e, favicon_letter, icon, site_title, title_hue, url};

/** @var string $content */
$account  = $account ?? null;
$isStaff  = $account !== null && (Auth::isAdmin($account) || Auth::isTreasurer($account) || (string) ($account['role'] ?? '') === 'csr_rep');
$theme    = (string) ($account['theme_preference'] ?? ($_COOKIE['rise63_theme'] ?? 'system'));
$large    = (int) ($account['large_text'] ?? 0) === 1;
$letter   = favicon_letter();
$hue      = title_hue(site_title());
$brand    = (string) (\RISE63\setting('theme_color', '#2563eb'));
$flashes  = $flashes ?? [];
// [label, icon name] per tab. The icons are drawn SVG (see icon() in Functions.php) rather than
// text glyphs: a tab bar is judged by whether the marks line up, and ▤ ▦ ₦ ◈ ⚙ ⏻ do not.
// [label, icon, short label]. The short label is rendered as well and swapped in by CSS below
// 420px, where a 360px phone leaves ~46px per tab and “Parish Connect” would ellipsise to "Par…".
$navItems = $account === null ? [] : ($isStaff
    ? [
        'dashboard'    => ['Dashboard', 'dashboard', 'Home'],
        'applications' => ['Applications', 'applications', 'Apps'],
        'parishes'     => ['Parish Connect', 'parish', 'Parish'],
        'treasury'     => ['Treasury', 'treasury', 'Treasury'],
    ]
    : [
        'my-rise'  => ['My RISE', 'star', 'My RISE'],
        'programs' => ['Programs', 'programs', 'Programs'],
        'parishes' => ['Parish Connect', 'parish', 'Parish'],
    ]);
if ($account !== null && !Auth::isTreasurer($account)) {
    unset($navItems['treasury']);
}
if ($account !== null && (string) $account['role'] === 'youth_member') {
    $navItems['parishes'] = ['Parish Connect', 'parish', 'Parish'];
}

// The tab bar is assembled up front so the active-indicator transform can be indexed by position,
// and so the markup below has one shape for links and the sign-out button alike.
$tabs = [];
foreach ($navItems as $key => [$label, $iconName, $short]) {
    $tabs[] = ['href' => url($key), 'icon' => $iconName, 'label' => $label, 'short' => $short, 'active' => $page === $key];
}
if ($account !== null && $isStaff && (Auth::isAdmin($account) || (string) $account['role'] === 'csr_rep')) {
    $tabs[] = ['href' => url('security'), 'icon' => 'security', 'label' => 'Security', 'short' => 'Security', 'active' => $page === 'security'];
}
if ($account !== null && Auth::isAdmin($account)) {
    $tabs[] = ['href' => url('settings'), 'icon' => 'settings', 'label' => 'Settings', 'short' => 'Settings', 'active' => $page === 'settings'];
}
$activeIndex = 0;
foreach ($tabs as $index => $tab) {
    if ($tab['active']) {
        $activeIndex = $index;
        break;
    }
}
?>
<!doctype html>
<html lang="en" data-theme="<?= e($theme) ?>"<?= $large ? ' class="text-large"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($title ?? 'RISE 63') ?> · <?= e(site_title()) ?></title>
<meta name="description" content="<?= e(\RISE63\setting('site_tagline', 'RCCG Lagos Province 63 empowerment platform')) ?>">
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

<?php /* Shown only while the browser reports no connection, and removed the moment it returns —
         both are handled by app.js, which also re-tests the connection every few seconds so a
         stale "online" flag cannot leave the bar on screen. Hidden in the markup, so it never
         flashes on a healthy page before the script runs. */ ?>
<div class="offline-banner" id="offline-banner" role="status" aria-live="polite" hidden>
  <span class="offline-icon" aria-hidden="true"><?= icon('offline', 'icon') ?></span>
  <p class="offline-text">No internet connection. Anything you submit will be saved and sent when you reconnect.</p>
  <button type="button" class="offline-close" id="offline-dismiss" aria-label="Dismiss offline notice">
    <span aria-hidden="true">&times;</span>
  </button>
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
      <?php if (Auth::isAdmin($account)): ?>
        <a class="icon-btn" href="<?= e(url('updates')) ?>" aria-label="Updates" title="Updates">⇪</a>
      <?php endif; ?>
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
<?php $tabCount = count($tabs) + 1; ?>
<nav class="bottom-nav" aria-label="Primary" style="--tab-count: <?= e((string) $tabCount) ?>; --active-index: <?= e((string) $activeIndex) ?>">
  <?php /* One element that slides under the active tab. Animated with a transform so it costs the
           compositor nothing, and hidden entirely when no tab is active. */ ?>
  <span class="nav-indicator" aria-hidden="true"<?= $activeIndex === 0 && !($tabs[0]['active'] ?? false) ? ' data-idle="1"' : '' ?>></span>
  <?php foreach ($tabs as $tab): ?>
    <a href="<?= e((string) $tab['href']) ?>"
       class="nav-tab<?= $tab['active'] ? ' active' : '' ?>"
       <?= $tab['active'] ? 'aria-current="page"' : '' ?>>
      <span class="nav-icon" aria-hidden="true"><?= icon((string) $tab['icon']) ?></span>
      <span class="nav-label"><?= e((string) $tab['label']) ?></span>
      <?php if (($tab['short'] ?? '') !== $tab['label']): ?>
        <span class="nav-label nav-label-short" aria-hidden="true"><?= e((string) $tab['short']) ?></span>
      <?php endif; ?>
    </a>
  <?php endforeach; ?>
  <form method="post" action="<?= e(url('')) ?>" class="nav-logout">
    <?= Security::csrfField() ?>
    <input type="hidden" name="action" value="logout">
    <button type="submit" class="nav-tab">
      <span class="nav-icon" aria-hidden="true"><?= icon('logout') ?></span>
      <span class="nav-label">Sign out</span>
    </button>
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

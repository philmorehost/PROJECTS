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
// Impersonation state. Read straight from the session rather than passed down, because it is a
// property of the session, not of the page, and a page that forgot to pass it would silently hide
// the banner — which is precisely the failure that makes impersonation dangerous.
$impersonator = $_SESSION['rise63_impersonator'] ?? null;
if (!is_array($impersonator)) {
    $impersonator = null;
}
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
        // People is a primary admin destination, not a link buried in another page. It was
        // reachable from nowhere at all until this release: the page worked, the forms worked, and
        // no administrator could get to them. A feature nobody can find is not a feature.
        'people'       => ['People', 'people', 'People'],
    ]
    : [
        'my-rise'  => ['My RISE', 'star', 'My RISE'],
        'programs' => ['Programs', 'programs', 'Programs'],
        'parishes' => ['Parish Connect', 'parish', 'Parish'],
    ]);
if ($account !== null && !Auth::isAdmin($account)) {
    // Representatives and members never see the roster.
    unset($navItems['people']);
}
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
// Security is a tab only for the people who have no other route to it. For an administrator it
// lives in the top bar with Settings and Activity, because seven-plus tabs on a 360 px phone
// ellipsise "Treasury" and "Security" into "Treas…" and "Secur…" — and a tab bar of truncated words
// is worse than one with fewer, legible tabs.
if ($account !== null && $isStaff && (string) $account['role'] === 'csr_rep') {
    $tabs[] = ['href' => url('security'), 'icon' => 'security', 'label' => 'Security', 'short' => 'Security', 'active' => $page === 'security'];
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
<?php
// Share metadata. An operator-facing page is never indexed and never previewed: a card for
// /treasury pasted into a chat would show figures to people who are not signed in.
$shareTitle       = $title ?? null;
$shareNoIndex     = $account !== null;
require __DIR__ . '/partials/share.php';
?>
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

<?php if ($impersonator !== null): ?>
  <?php /* An administrator is looking at a member's account. The banner cannot be dismissed: the
           one thing that must never happen is an administrator forgetting whose account they are
           acting in and, say, editing their own record through it. */ ?>
  <div class="impersonation-bar" role="status">
    <span>
      Signed in as <strong><?= e((string) ($account['full_name'] ?? 'this member')) ?></strong>
      — an administrator view started by <?= e((string) ($impersonator['name'] ?? 'an administrator')) ?>.
      Actions are recorded against this member's account.
    </span>
    <form method="post" action="<?= e(url('')) ?>">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="user-login-as-stop">
      <button class="btn btn-secondary btn-sm" type="submit">Return to my account</button>
    </form>
  </div>
<?php endif; ?>

<?php /* Shown only while the browser reports no connection, and removed the moment it returns —
         both are handled by app.js, which also re-tests the connection every few seconds so a
         stale "online" flag cannot leave the bar on screen. Hidden in the markup, so it never
         flashes on a healthy page before the script runs. */ ?>
<?php /* Connectivity notice.
         A bar pinned to the top of the viewport was the wrong shape for this message. It sat over
         the top bar, it could not be closed on a first release (the handler was in a stale
         app.js), and — because it is the one thing on the page the user cannot navigate away from
         — being unable to close it is worse than not showing it at all.
         Now: a modal dialog in the bottom sheet position, so it never covers the header, it is
         dismissible three ways (button, backdrop, Escape), and closing it sticks until the next
         time the connection actually drops. Hidden in the markup, so it never flashes on a page
         that loaded fine. */ ?>
<div class="offline-modal" id="offline-modal" role="dialog" aria-modal="true"
     aria-labelledby="offline-title" aria-describedby="offline-body" hidden>
  <div class="offline-sheet" role="document">
    <span class="offline-emoji" aria-hidden="true"><?= icon('offline', 'icon') ?></span>
    <h2 id="offline-title">You are offline</h2>
    <p id="offline-body">This device has lost its internet connection. Anything you submit will be
      kept and sent as soon as you reconnect.</p>
    <div class="offline-actions">
      <button type="button" class="btn btn-primary btn-block" id="offline-close">Dismiss</button>
      <button type="button" class="btn btn-ghost btn-block" id="offline-retry">Try again now</button>
    </div>
    <p class="offline-hint" id="offline-hint" aria-live="polite"></p>
  </div>
</div>
<script>
  // The modal's own controls, deliberately independent of app.js.
  //
  // app.js binds the same buttons and owns the connectivity decision; this copy only dismisses and
  // re-tests. It exists because the first report of "the × does nothing" was a cached app.js: the
  // markup had been updated while the script that bound it was a release behind. A handler that
  // ships in the same response as the markup cannot drift out of step with it.
  //
  // The CSP is script-src 'self' with a hash allow-list, so this snippet runs only because its
  // SHA-256 is in Security::INLINE_SCRIPT_HASHES — and tests/render.php fails if the two disagree.
  (function () {
    var modal = document.getElementById('offline-modal');
    if (!modal) { return; }
    var sheet = modal.querySelector('.offline-sheet');
    var hint = document.getElementById('offline-hint');
    var close = document.getElementById('offline-close');
    var retry = document.getElementById('offline-retry');

    function hide() {
      modal.hidden = true;
      modal.setAttribute('data-dismissed', '1');
      document.documentElement.classList.remove('offline-open');
    }
    if (close) { close.addEventListener('click', hide); }
    // Backdrop click closes; a click inside the sheet must not.
    modal.addEventListener('click', function (event) {
      if (sheet && !sheet.contains(event.target)) { hide(); }
    });
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && !modal.hidden) { hide(); }
    });
    if (retry) {
      retry.addEventListener('click', function () {
        if (hint) { hint.textContent = 'Checking…'; }
        // A HEAD to a tiny same-origin file: any HTTP answer means the server was reached.
        fetch('./favicon.svg', { method: 'HEAD', cache: 'no-store' }).then(function () {
          hide();
          window.location.reload();
        }, function () {
          if (hint) { hint.textContent = 'Still no connection. Check Wi-Fi or mobile data.'; }
        });
      });
    }
  }());
</script>

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
      <?php /* The four administrator destinations that do not fit as tabs on a phone. They are
               labelled text, not glyphs: "⇪" was the only route to Updates and a glyph is not a
               signpost — an operator who has not been told what it means will not press it. */ ?>
      <?php if (Auth::isAdmin($account)): ?>
        <nav class="admin-links" aria-label="Administration">
          <a class="admin-link<?= $page === 'settings' ? ' is-current' : '' ?>" href="<?= e(url('settings')) ?>">Settings</a>
          <a class="admin-link<?= $page === 'security' ? ' is-current' : '' ?>" href="<?= e(url('security')) ?>">Security</a>
          <a class="admin-link<?= $page === 'audit' ? ' is-current' : '' ?>" href="<?= e(url('audit')) ?>">Activity</a>
          <a class="admin-link<?= $page === 'updates' ? ' is-current' : '' ?>" href="<?= e(url('updates')) ?>">Updates</a>
        </nav>
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
      <span class="nav-label nav-label-short" aria-hidden="true">Exit</span>
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

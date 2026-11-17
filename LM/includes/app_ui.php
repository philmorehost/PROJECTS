<?php
/**
 * Admin console shell — one header, one sidebar, one set of components.
 *
 * Every screen in admin/ renders through lm_app_start() / lm_app_end(), which
 * means the navigation is defined once (lm_app_nav_items()), the icons once
 * (lm_icon()), and the CSS once (admin.css). No admin page carries its own
 * <style> block or its own copy of the menu any more.
 *
 * Layout: fixed 264px sidebar on desktop; on ≤980px the sidebar is replaced by
 * a fixed top bar, a slide-in drawer and a five-slot bottom tab bar.
 *
 * @package LicenseManager
 */

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/ui.php';
require_once __DIR__ . '/guide.php';

if (!defined('LM_APP_UI')) {
    define('LM_APP_UI', true);
}

if (!function_exists('lm_app_nav_items')) {
    /**
     * The console navigation. Every entry exists — that is the contract.
     *
     * @return array
     */
    function lm_app_nav_items()
    {
        return array(
            'dashboard'     => array('label' => 'Dashboard',    'icon' => 'grid',     'href' => 'dashboard.php',           'group' => 'Overview'),
            'licenses'      => array('label' => 'Licences',     'icon' => 'key',      'href' => 'licenses.php',            'group' => 'Overview'),
            'transactions'  => array('label' => 'Transactions', 'icon' => 'wallet',   'href' => 'transactions.php',        'group' => 'Overview'),
            'updates'       => array('label' => 'Push Updates', 'icon' => 'rocket',   'href' => 'updates.php',             'group' => 'Releases'),
            'github'        => array('label' => 'GitHub Pull',  'icon' => 'layers',   'href' => 'github-updates.php',      'group' => 'Releases'),
            'notifications' => array('label' => 'Notifications', 'icon' => 'bell',    'href' => 'update-notifications.php', 'group' => 'Releases'),
            'projects'      => array('label' => 'Projects',     'icon' => 'layers',   'href' => 'projects.php',            'group' => 'Releases'),
            'guide'         => array('label' => 'Admin Guide',  'icon' => 'book',     'href' => 'guide.php',               'group' => 'System'),
            'settings'      => array('label' => 'Settings',     'icon' => 'settings', 'href' => 'settings.php',            'group' => 'System'),
            'profile'       => array('label' => 'Profile',      'icon' => 'user',     'href' => 'profile.php',             'group' => 'System'),
            'system-check'  => array('label' => 'System Check', 'icon' => 'shield',   'href' => 'system-check.php',        'group' => 'Diagnostics'),
            'diagnostic'    => array('label' => 'Diagnostics',  'icon' => 'chart',    'href' => 'diagnostic.php',          'group' => 'Diagnostics'),
            'database'      => array('label' => 'Database Repair', 'icon' => 'refresh', 'href' => 'database-repair.php',   'group' => 'Diagnostics'),
            'tiers'         => array('label' => 'Licence Tiers', 'icon' => 'layers',   'href' => 'fix_license_tiers.php',  'group' => 'Diagnostics'),
            'manifests'     => array('label' => 'Package Manifests', 'icon' => 'code', 'href' => 'patch_zip_manifests.php', 'group' => 'Diagnostics'),
            'logs'          => array('label' => 'Debug Logs',   'icon' => 'terminal', 'href' => 'payment-debug.php',       'group' => 'Diagnostics'),
            'webhook'       => array('label' => 'Webhook Tester', 'icon' => 'plug',   'href' => 'webhook-tester.php',      'group' => 'Diagnostics'),
            'health'        => array('label' => 'Installation', 'icon' => 'check',    'href' => '../health.php',           'group' => 'Diagnostics'),
            'logout'        => array('label' => 'Sign out',     'icon' => 'logout',   'href' => 'logout.php',              'group' => 'Account'),
        );
    }
}

if (!function_exists('lm_app_active')) {
    /**
     * Which navigation entry belongs to the running script.
     *
     * @return string
     */
    function lm_app_active()
    {
        static $active = null;
        if ($active !== null) {
            return $active;
        }

        $file = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $map = array(
            'index.php'                => 'dashboard',
            'dashboard.php'            => 'dashboard',
            'licenses.php'             => 'licenses',
            'transactions.php'         => 'transactions',
            'updates.php'              => 'updates',
            'github-updates.php'       => 'github',
            'update-notifications.php' => 'notifications',
            'projects.php'             => 'projects',
            'guide.php'                => 'guide',
            'settings.php'             => 'settings',
            'profile.php'              => 'profile',
            'system-check.php'         => 'system-check',
            'diagnostic.php'           => 'diagnostic',
            'database-repair.php'      => 'database',
            'fix_license_tiers.php'    => 'tiers',
            'patch_zip_manifests.php'  => 'manifests',
            'payment-debug.php'        => 'logs',
            'webhook-tester.php'       => 'webhook',
            'health.php'               => 'health',
        );

        $active = isset($map[$file]) ? $map[$file] : 'dashboard';
        return $active;
    }
}

if (!function_exists('lm_app_boot')) {
    /**
     * Load the database and hold the door for signed-out visitors.
     *
     * @param bool $requireAdmin Redirect to the sign-in screen when true.
     * @return PDO|null
     */
    function lm_app_boot($requireAdmin = true)
    {
        global $pdo;

        if ($requireAdmin && empty($_SESSION['admin_logged_in'])) {
            header('Location: index.php');
            exit();
        }

        $db = __DIR__ . '/../db.php';
        if (is_file($db)) {
            require_once $db;
        }

        return isset($pdo) ? $pdo : null;
    }
}

if (!function_exists('lm_app_handle_post')) {
    /**
     * Is this a first-party form post that passed the CSRF check?
     *
     * @return bool
     */
    function lm_app_handle_post()
    {
        return (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') && lm_verify_csrf();
    }
}

if (!function_exists('lm_app_start')) {
    /**
     * Open the console shell: <head>, sidebar, mobile chrome and <main>.
     *
     * @param string $title  Page title, shown in the top bar and the browser tab
     * @param array  $options subtitle, actions (HTML), active, wide
     * @return void
     */
    function lm_app_start($title, array $options = array())
    {
        $siteName = function_exists('lm_site_name') ? lm_site_name() : 'License Manager';
        $active = isset($options['active']) ? (string) $options['active'] : lm_app_active();
        $subtitle = isset($options['subtitle']) ? (string) $options['subtitle'] : '';
        $actions = isset($options['actions']) ? (string) $options['actions'] : '';
        $csrf = function_exists('lm_csrf_token') ? lm_csrf_token() : '';
        $items = lm_app_nav_items();
        $group = '';
        $email = isset($_SESSION['admin_username']) ? (string) $_SESSION['admin_username'] : 'administrator';
        $version = defined('LM_VERSION') ? LM_VERSION : '';
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#0b1220">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?= lm_e($csrf) ?>">
    <meta name="lm-version" content="<?= lm_e($version) ?>">
    <title><?= lm_e($title) ?> — <?= lm_e($siteName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= lm_e(lm_asset('admin.css')) ?>">
</head>
<body data-lm-active="<?= lm_e($active) ?>">

<a class="lm-skip" href="#main">Skip to content</a>

<!-- Mobile top bar -->
<header class="lm-appbar" role="banner">
    <button class="lm-appbar__btn" type="button" data-lm-drawer-open aria-controls="lm-appdrawer"
            aria-expanded="false" aria-label="Open navigation"><?= lm_icon('menu') ?></button>
    <div class="lm-appbar__title">
        <strong><?= lm_e($title) ?></strong>
        <span><?= lm_e($siteName) ?> · v<?= lm_e($version) ?></span>
    </div>
    <a class="lm-appbar__btn" href="../index.php" aria-label="View the public site" target="_blank" rel="noopener">
        <?= lm_icon('external') ?>
    </a>
</header>

<!-- Mobile drawer -->
<div class="lm-appdrawer" id="lm-appdrawer" data-lm-drawer data-open="false">
    <button class="lm-appdrawer__scrim" type="button" data-lm-drawer-close aria-label="Close navigation" tabindex="-1"></button>
    <div class="lm-appdrawer__panel" role="dialog" aria-modal="true" aria-label="Console navigation">
        <div class="lm-appdrawer__head">
            <a class="lm-brand" href="dashboard.php">
                <span class="lm-brand__mark"><?= lm_icon('key') ?></span>
                <span class="lm-brand__text"><?= lm_e($siteName) ?><small>Console</small></span>
            </a>
            <button class="lm-appdrawer__close" type="button" data-lm-drawer-close aria-label="Close navigation">
                <?= lm_icon('close') ?>
            </button>
        </div>

        <?php foreach ($items as $key => $item): ?>
            <?php if ($item['group'] !== $group): $group = $item['group']; ?>
                <div class="lm-navgroup"><?= lm_e($group) ?></div>
            <?php endif; ?>
            <a class="lm-navlink<?= $key === $active ? ' is-active' : '' ?>" href="<?= lm_e($item['href']) ?>"
               <?= $key === $active ? 'aria-current="page"' : '' ?>>
                <?= lm_icon($item['icon']) ?>
                <span><?= lm_e($item['label']) ?></span>
            </a>
        <?php endforeach; ?>

        <div class="lm-appdrawer__foot">
            <span class="lm-help">Signed in as <strong><?= lm_e($email) ?></strong></span>
            <a class="lm-btn lm-btn--ghost lm-btn--block lm-btn--sm" href="../index.php" target="_blank" rel="noopener">
                <?= lm_icon('external') ?> Open the site
            </a>
        </div>
    </div>
</div>

<div class="lm-shell">
    <!-- Desktop sidebar -->
    <aside class="lm-sidebar">
        <a class="lm-sidebar__brand" href="dashboard.php">
            <span class="lm-brand__mark"><?= lm_icon('key') ?></span>
            <span class="lm-brand__text"><?= lm_e($siteName) ?><small>Console v<?= lm_e($version) ?></small></span>
        </a>

        <nav class="lm-sidebar__nav" aria-label="Console">
            <?php $group = ''; ?>
            <?php foreach ($items as $key => $item): ?>
                <?php if ($item['group'] !== $group): $group = $item['group']; ?>
                    <div class="lm-navgroup"><?= lm_e($group) ?></div>
                <?php endif; ?>
                <a class="lm-navlink<?= $key === $active ? ' is-active' : '' ?>" href="<?= lm_e($item['href']) ?>"
                   <?= $key === $active ? 'aria-current="page"' : '' ?>>
                    <?= lm_icon($item['icon']) ?>
                    <span><?= lm_e($item['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="lm-sidebar__foot">
            <span class="lm-help">Signed in as<br><strong><?= lm_e($email) ?></strong></span>
        </div>
    </aside>

    <main class="lm-main" id="main">
        <div class="lm-main__inner">
            <div class="lm-apphead">
                <div class="lm-apphead__text">
                    <h1><?= lm_e($title) ?></h1>
                    <?php if ($subtitle !== ''): ?>
                        <p><?= $subtitle ?></p>
                    <?php endif; ?>
                </div>
                <?php if ($actions !== ''): ?>
                    <div class="lm-apphead__actions"><?= $actions ?></div>
                <?php endif; ?>
            </div>
            <?php
            /* Mixed-release warning: the console cannot work if the folder holds
               two versions, so say it once, at the top of every screen. */
            $release = function_exists('lm_release_state') ? lm_release_state() : array('ok' => true, 'reason' => '', 'file' => '');
            if (!$release['ok']) {
                $manifestVersion = function_exists('lm_release_manifest') ? (string) ((lm_release_manifest()['version'] ?? '')) : '';
                lm_alert('error', $release['reason'] === 'version'
                    ? 'Mixed release: the folder on the server is ' . ($manifestVersion !== '' ? $manifestVersion : 'an older build')
                        . ' but the running code is ' . $version . '. Until the whole folder is replaced, sign-in and '
                        . 'every AJAX action can be refused with 403. Open ../health.php to see each stale file.'
                    : 'This page (' . $release['file'] . ') is not from the release recorded in build.json, so it may '
                        . 'send a different session or security token than the endpoints it calls. Re-upload the '
                        . 'complete admin/ folder.');
            }
            ?> <?php // back to PHP: the markup above is the shell's closing tags, the braces below end the function.
    }
}

if (!function_exists('lm_app_end')) {
    /**
     * Close the console shell and print the shared script bundle.
     *
     * @return void
     */
    function lm_app_end()
    {
        ?>
        </div><!-- /.lm-main__inner -->
    </main>
</div><!-- /.lm-shell -->

<nav class="lm-apptabs" aria-label="Quick navigation">
    <a class="lm-apptab" href="dashboard.php" <?= lm_app_active() === 'dashboard' ? 'aria-current="page"' : '' ?>>
        <?= lm_icon('grid') ?><span>Home</span>
    </a>
    <a class="lm-apptab" href="licenses.php" <?= lm_app_active() === 'licenses' ? 'aria-current="page"' : '' ?>>
        <?= lm_icon('key') ?><span>Licences</span>
    </a>
    <a class="lm-apptab" href="updates.php" <?= lm_app_active() === 'updates' ? 'aria-current="page"' : '' ?>>
        <?= lm_icon('rocket') ?><span>Updates</span>
    </a>
    <a class="lm-apptab" href="github-updates.php" <?= lm_app_active() === 'github' ? 'aria-current="page"' : '' ?>>
        <?= lm_icon('layers') ?><span>GitHub</span>
    </a>
    <button class="lm-apptab" type="button" data-lm-drawer-open aria-controls="lm-appdrawer" aria-expanded="false">
        <?= lm_icon('menu') ?><span>Menu</span>
    </button>
</nav>

<div class="lm-toast" id="lm-toast" role="status" aria-live="polite"></div>

<!-- Modals are declared by pages with class="lm-modal" and opened by id. -->
<script src="<?= lm_e(lm_asset('app.js')) ?>" defer></script>
</body>
</html>
        <?php
    }
}

if (!function_exists('lm_app_stat')) {
    /**
     * One stat card.
     *
     * @param string $label
     * @param string $value
     * @param string $sub
     * @param string $tone  '' | green | amber | indigo
     * @return void
     */
    function lm_app_stat($label, $value, $sub = '', $tone = '')
    {
        ?>
        <div class="lm-stat<?= $tone !== '' ? ' lm-stat--' . lm_e($tone) : '' ?>">
            <div class="lm-stat__label"><?= lm_e($label) ?></div>
            <div class="lm-stat__value"><?= lm_e($value) ?></div>
            <?php if ($sub !== ''): ?>
                <div class="lm-stat__sub"><?= $sub ?></div>
            <?php endif; ?>
        </div>
        <?php
    }
}

if (!function_exists('lm_app_badge')) {
    /**
     * A status badge.
     *
     * @param string $text
     * @param string $tone ok|warn|bad|info|''
     * @return string
     */
    function lm_app_badge($text, $tone = '')
    {
        return '<span class="lm-badge' . ($tone !== '' ? ' lm-badge--' . $tone : '') . '">'
            . lm_e($text) . '</span>';
    }
}

if (!function_exists('lm_app_empty')) {
    /**
     * Empty-state block.
     *
     * @param string $icon
     * @param string $title
     * @param string $text
     * @param string $action HTML
     * @return void
     */
    function lm_app_empty($icon, $title, $text, $action = '')
    {
        ?>
        <div class="lm-empty">
            <div class="lm-empty__icon"><?= lm_icon($icon) ?></div>
            <h3><?= lm_e($title) ?></h3>
            <p><?= lm_e($text) ?></p>
            <?= $action ?>
        </div>
        <?php
    }
}

if (!function_exists('lm_app_copy')) {
    /**
     * Copy button wired by app.js.
     *
     * @param string $value
     * @param string $label
     * @return string
     */
    function lm_app_copy($value, $label = 'Copy')
    {
        return '<button class="lm-copybtn lm-copybtn--light" type="button" data-lm-copy="'
            . lm_e($value) . '">' . lm_icon('copy') . '<span>' . lm_e($label) . '</span></button>';
    }
}

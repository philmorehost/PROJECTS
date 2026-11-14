<?php
/**
 * Mobile navigation for the admin console.
 *
 * Included immediately after <body> on every admin screen. On a phone or a
 * small tablet it replaces the sidebar with:
 *
 *   - a fixed top app bar (brand, current page, menu button)
 *   - a slide-in drawer with the full navigation
 *   - a fixed bottom tab bar (the "footer menu") with the five places an
 *     operator actually uses: Dashboard, Licences, Push Updates, GitHub, More
 *
 * Desktop rendering is untouched. Everything is scoped under .lm-m-* and
 * loaded after the page's own CSS, so it can hide the legacy sidebar without
 * editing twenty separate stylesheets.
 *
 * @package LicenseManager
 */

if (!function_exists('lm_admin_mobile_items')) {
    /**
     * Navigation for the drawer. Uses the shared _ui.php list when the page
     * was rendered through it, otherwise a literal fallback.
     *
     * @return array
     */
    function lm_admin_mobile_items()
    {
        if (function_exists('lm_admin_nav_items')) {
            return lm_admin_nav_items();
        }

        return array(
            'dashboard'     => array('label' => 'Dashboard', 'icon' => '📊', 'href' => 'dashboard.php', 'group' => 'Overview'),
            'licenses'      => array('label' => 'Licences', 'icon' => '📜', 'href' => 'licenses.php', 'group' => 'Overview'),
            'transactions'  => array('label' => 'Transactions', 'icon' => '💳', 'href' => 'transactions.php', 'group' => 'Overview'),
            'updates'       => array('label' => 'Push Updates', 'icon' => '🚀', 'href' => 'updates.php', 'group' => 'Releases'),
            'github'        => array('label' => 'GitHub Pull', 'icon' => '🐙', 'href' => 'github-updates.php', 'group' => 'Releases'),
            'notifications' => array('label' => 'Notifications', 'icon' => '🔔', 'href' => 'update-notifications.php', 'group' => 'Releases'),
            'projects'      => array('label' => 'Script Projects', 'icon' => '🧩', 'href' => 'projects.php', 'group' => 'Releases'),
            'guide'         => array('label' => 'Admin Guide', 'icon' => '📖', 'href' => 'guide.php', 'group' => 'System'),
            'settings'      => array('label' => 'Settings', 'icon' => '⚙️', 'href' => 'settings.php', 'group' => 'System'),
            'profile'       => array('label' => 'Profile', 'icon' => '👤', 'href' => 'profile.php', 'group' => 'System'),
            'system-check'  => array('label' => 'System Check', 'icon' => '✅', 'href' => 'system-check.php', 'group' => 'System'),
            'database'      => array('label' => 'Database Repair', 'icon' => '🔧', 'href' => 'database-repair.php', 'group' => 'System'),
            'logs'          => array('label' => 'Debug Logs', 'icon' => '🐛', 'href' => 'payment-debug.php', 'group' => 'System'),
        );
    }
}

if (!function_exists('lm_admin_mobile_active')) {
    /**
     * Key of the navigation item for the current script.
     *
     * @return string
     */
    function lm_admin_mobile_active()
    {
        static $active = null;
        if ($active !== null) {
            return $active;
        }

        $file = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $map = array(
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
            'database-repair.php'      => 'database',
            'payment-debug.php'        => 'logs',
            'diagnostic.php'           => 'logs',
            'fix_license_tiers.php'    => 'database',
            'patch_zip_manifests.php'  => 'database',
        );

        $active = isset($map[$file]) ? $map[$file] : 'dashboard';
        return $active;
    }
}

if (!function_exists('lm_admin_mobile_svg')) {
    /**
     * Small stroke icon set for the top bar and the tab bar.
     *
     * @param string $name
     * @return string
     */
    function lm_admin_mobile_svg($name)
    {
        $paths = array(
            'home'   => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5.5 9.5V21h13V9.5"/>',
            'doc'    => '<path d="M6 3h9l3 3v15H6Z"/><path d="M9 9h6M9 13h6M9 17h4"/>',
            'rocket' => '<path d="M12 15.5 8.5 12c1.2-4.2 4.4-7.3 8.6-8.5 1.2-.3 2 .5 1.7 1.7-1.2 4.2-4.3 7.4-8.5 8.6Z"/><path d="M8.5 12 5 12.5 2.5 15.5l4 .5"/><path d="M12 15.5l-.5 3.5-3 2.5-.5-4"/>',
            'branch' => '<circle cx="6" cy="6" r="2.4"/><circle cx="18" cy="6" r="2.4"/><circle cx="12" cy="18" r="2.4"/><path d="M8.4 6h7.2M7.2 8.2 10.8 15.6M16.8 8.2 13.2 15.6"/>',
            'menu'   => '<path d="M4 7h16M4 12h16M4 17h10"/>',
            'logout' => '<path d="M14 4h4a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-4"/><path d="M10 8 6 12l4 4"/><path d="M6 12h9"/>',
            'close'  => '<path d="m6 6 12 12M18 6 6 18"/>',
        );
        $body = isset($paths[$name]) ? $paths[$name] : $paths['menu'];

        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"'
            . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $body . '</svg>';
    }
}

$lmMobileItems  = lm_admin_mobile_items();
$lmMobileActive = lm_admin_mobile_active();
$lmMobileSite   = function_exists('lm_site_name') ? lm_site_name() : 'License Manager';
$lmMobileTitle  = isset($lmMobileItems[$lmMobileActive]) ? $lmMobileItems[$lmMobileActive]['label'] : 'Console';
$lmMobileGroups = array();
foreach ($lmMobileItems as $key => $item) {
    $group = isset($item['group']) ? $item['group'] : 'More';
    if (!isset($lmMobileGroups[$group])) {
        $lmMobileGroups[$group] = array();
    }
    $lmMobileGroups[$group][$key] = $item;
}

$lmMobileTabs = array(
    'dashboard' => array('label' => 'Home',     'icon' => 'home',   'href' => 'dashboard.php'),
    'licenses'  => array('label' => 'Licences', 'icon' => 'doc',    'href' => 'licenses.php'),
    'updates'   => array('label' => 'Updates',  'icon' => 'rocket', 'href' => 'updates.php'),
    'github'    => array('label' => 'GitHub',   'icon' => 'branch', 'href' => 'github-updates.php'),
);
?>
<style>
    /* ---------------------------------------------------------------- bar */
    .lm-m-bar {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        z-index: 130;
        align-items: center;
        gap: 12px;
        min-height: 60px;
        padding: 9px 14px;
        padding-top: calc(9px + env(safe-area-inset-top));
        background: rgba(9, 14, 26, .92);
        -webkit-backdrop-filter: blur(14px) saturate(160%);
        backdrop-filter: blur(14px) saturate(160%);
        border-bottom: 1px solid rgba(148, 163, 184, .16);
        color: #e2e8f0;
    }

    .lm-m-bar__brand {
        display: flex;
        align-items: center;
        gap: 9px;
        min-width: 0;
        color: #fff;
        text-decoration: none;
    }

    .lm-m-bar__mark {
        width: 32px;
        height: 32px;
        border-radius: 9px;
        background: linear-gradient(135deg, #4f46e5, #06b6d4);
        display: grid;
        place-items: center;
        font-size: 15px;
        flex: none;
    }

    .lm-m-bar__text {
        min-width: 0;
        line-height: 1.15;
    }

    .lm-m-bar__text strong {
        display: block;
        font-size: 13.5px;
        font-weight: 800;
        letter-spacing: -.2px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 42vw;
    }

    .lm-m-bar__text span {
        display: block;
        font-size: 10.5px;
        color: #94a3b8;
        letter-spacing: .06em;
        text-transform: uppercase;
    }

    .lm-m-bar__spacer { flex: 1; }

    .lm-m-bar__btn {
        width: 42px;
        height: 42px;
        border-radius: 11px;
        border: 1px solid rgba(148, 163, 184, .25);
        background: rgba(255, 255, 255, .06);
        color: #e2e8f0;
        display: grid;
        place-items: center;
        cursor: pointer;
        padding: 0;
        flex: none;
    }

    .lm-m-bar__btn svg { width: 21px; height: 21px; }

    /* ------------------------------------------------------------- drawer */
    .lm-m-drawer {
        position: fixed;
        inset: 0;
        z-index: 150;
        display: none;
    }

    .lm-m-drawer[data-open="true"] { display: block; }

    .lm-m-drawer__backdrop {
        position: absolute;
        inset: 0;
        border: 0;
        padding: 0;
        background: rgba(4, 8, 16, .6);
        -webkit-backdrop-filter: blur(3px);
        backdrop-filter: blur(3px);
    }

    .lm-m-drawer__panel {
        position: absolute;
        top: 0;
        right: 0;
        bottom: 0;
        width: min(330px, 88vw);
        background: #0b1220;
        color: #cbd5e1;
        display: flex;
        flex-direction: column;
        padding: 18px 14px calc(22px + env(safe-area-inset-bottom));
        box-shadow: -30px 0 60px -30px rgba(0, 0, 0, .7);
        overflow-y: auto;
        animation: lm-m-slide .2s ease both;
    }

    @keyframes lm-m-slide {
        from { transform: translateX(20px); opacity: .5; }
        to   { transform: none; opacity: 1; }
    }

    .lm-m-drawer__head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 0 6px 14px;
        border-bottom: 1px solid rgba(148, 163, 184, .14);
        margin-bottom: 10px;
    }

    .lm-m-drawer__head strong {
        color: #fff;
        font-size: 14.5px;
        letter-spacing: -.2px;
    }

    .lm-m-drawer__close {
        width: 38px;
        height: 38px;
        border-radius: 10px;
        border: 1px solid rgba(148, 163, 184, .22);
        background: rgba(255, 255, 255, .05);
        color: #e2e8f0;
        display: grid;
        place-items: center;
        cursor: pointer;
        padding: 0;
    }

    .lm-m-drawer__close svg { width: 18px; height: 18px; }

    .lm-m-group {
        font-size: 9.5px;
        letter-spacing: .18em;
        text-transform: uppercase;
        color: #64748b;
        font-weight: 700;
        padding: 14px 12px 6px;
    }

    .lm-m-link {
        display: flex;
        align-items: center;
        gap: 11px;
        padding: 11px 12px;
        border-radius: 10px;
        color: #cbd5e1;
        text-decoration: none;
        font-size: 14px;
        font-weight: 500;
    }

    .lm-m-link .lm-m-ico {
        width: 26px;
        text-align: center;
        font-size: 15px;
        flex: none;
    }

    .lm-m-link:hover { background: rgba(79, 70, 229, .18); color: #fff; }
    .lm-m-link[aria-current="page"] {
        background: linear-gradient(135deg, rgba(79, 70, 229, .42), rgba(6, 182, 212, .2));
        color: #fff;
        box-shadow: inset 3px 0 0 #818cf8;
    }

    .lm-m-drawer__foot { margin-top: auto; padding-top: 16px; }

    .lm-m-signout {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 12px 12px;
        border-radius: 10px;
        color: #fca5a5;
        text-decoration: none;
        font-size: 14px;
        font-weight: 600;
        border: 1px solid rgba(248, 113, 113, .22);
        background: rgba(248, 113, 113, .07);
    }

    .lm-m-signout svg { width: 18px; height: 18px; }

    /* --------------------------------------------------------------- tabs */
    .lm-m-tabs {
        display: none;
        position: fixed;
        left: 0;
        right: 0;
        bottom: 0;
        z-index: 120;
        grid-template-columns: repeat(5, 1fr);
        gap: 2px;
        padding: 7px 7px calc(7px + env(safe-area-inset-bottom));
        background: rgba(9, 14, 26, .95);
        -webkit-backdrop-filter: blur(16px) saturate(160%);
        backdrop-filter: blur(16px) saturate(160%);
        border-top: 1px solid rgba(148, 163, 184, .16);
        box-shadow: 0 -14px 30px -26px rgba(0, 0, 0, .8);
    }

    .lm-m-tab {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 3px;
        min-height: 48px;
        padding: 4px 2px;
        border-radius: 10px;
        color: #94a3b8;
        text-decoration: none;
        font-size: 10px;
        font-weight: 700;
        letter-spacing: .01em;
        border: 0;
        background: none;
        cursor: pointer;
        font-family: inherit;
    }

    .lm-m-tab svg { width: 20px; height: 20px; }
    .lm-m-tab[aria-current="page"] { color: #fff; background: rgba(79, 70, 229, .28); }
    .lm-m-tab:hover { color: #fff; }

    body[data-lm-drawer="true"] { overflow: hidden; }

    /* ---------------------------------------------------------- breakpoint */
    @media (max-width: 980px) {
        .lm-m-bar { display: flex; }
        .lm-m-tabs { display: grid; }

        /* The console's own sidebar becomes the drawer. */
        .sidebar { display: none !important; }

        /* One rule for every console layout: clear the top bar and the tabs. */
        body {
            display: block !important;
            padding-top: 68px !important;
            padding-bottom: calc(88px + env(safe-area-inset-bottom)) !important;
        }

        .main,
        .main-content {
            margin-left: 0 !important;
            width: 100% !important;
            padding-top: 16px !important;
            padding-bottom: 24px !important;
            padding-left: 16px !important;
            padding-right: 16px !important;
        }

        .lm-m-hide-mobile { display: none !important; }
    }

    @media (min-width: 981px) {
        .lm-m-bar, .lm-m-drawer, .lm-m-tabs { display: none !important; }

        /*
         * Legacy console pages draw their own sidebar. These rules modernise it
         * without touching twenty separate stylesheets: the _ui.php screens use
         * .brand / a.nav and are left alone.
         */
        .sidebar h1 {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 15px !important;
            letter-spacing: -.3px;
            padding: 4px 10px 16px !important;
            margin: 0 0 8px !important;
            border-bottom: 1px solid rgba(148, 163, 184, .16);
        }

        .sidebar nav {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .sidebar nav a {
            display: flex;
            align-items: center;
            gap: 9px;
            padding: 9px 11px !important;
            border-radius: 10px !important;
            font-size: 13.5px !important;
            text-decoration: none;
            transition: background .16s ease, color .16s ease;
        }

        .sidebar nav a:hover {
            background: rgba(79, 70, 229, .18);
            color: #fff;
        }

        .sidebar nav a.active {
            background: linear-gradient(135deg, rgba(79, 70, 229, .42), rgba(6, 182, 212, .18)) !important;
            box-shadow: inset 3px 0 0 #818cf8;
            color: #fff !important;
        }

        .sidebar nav hr {
            border: 0 !important;
            border-top: 1px solid rgba(148, 163, 184, .16) !important;
            margin: 8px 0 !important;
        }
    }
</style>

<header class="lm-m-bar" role="banner">
    <a class="lm-m-bar__brand" href="dashboard.php">
        <span class="lm-m-bar__mark">🔐</span>
        <span class="lm-m-bar__text">
            <strong><?= htmlspecialchars($lmMobileTitle) ?></strong>
            <span><?= htmlspecialchars($lmMobileSite) ?> · v<?= htmlspecialchars(defined('LM_VERSION') ? LM_VERSION : '') ?></span>
        </span>
    </a>
    <span class="lm-m-bar__spacer"></span>
    <button class="lm-m-bar__btn" type="button" data-lm-drawer-open aria-controls="lm-m-drawer"
            aria-expanded="false" aria-label="Open navigation"><?= lm_admin_mobile_svg('menu') ?></button>
</header>

<div class="lm-m-drawer" id="lm-m-drawer" data-lm-drawer data-open="false">
    <button class="lm-m-drawer__backdrop" type="button" data-lm-drawer-close aria-label="Close navigation" tabindex="-1"></button>
    <div class="lm-m-drawer__panel" role="dialog" aria-modal="true" aria-label="Console navigation">
        <div class="lm-m-drawer__head">
            <strong><?= htmlspecialchars($lmMobileSite) ?></strong>
            <button class="lm-m-drawer__close" type="button" data-lm-drawer-close aria-label="Close navigation">
                <?= lm_admin_mobile_svg('close') ?>
            </button>
        </div>

        <?php foreach ($lmMobileGroups as $group => $items): ?>
            <div class="lm-m-group"><?= htmlspecialchars($group) ?></div>
            <?php foreach ($items as $key => $item): ?>
                <a class="lm-m-link" href="<?= htmlspecialchars($item['href']) ?>"
                   <?= $key === $lmMobileActive ? 'aria-current="page"' : '' ?>>
                    <span class="lm-m-ico" aria-hidden="true"><?= $item['icon'] ?></span>
                    <?= htmlspecialchars($item['label']) ?>
                </a>
            <?php endforeach; ?>
        <?php endforeach; ?>

        <div class="lm-m-drawer__foot">
            <a class="lm-m-signout" href="logout.php"><?= lm_admin_mobile_svg('logout') ?> Sign out</a>
        </div>
    </div>
</div>

<nav class="lm-m-tabs" aria-label="Quick navigation">
    <?php foreach ($lmMobileTabs as $key => $tab): ?>
        <a class="lm-m-tab" href="<?= htmlspecialchars($tab['href']) ?>"
           <?= $key === $lmMobileActive ? 'aria-current="page"' : '' ?>>
            <?= lm_admin_mobile_svg($tab['icon']) ?>
            <span><?= htmlspecialchars($tab['label']) ?></span>
        </a>
    <?php endforeach; ?>
    <button class="lm-m-tab" type="button" data-lm-drawer-open aria-controls="lm-m-drawer"
            aria-expanded="false">
        <?= lm_admin_mobile_svg('menu') ?>
        <span>More</span>
    </button>
</nav>

<script>
(function () {
    'use strict';

    var drawer = document.getElementById('lm-m-drawer');
    var openers = document.querySelectorAll('[data-lm-drawer-open]');
    var closers = document.querySelectorAll('[data-lm-drawer-close]');
    var lastFocus = null;

    function setOpen(open) {
        if (!drawer) { return; }
        drawer.setAttribute('data-open', open ? 'true' : 'false');
        document.body.setAttribute('data-lm-drawer', open ? 'true' : 'false');
        for (var i = 0; i < openers.length; i++) {
            openers[i].setAttribute('aria-expanded', open ? 'true' : 'false');
        }
        if (open) {
            lastFocus = document.activeElement;
            var first = drawer.querySelector('a, button');
            if (first) { setTimeout(function () { first.focus(); }, 30); }
        } else if (lastFocus && lastFocus.focus) {
            lastFocus.focus();
        }
    }

    for (var i = 0; i < openers.length; i++) {
        openers[i].addEventListener('click', function () {
            setOpen(drawer && drawer.getAttribute('data-open') !== 'true');
        });
    }
    for (var j = 0; j < closers.length; j++) {
        closers[j].addEventListener('click', function () { setOpen(false); });
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && drawer && drawer.getAttribute('data-open') === 'true') {
            setOpen(false);
        }
    });
})();
</script>

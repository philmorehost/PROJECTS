<?php
/**
 * Public + account site chrome — header, footer, mobile drawer, bottom tabs.
 *
 * Every visitor-facing page (index, order, api-docs, success, request, user/*)
 * is rendered through lm_public_page_start() / lm_public_page_end() so the
 * markup, the stylesheet and the navigation are defined exactly once. No page
 * carries its own <style> block any more: layout lives in assets/site.css and
 * behaviour in assets/site.js.
 *
 * Links are written through lm_public_url(), which resolves relative to the LM
 * root, so the same chrome works from /lm/ and from /lm/user/.
 *
 * @package LicenseManager
 */

require_once __DIR__ . '/lm_config.php';

if (!function_exists('lm_e')) {
    /**
     * Escape a value for HTML output.
     *
     * @param mixed $value
     * @return string
     */
    function lm_e($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('lm_public_depth')) {
    /**
     * How many folders below the LM root the current script lives in.
     *
     * @return int
     */
    function lm_public_depth()
    {
        static $depth = null;
        if ($depth !== null) {
            return $depth;
        }

        $depth = 0;
        $file = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
        $root = str_replace('\\', '/', lm_lm_dir());

        if ($file !== '' && $root !== '' && strpos($file, $root) === 0) {
            $sub = ltrim(substr($file, strlen($root)), '/');
            $dir = str_replace('\\', '/', dirname($sub));
            if ($dir !== '' && $dir !== '.' && $dir !== '/') {
                $depth = substr_count(trim($dir, '/'), '/') + 1;
            }
        } elseif (!empty($_SERVER['SCRIPT_NAME'])) {
            $dir = trim(str_replace('\\', '/', dirname((string) $_SERVER['SCRIPT_NAME'])), '/');
            if ($dir !== '' && strpos($dir, '/') === false) {
                // e.g. /lm/user/login.php → dirname is "lm/user"; one level.
                $depth = 1;
            }
        }

        return $depth;
    }
}

if (!function_exists('lm_public_url')) {
    /**
     * Link to a page relative to the LM root.
     *
     * @param string $path
     * @return string
     */
    function lm_public_url($path = '')
    {
        return str_repeat('../', lm_public_depth()) . ltrim((string) $path, '/');
    }
}

if (!function_exists('lm_asset')) {
    /**
     * Cache-busted URL for a file in LM/assets.
     *
     * @param string $file
     * @return string
     */
    function lm_asset($file)
    {
        $file = ltrim((string) $file, '/');
        $abs = lm_lm_dir() . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file);
        $version = is_file($abs) ? (string) @filemtime($abs) : LM_VERSION;
        return lm_public_url('assets/' . $file) . '?v=' . rawurlencode($version);
    }
}

if (!function_exists('lm_public_user')) {
    /**
     * E-mail of the signed-in customer, or ''.
     *
     * @return string
     */
    function lm_public_user()
    {
        return isset($_SESSION['user_email']) ? (string) $_SESSION['user_email'] : '';
    }
}

if (!function_exists('lm_public_user_name')) {
    /**
     * Best-effort display name for the signed-in customer.
     *
     * @return string
     */
    function lm_public_user_name()
    {
        $name = trim((string) ($_SESSION['user_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        $email = lm_public_user();
        if ($email === '') {
            return '';
        }

        $local = strstr($email, '@', true);
        if ($local === false || $local === '') {
            return $email;
        }

        return ucfirst(preg_replace('/[._-]+/', ' ', $local));
    }
}

if (!function_exists('lm_site_logo_url')) {
    /**
     * Absolute-ish URL of the uploaded logo, or '' when none is set.
     *
     * @return string
     */
    function lm_site_logo_url()
    {
        $settings = function_exists('lm_settings') ? lm_settings() : array();
        $logo = trim((string) ($settings['site_logo'] ?? ''));
        if ($logo === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $logo)) {
            return $logo;
        }

        return lm_public_url($logo);
    }
}

if (!function_exists('lm_icon')) {
    /**
     * Inline icon. 24×24, stroke-based, inherits currentColor.
     *
     * @param string $name
     * @param string $class
     * @return string
     */
    function lm_icon($name, $class = '')
    {
        $paths = array(
            'home'      => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5.5 9.5V21h13V9.5"/>',
            'grid'      => '<rect x="3.5" y="3.5" width="7" height="7" rx="2"/><rect x="13.5" y="3.5" width="7" height="7" rx="2"/><rect x="3.5" y="13.5" width="7" height="7" rx="2"/><rect x="13.5" y="13.5" width="7" height="7" rx="2"/>',
            'rocket'    => '<path d="M12 15.5 8.5 12c1.2-4.2 4.4-7.3 8.6-8.5 1.2-.3 2 .5 1.7 1.7-1.2 4.2-4.3 7.4-8.5 8.6Z"/><path d="M8.5 12 5 12.5 2.5 15.5l4 .5"/><path d="M12 15.5l-.5 3.5-3 2.5-.5-4"/><circle cx="15.5" cy="8.5" r="1.4"/>',
            'book'      => '<path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v15H6.5A2.5 2.5 0 0 0 4 20.5Z"/><path d="M4 5.5v15"/><path d="M8 7h8M8 11h6"/>',
            'user'      => '<circle cx="12" cy="8" r="3.6"/><path d="M4.5 20.5c1.1-3.6 4-5.6 7.5-5.6s6.4 2 7.5 5.6"/>',
            'users'     => '<circle cx="9" cy="8" r="3.3"/><path d="M2.8 20c.9-3.3 3.3-5.2 6.2-5.2s5.3 1.9 6.2 5.2"/><path d="M16 5.2a3.3 3.3 0 0 1 0 6.4"/><path d="M17.4 14.5c2 .6 3.4 2.4 3.9 5.1"/>',
            'shield'    => '<path d="M12 3 5 6v5.5c0 4.4 2.9 7.8 7 9.5 4.1-1.7 7-5.1 7-9.5V6Z"/><path d="m9 12 2.2 2.2L15.5 10"/>',
            'key'       => '<circle cx="8" cy="15" r="4"/><path d="m11 12 8-8"/><path d="m16.5 4.5 3 3"/><path d="m14 7 3 3"/>',
            'bolt'      => '<path d="M13 2 4.5 13.5H11L10 22l8.5-11.5H12Z"/>',
            'check'     => '<path d="m4.5 12.5 5 5 10-11"/>',
            'arrow'     => '<path d="M5 12h14"/><path d="m13 6 6 6-6 6"/>',
            'arrow-left' => '<path d="M19 12H5"/><path d="m11 18-6-6 6-6"/>',
            'download'  => '<path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M4.5 20.5h15"/>',
            'lock'      => '<rect x="4" y="10" width="16" height="10.5" rx="2.5"/><path d="M8 10V7.5a4 4 0 0 1 8 0V10"/>',
            'bell'      => '<path d="M18 15.5V11a6 6 0 1 0-12 0v4.5L4 18h16Z"/><path d="M9.5 21h5"/>',
            'mail'      => '<rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="m4 7 8 6 8-6"/>',
            'clock'     => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
            'copy'      => '<rect x="9" y="9" width="11" height="11" rx="2.5"/><path d="M15 5.5A2.5 2.5 0 0 0 12.5 3H6.5A2.5 2.5 0 0 0 4 5.5v6A2.5 2.5 0 0 0 6.5 14"/>',
            'external'  => '<path d="M14 4h6v6"/><path d="M20 4 11 13"/><path d="M18.5 14.5V18a2.5 2.5 0 0 1-2.5 2.5H6A2.5 2.5 0 0 1 3.5 18V7.5A2.5 2.5 0 0 1 6 5h3.5"/>',
            'refresh'   => '<path d="M20 11a8 8 0 1 0-2.4 6.1"/><path d="M20 4.5V11h-6.2"/>',
            'alert'     => '<path d="M12 3.5 21 20H3Z"/><path d="M12 9.5v4.5"/><path d="M12 17.2h.01"/>',
            'question'  => '<circle cx="12" cy="12" r="8.6"/><path d="M9.6 9.4a2.5 2.5 0 1 1 3.6 2.3c-.7.4-1.2.9-1.2 1.8v.4"/><path d="M12 17.3h.01"/>',
            'plug'      => '<path d="M9 3v5"/><path d="M15 3v5"/><path d="M6 8h12v3.5a6 6 0 0 1-12 0Z"/><path d="M12 17.5V21"/>',
            'wallet'    => '<rect x="3" y="6" width="18" height="13" rx="3"/><path d="M3 10h18"/><path d="M16.5 14.5h1.5"/>',
            'receipt'   => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2Z"/><path d="M9 8h6M9 12h6"/>',
            'chart'     => '<path d="M4 20V4"/><path d="M4 20h16"/><path d="M8 16v-5"/><path d="M13 16V7"/><path d="M18 16v-8"/>',
            'terminal'  => '<rect x="3" y="4" width="18" height="16" rx="3"/><path d="m7.5 9 3 3-3 3"/><path d="M13 15h4"/>',
            'globe'     => '<circle cx="12" cy="12" r="8.6"/><path d="M3.6 9.5h16.8M3.6 14.5h16.8"/><path d="M12 3.4c2.3 2.3 3.4 5.2 3.4 8.6S14.3 18.3 12 20.6C9.7 18.3 8.6 15.4 8.6 12S9.7 5.7 12 3.4Z"/>',
            'code'      => '<path d="m8.5 7-5 5 5 5"/><path d="m15.5 7 5 5-5 5"/><path d="m13.5 4-3 16"/>',
            'menu'      => '<path d="M4 7h16"/><path d="M4 12h16"/><path d="M4 17h16"/>',
            'close'     => '<path d="m6 6 12 12"/><path d="m18 6-12 12"/>',
            'logout'    => '<path d="M15 4.5h3A2.5 2.5 0 0 1 20.5 7v10a2.5 2.5 0 0 1-2.5 2.5h-3"/><path d="M10 8 6 12l4 4"/><path d="M6 12h8"/>',
            'plus'      => '<path d="M12 5v14"/><path d="M5 12h14"/>',
            'search'    => '<circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5"/>',
            'settings'  => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-2.9 1.2 2 2 0 1 1-4 0 1.7 1.7 0 0 0-2.9-1.2l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1A1.7 1.7 0 0 0 3 15a2 2 0 1 1 0-6 1.7 1.7 0 0 0 1.4-2.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1A1.7 1.7 0 0 0 10 2a2 2 0 1 1 4 0 1.7 1.7 0 0 0 2.9 1.4l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1A1.7 1.7 0 0 0 21 9a2 2 0 1 1 0 6Z"/>',
            'spark'     => '<path d="M12 3.5 13.8 9l5.7 1.8-5.7 1.8L12 18.2 10.2 12.6 4.5 10.8 10.2 9Z"/><path d="M18.5 16.5 19 18l1.5.5L19 19l-.5 1.5L18 19l-1.5-.5L18 18Z"/>',
            'layers'    => '<path d="m12 3 9 4.5-9 4.5L3 7.5Z"/><path d="m3 12 9 4.5 9-4.5"/><path d="m3 16.5 9 4.5 9-4.5"/>',
        );

        $body = isset($paths[$name]) ? $paths[$name] : $paths['grid'];
        $classAttr = $class !== '' ? ' class="' . lm_e($class) . '"' : '';

        return '<svg' . $classAttr . ' viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"'
            . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
            . $body . '</svg>';
    }
}

if (!function_exists('lm_alert')) {
    /**
     * A dismissible-free alert block, matching .lm-alert in site.css.
     *
     * @param string $type    error|ok|info
     * @param string $message
     * @return void
     */
    function lm_alert($type, $message)
    {
        if (trim((string) $message) === '') {
            return;
        }

        $map = array('error' => '', 'ok' => ' lm-alert--ok', 'info' => ' lm-alert--info');
        $icon = array('error' => 'alert', 'ok' => 'check', 'info' => 'question');
        $type = isset($map[$type]) ? $type : 'error';
        ?>
        <div class="lm-alert<?= $map[$type] ?>" role="<?= $type === 'error' ? 'alert' : 'status' ?>">
            <?= lm_icon($icon[$type]) ?>
            <span><?= lm_e($message) ?></span>
        </div>
        <?php
    }
}

if (!function_exists('lm_public_nav')) {
    /**
     * Primary public links, shared by the desktop bar, the drawer and the tabs.
     *
     * @return array
     */
    function lm_public_nav()
    {
        $signedIn = lm_public_user() !== '';

        return array(
            'home'    => array('label' => 'Home',      'icon' => 'home',  'href' => 'index.php'),
            'order'   => array('label' => 'Licences',  'icon' => 'key',   'href' => 'order.php'),
            'docs'    => array('label' => 'API docs',  'icon' => 'book',  'href' => 'api-docs.php'),
            'account' => array(
                'label' => $signedIn ? 'My account' : 'Sign in',
                'icon'  => 'user',
                'href'  => $signedIn ? 'user/index.php' : 'user/login.php',
            ),
            'admin'   => array('label' => 'Admin', 'icon' => 'shield', 'href' => 'admin/index.php'),
        );
    }
}

if (!function_exists('lm_account_nav')) {
    /**
     * Navigation inside the customer area.
     *
     * @return array
     */
    function lm_account_nav()
    {
        return array(
            'dashboard' => array('label' => 'Overview',   'icon' => 'grid',     'href' => 'user/index.php'),
            'licences'  => array('label' => 'My licences', 'icon' => 'key',      'href' => 'user/index.php#licences'),
            'requests'  => array('label' => 'Requests',   'icon' => 'clock',    'href' => 'user/index.php#requests'),
            'docs'      => array('label' => 'API docs',   'icon' => 'book',     'href' => 'api-docs.php'),
            'profile'   => array('label' => 'Profile',    'icon' => 'user',     'href' => 'user/profile.php'),
        );
    }
}

if (!function_exists('lm_public_page_start')) {
    /**
     * Open an HTML document with the shared public (or account) chrome.
     *
     * @param string $active  Key from lm_public_nav() (or lm_account_nav()).
     * @param array  $options title, subtitle, body_class, account, no_tabbar,
     *                        no_footer, bare
     * @return void
     */
    function lm_public_page_start($active = '', array $options = array())
    {
        $siteName = function_exists('lm_site_name') ? lm_site_name() : 'License Manager';
        $title = isset($options['title']) ? (string) $options['title'] : '';
        $bodyClass = isset($options['body_class']) ? (string) $options['body_class'] : '';
        $active = (string) $active;
        $account = !empty($options['account']);
        $bare = !empty($options['bare']);

        $docTitle = $title !== '' ? $title . ' — ' . $siteName : $siteName;
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#0b1220">
    <meta name="description" content="Licence keys, automatic script updates and customer notifications from one console.">
    <title><?= lm_e($docTitle) ?></title>
    <?php lm_public_assets(); ?>
</head>
<body class="<?= lm_e(trim(($account ? 'lm-body-account ' : '') . $bodyClass)) ?>" data-lm-page="<?= lm_e($active) ?>">
        <?php
        // The landing page paints its own hero, so it opts out of the header.
        if (!$bare) {
            lm_public_header($active, array(
                'subtitle' => isset($options['subtitle']) ? (string) $options['subtitle'] : '',
                'account'  => $account,
            ));
        }
    }
}

if (!function_exists('lm_public_page_end')) {
    /**
     * Close the document opened by lm_public_page_start().
     *
     * @param array $options no_footer, no_tabbar (same keys as start)
     * @return void
     */
    function lm_public_page_end(array $options = array())
    {
        if (empty($options['no_footer'])) {
            lm_public_footer();
        }

        if (empty($options['no_tabbar'])) {
            lm_public_tabbar(isset($options['active']) ? (string) $options['active'] : '');
        }
        ?>
        <div class="lm-toast" id="lm-toast" role="status" aria-live="polite"></div>
</body>
</html>
        <?php
    }
}

if (!function_exists('lm_public_header')) {
    /**
     * Site header: brand, desktop links, CTA, hamburger, and the drawer.
     *
     * @param string $active  Key from lm_public_nav() to highlight.
     * @param array  $options 'subtitle' => string, 'account' => bool
     * @return void
     */
    function lm_public_header($active = '', array $options = array())
    {
        $siteName = function_exists('lm_site_name') ? lm_site_name() : 'License Manager';
        $nav = lm_public_nav();
        $signedIn = lm_public_user() !== '';
        $subtitle = isset($options['subtitle']) && $options['subtitle'] !== ''
            ? (string) $options['subtitle']
            : 'Licensing platform';
        $logo = lm_site_logo_url();
        ?>
<a class="lm-skip" href="#main">Skip to content</a>
<noscript>
    <style>
        @media (max-width: 940px) {
            .lm-menu-btn { display: none !important; }
            .lm-nav { display: flex !important; flex-wrap: wrap; width: 100%; margin: 0 0 10px; }
            .lm-header__inner { flex-wrap: wrap; }
        }
    </style>
</noscript>
<header class="lm-header">
    <div class="lm-wrap lm-header__inner">
        <a class="lm-brand" href="<?= lm_e(lm_public_url('index.php')) ?>">
            <?php if ($logo !== ''): ?>
                <img class="lm-brand__logo" src="<?= lm_e($logo) ?>" alt="">
            <?php else: ?>
                <span class="lm-brand__mark"><?= lm_icon('key') ?></span>
            <?php endif; ?>
            <span class="lm-brand__text"><?= lm_e($siteName) ?><small><?= lm_e($subtitle) ?></small></span>
        </a>

        <nav class="lm-nav" aria-label="Primary">
            <?php foreach ($nav as $key => $item): ?>
                <?php if ($key === 'admin' || $key === 'account') { continue; } ?>
                <a class="lm-navlink" href="<?= lm_e(lm_public_url($item['href'])) ?>"
                   <?= $key === $active ? 'aria-current="page"' : '' ?>><?= lm_e($item['label']) ?></a>
            <?php endforeach; ?>
            <?php if ($signedIn): ?>
                <a class="lm-navlink" href="<?= lm_e(lm_public_url('user/index.php')) ?>"
                   <?= $active === 'account' ? 'aria-current="page"' : '' ?>>My account</a>
            <?php endif; ?>
        </nav>

        <div class="lm-header__actions">
            <?php if ($signedIn): ?>
                <a class="lm-btn lm-btn--ghost lm-btn--sm" href="<?= lm_e(lm_public_url('api-docs.php')) ?>">
                    <?= lm_icon('book') ?> API docs</a>
                <a class="lm-btn lm-btn--sm" href="<?= lm_e(lm_public_url('order.php')) ?>">Get a licence</a>
            <?php else: ?>
                <a class="lm-btn lm-btn--ghost lm-btn--sm" href="<?= lm_e(lm_public_url('user/login.php')) ?>">Sign in</a>
                <a class="lm-btn lm-btn--sm" href="<?= lm_e(lm_public_url('order.php')) ?>">Get a licence</a>
            <?php endif; ?>
        </div>

        <button class="lm-menu-btn" type="button" data-drawer-open aria-controls="lm-drawer"
                aria-expanded="false" aria-label="Open menu"><span></span></button>
    </div>
</header>

<div class="lm-drawer" id="lm-drawer" data-drawer data-open="false">
    <button class="lm-drawer__backdrop" type="button" data-drawer-close aria-label="Close menu" tabindex="-1"></button>
    <div class="lm-drawer__panel" role="dialog" aria-modal="true" aria-label="Site menu">
        <div class="lm-drawer__head">
            <a class="lm-brand" href="<?= lm_e(lm_public_url('index.php')) ?>">
                <?php if ($logo !== ''): ?>
                    <img class="lm-brand__logo" src="<?= lm_e($logo) ?>" alt="">
                <?php else: ?>
                    <span class="lm-brand__mark"><?= lm_icon('key') ?></span>
                <?php endif; ?>
                <span class="lm-brand__text"><?= lm_e($siteName) ?></span>
            </a>
            <button class="lm-drawer__close" type="button" data-drawer-close aria-label="Close menu">&times;</button>
        </div>

        <?php foreach ($nav as $key => $item): ?>
            <a class="lm-drawer__link" href="<?= lm_e(lm_public_url($item['href'])) ?>"
               <?= $key === $active ? 'aria-current="page"' : '' ?>>
                <span class="lm-flex" style="gap:10px;flex-wrap:nowrap">
                    <?= lm_icon($item['icon'], 'lm-drawer__icon') ?>
                    <?= lm_e($item['label']) ?>
                </span>
                <?= lm_icon('arrow') ?>
            </a>
        <?php endforeach; ?>

        <?php if ($signedIn): ?>
            <a class="lm-drawer__link" href="<?= lm_e(lm_public_url('unsubscribe.php')) ?>">
                <span class="lm-flex" style="gap:10px;flex-wrap:nowrap">
                    <?= lm_icon('mail', 'lm-drawer__icon') ?> Email preferences
                </span>
                <?= lm_icon('arrow') ?>
            </a>
        <?php endif; ?>

        <div class="lm-drawer__foot">
            <?php if ($signedIn): ?>
                <a class="lm-btn lm-btn--ghost lm-btn--block" href="<?= lm_e(lm_public_url('user/logout.php')) ?>">Sign out</a>
            <?php else: ?>
                <a class="lm-btn lm-btn--ghost lm-btn--block" href="<?= lm_e(lm_public_url('user/login.php')) ?>">Sign in</a>
            <?php endif; ?>
            <a class="lm-btn lm-btn--block" href="<?= lm_e(lm_public_url('order.php')) ?>">Get a licence</a>
        </div>
    </div>
</div>
        <?php
    }
}

if (!function_exists('lm_public_footer')) {
    /**
     * Site footer. The mobile tab bar is separate so pages can cap their own
     * bottom padding where the tab bar is not wanted.
     *
     * @return void
     */
    function lm_public_footer()
    {
        $siteName = function_exists('lm_site_name') ? lm_site_name() : 'License Manager';
        $year = gmdate('Y');
        $logo = lm_site_logo_url();
        $signedIn = lm_public_user() !== '';
        ?>
<footer class="lm-footer">
    <div class="lm-wrap">
        <div class="lm-footer__grid">
            <div>
                <a class="lm-brand" href="<?= lm_e(lm_public_url('index.php')) ?>">
                    <?php if ($logo !== ''): ?>
                        <img class="lm-brand__logo" src="<?= lm_e($logo) ?>" alt="">
                    <?php else: ?>
                        <span class="lm-brand__mark"><?= lm_icon('key') ?></span>
                    <?php endif; ?>
                    <span class="lm-brand__text"><?= lm_e($siteName) ?></span>
                </a>
                <p class="lm-footer__about">
                    Licensing, automatic updates and customer notifications for the scripts you sell —
                    one console, no spreadsheets.
                </p>
            </div>
            <div>
                <h4>Product</h4>
                <ul>
                    <li><a href="<?= lm_e(lm_public_url('index.php#features')) ?>">Features</a></li>
                    <li><a href="<?= lm_e(lm_public_url('index.php#how')) ?>">How it works</a></li>
                    <li><a href="<?= lm_e(lm_public_url('order.php')) ?>">Get a licence</a></li>
                    <li><a href="<?= lm_e(lm_public_url('index.php#faq')) ?>">FAQ</a></li>
                </ul>
            </div>
            <div>
                <h4>Developers</h4>
                <ul>
                    <li><a href="<?= lm_e(lm_public_url('api-docs.php')) ?>">API reference</a></li>
                    <li><a href="<?= lm_e(lm_public_url('api-docs.php#endpoints')) ?>">Validate a licence</a></li>
                    <li><a href="<?= lm_e(lm_public_url('api-docs.php#examples')) ?>">Code examples</a></li>
                    <li><a href="<?= lm_e(lm_public_url('api-docs.php#errors')) ?>">Error codes</a></li>
                </ul>
            </div>
            <div>
                <h4>Account</h4>
                <ul>
                    <?php if ($signedIn): ?>
                        <li><a href="<?= lm_e(lm_public_url('user/index.php')) ?>">My licences</a></li>
                        <li><a href="<?= lm_e(lm_public_url('user/profile.php')) ?>">Profile</a></li>
                        <li><a href="<?= lm_e(lm_public_url('user/logout.php')) ?>">Sign out</a></li>
                    <?php else: ?>
                        <li><a href="<?= lm_e(lm_public_url('user/login.php')) ?>">Sign in</a></li>
                        <li><a href="<?= lm_e(lm_public_url('order.php')) ?>">Request a licence</a></li>
                    <?php endif; ?>
                    <li><a href="<?= lm_e(lm_public_url('unsubscribe.php')) ?>">Email preferences</a></li>
                </ul>
            </div>
        </div>
        <div class="lm-footer__bottom">
            <span>&copy; <?= $year ?> <?= lm_e($siteName) ?>. All rights reserved.</span>
            <span class="lm-footer__meta">
                <?= lm_icon('shield') ?> Licence keys are verified server-side.
                <a href="<?= lm_e(lm_public_url('admin/index.php')) ?>">Admin console</a>
                <a href="<?= lm_e(lm_public_url('health.php')) ?>">Installation check</a>
            </span>
        </div>
    </div>
</footer>
        <?php
    }
}

if (!function_exists('lm_public_tabbar')) {
    /**
     * Fixed bottom navigation — the "footer menu" on phones and small tablets.
     *
     * @param string $active
     * @return void
     */
    function lm_public_tabbar($active = '')
    {
        $nav = lm_public_nav();
        ?>
<nav class="lm-tabbar" aria-label="Quick menu">
    <?php foreach ($nav as $key => $item): ?>
        <?php if ($key === 'admin') { continue; } ?>
        <a class="lm-tab" href="<?= lm_e(lm_public_url($item['href'])) ?>"
           <?= $key === $active ? 'aria-current="page"' : '' ?>>
            <?= lm_icon($item['icon']) ?>
            <span><?= lm_e($item['label']) ?></span>
        </a>
    <?php endforeach; ?>
    <button class="lm-tab" type="button" data-drawer-open aria-controls="lm-drawer" aria-expanded="false">
        <?= lm_icon('menu') ?>
        <span>Menu</span>
    </button>
</nav>
        <?php
    }
}

if (!function_exists('lm_public_assets')) {
    /**
     * <link> and <script> tags printed in <head>.
     *
     * @return void
     */
    function lm_public_assets()
    {
        ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= lm_e(lm_asset('site.css')) ?>">
    <script src="<?= lm_e(lm_asset('site.js')) ?>" defer></script>
        <?php
    }
}

if (!function_exists('lm_copy_button')) {
    /**
     * A copy-to-clipboard button (site.js wires [data-lm-copy]).
     *
     * @param string $value Text to copy
     * @param string $label
     * @param bool   $light Light-on-dark variant
     * @return string
     */
    function lm_copy_button($value, $label = 'Copy', $light = false)
    {
        return '<button class="lm-copybtn' . ($light ? '' : ' lm-copybtn--light') . '" type="button"'
            . ' data-lm-copy="' . lm_e($value) . '">' . lm_icon('copy')
            . '<span>' . lm_e($label) . '</span></button>';
    }
}

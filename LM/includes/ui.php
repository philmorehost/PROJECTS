<?php
/**
 * Public site chrome — header, footer, mobile drawer and the bottom tab bar.
 *
 * Included by the visitor-facing pages (index, order, request_license,
 * user/login, success, unsubscribe, api-docs). It only renders markup; the
 * layout lives in assets/site.css and the behaviour in assets/site.js.
 *
 * Every link is written through lm_public_url() so the same file works from
 * /lm/ and from /lm/user/ without hard-coded relative paths.
 *
 * @package LicenseManager
 */

require_once __DIR__ . '/lm_config.php';

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

        if ($file !== '' && strpos($file, $root) === 0) {
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
            'home'     => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5.5 9.5V21h13V9.5"/>',
            'rocket'   => '<path d="M12 15.5 8.5 12c1.2-4.2 4.4-7.3 8.6-8.5 1.2-.3 2 .5 1.7 1.7-1.2 4.2-4.3 7.4-8.5 8.6Z"/><path d="M8.5 12 5 12.5 2.5 15.5l4 .5"/><path d="M12 15.5l-.5 3.5-3 2.5-.5-4"/><circle cx="15.5" cy="8.5" r="1.4"/>',
            'book'     => '<path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v15H6.5A2.5 2.5 0 0 0 4 20.5Z"/><path d="M4 5.5v15"/><path d="M8 7h8M8 11h6"/>',
            'user'     => '<circle cx="12" cy="8" r="3.6"/><path d="M4.5 20.5c1.1-3.6 4-5.6 7.5-5.6s6.4 2 7.5 5.6"/>',
            'shield'   => '<path d="M12 3 5 6v5.5c0 4.4 2.9 7.8 7 9.5 4.1-1.7 7-5.1 7-9.5V6Z"/><path d="m9 12 2.2 2.2L15.5 10"/>',
            'grid'     => '<rect x="3.5" y="3.5" width="7" height="7" rx="2"/><rect x="13.5" y="3.5" width="7" height="7" rx="2"/><rect x="3.5" y="13.5" width="7" height="7" rx="2"/><rect x="13.5" y="13.5" width="7" height="7" rx="2"/>',
            'key'      => '<circle cx="8" cy="15" r="4"/><path d="m11 12 8-8"/><path d="m16.5 4.5 3 3"/><path d="m14 7 3 3"/>',
            'bolt'     => '<path d="M13 2 4.5 13.5H11L10 22l8.5-11.5H12Z"/>',
            'check'    => '<path d="m4.5 12.5 5 5 10-11"/>',
            'arrow'    => '<path d="M5 12h14"/><path d="m13 6 6 6-6 6"/>',
            'download' => '<path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M4.5 20.5h15"/>',
            'lock'     => '<rect x="4" y="10" width="16" height="10.5" rx="2.5"/><path d="M8 10V7.5a4 4 0 0 1 8 0V10"/>',
            'bell'     => '<path d="M18 15.5V11a6 6 0 1 0-12 0v4.5L4 18h16Z"/><path d="M9.5 21h5"/>',
            'mail'     => '<rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="m4 7 8 6 8-6"/>',
            'clock'    => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
        );

        $body = isset($paths[$name]) ? $paths[$name] : $paths['grid'];
        $classAttr = $class !== '' ? ' class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '"' : '';

        return '<svg' . $classAttr . ' viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"'
            . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
            . $body . '</svg>';
    }
}

if (!function_exists('lm_public_nav')) {
    /**
     * The primary links, shared by the desktop bar, the drawer and the tabs.
     *
     * @return array
     */
    function lm_public_nav()
    {
        $signedIn = lm_public_user() !== '';

        return array(
            'home'    => array('label' => 'Home',     'icon' => 'home',  'href' => 'index.php'),
            'order'   => array('label' => 'Licences', 'icon' => 'key',   'href' => 'order.php'),
            'docs'    => array('label' => 'API docs', 'icon' => 'book',  'href' => 'api-docs.php'),
            'account' => array(
                'label' => $signedIn ? 'My account' : 'Sign in',
                'icon'  => 'user',
                'href'  => $signedIn ? 'user/index.php' : 'user/login.php',
            ),
            'admin'   => array('label' => 'Admin',    'icon' => 'shield', 'href' => 'admin/index.php'),
        );
    }
}

if (!function_exists('lm_public_header')) {
    /**
     * Site header: brand, desktop links, CTA, hamburger.
     *
     * @param string $active  Key from lm_public_nav() to highlight.
     * @param array  $options 'subtitle' => string
     * @return void
     */
    function lm_public_header($active = '', array $options = array())
    {
        $siteName = function_exists('lm_site_name') ? lm_site_name() : 'License Manager';
        $nav = lm_public_nav();
        $signedIn = lm_public_user() !== '';
        $subtitle = isset($options['subtitle']) ? (string) $options['subtitle'] : 'Licence Manager';
        ?>
<a class="lm-skip" href="#main">Skip to content</a>
<header class="lm-header">
    <div class="lm-wrap lm-header__inner">
        <a class="lm-brand" href="<?= htmlspecialchars(lm_public_url('index.php')) ?>">
            <span class="lm-brand__mark"><?= lm_icon('key') ?></span>
            <span class="lm-brand__text"><?= htmlspecialchars($siteName) ?><small><?= htmlspecialchars($subtitle) ?></small></span>
        </a>

        <nav class="lm-nav" aria-label="Primary">
            <?php foreach ($nav as $key => $item): ?>
                <?php if ($key === 'admin') { continue; } ?>
                <a class="lm-navlink" href="<?= htmlspecialchars(lm_public_url($item['href'])) ?>"
                   <?= $key === $active ? 'aria-current="page"' : '' ?>><?= htmlspecialchars($item['label']) ?></a>
            <?php endforeach; ?>
        </nav>

        <div class="lm-header__actions">
            <?php if ($signedIn): ?>
                <a class="lm-btn lm-btn--ghost lm-btn--sm" href="<?= htmlspecialchars(lm_public_url('user/index.php')) ?>">My account</a>
            <?php else: ?>
                <a class="lm-btn lm-btn--ghost lm-btn--sm" href="<?= htmlspecialchars(lm_public_url('user/login.php')) ?>">Sign in</a>
            <?php endif; ?>
            <a class="lm-btn lm-btn--sm" href="<?= htmlspecialchars(lm_public_url('order.php')) ?>">Get a licence</a>
        </div>

        <button class="lm-menu-btn" type="button" data-drawer-open aria-controls="lm-drawer"
                aria-expanded="false" aria-label="Open menu"><span></span></button>
    </div>
</header>

<div class="lm-drawer" id="lm-drawer" data-drawer data-open="false">
    <button class="lm-drawer__backdrop" type="button" data-drawer-close aria-label="Close menu" tabindex="-1"></button>
    <div class="lm-drawer__panel" role="dialog" aria-modal="true" aria-label="Site menu">
        <div class="lm-drawer__head">
            <a class="lm-brand" href="<?= htmlspecialchars(lm_public_url('index.php')) ?>">
                <span class="lm-brand__mark"><?= lm_icon('key') ?></span>
                <span class="lm-brand__text"><?= htmlspecialchars($siteName) ?></span>
            </a>
            <button class="lm-drawer__close" type="button" data-drawer-close aria-label="Close menu">&times;</button>
        </div>

        <?php foreach ($nav as $key => $item): ?>
            <a class="lm-drawer__link" href="<?= htmlspecialchars(lm_public_url($item['href'])) ?>"
               <?= $key === $active ? 'aria-current="page"' : '' ?>>
                <span class="lm-flex" style="gap:10px;flex-wrap:nowrap">
                    <?= lm_icon($item['icon'], 'lm-drawer__icon') ?>
                    <?= htmlspecialchars($item['label']) ?>
                </span>
                <?= lm_icon('arrow') ?>
            </a>
        <?php endforeach; ?>

        <div class="lm-drawer__foot">
            <?php if (!$signedIn): ?>
                <a class="lm-btn lm-btn--ghost lm-btn--block" href="<?= htmlspecialchars(lm_public_url('user/login.php')) ?>">Sign in</a>
            <?php endif; ?>
            <a class="lm-btn lm-btn--block" href="<?= htmlspecialchars(lm_public_url('order.php')) ?>">Get a licence</a>
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
        ?>
<footer class="lm-footer">
    <div class="lm-wrap">
        <div class="lm-footer__grid">
            <div>
                <a class="lm-brand" href="<?= htmlspecialchars(lm_public_url('index.php')) ?>">
                    <span class="lm-brand__mark"><?= lm_icon('key') ?></span>
                    <span class="lm-brand__text"><?= htmlspecialchars($siteName) ?></span>
                </a>
                <p class="lm-footer__about">
                    Licensing, automatic updates and customer notifications for the scripts you sell —
                    one console, no spreadsheets.
                </p>
            </div>
            <div>
                <h4>Product</h4>
                <ul>
                    <li><a href="<?= htmlspecialchars(lm_public_url('index.php#features')) ?>">Features</a></li>
                    <li><a href="<?= htmlspecialchars(lm_public_url('index.php#how')) ?>">How it works</a></li>
                    <li><a href="<?= htmlspecialchars(lm_public_url('order.php')) ?>">Get a licence</a></li>
                </ul>
            </div>
            <div>
                <h4>Developers</h4>
                <ul>
                    <li><a href="<?= htmlspecialchars(lm_public_url('api-docs.php')) ?>">API reference</a></li>
                    <li><a href="<?= htmlspecialchars(lm_public_url('api-docs.php#check')) ?>">Update check</a></li>
                    <li><a href="<?= htmlspecialchars(lm_public_url('api-docs.php#errors')) ?>">Error codes</a></li>
                </ul>
            </div>
            <div>
                <h4>Account</h4>
                <ul>
                    <li><a href="<?= htmlspecialchars(lm_public_url('user/login.php')) ?>">Sign in</a></li>
                    <li><a href="<?= htmlspecialchars(lm_public_url('admin/index.php')) ?>">Admin console</a></li>
                    <li><a href="<?= htmlspecialchars(lm_public_url('unsubscribe.php')) ?>">Email preferences</a></li>
                </ul>
            </div>
        </div>
        <div class="lm-footer__bottom">
            <span>&copy; <?= $year ?> <?= htmlspecialchars($siteName) ?>. All rights reserved.</span>
            <span><?= lm_icon('shield') ?> Licence keys are verified server-side.</span>
        </div>
    </div>
</footer>
        <?php
    }
}

if (!function_exists('lm_public_tabbar')) {
    /**
     * Fixed bottom navigation — the "footer menu" shown on phones and small
     * tablets. Five destinations, 44px targets, safe-area aware.
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
        <a class="lm-tab" href="<?= htmlspecialchars(lm_public_url($item['href'])) ?>"
           <?= $key === $active ? 'aria-current="page"' : '' ?>>
            <?= lm_icon($item['icon']) ?>
            <span><?= htmlspecialchars($item['label']) ?></span>
        </a>
    <?php endforeach; ?>
</nav>
        <?php
    }
}

if (!function_exists('lm_public_assets')) {
    /**
     * <link> and <script> tags. Printed in <head> so the CSS is not blocked
     * behind the whole body.
     *
     * @return void
     */
    function lm_public_assets()
    {
        ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars(lm_asset('site.css')) ?>">
    <script src="<?= htmlspecialchars(lm_asset('site.js')) ?>" defer></script>
        <?php
    }
}

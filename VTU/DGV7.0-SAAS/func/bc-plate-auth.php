<?php
/**
 * Plate auth shell — one shared layout for the unauthenticated and security-flow pages.
 *
 * Login, register, password recovery, OTP, PIN and the account-state pages (inactive,
 * suspended, locked out, error) all used to load the heavyweight `assets-2` template and
 * a photographic background. They now share this shell, which carries the same design
 * tokens as the landing page (template 6) so the signed-out journey looks like one product.
 *
 * The shell is presentation only. It moves no money, reads no credential and decides no
 * entitlement — every page keeps its own PHP logic verbatim and calls these helpers around
 * its existing markup.
 *
 * Helpers:
 *   bc_plate_auth_head($title)   — the <head> block (meta, fonts, design-system CSS)
 *   bc_plate_auth_top($opts)     — brand bar + centred shell, returns nothing
 *   bc_plate_auth_end($opts)     — closes the shell and prints the footer
 *
 * Everything is defensive: a helper that cannot resolve a value falls back rather than
 * emitting an empty attribute, and the brand colour is validated before it reaches CSS.
 */

if (!function_exists('bc_plate_auth_colour')) {
    /**
     * Tenant brand colour, validated. A bad stored value must not be able to inject CSS.
     */
    function bc_plate_auth_colour($site_details = null) {
        $details = is_array($site_details) ? $site_details : array();
        $is_spadmin = strpos((string)($_SERVER['PHP_SELF'] ?? ''), '/bc-spadmin/') !== false;

        if ($is_spadmin) {
            if (!empty($GLOBALS['spadmin_primary_color']) && preg_match('/^#[0-9a-fA-F]{6}$/', (string)$GLOBALS['spadmin_primary_color'])) {
                return (string)$GLOBALS['spadmin_primary_color'];
            }
        } else {
            if (!empty($GLOBALS['vendor_primary_color']) && preg_match('/^#[0-9a-fA-F]{6}$/', (string)$GLOBALS['vendor_primary_color'])) {
                return (string)$GLOBALS['vendor_primary_color'];
            }
        }

        if (!empty($details['primary_color']) && preg_match('/^#[0-9a-fA-F]{6}$/', (string)$details['primary_color'])) {
            return (string)$details['primary_color'];
        }

        $db = $GLOBALS['connection_server'] ?? null;
        if ($db instanceof mysqli) {
            if ($is_spadmin) {
                $q = @mysqli_query($db, "SELECT primary_color FROM sas_spadmin_style_templates LIMIT 1");
                if ($q && ($r = mysqli_fetch_assoc($q)) && !empty($r['primary_color']) && preg_match('/^#[0-9a-fA-F]{6}$/', (string)$r['primary_color'])) {
                    return (string)$r['primary_color'];
                }
            } else {
                $vid = (int)($GLOBALS['select_vendor_table']['id'] ?? 0);
                if ($vid <= 0 && function_exists('resolveVendorID')) {
                    $vid = (int)resolveVendorID();
                }
                if ($vid > 0) {
                    $q = @mysqli_query($db, "SELECT primary_color FROM sas_vendor_style_templates WHERE vendor_id='$vid' LIMIT 1");
                    if ($q && ($r = mysqli_fetch_assoc($q)) && !empty($r['primary_color']) && preg_match('/^#[0-9a-fA-F]{6}$/', (string)$r['primary_color'])) {
                        return (string)$r['primary_color'];
                    }
                }
            }
        }
        return '#0f766e';
    }
}

if (!function_exists('bc_plate_auth_site')) {
    /**
     * Resolve the tenant identity for the shell: title, logo path and support email.
     * Reads the same globals the older templates used, and degrades quietly.
     */
    function bc_plate_auth_site() {
        $details = array();
        if (isset($GLOBALS['get_all_site_details']) && is_array($GLOBALS['get_all_site_details'])) {
            $details = $GLOBALS['get_all_site_details'];
        }

        // §46: never invent a brand name — fall back to this site's own host.
        $title = (string)($_SERVER['HTTP_HOST'] ?? 'Admin Panel');
        if (!empty($details['site_title'])) {
            $title = (string)$details['site_title'];
        } elseif (!empty($GLOBALS['site_title'])) {
            $title = (string)$GLOBALS['site_title'];
        }

        // Logo file naming is the same convention the older templates use:
        // uploaded-image/{HTTP_HOST with . and : as -}_logo.png.
        $logo = '';
        $host = isset($_SERVER['HTTP_HOST']) ? str_replace(array('.', ':'), '-', (string)$_SERVER['HTTP_HOST']) : 'site';
        $file = 'uploaded-image/' . $host . '_logo.png';
        if (file_exists(__DIR__ . '/../' . $file)) {
            $logo = $file;
        }

        $support = '';
        if (!empty($details['support_email'])) {
            $support = (string)$details['support_email'];
        } elseif (!empty($GLOBALS['select_vendor_table']['email'])) {
            $support = (string)$GLOBALS['select_vendor_table']['email'];
        }

        return array(
            'title'   => $title,
            'logo'    => $logo,
            'support' => $support,
            'details' => $details,
        );
    }
}

if (!function_exists('bc_plate_auth_icon')) {
    /**
     * Inline SVG icon. The shell loads no icon font, so a `bi bi-*` class would render as
     * an empty box; the auth pages draw these instead. Names are shared with the landing.
     */
    function bc_plate_auth_icon($name, $size = 22) {
        $paths = array(
            'shield'  => '<path d="M12 2 4 6v6c0 5 3.5 9 8 10c4.5-1 8-5 8-10V6z"/>',
            'lock'    => '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
            'user'    => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
            'mail'    => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m2 7 10 6 10-6"/>',
            'eye'     => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/>',
            'check'   => '<path d="m20 6-11 11-5-5"/>',
            'alert'   => '<path d="M12 3 2 20h20z"/><path d="M12 10v4"/><path d="M12 17h.01"/>',
            'warn'    => '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
            'card'    => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
            'phone'   => '<rect x="6" y="2" width="12" height="20" rx="2"/><path d="M11 18h2"/>',
            'clock'   => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'key'     => '<circle cx="8" cy="15" r="4"/><path d="m11 12 9-9"/><path d="m16 7 3 3"/>',
            'fingerprint' => '<path d="M12 11a3 3 0 0 0-3 3c0 2 .5 4-1 6"/><path d="M12 11a3 3 0 0 1 3 3c0 2.5-.5 5-1 7"/><path d="M6.5 8.5A6 6 0 0 1 18 14c0 2.5-.3 4.5-1 6"/><path d="M4 12a8 8 0 0 1 16 0c0 3-.5 6-1.5 8"/>',
            'arrow'   => '<path d="M5 12h14"/><path d="m13 6 6 6-6 6"/>',
            'home'    => '<path d="m3 10 9-7 9 7v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/>',
            'bank'    => '<path d="M3 9 12 4l9 5"/><path d="M4 9v8"/><path d="M20 9v8"/><path d="M8 12v4"/><path d="M12 12v4"/><path d="M16 12v4"/><path d="M2 17h20"/>',
        );
        $body = isset($paths[$name]) ? $paths[$name] : $paths['check'];
        return '<svg width="' . (int)$size . '" height="' . (int)$size . '" viewBox="0 0 24 24" fill="none" '
            . 'stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" '
            . 'aria-hidden="true">' . $body . '</svg>';
    }
}

if (!function_exists('bc_plate_auth_css')) {
    /**
     * The shell stylesheet. Printed once by bc_plate_auth_head(); kept as a separate
     * function so the design tokens have exactly one definition.
     */
    function bc_plate_auth_css($brand) {
        require_once __DIR__ . '/bc-theme.php';
        $palette = bc_theme_palette($brand)['modes']['light'];
        $bc_brand_rgb = '15, 118, 110';
        if (is_string($brand) && preg_match('/^#([0-9a-fA-F]{2})([0-9a-fA-F]{2})([0-9a-fA-F]{2})$/', $brand, $bc_rgb_m)) {
            $bc_brand_rgb = hexdec($bc_rgb_m[1]) . ', ' . hexdec($bc_rgb_m[2]) . ', ' . hexdec($bc_rgb_m[3]);
        }
        ?>
<style>
        /* ── Plate auth shell ─────────────────────────────────────────── */
        :root {
            --brand: <?php echo $brand; ?>;
            --brand-rgb: <?php echo $bc_brand_rgb; ?>;
            --brand-fg: <?php echo $palette['on_primary']; ?>;
            --brand-ink: <?php echo $palette['primary_text']; ?>;
            --ink: #0b1220;
            --muted: #64748b;
            --line: #e2e8f0;
            --surface: #ffffff;
            --wash: #f6f8fb;
            --danger: #dc2626;
            --radius: 16px;
        }
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
        html { -webkit-text-size-adjust: 100%; }
        body {
            font-family: 'Inter', system-ui, -apple-system, "Segoe UI", sans-serif;
            color: var(--ink); background: var(--wash);
            min-height: 100vh; display: flex; flex-direction: column;
            line-height: 1.6; overflow-x: hidden;
        }
        /* A soft brand wash instead of the old photographic background. */
        .pk-auth__bg {
            position: fixed; inset: 0; z-index: -1; pointer-events: none;
            background:
                radial-gradient(700px 380px at 12% -8%, rgba(<?php echo $bc_brand_rgb; ?>, .12), transparent 70%),
                radial-gradient(700px 420px at 92% 8%, rgba(<?php echo $bc_brand_rgb; ?>, .10), transparent 70%),
                var(--wash);
        }
        .pk-auth__brand {
            display: flex; align-items: center; justify-content: space-between;
            gap: 16px; padding: 18px 24px; max-width: 1160px; width: 100%; margin: 0 auto;
        }
        .pk-auth__brandlink { display: inline-flex; align-items: center; gap: 10px; text-decoration: none; color: var(--ink); font-weight: 800; letter-spacing: -.02em; }
        .pk-auth__brandlink img { height: 36px; width: auto; display: block; }
        .pk-auth__mark {
            width: 36px; height: 36px; border-radius: 10px; background: var(--brand); color: var(--brand-fg);
            display: grid; place-items: center; font-weight: 800; font-size: .95rem;
        }
        .pk-auth__main { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 24px; width: 100%; }
        .pk-auth__card {
            width: 100%; max-width: 460px; background: var(--surface);
            border: 1px solid var(--line); border-radius: var(--radius);
            padding: 34px 30px; box-shadow: 0 18px 44px rgba(16, 24, 40, .08);
        }
        .pk-auth__card--wide { max-width: 560px; }
        .pk-auth__icon {
            width: 52px; height: 52px; border-radius: 14px; display: grid; place-items: center;
            background: rgba(<?php echo $bc_brand_rgb; ?>, .10); color: var(--brand-ink); margin-bottom: 18px;
        }
        .pk-auth__icon--danger { background: #fef2f2; color: var(--danger); }
        .pk-auth__icon--warn { background: #fffbeb; color: #d97706; }
        .pk-auth__title { font-size: 1.4rem; font-weight: 800; letter-spacing: -.02em; margin-bottom: 8px; }
        .pk-auth__sub { color: var(--muted); font-size: .95rem; margin-bottom: 24px; }
        .pk-auth__label { display: block; font-size: .76rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: var(--muted); margin-bottom: 7px; }
        .pk-auth__input, .pk-auth__select {
            width: 100%; font: inherit; font-size: .95rem; color: var(--ink);
            background: var(--wash); border: 1px solid var(--line); border-radius: 10px;
            padding: 12px 14px; outline: none; transition: border-color .15s ease, box-shadow .15s ease;
        }
        .pk-auth__input:focus, .pk-auth__select:focus {
            border-color: var(--brand-ink); box-shadow: 0 0 0 3px rgba(<?php echo $bc_brand_rgb; ?>, .14); background: #fff;
        }
        .pk-auth__field { margin-bottom: 18px; }
        .pk-auth__row { display: flex; gap: 12px; }
        .pk-auth__row > * { flex: 1; }
        .pk-auth__btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            width: 100%; font: inherit; font-size: .95rem; font-weight: 700; letter-spacing: .01em;
            color: var(--brand-fg); background: var(--brand); border: 1px solid transparent;
            border-radius: 10px; padding: 13px 18px; cursor: pointer; text-decoration: none;
            transition: opacity .15s ease;
        }
        .pk-auth__btn:hover { opacity: .9; color: var(--brand-fg); }
        .pk-auth__btn--ghost { background: transparent; color: var(--ink); border-color: var(--line); }
        .pk-auth__btn--ghost:hover { color: var(--ink); border-color: var(--ink); }
        .pk-auth__btn--danger { background: var(--danger); }
        .pk-auth__link { color: var(--brand-ink); font-weight: 600; text-decoration: none; font-size: .9rem; }
        .pk-auth__link:hover { text-decoration: underline; }
        .pk-auth__note { color: var(--muted); font-size: .88rem; text-align: center; }
        .pk-auth__alert {
            border-radius: 10px; padding: 12px 14px; font-size: .9rem; margin-bottom: 18px;
            border: 1px solid; display: flex; gap: 10px; align-items: flex-start;
        }
        .pk-auth__alert--error { background: #fef2f2; border-color: #fecaca; color: #991b1b; }
        .pk-auth__alert--ok { background: #ecfdf5; border-color: #a7f3d0; color: #065f46; }
        .pk-auth__alert--info { background: #eff6ff; border-color: #bfdbfe; color: #1e40af; }
        .pk-auth__divider { display: flex; align-items: center; gap: 12px; color: var(--muted); font-size: .8rem; margin: 20px 0; }
        .pk-auth__divider::before, .pk-auth__divider::after { content: ""; flex: 1; height: 1px; background: var(--line); }
        .pk-auth__foot { padding: 20px 24px; text-align: center; color: var(--muted); font-size: .82rem; }
        .pk-auth__foot a { color: var(--muted); }
        .pk-auth__otp {
            font-size: 1.6rem; font-weight: 800; text-align: center; letter-spacing: .5rem;
            font-variant-numeric: tabular-nums;
        }
        .pk-auth__otp::placeholder { letter-spacing: .3rem; color: #cbd5e1; }
        /* Bootstrap-era pages still print `.alert` / `.text-muted` / `.mb-*` utilities. */
        .pk-auth__card .alert { border-radius: 10px; border: 1px solid; font-size: .9rem; padding: 12px 14px; margin-bottom: 18px; }
        .pk-auth__card .alert-danger { background: #fef2f2; border-color: #fecaca; color: #991b1b; }
        .pk-auth__card .alert-success { background: #ecfdf5; border-color: #a7f3d0; color: #065f46; }
        .pk-auth__card .text-muted { color: var(--muted) !important; }
        .pk-auth__card .small { font-size: .85rem; }
        .pk-auth__card .fw-bold { font-weight: 700; }
        .pk-auth__card .text-center { text-align: center; }
        .pk-auth__card .d-grid { display: grid; }
        .pk-auth__card .gap-2 { gap: 10px; }
        @media (max-width: 560px) {
            .pk-auth__card { padding: 26px 20px; }
            .pk-auth__row { flex-direction: column; gap: 0; }
            .pk-auth__title { font-size: 1.2rem; }
        }
        @media (prefers-reduced-motion: reduce) {
            * { transition: none !important; }
        }
</style>
        <?php
    }
}

if (!function_exists('bc_plate_auth_head')) {
    /**
     * The <head> block. Call this in place of the old template stylesheet links.
     *
     * @param string $title      Page title (already escaped by the caller if it is dynamic).
     * @param mixed  $site_details Tenant row, or null to resolve from globals.
     */
    function bc_plate_auth_head($title, $site_details = null) {
        if (!is_array($site_details) && isset($GLOBALS['get_all_site_details']) && is_array($GLOBALS['get_all_site_details'])) {
            $site_details = $GLOBALS['get_all_site_details'];
        }
        $site  = bc_plate_auth_site();
        $brand = bc_plate_auth_colour(is_array($site_details) ? $site_details : null);
        $title = $title !== '' ? $title : $site['title'];
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="<?php echo $brand; ?>">
    <title><?php echo htmlspecialchars($title); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
        <?php bc_plate_auth_css($brand); ?>
</head>
        <?php
    }
}

if (!function_exists('bc_plate_auth_top')) {
    /**
     * Brand bar + the opening of the centred shell.
     *
     * $opts keys: 'wide' (bool) card modifier, 'icon' (string) icon name,
     *             'icon_style' ('danger'|'warn'), 'title', 'sub', 'alert' (array of
     *             array('type'=>'error|ok|info','body'=>...)).
     */
    function bc_plate_auth_top($opts = array()) {
        $site   = bc_plate_auth_site();
        $title  = isset($opts['title']) ? (string)$opts['title'] : '';
        $sub    = isset($opts['sub']) ? (string)$opts['sub'] : '';
        $icon   = isset($opts['icon']) ? (string)$opts['icon'] : 'shield';
        $istyle = isset($opts['icon_style']) ? (string)$opts['icon_style'] : '';
        $wide   = !empty($opts['wide']) ? ' pk-auth__card--wide' : '';
        ?>
<body>
<div class="pk-auth__bg" aria-hidden="true"></div>
<header class="pk-auth__brand">
    <a class="pk-auth__brandlink" href="<?php echo htmlspecialchars($GLOBALS['web_http_host'] ?? ''); ?>/index.php">
        <?php if ($site['logo']): ?>
            <img src="<?php echo htmlspecialchars(($GLOBALS['web_http_host'] ?? '') . '/' . $site['logo']); ?>" alt="<?php echo htmlspecialchars($site['title']); ?>">
        <?php else: ?>
            <span class="pk-auth__mark"><?php echo htmlspecialchars(strtoupper(substr($site['title'], 0, 1))); ?></span>
        <?php endif; ?>
        <span><?php echo htmlspecialchars($site['title']); ?></span>
    </a>
</header>
<main class="pk-auth__main">
    <div class="pk-auth__card<?php echo $wide; ?>">
        <?php if ($icon !== ''): ?>
        <div class="pk-auth__icon<?php echo $istyle ? ' pk-auth__icon--' . $istyle : ''; ?>"><?php echo bc_plate_auth_icon($icon); ?></div>
        <?php endif; ?>
        <?php if ($title !== ''): ?>
        <h1 class="pk-auth__title"><?php echo htmlspecialchars($title); ?></h1>
        <?php endif; ?>
        <?php if ($sub !== ''): ?>
        <p class="pk-auth__sub"><?php echo $sub; ?></p>
        <?php endif; ?>
        <?php if (!empty($opts['alert']) && is_array($opts['alert'])): ?>
            <?php foreach ($opts['alert'] as $a): ?>
                <?php $type = isset($a['type']) ? $a['type'] : 'error'; ?>
                <div class="pk-auth__alert pk-auth__alert--<?php echo htmlspecialchars($type); ?>">
                    <span><?php echo bc_plate_auth_icon($type === 'ok' ? 'check' : ($type === 'info' ? 'alert' : 'warn'), 18); ?></span>
                    <span><?php echo $a['body']; ?></span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php
    }
}

if (!function_exists('bc_plate_auth_end')) {
    /**
     * Closes the shell and prints the footer. $opts keys: 'links' (array of
     * array('href'=>..,'label'=>..)), 'note' (string).
     */
    function bc_plate_auth_end($opts = array()) {
        $site = bc_plate_auth_site();
        ?>
    </div>
</main>
<footer class="pk-auth__foot">
    <?php if (!empty($opts['links']) && is_array($opts['links'])): ?>
        <div style="margin-bottom:8px;">
            <?php foreach ($opts['links'] as $l): ?>
                <a href="<?php echo htmlspecialchars($l['href']); ?>" style="margin: 0 8px;"><?php echo htmlspecialchars($l['label']); ?></a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($opts['note'])): ?>
        <div><?php echo $opts['note']; ?></div>
    <?php elseif ($site['support'] !== ''): ?>
        <div>Need help? <a href="mailto:<?php echo htmlspecialchars($site['support']); ?>"><?php echo htmlspecialchars($site['support']); ?></a></div>
    <?php endif; ?>
    <div style="margin-top:6px;">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($site['title']); ?></div>
</footer>
</body>
</html>
        <?php
    }
}

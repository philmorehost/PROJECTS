<?php
/**
 * DGV7 phase 2 — landing template 6 ("Plate").
 *
 * A light, typographically-led landing page in the Platnova idiom: one clear promise, a
 * Personal/Business switch, the three-step cross-border flow, then the service grid.
 *
 * Two constraints shape this file:
 *
 *   1. Both panels are rendered server-side and the switch only toggles visibility. With JS
 *      disabled the Personal panel stays visible and the Business markup is still present, so
 *      nothing is hidden behind a script.
 *   2. Every service card comes from bc_fintech_marketing_partition(), so a card cannot claim a
 *      service is available when the account gate would refuse it. Unavailable cards are shown
 *      dashed and marked "Coming soon" rather than dropped.
 */

include_once(__DIR__ . "/func/bc-seo.php");
require_once(__DIR__ . "/func/bc-fintech-accounts.php");

$site_title       = 'FintechFlow';
$site_logo        = '';
$header_image_url = '';
$site_details     = [];

if (isset($vendor_account_details) && is_array($vendor_account_details)) {
    if (isset($connection_server)) {
        $cacheKey = 'vendor_site_style_' . md5((int)$vendor_account_details['id']);
        $cachedStyle = function_exists('bc_cache_get') ? bc_cache_get($cacheKey, 300) : null;

        if (is_array($cachedStyle)) {
            $site_details     = $cachedStyle;
            $site_title       = $cachedStyle['site_title'] ?? $site_title;
            $header_image_url = $cachedStyle['header_image'] ?? $header_image_url;
        } else {
            $template_name_pattern = '%template-6%';
            $stmt_site = mysqli_prepare($connection_server, "SELECT sd.*, vst.header_image FROM sas_site_details sd LEFT JOIN sas_vendor_style_templates vst ON vst.vendor_id = sd.vendor_id AND vst.template_name LIKE ? WHERE sd.vendor_id = ? LIMIT 1");
            mysqli_stmt_bind_param($stmt_site, "si", $template_name_pattern, $vendor_account_details["id"]);
            mysqli_stmt_execute($stmt_site);
            $result_site = mysqli_stmt_get_result($stmt_site);
            if ($site_row = mysqli_fetch_assoc($result_site)) {
                $site_details     = $site_row;
                $site_title       = $site_row['site_title'] ?: $site_title;
                $header_image_url = $site_row['header_image'] ?: $header_image_url;
            }
            mysqli_stmt_close($stmt_site);
        }
    }
}

seo_start_ob_auto_alt($site_title);

$logo_filename = str_replace([".", ":"], "-", $_SERVER["HTTP_HOST"]) . "_logo.png";
if (file_exists("uploaded-image/" . $logo_filename)) {
    $site_logo = "uploaded-image/" . $logo_filename;
}

// Tenant brand colour, with a validated fallback. A bad value must not be able to inject CSS.
$brand_colour = '#0f766e';
if (!empty($site_details['primary_color']) && preg_match('/^#[0-9a-fA-F]{6}$/', $site_details['primary_color'])) {
    $brand_colour = $site_details['primary_color'];
}

$vendor_id     = isset($vendor_account_details['id']) ? (int)$vendor_account_details['id'] : 0;
$personal_cards = bc_fintech_marketing_partition($vendor_id, 'personal');
$business_cards = bc_fintech_marketing_partition($vendor_id, 'business');

/**
 * Inline SVG icon. The landing page does not load an icon font, so a `bi bi-*` class would render
 * as an empty box; these are drawn directly.
 */
if (!function_exists('bc_plate_icon')) {
    function bc_plate_icon($name, $size = 22) {
        $paths = [
            'wallet'   => '<rect x="2" y="6" width="20" height="13" rx="2"/><path d="M2 10h20"/><circle cx="17" cy="14.5" r="1.5"/>',
            'fund'     => '<path d="M12 2v20"/><path d="M17 7H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
            'send'     => '<path d="M22 2 11 13"/><path d="M22 2l-7 20-4-9-9-4 20-7z"/>',
            'globe'    => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a15 15 0 0 1 0 18a15 15 0 0 1 0-18z"/>',
            'sim'      => '<rect x="6" y="2" width="12" height="20" rx="2"/><path d="M9 7h6v5H9z"/>',
            'invoice'  => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8"/><path d="M8 17h5"/>',
            'card'     => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
            'code'     => '<path d="m16 18 6-6-6-6"/><path d="m8 6-6 6 6 6"/>',
            'check'    => '<path d="m20 6-11 11-5-5"/>',
            'shield'   => '<path d="M12 2 4 6v6c0 5 3.5 9 8 10c4.5-1 8-5 8-10V6z"/>',
            'rate'     => '<path d="M3 17l6-6 4 4 8-8"/><path d="M14 7h7v7"/>',
        ];
        $body = $paths[$name] ?? $paths['check'];
        return '<svg width="' . (int)$size . '" height="' . (int)$size . '" viewBox="0 0 24 24" fill="none" '
            . 'stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" '
            . 'aria-hidden="true">' . $body . '</svg>';
    }
}

/** One service card. Unavailable cards are dashed and labelled, never hidden. */
if (!function_exists('bc_plate_card')) {
    function bc_plate_card(array $card, $live) {
        $icons = [
            'transfers' => 'wallet', 'cross_border' => 'send', 'fx' => 'rate', 'esim' => 'sim',
            'tuition' => 'globe', 'invoices' => 'invoice', 'pos' => 'card', 'developer_api' => 'code',
        ];
        $icon = $icons[$card['service']] ?? 'check';
        $cls  = $live ? 'pc-card' : 'pc-card pc-card--soon';
        ?>
        <article class="<?php echo $cls; ?>">
            <div class="pc-card__icon"><?php echo bc_plate_icon($icon); ?></div>
            <h3 class="pc-card__title"><?php echo htmlspecialchars($card['name']); ?></h3>
            <p class="pc-card__copy"><?php echo htmlspecialchars($card['copy']); ?></p>
            <?php if (!$live): ?>
                <span class="pc-card__tag">Coming soon</span>
            <?php endif; ?>
        </article>
        <?php
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php seo_render_head_tags($site_details, $vendor_account_details); ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        /* ── Plate design system ─────────────────────────────────────────── */
        :root {
            --brand: <?php echo $brand_colour; ?>;
            --ink: #0b1220;
            --muted: #64748b;
            --line: #e2e8f0;
            --surface: #ffffff;
            --wash: #f6f8fb;
            --radius: 16px;
        }
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
        html { scroll-behavior: smooth; -webkit-text-size-adjust: 100%; }
        body {
            font-family: 'Inter', system-ui, -apple-system, "Segoe UI", sans-serif;
            color: var(--ink); background: var(--surface); line-height: 1.6;
            overflow-x: hidden;
        }
        .pc-wrap { width: 100%; max-width: 1160px; margin: 0 auto; padding: 0 24px; }

        /* ── Header ─────────────────────────────────────────────────────── */
        .pc-header {
            position: sticky; top: 0; z-index: 50;
            background: rgba(255, 255, 255, .88); backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--line);
        }
        .pc-header__inner { display: flex; align-items: center; justify-content: space-between; height: 68px; gap: 16px; }
        .pc-brand { display: flex; align-items: center; gap: 10px; text-decoration: none; color: var(--ink); font-weight: 800; font-size: 1.05rem; letter-spacing: -.02em; }
        .pc-brand img { height: 34px; width: auto; display: block; }
        .pc-brand__mark {
            width: 34px; height: 34px; border-radius: 10px; background: var(--brand);
            color: #fff; display: grid; place-items: center; font-weight: 800; font-size: .95rem;
        }
        .pc-nav { display: flex; align-items: center; gap: 24px; }
        .pc-nav a { color: var(--muted); text-decoration: none; font-size: .92rem; font-weight: 500; }
        .pc-nav a:hover { color: var(--ink); }
        .pc-cta {
            display: inline-flex; align-items: center; gap: 8px;
            background: var(--brand); color: #fff; text-decoration: none;
            padding: 10px 18px; border-radius: 10px; font-weight: 600; font-size: .92rem;
            border: 1px solid transparent; transition: opacity .15s ease;
        }
        .pc-cta:hover { opacity: .9; color: #fff; }
        .pc-cta--ghost { background: transparent; color: var(--ink); border-color: var(--line); }
        .pc-cta--ghost:hover { color: var(--ink); border-color: var(--ink); }

        /* ── Hero ───────────────────────────────────────────────────────── */
        .pc-hero { padding: 72px 0 40px; background:
            radial-gradient(900px 420px at 50% -10%, rgba(15, 118, 110, .10), transparent 70%);
        }
        .pc-hero__inner { max-width: 820px; margin: 0 auto; text-align: center; }
        .pc-eyebrow {
            display: inline-flex; align-items: center; gap: 8px;
            background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0;
            padding: 6px 14px; border-radius: 999px; font-size: .8rem; font-weight: 600; margin-bottom: 22px;
        }
        .pc-hero h1 {
            font-size: clamp(2rem, 5.2vw, 3.6rem); line-height: 1.08;
            letter-spacing: -.035em; font-weight: 800; margin-bottom: 18px;
        }
        .pc-hero h1 em { font-style: normal; color: var(--brand); }
        .pc-hero__sub {
            font-size: clamp(1rem, 2vw, 1.15rem); color: var(--muted);
            max-width: 620px; margin: 0 auto 30px;
        }
        .pc-hero__actions { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }

        /* ── Personal / Business switch ─────────────────────────────────── */
        .pc-switch {
            display: inline-flex; padding: 5px; gap: 4px; margin: 44px auto 0;
            background: var(--wash); border: 1px solid var(--line); border-radius: 999px;
        }
        .pc-switch button {
            border: 0; background: transparent; cursor: pointer; font: inherit;
            font-size: .9rem; font-weight: 600; color: var(--muted);
            padding: 9px 26px; border-radius: 999px; transition: all .18s ease;
        }
        .pc-switch button[aria-selected="true"] {
            background: var(--surface); color: var(--ink);
            box-shadow: 0 1px 3px rgba(16, 24, 40, .12);
        }
        .pc-switch button:focus-visible { outline: 2px solid var(--brand); outline-offset: 2px; }
        .pc-panel[hidden] { display: none; }
        .pc-panel__note {
            text-align: center; color: var(--muted); font-size: .92rem;
            margin: 14px auto 0; max-width: 560px;
        }

        /* ── Sections ───────────────────────────────────────────────────── */
        .pc-section { padding: 64px 0; }
        .pc-section--wash { background: var(--wash); border-block: 1px solid var(--line); }
        .pc-head { text-align: center; max-width: 640px; margin: 0 auto 44px; }
        .pc-head h2 { font-size: clamp(1.5rem, 3.4vw, 2.15rem); letter-spacing: -.025em; font-weight: 800; margin-bottom: 10px; }
        .pc-head p { color: var(--muted); font-size: 1rem; }

        /* ── 3-step flow ────────────────────────────────────────────────── */
        .pc-steps { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; counter-reset: step; }
        .pc-step {
            background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius);
            padding: 28px 24px; position: relative; overflow: hidden;
        }
        .pc-step::before {
            counter-increment: step; content: counter(step);
            position: absolute; top: 14px; right: 20px;
            font-size: 3.4rem; font-weight: 800; color: var(--wash); line-height: 1;
        }
        .pc-step__icon {
            width: 46px; height: 46px; border-radius: 12px; display: grid; place-items: center;
            background: color-mix(in srgb, var(--brand) 12%, white); color: var(--brand); margin-bottom: 16px;
        }
        .pc-step h3 { font-size: 1.05rem; font-weight: 700; margin-bottom: 8px; letter-spacing: -.01em; }
        .pc-step p { color: var(--muted); font-size: .92rem; }

        /* ── Service cards ──────────────────────────────────────────────── */
        .pc-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 18px; }
        .pc-card {
            background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius);
            padding: 24px 20px; transition: transform .18s ease, box-shadow .18s ease;
        }
        .pc-card:hover { transform: translateY(-2px); box-shadow: 0 12px 28px rgba(16, 24, 40, .08); }
        .pc-card__icon { color: var(--brand); margin-bottom: 14px; }
        .pc-card__title { font-size: .98rem; font-weight: 700; margin-bottom: 6px; }
        .pc-card__copy { color: var(--muted); font-size: .875rem; }
        /* Coming soon: clearly unavailable, but still on the page so the product is legible. */
        .pc-card--soon { border-style: dashed; background: transparent; }
        .pc-card--soon .pc-card__icon { color: #94a3b8; }
        .pc-card--soon .pc-card__title { color: #475569; }
        .pc-card__tag {
            display: inline-block; margin-top: 12px; font-size: .72rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: .06em; color: #64748b;
            background: #f1f5f9; border-radius: 999px; padding: 4px 10px;
        }

        /* ── Trust strip ────────────────────────────────────────────────── */
        .pc-trust { display: grid; grid-template-columns: repeat(3, 1fr); gap: 28px; }
        .pc-trust__item { display: flex; gap: 14px; }
        .pc-trust__icon { color: var(--brand); flex: 0 0 auto; margin-top: 2px; }
        .pc-trust h3 { font-size: .95rem; font-weight: 700; margin-bottom: 4px; }
        .pc-trust p { color: var(--muted); font-size: .875rem; }

        /* ── Footer ─────────────────────────────────────────────────────── */
        .pc-footer { border-top: 1px solid var(--line); padding: 32px 0; background: var(--surface); }
        .pc-footer__inner { display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap; }
        .pc-footer p { color: var(--muted); font-size: .85rem; }
        .pc-footer nav { display: flex; gap: 20px; flex-wrap: wrap; }
        .pc-footer nav a { color: var(--muted); text-decoration: none; font-size: .85rem; }
        .pc-footer nav a:hover { color: var(--ink); }

        /* ── Responsive ─────────────────────────────────────────────────── */
        @media (max-width: 980px) {
            .pc-grid { grid-template-columns: repeat(2, 1fr); }
            .pc-trust { grid-template-columns: 1fr; gap: 20px; }
        }
        @media (max-width: 720px) {
            .pc-nav { display: none; }
            .pc-hero { padding: 48px 0 32px; }
            .pc-section { padding: 48px 0; }
            .pc-steps { grid-template-columns: 1fr; }
            .pc-grid { grid-template-columns: 1fr; }
            .pc-switch { width: 100%; }
            .pc-switch button { flex: 1; padding: 10px 12px; }
            .pc-hero__actions .pc-cta { flex: 1 1 100%; justify-content: center; }
        }
        @media (prefers-reduced-motion: reduce) {
            * { transition: none !important; scroll-behavior: auto !important; }
        }
    </style>
</head>
<body>

<header class="pc-header">
    <div class="pc-wrap pc-header__inner">
        <a class="pc-brand" href="<?php echo htmlspecialchars($web_http_host ?? ''); ?>/index.php">
            <?php if ($site_logo): ?>
                <img src="<?php echo htmlspecialchars($site_logo); ?>" alt="<?php echo htmlspecialchars($site_title); ?>">
            <?php else: ?>
                <span class="pc-brand__mark"><?php echo htmlspecialchars(strtoupper(substr($site_title, 0, 1))); ?></span>
            <?php endif; ?>
            <span><?php echo htmlspecialchars($site_title); ?></span>
        </a>
        <nav class="pc-nav" aria-label="Main">
            <a href="#how">How it works</a>
            <a href="#services">Services</a>
            <a href="#why">Why us</a>
        </nav>
        <div class="pc-nav">
            <a class="pc-cta" href="<?php echo htmlspecialchars($web_http_host ?? ''); ?>/web/Login.php">Sign in</a>
        </div>
    </div>
</header>

<!-- ── Hero ────────────────────────────────────────────────────────────── -->
<section class="pc-hero">
    <div class="pc-wrap pc-hero__inner">
        <span class="pc-eyebrow"><?php echo bc_plate_icon('shield', 14); ?> Built for cross-border money</span>
        <h1>One account for money that <em>crosses borders</em></h1>
        <p class="pc-hero__sub">
            Hold your naira, receive dollars, and pay suppliers abroad — without the paperwork,
            the queues or the guesswork about what it costs.
        </p>
        <div class="pc-hero__actions">
            <a class="pc-cta" href="<?php echo htmlspecialchars($web_http_host ?? ''); ?>/web/Register.php">Open an account</a>
            <a class="pc-cta pc-cta--ghost" href="#how">See how it works</a>
        </div>

        <!-- Both panels render server-side; JS only toggles visibility. -->
        <div class="pc-switch" role="tablist" aria-label="Account type">
            <button role="tab" id="pc-tab-personal" aria-controls="pc-panel-personal" aria-selected="true" type="button">Personal</button>
            <button role="tab" id="pc-tab-business" aria-controls="pc-panel-business" aria-selected="false" type="button">Business</button>
        </div>

        <div class="pc-panel" id="pc-panel-personal" role="tabpanel" aria-labelledby="pc-tab-personal">
            <p class="pc-panel__note">
                For individuals sending money home, paying school fees abroad or holding dollars.
            </p>
        </div>
        <div class="pc-panel" id="pc-panel-business" role="tabpanel" aria-labelledby="pc-tab-business" hidden>
            <p class="pc-panel__note">
                For businesses paying overseas suppliers, invoicing international clients and
                settling in more than one currency.
            </p>
        </div>
    </div>
</section>

<!-- ── Three-step flow ─────────────────────────────────────────────────── -->
<section class="pc-section pc-section--wash" id="how">
    <div class="pc-wrap">
        <div class="pc-head">
            <h2>Three steps to your first transfer</h2>
            <p>No branch visit and no paperwork. Start in the app or on the web.</p>
        </div>
        <div class="pc-steps">
            <div class="pc-step">
                <div class="pc-step__icon"><?php echo bc_plate_icon('wallet'); ?></div>
                <h3>Create your USD wallet</h3>
                <p>Open a dollar account next to your naira balance and see both in one place.</p>
            </div>
            <div class="pc-step">
                <div class="pc-step__icon"><?php echo bc_plate_icon('fund'); ?></div>
                <h3>Fund the account</h3>
                <p>Top up from your bank, or receive a transfer from abroad directly into it.</p>
            </div>
            <div class="pc-step">
                <div class="pc-step__icon"><?php echo bc_plate_icon('send'); ?></div>
                <h3>Send to China</h3>
                <p>Pay a supplier or family member, with the rate shown before you confirm.</p>
            </div>
        </div>
    </div>
</section>

<!-- ── Services ────────────────────────────────────────────────────────── -->
<section class="pc-section" id="services">
    <div class="pc-wrap">
        <div class="pc-head">
            <h2>What you can do</h2>
            <p>Personal services are shown first. Switch above for business tools.</p>
        </div>

        <div class="pc-panel" id="pc-services-personal" role="tabpanel" aria-labelledby="pc-tab-personal">
            <div class="pc-grid">
                <?php foreach ($personal_cards['live'] as $card) bc_plate_card($card, true); ?>
                <?php foreach ($personal_cards['soon'] as $card) bc_plate_card($card, false); ?>
            </div>
        </div>

        <div class="pc-panel" id="pc-services-business" role="tabpanel" aria-labelledby="pc-tab-business" hidden>
            <div class="pc-grid">
                <?php foreach ($business_cards['live'] as $card) bc_plate_card($card, true); ?>
                <?php foreach ($business_cards['soon'] as $card) bc_plate_card($card, false); ?>
            </div>
        </div>
    </div>
</section>

<!-- ── Trust ───────────────────────────────────────────────────────────── -->
<section class="pc-section pc-section--wash" id="why">
    <div class="pc-wrap">
        <div class="pc-head">
            <h2>Why people use it</h2>
        </div>
        <div class="pc-trust">
            <div class="pc-trust__item">
                <span class="pc-trust__icon"><?php echo bc_plate_icon('rate'); ?></span>
                <div>
                    <h3>The rate you are quoted</h3>
                    <p>See the rate and the fee before you confirm, not after the money has left.</p>
                </div>
            </div>
            <div class="pc-trust__item">
                <span class="pc-trust__icon"><?php echo bc_plate_icon('shield'); ?></span>
                <div>
                    <h3>Verified, not anonymous</h3>
                    <p>Identity checks protect your account and the people you send to.</p>
                </div>
            </div>
            <div class="pc-trust__item">
                <span class="pc-trust__icon"><?php echo bc_plate_icon('globe'); ?></span>
                <div>
                    <h3>Support that answers</h3>
                    <p>Ask in the app and get a reply — from the assistant first, a person when it matters.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<footer class="pc-footer">
    <div class="pc-wrap pc-footer__inner">
        <p>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($site_title); ?>. All rights reserved.</p>
        <nav aria-label="Footer">
            <a href="<?php echo htmlspecialchars($web_http_host ?? ''); ?>/web/Login.php">Sign in</a>
            <a href="<?php echo htmlspecialchars($web_http_host ?? ''); ?>/web/Register.php">Open an account</a>
            <a href="<?php echo htmlspecialchars($web_http_host ?? ''); ?>/blog.php">Blog</a>
        </nav>
    </div>
</footer>

<script>
(function () {
    "use strict";
    // The switch only toggles visibility: both panels are already in the DOM, so nothing here is
    // required to read the page. With JS off, Personal stays visible.
    var tabs = {
        personal: {
            tab: document.getElementById("pc-tab-personal"),
            panels: [document.getElementById("pc-panel-personal"), document.getElementById("pc-services-personal")]
        },
        business: {
            tab: document.getElementById("pc-tab-business"),
            panels: [document.getElementById("pc-panel-business"), document.getElementById("pc-services-business")]
        }
    };

    function select(which) {
        Object.keys(tabs).forEach(function (key) {
            var isOn = key === which;
            tabs[key].tab.setAttribute("aria-selected", isOn ? "true" : "false");
            tabs[key].panels.forEach(function (p) { if (p) p.hidden = !isOn; });
        });
    }

    Object.keys(tabs).forEach(function (key) {
        var tab = tabs[key].tab;
        if (!tab) return;
        tab.addEventListener("click", function () { select(key); });
        // Arrow keys move between tabs, as the tablist pattern expects.
        tab.addEventListener("keydown", function (e) {
            if (e.key !== "ArrowLeft" && e.key !== "ArrowRight") return;
            var other = key === "personal" ? "business" : "personal";
            e.preventDefault();
            tabs[other].tab.focus();
            select(other);
        });
    });
})();
</script>

<?php seo_render_custom_footer($site_details); ?>
</body>
</html>

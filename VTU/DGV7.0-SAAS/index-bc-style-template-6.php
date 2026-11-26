<?php
require_once __DIR__ . '/func/bc-public-app-brand.php';
/**
 * DGV7 phase 2 — landing template 6 ("Plate").
 *
 * Layout follows the reference fintech homepage: a navbar, a two-line display hero with a
 * proof row and a product mockup, a scrolling category band, then alternating feature
 * sections with oversized headlines, a dark analytics band, and a multi-column footer.
 *
 * Every asset here is original. Copy, icons (inline SVG), the phone/card/analytics mockups
 * and the colour system are drawn in this file; nothing is copied from the reference site.
 *
 * Two constraints shape this file:
 *
 *   1. Both account panels are rendered server-side and the switch only toggles visibility.
 *      With JS disabled the Personal panel stays visible and the Business markup is still
 *      present, so nothing is hidden behind a script.
 *   2. Every service card comes from bc_fintech_marketing_partition(), so a card cannot claim
 *      a service is available when the account gate would refuse it. Unavailable cards are
 *      shown dashed and marked "Coming soon" rather than dropped.
 */

include_once(__DIR__ . "/func/bc-seo.php");
require_once(__DIR__ . "/func/bc-fintech-accounts.php");

// §46: default to this site's own host; the vendor's saved Site Name overwrites it below.
$site_title       = (string)($_SERVER['HTTP_HOST'] ?? '');
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
            $stmt_site = mysqli_prepare($connection_server, "SELECT sd.*, vst.header_image, vst.primary_color FROM sas_site_details sd LEFT JOIN sas_vendor_style_templates vst ON vst.vendor_id = sd.vendor_id WHERE sd.vendor_id = ? ORDER BY vst.id DESC LIMIT 1");
            mysqli_stmt_bind_param($stmt_site, "i", $vendor_account_details["id"]);
            mysqli_stmt_execute($stmt_site);
            $result_site = mysqli_stmt_get_result($stmt_site);
            if ($site_row = mysqli_fetch_assoc($result_site)) {
                $site_details     = $site_row;
                $site_title       = $site_row['site_title'] ?: $site_title;
                $header_image_url = $site_row['header_image'] ?: $header_image_url;
                if (function_exists('bc_cache_set')) {
                    bc_cache_set($cacheKey, $site_row);
                }
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
require_once __DIR__ . '/func/bc-theme.php';
$plate_palette = bc_theme_palette($brand_colour);
$plate_light = $plate_palette['modes']['light'];
$plate_dark = $plate_palette['modes']['dark'];
$brand_rgb = '15, 118, 110';
if (sscanf($brand_colour, '#%02x%02x%02x', $br, $bg, $bb) === 3) {
    $brand_rgb = "$br, $bg, $bb";
}

$vendor_id      = isset($vendor_account_details['id']) ? (int)$vendor_account_details['id'] : 0;
$personal_cards = bc_fintech_marketing_partition($vendor_id, 'personal');
$business_cards = bc_fintech_marketing_partition($vendor_id, 'business');

// The "Download app" link honours the tenant's configured Android link; without one it falls
// back to opening an account, so the hero never points at a placeholder.
$app_link   = htmlspecialchars($web_http_host ?? '') . '/web/Register.php';
$app_state  = function_exists('bc_fintech_tenant_android_app_url') ? bc_fintech_tenant_android_app_url($vendor_id) : null;
if (is_array($app_state) && ($app_state['state'] ?? '') === 'valid' && !empty($app_state['url'])) {
    $app_link = htmlspecialchars($app_state['url']);
}

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
            'bills'    => '<path d="M6 2h12v20l-3-2-3 2-3-2-3 2z"/><path d="M9 7h6"/><path d="M9 11h6"/>',
            'gift'     => '<rect x="3" y="8" width="18" height="4"/><path d="M5 12v9h14v-9"/><path d="M12 8v13"/><path d="M12 8S10 3 7.5 4.5 9 8 12 8z"/><path d="M12 8s2-5 4.5-3.5S15 8 12 8z"/>',
            'vault'    => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="12" cy="12" r="4"/><path d="M12 8v1"/><path d="M12 15v1"/><path d="M8 12h1"/><path d="M15 12h1"/>',
            'book'     => '<path d="M4 4h7a3 3 0 0 1 3 3v13a2.5 2.5 0 0 0-2.5-2.5H4z"/><path d="M20 4h-7a3 3 0 0 0-3 3v13a2.5 2.5 0 0 1 2.5-2.5H20z"/>',
            'chart'    => '<path d="M4 20V4"/><path d="M4 20h16"/><path d="M8 16v-5"/><path d="M12 16V8"/><path d="M16 16v-3"/>',
            'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'lock'     => '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
            'spark'    => '<path d="M12 2v6"/><path d="M12 16v6"/><path d="M2 12h6"/><path d="M16 12h6"/><path d="m5 5 4 4"/><path d="m15 15 4 4"/><path d="m19 5-4 4"/><path d="m9 15-4 4"/>',
            'users'    => '<circle cx="9" cy="8" r="3.5"/><path d="M2 21a7 7 0 0 1 14 0"/><path d="M16 5.5a3.5 3.5 0 0 1 0 6.5"/><path d="M18 21a7 7 0 0 0-2-4.8"/>',
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

/** Proof/stat figure used in the feature rows. */
if (!function_exists('bc_plate_stat')) {
    function bc_plate_stat($value, $label) {
        ?>
        <div class="pk-stat">
            <span class="pk-stat__value"><?php echo htmlspecialchars($value); ?></span>
            <span class="pk-stat__label"><?php echo htmlspecialchars($label); ?></span>
        </div>
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

    <style>
        /* ── Plate design system ─────────────────────────────────────────── */
        :root {
            --brand: <?php echo $brand_colour; ?>;
            --brand-rgb: <?php echo $brand_rgb; ?>;
            --brand-fg: <?php echo $plate_light['on_primary']; ?>;
            --brand-ink: <?php echo $plate_light['primary_text']; ?>;
            --brand-dark-fg: <?php echo $plate_dark['primary_text']; ?>;
            --ink: #0b1220;
            --muted: #64748b;
            --line: #e2e8f0;
            --surface: #ffffff;
            --wash: #f6f8fb;
            --ink-band: #0b1220;
            --ink-band-2: #111a2e;
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
            color: var(--brand-fg); display: grid; place-items: center; font-weight: 800; font-size: .95rem;
        }
        .pc-nav { display: flex; align-items: center; gap: 24px; }
        .pc-nav a { color: var(--muted); text-decoration: none; font-size: .92rem; font-weight: 500; }
        .pc-nav a:hover { color: var(--ink); }
        .pc-cta {
            display: inline-flex; align-items: center; gap: 8px;
            background: var(--brand); color: var(--brand-fg); text-decoration: none;
            padding: 11px 20px; border-radius: 10px; font-weight: 600; font-size: .92rem;
            border: 1px solid var(--brand-ink); transition: opacity .15s ease;
        }
        .pc-cta:hover { opacity: .9; color: var(--brand-fg); }
        .pc-cta--ghost { background: transparent; color: var(--ink); border-color: var(--line); }
        .pc-cta--ghost:hover { color: var(--ink); border-color: var(--ink); }

        /* ── Hero ───────────────────────────────────────────────────────── */
        .pc-hero { padding: 64px 0 0; background:
            radial-gradient(900px 420px at 50% -10%, rgba(var(--brand-rgb), .12), transparent 70%);
        }
        .pc-hero__inner { max-width: 900px; margin: 0 auto; text-align: center; }
        .pc-eyebrow {
            display: inline-flex; align-items: center; gap: 8px;
            background: rgba(var(--brand-rgb), .08); color: var(--brand-ink); border: 1px solid rgba(var(--brand-rgb), .24);
            padding: 6px 14px; border-radius: 999px; font-size: .8rem; font-weight: 600; margin-bottom: 22px;
        }
        /* The reference idiom: a two-line display headline whose second line is outlined. */
        .pc-hero h1 {
            font-size: clamp(2.1rem, 5.6vw, 4rem); line-height: 1.04;
            letter-spacing: -.04em; font-weight: 900; margin-bottom: 20px; text-transform: uppercase;
        }
        .pk-h1-outline {
            font-style: normal; color: transparent;
            -webkit-text-stroke: 1.5px var(--ink);
        }
        @supports not (-webkit-text-stroke: 1px #000) {
            .pk-h1-outline { color: var(--brand); }
        }
        .pc-hero__sub {
            font-size: clamp(1rem, 2vw, 1.15rem); color: var(--muted);
            max-width: 640px; margin: 0 auto 28px;
        }
        .pc-hero__actions { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
        .pk-proof {
            display: flex; align-items: center; justify-content: center; gap: 22px;
            flex-wrap: wrap; margin: 26px 0 40px; color: var(--muted); font-size: .86rem;
        }
        .pk-proof__rating { display: inline-flex; align-items: center; gap: 7px; font-weight: 700; color: var(--ink); }
        .pk-proof__stars { color: #f59e0b; letter-spacing: 1px; }
        .pk-proof__dots { display: inline-flex; }
        .pk-proof__dots span {
            width: 22px; height: 22px; border-radius: 50%; border: 2px solid #fff;
            background: #94a3b8; margin-left: -7px;
        }
        .pk-proof__dots span:first-child { margin-left: 0; }

        /* ── Product mockup (pure CSS, original) ────────────────────────── */
        .pk-phone {
            width: 280px; margin: 0 auto; padding: 10px; border-radius: 34px;
            background: #0b1220; box-shadow: 0 30px 70px rgba(11, 18, 32, .28);
        }
        .pk-phone__screen {
            background: var(--wash); border-radius: 26px; padding: 18px 16px 22px;
            display: flex; flex-direction: column; gap: 14px; text-align: left;
        }
        .pk-phone__notch {
            width: 96px; height: 6px; border-radius: 999px; background: #1e293b; margin: 2px auto 8px;
        }
        .pk-balance {
            background: var(--ink); color: #fff; border-radius: 16px; padding: 16px;
        }
        .pk-balance small { opacity: .7; font-size: .72rem; text-transform: uppercase; letter-spacing: .08em; }
        .pk-balance strong { display: block; font-size: 1.5rem; letter-spacing: -.02em; margin-top: 4px; }
        .pk-quick { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; }
        .pk-quick span {
            background: #fff; border: 1px solid var(--line); border-radius: 12px;
            padding: 9px 4px; font-size: .68rem; font-weight: 600; text-align: center; color: var(--muted);
        }
        .pk-tx { background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 12px; display: grid; gap: 10px; }
        .pk-tx div { display: flex; align-items: center; gap: 9px; font-size: .78rem; }
        .pk-tx i { width: 26px; height: 26px; border-radius: 8px; background: #f1f5f9; color: var(--brand-ink); display: grid; place-items: center; font-style: normal; }
        .pk-tx b { margin-left: auto; font-weight: 700; }

        /* ── Marquee band ──────────────────────────────────────────────── */
        .pk-band { background: var(--ink); color: #fff; overflow: hidden; padding: 14px 0; }
        .pk-band__track { display: flex; gap: 34px; width: max-content; animation: pk-marquee 26s linear infinite; }
        .pk-band__track span {
            font-size: .82rem; font-weight: 700; letter-spacing: .22em; text-transform: uppercase;
            display: inline-flex; align-items: center; gap: 34px;
        }
        .pk-band__track span::after { content: ""; width: 7px; height: 7px; border-radius: 50%; background: var(--brand); }
        @keyframes pk-marquee { from { transform: translateX(0); } to { transform: translateX(-50%); } }

        /* ── Sections ───────────────────────────────────────────────────── */
        .pc-section { padding: 76px 0; }
        .pc-section--wash { background: var(--wash); border-block: 1px solid var(--line); }
        .pc-section--ink { background: var(--ink-band); color: #fff; }
        .pc-section--ink .pc-head p { color: #94a3b8; }
        .pc-head { text-align: center; max-width: 660px; margin: 0 auto 48px; }
        .pc-head h2 { font-size: clamp(1.6rem, 3.6vw, 2.4rem); letter-spacing: -.03em; font-weight: 900; margin-bottom: 12px; text-transform: uppercase; line-height: 1.1; }
        .pc-head p { color: var(--muted); font-size: 1rem; }

        /* In-page anchor targets that have no section of their own. */
        .pc-anchor { display: block; position: relative; top: -84px; }

        /* ── Feature split rows ─────────────────────────────────────────── */
        .pk-split { display: grid; grid-template-columns: 1fr 1fr; gap: 56px; align-items: center; }
        .pk-split--rev > .pk-split__copy { order: 2; }
        .pk-split__copy h2 {
            font-size: clamp(1.5rem, 3.2vw, 2.2rem); letter-spacing: -.03em; font-weight: 900;
            text-transform: uppercase; line-height: 1.1; margin-bottom: 16px;
        }
        .pk-split__copy p { color: var(--muted); margin-bottom: 22px; }
        .pc-section--ink .pk-split__copy p { color: #94a3b8; }
        .pk-stats { display: flex; gap: 30px; flex-wrap: wrap; margin-bottom: 26px; }
        .pk-stat { display: flex; flex-direction: column; }
        .pk-stat__value { font-size: 1.5rem; font-weight: 900; letter-spacing: -.03em; color: var(--brand-ink); }
        .pc-section--ink .pk-stat__value { color: var(--brand-dark-fg); }
        .pk-stat__label { font-size: .84rem; color: var(--muted); }

        /* ── Icon feature grid ──────────────────────────────────────────── */
        .pk-features { display: grid; grid-template-columns: repeat(3, 1fr); gap: 26px 30px; }
        .pk-feature { display: flex; gap: 14px; }
        .pk-feature__icon {
            flex: 0 0 auto; width: 44px; height: 44px; border-radius: 12px;
            display: grid; place-items: center; background: rgba(var(--brand-rgb), .09); color: var(--brand-ink);
        }
        .pc-section--ink .pk-feature__icon { background: rgba(255, 255, 255, .08); color: var(--brand-dark-fg); }
        .pk-feature h3 { font-size: .98rem; font-weight: 700; margin-bottom: 4px; }
        .pk-feature p { color: var(--muted); font-size: .88rem; }
        .pc-section--ink .pk-feature p { color: #94a3b8; }

        /* ── Analytics mockup ──────────────────────────────────────────── */
        .pk-panel {
            background: var(--ink-band-2); border: 1px solid rgba(255, 255, 255, .08);
            border-radius: var(--radius); padding: 22px; display: grid; gap: 16px;
        }
        .pk-bars { display: flex; align-items: flex-end; gap: 10px; height: 130px; }
        .pk-bars i { flex: 1; border-radius: 6px 6px 0 0; background: linear-gradient(180deg, var(--brand-dark-fg), var(--brand)); display: block; }
        .pk-cards { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; }
        .pk-mini { background: rgba(255, 255, 255, .05); border: 1px solid rgba(255, 255, 255, .07); border-radius: 12px; padding: 14px; }
        .pk-mini small { color: #94a3b8; font-size: .72rem; text-transform: uppercase; letter-spacing: .06em; }
        .pk-mini strong { display: block; font-size: 1.15rem; margin-top: 4px; }

        /* ── Card mockup ───────────────────────────────────────────────── */
        .pk-cards { display: flex; gap: 18px; flex-wrap: wrap; }
        .pk-cardmock {
            width: 250px; height: 152px; border-radius: 16px; padding: 18px; color: #fff;
            background: linear-gradient(135deg, var(--brand), #0b1220); display: flex; flex-direction: column; justify-content: space-between;
        }
        .pk-cardmock--alt { background: linear-gradient(135deg, #0b1220, #334155); }
        .pk-cardmock__num { letter-spacing: .16em; font-size: .86rem; }
        .pk-cardmock__foot { display: flex; justify-content: space-between; align-items: flex-end; font-size: .78rem; }
        .pk-cardmock__chip { width: 30px; height: 22px; border-radius: 5px; background: #facc15; }

        /* ── Account mockup ────────────────────────────────────────────── */
        .pk-acct { background: #fff; border: 1px solid var(--line); border-radius: var(--radius); padding: 20px; display: grid; gap: 12px; }
        .pk-acct__row { display: flex; justify-content: space-between; align-items: center; padding-bottom: 12px; border-bottom: 1px solid var(--line); font-size: .88rem; }
        .pk-acct__row:last-child { border-bottom: 0; padding-bottom: 0; }
        .pk-acct__row span { color: var(--muted); }
        .pk-acct__row b { font-family: ui-monospace, "SFMono-Regular", Menlo, monospace; font-size: .82rem; }

        /* ── Service cards ─────────────────────────────────────────────── */
        .pc-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 18px; }
        .pc-card {
            background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius);
            padding: 24px 20px; transition: transform .18s ease, box-shadow .18s ease;
        }
        .pc-card:hover { transform: translateY(-2px); box-shadow: 0 12px 28px rgba(16, 24, 40, .08); }
        .pc-card__icon { color: var(--brand-ink); margin-bottom: 14px; }
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

        /* ── Personal / Business switch ─────────────────────────────────── */
        .pc-switch {
            display: inline-flex; padding: 5px; gap: 4px; margin: 0 auto 40px;
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
        .pc-switch button:focus-visible { outline: 2px solid var(--brand-ink); outline-offset: 2px; }
        .pc-panel[hidden] { display: none; }
        .pc-panel__note { text-align: center; color: var(--muted); font-size: .92rem; margin: 0 auto 26px; max-width: 620px; }
        .pk-switch-wrap { display: flex; justify-content: center; }

        /* ── Footer ─────────────────────────────────────────────────────── */
        .pc-footer { border-top: 1px solid var(--line); padding: 52px 0 28px; background: var(--surface); }
        .pc-footer__grid { display: grid; grid-template-columns: 1.6fr 1fr 1fr 1fr; gap: 34px; margin-bottom: 34px; }
        .pc-footer__grid h4 { font-size: .8rem; text-transform: uppercase; letter-spacing: .1em; color: var(--muted); margin-bottom: 14px; }
        .pc-footer__grid a { display: block; color: var(--muted); text-decoration: none; font-size: .88rem; padding: 5px 0; }
        .pc-footer__grid a:hover { color: var(--ink); }
        .pc-footer__brand p { color: var(--muted); font-size: .88rem; max-width: 320px; margin-top: 12px; }
        .pc-footer__bar { border-top: 1px solid var(--line); padding-top: 20px; display: flex; justify-content: space-between; gap: 14px; flex-wrap: wrap; }
        .pc-footer__bar p { color: var(--muted); font-size: .82rem; }

        /* ── Responsive ─────────────────────────────────────────────────── */
        @media (max-width: 980px) {
            .pc-grid { grid-template-columns: repeat(2, 1fr); }
            .pk-features { grid-template-columns: repeat(2, 1fr); }
            .pk-split { grid-template-columns: 1fr; gap: 30px; }
            .pk-split--rev > .pk-split__copy { order: 0; }
            .pc-footer__grid { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 720px) {
            .pc-nav { display: none; }
            .pc-hero { padding: 44px 0 0; }
            .pc-section { padding: 52px 0; }
            .pc-grid { grid-template-columns: 1fr; }
            .pk-features { grid-template-columns: 1fr; }
            .pc-switch { width: 100%; }
            .pc-switch button { flex: 1; padding: 10px 12px; }
            .pc-hero__actions .pc-cta { flex: 1 1 100%; justify-content: center; }
            .pk-cards { justify-content: center; }
            .pc-footer__grid { grid-template-columns: 1fr; }
        }
        @media (prefers-reduced-motion: reduce) {
            * { transition: none !important; scroll-behavior: auto !important; }
            .pk-band__track { animation: none !important; }
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
            <a href="Services.php">Services</a>
            <a href="ReferAndEarn.php">Refer &amp; Earn</a>
            <a href="#send">Send money</a>
            <a href="#cards">Cards</a>
            <a href="#bills">Bills</a>
        </nav>
        <div class="pc-nav">
            <a class="pc-cta pc-cta--ghost" href="<?php echo htmlspecialchars($web_http_host ?? ''); ?>/web/Login.php">Sign in</a>
            <a class="pc-cta" href="<?php echo htmlspecialchars($web_http_host ?? ''); ?>/web/Register.php">Get started</a>
        </div>
    </div>
</header>

<!-- ── Hero ────────────────────────────────────────────────────────────── -->
<section class="pc-hero">
    <div class="pc-wrap pc-hero__inner">
        <span class="pc-eyebrow"><?php echo bc_plate_icon('spark', 14); ?> One account, every currency</span>
        <h1>Money that moves<br><span class="pk-h1-outline">without borders</span></h1>
        <p class="pc-hero__sub">
            Hold naira, collect dollars and pay abroad from one balance. See the rate and the fee
            before you confirm — not after the money has left.
        </p>
        <div class="pc-hero__actions">
            <a class="pc-cta" href="<?php echo htmlspecialchars($web_http_host ?? ''); ?>/web/Register.php">Open an account</a>
            <a class="pc-cta pc-cta--ghost" href="<?php echo $app_link; ?>"><?php echo bc_plate_icon('sim', 16); ?> Download app</a>
        </div>

        <div class="pk-proof">
            <span class="pk-proof__rating"><span class="pk-proof__stars" aria-hidden="true">★★★★★</span> 4.0/5</span>
            <span class="pk-proof__dots" aria-hidden="true"><span></span><span></span><span></span><span></span></span>
            <span>Over 100k+ active users</span>
        </div>

        <!-- Original product mockup: balance, quick actions and recent activity. -->
        <div class="pk-phone" aria-hidden="true">
            <div class="pk-phone__screen">
                <div class="pk-phone__notch"></div>
                <div class="pk-balance">
                    <small>Total balance</small>
                    <strong>₦1,284,500.00</strong>
                </div>
                <div class="pk-quick"><span>Send</span><span>Fund</span><span>Bills</span><span>Cards</span></div>
                <div class="pk-tx">
                    <div><i>↗</i> Sent to Lagos<b>₦85,000</b></div>
                    <div><i>↓</i> Received in USD<b>$500.00</b></div>
                    <div><i>◆</i> Airtime top-up<b>₦1,000</b></div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ── Category band ───────────────────────────────────────────────────── -->
<div class="pk-band" aria-hidden="true">
    <div class="pk-band__track">
        <span>Wallets</span><span>Cards</span><span>Transfers</span><span>Giftcards</span><span>Bills</span><span>Vault</span>
        <span>Wallets</span><span>Cards</span><span>Transfers</span><span>Giftcards</span><span>Bills</span><span>Vault</span>
    </div>
</div>

<!-- ── Send money ──────────────────────────────────────────────────────── -->
<section class="pc-section" id="send">
    <div class="pc-wrap pk-split">
        <div class="pk-split__copy">
            <h2>Send money locally or abroad</h2>
            <p>
                Send money to friends, family or a business. Payments settle in the currency they
                expect, and you choose how the recipient gets it.
            </p>
            <div class="pk-stats">
                <?php bc_plate_stat('13+', 'currencies supported'); ?>
                <?php bc_plate_stat('50+', 'countries reached'); ?>
                <?php bc_plate_stat('4', 'payout methods'); ?>
            </div>
            <a class="pc-cta" href="<?php echo htmlspecialchars($web_http_host ?? ''); ?>/web/Register.php">Send money</a>
        </div>
        <div class="pk-split__visual" aria-hidden="true">
            <div class="pk-acct">
                <div class="pk-acct__row"><span>Recipient</span><b>Lagos, NG</b></div>
                <div class="pk-acct__row"><span>You send</span><b>USD 500.00</b></div>
                <div class="pk-acct__row"><span>Rate</span><b>1,512.40</b></div>
                <div class="pk-acct__row"><span>They receive</span><b>NGN 756,200</b></div>
            </div>
        </div>
    </div>
</section>

<!-- ── Feature grid ────────────────────────────────────────────────────── -->
<section class="pc-section pc-section--wash" id="features">
    <!-- "Why us": the trust strip the footer links to, kept with the feature grid. -->
    <span id="why" class="pc-anchor" aria-hidden="true"></span>
    <div class="pc-wrap">
        <div class="pc-head">
            <h2>An app without borders</h2>
            <p>Cross-border money movement that gives you control over every step.</p>
        </div>
        <div class="pk-features">
            <div class="pk-feature">
                <span class="pk-feature__icon"><?php echo bc_plate_icon('send'); ?></span>
                <div><h3>Payments made easy</h3><p>Send to a bank, a wallet or a pickup point in a few taps.</p></div>
            </div>
            <div class="pk-feature">
                <span class="pk-feature__icon"><?php echo bc_plate_icon('clock'); ?></span>
                <div><h3>Instant notification</h3><p>Know the moment a transfer lands, not the next time you check.</p></div>
            </div>
            <div class="pk-feature">
                <span class="pk-feature__icon"><?php echo bc_plate_icon('rate'); ?></span>
                <div><h3>Competitive rates</h3><p>The rate is quoted before you confirm and locked when you accept.</p></div>
            </div>
            <div class="pk-feature">
                <span class="pk-feature__icon"><?php echo bc_plate_icon('wallet'); ?></span>
                <div><h3>Multiple payout methods</h3><p>Bank deposit, cash pickup or wallet credit — whichever suits them.</p></div>
            </div>
            <div class="pk-feature">
                <span class="pk-feature__icon"><?php echo bc_plate_icon('shield'); ?></span>
                <div><h3>Zero maintenance fees</h3><p>No account keeping charge and no card maintenance charge.</p></div>
            </div>
            <div class="pk-feature">
                <span class="pk-feature__icon"><?php echo bc_plate_icon('users'); ?></span>
                <div><h3>One KYC for everything</h3><p>Verify once and use every service the account unlocks.</p></div>
            </div>
        </div>
    </div>
</section>

<!-- ── Bills ───────────────────────────────────────────────────────────── -->
<section class="pc-section" id="bills">
    <div class="pc-wrap pk-split pk-split--rev">
        <div class="pk-split__copy">
            <h2>Manage your bills and subscriptions</h2>
            <p>
                Recharge airtime, buy data, renew the TV subscription and top up the meter from
                wherever you are — without a queue and without writing anything down.
            </p>
            <div class="pk-stats">
                <?php bc_plate_stat('9', 'bill services'); ?>
                <?php bc_plate_stat('24/7', 'always on'); ?>
                <?php bc_plate_stat('10s', 'average checkout'); ?>
            </div>
            <a class="pc-cta" href="<?php echo htmlspecialchars($web_http_host ?? ''); ?>/web/Register.php">Pay a bill</a>
        </div>
        <div class="pk-split__visual" aria-hidden="true">
            <div class="pk-acct">
                <div class="pk-acct__row"><span>Airtime · MTN</span><b>₦1,000</b></div>
                <div class="pk-acct__row"><span>Data · 5GB monthly</span><b>₦3,500</b></div>
                <div class="pk-acct__row"><span>TV subscription</span><b>₦2,900</b></div>
                <div class="pk-acct__row"><span>Electricity token</span><b>₦5,000</b></div>
            </div>
        </div>
    </div>
</section>

<!-- ── Services (gated by the account tier) ────────────────────────────── -->
<section class="pc-section pc-section--wash" id="services">
    <div class="pc-wrap">
        <div class="pc-head">
            <h2>What you can do</h2>
            <p>Services shown for your account type. Switch to see the other panel.</p>
        </div>

        <!-- Both panels render server-side; JS only toggles visibility. -->
        <div class="pk-switch-wrap">
            <div class="pc-switch" role="tablist" aria-label="Account type">
                <button role="tab" id="pc-tab-personal" aria-controls="pc-services-personal" aria-selected="true" type="button">Personal</button>
                <button role="tab" id="pc-tab-business" aria-controls="pc-services-business" aria-selected="false" type="button">Business</button>
            </div>
        </div>

        <div class="pc-panel" id="pc-services-personal" role="tabpanel" aria-labelledby="pc-tab-personal">
            <p class="pc-panel__note">For individuals sending money home, paying school fees abroad or holding dollars.</p>
            <div class="pc-grid">
                <?php foreach ($personal_cards['live'] as $card) bc_plate_card($card, true); ?>
                <?php foreach ($personal_cards['soon'] as $card) bc_plate_card($card, false); ?>
            </div>
        </div>

        <div class="pc-panel" id="pc-services-business" role="tabpanel" aria-labelledby="pc-tab-business" hidden>
            <p class="pc-panel__note">For businesses paying overseas suppliers, invoicing international clients and settling in more than one currency.</p>
            <div class="pc-grid">
                <?php foreach ($business_cards['live'] as $card) bc_plate_card($card, true); ?>
                <?php foreach ($business_cards['soon'] as $card) bc_plate_card($card, false); ?>
            </div>
        </div>
    </div>
</section>

<!-- ── Cards ───────────────────────────────────────────────────────────── -->
<section class="pc-section" id="cards">
    <div class="pc-wrap pk-split">
        <div class="pk-split__copy">
            <h2>A card that works everywhere</h2>
            <p>
                Spend from the same balance online and in stores, in naira or in foreign currency,
                with the rate shown at the point of sale.
            </p>
            <div class="pk-cards" aria-hidden="true">
                <div class="pk-cardmock">
                    <span class="pk-cardmock__chip"></span>
                    <div class="pk-cardmock__foot"><span>5310 •••• •••• 2204</span><span>VIRTUAL</span></div>
                </div>
                <div class="pk-cardmock pk-cardmock--alt">
                    <span class="pk-cardmock__chip"></span>
                    <div class="pk-cardmock__foot"><span>4021 •••• •••• 8890</span><span>PHYSICAL</span></div>
                </div>
            </div>
        </div>
        <div class="pk-split__visual" aria-hidden="true">
            <div class="pk-acct">
                <div class="pk-acct__row"><span>Card currency</span><b>USD</b></div>
                <div class="pk-acct__row"><span>Weekly limit</span><b>$2,500</b></div>
                <div class="pk-acct__row"><span>Freeze card</span><b>Instant</b></div>
                <div class="pk-acct__row"><span>Replacement</span><b>Free</b></div>
            </div>
        </div>
    </div>
</section>

<!-- ── Analytics (dark band) ───────────────────────────────────────────── -->
<section class="pc-section pc-section--ink" id="control">
    <div class="pc-wrap pk-split pk-split--rev">
        <div class="pk-split__copy">
            <h2>You are in control</h2>
            <p>
                Clear insight into every money movement. Track inflows and outflows, see where the
                money actually goes, and decide with the numbers in front of you.
            </p>
            <div class="pk-stats">
                <?php bc_plate_stat('Real-time', 'balance updates'); ?>
                <?php bc_plate_stat('Exportable', 'statements'); ?>
            </div>
            <a class="pc-cta" href="<?php echo htmlspecialchars($web_http_host ?? ''); ?>/web/Register.php">See your dashboard</a>
        </div>
        <div class="pk-split__visual" aria-hidden="true">
            <div class="pk-panel">
                <div class="pk-bars">
                    <i style="height:42%"></i><i style="height:66%"></i><i style="height:54%"></i>
                    <i style="height:88%"></i><i style="height:72%"></i><i style="height:96%"></i>
                    <i style="height:60%"></i><i style="height:78%"></i>
                </div>
                <div class="pk-cards">
                    <div class="pk-mini"><small>Inflows</small><strong>₦2.4M</strong></div>
                    <div class="pk-mini"><small>Outflows</small><strong>₦1.1M</strong></div>
                    <div class="pk-mini"><small>Spend breakdown</small><strong>12 categories</strong></div>
                    <div class="pk-mini"><small>All activity</small><strong>348 this month</strong></div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ── Accounts ────────────────────────────────────────────────────────── -->
<section class="pc-section" id="accounts">
    <div class="pc-wrap pk-split">
        <div class="pk-split__copy">
            <h2>Open a USD, GBP or EUR account in seconds</h2>
            <p>
                Get local account details for dollars, pounds and euros, then receive international
                payments as if you had an account there.
            </p>
            <a class="pc-cta" href="<?php echo htmlspecialchars($web_http_host ?? ''); ?>/web/Register.php">Get your account</a>
        </div>
        <div class="pk-split__visual" aria-hidden="true">
            <div class="pk-acct">
                <div class="pk-acct__row"><span>USD account</span><b>0123456789</b></div>
                <div class="pk-acct__row"><span>Routing</span><b>090123456</b></div>
                <div class="pk-acct__row"><span>GBP account</span><b>9876543210</b></div>
                <div class="pk-acct__row"><span>EUR account</span><b>5555111122</b></div>
            </div>
        </div>
    </div>
</section>

<!-- ── Savings ─────────────────────────────────────────────────────────── -->
<section class="pc-section pc-section--wash" id="savings">
    <div class="pc-wrap pk-split pk-split--rev">
        <div class="pk-split__copy">
            <h2>Get returns on every currency you hold</h2>
            <p>
                Put idle naira, dollars, pounds or euros to work. Savings are held separately from
                your spending balance, so they are not spent by accident.
            </p>
            <div class="pk-stats">
                <?php bc_plate_stat('4', 'currencies'); ?>
                <?php bc_plate_stat('Separate', 'from spending'); ?>
            </div>
            <a class="pc-cta" href="<?php echo htmlspecialchars($web_http_host ?? ''); ?>/web/Register.php">Start saving</a>
        </div>
        <div class="pk-split__visual" aria-hidden="true">
            <div class="pk-acct">
                <div class="pk-acct__row"><span>NGN vault</span><b>₦840,000</b></div>
                <div class="pk-acct__row"><span>USD vault</span><b>$1,250</b></div>
                <div class="pk-acct__row"><span>GBP vault</span><b>£400</b></div>
                <div class="pk-acct__row"><span>EUR vault</span><b>€300</b></div>
            </div>
        </div>
    </div>
</section>

<!-- ── Tuition ─────────────────────────────────────────────────────────── -->
<section class="pc-section" id="tuition">
    <div class="pc-wrap pk-split">
        <div class="pk-split__copy">
            <h2>Tuition payment made easy</h2>
            <p>
                Pay tuition, accommodation and other school bills in any country, in the currency
                the institution expects, with no extra fees on top of the rate you accepted.
            </p>
            <a class="pc-cta" href="<?php echo htmlspecialchars($web_http_host ?? ''); ?>/web/Register.php">Pay tuition</a>
        </div>
        <div class="pk-split__visual" aria-hidden="true">
            <div class="pk-acct">
                <div class="pk-acct__row"><span>Institution</span><b>Any country</b></div>
                <div class="pk-acct__row"><span>Currency</span><b>USD / GBP / EUR</b></div>
                <div class="pk-acct__row"><span>Extra fee</span><b>None</b></div>
                <div class="pk-acct__row"><span>Receipt</span><b>Instant</b></div>
            </div>
        </div>
    </div>
</section>

<footer class="pc-footer">
    <div class="pc-wrap">
        <div class="pc-footer__grid">
            <div class="pc-footer__brand">
                <a class="pc-brand" href="<?php echo htmlspecialchars($web_http_host ?? ''); ?>/index.php">
                    <?php if ($site_logo): ?>
                        <img src="<?php echo htmlspecialchars($site_logo); ?>" alt="<?php echo htmlspecialchars($site_title); ?>">
                    <?php else: ?>
                        <span class="pc-brand__mark"><?php echo htmlspecialchars(strtoupper(substr($site_title, 0, 1))); ?></span>
                    <?php endif; ?>
                    <span><?php echo htmlspecialchars($site_title); ?></span>
                </a>
                <p>One account for money that crosses borders — wallets, cards, transfers, bills and savings.</p>
            </div>
            <div>
                <h4>Services</h4>
                <a href="#services">Personal</a>
                <a href="#services">Business</a>
                <a href="#cards">Cards</a>
                <a href="#savings">Savings</a>
            </div>
            <div>
                <h4>Company</h4>
                <a href="<?php echo htmlspecialchars($web_http_host ?? ''); ?>/blog.php">Blog &amp; insights</a>
                <a href="#why">Why us</a>
                <a href="#control">Dashboard</a>
            </div>
            <div>
                <h4>Account</h4>
                <a href="<?php echo htmlspecialchars($web_http_host ?? ''); ?>/web/Login.php">Sign in</a>
                <a href="<?php echo htmlspecialchars($web_http_host ?? ''); ?>/web/Register.php">Open an account</a>
                <a href="#tuition">Tuition</a>
            </div>
        </div>
        <div class="pc-footer__bar">
            <p>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($site_title); ?>. All rights reserved.</p>
            <p>Rates and fees are shown before you confirm.</p>
        </div>
    </div>
<?php require_once __DIR__ . '/func/bc-public-pages.php'; bc_public_directory_html(); ?>
<?php echo bc_public_app_badge_html(bc_public_saved_app_url((int)($vendor_account_details['id'] ?? 0), $site_details ?? [])); ?>
</footer>

<script>
(function () {
    "use strict";
    // The switch only toggles visibility: both panels are already in the DOM, so nothing here is
    // required to read the page. With JS off, Personal stays visible.
    var tabs = {
        personal: {
            tab: document.getElementById("pc-tab-personal"),
            panels: [document.getElementById("pc-services-personal")]
        },
        business: {
            tab: document.getElementById("pc-tab-business"),
            panels: [document.getElementById("pc-services-business")]
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

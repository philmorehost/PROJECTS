<?php
// Initialize SEO engine and variables
include_once(__DIR__ . "/func/bc-seo.php");

$site_title       = 'FintechFlow';
$site_logo        = '';
$header_image_url = '';
$apk_download_url = '';
$site_details     = [];

// Check if vendor details are available from index.php
if (isset($vendor_account_details) && is_array($vendor_account_details)) {
    // Fetch Site Details and optional header image in one query for faster page load
    if (isset($connection_server)) {
        $cacheKey = 'vendor_site_style_' . md5((int)$vendor_account_details['id']);
        $cachedStyle = function_exists('bc_cache_get') ? bc_cache_get($cacheKey, 300) : null;

        if (is_array($cachedStyle)) {
            $site_details     = $cachedStyle;
            $site_title       = $cachedStyle['site_title'] ?? $site_title;
            $apk_download_url = $cachedStyle['apk_download_url'] ?? $apk_download_url;
            $header_image_url = $cachedStyle['header_image'] ?? $header_image_url;
        } else {
            $template_name_pattern = '%template-1%';
            $stmt_site = mysqli_prepare($connection_server, "SELECT sd.*, vst.header_image FROM sas_site_details sd LEFT JOIN sas_vendor_style_templates vst ON vst.vendor_id = sd.vendor_id AND vst.template_name LIKE ? WHERE sd.vendor_id = ? LIMIT 1");
            mysqli_stmt_bind_param($stmt_site, "si", $template_name_pattern, $vendor_account_details["id"]);
            mysqli_stmt_execute($stmt_site);
            $result_site = mysqli_stmt_get_result($stmt_site);
            if ($site_row = mysqli_fetch_assoc($result_site)) {
                $site_details     = $site_row;
                $site_title       = $site_row['site_title'] ?: $site_title;
                $apk_download_url = $site_row['apk_download_url'] ?: $apk_download_url;
                $header_image_url = $site_row['header_image'] ?: $header_image_url;

                if (function_exists('bc_cache_set')) {
                    bc_cache_set($cacheKey, $site_row);
                }
            }
            mysqli_stmt_close($stmt_site);
        }
    }
}

// Start Output Buffer for dynamic Alt Tags insertion
seo_start_ob_auto_alt($site_title);

// Logo path
$logo_filename = str_replace([".", ":"], "-", $_SERVER["HTTP_HOST"]) . "_logo.png";
if (file_exists("uploaded-image/" . $logo_filename)) {
    $site_logo = "uploaded-image/" . $logo_filename;
} else {
    // Default logo or placeholder
    $site_logo = "https://via.placeholder.com/150x44/1e3a8a/ffffff?text=" . urlencode($site_title);
}

// Header Image
if (!empty($header_image_url) && file_exists("uploaded-image/" . $header_image_url)) {
    $hero_image = "uploaded-image/" . $header_image_url;
} else {
    $hero_image = "asset/vtu-fintech.jpg"; // Use an existing asset
    if (!file_exists($hero_image)) {
        $hero_image = "https://via.placeholder.com/600x400/1e3a8a/ffffff?text=" . urlencode($site_title);
    }
}

// Social Share URLs
$current_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
$share_text = urlencode("Check out " . $site_title . " for Digital Payments Made Simple!");
$facebook_share = "https://www.facebook.com/sharer/sharer.php?u=" . urlencode($current_url);
$twitter_share = "https://twitter.com/intent/tweet?url=" . urlencode($current_url) . "&text=" . $share_text;
$linkedin_share = "https://www.linkedin.com/shareArticle?mini=true&url=" . urlencode($current_url);
$email_share = "mailto:?subject=" . $share_text . "&body=" . urlencode($current_url);
$contact_email = "mailto:" . (($vendor_account_details['email'] ?? '') ?: '');
?>
<?php
// ── Freelancer landing (2026-11 redesign of template 1) ─────────────────
// The brand name is automated: every mention on this page uses $site_title,
// which the preamble above resolved from the saved site details. Services
// switched off in bc-admin (Service Control) are not advertised here.
$site_name_e = htmlspecialchars((string)$site_title, ENT_QUOTES, 'UTF-8');
$site_name_initial = strtoupper(substr(trim((string)$site_title) !== '' ? trim((string)$site_title) : 'F', 0, 1));
$lp_vid = (isset($vendor_account_details) && is_array($vendor_account_details) && !empty($vendor_account_details['id'])) ? (int)$vendor_account_details['id'] : 0;

// SAAS platform domain (no vendor matched this host): brand the page with
// the platform's own site title instead of leaving the generic fallback.
if ($lp_vid <= 0 && isset($connection_server) && $connection_server) {
    $lp_sa = mysqli_query($connection_server, "SELECT site_title FROM sas_super_admin_site_details LIMIT 1");
    if ($lp_sa && ($lp_sa_row = mysqli_fetch_assoc($lp_sa)) && !empty($lp_sa_row['site_title'])) {
        $site_title = $lp_sa_row['site_title'];
        $site_name_e = htmlspecialchars((string)$site_title, ENT_QUOTES, 'UTF-8');
        $site_name_initial = strtoupper(substr(trim((string)$site_title), 0, 1));
    }
}

$lp_on = function ($service) use ($lp_vid) {
    return function_exists('isServiceEnabled') ? (bool) isServiceEnabled($service, $lp_vid) : true;
};
$lp_airtime = $lp_on('airtime');
$lp_data = $lp_on('data');
$lp_cable = $lp_on('cable');
$lp_electric = $lp_on('electric');
$lp_betting = $lp_on('betting');
$lp_exam = $lp_on('exam');
$lp_vtu_any = $lp_airtime || $lp_data || $lp_cable || $lp_electric || $lp_betting || $lp_exam;
$lp_vtu_list = array();
if ($lp_airtime) $lp_vtu_list[] = 'airtime';
if ($lp_data) $lp_vtu_list[] = 'data';
if ($lp_electric) $lp_vtu_list[] = 'electricity';
if ($lp_cable) $lp_vtu_list[] = 'cable TV';
if ($lp_betting) $lp_vtu_list[] = 'betting';
if ($lp_exam) $lp_vtu_list[] = 'exam PIN';
$lp_cards = $lp_on('virtual_card');
$lp_receive = $lp_on('bank_transfer');
$lp_send = $lp_on('share_fund') || $lp_on('withdraw');
$lp_fx_toggle = $lp_on('currency_conversion');
// Live conversion is offered only while Currency Conversion is ON in
// bc-admin AND the FX API is configured there (Service Control -> Currency
// Conversion API). Otherwise the converter is not rendered at all.
$lp_fx_key = ($lp_vid > 0 && function_exists('getVendorOption')) ? trim((string) getVendorOption($lp_vid, 'fx_api_key', '')) : '';
$lp_fx_live = $lp_fx_toggle && $lp_fx_key !== '';
$lp_currencies = array('NGN', 'USD', 'GBP', 'EUR', 'KES', 'GHS', 'UGX', 'TZS', 'CAD', 'AED');
$lp_personas = array(
    array('Freelancers', 'FR', 'Invoices, transfers and top-ups in one free account.'),
    array('Creators', 'CR', 'Get paid by brands and platforms without chasing payments.'),
    array('Consultants', 'CO', 'Bill clients professionally and track every payment.'),
    array('Coaches', 'CH', 'Collect session fees by link, invoice or QR code.'),
    array('Beauty pros', 'BP', 'Take deposits and payments straight to your account.'),
    array('Makers', 'MK', 'Sell your work and keep the income separate from personal cash.'),
    array('Tech & devs', 'TD', 'Receive contract payments in the currencies clients use.'),
    array('Drivers & riders', 'DR', 'Daily earnings, top-ups and bills in one place.'),
);
$lp_contact_email = (isset($vendor_account_details['email']) && $vendor_account_details['email']) ? $vendor_account_details['email'] : '';
$brand_colour = '#287bff';
if ($lp_vid > 0 && isset($connection_server) && $connection_server) {
    $lp_bc = mysqli_query($connection_server, "SELECT primary_color FROM sas_vendor_style_templates WHERE vendor_id='" . $lp_vid . "' LIMIT 1");
    if ($lp_bc && ($lp_bc_row = mysqli_fetch_assoc($lp_bc)) && !empty($lp_bc_row['primary_color']) && preg_match('/^#[0-9a-fA-F]{6}$/', $lp_bc_row['primary_color'])) {
        $brand_colour = $lp_bc_row['primary_color'];
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars((string)$site_logo, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="theme-color" content="<?php echo $brand_colour; ?>">
<style>
/* ── Freelancer landing (2026-11 redesign of template 1) ─────────────── */
:root{
    --brand: <?php echo $brand_colour; ?>;
    --ink:#0b1220; --muted:#5b6b82; --paper:#f6f8fb; --card:#ffffff;
    --line:#e4eaf2; --dark:#0b1220; --dark2:#111c33;
    --radius:22px; --shadow:0 24px 48px -24px rgba(2,6,23,.25);
}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
html{scroll-behavior:smooth}
body{font-family:'Inter',system-ui,sans-serif;background:var(--paper);color:var(--ink);line-height:1.6;overflow-x:hidden}
a{text-decoration:none;color:inherit}
img{max-width:100%}
.lp-wrap{max-width:1180px;margin:0 auto;padding:0 22px}
.lp-eyebrow{font-size:.72rem;font-weight:800;letter-spacing:.16em;text-transform:uppercase;color:var(--brand)}
.lp-h2{font-size:clamp(1.7rem,3.4vw,2.5rem);font-weight:900;letter-spacing:-.02em;line-height:1.12;margin:.5rem 0 .6rem}
.lp-sub{color:var(--muted);max-width:640px}
.lp-section{padding:84px 0 8px}
.lp-btn{display:inline-flex;align-items:center;gap:8px;background:var(--brand);color:#fff;font-weight:800;
    padding:.85rem 1.7rem;border-radius:999px;box-shadow:0 14px 28px -12px var(--brand);transition:transform .15s ease}
.lp-btn:hover{transform:translateY(-2px)}
.lp-btn--ghost{background:#fff;color:var(--ink);border:1px solid var(--line);box-shadow:none}
.lp-btn--light{background:#fff;color:var(--ink);box-shadow:none}

/* nav */
.lp-nav{position:sticky;top:0;z-index:900;background:rgba(246,248,251,.88);backdrop-filter:blur(16px);border-bottom:1px solid var(--line)}
.lp-nav__in{display:flex;align-items:center;gap:26px;padding:14px 0}
.lp-brand{display:flex;align-items:center;gap:10px;font-weight:900;font-size:1.06rem;letter-spacing:-.02em}
.lp-brand img{height:36px;width:auto;border-radius:8px}
.lp-brand__mark{width:36px;height:36px;border-radius:11px;background:var(--brand);color:#fff;display:grid;place-items:center;font-weight:900}
.lp-brand small{display:block;font-size:.66rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)}
.lp-links{display:flex;gap:22px;margin-left:auto;font-weight:600;font-size:.92rem;color:var(--muted)}
.lp-links a:hover{color:var(--ink)}
.lp-nav__cta{display:flex;gap:10px;align-items:center;margin-left:8px}
.lp-login{font-weight:700}
@media(max-width:900px){.lp-links{display:none}}

/* hero */
.lp-hero{padding:72px 0 30px;background:
    radial-gradient(700px 320px at 85% -10%,color-mix(in srgb,var(--brand) 20%,transparent),transparent 60%),
    radial-gradient(600px 300px at 0% 0%,rgba(2,6,23,.05),transparent 55%)}
.lp-hero__grid{display:grid;grid-template-columns:1.05fr .95fr;gap:44px;align-items:center}
@media(max-width:960px){.lp-hero__grid{grid-template-columns:1fr}}
.lp-h1{font-size:clamp(2.3rem,5vw,3.6rem);font-weight:900;letter-spacing:-.03em;line-height:1.05;margin:.7rem 0 1rem}
.lp-hero p.lede{color:var(--muted);font-size:1.08rem;max-width:520px}
.lp-cta-row{display:flex;gap:12px;flex-wrap:wrap;margin:26px 0 14px}
.lp-trust{color:var(--muted);font-size:.86rem;font-weight:600}
.lp-phone{background:var(--dark);color:#e8eefb;border-radius:30px;padding:24px;box-shadow:var(--shadow);position:relative;overflow:hidden}
.lp-phone::before{content:"";position:absolute;inset:0;background:radial-gradient(420px 200px at 85% -10%,color-mix(in srgb,var(--brand) 55%,transparent),transparent 60%)}
.lp-phone>*{position:relative}
.lp-phone__name{font-weight:800}
.lp-phone__bal{font-size:2rem;font-weight:900;letter-spacing:-.02em;margin:2px 0 0}
.lp-phone__sub{color:#9fb0cc;font-size:.8rem}
.lp-pockets{display:grid;gap:10px;margin:18px 0}
.lp-pocket{display:flex;justify-content:space-between;align-items:center;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:16px;padding:12px 14px}
.lp-pocket b{font-size:.92rem}
.lp-pocket span{color:#9fb0cc;font-size:.78rem;display:block}
.lp-actions{display:flex;gap:8px;flex-wrap:wrap}
.lp-actions span{background:#fff;color:var(--ink);font-weight:800;font-size:.8rem;border-radius:999px;padding:.5rem .95rem}
.lp-actions span.alt{background:rgba(255,255,255,.1);color:#e8eefb;border:1px solid rgba(255,255,255,.16)}
.lp-feed{margin-top:18px;display:grid;gap:8px}
.lp-feed__row{display:flex;justify-content:space-between;align-items:center;background:rgba(255,255,255,.05);border-radius:14px;padding:10px 14px;font-size:.88rem}
.lp-feed__row .amt{font-weight:800;color:#7ee2a8}

/* feature cards */
.lp-cards{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-top:34px}
@media(max-width:960px){.lp-cards{grid-template-columns:1fr 1fr}}
@media(max-width:620px){.lp-cards{grid-template-columns:1fr}}
.lp-card{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);padding:24px;box-shadow:0 10px 24px -18px rgba(2,6,23,.25)}
.lp-card h3{font-size:1.05rem;font-weight:800;letter-spacing:-.01em;margin-bottom:6px}
.lp-card p{color:var(--muted);font-size:.9rem}
.lp-cardmock{background:linear-gradient(135deg,var(--dark),var(--dark2));color:#fff;border-radius:16px;padding:16px;margin-top:14px}
.lp-cardmock .num{font-family:ui-monospace,monospace;letter-spacing:.12em;margin-top:16px}
.lp-fee-row{display:flex;justify-content:space-between;border-bottom:1px dashed var(--line);padding:8px 0;font-size:.9rem}
.lp-fee-row:last-child{border-bottom:0}
.lp-chips{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}
.lp-chips span{background:color-mix(in srgb,var(--brand) 10%,#fff);color:var(--brand);border:1px solid color-mix(in srgb,var(--brand) 25%,#fff);font-weight:700;font-size:.78rem;border-radius:999px;padding:.35rem .8rem}
.lp-marquee{overflow:hidden;margin-top:14px;-webkit-mask-image:linear-gradient(90deg,transparent,#000 8%,#000 92%,transparent);mask-image:linear-gradient(90deg,transparent,#000 8%,#000 92%,transparent)}
.lp-marquee__track{display:flex;gap:10px;width:max-content;animation:lpScroll 26s linear infinite}
.lp-marquee__track span{border:1px solid var(--line);border-radius:999px;padding:.4rem .9rem;font-weight:800;font-size:.8rem;background:#fff}
@keyframes lpScroll{to{transform:translateX(-50%)}}

/* panels (receive / spend) */
.lp-panels{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-top:30px}
@media(max-width:960px){.lp-panels{grid-template-columns:1fr}}
.lp-panel{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);padding:24px;box-shadow:0 10px 24px -18px rgba(2,6,23,.25)}
.lp-panel h3{font-weight:800;font-size:1.02rem;margin-bottom:6px}
.lp-panel p{color:var(--muted);font-size:.9rem}
.lp-mock{background:var(--paper);border:1px solid var(--line);border-radius:16px;padding:14px;margin-top:14px;display:grid;gap:8px;font-size:.86rem}
.lp-mock__row{display:flex;justify-content:space-between;gap:10px}
.lp-mock__row span{color:var(--muted)}
.lp-mock__row b{font-weight:700;text-align:right}
.lp-badge{display:inline-block;background:#e8f9ee;color:#15803d;font-weight:800;font-size:.68rem;border-radius:999px;padding:.15rem .6rem;letter-spacing:.06em}
.lp-qr{display:grid;place-items:center;text-align:center;padding:18px 10px}
.lp-qr__box{width:118px;height:118px;border-radius:16px;background:
    repeating-conic-gradient(var(--ink) 0 25%,#fff 0 50%) 0 0/22px 22px;border:6px solid var(--ink)}
.lp-qr p{margin-top:12px}

/* steps */
.lp-steps{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-top:30px}
@media(max-width:960px){.lp-steps{grid-template-columns:1fr}}
.lp-step{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);padding:22px}
.lp-step__n{width:34px;height:34px;border-radius:50%;background:var(--brand);color:#fff;font-weight:900;display:grid;place-items:center;margin-bottom:12px}
.lp-step h3{font-size:1rem;font-weight:800}
.lp-step p{color:var(--muted);font-size:.9rem}

/* personas */
.lp-personas{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-top:30px}
@media(max-width:960px){.lp-personas{grid-template-columns:1fr 1fr}}
@media(max-width:560px){.lp-personas{grid-template-columns:1fr}}
.lp-persona{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);padding:20px}
.lp-persona__ph{height:86px;border-radius:14px;background:linear-gradient(135deg,color-mix(in srgb,var(--brand) 65%,#0b1220),var(--dark2));display:grid;place-items:center;color:#fff;font-weight:900;font-size:1.3rem;letter-spacing:.04em;margin-bottom:14px}
.lp-persona h3{font-size:.98rem;font-weight:800}
.lp-persona p{color:var(--muted);font-size:.86rem}

/* converter */
.lp-fx{background:var(--dark);color:#e8eefb;border-radius:26px;padding:26px;margin-top:26px;box-shadow:var(--shadow);position:relative;overflow:hidden}
.lp-fx::before{content:"";position:absolute;inset:0;background:radial-gradient(420px 200px at 85% -10%,color-mix(in srgb,var(--brand) 55%,transparent),transparent 60%)}
.lp-fx>*{position:relative}
.lp-fx__grid{display:grid;grid-template-columns:1fr auto 1fr;gap:14px;align-items:end;margin-top:16px}
@media(max-width:700px){.lp-fx__grid{grid-template-columns:1fr}}
.lp-fx label{font-size:.72rem;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:#9fb0cc;display:block}
.lp-fx .fx-field{display:flex;gap:8px;margin-top:8px}
.lp-fx input,.lp-fx select{background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.16);color:#fff;border-radius:14px;padding:.8rem .9rem;font:inherit;font-weight:700;width:100%}
.lp-fx select{width:auto}
.lp-fx option{color:#0b1220}
.lp-fx__swap{display:grid;place-items:center;width:42px;height:42px;border-radius:50%;background:#fff;color:var(--ink);font-weight:900;border:0;cursor:pointer;margin-bottom:2px}
.lp-fx__rate{margin-top:14px;color:#9fb0cc;font-size:.88rem}
.lp-fx__rate b{color:#7ee2a8}
.lp-live{display:inline-block;background:#e8f9ee;color:#15803d;font-weight:800;font-size:.66rem;border-radius:999px;padding:.2rem .6rem;letter-spacing:.08em;vertical-align:middle}

/* spend tiles */
.lp-tiles{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-top:26px}
@media(max-width:960px){.lp-tiles{grid-template-columns:1fr 1fr}}
@media(max-width:560px){.lp-tiles{grid-template-columns:1fr}}
.lp-tile{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);padding:20px}
.lp-tile h3{font-size:.96rem;font-weight:800}
.lp-tile p{color:var(--muted);font-size:.87rem}

/* pricing */
.lp-price{background:var(--card);border:1px solid var(--line);border-radius:26px;overflow:hidden;margin-top:28px;box-shadow:0 10px 24px -18px rgba(2,6,23,.25)}
.lp-price__row{display:flex;justify-content:space-between;gap:14px;padding:16px 22px;border-bottom:1px solid var(--line);font-size:.94rem}
.lp-price__row:last-child{border-bottom:0}
.lp-price__row b{font-weight:800}
.lp-price__head{background:var(--dark);color:#fff;padding:20px 22px;font-weight:900;display:flex;justify-content:space-between;align-items:center}
.lp-free{color:#7ee2a8;letter-spacing:.08em;font-size:.78rem}

/* faq */
.lp-faq{display:grid;gap:12px;margin-top:26px;max-width:820px}
.lp-faq details{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:16px 20px}
.lp-faq summary{font-weight:800;cursor:pointer}
.lp-faq p{color:var(--muted);font-size:.92rem;margin-top:8px}

/* final cta + footer */
.lp-final{background:var(--dark);color:#fff;border-radius:28px;padding:48px 34px;margin:84px 0 0;text-align:center;position:relative;overflow:hidden}
.lp-final::before{content:"";position:absolute;inset:0;background:radial-gradient(520px 240px at 50% -20%,color-mix(in srgb,var(--brand) 55%,transparent),transparent 65%)}
.lp-final>*{position:relative}
.lp-final h2{font-size:clamp(1.8rem,3.6vw,2.6rem);font-weight:900;letter-spacing:-.02em}
.lp-final form{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-top:22px}
.lp-final input{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.2);color:#fff;border-radius:999px;padding:.85rem 1.2rem;font:inherit;min-width:min(320px,80vw)}
.lp-footer{padding:44px 0 54px;color:var(--muted);font-size:.88rem}
.lp-footer__grid{display:flex;gap:26px;flex-wrap:wrap;justify-content:space-between;align-items:flex-start}
.lp-footer a:hover{color:var(--ink)}
#cookieBanner{position:fixed;left:16px;right:16px;bottom:16px;z-index:9999;background:var(--dark);color:#e8eefb;border-radius:18px;padding:16px 20px;box-shadow:var(--shadow)}
#cookieBanner .row{display:flex;gap:16px;align-items:center;flex-wrap:wrap;max-width:1100px;margin:0 auto}
#cookieBanner p{flex:1;min-width:220px;font-size:.88rem}
#cookieBanner button{border-radius:999px;padding:.55rem 1.2rem;font-weight:800;border:1px solid rgba(255,255,255,.3);background:transparent;color:#fff;cursor:pointer}
#cookieBanner button.acc{background:#fff;color:var(--ink);border-color:#fff}
</style>
</head>
<body>

<header class="lp-nav">
    <div class="lp-wrap lp-nav__in">
        <a class="lp-brand" href="./">
            <?php if (!empty($site_logo) && strpos((string)$site_logo, 'placeholder') === false): ?>
                <img src="<?php echo htmlspecialchars((string)$site_logo, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo $site_name_e; ?>">
            <?php else: ?>
                <span class="lp-brand__mark"><?php echo $site_name_initial; ?></span>
            <?php endif; ?>
            <span><?php echo $site_name_e; ?><small>for Freelancers</small></span>
        </a>
        <nav class="lp-links">
            <a href="#features">Features</a>
            <a href="#get-paid">Get paid</a>
            <a href="#currencies">Currencies</a>
            <a href="#spend">Spend</a>
            <a href="#faq">FAQ</a>
        </nav>
        <div class="lp-nav__cta">
            <a class="lp-login" href="web/Login.php">Log in</a>
            <a class="lp-btn" href="web/Register.php">Open free account</a>
        </div>
    </div>
</header>

<!-- ── HERO ─────────────────────────────────────────────────────── -->
<section class="lp-hero">
    <div class="lp-wrap lp-hero__grid">
        <div>
            <span class="lp-eyebrow">For freelancers &amp; the self-employed</span>
            <h1 class="lp-h1">Free account for freelancers who run their own show.</h1>
            <p class="lede">Get paid by invoice, bank transfer or QR code. Keep your freelance income in one account<?php if ($lp_cards): ?>, spend it with <?php echo $site_name_e; ?> cards<?php endif; ?><?php if ($lp_vtu_any): ?>, and top up airtime, data and bills in seconds<?php endif; ?>.</p>
            <div class="lp-cta-row">
                <a class="lp-btn" href="web/Register.php">Open free account</a>
                <a class="lp-btn lp-btn--ghost" href="#get-paid">See how it works</a>
            </div>
            <p class="lp-trust">No setup fee &nbsp;·&nbsp; No monthly fee &nbsp;·&nbsp; Sign up in minutes</p>
        </div>
        <div class="lp-phone" aria-hidden="true">
            <div class="lp-phone__name">Kate Bennett</div>
            <div class="lp-phone__sub">Total balance · across 3 pockets</div>
            <div class="lp-phone__bal">4,812.40 <span style="font-size:1rem;">USD</span></div>
            <div class="lp-pockets">
                <div class="lp-pocket"><div><b>Client income</b><span>Main pocket</span></div><b>3,120.00</b></div>
                <div class="lp-pocket"><div><b>Tax pot</b><span>Set aside automatically</span></div><b>1,040.00</b></div>
                <div class="lp-pocket"><div><b>Savings</b><span>Rainy-day fund</span></div><b>652.40</b></div>
            </div>
            <div class="lp-actions"><span>Add</span><?php if ($lp_send): ?><span>Send</span><?php endif; ?><?php if ($lp_fx_toggle): ?><span>Exchange</span><?php endif; ?><span class="alt">Top up</span></div>
            <div class="lp-feed">
                <div class="lp-feed__row"><span>Amara Okafor · Invoice paid</span><span class="amt">+120.00</span></div>
                <div class="lp-feed__row"><span>James Mwangi · Bank transfer</span><span class="amt">+65.00</span></div>
                <div class="lp-feed__row"><span>Tunde Bello · QR payment</span><span class="amt">+35.00</span></div>
            </div>
        </div>
    </div>

    <!-- feature cards -->
    <div class="lp-wrap">
        <div class="lp-cards" id="features">
            <?php if ($lp_cards): ?>
            <div class="lp-card">
                <h3>All cards included</h3>
                <p>Virtual and physical VISA cards for online and in-store spending, ready in minutes.</p>
                <div class="lp-cardmock"><div style="font-weight:800;"><?php echo $site_name_e; ?> · Virtual</div><div class="num">VISA •••• 2257</div><div style="color:#9fb0cc;font-size:.78rem;margin-top:6px;">Kate Bennett</div></div>
            </div>
            <?php endif; ?>
            <div class="lp-card">
                <h3>No setup or monthly fees</h3>
                <p>The freelancer account is free to open and free to keep. No small print.</p>
                <div style="margin-top:10px">
                    <div class="lp-fee-row"><span>Setup</span><b>0.00</b></div>
                    <div class="lp-fee-row"><span>Monthly</span><b>0.00</b></div>
                    <div class="lp-fee-row"><span>Maintenance</span><b>0.00</b></div>
                </div>
            </div>
            <?php if ($lp_vtu_any): ?>
            <div class="lp-card">
                <h3>Instant VTU top-ups</h3>
                <p>Buy airtime, data, electricity and TV subscriptions right from your balance — no separate app.</p>
                <div class="lp-chips">
                    <?php if ($lp_airtime): ?><span>Airtime</span><?php endif; ?>
                    <?php if ($lp_data): ?><span>Data</span><?php endif; ?>
                    <?php if ($lp_electric): ?><span>Electricity</span><?php endif; ?>
                    <?php if ($lp_cable): ?><span>TV</span><?php endif; ?>
                    <?php if ($lp_betting): ?><span>Betting</span><?php endif; ?>
                    <?php if ($lp_exam): ?><span>Exam PINs</span><?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            <div class="lp-card">
                <h3>Supported currencies</h3>
                <p>Hold and spend in the currencies your clients already pay in.</p>
                <div class="lp-marquee"><div class="lp-marquee__track">
                    <?php foreach (array_merge($lp_currencies, $lp_currencies) as $cur): ?><span><?php echo $cur; ?></span><?php endforeach; ?>
                </div></div>
            </div>
            <div class="lp-card">
                <h3>Keep your freelance income separate</h3>
                <p>Client money lands in its own account — away from personal spending and easy to track at tax time.</p>
                <div class="lp-feed" style="margin-top:14px">
                    <div class="lp-feed__row" style="background:var(--paper)"><span>Amara Okafor</span><span class="amt" style="color:#15803d">+120.00</span></div>
                    <div class="lp-feed__row" style="background:var(--paper)"><span>James Mwangi</span><span class="amt" style="color:#15803d">+65.00</span></div>
                    <div class="lp-feed__row" style="background:var(--paper)"><span>Tunde Bello</span><span class="amt" style="color:#15803d">+35.00</span></div>
                </div>
            </div>
            <div class="lp-card">
                <h3>Client payments via invoice</h3>
                <p>Send a branded invoice and get paid straight into your account. No chasing, no awkward reminders.</p>
                <div class="lp-mock">
                    <div class="lp-mock__row"><span>Bright Studio Ltd</span><b>Website redesign</b></div>
                    <div class="lp-mock__row"><span>Total</span><b>1,000.00 USD</b></div>
                    <div class="lp-mock__row"><span>Status</span><span class="lp-badge">PAID</span></div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ── RECEIVE ──────────────────────────────────────────────────── -->
<section class="lp-section" id="get-paid">
    <div class="lp-wrap">
        <span class="lp-eyebrow">Receive</span>
        <h2 class="lp-h2">Three ways to get paid. Zero chasing.</h2>
        <p class="lp-sub">However your clients prefer to pay, the money lands in your <?php echo $site_name_e; ?> account — ready to split, save or spend.</p>
        <div class="lp-panels">
            <?php if ($lp_receive): ?>
            <div class="lp-panel">
                <h3>By bank transfer</h3>
                <p>Your own dedicated account number. Clients pay like a local — you get notified instantly.</p>
                <div class="lp-mock">
                    <div class="lp-mock__row"><span>Account number</span><b>8012 345 678</b></div>
                    <div class="lp-mock__row"><span>Expected · Amara Okafor</span><b>+120.00</b></div>
                    <div class="lp-mock__row"><span>Expected · James Mwangi</span><b>+65.00</b></div>
                    <div class="lp-mock__row"><span>Local / International</span><b>Both</b></div>
                </div>
            </div>
            <?php endif; ?>
            <div class="lp-panel">
                <h3>By invoice</h3>
                <p>Create a branded invoice in seconds and share the payment link anywhere.</p>
                <div class="lp-mock">
                    <div class="lp-mock__row"><span>Client</span><b>Bright Studio Ltd</b></div>
                    <div class="lp-mock__row"><span>Service</span><b>Website redesign</b></div>
                    <div class="lp-mock__row"><span>Total</span><b>1,000.00 USD</b></div>
                    <div class="lp-mock__row"><span>Due</span><b>On receipt · 30 days · Custom</b></div>
                </div>
            </div>
            <div class="lp-panel">
                <h3>By QR code</h3>
                <p>Show your code in person or on screen. Clients scan and pay — card, Apple Pay or Google Pay.</p>
                <div class="lp-qr">
                    <div class="lp-qr__box" role="img" aria-label="QR code placeholder"></div>
                    <p><b>SCAN TO PAY</b><br>Pay to Kate Bennett · 1,000.00 USD<br><span style="color:var(--muted);font-size:.8rem">Apple Pay · Google Pay · Card</span></p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ── MANAGE ───────────────────────────────────────────────────── -->
<section class="lp-section" id="manage">
    <div class="lp-wrap">
        <span class="lp-eyebrow">Manage</span>
        <h2 class="lp-h2">Your freelance money, all together.</h2>
        <div class="lp-steps">
            <div class="lp-step"><div class="lp-step__n">1</div><h3>Get your own account</h3><p>Open free in minutes and receive client payments into an account that is yours alone.</p></div>
            <div class="lp-step"><div class="lp-step__n">2</div><h3>Split into pockets</h3><p>Separate client income, tax money and savings so every payment has a job.</p></div>
            <div class="lp-step"><div class="lp-step__n">3</div><h3><?php echo $lp_fx_toggle ? 'Exchange &amp; send' : 'Send &amp; spend'; ?></h3><p><?php echo $lp_fx_toggle ? 'Convert between currencies at live rates and send to any bank, or spend with your card.' : 'Send to any bank account or spend straight from your balance.'; ?></p></div>
        </div>
    </div>
</section>

<!-- ── PERSONAS ─────────────────────────────────────────────────── -->
<section class="lp-section" id="who">
    <div class="lp-wrap">
        <span class="lp-eyebrow">For independent workers</span>
        <h2 class="lp-h2">Whatever you do, <?php echo $site_name_e; ?> helps you get paid and stay in control.</h2>
        <div class="lp-personas">
            <?php foreach ($lp_personas as $persona): ?>
            <div class="lp-persona">
                <div class="lp-persona__ph"><?php echo $persona[1]; ?></div>
                <h3><?php echo $persona[0]; ?></h3>
                <p><?php echo $persona[2]; ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ── CURRENCIES ───────────────────────────────────────────────── -->
<section class="lp-section" id="currencies">
    <div class="lp-wrap">
        <span class="lp-eyebrow">Supported currencies</span>
        <h2 class="lp-h2">Get paid locally. Work globally.</h2>
        <p class="lp-sub">Hold balances in <b>NGN · USD · GBP · EUR</b> with real account details, and spend in 10+ currencies wherever your work takes you.</p>
        <div class="lp-chips" style="margin-top:18px">
            <?php foreach ($lp_currencies as $cur): ?><span><?php echo $cur; ?></span><?php endforeach; ?>
        </div>

        <?php if ($lp_fx_live): ?>
        <!-- Live converter: rendered only when Currency Conversion is ON in
             bc-admin and the vendor's FX API key is configured. Rates come
             from web/api/exchange-rate.php (getLiveExchangeRate). -->
        <div class="lp-fx" id="fxConverter">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
                <h3 style="font-weight:900;font-size:1.1rem">Currency converter</h3>
                <span class="lp-live" id="fxLiveBadge">LIVE RATE</span>
            </div>
            <div class="lp-fx__grid">
                <div>
                    <label for="fxAmount">You exchange</label>
                    <div class="fx-field">
                        <input id="fxAmount" type="number" value="100" min="0" step="any" inputmode="decimal">
                        <select id="fxFrom" aria-label="From currency">
                            <?php foreach ($lp_currencies as $cur): ?><option value="<?php echo $cur; ?>"<?php echo $cur === 'USD' ? ' selected' : ''; ?>><?php echo $cur; ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <button type="button" class="lp-fx__swap" id="fxSwap" title="Swap currencies" aria-label="Swap currencies">⇄</button>
                <div>
                    <label for="fxResult">You get</label>
                    <div class="fx-field">
                        <input id="fxResult" type="text" readonly value="—">
                        <select id="fxTo" aria-label="To currency">
                            <?php foreach ($lp_currencies as $cur): ?><option value="<?php echo $cur; ?>"<?php echo $cur === 'NGN' ? ' selected' : ''; ?>><?php echo $cur; ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <p class="lp-fx__rate" id="fxRateLine">Fetching the live rate…</p>
        </div>
        <script>
        (function () {
            var amountEl = document.getElementById('fxAmount'),
                fromEl = document.getElementById('fxFrom'),
                toEl = document.getElementById('fxTo'),
                resultEl = document.getElementById('fxResult'),
                lineEl = document.getElementById('fxRateLine'),
                badgeEl = document.getElementById('fxLiveBadge'),
                timer = null, seq = 0;

            function fmt(n) {
                return Number(n).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
            }
            function convert() {
                var from = fromEl.value, to = toEl.value, amount = parseFloat(amountEl.value) || 0, my = ++seq;
                lineEl.textContent = 'Fetching the live rate…';
                fetch('web/api/exchange-rate.php?from=' + encodeURIComponent(from) + '&to=' + encodeURIComponent(to), {headers: {'Accept': 'application/json'}})
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (my !== seq) return;
                        var rate = parseFloat(data && data.rate);
                        if (!rate || rate <= 0) throw new Error('bad rate');
                        resultEl.value = fmt(amount * rate);
                        lineEl.innerHTML = '1 ' + from + ' = <b>' + fmt(rate) + ' ' + to + '</b> · live rate, updates as you type';
                        badgeEl.style.display = '';
                    })
                    .catch(function () {
                        if (my !== seq) return;
                        resultEl.value = '—';
                        badgeEl.style.display = 'none';
                        lineEl.textContent = 'Live rate unavailable right now — please try again in a moment.';
                    });
            }
            function schedule() { clearTimeout(timer); timer = setTimeout(convert, 250); }
            [amountEl, fromEl, toEl].forEach(function (el) {
                el.addEventListener('input', schedule);
                el.addEventListener('change', schedule);
            });
            document.getElementById('fxSwap').addEventListener('click', function () {
                var t = fromEl.value; fromEl.value = toEl.value; toEl.value = t; convert();
            });
            convert();
        })();
        </script>
        <?php endif; ?>
    </div>
</section>

<!-- ── SPEND ────────────────────────────────────────────────────── -->
<section class="lp-section" id="spend">
    <div class="lp-wrap">
        <span class="lp-eyebrow">Spend</span>
        <h2 class="lp-h2">Spend it your way — cards, wallets and VTU top-ups.</h2>
        <?php if ($lp_cards): ?>
        <div class="lp-panels" style="grid-template-columns:1fr 1fr">
            <div class="lp-panel">
                <h3>Virtual card</h3>
                <div class="lp-cardmock"><div style="font-weight:800;"><?php echo $site_name_e; ?> · Virtual</div><div class="num">VISA •••• 2257</div><div style="color:#9fb0cc;font-size:.78rem;margin-top:6px;">Kate Bennett · Show details · Freeze card · Card settings</div></div>
            </div>
            <div class="lp-panel">
                <h3>Physical card</h3>
                <div class="lp-cardmock"><div style="font-weight:800;"><?php echo $site_name_e; ?> · Physical</div><div class="num">VISA •••• 8841</div><div style="color:#9fb0cc;font-size:.78rem;margin-top:6px;">Kate Bennett · Monthly limit 620.00 of 1,000.00</div></div>
            </div>
        </div>
        <?php endif; ?>
        <div class="lp-tiles">
            <?php if ($lp_cards): ?><div class="lp-tile"><h3>Physical &amp; virtual cards</h3><p>Spend online and in-store, anywhere VISA is accepted.</p></div><?php endif; ?>
            <div class="lp-tile"><h3>Mobile wallets</h3><p>Add your card to Apple Pay or Google Pay and tap to pay.</p></div>
            <?php if ($lp_cards): ?><div class="lp-tile"><h3>Spend controls &amp; limits</h3><p>Freeze a card, set monthly limits and see every transaction live.</p></div><?php endif; ?>
            <?php if ($lp_airtime || $lp_data): ?><div class="lp-tile"><h3>Airtime &amp; data top-ups</h3><p>Top up any Nigerian network in seconds, straight from your balance.</p></div><?php endif; ?>
            <?php if ($lp_cable || $lp_electric): ?><div class="lp-tile"><h3>Bills &amp; utilities</h3><p>Electricity tokens and TV subscriptions, settled instantly.</p></div><?php endif; ?>
            <?php if ($lp_fx_toggle): ?><div class="lp-tile"><h3>Spend in many currencies</h3><p>Your balance works across borders — no separate accounts to juggle.</p></div><?php endif; ?>
        </div>
    </div>
</section>

<!-- ── PRICING ──────────────────────────────────────────────────── -->
<section class="lp-section" id="pricing">
    <div class="lp-wrap">
        <span class="lp-eyebrow">Free</span>
        <h2 class="lp-h2">Free means free. No small print.</h2>
        <div class="lp-price">
            <div class="lp-price__head"><span>Freelancer plan</span><span class="lp-free">FREE</span></div>
            <div class="lp-price__row"><span>Account opening</span><b>Free</b></div>
            <div class="lp-price__row"><span>Monthly fee</span><b>Free</b></div>
            <?php if ($lp_cards): ?>
            <div class="lp-price__row"><span>Virtual cards</span><b>Free</b></div>
            <?php endif; ?>
            <div class="lp-price__row"><span>Sending invoices</span><b>Free</b></div>
            <?php if ($lp_cards): ?>
            <div class="lp-price__row"><span>Physical card delivery</span><b>At cost</b></div>
            <?php endif; ?>
            <?php if ($lp_fx_live): ?>
            <div class="lp-price__row"><span>Currency exchange</span><b>Live market rate</b></div>
            <?php endif; ?>
        </div>
        <div class="lp-chips" style="margin-top:22px">
            <span>Coming soon</span><span>Payment links</span><span>Expense receipts</span><span>Recurring invoices</span><span>Bulk VTU for teams</span>
        </div>
    </div>
</section>

<!-- ── FAQ ──────────────────────────────────────────────────────── -->
<section class="lp-section" id="faq">
    <div class="lp-wrap">
        <span class="lp-eyebrow">FAQ</span>
        <h2 class="lp-h2">Questions, answered.</h2>
        <div class="lp-faq">
            <details><summary>Who can open a <?php echo $site_name_e; ?> freelancer account?</summary><p>Any freelancer, creator, consultant or independent worker who wants client payments kept separate from personal money. Sign-up takes minutes.</p></details>
            <details><summary>Is the account really free?</summary><p>Yes. Opening, monthly and maintenance fees are all zero on the Freelancer plan — see the pricing table above.</p></details>
            <details><summary>How do my clients pay me?</summary><p>By bank transfer to your dedicated account number, by invoice link, or by scanning your QR code — whichever suits them.</p></details>
            <details><summary>Can I keep business and personal money separate?</summary><p>Yes. Client income lands in its own account and can be split into pockets for taxes, savings and spending.</p></details>
            <?php if ($lp_vtu_any): ?>
            <details><summary>What VTU services are included?</summary><p><?php echo $site_name_e; ?> includes instant <?php echo implode(', ', $lp_vtu_list); ?> top-ups straight from your balance.</p></details>
            <?php endif; ?>
            <details><summary>How long does sign-up take?</summary><p>A few minutes. Open your free account, verify your details, and you can receive your first payment the same day.</p></details>
        </div>

        <div class="lp-final">
            <h2>Open your free freelancer account.</h2>
            <p style="color:#9fb0cc">Join freelancers who get paid faster and keep every currency in one place.</p>
            <form method="get" action="web/Register.php">
                <input type="email" name="email" placeholder="Enter your email" aria-label="Email address">
                <button class="lp-btn lp-btn--light" type="submit">Start free</button>
            </form>
            <p class="lp-trust" style="color:#9fb0cc;margin-top:16px">No setup fee · No monthly fee · Cancel anytime</p>
        </div>
    </div>
</section>

<footer class="lp-footer">
    <div class="lp-wrap lp-footer__grid">
        <div>
            <div class="lp-brand" style="margin-bottom:8px">
                <?php if (!empty($site_logo) && strpos((string)$site_logo, 'placeholder') === false): ?>
                    <img src="<?php echo htmlspecialchars((string)$site_logo, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo $site_name_e; ?>">
                <?php else: ?>
                    <span class="lp-brand__mark"><?php echo $site_name_initial; ?></span>
                <?php endif; ?>
                <span><?php echo $site_name_e; ?></span>
            </div>
            <p>Free account for freelancers who run their own show.</p>
        </div>
        <div>
            <p><a href="#features">Features</a> · <a href="#get-paid">Get paid</a> · <a href="#currencies">Currencies</a> · <a href="#faq">FAQ</a></p>
            <p style="margin-top:8px"><a href="web/Login.php">Log in</a> · <a href="web/Register.php">Open free account</a><?php if (!empty($lp_contact_email)): ?> · <a href="mailto:<?php echo htmlspecialchars($lp_contact_email, ENT_QUOTES, 'UTF-8'); ?>">Contact us</a><?php endif; ?></p>
        </div>
        <p>© <?php echo date('Y'); ?> <?php echo $site_name_e; ?>. All rights reserved.</p>
    </div>
</footer>

<div id="cookieBanner" style="display:none">
    <div class="row">
        <p>We use cookies to improve your experience and keep your account secure. By continuing, you accept our cookie use.</p>
        <button type="button" onclick="declineCookies()">Decline</button>
        <button type="button" class="acc" onclick="acceptCookies()">Accept</button>
    </div>
</div>
<script>
    function acceptCookies() {
        localStorage.setItem('cookie_consent', 'accepted');
        document.getElementById('cookieBanner').style.display = 'none';
    }
    function declineCookies() {
        localStorage.setItem('cookie_consent', 'declined');
        document.getElementById('cookieBanner').style.display = 'none';
    }
    (function() {
        if (!localStorage.getItem('cookie_consent')) {
            setTimeout(function() {
                document.getElementById('cookieBanner').style.display = 'block';
            }, 1500);
        }
    })();
</script>
<script src="asset/pwa-handler.js"></script>

<?php seo_render_custom_footer($site_details); ?>
</body>
</html>

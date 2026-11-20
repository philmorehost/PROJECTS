<?php
// Initialize variables to be used in the template
$site_title       = 'FintechFlow';
$site_logo        = '';
$header_image_url = '';
$apk_download_url = '';
$meta_keywords    = '';
$custom_header    = '';
$custom_footer    = '';

// Check if vendor details are available from index.php
if (isset($vendor_account_details) && is_array($vendor_account_details)) {
    // Fetch Site Details and optional header image in one query for faster page load
    if (isset($connection_server)) {
        $cacheKey = 'vendor_site_style_' . md5((int)$vendor_account_details['id']);
        $cachedStyle = function_exists('bc_cache_get') ? bc_cache_get($cacheKey, 300) : null;

        if (is_array($cachedStyle)) {
            $site_title       = $cachedStyle['site_title'] ?? $site_title;
            $apk_download_url = $cachedStyle['apk_download_url'] ?? $apk_download_url;
            $header_image_url = $cachedStyle['header_image'] ?? $header_image_url;
            $meta_keywords    = $cachedStyle['meta_keywords'] ?? '';
            $custom_header    = $cachedStyle['custom_header_code'] ?? '';
            $custom_footer    = $cachedStyle['custom_footer_code'] ?? '';
        } else {
            $stmt_site = mysqli_prepare($connection_server, "SELECT sd.site_title, sd.apk_download_url, sd.meta_keywords, sd.custom_head_code, sd.custom_footer_code, vst.header_image FROM sas_site_details sd LEFT JOIN sas_vendor_style_templates vst ON vst.vendor_id = sd.vendor_id WHERE sd.vendor_id = ? LIMIT 1");
            if ($stmt_site) {
                mysqli_stmt_bind_param($stmt_site, "i", $vendor_account_details["id"]);
                mysqli_stmt_execute($stmt_site);
                $result_site = mysqli_stmt_get_result($stmt_site);
                if ($site_row = mysqli_fetch_assoc($result_site)) {
                    $site_title       = $site_row['site_title'] ?: $site_title;
                    $apk_download_url = $site_row['apk_download_url'] ?: $apk_download_url;
                    $header_image_url = $site_row['header_image'] ?: $header_image_url;
                    $meta_keywords    = $site_row['meta_keywords'] ?: '';
                    $custom_header    = $site_row['custom_head_code'] ?: '';
                    $custom_footer    = $site_row['custom_footer_code'] ?: '';

                    if (function_exists('bc_cache_set')) {
                        bc_cache_set($cacheKey, [
                            'site_title' => $site_title,
                            'apk_download_url' => $apk_download_url,
                            'header_image' => $header_image_url,
                            'meta_keywords' => $meta_keywords,
                            'custom_header_code' => $custom_header,
                            'custom_footer_code' => $custom_footer,
                        ]);
                    }
                }
                mysqli_stmt_close($stmt_site);
            }
        }
    }
}

// Include the SEO Alt Engine
@include_once __DIR__ . '/func/seo-alt-engine.php';
if (function_exists('seo_auto_alt')) {
    ob_start(function($buffer) use ($site_title) {
        return seo_auto_alt($buffer, $site_title);
    });
} else {
    ob_start();
}

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
    <?php if (function_exists('seo_render_head_tags')) { seo_render_head_tags($site_details, $vendor_account_details); } ?>
    <title><?php echo $site_name_e; ?> — Free Account for Freelancers Who Run Their Own Show</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars((string)$site_logo, ENT_QUOTES, 'UTF-8'); ?>">
    <style>
    /* Client freelancer landing template, rebuilt on automated site
       details: --brand-red becomes the bc-admin-selected primary colour, and every
       brand mention uses the automated site name/logo/contact. */
    :root{
      --bg-dark:#0a0a0a; --bg-card-dark:#141414; --bg-card-darker:#0f0f0f;
      --bg-light:#ffffff; --bg-subtle:#f5f5f7;
      --brand-red:<?php echo htmlspecialchars($brand_colour, ENT_QUOTES, 'UTF-8'); ?>;
      --brand-red-dark:color-mix(in srgb, var(--brand-red) 82%, #000);
      --text-white:#ffffff; --text-muted-dark:#a1a1aa;
      --text-dark:#0a0a0a; --text-muted-light:#52525b;
      --border-dark:rgba(255,255,255,.08); --border-light:rgba(0,0,0,.08);
      --green-badge-bg:rgba(22,163,74,.16); --green-badge-text:#4ade80;
    }
    *{box-sizing:border-box;margin:0;padding:0}
    html{scroll-behavior:smooth;font-family:'Inter',-apple-system,BlinkMacSystemFont,sans-serif;color:var(--text-dark);background:var(--bg-light)}
    h1,h2,h3,.display-font{font-family:'Space Grotesk','Inter',sans-serif;letter-spacing:-.03em}
    a{text-decoration:none;color:inherit}
    .container{max-width:1200px;margin:0 auto;padding:0 24px}
    .navbar{background:#fff;border-bottom:1px solid var(--border-light);position:sticky;top:0;z-index:100}
    .navbar-inner{display:flex;align-items:center;justify-content:space-between;height:76px;gap:16px}
    .brand-logo{display:inline-flex;align-items:center;gap:6px;font-weight:800;font-size:18px;letter-spacing:-.04em}
    .brand-logo img{height:34px;width:auto}
    .brand-logo .vtu-bubble{background:var(--brand-red);color:#fff;padding:2px 8px;border-radius:8px;font-size:13px;letter-spacing:0}
    .nav-links{display:flex;gap:26px;align-items:center;font-weight:600;font-size:15px}
    .nav-links a:hover{color:var(--brand-red)}
    .new-tag{background:var(--brand-red);color:#fff;font-size:10px;padding:2px 7px;border-radius:999px;vertical-align:middle;margin-left:5px}
    .nav-actions{display:flex;gap:18px;align-items:center;font-weight:600}
    .btn-red{background:var(--brand-red);color:#fff;padding:12px 22px;border-radius:999px;font-weight:700;display:inline-block;border:0;cursor:pointer}
    .btn-red:hover{background:var(--brand-red-dark);color:#fff}
    .btn-outline-dark{border:1.5px solid var(--text-dark);padding:11px 22px;border-radius:999px;font-weight:700;display:inline-block}
    .hero-section{padding:74px 0 60px;background:linear-gradient(180deg,#fff, var(--bg-subtle))}
    .hero-grid{display:grid;grid-template-columns:1fr 1fr;gap:48px;align-items:start}
    .eyebrow-red{color:var(--brand-red);font-weight:800;font-size:12px;letter-spacing:.14em}
    .hero-title{font-size:clamp(2.4rem,5vw,3.7rem);line-height:1.02;margin:16px 0 18px}
    .hero-title .accent{color:var(--brand-red)}
    .hero-sub{font-size:1.12rem;color:var(--text-muted-light);line-height:1.65;max-width:520px}
    .hero-cta-row{display:flex;gap:14px;margin:28px 0 18px;flex-wrap:wrap}
    .hero-checks{display:flex;gap:22px;color:var(--text-muted-light);font-size:.92rem;flex-wrap:wrap}
    .hero-checks span::before{content:"✓";color:var(--brand-red);font-weight:800;margin-right:6px}
    .bento-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
    .bento-col{display:flex;flex-direction:column;gap:16px}
    .bento-card{background:var(--bg-card-dark);color:#fff;border-radius:20px;padding:22px}
    .bento-card.white{background:#fff;color:var(--text-dark);border:1px solid var(--border-light)}
    .bento-card.red{background:var(--brand-red)}
    .bento-card h4{font-family:'Inter';letter-spacing:0;font-size:.95rem;margin-bottom:14px}
    .virtual-card-preview{background:linear-gradient(135deg,#26262b,#101013);border:1px solid var(--border-dark);border-radius:16px;padding:18px;min-height:130px;display:flex;flex-direction:column;justify-content:space-between}
    .card-top-row,.card-bottom-row{display:flex;justify-content:space-between;align-items:center}
    .card-toggle-pill{display:inline-flex;background:#1e1e22;border-radius:999px;padding:4px;margin-top:14px}
    .card-toggle-pill button{border:0;background:transparent;color:#d4d4d8;padding:7px 16px;border-radius:999px;font-weight:700;cursor:pointer}
    .card-toggle-pill button.active{background:#fff;color:#0a0a0a}
    .currency-pills{display:flex;gap:10px;flex-wrap:wrap}
    .flag-dot{background:#1e1e22;border:1px solid var(--border-dark);padding:8px 14px;border-radius:999px;font-weight:700;font-size:.9rem}
    .invoice-mini-row{display:flex;justify-content:space-between;align-items:center;gap:10px}
    .paid-pill{background:var(--green-badge-bg);color:var(--green-badge-text);font-size:11px;font-weight:800;padding:4px 10px;border-radius:999px}
    .fee-row{display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px dashed var(--border-light);font-size:.95rem}
    .fee-row:last-child{border-bottom:0}
    .vtu-tags{display:flex;gap:8px;flex-wrap:wrap}
    .vtu-tags span{background:rgba(255,255,255,.18);padding:7px 13px;border-radius:999px;font-weight:700;font-size:.85rem}
    .tx-item{display:flex;justify-content:space-between;align-items:center;padding:9px 0;border-bottom:1px solid var(--border-dark)}
    .tx-item:last-child{border-bottom:0}
    .tx-left{display:flex;gap:10px;align-items:center}
    .avatar-badge{width:36px;height:36px;border-radius:50%;display:grid;place-items:center;font-weight:800;font-size:.8rem;background:var(--brand-red);color:#fff}
    .avatar-badge.white{background:#fff;color:#0a0a0a}.avatar-badge.gray{background:#3f3f46;color:#fff}
    .tx-name{font-weight:700;font-size:.9rem}.tx-desc{font-size:.75rem;color:var(--text-muted-dark)}
    .tx-amount{font-weight:800;color:#4ade80}
    .floating-subnav-wrap{position:sticky;top:88px;z-index:90;display:flex;justify-content:center;padding:10px 16px}
    .floating-subnav{background:rgba(10,10,10,.92);backdrop-filter:blur(8px);border-radius:999px;padding:8px;display:flex;gap:4px;align-items:center;flex-wrap:wrap;justify-content:center}
    .floating-subnav a{color:#e4e4e7;padding:9px 14px;border-radius:999px;font-size:13px;font-weight:600}
    .floating-subnav a:hover{background:#26262b;color:#fff}
    section.block{padding:84px 0}
    .section-title-lg{font-size:clamp(2rem,4vw,3rem);line-height:1.05;margin:14px 0}
    .receive-header{display:grid;grid-template-columns:1.2fr .8fr;gap:40px;align-items:end;margin-bottom:44px}
    .receive-sub{color:var(--text-muted-light);line-height:1.7}
    .three-ways-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}
    .way-card{border-radius:24px;padding:28px;min-height:340px;display:flex;flex-direction:column;justify-content:space-between;gap:22px}
    .way-card.light{background:var(--bg-subtle);border:1px solid var(--border-light)}
    .way-card.dark{background:var(--bg-card-dark);color:#fff}
    .way-card h3{font-size:1.35rem;margin-bottom:8px}
    .way-card p{color:var(--text-muted-light);line-height:1.6;font-size:.95rem}
    .way-card.dark p{color:var(--text-muted-dark)}
    .transfer-list-box,.invoice-box,.qr-box{background:#fff;border:1px solid var(--border-light);border-radius:16px;padding:18px;color:var(--text-dark)}
    .way-card.dark .transfer-list-box,.way-card.dark .invoice-box,.way-card.dark .qr-box{background:#1a1a1e;border-color:var(--border-dark);color:#fff}
    .transfer-row{display:flex;justify-content:space-between;padding:7px 0;font-size:.9rem}
    .dark-section{background:var(--bg-dark);color:#fff}
    .steps-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;margin-top:40px}
    .step-card{background:var(--bg-card-dark);border:1px solid var(--border-dark);border-radius:20px;padding:26px}
    .step-card .n{color:var(--brand-red);font-weight:800;font-size:.8rem;letter-spacing:.12em}
    .step-card h3{margin:10px 0 8px;font-size:1.2rem}
    .step-card p{color:var(--text-muted-dark);line-height:1.65;font-size:.94rem}
    .china-section{background:linear-gradient(135deg, var(--bg-dark), #1b0505);}
    .china-card{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.14);border-radius:22px;padding:30px;box-shadow:inset 0 1px 0 rgba(255,255,255,.15), 0 24px 50px -30px rgba(0,0,0,.8)}
    .spend-section{background:var(--brand-red);color:#fff}
    .feature-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;margin-top:40px}
    .feature-card{background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.22);border-radius:20px;padding:26px}
    .feature-card h3{margin-bottom:8px;font-size:1.15rem}
    .feature-card p{opacity:.88;line-height:1.65;font-size:.94rem}
    .feature-grid.light .feature-card{background:#fff;border-color:var(--border-light);color:var(--text-dark)}
    .feature-grid.light .feature-card p{color:var(--text-muted-light);opacity:1}
    .svc-strip{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-top:36px}
    .svc-card{background:#fff;border:1px solid var(--border-light);border-radius:20px;padding:24px}
    .svc-card h3{font-size:1.05rem;margin-bottom:6px}
    .svc-card p{color:var(--text-muted-light);font-size:.9rem;line-height:1.6}
    .svc-card a{color:var(--brand-red);font-weight:800;font-size:.9rem}
    .faq-item{border-bottom:1px solid var(--border-light);padding:20px 0}
    .faq-item summary{font-weight:800;cursor:pointer;font-size:1.05rem}
    .faq-item p{color:var(--text-muted-light);line-height:1.7;margin-top:10px;max-width:760px}
    .cta-section{background:var(--bg-dark);color:#fff;border-radius:28px;padding:60px 40px;text-align:center}
    .cta-form{display:flex;gap:10px;justify-content:center;margin-top:26px;flex-wrap:wrap}
    .cta-form input{padding:14px 20px;border-radius:999px;border:1px solid var(--border-dark);background:#141414;color:#fff;min-width:280px}
    footer.site{padding:56px 0 34px;border-top:1px solid var(--border-light)}
    .footer-grid{display:grid;grid-template-columns:1.4fr 1fr 1fr 1fr;gap:30px}
    footer.site h4{font-size:.95rem;margin-bottom:14px}
    footer.site li{list-style:none;margin-bottom:9px;color:var(--text-muted-light);font-size:.92rem}
    .fx-box{background:#fff;border:1px solid var(--border-light);border-radius:20px;padding:26px;margin-top:36px}
    .fx-grid{display:grid;grid-template-columns:1fr auto 1fr;gap:14px;align-items:end;margin-top:16px}
    .fx-box input,.fx-box select{width:100%;padding:12px;border:1px solid var(--border-light);border-radius:12px;font:inherit}
    @media(max-width:900px){
      .hero-grid,.receive-header{grid-template-columns:1fr}
      .three-ways-grid,.steps-grid,.feature-grid,.svc-strip,.footer-grid{grid-template-columns:1fr 1fr}
      .nav-links{display:none}
    }
    @media(max-width:560px){.three-ways-grid,.steps-grid,.feature-grid,.svc-strip,.footer-grid,.bento-grid{grid-template-columns:1fr}}
    </style>
</head>
<body>

<!-- NAVBAR (client template) -->
<header class="navbar">
  <div class="container navbar-inner">
    <a href="#" class="brand-logo">
      <?php if (!empty($site_logo) && strpos((string)$site_logo, 'placeholder') === false): ?>
        <img src="<?php echo htmlspecialchars((string)$site_logo, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo $site_name_e; ?>">
      <?php endif; ?>
      <span><?php echo $site_name_e; ?></span><span class="vtu-bubble">VTU</span>
    </a>
    <nav class="nav-links">
      <a href="#who">Freelancers <span class="new-tag">NEW</span></a>
      <a href="#manage">Business</a>
      <a href="#spend">VTU Services</a>
      <a href="Services.php">All Services</a>
      <a href="ReferAndEarn.php">Refer &amp; Earn</a>
      <a href="#faq">Company</a>
    </nav>
    <div class="nav-actions">
      <a href="web/Login.php">Log in</a>
      <a href="web/Register.php" class="btn-red">Start free</a>
    </div>
  </div>
</header>

<!-- HERO -->
<section class="hero-section" id="who">
  <div class="container hero-grid">
    <div>
      <span class="eyebrow-red">FOR FREELANCERS &amp; THE SELF-EMPLOYED</span>
      <h1 class="hero-title">Free account for <span class="accent">freelancers</span> who run their own show.</h1>
      <p class="hero-sub">Get paid by invoice, bank transfer or QR code. Keep your freelance income in one account, spend it with <?php echo $site_name_e; ?> cards, send money abroad, and top up airtime, data and bills in seconds.</p>
      <div class="hero-cta-row">
        <a href="web/Register.php" class="btn-red">Open free account</a>
        <a href="#receive" class="btn-outline-dark">See how it works</a>
      </div>
      <div class="hero-checks"><span>No setup fee</span><span>No monthly fee</span><span>Sign up in minutes</span></div>
    </div>
    <div class="bento-grid">
      <div class="bento-col">
        <?php if ($lp_cards): ?>
        <div class="bento-card">
          <h4>All cards included</h4>
          <div class="virtual-card-preview">
            <div class="card-top-row"><span id="heroCardType">Virtual</span><strong><?php echo $site_name_e; ?></strong></div>
            <div class="card-bottom-row"><span id="heroCardDigits">•••• 2257</span><strong style="font-style:italic;font-size:16px;">VISA</strong></div>
          </div>
          <div style="text-align:center;">
            <div class="card-toggle-pill">
              <button type="button" class="active" onclick="toggleHeroCard('virtual', this)">Virtual</button>
              <button type="button" onclick="toggleHeroCard('physical', this)">Physical</button>
            </div>
          </div>
        </div>
        <?php endif; ?>
        <div class="bento-card">
          <h4>Supported currencies</h4>
          <div class="currency-pills"><span class="flag-dot">🇳🇬 NGN</span><span class="flag-dot">🇺🇸 USD</span><span class="flag-dot">🇬🇧 GBP</span><span class="flag-dot">🇪🇺 EUR</span></div>
        </div>
        <div class="bento-card">
          <h4>Client payments via invoice</h4>
          <div class="invoice-mini-row">
            <div><div style="font-size:11px;color:#a1a1aa;">Bright Studio Ltd · Website redesign</div><strong style="font-size:15px;">1,000.00 USD</strong></div>
            <span class="paid-pill">PAID</span>
          </div>
        </div>
      </div>
      <div class="bento-col">
        <div class="bento-card white">
          <h4>No setup or monthly fees</h4>
          <div class="fee-row"><span>Setup fee</span><strong>0.00</strong></div>
          <div class="fee-row"><span>Monthly fee</span><strong>0.00</strong></div>
          <div class="fee-row"><span>Maintenance</span><strong>0.00</strong></div>
        </div>
        <?php if ($lp_vtu_any): ?>
        <div class="bento-card red">
          <h4>Instant VTU top-ups</h4>
          <div class="vtu-tags"><?php foreach (array_slice($lp_vtu_list, 0, 4) as $v): ?><span><?php echo ucfirst(htmlspecialchars($v, ENT_QUOTES, 'UTF-8')); ?></span><?php endforeach; ?></div>
        </div>
        <?php endif; ?>
        <div class="bento-card">
          <h4>Keep your freelance income separate</h4>
          <div class="tx-item"><div class="tx-left"><div class="avatar-badge">AO</div><div><div class="tx-name">Amara Okafor</div><div class="tx-desc">Logo design project</div></div></div><span class="tx-amount">+ 120.00</span></div>
          <div class="tx-item"><div class="tx-left"><div class="avatar-badge white">JM</div><div><div class="tx-name">James Mwangi</div><div class="tx-desc">Personal training session</div></div></div><span class="tx-amount">+ 65.00</span></div>
          <div class="tx-item"><div class="tx-left"><div class="avatar-badge gray">TB</div><div><div class="tx-name">Tunde Bello</div><div class="tx-desc">Haircut &amp; beard trim</div></div></div><span class="tx-amount">+ 35.00</span></div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- FLOATING SUBNAV -->
<div class="floating-subnav-wrap">
  <nav class="floating-subnav">
    <a href="#who">Who</a><a href="#receive">Receive</a><a href="#manage">Manage</a><a href="#currencies">Currencies</a><a href="#spend">Spend</a><a href="Services.php">Services</a><a href="ReferAndEarn.php">Refer &amp; Earn</a><a href="#free">Free</a><a href="#soon">Soon</a><a href="#faq">FAQ</a>
    <a href="web/Register.php" class="btn-red" style="padding:9px 18px;font-size:13px;">Start free</a>
  </nav>
</div>

<!-- RECEIVE -->
<section class="block" id="receive" style="padding-top:30px;">
  <div class="container">
    <div class="receive-header">
      <div><span class="eyebrow-red">RECEIVE</span><h2 class="section-title-lg">Three ways to get paid.<br>Zero chasing.</h2></div>
      <p class="receive-sub">Clients pay the way they prefer. Every payment lands in your <?php echo $site_name_e; ?> account and is matched to the right job.</p>
    </div>
    <div class="three-ways-grid">
      <div class="way-card light">
        <div><h3>By bank transfer</h3><p>Get paid straight into your own dedicated account number.</p></div>
        <div class="transfer-list-box">
          <div style="font-size:11px;color:#71717a;margin-bottom:8px;">Expected transfers</div>
          <div class="transfer-row"><span>Bright Studio Ltd</span><strong>+ 1,000.00</strong></div>
          <div class="transfer-row"><span>Kijani Media</span><strong>+ 2,106.50</strong></div>
          <div class="transfer-row"><span>Urban Gallery</span><strong>+ 650.00</strong></div>
        </div>
      </div>
      <div class="way-card dark">
        <div><h3>By invoice</h3><p>Send branded invoices and keep client payments organised.</p></div>
        <div class="invoice-box">
          <div style="font-size:11px;color:#a1a1aa;">New invoice</div>
          <div class="transfer-row"><span>Client</span><strong>Bright Studio Ltd</strong></div>
          <div class="transfer-row"><span>Service</span><strong>Website redesign package</strong></div>
          <div class="transfer-row"><span>Total</span><strong>1,000.00 USD</strong></div>
          <a href="web/Register.php" class="btn-red" style="padding:9px 18px;font-size:13px;margin-top:10px;">Create invoice</a>
        </div>
      </div>
      <div class="way-card light">
        <div><h3>By QR code</h3><p>Let clients pay on the spot by card, Apple Pay or Google Pay.</p></div>
        <div class="qr-box" style="text-align:center;">
          <div style="font-size:11px;letter-spacing:.14em;color:#71717a;">SCAN TO PAY</div>
          <div style="font-size:52px;line-height:1.2;">▦</div>
          <div>Pay to <strong><?php echo $site_name_e; ?></strong></div>
          <div style="font-weight:800;">Amount 1,000.00 USD</div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- MANAGE -->
<section class="block dark-section" id="manage">
  <div class="container">
    <span class="eyebrow-red">MANAGE</span>
    <h2 class="section-title-lg">Your freelance money, all together.</h2>
    <p style="color:var(--text-muted-dark);max-width:640px;line-height:1.7;">Keep freelance income and personal money separate, hold balances in several currencies, and exchange when you need to.</p>
    <div class="steps-grid">
      <div class="step-card"><div class="n">STEP 01</div><h3>Get paid into your own account</h3><p>Client payments arrive in a dedicated account in your name, separate from your personal money.</p></div>
      <div class="step-card"><div class="n">STEP 02</div><h3>Split income into pockets</h3><p>Set aside money for tax, savings and running costs the moment a payment lands.</p></div>
      <div class="step-card"><div class="n">STEP 03</div><h3>Exchange and send when you need to</h3><p>Move between currencies and pay suppliers or yourself in a couple of taps.</p></div>
    </div>
  </div>
</section>

<!-- CHINA PAYOUT — embossed 3-step workflow (client requirement) -->
<section class="block china-section dark-section" id="china">
  <div class="container">
    <span class="eyebrow-red">PAYOUT TO CHINA</span>
    <h2 class="section-title-lg">From your wallet to China, in three steps.</h2>
    <p style="color:var(--text-muted-dark);max-width:680px;line-height:1.7;">Provision a USD wallet, fund it instantly, and settle directly to Chinese bank accounts and mobile wallets.</p>
    <div class="steps-grid">
      <div class="china-card"><div class="n" style="color:var(--brand-red);font-weight:800;letter-spacing:.12em;font-size:.8rem;">STEP 01 · WALLET PROVISIONING</div><h3 style="margin:10px 0 8px;">Create USD Wallet</h3><p style="color:var(--text-muted-dark);line-height:1.65;">Open a multi-currency USD virtual wallet inside your <?php echo $site_name_e; ?> dashboard with instant verification.</p></div>
      <div class="china-card"><div class="n" style="color:var(--brand-red);font-weight:800;letter-spacing:.12em;font-size:.8rem;">STEP 02 · INSTANT FUNDING</div><h3 style="margin:10px 0 8px;">Fund USD Account</h3><p style="color:var(--text-muted-dark);line-height:1.65;">Fund via local currency conversion (NGN/GHS/ZAR), virtual accounts, or stablecoins (USDC/USDT) at live FX rates.</p></div>
      <div class="china-card"><div class="n" style="color:var(--brand-red);font-weight:800;letter-spacing:.12em;font-size:.8rem;">STEP 03 · CHINA SETTLEMENT</div><h3 style="margin:10px 0 8px;">Transfer to China</h3><p style="color:var(--text-muted-dark);line-height:1.65;">Lock a live FX quote and execute direct cross-border payouts to Chinese bank accounts and mobile wallets.</p></div>
    </div>
  </div>
</section>

<!-- WORKERS BANNER -->
<section class="block" id="workers" style="padding-bottom:0;">
  <div class="container" style="text-align:center;">
    <span class="eyebrow-red">FOR INDEPENDENT WORKERS</span>
    <h2 class="section-title-lg">Whatever you do, <?php echo $site_name_e; ?> helps you get paid<br>and stay on top of it.</h2>
    <p style="color:var(--text-muted-light);">From the studio to the salon — your money moves with you.</p>
  </div>
</section>

<!-- SPEND -->
<section class="block spend-section" id="spend">
  <div class="container">
    <span class="eyebrow-red" style="color:#fff;">SPEND</span>
    <h2 class="section-title-lg">Spend it your way — cards, wallets and VTU top-ups.</h2>
    <div class="feature-grid">
      <?php if ($lp_cards): ?><div class="feature-card"><h3>💳 Physical &amp; virtual cards</h3><p>Create virtual cards instantly for subscriptions and online tools; order a physical card for everyday spending.</p></div><?php endif; ?>
      <div class="feature-card"><h3>📱 Mobile wallets</h3><p>Add your card to Apple Pay or Google Pay and tap to pay anywhere contactless is accepted.</p></div>
      <div class="feature-card"><h3>🛡️ Spend controls &amp; limits</h3><p>Set daily or monthly limits, freeze a card in a tap and get notified on every payment.</p></div>
      <?php if ($lp_airtime || $lp_data): ?><div class="feature-card"><h3>📶 Airtime &amp; data top-ups</h3><p>Recharge any network straight from your balance — for yourself, your team or your clients.</p></div><?php endif; ?>
      <?php if ($lp_electric || $lp_cable): ?><div class="feature-card"><h3>⚡ Bills &amp; utilities</h3><p>Pay electricity, cable TV and internet bills without leaving the app.</p></div><?php endif; ?>
      <?php if ($lp_fx_toggle): ?><div class="feature-card"><h3>🌐 Spend in many currencies</h3><p>Pay international tools and suppliers with transparent exchange rates shown up front.</p></div><?php endif; ?>
    </div>
  </div>
</section>

<!-- CURRENCIES -->
<section class="block" id="currencies">
  <div class="container">
    <span class="eyebrow-red">CURRENCIES</span>
    <h2 class="section-title-lg">One account. Many currencies.</h2>
    <div class="feature-grid light">
      <div class="feature-card"><h3>Multi-currency accounts</h3><p>Get USD, GBP and EUR accounts to receive payments globally, alongside your NGN wallet.</p></div>
      <div class="feature-card"><h3>Live exchange rates</h3><p>Real-time FX before every conversion — the rate you see is the rate you get.</p></div>
      <div class="feature-card"><h3>Stablecoins</h3><p>Send and receive USDC and USDT globally with ease, and swap into your wallet balance.</p></div>
      <div class="feature-card"><h3>Wire transfers</h3><p>Send wires and cross-border payouts — including direct settlement to China.</p></div>
      <div class="feature-card"><h3>Payment links &amp; invoices</h3><p>Share a link or send a branded invoice and get paid without chasing.</p></div>
      <div class="feature-card"><h3>Single &amp; bulk transfers</h3><p>Pay one person or a whole payroll to bank accounts, mobile money and stablecoin wallets.</p></div>
    </div>

    <?php if ($lp_fx_live): ?>
    <div class="fx-box" id="fxConverter">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
        <h3 style="font-weight:800;font-size:1.1rem;">Currency converter</h3>
        <span style="background:rgba(22,163,74,.14);color:#15803d;font-size:11px;font-weight:800;padding:4px 10px;border-radius:999px;">LIVE RATE</span>
      </div>
      <div class="fx-grid">
        <div><label for="fxAmount">You exchange</label>
          <input id="fxAmount" type="number" value="100" min="0" step="any" inputmode="decimal">
          <select id="fxFrom" aria-label="From currency" style="margin-top:8px;"><?php foreach ($lp_currencies as $cur): ?><option value="<?php echo $cur; ?>"<?php echo $cur === 'USD' ? ' selected' : ''; ?>><?php echo $cur; ?></option><?php endforeach; ?></select>
        </div>
        <button type="button" id="fxSwap" title="Swap currencies" aria-label="Swap currencies" style="border:1px solid var(--border-light);background:#fff;border-radius:50%;width:44px;height:44px;cursor:pointer;">⇄</button>
        <div><label for="fxResult">You get</label>
          <input id="fxResult" type="text" readonly value="—">
          <select id="fxTo" aria-label="To currency" style="margin-top:8px;"><?php foreach ($lp_currencies as $cur): ?><option value="<?php echo $cur; ?>"<?php echo $cur === 'NGN' ? ' selected' : ''; ?>><?php echo $cur; ?></option><?php endforeach; ?></select>
        </div>
      </div>
      <p id="fxRateLine" style="color:var(--text-muted-light);margin-top:14px;font-size:.9rem;">Fetching the live rate…</p>
    </div>
    <script>
    (function () {
      var amountEl=document.getElementById('fxAmount'),fromEl=document.getElementById('fxFrom'),toEl=document.getElementById('fxTo'),resultEl=document.getElementById('fxResult'),lineEl=document.getElementById('fxRateLine');
      function convert(){
        var amount=parseFloat(amountEl.value)||0,from=fromEl.value,to=toEl.value;
        if(from===to){resultEl.value=amount.toLocaleString(undefined,{maximumFractionDigits:2});lineEl.textContent='1 '+from+' = 1 '+to;return;}
        fetch('web/api/exchange-rate.php?from='+encodeURIComponent(from)+'&to='+encodeURIComponent(to),{headers:{'Accept':'application/json'}})
          .then(function(r){return r.json();})
          .then(function(d){
            var rate=d&&(d.rate||(d.data&&d.data.rate));
            if(!rate){lineEl.textContent='Live rate unavailable right now.';resultEl.value='—';return;}
            resultEl.value=(amount*rate).toLocaleString(undefined,{maximumFractionDigits:2});
            lineEl.textContent='1 '+from+' = '+rate.toLocaleString(undefined,{maximumFractionDigits:4})+' '+to+' · live mid-market rate';
          }).catch(function(){lineEl.textContent='Live rate unavailable right now.';});
      }
      [amountEl,fromEl,toEl].forEach(function(el){el.addEventListener('input',convert);el.addEventListener('change',convert);});
      document.getElementById('fxSwap').addEventListener('click',function(){var t=fromEl.value;fromEl.value=toEl.value;toEl.value=t;convert();});
      convert();
    })();
    </script>
    <?php endif; ?>
  </div>
</section>

<!-- NEW SERVICES -->
<section class="block" id="soon" style="background:var(--bg-subtle);">
  <div class="container">
    <span class="eyebrow-red">NEW ON <?php echo strtoupper($site_name_e); ?></span>
    <h2 class="section-title-lg">More ways to run your show.</h2>
    <div class="svc-strip">
      <div class="svc-card"><h3>🌍 Send money abroad</h3><p>Global transfers, wire payouts and direct settlement to China at live FX.</p><a href="web/Register.php">Start sending →</a></div>
      <div class="svc-card"><h3>🎓 Pay tuition worldwide</h3><p>Tuition to 160+ universities with student ID validation before settlement.</p><a href="web/Register.php">Pay tuition →</a></div>
      <div class="svc-card"><h3>📶 Travel eSIM</h3><p>Data in 190+ countries. Buy a plan, scan the QR code, land connected.</p><a href="web/Register.php">Get an eSIM →</a></div>
      <div class="svc-card"><h3>🧾 POS devices</h3><p>Request a point-of-sale terminal and accept card payments in-store.</p><a href="web/Register.php">Request POS →</a></div>
      <div class="svc-card"><h3>₿ Crypto hub</h3><p>Buy, sell and swap crypto and stablecoins from your wallet.</p><a href="web/Register.php">Trade crypto →</a></div>
      <div class="svc-card"><h3>🎁 Gift cards</h3><p>Buy and trade gift cards at competitive rates, credited instantly.</p><a href="web/Register.php">Trade gift cards →</a></div>
      <div class="svc-card"><h3>💵 Refer &amp; earn $10 + $10</h3><p>You get $10, your friend gets $10 — once they transact $100 in Crypto &amp; International Transfers.</p><a href="web/Register.php">Refer friends →</a></div>
      <div class="svc-card"><h3>🤖 Free AI + human support</h3><p>Instant AI answers with a human team one tap away. Support is always free.</p><a href="web/Register.php">Chat with us →</a></div>
    </div>
  </div>
</section>

<!-- FREE -->
<section class="block" id="free">
  <div class="container" style="text-align:center;">
    <span class="eyebrow-red">FREE</span>
    <h2 class="section-title-lg">From the studio to the salon —<br>your money moves with you.</h2>
    <p style="color:var(--text-muted-light);max-width:620px;margin:0 auto;line-height:1.7;">No setup fee. No monthly fee. No maintenance charges. Open your account in minutes and keep every fee transparent after that.</p>
    <div style="display:flex;gap:14px;justify-content:center;margin-top:28px;flex-wrap:wrap;">
      <span class="flag-dot" style="background:var(--bg-subtle);border-color:var(--border-light);">Available amount 9,088.10 · Card •••• 8841</span>
      <span class="flag-dot" style="background:var(--bg-subtle);border-color:var(--border-light);">✓ Payment complete · Apple Pay · Google Pay</span>
    </div>
  </div>
</section>

<!-- FAQ -->
<section class="block" id="faq" style="padding-top:0;">
  <div class="container">
    <span class="eyebrow-red">FAQ</span>
    <h2 class="section-title-lg">Questions, answered.</h2>
    <details class="faq-item" open><summary>Who can open a <?php echo $site_name_e; ?> freelancer account?</summary><p>Freelancers, sole traders, creators and anyone who is self-employed can apply. You will need a valid ID and a few details about the work you do.</p></details>
    <details class="faq-item"><summary>Is the account really free?</summary><p>Yes. There are zero setup fees, zero monthly subscription fees, and zero account maintenance charges.</p></details>
    <details class="faq-item"><summary>How do my clients pay me?</summary><p>Clients can pay you directly via dedicated local or multi-currency bank transfer, branded payment invoices, or instant QR code checkout.</p></details>
    <details class="faq-item"><summary>Can I keep business and personal money separate?</summary><p>Yes. You can organize incoming client payments into separate pockets for taxes, savings, and operating expenses.</p></details>
    <details class="faq-item"><summary>What VTU services are included?</summary><p>Instant airtime top-ups, mobile data bundles across all networks, electricity meter tokens, and cable TV subscriptions straight from your wallet balance.</p></details>
    <details class="faq-item"><summary>How does the referral program work?</summary><p>Share your link. When your friend completes $100 in Crypto or International Transfers, you each receive $10. Other products do not count toward the $100. Creators and influencers can also apply to promote the program — T&amp;Cs apply.</p></details>
    <details class="faq-item"><summary>How long does sign-up take?</summary><p>Registration takes only a few minutes with instant digital verification so you can start receiving payments right away.</p></details>
  </div>
</section>

<!-- SIGNUP CTA -->
<section class="block" id="signup" style="padding-top:0;">
  <div class="container">
    <div class="cta-section">
      <h2 class="section-title-lg" style="margin-top:0;">Open your free freelancer account.</h2>
      <p style="color:var(--text-muted-dark);">Get paid, manage income and spend from one account — in minutes.</p>
      <form class="cta-form" method="get" action="web/Register.php">
        <input type="email" name="email" placeholder="Email address" aria-label="Email address">
        <button type="submit" class="btn-red">Start free</button>
      </form>
      <p style="color:var(--text-muted-dark);font-size:.85rem;margin-top:16px;">No setup fee · No monthly fee · Cancel anytime</p>
    </div>
  </div>
</section>

<!-- FOOTER -->
<footer class="site">
  <div class="container">
    <div class="footer-grid">
      <div>
        <a href="#" class="brand-logo"><span><?php echo $site_name_e; ?></span><span class="vtu-bubble">VTU</span></a>
        <p style="color:var(--text-muted-light);line-height:1.7;margin-top:14px;max-width:320px;">One account for freelancers to get paid, manage money, spend and top up.</p>
        <?php if (!empty($lp_contact_email)): ?><p style="margin-top:10px;"><a href="mailto:<?php echo htmlspecialchars($lp_contact_email, ENT_QUOTES, 'UTF-8'); ?>" style="color:var(--brand-red);font-weight:700;"><?php echo htmlspecialchars($lp_contact_email, ENT_QUOTES, 'UTF-8'); ?></a></p><?php endif; ?>
      </div>
      <div><h4>Product</h4><ul><li><a href="#receive">Receive</a></li><li><a href="#manage">Manage</a></li><li><a href="#spend">Spend</a></li><li><a href="#currencies">Currencies</a></li></ul></div>
      <div><h4>VTU services</h4><ul><?php foreach (array_slice($lp_vtu_list, 0, 5) as $v): ?><li style="text-transform:capitalize;"><?php echo htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); ?></li><?php endforeach; ?><li><a href="#soon">eSIM · Tuition · POS</a></li></ul></div>
      <div><h4>Company</h4><ul><li><a href="#faq">About</a></li><li><a href="web/Login.php">Support</a></li><li><a href="#">Privacy</a></li><li><a href="#">Terms</a></li></ul></div>
    </div>
    <p style="color:var(--text-muted-light);font-size:.85rem;margin-top:34px;">© <?php echo date('Y'); ?> <?php echo $site_name_e; ?>. All rights reserved.</p>
  </div>
</footer>

<script>
function toggleHeroCard(type, btn) {
  document.querySelectorAll('.card-toggle-pill button').forEach(function (b) { b.classList.remove('active'); });
  btn.classList.add('active');
  document.getElementById('heroCardType').textContent = type === 'virtual' ? 'Virtual' : 'Physical';
  document.getElementById('heroCardDigits').textContent = type === 'virtual' ? '•••• 2257' : '•••• 8841';
}
</script>
</body>
</html>

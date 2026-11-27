<?php
// Template 1 Public Home — Responsive editorial layout with Service Control-synced Personal Features.
require_once __DIR__ . '/func/bc-landing-pages.php';
require_once __DIR__ . '/func/bc-public-app-brand.php';
require_once __DIR__ . '/func/bc-public-meridian.php';
require_once __DIR__ . '/func/bc-public-pages.php';
require_once __DIR__ . '/func/bc-seo.php';
require_once __DIR__ . '/func/bc-theme.php';

$home_details = array();
$home_name = (string)($_SERVER['HTTP_HOST'] ?? 'Our Platform');
$home_vid = (int)($vendor_account_details['id'] ?? 0);
if (isset($connection_server) && $connection_server instanceof mysqli) {
    $home_q = $home_vid > 0
        ? mysqli_query($connection_server, "SELECT * FROM sas_site_details WHERE vendor_id='$home_vid' LIMIT 1")
        : mysqli_query($connection_server, "SELECT * FROM sas_super_admin_site_details LIMIT 1");
    $home_row = $home_q ? mysqli_fetch_assoc($home_q) : null;
    $home_details = is_array($home_row) ? $home_row : array();
    if (!empty($home_row['site_title'])) {
        $home_name = (string)$home_row['site_title'];
    }
}

$home_brand = null;
if ($home_vid > 0 && isset($connection_server) && $connection_server instanceof mysqli) {
    $home_style_q = mysqli_query($connection_server, "SELECT primary_color FROM sas_vendor_style_templates WHERE vendor_id='$home_vid' LIMIT 1");
    $home_style = $home_style_q ? mysqli_fetch_assoc($home_style_q) : null;
    $home_brand = $home_style['primary_color'] ?? null;
}

$home_palette = bc_theme_palette($home_brand);
$home_theme = $home_palette['modes']['light'];
$home_dark_theme = $home_palette['modes']['dark'];
$home_brand_hex = $home_theme['primary'];
$home_brand_rgb = '217, 0, 18';
if (sscanf($home_brand_hex, '#%02x%02x%02x', $hr, $hg, $hb) === 3) {
    $home_brand_rgb = "$hr, $hg, $hb";
}

$home_short_brand = bc_public_short_brand($home_name);
$home_app_badge = bc_public_app_badge_html(bc_public_saved_app_url($home_vid, $home_details));
$home_logo = '';
$home_logo_file = preg_replace('/[^a-zA-Z0-9_-]/', '-', (string)($_SERVER['HTTP_HOST'] ?? '')) . '_logo.png';
if (is_file(__DIR__ . '/uploaded-image/' . $home_logo_file)) {
    $home_logo = '/uploaded-image/' . $home_logo_file;
}
$home_dash = function_exists('bc_public_dashboard_url') ? bc_public_dashboard_url() : '';

// Service Control Center gating for Personal Features
$t1_enabled = function ($key) use ($home_vid) {
    if ($key === null || $key === '') {
        return true;
    }
    return bc_meridian_service_enabled($key, $home_vid);
};

$personal_pillars = array();
$coming_soon_items = array();

$add_personal_feature = function (&$pillar, $title, $desc, $href, $key = null, $badge = '', $pending_note = '') use (&$coming_soon_items, $t1_enabled) {
    if ($t1_enabled($key)) {
        $pillar['items'][] = array(
            'title' => $title,
            'desc' => $desc,
            'href' => $href,
            'badge' => $badge,
        );
    } else {
        $coming_soon_items[] = array(
            'title' => $title,
            'category' => $pillar['_cat_title'],
            'desc' => $desc,
            'href' => $href,
            'reason' => $pending_note !== '' ? $pending_note : 'Paused in Service Control Center',
        );
    }
};

// Pillar 1: Accounts & Payments
$pillar_accounts = array(
    'id' => 'accounts-payments',
    'eyebrow' => '01 · Accounts & Payments',
    'title' => 'Accounts & Payments',
    'subtitle' => 'Core wallet management, receipts, transfers, virtual accounts & FX.',
    'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="5" width="19" height="14" rx="3"/><path d="M2.5 10h19M16 14.5h2.5"/></svg>',
    'items' => array(),
    '_cat_title' => 'Accounts & Payments',
);
$add_personal_feature($pillar_accounts, 'Personal wallet and account dashboard', 'Unified NGN and multi-currency balance overview with real-time status.', '/web/Dashboard.php', null, 'Core');
$add_personal_feature($pillar_accounts, 'Wallet funding and payment receipts', 'Top up via cards, bank gateways, or manual funding with downloadable receipts.', '/web/Fund.php', null, 'Receipts');

$bank_tx_on = $t1_enabled('bank_transfer');
$share_on = $t1_enabled('share_fund');
if ($bank_tx_on && $share_on) {
    $pillar_accounts['items'][] = array(
        'title' => 'Local transfers and wallet-to-wallet transfers',
        'desc' => 'Send to local bank accounts or transfer funds instantly between platform wallets.',
        'href' => '/services/bank-transfer.php',
        'badge' => 'Instant',
    );
} elseif ($bank_tx_on) {
    $pillar_accounts['items'][] = array(
        'title' => 'Local bank transfers',
        'desc' => 'Send funds directly to verified local bank accounts with live tracking.',
        'href' => '/services/bank-transfer.php',
        'badge' => 'Bank',
    );
    $coming_soon_items[] = array(
        'title' => 'Wallet-to-wallet transfers',
        'category' => 'Accounts & Payments',
        'desc' => 'Peer-to-peer wallet transfers between platform users.',
        'href' => '/services/share-fund.php',
        'reason' => 'Paused in Service Control Center',
    );
} elseif ($share_on) {
    $pillar_accounts['items'][] = array(
        'title' => 'Wallet-to-wallet transfers',
        'desc' => 'Send funds directly to other platform users in seconds.',
        'href' => '/services/share-fund.php',
        'badge' => 'P2P',
    );
    $coming_soon_items[] = array(
        'title' => 'Local bank transfers',
        'category' => 'Accounts & Payments',
        'desc' => 'Outgoing transfers to external local bank accounts.',
        'href' => '/services/bank-transfer.php',
        'reason' => 'Paused in Service Control Center',
    );
} else {
    $coming_soon_items[] = array(
        'title' => 'Local transfers and wallet-to-wallet transfers',
        'category' => 'Accounts & Payments',
        'desc' => 'Local bank payouts and peer-to-peer wallet transfers.',
        'href' => '/services/bank-transfer.php',
        'reason' => 'Paused in Service Control Center',
    );
}

$add_personal_feature($pillar_accounts, 'International transfer requests', 'Cross-border settlement requests to USD, GBP, EUR, CNY and more.', '/services/international-transfer.php', 'intl_transfer', 'Global');
$add_personal_feature($pillar_accounts, 'Virtual bank accounts', 'Dedicated NGN & multi-currency collection accounts assigned to your profile.', '/MultiCurrencyWallet.php', 'virtual_bank_display', 'Collection');
$add_personal_feature($pillar_accounts, 'Transaction history and payment tracking', 'Filter, inspect and export status logs for every wallet movement.', '/web/Transactions.php', null, 'History');
$add_personal_feature($pillar_accounts, 'Live currency conversion and indicative FX rates', 'Check public reference FX rates and switch between currency wallets.', '#send', null, 'FX');
if (!empty($pillar_accounts['items'])) {
    $personal_pillars[] = $pillar_accounts;
}

// Pillar 2: Everyday Services
$pillar_everyday = array(
    'id' => 'everyday-services',
    'eyebrow' => '02 · Everyday Services',
    'title' => 'Everyday Services',
    'subtitle' => 'Airtime, data, power, TV, betting, exam PINs, travel eSIM & tuition.',
    'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L4 14h7l-1 8 9-12h-7l1-8z"/></svg>',
    'items' => array(),
    '_cat_title' => 'Everyday Services',
);
$add_personal_feature($pillar_everyday, 'Airtime purchase', 'Instant top-ups across all major mobile networks.', '/services/airtime.php', 'airtime', 'Instant');
$add_personal_feature($pillar_everyday, 'Mobile data purchase', 'SME, gifting and corporate data bundles at competitive rates.', '/services/data.php', 'data', 'Bundles');
$add_personal_feature($pillar_everyday, 'Electricity bill payments', 'Prepaid token delivery and postpaid meter settlement.', '/services/electricity.php', 'electric', 'Tokens');
$add_personal_feature($pillar_everyday, 'Cable TV subscriptions', 'Instant bouquet renewal for DStv, GOtv, StarTimes and Showmax.', '/services/cable-tv.php', 'cable', 'Auto');
$add_personal_feature($pillar_everyday, 'Betting account funding', 'Fast wallet top-ups for supported sportsbooks.', '/services/betting.php', 'betting', 'Top-up');
$add_personal_feature($pillar_everyday, 'Examination PINs', 'WAEC, NECO, NABTEB and JAMB registration & result PINs.', '/services/exam-pins.php', 'exam', 'PINs');
$add_personal_feature($pillar_everyday, 'Travel eSIM services', 'Global roaming data plans activated digitally before departure.', '/services/esim.php', 'esim', 'Travel');
$add_personal_feature($pillar_everyday, 'Tuition payment requests', 'Verified university tuition and accommodation remittances abroad.', '/Tuition.php', 'tuition', 'Students');
if (!empty($pillar_everyday['items'])) {
    $personal_pillars[] = $pillar_everyday;
}

// Pillar 3: Cards & Digital Assets
$pillar_cards = array(
    'id' => 'cards-assets',
    'eyebrow' => '03 · Cards & Digital Assets',
    'title' => 'Cards & Digital Assets',
    'subtitle' => 'Virtual dollar cards, gift cards and digital asset workflows.',
    'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 10h19M7 15h4"/></svg>',
    'items' => array(),
    '_cat_title' => 'Cards & Digital Assets',
);
$add_personal_feature($pillar_cards, 'Virtual dollar cards', 'Create, fund, freeze and spend globally with instant card controls.', '/services/virtual-cards.php', 'virtual_card', 'USD');
$add_personal_feature($pillar_cards, 'Gift card services', 'Buy and trade popular global retail and entertainment gift cards.', '/services/gift-cards.php', 'gift_card', 'Buy · Sell');
$add_personal_feature($pillar_cards, 'Crypto Buy/Sell and stablecoin services', 'Digital asset buy/sell and stablecoin wallet routes.', '/services/crypto.php', 'crypto_hub', '', 'Pending replacement integration');
if (!empty($pillar_cards['items'])) {
    $personal_pillars[] = $pillar_cards;
}

// Pillar 4: Rewards & Tools
$pillar_rewards = array(
    'id' => 'rewards-tools',
    'eyebrow' => '04 · Rewards & Tools',
    'title' => 'Rewards & Tools',
    'subtitle' => 'Referrals, loyalty rewards, rate calculators & AI budgeting.',
    'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l2.6 5.3 5.9.9-4.2 4.1 1 5.9L12 16.4l-5.3 2.8 1-5.9-4.2-4.1 5.9-.9z"/></svg>',
    'items' => array(),
    '_cat_title' => 'Rewards & Tools',
);
$add_personal_feature($pillar_rewards, 'Referral programme', 'Share your invite code and earn commissions on friend activity.', '/ReferAndEarn.php', null, 'Earn');
$add_personal_feature($pillar_rewards, 'Loyalty points and rewards', 'Collect points on qualifying everyday transactions and redeem bonuses.', '/ReferAndEarn.php', null, 'Rewards');
$add_personal_feature($pillar_rewards, 'Transaction calculator', 'Preview public FX conversions transparently before making transfers.', '#send', null, 'Calculator');
$add_personal_feature($pillar_rewards, 'AI assistant and budgeting tools', 'Smart spend tracking, alerts and automated budgeting insights.', '/web/ai-budgeting.php', 'ai_suite', 'AI Tools');
if (!empty($pillar_rewards['items'])) {
    $personal_pillars[] = $pillar_rewards;
}

// Pillar 5: Security & Support
$pillar_security = array(
    'id' => 'security-support',
    'eyebrow' => '05 · Security & Support',
    'title' => 'Security & Support',
    'subtitle' => 'KYC verification, transaction PINs, 2FA, recovery & dispute care.',
    'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l7 3v5c0 5-3.5 8.8-7 10-3.5-1.2-7-5-7-10V6z"/><path d="M9.5 12l1.8 1.8L15 10"/></svg>',
    'items' => array(),
    '_cat_title' => 'Security & Support',
);
$add_personal_feature($pillar_security, 'Identity verification—KYC', 'One-time guided KYC unlocks full wallets, cards and transfer limits.', '/Security.php', 'kyc_verify', 'KYC');
$add_personal_feature($pillar_security, 'Security PIN', 'Protect every transfer and bill payment with a personal transaction PIN.', '/Security.php', 'security_pin', 'PIN');
$add_personal_feature($pillar_security, 'Google Authenticator and email OTP options', 'Multi-factor login and withdrawal approval safeguards.', '/Security.php', 'google_auth', '2FA');
$add_personal_feature($pillar_security, 'Account recovery and security settings', 'Self-service password reset and device session management.', '/Security.php', 'password_reset', 'Recovery');
$add_personal_feature($pillar_security, 'Customer support and dispute assistance', 'Reach responsive care for transaction checks and dispute resolution.', '/Contact.php', null, 'Support');
if (!empty($pillar_security['items'])) {
    $personal_pillars[] = $pillar_security;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
<?php seo_render_head_tags(array_merge(array('site_title' => $home_name), $home_details), $vendor_account_details ?? array(), $home_name . ' — Home'); ?>
<link rel="preconnect" href="https://fonts.googleapis.com/">
<style>
:root{--home-brand:<?php echo $home_brand_hex; ?>;--home-ink:<?php echo $home_theme['primary_text']; ?>;--home-on-brand:<?php echo $home_theme['on_primary']; ?>;--home-brand-dark:<?php echo $home_dark_theme['primary']; ?>;--home-ink-dark:<?php echo $home_dark_theme['primary_text']; ?>;--home-on-brand-dark:<?php echo $home_dark_theme['on_primary']; ?>;--home-brand-rgb:<?php echo $home_brand_rgb; ?>}
body{margin:0;background:#F6F7FA;color:#0F172A;font-family:Inter,system-ui,sans-serif}
html{scroll-behavior:smooth}
a{color:var(--home-ink)}
a:hover{color:var(--home-brand)}
a:focus-visible,button:focus-visible,input:focus-visible,select:focus-visible{outline:3px solid var(--home-ink);outline-offset:3px}
section{scroll-margin-top:20px}
/* Base fallback rules for converter card even when external CSS is blocked */
#send,#send *{box-sizing:border-box}
#send{width:100%;max-width:460px;background:#fff;color:#0F172A;border-radius:28px;padding:24px;display:flex;flex-direction:column;gap:16px}
#send .t1-fx-field{display:flex;border:1.5px solid #D5DAE3;border-radius:16px;overflow:hidden}
#send input{flex:1 1 auto;min-width:0;border:0;padding:14px;background:transparent}
#send select{width:136px;max-width:42%;min-width:90px;min-height:52px}
#send output{flex:1 1 auto;min-width:0;padding:14px;overflow-wrap:anywhere}
#mz-refresh{width:100%;background:var(--home-brand);color:var(--home-on-brand);border:1px solid var(--home-ink);padding:14px;border-radius:999px;font:700 15px system-ui;cursor:pointer}
.home-brand{font:800 22px/1.15 Inter,system-ui;color:var(--home-ink)!important;white-space:normal;overflow-wrap:anywhere}
.home-brand img{max-height:42px;max-width:180px;width:auto;height:auto}
/* Keyframe animations */
.mz-ticker{display:flex;gap:48px;white-space:nowrap;animation:mzscroll 28s linear infinite}
@keyframes mzscroll{from{transform:translateX(0)}to{transform:translateX(-50%)}}
.mz-feed{animation:mzfeed 9s linear infinite}
@keyframes mzfeed{from{transform:translateY(0)}to{transform:translateY(-50%)}}
.mz-orbit{animation:mzspin 16s linear infinite}
.mz-upright{animation:mzspin 16s linear infinite reverse}
@keyframes mzspin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}
.mz-flash{animation:mzflash 2.4s ease-in-out infinite}
@keyframes mzflash{0%,100%{box-shadow:0 0 0 0 rgba(var(--home-brand-rgb),0),0 14px 28px rgba(11,11,13,0.12)}50%{box-shadow:0 0 0 8px rgba(var(--home-brand-rgb),0.18),0 0 28px 6px rgba(var(--home-brand-rgb),0.32)}}
.mz-blink{animation:mzblink 1.2s ease-in-out infinite}
@keyframes mzblink{0%,100%{opacity:1}50%{opacity:0.25}}
.mz-ring{animation:mzring 2.4s ease-in-out infinite;transform-origin:50% 10%}
@keyframes mzring{0%,60%,100%{transform:rotate(0)}65%{transform:rotate(14deg)}75%{transform:rotate(-12deg)}85%{transform:rotate(8deg)}}
.mz-glow1{animation:mzglow 2.4s ease-in-out infinite}
.mz-glow2{animation:mzglow 2.4s ease-in-out 0.6s infinite}
.mz-glow3{animation:mzglow 2.4s ease-in-out 1.2s infinite}
.mz-glow4{animation:mzglow 2.4s ease-in-out 1.8s infinite}
@keyframes mzglow{0%,100%{box-shadow:inset 0 2px 0 #FFFFFF,0 6px 14px rgba(11,11,13,0.12)}50%{box-shadow:inset 0 2px 0 #FFFFFF,0 0 0 5px rgba(var(--home-brand-rgb),0.2),0 0 22px 4px rgba(var(--home-brand-rgb),0.38)}}
.mz-slide-l{animation:mzslide 45s linear infinite}
.mz-slide-r{animation:mzslide 45s linear infinite reverse}
.mz-slide-l:hover,.mz-slide-r:hover{animation-play-state:paused}
@keyframes mzslide{from{transform:translateX(0)}to{transform:translateX(-50%)}}
.mz-tilt-a{animation:mztilt 3.6s ease-in-out infinite alternate}
.mz-tilt-b{animation:mztilt 3.6s ease-in-out 1.8s infinite alternate-reverse}
@keyframes mztilt{from{transform:rotate(-5deg) translateY(0)}to{transform:rotate(5deg) translateY(-6px)}}
.mz-spin3d{animation:mzspin3d 9s linear infinite;transform-style:preserve-3d}
@keyframes mzspin3d{from{transform:rotateY(0deg) rotateX(8deg)}to{transform:rotateY(360deg) rotateX(8deg)}}
.mz-up{animation:mzup 30s linear infinite}
.mz-down{animation:mzup 30s linear infinite reverse}
@keyframes mzup{from{transform:translateY(0)}to{transform:translateY(-50%)}}
.mz-fade{-webkit-mask-image:linear-gradient(180deg,transparent 0,#000 12%,#000 88%,transparent 100%);mask-image:linear-gradient(180deg,transparent 0,#000 12%,#000 88%,transparent 100%)}
@media (prefers-reduced-motion:reduce){.mz-up,.mz-down,.mz-slide-l,.mz-slide-r,.mz-tilt-a,.mz-tilt-b,.mz-spin3d,.mz-ticker,.mz-feed,.mz-orbit,.mz-upright,.mz-flash,.mz-blink,.mz-ring,.mz-glow1,.mz-glow2,.mz-glow3,.mz-glow4{animation:none}}
</style>
<link rel="stylesheet" href="/cssfile/template/bc-style-template-1.css?v=tenant-cards-2">
<link rel="stylesheet" href="/asset/bc-public-navigation.css?v=1">
</head>
<body>
<div id="dc-root"><div class="sc-host t1-page">

<!-- Responsive Navigation Header -->
<header class="t1-header">
  <nav aria-label="Main navigation" data-public-navigation class="t1-nav">
    <a href="/" aria-label="<?php echo bc_landing_e($home_name); ?> home" class="t1-brand-link">
      <span class="home-brand">
        <?php if ($home_logo !== ''): ?>
          <img src="<?php echo bc_landing_e($home_logo); ?>" alt="<?php echo bc_landing_e($home_name); ?>">
        <?php else: ?>
          <?php echo bc_landing_e($home_name); ?>
        <?php endif; ?>
      </span>
    </a>
    <button type="button" class="public-menu-toggle" aria-expanded="false" aria-controls="public-menu-links" hidden>
      <span>Menu</span>
      <span aria-hidden="true">☰</span>
    </button>
    <div id="public-menu-links" class="public-menu-links">
      <div class="t1-nav-links">
        <a href="/">Personal</a>
        <a href="#personal-features">Personal Features</a>
        <a href="/Business.php">Business</a>
        <a href="/Freelancers.php">Freelancers</a>
        <a href="/Services.php">Services</a>
        <a href="/ReferAndEarn.php">Refer &amp; Earn</a>
      </div>
      <div class="t1-nav-actions">
        <?php if ($home_dash !== ''): ?>
          <a href="<?php echo bc_landing_e($home_dash); ?>" class="t1-nav-cta">Dashboard</a>
        <?php else: ?>
          <a href="/web/Login.php" class="t1-nav-login">Log in</a>
          <a href="/DownloadApp.php" class="t1-nav-cta">Download app</a>
        <?php endif; ?>
      </div>
    </div>
  </nav>
</header>

<!-- Hero & Public Reference Currency Converter -->
<section id="top" class="t1-hero">
  <div class="t1-wrap">
    <div class="t1-hero-grid">
      <div class="t1-hero-copy">
        <span class="t1-eyebrow t1-eyebrow--dark">One app · every currency</span>
        <h1 class="t1-hero-title">Seamless money,<br>without borders.</h1>
        <p class="t1-hero-lead">Hold, convert and send money in multiple currencies from one wallet. Pay bills, fund virtual cards and settle across borders — fast, low-fee and transparent.</p>
        <div class="t1-hero-actions">
          <a href="/DownloadApp.php" class="t1-btn t1-btn--brand">Get the app</a>
          <a href="#personal-features" class="t1-btn t1-btn--ghost-light">Explore personal features</a>
          <a href="/services/international-transfer.php" class="t1-btn t1-btn--ghost-light">Send money</a>
        </div>
        <div class="t1-hero-stats">
          <div class="t1-stat">
            <strong>Reviews in app /5</strong>
            <span>App store rating</span>
          </div>
          <div class="t1-stat">
            <strong>Our community+</strong>
            <span>Active users</span>
          </div>
          <div class="t1-stat">
            <strong>Available+</strong>
            <span>Currencies &amp; services</span>
          </div>
        </div>
      </div>

      <div id="send">
        <div class="t1-fx-head">
          <h2>Currency converter</h2>
          <span id="mz-rate-badge">Daily reference</span>
        </div>
        <div class="t1-fx-group">
          <label for="mz-send" class="t1-fx-label">Amount to convert</label>
          <div class="t1-fx-field">
            <input id="mz-send" type="number" min="0" value="1000" aria-label="Send amount">
            <label for="mz-from" style="position:absolute;left:-9999px;">Send currency</label>
            <select id="mz-from" aria-label="Send currency"><option value="">Loading currencies…</option></select>
          </div>
        </div>
        <div class="t1-fx-mid">
          <button type="button" aria-label="Swap currencies" id="mz-swap">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#0F172A" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 4v16M3 8l4-4 4 4M17 20V4M13 16l4 4 4-4"></path></svg>
          </button>
          <span id="mz-rate-line">Loading latest public reference rates…</span>
        </div>
        <div class="t1-fx-group">
          <label for="mz-get" class="t1-fx-label">Converted amount</label>
          <div class="t1-fx-field">
            <output id="mz-get">—</output>
            <label for="mz-to" style="position:absolute;left:-9999px;">Receive currency</label>
            <select id="mz-to" aria-label="Receive currency"><option value="">Loading currencies…</option></select>
          </div>
          <p id="mz-rate-status" role="status">Independent calculator only — not a transfer quote or a payment service.</p>
        </div>
        <p class="t1-fx-source">Source: <a href="https://github.com/fawazahmed0/exchange-api" target="_blank" rel="noopener noreferrer">Open-source Currency API</a> · latest daily reference data, not a real-time trading feed. No connection to your wallet or this website’s transaction rates.</p>
        <button id="mz-refresh" type="button">Refresh rates</button>
        <noscript>Enable JavaScript to load public currency rates. No transaction is submitted by this calculator.</noscript>
      </div>
    </div>
  </div>
</section>

<!-- Live Marquee Ticker -->
<div aria-hidden="true" class="t1-ticker-bar">
  <div class="mz-ticker">
    <span>TRANSFERS ✦ MULTI-CURRENCY WALLETS ✦ VIRTUAL CARDS ✦ BILLS &amp; AIRTIME ✦ SAVINGS ✦ GIFT CARDS ✦</span>
    <span>TRANSFERS ✦ MULTI-CURRENCY WALLETS ✦ VIRTUAL CARDS ✦ BILLS &amp; AIRTIME ✦ SAVINGS ✦ GIFT CARDS ✦</span>
    <span>TRANSFERS ✦ MULTI-CURRENCY WALLETS ✦ VIRTUAL CARDS ✦ BILLS &amp; AIRTIME ✦ SAVINGS ✦ GIFT CARDS ✦</span>
    <span>TRANSFERS ✦ MULTI-CURRENCY WALLETS ✦ VIRTUAL CARDS ✦ BILLS &amp; AIRTIME ✦ SAVINGS ✦ GIFT CARDS ✦</span>
  </div>
</div>

<!-- Personal Features (5 Service-Control-Synced Pillars) -->
<section id="personal-features" class="t1-personal-sec">
  <div class="t1-wrap">
    <div class="t1-sec-head t1-sec-head--split">
      <div>
        <span class="t1-eyebrow">Service Control Synced</span>
        <h2 class="t1-sec-title" style="margin-top:8px;">Personal Features</h2>
        <p class="t1-sec-sub" style="margin-top:10px;">Every wallet, utility bill, card, reward and security feature available for personal accounts on <?php echo bc_landing_e($home_name); ?>, synced live with your Service Control Center.</p>
      </div>
      <a href="/Services.php" class="t1-btn t1-btn--ghost-dark">View full service index</a>
    </div>

    <div class="t1-filter-bar" id="t1PillarTabs" role="tablist" aria-label="Filter personal feature categories">
      <button type="button" class="t1-filter-chip is-active" data-filter="all" aria-pressed="true">All Pillars (<?php echo count($personal_pillars); ?>)</button>
      <?php foreach ($personal_pillars as $pl): ?>
        <button type="button" class="t1-filter-chip" data-filter="<?php echo bc_landing_e($pl['id']); ?>" aria-pressed="false"><?php echo bc_landing_e($pl['title']); ?></button>
      <?php endforeach; ?>
    </div>

    <div class="t1-pillars-grid" id="t1PillarsGrid">
      <?php foreach ($personal_pillars as $pl): ?>
        <article class="t1-pillar-card" data-pillar="<?php echo bc_landing_e($pl['id']); ?>">
          <div class="t1-pillar-head">
            <span class="t1-pillar-icon" aria-hidden="true"><?php echo $pl['icon']; ?></span>
            <div>
              <span class="t1-pillar-eyebrow"><?php echo bc_landing_e($pl['eyebrow']); ?></span>
              <h3><?php echo bc_landing_e($pl['title']); ?></h3>
              <p class="t1-pillar-sub"><?php echo bc_landing_e($pl['subtitle']); ?></p>
            </div>
          </div>
          <ul class="t1-feature-list">
            <?php foreach ($pl['items'] as $it): ?>
              <li>
                <a href="<?php echo bc_landing_e($it['href']); ?>">
                  <span class="t1-feature-copy">
                    <strong><?php echo bc_landing_e($it['title']); ?></strong>
                    <small><?php echo bc_landing_e($it['desc']); ?></small>
                  </span>
                  <span class="t1-feature-meta">
                    <?php if (!empty($it['badge'])): ?>
                      <span class="t1-feature-badge"><?php echo bc_landing_e($it['badge']); ?></span>
                    <?php endif; ?>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                  </span>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        </article>
      <?php endforeach; ?>
    </div>

    <?php if (!empty($coming_soon_items)): ?>
      <div class="t1-cs-band" id="coming-soon">
        <div class="t1-cs-head">
          <div>
            <span class="t1-eyebrow t1-eyebrow--dark" style="padding:5px 11px;">Coming Soon &amp; Service Control Status</span>
            <h3>Paused or pending integration (<?php echo count($coming_soon_items); ?>)</h3>
          </div>
          <span style="font-size:13px;color:#94A3B8;">Updates automatically when toggled in Service Control Center</span>
        </div>
        <div class="t1-cs-grid">
          <?php foreach ($coming_soon_items as $cs): ?>
            <a class="t1-cs-item" href="<?php echo bc_landing_e($cs['href']); ?>">
              <span class="t1-cs-pill"><?php echo bc_landing_e($cs['reason']); ?></span>
              <h4><?php echo bc_landing_e($cs['title']); ?></h4>
              <p><?php echo bc_landing_e($cs['category']); ?> · <?php echo bc_landing_e($cs['desc']); ?></p>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
</section>

<!-- Multi-Currency Wallets -->
<section id="wallets" class="t1-wallets-sec">
  <div class="t1-wrap">
    <div class="t1-wallets-layout">
      <div class="t1-sec-head">
        <span class="t1-eyebrow">Multi-currency wallet</span>
        <h2 class="t1-sec-title">Get USD, GBP and EUR <span class="t1-accent-text">accounts in seconds.</span></h2>
        <p class="t1-sec-sub">Open local account details in major currencies, receive payments like a local and switch between balances instantly at competitive exchange rates.</p>
        <div class="t1-check-list">
          <div class="t1-check-item">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--home-ink)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
            <span>Hold Available+ currencies in one wallet</span>
          </div>
          <div class="t1-check-item">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--home-ink)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
            <span>Send to Available+ countries</span>
          </div>
          <div class="t1-check-item">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--home-ink)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
            <span>Bank transfer, card and mobile money payouts</span>
          </div>
        </div>
        <div style="margin-top:8px;">
          <a href="/MultiCurrencyWallet.php" class="t1-btn t1-btn--dark">Open multi-currency wallet</a>
        </div>
      </div>

      <div class="t1-wallets-grid">
        <div class="t1-wallet-card t1-wallet-card--dark">
          <div class="t1-wallet-top"><span class="t1-wallet-sym">$</span><span class="t1-wallet-code">USD</span></div>
          <div class="t1-wallet-meta"><span>US dollar</span><strong>Account details</strong></div>
        </div>
        <div class="t1-wallet-card t1-wallet-card--brand">
          <div class="t1-wallet-top"><span class="t1-wallet-sym">£</span><span class="t1-wallet-code">GBP</span></div>
          <div class="t1-wallet-meta"><span>British pound</span><strong>Sort code + account</strong></div>
        </div>
        <div class="t1-wallet-card t1-wallet-card--brand">
          <div class="t1-wallet-top"><span class="t1-wallet-sym">€</span><span class="t1-wallet-code">EUR</span></div>
          <div class="t1-wallet-meta"><span>Euro</span><strong>IBAN</strong></div>
        </div>
        <div class="t1-wallet-card">
          <div class="t1-wallet-top"><span class="t1-wallet-sym">₦</span><span class="t1-wallet-code">NGN</span></div>
          <div class="t1-wallet-meta"><span>Nigerian naira</span><strong>Local account</strong></div>
        </div>
        <div class="t1-wallet-card">
          <div class="t1-wallet-top"><span class="t1-wallet-sym">K</span><span class="t1-wallet-code">KES</span></div>
          <div class="t1-wallet-meta"><span>Kenyan shilling</span><strong>Mobile money</strong></div>
        </div>
        <div class="t1-wallet-card">
          <div class="t1-wallet-top"><span class="t1-wallet-sym">₵</span><span class="t1-wallet-code">GHS</span></div>
          <div class="t1-wallet-meta"><span>Ghanaian cedi</span><strong>Mobile money</strong></div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- How to pay out to a China account -->
<section id="china" class="t1-china-sec">
  <div class="t1-wrap">
    <div class="t1-sec-head t1-sec-head--center">
      <span class="t1-eyebrow">How it works</span>
      <h2 class="t1-sec-title">How to pay out to a China account</h2>
      <p class="t1-sec-sub">Pay suppliers and partners in China from your <?php echo bc_landing_e($home_name); ?> USD wallet, straight to Chinese bank accounts and wallets.</p>
    </div>

    <div class="t1-steps-grid">
      <div class="t1-step-card">
        <span class="t1-step-num">1</span>
        <h3>Create a USD wallet</h3>
        <p>Open a USD wallet from your dashboard in a few taps.</p>
        <div class="t1-step-mock">
          <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
            <span style="font-size:13px;font-weight:700;">Wallets</span>
            <span style="font-size:11px;font-weight:700;color:var(--home-on-brand);background:var(--home-brand);padding:5px 10px;border-radius:999px;">+ New wallet</span>
          </div>
          <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;">
            <div style="border:1px solid #E2E8F0;border-radius:12px;padding:10px;display:flex;flex-direction:column;gap:3px;">
              <span style="font-size:11px;color:#475569;">Nigerian naira</span>
              <span style="font-size:13px;font-weight:700;">NGN ••••</span>
            </div>
            <div style="border:2px solid var(--home-ink);border-radius:12px;padding:10px;display:flex;flex-direction:column;gap:3px;background:rgba(var(--home-brand-rgb),0.08);">
              <span style="font-size:11px;color:var(--home-ink);font-weight:700;">US dollar · New</span>
              <span style="font-size:13px;font-weight:700;">USD 0.00</span>
            </div>
          </div>
        </div>
      </div>

      <div class="t1-step-card">
        <span class="t1-step-num">2</span>
        <h3>Fund your USD wallet</h3>
        <p>Top up by bank transfer, card or by converting from another balance.</p>
        <div class="t1-step-mock">
          <span style="font-size:12px;color:#475569;">USD balance</span>
          <span class="t1-display" style="font-size:24px;font-weight:700;">$ 2,500.00</span>
          <div style="display:flex;flex-wrap:wrap;gap:6px;">
            <span style="font-size:11px;font-weight:600;border:1px solid #CBD5E1;padding:6px 10px;border-radius:8px;">Account details</span>
            <span style="font-size:11px;font-weight:700;color:#fff;background:#0F172A;padding:6px 12px;border-radius:8px;">Fund</span>
            <span style="font-size:11px;font-weight:600;border:1px solid #CBD5E1;padding:6px 10px;border-radius:8px;">Send</span>
          </div>
        </div>
      </div>

      <div class="t1-step-card t1-step-card--dark">
        <span class="t1-step-num">3</span>
        <h3>Transfer to China</h3>
        <p>Send from USD to any Chinese bank account or wallet. Your recipient gets CNY.</p>
        <div class="t1-step-mock">
          <span style="font-size:13px;font-weight:700;">How much are you sending?</span>
          <div style="border:1px solid #CBD5E1;border-radius:10px;padding:9px 10px;display:flex;justify-content:space-between;font-size:14px;font-weight:700;">
            <span>$ 1,000.00</span><span>USD</span>
          </div>
          <div style="border:1px solid #CBD5E1;border-radius:10px;padding:9px 10px;display:flex;justify-content:space-between;background:#F6F7FA;font-size:14px;font-weight:700;">
            <span>¥ See account limits</span><span>CNY</span>
          </div>
          <span style="font-size:12px;font-weight:700;color:var(--home-on-brand);background:var(--home-brand);text-align:center;padding:9px;border-radius:999px;">Send to China</span>
        </div>
      </div>
    </div>

    <div style="text-align:center;">
      <a href="/Payouts.php" class="t1-btn t1-btn--dark">Start a China payout</a>
    </div>
  </div>
</section>

<!-- Global Coverage -->
<section id="coverage" class="t1-coverage-sec">
  <div class="t1-wrap">
    <div class="t1-sec-head t1-sec-head--center">
      <span class="t1-eyebrow">Global coverage</span>
      <h2 class="t1-sec-title">One wallet.<br><span class="t1-accent-text">Money moving worldwide.</span></h2>
      <p class="t1-sec-sub">Send, receive and convert between local currencies across the <?php echo bc_landing_e($home_name); ?> network — from Lagos to London, Nairobi to New York.</p>
    </div>

    <div class="t1-globe-box">
      <svg viewBox="0 0 800 360" role="img" aria-label="Illustration of global connections" style="width:100%;max-height:420px;display:block;">
        <defs><radialGradient id="globe"><stop stop-color="#fff"/><stop offset="1" stop-color="#E2E8F0"/></radialGradient></defs>
        <circle cx="400" cy="180" r="155" fill="url(#globe)" stroke="var(--home-ink)" stroke-width="2"></circle>
        <g fill="none" stroke="var(--home-ink)" opacity=".35">
          <ellipse cx="400" cy="180" rx="72" ry="155"></ellipse>
          <ellipse cx="400" cy="180" rx="122" ry="155"></ellipse>
          <ellipse cx="400" cy="180" rx="155" ry="54"></ellipse>
          <ellipse cx="400" cy="180" rx="155" ry="110"></ellipse>
          <path d="M245 180h310M400 25v310M280 95Q400 300 525 110M270 220Q410 45 535 235"></path>
        </g>
        <g fill="var(--home-ink)">
          <circle cx="310" cy="140" r="7"></circle>
          <circle cx="420" cy="225" r="7"></circle>
          <circle cx="505" cy="130" r="7"></circle>
        </g>
      </svg>
      <div class="t1-globe-pill-left">
        <span class="mz-blink" style="width:8px;height:8px;border-radius:999px;background:var(--home-brand);"></span>
        <span>Money moving now</span>
      </div>
      <div class="t1-globe-pill-right">
        <div class="t1-display" style="font-size:21px;font-weight:700;">Available+</div>
        <div style="font-size:11.5px;color:#CBD5E1;">countries</div>
      </div>
    </div>

    <div class="t1-region-pills">
      <span class="t1-region-label">Region</span>
      <a class="t1-region-pill t1-region-pill--active" href="/services/international-transfer.php">Global</a>
      <a class="t1-region-pill" href="/services/international-transfer.php">Africa</a>
      <a class="t1-region-pill" href="/services/international-transfer.php">Europe</a>
      <a class="t1-region-pill" href="/services/international-transfer.php">Americas</a>
      <a class="t1-region-pill" href="/services/international-transfer.php">Asia &amp; Pacific</a>
    </div>

    <div class="t1-corridors-grid">
      <?php
      $corridors = array(
          array('NG', 'Nigeria', 'NGN', true),
          array('GH', 'Ghana', 'GHS', false),
          array('KE', 'Kenya', 'KES', false),
          array('ZA', 'South Africa', 'ZAR', true),
          array('UG', 'Uganda', 'UGX', false),
          array('TZ', 'Tanzania', 'TZS', false),
          array('RW', 'Rwanda', 'RWF', false),
          array('CM', 'Cameroon', 'XAF', true),
          array('SN', 'Senegal', 'XOF', false),
          array('CI', "Côte d'Ivoire", 'XOF', false),
          array('GB', 'United Kingdom', 'GBP', true),
          array('DE', 'Germany', 'EUR', false),
          array('FR', 'France', 'EUR', false),
          array('US', 'United States', 'USD', true),
          array('CA', 'Canada', 'CAD', false),
          array('CN', 'China', 'CNY', true),
          array('AE', 'United Arab Emirates', 'AED', false),
          array('SG', 'Singapore', 'SGD', false),
          array('IN', 'India', 'INR', false),
          array('AU', 'Australia', 'AUD', false),
      );
      foreach ($corridors as $cor):
      ?>
        <div class="t1-corridor-row">
          <span class="t1-corridor-code <?php echo $cor[3] ? 't1-corridor-code--brand' : ''; ?>"><?php echo bc_landing_e($cor[0]); ?></span>
          <span style="flex:1 1 0;font-size:15px;font-weight:600;color:#0F172A;"><?php echo bc_landing_e($cor[1]); ?></span>
          <span style="font-size:13.5px;font-weight:700;color:#475569;"><?php echo bc_landing_e($cor[2]); ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- An app without borders -->
<section class="t1-highlights-sec">
  <div class="t1-wrap">
    <div class="t1-sec-head t1-sec-head--split">
      <h2 class="t1-sec-title" style="max-width:600px;">An app without borders.</h2>
      <p class="t1-sec-sub" style="max-width:420px;">Everything you need to move money across borders — built into one simple, secure app.</p>
    </div>

    <div class="t1-highlights-frame">
      <div class="t1-highlight-card">
        <div aria-hidden="true" class="t1-highlight-stage">
          <div class="mz-feed" style="display:flex;flex-direction:column;padding-top:12px;">
            <?php
            $feed_rows = array(
                array('$', 'Sent USD', '-$120.00', '#0F172A', '#FFFFFF', '#0F172A'),
                array('₦', 'Received NGN', '+₦85,000', 'var(--home-brand)', 'var(--home-on-brand)', '#15803D'),
                array('£', 'Received GBP', '+£45.00', '#FFFFFF', 'var(--home-ink)', '#15803D'),
                array('€', 'Converted EUR', '€200.00', '#E2E8F0', '#0F172A', '#0F172A'),
            );
            for ($loop = 0; $loop < 2; $loop++):
              foreach ($feed_rows as $fr):
            ?>
              <div style="background:#fff;border-radius:14px;padding:10px 12px;display:flex;align-items:center;gap:10px;box-shadow:0 4px 10px rgba(15,23,42,0.06);margin-bottom:10px;flex:0 0 auto;">
                <span style="width:34px;height:34px;border-radius:999px;flex:0 0 auto;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;background:<?php echo $fr[3]; ?>;color:<?php echo $fr[4]; ?>;border:1px solid rgba(15,23,42,0.08);"><?php echo $fr[0]; ?></span>
                <div style="flex:1 1 0;min-width:0;">
                  <div style="font-size:13.5px;font-weight:700;color:#0F172A;"><?php echo $fr[1]; ?></div>
                  <div style="font-size:11px;color:#475569;">Just now</div>
                </div>
                <span style="font-size:13px;font-weight:700;color:<?php echo $fr[5]; ?>;"><?php echo $fr[2]; ?></span>
              </div>
            <?php endforeach; endfor; ?>
          </div>
        </div>
        <div>
          <h3 class="t1-display" style="margin:0 0 6px;font-size:21px;font-weight:700;">Send and receive in a tap</h3>
          <p style="margin:0;font-size:15px;line-height:1.55;color:#475569;">Move money between currencies and people in seconds, from one wallet.</p>
        </div>
      </div>

      <div class="t1-highlight-card">
        <div aria-hidden="true" class="t1-highlight-stage" style="align-items:center;">
          <div class="mz-flash" style="width:100%;background:#fff;border-radius:16px;padding:14px;display:flex;gap:12px;align-items:flex-start;">
            <span class="mz-ring" style="width:42px;height:42px;flex:0 0 auto;border-radius:12px;display:flex;align-items:center;justify-content:center;background:var(--home-brand);color:var(--home-on-brand);">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9M10 21h4"/></svg>
            </span>
            <div style="flex:1 1 0;min-width:0;">
              <div style="display:flex;justify-content:space-between;align-items:center;gap:6px;">
                <span style="font-size:13.5px;font-weight:700;">Payment received</span>
                <span style="display:inline-flex;align-items:center;gap:4px;font-size:10px;font-weight:700;color:var(--home-ink);"><span class="mz-blink" style="width:6px;height:6px;border-radius:999px;background:var(--home-brand);"></span>LIVE</span>
              </div>
              <div style="font-size:12px;line-height:1.45;color:#475569;margin-top:3px;">₦25,000 has landed in your NGN wallet.</div>
            </div>
          </div>
          <div style="margin-top:14px;display:flex;flex-wrap:wrap;justify-content:center;gap:8px;">
            <span style="font-size:11px;font-weight:600;color:#475569;background:#fff;padding:6px 10px;border-radius:999px;">Card spend · $12.40</span>
            <span style="font-size:11px;font-weight:600;color:#475569;background:#fff;padding:6px 10px;border-radius:999px;">Airtime · ₦2,000</span>
          </div>
        </div>
        <div>
          <h3 class="t1-display" style="margin:0 0 6px;font-size:21px;font-weight:700;">Alerts the moment money moves</h3>
          <p style="margin:0;font-size:15px;line-height:1.55;color:#475569;">Real-time alerts for every deposit, payment and card spend.</p>
        </div>
      </div>

      <div class="t1-highlight-card">
        <div aria-hidden="true" class="t1-highlight-stage" style="align-items:center;">
          <div style="position:relative;width:184px;height:184px;">
            <div style="position:absolute;inset:20px;border-radius:999px;border:2px dashed #CBD5E1;"></div>
            <div style="position:absolute;left:58px;top:58px;width:68px;height:68px;border-radius:999px;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:26px;background:var(--home-brand);color:var(--home-on-brand);">⇄</div>
            <div class="mz-orbit" style="position:absolute;inset:0;">
              <div style="position:absolute;left:64px;top:-2px;width:56px;height:38px;"><div class="mz-upright" style="width:100%;height:100%;"><span class="mz-glow1" style="display:flex;align-items:center;justify-content:center;gap:3px;height:100%;border-radius:999px;background:#fff;font-size:12px;font-weight:700;"><span style="color:var(--home-ink);">$</span>USD</span></div></div>
              <div style="position:absolute;left:130px;top:73px;width:56px;height:38px;"><div class="mz-upright" style="width:100%;height:100%;"><span class="mz-glow2" style="display:flex;align-items:center;justify-content:center;gap:3px;height:100%;border-radius:999px;background:#fff;font-size:12px;font-weight:700;"><span style="color:var(--home-ink);">£</span>GBP</span></div></div>
              <div style="position:absolute;left:64px;top:148px;width:56px;height:38px;"><div class="mz-upright" style="width:100%;height:100%;"><span class="mz-glow3" style="display:flex;align-items:center;justify-content:center;gap:3px;height:100%;border-radius:999px;background:#fff;font-size:12px;font-weight:700;"><span style="color:var(--home-ink);">€</span>EUR</span></div></div>
              <div style="position:absolute;left:-2px;top:73px;width:56px;height:38px;"><div class="mz-upright" style="width:100%;height:100%;"><span class="mz-glow4" style="display:flex;align-items:center;justify-content:center;gap:3px;height:100%;border-radius:999px;background:#fff;font-size:12px;font-weight:700;"><span style="color:var(--home-ink);">₦</span>NGN</span></div></div>
            </div>
          </div>
        </div>
        <div>
          <h3 class="t1-display" style="margin:0 0 6px;font-size:21px;font-weight:700;">Fair rates, shown up front</h3>
          <p style="margin:0;font-size:15px;line-height:1.55;color:#475569;">See the exact rate and fee before you convert, every time.</p>
        </div>
      </div>
    </div>

    <div class="t1-pillars-mini">
      <div class="t1-mini-card">
        <span class="t1-mini-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7h18v10H3zM3 11h18"/></svg></span>
        <h3 class="t1-display" style="margin:0;font-size:19px;font-weight:700;">Many ways to pay in and out</h3>
        <p style="margin:0;font-size:15px;line-height:1.55;color:#475569;">Bank transfer, card, mobile money and cash pickup where available.</p>
      </div>
      <div class="t1-mini-card">
        <span class="t1-mini-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2v20M17 6H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></span>
        <h3 class="t1-display" style="margin:0;font-size:19px;font-weight:700;">No maintenance fees</h3>
        <p style="margin:0;font-size:15px;line-height:1.55;color:#475569;">No monthly account or card maintenance charges.</p>
      </div>
      <div class="t1-mini-card">
        <span class="t1-mini-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l8 4v5c0 5-3.5 8-8 9-4.5-1-8-4-8-9V7z"/></svg></span>
        <h3 class="t1-display" style="margin:0;font-size:19px;font-weight:700;">One KYC for everything</h3>
        <p style="margin:0;font-size:15px;line-height:1.55;color:#475569;">Verify once and unlock every wallet, card and feature.</p>
      </div>
    </div>
  </div>
</section>

<!-- Inside the App Showcase -->
<section id="app" class="t1-app-sec">
  <div class="t1-wrap">
    <div class="t1-sec-head t1-sec-head--center">
      <span class="t1-eyebrow t1-eyebrow--on-brand">Inside the app</span>
      <h2 class="t1-sec-title" style="color:var(--home-on-brand);">You are in control.</h2>
      <p class="t1-sec-sub" style="color:var(--home-on-brand);opacity:0.88;">Track every balance, send in a few taps and see exactly where your money goes.</p>
    </div>

    <div class="t1-phones-row">
      <div class="t1-phone">
        <div class="t1-phone-screen">
          <div style="background:#0B0C10;color:#fff;padding:28px 18px 20px;display:flex;flex-direction:column;gap:12px;">
            <span style="font-size:12.5px;color:#94A3B8;">Total balance · GBP</span>
            <span class="t1-display" style="font-size:30px;font-weight:700;">£2,480.50</span>
            <div style="display:flex;gap:8px;">
              <span style="flex:1 1 0;background:var(--home-brand);color:var(--home-on-brand);text-align:center;padding:9px 0;border-radius:999px;font-size:12.5px;font-weight:700;">Send</span>
              <span style="flex:1 1 0;background:#1E202B;color:#fff;text-align:center;padding:9px 0;border-radius:999px;font-size:12.5px;font-weight:600;">Add</span>
              <span style="flex:1 1 0;background:#1E202B;color:#fff;text-align:center;padding:9px 0;border-radius:999px;font-size:12.5px;font-weight:600;">Swap</span>
            </div>
          </div>
          <div style="padding:16px;display:flex;flex-direction:column;gap:9px;">
            <span style="font-size:12.5px;font-weight:700;">Your wallets</span>
            <div style="background:#fff;border-radius:14px;padding:11px;display:flex;align-items:center;gap:10px;"><span style="width:32px;height:32px;border-radius:999px;background:#EEF2FF;color:var(--home-ink);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;">£</span><div style="flex:1 1 0;"><div style="font-size:13px;font-weight:700;">GBP</div><div style="font-size:11px;color:#475569;">British pound</div></div><span style="font-size:13px;font-weight:700;">£1,240.00</span></div>
            <div style="background:#fff;border-radius:14px;padding:11px;display:flex;align-items:center;gap:10px;"><span style="width:32px;height:32px;border-radius:999px;background:#0F172A;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;">$</span><div style="flex:1 1 0;"><div style="font-size:13px;font-weight:700;">USD</div><div style="font-size:11px;color:#475569;">US dollar</div></div><span style="font-size:13px;font-weight:700;">$820.15</span></div>
            <div style="background:#fff;border-radius:14px;padding:11px;display:flex;align-items:center;gap:10px;"><span style="width:32px;height:32px;border-radius:999px;background:var(--home-brand);color:var(--home-on-brand);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;">€</span><div style="flex:1 1 0;"><div style="font-size:13px;font-weight:700;">EUR</div><div style="font-size:11px;color:#475569;">Euro</div></div><span style="font-size:13px;font-weight:700;">€310.40</span></div>
            <div style="background:#fff;border-radius:14px;padding:11px;display:flex;align-items:center;gap:10px;"><span style="width:32px;height:32px;border-radius:999px;background:#E2E8F0;color:#0F172A;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;">₦</span><div style="flex:1 1 0;"><div style="font-size:13px;font-weight:700;">NGN</div><div style="font-size:11px;color:#475569;">Nigerian naira</div></div><span style="font-size:13px;font-weight:700;">₦145,000</span></div>
          </div>
        </div>
      </div>

      <div class="t1-phone t1-phone--center">
        <div class="t1-phone-screen" style="background:#fff;padding:28px 18px 18px;gap:14px;">
          <span class="t1-display" style="font-size:21px;font-weight:700;">Send money</span>
          <div style="display:flex;align-items:center;gap:10px;background:#F6F7FA;border-radius:14px;padding:11px;">
            <span style="width:38px;height:38px;border-radius:999px;background:rgba(var(--home-brand-rgb),0.12);display:flex;align-items:center;justify-content:center;font-weight:700;color:var(--home-ink);">AO</span>
            <div><div style="font-size:13.5px;font-weight:700;">Recipient name</div><div style="font-size:11.5px;color:#475569;">Bank · NGN account</div></div>
          </div>
          <div style="border:1.5px solid #D5DAE3;border-radius:14px;padding:12px;">
            <div style="font-size:11.5px;color:#475569;">You send</div>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:4px;"><span class="t1-display" style="font-size:22px;font-weight:700;">£200.00</span><span style="font-weight:700;">GBP</span></div>
          </div>
          <div style="border:1.5px solid #D5DAE3;border-radius:14px;padding:12px;">
            <div style="font-size:11.5px;color:#475569;">They receive</div>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:4px;"><span class="t1-display" style="font-size:22px;font-weight:700;">₦ — —</span><span style="font-weight:700;">NGN</span></div>
          </div>
          <div style="display:flex;flex-direction:column;gap:7px;font-size:12.5px;color:#475569;">
            <div style="display:flex;justify-content:space-between;"><span>Rate</span><strong style="color:#0F172A;">Live rate</strong></div>
            <div style="display:flex;justify-content:space-between;"><span>Fee</span><strong style="color:#0F172A;">Shown before confirmation</strong></div>
            <div style="display:flex;justify-content:space-between;"><span>Arrives</span><strong style="color:#0F172A;">Varies by provider</strong></div>
          </div>
          <div style="margin-top:auto;background:var(--home-brand);color:var(--home-on-brand);text-align:center;padding:13px;border-radius:999px;font-weight:700;">Continue</div>
        </div>
      </div>

      <div class="t1-phone">
        <div class="t1-phone-screen" style="padding:28px 16px 16px;gap:12px;">
          <span class="t1-display" style="font-size:19px;font-weight:700;">Spending</span>
          <div style="background:#fff;border-radius:16px;padding:12px;display:flex;flex-direction:column;gap:10px;">
            <div style="display:flex;justify-content:space-between;font-size:12px;color:#475569;"><span>This month</span><span>In / Out</span></div>
            <div style="display:flex;align-items:flex-end;gap:7px;height:105px;">
              <?php foreach (array(array(50,18),array(70,24),array(40,33),array(85,21),array(60,36),array(75,15),array(55,27)) as $bars): ?>
                <div style="flex:1 1 0;display:flex;flex-direction:column;justify-content:flex-end;gap:3px;height:100%;">
                  <div style="height:<?php echo $bars[0]; ?>%;background:#0F172A;border-radius:4px;"></div>
                  <div style="height:<?php echo $bars[1]; ?>%;background:var(--home-brand);border-radius:4px;"></div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php foreach (array(array('Transfers','42%'),array('Bills & airtime','24%'),array('Shopping','18%'),array('Savings','16%')) as $sp): ?>
            <div style="background:#fff;border-radius:12px;padding:10px 12px;display:flex;flex-direction:column;gap:6px;">
              <div style="display:flex;justify-content:space-between;font-size:12.5px;"><span style="font-weight:600;"><?php echo $sp[0]; ?></span><span style="color:#475569;"><?php echo $sp[1]; ?></span></div>
              <div style="height:6px;background:#E2E8F0;border-radius:999px;"><div style="height:6px;width:<?php echo $sp[1]; ?>;background:#0F172A;border-radius:999px;"></div></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- Virtual & Physical Cards (preserves 2 .bc-card-brand elements with ellipsis) -->
<section id="card" class="bc-card-showcase">
  <div class="bc-card-stage">
    <div class="bc-card-visual bc-card-visual--dark">
      <span class="bc-card-brand"><?php echo bc_landing_e($home_short_brand); ?></span>
      <div style="display:flex;justify-content:space-between;align-items:flex-end;">
        <span style="letter-spacing:0.18em;font-size:14px;">•••• 4821</span>
        <span style="font-size:12.5px;color:#CBD5E1;font-weight:700;">VIRTUAL</span>
      </div>
    </div>
    <div class="bc-card-visual bc-card-visual--brand">
      <div style="display:flex;justify-content:space-between;align-items:center;">
        <span class="bc-card-brand"><?php echo bc_landing_e($home_short_brand); ?></span>
        <span style="width:42px;height:30px;border-radius:6px;background:rgba(255,255,255,0.75);"></span>
      </div>
      <div style="display:flex;justify-content:space-between;align-items:flex-end;">
        <span style="letter-spacing:0.18em;font-size:14px;">•••• 0937</span>
        <span style="font-size:12.5px;font-weight:700;">PHYSICAL</span>
      </div>
    </div>
  </div>

  <div style="flex:1 1 420px;min-width:0;display:flex;flex-direction:column;gap:20px;">
    <span class="t1-eyebrow"><?php echo bc_landing_e($home_name); ?> card</span>
    <h2 class="t1-sec-title">A card that works everywhere.</h2>
    <p class="t1-sec-sub">Spend online and in-store straight from any currency balance. Freeze, unfreeze and set limits from the app.</p>
    <div>
      <a href="/services/virtual-cards.php" class="t1-btn t1-btn--dark">Get your card</a>
    </div>
  </div>
</section>

<!-- Gift Cards -->
<section id="giftcards" class="t1-giftcards-sec">
  <div class="t1-wrap">
    <div style="display:flex;flex-wrap:wrap;gap:40px;align-items:center;">
      <div style="flex:1 1 480px;min-width:0;display:flex;flex-direction:column;gap:20px;">
        <div role="group" aria-label="Buy or sell gift cards" style="align-self:flex-start;display:flex;flex-wrap:wrap;gap:6px;padding:6px;background:#fff;border-radius:999px;border:1px solid var(--t1-border);">
          <a href="/services/gift-cards.php" class="t1-btn t1-btn--dark" style="min-height:44px;padding:10px 20px;">Buy gift cards</a>
          <a href="/services/gift-cards.php" class="t1-btn t1-btn--ghost-dark" style="min-height:44px;padding:10px 20px;border:0;">Sell gift cards</a>
        </div>
        <h2 class="t1-sec-title">Buy gift cards,<br><span class="t1-accent-text">delivered in seconds.</span></h2>
        <p class="t1-sec-sub">Choose from Available+ local and international gift cards, pay from any <?php echo bc_landing_e($home_name); ?> balance and get your code straight in the app.</p>
        <div><a href="/services/gift-cards.php" class="t1-btn t1-btn--dark">Buy a gift card</a></div>
      </div>

      <div aria-hidden="true" style="flex:1 1 320px;min-width:0;display:flex;justify-content:center;align-items:center;height:260px;perspective:900px;">
        <div class="mz-spin3d" style="width:290px;max-width:100%;height:182px;border-radius:22px;background:linear-gradient(135deg,var(--home-brand) 0%,#0F172A 100%);color:#fff;padding:20px;display:flex;flex-direction:column;justify-content:space-between;box-shadow:0 26px 46px rgba(11,12,16,0.28);">
          <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
            <span style="font-size:11px;font-weight:700;letter-spacing:0.14em;">GIFT CARD</span>
            <span style="background:#fff;border-radius:10px;padding:4px 8px;max-width:60%;overflow:hidden;"><span class="home-brand" style="font-size:14px;"><?php echo bc_landing_e($home_name); ?></span></span>
          </div>
          <span class="t1-display" style="font-size:30px;font-weight:700;">Buy · Sell</span>
          <span style="font-size:12px;opacity:0.88;">Instant codes · Fast payouts</span>
        </div>
      </div>
    </div>
  </div>

  <div style="margin-top:44px;display:flex;flex-direction:column;gap:18px;">
    <span style="text-align:center;font-size:12.5px;font-weight:700;letter-spacing:0.12em;color:#475569;text-transform:uppercase;">Popular gift cards</span>
    <div class="t1-marquee-wrap">
      <div class="mz-slide-l" style="display:flex;width:max-content;">
        <?php
        $gc_row1 = array(
            array('Amazon', '#37475A', '#FF9900'),
            array('Apple', '#2C2C2E', '#FFFFFF'),
            array('Google Play', '#2563EB', '#FFFFFF'),
            array('Steam', '#1B2838', '#66C0F4'),
            array('Xbox', '#107C10', '#FFFFFF'),
            array('PlayStation', '#003791', '#FFFFFF'),
        );
        for ($loop = 0; $loop < 2; $loop++):
          foreach ($gc_row1 as $idx => $gc):
        ?>
          <div style="padding:0 10px;flex:0 0 auto;">
            <div class="<?php echo $idx % 2 === 0 ? 'mz-tilt-a' : 'mz-tilt-b'; ?>" style="width:210px;height:128px;border-radius:18px;background:<?php echo $gc[1]; ?>;color:<?php echo $gc[2]; ?>;padding:16px;display:flex;flex-direction:column;justify-content:space-between;box-shadow:0 12px 24px rgba(15,23,42,0.14);">
              <div style="display:flex;justify-content:space-between;align-items:center;"><span style="font-size:10px;font-weight:700;letter-spacing:0.12em;opacity:0.85;">GIFT CARD</span><span style="font-size:10px;font-weight:700;padding:4px 8px;border-radius:999px;background:rgba(255,255,255,0.16);">Buy · Sell</span></div>
              <span class="t1-display" style="font-size:21px;font-weight:700;"><?php echo $gc[0]; ?></span>
            </div>
          </div>
        <?php endforeach; endfor; ?>
      </div>
    </div>
    <span style="text-align:center;font-size:12px;color:#475569;padding:0 24px;">Brand names are trademarks of their respective owners and are shown for identification only.</span>
  </div>
</section>

<!-- POS Terminals (responsive mobile-first layout) -->
<section id="pos" class="t1-pos-sec">
  <div class="t1-wrap">
    <div class="t1-pos-layout">
      <div aria-hidden="true" class="t1-pos-visual">
        <div class="t1-pos-terminal-card">
          <div class="t1-pos-device">
            <div class="t1-pos-screen">
              <div class="t1-pos-head">
                <span><?php echo bc_landing_e($home_short_brand); ?> pay</span>
                <span>●</span>
              </div>
              <div style="padding:18px 12px;display:flex;flex-direction:column;align-items:center;gap:8px;text-align:center;">
                <span class="t1-display" style="font-size:26px;font-weight:700;color:#0F172A;">₦1,000.00</span>
                <span style="font-size:11px;color:#475569;line-height:1.4;">Tap, insert or swipe your card</span>
                <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="var(--home-ink)" stroke-width="2" stroke-linecap="round"><path d="M8.5 8.5a5 5 0 0 1 0 7M12 6a8.5 8.5 0 0 1 0 12M15.5 3.5a12 12 0 0 1 0 17"/></svg>
              </div>
            </div>
            <div class="t1-pos-keys">
              <?php foreach (array('1','2','3','4','5','6','7','8','9') as $k): ?>
                <span class="t1-pos-key"><?php echo $k; ?></span>
              <?php endforeach; ?>
              <span class="t1-pos-key" style="background:var(--home-brand);color:var(--home-on-brand);">✕</span>
              <span class="t1-pos-key">0</span>
              <span class="t1-pos-key" style="background:#16A34A;color:#fff;">✓</span>
            </div>
          </div>
        </div>
      </div>

      <div class="t1-sec-head">
        <span class="t1-eyebrow"><?php echo bc_landing_e($home_name); ?> POS</span>
        <h2 class="t1-sec-title">Get paid anywhere with the right POS terminal.</h2>
        <p class="t1-sec-sub">Take card and contactless payments in-store or on the move with <?php echo bc_landing_e($home_name); ?> Android POS terminals. Every sale settles straight into your <?php echo bc_landing_e($home_name); ?> wallet — fast, secure and easy to track.</p>
        <div style="display:flex;flex-wrap:wrap;gap:12px;margin-top:8px;">
          <a href="/POSTerminals.php" class="t1-btn t1-btn--ghost-dark">See all our terminals</a>
          <a href="/POSTerminals.php" class="t1-btn t1-btn--brand">Request a terminal</a>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- Lifestyle -->
<section id="lifestyle" class="t1-lifestyle-sec">
  <div class="t1-wrap">
    <div class="t1-sec-head t1-sec-head--center">
      <span class="t1-eyebrow"><?php echo bc_landing_e($home_name); ?> lifestyle</span>
      <h2 class="t1-sec-title">Travel more.<br><span class="t1-accent-text">Live the life you want.</span></h2>
      <p class="t1-sec-sub">Book hotels, apartments and experiences around the world in a few taps, and pay from any <?php echo bc_landing_e($home_name); ?> balance.</p>
    </div>

    <div class="t1-cards-3">
      <div style="border-radius:24px;overflow:hidden;position:relative;min-height:380px;background:#2B2B31;box-shadow:var(--t1-shadow-md);">
        <svg aria-hidden="true" viewBox="0 0 400 400" preserveAspectRatio="xMidYMid slice" style="position:absolute;inset:0;width:100%;height:100%;">
          <rect width="400" height="400" fill="#E8D9C4"/><rect x="220" y="30" width="150" height="200" fill="#9CC8EE"/><rect x="0" y="290" width="400" height="110" fill="#8A5A3A"/><rect x="20" y="220" width="200" height="65" rx="16" fill="#F4EFE6"/>
        </svg>
        <div style="position:absolute;left:16px;right:16px;bottom:16px;background:rgba(255,255,255,0.95);border-radius:16px;padding:14px 16px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
          <div>
            <div style="font-size:15px;font-weight:700;color:#0F172A;">Hotels &amp; apartments</div>
            <div style="font-size:12.5px;color:#475569;">From See account pricing / night</div>
          </div>
          <a href="/Services.php" class="t1-btn t1-btn--brand" style="min-height:44px;padding:9px 18px;">Explore</a>
        </div>
      </div>

      <div style="border-radius:24px;overflow:hidden;background:#fff;border:1px solid var(--t1-border);display:flex;flex-direction:column;box-shadow:var(--t1-shadow-sm);">
        <svg aria-hidden="true" viewBox="0 0 400 220" preserveAspectRatio="xMidYMid slice" style="width:100%;height:220px;display:block;">
          <rect width="400" height="220" fill="#EADFCC"/><rect x="0" y="170" width="400" height="50" fill="#9A6236"/><rect x="85" y="75" width="210" height="105" rx="14" fill="#FFFFFF"/><rect x="115" y="102" width="46" height="26" rx="8" fill="#F6F7FA"/><rect x="175" y="102" width="46" height="26" rx="8" fill="var(--home-ink)"/>
        </svg>
        <div style="padding:22px 24px 26px;display:flex;flex-direction:column;gap:8px;">
          <h3 class="t1-display" style="margin:0;font-size:21px;font-weight:700;">Reserve now, pay later</h3>
          <p style="margin:0;font-size:15px;line-height:1.55;color:#475569;">Hold your room instantly and settle the bill closer to your stay.</p>
        </div>
      </div>

      <div style="border-radius:24px;overflow:hidden;background:#fff;border:1px solid var(--t1-border);display:flex;flex-direction:column;box-shadow:var(--t1-shadow-sm);">
        <svg aria-hidden="true" viewBox="0 0 400 220" preserveAspectRatio="xMidYMid slice" style="width:100%;height:220px;display:block;">
          <rect width="400" height="220" fill="#9FD3F2"/><circle cx="330" cy="48" r="22" fill="#FFD27A"/><rect x="0" y="110" width="400" height="45" fill="#2E86C1"/><rect x="0" y="150" width="400" height="70" fill="#EAD9B5"/>
        </svg>
        <div style="padding:22px 24px 26px;display:flex;flex-direction:column;gap:8px;">
          <h3 class="t1-display" style="margin:0;font-size:21px;font-weight:700;">Luxury for every budget</h3>
          <p style="margin:0;font-size:15px;line-height:1.55;color:#475569;">From city apartments to beach resorts, find a stay that fits your plans and your wallet.</p>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- Students / Tuition -->
<section id="tuition" class="t1-tuition-sec">
  <div class="t1-wrap">
    <div class="t1-sec-head t1-sec-head--center">
      <span class="t1-eyebrow"><?php echo bc_landing_e($home_name); ?> for students</span>
      <h2 class="t1-sec-title">Pay school fees<br><span class="t1-accent-text">anywhere in the world.</span></h2>
      <p class="t1-sec-sub">Pay tuition, accommodation and other student bills at universities abroad, straight from your <?php echo bc_landing_e($home_name); ?> wallet, with the rate and fee shown up front.</p>
      <div style="display:flex;flex-wrap:wrap;justify-content:center;gap:8px;margin-top:4px;">
        <span style="font-size:13.5px;font-weight:600;background:var(--t1-bg);border:1px solid var(--t1-border);padding:8px 14px;border-radius:999px;">Tuition fees</span>
        <span style="font-size:13.5px;font-weight:600;background:var(--t1-bg);border:1px solid var(--t1-border);padding:8px 14px;border-radius:999px;">Accommodation</span>
        <span style="font-size:13.5px;font-weight:600;background:var(--t1-bg);border:1px solid var(--t1-border);padding:8px 14px;border-radius:999px;">Application &amp; exam fees</span>
        <span style="font-size:13.5px;font-weight:600;background:var(--t1-bg);border:1px solid var(--t1-border);padding:8px 14px;border-radius:999px;">Living expenses</span>
      </div>
    </div>

    <div aria-label="Universities you can pay" class="mz-fade t1-tuition-grid">
      <?php
      $uni_cols = array(
          array('offset' => false, 'cls' => 'mz-up', 'bg' => 'linear-gradient(#6FB7F2,#CFE8FB)', 'items' => array(
              array('University of Oxford', 'Oxford, United Kingdom', 'GBP'),
              array('University of Melbourne', 'Melbourne, Australia', 'AUD'),
              array('University of Manchester', 'Manchester, United Kingdom', 'GBP'),
          )),
          array('offset' => true, 'cls' => 'mz-down', 'bg' => 'linear-gradient(#3D8FE0,#A9D4F7)', 'items' => array(
              array('Harvard University', 'Cambridge, MA, USA', 'USD'),
              array('University of Cape Town', 'Cape Town, South Africa', 'ZAR'),
              array('McGill University', 'Montréal, Canada', 'CAD'),
          )),
          array('offset' => false, 'cls' => 'mz-up', 'bg' => 'linear-gradient(#FFB199,#FFE3D6)', 'items' => array(
              array('University of Toronto', 'Toronto, Canada', 'CAD'),
              array('Technical University of Munich', 'Munich, Germany', 'EUR'),
              array('University of Nairobi', 'Nairobi, Kenya', 'KES'),
          )),
      );
      foreach ($uni_cols as $col):
      ?>
        <div class="<?php echo $col['offset'] ? 't1-tuition-col--offset' : ''; ?>">
          <div class="<?php echo $col['cls']; ?>" style="display:flex;flex-direction:column;">
            <?php for ($loop = 0; $loop < 2; $loop++): foreach ($col['items'] as $uni): ?>
              <div style="margin-bottom:18px;height:260px;border-radius:22px;overflow:hidden;position:relative;background:<?php echo $col['bg']; ?>;box-shadow:var(--t1-shadow-md);">
                <svg aria-hidden="true" viewBox="0 0 240 160" preserveAspectRatio="xMidYMax meet" style="position:absolute;left:0;right:0;bottom:64px;width:100%;height:140px;">
                  <rect x="30" y="65" width="180" height="95" fill="#94A3B8"/><polygon points="20,65 120,25 220,65" fill="#64748B"/><rect x="108" y="108" width="24" height="52" fill="#334155"/>
                </svg>
                <div style="position:absolute;left:0;right:0;bottom:0;height:68px;background:#fff;padding:12px 16px;display:flex;flex-direction:column;justify-content:center;gap:2px;">
                  <span style="font-size:14.5px;font-weight:700;color:#0F172A;"><?php echo bc_landing_e($uni[0]); ?></span>
                  <span style="font-size:12px;color:#475569;"><?php echo bc_landing_e($uni[1]); ?></span>
                </div>
                <span style="position:absolute;top:12px;left:12px;font-size:11px;font-weight:700;color:#fff;background:rgba(15,23,42,0.65);padding:5px 10px;border-radius:999px;"><?php echo bc_landing_e($uni[2]); ?></span>
              </div>
            <?php endforeach; endfor; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div style="display:flex;flex-direction:column;align-items:center;gap:12px;margin-top:32px;">
      <a href="/Tuition.php" class="t1-btn t1-btn--brand">Pay tuition</a>
      <span style="font-size:12px;color:#475569;text-align:center;">University names are shown for illustration only and do not imply partnership or endorsement.</span>
    </div>
  </div>
</section>

<!-- Download CTA -->
<section id="download" class="t1-download-sec">
  <div class="t1-download-box">
    <div style="flex:1 1 440px;min-width:0;display:flex;flex-direction:column;gap:14px;">
      <h2 class="t1-sec-title" style="color:var(--home-on-brand);">The only money app you'll ever need.</h2>
      <p class="t1-sec-sub" style="color:var(--home-on-brand);opacity:0.9;">Download <?php echo bc_landing_e($home_name); ?> and open your multi-currency wallet in minutes.</p>
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:14px;">
      <?php echo $home_app_badge; ?>
    </div>
  </div>
</section>

<!-- Footer -->
<footer id="business" class="t1-footer">
  <div class="t1-wrap">
    <div class="t1-footer-grid">
      <div style="display:flex;flex-direction:column;gap:14px;">
        <span style="align-self:flex-start;background:#fff;border-radius:14px;padding:9px 14px;"><span class="home-brand"><?php echo bc_landing_e($home_name); ?></span></span>
        <span style="font-size:14px;line-height:1.6;">Seamless money, without borders.</span>
      </div>
      <div class="t1-footer-col">
        <span style="font-weight:700;color:#fff;">Personal</span>
        <a href="#personal-features">Personal features</a>
        <a href="/services/international-transfer.php">International transfers</a>
        <a href="/MultiCurrencyWallet.php">Multi-currency wallet</a>
        <a href="/services/virtual-cards.php">Cards</a>
        <a href="/BillsAndAirtime.php">Bills &amp; airtime</a>
        <a href="/Savings.php">Savings</a>
      </div>
      <div class="t1-footer-col">
        <span style="font-weight:700;color:#fff;">Business</span>
        <a href="/Business.php">Business account</a>
        <a href="/PaymentLinks.php">Payment links</a>
        <a href="/Invoices.php">Invoices</a>
        <a href="/Payouts.php">Payouts</a>
      </div>
      <div class="t1-footer-col">
        <span style="font-weight:700;color:#fff;">Company</span>
        <a href="/AboutUs.php">About us</a>
        <a href="/Careers.php">Careers</a>
        <a href="/blog.php">Blog</a>
        <a href="/Contact.php">Contact</a>
      </div>
      <div class="t1-footer-col">
        <span style="font-weight:700;color:#fff;">Legal</span>
        <a href="/Privacy.php">Privacy policy</a>
        <a href="/Terms.php">Terms of use</a>
        <a href="/CookiePolicy.php">Cookie policy</a>
      </div>
    </div>

    <div class="t1-footer-bottom">
      <span>Services are subject to verification, local availability and the applicable provider terms. See our Terms of use.</span>
      <span>© 2026 <?php echo bc_landing_e($home_name); ?>. All rights reserved.</span>
    </div>

    <div style="margin-top:24px;"><?php bc_public_directory_html(); ?></div>
  </div>
</footer>

</div></div>

<script>
(function(){
  var bar = document.getElementById('t1PillarTabs');
  var grid = document.getElementById('t1PillarsGrid');
  if (!bar || !grid) return;
  var buttons = bar.querySelectorAll('.t1-filter-chip');
  var cards = grid.querySelectorAll('.t1-pillar-card');
  bar.addEventListener('click', function(e) {
    var btn = e.target.closest('.t1-filter-chip');
    if (!btn) return;
    var filter = btn.getAttribute('data-filter') || 'all';
    for (var i = 0; i < buttons.length; i++) {
      var active = buttons[i] === btn;
      buttons[i].classList.toggle('is-active', active);
      buttons[i].setAttribute('aria-pressed', active ? 'true' : 'false');
    }
    for (var j = 0; j < cards.length; j++) {
      var show = filter === 'all' || cards[j].getAttribute('data-pillar') === filter;
      cards[j].classList.toggle('is-hidden', !show);
    }
  });
}());
</script>
<script src="/jsfile/bc-public-navigation.js?v=1" defer></script>
<script src="/jsfile/bc-public-home.js?v=public-reference-1" defer></script>
</body>
</html>

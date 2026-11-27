<?php
require_once __DIR__ . '/func/bc-public-app-brand.php';
require_once __DIR__ . '/func/bc-public-meridian.php';
require_once __DIR__ . '/func/bc-public-pages.php';
require_once __DIR__ . '/func/bc-theme.php';
include_once(__DIR__ . '/func/bc-seo.php');

$ctx = bc_meridian_context($vendor_account_details ?? null);
$vid = (int)($ctx['vendor_id'] ?? 0);
$site_name = $ctx['site_name'];
$short_brand = $ctx['short_brand'];
$site_e = bc_meridian_e($site_name);
$short_e = bc_meridian_e($short_brand);
$personal_vars = bc_meridian_personal_style_vars($ctx['primary_color']);

// Fetch site details for SEO engine & optional uploaded hero image
$site_details = array(
    'site_title' => $site_name,
    'site_desc' => $ctx['site_desc'],
    'apk_download_url' => $ctx['app_url'],
);
$header_image_url = '';
if ($vid > 0 && ($connection_server ?? null) instanceof mysqli) {
    $stmt_site = mysqli_prepare($connection_server, "SELECT sd.*, vst.header_image, vst.primary_color FROM sas_site_details sd LEFT JOIN sas_vendor_style_templates vst ON vst.vendor_id = sd.vendor_id WHERE sd.vendor_id = ? ORDER BY vst.date DESC LIMIT 1");
    if (!$stmt_site) {
        $stmt_site = mysqli_prepare($connection_server, "SELECT * FROM sas_site_details WHERE vendor_id = ? LIMIT 1");
    }
    if ($stmt_site) {
        mysqli_stmt_bind_param($stmt_site, "i", $vid);
        mysqli_stmt_execute($stmt_site);
        $result_site = mysqli_stmt_get_result($stmt_site);
        if ($result_site && ($site_row = mysqli_fetch_assoc($result_site))) {
            $site_details = $site_row;
            if (!empty($site_row['header_image']) && is_file(__DIR__ . '/uploaded-image/' . $site_row['header_image'])) {
                $header_image_url = '/uploaded-image/' . $site_row['header_image'];
            }
        }
        mysqli_stmt_close($stmt_site);
    }
}

if (function_exists('seo_start_ob_auto_alt')) {
    seo_start_ob_auto_alt($site_name);
}

$t2_primary = bc_theme_hex($ctx['primary_color']) ?: '#287BFF';
$t2_dark = bc_theme_palette($t2_primary)['modes']['dark'];
$t2_rgb = '40, 123, 255';
if (sscanf($t2_primary, '#%02x%02x%02x', $r2, $g2, $b2) === 3) {
    $t2_rgb = "$r2, $g2, $b2";
}
$t2_accent_2 = bc_meridian_mix_hex($t2_primary, '#38BDF8', 0.35);
$t2_accent_3 = bc_meridian_mix_hex($t2_primary, '#A855F7', 0.45);
$t2_dash = function_exists('bc_public_dashboard_url') ? bc_public_dashboard_url() : '';
$t2_app_badge = bc_public_app_badge_html($ctx['app_url']);

// Service Control Center helper
$t2_enabled = function ($key) use ($vid) {
    if ($key === null || $key === '') return true;
    return bc_meridian_service_enabled($key, $vid);
};

// Personal Features directory — every service listens to the Service Control Center
$personal_pillars = array();
$coming_soon_items = array();

$add_feature = function (&$active_list, $title, $desc, $href, $key = null, $badge = '', $pending_note = '') use (&$coming_soon_items, $t2_enabled) {
    $cat_title = $active_list['_cat_title'] ?? 'Personal Features';
    if ($t2_enabled($key)) {
        $active_list['items'][] = array(
            'title' => $title,
            'desc' => $desc,
            'href' => $href,
            'badge' => $badge,
        );
    } else {
        $coming_soon_items[] = array(
            'title' => $title,
            'category' => $cat_title,
            'desc' => $desc,
            'href' => $href,
            'reason' => $pending_note !== '' ? $pending_note : 'Paused in Service Control Center',
        );
    }
};

// 1. Accounts & Payments
$pillar_accounts = array(
    'id' => 'accounts-payments',
    'title' => 'Accounts & Payments',
    'eyebrow' => '01 · Accounts & Payments',
    'subtitle' => 'Core wallet management, receipts, transfers, virtual accounts & FX',
    'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="5" width="19" height="14" rx="3"/><path d="M2.5 10h19M16 14.5h2.5"/></svg>',
    'items' => array(),
    '_cat_title' => 'Accounts & Payments',
);
$add_feature($pillar_accounts, 'Personal wallet and account dashboard', 'Unified NGN and multi-currency balance overview with real-time status.', '/web/Dashboard.php', null, 'Core');
$add_feature($pillar_accounts, 'Wallet funding and payment receipts', 'Top up via cards, bank gateways, or manual funding with instant downloadable receipts.', '/web/Fund.php', null, 'Core');

$bank_tx_on = $t2_enabled('bank_transfer');
$share_on = $t2_enabled('share_fund');
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
        'desc' => 'Send funds directly to verified Nigerian bank accounts with instant tracking.',
        'href' => '/services/bank-transfer.php',
        'badge' => 'Active',
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
        'desc' => 'Instant peer-to-peer wallet transfers inside the platform.',
        'href' => '/services/share-fund.php',
        'badge' => 'Active',
    );
    $coming_soon_items[] = array(
        'title' => 'Local bank transfers',
        'category' => 'Accounts & Payments',
        'desc' => 'Transfers to external Nigerian bank accounts.',
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

$add_feature($pillar_accounts, 'International transfer requests', 'Cross-border remittance requests across USD, GBP, EUR and global corridors.', '/services/international-transfer.php', 'intl_transfer', 'Global');
$add_feature($pillar_accounts, 'Virtual bank accounts', 'Dedicated virtual collection bank accounts that auto-credit your personal wallet.', '/web/VirtualBanks.php', 'virtual_bank_display', 'Auto-Credit');
$add_feature($pillar_accounts, 'Transaction history and payment tracking', 'Full auditable ledger with order references, live status checks and CSV export.', '/web/Transactions.php', null, 'Ledger');
$add_feature($pillar_accounts, 'Live currency conversion and indicative FX rates', 'Convert across NGN, USD, GBP and EUR with transparent indicative FX quotes.', '/MultiCurrencyWallet.php', 'currency_conversion', 'FX');
$personal_pillars[] = $pillar_accounts;

// 2. Everyday Services
$pillar_everyday = array(
    'id' => 'everyday-services',
    'title' => 'Everyday Services',
    'eyebrow' => '02 · Everyday Services',
    'subtitle' => 'Automated bills, telecom recharges, education & travel essentials',
    'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L4 14h7l-1 8 9-12h-7z"/></svg>',
    'items' => array(),
    '_cat_title' => 'Everyday Services',
);
$add_feature($pillar_everyday, 'Airtime purchase', 'Instant MTN, Airtel, Glo and 9mobile VTU recharge with automatic network detection.', '/services/airtime.php', 'airtime', '24/7');
$add_feature($pillar_everyday, 'Mobile data purchase', 'SME, corporate gifting, shared and direct data plans across all networks.', '/services/data.php', 'data', 'Bundles');
$add_feature($pillar_everyday, 'Electricity bill payments', 'Prepaid meter tokens and postpaid DisCo bills with meter name verification.', '/services/electricity.php', 'electric', 'Tokens');
$add_feature($pillar_everyday, 'Cable TV subscriptions', 'DStv, GOtv, StarTimes and Showmax renewals with smartcard validation.', '/services/cable-tv.php', 'cable', 'TV');
$add_feature($pillar_everyday, 'Betting account funding', 'Instant bookmaker wallet funding with verified customer ID confirmation.', '/services/betting.php', 'betting', 'Gaming');
$add_feature($pillar_everyday, 'Examination PINs', 'WAEC, NECO, NABTEB and JAMB e-PINs delivered straight to your history.', '/services/exam-pins.php', 'exam', 'e-PINs');
$add_feature($pillar_everyday, 'Travel eSIM services', 'Instant digital data eSIMs for international travel across 190+ destinations.', '/services/esim.php', 'esim', 'Travel');
$add_feature($pillar_everyday, 'Tuition payment requests', 'Structured cross-border education and university fee payment requests.', '/Tuition.php', 'tuition', 'Education');
if (!empty($pillar_everyday['items'])) {
    $personal_pillars[] = $pillar_everyday;
}

// 3. Cards & Digital Assets
$pillar_cards = array(
    'id' => 'cards-digital-assets',
    'title' => 'Cards & Digital Assets',
    'eyebrow' => '03 · Cards & Digital Assets',
    'subtitle' => 'Global virtual cards, brand gift cards & digital asset settlement',
    'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="5.5" width="19" height="13" rx="2.5"/><path d="M2.5 10h19M7 14.5h4"/></svg>',
    'items' => array(),
    '_cat_title' => 'Cards & Digital Assets',
);
$add_feature($pillar_cards, 'Virtual dollar cards', 'Create, fund and freeze USD virtual cards for global subscriptions and online shopping.', '/services/virtual-cards.php', 'virtual_card', 'USD');
$add_feature($pillar_cards, 'Gift card services', 'Purchase global retail, streaming and gaming gift cards with rapid code delivery.', '/services/gift-cards.php', 'gift_card', 'Global');
$add_feature($pillar_cards, 'Crypto Buy/Sell and stablecoin services', 'USDC, USDT and digital asset rails governed by replacement provider integration.', '/services/crypto.php', 'crypto_hub', 'Digital', 'Pending replacement integration');
if (!empty($pillar_cards['items'])) {
    $personal_pillars[] = $pillar_cards;
}

// 4. Rewards & Tools
$pillar_rewards = array(
    'id' => 'rewards-tools',
    'title' => 'Rewards & Tools',
    'eyebrow' => '04 · Rewards & Tools',
    'subtitle' => 'Referrals, loyalty coin conversion, cost planning & smart budgeting',
    'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l2.4 5.6L20 11l-5.6 2.4L12 19l-2.4-5.6L4 11l5.6-2.4z"/></svg>',
    'items' => array(),
    '_cat_title' => 'Rewards & Tools',
);
$add_feature($pillar_rewards, 'Referral programme', 'Invite friends and earn referral rewards that unlock on qualifying settlement volume.', '/ReferAndEarn.php', 'referral', 'Earn');
$add_feature($pillar_rewards, 'Loyalty points and rewards', 'Earn loyalty coins on eligible activity and convert unlocked value to your wallet.', '/services/CoinConversion.php', 'vtu_coins', 'Coins');
$add_feature($pillar_rewards, 'Transaction calculator', 'Calculate checkout amounts, fees and estimated margins before confirming orders.', '/web/TransactionCalculator.php', null, 'Tool');
$add_feature($pillar_rewards, 'AI assistant and budgeting tools', 'Smart financial assistant, spend insights and personal budgeting guidance.', '/web/AI-Assistant.php', 'ai_suite', 'AI');
$personal_pillars[] = $pillar_rewards;

// 5. Security & Support
$pillar_security = array(
    'id' => 'security-support',
    'title' => 'Security & Support',
    'eyebrow' => '05 · Security & Support',
    'subtitle' => 'Multi-factor protection, identity checks, recovery & dispute care',
    'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l7 3v5c0 5-3.5 8.8-7 10-3.5-1.2-7-5-7-10V6z"/><path d="M9.5 12l1.8 1.8L15 10"/></svg>',
    'items' => array(),
    '_cat_title' => 'Security & Support',
);
$add_feature($pillar_security, 'Identity verification—KYC', 'Tiered KYC verification to safeguard accounts and unlock higher transaction limits.', '/web/KYCVerification.php', null, 'KYC');
$add_feature($pillar_security, 'Security PIN', 'Dedicated transaction PIN confirming transfers, bills and sensitive wallet actions.', '/web/SecurityPIN.php', null, 'PIN');
$add_feature($pillar_security, 'Google Authenticator and email OTP options', 'Step-up 2FA via TOTP authenticator apps or verified email one-time codes.', '/web/SecuritySetup.php', null, '2FA');
if ($t2_enabled('password_reset')) {
    $pillar_security['items'][] = array(
        'title' => 'Account recovery and security settings',
        'desc' => 'Guided password recovery, active session controls and security preferences.',
        'href' => '/web/PasswordRecovery.php',
        'badge' => 'Protected',
    );
} else {
    $pillar_security['items'][] = array(
        'title' => 'Security settings and session controls',
        'desc' => 'Manage account security settings and device sessions from your console.',
        'href' => '/web/AccountSettings.php',
        'badge' => 'Console',
    );
    $coming_soon_items[] = array(
        'title' => 'Self-service password reset recovery',
        'category' => 'Security & Support',
        'desc' => 'Automated self-service account password recovery.',
        'href' => '/web/PasswordRecovery.php',
        'reason' => 'Paused in Service Control Center',
    );
}
$add_feature($pillar_security, 'Customer support and dispute assistance', 'Reach human support for order queries, receipt lookups and dispute resolution.', '/Contact.php', null, '24/7');
$personal_pillars[] = $pillar_security;

$active_feature_count = 0;
foreach ($personal_pillars as $p) {
    $active_feature_count += count($p['items']);
}
$paused_feature_count = count($coming_soon_items);

$js_config = array(
    'siteName' => $site_name,
    'appUrl' => $ctx['app_url'],
    'downloadPageUrl' => '/DownloadApp.php',
    'businessUrl' => '/Business.php',
    'personalUrl' => '/',
    'registerUrl' => '/web/Register.php',
    'loginUrl' => '/web/Login.php',
    'rates' => $ctx['rates'],
);
?><!DOCTYPE html>
<html lang="en" data-theme="dark" data-world="personal" style="<?php echo bc_meridian_e($personal_vars); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php
    if (function_exists('seo_render_head_tags')) {
        seo_render_head_tags($site_details, is_array($vendor_account_details ?? null) ? $vendor_account_details : array(), $site_name . ' | Modern Glassmorphism Personal Finance & Bills');
    } else {
        echo '<title>' . $site_e . ' | Personal Finance &amp; Everyday Services</title>';
    }
    ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@500;600;700&display=swap">
    <script>window.MERIDIAN_CONFIG = <?php echo json_encode($js_config, JSON_UNESCAPED_SLASHES); ?>;</script>
    <style>
        :root {
            --t2-primary: <?php echo $t2_primary; ?>;
            --t2-primary-rgb: <?php echo $t2_rgb; ?>;
            --t2-on-primary: <?php echo $t2_dark['on_primary']; ?>;
            --t2-primary-text: <?php echo $t2_dark['primary_text']; ?>;
            --t2-accent-2: <?php echo $t2_accent_2; ?>;
            --t2-accent-3: <?php echo $t2_accent_3; ?>;
            --t2-bg: #050816;
            --t2-bg-2: #0B1228;
            --t2-text: #F8FAFC;
            --t2-text-2: #CBD5E1;
            --t2-text-3: #94A3B8;
            --t2-glass-bg: linear-gradient(145deg, rgba(255, 255, 255, 0.11), rgba(255, 255, 255, 0.035));
            --t2-glass-strong: linear-gradient(145deg, rgba(255, 255, 255, 0.16), rgba(255, 255, 255, 0.05));
            --t2-rim: rgba(255, 255, 255, 0.16);
            --t2-rim-hi: rgba(255, 255, 255, 0.34);
            --t2-shadow: 0 28px 70px -24px rgba(2, 6, 23, 0.85);
            --t2-glow: rgba(var(--t2-primary-rgb), 0.38);
            --t2-font: "Figtree", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            --t2-mono: "JetBrains Mono", ui-monospace, monospace;
            /* Meridian shared header/sheet compatibility vars */
            --bg: var(--t2-bg);
            --bg2: #0F172A;
            --text: var(--t2-text);
            --text2: var(--t2-text-2);
            --text3: var(--t2-text-3);
            --rim: var(--t2-rim);
            --rimhi: var(--t2-rim-hi);
            --accent: var(--t2-primary);
            --accent-ink: var(--t2-on-primary);
            --chip: rgba(255, 255, 255, 0.07);
            --chiph: rgba(255, 255, 255, 0.14);
            --ring: var(--t2-primary-text);
            --r: 26px;
            --r-sm: 16px;
            --pill: 999px;
            --dur: .28s;
            --ease: cubic-bezier(.2, .8, .2, 1);
            --gutter-l: max(16px, env(safe-area-inset-left, 0px));
            --gutter-r: max(16px, env(safe-area-inset-right, 0px));
        }

        *, *::before, *::after { box-sizing: border-box; }
        html { scroll-behavior: smooth; background: var(--t2-bg); }
        body {
            margin: 0;
            font-family: var(--t2-font);
            color: var(--t2-text);
            background: var(--t2-bg);
            line-height: 1.55;
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
        }
        a { color: inherit; }
        button, input, select { font: inherit; color: inherit; }
        img, svg { display: block; max-width: 100%; }
        [hidden] { display: none !important; }
        :focus-visible { outline: 2px solid var(--t2-primary-text); outline-offset: 3px; border-radius: 12px; }

        /* ── Animated Aurora Glass Backdrop ─────────────────────────── */
        .t2-backdrop {
            position: fixed;
            inset: 0;
            z-index: 0;
            pointer-events: none;
            overflow: hidden;
            background:
                radial-gradient(920px 540px at 10% -6%, rgba(var(--t2-primary-rgb), 0.34), transparent 62%),
                radial-gradient(840px 540px at 92% 8%, color-mix(in srgb, var(--t2-accent-2) 28%, transparent), transparent 60%),
                radial-gradient(760px 560px at 52% 98%, color-mix(in srgb, var(--t2-accent-3) 24%, transparent), transparent 64%),
                linear-gradient(180deg, #050816 0%, #091026 52%, #040612 100%);
        }
        .t2-orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(85px);
            opacity: 0.55;
            will-change: transform;
        }
        .t2-orb--1 {
            width: 44vmax; height: 44vmax; left: -12vmax; top: -10vmax;
            background: radial-gradient(circle, rgba(var(--t2-primary-rgb), 0.62) 0%, transparent 68%);
            animation: t2Drift 18s ease-in-out infinite alternate;
        }
        .t2-orb--2 {
            width: 38vmax; height: 38vmax; right: -10vmax; top: 24vh;
            background: radial-gradient(circle, color-mix(in srgb, var(--t2-accent-2) 56%, transparent) 0%, transparent 68%);
            animation: t2Drift 22s ease-in-out infinite alternate-reverse;
        }
        .t2-orb--3 {
            width: 34vmax; height: 34vmax; left: 26vw; bottom: -14vmax;
            background: radial-gradient(circle, color-mix(in srgb, var(--t2-accent-3) 48%, transparent) 0%, transparent 68%);
            animation: t2Drift 20s ease-in-out infinite alternate;
        }
        .t2-grid-mesh {
            position: absolute;
            inset: 0;
            background-image:
                linear-gradient(rgba(255, 255, 255, 0.035) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255, 255, 255, 0.035) 1px, transparent 1px);
            background-size: 64px 64px;
            mask-image: radial-gradient(ellipse 80% 70% at 50% 20%, #000 35%, transparent 90%);
            -webkit-mask-image: radial-gradient(ellipse 80% 70% at 50% 20%, #000 35%, transparent 90%);
        }
        @keyframes t2Drift {
            0% { transform: translate3d(0, 0, 0) scale(1); }
            100% { transform: translate3d(38px, -26px, 0) scale(1.08); }
        }

        /* ── Core Frosted Glass Surface ─────────────────────────────── */
        .glass {
            position: relative;
            isolation: isolate;
            background: var(--t2-glass-bg);
            -webkit-backdrop-filter: blur(24px) saturate(180%);
            backdrop-filter: blur(24px) saturate(180%);
            border: 1px solid var(--t2-rim);
            border-radius: var(--r);
            box-shadow:
                inset 0 1px 0 var(--t2-rim-hi),
                inset 0 0 28px rgba(255, 255, 255, 0.035),
                var(--t2-shadow);
        }
        .glass.tint {
            background: var(--t2-glass-strong);
        }

        .t2-wrap {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 1240px;
            margin: 0 auto;
            padding-left: var(--gutter-l);
            padding-right: var(--gutter-r);
        }

        /* ── Buttons & Pills ────────────────────────────────────────── */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            min-height: 48px;
            padding: 0 22px;
            border-radius: 999px;
            border: 1px solid transparent;
            cursor: pointer;
            font-weight: 700;
            font-size: 15px;
            text-decoration: none;
            transition: transform .22s var(--ease), box-shadow .22s ease, background .22s ease, border-color .22s ease;
            white-space: nowrap;
        }
        .btn:active { transform: scale(.97); }
        .btn svg { width: 18px; height: 18px; flex: none; }
        .btn.primary {
            background: var(--t2-primary);
            color: var(--t2-on-primary);
            border-color: color-mix(in srgb, var(--t2-on-primary) 22%, transparent);
            box-shadow: inset 0 1px 0 rgba(255,255,255,.35), 0 14px 32px -12px var(--t2-glow);
        }
        .btn.primary:hover {
            transform: translateY(-2px);
            box-shadow: inset 0 1px 0 rgba(255,255,255,.45), 0 18px 40px -10px var(--t2-glow);
        }
        .btn.ghost {
            background: rgba(255, 255, 255, 0.075);
            color: var(--t2-text);
            border-color: rgba(255, 255, 255, 0.18);
            -webkit-backdrop-filter: blur(16px);
            backdrop-filter: blur(16px);
        }
        .btn.ghost:hover {
            background: rgba(255, 255, 255, 0.14);
            border-color: rgba(255, 255, 255, 0.3);
            transform: translateY(-1px);
        }
        .store {
            min-height: 48px;
            padding: 8px 18px;
            border-radius: 999px;
            text-align: left;
            line-height: 1.15;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: var(--t2-text);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }
        .store:hover { background: rgba(255, 255, 255, 0.15); }
        .store small { display: block; font-size: 10.5px; opacity: .8; font-weight: 500; }
        .store b { font-size: 14px; font-weight: 700; }

        /* ── Meridian-1 Parity Glass Header & Mobile Sheet ──────────── */
        .nav {
            position: fixed;
            z-index: 50;
            top: 0; left: 0; right: 0;
            padding-top: max(12px, env(safe-area-inset-top, 0px));
            pointer-events: none;
        }
        .nav-in {
            pointer-events: auto;
            display: flex;
            align-items: center;
            gap: 12px;
            height: 66px;
            max-width: 1240px;
            margin: 0 var(--gutter-r) 0 var(--gutter-l);
            padding: 0 10px 0 18px;
            border-radius: 999px;
            background: rgba(11, 18, 40, 0.56);
            border: 1px solid rgba(255, 255, 255, 0.16);
            -webkit-backdrop-filter: blur(26px) saturate(180%);
            backdrop-filter: blur(26px) saturate(180%);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.25), 0 18px 44px -20px rgba(0, 0, 0, 0.72);
            transition: background .25s ease, border-color .25s ease, box-shadow .25s ease;
        }
        @media (min-width: 1272px) {
            .nav-in { margin-left: auto; margin-right: auto; }
        }
        .nav.compact .nav-in {
            background: rgba(8, 13, 30, 0.78);
            border-color: rgba(255, 255, 255, 0.22);
        }
        .logo {
            display: inline-flex;
            align-items: center;
            min-height: 44px;
            text-decoration: none;
            color: var(--t2-text);
            font-weight: 800;
            font-size: 17px;
            letter-spacing: -0.02em;
            flex: none;
        }
        .m-logo-img {
            width: 34px; height: 34px;
            border-radius: 10px;
            object-fit: cover;
            background: rgba(255,255,255,.08);
        }
        .m-brand-mark {
            width: 34px; height: 34px;
            border-radius: 10px;
            display: inline-grid;
            place-items: center;
            background: var(--t2-primary);
            color: var(--t2-on-primary);
            font-weight: 900;
            font-size: 16px;
            border: 1px solid rgba(255,255,255,.22);
        }
        .pswitch {
            position: relative;
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            height: 44px;
            padding: 3px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.16);
            flex: none;
        }
        .pswitch button {
            position: relative;
            z-index: 2;
            min-width: 78px;
            min-height: 36px;
            border: 0;
            background: none;
            border-radius: 999px;
            cursor: pointer;
            font-weight: 700;
            font-size: 13.5px;
            color: var(--t2-text-2);
            padding: 0 12px;
            transition: color .25s ease;
        }
        .pswitch button[aria-checked="true"] { color: var(--t2-on-primary); }
        .pthumb {
            position: absolute;
            z-index: 1;
            top: 3px; bottom: 3px; left: 3px;
            border-radius: 999px;
            background: var(--t2-primary);
            box-shadow: inset 0 1px 0 rgba(255,255,255,.45), 0 6px 18px -6px var(--t2-glow);
            transition: transform .32s cubic-bezier(.22, 1, .36, 1);
        }
        .links {
            display: none;
            gap: 2px;
            margin: 0 auto;
        }
        .links a {
            padding: 9px 12px;
            border-radius: 999px;
            text-decoration: none;
            font-weight: 600;
            font-size: 14.5px;
            color: var(--t2-text-2);
            min-height: 40px;
            display: inline-flex;
            align-items: center;
            white-space: nowrap;
            transition: background .2s ease, color .2s ease;
        }
        .links a:hover { color: var(--t2-text); background: rgba(255, 255, 255, 0.09); }
        .links a.is-active, .links a[aria-current="page"] {
            color: var(--t2-text);
            background: rgba(var(--t2-primary-rgb), 0.24);
            border: 1px solid rgba(var(--t2-primary-rgb), 0.45);
        }
        .nav-r { display: flex; align-items: center; gap: 8px; margin-left: auto; }
        .nav .get { min-height: 42px; padding: 0 18px; font-size: 14px; }
        .icon-btn {
            width: 42px; height: 42px;
            border-radius: 999px;
            border: 1px solid var(--t2-rim);
            background: rgba(255, 255, 255, 0.08);
            display: grid;
            place-items: center;
            cursor: pointer;
            color: var(--t2-text);
        }
        .icon-btn svg { width: 20px; height: 20px; }
        @media (min-width: 1200px) {
            .links { display: flex; }
            .menu-btn { display: none; }
        }
        @media (max-width: 960px) {
            .nav .get, .nav-r > .ghost { display: none; }
        }
        @media (max-width: 600px) {
            .ff-word { display: none; }
            .pswitch button { min-width: 66px; font-size: 12.5px; padding: 0 9px; }
            .nav-in { padding: 0 8px 0 12px; gap: 8px; }
        }

        /* Mobile / App Modal Sheet */
        .sheet {
            position: fixed;
            inset: 0;
            z-index: 80;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 88px var(--gutter-r) 24px var(--gutter-l);
            background: rgba(3, 6, 18, 0.65);
            -webkit-backdrop-filter: blur(10px);
            backdrop-filter: blur(10px);
            overflow: auto;
        }
        .sheet > .glass { width: 100%; max-width: 440px; padding: 16px; background: rgba(12, 18, 40, 0.9); }
        .sheet .mlinks { display: grid; gap: 4px; margin: 12px 0; }
        .sheet .mlinks a {
            display: flex; align-items: center; min-height: 46px; padding: 0 14px;
            border-radius: 14px; text-decoration: none; font-weight: 600; font-size: 16px; color: var(--t2-text-2);
        }
        .sheet .mlinks a:hover, .sheet .mlinks a.is-active {
            background: rgba(var(--t2-primary-rgb), 0.22);
            color: var(--t2-text);
        }
        .sheet .row { display: flex; gap: 10px; padding-top: 8px; }
        .toast {
            position: fixed; z-index: 90; left: 50%; bottom: 24px;
            transform: translate(-50%, 16px); opacity: 0; pointer-events: none;
            padding: 12px 20px; border-radius: 999px; font-weight: 600; font-size: 14px;
            transition: opacity .25s ease, transform .25s ease;
        }
        .toast.on { opacity: 1; transform: translate(-50%, 0); }

        /* ── Hero Section ───────────────────────────────────────────── */
        .t2-hero {
            padding: 132px 0 64px;
            position: relative;
        }
        .t2-hero-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.1fr) minmax(0, 0.95fr);
            gap: 36px;
            align-items: center;
        }
        @media (max-width: 980px) {
            .t2-hero { padding: 112px 0 48px; }
            .t2-hero-grid { grid-template-columns: 1fr; gap: 28px; }
        }
        .t2-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 7px 14px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.18);
            color: var(--t2-primary-text);
            font-size: 12.5px;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            margin-bottom: 18px;
        }
        .t2-eyebrow i {
            width: 8px; height: 8px; border-radius: 50%;
            background: #34D399;
            box-shadow: 0 0 0 5px rgba(52, 211, 153, 0.22);
        }
        .t2-hero h1 {
            margin: 0 0 18px;
            font-size: clamp(36px, 5.2vw, 62px);
            font-weight: 900;
            letter-spacing: -0.035em;
            line-height: 1.04;
            background: linear-gradient(135deg, #FFFFFF 45%, var(--t2-primary-text) 100%);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .t2-hero-lede {
            font-size: clamp(16.5px, 1.5vw, 19px);
            color: var(--t2-text-2);
            max-width: 56ch;
            margin: 0 0 26px;
        }
        .t2-ctas {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
        }
        .m-support-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 20px;
        }
        .m-support-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 14px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.14);
            color: var(--t2-text-2);
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            transition: background .18s ease, border-color .18s ease;
        }
        .m-support-chip:hover { background: rgba(255, 255, 255, 0.12); border-color: rgba(255, 255, 255, 0.28); color: #fff; }
        .m-support-chip svg { width: 15px; height: 15px; color: var(--t2-primary-text); }

        /* Hero Glass Prism Dashboard Card */
        .t2-prism-card {
            padding: 24px;
            border-radius: 30px;
            display: grid;
            gap: 18px;
        }
        .t2-prism-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }
        .t2-prism-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 11px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            background: rgba(52, 211, 153, 0.14);
            border: 1px solid rgba(52, 211, 153, 0.35);
            color: #A7F3D0;
        }
        .t2-balance-box {
            padding: 18px 20px;
            border-radius: 20px;
            background: linear-gradient(135deg, rgba(var(--t2-primary-rgb), 0.24), rgba(255, 255, 255, 0.04));
            border: 1px solid rgba(255, 255, 255, 0.18);
        }
        .t2-balance-lbl { font-size: 12.5px; color: var(--t2-text-2); font-weight: 600; }
        .t2-balance-val {
            font-size: clamp(28px, 3.4vw, 38px);
            font-weight: 900;
            letter-spacing: -0.03em;
            font-variant-numeric: tabular-nums;
            margin: 4px 0 8px;
        }
        .t2-fx-strip {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .t2-fx-pill {
            padding: 5px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            font-family: var(--t2-mono);
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.14);
            color: var(--t2-text-2);
        }
        .t2-mini-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }
        .t2-mini-tile {
            padding: 13px 14px;
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.12);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 11px;
            transition: transform .18s ease, background .18s ease, border-color .18s ease;
        }
        .t2-mini-tile:hover {
            transform: translateY(-2px);
            background: rgba(var(--t2-primary-rgb), 0.18);
            border-color: rgba(var(--t2-primary-rgb), 0.42);
        }
        .t2-mini-ico {
            width: 36px; height: 36px; border-radius: 11px; flex: none;
            display: grid; place-items: center;
            background: rgba(var(--t2-primary-rgb), 0.22);
            color: var(--t2-primary-text);
            border: 1px solid rgba(var(--t2-primary-rgb), 0.35);
        }
        .t2-mini-ico svg { width: 18px; height: 18px; }
        .t2-mini-tile b { display: block; font-size: 13.5px; line-height: 1.25; }
        .t2-mini-tile span { display: block; font-size: 11.5px; color: var(--t2-text-3); }
        .t2-hero-media {
            border-radius: 18px;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.15);
            max-height: 170px;
        }
        .t2-hero-media img { width: 100%; height: 170px; object-fit: cover; }

        /* ── Metrics Glass Strip ────────────────────────────────────── */
        .t2-metrics {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
            margin-top: 28px;
        }
        @media (max-width: 820px) { .t2-metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        .t2-metric {
            padding: 18px 20px;
            border-radius: 20px;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .t2-metric strong {
            font-size: 24px;
            font-weight: 800;
            letter-spacing: -0.02em;
            color: #FFFFFF;
        }
        .t2-metric span { font-size: 13px; color: var(--t2-text-3); }

        /* ── Section Commons ────────────────────────────────────────── */
        .t2-sec { padding: 68px 0; position: relative; }
        .t2-sec-head {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-end;
            justify-content: space-between;
            gap: 18px;
            margin-bottom: 28px;
        }
        .t2-sec-head h2 {
            margin: 8px 0 0;
            font-size: clamp(28px, 3.8vw, 44px);
            font-weight: 800;
            letter-spacing: -0.03em;
            line-height: 1.08;
        }
        .t2-sec-head p.sub {
            margin: 10px 0 0;
            color: var(--t2-text-2);
            max-width: 62ch;
            font-size: 16px;
        }

        /* ── Interactive Glass Preview Dock ─────────────────────────── */
        .t2-lab {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(310px, 1fr));
            gap: 20px;
        }
        .t2-lab-card {
            padding: 26px;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        .t2-lab-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
        }
        .t2-lab-head h3 { margin: 0; font-size: 20px; font-weight: 800; }
        .t2-tag {
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 11.5px;
            font-weight: 700;
            background: rgba(var(--t2-primary-rgb), 0.18);
            border: 1px solid rgba(var(--t2-primary-rgb), 0.36);
            color: var(--t2-primary-text);
        }
        .t2-chips { display: flex; flex-wrap: wrap; gap: 8px; }
        .t2-chip {
            min-height: 38px;
            padding: 0 14px;
            border-radius: 999px;
            border: 1px solid rgba(255, 255, 255, 0.16);
            background: rgba(255, 255, 255, 0.06);
            color: var(--t2-text-2);
            font-size: 13.5px;
            font-weight: 600;
            cursor: pointer;
            transition: background .16s ease, border-color .16s ease, color .16s ease;
        }
        .t2-chip:hover { background: rgba(255, 255, 255, 0.14); color: #fff; }
        .t2-chip[aria-pressed="true"], .t2-chip.is-on {
            background: var(--t2-primary);
            color: var(--t2-on-primary);
            border-color: transparent;
        }
        .t2-input {
            width: 100%;
            min-height: 46px;
            padding: 0 14px;
            border-radius: 14px;
            border: 1px solid rgba(255, 255, 255, 0.16);
            background: rgba(255, 255, 255, 0.06);
            color: var(--t2-text);
            font-size: 15px;
        }
        .t2-input:focus { outline: none; border-color: var(--t2-primary-text); box-shadow: 0 0 0 3px var(--t2-glow); }
        .t2-range { width: 100%; accent-color: var(--t2-primary); cursor: pointer; }
        .t2-quote-box {
            padding: 14px 16px;
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.055);
            border: 1px solid rgba(255, 255, 255, 0.12);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }
        .t2-quote-num {
            font-size: 24px;
            font-weight: 900;
            font-variant-numeric: tabular-nums;
            color: #fff;
        }

        /* ── Personal Features 5-Pillar Grid ────────────────────────── */
        .t2-filter-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 24px;
        }
        .t2-pillars {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(340px, 1fr));
            gap: 20px;
        }
        @media (max-width: 440px) {
            .t2-pillars { grid-template-columns: 1fr; }
        }
        .t2-pillar {
            padding: 24px;
            display: flex;
            flex-direction: column;
            gap: 16px;
            transition: transform .22s var(--ease), border-color .22s ease;
        }
        .t2-pillar:hover {
            transform: translateY(-3px);
            border-color: color-mix(in srgb, var(--t2-primary) 45%, rgba(255, 255, 255, 0.25));
        }
        .t2-pillar-head {
            display: flex;
            align-items: flex-start;
            gap: 14px;
        }
        .t2-pillar-icon {
            width: 46px; height: 46px;
            border-radius: 15px;
            flex: none;
            display: grid;
            place-items: center;
            background: color-mix(in srgb, var(--t2-primary) 20%, rgba(255, 255, 255, 0.06));
            border: 1px solid color-mix(in srgb, var(--t2-primary) 40%, rgba(255, 255, 255, 0.16));
            color: var(--t2-primary-text);
        }
        .t2-pillar-icon svg { width: 22px; height: 22px; }
        .t2-pillar-eyebrow {
            display: block;
            font-size: 11.5px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--t2-primary-text);
            margin-bottom: 3px;
        }
        .t2-pillar-head h3 { margin: 0; font-size: 21px; font-weight: 800; }
        .t2-pillar-sub { margin: 4px 0 0; font-size: 13.5px; color: var(--t2-text-3); }
        .t2-feature-list {
            list-style: none;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            gap: 9px;
        }
        .t2-feature-list li a {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 14px;
            border-radius: 15px;
            background: rgba(255, 255, 255, 0.045);
            border: 1px solid rgba(255, 255, 255, 0.09);
            text-decoration: none;
            transition: background .16s ease, border-color .16s ease, transform .16s ease;
        }
        .t2-feature-list li a:hover {
            background: rgba(var(--t2-primary-rgb), 0.16);
            border-color: rgba(var(--t2-primary-rgb), 0.4);
            transform: translateX(2px);
        }
        .t2-feature-list li strong {
            display: block;
            font-size: 14.5px;
            font-weight: 700;
            color: #FFFFFF;
            line-height: 1.3;
        }
        .t2-feature-list li small {
            display: block;
            font-size: 12px;
            color: var(--t2-text-3);
            margin-top: 2px;
            line-height: 1.4;
        }
        .t2-feat-right {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            flex: none;
        }
        .t2-pill-mini {
            padding: 3px 8px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.14);
            color: var(--t2-text-2);
        }
        .t2-feat-right svg { width: 16px; height: 16px; color: var(--t2-text-3); }

        /* ── Coming Soon / Paused by Service Control Center ─────────── */
        .t2-cs-band {
            margin-top: 26px;
            padding: 24px;
            border-radius: 24px;
            background: rgba(255, 255, 255, 0.035);
            border: 1px dashed rgba(255, 255, 255, 0.22);
            -webkit-backdrop-filter: blur(18px);
            backdrop-filter: blur(18px);
        }
        .t2-cs-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 12px;
            margin-top: 16px;
        }
        .t2-cs-item {
            display: block;
            padding: 15px 16px;
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.1);
            text-decoration: none;
        }
        .t2-cs-item:hover { border-color: rgba(255, 255, 255, 0.24); }
        .t2-cs-pill {
            display: inline-block;
            padding: 3px 9px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            background: rgba(245, 158, 11, 0.16);
            border: 1px solid rgba(245, 158, 11, 0.36);
            color: #FDE68A;
            margin-bottom: 8px;
        }
        .t2-cs-item h4 { margin: 0 0 4px; font-size: 15px; font-weight: 700; color: #fff; }
        .t2-cs-item p { margin: 0; font-size: 12.5px; color: var(--t2-text-3); }

        /* ── Security & FAQ Bento ───────────────────────────────────── */
        .t2-sec-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
            gap: 16px;
        }
        .t2-sec-card {
            padding: 22px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .t2-sec-card h3 { margin: 0; font-size: 18px; font-weight: 800; }
        .t2-sec-card p { margin: 0; font-size: 14px; color: var(--t2-text-2); }

        .t2-faq-list {
            display: grid;
            gap: 12px;
            max-width: 920px;
            margin: 0 auto;
        }
        .t2-faq-item { padding: 0; overflow: hidden; border-radius: 20px; }
        .t2-faq-btn {
            width: 100%;
            text-align: left;
            background: none;
            border: 0;
            padding: 20px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            cursor: pointer;
            font-size: 17px;
            font-weight: 700;
            color: #FFFFFF;
        }
        .t2-faq-btn svg { width: 20px; height: 20px; flex: none; color: var(--t2-primary-text); transition: transform .24s ease; }
        .t2-faq-item.open .t2-faq-btn svg { transform: rotate(45deg); }
        .t2-faq-body {
            display: none;
            padding: 0 24px 20px;
            font-size: 15px;
            color: var(--t2-text-2);
        }
        .t2-faq-item.open .t2-faq-body { display: block; }

        /* ── Finale CTA & Footer ────────────────────────────────────── */
        .t2-finale {
            padding: clamp(28px, 4.5vw, 52px);
            border-radius: 32px;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
        }
        .t2-finale h2 { margin: 0 0 10px; font-size: clamp(26px, 3.6vw, 40px); font-weight: 900; letter-spacing: -0.03em; }
        .t2-finale p { margin: 0; color: var(--t2-text-2); max-width: 52ch; }

        .t2-footer {
            position: relative;
            z-index: 1;
            margin-top: 40px;
            padding: 48px 0 36px;
            border-top: 1px solid rgba(255, 255, 255, 0.14);
            background: rgba(4, 7, 20, 0.55);
            -webkit-backdrop-filter: blur(20px);
            backdrop-filter: blur(20px);
        }
        .t2-ft-top {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: flex-start;
            gap: 24px;
            margin-bottom: 12px;
        }
        .t2-ft-links {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            align-items: center;
        }
        .t2-ft-links a {
            color: var(--t2-text-2);
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
        }
        .t2-ft-links a:hover { color: #FFFFFF; text-decoration: underline; }
        .t2-ft-bot {
            margin-top: 22px;
            padding-top: 18px;
            border-top: 1px solid rgba(255, 255, 255, 0.12);
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: 12px;
            font-size: 13px;
            color: var(--t2-text-3);
        }
        @media (prefers-reduced-motion: reduce) {
            .t2-orb { animation: none !important; }
            * { transition-duration: 0.01ms !important; }
        }
    </style>
</head>
<body>
<div class="t2-backdrop" aria-hidden="true">
    <div class="t2-orb t2-orb--1"></div>
    <div class="t2-orb t2-orb--2"></div>
    <div class="t2-orb t2-orb--3"></div>
    <div class="t2-grid-mesh"></div>
</div>

<?php echo bc_meridian_header_and_menu($ctx, 'personal', '/'); ?>

<main id="main">
    <!-- Hero Glassmorphism Showcase -->
    <section class="t2-hero">
        <div class="t2-wrap">
            <div class="t2-hero-grid">
                <div>
                    <span class="t2-eyebrow"><i></i><?php echo $site_e; ?> · Frosted Glass Personal Platform</span>
                    <h1>Modern digital finance &amp; everyday bills in one clear glass workspace.</h1>
                    <p class="t2-hero-lede"><?php echo bc_meridian_e($ctx['site_desc']); ?> Every personal service displayed on this page automatically reflects your live Service Control Center settings.</p>
                    <div class="t2-ctas">
                        <?php if ($t2_dash !== ''): ?>
                            <a class="btn primary" href="<?php echo bc_meridian_e($t2_dash); ?>">Open Dashboard</a>
                        <?php elseif ($ctx['registration_open']): ?>
                            <a class="btn primary" href="/web/Register.php">Create free account</a>
                            <a class="btn ghost" href="/web/Login.php">Log in</a>
                        <?php else: ?>
                            <a class="btn primary" href="/web/Login.php">Log in to account</a>
                        <?php endif; ?>
                        <a class="btn ghost" href="#personal-features">Explore Personal Features</a>
                        <?php if ($t2_app_badge !== ''): ?>
                            <?php echo $t2_app_badge; ?>
                        <?php else: ?>
                            <a class="btn ghost" href="/DownloadApp.php">Get the app</a>
                        <?php endif; ?>
                    </div>
                    <?php echo bc_meridian_support_bar($ctx); ?>
                </div>

                <div class="glass tint t2-prism-card">
                    <div class="t2-prism-top">
                        <div style="display:flex;align-items:center;gap:10px">
                            <?php if (!empty($ctx['logo_url'])): ?>
                                <img class="m-logo-img" src="<?php echo bc_meridian_e($ctx['logo_url']); ?>" alt="<?php echo $site_e; ?> logo">
                            <?php else: ?>
                                <span class="m-brand-mark" aria-hidden="true"><?php echo bc_meridian_e(strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $site_name) ?: 'F', 0, 1))); ?></span>
                            <?php endif; ?>
                            <div>
                                <strong style="display:block;font-size:15px"><?php echo $site_e; ?> Personal</strong>
                                <span style="font-size:12px;color:var(--t2-text-3)">Live Service Control: <?php echo (int)$active_feature_count; ?> active</span>
                            </div>
                        </div>
                        <span class="t2-prism-badge">Protected · 2FA</span>
                    </div>

                    <?php if ($header_image_url !== ''): ?>
                    <div class="t2-hero-media">
                        <img src="<?php echo bc_meridian_e($header_image_url); ?>" alt="<?php echo $site_e; ?> preview">
                    </div>
                    <?php endif; ?>

                    <div class="t2-balance-box">
                        <span class="t2-balance-lbl">Personal Wallet &amp; Multi-Currency Preview</span>
                        <div class="t2-balance-val" id="t2WalletPreview">₦250,000.00</div>
                        <div class="t2-fx-strip">
                            <span class="t2-fx-pill">NGN Wallet</span>
                            <?php if ($t2_enabled('currency_conversion')): ?>
                                <span class="t2-fx-pill">USD ≈ ₦<?php echo number_format(($ctx['rates']['USD'] ?? 0) > 0 ? $ctx['rates']['USD'] : 1500, 0); ?></span>
                                <span class="t2-fx-pill">GBP ≈ ₦<?php echo number_format(($ctx['rates']['GBP'] ?? 0) > 0 ? $ctx['rates']['GBP'] : 1900, 0); ?></span>
                                <span class="t2-fx-pill">EUR ≈ ₦<?php echo number_format(($ctx['rates']['EUR'] ?? 0) > 0 ? $ctx['rates']['EUR'] : 1650, 0); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="t2-mini-grid">
                        <?php if ($t2_enabled('airtime') || $t2_enabled('data')): ?>
                        <a class="t2-mini-tile" href="/services/airtime.php">
                            <span class="t2-mini-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2L4 14h7l-1 8 9-12h-7z"/></svg></span>
                            <div><b>Airtime &amp; Data</b><span>Instant delivery</span></div>
                        </a>
                        <?php endif; ?>
                        <?php if ($t2_enabled('electric') || $t2_enabled('cable')): ?>
                        <a class="t2-mini-tile" href="/BillsAndAirtime.php">
                            <span class="t2-mini-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="14" rx="2"/><path d="M8 21h8M12 18v3"/></svg></span>
                            <div><b>Bills &amp; Cable TV</b><span>Verified meters</span></div>
                        </a>
                        <?php endif; ?>
                        <?php if ($t2_enabled('bank_transfer') || $t2_enabled('intl_transfer')): ?>
                        <a class="t2-mini-tile" href="<?php echo $t2_enabled('intl_transfer') ? '/services/international-transfer.php' : '/services/bank-transfer.php'; ?>">
                            <span class="t2-mini-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 6l6 6-6 6"/></svg></span>
                            <div><b>Transfers</b><span>Local &amp; global</span></div>
                        </a>
                        <?php endif; ?>
                        <?php if ($t2_enabled('virtual_card')): ?>
                        <a class="t2-mini-tile" href="/services/virtual-cards.php">
                            <span class="t2-mini-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2.5" y="5.5" width="19" height="13" rx="2.5"/><path d="M2.5 10h19"/></svg></span>
                            <div><b>Virtual Dollar Card</b><span>Online checkout</span></div>
                        </a>
                        <?php else: ?>
                        <a class="t2-mini-tile" href="/web/Dashboard.php">
                            <span class="t2-mini-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="3"/><path d="M3 10h18"/></svg></span>
                            <div><b>Wallet &amp; Ledger</b><span>Receipts &amp; history</span></div>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="t2-metrics">
                <div class="glass t2-metric"><strong><?php echo (int)$active_feature_count; ?> Active</strong><span>Personal features live via Service Control</span></div>
                <div class="glass t2-metric"><strong>24/7 Auto</strong><span>Instant bill &amp; top-up fulfillment</span></div>
                <div class="glass t2-metric"><strong>PIN + 2FA</strong><span>Authenticator, email OTP &amp; KYC security</span></div>
                <div class="glass t2-metric"><strong>Real-Time</strong><span>Payment receipts &amp; order tracking</span></div>
            </div>
        </div>
    </section>

    <!-- Interactive Glass Preview Tools -->
    <?php if ($t2_enabled('airtime') || $t2_enabled('data') || $t2_enabled('currency_conversion')): ?>
    <section class="t2-sec" id="interactive-preview">
        <div class="t2-wrap">
            <div class="t2-sec-head">
                <div>
                    <span class="t2-eyebrow">Interactive Glass Preview</span>
                    <h2>Try top-ups &amp; FX quotes before signing in</h2>
                    <p class="sub">Interactive estimators mirror active Service Control permissions—switch networks or preview indicative currency conversions in real time.</p>
                </div>
            </div>

            <div class="t2-lab">
                <?php if ($t2_enabled('airtime') || $t2_enabled('data')): ?>
                <form class="glass t2-lab-card" id="t2TopupForm" novalidate>
                    <div class="t2-lab-head">
                        <h3>Instant Top-Up Preview</h3>
                        <span class="t2-tag">Airtime &amp; Data</span>
                    </div>
                    <div class="t2-chips" id="t2NetChips" role="group" aria-label="Mobile network">
                        <button type="button" class="t2-chip" aria-pressed="true" data-net="MTN">MTN</button>
                        <button type="button" class="t2-chip" aria-pressed="false" data-net="Airtel">Airtel</button>
                        <button type="button" class="t2-chip" aria-pressed="false" data-net="Glo">Glo</button>
                        <button type="button" class="t2-chip" aria-pressed="false" data-net="9mobile">9mobile</button>
                    </div>
                    <div>
                        <label for="t2Phone" style="display:block;font-size:13px;font-weight:600;color:var(--t2-text-2);margin-bottom:6px">Beneficiary phone number</label>
                        <input class="t2-input" id="t2Phone" inputmode="tel" autocomplete="off" value="0803 000 0000">
                    </div>
                    <div class="t2-chips" id="t2AmtChips" role="group" aria-label="Top-up amount">
                        <button type="button" class="t2-chip" aria-pressed="true" data-amt="1000">₦1,000</button>
                        <button type="button" class="t2-chip" aria-pressed="false" data-amt="2000">₦2,000</button>
                        <button type="button" class="t2-chip" aria-pressed="false" data-amt="5000">₦5,000</button>
                    </div>
                    <div class="t2-quote-box">
                        <span id="t2TopupStatus" style="font-size:13.5px;color:var(--t2-text-2)">Select a network and amount to preview your order.</span>
                    </div>
                    <button type="submit" class="btn primary" id="t2TopupBtn" style="width:100%">Preview ₦1,000 MTN Top-Up</button>
                </form>
                <?php endif; ?>

                <?php if ($t2_enabled('currency_conversion')): ?>
                <div class="glass t2-lab-card" id="t2FxCard">
                    <div class="t2-lab-head">
                        <h3>Indicative FX Converter</h3>
                        <span class="t2-tag">Multi-Currency</span>
                    </div>
                    <div>
                        <div style="display:flex;justify-content:space-between;font-size:13.5px;font-weight:600;color:var(--t2-text-2);margin-bottom:6px">
                            <label for="t2FxRange">Amount in NGN</label>
                            <span id="t2FxNgnLbl" style="color:#fff;font-family:var(--t2-mono)">₦50,000</span>
                        </div>
                        <input class="t2-range" id="t2FxRange" type="range" min="5000" max="500000" step="5000" value="50000">
                    </div>
                    <div class="t2-chips" id="t2FxChips" role="group" aria-label="Target currency">
                        <button type="button" class="t2-chip" aria-pressed="true" data-cur="USD">USD ($)</button>
                        <button type="button" class="t2-chip" aria-pressed="false" data-cur="GBP">GBP (£)</button>
                        <button type="button" class="t2-chip" aria-pressed="false" data-cur="EUR">EUR (€)</button>
                    </div>
                    <div class="t2-quote-box">
                        <div>
                            <span style="display:block;font-size:12px;color:var(--t2-text-3)">Indicative converted value</span>
                            <span id="t2FxRateNote" style="font-size:12.5px;color:var(--t2-text-2)">Sign in for confirmed live settlement quotes.</span>
                        </div>
                        <div class="t2-quote-num" id="t2FxOutput">$33.33</div>
                    </div>
                    <a class="btn ghost" href="/MultiCurrencyWallet.php" style="width:100%">Open Multi-Currency Wallet</a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- Personal Features (5 Pillars synced with Service Control Center) -->
    <section class="t2-sec" id="personal-features">
        <div class="t2-wrap">
            <div class="t2-sec-head">
                <div>
                    <span class="t2-eyebrow">Service Control Synced</span>
                    <h2>Personal Features</h2>
                    <p class="sub">Every service below listens to your Site Owner Service Control Center—active features link straight to their workflows, while paused or pending items are separated transparently under Coming Soon.</p>
                </div>
                <a class="btn ghost" href="/Services.php">View full service index</a>
            </div>

            <div class="t2-filter-bar" id="t2PillarTabs" role="tablist" aria-label="Filter personal feature categories">
                <button type="button" class="t2-chip is-on" data-filter="all" aria-pressed="true">All Pillars (<?php echo count($personal_pillars); ?>)</button>
                <?php foreach ($personal_pillars as $pl): ?>
                    <button type="button" class="t2-chip" data-filter="<?php echo bc_meridian_e($pl['id']); ?>" aria-pressed="false"><?php echo bc_meridian_e($pl['title']); ?></button>
                <?php endforeach; ?>
            </div>

            <div class="t2-pillars" id="t2PillarsGrid">
                <?php foreach ($personal_pillars as $pl): ?>
                <article class="glass t2-pillar" data-pillar="<?php echo bc_meridian_e($pl['id']); ?>">
                    <div class="t2-pillar-head">
                        <span class="t2-pillar-icon" aria-hidden="true"><?php echo $pl['icon']; ?></span>
                        <div>
                            <span class="t2-pillar-eyebrow"><?php echo bc_meridian_e($pl['eyebrow']); ?></span>
                            <h3><?php echo bc_meridian_e($pl['title']); ?></h3>
                            <p class="t2-pillar-sub"><?php echo bc_meridian_e($pl['subtitle']); ?></p>
                        </div>
                    </div>
                    <ul class="t2-feature-list">
                        <?php foreach ($pl['items'] as $it): ?>
                        <li>
                            <a href="<?php echo bc_meridian_e($it['href']); ?>">
                                <span>
                                    <strong><?php echo bc_meridian_e($it['title']); ?></strong>
                                    <small><?php echo bc_meridian_e($it['desc']); ?></small>
                                </span>
                                <span class="t2-feat-right">
                                    <?php if (!empty($it['badge'])): ?>
                                        <span class="t2-pill-mini"><?php echo bc_meridian_e($it['badge']); ?></span>
                                    <?php endif; ?>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                                </span>
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </article>
                <?php endforeach; ?>
            </div>

            <?php if (!empty($coming_soon_items)): ?>
            <div class="t2-cs-band" id="coming-soon">
                <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:12px">
                    <div>
                        <span class="t2-eyebrow" style="margin-bottom:8px">Coming Soon &amp; Service Control Status</span>
                        <h3 style="margin:0;font-size:21px;font-weight:800">Paused or pending integration (<?php echo (int)$paused_feature_count; ?>)</h3>
                    </div>
                    <span style="font-size:13px;color:var(--t2-text-3)">Automatically updates when toggled in Service Control Center</span>
                </div>
                <div class="t2-cs-grid">
                    <?php foreach ($coming_soon_items as $cs): ?>
                    <a class="t2-cs-item" href="<?php echo bc_meridian_e($cs['href']); ?>">
                        <span class="t2-cs-pill"><?php echo bc_meridian_e($cs['reason']); ?></span>
                        <h4><?php echo bc_meridian_e($cs['title']); ?></h4>
                        <p><?php echo bc_meridian_e($cs['category']); ?> · <?php echo bc_meridian_e($cs['desc']); ?></p>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Security & Support Glass Highlights -->
    <section class="t2-sec" id="security">
        <div class="t2-wrap">
            <div class="t2-sec-head">
                <div>
                    <span class="t2-eyebrow">Security &amp; Care</span>
                    <h2>Built-in protection at every step</h2>
                    <p class="sub">From KYC identity verification and transaction PINs to Google Authenticator, email OTP, recovery controls and dispute support.</p>
                </div>
                <div style="display:flex;gap:10px;flex-wrap:wrap">
                    <a class="btn ghost" href="/Security.php">Security details</a>
                    <a class="btn primary" href="/Contact.php">Support &amp; disputes</a>
                </div>
            </div>

            <div class="t2-sec-grid">
                <div class="glass t2-sec-card">
                    <span class="t2-pillar-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3l7 3v5c0 5-3.5 8.8-7 10-3.5-1.2-7-5-7-10V6z"/><path d="M9.5 12l1.8 1.8L15 10"/></svg></span>
                    <h3>Identity verification—KYC</h3>
                    <p>Complete guided KYC checks inside your dashboard to protect your identity and qualify for higher transfer and card limits.</p>
                </div>
                <div class="glass t2-sec-card">
                    <span class="t2-pillar-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="11" width="16" height="10" rx="2.5"/><path d="M8 11V7.5a4 4 0 0 1 8 0V11"/></svg></span>
                    <h3>Security PIN &amp; 2FA</h3>
                    <p>Lock transfers and purchases behind your personal Security PIN plus Google Authenticator or email OTP verification.</p>
                </div>
                <div class="glass t2-sec-card">
                    <span class="t2-pillar-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 3v6h-6"/></svg></span>
                    <h3>Account recovery</h3>
                    <p>Recover access safely with verified email checks, session management and configurable security preferences.</p>
                </div>
                <div class="glass t2-sec-card">
                    <span class="t2-pillar-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.4 8.4 0 0 1-12.2 7.5L3 20l1.1-5.6A8.4 8.4 0 1 1 21 11.5z"/></svg></span>
                    <h3>Support &amp; disputes</h3>
                    <p>Open support tickets, track transaction references and resolve payment disputes directly with our team.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- FAQ Glass Accordion -->
    <section class="t2-sec" id="faq">
        <div class="t2-wrap">
            <div class="t2-sec-head" style="justify-content:center;text-align:center">
                <div>
                    <span class="t2-eyebrow">Questions &amp; Answers</span>
                    <h2>Frequently Asked Questions</h2>
                </div>
            </div>
            <div class="t2-faq-list" id="t2Faq">
                <div class="glass t2-faq-item open">
                    <button type="button" class="t2-faq-btn" aria-expanded="true">
                        <span>How do Personal Features stay synced with the Service Control Center?</span>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 5v14M5 12h14"/></svg>
                    </button>
                    <div class="t2-faq-body">Every toggleable service (airtime, data, electricity, cable TV, betting, exam PINs, travel eSIM, tuition, virtual dollar cards, gift cards, transfers, virtual bank accounts, live FX, referrals, loyalty coins, AI assistant and password recovery) reads live from the Service Control Center. Enabled items appear in the active catalogue; disabled or pending ones automatically move to Coming Soon.</div>
                </div>
                <div class="glass t2-faq-item">
                    <button type="button" class="t2-faq-btn" aria-expanded="false">
                        <span>How do I fund my wallet and track receipts?</span>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 5v14M5 12h14"/></svg>
                    </button>
                    <div class="t2-faq-body">You can fund your wallet using enabled payment gateways, manual bank transfers, or dedicated virtual bank accounts (when active), and view downloadable receipts and live status updates in Transaction History.</div>
                </div>
                <div class="glass t2-faq-item">
                    <button type="button" class="t2-faq-btn" aria-expanded="false">
                        <span>What security options protect my account?</span>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 5v14M5 12h14"/></svg>
                    </button>
                    <div class="t2-faq-body">Your account supports KYC identity verification, a transaction Security PIN, Google Authenticator (TOTP), email OTP step-up verification, account recovery controls and direct customer support.</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Finale Glass CTA -->
    <section class="t2-sec" style="padding-top:20px">
        <div class="t2-wrap">
            <div class="glass tint t2-finale">
                <div>
                    <h2>Ready to start with <?php echo $site_e; ?>?</h2>
                    <p>Manage everyday bills, transfers, virtual cards, rewards, AI tools and verified security from one modern glassmorphism experience.</p>
                </div>
                <div class="t2-ctas">
                    <?php if ($t2_dash !== ''): ?>
                        <a class="btn primary" href="<?php echo bc_meridian_e($t2_dash); ?>">Open Dashboard</a>
                    <?php else: ?>
                        <?php if ($ctx['registration_open']): ?>
                            <a class="btn primary" href="/web/Register.php">Create free account</a>
                        <?php endif; ?>
                        <a class="btn ghost" href="/web/Login.php">Log in</a>
                    <?php endif; ?>
                    <?php if ($t2_app_badge !== ''): ?>
                        <?php echo $t2_app_badge; ?>
                    <?php else: ?>
                        <a class="btn ghost" href="/DownloadApp.php">Get the app</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>
</main>

<footer class="t2-footer">
    <div class="t2-wrap">
        <div class="t2-ft-top">
            <div>
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
                    <?php if (!empty($ctx['logo_url'])): ?>
                        <img class="m-logo-img" src="<?php echo bc_meridian_e($ctx['logo_url']); ?>" alt="<?php echo $site_e; ?> logo">
                    <?php else: ?>
                        <span class="m-brand-mark" aria-hidden="true"><?php echo bc_meridian_e(strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $site_name) ?: 'F', 0, 1))); ?></span>
                    <?php endif; ?>
                    <strong style="font-size:18px"><?php echo $site_e; ?></strong>
                </div>
                <p style="margin:0;color:var(--t2-text-3);font-size:14px;max-width:44ch"><?php echo bc_meridian_e($ctx['site_desc']); ?></p>
                <?php echo bc_meridian_support_bar($ctx); ?>
            </div>
            <div class="t2-ft-links">
                <a href="/">Home</a>
                <a href="/Services.php">Services</a>
                <a href="/Business.php">Business</a>
                <a href="/ReferAndEarn.php">Refer &amp; Earn</a>
                <a href="/Security.php">Security</a>
                <a href="/Contact.php">Support</a>
                <a href="/Privacy.php">Privacy</a>
                <a href="/Terms.php">Terms</a>
            </div>
        </div>

        <?php bc_public_directory_html(); ?>

        <div class="t2-ft-bot">
            <span>&copy; <?php echo date('Y'); ?> <?php echo $site_e; ?>.<?php if (!empty($ctx['address'])): ?> <?php echo bc_meridian_e($ctx['address']); ?>.<?php endif; ?></span>
            <span>All services reflect live Site Owner Service Control Center status.</span>
        </div>
    </div>
</footer>

<div class="sheet" id="getSheet" hidden=""><div class="glass" role="dialog" aria-modal="true" aria-labelledby="getTitle" style="max-width:420px;text-align:center" id="getBody"></div></div>
<div class="toast glass" id="toast" role="status" aria-live="polite"></div>

<script>window.MERIDIAN_CONFIG = <?php echo json_encode($js_config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP); ?>;</script>
<script src="/asset/meridian-1/meridian-1.js"></script>
<script>
(function () {
    // 1. Personal Feature pillar filter tabs
    var tabs = document.getElementById('t2PillarTabs');
    var grid = document.getElementById('t2PillarsGrid');
    if (tabs && grid) {
        tabs.addEventListener('click', function (e) {
            var btn = e.target.closest('button[data-filter]');
            if (!btn) return;
            var filter = btn.getAttribute('data-filter');
            tabs.querySelectorAll('button[data-filter]').forEach(function (b) {
                var active = (b === btn);
                b.classList.toggle('is-on', active);
                b.setAttribute('aria-pressed', String(active));
            });
            grid.querySelectorAll('article[data-pillar]').forEach(function (card) {
                card.hidden = (filter !== 'all' && card.getAttribute('data-pillar') !== filter);
            });
        });
    }

    // 2. Interactive Top-Up Preview
    var topupForm = document.getElementById('t2TopupForm');
    if (topupForm) {
        var netChips = document.getElementById('t2NetChips');
        var amtChips = document.getElementById('t2AmtChips');
        var phoneEl = document.getElementById('t2Phone');
        var statusEl = document.getElementById('t2TopupStatus');
        var btnEl = document.getElementById('t2TopupBtn');
        var walletEl = document.getElementById('t2WalletPreview');
        var net = 'MTN';
        var amt = 1000;
        var bal = 250000;
        var syncBtn = function () {
            btnEl.textContent = 'Preview ₦' + amt.toLocaleString('en-NG') + ' ' + net + ' Top-Up';
        };
        if (netChips) {
            netChips.addEventListener('click', function (e) {
                var b = e.target.closest('button[data-net]');
                if (!b) return;
                net = b.getAttribute('data-net');
                netChips.querySelectorAll('button').forEach(function (c) { c.setAttribute('aria-pressed', String(c === b)); });
                syncBtn();
            });
        }
        if (amtChips) {
            amtChips.addEventListener('click', function (e) {
                var b = e.target.closest('button[data-amt]');
                if (!b) return;
                amt = Number(b.getAttribute('data-amt')) || 1000;
                amtChips.querySelectorAll('button').forEach(function (c) { c.setAttribute('aria-pressed', String(c === b)); });
                syncBtn();
            });
        }
        topupForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var digits = (phoneEl.value || '').replace(/\D/g, '');
            if (!/^0[789]\d{9}$/.test(digits) && !/^234[789]\d{9}$/.test(digits)) {
                statusEl.textContent = 'Enter a valid 11-digit Nigerian mobile number to preview.';
                return;
            }
            bal = Math.max(0, bal - amt);
            if (walletEl) walletEl.textContent = '₦' + bal.toLocaleString('en-NG', { minimumFractionDigits: 2 });
            statusEl.textContent = 'Preview ready: ₦' + amt.toLocaleString('en-NG') + ' ' + net + ' top-up for ' + phoneEl.value.trim() + '. Sign in to pay live.';
        });
    }

    // 3. Indicative FX Converter
    var fxRange = document.getElementById('t2FxRange');
    var fxChips = document.getElementById('t2FxChips');
    var fxNgnLbl = document.getElementById('t2FxNgnLbl');
    var fxOut = document.getElementById('t2FxOutput');
    var fxNote = document.getElementById('t2FxRateNote');
    if (fxRange && fxChips && fxOut) {
        var cfgRates = (window.MERIDIAN_CONFIG && window.MERIDIAN_CONFIG.rates) || {};
        var rates = {
            USD: { sym: '$', rate: Number(cfgRates.USD) > 0 ? Number(cfgRates.USD) : 1500 },
            GBP: { sym: '£', rate: Number(cfgRates.GBP) > 0 ? Number(cfgRates.GBP) : 1900 },
            EUR: { sym: '€', rate: Number(cfgRates.EUR) > 0 ? Number(cfgRates.EUR) : 1650 }
        };
        var cur = 'USD';
        var syncFx = function () {
            var ngn = Number(fxRange.value) || 50000;
            var info = rates[cur];
            var conv = ngn / info.rate;
            if (fxNgnLbl) fxNgnLbl.textContent = '₦' + ngn.toLocaleString('en-NG');
            fxOut.textContent = info.sym + conv.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            if (fxNote) fxNote.textContent = (cfgRates[cur] > 0 ? 'Live rate: ' : 'Indicative rate: ') + '₦' + info.rate.toLocaleString('en-NG') + ' = ' + info.sym + '1.00';
        };
        fxRange.addEventListener('input', syncFx);
        fxChips.addEventListener('click', function (e) {
            var b = e.target.closest('button[data-cur]');
            if (!b || !rates[b.getAttribute('data-cur')]) return;
            cur = b.getAttribute('data-cur');
            fxChips.querySelectorAll('button').forEach(function (c) { c.setAttribute('aria-pressed', String(c === b)); });
            syncFx();
        });
        syncFx();
    }

    // 4. Glass FAQ Accordion
    var faq = document.getElementById('t2Faq');
    if (faq) {
        faq.addEventListener('click', function (e) {
            var btn = e.target.closest('.t2-faq-btn');
            if (!btn) return;
            var item = btn.closest('.t2-faq-item');
            var willOpen = !item.classList.contains('open');
            faq.querySelectorAll('.t2-faq-item').forEach(function (el) {
                el.classList.remove('open');
                var b = el.querySelector('.t2-faq-btn');
                if (b) b.setAttribute('aria-expanded', 'false');
            });
            if (willOpen) {
                item.classList.add('open');
                btn.setAttribute('aria-expanded', 'true');
            }
        });
    }
})();
</script>
<?php
if (function_exists('seo_render_custom_footer')) {
    seo_render_custom_footer($site_details);
}
?>
</body>
</html>

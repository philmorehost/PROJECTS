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
    $stmt_site = mysqli_prepare($connection_server, "SELECT sd.*, vst.header_image, vst.primary_color FROM sas_site_details sd LEFT JOIN sas_vendor_style_templates vst ON vst.vendor_id = sd.vendor_id WHERE sd.vendor_id = ? ORDER BY vst.id DESC LIMIT 1");
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

$t3_primary = bc_theme_hex($ctx['primary_color']) ?: '#287BFF';
$t3_palette = bc_theme_palette($t3_primary);
$t3_light_mode = $t3_palette['modes']['light'];
$t3_dark_mode = $t3_palette['modes']['dark'];
$t3_rgb = '40, 123, 255';
if (sscanf($t3_primary, '#%02x%02x%02x', $r3, $g3, $b3) === 3) {
    $t3_rgb = "$r3, $g3, $b3";
}
// Harmonious analogous shades derived strictly from the vendor's primary color (no clashing rainbow hues)
$t3_accent_2 = bc_meridian_mix_hex($t3_primary, '#0F172A', 0.72);
$t3_accent_soft = bc_meridian_mix_hex($t3_primary, '#FFFFFF', 0.35);
$t3_light_ink = $t3_light_mode['primary_text'];
$t3_dark_ink = $t3_dark_mode['primary_text'];
$t3_dash = function_exists('bc_public_dashboard_url') ? bc_public_dashboard_url() : '';
$t3_app_badge = bc_public_app_badge_html($ctx['app_url']);

// Service Control Center helper
$t3_enabled = function ($key) use ($vid) {
    if ($key === null || $key === '') return true;
    return bc_meridian_service_enabled($key, $vid);
};

// Build Personal Features synced to Service Control Center
$personal_pillars = array();
$coming_soon_items = array();

$add_feature = function (&$pillar, $title, $desc, $href, $service_key = null, $badge = '', $pending_note = '') use ($t3_enabled, &$coming_soon_items) {
    if ($service_key === null || $t3_enabled($service_key)) {
        $pillar['items'][] = array(
            'title' => $title,
            'desc' => $desc,
            'href' => $href,
            'badge' => $badge,
            'key' => $service_key ?: 'core',
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

// 1. Accounts & Payments
$pillar_accounts = array(
    'id' => 'accounts',
    'eyebrow' => '01 · Accounts & Payments',
    'title' => 'Accounts & Payments',
    'subtitle' => 'Central personal wallet, virtual bank accounts, local & global transfers, receipts and FX conversion.',
    'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="5" width="19" height="14" rx="3"/><path d="M2.5 10h19"/><circle cx="17" cy="14.5" r="1.2"/></svg>',
    'items' => array(),
    '_cat_title' => 'Accounts & Payments',
);
$add_feature($pillar_accounts, 'Personal wallet and account dashboard', 'Monitor live balances, recent activity and quick-pay controls in one command view.', '/web/Dashboard.php', null, 'Dashboard');
$add_feature($pillar_accounts, 'Wallet funding and payment receipts', 'Add funds via card or transfer and open downloadable receipts for every top-up.', '/web/Fund.php', null, 'Receipts');

$local_bank_on = $t3_enabled('bank_transfer');
$local_wallet_on = $t3_enabled('share_fund');
if ($local_bank_on || $local_wallet_on) {
    $local_href = $local_bank_on ? '/services/bank-transfer.php' : '/services/share-fund.php';
    $local_badge = ($local_bank_on && $local_wallet_on) ? 'Bank + Wallet' : ($local_bank_on ? 'Bank' : 'Wallet');
    $pillar_accounts['items'][] = array(
        'title' => 'Local transfers and wallet-to-wallet transfers',
        'desc' => 'Instant payouts to local bank accounts and zero-friction transfers between users.',
        'href' => $local_href,
        'badge' => $local_badge,
        'key' => 'local_transfer',
    );
} else {
    $coming_soon_items[] = array(
        'title' => 'Local transfers and wallet-to-wallet transfers',
        'category' => 'Accounts & Payments',
        'desc' => 'Send funds to local banks or peer wallets directly from your balance.',
        'href' => '/services/bank-transfer.php',
        'reason' => 'Paused in Service Control Center',
    );
}
$add_feature($pillar_accounts, 'International transfer requests', 'Submit cross-border remittance requests with clear destination fee breakdowns.', '/services/international-transfer.php', 'intl_transfer', 'Global');
$add_feature($pillar_accounts, 'Virtual bank accounts', 'Dedicated virtual account numbers to receive deposits straight into your wallet.', '/web/VirtualBanks.php', 'virtual_bank_display', 'Auto-fund');
$add_feature($pillar_accounts, 'Transaction history and payment tracking', 'Filter completed, pending and reversed orders with instant reference lookup.', '/web/Transactions.php', null, 'Ledger');
$add_feature($pillar_accounts, 'Live currency conversion and indicative FX rates', 'Check NGN, USD, GBP and EUR indicative rates and convert balances seamlessly.', '/MultiCurrencyWallet.php', 'currency_conversion', 'FX Live');
$personal_pillars[] = $pillar_accounts;

// 2. Everyday Services
$pillar_everyday = array(
    'id' => 'everyday',
    'eyebrow' => '02 · Everyday Services',
    'title' => 'Everyday Services',
    'subtitle' => 'High-speed telecom, electricity tokens, TV renewals, exams, eSIMs and tuition requests.',
    'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L4.5 13.5H12L11 22l8.5-11.5H12L13 2z"/></svg>',
    'items' => array(),
    '_cat_title' => 'Everyday Services',
);
$add_feature($pillar_everyday, 'Airtime purchase', 'Instant airtime recharge for MTN, Airtel, Glo and 9mobile lines.', '/services/airtime.php', 'airtime', 'Instant');
$add_feature($pillar_everyday, 'Mobile data purchase', 'SME, corporate gifting and direct mobile data bundles delivered in seconds.', '/services/data.php', 'data', 'Bundles');
$add_feature($pillar_everyday, 'Electricity bill payments', 'Prepaid meter tokens and postpaid settlement across major electricity DisCos.', '/services/electricity.php', 'electric', 'Token');
$add_feature($pillar_everyday, 'Cable TV subscriptions', 'Instant DStv, GOtv, StarTimes and Showmax bouquet renewals.', '/services/cable-tv.php', 'cable', 'TV');
$add_feature($pillar_everyday, 'Betting account funding', 'Fast wallet funding for supported sports and gaming platforms.', '/services/betting.php', 'betting', 'Top-up');
$add_feature($pillar_everyday, 'Examination PINs', 'WAEC, NECO and JAMB result-checker and registration PINs on demand.', '/services/exam-pins.php', 'exam', 'PINs');
$add_feature($pillar_everyday, 'Travel eSIM services', 'Activate international travel data eSIM plans without swapping physical SIMs.', '/services/esim.php', 'esim', 'eSIM');
$add_feature($pillar_everyday, 'Tuition payment requests', 'Submit verified local and international education fee payment requests.', '/Tuition.php', 'tuition', 'Education');
$personal_pillars[] = $pillar_everyday;

// 3. Cards & Digital Assets
$pillar_cards = array(
    'id' => 'cards-assets',
    'eyebrow' => '03 · Cards & Digital Assets',
    'title' => 'Cards & Digital Assets',
    'subtitle' => 'Virtual USD cards for global checkout, digital gift cards and digital asset access.',
    'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2.5"/><path d="M2 10h20M7 15h4"/></svg>',
    'items' => array(),
    '_cat_title' => 'Cards & Digital Assets',
);
$add_feature($pillar_cards, 'Virtual dollar cards', 'Create, fund and freeze USD virtual cards for subscriptions and global merchants.', '/services/virtual-cards.php', 'virtual_card', 'USD Card');
$add_feature($pillar_cards, 'Gift card services', 'Purchase and redeem popular global and digital brand gift cards.', '/services/gift-cards.php', 'gift_card', 'Cards');
$add_feature($pillar_cards, 'Crypto Buy/Sell and stablecoin services', 'Buy, sell and manage stablecoins and supported digital assets.', '/services/crypto.php', 'crypto_hub', 'Assets', 'Pending replacement integration');
$personal_pillars[] = $pillar_cards;

// 4. Rewards & Tools
$pillar_rewards = array(
    'id' => 'rewards-tools',
    'eyebrow' => '04 · Rewards & Tools',
    'title' => 'Rewards & Tools',
    'subtitle' => 'Earn commissions and loyalty coins, calculate fees ahead of time and plan with AI.',
    'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l2.6 5.3 5.9.9-4.2 4.1 1 5.8L12 16.4l-5.3 2.7 1-5.8-4.2-4.1 5.9-.9L12 3z"/></svg>',
    'items' => array(),
    '_cat_title' => 'Rewards & Tools',
);
$add_feature($pillar_rewards, 'Referral programme', 'Share your personal invite link and earn ongoing rewards when friends transact.', '/ReferAndEarn.php', 'referral', 'Invite');
$add_feature($pillar_rewards, 'Loyalty points and rewards', 'Accumulate coins on eligible bills and convert loyalty points to wallet value.', '/services/CoinConversion.php', 'vtu_coins', 'Points');
$add_feature($pillar_rewards, 'Transaction calculator', 'Estimate service charges, margins and net wallet amounts before paying.', '/web/TransactionCalculator.php', null, 'Calc');
$add_feature($pillar_rewards, 'AI assistant and budgeting tools', 'Smart bill planning, spend insights and guided assistant workflows.', '/web/AI-Assistant.php', 'ai_suite', 'AI');
$personal_pillars[] = $pillar_rewards;

// 5. Security & Support
$pillar_security = array(
    'id' => 'security-support',
    'eyebrow' => '05 · Security & Support',
    'title' => 'Security & Support',
    'subtitle' => 'Multi-layered identity verification, transaction PINs, 2FA/OTP and dedicated care.',
    'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2.5l8 3.5v5.5c0 5.1-3.4 9.5-8 10.9-4.6-1.4-8-5.8-8-10.9V6l8-3.5z"/><path d="M9.5 12l1.8 1.8 3.7-3.8"/></svg>',
    'items' => array(),
    '_cat_title' => 'Security & Support',
);
$add_feature($pillar_security, 'Identity verification—KYC', 'Complete KYC tiers to protect your profile and unlock expanded wallet limits.', '/web/KYCVerification.php', null, 'KYC');
$add_feature($pillar_security, 'Security PIN', 'Enforce a personal PIN before releasing transfers, cards or utility orders.', '/web/SecurityPIN.php', null, 'PIN');
$add_feature($pillar_security, 'Google Authenticator and email OTP options', 'Protect sign-ins with TOTP authenticator apps or verified email OTP codes.', '/web/SecuritySetup.php', null, '2FA');
if ($t3_enabled('password_reset')) {
    $pillar_security['items'][] = array(
        'title' => 'Account recovery and security settings',
        'desc' => 'Self-service password recovery, active session controls and security preferences.',
        'href' => '/web/PasswordRecovery.php',
        'badge' => 'Recovery',
        'key' => 'password_reset',
    );
} else {
    $pillar_security['items'][] = array(
        'title' => 'Security settings and session controls',
        'desc' => 'Review account security preferences and active sessions from your dashboard.',
        'href' => '/web/AccountSettings.php',
        'badge' => 'Console',
        'key' => 'account_settings',
    );
    $coming_soon_items[] = array(
        'title' => 'Self-service password reset recovery',
        'category' => 'Security & Support',
        'desc' => 'Automated self-service account password recovery.',
        'href' => '/web/PasswordRecovery.php',
        'reason' => 'Paused in Service Control Center',
    );
}
$add_feature($pillar_security, 'Customer support and dispute assistance', 'Open support tickets, trace transaction receipts and resolve disputes quickly.', '/Contact.php', null, 'Support');
$personal_pillars[] = $pillar_security;

$active_feature_count = 0;
foreach ($personal_pillars as $p) {
    $active_feature_count += count($p['items']);
}
$paused_feature_count = count($coming_soon_items);

// Quick dock shortcuts from active personal items
$quick_dock = array();
if ($t3_enabled('airtime')) $quick_dock[] = array('label' => 'Airtime', 'href' => '/services/airtime.php', 'tag' => 'Instant');
if ($t3_enabled('data')) $quick_dock[] = array('label' => 'Mobile Data', 'href' => '/services/data.php', 'tag' => 'Bundles');
if ($t3_enabled('electric')) $quick_dock[] = array('label' => 'Electricity', 'href' => '/services/electricity.php', 'tag' => 'Meter');
if ($t3_enabled('bank_transfer')) $quick_dock[] = array('label' => 'Transfers', 'href' => '/services/bank-transfer.php', 'tag' => 'Payouts');
if ($t3_enabled('virtual_card')) $quick_dock[] = array('label' => 'USD Cards', 'href' => '/services/virtual-cards.php', 'tag' => 'Virtual');
if ($t3_enabled('currency_conversion')) $quick_dock[] = array('label' => 'FX Rates', 'href' => '/MultiCurrencyWallet.php', 'tag' => 'Multi-CCY');

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
<html lang="en" data-theme="light" data-world="personal" style="<?php echo bc_meridian_e($personal_vars); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php
    if (function_exists('seo_render_head_tags')) {
        seo_render_head_tags($site_details, $site_name, $header_image_url);
    } else {
        echo '<title>' . $site_e . ' — Modern Frosted Glass Financial &amp; VTU Hub</title>';
    }
    ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --t3-primary: <?php echo bc_meridian_e($t3_primary); ?>;
            --primary: var(--t3-primary);
            --t3-rgb: <?php echo bc_meridian_e($t3_rgb); ?>;
            --t3-accent-2: <?php echo bc_meridian_e($t3_accent_2); ?>;
            --t3-accent-soft: <?php echo bc_meridian_e($t3_accent_soft); ?>;
            --t3-emerald: #059669;

            /* Luminous Frost Light (default for Template 3) */
            --t3-bg: #F4F6FA;
            --t3-bg-2: #ECEFF6;
            --t3-ink: #0F172A;
            --t3-ink-2: #334155;
            --t3-ink-3: #64748B;
            --t3-glass: rgba(255, 255, 255, 0.76);
            --t3-glass-strong: rgba(255, 255, 255, 0.92);
            --t3-glass-soft: rgba(255, 255, 255, 0.56);
            --t3-border: rgba(15, 23, 42, 0.09);
            --t3-border-hi: rgba(255, 255, 255, 0.94);
            --t3-shadow: 0 22px 58px rgba(15, 23, 42, 0.08), inset 0 1px 0 rgba(255, 255, 255, 0.92);
            --t3-shadow-sm: 0 8px 24px rgba(15, 23, 42, 0.06), inset 0 1px 0 rgba(255, 255, 255, 0.88);
            --t3-nav-bg: rgba(255, 255, 255, 0.80);
            --t3-input-bg: rgba(255, 255, 255, 0.84);
            --t3-pill-bg: rgba(var(--t3-rgb), 0.10);
            --t3-pill-ink: <?php echo bc_meridian_e($t3_light_ink); ?>;
            --t3-primary-ink: <?php echo bc_meridian_e($t3_light_ink); ?>;
            --t3-on-primary: <?php echo bc_meridian_e($t3_light_mode['on_primary']); ?>;

            --accent: var(--t3-primary);
            --on-accent: var(--t3-on-primary);
            --radius: 22px;
            --radius-sm: 14px;
            --ease: cubic-bezier(0.22, 1, 0.36, 1);
        }

        html[data-theme="dark"] {
            --t3-bg: #080D1A;
            --t3-bg-2: #0D1426;
            --t3-ink: #F1F5F9;
            --t3-ink-2: #CBD5E1;
            --t3-ink-3: #94A3B8;
            --t3-glass: rgba(15, 23, 42, 0.68);
            --t3-glass-strong: rgba(17, 26, 48, 0.88);
            --t3-glass-soft: rgba(255, 255, 255, 0.055);
            --t3-border: rgba(255, 255, 255, 0.14);
            --t3-border-hi: rgba(255, 255, 255, 0.24);
            --t3-shadow: 0 24px 64px rgba(2, 6, 23, 0.56), inset 0 1px 0 rgba(255, 255, 255, 0.14);
            --t3-shadow-sm: 0 10px 28px rgba(2, 6, 23, 0.42), inset 0 1px 0 rgba(255, 255, 255, 0.10);
            --t3-nav-bg: rgba(10, 16, 32, 0.80);
            --t3-input-bg: rgba(9, 15, 30, 0.64);
            --t3-pill-bg: rgba(var(--t3-rgb), 0.20);
            --t3-pill-ink: <?php echo bc_meridian_e($t3_dark_ink); ?>;
            --t3-primary-ink: <?php echo bc_meridian_e($t3_dark_ink); ?>;
            --t3-on-primary: <?php echo bc_meridian_e($t3_dark_mode['on_primary']); ?>;
        }

        *, *::before, *::after { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            margin: 0;
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--t3-bg);
            color: var(--t3-ink);
            line-height: 1.58;
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
            transition: background .3s ease, color .3s ease;
        }
        a { color: inherit; text-decoration: none; }
        button, input, select { font: inherit; color: inherit; }
        img { max-width: 100%; display: block; }

        /* ── Harmonious Frost Mesh Canvas ────────────────────────────── */
        .t3-mesh {
            position: fixed;
            inset: 0;
            z-index: -2;
            pointer-events: none;
            overflow: hidden;
            background:
                radial-gradient(1100px 620px at 8% -4%, rgba(var(--t3-rgb), 0.14), transparent 62%),
                radial-gradient(920px 580px at 94% 14%, rgba(148, 163, 184, 0.16), transparent 60%),
                radial-gradient(880px 540px at 62% 92%, rgba(var(--t3-rgb), 0.09), transparent 62%),
                linear-gradient(180deg, var(--t3-bg) 0%, var(--t3-bg-2) 100%);
        }
        .t3-orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(88px);
            opacity: .42;
            animation: t3Float 22s ease-in-out infinite alternate;
        }
        .t3-orb.a { width: 440px; height: 440px; top: -130px; left: -90px; background: rgba(var(--t3-rgb), 0.24); }
        .t3-orb.b { width: 390px; height: 390px; top: 20%; right: -110px; background: rgba(148, 163, 184, 0.24); animation-delay: -6s; }
        .t3-orb.c { width: 360px; height: 360px; bottom: -110px; left: 32%; background: rgba(var(--t3-rgb), 0.16); animation-delay: -11s; }
        @keyframes t3Float {
            0% { transform: translate3d(0, 0, 0) scale(1); }
            100% { transform: translate3d(34px, -24px, 0) scale(1.07); }
        }
        .t3-dots {
            position: fixed;
            inset: 0;
            z-index: -1;
            pointer-events: none;
            background-image: radial-gradient(rgba(15, 23, 42, 0.065) 1px, transparent 1px);
            background-size: 26px 26px;
            mask-image: linear-gradient(180deg, rgba(0, 0, 0, 0.42), transparent 85%);
        }
        html[data-theme="dark"] .t3-dots {
            background-image: radial-gradient(rgba(255, 255, 255, 0.075) 1px, transparent 1px);
        }

        .t3-wrap {
            width: 100%;
            max-width: 1220px;
            margin: 0 auto;
            padding: 0 22px;
        }

        /* ── Frosted Glass Primitives ────────────────────────────────── */
        .t3-glass {
            background: var(--t3-glass);
            backdrop-filter: blur(24px) saturate(185%);
            -webkit-backdrop-filter: blur(24px) saturate(185%);
            border: 1px solid var(--t3-border);
            box-shadow: var(--t3-shadow);
            border-radius: var(--radius);
            position: relative;
        }
        .t3-glass::before {
            content: "";
            position: absolute;
            inset: 0;
            border-radius: inherit;
            padding: 1px;
            background: linear-gradient(140deg, var(--t3-border-hi), transparent 45%, rgba(var(--t3-rgb), 0.18));
            -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            -webkit-mask-composite: xor;
            mask-composite: exclude;
            pointer-events: none;
        }
        .t3-glass-sm {
            background: var(--t3-glass-soft);
            backdrop-filter: blur(16px) saturate(165%);
            -webkit-backdrop-filter: blur(16px) saturate(165%);
            border: 1px solid var(--t3-border);
            box-shadow: var(--t3-shadow-sm);
            border-radius: var(--radius-sm);
        }

        /* ── Meridian-1 Compatible Floating Glass Header ─────────────── */
        #nav {
            position: fixed;
            top: 14px;
            left: 50%;
            transform: translateX(-50%);
            width: min(1220px, calc(100% - 28px));
            z-index: 60;
            border-radius: 20px;
            background: var(--t3-nav-bg);
            backdrop-filter: blur(22px) saturate(190%);
            -webkit-backdrop-filter: blur(22px) saturate(190%);
            border: 1px solid var(--t3-border);
            box-shadow: var(--t3-shadow-sm);
            transition: background .25s ease, box-shadow .25s ease;
        }
        #nav.stuck, #nav.compact {
            background: var(--t3-glass-strong);
            box-shadow: var(--t3-shadow);
        }
        .nav-in {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 10px 16px;
        }
        .logo {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-family: 'Space Grotesk', sans-serif;
            font-weight: 700;
            font-size: 16px;
            letter-spacing: -0.025em;
            color: var(--t3-ink);
            white-space: nowrap;
            flex: 0 0 auto;
        }
        .m-brand-mark {
            width: 36px;
            height: 36px;
            border-radius: 11px;
            display: inline-grid;
            place-items: center;
            background: linear-gradient(135deg, var(--t3-primary), var(--t3-accent-2));
            color: var(--t3-on-primary);
            font-family: 'Space Grotesk', sans-serif;
            font-weight: 800;
            font-size: 16px;
            line-height: 1;
            border: 1px solid rgba(255, 255, 255, 0.34);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.42), 0 8px 18px rgba(var(--t3-rgb), 0.28);
            flex: 0 0 auto;
        }
        .m-logo-img {
            width: 36px;
            height: 36px;
            border-radius: 11px;
            object-fit: cover;
            border: 1px solid var(--t3-border);
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.10);
            flex: 0 0 auto;
        }
        .ff-word {
            font-weight: 700;
            color: var(--t3-ink);
        }

        .pswitch {
            position: relative;
            display: inline-grid;
            grid-template-columns: repeat(2, 1fr);
            padding: 3px;
            border-radius: 999px;
            background: var(--t3-glass-soft);
            border: 1px solid var(--t3-border);
            min-width: 184px;
            flex: 0 0 auto;
        }
        .pthumb {
            position: absolute;
            top: 3px;
            bottom: 3px;
            left: 3px;
            border-radius: 999px;
            background: linear-gradient(135deg, var(--t3-primary), var(--t3-accent-2));
            box-shadow: 0 4px 14px rgba(var(--t3-rgb), 0.30);
            transition: transform .28s var(--ease);
            z-index: 1;
        }
        :root[data-world="business"] .pthumb { transform: translateX(100%); }
        .pswitch button {
            position: relative;
            z-index: 2;
            border: 0;
            background: transparent;
            padding: 7px 14px;
            border-radius: 999px;
            font-size: 12.5px;
            font-weight: 700;
            cursor: pointer;
            color: var(--t3-ink-2);
            transition: color .2s ease;
            white-space: nowrap;
        }
        .pswitch button[aria-checked="true"] {
            color: var(--t3-on-primary);
        }

        .links {
            display: flex;
            align-items: center;
            gap: 2px;
            list-style: none;
            margin: 0;
            padding: 0;
        }
        .links a {
            padding: 8px 12px;
            border-radius: 10px;
            font-size: 13.5px;
            font-weight: 600;
            color: var(--t3-ink-2);
            transition: background .18s ease, color .18s ease;
            white-space: nowrap;
        }
        .links a:hover,
        .links a.is-active,
        .links a[aria-current="page"] {
            color: var(--t3-primary-ink);
            background: var(--t3-pill-bg);
        }

        .nav-r { display: flex; align-items: center; gap: 8px; flex: 0 0 auto; }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border-radius: 12px;
            font-weight: 700;
            font-size: 13.5px;
            padding: 10px 16px;
            cursor: pointer;
            border: 1px solid transparent;
            transition: transform .18s var(--ease), box-shadow .18s ease, background .18s ease;
            white-space: nowrap;
        }
        .btn:hover { transform: translateY(-1px); }
        .btn.sm { padding: 8px 13px; font-size: 12.5px; border-radius: 10px; }
        .btn.lg { padding: 13px 22px; font-size: 15px; border-radius: 14px; }
        .btn.primary {
            background: linear-gradient(135deg, var(--t3-primary), var(--t3-accent-2));
            color: var(--t3-on-primary);
            box-shadow: 0 10px 24px rgba(var(--t3-rgb), 0.26);
        }
        .btn.ghost {
            background: var(--t3-glass-soft);
            border-color: var(--t3-border);
            color: var(--t3-ink);
        }
        .btn.ghost:hover { background: var(--t3-glass-strong); }
        .icon-btn {
            width: 38px;
            height: 38px;
            border-radius: 11px;
            border: 1px solid var(--t3-border);
            background: var(--t3-glass-soft);
            color: var(--t3-ink);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            flex: 0 0 auto;
        }
        .icon-btn svg, .btn svg { width: 17px; height: 17px; flex: 0 0 auto; }
        .menu-btn { display: none; }

        @media (max-width: 1060px) {
            .links { display: none; }
            .menu-btn { display: inline-flex; }
        }
        @media (max-width: 640px) {
            .nav-r > .ghost { display: none; }
        }
        @media (max-width: 580px) {
            .ff-word { display: none; }
            .pswitch { min-width: 152px; }
            .pswitch button { padding: 6px 10px; font-size: 12px; }
        }

        /* ── Meridian-1 Sheet & Get-App Modal ────────────────────────── */
        #sheet[hidden], #getSheet[hidden] { display: none !important; }
        .sheet {
            position: fixed;
            inset: 0;
            z-index: 90;
            background: rgba(15, 23, 42, 0.52);
            backdrop-filter: blur(12px);
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 86px 16px 24px;
            overflow: auto;
        }
        .sheet > .glass {
            width: min(520px, 100%);
            max-height: 85vh;
            overflow: auto;
            background: var(--t3-glass-strong);
            backdrop-filter: blur(28px) saturate(190%);
            border: 1px solid var(--t3-border);
            border-radius: 24px;
            padding: 18px;
            box-shadow: var(--t3-shadow);
            color: var(--t3-ink);
        }
        .sheet .mlinks {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
            margin: 14px 0;
        }
        .sheet .mlinks a {
            padding: 11px 12px;
            border-radius: 12px;
            background: var(--t3-glass-soft);
            border: 1px solid var(--t3-border);
            font-size: 13.5px;
            font-weight: 600;
            color: var(--t3-ink-2);
        }
        .sheet .mlinks a:hover,
        .sheet .mlinks a.is-active {
            background: var(--t3-pill-bg);
            color: var(--t3-primary-ink);
            border-color: rgba(var(--t3-rgb), 0.30);
        }
        .sheet .row { display: flex; gap: 10px; }
        @media (max-width: 460px) {
            .sheet .mlinks { grid-template-columns: 1fr; }
            .sheet .row { flex-direction: column; }
        }

        /* ── Hero: Asymmetric Luminous Bento ─────────────────────────── */
        .t3-hero {
            padding: 116px 0 46px;
        }
        .t3-hero-top {
            display: grid;
            grid-template-columns: minmax(0, 1.12fr) minmax(0, 0.88fr);
            gap: 28px;
            align-items: end;
            margin-bottom: 26px;
        }
        @media (max-width: 920px) {
            .t3-hero { padding-top: 98px; }
            .t3-hero-top { grid-template-columns: 1fr; align-items: start; }
        }
        .t3-kicker-row {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
            margin-bottom: 16px;
        }
        .t3-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 7px 13px;
            border-radius: 999px;
            background: var(--t3-glass);
            backdrop-filter: blur(14px);
            border: 1px solid var(--t3-border);
            font-size: 12px;
            font-weight: 700;
            color: var(--t3-ink-2);
            box-shadow: var(--t3-shadow-sm);
        }
        .t3-dot-live {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--t3-emerald);
            box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.18);
        }
        .t3-theme-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 12px;
            border-radius: 999px;
            border: 1px solid var(--t3-border);
            background: var(--t3-glass);
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            color: var(--t3-ink-2);
        }
        .t3-hero h1 {
            margin: 0 0 14px;
            font-family: 'Space Grotesk', sans-serif;
            font-size: clamp(36px, 5.2vw, 60px);
            line-height: 1.03;
            letter-spacing: -0.04em;
            font-weight: 700;
        }
        .t3-grad {
            background: linear-gradient(125deg, var(--t3-primary) 15%, var(--t3-accent-2) 95%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        .t3-hero-copy {
            margin: 0;
            font-size: 16.5px;
            color: var(--t3-ink-2);
            max-width: 58ch;
        }
        .t3-hero-cta-box {
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }
        .t3-cta-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }
        .t3-kpi-strip {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
            padding-top: 12px;
            border-top: 1px solid var(--t3-border);
        }
        .t3-kpi strong {
            display: block;
            font-family: 'Space Grotesk', sans-serif;
            font-size: 19px;
            font-weight: 700;
        }
        .t3-kpi span {
            font-size: 12px;
            color: var(--t3-ink-3);
        }

        /* ── 3-Card Bento Mosaic ─────────────────────────────────────── */
        .t3-bento {
            display: grid;
            grid-template-columns: 1.05fr 1.15fr 0.85fr;
            gap: 18px;
            align-items: stretch;
        }
        @media (max-width: 1040px) {
            .t3-bento { grid-template-columns: 1fr 1fr; }
            .t3-bento-trust { grid-column: 1 / -1; }
        }
        @media (max-width: 680px) {
            .t3-bento { grid-template-columns: 1fr; }
        }
        .t3-bento-card {
            padding: 22px;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }
        .t3-card-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }
        .t3-card-top h2, .t3-card-top h3 {
            margin: 0;
            font-size: 15.5px;
            font-weight: 800;
        }
        .t3-badge {
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            background: var(--t3-pill-bg);
            color: var(--t3-pill-ink);
            border: 1px solid rgba(var(--t3-rgb), 0.18);
            white-space: nowrap;
        }
        .t3-dock-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 9px;
        }
        .t3-dock-tile {
            padding: 11px 12px;
            border-radius: 13px;
            background: var(--t3-glass-soft);
            border: 1px solid var(--t3-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            transition: transform .18s var(--ease), border-color .18s ease, background .18s ease;
        }
        .t3-dock-tile:hover {
            transform: translateY(-2px);
            background: var(--t3-glass-strong);
            border-color: rgba(var(--t3-rgb), 0.36);
        }
        .t3-dock-tile strong { display: block; font-size: 13px; font-weight: 700; }
        .t3-dock-tile span { font-size: 11px; color: var(--t3-ink-3); }
        .t3-hero-banner-img {
            border-radius: 14px;
            overflow: hidden;
            border: 1px solid var(--t3-border);
            margin-top: auto;
        }
        .t3-hero-banner-img img { width: 100%; height: 112px; object-fit: cover; }

        /* Tabbed Wallet & FX Bento Terminal */
        .t3-seg {
            display: inline-grid;
            grid-template-columns: 1fr 1fr;
            gap: 4px;
            padding: 4px;
            border-radius: 12px;
            background: var(--t3-glass-soft);
            border: 1px solid var(--t3-border);
        }
        .t3-seg button {
            border: 0;
            background: transparent;
            padding: 7px 11px;
            border-radius: 9px;
            font-size: 12.5px;
            font-weight: 700;
            cursor: pointer;
            color: var(--t3-ink-3);
        }
        .t3-seg button.is-on {
            background: var(--t3-primary);
            color: var(--t3-on-primary);
            box-shadow: 0 6px 16px rgba(var(--t3-rgb), 0.28);
        }
        .t3-pane { display: none; flex-direction: column; gap: 11px; }
        .t3-pane.is-on { display: flex; }
        .t3-net-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; }
        .t3-net-btn {
            padding: 8px 6px;
            border-radius: 10px;
            border: 1px solid var(--t3-border);
            background: var(--t3-glass-soft);
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
        }
        .t3-net-btn.is-on {
            background: var(--t3-pill-bg);
            border-color: var(--t3-primary);
            color: var(--t3-pill-ink);
        }
        .t3-field { display: flex; flex-direction: column; gap: 5px; }
        .t3-field label { font-size: 11.5px; font-weight: 700; color: var(--t3-ink-3); }
        .t3-field input, .t3-field select {
            width: 100%;
            padding: 10px 12px;
            border-radius: 11px;
            border: 1px solid var(--t3-border);
            background: var(--t3-input-bg);
            font-size: 14px;
            font-weight: 600;
            outline: none;
        }
        .t3-field input:focus, .t3-field select:focus {
            border-color: var(--t3-primary);
            box-shadow: 0 0 0 3px rgba(var(--t3-rgb), 0.16);
        }
        .t3-row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .t3-out-box {
            padding: 12px 14px;
            border-radius: 12px;
            background: var(--t3-glass-soft);
            border: 1px solid var(--t3-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }
        .t3-out-box strong { font-family: 'Space Grotesk', sans-serif; font-size: 18px; }
        .t3-trust-list {
            display: flex;
            flex-direction: column;
            gap: 9px;
        }
        .t3-trust-row {
            padding: 10px 12px;
            border-radius: 12px;
            background: var(--t3-glass-soft);
            border: 1px solid var(--t3-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            font-size: 13px;
            font-weight: 600;
        }
        .t3-trust-row span:last-child {
            padding: 3px 9px;
            border-radius: 999px;
            background: var(--t3-pill-bg);
            color: var(--t3-primary-ink);
            font-size: 11px;
            font-weight: 700;
        }

        /* ── Sections ────────────────────────────────────────────────── */
        .t3-sec { padding: 54px 0; }
        .t3-sec-head {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-end;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 26px;
        }
        .t3-eyebrow {
            display: inline-block;
            font-size: 11.5px;
            font-weight: 800;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: var(--t3-primary-ink);
            margin-bottom: 6px;
        }
        .t3-sec-head h2 {
            margin: 0 0 6px;
            font-family: 'Space Grotesk', sans-serif;
            font-size: clamp(26px, 3.6vw, 38px);
            line-height: 1.1;
            letter-spacing: -0.03em;
        }
        .t3-sec-head p {
            margin: 0;
            color: var(--t3-ink-2);
            max-width: 62ch;
            font-size: 15px;
        }

        /* ── Split-Rail Personal Features Explorer ───────────────────── */
        .t3-explorer {
            display: grid;
            grid-template-columns: 300px minmax(0, 1fr);
            gap: 22px;
            align-items: start;
        }
        @media (max-width: 960px) {
            .t3-explorer { grid-template-columns: 1fr; }
        }
        .t3-rail {
            position: sticky;
            top: 86px;
            padding: 18px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        @media (max-width: 960px) {
            .t3-rail { position: static; }
        }
        .t3-search {
            width: 100%;
            padding: 10px 12px;
            border-radius: 12px;
            border: 1px solid var(--t3-border);
            background: var(--t3-input-bg);
            font-size: 13.5px;
            outline: none;
        }
        .t3-search:focus { border-color: var(--t3-primary); }
        .t3-rail-btn {
            width: 100%;
            text-align: left;
            padding: 11px 12px;
            border-radius: 13px;
            border: 1px solid transparent;
            background: transparent;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            cursor: pointer;
            color: var(--t3-ink-2);
            font-weight: 700;
            font-size: 13.5px;
            transition: background .18s ease, border-color .18s ease, color .18s ease;
        }
        .t3-rail-btn:hover {
            background: var(--t3-glass-soft);
            border-color: var(--t3-border);
            color: var(--t3-ink);
        }
        .t3-rail-btn.is-on {
            background: var(--t3-pill-bg);
            border-color: rgba(var(--t3-rgb), 0.35);
            color: var(--t3-ink);
        }
        .t3-rail-count {
            font-size: 11px;
            font-weight: 800;
            padding: 3px 8px;
            border-radius: 999px;
            background: var(--t3-glass-strong);
            border: 1px solid var(--t3-border);
        }

        .t3-workspace {
            display: flex;
            flex-direction: column;
            gap: 18px;
        }
        .t3-cat-panel {
            padding: 24px;
        }
        .t3-cat-panel.is-hidden { display: none; }
        .t3-cat-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 14px;
            margin-bottom: 18px;
            padding-bottom: 14px;
            border-bottom: 1px solid var(--t3-border);
        }
        .t3-cat-title {
            display: flex;
            align-items: center;
            gap: 13px;
        }
        .t3-cat-ico {
            width: 44px;
            height: 44px;
            border-radius: 14px;
            display: inline-grid;
            place-items: center;
            background: linear-gradient(135deg, rgba(var(--t3-rgb), 0.16), rgba(var(--t3-rgb), 0.06));
            border: 1px solid rgba(var(--t3-rgb), 0.25);
            color: var(--t3-primary-ink);
            flex: 0 0 auto;
        }
        .t3-cat-ico svg { width: 21px; height: 21px; }
        .t3-cat-head h3 { margin: 0 0 3px; font-size: 20px; font-weight: 800; }
        .t3-cat-head p { margin: 0; font-size: 13.5px; color: var(--t3-ink-3); }

        .t3-feat-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }
        @media (max-width: 640px) {
            .t3-feat-grid { grid-template-columns: 1fr; }
        }
        .t3-feat-tile {
            padding: 15px 16px;
            border-radius: 15px;
            background: var(--t3-glass-soft);
            border: 1px solid var(--t3-border);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            gap: 10px;
            transition: transform .18s var(--ease), border-color .18s ease, background .18s ease;
        }
        .t3-feat-tile:hover {
            transform: translateY(-2px);
            background: var(--t3-glass-strong);
            border-color: rgba(var(--t3-rgb), 0.36);
        }
        .t3-feat-tile.is-filtered-out { display: none; }
        .t3-feat-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 8px;
        }
        .t3-feat-top strong {
            font-size: 14.5px;
            font-weight: 700;
            line-height: 1.32;
        }
        .t3-feat-tile p {
            margin: 0;
            font-size: 12.8px;
            color: var(--t3-ink-3);
            line-height: 1.48;
        }
        .t3-feat-foot {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 12px;
            font-weight: 700;
            color: var(--t3-primary-ink);
        }
        .t3-feat-foot svg { width: 14px; height: 14px; }

        /* ── Coming Soon Glass Strip ─────────────────────────────────── */
        .t3-cs-wrap {
            margin-top: 24px;
            padding: 22px;
            border-radius: var(--radius);
            background: var(--t3-glass-soft);
            border: 1px dashed rgba(var(--t3-rgb), 0.36);
            backdrop-filter: blur(18px);
        }
        .t3-cs-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 12px;
            margin-top: 14px;
        }
        .t3-cs-card {
            padding: 14px 15px;
            border-radius: 14px;
            background: var(--t3-glass);
            border: 1px solid var(--t3-border);
        }
        .t3-cs-tag {
            display: inline-block;
            font-size: 10.5px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .06em;
            padding: 3px 8px;
            border-radius: 999px;
            background: rgba(245, 158, 11, 0.14);
            color: #B45309;
            margin-bottom: 6px;
        }
        html[data-theme="dark"] .t3-cs-tag { color: #FBBF24; }
        .t3-cs-card h4 { margin: 0 0 4px; font-size: 14px; font-weight: 700; }
        .t3-cs-card p { margin: 0; font-size: 12.5px; color: var(--t3-ink-3); }

        /* ── 3-Step Glass Flow & 4-Tile Security Band ────────────────── */
        .t3-steps {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
        }
        @media (max-width: 820px) { .t3-steps { grid-template-columns: 1fr; } }
        .t3-step {
            padding: 22px;
        }
        .t3-step-num {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            display: inline-grid;
            place-items: center;
            font-family: 'Space Grotesk', sans-serif;
            font-weight: 700;
            background: var(--t3-pill-bg);
            color: var(--t3-primary-ink);
            margin-bottom: 12px;
        }
        .t3-step h3 { margin: 0 0 6px; font-size: 17px; }
        .t3-step p { margin: 0; font-size: 13.8px; color: var(--t3-ink-2); }

        .t3-sec-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
        }
        @media (max-width: 980px) { .t3-sec-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 560px) { .t3-sec-grid { grid-template-columns: 1fr; } }
        .t3-sec-card { padding: 20px; }
        .t3-sec-card h3 { margin: 0 0 6px; font-size: 16px; }
        .t3-sec-card p { margin: 0 0 12px; font-size: 13.5px; color: var(--t3-ink-2); }
        .t3-sec-card a { font-size: 13px; font-weight: 700; color: var(--t3-primary-ink); }

        /* ── 2-Column Glass FAQ + CTA + Footer ───────────────────────── */
        .t3-faq-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }
        @media (max-width: 780px) { .t3-faq-grid { grid-template-columns: 1fr; } }
        .t3-faq-card { overflow: hidden; }
        .t3-faq-btn {
            width: 100%;
            text-align: left;
            padding: 18px 20px;
            border: 0;
            background: transparent;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .t3-faq-btn span:last-child { font-size: 19px; color: var(--t3-primary-ink); transition: transform .2s ease; }
        .t3-faq-card.open .t3-faq-btn span:last-child { transform: rotate(45deg); }
        .t3-faq-body {
            display: none;
            padding: 0 20px 18px;
            font-size: 13.8px;
            color: var(--t3-ink-2);
        }
        .t3-faq-card.open .t3-faq-body { display: block; }

        .t3-cta-banner {
            padding: 32px;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
        }
        .t3-cta-banner h2 {
            margin: 0 0 6px;
            font-family: 'Space Grotesk', sans-serif;
            font-size: clamp(24px, 3vw, 32px);
        }
        .t3-cta-banner p { margin: 0; color: var(--t3-ink-2); max-width: 54ch; }

        .t3-footer .brand {
            font-family: 'Space Grotesk', sans-serif;
            font-weight: 800;
            letter-spacing: -0.02em;
            color: var(--t3-ink);
        }
        .t3-footer {
            margin-top: 28px;
            padding: 38px 0 46px;
            border-top: 1px solid var(--t3-border);
        }
        .t3-foot-top {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 24px;
        }
        .t3-foot-meta {
            margin-top: 20px;
            padding-top: 18px;
            border-top: 1px solid var(--t3-border);
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: 12px;
            font-size: 12.5px;
            color: var(--t3-ink-3);
        }
        /* Style canonical bc_public_directory_html() inside Template 3 frost */
        .bc-public-directory {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 14px;
            padding: 20px;
            border-radius: var(--radius-sm);
            background: var(--t3-glass-soft);
            border: 1px solid var(--t3-border);
            backdrop-filter: blur(16px);
        }
        .bc-public-directory h3 {
            margin: 0 0 10px;
            font-size: 11.5px;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: var(--t3-primary-ink);
        }
        .bc-public-directory ul { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 7px; }
        .bc-public-directory a { font-size: 13px; color: var(--t3-ink-2); }
        .bc-public-directory a:hover { color: var(--t3-ink); text-decoration: underline; }
        .bc-app-badge {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            padding: 10px 16px;
            border-radius: 13px;
            background: var(--t3-glass-strong);
            border: 1px solid var(--t3-border);
            font-size: 13.5px;
            font-weight: 700;
            color: var(--t3-ink);
        }
        .bc-app-badge svg { width: 18px; height: 18px; }

        [data-rv] { opacity: 0; transform: translateY(14px); transition: opacity .5s ease, transform .5s var(--ease); }
        [data-rv].in { opacity: 1; transform: none; }
    </style>
</head>
<body>
    <div class="t3-mesh" aria-hidden="true">
        <div class="t3-orb a"></div>
        <div class="t3-orb b"></div>
        <div class="t3-orb c"></div>
    </div>
    <div class="t3-dots" aria-hidden="true"></div>

    <?php echo bc_meridian_header_and_menu($ctx, 'personal', '/'); ?>

    <!-- Hero: Asymmetric Luminous Bento -->
    <section class="t3-hero">
        <div class="t3-wrap">
            <div class="t3-hero-top">
                <div data-rv class="in">
                    <div class="t3-kicker-row">
                        <span class="t3-pill"><span class="t3-dot-live"></span> <?php echo $short_e; ?> · Luminous Glass Personal Hub</span>
                        <button type="button" class="t3-theme-btn" id="t3ThemeToggle" aria-label="Switch glass surface mode">Switch to Midnight Frost</button>
                    </div>
                    <h1>Every payment, bill and <span class="t3-grad">digital card</span> in clear glass.</h1>
                    <p class="t3-hero-copy"><?php echo bc_meridian_e($ctx['site_desc']); ?> Every personal feature on this landing page stays live-synced with your Service Control Center.</p>
                </div>

                <div class="t3-glass t3-hero-cta-box" data-rv>
                    <div class="t3-cta-row">
                        <?php if ($t3_dash !== ''): ?>
                            <a class="btn primary lg" href="<?php echo bc_meridian_e($t3_dash); ?>">Open your dashboard</a>
                        <?php else: ?>
                            <a class="btn primary lg" href="/web/Register.php">Create personal account</a>
                            <a class="btn ghost lg" href="/web/Login.php">Sign in</a>
                        <?php endif; ?>
                        <?php if ($t3_app_badge !== ''): ?>
                            <?php echo $t3_app_badge; ?>
                        <?php endif; ?>
                    </div>
                    <div class="t3-kpi-strip">
                        <div class="t3-kpi">
                            <strong><?php echo (int)$active_feature_count; ?></strong>
                            <span>Active features</span>
                        </div>
                        <div class="t3-kpi">
                            <strong>5</strong>
                            <span>Service pillars</span>
                        </div>
                        <div class="t3-kpi">
                            <strong>2FA + KYC</strong>
                            <span>Protected wallet</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 3-Card Bento Mosaic -->
            <div class="t3-bento">
                <!-- Bento 1: Quick Launch Dock + Brand Media -->
                <div class="t3-glass t3-bento-card" data-rv>
                    <div class="t3-card-top">
                        <h2>Quick Launch Dock</h2>
                        <span class="t3-badge">Live Service Control</span>
                    </div>
                    <div class="t3-dock-grid">
                        <?php if (!empty($quick_dock)): ?>
                            <?php foreach ($quick_dock as $qd): ?>
                            <a class="t3-dock-tile" href="<?php echo bc_meridian_e($qd['href']); ?>">
                                <span>
                                    <strong><?php echo bc_meridian_e($qd['label']); ?></strong>
                                    <span><?php echo bc_meridian_e($qd['tag']); ?></span>
                                </span>
                                <span aria-hidden="true">→</span>
                            </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <a class="t3-dock-tile" href="/web/Dashboard.php"><span><strong>Dashboard</strong><span>Wallet</span></span><span>→</span></a>
                            <a class="t3-dock-tile" href="/web/Transactions.php"><span><strong>Ledger</strong><span>Receipts</span></span><span>→</span></a>
                        <?php endif; ?>
                    </div>
                    <?php if ($header_image_url !== ''): ?>
                    <div class="t3-hero-banner-img">
                        <img src="<?php echo bc_meridian_e($header_image_url); ?>" alt="<?php echo $site_e; ?> hero preview">
                    </div>
                    <?php else: ?>
                    <div class="t3-out-box" style="margin-top:auto">
                        <div>
                            <div style="font-size:11.5px;color:var(--t3-ink-3)">Personal Directory</div>
                            <strong style="font-size:14.5px">Explore all <?php echo (int)$active_feature_count; ?> active services</strong>
                        </div>
                        <a class="btn sm ghost" href="#personal-features">Browse</a>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Bento 2: Tabbed Recharge + FX Terminal -->
                <div class="t3-glass t3-bento-card" data-rv>
                    <div class="t3-card-top">
                        <h2>Interactive Estimator</h2>
                        <div class="t3-seg" role="tablist" aria-label="Estimator mode">
                            <button type="button" class="is-on" data-t3tab="topup" role="tab" aria-selected="true">Top-Up</button>
                            <button type="button" data-t3tab="fx" role="tab" aria-selected="false">FX Rates</button>
                        </div>
                    </div>

                    <div class="t3-pane is-on" id="t3PaneTopup">
                        <div class="t3-net-row" id="t3NetRow">
                            <button type="button" class="t3-net-btn is-on" data-net="MTN">MTN</button>
                            <button type="button" class="t3-net-btn" data-net="Airtel">Airtel</button>
                            <button type="button" class="t3-net-btn" data-net="Glo">Glo</button>
                            <button type="button" class="t3-net-btn" data-net="9mobile">9mobile</button>
                        </div>
                        <div class="t3-row2">
                            <div class="t3-field">
                                <label for="t3TopupType">Service</label>
                                <select id="t3TopupType">
                                    <option value="airtime">Airtime</option>
                                    <option value="data">Data Bundle</option>
                                </select>
                            </div>
                            <div class="t3-field">
                                <label for="t3TopupAmt">Amount (NGN)</label>
                                <input id="t3TopupAmt" type="number" min="100" step="100" value="2000">
                            </div>
                        </div>
                        <div class="t3-out-box">
                            <div>
                                <div style="font-size:11.5px;color:var(--t3-ink-3)" id="t3TopupMeta">MTN Airtime estimate</div>
                                <strong id="t3TopupPay">₦1,960</strong>
                            </div>
                            <a class="btn sm primary" id="t3TopupGo" href="<?php echo $t3_enabled('airtime') ? '/services/airtime.php' : '/services/data.php'; ?>">Continue</a>
                        </div>
                    </div>

                    <div class="t3-pane" id="t3PaneFx">
                        <div class="t3-row2">
                            <div class="t3-field">
                                <label for="t3FxAmt">Amount</label>
                                <input id="t3FxAmt" type="number" min="1" step="1" value="100">
                            </div>
                            <div class="t3-field">
                                <label for="t3FxFrom">From</label>
                                <select id="t3FxFrom">
                                    <option value="USD" selected>USD</option>
                                    <option value="GBP">GBP</option>
                                    <option value="EUR">EUR</option>
                                    <option value="NGN">NGN</option>
                                </select>
                            </div>
                        </div>
                        <div class="t3-field">
                            <label for="t3FxTo">Convert to</label>
                            <select id="t3FxTo">
                                <option value="NGN" selected>NGN — Nigerian Naira</option>
                                <option value="USD">USD — US Dollar</option>
                                <option value="GBP">GBP — British Pound</option>
                                <option value="EUR">EUR — Euro</option>
                            </select>
                        </div>
                        <div class="t3-out-box">
                            <div>
                                <div style="font-size:11.5px;color:var(--t3-ink-3)" id="t3FxNote">Indicative conversion</div>
                                <strong id="t3FxResult">₦155,000.00</strong>
                            </div>
                            <?php if ($t3_enabled('currency_conversion')): ?>
                                <a class="btn sm primary" href="/MultiCurrencyWallet.php">Convert</a>
                            <?php else: ?>
                                <span class="t3-badge">Paused</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Bento 3: Security & Trust Status -->
                <div class="t3-glass t3-bento-card t3-bento-trust" data-rv>
                    <div class="t3-card-top">
                        <h2>Account Protection</h2>
                        <span class="t3-badge">Verified</span>
                    </div>
                    <div class="t3-trust-list">
                        <a class="t3-trust-row" href="/web/KYCVerification.php"><span>Identity KYC</span><span>Ready</span></a>
                        <a class="t3-trust-row" href="/web/SecurityPIN.php"><span>Transaction PIN</span><span>Active</span></a>
                        <a class="t3-trust-row" href="/web/SecuritySetup.php"><span>Google Auth &amp; OTP</span><span>2FA</span></a>
                        <a class="t3-trust-row" href="<?php echo $t3_enabled('password_reset') ? '/web/PasswordRecovery.php' : '/web/AccountSettings.php'; ?>"><span>Recovery &amp; Sessions</span><span>Protected</span></a>
                        <a class="t3-trust-row" href="/Contact.php"><span>Dispute Support</span><span>24/7</span></a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Personal Features: Split-Rail Explorer -->
    <section class="t3-sec" id="personal-features">
        <div class="t3-wrap">
            <div class="t3-sec-head">
                <div>
                    <span class="t3-eyebrow">Service Control Synced</span>
                    <h2>Personal Features</h2>
                    <p>Browse every capability across all five personal pillars. Each item checks your Service Control Center in real time—disabled or pending integrations appear cleanly under Coming Soon.</p>
                </div>
                <a class="btn ghost" href="/Services.php">Open Services Directory</a>
            </div>

            <div class="t3-explorer">
                <!-- Left Sticky Glass Rail -->
                <aside class="t3-glass t3-rail" aria-label="Personal Features navigation rail">
                    <input type="search" class="t3-search" id="t3FeatureSearch" placeholder="Search personal features..." aria-label="Search personal features">
                    <button type="button" class="t3-rail-btn is-on" data-t3cat="all">
                        <span>All Categories</span>
                        <span class="t3-rail-count"><?php echo (int)$active_feature_count; ?></span>
                    </button>
                    <?php foreach ($personal_pillars as $pl): ?>
                    <button type="button" class="t3-rail-btn" data-t3cat="<?php echo bc_meridian_e($pl['id']); ?>">
                        <span><?php echo bc_meridian_e($pl['title']); ?></span>
                        <span class="t3-rail-count"><?php echo count($pl['items']); ?></span>
                    </button>
                    <?php endforeach; ?>
                </aside>

                <!-- Right Active Glass Workspace -->
                <div class="t3-workspace" id="t3Workspace">
                    <?php foreach ($personal_pillars as $pl): ?>
                    <article class="t3-glass t3-cat-panel" data-t3panel="<?php echo bc_meridian_e($pl['id']); ?>">
                        <div class="t3-cat-head">
                            <div class="t3-cat-title">
                                <span class="t3-cat-ico" aria-hidden="true"><?php echo $pl['icon']; ?></span>
                                <div>
                                    <span class="t3-eyebrow" style="margin-bottom:2px"><?php echo bc_meridian_e($pl['eyebrow']); ?></span>
                                    <h3><?php echo bc_meridian_e($pl['title']); ?></h3>
                                    <p><?php echo bc_meridian_e($pl['subtitle']); ?></p>
                                </div>
                            </div>
                            <span class="t3-badge"><?php echo count($pl['items']); ?> Active</span>
                        </div>

                        <div class="t3-feat-grid">
                            <?php foreach ($pl['items'] as $it): ?>
                            <a class="t3-feat-tile" href="<?php echo bc_meridian_e($it['href']); ?>" data-search="<?php echo bc_meridian_e(strtolower($it['title'] . ' ' . $it['desc'] . ' ' . $pl['title'])); ?>">
                                <div>
                                    <div class="t3-feat-top">
                                        <strong><?php echo bc_meridian_e($it['title']); ?></strong>
                                        <?php if (!empty($it['badge'])): ?>
                                            <span class="t3-badge"><?php echo bc_meridian_e($it['badge']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <p style="margin-top:6px"><?php echo bc_meridian_e($it['desc']); ?></p>
                                </div>
                                <span class="t3-feat-foot">
                                    <span>Open feature</span>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                                </span>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </article>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if (!empty($coming_soon_items)): ?>
            <div class="t3-cs-wrap" id="coming-soon">
                <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:10px">
                    <div>
                        <span class="t3-eyebrow">Service Control Status</span>
                        <h3 style="margin:0;font-size:20px;font-weight:800">Coming Soon &amp; Paused Features (<?php echo (int)$paused_feature_count; ?>)</h3>
                    </div>
                    <span style="font-size:13px;color:var(--t3-ink-3)">Synced automatically with Site Owner Service Control Center</span>
                </div>
                <div class="t3-cs-grid">
                    <?php foreach ($coming_soon_items as $cs): ?>
                    <a class="t3-cs-card" href="<?php echo bc_meridian_e($cs['href']); ?>">
                        <span class="t3-cs-tag"><?php echo bc_meridian_e($cs['reason']); ?></span>
                        <h4><?php echo bc_meridian_e($cs['title']); ?></h4>
                        <p><?php echo bc_meridian_e($cs['category']); ?> · <?php echo bc_meridian_e($cs['desc']); ?></p>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- 3-Step Glass Onboarding Flow -->
    <section class="t3-sec">
        <div class="t3-wrap">
            <div class="t3-sec-head">
                <div>
                    <span class="t3-eyebrow">How It Works</span>
                    <h2>Start in three streamlined steps</h2>
                    <p>Move from account registration to automated everyday payments and virtual card spending in minutes.</p>
                </div>
            </div>
            <div class="t3-steps">
                <div class="t3-glass t3-step" data-rv>
                    <span class="t3-step-num">01</span>
                    <h3>Create &amp; verify your profile</h3>
                    <p>Register in seconds, set your transaction Security PIN and complete KYC verification for higher limits.</p>
                </div>
                <div class="t3-glass t3-step" data-rv>
                    <span class="t3-step-num">02</span>
                    <h3>Fund via virtual bank or card</h3>
                    <p>Receive deposits into your dedicated virtual bank account or fund directly with verifiable receipts.</p>
                </div>
                <div class="t3-glass t3-step" data-rv>
                    <span class="t3-step-num">03</span>
                    <h3>Pay, transfer &amp; earn rewards</h3>
                    <p>Settle bills, send transfers, spend with USD virtual cards and collect loyalty rewards automatically.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Security & Support Band -->
    <section class="t3-sec" id="security">
        <div class="t3-wrap">
            <div class="t3-sec-head">
                <div>
                    <span class="t3-eyebrow">Security &amp; Support</span>
                    <h2>Protection at every layer</h2>
                    <p>Identity checks, transaction PIN locks, Google Authenticator &amp; email OTP, recovery tools and human dispute care.</p>
                </div>
                <a class="btn ghost" href="/Security.php">Security Overview</a>
            </div>
            <div class="t3-sec-grid">
                <div class="t3-glass t3-sec-card" data-rv>
                    <h3>Identity verification—KYC</h3>
                    <p>Tiered verification helps keep accounts compliant and unlocks higher transfer and card limits.</p>
                    <a href="/web/KYCVerification.php">Verify identity →</a>
                </div>
                <div class="t3-glass t3-sec-card" data-rv>
                    <h3>Security PIN &amp; 2FA</h3>
                    <p>Combine a private transaction PIN with Google Authenticator or email OTP before sensitive actions.</p>
                    <a href="/web/SecuritySetup.php">Configure 2FA →</a>
                </div>
                <div class="t3-glass t3-sec-card" data-rv>
                    <h3>Recovery &amp; controls</h3>
                    <p>Reset credentials safely, monitor active sessions and manage account security preferences anytime.</p>
                    <a href="<?php echo $t3_enabled('password_reset') ? '/web/PasswordRecovery.php' : '/web/AccountSettings.php'; ?>">Manage security →</a>
                </div>
                <div class="t3-glass t3-sec-card" data-rv>
                    <h3>Support &amp; disputes</h3>
                    <p>Track transaction references and reach dedicated support whenever a payment needs manual review.</p>
                    <a href="/Contact.php">Contact support →</a>
                </div>
            </div>
        </div>
    </section>

    <!-- FAQ -->
    <section class="t3-sec" id="faq">
        <div class="t3-wrap">
            <div class="t3-sec-head">
                <div>
                    <span class="t3-eyebrow">Questions &amp; Answers</span>
                    <h2>Frequently asked questions</h2>
                </div>
            </div>
            <div class="t3-faq-grid">
                <div class="t3-glass t3-faq-card open">
                    <button type="button" class="t3-faq-btn" aria-expanded="true">
                        <span>How do Personal Features sync with Service Control Center?</span>
                        <span>+</span>
                    </button>
                    <div class="t3-faq-body">Every service listed under Accounts &amp; Payments, Everyday Services, Cards &amp; Digital Assets, Rewards &amp; Tools and Security &amp; Support checks live Service Control status. Paused services automatically relocate to Coming Soon.</div>
                </div>
                <div class="t3-glass t3-faq-card">
                    <button type="button" class="t3-faq-btn" aria-expanded="false">
                        <span>Can I switch between Personal and Business accounts?</span>
                        <span>+</span>
                    </button>
                    <div class="t3-faq-body">Yes. Use the Personal / Business switcher in the top navigation bar to explore consumer tools or visit the Business, Merchant &amp; Developer API portal.</div>
                </div>
                <div class="t3-glass t3-faq-card">
                    <button type="button" class="t3-faq-btn" aria-expanded="false">
                        <span>What security options protect my wallet?</span>
                        <span>+</span>
                    </button>
                    <div class="t3-faq-body">Accounts support KYC verification, a transaction Security PIN, Google Authenticator (TOTP), email OTP verification, recovery tools and 24/7 dispute support.</div>
                </div>
                <div class="t3-glass t3-faq-card">
                    <button type="button" class="t3-faq-btn" aria-expanded="false">
                        <span>Are receipts and transaction tracking included?</span>
                        <span>+</span>
                    </button>
                    <div class="t3-faq-body">Every wallet funding, transfer and bill order generates a trackable reference inside your Transaction History and Payment Receipts console.</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Bottom CTA Banner -->
    <section class="t3-sec" style="padding-top:16px">
        <div class="t3-wrap">
            <div class="t3-glass t3-cta-banner" data-rv>
                <div>
                    <span class="t3-eyebrow">Ready to begin</span>
                    <h2>Join <?php echo $site_e; ?> today</h2>
                    <p>Manage personal wallets, everyday utilities, virtual cards, rewards and account security from one frosted glass hub.</p>
                </div>
                <div class="t3-cta-row">
                    <?php if ($t3_dash !== ''): ?>
                        <a class="btn primary lg" href="<?php echo bc_meridian_e($t3_dash); ?>">Go to Dashboard</a>
                    <?php else: ?>
                        <a class="btn primary lg" href="/web/Register.php">Create free account</a>
                    <?php endif; ?>
                    <a class="btn ghost lg" href="/Business.php">Explore Business</a>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="t3-footer">
        <div class="t3-wrap">
            <div class="t3-foot-top">
                <div>
                    <div class="brand" style="font-size:17px"><?php echo $site_e; ?></div>
                    <p style="margin:6px 0 0;font-size:13.5px;color:var(--t3-ink-3)"><?php echo bc_meridian_e($ctx['site_desc']); ?></p>
                </div>
                <div class="t3-cta-row">
                    <?php if ($t3_app_badge !== ''): ?>
                        <?php echo $t3_app_badge; ?>
                    <?php else: ?>
                        <a class="btn ghost sm" href="/DownloadApp.php">Mobile App Info</a>
                    <?php endif; ?>
                    <a class="btn ghost sm" href="/Contact.php">Support</a>
                </div>
            </div>

            <?php echo bc_public_directory_html(); ?>

            <div class="t3-foot-meta">
                <span>&copy; <?php echo date('Y'); ?> <?php echo $site_e; ?>. All rights reserved.</span>
                <span>Luminous Glass Template · Service Control Synced</span>
            </div>
        </div>
    </footer>

    <div class="sheet" id="getSheet" hidden=""><div class="glass" role="dialog" aria-modal="true" aria-labelledby="getTitle" style="max-width:420px;text-align:center" id="getBody"></div></div>

    <script>
        window.MERIDIAN_CONFIG = <?php echo json_encode($js_config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
        window.__MERIDIAN__ = window.MERIDIAN_CONFIG;
    </script>
    <script src="/asset/meridian-1/meridian-1.js?v=1" defer></script>
    <script>
    (function () {
        // Glass Surface Toggle (Luminous Frost Light <-> Midnight Frost)
        var themeBtn = document.getElementById('t3ThemeToggle');
        if (themeBtn) {
            themeBtn.addEventListener('click', function () {
                var root = document.documentElement;
                var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
                root.setAttribute('data-theme', next);
                themeBtn.textContent = next === 'dark' ? 'Switch to Luminous Frost' : 'Switch to Midnight Frost';
            });
        }

        // Bento Tab Switcher (Top-Up <-> FX)
        var tabBtns = document.querySelectorAll('[data-t3tab]');
        var paneTopup = document.getElementById('t3PaneTopup');
        var paneFx = document.getElementById('t3PaneFx');
        for (var i = 0; i < tabBtns.length; i++) {
            tabBtns[i].addEventListener('click', function () {
                var target = this.getAttribute('data-t3tab');
                for (var j = 0; j < tabBtns.length; j++) {
                    var on = tabBtns[j] === this;
                    tabBtns[j].classList.toggle('is-on', on);
                    tabBtns[j].setAttribute('aria-selected', on ? 'true' : 'false');
                }
                if (paneTopup) paneTopup.classList.toggle('is-on', target === 'topup');
                if (paneFx) paneFx.classList.toggle('is-on', target === 'fx');
            });
        }

        // Top-up calculator
        var activeNet = 'MTN';
        var netBtns = document.querySelectorAll('#t3NetRow .t3-net-btn');
        var topType = document.getElementById('t3TopupType');
        var topAmt = document.getElementById('t3TopupAmt');
        var topMeta = document.getElementById('t3TopupMeta');
        var topPay = document.getElementById('t3TopupPay');
        var topGo = document.getElementById('t3TopupGo');

        function updateTopup() {
            var amt = Math.max(100, parseFloat(topAmt ? topAmt.value : 2000) || 2000);
            var isData = topType && topType.value === 'data';
            var discount = isData ? 0.03 : 0.02;
            var payable = Math.round(amt * (1 - discount));
            if (topMeta) topMeta.textContent = activeNet + (isData ? ' Data bundle estimate' : ' Airtime estimate');
            if (topPay) topPay.textContent = '₦' + payable.toLocaleString('en-US');
            if (topGo) topGo.setAttribute('href', isData ? '/services/data.php' : '/services/airtime.php');
        }
        for (var n = 0; n < netBtns.length; n++) {
            netBtns[n].addEventListener('click', function () {
                activeNet = this.getAttribute('data-net') || 'MTN';
                for (var k = 0; k < netBtns.length; k++) netBtns[k].classList.toggle('is-on', netBtns[k] === this);
                updateTopup();
            });
        }
        if (topType) topType.addEventListener('change', updateTopup);
        if (topAmt) topAmt.addEventListener('input', updateTopup);
        updateTopup();

        // FX converter
        var rates = (window.__MERIDIAN__ && window.__MERIDIAN__.rates) || { USD: 1, NGN: 1550, GBP: 0.79, EUR: 0.92 };
        var fxAmt = document.getElementById('t3FxAmt');
        var fxFrom = document.getElementById('t3FxFrom');
        var fxTo = document.getElementById('t3FxTo');
        var fxRes = document.getElementById('t3FxResult');
        var fxNote = document.getElementById('t3FxNote');
        var sym = { NGN: '₦', USD: '$', GBP: '£', EUR: '€' };

        function updateFx() {
            if (!fxAmt || !fxFrom || !fxTo || !fxRes) return;
            var a = Math.max(0, parseFloat(fxAmt.value) || 0);
            var f = fxFrom.value;
            var t = fxTo.value;
            var rf = rates[f] || 1;
            var rt = rates[t] || 1;
            var out = (a / rf) * rt;
            fxRes.textContent = (sym[t] || '') + out.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            if (fxNote) fxNote.textContent = '1 ' + f + ' ≈ ' + ((rt / rf)).toLocaleString('en-US', { maximumFractionDigits: 2 }) + ' ' + t;
        }
        if (fxAmt) fxAmt.addEventListener('input', updateFx);
        if (fxFrom) fxFrom.addEventListener('change', updateFx);
        if (fxTo) fxTo.addEventListener('change', updateFx);
        updateFx();

        // Split-rail category filter + live search
        var railBtns = document.querySelectorAll('[data-t3cat]');
        var panels = document.querySelectorAll('[data-t3panel]');
        var searchBox = document.getElementById('t3FeatureSearch');
        var activeCat = 'all';

        function applyExplorer() {
            var q = (searchBox ? searchBox.value : '').trim().toLowerCase();
            for (var i = 0; i < panels.length; i++) {
                var p = panels[i];
                var id = p.getAttribute('data-t3panel');
                var catMatch = (activeCat === 'all' || id === activeCat);
                var tiles = p.querySelectorAll('.t3-feat-tile');
                var visibleCount = 0;
                for (var j = 0; j < tiles.length; j++) {
                    var hay = tiles[j].getAttribute('data-search') || '';
                    var match = !q || hay.indexOf(q) !== -1;
                    tiles[j].classList.toggle('is-filtered-out', !match);
                    if (match) visibleCount++;
                }
                p.classList.toggle('is-hidden', !catMatch || visibleCount === 0);
            }
        }
        for (var r = 0; r < railBtns.length; r++) {
            railBtns[r].addEventListener('click', function () {
                activeCat = this.getAttribute('data-t3cat') || 'all';
                for (var m = 0; m < railBtns.length; m++) {
                    railBtns[m].classList.toggle('is-on', railBtns[m] === this);
                }
                applyExplorer();
            });
        }
        if (searchBox) searchBox.addEventListener('input', applyExplorer);

        // FAQ Accordion
        var faqCards = document.querySelectorAll('.t3-faq-card');
        for (var f = 0; f < faqCards.length; f++) {
            (function (card) {
                var btn = card.querySelector('.t3-faq-btn');
                if (!btn) return;
                btn.addEventListener('click', function () {
                    var open = !card.classList.contains('open');
                    card.classList.toggle('open', open);
                    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                });
            })(faqCards[f]);
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

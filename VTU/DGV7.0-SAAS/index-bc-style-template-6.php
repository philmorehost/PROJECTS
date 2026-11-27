<?php
require_once __DIR__ . '/func/bc-public-app-brand.php';
require_once __DIR__ . '/func/bc-public-meridian.php';
require_once __DIR__ . '/func/bc-public-pages.php';
require_once __DIR__ . '/func/bc-theme.php';
require_once __DIR__ . '/func/bc-fintech-accounts.php';
include_once(__DIR__ . '/func/bc-seo.php');

/**
 * DGV7 landing template 6 ("Plate" — Smoked Obsidian & Frosted Glass Showcase).
 *
 * Distinct modern glassmorphism layout with exact Meridian-1 navigation parity,
 * Service Control Center gated Personal Features across all 5 pillars, and
 * server-rendered Personal/Business account partition panels.
 */

$ctx = bc_meridian_context($vendor_account_details ?? null);
$vendor_id = (int)($ctx['vendor_id'] ?? 0);
$site_title = $ctx['site_name'];
$short_brand = $ctx['short_brand'];
$site_e = bc_meridian_e($site_title);
$short_e = bc_meridian_e($short_brand);
$personal_vars = bc_meridian_personal_style_vars($ctx['primary_color']);

$site_logo = $ctx['logo_url'];
$header_image_url = '';
$site_details = array(
    'site_title' => $site_title,
    'site_desc' => $ctx['site_desc'],
    'apk_download_url' => $ctx['app_url'],
);

if ($vendor_id > 0 && ($connection_server ?? null) instanceof mysqli) {
    $stmt_site = mysqli_prepare($connection_server, "SELECT sd.*, vst.header_image, vst.primary_color FROM sas_site_details sd LEFT JOIN sas_vendor_style_templates vst ON vst.vendor_id = sd.vendor_id WHERE sd.vendor_id = ? ORDER BY vst.id DESC LIMIT 1");
    if (!$stmt_site) {
        $stmt_site = mysqli_prepare($connection_server, "SELECT * FROM sas_site_details WHERE vendor_id = ? LIMIT 1");
    }
    if ($stmt_site) {
        mysqli_stmt_bind_param($stmt_site, "i", $vendor_id);
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
    seo_start_ob_auto_alt($site_title);
}

// Tenant brand colour, validated strictly.
$brand_colour = '#0F766E';
if (!empty($site_details['primary_color']) && preg_match('/^#[0-9a-fA-F]{6}$/', $site_details['primary_color'])) {
    $brand_colour = strtoupper($site_details['primary_color']);
} elseif (!empty($ctx['primary_color']) && preg_match('/^#[0-9a-fA-F]{6}$/', $ctx['primary_color'])) {
    $brand_colour = strtoupper($ctx['primary_color']);
}
$plate_palette = bc_theme_palette($brand_colour);
$plate_light = $plate_palette['modes']['light'];
$plate_dark = $plate_palette['modes']['dark'];
$brand_rgb = '15, 118, 110';
if (sscanf($brand_colour, '#%02x%02x%02x', $br, $bg, $bb) === 3) {
    $brand_rgb = "$br, $bg, $bb";
}
$plate_accent_deep = bc_meridian_mix_hex($brand_colour, '#0B1220', 0.72);
$plate_accent_tint = bc_meridian_mix_hex($brand_colour, '#FFFFFF', 0.80);

// Account capability partitions (server-rendered Personal & Business panels)
$personal_cards = bc_fintech_marketing_partition($vendor_id, 'personal');
$business_cards = bc_fintech_marketing_partition($vendor_id, 'business');

$plate_dash = function_exists('bc_public_dashboard_url') ? bc_public_dashboard_url() : '';
$plate_app_badge = bc_public_app_badge_html($ctx['app_url']);

// Inline SVG icon helper (never uses icon-font classes)
if (!function_exists('bc_plate_icon')) {
    function bc_plate_icon($name, $size = 20) {
        $paths = array(
            'wallet'        => '<rect x="2" y="6" width="20" height="13" rx="2.5"/><path d="M2 10h20"/><circle cx="17" cy="14.5" r="1.3"/>',
            'fund'          => '<path d="M12 2v20"/><path d="M17 7H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
            'send'          => '<path d="M22 2 11 13"/><path d="M22 2l-7 20-4-9-9-4 20-7z"/>',
            'globe'         => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a15 15 0 0 1 0 18a15 15 0 0 1 0-18z"/>',
            'sim'           => '<rect x="6" y="2" width="12" height="20" rx="2"/><path d="M9 7h6v5H9z"/>',
            'invoice'       => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8"/><path d="M8 17h5"/>',
            'card'          => '<rect x="2" y="5" width="20" height="14" rx="2.5"/><path d="M2 10h20"/>',
            'code'          => '<path d="m16 18 6-6-6-6"/><path d="m8 6-6 6 6 6"/>',
            'check'         => '<path d="m20 6-11 11-5-5"/>',
            'shield'        => '<path d="M12 2 4 6v6c0 5 3.5 9 8 10c4.5-1 8-5 8-10V6z"/>',
            'rate'          => '<path d="M3 17l6-6 4 4 8-8"/><path d="M14 7h7v7"/>',
            'spark'         => '<path d="M13 2L4.5 13.5H12L11 22l8.5-11.5H12L13 2z"/>',
            'star'          => '<path d="M12 3l2.6 5.3 5.9.9-4.2 4.1 1 5.8L12 16.4l-5.3 2.7 1-5.8-4.2-4.1 5.9-.9L12 3z"/>',
            'transfers'     => '<path d="M5 12h14M13 6l6 6-6 6"/>',
            'cross_border'  => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/>',
            'fx'            => '<path d="M3 17l6-6 4 4 8-8"/>',
            'esim'          => '<rect x="6" y="2" width="12" height="20" rx="2"/>',
            'tuition'       => '<path d="M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 1.7 2.7 3 6 3s6-1.3 6-3v-5"/>',
            'invoices'      => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>',
            'pos'           => '<rect x="2" y="5" width="20" height="14" rx="2"/>',
            'developer_api' => '<path d="m16 18 6-6-6-6"/><path d="m8 6-6 6 6 6"/>',
        );
        $body = $paths[$name] ?? $paths['check'];
        return '<svg width="' . (int)$size . '" height="' . (int)$size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $body . '</svg>';
    }
}

if (!function_exists('bc_plate_card')) {
    function bc_plate_card(array $card, $live) {
        $icon = $card['service'] ?? 'check';
        $cls = $live ? 'pc-card' : 'pc-card pc-card--soon';
        ?>
        <article class="<?php echo $cls; ?>">
            <div class="pc-card__icon"><?php echo bc_plate_icon($icon); ?></div>
            <h3 class="pc-card__title"><?php echo htmlspecialchars((string)($card['name'] ?? '')); ?></h3>
            <p class="pc-card__copy"><?php echo htmlspecialchars((string)($card['copy'] ?? '')); ?></p>
            <?php if (!$live): ?>
                <span class="pc-card__tag">Coming soon</span>
            <?php endif; ?>
        </article>
        <?php
    }
}

if (!function_exists('bc_plate_stat')) {
    function bc_plate_stat($value, $label) {
        ?>
        <div class="pk-stat">
            <span class="pk-stat__value"><?php echo htmlspecialchars((string)$value); ?></span>
            <span class="pk-stat__label"><?php echo htmlspecialchars((string)$label); ?></span>
        </div>
        <?php
    }
}

// Service Control Center gating helper
$t6_enabled = function ($key) use ($vendor_id) {
    if ($key === null || $key === '') return true;
    return bc_meridian_service_enabled($key, $vendor_id);
};

// Personal Features synced with Service Control Center across all 5 pillars
$personal_pillars = array();
$coming_soon_items = array();

$add_feature = function (&$pillar, $title, $desc, $href, $service_key = null, $badge = '', $pending_note = '') use ($t6_enabled, &$coming_soon_items) {
    if ($service_key === null || $t6_enabled($service_key)) {
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
            'reason' => $pending_note !== '' ? $pending_note : 'Coming soon · Paused in Service Control',
        );
    }
};

// 1. Accounts & Payments
$pillar_accounts = array(
    'id' => 'accounts',
    'num' => '01',
    'title' => 'Accounts & Payments',
    'subtitle' => 'Central personal wallet, dedicated virtual banks, local & cross-border transfers, receipts and live FX.',
    'icon' => 'wallet',
    'items' => array(),
    '_cat_title' => 'Accounts & Payments',
);
$add_feature($pillar_accounts, 'Personal wallet and account dashboard', 'Live multi-balance console, quick funding controls and daily wallet overview.', '/web/Dashboard.php', null, 'Dashboard');
$add_feature($pillar_accounts, 'Wallet funding and payment receipts', 'Top up with instant payment receipts ready to view or share anytime.', '/web/Fund.php', null, 'Receipts');

$local_bank_on = $t6_enabled('bank_transfer');
$local_wallet_on = $t6_enabled('share_fund');
if ($local_bank_on || $local_wallet_on) {
    $local_href = $local_bank_on ? '/services/bank-transfer.php' : '/services/share-fund.php';
    $local_badge = ($local_bank_on && $local_wallet_on) ? 'Bank + Wallet' : ($local_bank_on ? 'Bank' : 'Wallet');
    $pillar_accounts['items'][] = array(
        'title' => 'Local transfers and wallet-to-wallet transfers',
        'desc' => 'Send to local bank accounts or transfer instantly between personal wallets.',
        'href' => $local_href,
        'badge' => $local_badge,
    );
} else {
    $coming_soon_items[] = array(
        'title' => 'Local transfers and wallet-to-wallet transfers',
        'category' => 'Accounts & Payments',
        'desc' => 'Send funds to local banks or peer wallets directly from your balance.',
        'href' => '/services/bank-transfer.php',
        'reason' => 'Coming soon · Paused in Service Control',
    );
}
$add_feature($pillar_accounts, 'International transfer requests', 'Submit cross-border remittance requests with transparent destination FX.', '/services/international-transfer.php', 'intl_transfer', 'Cross-border');
$add_feature($pillar_accounts, 'Virtual bank accounts', 'Receive bank transfers straight into your wallet via dedicated virtual accounts.', '/web/VirtualBanks.php', 'virtual_bank_display', 'Virtual IBAN/NUBAN');
$add_feature($pillar_accounts, 'Transaction history and payment tracking', 'Inspect full order logs, live delivery statuses and verifiable payment references.', '/web/Transactions.php', null, 'Ledger');
$add_feature($pillar_accounts, 'Live currency conversion and indicative FX rates', 'Track indicative NGN, USD, GBP and EUR quotes and convert balances smoothly.', '/MultiCurrencyWallet.php', 'currency_conversion', 'FX Live');
$personal_pillars[] = $pillar_accounts;

// 2. Everyday Services
$pillar_everyday = array(
    'id' => 'everyday',
    'num' => '02',
    'title' => 'Everyday Services',
    'subtitle' => 'Telecom airtime & data, DisCo electricity tokens, cable TV, betting, exam PINs, travel eSIM and tuition.',
    'icon' => 'spark',
    'items' => array(),
    '_cat_title' => 'Everyday Services',
);
$add_feature($pillar_everyday, 'Airtime purchase', 'Instant recharge across MTN, Airtel, Glo and 9mobile networks.', '/services/airtime.php', 'airtime', 'Instant');
$add_feature($pillar_everyday, 'Mobile data purchase', 'Affordable SME, corporate gifting and direct mobile data bundles.', '/services/data.php', 'data', 'Bundles');
$add_feature($pillar_everyday, 'Electricity bill payments', 'Prepaid meter tokens and postpaid settlement for supported DisCos.', '/services/electricity.php', 'electric', 'Tokens');
$add_feature($pillar_everyday, 'Cable TV subscriptions', 'Automatic DStv, GOtv, StarTimes and Showmax package renewals.', '/services/cable-tv.php', 'cable', 'TV');
$add_feature($pillar_everyday, 'Betting account funding', 'Verified instant top-ups for supported sports and gaming wallets.', '/services/betting.php', 'betting', 'Top-up');
$add_feature($pillar_everyday, 'Examination PINs', 'WAEC, NECO and JAMB result-checker and registration e-PINs.', '/services/exam-pins.php', 'exam', 'PINs');
$add_feature($pillar_everyday, 'Travel eSIM services', 'Activate international travel eSIM data plans across global destinations.', '/services/esim.php', 'esim', 'eSIM');
$add_feature($pillar_everyday, 'Tuition payment requests', 'Settle institutional tuition and accommodation requests in local or foreign currency.', '/Tuition.php', 'tuition', 'Tuition');
$personal_pillars[] = $pillar_everyday;

// 3. Cards & Digital Assets
$pillar_cards = array(
    'id' => 'cards-assets',
    'num' => '03',
    'title' => 'Cards & Digital Assets',
    'subtitle' => 'Virtual USD cards for global checkout, digital gift cards and stablecoin asset services.',
    'icon' => 'card',
    'items' => array(),
    '_cat_title' => 'Cards & Digital Assets',
);
$add_feature($pillar_cards, 'Virtual dollar cards', 'Issue, fund and freeze virtual USD cards for subscriptions and global merchants.', '/services/virtual-cards.php', 'virtual_card', 'USD Card');
$add_feature($pillar_cards, 'Gift card services', 'Buy and redeem popular global retail and entertainment gift cards.', '/services/gift-cards.php', 'gift_card', 'Gift Cards');
$add_feature($pillar_cards, 'Crypto Buy/Sell and stablecoin services', 'Buy, sell and manage stablecoins and supported digital assets.', '/services/crypto.php', 'crypto_hub', 'Assets', 'Coming soon · Pending replacement integration');
$personal_pillars[] = $pillar_cards;

// 4. Rewards & Tools
$pillar_rewards = array(
    'id' => 'rewards-tools',
    'num' => '04',
    'title' => 'Rewards & Tools',
    'subtitle' => 'Referral commissions, loyalty coins, smart fee calculator and AI budgeting assistant.',
    'icon' => 'star',
    'items' => array(),
    '_cat_title' => 'Rewards & Tools',
);
$add_feature($pillar_rewards, 'Referral programme', 'Share your personal invite link and earn ongoing rewards as friends transact.', '/ReferAndEarn.php', 'referral', 'Referrals');
$add_feature($pillar_rewards, 'Loyalty points and rewards', 'Accumulate reward points on transactions and convert coins to wallet credit.', '/services/CoinConversion.php', 'vtu_coins', 'Loyalty');
$add_feature($pillar_rewards, 'Transaction calculator', 'Preview charges, commissions and net settlement amounts before confirming.', '/web/TransactionCalculator.php', null, 'Calculator');
$add_feature($pillar_rewards, 'AI assistant and budgeting tools', 'Plan recurring bills, track spend trends and get instant AI guidance.', '/web/AI-Assistant.php', 'ai_suite', 'AI Tools');
$personal_pillars[] = $pillar_rewards;

// 5. Security & Support
$pillar_security = array(
    'id' => 'security-support',
    'num' => '05',
    'title' => 'Security & Support',
    'subtitle' => 'Tiered KYC, transaction PINs, Google Authenticator & email OTP, account recovery and dispute support.',
    'icon' => 'shield',
    'items' => array(),
    '_cat_title' => 'Security & Support',
);
$add_feature($pillar_security, 'Identity verification—KYC', 'Complete KYC verification to safeguard your account and raise wallet limits.', '/web/KYCVerification.php', null, 'KYC');
$add_feature($pillar_security, 'Security PIN', 'Require your dedicated Security PIN before approving transfers and purchases.', '/web/SecurityPIN.php', null, 'PIN');
$add_feature($pillar_security, 'Google Authenticator and email OTP options', 'Protect sign-ins with TOTP authenticator apps or verified email one-time codes.', '/web/SecuritySetup.php', null, '2FA');
if ($t6_enabled('password_reset')) {
    $pillar_security['items'][] = array(
        'title' => 'Account recovery and security settings',
        'desc' => 'Guided password recovery, active session controls and security preferences.',
        'href' => '/web/PasswordRecovery.php',
        'badge' => 'Recovery',
    );
} else {
    $pillar_security['items'][] = array(
        'title' => 'Security settings and session controls',
        'desc' => 'Review account security controls and active sessions from your dashboard.',
        'href' => '/web/AccountSettings.php',
        'badge' => 'Console',
    );
    $coming_soon_items[] = array(
        'title' => 'Self-service password reset recovery',
        'category' => 'Security & Support',
        'desc' => 'Automated self-service account password recovery.',
        'href' => '/web/PasswordRecovery.php',
        'reason' => 'Coming soon · Paused in Service Control',
    );
}
$add_feature($pillar_security, 'Customer support and dispute assistance', 'Open support tickets, share payment receipts and resolve disputes quickly.', '/Contact.php', null, 'Support');
$personal_pillars[] = $pillar_security;

$active_feature_count = 0;
foreach ($personal_pillars as $p) {
    $active_feature_count += count($p['items']);
}

$js_config = array(
    'siteName' => $site_title,
    'appUrl' => $ctx['app_url'],
    'downloadPageUrl' => '/DownloadApp.php',
    'businessUrl' => '/Business.php',
    'personalUrl' => '/',
    'registerUrl' => '/web/Register.php',
    'loginUrl' => '/web/Login.php',
    'rates' => $ctx['rates'],
);
?><!DOCTYPE html>
<html lang="en" data-world="personal" style="<?php echo bc_meridian_e($personal_vars); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php seo_render_head_tags($site_details, $vendor_account_details); ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --brand: <?php echo bc_meridian_e($brand_colour); ?>;
            --brand-rgb: <?php echo bc_meridian_e($brand_rgb); ?>;
            --brand-deep: <?php echo bc_meridian_e($plate_accent_deep); ?>;
            --brand-tint: <?php echo bc_meridian_e($plate_accent_tint); ?>;
            --brand-fg: <?php echo bc_meridian_e($plate_light['on_primary']); ?>;
            --brand-ink: <?php echo bc_meridian_e($plate_light['primary_text']); ?>;
            --brand-dark-fg: <?php echo bc_meridian_e($plate_dark['primary_text']); ?>;
            --brand-dark-on: <?php echo bc_meridian_e($plate_dark['on_primary']); ?>;

            --ink: #0B1220;
            --muted: #526075;
            --line: rgba(15, 23, 42, 0.10);
            --surface: #F7F9FC;
            --glass-light: rgba(255, 255, 255, 0.78);
            --glass-light-2: rgba(255, 255, 255, 0.56);
            --ink-band: #070C17;
            --ink-band-2: #0D1526;
            --glass-dark: rgba(17, 25, 45, 0.66);
            --glass-dark-2: rgba(255, 255, 255, 0.06);
            --rim-dark: rgba(255, 255, 255, 0.14);
            --radius: 20px;
            --ease: cubic-bezier(0.22, 1, 0.36, 1);
        }
        *, *::before, *::after { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            margin: 0;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--surface);
            color: var(--ink);
            line-height: 1.6;
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
        }
        a { color: inherit; text-decoration: none; }
        button { font: inherit; color: inherit; }
        img { max-width: 100%; display: block; }
        .pc-wrap { width: 100%; max-width: 1200px; margin: 0 auto; padding: 0 22px; }

        /* ── Meridian-1 Parity Glass Header (pc-header) ──────────────── */
        .pc-header {
            position: sticky;
            top: 0;
            z-index: 60;
            background: rgba(7, 12, 23, 0.80);
            backdrop-filter: blur(22px) saturate(185%);
            -webkit-backdrop-filter: blur(22px) saturate(185%);
            border-bottom: 1px solid rgba(255, 255, 255, 0.12);
            color: #F8FAFC;
        }
        .pc-header .nav-in {
            max-width: 1200px;
            margin: 0 auto;
            padding: 10px 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
        }
        .logo {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-family: 'Space Grotesk', sans-serif;
            font-weight: 700;
            font-size: 16px;
            letter-spacing: -0.02em;
            color: #F8FAFC;
            white-space: nowrap;
            flex: 0 0 auto;
        }
        .m-brand-mark {
            width: 36px;
            height: 36px;
            border-radius: 11px;
            display: inline-grid;
            place-items: center;
            background: linear-gradient(135deg, var(--brand), var(--brand-deep));
            color: var(--brand-fg);
            font-family: 'Space Grotesk', sans-serif;
            font-weight: 800;
            font-size: 16px;
            border: 1px solid rgba(255, 255, 255, 0.28);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.36), 0 8px 18px rgba(var(--brand-rgb), 0.34);
        }
        .m-logo-img {
            width: 36px;
            height: 36px;
            border-radius: 11px;
            object-fit: cover;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        .pswitch {
            position: relative;
            display: inline-grid;
            grid-template-columns: repeat(2, 1fr);
            padding: 3px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.15);
            min-width: 182px;
            flex: 0 0 auto;
        }
        .pthumb {
            position: absolute;
            top: 3px; bottom: 3px; left: 3px;
            border-radius: 999px;
            background: var(--brand);
            box-shadow: 0 4px 14px rgba(var(--brand-rgb), 0.38);
            transition: transform .28s var(--ease);
            z-index: 1;
        }
        :root[data-world="business"] .pthumb { transform: translateX(100%); }
        .pswitch button {
            position: relative;
            z-index: 2;
            border: 0;
            background: transparent;
            padding: 7px 13px;
            border-radius: 999px;
            font-size: 12.5px;
            font-weight: 700;
            cursor: pointer;
            color: #CBD5E1;
            transition: color .2s ease;
        }
        .pswitch button[aria-checked="true"] { color: var(--brand-fg); }

        .links {
            display: flex;
            align-items: center;
            gap: 2px;
        }
        .links a {
            padding: 8px 11px;
            border-radius: 10px;
            font-size: 13.5px;
            font-weight: 600;
            color: #CBD5E1;
            transition: background .18s ease, color .18s ease;
            white-space: nowrap;
        }
        .links a:hover, .links a.is-active, .links a[aria-current="page"] {
            color: #FFFFFF;
            background: rgba(var(--brand-rgb), 0.28);
        }
        .nav-r { display: flex; align-items: center; gap: 8px; flex: 0 0 auto; }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 9px 16px;
            border-radius: 11px;
            font-size: 13.5px;
            font-weight: 700;
            cursor: pointer;
            border: 1px solid transparent;
            transition: transform .18s var(--ease), background .18s ease, box-shadow .18s ease;
            white-space: nowrap;
        }
        .btn:hover { transform: translateY(-1px); }
        .btn.primary {
            background: linear-gradient(135deg, var(--brand), var(--brand-deep));
            color: var(--brand-fg);
            box-shadow: 0 8px 22px rgba(var(--brand-rgb), 0.34);
        }
        .btn.ghost {
            background: rgba(255, 255, 255, 0.08);
            border-color: rgba(255, 255, 255, 0.16);
            color: #F8FAFC;
        }
        .btn.ghost:hover { background: rgba(255, 255, 255, 0.14); }
        .icon-btn {
            width: 38px;
            height: 38px;
            border-radius: 11px;
            border: 1px solid rgba(255, 255, 255, 0.16);
            background: rgba(255, 255, 255, 0.08);
            color: #F8FAFC;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }
        .icon-btn svg { width: 18px; height: 18px; }
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
            .pswitch { min-width: 150px; }
            .pswitch button { padding: 6px 9px; font-size: 12px; }
        }

        /* Mobile & Get-App Sheets */
        #sheet[hidden], #getSheet[hidden] { display: none !important; }
        .sheet {
            position: fixed;
            inset: 0;
            z-index: 90;
            background: rgba(4, 7, 15, 0.72);
            backdrop-filter: blur(12px);
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 84px 16px 24px;
            overflow: auto;
        }
        .sheet > .glass {
            width: min(500px, 100%);
            background: rgba(13, 21, 38, 0.94);
            backdrop-filter: blur(26px);
            border: 1px solid rgba(255, 255, 255, 0.16);
            border-radius: 22px;
            padding: 18px;
            color: #F8FAFC;
        }
        .sheet .mlinks { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; margin: 14px 0; }
        .sheet .mlinks a {
            padding: 11px 12px;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.10);
            font-size: 13.5px;
            font-weight: 600;
            color: #E2E8F0;
        }
        .sheet .mlinks a.is-active, .sheet .mlinks a:hover {
            background: rgba(var(--brand-rgb), 0.25);
            color: #FFFFFF;
        }
        .sheet .row { display: flex; gap: 10px; }
        @media (max-width: 460px) {
            .sheet .mlinks { grid-template-columns: 1fr; }
            .sheet .row { flex-direction: column; }
        }

        /* ── Hero: Smoked Obsidian Glass Editorial Showcase ──────────── */
        .pk-hero {
            position: relative;
            background:
                radial-gradient(960px 540px at 14% 0%, rgba(var(--brand-rgb), 0.32), transparent 60%),
                radial-gradient(840px 500px at 90% 18%, rgba(var(--brand-rgb), 0.18), transparent 58%),
                linear-gradient(180deg, var(--ink-band) 0%, var(--ink-band-2) 100%);
            color: #F8FAFC;
            padding: 68px 0 64px;
            overflow: hidden;
        }
        .pk-hero-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.08fr) minmax(0, 0.92fr);
            gap: 44px;
            align-items: center;
        }
        @media (max-width: 940px) {
            .pk-hero-grid { grid-template-columns: 1fr; gap: 32px; }
        }
        .pk-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 7px 14px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.16);
            backdrop-filter: blur(14px);
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 18px;
        }
        .pk-badge i {
            width: 8px; height: 8px; border-radius: 50%;
            background: #34D399;
            box-shadow: 0 0 0 4px rgba(52, 211, 153, 0.20);
        }
        .pk-h1 {
            margin: 0 0 18px;
            font-family: 'Space Grotesk', sans-serif;
            font-size: clamp(38px, 5.2vw, 62px);
            font-weight: 700;
            line-height: 1.03;
            letter-spacing: -0.035em;
        }
        .pk-h1-outline {
            display: block;
            color: transparent;
            -webkit-text-stroke: 1.5px rgba(255, 255, 255, 0.86);
            text-shadow: 0 10px 30px rgba(var(--brand-rgb), 0.35);
        }
        .pk-hero p {
            margin: 0 0 24px;
            font-size: 16.5px;
            color: #CBD5E1;
            max-width: 54ch;
        }
        .pk-hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
            margin-bottom: 24px;
        }
        .pk-proof {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
        }
        @media (max-width: 560px) { .pk-proof { grid-template-columns: 1fr; } }
        .pk-proof-card {
            padding: 13px 14px;
            border-radius: 14px;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.12);
            backdrop-filter: blur(14px);
        }
        .pk-proof-card strong { display: block; font-size: 17px; font-weight: 800; }
        .pk-proof-card span { font-size: 12px; color: #94A3B8; }

        /* Decorative Glass Device & Card Stack */
        .pk-stage {
            position: relative;
            padding: 18px;
            border-radius: 26px;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.16);
            backdrop-filter: blur(24px) saturate(180%);
            box-shadow: 0 28px 70px rgba(2, 6, 23, 0.62), inset 0 1px 0 rgba(255, 255, 255, 0.22);
        }
        .pk-phone {
            border-radius: 20px;
            padding: 20px;
            background: linear-gradient(155deg, rgba(255, 255, 255, 0.11), rgba(255, 255, 255, 0.03));
            border: 1px solid rgba(255, 255, 255, 0.14);
        }
        .pk-phone-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            font-size: 12px;
            color: #CBD5E1;
        }
        .pk-bal-box {
            padding: 16px;
            border-radius: 16px;
            background: linear-gradient(135deg, rgba(var(--brand-rgb), 0.36), rgba(255, 255, 255, 0.06));
            border: 1px solid rgba(255, 255, 255, 0.18);
            margin-bottom: 14px;
        }
        .pk-bal-box small { font-size: 11.5px; color: #E2E8F0; display: block; }
        .pk-bal-box strong { font-family: 'Space Grotesk', sans-serif; font-size: 28px; }
        .pk-mini-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
        }
        .pk-mini-pill {
            padding: 11px 12px;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.10);
            font-size: 12.5px;
        }
        .pk-mini-pill b { display: block; font-size: 13px; margin-bottom: 2px; }
        .pk-mini-pill span { color: #94A3B8; font-size: 11.5px; }

        /* ── Scrolling Glass Category Marquee ────────────────────────── */
        .pk-marquee {
            background: rgba(11, 18, 32, 0.94);
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            border-bottom: 1px solid rgba(255, 255, 255, 0.10);
            padding: 14px 0;
            overflow: hidden;
        }
        .pk-marquee__track {
            display: flex;
            gap: 14px;
            width: max-content;
            animation: pkScroll 30s linear infinite;
        }
        .pk-marquee:hover .pk-marquee__track { animation-play-state: paused; }
        .pk-chip {
            padding: 8px 16px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.07);
            border: 1px solid rgba(255, 255, 255, 0.14);
            color: #E2E8F0;
            font-size: 12.5px;
            font-weight: 700;
            white-space: nowrap;
        }
        @keyframes pkScroll {
            0% { transform: translateX(0); }
            100% { transform: translateX(-50%); }
        }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation-duration: 0.01ms !important; transition-duration: 0.01ms !important; }
            .pk-marquee__track { animation: none !important; flex-wrap: wrap; justify-content: center; width: auto; }
        }

        /* ── Light Frosted Sections ──────────────────────────────────── */
        .pc-section {
            padding: 68px 0;
            position: relative;
        }
        .pc-section--frost {
            background:
                radial-gradient(900px 460px at 10% 10%, rgba(var(--brand-rgb), 0.09), transparent 60%),
                radial-gradient(840px 460px at 90% 85%, rgba(var(--brand-rgb), 0.06), transparent 60%),
                var(--surface);
        }
        .pc-section--ink {
            background:
                radial-gradient(880px 520px at 15% 15%, rgba(var(--brand-rgb), 0.25), transparent 60%),
                linear-gradient(180deg, var(--ink-band) 0%, var(--ink-band-2) 100%);
            color: #F8FAFC;
        }
        .pc-sec-head {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: flex-end;
            gap: 16px;
            margin-bottom: 28px;
        }
        .pc-eyebrow {
            display: inline-block;
            font-size: 11.5px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .11em;
            color: var(--brand-ink);
            margin-bottom: 6px;
        }
        .pc-section--ink .pc-eyebrow { color: var(--brand-dark-fg); }
        .pc-sec-head h2 {
            margin: 0 0 6px;
            font-family: 'Space Grotesk', sans-serif;
            font-size: clamp(27px, 3.4vw, 38px);
            letter-spacing: -0.03em;
            line-height: 1.1;
        }
        .pc-sec-head p { margin: 0; color: var(--muted); max-width: 60ch; }
        .pc-section--ink .pc-sec-head p { color: #CBD5E1; }

        /* ── Personal Features: 5-Pillar Frosted Glass Showcase Deck ─── */
        .t6-deck {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        .t6-pillar {
            padding: 24px;
            border-radius: 22px;
            background: var(--glass-light);
            backdrop-filter: blur(22px) saturate(175%);
            -webkit-backdrop-filter: blur(22px) saturate(175%);
            border: 1px solid var(--line);
            box-shadow: 0 18px 46px rgba(15, 23, 42, 0.06), inset 0 1px 0 rgba(255, 255, 255, 0.9);
        }
        .t6-pillar-bar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding-bottom: 16px;
            margin-bottom: 16px;
            border-bottom: 1px solid var(--line);
        }
        .t6-pillar-title {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .t6-pillar-num {
            width: 42px;
            height: 42px;
            border-radius: 13px;
            display: inline-grid;
            place-items: center;
            background: rgba(var(--brand-rgb), 0.12);
            color: var(--brand-ink);
            border: 1px solid rgba(var(--brand-rgb), 0.25);
            flex: 0 0 auto;
        }
        .t6-pillar-title h3 { margin: 0 0 3px; font-size: 20px; font-weight: 800; }
        .t6-pillar-title p { margin: 0; font-size: 13.5px; color: var(--muted); }
        .t6-pillar-count {
            font-size: 12px;
            font-weight: 800;
            padding: 5px 11px;
            border-radius: 999px;
            background: rgba(var(--brand-rgb), 0.10);
            color: var(--brand-ink);
            white-space: nowrap;
        }
        .t6-items-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 12px;
        }
        .t6-item {
            padding: 15px 16px;
            border-radius: 15px;
            background: var(--glass-light-2);
            border: 1px solid var(--line);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            gap: 10px;
            transition: transform .18s var(--ease), border-color .18s ease, background .18s ease;
        }
        .t6-item:hover {
            transform: translateY(-2px);
            background: rgba(255, 255, 255, 0.92);
            border-color: rgba(var(--brand-rgb), 0.38);
        }
        .t6-item-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 8px;
        }
        .t6-item-top strong { font-size: 14.5px; font-weight: 700; line-height: 1.32; }
        .t6-badge {
            font-size: 11px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 999px;
            background: rgba(var(--brand-rgb), 0.10);
            color: var(--brand-ink);
            white-space: nowrap;
        }
        .t6-item p { margin: 6px 0 0; font-size: 12.8px; color: var(--muted); line-height: 1.48; }
        .t6-item-link { font-size: 12px; font-weight: 700; color: var(--brand-ink); }

        .t6-cs-band {
            margin-top: 24px;
            padding: 22px;
            border-radius: 20px;
            background: rgba(255, 255, 255, 0.65);
            border: 1px dashed rgba(var(--brand-rgb), 0.40);
            backdrop-filter: blur(18px);
        }
        .t6-cs-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 12px;
            margin-top: 14px;
        }
        .t6-cs-card {
            padding: 14px 16px;
            border-radius: 14px;
            background: rgba(255, 255, 255, 0.85);
            border: 1px solid var(--line);
        }
        .t6-cs-tag {
            display: inline-block;
            padding: 3px 9px;
            border-radius: 999px;
            font-size: 10.5px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .05em;
            background: rgba(217, 119, 6, 0.14);
            color: #92400E;
            margin-bottom: 6px;
        }
        .t6-cs-card h4 { margin: 0 0 4px; font-size: 14px; font-weight: 700; }
        .t6-cs-card p { margin: 0; font-size: 12.5px; color: var(--muted); }

        /* ── Plate Account Partition Panels (Personal / Business) ─────── */
        .pc-tabs {
            display: inline-flex;
            gap: 6px;
            padding: 5px;
            border-radius: 999px;
            background: rgba(15, 23, 42, 0.06);
            border: 1px solid var(--line);
        }
        .pc-tab {
            border: 0;
            background: transparent;
            padding: 9px 18px;
            border-radius: 999px;
            font-size: 13.5px;
            font-weight: 700;
            cursor: pointer;
            color: var(--muted);
        }
        .pc-tab[aria-selected="true"] {
            background: var(--brand);
            color: var(--brand-fg);
            box-shadow: 0 6px 16px rgba(var(--brand-rgb), 0.25);
        }
        .pc-panel { margin-top: 22px; }
        .pc-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 14px;
        }
        .pc-card {
            padding: 20px;
            border-radius: 18px;
            background: var(--glass-light);
            backdrop-filter: blur(18px);
            border: 1px solid var(--line);
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.05);
        }
        .pc-card--soon {
            border-style: dashed;
            background: rgba(255, 255, 255, 0.50);
            opacity: .92;
        }
        .pc-card__icon {
            width: 38px; height: 38px;
            border-radius: 11px;
            display: inline-grid; place-items: center;
            background: rgba(var(--brand-rgb), 0.12);
            color: var(--brand-ink);
            margin-bottom: 12px;
        }
        .pc-card__title { margin: 0 0 6px; font-size: 16px; font-weight: 800; }
        .pc-card__copy { margin: 0; font-size: 13.5px; color: var(--muted); }
        .pc-card__tag {
            display: inline-block;
            margin-top: 10px;
            font-size: 11px;
            font-weight: 800;
            padding: 3px 9px;
            border-radius: 999px;
            background: rgba(217, 119, 6, 0.14);
            color: #92400E;
        }

        /* ── Alternating Feature Split Rows (pk-split / pk-split--rev) ── */
        .pk-split {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 36px;
            align-items: center;
        }
        @media (max-width: 860px) { .pk-split { grid-template-columns: 1fr; } }
        .pk-split--rev .pk-split__visual { order: -1; }
        @media (max-width: 860px) { .pk-split--rev .pk-split__visual { order: 0; } }
        .pk-split__copy h2 {
            margin: 0 0 12px;
            font-family: 'Space Grotesk', sans-serif;
            font-size: clamp(26px, 3.2vw, 36px);
            letter-spacing: -0.03em;
            line-height: 1.12;
        }
        .pk-split__copy p { margin: 0 0 18px; color: var(--muted); font-size: 15.5px; }
        .pk-stats-row { display: flex; flex-wrap: wrap; gap: 18px; margin-bottom: 20px; }
        .pk-stat__value { display: block; font-family: 'Space Grotesk', sans-serif; font-size: 24px; font-weight: 700; color: var(--brand-ink); }
        .pk-stat__label { font-size: 12.5px; color: var(--muted); }
        .pc-cta {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 11px 20px;
            border-radius: 12px;
            background: var(--brand);
            color: var(--brand-fg);
            font-weight: 700;
            font-size: 14px;
            box-shadow: 0 10px 24px rgba(var(--brand-rgb), 0.26);
        }
        .pk-split__visual {
            padding: 24px;
            border-radius: 22px;
            background: var(--glass-light);
            backdrop-filter: blur(22px);
            border: 1px solid var(--line);
            box-shadow: 0 20px 50px rgba(15, 23, 42, 0.07);
        }
        .pk-acct { display: flex; flex-direction: column; gap: 10px; }
        .pk-acct__row {
            padding: 13px 15px;
            border-radius: 13px;
            background: rgba(255, 255, 255, 0.72);
            border: 1px solid var(--line);
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 14px;
        }

        /* ── Dark Analytics & Security Plate Band (pc-section--ink) ───── */
        .pk-ink-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
            margin-top: 24px;
        }
        @media (max-width: 960px) { .pk-ink-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 560px) { .pk-ink-grid { grid-template-columns: 1fr; } }
        .pk-ink-card {
            padding: 20px;
            border-radius: 18px;
            background: var(--glass-dark);
            backdrop-filter: blur(20px);
            border: 1px solid var(--rim-dark);
        }
        .pk-ink-card h3 { margin: 0 0 6px; font-size: 16px; }
        .pk-ink-card p { margin: 0 0 12px; font-size: 13.5px; color: #CBD5E1; }
        .pk-ink-card a { font-size: 13px; font-weight: 700; color: var(--brand-dark-fg); }

        /* ── Multi-Column Glass Footer (pc-footer__grid) ─────────────── */
        .pc-footer {
            background: #060A14;
            color: #CBD5E1;
            padding: 52px 0 40px;
            border-top: 1px solid rgba(255, 255, 255, 0.10);
        }
        .pc-footer__grid {
            display: grid;
            grid-template-columns: 1.5fr repeat(3, minmax(0, 1fr));
            gap: 28px;
            margin-bottom: 28px;
        }
        @media (max-width: 820px) { .pc-footer__grid { grid-template-columns: 1fr 1fr; } }
        @media (max-width: 520px) { .pc-footer__grid { grid-template-columns: 1fr; } }
        .pc-footer__grid h4 { margin: 0 0 12px; font-size: 13px; text-transform: uppercase; letter-spacing: .08em; color: #FFFFFF; }
        .pc-footer__grid a { display: block; margin-bottom: 8px; font-size: 13.5px; color: #94A3B8; }
        .pc-footer__grid a:hover { color: #FFFFFF; }
        .pc-footer__bar {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: 12px;
            padding: 18px 0;
            border-top: 1px solid rgba(255, 255, 255, 0.10);
            border-bottom: 1px solid rgba(255, 255, 255, 0.10);
            margin-bottom: 22px;
            font-size: 13px;
            color: #94A3B8;
        }
        .bc-public-directory {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 14px;
            padding: 20px;
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.10);
            margin-bottom: 18px;
        }
        .bc-public-directory h3 { margin: 0 0 8px; font-size: 11.5px; text-transform: uppercase; letter-spacing: .08em; color: var(--brand-dark-fg); }
        .bc-public-directory ul { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px; }
        .bc-public-directory a { font-size: 13px; color: #CBD5E1; }
        .bc-public-directory a:hover { color: #FFFFFF; text-decoration: underline; }
        .bc-app-badge {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            padding: 10px 16px;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.10);
            border: 1px solid rgba(255, 255, 255, 0.22);
            color: #FFFFFF;
            font-size: 13.5px;
            font-weight: 700;
        }
        .bc-app-badge svg { width: 18px; height: 18px; }
    </style>
</head>
<body>
    <div class="pc-header"><?php echo bc_meridian_header_and_menu($ctx, 'personal', '/'); ?></div>

    <!-- Hero: Obsidian Smoked Glass Plate -->
    <section class="pk-hero">
        <div class="pc-wrap pk-hero-grid">
            <div>
                <span class="pk-badge"><i></i> <?php echo $short_e; ?> · Smoked Glass Plate Edition</span>
                <h1 class="pk-h1">
                    Everyday payments
                    <span class="pk-h1-outline">crafted in glass</span>
                </h1>
                <p><?php echo bc_meridian_e($ctx['site_desc']); ?> Built on a unified wallet with Service Control synced personal features and instant global cards.</p>
                <div class="pk-hero-actions">
                    <?php if ($plate_dash !== ''): ?>
                        <a class="btn primary" href="<?php echo bc_meridian_e($plate_dash); ?>">Open Dashboard</a>
                    <?php else: ?>
                        <a class="btn primary" href="/web/Register.php">Open an account</a>
                        <a class="btn ghost" href="/web/Login.php">Sign in</a>
                    <?php endif; ?>
                    <?php if ($plate_app_badge !== ''): ?>
                        <?php echo $plate_app_badge; ?>
                    <?php endif; ?>
                </div>
                <div class="pk-proof">
                    <div class="pk-proof-card"><strong><?php echo (int)$active_feature_count; ?></strong><span>Active personal features</span></div>
                    <div class="pk-proof-card"><strong>5 Pillars</strong><span>Service Control synced</span></div>
                    <div class="pk-proof-card"><strong>KYC + 2FA</strong><span>PIN &amp; OTP protected</span></div>
                </div>
            </div>

            <div class="pk-stage" aria-hidden="true">
                <?php if ($header_image_url !== ''): ?>
                    <div style="border-radius:16px;overflow:hidden;margin-bottom:14px;border:1px solid rgba(255,255,255,.15)">
                        <img src="<?php echo bc_meridian_e($header_image_url); ?>" alt="<?php echo $site_e; ?> showcase" style="width:100%;height:140px;object-fit:cover">
                    </div>
                <?php endif; ?>
                <div class="pk-phone">
                    <div class="pk-phone-top">
                        <span><?php echo $short_e; ?> Wallet Plate</span>
                        <span>Live Sync</span>
                    </div>
                    <div class="pk-bal-box">
                        <small>Unified Personal &amp; Multi-Currency Hub</small>
                        <strong>Accounts · Cards · Bills</strong>
                    </div>
                    <div class="pk-mini-grid">
                        <div class="pk-mini-pill"><b>Virtual Banks</b><span>Instant wallet funding</span></div>
                        <div class="pk-mini-pill"><b>USD Cards</b><span>Global online checkout</span></div>
                        <div class="pk-mini-pill"><b>Everyday VTU</b><span>Airtime, Data &amp; Power</span></div>
                        <div class="pk-mini-pill"><b>Security 2FA</b><span>PIN, TOTP &amp; KYC</span></div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Scrolling Category Marquee -->
    <div class="pk-marquee" aria-hidden="true">
        <div class="pk-marquee__track">
            <span class="pk-chip">Personal Wallet &amp; Receipts</span>
            <span class="pk-chip">Virtual Bank Accounts</span>
            <span class="pk-chip">Local &amp; International Transfers</span>
            <span class="pk-chip">Live FX Rates</span>
            <span class="pk-chip">Airtime &amp; Mobile Data</span>
            <span class="pk-chip">Electricity &amp; Cable TV</span>
            <span class="pk-chip">Exam PINs &amp; Betting</span>
            <span class="pk-chip">Travel eSIM &amp; Tuition</span>
            <span class="pk-chip">Virtual Dollar Cards &amp; Gift Cards</span>
            <span class="pk-chip">Referrals, Loyalty &amp; AI Tools</span>
            <span class="pk-chip">KYC, Security PIN &amp; 2FA</span>
            <span class="pk-chip">Personal Wallet &amp; Receipts</span>
            <span class="pk-chip">Virtual Bank Accounts</span>
            <span class="pk-chip">Local &amp; International Transfers</span>
            <span class="pk-chip">Live FX Rates</span>
            <span class="pk-chip">Airtime &amp; Mobile Data</span>
        </div>
    </div>

    <!-- Personal Features: 5-Pillar Service Control Synced Deck -->
    <section class="pc-section pc-section--frost" id="personal-features">
        <div class="pc-wrap">
            <div class="pc-sec-head">
                <div>
                    <span class="pc-eyebrow">Service Control Center Synced</span>
                    <h2>Personal Features</h2>
                    <p>Every feature below checks live Site Owner Service Control settings. Active capabilities launch directly; paused or pending services are grouped cleanly under Coming soon.</p>
                </div>
                <a class="pc-cta" href="/Services.php">Browse all services</a>
            </div>

            <div class="t6-deck">
                <?php foreach ($personal_pillars as $pl): ?>
                <article class="t6-pillar">
                    <div class="t6-pillar-bar">
                        <div class="t6-pillar-title">
                            <span class="t6-pillar-num"><?php echo bc_plate_icon($pl['icon'], 20); ?></span>
                            <div>
                                <h3><?php echo bc_meridian_e($pl['title']); ?></h3>
                                <p><?php echo bc_meridian_e($pl['subtitle']); ?></p>
                            </div>
                        </div>
                        <span class="t6-pillar-count"><?php echo count($pl['items']); ?> Active</span>
                    </div>
                    <div class="t6-items-grid">
                        <?php foreach ($pl['items'] as $it): ?>
                        <a class="t6-item" href="<?php echo bc_meridian_e($it['href']); ?>">
                            <div>
                                <div class="t6-item-top">
                                    <strong><?php echo bc_meridian_e($it['title']); ?></strong>
                                    <?php if (!empty($it['badge'])): ?>
                                        <span class="t6-badge"><?php echo bc_meridian_e($it['badge']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <p><?php echo bc_meridian_e($it['desc']); ?></p>
                            </div>
                            <span class="t6-item-link">Launch →</span>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>

            <?php if (!empty($coming_soon_items)): ?>
            <div class="t6-cs-band" id="coming-soon">
                <span class="pc-eyebrow">Coming Soon &amp; Rollout</span>
                <h3 style="margin:0;font-size:20px;font-weight:800">Paused or pending services (<?php echo count($coming_soon_items); ?>)</h3>
                <div class="t6-cs-grid">
                    <?php foreach ($coming_soon_items as $cs): ?>
                    <a class="t6-cs-card" href="<?php echo bc_meridian_e($cs['href']); ?>">
                        <span class="t6-cs-tag"><?php echo bc_meridian_e($cs['reason']); ?></span>
                        <h4><?php echo bc_meridian_e($cs['title']); ?></h4>
                        <p><?php echo bc_meridian_e($cs['category']); ?> · <?php echo bc_meridian_e($cs['desc']); ?></p>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Account Capability Matrix (Server-rendered Personal & Business Partition Panels) -->
    <section class="pc-section" id="services">
        <div class="pc-wrap">
            <div class="pc-sec-head">
                <div>
                    <span class="pc-eyebrow">Account Matrix</span>
                    <h2>Personal &amp; Business capabilities</h2>
                    <p>Switch between Personal and Business service partitions—every card reflects live tenant account gating.</p>
                </div>
                <div class="pc-tabs" role="tablist" aria-label="Account capability panels">
                    <button type="button" class="pc-tab" id="pc-tab-personal" role="tab" aria-selected="true" aria-controls="pc-services-personal">Personal</button>
                    <button type="button" class="pc-tab" id="pc-tab-business" role="tab" aria-selected="false" aria-controls="pc-services-business">Business</button>
                </div>
            </div>

            <div class="pc-panel" id="pc-services-personal" role="tabpanel" aria-labelledby="pc-tab-personal">
                <div class="pc-grid">
                    <?php foreach ((array)($personal_cards['live'] ?? array()) as $c) { bc_plate_card($c, true); } ?>
                    <?php foreach ((array)($personal_cards['soon'] ?? array()) as $c) { bc_plate_card($c, false); } ?>
                </div>
            </div>

            <div class="pc-panel" id="pc-services-business" role="tabpanel" aria-labelledby="pc-tab-business" hidden>
                <div class="pc-grid">
                    <?php foreach ((array)($business_cards['live'] ?? array()) as $c) { bc_plate_card($c, true); } ?>
                    <?php foreach ((array)($business_cards['soon'] ?? array()) as $c) { bc_plate_card($c, false); } ?>
                </div>
            </div>
        </div>
    </section>

    <!-- Alternating Feature Split 1: Cards & Digital Assets -->
    <section class="pc-section pc-section--frost" id="cards">
        <div class="pc-wrap pk-split">
            <div class="pk-split__copy">
                <span class="pc-eyebrow">Cards &amp; Global Spending</span>
                <h2>Virtual dollar cards &amp; gift cards built for speed</h2>
                <p>Issue USD virtual cards for subscriptions and global merchants, track card funding in real time and redeem popular digital gift cards directly from your wallet.</p>
                <div class="pk-stats-row">
                    <?php bc_plate_stat('USD', 'Virtual card currency'); ?>
                    <?php bc_plate_stat('Instant', 'Freeze & fund control'); ?>
                </div>
                <a class="pc-cta" href="/services/virtual-cards.php">Explore virtual cards</a>
            </div>
            <div class="pk-split__visual" aria-hidden="true">
                <div class="pk-acct">
                    <div class="pk-acct__row"><span>Card Type</span><b>Virtual USD</b></div>
                    <div class="pk-acct__row"><span>Funding</span><b>Wallet Balance</b></div>
                    <div class="pk-acct__row"><span>Controls</span><b>Freeze / Unfreeze</b></div>
                    <div class="pk-acct__row"><span>Gift Cards</span><b>Global Brands</b></div>
                </div>
            </div>
        </div>
    </section>

    <!-- Alternating Feature Split 2 (Reversed): Tuition & Everyday Services -->
    <section class="pc-section" id="tuition">
        <div class="pc-wrap pk-split pk-split--rev">
            <div class="pk-split__copy">
                <span class="pc-eyebrow">Everyday &amp; Cross-Border</span>
                <h2>Tuition, travel eSIMs and daily bills in one place</h2>
                <p>Recharge airtime and mobile data, generate electricity tokens, renew cable TV, activate travel eSIMs and submit tuition payment requests without switching apps.</p>
                <div class="pk-stats-row">
                    <?php bc_plate_stat('8+', 'Everyday categories'); ?>
                    <?php bc_plate_stat('190+', 'Travel eSIM regions'); ?>
                </div>
                <a class="pc-cta" href="/Tuition.php">Explore tuition &amp; bills</a>
            </div>
            <div class="pk-split__visual" aria-hidden="true">
                <div class="pk-acct">
                    <div class="pk-acct__row"><span>Telecom</span><b>Airtime &amp; Data</b></div>
                    <div class="pk-acct__row"><span>Utilities</span><b>Electricity &amp; Cable TV</b></div>
                    <div class="pk-acct__row"><span>Education</span><b>Exam PINs &amp; Tuition</b></div>
                    <div class="pk-acct__row"><span>Connectivity</span><b>Travel eSIM</b></div>
                </div>
            </div>
        </div>
    </section>

    <!-- Dark Smoked-Glass Analytics & Security Band -->
    <section class="pc-section pc-section--ink" id="why">
        <div class="pc-wrap" id="control">
            <div class="pc-sec-head">
                <div>
                    <span class="pc-eyebrow">Security &amp; Support</span>
                    <h2>Protected accounts with human dispute support</h2>
                    <p>Every transfer, card order and utility payment is backed by multi-factor verification, transaction PIN enforcement and dedicated dispute resolution.</p>
                </div>
                <a class="btn primary" href="/Security.php">Security Overview</a>
            </div>
            <div class="pk-ink-grid">
                <div class="pk-ink-card">
                    <h3>Identity verification—KYC</h3>
                    <p>Verify identity tiers to protect your profile and unlock expanded wallet &amp; transfer limits.</p>
                    <a href="/web/KYCVerification.php">Verify KYC →</a>
                </div>
                <div class="pk-ink-card">
                    <h3>Security PIN &amp; 2FA</h3>
                    <p>Require a personal Security PIN plus Google Authenticator or email OTP on sensitive actions.</p>
                    <a href="/web/SecuritySetup.php">Configure 2FA →</a>
                </div>
                <div class="pk-ink-card">
                    <h3>Account recovery</h3>
                    <p>Manage recovery controls, active sessions and security preferences anytime.</p>
                    <a href="<?php echo $t6_enabled('password_reset') ? '/web/PasswordRecovery.php' : '/web/AccountSettings.php'; ?>">Recovery settings →</a>
                </div>
                <div class="pk-ink-card">
                    <h3>Customer support</h3>
                    <p>Open tickets, share payment receipts and get human assistance on transaction disputes.</p>
                    <a href="/Contact.php">Contact support →</a>
                </div>
            </div>
        </div>
    </section>

    <!-- Multi-Column Glass Footer -->
    <footer class="pc-footer">
        <div class="pc-wrap">
            <div class="pc-footer__grid">
                <div>
                    <a class="logo" href="/">
                        <?php if ($site_logo): ?>
                            <img class="m-logo-img" src="<?php echo bc_meridian_e($site_logo); ?>" alt="<?php echo $site_e; ?> logo">
                        <?php else: ?>
                            <span class="m-brand-mark" aria-hidden="true"><?php echo bc_meridian_e(strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $site_title) ?: 'F', 0, 1))); ?></span>
                        <?php endif; ?>
                        <span><?php echo $site_e; ?></span>
                    </a>
                    <p style="margin:10px 0 0;font-size:13.5px;color:#94A3B8;max-width:34ch"><?php echo bc_meridian_e($ctx['site_desc']); ?></p>
                </div>
                <div>
                    <h4>Personal</h4>
                    <a href="#personal-features">Personal Features</a>
                    <a href="#services">Account Matrix</a>
                    <a href="#cards">Virtual Cards</a>
                    <a href="#tuition">Tuition &amp; Bills</a>
                </div>
                <div>
                    <h4>Platform</h4>
                    <a href="/Business.php">Business Portal</a>
                    <a href="/Developers.php">Developers &amp; API</a>
                    <a href="#why">Security &amp; KYC</a>
                    <a href="#control">Control Center</a>
                </div>
                <div>
                    <h4>Account</h4>
                    <?php if ($plate_dash !== ''): ?>
                        <a href="<?php echo bc_meridian_e($plate_dash); ?>">Dashboard</a>
                    <?php else: ?>
                        <a href="/web/Login.php">Log in</a>
                        <a href="/web/Register.php">Create account</a>
                    <?php endif; ?>
                    <a href="/Contact.php">Support</a>
                </div>
            </div>

            <div class="pc-footer__bar">
                <span>&copy; <?php echo date('Y'); ?> <?php echo $site_e; ?>. All rights reserved.</span>
                <span>Plate Glass Edition · Service Control Synced</span>
            </div>

            <?php echo bc_public_directory_html(); ?>
            <?php if ($plate_app_badge !== ''): ?>
                <div style="margin-top:14px"><?php echo $plate_app_badge; ?></div>
            <?php endif; ?>
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
        "use strict";
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
                if (tabs[key].tab) tabs[key].tab.setAttribute("aria-selected", isOn ? "true" : "false");
                tabs[key].panels.forEach(function (p) { if (p) p.hidden = !isOn; });
            });
        }
        Object.keys(tabs).forEach(function (key) {
            var tab = tabs[key].tab;
            if (!tab) return;
            tab.addEventListener("click", function () { select(key); });
            tab.addEventListener("keydown", function (e) {
                if (e.key !== "ArrowLeft" && e.key !== "ArrowRight") return;
                var other = key === "personal" ? "business" : "personal";
                e.preventDefault();
                if (tabs[other].tab) tabs[other].tab.focus();
                select(other);
            });
        });
    })();
    </script>
    <?php
    if (function_exists('seo_render_custom_footer')) {
        seo_render_custom_footer($site_details);
    }
    ?>
</body>
</html>

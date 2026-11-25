<?php
/**
 * Shared Meridian-1 resolver: dynamic branding, WCAG glassmorphism/dark tokens,
 * contact/WhatsApp info, app download links, and Service Control Center gating.
 */
if (!function_exists('bc_meridian_e')) {
    function bc_meridian_e($v) {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('bc_meridian_sanitize_whatsapp')) {
    function bc_meridian_sanitize_whatsapp($raw) {
        return preg_replace('/[^0-9]/', '', (string)$raw);
    }
}

if (!function_exists('bc_meridian_mix_hex')) {
    function bc_meridian_mix_hex($hexA, $hexB, $weightA) {
        require_once __DIR__ . '/bc-theme.php';
        $a = bc_theme_hex($hexA) ?: '#287BFF';
        $b = bc_theme_hex($hexB) ?: '#0B1026';
        $w = max(0.0, min(1.0, (float)$weightA));
        $out = '#';
        foreach (array(1, 3, 5) as $pos) {
            $ca = hexdec(substr($a, $pos, 2));
            $cb = hexdec(substr($b, $pos, 2));
            $out .= sprintf('%02X', (int)round($ca * $w + $cb * (1.0 - $w)));
        }
        return $out;
    }
}

if (!function_exists('bc_meridian_hex_rgba')) {
    function bc_meridian_hex_rgba($hex, $alpha) {
        require_once __DIR__ . '/bc-theme.php';
        $c = bc_theme_hex($hex) ?: '#287BFF';
        $r = hexdec(substr($c, 1, 2));
        $g = hexdec(substr($c, 3, 2));
        $b = hexdec(substr($c, 5, 2));
        $a = max(0.0, min(1.0, (float)$alpha));
        return sprintf('rgba(%d,%d,%d,%.2f)', $r, $g, $b, $a);
    }
}

if (!function_exists('bc_meridian_context')) {
    function bc_meridian_context($vendor_override = null) {
        global $connection_server, $vendor_account_details;
        require_once __DIR__ . '/bc-theme.php';
        require_once __DIR__ . '/bc-public-app-brand.php';

        $host_raw = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        $host_clean = strtolower(preg_replace('/^www\./', '', explode(':', $host_raw)[0]));
        if ($host_clean === '') $host_clean = 'localhost';

        $vendor = is_array($vendor_override) ? $vendor_override : (is_array($vendor_account_details ?? null) ? $vendor_account_details : null);
        if (!$vendor && ($connection_server instanceof mysqli)) {
            $stmt = mysqli_prepare($connection_server, "SELECT * FROM sas_vendors WHERE (website_url = ? OR website_url = ?) AND status = 1 LIMIT 1");
            if ($stmt) {
                $www = 'www.' . $host_clean;
                mysqli_stmt_bind_param($stmt, 'ss', $host_clean, $www);
                if (mysqli_stmt_execute($stmt)) {
                    $res = mysqli_stmt_get_result($stmt);
                    if ($res && ($row = mysqli_fetch_assoc($res))) $vendor = $row;
                }
                mysqli_stmt_close($stmt);
            }
            if (!$vendor) {
                $q = mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE status = 1 ORDER BY id ASC LIMIT 1");
                if ($q) $vendor = mysqli_fetch_assoc($q);
            }
        }

        $vid = (int)($vendor['id'] ?? 0);
        $site = array();
        $super_site = array();
        $style = array();

        if ($connection_server instanceof mysqli) {
            if ($vid > 0) {
                $q = mysqli_query($connection_server, "SELECT * FROM sas_site_details WHERE vendor_id='$vid' LIMIT 1");
                if ($q) $site = mysqli_fetch_assoc($q) ?: array();
                $qs = mysqli_query($connection_server, "SELECT * FROM sas_vendor_style_templates WHERE vendor_id='$vid' LIMIT 1");
                if ($qs) $style = mysqli_fetch_assoc($qs) ?: array();
            }
            $qsa = mysqli_query($connection_server, "SELECT site_title, site_desc FROM sas_super_admin_site_details LIMIT 1");
            if ($qsa) $super_site = mysqli_fetch_assoc($qsa) ?: array();
        }

        $site_title = trim((string)($site['site_title'] ?? ''));
        if ($site_title === '') {
            $site_title = trim((string)($super_site['site_title'] ?? ''));
        }
        if ($site_title === '') {
            $site_title = 'FintechFlow';
        }

        $site_desc = trim((string)($site['site_desc'] ?? ''));
        if ($site_desc === '') {
            $site_desc = trim((string)($super_site['site_desc'] ?? ''));
        }
        if ($site_desc === '') {
            $site_desc = 'One digital platform for everyday bills, global transfers, multi-currency wallets, virtual cards, and merchant tools.';
        }

        $primary = bc_theme_hex($style['primary_color'] ?? null) ?: '#287BFF';
        $palette = bc_theme_palette($primary);
        $dark_tokens = $palette['modes']['dark'];

        $logo_file = str_replace(array('.', ':'), '-', $host_clean) . '_logo.png';
        $logo_disk = dirname(__DIR__) . '/uploaded-image/' . $logo_file;
        $logo_url = is_file($logo_disk) ? '/uploaded-image/' . $logo_file : '';

        $app_url = bc_public_saved_app_url($vid, $site);
        if ($app_url === '' && $vid > 0) {
            require_once __DIR__ . '/bc-public-pages.php';
            $app_url = bc_public_download_url($vid);
        }

        $email = trim((string)($vendor['email'] ?? ''));
        $phone = trim((string)($site['schema_org_phone'] ?? $vendor['phone_number'] ?? ''));
        $address = trim((string)($site['schema_org_address'] ?? $vendor['home_address'] ?? ''));
        $whatsapp_raw = trim((string)($vendor['support_whatsapp'] ?? $site['social_whatsapp'] ?? ''));
        $whatsapp_digits = bc_meridian_sanitize_whatsapp($whatsapp_raw);
        $whatsapp_url = $whatsapp_digits !== '' ? 'https://wa.me/' . $whatsapp_digits : '';

        $rates = array('USD' => 0.0, 'GBP' => 0.0, 'EUR' => 0.0);
        if (function_exists('getLiveExchangeRate') && $vid > 0 && ($connection_server instanceof mysqli)) {
            foreach (array_keys($rates) as $cur) {
                $r = @getLiveExchangeRate($cur, 'NGN', $vid);
                if (is_numeric($r) && (float)$r > 0) $rates[$cur] = (float)$r;
            }
        }

        return array(
            'vendor_id' => $vid,
            'vendor' => $vendor,
            'host' => $host_clean,
            'site_name' => $site_title,
            'short_brand' => bc_public_short_brand($site_title),
            'site_desc' => $site_desc,
            'logo_url' => $logo_url,
            'primary_color' => $primary,
            'palette' => $palette,
            'dark_tokens' => $dark_tokens,
            'app_url' => $app_url,
            'app_badge_html' => bc_public_app_badge_html($app_url),
            'email' => $email,
            'phone' => $phone,
            'address' => $address,
            'whatsapp' => $whatsapp_digits,
            'whatsapp_url' => $whatsapp_url,
            'twitter' => trim((string)($site['social_twitter'] ?? '')),
            'facebook' => trim((string)($site['social_facebook'] ?? '')),
            'instagram' => trim((string)($site['social_instagram'] ?? '')),
            'rates' => $rates,
            'registration_open' => function_exists('bc_registration_enabled') ? bc_registration_enabled($vid) : true,
        );
    }
}

if (!function_exists('bc_meridian_personal_style_vars')) {
    /**
     * Generates Blue glassmorphism CSS variables driven dynamically by admin primary color,
     * preserving readable button text and visible icons for any selected hex color.
     */
    function bc_meridian_personal_style_vars($primary) {
        require_once __DIR__ . '/bc-theme.php';
        $p = bc_theme_hex($primary) ?: '#287BFF';
        $dark = bc_theme_palette($p)['modes']['dark'];
        $bg_top = bc_meridian_mix_hex($p, '#060A1D', 0.14);
        $bg_mid = bc_meridian_mix_hex($p, '#0B1330', 0.24);
        $bg_deep = bc_meridian_mix_hex($p, '#040614', 0.08);
        $orb1 = bc_meridian_hex_rgba($p, 0.34);
        $orb2 = bc_meridian_hex_rgba(bc_meridian_mix_hex($p, '#38BDF8', 0.5), 0.24);
        $orb3 = bc_meridian_hex_rgba(bc_meridian_mix_hex($p, '#6366F1', 0.5), 0.22);
        $glow = bc_meridian_hex_rgba($p, 0.44);

        return "--m-primary:{$dark['primary']};"
            . "--m-on-primary:{$dark['on_primary']};"
            . "--m-primary-text:{$dark['primary_text']};"
            . "--m-outline:{$dark['outline']};"
            . "--m-glow:{$glow};"
            . "--bg:{$bg_top};"
            . "--bg2:{$bg_mid};"
            . "--orb1:{$orb1};"
            . "--orb2:{$orb2};"
            . "--orb3:{$orb3};"
            . "--bgimg:radial-gradient(960px 560px at 16% -6%,{$orb1},transparent 62%),"
            . "radial-gradient(820px 520px at 88% 8%,{$orb2},transparent 62%),"
            . "radial-gradient(760px 560px at 50% 94%,{$orb3},transparent 65%),"
            . "linear-gradient(180deg,{$bg_top} 0%,{$bg_mid} 48%,{$bg_deep} 100%);";
    }
}

if (!function_exists('bc_meridian_service_enabled')) {
    function bc_meridian_service_enabled($key, $vendor_id) {
        if ($key === null || $key === '') return true;
        if ($key === 'crypto_hub') {
            // §28: Plisio was retired; replacement crypto provider integration is pending.
            return false;
        }
        if (function_exists('isServiceEnabled')) {
            return (bool)isServiceEnabled($key, $vendor_id);
        }
        return true;
    }
}

if (!function_exists('bc_meridian_service_catalog')) {
    /**
     * Returns ['categories' => active categories, 'coming_soon' => disabled/pending items]
     * for either 'personal' or 'business' world, gated by ServiceControl.
     */
    function bc_meridian_service_catalog($world, $vendor_id) {
        $vid = (int)$vendor_id;
        if ($world === 'business') {
            $defs = array(
                array(
                    'id' => 'collections',
                    'title' => 'Collections & Invoicing',
                    'subtitle' => 'Receive customer payments and manage billing records',
                    'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h4"/></svg>',
                    'items' => array(
                        array('title' => 'Virtual Collection Accounts', 'note' => 'Dedicated accounts for business inflows', 'href' => '/MultiCurrencyWallet.php', 'key' => 'virtual_bank_display'),
                        array('title' => 'Payment Links', 'note' => 'Shareable checkout links for customers', 'href' => '/PaymentLinks.php', 'key' => 'payout'),
                        array('title' => 'Business Invoices', 'note' => 'Create and track customer payment requests', 'href' => '/Invoices.php', 'key' => 'bank_transfer'),
                    ),
                ),
                array(
                    'id' => 'payouts',
                    'title' => 'Business Payouts & Cross-Border',
                    'subtitle' => 'Pay suppliers, staff, and overseas partners',
                    'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg>',
                    'items' => array(
                        array('title' => 'Single & Bulk Payouts', 'note' => 'Web and API payout disbursements', 'href' => '/Payouts.php', 'key' => 'payout'),
                        array('title' => 'Wallet-to-Bank Settlements', 'note' => 'Settle merchant funds to bank accounts', 'href' => '/Payouts.php', 'key' => 'withdraw'),
                        array('title' => 'International & China Transfers', 'note' => 'Cross-border supplier & wire routes', 'href' => '/services/international-transfer.php', 'key' => 'intl_transfer'),
                        array('title' => 'Tuition Remittance', 'note' => 'Institutional education payments', 'href' => '/Tuition.php', 'key' => 'tuition'),
                    ),
                ),
                array(
                    'id' => 'merchant',
                    'title' => 'Merchant & Reseller Tools',
                    'subtitle' => 'In-person acceptance, messaging, and card printing',
                    'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="5" width="19" height="14" rx="2"/><path d="M2.5 10h19M7 15h3"/></svg>',
                    'items' => array(
                        array('title' => 'POS Terminals', 'note' => 'Terminal enquiries and merchant deployments', 'href' => '/POSTerminals.php', 'key' => 'pos_request'),
                        array('title' => 'Bulk SMS & Sender IDs', 'note' => 'Customer alerts and branded campaigns', 'href' => '/services/bulk-sms.php', 'key' => 'bulk_sms'),
                        array('title' => 'Print Hub (Data & Bill Cards)', 'note' => 'Generate printable voucher batches', 'href' => '/Resellers.php', 'key' => 'data_card'),
                        array('title' => 'Recharge Card Printing', 'note' => 'Printable airtime PINs for resellers', 'href' => '/Resellers.php', 'key' => 'recharge_card'),
                    ),
                ),
                array(
                    'id' => 'wholesale',
                    'title' => 'VTU Wholesale & APIs',
                    'subtitle' => 'Programmatic airtime, data, and utility vending',
                    'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M8 9l-4 3 4 3M16 9l4 3-4 3M13 7l-2 10"/></svg>',
                    'items' => array(
                        array('title' => 'Wholesale Airtime API', 'note' => 'Automated telecom top-up endpoints', 'href' => '/Developers.php', 'key' => 'airtime'),
                        array('title' => 'Wholesale Data API', 'note' => 'SME, CG, and direct data delivery', 'href' => '/Developers.php', 'key' => 'data'),
                        array('title' => 'Cable & Utility APIs', 'note' => 'Meter, decoder, and exam vending', 'href' => '/Developers.php', 'key' => 'cable'),
                        array('title' => 'Electricity Token API', 'note' => 'Prepaid and postpaid DisCo vending', 'href' => '/Developers.php', 'key' => 'electric'),
                    ),
                ),
                array(
                    'id' => 'growth',
                    'title' => 'AI, Crypto & Growth Suite',
                    'subtitle' => 'Automation, loyalty, and emerging treasury rails',
                    'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l2.2 5.8L20 11l-5.8 2.2L12 19l-2.2-5.8L4 11l5.8-2.2z"/></svg>',
                    'items' => array(
                        array('title' => 'AI Business Suite', 'note' => 'Smart reconciliation & business workflows', 'href' => '/Business.php#vtu-api', 'key' => 'ai_suite'),
                        array('title' => 'Partner Referrals', 'note' => 'Track verified partner onboarding rewards', 'href' => '/ReferAndEarn.php', 'key' => 'referral'),
                        array('title' => 'Crypto Treasury & Stablecoins', 'note' => 'USDC / USDT settlement (pending replacement integration)', 'href' => '/services/crypto.php', 'key' => 'crypto_hub', 'pending_reason' => 'Pending replacement integration'),
                    ),
                ),
            );
        } else {
            $defs = array(
                array(
                    'id' => 'payments',
                    'title' => 'Payments & Transfers',
                    'subtitle' => 'Send locally, remit abroad, and convert currencies',
                    'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>',
                    'items' => array(
                        array('title' => 'International Transfers', 'note' => 'Send abroad with locked FX quotes', 'href' => '/services/international-transfer.php', 'key' => 'intl_transfer'),
                        array('title' => 'Local Bank Transfers', 'note' => 'Fast transfers to local bank accounts', 'href' => '/services/bank-transfer.php', 'key' => 'bank_transfer'),
                        array('title' => 'Share Funds (P2P)', 'note' => 'Instant wallet-to-wallet transfers', 'href' => '/services/share-fund.php', 'key' => 'share_fund'),
                        array('title' => 'Multi-Currency & FX', 'note' => 'Convert across NGN, USD, GBP & EUR', 'href' => '/MultiCurrencyWallet.php', 'key' => 'currency_conversion'),
                    ),
                ),
                array(
                    'id' => 'bills',
                    'title' => 'Bills & Everyday Utilities',
                    'subtitle' => 'Airtime, data, power, TV, exams, and betting',
                    'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L4 14h7l-1 8 9-12h-7z"/></svg>',
                    'items' => array(
                        array('title' => 'Airtime Top-Up', 'note' => 'MTN, Airtel, Glo & 9mobile recharge', 'href' => '/services/airtime.php', 'key' => 'airtime'),
                        array('title' => 'Data Bundles', 'note' => 'SME, corporate gifting & direct plans', 'href' => '/services/data.php', 'key' => 'data'),
                        array('title' => 'Cable TV', 'note' => 'DStv, GOtv, StarTimes & Showmax', 'href' => '/services/cable-tv.php', 'key' => 'cable'),
                        array('title' => 'Electricity Bills', 'note' => 'Prepaid tokens & postpaid DisCo bills', 'href' => '/services/electricity.php', 'key' => 'electric'),
                        array('title' => 'Exam PINs', 'note' => 'WAEC, NECO, NABTEB & JAMB e-PINs', 'href' => '/services/exam-pins.php', 'key' => 'exam'),
                        array('title' => 'Betting Wallet Funding', 'note' => 'Verified customer ID top-ups', 'href' => '/services/betting.php', 'key' => 'betting'),
                    ),
                ),
                array(
                    'id' => 'global',
                    'title' => 'Global Spending & Lifestyle',
                    'subtitle' => 'Virtual cards, gift cards, travel eSIM & tuition',
                    'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="5.5" width="19" height="13" rx="2.5"/><path d="M2.5 10h19M7 14.5h4"/></svg>',
                    'items' => array(
                        array('title' => 'Virtual Dollar Cards', 'note' => 'Spend online globally with instant freeze', 'href' => '/services/virtual-cards.php', 'key' => 'virtual_card'),
                        array('title' => 'Global Gift Cards', 'note' => 'International brand codes via email', 'href' => '/services/gift-cards.php', 'key' => 'gift_card'),
                        array('title' => 'Travel eSIM', 'note' => 'Data plans across 190+ destinations', 'href' => '/services/esim.php', 'key' => 'esim'),
                        array('title' => 'Tuition Payments', 'note' => 'Pay international school fees securely', 'href' => '/Tuition.php', 'key' => 'tuition'),
                    ),
                ),
                array(
                    'id' => 'crypto',
                    'title' => 'Crypto & Digital Assets',
                    'subtitle' => 'Buy, sell & stablecoin settlement rails',
                    'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M9.5 8h4a2 2 0 0 1 0 4h-4m0 0h4.5a2 2 0 0 1 0 4H9.5m2-9.5V8m0 8v1.5"/></svg>',
                    'items' => array(
                        array('title' => 'Crypto Buy / Sell & Stablecoins', 'note' => 'USDC, USDT & digital asset settlement (pending replacement integration)', 'href' => '/services/crypto.php', 'key' => 'crypto_hub', 'pending_reason' => 'Pending replacement integration'),
                    ),
                ),
                array(
                    'id' => 'rewards',
                    'title' => 'Savings, Rewards & Identity',
                    'subtitle' => 'Referral rewards, loyalty coins & verification',
                    'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l7 3v5c0 5-3.5 8.8-7 10-3.5-1.2-7-5-7-10V6z"/><path d="M9 12.2l2 2 4-4"/></svg>',
                    'items' => array(
                        array('title' => 'Refer & Earn', 'note' => 'Earn locked coins that unlock after $100 volume', 'href' => '/ReferAndEarn.php', 'key' => 'referral'),
                        array('title' => 'Loyalty Coin Conversion', 'note' => 'Convert unlocked coins into wallet value', 'href' => '/services/CoinConversion.php', 'key' => 'vtu_coins'),
                        array('title' => 'Digital NIN Slip', 'note' => 'Verified identity slip retrieval', 'href' => '/services/nin-card.php', 'key' => 'nin_card'),
                        array('title' => 'BVN Verification', 'note' => 'Fast identity verification for higher limits', 'href' => '/services/bvn-verification.php', 'key' => 'bvn_verify'),
                    ),
                ),
            );
        }

        $categories = array();
        $coming_soon = array();
        foreach ($defs as $cat) {
            $active = array();
            foreach ($cat['items'] as $item) {
                $enabled = bc_meridian_service_enabled($item['key'], $vid);
                if ($enabled) {
                    $active[] = $item;
                } else {
                    $coming_soon[] = array(
                        'title' => $item['title'],
                        'category' => $cat['title'],
                        'note' => $item['note'],
                        'reason' => $item['pending_reason'] ?? 'Paused by site control — coming soon',
                        'href' => $item['href'],
                    );
                }
            }
            if (!empty($active)) {
                $cat['items'] = $active;
                $categories[] = $cat;
            }
        }

        return array(
            'categories' => $categories,
            'coming_soon' => $coming_soon,
        );
    }
}

if (!function_exists('bc_meridian_brand_lockup')) {
    function bc_meridian_brand_lockup($ctx, $href = '/') {
        $name = bc_meridian_e($ctx['site_name']);
        $initial = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $ctx['site_name']) ?: 'F', 0, 1));
        $out = '<a class="lock" href="' . bc_meridian_e($href) . '" aria-label="' . $name . ' home">';
        if (!empty($ctx['logo_url'])) {
            $out .= '<img class="m-logo-img" src="' . bc_meridian_e($ctx['logo_url']) . '" alt="' . $name . ' logo">';
        } else {
            $out .= '<span class="m-brand-mark" aria-hidden="true">' . bc_meridian_e($initial) . '</span>';
        }
        $out .= '<span class="ff-word">' . $name . '</span></a>';
        return $out;
    }
}

if (!function_exists('bc_meridian_support_bar')) {
    function bc_meridian_support_bar($ctx) {
        $chips = array();
        if (!empty($ctx['whatsapp_url'])) {
            $chips[] = '<a class="m-support-chip" href="' . bc_meridian_e($ctx['whatsapp_url']) . '" target="_blank" rel="noopener noreferrer">'
                . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 11.5a8.4 8.4 0 0 1-12.2 7.5L3 20l1.1-5.6A8.4 8.4 0 1 1 21 11.5z"/></svg>'
                . '<span>WhatsApp Support</span></a>';
        }
        if (!empty($ctx['email'])) {
            $chips[] = '<a class="m-support-chip" href="mailto:' . bc_meridian_e($ctx['email']) . '">'
                . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg>'
                . '<span>' . bc_meridian_e($ctx['email']) . '</span></a>';
        }
        if (!empty($ctx['phone'])) {
            $tel = preg_replace('/[^0-9+]/', '', $ctx['phone']);
            $chips[] = '<a class="m-support-chip" href="tel:' . bc_meridian_e($tel) . '">'
                . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 2 .7 2.9a2 2 0 0 1-.5 2.1L8 10a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.5c.9.3 1.9.6 2.9.7a2 2 0 0 1 1.7 2z"/></svg>'
                . '<span>' . bc_meridian_e($ctx['phone']) . '</span></a>';
        }
        if (empty($chips)) return '';
        return '<div class="m-support-bar">' . implode('', $chips) . '</div>';
    }
}

if (!function_exists('bc_meridian_render_catalog_section')) {
    function bc_meridian_render_catalog_section($world, $vendor_id, $heading, $subtext) {
        $cat_data = bc_meridian_service_catalog($world, $vendor_id);
        ob_start();
        ?>
        <section class="chapter tight" id="<?php echo $world === 'business' ? 'bizTiles' : 'homeTiles'; ?>">
            <div class="wrap">
                <div class="chap-head">
                    <p class="eyebrow"><i></i>Live Service Directory</p>
                    <h2 style="margin-top:10px"><?php echo bc_meridian_e($heading); ?></h2>
                    <p class="small" style="margin-top:8px"><?php echo bc_meridian_e($subtext); ?></p>
                </div>
                <?php if (!empty($cat_data['categories'])): ?>
                <div class="m-cat-grid" data-rv="">
                    <?php foreach ($cat_data['categories'] as $cat): ?>
                    <article class="m-cat-card">
                        <div class="m-cat-head">
                            <span class="m-cat-icon" aria-hidden="true"><?php echo $cat['icon']; ?></span>
                            <div>
                                <h3><?php echo bc_meridian_e($cat['title']); ?></h3>
                                <p class="m-cat-sub"><?php echo bc_meridian_e($cat['subtitle']); ?></p>
                            </div>
                        </div>
                        <ul class="m-cat-list">
                            <?php foreach ($cat['items'] as $item): ?>
                            <li>
                                <a href="<?php echo bc_meridian_e($item['href']); ?>">
                                    <span>
                                        <strong><?php echo bc_meridian_e($item['title']); ?></strong>
                                        <span class="m-item-note"><?php echo bc_meridian_e($item['note']); ?></span>
                                    </span>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                                </a>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </article>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($cat_data['coming_soon'])): ?>
                <div class="m-cs-band" id="coming-soon">
                    <p class="eyebrow"><i></i>Coming Soon &amp; Service Rollout</p>
                    <h3 style="margin-top:8px">Services currently paused or pending integration</h3>
                    <p class="small" style="margin-top:4px">Items turned off in the Site Owner Service Control Center or awaiting replacement provider integration appear here until activated.</p>
                    <div class="m-cs-grid">
                        <?php foreach ($cat_data['coming_soon'] as $cs): ?>
                        <?php if (!empty($cs['href'])): ?>
                        <a class="m-cs-item" href="<?php echo bc_meridian_e($cs['href']); ?>">
                            <span class="m-cs-pill"><?php echo bc_meridian_e($cs['reason']); ?></span>
                            <h4><?php echo bc_meridian_e($cs['title']); ?></h4>
                            <p><?php echo bc_meridian_e($cs['category']); ?> · <?php echo bc_meridian_e($cs['note']); ?></p>
                        </a>
                        <?php else: ?>
                        <div class="m-cs-item">
                            <span class="m-cs-pill"><?php echo bc_meridian_e($cs['reason']); ?></span>
                            <h4><?php echo bc_meridian_e($cs['title']); ?></h4>
                            <p><?php echo bc_meridian_e($cs['category']); ?> · <?php echo bc_meridian_e($cs['note']); ?></p>
                        </div>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </section>
        <?php
        return ob_get_clean();
    }
}

if (!function_exists('bc_meridian_current_path')) {
    function bc_meridian_current_path() {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? $_SERVER['SCRIPT_NAME'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') $path = '/';
        if ($path === '/index.php' || $path === '/index-bc-style-template-meridian-1.php') $path = '/';
        return $path;
    }
}

if (!function_exists('bc_meridian_header_and_menu')) {
    function bc_meridian_header_and_menu($ctx, $world = 'personal', $active_path = null) {
        $is_biz = ($world === 'business');
        $name = bc_meridian_e($ctx['site_name']);
        $initial = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $ctx['site_name']) ?: 'F', 0, 1));
        $cur = $active_path !== null ? (string)$active_path : bc_meridian_current_path();
        $is_service_route = ($cur === '/Services.php' || strpos($cur, '/services/') === 0);
        $is_biz_route = in_array($cur, array('/Business.php', '/PaymentLinks.php', '/Invoices.php', '/Payouts.php', '/POSTerminals.php'), true);

        if ($is_biz) {
            $nav_items = array(
                array('href' => '/', 'label' => 'Personal', 'active' => ($cur === '/')),
                array('href' => '/Business.php', 'label' => 'Business', 'active' => $is_biz_route),
                array('href' => '/Developers.php', 'label' => 'Developers', 'active' => ($cur === '/Developers.php')),
                array('href' => '/Resellers.php', 'label' => 'Resellers', 'active' => ($cur === '/Resellers.php')),
                array('href' => '/Services.php', 'label' => 'Services', 'active' => $is_service_route),
                array('href' => '/Contact.php', 'label' => 'Support', 'active' => ($cur === '/Contact.php')),
            );
        } else {
            $nav_items = array(
                array('href' => '/', 'label' => 'Home', 'active' => ($cur === '/')),
                array('href' => '/Services.php', 'label' => 'Services', 'active' => $is_service_route),
                array('href' => '/Business.php', 'label' => 'Business', 'active' => $is_biz_route),
                array('href' => '/ReferAndEarn.php', 'label' => 'Refer & Earn', 'active' => ($cur === '/ReferAndEarn.php')),
                array('href' => '/Security.php', 'label' => 'Security', 'active' => ($cur === '/Security.php')),
                array('href' => '/Contact.php', 'label' => 'Support', 'active' => ($cur === '/Contact.php')),
            );
        }

        $mobile_items = array(
            array('href' => '/', 'label' => 'Personal Home', 'active' => ($cur === '/')),
            array('href' => '/Business.php', 'label' => 'Business', 'active' => $is_biz_route),
            array('href' => '/Services.php', 'label' => 'All Services', 'active' => $is_service_route),
            array('href' => '/Developers.php', 'label' => 'Developers & API', 'active' => ($cur === '/Developers.php')),
            array('href' => '/Resellers.php', 'label' => 'Merchant & Resellers', 'active' => ($cur === '/Resellers.php')),
            array('href' => '/ReferAndEarn.php', 'label' => 'Refer & Earn', 'active' => ($cur === '/ReferAndEarn.php')),
            array('href' => '/Security.php', 'label' => 'Security', 'active' => ($cur === '/Security.php')),
            array('href' => '/DownloadApp.php', 'label' => 'Get the App', 'active' => ($cur === '/DownloadApp.php')),
            array('href' => '/Contact.php', 'label' => 'Support', 'active' => ($cur === '/Contact.php')),
        );
        ob_start();
        ?>
        <header class="nav" id="nav">
            <div class="nav-in">
                <a class="logo" href="/" id="logoLink" aria-label="<?php echo $name; ?> home">
                    <span class="full" style="display:flex;align-items:center;gap:8px">
                        <?php if (!empty($ctx['logo_url'])): ?>
                            <img class="m-logo-img" src="<?php echo bc_meridian_e($ctx['logo_url']); ?>" alt="<?php echo $name; ?> logo">
                        <?php else: ?>
                            <span class="m-brand-mark" aria-hidden="true"><?php echo bc_meridian_e($initial); ?></span>
                        <?php endif; ?>
                        <span class="ff-word"><?php echo $name; ?></span>
                    </span>
                </a>
                <div class="pswitch" id="pswitch" role="radiogroup" aria-label="Choose a <?php echo $name; ?> experience" style="grid-template-columns:repeat(2,1fr)">
                    <span class="pthumb" id="pthumb" aria-hidden="true" style="width:calc((100% - 6px)/2)"></span>
                    <button type="button" role="radio" aria-checked="<?php echo $is_biz ? 'false' : 'true'; ?>" tabindex="<?php echo $is_biz ? '-1' : '0'; ?>" data-w="personal"><span>Personal</span></button>
                    <button type="button" role="radio" aria-checked="<?php echo $is_biz ? 'true' : 'false'; ?>" tabindex="<?php echo $is_biz ? '0' : '-1'; ?>" data-w="business"><span>Business</span></button>
                </div>
                <nav class="links" id="links" aria-label="Main">
                    <?php foreach ($nav_items as $ni): ?>
                        <a href="<?php echo bc_meridian_e($ni['href']); ?>"<?php echo $ni['active'] ? ' class="is-active" aria-current="page"' : ''; ?>><?php echo bc_meridian_e($ni['label']); ?></a>
                    <?php endforeach; ?>
                </nav>
                <div class="nav-r">
                    <a class="btn ghost" href="/web/Login.php">Log in</a>
                    <?php if (!empty($ctx['app_url'])): ?>
                        <button class="btn primary get" type="button" data-open-get="1" id="navGet">Get the app</button>
                    <?php else: ?>
                        <a class="btn primary get" href="/web/Register.php" id="navGet">Get started</a>
                    <?php endif; ?>
                    <button class="icon-btn menu-btn" id="menuBtn" type="button" aria-label="Open menu" aria-expanded="false" aria-controls="sheet">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 8h16M4 16h16"/></svg>
                    </button>
                </div>
            </div>
        </header>

        <div class="sheet" id="sheet" hidden="">
            <div class="glass" role="dialog" aria-modal="true" aria-label="Menu">
                <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 14px 4px">
                    <strong><?php echo $name; ?></strong>
                    <button class="icon-btn" id="menuClose" type="button" aria-label="Close menu">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
                    </button>
                </div>
                <div class="mlinks" id="mlinks">
                    <?php foreach ($mobile_items as $mi): ?>
                        <a href="<?php echo bc_meridian_e($mi['href']); ?>"<?php echo $mi['active'] ? ' class="is-active" aria-current="page"' : ''; ?>><?php echo bc_meridian_e($mi['label']); ?></a>
                    <?php endforeach; ?>
                </div>
                <div class="row">
                    <a class="btn ghost" href="/web/Login.php" style="flex:1">Log in</a>
                    <a class="btn primary" href="/web/Register.php" style="flex:1">Create account</a>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}

if (!function_exists('bc_meridian_footer')) {
    function bc_meridian_footer($ctx, $directory_html = null) {
        require_once __DIR__ . '/bc-public-pages.php';
        ob_start();
        ?>
        <footer>
            <div class="wrap">
                <div class="ft-top">
                    <div class="ft-brand">
                        <?php echo bc_meridian_brand_lockup($ctx, '/'); ?>
                        <p class="small"><?php echo bc_meridian_e($ctx['site_desc']); ?></p>
                        <?php if (!empty($ctx['app_url'])): ?>
                            <div style="margin-top:10px"><?php echo $ctx['app_badge_html']; ?></div>
                        <?php else: ?>
                            <a class="btn primary ft-get" href="/DownloadApp.php">Get the app</a>
                        <?php endif; ?>
                        <?php echo bc_meridian_support_bar($ctx); ?>
                    </div>
                    <div class="ft-nav">
                        <details class="fg" open>
                            <summary><h4>Discover</h4></summary>
                            <nav class="fl" aria-label="Discover">
                                <a href="/">Home</a>
                                <a href="/Services.php">All services</a>
                                <a href="/MultiCurrencyWallet.php">Multi-currency wallet</a>
                                <a href="/Savings.php">Savings</a>
                                <a href="/DownloadApp.php">Get the app</a>
                            </nav>
                        </details>
                        <details class="fg" open>
                            <summary><h4>Money &amp; cards</h4></summary>
                            <nav class="fl" aria-label="Money and cards">
                                <a href="/services/international-transfer.php">International transfers</a>
                                <a href="/services/virtual-cards.php">Virtual cards</a>
                                <a href="/services/crypto.php">Digital assets</a>
                                <a href="/services/gift-cards.php">Gift cards</a>
                                <a href="/services/esim.php">Travel eSIM</a>
                                <a href="/Tuition.php">Tuition payments</a>
                            </nav>
                        </details>
                        <details class="fg" open>
                            <summary><h4>Bills &amp; utilities</h4></summary>
                            <nav class="fl" aria-label="Bills and utilities">
                                <a href="/services/airtime.php">Airtime</a>
                                <a href="/services/data.php">Data bundles</a>
                                <a href="/services/cable-tv.php">Cable TV</a>
                                <a href="/services/electricity.php">Electricity</a>
                                <a href="/services/exam-pins.php">Exam PINs</a>
                                <a href="/services/betting.php">Betting funding</a>
                            </nav>
                        </details>
                        <details class="fg" open>
                            <summary><h4>For business</h4></summary>
                            <nav class="fl" aria-label="Business services">
                                <a href="/Business.php">Business account</a>
                                <a href="/PaymentLinks.php">Payment links</a>
                                <a href="/Invoices.php">Invoices</a>
                                <a href="/Payouts.php">Payouts</a>
                                <a href="/POSTerminals.php">POS terminals</a>
                                <a href="/Developers.php">Developers &amp; API</a>
                                <a href="/Resellers.php">Merchant &amp; resellers</a>
                            </nav>
                        </details>
                        <details class="fg" open>
                            <summary><h4>Company &amp; rewards</h4></summary>
                            <nav class="fl" aria-label="Company and rewards">
                                <a href="/AboutUs.php">About <?php echo bc_meridian_e($ctx['short_brand']); ?></a>
                                <a href="/Security.php">Security &amp; trust</a>
                                <a href="/Careers.php">Careers</a>
                                <a href="/blog.php">Blog</a>
                                <a href="/Contact.php">Contact &amp; support</a>
                                <a href="/ReferAndEarn.php">Refer &amp; Earn</a>
                            </nav>
                        </details>
                        <details class="fg" open>
                            <summary><h4>Account &amp; legal</h4></summary>
                            <nav class="fl" aria-label="Account and legal">
                                <a href="/web/Register.php">Create account</a>
                                <a href="/web/Login.php">Log in</a>
                                <a href="/Privacy.php">Privacy policy</a>
                                <a href="/Terms.php">Terms of service</a>
                                <a href="/CookiePolicy.php">Cookie policy</a>
                                <a href="/web/DeleteAccount.php">Delete account</a>
                            </nav>
                        </details>
                    </div>
                </div>
                <?php
                if ($directory_html !== null) {
                    echo $directory_html;
                } else {
                    bc_public_directory_html();
                }
                ?>
                <div class="ft-bot" style="margin-top:20px">
                    <div class="ft-note" id="demo-notice">
                        <p>&copy; <?php echo date('Y'); ?> <?php echo bc_meridian_e($ctx['site_name']); ?>.<?php if (!empty($ctx['address'])): ?> <?php echo bc_meridian_e($ctx['address']); ?>.<?php endif; ?></p>
                        <p>Service availability depends on account verification, provider status, and Site Owner Service Control settings.</p>
                    </div>
                    <nav aria-label="Legal">
                        <a href="/Security.php">Security</a>
                        <a href="/Privacy.php">Privacy</a>
                        <a href="/Terms.php">Terms</a>
                    </nav>
                </div>
            </div>
        </footer>

        <div class="sheet" id="getSheet" hidden=""><div class="glass panel" role="dialog" aria-modal="true" aria-labelledby="getTitle" style="max-width:420px;text-align:center" id="getBody"></div></div>
        <div class="toast glass" id="toast" role="status" aria-live="polite"></div>
        <?php
        return ob_get_clean();
    }
}

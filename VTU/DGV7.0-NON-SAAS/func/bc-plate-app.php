<?php
/**
 * Plate app shell — one shared layout for the signed-in customer pages.
 *
 * The signed-out journey (landing, login, register, password recovery, OTP, PIN, the
 * account-state pages) and both installation wizards already carry the Plate design tokens.
 * The logged-in pages were the last holdout: fifty-five of them still loaded the heavyweight
 * `assets-2` Bootstrap template and the old `bc-header.php` chrome, so the product looked like
 * two different systems depending on whether you had signed in.
 *
 * This shell gives those pages the same language — teal brand, Inter, roomy cards,
 * bootstrap-icons — and one data-driven navigation model, so a page migrates by swapping its
 * chrome rather than by restyling fifty copies of the same menu.
 *
 * The shell is presentation only. It moves no money, reads no credential and decides no
 * entitlement: the per-item service gates below call the SAME helpers the old header called
 * (`isServiceEnabled()` and the tier matrix), and every page keeps its own PHP logic verbatim.
 *
 * Helpers:
 *   bc_app_head($title)      — the <head> block (meta, fonts, icons, design-system CSS)
 *   bc_app_sidebar($opts)    — <body> + brand rail + the navigation
 *   bc_app_topbar($opts)     — the sticky header, opening the content column
 *   bc_app_foot($opts)       — closes the document and prints the shell script
 *   bc_app_menu()            — the navigation definition (groups, items, gates)
 *
 * A page migrates like this:
 *   <?php bc_app_head("Dashboard | " . $get_all_site_details["site_title"]); ?>
 *   <?php bc_app_sidebar(); ?>
 *   <?php bc_app_topbar(array("title" => "Dashboard")); ?>
 *   ...existing page markup...
 *   <?php bc_app_foot(); ?>
 */

if (!function_exists('bc_app_colour')) {
    /**
     * Tenant brand colour, validated. A bad stored value must not be able to inject CSS.
     * Same rule and same default as the auth shell, so the two shells cannot drift.
     */
    function bc_app_colour($site_details = null) {
        if (!empty($GLOBALS['vendor_primary_color']) && preg_match('/^#[0-9a-fA-F]{6}$/', (string)$GLOBALS['vendor_primary_color'])) {
            return (string)$GLOBALS['vendor_primary_color'];
        }
        $details = is_array($site_details) ? $site_details : array();
        if (!empty($details['primary_color']) && preg_match('/^#[0-9a-fA-F]{6}$/', (string)$details['primary_color'])) {
            return (string)$details['primary_color'];
        }
        return '#0f766e';
    }
}

if (!function_exists('bc_app_site')) {
    /**
     * Resolve the tenant identity for the shell: title, logo path and the signed-in user.
     * Reads the same globals the older templates used, and degrades quietly.
     */
    function bc_app_site() {
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

        // Same logo naming convention as the auth shell and the older templates:
        // uploaded-image/{HTTP_HOST with . and : as -}_logo.png.
        $logo = '';
        $host = isset($_SERVER['HTTP_HOST']) ? str_replace(array('.', ':'), '-', (string)$_SERVER['HTTP_HOST']) : 'site';
        $file = 'uploaded-image/' . $host . '_logo.png';
        if (file_exists(__DIR__ . '/../' . $file)) {
            $logo = $file;
        }

        $user = isset($GLOBALS['get_logged_user_details']) && is_array($GLOBALS['get_logged_user_details'])
            ? $GLOBALS['get_logged_user_details'] : array();
        $username = isset($_SESSION['user_session']) ? (string)$_SESSION['user_session'] : '';
        if ($username === '' && !empty($user['username'])) {
            $username = (string)$user['username'];
        }

        return array(
            'title'    => $title,
            'logo'     => $logo,
            'details'  => $details,
            'user'     => $user,
            'username' => $username,
            'host'     => isset($GLOBALS['web_http_host']) ? (string)$GLOBALS['web_http_host'] : '',
        );
    }
}

if (!function_exists('bc_app_base')) {
    /**
     * Absolute base URL for shell links. Falls back to the host root when the page has not
     * loaded the config that defines $web_http_host.
     */
    function bc_app_base() {
        $site = bc_app_site();
        if ($site['host'] !== '') return $site['host'];
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host  = isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : 'localhost';
        return $proto . '://' . $host;
    }
}

if (!function_exists('bc_app_tier_service')) {
    /**
     * "May THIS account use this service right now?" — the same answer the old header gave.
     *
     * Wrapped rather than inlined so a page that includes this shell before the fintech account
     * layer is loaded still works: an absent helper simply hides the entry instead of fataling.
     */
    function bc_app_tier_service($service) {
        if (!function_exists('bc_fintech_account_type') || !function_exists('bc_fintech_matrix_read')) {
            return true;
        }
        $user = isset($GLOBALS['get_logged_user_details']) && is_array($GLOBALS['get_logged_user_details'])
            ? $GLOBALS['get_logged_user_details'] : null;
        $tier = bc_fintech_account_type($user);
        if (!in_array($tier, array('personal', 'business'), true)) return false;
        $vendor_id = (int)($user['vendor_id'] ?? 0);
        $matrix = bc_fintech_matrix_read($vendor_id);
        if (!isset($matrix[$service])) return false;
        $row = $matrix[$service];
        $row['service_name'] = $service;
        return bc_fintech_tier_effective($row, $tier);
    }
}

if (!function_exists('bc_app_service_on')) {
    /**
     * Vendor-wide service switch. The old header called isServiceEnabled() directly; this wrapper
     * keeps a page that has not loaded bc-func.php working instead of fataling.
     */
    function bc_app_service_on($service) {
        if (!function_exists('isServiceEnabled')) return true;
        return (bool)isServiceEnabled($service);
    }
}

if (!function_exists('bc_app_menu')) {
    /**
     * The navigation model.
     *
     * One definition, both editions, every page. Each item may carry:
     *   label    — the visible name
     *   href     — the page filename under /web (relative links are resolved against bc_app_base)
     *   icon     — a bootstrap-icons glyph name
     *   service  — vendor-wide switch, checked with isServiceEnabled()
     *   tier     — tier/contract gate, checked against the per-tier matrix
     *   when     — closure receiving the user row, for rules that are neither of the above
     *   badge    — small trailing label (e.g. "NEW")
     * Groups carry `match`, the list of page filenames that expand and highlight the group.
     */
    function bc_app_menu() {
        return array(
            array(
                'items' => array(
                    array('label' => 'Dashboard', 'href' => 'Dashboard.php', 'icon' => 'grid'),
                ),
            ),
            array(
                'label' => 'Pay Bills', 'icon' => 'lightning-charge', 'id' => 'pay-bills',
                'match' => array('Data.php', 'Airtime.php', 'BulkData.php', 'BulkAirtime.php', 'Cable.php', 'Electric.php', 'Betting.php', 'Exam.php'),
                'items' => array(
                    array('label' => 'Buy Data Bundle', 'href' => 'Data.php', 'icon' => 'diagram-3', 'service' => 'data'),
                    array('label' => 'Buy Airtime VTU', 'href' => 'Airtime.php', 'icon' => 'phone', 'service' => 'airtime'),
                    array('label' => 'Buy Bulk Data Bundle', 'href' => 'BulkData.php', 'icon' => 'collection', 'service' => 'data'),
                    array('label' => 'Buy Bulk Airtime VTU', 'href' => 'BulkAirtime.php', 'icon' => 'collection-play', 'service' => 'airtime'),
                    array('label' => 'Buy CableTv Sub(s)', 'href' => 'Cable.php', 'icon' => 'tv', 'service' => 'cable'),
                    array('label' => 'Buy Electric Token', 'href' => 'Electric.php', 'icon' => 'plug', 'service' => 'electric'),
                    array('label' => 'Fund Betting', 'href' => 'Betting.php', 'icon' => 'trophy', 'service' => 'betting'),
                    array('label' => 'Buy Exam Pin(s)', 'href' => 'Exam.php', 'icon' => 'pencil-square', 'service' => 'exam'),
                ),
            ),
            array(
                'label' => 'Wallet & Transfers', 'icon' => 'wallet2', 'id' => 'wallet-transfers',
                'match' => array('Fund.php', 'VirtualBanks.php', 'SendFund.php', 'ShareFund.php', 'CoinConversion.php', 'VirtualCard.php', 'GiftCard.php', 'CryptoHub.php', 'IntlTransfer.php', 'Tuition.php', 'ESIM.php', 'POSRequest.php'),
                'items' => array(
                    array('label' => 'Fund with ATM Card', 'href' => 'Fund.php', 'icon' => 'credit-card-2-front'),
                    array('label' => 'Fund via Bank Transfer', 'href' => 'VirtualBanks.php', 'icon' => 'bank', 'service' => 'virtual_bank_display'),
                    array('label' => 'Wallet to Bank', 'href' => 'SendFund.php', 'icon' => 'arrow-up-right-circle', 'service' => 'withdraw'),
                    array('label' => 'Share Fund', 'href' => 'ShareFund.php', 'icon' => 'share', 'service' => 'share_fund'),
                    array('label' => 'Convert Coins', 'href' => 'CoinConversion.php', 'icon' => 'arrow-left-right', 'service' => 'vtu_coins'),
                    array('label' => 'Virtual Card', 'href' => 'VirtualCard.php', 'icon' => 'credit-card', 'service' => 'virtual_card'),
                    array('label' => 'Gift Card', 'href' => 'GiftCard.php', 'icon' => 'gift', 'service' => 'gift_card'),
                    array('label' => 'Crypto overview', 'href' => 'CryptoOverview.php', 'icon' => 'shield-check', 'service' => 'crypto_hub'),
                    array('label' => 'Crypto', 'href' => 'CryptoHub.php', 'icon' => 'currency-bitcoin', 'service' => 'crypto_hub'),
                    array('label' => 'Send Money Abroad', 'href' => 'IntlTransfer.php', 'icon' => 'globe', 'service' => 'intl_transfer'),
                    array('label' => 'Pay Tuition Worldwide', 'href' => 'Tuition.php', 'icon' => 'mortarboard', 'service' => 'tuition'),
                    array('label' => 'eSIM', 'href' => 'ESIM.php', 'icon' => 'sim', 'service' => 'esim'),
                    array('label' => 'Request POS Device', 'href' => 'POSRequest.php', 'icon' => 'calculator', 'service' => 'pos_request'),
                ),
            ),
            array(
                'label' => 'Verify & Print', 'icon' => 'briefcase', 'id' => 'verify-print',
                'match' => array('NINCard.php', 'NINCardHistory.php', 'BVNVerification.php'),
                'items' => array(
                    array('label' => 'Print NIN Slip', 'href' => 'NINCard.php', 'icon' => 'person-vcard', 'service' => 'nin_card'),
                    array('label' => 'NIN Slip History', 'href' => 'NINCardHistory.php', 'icon' => 'clock-history', 'service' => 'nin_card'),
                    array('label' => 'BVN Verification', 'href' => 'BVNVerification.php', 'icon' => 'shield-check', 'service' => 'bvn_verify'),
                ),
            ),
            array(
                'items' => array(
                    array('label' => 'PrintHub', 'href' => 'PrintHub.php', 'icon' => 'printer', 'service' => 'data_card'),
                ),
            ),
            array(
                'label' => 'Tools & API', 'icon' => 'building', 'id' => 'tools-api',
                'match' => array('Referral.php', 'BulkSMS.php', 'SubmitSenderID.php', 'Pricing.php', 'handle_upgrade.php', 'BusinessUpgrade.php', 'APIDocs.php'),
                'items' => array(
                    array('label' => 'Refer & Earn', 'href' => 'Referral.php', 'icon' => 'gift', 'badge' => 'NEW', 'service' => 'referral'),
                    array('label' => 'Bulk SMS', 'href' => 'BulkSMS.php', 'icon' => 'chat-square-text', 'service' => 'bulk_sms'),
                    array('label' => 'Submit SMS ID', 'href' => 'SubmitSenderID.php', 'icon' => 'send-check', 'service' => 'bulk_sms'),
                    array('label' => 'Account Pricing', 'href' => 'Pricing.php', 'icon' => 'tags'),
                    array('label' => 'Pricing Level', 'href' => 'handle_upgrade.php', 'icon' => 'arrow-up-circle'),
                    array('label' => 'Business Upgrade', 'href' => 'BusinessUpgrade.php', 'icon' => 'arrow-up-circle',
                          'when' => function ($user) {
                              $tier = function_exists('bc_fintech_account_type') ? bc_fintech_account_type($user) : 'personal';
                              return $tier !== 'business' && (int)($user['kyb_status'] ?? 0) !== 1;
                          }),
                    array('label' => 'Developer API', 'href' => 'APIDocs.php', 'icon' => 'braces', 'tier' => 'developer_api'),
                ),
            ),
            array(
                'label' => 'History & Tools', 'icon' => 'clock-history', 'id' => 'history-tools',
                'match' => array('Transactions.php', 'BatchTransactions.php', 'PointsHistory.php', 'TransactionCalculator.php', 'NumberChecker.php', 'NumberFilter.php', 'PaymentOrders.php', 'SubmitPayment.php', 'ShareFundHistory.php'),
                'items' => array(
                    array('label' => 'All Transactions', 'href' => 'Transactions.php', 'icon' => 'list-ul'),
                    array('label' => 'Share Fund History', 'href' => 'ShareFundHistory.php', 'icon' => 'clock-history'),
                    array('label' => 'Batch Transactions', 'href' => 'BatchTransactions.php', 'icon' => 'layers'),
                    array('label' => 'Points History', 'href' => 'PointsHistory.php', 'icon' => 'star'),
                    array('label' => 'Transaction Calculator', 'href' => 'TransactionCalculator.php', 'icon' => 'calculator'),
                    array('label' => 'Number Credit Checker', 'href' => 'NumberChecker.php', 'icon' => 'phone-flip'),
                    array('label' => 'Number Filter', 'href' => 'NumberFilter.php', 'icon' => 'funnel'),
                    array('label' => 'Payment Orders', 'href' => 'PaymentOrders.php', 'icon' => 'receipt'),
                    array('label' => 'Submit Payment', 'href' => 'SubmitPayment.php', 'icon' => 'cash-stack', 'service' => 'manual_funding'),
                ),
            ),
            array(
                'label' => 'Settings & Help', 'icon' => 'gear', 'id' => 'settings-help',
                'match' => array('AccountSettings.php', 'SiteMap.php', 'APIDocs.php'),
                'items' => array(
                    array('label' => 'Account Settings', 'href' => 'AccountSettings.php', 'icon' => 'person-gear'),
                    array('label' => 'Support', 'href' => 'Hub.php?g=settings-help#bcSupportSection', 'icon' => 'chat-dots'),
                    array('label' => 'Platform Guide', 'href' => 'SiteMap.php', 'icon' => 'map'),
                    array('label' => 'Developer API', 'href' => 'APIDocs.php', 'icon' => 'braces', 'tier' => 'developer_api'),
                ),
            ),
        );
    }
}

if (!function_exists('bc_app_hub_group')) {
    /** Find a labelled main-menu group by its id (the Hub page's ?g= slug). */
    function bc_app_hub_group($id) {
        foreach (bc_app_menu() as $group) {
            if (isset($group['id']) && (string)$group['id'] === (string)$id && !empty($group['label'])) {
                return $group;
            }
        }
        return null;
    }
}

if (!function_exists('bc_app_hub_render')) {
    /**
     * Render a main-menu group as an embossed dark matrix of its sub-pages.
     * Used by web/Hub.php so the sidebar stays a short list of main items and
     * each section gets a proper landing page instead of an expanding tree.
     */
    function bc_app_hub_render($group, $user = null) {
        if ($user === null) {
            $site = bc_app_site();
            $user = $site['user'];
        }
        $items = array();
        foreach ((array)$group['items'] as $item) {
            if (bc_app_item_visible($item, $user)) $items[] = $item;
        }
        if (!$items) {
            echo '<div class="pk-matrix__empty">Nothing here is enabled for your account yet.</div>';
            return;
        }
        echo '<div class="pk-matrix">';
        foreach ($items as $item) {
            $href = bc_app_url((string)$item['href']);
            echo '<a class="pk-matrix__card" href="' . htmlspecialchars($href) . '">';
            echo '<span class="pk-matrix__icon">' . bc_app_icon(isset($item['icon']) ? $item['icon'] : 'circle') . '</span>';
            echo '<span class="pk-matrix__label">' . htmlspecialchars((string)$item['label']) . '</span>';
            if (!empty($item['badge'])) {
                echo '<span class="pk-matrix__badge">' . htmlspecialchars((string)$item['badge']) . '</span>';
            }
            echo '<span class="pk-matrix__go">' . bc_app_icon('arrow-right-circle') . '</span>';
            echo '</a>';
        }
        echo '</div>';
    }
}

if (!function_exists('bc_app_item_visible')) {
    /**
     * Apply an item's gates. Every gate defaults to visible, so a shell included before the
     * fintech layers are loaded degrades to the full menu rather than to a fatal.
     */
    function bc_app_item_visible($item, $user) {
        if (!empty($item['service']) && !bc_app_service_on($item['service'])) return false;
        if (!empty($item['tier']) && !bc_app_tier_service($item['tier'])) return false;
        if (!empty($item['when']) && is_callable($item['when']) && !$item['when']($user)) return false;
        return true;
    }
}

if (!function_exists('bc_app_current_page')) {
    /** The page filename the shell should highlight. */
    function bc_app_current_page() {
        $self = isset($_SERVER['PHP_SELF']) ? (string)$_SERVER['PHP_SELF'] : '';
        return $self !== '' ? basename($self) : '';
    }
}

if (!function_exists('bc_app_url')) {
    /**
     * Resolve a signed-in page filename against the site base.
     *
     * Every console page lives under /web in both editions, so the segment is added here
     * rather than repeated in the menu. Query strings are preserved.
     */
    function bc_app_url($href) {
        $base  = rtrim(bc_app_base(), '/');
        $parts = explode('?', (string)$href, 2);
        $path  = '/web/' . ltrim($parts[0], '/');
        return $base . $path . (isset($parts[1]) ? '?' . $parts[1] : '');
    }
}

if (!function_exists('bc_app_root_url')) {
    /** Resolve a file that lives at the edition root (e.g. logout.php). */
    function bc_app_root_url($href) {
        $base  = rtrim(bc_app_base(), '/');
        $parts = explode('?', (string)$href, 2);
        $path  = '/' . ltrim($parts[0], '/');
        return $base . $path . (isset($parts[1]) ? '?' . $parts[1] : '');
    }
}

if (!function_exists('bc_app_icon')) {
    /** bootstrap-icons glyph. The shell loads the icon font, so no inline SVG is needed here. */
    function bc_app_icon($name, $class = '') {
        $name = preg_replace('/[^a-z0-9-]/', '', (string)$name);
        if ($name === '') $name = 'circle';
        return '<i class="bi bi-' . $name . ($class !== '' ? ' ' . $class : '') . '" aria-hidden="true"></i>';
    }
}

if (!function_exists('bc_app_css')) {
    /**
     * The shell stylesheet. Printed once by bc_app_head(); kept as a separate function so the
     * design tokens have exactly one definition.
     *
     * It carries two layers:
     *   1. the shell itself (rail, navigation, header, content column, cards, utilities);
     *   2. a Bootstrap-compat layer scoped to .pk-app__wrap, so a page that still prints
     *      `card` / `row` / `btn` / `alert` markup keeps rendering while it is being restyled.
     */
    function bc_app_css($brand) {
        $bc_brand_rgb = '15, 118, 110';
        if (is_string($brand) && preg_match('/^#([0-9a-fA-F]{2})([0-9a-fA-F]{2})([0-9a-fA-F]{2})$/', $brand, $bc_rgb_m)) {
            $bc_brand_rgb = hexdec($bc_rgb_m[1]) . ', ' . hexdec($bc_rgb_m[2]) . ', ' . hexdec($bc_rgb_m[3]);
        }
        // §28 contrast guardrail: text ON the brand colour must stay readable for
        // ANY tenant-picked brand. A light brand (e.g. amber) gets dark text; a
        // dark brand keeps white. --brand-ink is the brand darkened enough to be
        // readable as text on white surfaces.
        require_once __DIR__ . '/bc-theme.php';
        $bc_palette = bc_theme_palette($brand);
        $bc_brand_fg = $bc_palette['modes']['light']['on_primary'];
        $bc_brand_ink = $bc_palette['modes']['light']['primary_text'];
        ?><style>
        :root {
            --brand: <?php echo $brand; ?>;
            /* Legacy aliases. Pages written against Bootstrap 5 or the retired template
               reference --bs-primary / --primary-color; without them those rules compute
               to nothing and themed elements (pills, badges, focus rings) lose their
               background while keeping white text. */
            --primary-color: var(--brand);
            --bs-primary: var(--brand);
            --bs-primary-rgb: <?php echo $bc_brand_rgb; ?>;
            --danger: var(--bad);
            --info: #0284c7;
            --ok-rgb: 5, 150, 105;
            --bad-rgb: 220, 38, 38;
            --warn-rgb: 217, 119, 6;
            --info-rgb: 2, 132, 199;
            --ink-rgb: 11, 18, 32;
            --muted-rgb: 100, 116, 139;
            --wash-rgb: 246, 248, 251;
            /* Derived, so a tenant's own brand colour never gets a teal hover state. */
            --brand-dark: color-mix(in srgb, var(--brand) 82%, #000);
            --brand-fg: <?php echo $bc_brand_fg; ?>;
            --brand-ink: <?php echo $bc_brand_ink; ?>;
            --ink: #0b1220;
            --muted: #64748b;
            --line: #e2e8f0;
            --surface: #ffffff;
            --wash: #f6f8fb;
            --ok: #059669;
            --bad: #dc2626;
            --warn: #d97706;
            --radius: 16px;
            --shadow: 0 18px 44px rgba(16, 24, 40, .08);
            --side-w: 268px;
        }
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
        html { -webkit-text-size-adjust: 100%; }
        body {
            font-family: 'Inter', system-ui, -apple-system, "Segoe UI", sans-serif;
            color: var(--ink); background: var(--wash); min-height: 100vh;
            line-height: 1.6; overflow-x: hidden;
        }
        a { color: var(--brand-ink); }
        img { max-width: 100%; }
        /* Soft brand wash instead of the old flat grey. */
        .pk-app__bg {
            position: fixed; inset: 0; z-index: -1; pointer-events: none;
            background:
                radial-gradient(760px 420px at 8% -6%, rgba(<?php echo $bc_brand_rgb; ?>, .14), transparent 70%),
                radial-gradient(720px 460px at 94% 6%, rgba(<?php echo $bc_brand_rgb; ?>, .10), transparent 70%),
                var(--wash);
        }
        /* ── Rail ─────────────────────────────────────────────────────────── */
        .pk-app__side {
            position: fixed; top: 0; left: 0; bottom: 0; width: var(--side-w); z-index: 60;
            background: var(--surface); border-right: 1px solid var(--line);
            display: flex; flex-direction: column; transition: transform .22s ease;
        }
        .pk-app__brand {
            display: flex; align-items: center; gap: 10px; padding: 16px 18px;
            border-bottom: 1px solid var(--line); text-decoration: none; color: var(--ink);
        }
        .pk-app__mark {
            width: 36px; height: 36px; border-radius: 10px; background: var(--brand); color: var(--brand-fg);
            display: grid; place-items: center; font-weight: 800; font-size: .95rem; flex: 0 0 auto;
        }
        .pk-app__brand img { width: 36px; height: 36px; border-radius: 10px; object-fit: cover; }
        .pk-app__brandtxt { font-weight: 800; font-size: 1rem; letter-spacing: -.01em; line-height: 1.2; }
        .pk-app__brandsub { display: block; font-size: .68rem; font-weight: 700; letter-spacing: .12em;
            text-transform: uppercase; color: var(--muted); }
        .pk-app__nav { flex: 1; overflow-y: auto; padding: 12px 14px 18px; }
        .pk-app__nav::-webkit-scrollbar { width: 8px; }
        .pk-app__nav::-webkit-scrollbar-thumb { background: var(--line); border-radius: 8px; }
        .pk-app__glink {
            display: flex; align-items: center; gap: 10px; width: 100%; padding: 10px 12px;
            border: 0; background: none; border-radius: 10px; color: var(--ink); cursor: pointer;
            font: inherit; font-weight: 650; font-size: .92rem; text-decoration: none; text-align: left;
        }
        .pk-app__glink:hover { background: var(--wash); }
        .pk-app__glink.is-active { background: rgba(<?php echo $bc_brand_rgb; ?>, .10); color: var(--brand-ink); }
        .pk-app__gicon {
            width: 27px; height: 27px; border-radius: 8px; background: var(--wash); color: var(--brand-ink);
            display: grid; place-items: center; font-size: .92rem; flex: 0 0 auto;
        }
        .pk-app__glink.is-active .pk-app__gicon { background: var(--brand); color: var(--brand-fg); }
        .pk-app__glabel { flex: 1; }
        .pk-app__chev { color: var(--muted); font-size: .7rem; transition: transform .18s ease; }
        .pk-app__glink[aria-expanded="true"] .pk-app__chev { transform: rotate(90deg); }
        .pk-app__badge {
            font-size: .62rem; font-weight: 800; letter-spacing: .06em; color: var(--brand-ink);
            background: rgba(<?php echo $bc_brand_rgb; ?>, .10); border: 1px solid rgba(<?php echo $bc_brand_rgb; ?>, .22); border-radius: 999px; padding: 1px 6px;
        }
        .pk-app__sub { list-style: none; padding: 2px 0 8px 47px; display: none; }
        .pk-app__sub.is-open { display: block; }
        .pk-app__slink {
            display: flex; align-items: center; gap: 8px; padding: 7px 10px; border-radius: 8px;
            color: var(--muted); text-decoration: none; font-size: .86rem; font-weight: 600;
        }
        .pk-app__slink:hover { color: var(--brand-ink); background: var(--wash); }
        .pk-app__slink.is-active { color: var(--brand-ink); background: rgba(<?php echo $bc_brand_rgb; ?>, .10); }
        .pk-app__user {
            display: flex; align-items: center; gap: 10px; padding: 13px 16px;
            border-top: 1px solid var(--line); text-decoration: none; color: var(--ink);
        }
        .pk-app__avatar {
            width: 34px; height: 34px; border-radius: 50%; background: var(--brand); color: var(--brand-fg);
            display: grid; place-items: center; font-weight: 800; font-size: .85rem; flex: 0 0 auto;
        }
        .pk-app__usertxt { min-width: 0; }
        .pk-app__username { display: block; font-weight: 700; font-size: .86rem;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .pk-app__userplan { display: block; font-size: .72rem; color: var(--muted); }
        /* ── Column ───────────────────────────────────────────────────────── */
        .pk-app__main { margin-left: var(--side-w); min-height: 100vh; display: flex; flex-direction: column; }
        .pk-app__top {
            position: sticky; top: 0; z-index: 40; display: flex; align-items: center; gap: 12px;
            padding: 13px 26px; background: rgba(255, 255, 255, .88);
            backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--line);
        }
        .pk-app__burger {
            display: none; align-items: center; justify-content: center; width: 38px; height: 38px;
            border: 1px solid var(--line); border-radius: 10px; background: #fff; color: var(--ink);
            cursor: pointer; font-size: 1.05rem;
        }
        .pk-app__top > div:not(.pk-app__actions) { min-width: 0; flex: 1 1 auto; }
        .pk-app__title { font-size: 1.04rem; font-weight: 800; letter-spacing: -.01em; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .pk-app__crumb { color: var(--muted); font-size: .82rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .pk-app__actions { margin-left: auto; display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
        .pk-app__iconbtn {
            position: relative; display: inline-flex; align-items: center; gap: 7px; height: 38px;
            padding: 0 12px; border: 1px solid var(--line); border-radius: 10px; background: #fff;
            color: var(--ink); cursor: pointer; font: inherit; font-size: .85rem; font-weight: 650;
            text-decoration: none;
        }
        .pk-app__iconbtn:hover { background: var(--wash); }
        .pk-app__iconbtn i { font-size: 1rem; }
        .pk-app__dot {
            position: absolute; top: -5px; right: -5px; min-width: 17px; height: 17px; padding: 0 4px;
            border-radius: 999px; background: var(--bad); color: #fff; font-size: .64rem; font-weight: 800;
            display: grid; place-items: center;
        }
        .pk-app__profile { position: relative; }
        .pk-app__menu {
            position: absolute; right: 0; top: calc(100% + 8px); min-width: 230px; max-width: calc(100vw - 24px); padding: 8px;
            background: #fff; border: 1px solid var(--line); border-radius: 12px; box-shadow: var(--shadow);
            display: none; z-index: 70;
        }
        .pk-app__menu.is-open { display: block; }
        .pk-app__menuhead { padding: 8px 10px 10px; border-bottom: 1px solid var(--line); margin-bottom: 6px; }
        .pk-app__menuhead strong { display: block; font-size: .9rem; overflow-wrap: anywhere; }
        .pk-app__menuhead span { display: block; font-size: .76rem; color: var(--muted); }
        .pk-app__mlink {
            display: flex; align-items: center; gap: 9px; padding: 9px 10px; border-radius: 8px;
            color: var(--ink); text-decoration: none; font-size: .88rem; font-weight: 600;
        }
        .pk-app__mlink:hover { background: var(--wash); color: var(--brand); }
        .pk-app__mlink i { color: var(--muted); }
        .pk-app__mlink.pk-app__mlink--danger { color: var(--bad); }
        .pk-app__content { flex: 1; padding: 24px 26px 40px; }
        .pk-app__wrap { max-width: 1180px; margin: 0 auto; }
        .pk-app__foot {
            display: flex; flex-wrap: wrap; align-items: center; gap: 4px; padding: 16px 26px 22px;
            border-top: 1px solid var(--line); background: var(--surface);
            color: var(--muted); font-size: .8rem;
            /* The footer is printed after the offset content column, so it must clear the
               fixed sidebar itself — otherwise its first ~268px hides under the rail and
               only the tail of the last link shows (the reported "Guide" leak). */
            margin-left: var(--side-w);
        }
        .pk-app__foot a { color: var(--muted); margin-left: 10px; }
        @media (max-width: 640px) { .pk-app__hide-sm { display: none; } }
        @media (max-width: 767.98px) {
            .pk-app__profile .pk-app__hide-sm { display: none; }
            .pk-app__profile .pk-app__iconbtn {
                width: 38px; height: 38px; padding: 0; gap: 0;
                justify-content: center; border-radius: 999px;
            }
            .pk-app__profile .pk-app__avatar { width: 32px; height: 32px; font-size: .82rem; }
        }
        .pk-app__scrim {
            position: fixed; inset: 0; z-index: 55; background: rgba(11, 18, 32, .42);
            opacity: 0; pointer-events: none; transition: opacity .2s ease;
        }
        .pk-app__scrim.is-open { opacity: 1; pointer-events: auto; }
        /* ── Bootstrap-compat layer ─────────────────────────────────────────
           Migrated pages still print the old utility classes around their markup. This layer is
           a deliberate subset — the grid, the spacing scale, the components the console uses —
           and it is scoped to the content column so it can never leak into the shell. Items it
           does not know are simply ignored, so an unmigrated page degrades to plain markup
           rather than to a broken layout. */
        /* Grid */
        .pk-app__wrap .row { display: flex; flex-wrap: wrap; gap: 14px; }
        .pk-app__wrap .row > * { min-width: 0; }
        /* \u00a737: grid columns the pages use that the compat layer never
           defined \u2014 they fell back to content-sized flex items, so the
           settings rail/content split squeezed or wrapped unpredictably
           (worst on mobile). Placed BEFORE .col-6/.col-md-* so elements
           combining classes (col-6 col-lg-3 stat cards) keep the winner
           they have today. */
        .pk-app__wrap .col-md-2 { flex: 1 1 130px; }
        .pk-app__wrap .col-md-9 { flex: 1 1 620px; }
        .pk-app__wrap .col-lg-1 { flex: 1 1 90px; }
        .pk-app__wrap .col-lg-2 { flex: 1 1 150px; }
        .pk-app__wrap .col-lg-3 { flex: 1 1 230px; }
        .pk-app__wrap .col-lg-5 { flex: 1 1 340px; }
        .pk-app__wrap .col-lg-6 { flex: 1 1 400px; }
        .pk-app__wrap .col-lg-7 { flex: 1 1 470px; }
        .pk-app__wrap .col-lg-9 { flex: 1 1 620px; }
        .pk-app__wrap .col-lg-10 { flex: 1 1 760px; }
        .pk-app__wrap .col-lg-11 { flex: 1 1 840px; }
        .pk-app__wrap .col-lg-12 { flex: 1 1 100%; }
        .pk-app__wrap .col { flex: 1 1 0; }
        .pk-app__wrap .col-6 { flex: 0 0 calc(50% - 7px); max-width: calc(50% - 7px); }
        .pk-app__wrap .col-12, .pk-app__wrap .col-md-12 { flex: 1 1 100%; }
        .pk-app__wrap .col-md-3 { flex: 1 1 180px; }
        .pk-app__wrap .col-md-4 { flex: 1 1 240px; }
        .pk-app__wrap .col-md-5 { flex: 1 1 300px; }
        .pk-app__wrap .col-md-6 { flex: 1 1 300px; }
        .pk-app__wrap .col-md-7 { flex: 1 1 420px; }
        .pk-app__wrap .col-md-8 { flex: 1 1 480px; }
        .pk-app__wrap .col-lg-4 { flex: 1 1 260px; }
        .pk-app__wrap .col-lg-8 { flex: 1 1 560px; }
        .pk-app__wrap .row-cols-2 > * { flex: 1 1 calc(50% - 7px); }
        .pk-app__wrap .row-cols-3 > * { flex: 1 1 calc(33.3333% - 10px); }
        .pk-app__wrap .row-cols-4 > * { flex: 1 1 calc(25% - 11px); }
        .pk-app__wrap .row-cols-sm-3 > *, .pk-app__wrap .row-cols-md-3 > * { flex: 1 1 calc(33.3333% - 10px); }
        .pk-app__wrap .row-cols-md-4 > *, .pk-app__wrap .row-cols-xl-4 > * { flex: 1 1 calc(25% - 11px); }
        .pk-app__wrap .g-2 { gap: 8px; } .pk-app__wrap .g-3 { gap: 14px; }
        .pk-app__wrap .g-4 { gap: 20px; } .pk-app__wrap .g-5 { gap: 26px; }
        .pk-app__wrap .gx-2 { column-gap: 8px; } .pk-app__wrap .gy-3 { row-gap: 14px; }
        /* Display and flex */
        .pk-app__wrap .d-none { display: none !important; }
        .pk-app__wrap .d-block { display: block; }
        .pk-app__wrap .d-flex { display: flex; }
        .pk-app__wrap .d-inline-flex { display: inline-flex; }
        .pk-app__wrap .d-md-block { display: block; }
        .pk-app__wrap .d-md-none { display: none; }
        .pk-app__wrap .flex-column { flex-direction: column; }
        .pk-app__wrap .flex-wrap { flex-wrap: wrap; }
        .pk-app__wrap .flex-grow-1 { flex: 1; }
        .pk-app__wrap .flex-shrink-0 { flex-shrink: 0; }
        .pk-app__wrap .align-items-center { align-items: center; }
        .pk-app__wrap .align-items-start { align-items: flex-start; }
        .pk-app__wrap .align-items-end { align-items: flex-end; }
        .pk-app__wrap .justify-content-center { justify-content: center; }
        .pk-app__wrap .justify-content-between { justify-content: space-between; }
        .pk-app__wrap .justify-content-end { justify-content: flex-end; }
        .pk-app__wrap .gap-1 { gap: 4px; } .pk-app__wrap .gap-2 { gap: 8px; }
        .pk-app__wrap .gap-3 { gap: 14px; } .pk-app__wrap .gap-4 { gap: 20px; }
        /* Spacing */
        .pk-app__wrap .m-0 { margin: 0; } .pk-app__wrap .mt-0 { margin-top: 0; }
        .pk-app__wrap .mt-1 { margin-top: 4px; } .pk-app__wrap .mt-2 { margin-top: 8px; }
        .pk-app__wrap .mt-3 { margin-top: 12px; } .pk-app__wrap .mt-4 { margin-top: 16px; }
        .pk-app__wrap .mt-5 { margin-top: 24px; } .pk-app__wrap .mb-0 { margin-bottom: 0; }
        .pk-app__wrap .mb-1 { margin-bottom: 4px; } .pk-app__wrap .mb-2 { margin-bottom: 8px; }
        .pk-app__wrap .mb-3 { margin-bottom: 12px; } .pk-app__wrap .mb-4 { margin-bottom: 16px; }
        .pk-app__wrap .mb-5 { margin-bottom: 24px; } .pk-app__wrap .my-2 { margin-top: 8px; margin-bottom: 8px; }
        .pk-app__wrap .my-3 { margin-top: 12px; margin-bottom: 12px; }
        .pk-app__wrap .ms-1 { margin-left: 4px; } .pk-app__wrap .ms-2 { margin-left: 8px; }
        .pk-app__wrap .ms-3 { margin-left: 12px; } .pk-app__wrap .ms-auto { margin-left: auto; }
        .pk-app__wrap .me-1 { margin-right: 4px; } .pk-app__wrap .me-2 { margin-right: 8px; }
        .pk-app__wrap .me-3 { margin-right: 12px; } .pk-app__wrap .p-0 { padding: 0; }
        .pk-app__wrap .p-2 { padding: 8px; } .pk-app__wrap .p-3 { padding: 14px; }
        .pk-app__wrap .p-4 { padding: 18px; } .pk-app__wrap .p-5 { padding: 26px; }
        .pk-app__wrap .py-1 { padding-top: 4px; padding-bottom: 4px; }
        .pk-app__wrap .py-2 { padding-top: 8px; padding-bottom: 8px; }
        .pk-app__wrap .py-3 { padding-top: 14px; padding-bottom: 14px; }
        .pk-app__wrap .px-2 { padding-left: 8px; padding-right: 8px; }
        .pk-app__wrap .px-3 { padding-left: 14px; padding-right: 14px; }
        .pk-app__wrap .px-4 { padding-left: 18px; padding-right: 18px; }
        .pk-app__wrap .ps-2 { padding-left: 8px; } .pk-app__wrap .pe-3 { padding-right: 12px; }
        .pk-app__wrap .w-100 { width: 100%; } .pk-app__wrap .h-100 { height: 100%; }
        .pk-app__wrap .mw-100 { max-width: 100%; }
        /* Position and overflow */
        .pk-app__wrap .position-relative { position: relative; }
        .pk-app__wrap .position-absolute { position: absolute; }
        .pk-app__wrap .top-0 { top: 0; } .pk-app__wrap .end-0 { right: 0; }
        .pk-app__wrap .bottom-0 { bottom: 0; } .pk-app__wrap .start-0 { left: 0; }
        .pk-app__wrap .z-1 { position: relative; z-index: 1; }
        .pk-app__wrap .overflow-hidden { overflow: hidden; }
        .pk-app__wrap .text-center { text-align: center; }
        .pk-app__wrap .text-start { text-align: left; }
        .pk-app__wrap .text-end { text-align: right; }
        .pk-app__wrap .text-md-start { text-align: left; }
        .pk-app__wrap .justify-content-md-start { justify-content: flex-start; }
        .pk-app__wrap .mb-md-0 { margin-bottom: 0; }
        .pk-app__wrap .py-md-5 { padding-top: 26px; padding-bottom: 26px; }
        /* Typography */
        .pk-app__wrap .fw-bold { font-weight: 700; }
        .pk-app__wrap .fw-semibold { font-weight: 600; }
        .pk-app__wrap .fst-italic { font-style: italic; }
        .pk-app__wrap .small { font-size: .85rem; }
        .pk-app__wrap .x-small { font-size: .74rem; }
        .pk-app__wrap .lead { font-size: 1.05rem; }
        .pk-app__wrap .fs-2 { font-size: 1.6rem; } .pk-app__wrap .fs-3 { font-size: 1.35rem; }
        .pk-app__wrap .fs-4 { font-size: 1.2rem; } .pk-app__wrap .fs-5 { font-size: 1.1rem; }
        .pk-app__wrap .h3 { font-size: 1.4rem; font-weight: 700; }
        .pk-app__wrap .h4 { font-size: 1.2rem; font-weight: 700; }
        .pk-app__wrap .h5 { font-size: 1.05rem; font-weight: 700; }
        .pk-app__wrap .h6 { font-size: .95rem; font-weight: 700; }
        /* Colour and surface */
        .pk-app__wrap .text-muted { color: var(--muted) !important; }
        .pk-app__wrap .text-dark { color: var(--ink) !important; }
        .pk-app__wrap .text-white { color: #fff !important; }
        .pk-app__wrap .text-primary { color: var(--brand-ink) !important; }
        .pk-app__wrap .text-secondary { color: var(--muted) !important; }
        .pk-app__wrap .text-success { color: var(--ok) !important; }
        .pk-app__wrap .text-danger { color: var(--bad) !important; }
        .pk-app__wrap .text-warning { color: var(--warn) !important; }
        .pk-app__wrap .text-info { color: var(--info) !important; }
        .pk-app__wrap .text-dark-primary { color: var(--brand-ink) !important; }
        .pk-app__wrap .text-body { color: var(--ink) !important; }
        /* Background utilities follow Bootstrap semantics: bg-opacity-* tints the
           element's own bg-* colour (via --bs-bg-opacity). The previous white-tint
           version made bg-<colour> bg-opacity-10 badges/buttons render white-on-white. */
        .pk-app__wrap .bg-light { background-color: rgba(var(--wash-rgb), var(--bs-bg-opacity, 1)) !important; }
        .pk-app__wrap .bg-white { background-color: rgba(255, 255, 255, var(--bs-bg-opacity, 1)) !important; }
        .pk-app__wrap .bg-primary { background-color: rgba(var(--bs-primary-rgb), var(--bs-bg-opacity, 1)) !important; color: var(--brand-fg); }
        .pk-app__wrap .bg-secondary { background-color: rgba(var(--muted-rgb), var(--bs-bg-opacity, 1)) !important; }
        .pk-app__wrap .bg-success { background-color: rgba(var(--ok-rgb), var(--bs-bg-opacity, 1)) !important; color: #fff; }
        .pk-app__wrap .bg-danger { background-color: rgba(var(--bad-rgb), var(--bs-bg-opacity, 1)) !important; color: #fff; }
        .pk-app__wrap .bg-warning { background-color: rgba(var(--warn-rgb), var(--bs-bg-opacity, 1)) !important; color: #fff; }
        .pk-app__wrap .bg-info { background-color: rgba(var(--info-rgb), var(--bs-bg-opacity, 1)) !important; color: #fff; }
        .pk-app__wrap .bg-dark { background-color: rgba(var(--ink-rgb), var(--bs-bg-opacity, 1)) !important; color: #fff; }
        .pk-app__wrap .bg-transparent { background: transparent !important; }
        .pk-app__wrap .bg-opacity-10 { --bs-bg-opacity: .1; }
        .pk-app__wrap .bg-opacity-20 { --bs-bg-opacity: .2; }
        .pk-app__wrap .bg-opacity-25 { --bs-bg-opacity: .25; }
        .pk-app__wrap .bg-opacity-50 { --bs-bg-opacity: .5; }
        .pk-app__wrap .bg-opacity-75 { --bs-bg-opacity: .75; }
        .pk-app__wrap .bg-gradient { background-image: linear-gradient(135deg, var(--brand), var(--brand-dark)) !important; }
        .pk-app__wrap .opacity-75 { opacity: .75; } .pk-app__wrap .opacity-50 { opacity: .5; }
        .pk-app__wrap .opacity-25 { opacity: .25; } .pk-app__wrap .opacity-10 { opacity: .1; }
        /* Borders and radius */
        .pk-app__wrap .border { border: 1px solid var(--line); }
        .pk-app__wrap .border-0 { border: 0; }
        .pk-app__wrap .border-light { border-color: var(--line) !important; }
        .pk-app__wrap .border-white { border-color: #fff !important; }
        .pk-app__wrap .border-start { border-left: 1px solid var(--line) !important; }
        .pk-app__wrap .border-success { border-color: rgba(var(--ok-rgb), var(--bs-border-opacity, 1)) !important; }
        .pk-app__wrap .border-danger { border-color: rgba(var(--bad-rgb), var(--bs-border-opacity, 1)) !important; }
        .pk-app__wrap .border-warning { border-color: rgba(var(--warn-rgb), var(--bs-border-opacity, 1)) !important; }
        .pk-app__wrap .border-primary { border-color: rgba(var(--bs-primary-rgb), var(--bs-border-opacity, 1)) !important; }
        .pk-app__wrap .border-info { border-color: rgba(var(--info-rgb), var(--bs-border-opacity, 1)) !important; }
        .pk-app__wrap .border-4 { border-width: 4px !important; }
        .pk-app__wrap .border-5 { border-width: 5px !important; }
        .pk-app__wrap .border-opacity-10 { --bs-border-opacity: .1; }
        .pk-app__wrap .border-opacity-25 { --bs-border-opacity: .25; }
        .pk-app__wrap .border-opacity-50 { --bs-border-opacity: .5; }
        .pk-app__wrap .rounded { border-radius: 10px; }
        .pk-app__wrap .rounded-2 { border-radius: 10px; }
        .pk-app__wrap .rounded-3 { border-radius: 12px; }
        .pk-app__wrap .rounded-4 { border-radius: var(--radius); }
        .pk-app__wrap .rounded-5 { border-radius: 22px; }
        .pk-app__wrap .rounded-pill { border-radius: 999px; }
        .pk-app__wrap .rounded-circle { border-radius: 50%; }
        .pk-app__wrap .shadow { box-shadow: var(--shadow); }
        .pk-app__wrap .shadow-sm { box-shadow: 0 8px 24px rgba(16, 24, 40, .06); }
        .pk-app__wrap .shadow-lg { box-shadow: 0 24px 60px rgba(16, 24, 40, .16); }
        .pk-app__wrap .backdrop-blur { backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); }
        /* Page header */
        .pk-app__wrap .pagetitle { margin-bottom: 18px; }
        .pk-app__wrap .pagetitle h1 { font-size: 1.12rem; font-weight: 800; letter-spacing: .04em;
            text-transform: uppercase; margin-bottom: 4px; }
        .pk-app__wrap .breadcrumb { display: flex; flex-wrap: wrap; gap: 6px; list-style: none;
            color: var(--muted); font-size: .8rem; }
        .pk-app__wrap .breadcrumb-item + .breadcrumb-item::before { content: "/"; margin-right: 6px; color: var(--line); }
        .pk-app__wrap .breadcrumb-item.active { color: var(--ink); font-weight: 600; }
        .pk-app__wrap .section { padding: 0; }
        /* Cards */
        .pk-app__wrap .card {
            background: #fff; border: 1px solid var(--line); border-radius: var(--radius);
            box-shadow: 0 8px 24px rgba(16, 24, 40, .05); overflow: hidden;
        }
        .pk-app__wrap .card-body { padding: 18px; }
        .pk-app__wrap .card-header { padding: 14px 18px; border-bottom: 1px solid var(--line);
            font-weight: 700; background: #fff; }
        .pk-app__wrap .card-footer { padding: 14px 18px; border-top: 1px solid var(--line); background: var(--wash); }
        .pk-app__wrap .card-blue, .pk-app__wrap .card-primary { background: var(--brand); color: var(--brand-fg); }
        /* Preserve HTML5 hidden attribute even when component classes set display (e.g. .btn { display: inline-flex }) */
        .pk-app__wrap [hidden] { display: none !important; }
        /* Buttons */
        .pk-app__wrap .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 7px; font: inherit;
            font-size: .92rem; font-weight: 700; line-height: 1.2; padding: 10px 16px; cursor: pointer;
            border: 1px solid transparent; border-radius: 10px; background: var(--wash); color: var(--ink);
            text-decoration: none; transition: background .15s ease, color .15s ease;
        }
        .pk-app__wrap .btn:hover { background: #eceff4; }
        .pk-app__wrap .btn-primary { background: var(--brand); color: var(--brand-fg); }
        .pk-app__wrap .btn-primary:hover { background: var(--brand-dark); color: var(--brand-fg); }
        .pk-app__wrap .btn-secondary { background: #e2e8f0; color: var(--ink); }
        .pk-app__wrap .btn-success { background: var(--ok); color: #fff; }
        .pk-app__wrap .btn-danger { background: var(--bad); color: #fff; }
        .pk-app__wrap .btn-warning { background: var(--warn); color: #fff; }
        .pk-app__wrap .btn-dark { background: var(--ink); color: #fff; }
        .pk-app__wrap .btn-light { background: #fff; color: var(--ink); border-color: var(--line); }
        .pk-app__wrap .btn-outline-primary { background: transparent; color: var(--brand-ink); border-color: var(--brand); }
        .pk-app__wrap .btn-outline-primary:hover { background: var(--brand); color: var(--brand-fg); }
        .pk-app__wrap .btn-outline-secondary { background: transparent; color: var(--ink); border-color: var(--line); }
        .pk-app__wrap .btn-outline-light { background: transparent; color: #fff; border-color: rgba(255,255,255,.6); }
        .pk-app__wrap .btn-outline-success { background: transparent; color: var(--ok); border-color: var(--ok); }
        .pk-app__wrap .btn-outline-success:hover { background: var(--ok); color: #fff; }
        .pk-app__wrap .btn-outline-danger { background: transparent; color: var(--bad); border-color: var(--bad); }
        .pk-app__wrap .btn-outline-danger:hover { background: var(--bad); color: #fff; }
        .pk-app__wrap .btn-outline-warning { background: transparent; color: var(--warn); border-color: var(--warn); }
        .pk-app__wrap .btn-outline-warning:hover { background: var(--warn); color: #451a03; }
        .pk-app__wrap .btn-outline-info { background: transparent; color: var(--info); border-color: var(--info); }
        .pk-app__wrap .btn-outline-info:hover { background: var(--info); color: #fff; }
        .pk-app__wrap .btn-outline-dark { background: transparent; color: var(--ink); border-color: var(--ink); }
        .pk-app__wrap .btn-outline-dark:hover { background: var(--ink); color: #fff; }
        .pk-app__wrap .btn-link { background: none; color: var(--brand-ink); padding: 0; }
        .pk-app__wrap .btn-sm { padding: 6px 11px; font-size: .82rem; border-radius: 8px; }
        .pk-app__wrap .btn-lg { padding: 13px 22px; font-size: 1rem; }
        .pk-app__wrap .btn-close { border: 0; background: var(--wash); color: var(--muted);
            width: 32px; height: 32px; border-radius: 8px; cursor: pointer; }
        /* Alerts and badges */
        .pk-app__wrap .alert { border-radius: 12px; border: 1px solid; font-size: .9rem;
            padding: 13px 15px; margin-bottom: 16px; }
        .pk-app__wrap .alert-error, .pk-app__wrap .alert-danger { background: #fef2f2; border-color: #fecaca; color: #991b1b; }
        .pk-app__wrap .alert-success, .pk-app__wrap .alert-ok { background: #ecfdf5; border-color: #a7f3d0; color: #065f46; }
        .pk-app__wrap .alert-warning, .pk-app__wrap .alert-warn { background: #fffbeb; border-color: #fde68a; color: #92400e; }
        .pk-app__wrap .alert-info, .pk-app__wrap .alert-primary { background: #eff6ff; border-color: #bfdbfe; color: #1e40af; }
        .pk-app__wrap .alert-secondary { background: var(--wash); border-color: var(--line); color: var(--ink); }
        .pk-app__wrap .badge { display: inline-block; padding: 3px 8px; border-radius: 999px;
            font-size: .72rem; font-weight: 700; background: var(--wash); color: var(--muted); }
        .pk-app__wrap .badge.bg-primary, .pk-app__wrap .badge-primary { background: var(--brand); color: var(--brand-fg); }
        .pk-app__wrap .badge.bg-success, .pk-app__wrap .badge-success { background: var(--ok); color: #fff; }
        .pk-app__wrap .badge.bg-warning, .pk-app__wrap .badge-warning { background: var(--warn); color: #fff; }
        .pk-app__wrap .badge.bg-danger, .pk-app__wrap .badge-danger { background: var(--bad); color: #fff; }
        .pk-app__wrap .badge.bg-secondary, .pk-app__wrap .badge-secondary { background: #e2e8f0; color: var(--ink); }
        .pk-app__wrap .badge-number { position: absolute; top: -6px; right: -6px; }
        .pk-app__wrap .badge-new { background: #fef3c7; color: #92400e; letter-spacing: .04em; }
        /* Tables and forms */
        .pk-app__wrap .table { width: 100%; border-collapse: collapse; font-size: .88rem; }
        .pk-app__wrap .table th { text-align: left; font-size: .72rem; text-transform: uppercase;
            letter-spacing: .06em; color: var(--muted); padding: 10px 12px; border-bottom: 1px solid var(--line); }
        .pk-app__wrap .table td { padding: 11px 12px; border-bottom: 1px solid var(--line); vertical-align: top; }
        .pk-app__wrap .table tr:last-child td { border-bottom: 0; }
        .pk-app__wrap .table-striped tbody tr:nth-child(even) { background: var(--wash); }
        .pk-app__wrap .table-responsive { overflow-x: auto; }
        .pk-app__wrap .form-label { display: block; font-size: .76rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: .08em; color: var(--muted); margin-bottom: 7px; }
        .pk-app__wrap .form-text { color: var(--muted); font-size: .8rem; margin-top: 6px; }
        .pk-app__wrap .form-control, .pk-app__wrap .form-select, .pk-app__wrap .form-control-plaintext {
            width: 100%; font: inherit; font-size: .95rem; color: var(--ink);
            background: var(--wash); border: 1px solid var(--line); border-radius: 10px;
            padding: 11px 13px; outline: none; transition: border-color .15s ease, box-shadow .15s ease, background .15s ease;
        }
        .pk-app__wrap .form-control:focus, .pk-app__wrap .form-select:focus {
            border-color: var(--brand-ink); box-shadow: 0 0 0 3px rgba(<?php echo $bc_brand_rgb; ?>, .14); background: #fff;
        }
        .pk-app__wrap .form-control::placeholder { color: #94a3b8; }
        .pk-app__wrap .input-group { display: flex; }
        .pk-app__wrap .input-group .form-control { border-top-right-radius: 0; border-bottom-right-radius: 0; }
        .pk-app__wrap .input-group .btn { border-top-left-radius: 0; border-bottom-left-radius: 0; }
        .pk-app__wrap .form-check { display: flex; align-items: center; gap: 8px; font-size: .9rem; }
        .pk-app__wrap .form-check-input { width: 16px; height: 16px; accent-color: var(--brand); }
        .pk-app__wrap .progress { display: flex; height: 8px; overflow: hidden; border-radius: 999px; background: #e2e8f0; }
        .pk-app__wrap .progress-bar { display: flex; flex-direction: column; justify-content: center;
            overflow: hidden; color: var(--brand-fg); text-align: center; white-space: nowrap; background-color: var(--brand); }
        /* Modal, dropdown, spinner */
        .pk-app__wrap .modal { position: fixed; inset: 0; z-index: 90; display: none;
            align-items: center; justify-content: center; padding: 20px; background: rgba(11,18,32,.5); }
        .pk-app__wrap .modal.show { display: flex; }
        /* Receipt lives beside main, not in the scrollable history/card tree. */
        .bc-receipt-host .modal { overflow-y: auto; overscroll-behavior: contain; }
        .bc-receipt-host .modal-dialog { margin: auto; max-height: calc(100dvh - 40px); overflow-y: auto; }
        .bc-receipt-host .modal-footer { display: flex; gap: 8px; flex-wrap: wrap; padding-top: 16px; }

        .pk-app__wrap .modal-dialog { width: 100%; max-width: 520px; background: #fff;
            border-radius: var(--radius); box-shadow: var(--shadow); }
        .pk-app__wrap .modal-content { padding: 22px; }
        .pk-app__wrap .modal-header { display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 12px; }
        .pk-app__wrap .modal-title { font-weight: 800; font-size: 1.05rem; }
        .pk-app__wrap .dropdown-menu { min-width: 200px; padding: 8px; list-style: none; background: #fff;
            border: 1px solid var(--line); border-radius: 12px; box-shadow: var(--shadow); }
        .pk-app__wrap .dropdown-item { display: flex; align-items: center; gap: 8px; padding: 9px 10px;
            border-radius: 8px; color: var(--ink); text-decoration: none; font-size: .88rem; font-weight: 600; }
        .pk-app__wrap .dropdown-item:hover { background: var(--wash); color: var(--brand); }
        .pk-app__wrap .dropdown-divider { height: 1px; background: var(--line); margin: 6px 0; }
        .pk-app__wrap .spinner-border { display: inline-block; width: 1rem; height: 1rem;
            border: 2px solid currentColor; border-right-color: transparent; border-radius: 50%;
            animation: pk-app__spin 1s linear infinite; vertical-align: -2px; }
        /* Dashboard primitives the old template carried */
        .pk-app__wrap .balance-hero { padding: 1.25rem; }
        .pk-app__wrap .balance-amount { font-size: 1.75rem; }
        .pk-app__wrap .quick-action-btn { font-size: .78rem; padding: .55rem .4rem; }

        /* ---- Visual pass (2026-11): modern fintech finish for the big console pages ----
           Purely presentational: same markup, richer surfaces. Cards get a soft layered
           shadow and a larger radius, tables read like a ledger, controls get a brand
           focus ring, and the primary action carries the tenant gradient. */
        .pk-app__wrap .card {
            border: 1px solid var(--line); border-radius: 18px; background: var(--surface);
            box-shadow: 0 1px 2px rgba(16, 24, 40, .04), 0 10px 30px -18px rgba(16, 24, 40, .18);
        }
        .pk-app__wrap .card-header {
            background: transparent; font-weight: 700; letter-spacing: .01em;
        }
        .pk-app__wrap .card-title { font-weight: 700; letter-spacing: -.01em; }
        .pk-app__wrap .transition-hover { transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease; }
        .pk-app__wrap .transition-hover:hover {
            transform: translateY(-2px); border-color: color-mix(in srgb, var(--brand) 35%, var(--line));
            box-shadow: 0 2px 4px rgba(16, 24, 40, .05), 0 18px 38px -18px rgba(16, 24, 40, .28);
        }
        .pk-app__wrap .info-card, .pk-app__wrap .sales-card, .pk-app__wrap .revenue-card,
        .pk-app__wrap .customers-card, .pk-app__wrap .balance-hero {
            position: relative; overflow: hidden;
        }
        .pk-app__wrap .balance-hero {
            background: linear-gradient(135deg, var(--brand) 0%, var(--brand-dark) 88%);
            color: #fff; border: none; border-radius: 20px;
            box-shadow: 0 22px 44px -18px color-mix(in srgb, var(--brand) 65%, transparent);
        }
        .pk-app__wrap .balance-hero .text-muted, .pk-app__wrap .balance-hero small { color: rgba(255, 255, 255, .75) !important; }
        .pk-app__wrap .balance-amount { font-weight: 800; letter-spacing: -.02em; }
        .pk-app__wrap .card-icon, .pk-app__wrap .info-card .card-icon {
            display: inline-flex; align-items: center; justify-content: center;
            width: 46px; height: 46px; border-radius: 14px; font-size: 1.25rem;
            background: color-mix(in srgb, var(--brand) 12%, var(--surface)); color: var(--brand);
        }
        .pk-app__wrap .table-responsive {
            border: 1px solid var(--line); border-radius: 14px; overflow: auto; background: var(--surface);
        }
        .pk-app__wrap .table { margin-bottom: 0; }
        .pk-app__wrap .table thead th {
            position: sticky; top: 0; z-index: 1; background: var(--wash);
            color: var(--muted); font-weight: 700; border-bottom: 1px solid var(--line);
            padding: 11px 12px; white-space: nowrap;
        }
        .pk-app__wrap .table tbody tr { transition: background .12s ease; }
        .pk-app__wrap .table tbody tr:hover { background: color-mix(in srgb, var(--brand) 4%, var(--surface)); }
        .pk-app__wrap .table-striped tbody tr:nth-of-type(odd) { background: color-mix(in srgb, var(--wash) 55%, var(--surface)); }
        .pk-app__wrap .table-striped tbody tr:nth-of-type(odd):hover { background: color-mix(in srgb, var(--brand) 5%, var(--surface)); }
        .pk-app__wrap .form-label { font-weight: 600; font-size: .85rem; margin-bottom: 6px; }
        .pk-app__wrap .form-control, .pk-app__wrap .form-select {
            border-radius: 12px; border: 1px solid var(--line); padding: 10px 12px;
            transition: border-color .15s ease, box-shadow .15s ease;
        }
        .pk-app__wrap .form-control:focus, .pk-app__wrap .form-select:focus {
            border-color: var(--brand); outline: none;
            box-shadow: 0 0 0 4px color-mix(in srgb, var(--brand) 16%, transparent);
        }
        .pk-app__wrap .form-check-input { width: 1.05rem; height: 1.05rem; accent-color: var(--brand); }
        .pk-app__wrap .btn {
            border-radius: 12px; font-weight: 600; letter-spacing: .01em;
            transition: transform .15s ease, box-shadow .15s ease, background .15s ease;
        }
        .pk-app__wrap .btn:active { transform: translateY(1px); }
        .pk-app__wrap .btn-primary {
            background: var(--brand);
            border: none; color: var(--brand-fg);
            box-shadow: 0 10px 22px -12px color-mix(in srgb, var(--brand) 75%, transparent);
        }
        .pk-app__wrap .btn-primary:hover { background: var(--brand); color: var(--brand-fg); }
        .pk-app__wrap .btn-primary { border: 1px solid var(--brand-ink); }
        .pk-app__wrap .btn-primary .text-white { color: var(--brand-fg) !important; }
        .pk-app__wrap .btn-primary i, .pk-app__wrap .bg-primary i { color: inherit; }
        .pk-app__wrap .text-primary { color: var(--brand-ink) !important; }
        .pk-app__wrap .btn-outline-primary { border: 1px solid color-mix(in srgb, var(--brand) 45%, var(--line)); color: var(--brand-ink); background: transparent; }
        .pk-app__wrap .btn-outline-primary:hover { background: var(--surface); color: var(--brand-ink); }
        .pk-app__wrap .badge { border-radius: 999px; padding: 5px 10px; font-weight: 700; letter-spacing: .02em; }
        .pk-app__wrap .alert { border-radius: 14px; border: 1px solid var(--line); border-left: 4px solid var(--brand); padding: 14px 16px; }
        .pk-app__wrap .alert-success { border-left-color: var(--ok); }
        .pk-app__wrap .alert-danger, .pk-app__wrap .alert-error { border-left-color: var(--bad); }
        .pk-app__wrap .alert-warning, .pk-app__wrap .alert-warn { border-left-color: var(--warn); }
        .pk-app__wrap .modal-content { border-radius: 20px; border: 1px solid var(--line); box-shadow: var(--shadow); }
        .pk-app__wrap .modal-header { border-bottom: 1px solid var(--line); font-weight: 700; }
        .pk-app__wrap .modal-footer { border-top: 1px solid var(--line); }
        .pk-app__wrap .nav-pills { display: flex; flex-wrap: wrap; gap: 6px; }
        .pk-app__wrap .nav-pills .nav-link {
            border-radius: 999px; padding: 8px 16px; font-weight: 600; font-size: .88rem;
            color: var(--muted); background: var(--wash); border: 1px solid var(--line); text-decoration: none;
        }
        .pk-app__wrap .nav-pills .nav-link.active { background: var(--brand); border-color: var(--brand); color: var(--brand-fg); }
        /* \u00a737: .nav itself never got display:flex, so tab/pill lists
           (the settings SEO sub-tabs) rendered as stacked block lists
           instead of a tab strip. Tabs now form a single scrollable row. */
        .pk-app__wrap .nav { display: flex; flex-wrap: wrap; list-style: none; margin: 0; padding: 0; }
        .pk-app__wrap .nav-tabs { flex-wrap: nowrap; overflow-x: auto; scrollbar-width: none; -webkit-overflow-scrolling: touch; }
        .pk-app__wrap .nav-tabs::-webkit-scrollbar { display: none; }
        .pk-app__wrap .nav-tabs .nav-item { flex: 0 0 auto; }
        .pk-app__wrap .nav-tabs .nav-link { white-space: nowrap; }
        .pk-app__wrap .nav-tabs { border-bottom: 1px solid var(--line); gap: 4px; }
        .pk-app__wrap .nav-tabs .nav-link { border: none; border-bottom: 2px solid transparent; color: var(--muted); font-weight: 600; padding: 10px 14px; text-decoration: none; }
        .pk-app__wrap .nav-tabs .nav-link.active { color: var(--brand); border-bottom-color: var(--brand); }
        .pk-app__wrap .tab-content > .tab-pane { display: none; }
        .pk-app__wrap .tab-content > .tab-pane.active { display: block; }
        .pk-app__wrap .tab-content > .tab-pane.fade { transition: opacity .15s ease; }
        .pk-app__wrap .tab-content > .tab-pane.fade:not(.show) { opacity: 0; }
        .pk-app__wrap .nav .nav-link { cursor: pointer; font: inherit; text-align: left; }
        .pk-app__wrap .progress { height: 8px; border-radius: 999px; background: var(--wash); overflow: hidden; }
        .pk-app__wrap .progress-bar { background: linear-gradient(90deg, var(--brand), var(--brand-dark)); border-radius: 999px; }
        .pk-app__wrap .pagination .page-link { border-radius: 10px !important; border: 1px solid var(--line); color: var(--ink); margin: 0 2px; }
        .pk-app__wrap .pagination .page-item.active .page-link { background: var(--brand); border-color: var(--brand); color: var(--brand-fg); }
        .pk-app__wrap a { color: var(--brand-ink); }
        .pk-app__wrap .text-primary { color: var(--brand-ink) !important; }
        /* Primary surfaces are defined with their matching foreground below. */
        .pk-app__wrap hr { border: 0; border-top: 1px solid var(--line); opacity: 1; }
        @keyframes pk-app__spin { to { transform: rotate(360deg); } }
        @media (max-width: 1080px) {
            .pk-app__side { transform: translateX(-100%); box-shadow: var(--shadow); }
            .pk-app__side.is-open { transform: none; }
            .pk-app__main { margin-left: 0; }
            .pk-app__foot { margin-left: 0; }
            .pk-app__burger { display: inline-flex; }
            .pk-app__content { padding: 18px 14px 34px; }
            .pk-app__top { padding: 12px 14px; }
        }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { transition: none !important; animation-duration: .01ms !important; }
        }

        /* ── Fintech design layer v2 (2026-11) ──────────────────────────────────
           Modernises every shell page without touching page bodies: deeper card
           elevation, gradient primary actions, softer surfaces, and the embossed
           dark matrix used by the section Hub pages. */
        .pk-app__wrap .card {
            border: 1px solid color-mix(in srgb, var(--line) 70%, transparent);
            border-radius: 20px;
            box-shadow: 0 1px 2px rgba(11,18,32,.05), 0 12px 32px -16px rgba(11,18,32,.18);
        }
        .pk-app__wrap .card-header {
            background: linear-gradient(135deg, color-mix(in srgb, var(--brand) 10%, var(--surface)), var(--surface));
            border-bottom: 1px solid var(--line);
            border-radius: 20px 20px 0 0 !important;
            font-weight: 700;
        }
        .pk-app__wrap .btn { border-radius: 12px; }
        .pk-app__wrap .btn-primary {
            background: var(--brand) !important;
            border: none; color: var(--brand-fg) !important;
            box-shadow: 0 8px 20px -8px color-mix(in srgb, var(--brand) 80%, transparent);
        }
        .pk-app__wrap .btn-primary:hover { filter: none; transform: translateY(-1px); }
        .pk-app__wrap .form-control, .pk-app__wrap .form-select, .pk-app__wrap .input-group-text {
            border-radius: 12px;
        }
        .pk-app__wrap .badge { border-radius: 999px; font-weight: 700; letter-spacing: .01em; }

        /* Embossed dark matrix: section hubs + service pickers. The card sits on a
           subtle dark slate with an inset top highlight and a deep outer shadow,
           which reads as pressed/embossed rather than flat. */
        /* In the admin shell <main class="pk-app__wrap"> is a flex item of
           .pk-app__main; its margin:0 auto otherwise shrink-wraps it to the
           widest card and the matrix collapses to a single column. */
        .pk-app__main > .pk-app__wrap { width: 100%; }
        .pk-matrix {
            display: grid; gap: 16px; width: 100%;
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
        @media (max-width: 1080px) {
            .pk-matrix { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 640px) {
            .pk-matrix { grid-template-columns: minmax(0, 1fr); }
        }
        .pk-matrix__card {
            position: relative; display: flex; align-items: center; gap: 14px;
            padding: 20px 18px; border-radius: 18px; text-decoration: none;
            color: #e2e8f0;
            background: linear-gradient(145deg, #16213a 0%, #0b1220 60%, #0d1526 100%);
            border: 1px solid rgba(148, 163, 184, .18);
            box-shadow:
                inset 0 1px 0 rgba(255,255,255,.10),
                inset 0 -10px 18px rgba(0,0,0,.35),
                0 14px 28px -12px rgba(2, 6, 23, .55);
            transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
        }
        .pk-matrix__card:hover {
            transform: translateY(-3px); color: #fff; text-decoration: none;
            border-color: color-mix(in srgb, var(--brand) 65%, rgba(148,163,184,.18));
            box-shadow:
                inset 0 1px 0 rgba(255,255,255,.14),
                inset 0 -10px 18px rgba(0,0,0,.35),
                0 22px 38px -14px rgba(2, 6, 23, .65);
        }
        .pk-matrix__icon {
            width: 46px; height: 46px; flex: 0 0 46px; border-radius: 14px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 1.25rem; color: #fff;
            background: linear-gradient(135deg, var(--brand), var(--brand-dark));
            box-shadow: inset 0 1px 0 rgba(255,255,255,.25), 0 8px 16px -8px rgba(0,0,0,.8);
        }
        .pk-matrix__label { font-weight: 700; font-size: .95rem; line-height: 1.25; color: #f8fafc; }
        /* The generic `.pk-app__wrap a { color: var(--brand) }` rule above outranks
           .pk-matrix__card (0,1,1 beats 0,1,0), so on sites whose brand colour is
           dark the card labels inherited it and vanished against the dark card.
           Pin every card text colour explicitly. */
        .pk-app__wrap .pk-matrix__card { color: #e2e8f0; }
        .pk-app__wrap .pk-matrix__card:hover { color: #fff; }
        .pk-app__wrap .pk-matrix__label { color: #f8fafc; }
        .pk-app__wrap .pk-matrix__label small { color: rgba(226, 232, 240, .72); }
        .pk-app__wrap .pk-matrix__go { color: #e2e8f0; }
        .pk-matrix__badge {
            position: absolute; top: 10px; right: 12px;
            background: var(--warn); color: #fff; font-size: .62rem; font-weight: 800;
            padding: 2px 8px; border-radius: 999px; letter-spacing: .05em;
        }
        .pk-matrix__go { margin-left: auto; opacity: .45; font-size: 1.05rem; }
        .pk-matrix__card:hover .pk-matrix__go { opacity: 1; }
        .pk-matrix__empty {
            padding: 28px; border-radius: 18px; text-align: center; color: var(--muted);
            background: var(--wash); border: 1px dashed var(--line);
        }
        .pk-hub__hero {
            border-radius: 22px; padding: 30px 28px; margin-bottom: 22px; color: #fff;
            background:
                radial-gradient(600px 220px at 85% -10%, color-mix(in srgb, var(--brand) 55%, transparent), transparent 60%),
                linear-gradient(135deg, #0b1220 0%, #101c33 55%, color-mix(in srgb, var(--brand-dark) 55%, #0b1220) 100%);
            box-shadow: inset 0 1px 0 rgba(255,255,255,.12), 0 20px 40px -18px rgba(2,6,23,.7);
        }
        .pk-hub__hero h1 { font-size: 1.55rem; font-weight: 800; margin: 0 0 6px; display: flex; align-items: center; gap: 12px; }
        .pk-hub__hero p { margin: 0; opacity: .75; font-size: .92rem; }

        /* Pay Bills: carrier picker + purchase card. The tick*Carrier() functions
           in jsfile/bc-custom-all.js toggle .selected on these images and gate
           #proceedBtn; the sizing/selected styles used to live in the retired
           assets-2 stylesheet. */
        .pk-app__wrap .carrier-grid { display: flex; flex-wrap: wrap; gap: 12px; justify-content: center; }
        .pk-app__wrap .carrier-grid img, .pk-app__wrap .service-logo, .pk-app__wrap .network-logo {
            width: 84px; height: 84px; object-fit: contain; background: #fff;
            border: 1.5px solid var(--line); border-radius: 18px; padding: 8px;
            cursor: pointer; transition: transform .15s ease, border-color .15s ease, box-shadow .15s ease;
        }
        .pk-app__wrap .carrier-grid img:hover { transform: translateY(-2px); border-color: color-mix(in srgb, var(--brand) 45%, var(--line)); box-shadow: 0 10px 20px -12px rgba(2,6,23,.35); }
        .pk-app__wrap .carrier-grid img.selected, .pk-app__wrap .network-logo.selected, .pk-app__wrap img.selected {
            border-color: var(--brand); transform: scale(1.04);
            box-shadow: 0 0 0 3px color-mix(in srgb, var(--brand) 25%, transparent), 0 12px 22px -12px rgba(2,6,23,.4);
        }
        .pk-app__wrap .service-logo { width: 92px; height: 92px; border-radius: 20px; }
        @media (max-width: 640px) {
            .pk-app__wrap .carrier-grid img, .pk-app__wrap .service-logo, .pk-app__wrap .network-logo { width: 62px; height: 62px; padding: 5px; border-radius: 14px; }
        }
        .pk-bill__head {
            display: flex; align-items: center; gap: 14px; padding: 18px 22px; color: #fff;
            background: radial-gradient(420px 160px at 90% -20%, color-mix(in srgb, var(--brand) 55%, transparent), transparent 60%), linear-gradient(135deg, #0b1220, #101c33 60%, color-mix(in srgb, var(--brand-dark) 55%, #0b1220));
        }
        .pk-bill__head .pk-bill__ic { width: 46px; height: 46px; flex: 0 0 46px; border-radius: 14px; display: inline-flex; align-items: center; justify-content: center; font-size: 1.25rem; color: #fff; background: linear-gradient(135deg, var(--brand), var(--brand-dark)); box-shadow: inset 0 1px 0 rgba(255,255,255,.25); }
        .pk-bill__head h5 { margin: 0; font-weight: 800; font-size: 1.05rem; }
        .pk-bill__head p { margin: 2px 0 0; font-size: .8rem; opacity: .75; }
        .pk-bill__steps { display: flex; gap: 6px; margin-left: auto; flex-wrap: wrap; }
        .pk-bill__steps span { font-size: .66rem; font-weight: 700; letter-spacing: .02em; padding: 4px 10px; border-radius: 999px; background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.2); white-space: nowrap; }
        .pk-bill__steps span.on { background: var(--brand); border-color: transparent; }
        @media (max-width: 640px) { .pk-bill__steps { display: none; } }
        .pk-verify { background: var(--wash); border: 1px solid var(--line); border-radius: 16px; padding: 16px 18px; }
        .pk-verify .row-line { display: flex; justify-content: space-between; gap: 1rem; padding: 7px 0; border-bottom: 1px dashed var(--line); }
        .pk-verify .row-line:last-child { border-bottom: 0; }
        .pk-verify .k { font-size: .68rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); }
        .pk-verify .v { font-weight: 700; color: var(--ink); text-align: right; }

        /* Bootstrap accordion (KnowledgeBase and friends). The retired bundle used
           to provide this; the compat layer styles and wires it instead. */
        .pk-app__wrap .accordion { display: grid; gap: 12px; }
        .pk-app__wrap .accordion-item {
            border: 1px solid var(--line) !important; border-radius: 16px !important;
            overflow: hidden; background: var(--surface);
            box-shadow: 0 6px 18px -12px rgba(11,18,32,.25);
        }
        .pk-app__wrap .accordion-header { margin: 0; }
        .pk-app__wrap .accordion-button {
            width: 100%; display: flex; align-items: center; gap: 10px; text-align: left;
            background: var(--surface); border: 0; padding: 16px 18px; cursor: pointer;
            font: inherit; font-weight: 700; color: var(--ink);
        }
        .pk-app__wrap .accordion-button::after {
            content: "\F282"; font-family: "bootstrap-icons"; margin-left: auto;
            transition: transform .18s ease; font-size: .9rem; color: var(--muted);
        }
        .pk-app__wrap .accordion-button:not(.collapsed) { color: var(--brand); background: color-mix(in srgb, var(--brand) 6%, var(--surface)); }
        .pk-app__wrap .accordion-button:not(.collapsed)::after { transform: rotate(180deg); }
        .pk-app__wrap .accordion-collapse { display: none; }
        .pk-app__wrap .accordion-collapse.show { display: block; }
        .pk-app__wrap .accordion-body { padding: 4px 18px 18px; color: var(--muted); line-height: 1.65; }
        /* Context-aware foregrounds: faint panels are NOT filled brand buttons.
           Neutral nested cards/inputs restore their own text and link context. */
        .pk-app__wrap, .pk-app__wrap :is(.card,.bg-white,.bg-light,.form-control,.form-select,.input-group-text) {
            --bc-context-fg: var(--ink); --bc-context-link: var(--brand-ink);
            color: var(--bc-context-fg);
        }
        .pk-app__wrap .bg-primary {
            --bc-context-fg: var(--brand-fg); --bc-context-link: var(--brand-fg);
            background-image: none !important;
            background-color: rgba(var(--bs-primary-rgb), var(--bs-bg-opacity,1)) !important;
            color: var(--bc-context-fg);
        }
<?php foreach (array(0,10,20,25,50,75,100) as $opacity): $tint=bc_theme_tint($brand,$opacity/100); ?>
        .pk-app__wrap .bg-primary.bg-opacity-<?php echo $opacity; ?> {
            --bc-context-fg: <?php echo $tint['foreground']; ?>;
            --bc-context-link: <?php echo $tint['link']; ?>;
            /* Flatten the tint on white so text contrast is deterministic on every page. */
            background-color: <?php echo $tint['background']; ?> !important;
        }
<?php endforeach; ?>
<?php foreach (array('secondary'=>'#64748B','success'=>'#059669','danger'=>'#DC2626','warning'=>'#D97706','info'=>'#0284C7','dark'=>'#0B1220') as $name=>$base): $full=bc_theme_tint($base,1); ?>
        .pk-app__wrap .bg-<?php echo $name; ?> {
            --bc-context-fg: <?php echo $full['foreground']; ?>; --bc-context-link: <?php echo $full['foreground']; ?>;
            color: var(--bc-context-fg);
        }
<?php foreach (array(0,10,20,25,50,75,100) as $opacity): $tint=bc_theme_tint($base,$opacity/100); ?>
        .pk-app__wrap .bg-<?php echo $name; ?>.bg-opacity-<?php echo $opacity; ?> {
            --bc-context-fg: <?php echo $tint['foreground']; ?>; --bc-context-link: <?php echo $tint['link']; ?>;
            background-color: <?php echo $tint['background']; ?> !important;
        }
<?php endforeach; ?>
        .pk-app__wrap .bg-<?php echo $name; ?>.text-<?php echo $name; ?>,
        .pk-app__wrap .bg-<?php echo $name; ?> .text-<?php echo $name; ?> { color: var(--bc-context-link) !important; }
        .pk-app__wrap .bg-<?php echo $name; ?>:is(.text-dark,.text-white,.text-muted,.text-body),
        .pk-app__wrap .bg-<?php echo $name; ?> :is(.text-dark,.text-white,.text-muted,.text-body) { color: var(--bc-context-fg) !important; }
<?php endforeach; ?>
        .pk-app__wrap .bg-primary:is(.text-dark,.text-dark-primary,.text-white,.text-muted,.text-body) { color: var(--bc-context-fg) !important; }
        .pk-app__wrap .bg-primary.text-primary { color: var(--bc-context-link) !important; }
        .pk-app__wrap .bg-primary :is(.text-dark,.text-dark-primary,.text-white,.text-muted,.text-secondary,.text-body) { color: var(--bc-context-fg) !important; }
        .pk-app__wrap .bg-primary :is(.text-primary,.link-primary) { color: var(--bc-context-link) !important; }
        .pk-app__wrap .bg-primary .text-dark-primary { opacity: 1 !important; }
        .pk-app__wrap .bg-primary a:not(.btn):not(.badge) { color: var(--bc-context-link); }
        .pk-app__wrap .btn-primary, .pk-app__wrap .nav-pills .nav-link.active {
            --bc-context-fg: var(--brand-fg); --bc-context-link: var(--brand-fg);
            background: var(--brand) !important; color: var(--brand-fg) !important;
            border: 1px solid var(--brand-ink);
        }
        .pk-app__wrap .btn-primary:hover { filter: none; }
        .pk-app__wrap :is(.btn-primary,.nav-pills .nav-link.active) :is(.text-primary,.text-dark,.text-dark-primary,.text-white,.text-muted) { color: var(--bc-context-fg) !important; }
</style>
        <?php
    }
}

if (!function_exists('bc_app_head')) {
    /**
     * The <head> block. Call this in place of the old template stylesheet links.
     *
     * @param string $title      Page title. Escaped here, so a dynamic title is safe.
     * @param mixed  $site_details Tenant row, or null to resolve from globals.
     */
    function bc_app_head($title, $site_details = null) {
        if (!is_array($site_details) && isset($GLOBALS['get_all_site_details']) && is_array($GLOBALS['get_all_site_details'])) {
            $site_details = $GLOBALS['get_all_site_details'];
        }
        $site  = bc_app_site();
        $brand = bc_app_colour(is_array($site_details) ? $site_details : null);
        $title = $title !== '' ? (string)$title : $site['title'];
        ?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="<?php echo $brand; ?>">
    <meta name="author" content="Philmore Codes">
    <?php if (!empty($site['details']['site_desc'])): ?>
    <meta name="description" content="<?php echo htmlspecialchars((string)$site['details']['site_desc'], ENT_QUOTES, 'UTF-8'); ?>">
    <?php endif; ?>
    <title><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <!-- Optional typography must not hold the wallet UI's first paint hostage. -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet"></noscript>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(bc_app_base(), ENT_QUOTES, 'UTF-8'); ?>/assets-2/vendor/bootstrap-icons/bootstrap-icons.min.css">
        <?php bc_app_css($brand); ?>
</head>
        <?php
    }
}

if (!function_exists('bc_app_sidebar')) {
    /**
     * Opens the document body and prints the brand rail + navigation.
     *
     * $opts keys: 'active' (page filename to highlight; defaults to the current script),
     *             'user' (user row override).
     */
    function bc_app_sidebar($opts = array()) {
        $site   = bc_app_site();
        $brand  = bc_app_colour($site['details']);
        $base   = bc_app_base();
        $active = isset($opts['active']) ? (string)$opts['active'] : bc_app_current_page();
        $user   = isset($opts['user']) && is_array($opts['user']) ? $opts['user'] : $site['user'];
        $menu   = bc_app_menu();
        ?><body>
<div class="pk-app__bg" aria-hidden="true"></div>
<aside class="pk-app__side" id="pkAppSide" aria-label="Main navigation">
    <a class="pk-app__brand" href="<?php echo htmlspecialchars(bc_app_url('Dashboard.php')); ?>">
        <?php if ($site['logo']): ?>
            <img src="<?php echo htmlspecialchars($base . '/' . $site['logo']); ?>" alt="<?php echo htmlspecialchars($site['title']); ?>">
        <?php else: ?>
            <span class="pk-app__mark"><?php echo htmlspecialchars(strtoupper(substr($site['title'], 0, 1))); ?></span>
        <?php endif; ?>
        <span class="pk-app__brandtxt"><?php echo htmlspecialchars($site['title']); ?><span class="pk-app__brandsub">Console</span></span>
    </a>
    <nav class="pk-app__nav">
        <?php foreach ($menu as $group): ?>
            <?php
            $items = array();
            foreach ((array)$group['items'] as $item) {
                if (bc_app_item_visible($item, $user)) $items[] = $item;
            }
            if (!$items) continue;
            $group_id = isset($group['id']) ? (string)$group['id'] : '';
            $group_active = (!empty($group['match']) && in_array($active, (array)$group['match'], true))
                || ($active === 'Hub.php' && isset($_GET['g']) && (string)$_GET['g'] === $group_id);
            $group_label = isset($group['label']) ? (string)$group['label'] : '';
            $group_icon = isset($group['icon']) ? (string)$group['icon'] : 'circle';
            ?>
            <?php if ($group_label === ''): ?>
                <?php foreach ($items as $item): ?>
                    <?php $item_active = $active === basename((string)$item['href']); ?>
                    <a class="pk-app__glink<?php echo $item_active ? ' is-active' : ''; ?>" href="<?php echo htmlspecialchars(bc_app_url($item['href'])); ?>">
                        <span class="pk-app__gicon"><?php echo bc_app_icon(isset($item['icon']) ? $item['icon'] : 'circle'); ?></span>
                        <span class="pk-app__glabel"><?php echo htmlspecialchars($item['label']); ?></span>
                        <?php if (!empty($item['badge'])): ?><span class="pk-app__badge"><?php echo htmlspecialchars($item['badge']); ?></span><?php endif; ?>
                        <i class="bi bi-arrow-right-circle pk-app__chev" aria-hidden="true"></i>
                    </a>
                <?php endforeach; ?>
            <?php else: ?>
                <a class="pk-app__glink<?php echo $group_active ? ' is-active' : ''; ?>"
                   href="<?php echo htmlspecialchars(bc_app_url('Hub.php?g=' . urlencode($group_id))); ?>">
                    <span class="pk-app__gicon"><?php echo bc_app_icon($group_icon); ?></span>
                    <span class="pk-app__glabel"><?php echo htmlspecialchars($group_label); ?></span>
                    <?php if (!empty($group['badge'])): ?><span class="pk-app__badge"><?php echo htmlspecialchars($group['badge']); ?></span><?php endif; ?>
                    <i class="bi bi-arrow-right-circle pk-app__chev" aria-hidden="true"></i>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
    <a class="pk-app__user" href="<?php echo htmlspecialchars(bc_app_url('AccountSettings.php')); ?>">
        <span class="pk-app__avatar"><?php echo htmlspecialchars(strtoupper(substr($site['username'] !== '' ? $site['username'] : 'U', 0, 1))); ?></span>
        <span class="pk-app__usertxt">
            <span class="pk-app__username"><?php echo htmlspecialchars($site['username'] !== '' ? $site['username'] : 'Account'); ?></span>
            <span class="pk-app__userplan"><?php echo htmlspecialchars(isset($user['account_type']) && $user['account_type'] ? ucfirst((string)$user['account_type']) . ' account' : 'Customer account'); ?></span>
        </span>
    </a>
</aside>
<div class="pk-app__scrim" id="pkAppScrim"></div>
        <?php
    }
}

if (!function_exists('bc_app_topbar')) {
    /**
     * The sticky header, and the opening of the content column.
     *
     * $opts keys: 'title', 'crumb', 'actions' (array of array('href'|'label'|'icon'|'danger')),
     *             'notification' (string body).
     */
    function bc_app_topbar($opts = array()) {
        $site  = bc_app_site();
        $base  = bc_app_base();
        $title = isset($opts['title']) ? (string)$opts['title'] : '';
        $crumb = isset($opts['crumb']) ? (string)$opts['crumb'] : '';
        if ($title === '') {
            $active = bc_app_current_page();
            $title = ucwords(str_replace(array('.php', '-'), array('', ' '), $active));
        }
        $notification = isset($opts['notification']) ? (string)$opts['notification'] : '';
        if ($notification === '') {
            $notification = isset($GLOBALS['user_vendor_status_message_template_text']) ? (string)$GLOBALS['user_vendor_status_message_template_text'] : '';
        }
        $username = $site['username'] !== '' ? $site['username'] : 'Account';
        ?><div class="pk-app__main">
<header class="pk-app__top">
    <button class="pk-app__burger" type="button" id="pkAppBurger" aria-label="Open navigation" aria-expanded="false">
        <i class="bi bi-list" aria-hidden="true"></i>
    </button>
    <div>
        <div class="pk-app__title"><?php echo htmlspecialchars($title); ?></div>
        <?php if ($crumb !== ''): ?><div class="pk-app__crumb"><?php echo htmlspecialchars($crumb); ?></div><?php endif; ?>
    </div>
    <div class="pk-app__actions">
        <a class="pk-app__iconbtn" href="<?php echo htmlspecialchars(bc_app_url('SiteMap.php')); ?>" title="Platform Guide">
            <i class="bi bi-map" aria-hidden="true"></i><span class="pk-app__hide-sm">Guide</span>
        </a>
        <a class="pk-app__iconbtn" href="<?php echo htmlspecialchars(bc_app_url('Referral.php')); ?>" title="Refer and Earn">
            <i class="bi bi-gift" aria-hidden="true"></i><span class="pk-app__hide-sm">Refer</span>
        </a>
        <?php if ($notification !== ''): ?>
            <button class="pk-app__iconbtn" type="button" id="pkAppNotice" title="Message from support">
                <i class="bi bi-bell" aria-hidden="true"></i><span class="pk-app__dot">1</span>
            </button>
            <div class="pk-app__menu" id="pkAppNoticeMenu" role="dialog" aria-label="Notifications">
                <div class="pk-app__menuhead"><strong>Message from Admin</strong><span>Latest notification</span></div>
                <div style="padding:4px 10px 10px;font-size:.86rem;line-height:1.55;"><?php echo nl2br(htmlspecialchars($notification)); ?></div>
            </div>
        <?php endif; ?>
        <div class="pk-app__profile">
            <button class="pk-app__iconbtn" type="button" id="pkAppProfile" aria-expanded="false" aria-haspopup="true" aria-label="<?php echo htmlspecialchars($username . ' profile menu', ENT_QUOTES, 'UTF-8'); ?>" title="<?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?>">
                <span class="pk-app__avatar"><?php echo htmlspecialchars(strtoupper(substr($username, 0, 1))); ?></span>
                <span class="pk-app__hide-sm"><?php echo htmlspecialchars($username); ?></span>
                <i class="bi bi-chevron-down pk-app__hide-sm" style="font-size:.7rem;color:var(--muted);" aria-hidden="true"></i>
            </button>
            <div class="pk-app__menu" id="pkAppProfileMenu" role="menu">
                <div class="pk-app__menuhead"><strong><?php echo htmlspecialchars($username); ?></strong><span>Signed in</span></div>
                <?php
                $actions = isset($opts['actions']) && is_array($opts['actions']) ? $opts['actions'] : array();
                if (!$actions) {
                    $actions = array(
                        array('href' => 'APIDocs.php', 'label' => 'API Documentation', 'icon' => 'braces'),
                        array('href' => 'AccountSettings.php', 'label' => 'Account Settings', 'icon' => 'person-gear'),
                        array('label' => 'Log Out', 'icon' => 'box-arrow-right', 'danger' => true, 'logout' => true),
                    );
                }
                foreach ($actions as $a) {
                    $label  = isset($a['label']) ? (string)$a['label'] : '';
                    $icon   = isset($a['icon']) ? (string)$a['icon'] : 'chevron-right';
                    $danger = !empty($a['danger']) ? ' pk-app__mlink--danger' : '';
                    $href   = !empty($a['logout']) ? '#' : bc_app_url((string)$a['href']);
                    $attrs  = !empty($a['logout']) ? ' data-pk-logout' : '';
                    echo '<a class="pk-app__mlink' . $danger . '" href="' . htmlspecialchars($href) . '"' . $attrs . '>';
                    echo '<i class="bi bi-' . htmlspecialchars($icon) . '" aria-hidden="true"></i>' . htmlspecialchars($label);
                    echo '</a>';
                }
                ?>
            </div>
        </div>
    </div>
</header>
<main class="pk-app__content">
<div class="pk-app__wrap">
        <?php
    }
}

if (!function_exists('bc_app_flash')) {
    /**
     * The one flash-message renderer for the signed-in console.
     *
     * Ported from the retired bc-footer.php so a migrated page behaves exactly as before: the
     * session response is classified from its text, shown as a SweetAlert dialog and cleared.
     * A page that never set the session key prints nothing at all.
     */
    function bc_app_flash() {
        if (empty($_SESSION['product_purchase_response'])) return;

        $msg   = (string)$_SESSION['product_purchase_response'];
        $state = isset($_SESSION['product_purchase_status']) ? $_SESSION['product_purchase_status'] : null;
        $ref   = isset($_SESSION['last_transaction_ref']) ? $_SESSION['last_transaction_ref'] : null;

        $type = 'success';
        if ($state === 'failed' || stripos($msg, 'Error') !== false || stripos($msg, 'reached the maximum') !== false
            || stripos($msg, 'Limit Reached') !== false || stripos($msg, 'failed') !== false
            || stripos($msg, 'Insufficient') !== false || stripos($msg, 'suspension') !== false
            || stripos($msg, 'suspended') !== false) {
            $type = 'error';
        } elseif ($state === 'pending' || stripos($msg, 'pending') !== false || stripos($msg, 'processed') !== false) {
            $type = 'warning';
        }

        unset($_SESSION['product_purchase_response']);
        unset($_SESSION['product_purchase_status']);
        unset($_SESSION['last_transaction_ref']);
        ?>
<script>
(function () {
    var msg = <?php echo json_encode($msg); ?>;
    var type = <?php echo json_encode($type); ?>;
    var ref = <?php echo json_encode($ref !== null && $ref !== '' ? (string)$ref : null); ?>;
    var icon = type === 'success' ? 'success' : (type === 'warning' ? 'warning' : 'error');
    var title = type === 'success' ? 'Successful!' : (type === 'warning' ? 'Pending' : 'Failed');
    function presentFlash() {
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            icon: icon,
            title: title,
            html: '<div style="word-break:break-word;line-height:1.5;">' + msg + '</div>'
                + (ref ? '<div style="margin-top:8px;"><span class="badge bg-secondary" style="font-size:11px;">Ref: ' + ref + '</span></div>' : ''),
            confirmButtonText: (ref && type === 'success') ? 'View Receipt' : 'OK'
        }).then(function () {
            if (ref && type === 'success' && typeof showTransactionDetails === 'function') {
                showTransactionDetails(ref);
            }
        });
    } else {
        // A failed optional library must not trigger a synchronous browser alert
        // while the document is still parsing. Keep feedback and receipt access visible.
        var notice = document.createElement('section');
        notice.setAttribute('role', 'status');
        notice.setAttribute('aria-live', 'polite');
        notice.style.cssText = 'position:relative;margin:16px;padding:18px;background:#fff;color:#172033;border:1px solid #cbd5e1;border-radius:12px;z-index:100';
        var text = document.createElement('p');
        text.textContent = msg.replace(/<br\s*\/?>/gi, '\n').replace(/<[^>]*>/g, '');
        notice.appendChild(text);
        if (ref && type === 'success') {
            var receipt = document.createElement('button');
            receipt.type = 'button'; receipt.textContent = 'View Receipt';
            receipt.onclick = function () { if (typeof showTransactionDetails === 'function') showTransactionDetails(ref); };
            notice.appendChild(receipt);
        }
        var close = document.createElement('button');
        close.type = 'button'; close.textContent = 'Dismiss';
        close.onclick = function () { notice.remove(); };
        notice.appendChild(close);
        var host = document.querySelector('main') || document.body;
        host.insertBefore(notice, host.firstChild);
    }
    }
    // Deferred local scripts execute before DOMContentLoaded, so Swal exists here.
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', presentFlash, { once: true });
    else presentFlash();
}());
</script>
        <?php
    }
}

if (!function_exists('bc_app_widgets')) {
    /**
     * Page-level features the retired bc-header.php / bc-footer.php injected on every
     * console page, ported with their original gates:
     *
     *  - the AI assistant (signed-in + `ai_suite`, skipped on the VTU purchase pages
     *    that favour the dedicated terminal),
     *  - the Refer-and-Earn modal (its trigger lives in the topbar),
     *  - the support chat widget, which self-gates on tenant settings and session.
     */
    function bc_app_widgets() {
        global $get_logged_user_details, $connection_server;
        $user = isset($GLOBALS['get_logged_user_details']) && is_array($GLOBALS['get_logged_user_details'])
            ? $GLOBALS['get_logged_user_details'] : null;
        $host = isset($GLOBALS['web_http_host']) && $GLOBALS['web_http_host'] !== ''
            ? (string)$GLOBALS['web_http_host'] : bc_app_base();

        if ($user && !empty($user['username'])):
            ?>
<!-- Referral Modal -->
<div class="modal" id="referralModal" tabindex="-1" aria-labelledby="referralModalLabel" aria-hidden="true" style="display:none;">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="referralModalLabel">Refer and Earn</h5>
        <button type="button" class="btn-close" data-pk-close aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p>Share your referral link with friends and earn rewards!</p>
        <div class="input-group">
            <input type="text" class="form-control" value="<?php echo htmlspecialchars($host . '/web/Register.php?referral=' . $user['username'], ENT_QUOTES, 'UTF-8'); ?>" id="referralLinkInput" readonly>
            <button class="btn btn-primary" type="button" onclick="copyReferLinkModal()">Copy</button>
        </div>
      </div>
    </div>
  </div>
</div>
<script>
function copyReferLinkModal() {
    var copyText = document.getElementById("referralLinkInput");
    copyText.select();
    copyText.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(copyText.value);
    alert("Referral link copied!");
}
</script>
            <?php
        endif;

        if (file_exists(__DIR__ . '/bc-support-chat-widget.php')) {
            include(__DIR__ . '/bc-support-chat-widget.php');
        }
    }
}

if (!function_exists('bc_app_foot')) {
    /**
     * Closes the content column and the document, and prints the shell script.
     *
     * $opts keys: 'note' (footer note), 'noscript' (markup shown with JavaScript disabled).
     */
    function bc_app_foot($opts = array()) {
        $site = bc_app_site();
        $base = bc_app_base();
        // Page-level widgets the old chrome injected (AI assistant, referral modal,
        // support chat) — still inside the wrap so the shell's modal CSS applies.
        bc_app_widgets();
        require_once __DIR__ . '/bc-float-services.php';
        bc_float_services_render();
        // 'bare' is for pages that own their own layout (print targets, full-screen terminals):
        // they still get the flash dialog and the shell script, but not the wrapper-closing tags.
        if (empty($opts['bare'])): ?></div>
</main>
<!-- §32 transaction receipt: history rows + post-purchase "View Receipt" call
     showTransactionDetails(ref[, isAdmin]); it fetches a scoped JSON payload
     (provider internals are staff-only) and offers PDF / image downloads. -->
<!-- Keep the footer receipt in the shell CSS scope after main has closed. -->
<div class="pk-app__wrap bc-receipt-host">
<div class="modal" id="bcReceiptModal" tabindex="-1" aria-hidden="true" style="display:none;z-index:2000;">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title fw-bold" id="bcReceiptTitle"><i class="bi bi-receipt me-2"></i>Transaction Receipt</h5>
        <button type="button" class="btn-close" data-bc-receipt-close aria-label="Close"></button>
      </div>
      <div class="modal-body" id="bcReceiptBody"><div class="text-center text-muted py-4">Loading receipt&hellip;</div></div>
      <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn btn-primary fw-bold" id="bcReceiptPdfBtn"><i class="bi bi-filetype-pdf me-1"></i>Download PDF</button>
        <button type="button" class="btn btn-outline-secondary fw-bold" id="bcReceiptImgBtn"><i class="bi bi-image me-1"></i>Download Image</button>
        <button type="button" class="btn btn-light fw-bold" data-bc-receipt-close>Close</button>
      </div>
    </div>
  </div>
</div>
</div><!-- .bc-receipt-host -->
<script>
(function () {
    'use strict';
    function esc(v) {
        var d = document.createElement('div');
        d.textContent = v == null ? '' : String(v);
        return d.innerHTML;
    }
    function row(k, v) {
        return '<div style="display:flex;justify-content:space-between;gap:14px;padding:7px 0;border-bottom:1px dashed #e5e7eb;font-size:13px;">'
            + '<span style="color:#6b7280;">' + esc(k) + '</span>'
            + '<span style="font-weight:700;text-align:right;word-break:break-all;">' + esc(v) + '</span></div>';
    }
    function card(d) {
        var color = d.status_code === 1 ? '#15803d' : (d.status_code === 2 ? '#b45309' : '#b91c1c');
        var tint = d.status_code === 1 ? '#f0fdf4' : (d.status_code === 2 ? '#fffbeb' : '#fef2f2');
        var h = '<div id="bcReceiptCard" style="background:#ffffff;border:1px solid #e5e7eb;border-radius:18px;padding:22px;font-family:Arial,Helvetica,sans-serif;color:#111827;max-width:430px;margin:0 auto;">'
            + '<div style="text-align:center;padding-bottom:12px;border-bottom:2px solid #111827;">'
            + '<div style="font-size:17px;font-weight:800;">' + esc(d.site) + '</div>'
            + '<div style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.14em;margin-top:2px;">Transaction Receipt</div></div>'
            + '<div style="text-align:center;margin:14px 0;"><span style="display:inline-block;padding:5px 16px;border-radius:999px;font-size:12px;font-weight:800;color:' + color + ';background:' + tint + ';border:1px solid ' + color + ';">' + esc(String(d.status_label).toUpperCase()) + '</span></div>'
            + row('Service', d.service)
            + (d.recipient ? row('Credited number / destination', d.recipient) : '')
            + row('Amount Paid', '\u20A6' + d.amount)
            + (d.face_amount && d.face_amount !== d.amount ? row('Face Value', '\u20A6' + d.face_amount) : '')
            + row('Reference', d.reference)
            + row('Date', d.date)
            + (d.balance_after != null ? row('Wallet Balance', '\u20A6' + d.balance_after) : '');
        if (d.utility) {
            h += row('Customer name', d.utility.customer_name || 'Not supplied');
            h += row('Customer address', d.utility.customer_address || 'Not supplied');
            h += row('Meter number', d.utility.meter_number || d.recipient || 'Not supplied');
            h += row('Meter type', d.utility.meter_type || 'Not supplied');
            if (d.utility.token) h += row('Prepaid token', d.utility.token);
            if (d.utility.units) h += row('Units', d.utility.units);
        }
        if (d.admin) {
            if (d.username) h += row('Customer', d.username);
            if (d.provider_site) h += row('Provider', d.provider_site);
            if (d.provider_reference) h += row('Provider Ref', d.provider_reference);
            if (d.description) h += '<div style="padding-top:10px;font-size:12px;color:#374151;">' + esc(d.description) + '</div>';
        }
        return h + '<div style="text-align:center;color:#9ca3af;font-size:11px;margin-top:14px;">Thank you for your business.</div></div>';
    }
    var receiptRequest = 0;
    var receiptController = null;
    var receiptTrigger = null;
    function openModal(el) {
        // This is a shell-owned overlay; do not mix Bootstrap backdrops/body locks
        // with the shell shim. Its styles are supplied by .bc-receipt-host.
        if (el.getAttribute('aria-hidden') !== 'false') receiptTrigger = document.activeElement;
        el.setAttribute('role', 'dialog');
        el.setAttribute('aria-modal', 'true');
        el.setAttribute('aria-labelledby', 'bcReceiptTitle');
        el.setAttribute('aria-hidden', 'false');
        el.classList.add('show');
        el.style.display = 'flex';
        el.focus();
    }
    function closeModal(el) {
        receiptRequest++;
        if (receiptController) receiptController.abort();
        el.classList.remove('show');
        el.style.display = 'none';
        el.setAttribute('aria-hidden', 'true');
        if (receiptTrigger && receiptTrigger.isConnected) receiptTrigger.focus();
    }
    var currentRef = '';
    window.showTransactionDetails = function (ref, isAdmin) {
        var body = document.getElementById('bcReceiptBody');
        var modal = document.getElementById('bcReceiptModal');
        if (!body || !modal || !ref) return;
        currentRef = String(ref);
        body.innerHTML = '<div class="text-center text-muted py-4">Loading receipt&hellip;</div>';
        openModal(modal);
        var requestId = ++receiptRequest;
        if (receiptController) receiptController.abort();
        receiptController = typeof AbortController !== 'undefined' ? new AbortController() : null;
        fetch('transaction-details.php?ref=' + encodeURIComponent(ref), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }, signal: receiptController ? receiptController.signal : undefined })
            .then(function (r) { if (!r.ok) throw new Error('Receipt request failed'); return r.json(); })
            .then(function (d) {
                if (requestId !== receiptRequest) return;
                if (!d || !d.ok) {
                    body.innerHTML = '<div class="alert alert-warning mb-0">Receipt unavailable for this transaction.</div>';
                    return;
                }
                body.innerHTML = card(d);
            })
            .catch(function () {
                if (requestId !== receiptRequest) return;
                body.innerHTML = '<div class="alert alert-warning mb-0">Could not load the receipt. Please try again.</div>';
            });
    };
    (function wire() {
        var modal = document.getElementById('bcReceiptModal');
        if (!modal) return;
        modal.addEventListener('click', function (e) {
            if (e.target === modal || e.target.closest('[data-bc-receipt-close]')) closeModal(modal);
        });
        modal.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { e.preventDefault(); closeModal(modal); }
            if (e.key === 'Tab') {
                var buttons = modal.querySelectorAll('button:not([disabled])');
                var first = buttons[0], last = buttons[buttons.length - 1];
                if (e.shiftKey && (document.activeElement === first || document.activeElement === modal)) { e.preventDefault(); last.focus(); }
                else if (!e.shiftKey && (document.activeElement === last || document.activeElement === modal)) { e.preventDefault(); first.focus(); }
            }
        });
        var pdfBtn = document.getElementById('bcReceiptPdfBtn');
        var imgBtn = document.getElementById('bcReceiptImgBtn');
        if (pdfBtn) pdfBtn.addEventListener('click', function () {
            var c = document.getElementById('bcReceiptCard');
            if (!c) return;
            var w = window.open('', '_blank', 'width=680,height=860');
            if (!w) { alert('Allow pop-ups to download the receipt as PDF.'); return; }
            w.document.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title>Receipt ' + esc(currentRef) + '</title></head><bo' + 'dy style="margin:24px;font-family:Arial,sans-serif;">' + c.outerHTML + '<scr' + 'ipt>window.onload=function(){setTimeout(function(){window.print();},200);};</scr' + 'ipt></bo' + 'dy></html>');
            w.document.close();
        });
        if (imgBtn) imgBtn.addEventListener('click', function () {
            var c = document.getElementById('bcReceiptCard');
            if (!c) return;
            try {
                var clone = c.cloneNode(true);
                clone.setAttribute('xmlns', 'http://www.w3.org/1999/xhtml');
                var wdt = Math.max(c.offsetWidth, 430);
                var hgt = c.offsetHeight;
                var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' + wdt + '" height="' + hgt + '"><foreignObject width="100%" height="100%">' + new XMLSerializer().serializeToString(clone) + '</foreignObject></svg>';
                var img = new Image();
                img.onload = function () {
                    var scale = 2;
                    var cv = document.createElement('canvas');
                    cv.width = wdt * scale; cv.height = hgt * scale;
                    var ctx = cv.getContext('2d');
                    ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, cv.width, cv.height);
                    ctx.drawImage(img, 0, 0, cv.width, cv.height);
                    var a = document.createElement('a');
                    a.download = 'receipt-' + (currentRef || 'transaction') + '.png';
                    a.href = cv.toDataURL('image/png');
                    a.click();
                };
                img.onerror = function () { alert('Image download is not supported in this browser. Please use Download PDF.'); };
                img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
            } catch (err) {
                alert('Image download failed. Please use Download PDF.');
            }
        });
    })();
})();
</script>

</div>
<?php endif; ?>
<?php if (empty($opts['nonav'])): ?>
<footer class="pk-app__foot">
    <span>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($site['title']); ?></span>
    <?php if (!empty($opts['note'])): ?><span> &middot; <?php echo $opts['note']; ?></span><?php endif; ?>
    <a href="<?php echo htmlspecialchars(bc_app_url('SiteMap.php')); ?>">Platform Guide</a>
</footer>
<?php endif; ?>
<script src="<?php echo htmlspecialchars($base, ENT_QUOTES, 'UTF-8'); ?>/assets-2/vendor/sweetalert2/sweetalert2.all.min.js?v=11.0.16" defer></script>
<script src="<?php echo htmlspecialchars($base, ENT_QUOTES, 'UTF-8'); ?>/jsfile/bc-custom-all.js" defer></script>
<?php bc_app_flash(); if (session_status() === PHP_SESSION_ACTIVE) { @session_write_close(); } ?>
<script>
(function () {
    'use strict';
    var side = document.getElementById('pkAppSide');
    var scrim = document.getElementById('pkAppScrim');
    var burger = document.getElementById('pkAppBurger');
    var profile = document.getElementById('pkAppProfile');
    var profileMenu = document.getElementById('pkAppProfileMenu');
    var notice = document.getElementById('pkAppNotice');
    var noticeMenu = document.getElementById('pkAppNoticeMenu');

    function openNav() {
        if (!side) return;
        side.classList.add('is-open');
        if (scrim) scrim.classList.add('is-open');
        if (burger) burger.setAttribute('aria-expanded', 'true');
    }
    function closeNav() {
        if (!side) return;
        side.classList.remove('is-open');
        if (scrim) scrim.classList.remove('is-open');
        if (burger) burger.setAttribute('aria-expanded', 'false');
    }
    if (burger) {
        burger.addEventListener('click', function () {
            if (side.classList.contains('is-open')) { closeNav(); } else { openNav(); }
        });
    }
    if (scrim) scrim.addEventListener('click', closeNav);

    // Collapsible groups. The active group starts expanded; the rest follow the click.
    var groups = document.querySelectorAll('[data-pk-group]');
    Array.prototype.forEach.call(groups, function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-pk-group');
            var sub = document.getElementById('pk-app-sub-' + id);
            if (!sub) return;
            var open = sub.classList.toggle('is-open');
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    });

    function toggle(menu, anchor) {
        if (!menu) return false;
        var open = menu.classList.toggle('is-open');
        if (anchor) anchor.setAttribute('aria-expanded', open ? 'true' : 'false');
        return open;
    }
    function closeAll() {
        [profileMenu, noticeMenu].forEach(function (m) { if (m) m.classList.remove('is-open'); });
        if (profile) profile.setAttribute('aria-expanded', 'false');
    }
    if (profile) {
        profile.addEventListener('click', function (e) {
            e.stopPropagation();
            closeAll();
            toggle(profileMenu, profile);
        });
    }
    if (notice) {
        notice.addEventListener('click', function (e) {
            e.stopPropagation();
            closeAll();
            toggle(noticeMenu, notice);
        });
    }
    [profileMenu, noticeMenu].forEach(function (m) {
        if (m) m.addEventListener('click', function (e) { e.stopPropagation(); });
    });
    document.addEventListener('click', closeAll);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' || e.key === 'Esc') { closeAll(); closeNav(); }
    });

    // Bootstrap-compat: the retired bundle is gone, so the handful of data attributes the
    // compat layer renders are wired here instead of shipping all of Bootstrap's JS.
    document.addEventListener('click', function (e) {
        var dismiss = e.target.closest('[data-bs-dismiss]');
        if (dismiss) {
            var kind = dismiss.getAttribute('data-bs-dismiss');
            if (kind === 'alert') {
                var alert = dismiss.closest('.alert');
                if (alert) alert.parentNode.removeChild(alert);
            } else if (kind === 'modal') {
                var modal = dismiss.closest('.modal');
                if (modal) modal.classList.remove('show');
            }
        }
        var toggle = e.target.closest('[data-bs-toggle]');
        if (toggle && toggle.getAttribute('data-bs-toggle') === 'modal') {
            var m = document.getElementById((toggle.getAttribute('data-bs-target') || '').replace('#', ''));
            if (m) m.classList.toggle('show');
        }

        var collapseToggle = e.target.closest('[data-bs-toggle="collapse"]');
        if (collapseToggle) {
            var collapseTarget = collapseToggle.getAttribute('data-bs-target') || collapseToggle.getAttribute('href');
            var collapsePane = collapseTarget ? document.querySelector(collapseTarget) : null;
            if (collapsePane) {
                e.preventDefault();
                var willShow = !collapsePane.classList.contains('show');
                var accordion = collapsePane.closest('.accordion');
                if (accordion && willShow) {
                    Array.prototype.forEach.call(accordion.querySelectorAll('.accordion-collapse.show'), function (p) {
                        p.classList.remove('show');
                    });
                    Array.prototype.forEach.call(accordion.querySelectorAll('[data-bs-toggle="collapse"]'), function (b) {
                        b.classList.add('collapsed'); b.setAttribute('aria-expanded', 'false');
                    });
                }
                collapsePane.classList.toggle('show', willShow);
                collapseToggle.classList.toggle('collapsed', !willShow);
                collapseToggle.setAttribute('aria-expanded', willShow ? 'true' : 'false');
            }
        }

        var tabToggle = e.target.closest('[data-bs-toggle="pill"], [data-bs-toggle="tab"]');
        if (tabToggle) {
            e.preventDefault();
            var tabList = tabToggle.closest('.nav, [role="tablist"]');
            if (tabList) {
                Array.prototype.forEach.call(
                    tabList.querySelectorAll('[data-bs-toggle="pill"], [data-bs-toggle="tab"]'),
                    function (t) { t.classList.remove('active'); t.setAttribute('aria-selected', 'false'); }
                );
            }
            tabToggle.classList.add('active');
            tabToggle.setAttribute('aria-selected', 'true');
            var tabTarget = tabToggle.getAttribute('data-bs-target') || tabToggle.getAttribute('href');
            var tabPane = tabTarget ? document.querySelector(tabTarget) : null;
            if (tabPane) {
                var tabContent = tabPane.closest('.tab-content');
                if (tabContent) {
                    Array.prototype.forEach.call(
                        tabContent.querySelectorAll(':scope > .tab-pane'),
                        function (p) { p.classList.remove('active', 'show'); }
                    );
                }
                tabPane.classList.add('active');
                void tabPane.offsetWidth;
                tabPane.classList.add('show');
            }
        }
    });

    // Logout confirm, matching the behaviour of the old header.
    var logout = document.querySelector('[data-pk-logout]');
    if (logout) {
        logout.addEventListener('click', function (e) {
            e.preventDefault();
            if (window.confirm('Do you want to logout?')) {
                window.location.href = '<?php echo htmlspecialchars(bc_app_root_url('logout.php')); ?>';
            }
        });
    }


    // Minimal bootstrap.Modal shim. Pages open dialogs with the Bootstrap JS API
    // (bootstrap.Modal.getOrCreateInstance(el).show()); the bundle is retired, so the
    // class is provided here on top of the compat layer's .modal.show display rule.
    if (!window.bootstrap || !window.bootstrap.Modal) {
        var PkModal = function (el) { this._el = el; };
        PkModal.prototype.show = function () { if (this._el) this._el.classList.add('show'); };
        PkModal.prototype.hide = function () { if (this._el) this._el.classList.remove('show'); };
        PkModal.prototype.toggle = function () { if (this._el) this._el.classList.toggle('show'); };
        PkModal.getInstance = function (el) { return (el && el.__pkModal) ? el.__pkModal : null; };
        PkModal.getOrCreateInstance = function (el) {
            if (!el) return new PkModal(null);
            if (!el.__pkModal) el.__pkModal = new PkModal(el);
            return el.__pkModal;
        };
        window.bootstrap = window.bootstrap || {};
        window.bootstrap.Modal = PkModal;
    }

    // Any modal a legacy page opens with the old Bootstrap toggle: honour .show.
    var modals = document.querySelectorAll('.pk-app__wrap .modal');
    Array.prototype.forEach.call(modals, function (m) {
        var close = m.querySelector('[data-pk-close]');
        if (close) close.addEventListener('click', function () { m.classList.remove('show'); });
    });
}());
</script>
<style>
.bc-btn-locked{opacity:.55!important;pointer-events:none!important}
</style>
<script>
/* §34: buy/payment submit lock. The instant a form is submitted, every
   submit control in it locks (disabled + spinner) so a double click, a
   double tap or a second Enter can never fire the same purchase/payment
   twice. GET (search/filter) forms are ignored, and a form can opt out
   with data-bc-nolock. AJAX flows never navigate away, so the lock
   auto-releases after 20s (and on pageshow) to allow a retry. */
(function () {
    'use strict';
    function unlockForm(form) {
        if (!form) return;
        try { delete form.dataset.bcLocked; } catch (e) { form.removeAttribute('data-bc-locked'); }
        form.querySelectorAll('[data-bc-locked-btn]').forEach(function (b) {
            b.disabled = false;
            b.classList.remove('bc-btn-locked');
            if (b.tagName === 'BUTTON' && b.hasAttribute('data-bc-orig-html')) {
                b.innerHTML = b.getAttribute('data-bc-orig-html');
                b.removeAttribute('data-bc-orig-html');
            }
            b.removeAttribute('data-bc-locked-btn');
        });
        var mirror = form.querySelector('input[data-bc-submitter-mirror]');
        if (mirror) mirror.parentNode.removeChild(mirror);
    }
    window.bcUnlockForm = unlockForm;
    document.addEventListener('click', function (e) {
        var t = e.target;
        var btn = t && t.closest ? t.closest('button[type="submit"], input[type="submit"], button:not([type])') : null;
        if (btn && btn.form && btn.form.dataset.bcLocked) {
            e.preventDefault();
            e.stopPropagation();
        }
    }, true);
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || form.tagName !== 'FORM') return;
        if (e.defaultPrevented) return;
        if (((form.getAttribute('method') || 'get').toLowerCase()) === 'get') return;
        if (form.hasAttribute('data-bc-nolock')) return;
        if (typeof form.checkValidity === 'function' && !form.checkValidity()) return;
        if (form.dataset.bcLocked) { e.preventDefault(); e.stopPropagation(); return; }
        form.dataset.bcLocked = '1';
        /* A disabled submitter can drop its name/value from the payload in
           some browsers, so mirror it into a hidden input BEFORE disabling. */
        var sub = e.submitter || null;
        if (sub && sub.name && !sub.disabled) {
            var m = document.createElement('input');
            m.type = 'hidden';
            m.name = sub.name;
            m.value = sub.value || '';
            m.setAttribute('data-bc-submitter-mirror', '1');
            form.appendChild(m);
        }
        form.querySelectorAll('button[type="submit"], input[type="submit"], button:not([type])').forEach(function (b) {
            if (b.disabled) return;
            b.setAttribute('data-bc-locked-btn', '1');
            if (b.tagName === 'BUTTON') b.setAttribute('data-bc-orig-html', b.innerHTML);
            b.disabled = true;
            b.classList.add('bc-btn-locked');
            if (b.tagName === 'BUTTON') {
                b.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Processing&hellip;';
            }
        });
        setTimeout(function () { unlockForm(form); }, 20000);
    }, false);
    window.addEventListener('pageshow', function () {
        document.querySelectorAll('form[data-bc-locked]').forEach(unlockForm);
    });
    window.addEventListener('pagehide', function () {
        document.querySelectorAll('form[data-bc-locked]').forEach(unlockForm);
    });
})();
</script>
</body>
</html>
        <?php
    }
}

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
        $details = is_array($site_details) ? $site_details : array();
        if (!empty($details['primary_color']) && preg_match('/^#[0-9a-fA-F]{6}$/', (string)$details['primary_color'])) {
            return (string)$details['primary_color'];
        }
        if (!empty($GLOBALS['vendor_primary_color']) && preg_match('/^#[0-9a-fA-F]{6}$/', (string)$GLOBALS['vendor_primary_color'])) {
            return (string)$GLOBALS['vendor_primary_color'];
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

        $title = 'FintechFlow';
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
        $host  = isset($_SERVER['HTTP_HOST']) ? (string)$$_SERVER['HTTP_HOST'] : 'localhost';
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
                'match' => array('Fund.php', 'VirtualBanks.php', 'SendFund.php', 'ShareFund.php', 'CoinConversion.php', 'VirtualCard.php', 'GiftCard.php', 'CryptoHub.php'),
                'items' => array(
                    array('label' => 'Fund with ATM Card', 'href' => 'Fund.php', 'icon' => 'credit-card-2-front'),
                    array('label' => 'Fund via Bank Transfer', 'href' => 'VirtualBanks.php', 'icon' => 'bank'),
                    array('label' => 'Wallet to Bank', 'href' => 'SendFund.php', 'icon' => 'arrow-up-right-circle', 'service' => 'bank_transfer'),
                    array('label' => 'Share Fund', 'href' => 'ShareFund.php', 'icon' => 'share'),
                    array('label' => 'Convert Coins', 'href' => 'CoinConversion.php', 'icon' => 'arrow-left-right', 'service' => 'vtu_coins'),
                    array('label' => 'Virtual Card', 'href' => 'VirtualCard.php', 'icon' => 'credit-card', 'service' => 'virtual_card'),
                    array('label' => 'Gift Card', 'href' => 'GiftCard.php', 'icon' => 'gift', 'service' => 'gift_card'),
                    array('label' => 'Crypto', 'href' => 'CryptoHub.php', 'icon' => 'currency-bitcoin', 'service' => 'crypto_hub'),
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
                'match' => array('AISuite.php', 'AI-Assistant.php', 'BulkSMS.php', 'SubmitSenderID.php', 'Pricing.php', 'BusinessUpgrade.php', 'APIDocs.php'),
                'items' => array(
                    array('label' => 'AI Suite', 'href' => 'AISuite.php', 'icon' => 'robot', 'service' => 'ai_suite', 'badge' => 'NEW'),
                    array('label' => 'AI Assistant Terminal', 'href' => 'AI-Assistant.php', 'icon' => 'chat-dots', 'service' => 'ai_suite'),
                    array('label' => 'Bulk SMS', 'href' => 'BulkSMS.php', 'icon' => 'chat-square-text', 'service' => 'bulk_sms'),
                    array('label' => 'Submit SMS ID', 'href' => 'SubmitSenderID.php', 'icon' => 'send-check', 'service' => 'bulk_sms'),
                    array('label' => 'Account Pricing', 'href' => 'Pricing.php', 'icon' => 'tags'),
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
        a { color: var(--brand); }
        img { max-width: 100%; }
        /* Soft brand wash instead of the old flat grey. */
        .pk-app__bg {
            position: fixed; inset: 0; z-index: -1; pointer-events: none;
            background:
                radial-gradient(760px 420px at 8% -6%, rgba(15, 118, 110, .14), transparent 70%),
                radial-gradient(720px 460px at 94% 6%, rgba(15, 118, 110, .10), transparent 70%),
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
            width: 36px; height: 36px; border-radius: 10px; background: var(--brand); color: #fff;
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
        .pk-app__glink.is-active { background: #f0fdfa; color: var(--brand); }
        .pk-app__gicon {
            width: 27px; height: 27px; border-radius: 8px; background: var(--wash); color: var(--brand);
            display: grid; place-items: center; font-size: .92rem; flex: 0 0 auto;
        }
        .pk-app__glink.is-active .pk-app__gicon { background: var(--brand); color: #fff; }
        .pk-app__glabel { flex: 1; }
        .pk-app__chev { color: var(--muted); font-size: .7rem; transition: transform .18s ease; }
        .pk-app__glink[aria-expanded="true"] .pk-app__chev { transform: rotate(90deg); }
        .pk-app__badge {
            font-size: .62rem; font-weight: 800; letter-spacing: .06em; color: var(--brand);
            background: #f0fdfa; border: 1px solid #ccfbf1; border-radius: 999px; padding: 1px 6px;
        }
        .pk-app__sub { list-style: none; padding: 2px 0 8px 47px; display: none; }
        .pk-app__sub.is-open { display: block; }
        .pk-app__slink {
            display: flex; align-items: center; gap: 8px; padding: 7px 10px; border-radius: 8px;
            color: var(--muted); text-decoration: none; font-size: .86rem; font-weight: 600;
        }
        .pk-app__slink:hover { color: var(--brand); background: var(--wash); }
        .pk-app__slink.is-active { color: var(--brand); background: #f0fdfa; }
        .pk-app__user {
            display: flex; align-items: center; gap: 10px; padding: 13px 16px;
            border-top: 1px solid var(--line); text-decoration: none; color: var(--ink);
        }
        .pk-app__avatar {
            width: 34px; height: 34px; border-radius: 50%; background: var(--brand); color: #fff;
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
        .pk-app__title { font-size: 1.04rem; font-weight: 800; letter-spacing: -.01em; }
        .pk-app__crumb { color: var(--muted); font-size: .82rem; }
        .pk-app__actions { margin-left: auto; display: flex; align-items: center; gap: 8px; }
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
            position: absolute; right: 0; top: calc(100% + 8px); min-width: 230px; padding: 8px;
            background: #fff; border: 1px solid var(--line); border-radius: 12px; box-shadow: var(--shadow);
            display: none; z-index: 70;
        }
        .pk-app__menu.is-open { display: block; }
        .pk-app__menuhead { padding: 8px 10px 10px; border-bottom: 1px solid var(--line); margin-bottom: 6px; }
        .pk-app__menuhead strong { display: block; font-size: .9rem; }
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
        }
        .pk-app__foot a { color: var(--muted); margin-left: 10px; }
        @media (max-width: 640px) { .pk-app__hide-sm { display: none; } }
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
        .pk-app__wrap .text-primary { color: var(--brand) !important; }
        .pk-app__wrap .text-secondary { color: var(--muted) !important; }
        .pk-app__wrap .text-success { color: var(--ok) !important; }
        .pk-app__wrap .text-danger { color: var(--bad) !important; }
        .pk-app__wrap .text-warning { color: var(--warn) !important; }
        .pk-app__wrap .text-info { color: var(--info) !important; }
        .pk-app__wrap .text-dark-primary { color: var(--brand) !important; }
        .pk-app__wrap .text-body { color: var(--ink) !important; }
        /* Background utilities follow Bootstrap semantics: bg-opacity-* tints the
           element's own bg-* colour (via --bs-bg-opacity). The previous white-tint
           version made bg-<colour> bg-opacity-10 badges/buttons render white-on-white. */
        .pk-app__wrap .bg-light { background-color: rgba(var(--wash-rgb), var(--bs-bg-opacity, 1)) !important; }
        .pk-app__wrap .bg-white { background-color: rgba(255, 255, 255, var(--bs-bg-opacity, 1)) !important; }
        .pk-app__wrap .bg-primary { background-color: rgba(var(--bs-primary-rgb), var(--bs-bg-opacity, 1)) !important; color: #fff; }
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
        .pk-app__wrap .card-blue, .pk-app__wrap .card-primary { background: var(--brand); color: #fff; }
        /* Buttons */
        .pk-app__wrap .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 7px; font: inherit;
            font-size: .92rem; font-weight: 700; line-height: 1.2; padding: 10px 16px; cursor: pointer;
            border: 1px solid transparent; border-radius: 10px; background: var(--wash); color: var(--ink);
            text-decoration: none; transition: background .15s ease, color .15s ease;
        }
        .pk-app__wrap .btn:hover { background: #eceff4; }
        .pk-app__wrap .btn-primary { background: var(--brand); color: #fff; }
        .pk-app__wrap .btn-primary:hover { background: var(--brand-dark); }
        .pk-app__wrap .btn-secondary { background: #e2e8f0; color: var(--ink); }
        .pk-app__wrap .btn-success { background: var(--ok); color: #fff; }
        .pk-app__wrap .btn-danger { background: var(--bad); color: #fff; }
        .pk-app__wrap .btn-warning { background: var(--warn); color: #fff; }
        .pk-app__wrap .btn-dark { background: var(--ink); color: #fff; }
        .pk-app__wrap .btn-light { background: #fff; color: var(--ink); border-color: var(--line); }
        .pk-app__wrap .btn-outline-primary { background: transparent; color: var(--brand); border-color: var(--brand); }
        .pk-app__wrap .btn-outline-primary:hover { background: var(--brand); color: #fff; }
        .pk-app__wrap .btn-outline-secondary { background: transparent; color: var(--ink); border-color: var(--line); }
        .pk-app__wrap .btn-outline-light { background: transparent; color: #fff; border-color: rgba(255,255,255,.6); }
        .pk-app__wrap .btn-outline-success { background: transparent; color: var(--ok); border-color: var(--ok); }
        .pk-app__wrap .btn-outline-success:hover { background: var(--ok); color: #fff; }
        .pk-app__wrap .btn-outline-danger { background: transparent; color: var(--bad); border-color: var(--bad); }
        .pk-app__wrap .btn-outline-danger:hover { background: var(--bad); color: #fff; }
        .pk-app__wrap .btn-outline-warning { background: transparent; color: var(--warn); border-color: var(--warn); }
        .pk-app__wrap .btn-outline-warning:hover { background: var(--warn); color: #fff; }
        .pk-app__wrap .btn-outline-info { background: transparent; color: var(--info); border-color: var(--info); }
        .pk-app__wrap .btn-outline-info:hover { background: var(--info); color: #fff; }
        .pk-app__wrap .btn-outline-dark { background: transparent; color: var(--ink); border-color: var(--ink); }
        .pk-app__wrap .btn-outline-dark:hover { background: var(--ink); color: #fff; }
        .pk-app__wrap .btn-link { background: none; color: var(--brand); padding: 0; }
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
        .pk-app__wrap .badge.bg-primary, .pk-app__wrap .badge-primary { background: var(--brand); color: #fff; }
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
            border-color: var(--brand); box-shadow: 0 0 0 3px rgba(15, 118, 110, .14); background: #fff;
        }
        .pk-app__wrap .form-control::placeholder { color: #94a3b8; }
        .pk-app__wrap .input-group { display: flex; }
        .pk-app__wrap .input-group .form-control { border-top-right-radius: 0; border-bottom-right-radius: 0; }
        .pk-app__wrap .input-group .btn { border-top-left-radius: 0; border-bottom-left-radius: 0; }
        .pk-app__wrap .form-check { display: flex; align-items: center; gap: 8px; font-size: .9rem; }
        .pk-app__wrap .form-check-input { width: 16px; height: 16px; accent-color: var(--brand); }
        .pk-app__wrap .progress { display: flex; height: 8px; overflow: hidden; border-radius: 999px; background: #e2e8f0; }
        .pk-app__wrap .progress-bar { display: flex; flex-direction: column; justify-content: center;
            overflow: hidden; color: #fff; text-align: center; white-space: nowrap; background-color: var(--brand); }
        /* Modal, dropdown, spinner */
        .pk-app__wrap .modal { position: fixed; inset: 0; z-index: 90; display: none;
            align-items: center; justify-content: center; padding: 20px; background: rgba(11,18,32,.5); }
        .pk-app__wrap .modal.show { display: flex; }
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
            background: linear-gradient(135deg, var(--brand) 0%, var(--brand-dark) 100%);
            border: none; color: #fff;
            box-shadow: 0 10px 22px -12px color-mix(in srgb, var(--brand) 75%, transparent);
        }
        .pk-app__wrap .btn-primary:hover { background: linear-gradient(135deg, var(--brand-dark) 0%, var(--brand) 130%); color: #fff; }
        .pk-app__wrap .btn-outline-primary { border: 1px solid color-mix(in srgb, var(--brand) 45%, var(--line)); color: var(--brand); background: transparent; }
        .pk-app__wrap .btn-outline-primary:hover { background: color-mix(in srgb, var(--brand) 8%, var(--surface)); color: var(--brand-dark); }
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
        .pk-app__wrap .nav-pills .nav-link.active { background: var(--brand); border-color: var(--brand); color: #fff; }
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
        .pk-app__wrap .pagination .page-item.active .page-link { background: var(--brand); border-color: var(--brand); color: #fff; }
        .pk-app__wrap a { color: var(--brand); }
        .pk-app__wrap .text-primary { color: var(--brand) !important; }
        .pk-app__wrap .bg-primary { background: var(--brand) !important; }
        .pk-app__wrap hr { border: 0; border-top: 1px solid var(--line); opacity: 1; }
        @keyframes pk-app__spin { to { transform: rotate(360deg); } }
        @media (max-width: 1080px) {
            .pk-app__side { transform: translateX(-100%); box-shadow: var(--shadow); }
            .pk-app__side.is-open { transform: none; }
            .pk-app__main { margin-left: 0; }
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
            background: linear-gradient(135deg, var(--brand), var(--brand-dark)) !important;
            border: none; color: #fff !important;
            box-shadow: 0 8px 20px -8px color-mix(in srgb, var(--brand) 80%, transparent);
        }
        .pk-app__wrap .btn-primary:hover { filter: brightness(1.07); transform: translateY(-1px); }
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
        .pk-matrix__label { font-weight: 700; font-size: .95rem; line-height: 1.25; }
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.10.5/font/bootstrap-icons.min.css">
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
            $group_active = !empty($group['match']) && in_array($active, (array)$group['match'], true);
            $group_id = isset($group['id']) ? (string)$group['id'] : '';
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
        <button class="pk-app__iconbtn" type="button" data-bs-toggle="modal" data-bs-target="#referralModal" title="Refer and Earn">
            <i class="bi bi-gift" aria-hidden="true"></i><span class="pk-app__hide-sm">Refer</span>
        </button>
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
            <button class="pk-app__iconbtn" type="button" id="pkAppProfile" aria-expanded="false" aria-haspopup="true">
                <span class="pk-app__avatar"><?php echo htmlspecialchars(strtoupper(substr($username, 0, 1))); ?></span>
                <span class="pk-app__hide-sm"><?php echo htmlspecialchars($username); ?></span>
                <i class="bi bi-chevron-down" style="font-size:.7rem;color:var(--muted);" aria-hidden="true"></i>
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
        alert((type === 'error' ? 'Alert: ' : 'Message: ') + msg.replace(/<br\s*\/?>/gi, '\n').replace(/<[^>]*>/g, ''));
    }
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
        $user = isset($GLOBALS['get_logged_user_details']) && is_array($GLOBALS['get_logged_user_details'])
            ? $GLOBALS['get_logged_user_details'] : null;
        $host = isset($GLOBALS['web_http_host']) && $GLOBALS['web_http_host'] !== ''
            ? (string)$GLOBALS['web_http_host'] : bc_app_base();

        if ($user && function_exists('isServiceEnabled') && isServiceEnabled('ai_suite')) {
            $current_page = basename(isset($_SERVER['PHP_SELF']) ? (string)$_SERVER['PHP_SELF'] : '');
            $vtu_pages = array('Airtime.php', 'Data.php', 'Cable.php', 'Electric.php', 'Betting.php', 'Exam.php', 'BulkAirtime.php', 'BulkData.php', 'BulkSMS.php', 'AI-Assistant.php');
            if (!in_array($current_page, $vtu_pages, true)):
                $ai_token_bal = (int)($user['ai_token_balance'] ?? 0);
                $ai_user_ctx  = function_exists('bc_get_ai_user_context') ? bc_get_ai_user_context($user) : array();
                $init_msg = "Hello! I'm your AI Assistant. How can I help you?";
                $page_tips = array(
                    'Airtime.php'  => 'Try: "Buy MTN 100 airtime for 08012345678"',
                    'Data.php'     => 'Try: "Buy Airtel 2GB SME data for 08123456789"',
                    'Electric.php' => 'Try: "Pay 2000 for IKEDC Prepaid 010123456789"',
                    'Cable.php'    => 'Try: "Renew DSTV Compact for 123456789"',
                );
                if (isset($page_tips[$current_page])) {
                    $init_msg .= ' You can say something like: ' . $page_tips[$current_page];
                }
                ?>
<script>
  window.__ai_enabled     = true;
  window.__ai_page_slug   = <?php echo json_encode(preg_replace('/[^a-z0-9_]/', '_', strtolower(basename(isset($_SERVER['PHP_SELF']) ? (string)$_SERVER['PHP_SELF'] : '', '.php')))); ?>;
  window.__ai_handler_url = <?php echo json_encode($host . '/web/ai-handler.php?context=user'); ?>;
  window.__ai_guide_url   = <?php echo json_encode($host . '/web/ai-guide-cache.php?context=user'); ?>;
  window.__ai_tokens      = <?php echo (int)$ai_token_bal; ?>;
  window.__ai_context     = <?php echo json_encode($ai_user_ctx, JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP) ?: '{}'; ?>;
  window.__ai_auto_open   = false;
  window.__ai_init_msg    = <?php echo json_encode($init_msg, JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP); ?>;
</script>
<script src="<?php echo htmlspecialchars($host, ENT_QUOTES, 'UTF-8'); ?>/jsfile/ai-assistant.js" defer></script>
                <?php
            endif;
        }

        if ($user && !empty($user['username'])):
            ?>
<!-- Referral Modal -->
<div class="modal" id="referralModal" tabindex="-1" aria-labelledby="referralModalLabel" aria-hidden="true">
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
        // 'bare' is for pages that own their own layout (print targets, full-screen terminals):
        // they still get the flash dialog and the shell script, but not the wrapper-closing tags.
        if (empty($opts['bare'])): ?></div>
</main>
</div>
<?php endif; ?>
<?php if (empty($opts['nonav'])): ?>
<footer class="pk-app__foot">
    <span>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($site['title']); ?></span>
    <?php if (!empty($opts['note'])): ?><span> &middot; <?php echo $opts['note']; ?></span><?php endif; ?>
    <a href="<?php echo htmlspecialchars(bc_app_url('SiteMap.php')); ?>">Platform Guide</a>
</footer>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.0.16/dist/sweetalert2.all.min.js"></script>
<?php bc_app_flash(); ?>
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
</body>
</html>
        <?php
    }
}

<?php
/**
 * Plate admin shell — the shared chrome for bc-admin/ and bc-spadmin/.
 *
 * Same design system as func/bc-plate-app.php, but with the two back-office
 * navigation models and the back-office asset bundles (jQuery, SweetAlert2,
 * DataTables, Quill, charts, the bc-custom-*.js helpers).
 *
 * A page migrates the same way as a console page: replace its <head>, the
 * bc-admin-header.php / bc-spadmin-header.php include and the
 * bc-admin-footer.php / bc-spadmin-footer.php include with
 *
 *     bc_adm_head("Page title");
 *     bc_adm_sidebar();
 *     bc_adm_topbar(array("title" => "PAGE", "crumb" => "Home / Page"));
 *     ... page body ...
 *     bc_adm_foot();
 *
 * The area is derived from PHP_SELF, so an existing page keeps working whichever
 * back office it lives in; pass array('area' => 'spadmin') to override.
 */

if (!function_exists('bc_adm_area_from_path')) {
    /** Which back office a path belongs to. */
    function bc_adm_area_from_path($path = null) {
        $path = $path === null ? (string)(isset($_SERVER['PHP_SELF']) ? $_SERVER['PHP_SELF'] : '') : (string)$path;
        if (strpos($path, '/bc-spadmin/') !== false) return 'spadmin';
        if (strpos($path, '/bc-admin/') !== false) return 'admin';
        return 'admin';
    }
}

if (!function_exists('bc_adm_area')) {
    function bc_adm_area($opts = array()) {
        if (isset($opts['area']) && in_array($opts['area'], array('admin', 'spadmin'), true)) {
            return $opts['area'];
        }
        return bc_adm_area_from_path();
    }
}

if (!function_exists('bc_adm_colour')) {
    /**
     * The tenant's brand colour, validated. Same rule as the app and auth shells:
     * a stored value must be a #rrggbb hex or it cannot inject CSS.
     */
    function bc_adm_colour($details = null) {
        $details = is_array($details) ? $details : array();
        $raw = isset($details['primary_color']) ? (string)$details['primary_color'] : '';
        if ($raw === '' && isset($GLOBALS['vendor_primary_color'])) {
            $raw = (string)$GLOBALS['vendor_primary_color'];
        }
        return preg_match('/^#[0-9a-fA-F]{6}$/', $raw) ? $raw : '#0f766e';
    }
}

if (!function_exists('bc_adm_site')) {
    /**
     * Site details for the back office in question. The admin area reads the
     * super-admin site row, matching what bc-admin-header.php did.
     */
    function bc_adm_site($area = null) {
        $area = $area ?: bc_adm_area();
        // §46: the brand must show the Site Name the owner actually set for
        // THIS website. bc-spadmin sets the platform name in
        // sas_super_admin_site_details (bc-spadmin-config loads it as
        // get_all_super_admin_site_details — the old key here,
        // 'get_all_spadmin_site_details', never exists, so the brand fell
        // back to a hard-coded product name). A vendor sets their own name
        // in sas_site_details, loaded by bc-admin-config as
        // get_all_site_details; that wins in the vendor back office, with
        // the platform row as fallback.
        $keys = $area === 'spadmin'
            ? array('get_all_super_admin_site_details', 'get_all_spadmin_site_details', 'get_all_site_details')
            : array('get_all_site_details', 'get_all_super_admin_site_details');
        $details = array();
        foreach ($keys as $key) {
            if (isset($GLOBALS[$key]) && is_array($GLOBALS[$key]) && !empty($GLOBALS[$key]['site_title'])) {
                $details = $GLOBALS[$key];
                break;
            }
        }
        if (!$details && isset($GLOBALS[$keys[0]]) && is_array($GLOBALS[$keys[0]])) {
            $details = $GLOBALS[$keys[0]];
        }
        // §46: never invent a brand name — fall back to this site's own host.
        $title = (string)($_SERVER['HTTP_HOST'] ?? 'Admin Panel');
        if (!empty($details['site_title'])) {
            $title = (string)$details['site_title'];
        } elseif (!empty($GLOBALS['site_title'])) {
            $title = (string)$GLOBALS['site_title'];
        }
        return array(
            'area'    => $area,
            'title'   => $title,
            'details' => $details,
            'user'    => bc_adm_user($area),
            'host'    => isset($GLOBALS['web_http_host']) ? (string)$GLOBALS['web_http_host'] : '',
        );
    }
}

if (!function_exists('bc_adm_user')) {
    /** The signed-in back-office user, from the session the old headers read. */
    function bc_adm_user($area = null) {
        $area = $area ?: bc_adm_area();
        if ($area === 'spadmin') {
            $name = isset($_SESSION['spadmin_session']) ? (string)$_SESSION['spadmin_session'] : '';
            $row  = isset($GLOBALS['get_logged_spadmin_details']) && is_array($GLOBALS['get_logged_spadmin_details'])
                ? $GLOBALS['get_logged_spadmin_details'] : array();
        } else {
            $name = isset($_SESSION['admin_session']) ? (string)$_SESSION['admin_session'] : '';
            $row  = isset($GLOBALS['get_logged_admin_details']) && is_array($GLOBALS['get_logged_admin_details'])
                ? $GLOBALS['get_logged_admin_details'] : array();
        }
        if ($name === '' && !empty($row['username'])) $name = (string)$row['username'];
        if ($name === '' && !empty($row['email']))    $name = (string)$row['email'];
        return array('name' => $name, 'row' => $row);
    }
}

if (!function_exists('bc_adm_base')) {
    /** Absolute base URL, matching bc_app_base(): $web_http_host when it is set. */
    function bc_adm_base() {
        $site = bc_adm_site();
        if ($site['host'] !== '') return $site['host'];
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host  = isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : 'localhost';
        return $proto . '://' . $host;
    }
}

if (!function_exists('bc_adm_current_page')) {
    function bc_adm_current_page() {
        $self = isset($_SERVER['PHP_SELF']) ? (string)$_SERVER['PHP_SELF'] : '';
        return $self === '' ? '' : basename($self);
    }
}

if (!function_exists('bc_adm_url')) {
    /** Link to a page inside the current back office. */
    function bc_adm_url($file, $area = null) {
        $area = $area ?: bc_adm_area();
        $base = bc_adm_base();
        $dir  = $area === 'spadmin' ? 'bc-spadmin' : 'bc-admin';
        return $base . '/' . $dir . '/' . ltrim((string)$file, '/');
    }
}

if (!function_exists('bc_adm_icon')) {
    /** Back-office pages use bootstrap-icons glyphs; emit the same <i> markup. */
    function bc_adm_icon($name, $class = '') {
        $name = preg_replace('/^bi-/', '', (string)$name);
        $cls  = $class !== '' ? ' ' . $class : '';
        return '<i class="bi bi-' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . $cls . '" aria-hidden="true"></i>';
    }
}

if (!function_exists('bc_adm_item_visible')) {
    /**
     * An item's gates. Same rule as the app shell: every gate defaults to visible,
     * so a page included before the fintech layer loads degrades to shown.
     */
    function bc_adm_item_visible($item, $area = null) {
        if (!empty($item['service'])) {
            if (!function_exists('isServiceEnabled')) return true;
            if (!isServiceEnabled($item['service'])) return false;
        }
        // An entry whose page does not exist in this edition is dead weight in the
        // menu (e.g. the standalone edition has no Fund/Self*/Renew pages, and had
        // no USSDChannel page). Hide it instead of linking to a 404.
        if (!empty($item['href'])) {
            $area = $area ?: bc_adm_area();
            $dir  = $area === 'spadmin' ? 'bc-spadmin' : 'bc-admin';
            $file = basename(explode('?', (string)$item['href'])[0]);
            if ($file !== '' && !is_file(__DIR__ . '/../' . $dir . '/' . $file)) return false;
        }
        return true;
    }
}

if (!function_exists('bc_adm_hub_slug')) {
    /** URL slug for a menu item with children (the Hub page's ?g= value). */
    function bc_adm_hub_slug($label) {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string)$label));
        return trim($slug, '-');
    }
}

if (!function_exists('bc_adm_hub_find')) {
    /** Find a children-bearing menu item (either area) by its hub slug. */
    function bc_adm_hub_find($area, $slug) {
        foreach (bc_adm_menu($area) as $group) {
            foreach ((array)$group['items'] as $item) {
                if (!empty($item['children']) && bc_adm_hub_slug($item['label']) === (string)$slug) {
                    return $item;
                }
            }
        }
        return null;
    }
}

if (!function_exists('bc_adm_item_icon')) {
    /**
     * An icon that fits the child page itself. Menu children carry no icon key,
     * so the Hub matrix used to repeat the parent item's glyph on every card.
     * Resolution: an explicit 'icon' key wins, then a per-page map, then a
     * label-keyword fallback.
     */
    function bc_adm_item_icon($item) {
        if (!empty($item['icon'])) return (string)$item['icon'];
        // Print variants share one page; give each type its own glyph.
        $href = (string)($item['href'] ?? '');
        if (strpos($href, 'DataBundleCard.php') !== false) {
            foreach (array('airtime' => 'phone', 'cable' => 'tv', 'electric' => 'plug', 'exam' => 'pencil-square', 'betting' => 'trophy', 'data' => 'wifi') as $type => $icon) {
                if (strpos($href, 'type=' . $type) !== false) return $icon;
            }
        }
        $file = basename(explode('?', $href)[0]);
        $map = array(
            'Dashboard.php' => 'grid', 'SiteMap.php' => 'grid-3x3-gap', 'AccountSettings.php' => 'gear',
            'Users.php' => 'people', 'CreateUser.php' => 'person-plus', 'UserEdit.php' => 'person-gear',
            'Transactions.php' => 'list-ul', 'BatchTransactions.php' => 'layers', 'SelfTransactions.php' => 'person-lines-fill',
            'PaymentOrders.php' => 'receipt', 'SelfPaymentOrders.php' => 'receipt-cutoff', 'Withdrawals.php' => 'cash-coin',
            'FundTransferRequests.php' => 'arrow-left-right', 'ShareFund.php' => 'share', 'Fund.php' => 'wallet2',
            'StatusMessage.php' => 'megaphone', 'EmailTemplates.php' => 'envelope-paper', 'SendMail.php' => 'envelope',
            'AppUpdateBroadcast.php' => 'broadcast', 'IDBlockingSystem.php' => 'shield-lock', 'TransactionLimits.php' => 'sliders',
            'SalesCalculator.php' => 'calculator', 'SenderIDRequests.php' => 'send-check', 'SelfSubmitPayment.php' => 'cash-stack',
            'PaymentGateway.php' => 'credit-card', 'LoyaltySettings.php' => 'award', 'CoinConversions.php' => 'arrow-repeat',
            'CryptoHub.php' => 'currency-bitcoin', 'CryptoInvoices.php' => 'file-earmark-text', 'FloatServiceIcons.php' => 'grid-1x2',
            'KYCManagement.php' => 'person-check', 'BusinessKYB.php' => 'building-check', 'SupportChat.php' => 'chat-dots', 'AccountDeletionRequests.php' => 'person-x',
            'ChatSettings.php' => 'chat-square-text', 'BruteForceSecurity.php' => 'shield-exclamation', 'ServiceControl.php' => 'toggles',
            'MarketPlace.php' => 'shop', 'ProductSetUp.php' => 'box-seam', 'IdentityAPI.php' => 'fingerprint',
            'Airtime.php' => 'phone', 'SharedData.php' => 'diagram-3', 'SmeData.php' => 'database', 'CorporateData.php' => 'building',
            'DirectData.php' => 'hdd-network', 'Cable.php' => 'tv', 'Electric.php' => 'plug', 'Exam.php' => 'pencil-square',
            'Betting.php' => 'trophy', 'BulkSMS.php' => 'chat-square-text', 'DataBundleCard.php' => 'printer',
            'NINCard.php' => 'person-vcard', 'BVNVerification.php' => 'shield-check', 'GiftCard.php' => 'gift',
            'VirtualCard.php' => 'credit-card-2-front', 'RenewSubscription.php' => 'arrow-clockwise', 'SubscriptionHistory.php' => 'clock-history',
            'BlogPosts.php' => 'journal-text', 'BlogCategories.php' => 'tags', 'BlogPostEdit.php' => 'pencil-square',
            'VendorReg.php' => 'person-plus', 'Vendors.php' => 'people', 'VendorRegistrations.php' => 'hourglass-split',
            'UnblockRequests.php' => 'unlock', 'DomainSettings.php' => 'globe', 'DomainChangeRequests.php' => 'arrow-repeat',
            'BillingPackages.php' => 'box-seam', 'AllSubscriptions.php' => 'collection', 'CreateAPI.php' => 'braces',
            'APIRequests.php' => 'key', 'KnowledgeBase.php' => 'book', 'NumberChecker.php' => 'phone-flip',
            'USSDChannel.php' => 'phone', 'AIMarketing.php' => 'robot', 'AISettings.php' => 'cpu',
        );
        if (isset($map[$file])) return $map[$file];
        $label = strtolower((string)($item['label'] ?? ''));
        foreach (array(
            'mail' => 'envelope', 'user' => 'people', 'transaction' => 'list-ul', 'payment' => 'credit-card',
            'fund' => 'wallet2', 'setting' => 'gear', 'security' => 'shield-lock', 'block' => 'shield-lock',
            'domain' => 'globe', 'blog' => 'journal-text', 'post' => 'journal-text', 'api' => 'braces',
            'crypto' => 'currency-bitcoin', 'coin' => 'arrow-repeat', 'kyc' => 'person-check', 'card' => 'credit-card',
            'data' => 'database', 'airtime' => 'phone', 'sms' => 'chat-square-text', 'chat' => 'chat-dots',
            'subscription' => 'collection', 'billing' => 'receipt', 'vendor' => 'people', 'print' => 'printer',
        ) as $needle => $icon) {
            if (strpos($label, $needle) !== false) return $icon;
        }
        return 'circle';
    }
}

if (!function_exists('bc_adm_hub_render')) {
    /**
     * Render a menu item's children as an embossed dark matrix (see bc_app_css).
     * Used by bc-admin/Hub.php and bc-spadmin/Hub.php so the sidebar lists only
     * main items and each section gets a landing page.
     */
    function bc_adm_hub_render($item, $area) {
        $children = array();
        foreach ((array)$item['children'] as $child) {
            if (bc_adm_item_visible($child, $area)) $children[] = $child;
        }
        if (!$children) {
            echo '<div class="pk-matrix__empty">Nothing here is available yet.</div>';
            return;
        }
        echo '<div class="pk-matrix">';
        foreach ($children as $child) {
            echo '<a class="pk-matrix__card" href="' . htmlspecialchars(bc_adm_url((string)$child['href'], $area)) . '">';
            echo '<span class="pk-matrix__icon">' . bc_adm_icon(bc_adm_item_icon($child)) . '</span>';
            echo '<span class="pk-matrix__label">' . htmlspecialchars((string)$child['label']) . '</span>';
            echo '<span class="pk-matrix__go">' . bc_adm_icon('arrow-right-circle') . '</span>';
            echo '</a>';
        }
        echo '</div>';
    }
}

if (!function_exists('bc_adm_menu')) {
    /**
     * The back-office navigation models.
     *
     * Carried across from bc-admin-header.php and bc-spadmin-header.php so there is one
     * definition per area. Item keys:
     *   label    — visible name
     *   href     — page filename inside the area
     *   icon     — bootstrap-icons glyph
     *   match    — filenames that mark this item (or its group) active
     *   service — vendor-wide switch, checked with isServiceEnabled()
     *   children — collapse group contents
     */
    function bc_adm_menu($area = null) {
        $area = $area ?: bc_adm_area();
        // Edition marker (per-tree file): the standalone tree has no
        // RenewSubscription/SubscriptionHistory pages, so its Subscription
        // Manager points at the portal snapshot instead of two 404s.
        if (!function_exists("bc_is_saas_edition") && is_file(__DIR__ . "/bc-edition.php")) include_once(__DIR__ . "/bc-edition.php");
        $bc_menu_is_saas = function_exists("bc_is_saas_edition") ? bc_is_saas_edition() : true;

        if ($area === 'spadmin') {
            return array(
                array('items' => array(
                    array('label' => 'Dashboard',             'href' => 'Dashboard.php',        'icon' => 'grid',            'match' => array('Dashboard.php')),
                    array('label' => 'Platform Guide',        'href' => 'SiteMap.php',          'icon' => 'grid-3x3-gap',    'match' => array('SiteMap.php')),
                    array('label' => 'Global Service Control','href' => 'ServiceControl.php',   'icon' => 'shield-lock',     'match' => array('ServiceControl.php')),
                    array('label' => 'AI Manager',            'href' => 'AIManagement.php',     'icon' => 'cpu-fill',        'match' => array('AIManagement.php')),
                    array('label' => 'Automation & AI Guide', 'href' => 'AutomationGuide.php',  'icon' => 'journal-text',    'match' => array('AutomationGuide.php')),
                    array('label' => 'USSD Channel Access',   'href' => 'USSDAccessFee.php',    'icon' => 'phone',           'match' => array('USSDAccessFee.php')),
                    array('label' => 'System Update',         'href' => 'UpdateSystem.php',     'icon' => 'cloud-arrow-up',  'match' => array('UpdateSystem.php')),
                )),
                array('label' => 'Management', 'items' => array(
                    array('label' => 'Manage Vendors', 'icon' => 'menu-button-wide', 'match' => array('VendorReg.php','Vendors.php','VendorRegistrations.php','UnblockRequests.php','DomainSettings.php','DomainChangeRequests.php'), 'children' => array(
                        array('label' => 'Add Vendor',            'href' => 'VendorReg.php',            'match' => array('VendorReg.php')),
                        array('label' => 'View Vendors',          'href' => 'Vendors.php',              'match' => array('Vendors.php')),
                        array('label' => 'Pending Registrations', 'href' => 'VendorRegistrations.php',  'match' => array('VendorRegistrations.php')),
                        array('label' => 'Vendor Signup Security', 'href' => 'VendorRegistrationSettings.php', 'match' => array('VendorRegistrationSettings.php')),
                        array('label' => 'Unblock Requests',      'href' => 'UnblockRequests.php',      'match' => array('UnblockRequests.php')),
                        array('label' => 'Domain Settings',       'href' => 'DomainSettings.php',       'match' => array('DomainSettings.php')),
                        array('label' => 'Domain Requests',       'href' => 'DomainChangeRequests.php', 'match' => array('DomainChangeRequests.php')),
                    )),
                    array('label' => 'Manage Billings', 'icon' => 'menu-button-wide', 'match' => array('BillingPackages.php','AllSubscriptions.php'), 'children' => array(
                        array('label' => 'Billing Packages',  'href' => 'BillingPackages.php',  'match' => array('BillingPackages.php')),
                        array('label' => 'All Subscriptions', 'href' => 'AllSubscriptions.php', 'match' => array('AllSubscriptions.php')),
                    )),
                    array('label' => 'Manage API', 'icon' => 'menu-button-wide', 'match' => array('CreateAPI.php','MarketPlace.php'), 'children' => array(
                        array('label' => 'Add API',          'href' => 'CreateAPI.php',   'match' => array('CreateAPI.php')),
                        array('label' => 'View MarketPlace', 'href' => 'MarketPlace.php', 'match' => array('MarketPlace.php')),
                    )),
                    array('label' => 'Compliance', 'icon' => 'shield-check', 'match' => array('KYCManagement.php','VendorKYC.php'), 'children' => array(
                        array('label' => 'User KYC Review',   'href' => 'KYCManagement.php', 'match' => array('KYCManagement.php')),
                        array('label' => 'Vendor KYC Review', 'href' => 'VendorKYC.php',     'match' => array('VendorKYC.php')),
                    )),
                    array('label' => 'Manage Users', 'icon' => 'people', 'match' => array('Users.php'), 'children' => array(
                        array('label' => 'All Users', 'href' => 'Users.php', 'match' => array('Users.php')),
                    )),
                    array('label' => 'Manage Notification', 'icon' => 'menu-button-wide', 'match' => array('StatusMessage.php','SendMail.php'), 'children' => array(
                        array('label' => 'Update Status Message', 'href' => 'StatusMessage.php', 'match' => array('StatusMessage.php')),
                        array('label' => 'Send Mail',             'href' => 'SendMail.php',      'match' => array('SendMail.php')),
                    )),
                )),
                array('items' => array(
                    array('label' => 'Crypto operations', 'href' => 'CryptoOverview.php', 'icon' => 'currency-bitcoin', 'match' => array('CryptoOverview.php')),
                    array('label' => 'Payment Gateway', 'href' => 'PaymentGateway.php',     'icon' => 'credit-card',  'match' => array('PaymentGateway.php')),
                    array('label' => 'Fund Vendor',     'href' => 'ShareFund.php',          'icon' => 'cash-stack',   'match' => array('ShareFund.php')),
                    array('label' => 'Transactions',    'href' => 'Transactions.php',       'icon' => 'list-check',   'match' => array('Transactions.php')),
                    array('label' => 'Payment Orders',  'href' => 'PaymentOrders.php',      'icon' => 'receipt',      'match' => array('PaymentOrders.php')),
                    array('label' => 'Email Templates', 'href' => 'EmailTemplates.php',     'icon' => 'envelope',     'match' => array('EmailTemplates.php')),
                    array('label' => 'Reports',         'href' => 'SubscriptionReports.php','icon' => 'bar-chart',   'match' => array('SubscriptionReports.php')),
                    array('label' => 'Account Settings', 'href' => 'AccountSettings.php',   'icon' => 'gear',         'match' => array('AccountSettings.php')),
                    array('label' => 'Session Security', 'href' => 'BruteForceSecurity.php','icon' => 'shield-exclamation','match' => array('BruteForceSecurity.php')),
                    array('label' => 'Cron Jobs',       'href' => 'CronJobs.php',           'icon' => 'clock-history','match' => array('CronJobs.php')),
                    array('label' => 'Update System',   'href' => 'UpdateSystem.php',       'icon' => 'cloud-arrow-up','match' => array('UpdateSystem.php')),
                )),
            );
        }

        return array(
            array('items' => array(
                array('label' => 'Dashboard',      'href' => 'Dashboard.php',    'icon' => 'grid',          'match' => array('Dashboard.php')),
                array('label' => 'Platform Guide', 'href' => 'SiteMap.php',      'icon' => 'grid-3x3-gap',  'match' => array('SiteMap.php')),
                array('label' => 'AI Business Suite', 'icon' => 'cpu-fill', 'service' => 'ai_suite',
                      'match' => array('AISettings.php','AIMarketing.php'), 'children' => array(
                    array('label' => 'AI Settings & Tokens', 'href' => 'AISettings.php',  'match' => array('AISettings.php')),
                    array('label' => 'AI Marketing Studio',  'href' => 'AIMarketing.php', 'match' => array('AIMarketing.php')),
                )),
                array('label' => 'USSD Channel', 'href' => 'USSDChannel.php',   'icon' => 'phone',         'match' => array('USSDChannel.php')),
            )),
            array('label' => 'Business Management', 'items' => array(
                array('label' => 'Manage User', 'icon' => 'menu-button-wide',
                      'match' => array('CreateUser.php','KnowledgeBase.php','Users.php','AccountDeletionRequests.php','Transactions.php','BatchTransactions.php','PaymentOrders.php','FundTransferRequests.php','ShareFund.php','APIRequests.php','NumberChecker.php','CryptoInvoices.php','Withdrawals.php'),
                      'children' => array(
                    array('label' => 'Create User',           'href' => 'CreateUser.php',           'match' => array('CreateUser.php')),
                    array('label' => 'API Access Requests',   'href' => 'APIRequests.php',          'match' => array('APIRequests.php')),
                    array('label' => 'Knowledge Base',        'href' => 'KnowledgeBase.php',        'match' => array('KnowledgeBase.php')),
                    array('label' => 'Users',                 'href' => 'Users.php',                'match' => array('Users.php')),
                    array('label' => 'Account Deletion Requests', 'href' => 'AccountDeletionRequests.php', 'match' => array('AccountDeletionRequests.php')),
                    array('label' => 'Crypto Invoices',       'href' => 'CryptoInvoices.php',       'match' => array('CryptoInvoices.php')),
                    array('label' => 'Transactions',          'href' => 'Transactions.php',         'match' => array('Transactions.php')),
                    array('label' => 'Number Credit Checker', 'href' => 'NumberChecker.php',        'match' => array('NumberChecker.php')),
                    array('label' => 'Batch Transactions',    'href' => 'BatchTransactions.php',   'match' => array('BatchTransactions.php')),
                    array('label' => 'Payment Orders',        'href' => 'PaymentOrders.php',        'match' => array('PaymentOrders.php')),
                    array('label' => 'Withdrawal Requests',   'href' => 'Withdrawals.php',          'match' => array('Withdrawals.php')),
                    array('label' => 'Fund Transfer Requests','href' => 'FundTransferRequests.php', 'match' => array('FundTransferRequests.php')),
                    array('label' => 'Fund User',             'href' => 'ShareFund.php',            'match' => array('ShareFund.php')),
                )),
                array('label' => 'System Function', 'icon' => 'menu-button-wide',
                      'match' => array('AccountSettings.php','Fund.php','StatusMessage.php','EmailTemplates.php','SendMail.php','AppUpdateBroadcast.php','IDBlockingSystem.php','TransactionLimits.php','SalesCalculator.php','SenderIDRequests.php','SelfTransactions.php','SelfSubmitPayment.php','SelfPaymentOrders.php','PaymentGateway.php','LoyaltySettings.php','CoinConversions.php','CryptoHub.php','FloatServiceIcons.php','KYCManagement.php','BusinessKYB.php','SupportChat.php','ChatSettings.php','BruteForceSecurity.php','ServiceControl.php'),
                      'children' => array(
                    array('label' => 'Account Settings',     'href' => 'AccountSettings.php',     'match' => array('AccountSettings.php')),
                    array('label' => 'Fund Wallet',          'href' => 'Fund.php',                'match' => array('Fund.php')),
                    array('label' => 'Status Message',       'href' => 'StatusMessage.php',       'match' => array('StatusMessage.php')),
                    array('label' => 'Email Templates',      'href' => 'EmailTemplates.php',      'match' => array('EmailTemplates.php')),
                    array('label' => 'Mail Sender',          'href' => 'SendMail.php',            'match' => array('SendMail.php')),
                    array('label' => 'App Update Broadcast', 'href' => 'AppUpdateBroadcast.php',  'match' => array('AppUpdateBroadcast.php')),
                    array('label' => 'ID Blocking System',   'href' => 'IDBlockingSystem.php',    'match' => array('IDBlockingSystem.php')),
                    array('label' => 'Transaction Limits',   'href' => 'TransactionLimits.php',   'match' => array('TransactionLimits.php')),
                    array('label' => 'Sales Calculator',     'href' => 'SalesCalculator.php',     'match' => array('SalesCalculator.php')),
                    array('label' => 'Sender ID Requests',   'href' => 'SenderIDRequests.php',    'match' => array('SenderIDRequests.php')),
                    array('label' => 'My Transactions',      'href' => 'SelfTransactions.php',    'match' => array('SelfTransactions.php')),
                    array('label' => 'My Submit Payment',    'href' => 'SelfSubmitPayment.php',   'match' => array('SelfSubmitPayment.php')),
                    array('label' => 'My Payment Orders',    'href' => 'SelfPaymentOrders.php',   'match' => array('SelfPaymentOrders.php')),
                    array('label' => 'Payment Gateway',      'href' => 'PaymentGateway.php',      'match' => array('PaymentGateway.php')),
                    array('label' => 'Loyalty Settings',     'href' => 'LoyaltySettings.php',     'match' => array('LoyaltySettings.php')),
                    array('label' => 'Coin Conversions',     'href' => 'CoinConversions.php',     'match' => array('CoinConversions.php')),
                    array('label' => 'Crypto operations', 'href' => 'CryptoOverview.php', 'icon' => 'currency-bitcoin', 'match' => array('CryptoOverview.php')),
                    array('label' => 'Crypto',               'href' => 'CryptoHub.php',           'match' => array('CryptoHub.php')),
                    array('label' => 'Float Service Icons',  'href' => 'FloatServiceIcons.php',   'match' => array('FloatServiceIcons.php')),
                    array('label' => 'KYC Management',       'href' => 'KYCManagement.php',       'match' => array('KYCManagement.php')),
                    array('label' => 'Business KYB',         'href' => 'BusinessKYB.php',         'match' => array('BusinessKYB.php')),
                    array('label' => 'Support Chat',         'href' => 'SupportChat.php',         'match' => array('SupportChat.php')),
                    array('label' => 'Chat Settings',        'href' => 'ChatSettings.php',        'match' => array('ChatSettings.php')),
                    array('label' => 'Brute Force Security',  'href' => 'BruteForceSecurity.php',  'match' => array('BruteForceSecurity.php')),
                    array('label' => 'Service Control Center','href' => 'ServiceControl.php',      'match' => array('ServiceControl.php')),
                    ...($bc_menu_is_saas ? array() : array(array('label' => 'System Update', 'href' => 'UpdateSystem.php', 'match' => array('UpdateSystem.php')))),
                )),
            )),
            array('label' => 'Service Operations', 'items' => array(
                array('label' => 'API Manager', 'icon' => 'menu-button-wide',
                      'match' => array('MarketPlace.php','ProductSetUp.php','IdentityAPI.php','Airtime.php','SharedData.php','SmeData.php','CorporateData.php','DirectData.php','Cable.php','Electric.php','Exam.php','Betting.php','BulkSMS.php','DataBundleCard.php','NINCard.php','BVNVerification.php','GiftCard.php','VirtualCard.php'),
                      'children' => array(
                    array('label' => 'MarketPlace',              'href' => 'MarketPlace.php',      'match' => array('MarketPlace.php')),
                    array('label' => 'Product SetUp',            'href' => 'ProductSetUp.php',     'match' => array('ProductSetUp.php')),
                    array('label' => 'Identity Services Manager','href' => 'IdentityAPI.php',      'match' => array('IdentityAPI.php')),
                    array('label' => 'Airtime',                  'href' => 'Airtime.php',          'match' => array('Airtime.php')),
                    array('label' => 'Shared Data',              'href' => 'SharedData.php',       'match' => array('SharedData.php')),
                    array('label' => 'SME Data',                 'href' => 'SmeData.php',          'match' => array('SmeData.php')),
                    array('label' => 'Corporate Data',           'href' => 'CorporateData.php',    'match' => array('CorporateData.php')),
                    array('label' => 'Direct Data',              'href' => 'DirectData.php',       'match' => array('DirectData.php')),
                    array('label' => 'Cable TV',                 'href' => 'Cable.php',            'match' => array('Cable.php')),
                    array('label' => 'Electricity',              'href' => 'Electric.php',         'match' => array('Electric.php')),
                    array('label' => 'Exam Pins',                'href' => 'Exam.php',             'match' => array('Exam.php')),
                    array('label' => 'Betting',                  'href' => 'Betting.php',          'match' => array('Betting.php')),
                    array('label' => 'Bulk SMS',                 'href' => 'BulkSMS.php',          'match' => array('BulkSMS.php')),
                    array('label' => 'Print Data Card',          'href' => 'DataBundleCard.php',   'match' => array('DataBundleCard.php')),
                    array('label' => 'Print Airtime Card',       'href' => 'DataBundleCard.php?type=airtime', 'match' => array('DataBundleCard.php')),
                    array('label' => 'Print Cable Card',         'href' => 'DataBundleCard.php?type=cable',   'match' => array('DataBundleCard.php')),
                    array('label' => 'Print Electric Token',     'href' => 'DataBundleCard.php?type=electric','match' => array('DataBundleCard.php')),
                    array('label' => 'Print Exam Pin',           'href' => 'DataBundleCard.php?type=exam',    'match' => array('DataBundleCard.php')),
                    array('label' => 'Print Betting Card',       'href' => 'DataBundleCard.php?type=betting', 'match' => array('DataBundleCard.php')),
                    array('label' => 'NIN Slip Requests',        'href' => 'NINCard.php',          'match' => array('NINCard.php')),
                    array('label' => 'BVN Verify Requests',      'href' => 'BVNVerification.php',  'match' => array('BVNVerification.php')),
                    array('label' => 'Gift Card',                'href' => 'GiftCard.php',         'match' => array('GiftCard.php')),
                    array('label' => 'Virtual Card',             'href' => 'VirtualCard.php',      'match' => array('VirtualCard.php')),
                )),
                array('label' => 'Subscription Manager', 'icon' => 'gem',
                      'match' => array('RenewSubscription.php','SubscriptionHistory.php','VendorPortal.php'), 'children' => $bc_menu_is_saas ? array(
                    array('label' => 'Renew Subscription',  'href' => 'RenewSubscription.php',  'match' => array('RenewSubscription.php')),
                    array('label' => 'Subscription History','href' => 'SubscriptionHistory.php','match' => array('SubscriptionHistory.php')),
                ) : array(
                    array('label' => 'View Subscription',   'href' => 'VendorPortal.php',       'match' => array('VendorPortal.php')),
                )),
                array('label' => 'Blog Manager', 'icon' => 'journal-text',
                      'match' => array('BlogPosts.php','BlogCategories.php'), 'children' => array(
                    array('label' => 'Posts',      'href' => 'BlogPosts.php',      'match' => array('BlogPosts.php')),
                    array('label' => 'Categories', 'href' => 'BlogCategories.php', 'match' => array('BlogCategories.php')),
                )),
            )),
        );
    }
}

if (!function_exists('bc_adm_head')) {
    /**
     * The <head>. $opts keys: 'area', 'css' (extra stylesheet hrefs),
     * 'brand' (brand colour override), 'title' handled by the first argument.
     */
    function bc_adm_head($title, $opts = array()) {
        $area   = bc_adm_area($opts);
        $site   = bc_adm_site($area);
        $brand  = isset($opts['brand']) ? (string)$opts['brand'] : bc_adm_colour($site['details']);
        $title  = $title !== '' ? (string)$title : $site['title'];
        $extra  = isset($opts['css']) && is_array($opts['css']) ? $opts['css'] : array();
        ?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="<?php echo htmlspecialchars($brand, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="author" content="Philmore Codes">
    <?php if (!empty($site['details']['site_desc'])): ?>
    <meta name="description" content="<?php echo htmlspecialchars((string)$site['details']['site_desc'], ENT_QUOTES, 'UTF-8'); ?>">
    <?php endif; ?>
    <title><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css">
    <?php foreach ($extra as $href): ?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars((string)$href, ENT_QUOTES, 'UTF-8'); ?>">
    <?php endforeach; ?>
    <?php
    // The app shell owns the design tokens and the Bootstrap-compat layer; this shell
    // only adds what the back offices need on top.
    if (!function_exists('bc_app_css')) {
        require_once __DIR__ . '/bc-plate-app.php';
    }
    bc_app_css($brand);
    bc_adm_css($brand, $area);
    ?>
</head>
        <?php
    }
}

if (!function_exists('bc_adm_css')) {
    /** Back-office additions: nav group headings, the topbar profile menu, print tweaks. */
    function bc_adm_css($brand, $area) {
        ?><style>
        /* User confirmations can live after the content wrapper. Never depend on
           wrapper-scoped Bootstrap compatibility rules for these security forms. */
        #sec33UserModal:not([open]):not(.show) { display: none !important; }
        dialog#sec33UserModal { position: fixed; inset: 0; margin: auto; box-sizing: border-box;
            width: min(94vw, 520px); max-height: 90vh; overflow: auto; padding: 0;
            background: #fff; color: #172033; border: 0; border-radius: 18px;
            box-shadow: 0 24px 80px rgba(0,0,0,.28); }
        #sec33UserModal::backdrop { background: rgba(15,23,42,.6); }
        /* Also cover older cached page markup while a deployment rolls out. */
        div#sec33UserModal.modal.show { display: flex; position: fixed; inset: 0;
            z-index: 2100; align-items: center; justify-content: center;
            padding: 16px; overflow-y: auto; background: rgba(15,23,42,.6); }
        #sec33UserModal .modal-dialog { width: 100%; max-width: 520px; margin: auto; }
        #sec33UserModal .modal-content { box-sizing: border-box; background: #fff; color: #172033;
            padding: 22px; border-radius: 18px; width: 100%; }
        #sec33UserModal .modal-header { display: flex; align-items: center; justify-content: space-between; gap: 16px; }
        #sec33UserModal .modal-title { margin: 0; font-size: 1.1rem; font-weight: 800; }
        #sec33UserModal .modal-body { padding: 18px 0; }
        #sec33UserModal .form-label { display: block; margin-bottom: 8px; }
        #sec33UserModal .form-control { box-sizing: border-box; display: block; width: 100%; min-height: 46px;
            padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 10px; background: #f8fafc; color: #172033; }
        #sec33UserModal .modal-footer { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 10px; }
        #sec33UserModal .btn-close { min-width: 32px; min-height: 32px; cursor: pointer; }
        #sec33UserModal .btn-close::before { content: '\00d7'; font-size: 24px; color: #172033; }
        .pk-app__glabel { letter-spacing: .01em; }
        /* \u00a733 settings tabs: the retired bundle used to hide inactive panes;
           without these rules every pane stacked and the tab rail looked
           distorted. Show the active pane only, and give settings pages a
           proper vertical rail that becomes a scroll strip on small screens. */
        .pk-app__wrap .tab-content > .tab-pane { display: none; }
        .pk-app__wrap .tab-content > .tab-pane.active { display: block; }
        .pk-app__wrap .tab-content > .tab-pane.fade { transition: opacity .15s ease; }
        .pk-app__wrap .tab-content > .tab-pane.fade:not(.show) { opacity: 0; }
        .pk-app__wrap .nav-pills .nav-link { border: 1px solid transparent; color: var(--ink); background: transparent; border-radius: 12px; font-weight: 600; text-align: left; padding: 12px 14px; width: 100%; cursor: pointer; }
        .pk-app__wrap .nav-pills .nav-link:hover { background: var(--wash); }
        .pk-app__wrap .nav-pills .nav-link.active { background: var(--brand); color: var(--brand-fg); }
        .pk-settings-nav { display: flex; flex-direction: column; gap: 6px; flex-wrap: nowrap; }
        .pk-settings-nav .nav-link { display: flex; align-items: center; white-space: normal; }
        .pk-settings-nav .nav-link i { font-size: 1rem; }
        .pk-settings-nav .nav-link::after { content: "\F285"; font-family: "bootstrap-icons"; margin-left: auto; font-size: .8rem; opacity: .55; }
        @media (max-width: 991.98px) {
            .pk-settings-nav { flex-direction: row; overflow-x: auto; padding-bottom: 8px; }
            .pk-settings-nav .nav-link { white-space: nowrap; flex: 0 0 auto; width: auto; }
            .pk-settings-nav .nav-link::after { display: none; }
        }
        .pk-app__wrap .sticky-top { position: sticky; top: 0; }
        /* \u00a737 settings layout: an exact rail/content split on desktop,
           full-width stacked panes on mobile, and the rail card must not
           stay pinned over the settings on small screens. */
        .pk-app__wrap .pk-settings-nav { flex-wrap: nowrap; }
        @media (min-width: 992px) {
            .pk-app__wrap .pk-settings .col-lg-3 { flex: 0 0 calc(25% - 15px); max-width: calc(25% - 15px); }
            .pk-app__wrap .pk-settings .col-lg-9 { flex: 0 0 calc(75% - 5px); max-width: calc(75% - 5px); }
        }
        @media (max-width: 991.98px) {
            .pk-app__wrap .pk-settings .col-lg-3, .pk-app__wrap .pk-settings .col-lg-9 { flex: 1 1 100%; max-width: 100%; }
            .pk-app__wrap .pk-settings .card:has(.pk-settings-nav) { position: static !important; }
        }
        /* Topbar actions are real buttons (icon + label), never bare underlined
           links: solid brand fill, readable on-brand foreground, pill shape. */
        .pk-app__action {
            display: inline-flex; align-items: center; gap: 7px; height: 38px; padding: 0 16px;
            border-radius: 999px; border: 1px solid transparent; font-family: inherit;
            background: var(--brand); color: var(--brand-fg, #fff) !important;
            font-size: .82rem; font-weight: 700; line-height: 1; text-decoration: none !important;
            cursor: pointer; white-space: nowrap;
            box-shadow: 0 8px 18px -8px color-mix(in srgb, var(--brand) 70%, transparent);
            transition: transform .15s ease, box-shadow .15s ease, filter .15s ease;
        }
        .pk-app__action:hover { color: var(--brand-fg, #fff) !important; text-decoration: none !important; transform: translateY(-1px); filter: none; box-shadow: 0 12px 22px -10px color-mix(in srgb, var(--brand) 70%, transparent); }
        .pk-app__action:active { transform: translateY(0); }
        .pk-app__action:focus-visible { outline: 2px solid var(--brand); outline-offset: 2px; }
        .pk-app__action i { font-size: .95rem; }
        .pk-app__action--danger { background: var(--bad); color: #fff !important; box-shadow: 0 8px 18px -8px color-mix(in srgb, var(--bad) 70%, transparent); }
        .pk-app__action--danger:hover { color: #fff !important; box-shadow: 0 12px 22px -10px color-mix(in srgb, var(--bad) 70%, transparent); }
        @media (max-width: 640px) { .pk-app__action { padding: 0 12px; height: 36px; font-size: .78rem; } }
        .pk-app__nav .pk-app__grouphead {
            font-size: .68rem; font-weight: 800; letter-spacing: .12em; text-transform: uppercase;
            color: var(--muted); padding: 18px 18px 6px;
        }
        .pk-app__sub a { font-size: .86rem; }
        .pk-app__badge { margin-left: auto; }
        .pk-app__gicon { display: inline-flex; }
        .pk-app__top .pk-app__title { max-width: 62vw; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .pk-adm__me { display: flex; align-items: center; gap: 10px; width: 100%; padding: 10px 14px;
            border-radius: 12px; border: 1px solid var(--line); background: var(--surface); color: var(--ink);
            font: inherit; cursor: pointer; text-align: left; }
        .pk-adm__me:hover { border-color: var(--brand); }
        .pk-adm__memeta { display: flex; flex-direction: column; min-width: 0; }
        .pk-adm__mename { font-weight: 700; font-size: .9rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .pk-adm__merole { font-size: .72rem; color: var(--muted); }
        .pk-app__menu { position: absolute; right: 0; top: calc(100% + 8px); min-width: 230px;
            background: var(--surface); border: 1px solid var(--line); border-radius: 14px;
            box-shadow: var(--shadow); padding: 8px; display: none; z-index: 60; }
        .pk-app__menu.is-open { display: block; }
        .pk-app__wrap .dropdown, .pk-app__wrap .btn-group { position: relative; }
        .pk-app__wrap .dropdown-menu { position: absolute; right: 0; top: calc(100% + 6px); z-index: 65; display: none; min-width: 220px;
            background: var(--surface); border: 1px solid var(--line); border-radius: 14px; box-shadow: var(--shadow); padding: 6px; list-style: none; margin: 0; }
        .pk-app__wrap .dropdown-menu.show { display: block; }
        .pk-app__wrap .dropdown-item { display: flex; align-items: center; gap: 8px; width: 100%; padding: 9px 12px; border: 0; background: none;
            border-radius: 10px; color: var(--ink); text-decoration: none; font-size: .88rem; text-align: left; cursor: pointer; }
        .pk-app__wrap .dropdown-item:hover { background: var(--wash); color: var(--brand-ink, var(--brand)); }
        .pk-app__wrap .dropdown-divider { border: 0; border-top: 1px solid var(--line); margin: 6px 4px; }
        .pk-app__menu a { display: flex; align-items: center; gap: 10px; padding: 10px 12px;
            border-radius: 10px; color: var(--ink); text-decoration: none; font-size: .88rem; }
        .pk-app__menu a:hover { background: var(--wash); color: var(--brand); }
        .pk-app__menu hr { border: 0; border-top: 1px solid var(--line); margin: 6px 4px; }
        /* DataTables and Quill render inside the compat layer; keep them legible. */
        .pk-app__wrap .dataTables_wrapper .dataTables_paginate .paginate_button {
            border: 1px solid var(--line) !important; border-radius: 8px !important;
            padding: 4px 10px !important; margin: 0 2px; background: var(--surface) !important; color: var(--ink) !important; }
        .pk-app__wrap .dataTables_wrapper .dataTables_paginate .paginate_button.current {
            background: var(--brand) !important; border-color: var(--brand) !important; color: var(--brand-fg, #fff) !important; }
        .pk-app__wrap .dataTables_wrapper .dataTables_filter input,
        .pk-app__wrap .dataTables_wrapper .dataTables_length select {
            border: 1px solid var(--line); border-radius: 8px; padding: 6px 10px; background: var(--surface); color: var(--ink); }
        .pk-app__wrap .dataTables_wrapper table.dataTable thead th { border-bottom: 1px solid var(--line); }
        .pk-app__wrap .ql-toolbar.ql-snow, .pk-app__wrap .ql-container.ql-snow { border-color: var(--line); }
        .pk-app__wrap .ql-container.ql-snow { background: var(--surface); color: var(--ink); }
        .pk-app__wrap .tox-tinymce { border: 1px solid var(--line) !important; border-radius: 10px !important; }
        @media print {
            .pk-app__side, .pk-app__top, .pk-app__scrim, .pk-app__foot, .no-print { display: none !important; }
            .pk-app__main { margin-left: 0 !important; }
            .pk-app__wrap { padding: 0 !important; }
        }
        </style>
        <?php
    }
}

if (!function_exists('bc_adm_sidebar')) {
    /**
     * Opens the document body and prints the brand rail + back-office navigation.
     * $opts keys: 'area', 'active' (page filename), 'user'.
     */
    function bc_adm_sidebar($opts = array()) {
        $area   = bc_adm_area($opts);
        $site   = bc_adm_site($area);
        $active = isset($opts['active']) ? (string)$opts['active'] : bc_adm_current_page();
        $user   = isset($opts['user']) && is_array($opts['user']) ? $opts['user'] : $site['user'];
        $menu   = bc_adm_menu($area);
        $name   = $user['name'] !== '' ? $user['name'] : 'Account';
        $role   = $area === 'spadmin' ? 'Super admin' : 'Administrator';
        ?><body>
<div class="pk-app__bg" aria-hidden="true"></div>
<aside class="pk-app__side" id="pkAppSide" aria-label="Main navigation">
    <a class="pk-app__brand" href="<?php echo htmlspecialchars(bc_adm_url('Dashboard.php', $area)); ?>">
        <span class="pk-app__mark"><?php echo htmlspecialchars(strtoupper(substr($site['title'], 0, 1))); ?></span>
        <span class="pk-app__brandtxt"><?php echo htmlspecialchars($site['title']); ?><span class="pk-app__brandsub"><?php echo $area === 'spadmin' ? 'Super admin' : 'Admin'; ?></span></span>
    </a>
    <nav class="pk-app__nav">
        <?php
        $index = 0;
        foreach ($menu as $group):
            $items = array();
            foreach ((array)$group['items'] as $item) {
                if (bc_adm_item_visible($item, $area)) $items[] = $item;
            }
            if (!$items) continue;
            $group_label = isset($group['label']) ? (string)$group['label'] : '';
            if ($group_label !== '') {
                echo '<div class="pk-app__grouphead">' . htmlspecialchars($group_label) . '</div>';
            }
            foreach ($items as $item):
                $index++;
                $item_active = !empty($item['match']) && in_array($active, (array)$item['match'], true);
                $href = isset($item['href']) ? (string)$item['href'] : '';
                $icon = isset($item['icon']) ? (string)$item['icon'] : 'circle';
                if (empty($item['children'])):
                    ?><a class="pk-app__glink<?php echo $item_active ? ' is-active' : ''; ?>" href="<?php echo htmlspecialchars($href !== '' ? bc_adm_url($href, $area) : '#'); ?>">
                        <span class="pk-app__gicon"><?php echo bc_adm_icon($icon); ?></span>
                        <span class="pk-app__glabel"><?php echo htmlspecialchars($item['label']); ?></span>
                    </a><?php
                else:
                    ?><a class="pk-app__glink<?php echo $item_active ? ' is-active' : ''; ?>"
                       href="<?php echo htmlspecialchars(bc_adm_url('Hub.php?g=' . urlencode(bc_adm_hub_slug($item['label'])), $area)); ?>">
                        <span class="pk-app__gicon"><?php echo bc_adm_icon($icon); ?></span>
                        <span class="pk-app__glabel"><?php echo htmlspecialchars($item['label']); ?></span>
                        <i class="bi bi-arrow-right-circle pk-app__chev" aria-hidden="true"></i>
                    </a><?php
                endif;
            endforeach;
        endforeach;
        ?>
    </nav>
    <a class="pk-app__user" href="<?php echo htmlspecialchars(bc_adm_url('AccountSettings.php', $area)); ?>">
        <span class="pk-app__avatar"><?php echo htmlspecialchars(strtoupper(substr($name, 0, 1))); ?></span>
        <span class="pk-app__usertxt">
            <span class="pk-app__username"><?php echo htmlspecialchars($name); ?></span>
            <span class="pk-app__userplan"><?php echo htmlspecialchars($role); ?></span>
        </span>
    </a>
</aside>
<div class="pk-app__scrim" id="pkAppScrim"></div>
        <?php
    }
}

if (!function_exists('bc_adm_topbar')) {
    /**
     * The sticky header and the opening of the content column.
     * $opts keys: 'title', 'crumb', 'actions', 'notification', 'area'.
     */
    function bc_adm_topbar($opts = array()) {
        $area  = bc_adm_area($opts);
        $site  = bc_adm_site($area);
        $title = isset($opts['title']) ? (string)$opts['title'] : '';
        $crumb = isset($opts['crumb']) ? (string)$opts['crumb'] : '';
        if ($title === '') $title = ucwords(str_replace(array('.php', '-'), array('', ' '), bc_adm_current_page()));
        $user  = $site['user'];
        $name  = $user['name'] !== '' ? $user['name'] : 'Account';
        $dash = isset($opts['dashboard']) ? (string)$opts['dashboard'] : bc_adm_url('Dashboard.php', $area);
        // Section 38: the Portal button is a SAAS-only concept - the standalone
        // (NON-SAAS) install is a single business and has no portal to open.
        if (!function_exists("bc_is_saas_edition") && is_file(__DIR__ . "/bc-edition.php")) include_once(__DIR__ . "/bc-edition.php");
        $bc_top_saas = function_exists("bc_is_saas_edition") ? bc_is_saas_edition() : true;
        ?><div class="pk-app__main">
<header class="pk-app__top">
    <button class="pk-app__burger" type="button" id="pkAppBurger" aria-label="Open navigation" aria-expanded="false">
        <i class="bi bi-list" aria-hidden="true"></i>
    </button>
    <div class="pk-app__titletxt">
        <h1 class="pk-app__title"><?php echo $title; ?></h1>
        <?php if ($crumb !== ''): ?><p class="pk-app__crumb"><?php echo htmlspecialchars($crumb); ?></p><?php endif; ?>
    </div>
    <div class="pk-app__actions">
        <?php if ($area === 'admin' && $bc_top_saas): ?>
            <a class="pk-app__action" href="<?php echo htmlspecialchars(bc_adm_url('VendorPortal.php', $area)); ?>"><?php echo bc_adm_icon('globe'); ?> Portal</a>
        <?php endif; ?>
        <?php if (!empty($opts['actions'])): ?>
            <?php foreach ((array)$opts['actions'] as $action): ?>
                <a class="pk-app__action<?php echo !empty($action['danger']) ? ' pk-app__action--danger' : ''; ?>"
                   href="<?php echo htmlspecialchars(isset($action['href']) ? (string)$action['href'] : '#'); ?>">
                    <?php if (!empty($action['icon'])): ?><?php echo bc_adm_icon($action['icon']); ?><?php endif; ?>
                    <?php echo htmlspecialchars(isset($action['label']) ? (string)$action['label'] : ''); ?>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
        <div class="pk-app__profile">
            <button class="pk-adm__me" type="button" id="pkAppProfile" aria-expanded="false">
                <span class="pk-app__avatar"><?php echo htmlspecialchars(strtoupper(substr($name, 0, 1))); ?></span>
                <span class="pk-adm__memeta">
                    <span class="pk-adm__mename"><?php echo htmlspecialchars($name); ?></span>
                    <span class="pk-adm__merole"><?php echo $area === 'spadmin' ? 'Super admin' : 'Administrator'; ?></span>
                </span>
                <i class="bi bi-chevron-down pk-app__chev" aria-hidden="true"></i>
            </button>
            <div class="pk-app__menu" id="pkAppProfileMenu">
                <a href="<?php echo htmlspecialchars($dash); ?>"><?php echo bc_adm_icon('grid'); ?> Dashboard</a>
                <a href="<?php echo htmlspecialchars(bc_adm_url('AccountSettings.php', $area)); ?>"><?php echo bc_adm_icon('gear'); ?> Account settings</a>
                <a href="<?php echo htmlspecialchars(bc_adm_url('SiteMap.php', $area)); ?>"><?php echo bc_adm_icon('grid-3x3-gap'); ?> Platform guide</a>
                <hr>
                <a href="#" data-pk-logout><?php echo bc_adm_icon('box-arrow-right'); ?> Log out</a>
            </div>
        </div>
    </div>
</header>
<main class="pk-app__wrap">
<?php if ($area === 'admin' && function_exists('bc_is_temporary_vendor_host') && bc_is_temporary_vendor_host() && !empty($_SESSION['tmp_vendor_override'])): ?>
    <div class="alert alert-warning" role="status" style="margin-bottom:18px;">
        <strong>Temporary vendor website.</strong>
        Changes saved here are stored for your website and will appear there as soon as your domain is accessible.
    </div>
<?php endif; ?>
        <?php
    }
}

if (!function_exists('bc_adm_flash')) {
    /**
     * The session flash dialog, ported from the retired back-office footers. Reads
     * $_SESSION['product_purchase_response'] (+ the status/ref keys some handlers set),
     * classifies it, renders a SweetAlert dialog, then clears the keys.
     */
    function bc_adm_flash() {
        if (empty($_SESSION['product_purchase_response'])) return;
        $message = (string)$_SESSION['product_purchase_response'];
        $status  = isset($_SESSION['product_purchase_status']) ? (string)$_SESSION['product_purchase_status'] : '';
        $ref     = isset($_SESSION['last_transaction_ref']) ? (string)$_SESSION['last_transaction_ref'] : '';
        $lower   = strtolower($message);
        $fail    = array('fail', 'error', 'invalid', 'insufficient', 'not found', 'unable', 'could not', 'unavailable', 'rejected', 'declined');
        $ok      = array('success', 'successful', 'completed', 'done', 'approved', 'confirmed', 'received', 'updated', 'saved');
        $is_fail = false;
        foreach ($fail as $word) { if (strpos($lower, $word) !== false) { $is_fail = true; break; } }
        $is_ok = false;
        if (!$is_fail) {
            foreach ($ok as $word) { if (strpos($lower, $word) !== false) { $is_ok = true; break; } }
        }
        if ($status === 'success' || $status === 'ok') { $is_ok = true; $is_fail = false; }
        if ($status === 'error' || $status === 'fail' || $status === 'failed') { $is_fail = true; $is_ok = false; }
        $icon  = $is_fail ? 'error' : ($is_ok ? 'success' : 'info');
        $title = $is_fail ? 'Processing Error!' : ($is_ok ? 'Successful!' : 'Notice');
        $body  = $message;
        if ($ref !== '') $body .= '<br><small style="opacity:.7">Ref: ' . htmlspecialchars($ref, ENT_QUOTES, 'UTF-8') . '</small>';
        ?>
<script>
(function () {
    if (typeof Swal === 'undefined') return;
    Swal.fire({
        title: <?php echo json_encode($title, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        html: <?php echo json_encode($body, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        icon: <?php echo json_encode($icon, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        confirmButtonText: 'OK'
    });
})();
</script>
        <?php
        unset($_SESSION['product_purchase_response'], $_SESSION['product_purchase_status'], $_SESSION['last_transaction_ref']);
    }
}

if (!function_exists('bc_adm_widgets')) {
    /**
     * Page-level features the retired bc-admin-header.php injected on every admin
     * page, ported with their original gates:
     *
     *  - the AI assistant (vendor `ai_status` = 1, admin context),
     *  - the forced Security PIN setup modal (shown while the vendor has no PIN;
     *    it saves through AccountSettings.php's `quick_set_pin` AJAX action).
     *
     * The modal is shown/hidden with the shell's `.show` class instead of the
     * retired Bootstrap bundle; the fetch contract is unchanged.
     */
    function bc_adm_widgets($area) {
        if ($area !== 'admin') return;
        $details = isset($GLOBALS['get_logged_admin_details']) && is_array($GLOBALS['get_logged_admin_details'])
            ? $GLOBALS['get_logged_admin_details'] : null;

        if ($details && isset($details['ai_status']) && (int)$details['ai_status'] === 1):
            $ai_token_bal = (int)($details['ai_token_balance'] ?? 0);
            ?>
<script>
  window.__ai_enabled     = true;
  window.__ai_page_slug   = '<?php echo preg_replace('/[^a-z0-9_]/', '_', strtolower(basename(isset($_SERVER['PHP_SELF']) ? (string)$_SERVER['PHP_SELF'] : '', '.php'))); ?>';
  window.__ai_handler_url = '/web/ai-handler.php?context=admin';
  window.__ai_guide_url   = '/web/ai-guide-cache.php?context=admin';
  window.__ai_tokens      = <?php echo $ai_token_bal; ?>;
</script>
<script src="<?php echo htmlspecialchars(bc_adm_base(), ENT_QUOTES, 'UTF-8'); ?>/jsfile/ai-assistant.js" defer></script>
            <?php
        endif;

        if (!empty($GLOBALS['bc_admin_needs_pin'])):
            ?>
<!-- Forced Security PIN setup — required to self-unblock via the anti-brute-force
     lockout flow (web/LockoutResolution.php). Modal, not a redirect, so it works
     on whatever page the admin is already on. -->
<div class="modal" id="forcePinModal" tabindex="-1" aria-hidden="true" style="display:none;">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-body" style="padding:8px;">
        <div style="text-align:center; margin-bottom:14px;">
          <h5 style="font-weight:800; margin:0 0 6px;">Set Your Security PIN</h5>
          <p class="pk-app__crumb" style="margin:0;">You'll need this 4-digit PIN to unblock your own account if our anti-brute-force system ever locks you out. It only takes a second.</p>
        </div>
        <div id="forcePinError" role="alert" aria-live="polite" class="alert alert-danger" style="display:none; margin-bottom:14px;"></div>
        <div style="margin-bottom:14px;">
          <label class="pk-app__crumb" for="forcePinInput" style="display:block; font-weight:700; margin-bottom:6px;">NEW 4-DIGIT PIN</label>
          <input type="password" id="forcePinInput" maxlength="4" pattern="[0-9]{4}" inputmode="numeric" placeholder="****" style="width:100%; text-align:center; font-weight:700; letter-spacing:10px; padding:12px; border:1px solid var(--line); border-radius:12px;">
        </div>
        <div style="margin-bottom:14px;">
          <label class="pk-app__crumb" for="forcePinConfirm" style="display:block; font-weight:700; margin-bottom:6px;">CONFIRM PIN</label>
          <input type="password" id="forcePinConfirm" maxlength="4" pattern="[0-9]{4}" inputmode="numeric" placeholder="****" style="width:100%; text-align:center; font-weight:700; letter-spacing:10px; padding:12px; border:1px solid var(--line); border-radius:12px;">
        </div>
        <button type="button" id="forcePinSubmit" class="pk-app__action" style="width:100%; justify-content:center;">Save PIN &amp; Continue</button>
      </div>
    </div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var modalEl = document.getElementById('forcePinModal');
    if (!modalEl) return;
    modalEl.classList.add('show');
    modalEl.style.display = 'flex';

    var errBox = document.getElementById('forcePinError');
    var submitBtn = document.getElementById('forcePinSubmit');

    function showErr(msg) {
        errBox.textContent = msg;
        errBox.style.display = 'block';
    }

    submitBtn.addEventListener('click', function () {
        var pin = document.getElementById('forcePinInput').value.trim();
        var con = document.getElementById('forcePinConfirm').value.trim();
        errBox.style.display = 'none';

        if (!/^\d{4}$/.test(pin)) { showErr('PIN must be exactly 4 digits.'); return; }
        if (pin !== con) { showErr('PIN and Confirm PIN do not match.'); return; }

        if (submitBtn.disabled) return;
        submitBtn.disabled = true;
        submitBtn.textContent = 'Saving…';

        fetch('/bc-admin/AccountSettings.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
            body: new URLSearchParams({ action: 'quick_set_pin', admin_pin: pin, admin_pin_con: con, _csrf_token: <?php echo json_encode(bc_generate_csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?> })
        })
        .then(function (r) {
            if (r.redirected) throw new Error('Your session or security requirements changed. Refresh this page and sign in again if requested.');
            var type = r.headers.get('Content-Type') || '';
            if (type.indexOf('application/json') === -1) throw new Error('The server did not return a PIN-save response. Refresh this page or contact support.');
            return r.json().catch(function () { throw new Error('The server returned an unreadable response. Refresh this page or contact support.'); });
        })
        .then(function (data) {
            if (data && data.ok === true && data.code === 'saved') {
                modalEl.classList.remove('show');
                modalEl.style.display = 'none';
            } else {
                showErr((data && (data.msg || data.message)) || 'Could not save PIN. Please try again.');
                submitBtn.disabled = false;
                submitBtn.textContent = 'Save PIN & Continue';
            }
        })
        .catch(function (error) {
            showErr(error instanceof TypeError ? 'Unable to reach the server. Check your connection and try again.' : (error.message || 'Could not save PIN. Please refresh and try again.'));
            submitBtn.disabled = false;
            submitBtn.textContent = 'Save PIN & Continue';
        });
    });
});
</script>
            <?php
        endif;
    }
}

if (!function_exists('bc_adm_foot')) {
    /**
     * Closes the content column and the document, and prints the back-office script.
     *
     * $opts keys: 'note', 'nonav', 'bare', 'assets' (extra script srcs), 'area'.
     */
    function bc_adm_foot($opts = array()) {
        $area = bc_adm_area($opts);
        $site = bc_adm_site($area);
        $base = bc_adm_base();
        // Page-level features the retired bc-admin-header.php injected (admin AI
        // assistant, forced PIN setup) — inside the wrap so the modal CSS applies.
        bc_adm_widgets($area);
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
            + (d.recipient ? row('Recipient', d.recipient) : '')
            + row('Amount Paid', '\u20A6' + d.amount)
            + (d.face_amount && d.face_amount !== d.amount ? row('Face Value', '\u20A6' + d.face_amount) : '')
            + row('Reference', d.reference)
            + row('Date', d.date)
            + (d.balance_after != null ? row('Wallet Balance', '\u20A6' + d.balance_after) : '');
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
    <a href="<?php echo htmlspecialchars(bc_adm_url('SiteMap.php', $area)); ?>">Platform Guide</a>
</footer>
<?php endif; ?>
<?php bc_adm_assets($area, $opts); ?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.0.16/dist/sweetalert2.all.min.js" defer></script>
<?php bc_adm_flash(); ?>
<script>
(function () {
    'use strict';
    var side = document.getElementById('pkAppSide');
    var scrim = document.getElementById('pkAppScrim');
    var burger = document.getElementById('pkAppBurger');
    var profile = document.getElementById('pkAppProfile');
    var profileMenu = document.getElementById('pkAppProfileMenu');

    function closeNav() {
        if (!side) return;
        side.classList.remove('is-open');
        if (scrim) scrim.classList.remove('is-open');
        if (burger) burger.setAttribute('aria-expanded', 'false');
    }
    if (burger) {
        burger.addEventListener('click', function () {
            if (!side) return;
            var open = side.classList.toggle('is-open');
            if (scrim) scrim.classList.toggle('is-open', open);
            burger.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }
    if (scrim) scrim.addEventListener('click', closeNav);

    // Nav groups: one open at a time, matching the old collapse behaviour.
    var groups = document.querySelectorAll('[data-pk-group]');
    Array.prototype.forEach.call(groups, function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-pk-group');
            var panel = document.getElementById('pk-app-sub-' + id);
            var open = panel && panel.classList.contains('is-open');
            Array.prototype.forEach.call(groups, function (other) {
                var p = document.getElementById('pk-app-sub-' + other.getAttribute('data-pk-group'));
                if (p) p.classList.remove('is-open');
                other.setAttribute('aria-expanded', 'false');
                other.classList.remove('is-active');
            });
            if (panel && !open) {
                panel.classList.add('is-open');
                btn.classList.add('is-active');
                btn.setAttribute('aria-expanded', 'true');
            }
        });
    });

    // Profile menu.
    if (profile && profileMenu) {
        profile.addEventListener('click', function (e) {
            e.stopPropagation();
            var open = profileMenu.classList.toggle('is-open');
            profile.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        profileMenu.addEventListener('click', function (e) { e.stopPropagation(); });
    }
    document.addEventListener('click', function () {
        if (profileMenu) { profileMenu.classList.remove('is-open'); if (profile) profile.setAttribute('aria-expanded', 'false'); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' || e.key === 'Esc') {
            closeNav();
            if (profileMenu) profileMenu.classList.remove('is-open');
        }
    });

    // Logout confirm, matching the old header.
    var logout = document.querySelector('[data-pk-logout]');
    if (logout) {
        logout.addEventListener('click', function (e) {
            e.preventDefault();
            if (window.confirm('Do you want to logout?')) {
                window.location.href = '<?php echo $area === 'spadmin' ? '/spadmin-logout.php' : '/admin-logout.php'; ?>';
            }
        });
    }

    // Bootstrap-compat: the retired bundle is gone, so the handful of data attributes the
    // compat layer renders are wired here instead of shipping all of Bootstrap's JS.
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            Array.prototype.forEach.call(document.querySelectorAll('.dropdown-menu.show'), function (m) { m.classList.remove('show'); });
            Array.prototype.forEach.call(document.querySelectorAll('[data-bs-toggle="dropdown"][aria-expanded="true"]'), function (t) { t.setAttribute('aria-expanded', 'false'); });
        }
    });
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
        // Dropdowns (§28): open only on click, close on any outside click or Esc.
        var dropToggle = e.target.closest('[data-bs-toggle="dropdown"]');
        var openMenus = document.querySelectorAll('.dropdown-menu.show');
        if (!dropToggle) {
            Array.prototype.forEach.call(openMenus, function (m) { m.classList.remove('show'); });
            Array.prototype.forEach.call(document.querySelectorAll('[data-bs-toggle="dropdown"][aria-expanded="true"]'), function (t) { t.setAttribute('aria-expanded', 'false'); });
        } else {
            e.preventDefault();
            var dropWrap = dropToggle.closest('.dropdown, .btn-group, .dropdown-center');
            var dropMenu = dropWrap ? dropWrap.querySelector('.dropdown-menu') : null;
            var willOpen = !(dropMenu && dropMenu.classList.contains('show'));
            Array.prototype.forEach.call(openMenus, function (m) { m.classList.remove('show'); });
            Array.prototype.forEach.call(document.querySelectorAll('[data-bs-toggle="dropdown"][aria-expanded="true"]'), function (t) { t.setAttribute('aria-expanded', 'false'); });
            if (dropMenu && willOpen) {
                dropMenu.classList.add('show');
                dropToggle.setAttribute('aria-expanded', 'true');
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
})();
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
        if (((form.getAttribute('method') || 'get').toLowerCase()) === 'get') return;
        if (form.hasAttribute('data-bc-nolock')) return;
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
    }, true);
    window.addEventListener('pageshow', function () {
        document.querySelectorAll('form[data-bc-locked]').forEach(unlockForm);
    });
})();
</script>
</body>
</html>
        <?php
    }
}

if (!function_exists('bc_adm_assets')) {
    /**
     * The back-office script bundles, loaded with the same per-page conditions the old
     * footers used, so DataTables / Quill / charts only load where they are needed.
     */
    function bc_adm_assets($area, $opts = array()) {
        $page = bc_adm_current_page();
        $root = bc_adm_base();
        // Path relative to the area directory (the old partials used ../assets-2/...).
        $vendor = '../assets-2/vendor/';
        $area_prefix = $area === 'spadmin' ? 'super' : 'vendor';

        $charts = array('Dashboard.php', 'SalesAnalysis.php', 'SalesCalculator.php', 'SubscriptionReports.php');
        $editor = array('AccountSettings.php', 'SiteSettings.php', 'Announcement.php', 'StatusMessage.php', 'EmailTemplates.php');
        $tables = array('Transactions.php', 'UserManagement.php', 'PaymentOrders.php', 'GiftCard.php', 'Users.php', 'BatchTransactions.php', 'Withdrawals.php', 'FundTransferRequests.php', 'MarketPlace.php', 'APIRequests.php', 'KnowledgeBase.php');

        echo '<script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>' . "\n";
        if (in_array($page, $charts, true)) {
            echo '<script src="' . $vendor . 'apexcharts/apexcharts.min.js" defer></script>' . "\n";
            echo '<script src="' . $vendor . 'chart.js/chart.umd.js" defer></script>' . "\n";
            echo '<script src="' . $vendor . 'echarts/echarts.min.js" defer></script>' . "\n";
        }
        if (in_array($page, $editor, true)) {
            echo '<script src="' . $vendor . 'quill/quill.js" defer></script>' . "\n";
            echo '<script src="' . $vendor . 'tinymce/tinymce.min.js" defer></script>' . "\n";
        }
        if (in_array($page, $tables, true)) {
            echo '<script src="' . $vendor . 'simple-datatables/simple-datatables.js" defer></script>' . "\n";
        }
        echo '<script src="' . htmlspecialchars($root, ENT_QUOTES, 'UTF-8') . '/jsfile/bc-custom-all.js"></script>' . "\n";
        // Content version prevents a stale footer helper from replacing repaired page handlers.
        $helper_file = __DIR__ . '/../jsfile/bc-custom-' . $area_prefix . '.js';
        $helper_version = is_file($helper_file) ? substr(hash_file('sha256', $helper_file), 0, 12) : '1';
        echo '<script src="' . htmlspecialchars($root, ENT_QUOTES, 'UTF-8') . '/jsfile/bc-custom-' . $area_prefix . '.js?v=' . $helper_version . '"></script>' . "\n";
        if (!empty($opts['assets']) && is_array($opts['assets'])) {
            foreach ($opts['assets'] as $src) {
                echo '<script src="' . htmlspecialchars((string)$src, ENT_QUOTES, 'UTF-8') . '"></script>' . "\n";
            }
        }
    }
}

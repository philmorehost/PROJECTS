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
        $key  = $area === 'spadmin' ? 'get_all_spadmin_site_details' : 'get_all_super_admin_site_details';
        $details = array();
        if (isset($GLOBALS[$key]) && is_array($GLOBALS[$key])) {
            $details = $GLOBALS[$key];
        } elseif (isset($GLOBALS['get_all_site_details']) && is_array($GLOBALS['get_all_site_details'])) {
            $details = $GLOBALS['get_all_site_details'];
        }
        $title = 'FintechFlow';
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
    function bc_adm_item_visible($item) {
        if (empty($item['service'])) return true;
        if (!function_exists('isServiceEnabled')) return true;
        return (bool)isServiceEnabled($item['service']);
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

        if ($area === 'spadmin') {
            return array(
                array('items' => array(
                    array('label' => 'Dashboard',             'href' => 'Dashboard.php',        'icon' => 'grid',            'match' => array('Dashboard.php')),
                    array('label' => 'Platform Guide',        'href' => 'SiteMap.php',          'icon' => 'grid-3x3-gap',    'match' => array('SiteMap.php')),
                    array('label' => 'Global Service Control','href' => 'ServiceControl.php',   'icon' => 'shield-lock',     'match' => array('ServiceControl.php')),
                    array('label' => 'AI Manager',            'href' => 'AIManagement.php',     'icon' => 'cpu-fill',        'match' => array('AIManagement.php')),
                    array('label' => 'Automation & AI Guide', 'href' => 'AutomationGuide.php',  'icon' => 'journal-text',    'match' => array('AutomationGuide.php')),
                    array('label' => 'USSD Channel Access',   'href' => 'USSDAccessFee.php',    'icon' => 'phone',           'match' => array('USSDAccessFee.php')),
                )),
                array('label' => 'Management', 'items' => array(
                    array('label' => 'Manage Vendors', 'icon' => 'menu-button-wide', 'match' => array('VendorReg.php','Vendors.php','VendorRegistrations.php','UnblockRequests.php','DomainSettings.php','DomainChangeRequests.php'), 'children' => array(
                        array('label' => 'Add Vendor',            'href' => 'VendorReg.php',            'match' => array('VendorReg.php')),
                        array('label' => 'View Vendors',          'href' => 'Vendors.php',              'match' => array('Vendors.php')),
                        array('label' => 'Pending Registrations', 'href' => 'VendorRegistrations.php',  'match' => array('VendorRegistrations.php')),
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
                    array('label' => 'Manage Users', 'icon' => 'people', 'match' => array('Users.php'), 'children' => array(
                        array('label' => 'All Users', 'href' => 'Users.php', 'match' => array('Users.php')),
                    )),
                    array('label' => 'Manage Notification', 'icon' => 'menu-button-wide', 'match' => array('StatusMessage.php','SendMail.php'), 'children' => array(
                        array('label' => 'Update Status Message', 'href' => 'StatusMessage.php', 'match' => array('StatusMessage.php')),
                        array('label' => 'Send Mail',             'href' => 'SendMail.php',      'match' => array('SendMail.php')),
                    )),
                )),
                array('items' => array(
                    array('label' => 'Payment Gateway', 'href' => 'PaymentGateway.php',     'icon' => 'credit-card',  'match' => array('PaymentGateway.php')),
                    array('label' => 'Fund Vendor',     'href' => 'ShareFund.php',          'icon' => 'cash-stack',   'match' => array('ShareFund.php')),
                    array('label' => 'Transactions',    'href' => 'Transactions.php',       'icon' => 'list-check',   'match' => array('Transactions.php')),
                    array('label' => 'Payment Orders',  'href' => 'PaymentOrders.php',      'icon' => 'receipt',      'match' => array('PaymentOrders.php')),
                    array('label' => 'Email Templates', 'href' => 'EmailTemplates.php',     'icon' => 'envelope',     'match' => array('EmailTemplates.php')),
                    array('label' => 'Reports',         'href' => 'SubscriptionReports.php','icon' => 'bar-chart',   'match' => array('SubscriptionReports.php')),
                    array('label' => 'Crypto',          'href' => 'CryptoHub.php',          'icon' => 'currency-bitcoin','match' => array('CryptoHub.php')),
                    array('label' => 'Account Settings', 'href' => 'AccountSettings.php',   'icon' => 'gear',         'match' => array('AccountSettings.php')),
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
                      'match' => array('CreateUser.php','KnowledgeBase.php','Users.php','Transactions.php','BatchTransactions.php','PaymentOrders.php','FundTransferRequests.php','ShareFund.php','APIRequests.php','NumberChecker.php','CryptoInvoices.php','Withdrawals.php'),
                      'children' => array(
                    array('label' => 'Create User',           'href' => 'CreateUser.php',           'match' => array('CreateUser.php')),
                    array('label' => 'API Access Requests',   'href' => 'APIRequests.php',          'match' => array('APIRequests.php')),
                    array('label' => 'Knowledge Base',        'href' => 'KnowledgeBase.php',        'match' => array('KnowledgeBase.php')),
                    array('label' => 'Users',                 'href' => 'Users.php',                'match' => array('Users.php')),
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
                    array('label' => 'Crypto',               'href' => 'CryptoHub.php',           'match' => array('CryptoHub.php')),
                    array('label' => 'Float Service Icons',  'href' => 'FloatServiceIcons.php',   'match' => array('FloatServiceIcons.php')),
                    array('label' => 'KYC Management',       'href' => 'KYCManagement.php',       'match' => array('KYCManagement.php')),
                    array('label' => 'Business KYB',         'href' => 'BusinessKYB.php',         'match' => array('BusinessKYB.php')),
                    array('label' => 'Support Chat',         'href' => 'SupportChat.php',         'match' => array('SupportChat.php')),
                    array('label' => 'Chat Settings',        'href' => 'ChatSettings.php',        'match' => array('ChatSettings.php')),
                    array('label' => 'Brute Force Security',  'href' => 'BruteForceSecurity.php',  'match' => array('BruteForceSecurity.php')),
                    array('label' => 'Service Control Center','href' => 'ServiceControl.php',      'match' => array('ServiceControl.php')),
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
                      'match' => array('RenewSubscription.php','SubscriptionHistory.php'), 'children' => array(
                    array('label' => 'Renew Subscription',  'href' => 'RenewSubscription.php',  'match' => array('RenewSubscription.php')),
                    array('label' => 'Subscription History','href' => 'SubscriptionHistory.php','match' => array('SubscriptionHistory.php')),
                )),
                array('label' => 'Blog Manager', 'icon' => 'journal-text',
                      'match' => array('BlogPosts.php','BlogCategories.php'), 'children' => array(
                    array('label' => 'Posts',      'href' => 'BlogPosts.php',      'match' => array('BlogPosts.php')),
                    array('label' => 'Categories', 'href' => 'BlogCategories.php', 'match' => array('BlogCategories.php')),
                )),
            )),
            array('items' => array(
                array('label' => 'Help / Guide', 'href' => 'SiteMap.php', 'icon' => 'question-circle', 'match' => array('SiteMap.php')),
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.10.5/font/bootstrap-icons.min.css">
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
        .pk-app__glabel { letter-spacing: .01em; }
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
        .pk-app__menu a { display: flex; align-items: center; gap: 10px; padding: 10px 12px;
            border-radius: 10px; color: var(--ink); text-decoration: none; font-size: .88rem; }
        .pk-app__menu a:hover { background: var(--wash); color: var(--brand); }
        .pk-app__menu hr { border: 0; border-top: 1px solid var(--line); margin: 6px 4px; }
        /* DataTables and Quill render inside the compat layer; keep them legible. */
        .pk-app__wrap .dataTables_wrapper .dataTables_paginate .paginate_button {
            border: 1px solid var(--line) !important; border-radius: 8px !important;
            padding: 4px 10px !important; margin: 0 2px; background: var(--surface) !important; color: var(--ink) !important; }
        .pk-app__wrap .dataTables_wrapper .dataTables_paginate .paginate_button.current {
            background: var(--brand) !important; border-color: var(--brand) !important; color: #fff !important; }
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
                if (bc_adm_item_visible($item)) $items[] = $item;
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
                    $gid = 'adm' . $index;
                    ?><button class="pk-app__glink<?php echo $item_active ? ' is-active' : ''; ?>" type="button"
                            aria-expanded="<?php echo $item_active ? 'true' : 'false'; ?>"
                            data-pk-group="<?php echo htmlspecialchars($gid); ?>">
                        <span class="pk-app__gicon"><?php echo bc_adm_icon($icon); ?></span>
                        <span class="pk-app__glabel"><?php echo htmlspecialchars($item['label']); ?></span>
                        <i class="bi bi-chevron-right pk-app__chev" aria-hidden="true"></i>
                    </button>
                    <ul class="pk-app__sub<?php echo $item_active ? ' is-open' : ''; ?>" id="pk-app-sub-<?php echo htmlspecialchars($gid); ?>">
                        <?php foreach ($item['children'] as $child): ?>
                            <?php
                            if (!bc_adm_item_visible($child)) continue;
                            $c_active = !empty($child['match']) && in_array($active, (array)$child['match'], true);
                            ?>
                            <li><a class="pk-app__slink<?php echo $c_active ? ' is-active' : ''; ?>" href="<?php echo htmlspecialchars(bc_adm_url((string)$child['href'], $area)); ?>"><?php echo htmlspecialchars($child['label']); ?></a></li>
                        <?php endforeach; ?>
                    </ul><?php
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
        if (empty($opts['bare'])): ?></div>
</main>
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
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.0.16/dist/sweetalert2.all.min.js"></script>
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

        $charts = array('Dashboard.php', 'Transactions.php', 'SalesAnalysis.php', 'SalesCalculator.php', 'SubscriptionReports.php');
        $editor = array('AccountSettings.php', 'SiteSettings.php', 'Announcement.php', 'StatusMessage.php', 'EmailTemplates.php', 'SendMail.php');
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
        echo '<script src="' . $vendor . 'php-email-form/validate.js" defer></script>' . "\n";
        echo '<script src="' . htmlspecialchars($root, ENT_QUOTES, 'UTF-8') . '/jsfile/bc-custom-all.js"></script>' . "\n";
        echo '<script src="' . htmlspecialchars($root, ENT_QUOTES, 'UTF-8') . '/jsfile/bc-custom-' . $area_prefix . '.js"></script>' . "\n";
        if (!empty($opts['assets']) && is_array($opts['assets'])) {
            foreach ($opts['assets'] as $src) {
                echo '<script src="' . htmlspecialchars((string)$src, ENT_QUOTES, 'UTF-8') . '"></script>' . "\n";
            }
        }
    }
}

<?php
/**
 * Plate app shell — functional test.
 *
 * Exercises the signed-in shell the same way a migrated page would: stub the tenant
 * globals and the service helpers, print the four helpers in order, and assert on the
 * markup and on the navigation gates.
 *
 * Runs under `php -n` in CI (no tokenizer, no mbstring, no database) and under the
 * php-wasm harness locally.
 */

$checks = 0;
function chk($cond, $label) {
    global $checks;
    $checks++;
    if (!$cond) {
        fwrite(STDERR, "FAIL: $label\n");
    }
}

$root = dirname(__DIR__);
$shell = $root . '/DGV7.0-SAAS/func/bc-plate-app.php';
chk(is_file($shell), 'shell file exists');

// ---------------------------------------------------------------- environment
$_SERVER['HTTP_HOST'] = 'console.example.test';
$_SERVER['PHP_SELF']  = '/web/Fund.php';
$_SERVER['HTTPS']     = 'on';
$_SESSION['user_session'] = 'adaobi';

$GLOBALS['get_all_site_details'] = array(
    'site_title'     => 'Adaobo Pay',
    'primary_color'  => '#123456',
);
$GLOBALS['get_logged_user_details'] = array(
    'username'     => 'adaobi',
    'vendor_id'    => 7,
    'account_type' => 'personal',
    'kyb_status'   => 0,
);
$GLOBALS['web_http_host'] = 'https://console.example.test';

// Service switches: everything on except `betting`, so the gate is observable.
$GLOBALS['__services'] = array(
    'data' => 1, 'airtime' => 1, 'cable' => 1, 'electric' => 1, 'betting' => 0,
    'recharge_card' => 1, 'exam' => 1, 'bank_transfer' => 1, 'vtu_coins' => 1,
    'virtual_card' => 1, 'nin_card' => 1, 'bvn_verify' => 1, 'gift_card' => 1,
    'crypto_hub' => 1, 'ai_suite' => 1, 'bulk_sms' => 1, 'data_card' => 1,
    'print_airtime' => 1, 'print_data' => 1, 'print_cable' => 1, 'print_electric' => 1,
    'print_exam' => 1, 'print_betting' => 1, 'manual_funding' => 1,
);
function isServiceEnabled($name) {
    $s = isset($GLOBALS['__services']) ? $GLOBALS['__services'] : array();
    return !empty($s[$name]);
}

// Tier matrix: personal accounts may not use the developer API.
function bc_fintech_account_type($user = null) {
    $u = is_array($user) ? $user : (isset($GLOBALS['get_logged_user_details']) ? $GLOBALS['get_logged_user_details'] : array());
    return isset($u['account_type']) ? (string)$u['account_type'] : 'personal';
}
function bc_fintech_matrix_read($vendor_id) {
    return array(
        'developer_api' => array('personal' => 0, 'business' => 1),
    );
}
function bc_fintech_tier_effective($row, $tier) {
    return !empty($row[$tier]);
}

require $shell;

// ---------------------------------------------------------------- helpers
chk(function_exists('bc_app_head'), 'bc_app_head defined');
chk(function_exists('bc_app_sidebar'), 'bc_app_sidebar defined');
chk(function_exists('bc_app_topbar'), 'bc_app_topbar defined');
chk(function_exists('bc_app_foot'), 'bc_app_foot defined');
chk(function_exists('bc_app_menu'), 'bc_app_menu defined');

// ---------------------------------------------------------------- brand
chk(bc_app_colour(array('primary_color' => '#123456')) === '#123456', 'brand from details');
chk(bc_app_colour(array('primary_color' => 'red')) === '#0f766e', 'invalid brand rejected');
chk(bc_app_colour(null) === '#0f766e', 'brand falls back to default');

// ---------------------------------------------------------------- site
$site = bc_app_site();
chk($site['title'] === 'Adaobo Pay', 'site title from details');
chk($site['username'] === 'adaobi', 'site username from session');
chk($site['host'] === 'https://console.example.test', 'site host from config');

// ---------------------------------------------------------------- base / url
chk(bc_app_base() === 'https://console.example.test', 'base from web_http_host');
chk(bc_app_url('Fund.php') === 'https://console.example.test/web/Fund.php', 'url resolves a console page under /web');
chk(bc_app_url('DataBundleCard.php?type=airtime') === 'https://console.example.test/web/DataBundleCard.php?type=airtime', 'url keeps the query string');
chk(bc_app_root_url('logout.php') === 'https://console.example.test/logout.php', 'root url resolves an edition-root file');

// ---------------------------------------------------------------- icon
chk(bc_app_icon('grid') === '<i class="bi bi-grid" aria-hidden="true"></i>', 'icon markup');
chk(bc_app_icon('bad glyph') === '<i class="bi bi-badglyph" aria-hidden="true"></i>', 'icon name is sanitised');
chk(bc_app_icon('') === '<i class="bi bi-circle" aria-hidden="true"></i>', 'empty icon falls back');

// ---------------------------------------------------------------- gates
chk(bc_app_service_on('data') === true, 'service on');
chk(bc_app_service_on('betting') === false, 'service off');
chk(bc_app_tier_service('developer_api') === false, 'personal tier denied developer api');
$GLOBALS['get_logged_user_details']['account_type'] = 'business';
chk(bc_app_tier_service('developer_api') === true, 'business tier allowed developer api');
$GLOBALS['get_logged_user_details']['account_type'] = 'personal';

// ---------------------------------------------------------------- menu
$menu = bc_app_menu();
chk(count($menu) === 7, 'menu has seven groups');
$labels = array();
foreach ($menu as $g) {
    if (!empty($g['label'])) $labels[] = $g['label'];
}
chk(in_array('Pay Bills', $labels, true), 'Pay Bills group present');
chk(in_array('Wallet & Transfers', $labels, true), 'Wallet & Transfers group present');
chk(in_array('Financial Services', $labels, true), 'Financial Services group present');
chk(in_array('Business & API', $labels, true), 'Business & API group present');
chk(in_array('History & Records', $labels, true), 'History & Records group present');
chk(in_array('Settings & Help', $labels, true), 'Settings & Help group present');

$all = array();
foreach ($menu as $g) {
    foreach ((array)$g['items'] as $i) $all[$i['href']] = $i;
}
chk(isset($all['Dashboard.php']), 'Dashboard item present');
chk(isset($all['Data.php']), 'Data item present');
chk(isset($all['SendFund.php']), 'SendFund item present');
chk(isset($all['CryptoHub.php']), 'Crypto item present');
chk(isset($all['AccountSettings.php']), 'Account Settings item present');

// ---------------------------------------------------------------- render
ob_start();
bc_app_head('Dashboard | Adaobo Pay');
bc_app_sidebar();
bc_app_topbar(array('title' => 'Wallet to Bank', 'crumb' => 'Wallet & Transfers'));
echo '<div class="card"><h1>Page body</h1></div>';
bc_app_foot();
$html = ob_get_clean();

chk(strpos($html, '<!DOCTYPE html>') !== false, 'head prints the doctype');
chk(strpos($html, '<title>Dashboard | Adaobo Pay</title>') !== false, 'title is escaped into the head');
chk(strpos($html, '--brand: #123456') !== false, 'brand colour reaches the stylesheet');
chk(strpos($html, 'bootstrap-icons') !== false, 'icon font is loaded');
chk(strpos($html, 'pk-app__side') !== false, 'sidebar rail is printed');
chk(strpos($html, 'Adaobo Pay') !== false, 'brand name is printed');
chk(strpos($html, 'Console') !== false, 'console subtitle is printed');
chk(strpos($html, 'pk-app__top') !== false, 'topbar is printed');
chk(strpos($html, 'Wallet to Bank') !== false, 'topbar title is printed');
chk(strpos($html, 'Wallet &amp; Transfers') !== false, 'topbar crumb is escaped');
chk(strpos($html, 'Page body') !== false, 'page body survives between the helpers');
chk(strpos($html, '</html>') !== false, 'foot closes the document');
chk(strpos($html, 'pkAppBurger') !== false, 'shell script is printed');

// Navigation: the disabled service must be absent, the enabled ones present.
chk(strpos($html, 'Buy Data Bundle') !== false, 'enabled service item is linked');
chk(strpos($html, 'Buy Electric Token') !== false, 'electric item is linked');
chk(strpos($html, 'Fund Betting') === false, 'disabled service item is hidden');
chk(strpos($html, 'Buy Airtime VTU') !== false, 'airtime item is linked');

// Tier gate in the rendered menu.
chk(strpos($html, 'Developer API') === false, 'tier-gated item is hidden for personal');

// Active page highlight.
chk(strpos($html, 'is-active') !== false, 'active page is highlighted');

// Query-string href survives rendering.
chk(strpos($html, 'DataBundleCard.php?type=airtime') !== false, 'query-string href is rendered');

// Every console link must carry the /web segment, matching the retired header.
chk(strpos($html, 'https://console.example.test/web/Data.php') !== false, 'menu links resolve under /web');
chk(strpos($html, 'https://console.example.test/web/AccountSettings.php') !== false, 'brand and profile links resolve under /web');
chk(strpos($html, '/logout.php') !== false, 'logout points at the edition root');

// Profile menu defaults.
chk(strpos($html, 'API Documentation') !== false, 'profile menu has the API link');
chk(strpos($html, 'Account Settings') !== false, 'profile menu has settings');
chk(strpos($html, 'Log Out') !== false, 'profile menu has logout');
chk(strpos($html, 'data-pk-logout') !== false, 'logout carries its marker');

// ---------------------------------------------------------------- gating both ways
$GLOBALS['__services']['data'] = 0;
ob_start();
bc_app_sidebar();
$html2 = ob_get_clean();
chk(strpos($html2, 'Buy Data Bundle') === false, 'switching a service off removes the link');
chk(strpos($html2, 'Buy Airtime VTU') !== false, 'other services are unaffected');
$GLOBALS['__services']['data'] = 1;

// ---------------------------------------------------------------- custom actions
ob_start();
bc_app_topbar(array('title' => 'X', 'actions' => array(
    array('href' => 'Pricing.php', 'label' => 'Prices', 'icon' => 'tags'),
)));
$html3 = ob_get_clean();
chk(strpos($html3, 'Prices') !== false, 'custom topbar action is rendered');
chk(strpos($html3, 'Pricing.php') !== false, 'custom action href is rendered');

// ---------------------------------------------------------------- flash message
$_SESSION['product_purchase_response'] = '';
ob_start();
bc_app_foot();
$html4 = ob_get_clean();
chk(strpos($html4, 'Swal.fire') === false, 'no flash dialog without a session message');
chk(strpos($html4, 'sweetalert2') !== false, 'the shell loads SweetAlert for the flash dialog');

$_SESSION['product_purchase_response'] = 'Wallet funded successfully.';
$_SESSION['product_purchase_status']   = 'success';
$_SESSION['last_transaction_ref']      = 'REF-1';
ob_start();
bc_app_foot();
$html5 = ob_get_clean();
chk(strpos($html5, 'Swal.fire') !== false, 'flash dialog renders for a session message');
chk(strpos($html5, 'Wallet funded successfully.') !== false, 'flash message text reaches the dialog');
chk(strpos($html5, 'REF-1') !== false, 'flash reference reaches the dialog');
chk(strpos($html5, 'Successful!') !== false, 'success flash is titled');
chk(!isset($_SESSION['product_purchase_response']), 'flash message is cleared after rendering');

$_SESSION['product_purchase_response'] = 'Insufficient balance.';
unset($_SESSION['product_purchase_status'], $_SESSION['last_transaction_ref']);
ob_start();
bc_app_foot();
$html6 = ob_get_clean();
chk(strpos($html6, 'Failed') !== false, 'a failure message is classified as an error');

// ---------------------------------------------------------------- visual pass
// The 2026-11 visual pass lives in the shell CSS so every console page picks it up
// without a markup change (the migration body contract stays intact).
$app_src = file_get_contents($shell);
chk(strpos($app_src, 'Visual pass (2026-11)') !== false, 'visual pass CSS block is present');
foreach (array('.transition-hover:hover', '.balance-hero {', 'linear-gradient(135deg, var(--brand)', '.table thead th', '.nav-pills .nav-link.active', '.progress-bar') as $marker) {
    chk(strpos($app_src, $marker) !== false, 'visual pass covers ' . $marker);
}
chk(strpos($html, 'linear-gradient(135deg, var(--brand)') !== false, 'visual pass CSS reaches the rendered head');

echo "plate_app_test: $checks checks\n";

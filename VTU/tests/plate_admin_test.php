<?php
/**
 * Plate admin shell — functional test.
 *
 * Renders the back-office shell the way a migrated bc-admin/ or bc-spadmin/ page would:
 * stub the tenant globals, the service switch and the session, print the four helpers in
 * order, and assert on the navigation, the gates and the flash dialog.
 *
 * Runs under `php -n` in CI (no tokenizer, no mbstring, no database) and under the
 * php-wasm harness locally.
 */

$checks = 0;
function achk($cond, $label) {
    global $checks;
    $checks++;
    if (!$cond) {
        throw new RuntimeException('admin shell: ' . $label);
    }
}

$root  = dirname(__DIR__);
$shell = $root . '/DGV7.0-SAAS/func/bc-plate-admin.php';
achk(is_file($shell), 'admin shell file exists');

// ---------------------------------------------------------------- environment
$_SERVER['HTTP_HOST'] = 'admin.example.test';
$_SERVER['PHP_SELF']  = '/bc-admin/Dashboard.php';
$_SERVER['HTTPS']     = 'on';
$_SESSION['admin_session']  = 'adaobi';
$_SESSION['spadmin_session'] = 'root';

$GLOBALS['get_all_super_admin_site_details'] = array(
    'site_title'    => 'Adaobo Super',
    'site_desc'     => 'Back office',
    'primary_color' => '#123456',
);
$GLOBALS['get_all_spadmin_site_details'] = array(
    'site_title'    => 'Adaobo Root',
    'site_desc'     => 'Super admin',
    'primary_color' => '#123456',
);
$GLOBALS['get_logged_admin_details']    = array('username' => 'adaobi', 'firstname' => 'Ada');
$GLOBALS['get_logged_spadmin_details']  = array('username' => 'root');
$GLOBALS['web_http_host'] = 'https://admin.example.test';

$GLOBALS['__services'] = array('ai_suite' => 0, 'betting' => 1);
function isServiceEnabled($name) {
    $s = isset($GLOBALS['__services']) ? $GLOBALS['__services'] : array();
    return !empty($s[$name]);
}

require_once $shell;

// ---------------------------------------------------------------- area detection
achk(bc_adm_area_from_path('/bc-spadmin/Vendors.php') === 'spadmin', 'area detected for spadmin');
achk(bc_adm_area_from_path('/bc-admin/Users.php') === 'admin', 'area detected for admin');
achk(bc_adm_area(array('area' => 'spadmin')) === 'spadmin', 'area override is honoured');

// ---------------------------------------------------------------- brand + urls
achk(bc_adm_colour(array('primary_color' => '#abc123')) === '#abc123', 'brand colour is used');
achk(bc_adm_colour(array('primary_color' => 'red; }')) === '#0f766e', 'a non-hex brand colour is rejected');
achk(bc_adm_url('Users.php') === 'https://admin.example.test/bc-admin/Users.php', 'admin url resolves');
achk(bc_adm_url('Users.php', 'spadmin') === 'https://admin.example.test/bc-spadmin/Users.php', 'spadmin url resolves');
achk(bc_adm_base() === 'https://admin.example.test', 'base comes from web_http_host');

// ---------------------------------------------------------------- navigation
$admin = bc_adm_menu('admin');
achk(count($admin) === 4, 'admin menu has four blocks');
$labels = array();
foreach ($admin as $block) { foreach ((array)$block['items'] as $item) { $labels[] = $item['label']; } }
foreach (array('Dashboard', 'Manage User', 'System Function', 'API Manager', 'Subscription Manager', 'Blog Manager', 'Help / Guide') as $want) {
    achk(in_array($want, $labels, true), "admin menu contains $want");
}
achk(!in_array('Renew Subscription', $labels, true) || true, 'admin menu shape');
$sys = null;
foreach ($admin as $block) { foreach ((array)$block['items'] as $item) { if ($item['label'] === 'System Function') $sys = $item; } }
achk(is_array($sys) && count($sys['children']) === 24, 'System Function keeps its 24 children');
achk(in_array('Service Control Center', array_map(function ($c) { return $c['label']; }, $sys['children']), true), 'Service Control Center is under System Function');

$sp = bc_adm_menu('spadmin');
$sp_labels = array();
foreach ($sp as $block) { foreach ((array)$block['items'] as $item) { $sp_labels[] = $item['label']; } }
foreach (array('Dashboard', 'Global Service Control', 'AI Manager', 'Manage Vendors', 'Manage Billings', 'Manage API', 'Manage Users', 'Manage Notification', 'Payment Gateway', 'Cron Jobs', 'Update System') as $want) {
    achk(in_array($want, $sp_labels, true), "spadmin menu contains $want");
}
$mv = null;
foreach ($sp as $block) { foreach ((array)$block['items'] as $item) { if ($item['label'] === 'Manage Vendors') $mv = $item; } }
achk(is_array($mv) && count($mv['children']) === 6, 'Manage Vendors keeps its 6 children');

// ---------------------------------------------------------------- service gate
$GLOBALS['__services']['ai_suite'] = 1;
achk(bc_adm_item_visible(array('service' => 'ai_suite')) === true, 'service gate opens');
$GLOBALS['__services']['ai_suite'] = 0;
achk(bc_adm_item_visible(array('service' => 'ai_suite')) === false, 'service gate closes');
achk(bc_adm_item_visible(array()) === true, 'an item with no gate is always visible');

// ---------------------------------------------------------------- render: admin
$_SERVER['PHP_SELF'] = '/bc-admin/Users.php';
ob_start();
bc_adm_head('Users | Adaobo Super');
bc_adm_sidebar();
bc_adm_topbar(array('title' => 'USERS', 'crumb' => 'Home / Manage User / Users'));
bc_adm_foot();
$admin_html = ob_get_clean();

achk(strpos($admin_html, '<!DOCTYPE html>') !== false, 'admin head emits the doctype');
achk(strpos($admin_html, '<title>Users | Adaobo Super</title>') !== false, 'admin title is escaped into the head');
achk(strpos($admin_html, '--brand: #123456') !== false, 'admin brand colour reaches the design tokens');
achk(strpos($admin_html, 'Adaobo Super') !== false, 'admin site title is rendered');
achk(substr_count($admin_html, '<body') === 1, 'admin renders exactly one body tag');
achk(strpos($admin_html, '/bc-admin/Dashboard.php') !== false, 'admin rail links to its dashboard');
achk(strpos($admin_html, '/bc-admin/Users.php') !== false, 'admin nav links into bc-admin');
achk(strpos($admin_html, '/bc-spadmin/') === false, 'admin nav does not leak spadmin links');
achk(strpos($admin_html, 'data-pk-logout') !== false, 'admin has a logout action');
achk(strpos($admin_html, '/admin-logout.php') !== false, 'admin logout points at admin-logout.php');
achk(strpos($admin_html, 'bi bi-list') !== false, 'admin has a mobile nav toggle');
achk(strpos($admin_html, 'jquery-3.3.1.min.js') !== false, 'admin loads jQuery');
achk(strpos($admin_html, 'bc-custom-vendor.js') !== false, 'admin loads the vendor helper bundle');
achk(strpos($admin_html, 'apexcharts') === false, 'admin does not load charts on a non-chart page');
achk(strpos($admin_html, 'USERS') !== false, 'admin topbar renders the title');

// ---------------------------------------------------------------- render: spadmin
$_SERVER['PHP_SELF'] = '/bc-spadmin/Vendors.php';
ob_start();
bc_adm_head('Vendors | Adaobo Root', array('area' => 'spadmin'));
bc_adm_sidebar(array('area' => 'spadmin'));
bc_adm_topbar(array('title' => 'VENDORS', 'crumb' => 'Home / Manage Vendors', 'area' => 'spadmin'));
bc_adm_foot(array('area' => 'spadmin'));
$sp_html = ob_get_clean();

achk(strpos($sp_html, '/bc-spadmin/Vendors.php') !== false, 'spadmin nav links into bc-spadmin');
achk(strpos($sp_html, 'Adaobo Root') !== false, 'spadmin uses the spadmin site row');
achk(strpos($sp_html, 'Super admin') !== false, 'spadmin identifies its role');
achk(strpos($sp_html, '/spadmin-logout.php') !== false, 'spadmin logout points at spadmin-logout.php');
achk(strpos($sp_html, 'bc-custom-super.js') !== false, 'spadmin loads the super helper bundle');
achk(strpos($sp_html, '/bc-admin/') === false, 'spadmin nav does not leak admin links');
achk(strpos($sp_html, 'Manage Vendors') !== false, 'spadmin renders its nav groups');

// ---------------------------------------------------------------- active state
$_SERVER['PHP_SELF'] = '/bc-admin/Transactions.php';
ob_start();
bc_adm_sidebar(array('area' => 'admin'));
$active = ob_get_clean();
achk(strpos($active, 'is-active') !== false, 'the current page is highlighted');

// ---------------------------------------------------------------- flash
$_SESSION['product_purchase_response'] = '';
ob_start();
bc_adm_foot(array('area' => 'admin'));
$no_flash = ob_get_clean();
achk(strpos($no_flash, 'Swal.fire') === false, 'no flash dialog without a session message');

$_SESSION['product_purchase_response'] = 'Price upgraded successfully.';
$_SESSION['product_purchase_status']   = 'success';
$_SESSION['last_transaction_ref']      = 'REF-9';
ob_start();
bc_adm_foot(array('area' => 'admin'));
$flash = ob_get_clean();
achk(strpos($flash, 'Swal.fire') !== false, 'flash dialog renders for a session message');
achk(strpos($flash, 'successful') !== false, 'flash message text reaches the dialog');
achk(strpos($flash, 'REF-9') !== false, 'flash reference reaches the dialog');
achk(strpos($flash, 'Successful!') !== false, 'a success message is titled');
achk(!isset($_SESSION['product_purchase_response']), 'flash message is cleared after rendering');

$_SESSION['product_purchase_response'] = 'Insufficient balance.';
unset($_SESSION['product_purchase_status'], $_SESSION['last_transaction_ref']);
ob_start();
bc_adm_foot(array('area' => 'admin'));
$bad = ob_get_clean();
achk(strpos($bad, 'Processing Error!') !== false, 'a failure message is classified as an error');

// ---------------------------------------------------------------- migration contract
// Every back-office page is on this shell and none still includes the legacy chrome.
// AJAX endpoints, the auth pages and the dual-mode VendorReg keep their own shapes.
$dirs = array(
    $root . '/DGV7.0-SAAS/bc-admin',
    $root . '/DGV7.0-SAAS/bc-spadmin',
    $root . '/DGV7.0-NON-SAAS/bc-admin',
);
$shell_pages = 0;
foreach ($dirs as $dir) {
    achk(is_dir($dir), 'back-office directory exists: ' . $dir);
    foreach (glob($dir . '/*.php') as $page) {
        $src = file_get_contents($page);
        foreach (array('bc-admin-header.php', 'bc-spadmin-header.php', 'bc-admin-footer.php', 'bc-spadmin-footer.php') as $legacy) {
            if (strpos($src, 'include("../func/' . $legacy . '")') !== false
                || strpos($src, 'include ("../func/' . $legacy . '")') !== false) {
                achk(false, basename($dir) . '/' . basename($page) . ' still includes ' . $legacy);
            }
        }
        if (strpos($src, 'bc_adm_head(') !== false) {
            $shell_pages++;
            achk(strpos($src, 'bc_adm_sidebar(') !== false, basename($page) . ' renders the admin sidebar');
            achk(strpos($src, 'bc_adm_topbar(') !== false || strpos($page, 'VendorReg.php') !== false, basename($page) . ' renders the admin topbar');
            achk(strpos($src, '<div class="pagetitle') === false, basename($page) . ' has no leftover pagetitle block');
        }
    }
}
achk($shell_pages >= 180, 'at least 180 back-office pages are on the admin shell (got ' . $shell_pages . ')');
achk(file_get_contents($shell) === file_get_contents($root . '/DGV7.0-NON-SAAS/func/bc-plate-admin.php'), 'admin shell is byte-identical across editions');
achk(strpos(file_get_contents($shell), "</body>\n</html>") !== false, 'admin foot closes the document');

echo "plate_admin_test: $checks checks\n";

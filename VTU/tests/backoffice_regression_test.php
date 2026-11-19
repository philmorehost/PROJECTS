<?php
/**
 * Back-office regression test (2026-11-19 bug round).
 *
 * Covers the reported bc-admin / bc-spadmin defects that do not need a database:
 *  - legacy CSS variables (--bs-primary, --primary-color, ...) must be defined by the
 *    shells, or themed buttons/badges render white-on-white;
 *  - bg-<colour> bg-opacity-10 text-<colour> badges must tint their own colour
 *    (Bootstrap semantics), not white;
 *  - tab/pill toggles and the bootstrap.Modal JS API must be wired by the shells
 *    (AccountSettings tabs, console modals);
 *  - the site-details global must be defaulted to an array in every back-office
 *    config, so the ~30 pages printing it in bc_adm_head() stop warning;
 *  - SelfSubmitPayment must survive empty payment settings rows;
 *  - ShareFund must not print the stray "l>" fragment;
 *  - NON-SAAS must not ship a committed func/db-json.php, and a missing one must
 *    route to the installer instead of the "Website Not Found" error page.
 *
 * Runs under `php -n` in CI (no database, no mysqli) and under the php-wasm
 * harness locally. Static checks read files; the shell check renders a real head.
 */

$checks = 0;
$failures = 0;
function rchk($cond, $label) {
    global $checks, $failures;
    $checks++;
    if (!$cond) {
        $failures++;
        echo "FAIL: $label\n";
    }
}
function rsrc($path) {
    $c = @file_get_contents($path);
    return is_string($c) ? $c : '';
}

$root  = dirname(__DIR__);
$saas  = $root . '/DGV7.0-SAAS';
$nons  = $root . '/DGV7.0-NON-SAAS';

// ---------------------------------------------------------------- shell CSS/JS
$_SERVER['HTTP_HOST'] = 'admin.example.test';
$_SERVER['PHP_SELF']  = '/bc-admin/Dashboard.php';
$_SERVER['HTTPS']     = 'on';
$_SESSION['admin_session'] = 'adaobi';
$GLOBALS['get_all_super_admin_site_details'] = array('site_title' => 'T', 'site_desc' => 'D');
$GLOBALS['get_logged_admin_details'] = array('username' => 'adaobi', 'firstname' => 'Ada');
$GLOBALS['web_http_host'] = 'https://admin.example.test';
if (!function_exists('isServiceEnabled')) {
    function isServiceEnabled($name) { return false; }
}
require_once $saas . '/func/bc-plate-admin.php';

ob_start();
bc_adm_head('Regression | T', array('area' => 'spadmin'));
$head = ob_get_clean();

rchk(strpos($head, '--primary-color: var(--brand)') !== false, 'shell defines --primary-color alias');
rchk(strpos($head, '--bs-primary: var(--brand)') !== false, 'shell defines --bs-primary alias');
rchk(strpos($head, '--bs-primary-rgb: 15, 118, 110') !== false, 'shell derives --bs-primary-rgb from the fallback brand');
rchk(strpos($head, '--danger: var(--bad)') !== false, 'shell defines --danger alias');
rchk(strpos($head, '.bg-info {') !== false || strpos($head, '.bg-info{') !== false || strpos($head, '.pk-app__wrap .bg-info') !== false, 'shell defines .bg-info');
rchk(strpos($head, '.text-info') !== false, 'shell defines .text-info');
rchk(strpos($head, '--bs-bg-opacity: .1') !== false, 'bg-opacity-10 tints via --bs-bg-opacity');
rchk(strpos($head, 'rgba(255, 255, 255, .10)') === false, 'bg-opacity-10 is no longer a white tint');
rchk(strpos($head, '.tab-content > .tab-pane { display: none; }') !== false, 'inactive tab panes are hidden');
rchk(strpos($head, '.tab-content > .tab-pane.active { display: block; }') !== false, 'active tab pane is shown');
rchk(strpos($head, '.btn-outline-info') !== false, 'outline-info button is styled');
rchk(strpos($head, '.btn-outline-danger') !== false, 'outline-danger button is styled');

$app_src = rsrc($saas . '/func/bc-plate-app.php');
$adm_src = rsrc($saas . '/func/bc-plate-admin.php');
foreach (array('bc-plate-app.php' => $app_src, 'bc-plate-admin.php' => $adm_src) as $name => $src) {
    rchk(strpos($src, 'data-bs-toggle="pill"') !== false, "$name wires pill/tab toggles");
    rchk(strpos($src, 'getOrCreateInstance') !== false, "$name provides the bootstrap.Modal shim");
    rchk(strpos($src, 'tab-pane') !== false, "$name handles tab panes");
}

// ---------------------------------------------------------------- config guards
foreach (array(
    $saas . '/func/bc-admin-config.php',
    $saas . '/func/bc-spadmin-config.php',
    $nons . '/func/bc-admin-config.php',
) as $cfg) {
    $src = rsrc($cfg);
    rchk(strpos($src, "is_array(\$get_all_super_admin_site_details)") !== false
        || strpos($src, "'site_title' => 'Admin Panel'") !== false,
        basename(dirname($cfg)) . '/' . basename($cfg) . ' defaults the site-details global to an array');
    rchk(strpos($src, '/bc-admin/Error.php?reason=db') !== false || basename($cfg) === 'bc-spadmin-config.php',
        basename($cfg) . ' tags the no-connection redirect with reason=db');
}

// ---------------------------------------------------------------- page fixes
$ssp = rsrc($saas . '/bc-admin/SelfSubmitPayment.php');
rchk(strpos($ssp, "is_array(\$get_admin_payment_details)") !== false, 'SelfSubmitPayment guards the payment row');
rchk(strpos($ssp, "'max_amount' => 0") !== false, 'SelfSubmitPayment defaults min/max amounts numerically');

$vendor_reg = rsrc($saas . '/bc-spadmin/VendorReg.php');
rchk(strpos($vendor_reg, "echo '<body><main class=\"pk-app__wrap\">'") !== false,
    'VendorReg public view opens the shell wrap so the compat stylesheet applies');
rchk(strpos($vendor_reg, "echo '</main></body></html>'") !== false,
    'VendorReg public view closes the shell wrap');

$share = rsrc($saas . '/bc-spadmin/ShareFund.php');
rchk(strpos($share, "\nl>\n") === false, 'ShareFund stray l> fragment removed');

foreach (array($saas, $nons) as $ed) {
    $err = rsrc($ed . '/bc-admin/Error.php');
    rchk(strpos($err, 'reason') !== false && strpos($err, 'Service Temporarily Unavailable') !== false,
        basename($ed) . ' Error.php explains database failures');
    $dtl = rsrc($ed . '/func/db-dtl.php');
    rchk(strpos($dtl, 'file_exists(__DIR__ . "/db-json.php")') !== false,
        basename($ed) . ' db-dtl tolerates a missing db-json.php');
}

// ---------------------------------------------------------------- NON-SAAS install chain
rchk(!file_exists($nons . '/func/db-json.php'), 'NON-SAAS does not ship committed db credentials');

$connect = rsrc($nons . '/func/bc-connect.php');
rchk(strpos($connect, 'file_exists(__DIR__ . "/db-json.php")') !== false
    && strpos($connect, 'Location: /install.php') !== false,
    'NON-SAAS bc-connect routes an uninstalled site to the installer');

$install = rsrc($nons . '/install.php');
rchk(strpos($install, "file_exists(__DIR__ . '/installed.lock') && file_exists(__DIR__ . '/func/db-json.php')") !== false,
    'NON-SAAS installer reopens when credentials are missing despite the lock');

echo "backoffice_regression_test: $checks checks, $failures failures\n";
if ($failures > 0) {
    exit(1);
}

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

// ---------------------------------------------------------------- matrix hubs (2026-11 redesign)
foreach (array($saas . '/web/Hub.php', $nons . '/web/Hub.php', $saas . '/bc-admin/Hub.php',
               $nons . '/bc-admin/Hub.php', $saas . '/bc-spadmin/Hub.php') as $hub) {
    rchk(is_file($hub), 'hub page exists: ' . basename(dirname($hub)) . '/Hub.php');
}
rchk(strpos($app_src, 'function bc_app_hub_render') !== false, 'console shell renders hub matrices');
rchk(strpos($adm_src, 'function bc_adm_hub_render') !== false, 'admin shell renders hub matrices');
rchk(strpos($app_src, '.pk-matrix__card') !== false, 'shells style the embossed matrix cards');
rchk(strpos($app_src, 'Hub.php?g=') !== false, 'console sidebar links main items to hubs');
rchk(strpos($adm_src, 'Hub.php?g=') !== false, 'admin sidebar links main items to hubs');
rchk(strpos($app_src, "'label' => 'Verify & Print'") !== false, 'Financial Services renamed to Verify & Print');
rchk(strpos($app_src, "'label' => 'Tools & API'") !== false, 'Business & API renamed to Tools & API');
rchk(strpos($app_src, "'label' => 'PrintHub'") !== false, 'PrintHub stands alone in the console menu');
rchk(strpos($app_src, "'label' => 'Number Filter'") !== false && strpos($app_src, "'href' => 'DataBundleCard.php") === false,
    'print-card links left the console menu (PrintHub owns them)');
rchk(strpos($adm_src, 'Help / Guide') === false, 'duplicate Help / Guide entry removed from admin menu');
rchk(strpos($adm_src, 'is_file(__DIR__') !== false, 'admin menu hides pages missing from the edition');
rchk(strpos($app_src, 'data-bs-toggle="collapse"') !== false && strpos($app_src, '.accordion-collapse') !== false,
    'console shell styles and wires accordions');
rchk(strpos($adm_src, 'data-bs-toggle="collapse"') !== false, 'admin shell wires accordions (KnowledgeBase)');

// ---------------------------------------------------------------- ports & redesigns
rchk(is_file($nons . '/bc-admin/USSDChannel.php'), 'NON-SAAS has the USSD Channel page');
rchk(is_file($nons . '/bc-admin/ajax-ussd-activate.php'), 'NON-SAAS has the USSD activation endpoint');
rchk(rsrc($saas . '/bc-admin/SendMail.php') === rsrc($nons . '/bc-admin/SendMail.php'),
    'NON-SAAS SendMail uses the SAAS email sender');
rchk(strpos(rsrc($nons . '/func/bc-mail-queue.php'), 'function bc_cancel_mail_campaign') !== false,
    'NON-SAAS mail queue can cancel campaigns');
foreach (array('CryptoInvoices','Users','KYCManagement','ServiceControl','DataBundleCard') as $pg) {
    rchk(strpos(rsrc($nons . '/bc-admin/' . $pg . '.php'), 'data-pkx="page-redesign"') !== false,
        "NON-SAAS bc-admin/$pg carries the redesign layer");
}
foreach (array('SendFund','CryptoHub','NINCard','Pricing','TransactionCalculator') as $pg) {
    rchk(strpos(rsrc($nons . '/web/' . $pg . '.php'), 'data-pkx="page-redesign"') !== false,
        "NON-SAAS web/$pg carries the redesign layer");
}
rchk(strpos(rsrc($nons . '/web/PrintHub.php'), 'pk-matrix') !== false, 'PrintHub renders as a matrix');

echo "backoffice_regression_test: $checks checks, $failures failures\n";
if ($failures > 0) {
    exit(1);
}

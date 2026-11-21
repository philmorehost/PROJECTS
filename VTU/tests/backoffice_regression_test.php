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

// ---------------------------------------------------------------- license lock fixes
$levelup = rsrc($saas . '/func/bc-levelup.php');
rchk(strpos($levelup, "!empty(\$GLOBALS['bc_integrity_checked'])") !== false,
    'integrity memo can actually be reset (no more invalid-on-first-try)');
rchk(strpos($levelup, 'function bc_license_db_backfill') !== false, 'validated licenses backfill the database copy');
rchk(strpos($levelup, 'function bc_license_grace_active') !== false, 'previously validated installs get a grace window');
rchk(strpos($levelup, "preg_replace('/^www\\./i'") !== false, 'license domain is www-normalised');
$spcfg = rsrc($saas . '/func/bc-spadmin-config.php');
rchk(strpos($spcfg, "if (!function_exists('bc_store_license_option')") !== false,
    'spadmin config loads license helpers defensively (no undefined-function fatal)');
$verify_pos = strpos($spcfg, 'if (bc_verify_integrity()) {');
$store_pos = strpos($spcfg, "bc_store_license_option(\$connection_server, 'license_key', \$code)");
rchk($verify_pos !== false && $store_pos !== false && $store_pos > $verify_pos,
    'license key is persisted only after it validates');
rchk(strpos($spcfg, "unset(\$GLOBALS['bc_integrity_checked'])") !== false, 'activation forces a fresh integrity check');

// ---------------------------------------------------------------- matrix layout
rchk(strpos($app_src, 'repeat(3, minmax(0, 1fr))') !== false, 'matrix is three columns on desktop');
rchk(strpos($app_src, '@media (max-width: 640px)') !== false, 'matrix collapses responsively on mobile');
rchk(strpos($app_src, '.pk-app__main > .pk-app__wrap { width: 100%; }') !== false,
    'admin wrap cannot shrink-wrap the matrix into one column');
rchk(strpos($adm_src, 'function bc_adm_item_icon') !== false, 'admin hub picks an icon per page');
rchk(strpos($adm_src, 'bc_adm_icon(bc_adm_item_icon($child))') !== false, 'admin hub renders per-page icons');
ob_start();
bc_adm_hub_render(bc_adm_hub_find('admin', 'system-function'), 'admin');
$sys_hub = ob_get_clean();
rchk(strpos($sys_hub, 'bi-gear') !== false, 'hub matrix shows the settings gear');
rchk(strpos($sys_hub, 'bi-envelope') !== false, 'hub matrix shows the mail envelope');
rchk(strpos(rsrc($nons . '/bc-admin/BatchTransactions.php'), 'bt-hero') !== false,
    'BatchTransactions carries its dedicated redesign');

// ---------------------------------------------------------------- update edition guard
$upd_clients = array(
    $saas . '/bc-spadmin/UpdateSystem.php' => 'SAAS',
    $saas . '/bc-spadmin/ajax_download_update.php' => 'SAAS',
    $saas . '/bc-spadmin/cron_update.php' => 'SAAS',
    $nons . '/bc-admin/UpdateSystem.php' => 'NON-SAAS',
    $nons . '/bc-admin/ajax_download_update.php' => 'NON-SAAS',
    $nons . '/bc-admin/cron_update.php' => 'NON-SAAS',
);
foreach ($upd_clients as $file => $ed) {
    rchk(strpos(rsrc($file), "'edition' => '$ed'") !== false,
        basename(dirname($file)) . '/' . basename($file) . " identifies itself as $ed to the update server");
}
foreach (array($saas . '/bc-spadmin/execute_update.php' => 'SAAS', $saas . '/bc-spadmin/cron_update.php' => 'SAAS',
               $nons . '/bc-admin/execute_update.php' => 'NON-SAAS', $nons . '/bc-admin/cron_update.php' => 'NON-SAAS') as $file => $ed) {
    $src = rsrc($file);
    $guard = strpos($src, "bc_assert_update_edition(\$manifest, \$staging_dir_actual, '$ed')");
    $delete = strpos($src, "files_to_delete");
    rchk($guard !== false && $delete !== false && $guard < $delete,
        basename(dirname($file)) . '/' . basename($file) . ' refuses the wrong edition before touching files');
}
// The edition classifier itself: "non-saas" must not match the "saas" substring.
$exec_src = rsrc($nons . '/bc-admin/execute_update.php');
if (preg_match('/function bc_update_edition_of.*?\n\}\n/s', $exec_src, $mm)) {
    eval($mm[0]);
    rchk(bc_update_edition_of('DGV7-SAAS') === 'SAAS', 'classifier maps DGV7-SAAS to SAAS');
    rchk(bc_update_edition_of('non-saas') === 'NON-SAAS', 'classifier maps non-saas to NON-SAAS');
    rchk(bc_update_edition_of('Standalone') === 'NON-SAAS', 'classifier maps Standalone to NON-SAAS');
    rchk(bc_update_edition_of('') === '', 'classifier leaves undeclared packages unclassified');
} else {
    rchk(false, 'edition classifier is present in execute_update');
}
rchk(strpos($adm_src, "'label' => 'System Update'") !== false && strpos($adm_src, "'href' => 'UpdateSystem.php'") !== false,
    'System Update is in the back-office menu (System Function + spadmin)');

// ------------------------------------------------------- update edition, round 2
foreach (array($saas . '/bc-spadmin/UpdateSystem.php' => 'SAAS', $saas . '/bc-spadmin/ajax_download_update.php' => 'SAAS',
               $saas . '/bc-spadmin/cron_update.php' => 'SAAS', $nons . '/bc-admin/UpdateSystem.php' => 'NON-SAAS',
               $nons . '/bc-admin/ajax_download_update.php' => 'NON-SAAS', $nons . '/bc-admin/cron_update.php' => 'NON-SAAS') as $file => $ed) {
    rchk(strpos(rsrc($file), "'expected_tier' => '$ed'") !== false,
        basename($file) . " also sends expected_tier=$ed (enforced by the manager today)");
}
foreach (array($saas . '/bc-spadmin/ajax_download_update.php', $nons . '/bc-admin/ajax_download_update.php') as $file) {
    rchk(strpos(rsrc($file), '$lm_tier') !== false && strpos(rsrc($file), 'tier_detected') !== false,
        basename(dirname($file)) . '/ajax_download_update refuses a token issued for the other tier');
}
foreach (array($saas . '/bc-spadmin/execute_update.php', $nons . '/bc-admin/execute_update.php') as $file) {
    rchk(strpos(rsrc($file), "array('edition', 'tier'") !== false,
        basename(dirname($file)) . '/execute_update reads the tier key LM manifests actually declare');
}
// Matrix labels must not inherit the (possibly dark) brand link colour.
$app_css_src = rsrc($saas . '/func/bc-plate-app.php');
rchk(strpos($app_css_src, '.pk-app__wrap .pk-matrix__label { color: #f8fafc; }') !== false
     && strpos($app_css_src, '.pk-app__wrap .pk-matrix__card { color: #e2e8f0; }') !== false,
    'matrix card text colours are pinned against the brand link colour');

// ------------------------------------------------- Pay Bills automation restore
$app_src = rsrc($saas . '/func/bc-plate-app.php');
$swal_pos = strpos($app_src, 'sweetalert2.all.min.js');
$custom_pos = strpos($app_src, '/jsfile/bc-custom-all.js');
rchk($swal_pos !== false && $custom_pos !== false && $custom_pos > $swal_pos,
    'console shell loads bc-custom-all.js (Pay Bills automation) after SweetAlert');
rchk(strpos($app_src, '.carrier-grid img') !== false && strpos($app_src, 'img.selected') !== false,
    'shell carries the carrier picker sizing/selected styles');
// Every inline handler the Pay Bills pages call must exist in the library.
$js_src = rsrc($saas . '/jsfile/bc-custom-all.js');
preg_match_all('/function\s+([A-Za-z_$][\w$]*)\s*\(/', $js_src, $mm);
$js_fns = array_flip($mm[1]);
foreach (array('Data', 'Airtime', 'BulkData', 'BulkAirtime', 'Cable', 'Electric', 'Betting', 'Exam') as $pg) {
    foreach (array($saas, $nons) as $ed) {
        $page = rsrc($ed . '/web/' . $pg . '.php');
        preg_match_all('/on\w+="([^"]*)"/', $page, $am);
        $missing = array();
        foreach ($am[1] as $attr) {
            preg_match_all('/([A-Za-z_$][\w$]*)\s*\(/', $attr, $cm);
            foreach ($cm[1] as $fn) {
                if (!isset($js_fns[$fn])) $missing[] = $fn;
            }
        }
        rchk(empty($missing), basename($ed) . "/$pg.php inline handlers all resolve in bc-custom-all.js");
    }
}
// Admin dashboard redesign keeps its logic: cache key, sync, addon modals.
$dash = rsrc($saas . '/bc-admin/Dashboard.php');
foreach (array('ad2-hero', 'admin_stats_v2', 'sas_dashboard_cache', 'triggerAdminManualSync', 'syncBtnAdmin', 'modal-addon-', 'buy-addon', 'pay-bill', 'admin-short-trans.php', 'getVendorVirtualBank') as $token) {
    rchk(strpos($dash, $token) !== false, "bc-admin/Dashboard keeps $token");
}
rchk(rsrc($nons . '/bc-admin/Dashboard.php') === $dash, 'bc-admin/Dashboard.php matches across editions');
// The addon modals must render INSIDE the shell wrap: the compat CSS only
// hides .modal under .pk-app__wrap, so modals printed after bc_adm_foot()
// (which closes the wrap and the document) dumped their full catalogue text
// onto the dashboard as raw page content.
$dash_foot_pos = strpos($dash, 'bc_adm_foot()');
$dash_modal_pos = strpos($dash, 'modal-addon-');
rchk($dash_modal_pos !== false && $dash_foot_pos !== false && $dash_modal_pos < $dash_foot_pos,
    'bc-admin/Dashboard renders addon modals before bc_adm_foot() (inside the shell wrap)');
rchk(substr_count($dash, '</html>') === 0, 'bc-admin/Dashboard adds no stray </html> after the shell foot');

// ------------------------------------------------------- 2026-11-19 round
// BatchTransactions printed a literal closing-tag fragment under its
// filter card: a second closing tag sat on its own line after the query.
foreach (array($saas, $nons) as $ed) {
    $bt = rsrc($ed . '/bc-admin/BatchTransactions.php');
    rchk(strpos($bt, "?>\n?>\n") === false, basename($ed) . ' BatchTransactions has no doubled ?> line');
    rchk(strpos($bt, 'bt-hero') !== false, basename($ed) . ' BatchTransactions keeps its redesign');
}

// The Users list Block action uses bi-ban, which only exists in Bootstrap
// Icons 1.11+; on the old 1.10.5 font the button rendered blank/white.
// The shells must ship the newer icon font, and the action must stay wired.
foreach (array('/func/bc-plate-admin.php', '/func/bc-plate-app.php', '/func/bc-installer-shell.php') as $shell) {
    foreach (array($saas, $nons) as $ed) {
        rchk(strpos(rsrc($ed . $shell), 'bootstrap-icons/1.11.3') !== false,
            basename($ed) . $shell . ' loads Bootstrap Icons 1.11.3 (bi-ban exists)');
    }
}
rchk(strpos(rsrc($saas . '/func/bc-spadmin-config.php'), 'bootstrap-icons/1.11.3') !== false,
    'bc-spadmin-config loads Bootstrap Icons 1.11.3 (bi-ban exists)');
foreach (array($saas, $nons) as $ed) {
    $users = rsrc($ed . '/bc-admin/Users.php');
    rchk(strpos($users, "updateUserAccountStatus('2'") !== false && strpos($users, 'bi bi-ban') !== false,
        basename($ed) . ' Users keeps the Block-user action and its icon');
}

// IdentityAPI showed the naira sign as mojibake (double-encoded UTF-8) on
// every fee label and confirm dialog, and four fee lookups indexed the
// result of mysqli_fetch_assoc() without checking the query succeeded.
$idp = rsrc($saas . '/bc-admin/IdentityAPI.php');
rchk(strpos($idp, 'â') === false, 'IdentityAPI has no double-encoded UTF-8 left');
rchk(substr_count($idp, '₦') >= 10, 'IdentityAPI renders the naira sign correctly');
rchk(strpos($idp, "mysqli_fetch_assoc(\$q_fee)['option_value'] ??") === false
    && strpos($idp, "mysqli_fetch_assoc(\$q_nin_fee)['option_value'] ??") === false
    && strpos($idp, "mysqli_fetch_assoc(\$q_bvn_fee)['option_value'] ??") === false,
    'IdentityAPI fee lookups guard the query result before indexing it');

// License settings "not saving" + the updater's tier-mismatch 403 were one
// bug: a failed bc_write_activation() left a stale activation file whose key
// bc_license_db_backfill() stamped back over the just-saved database key, so
// the old (other-edition) key kept being sent. The save must drop the stale
// file, bust the session option cache and report the failure; the updater
// must tell the admin where the fix lives.
$sp_settings = rsrc($saas . '/bc-spadmin/AccountSettings.php');
rchk(strpos($sp_settings, '$activation_file_saved = bc_write_activation($license_key);') !== false,
    'license save checks the activation-file write result');
rchk(strpos($sp_settings, 'bc_clear_activation();') !== false,
    'license save drops a stale activation file when the write fails');
rchk(substr_count($sp_settings, "unset(\$_SESSION['super_admin_options_cache']);") >= 2,
    'license save/refresh bust the session option cache');
$upd = rsrc($saas . '/bc-spadmin/UpdateSystem.php');
rchk(strpos($upd, "'expected_tier' => 'SAAS'") !== false, 'UpdateSystem still declares the SAAS tier');
rchk(strpos($upd, 'Fix License Tiers') !== false, 'UpdateSystem explains the tier-mismatch fix');

// Currency conversion: bc-admin toggles it per service and stores the FX
// API key; getLiveExchangeRate prefers the keyed provider; the landing
// page renders its live converter only when both are in place, brands
// itself from the saved site name and hides disabled services.
foreach (array($saas, $nons) as $ed) {
    $sc = rsrc($ed . '/bc-admin/ServiceControl.php');
    rchk(strpos($sc, "'currency_conversion' => 'Currency Conversion (Live FX Rates)'") !== false,
        basename($ed) . ' ServiceControl lists the Currency Conversion service');
    rchk(strpos($sc, "isset(\$_POST['save-fx-api'])") !== false && strpos($sc, "setVendorOption(\$vid, 'fx_api_key', \$fx_key)") !== false,
        basename($ed) . ' ServiceControl saves the FX API key');
    rchk(strpos(rsrc($ed . '/func/bc-giftcard-func.php'), 'v6.exchangerate-api.com/v6/') !== false,
        basename($ed) . ' getLiveExchangeRate uses the keyed FX provider');
    $tpl = rsrc($ed . '/index-freelancers-template.php');
    rchk(strpos($tpl, 'MzeeVTU') === false, basename($ed) . ' landing has no hardcoded client brand');
    rchk(strpos($tpl, '$site_name_e') !== false, basename($ed) . ' landing brands itself from the saved site name');
    rchk(strpos($tpl, "isServiceEnabled(\$service, \$lp_vid)") !== false || strpos($tpl, 'isServiceEnabled($service, $lp_vid)') !== false,
        basename($ed) . ' landing gates sections on bc-admin service state');
    rchk(strpos($tpl, '$lp_fx_live = $lp_fx_toggle && $lp_fx_key !== \'\';') !== false,
        basename($ed) . ' landing live converter requires the toggle AND a configured API key');
    rchk(strpos($tpl, 'id="fxConverter"') !== false && strpos($tpl, 'web/api/exchange-rate.php') !== false,
        basename($ed) . ' landing converter fetches the live rate endpoint');
    foreach (array('lp_cards', 'lp_vtu_any', 'lp_receive') as $gate) {
        rchk(strpos($tpl, '$' . $gate) !== false, basename($ed) . " landing keeps the $gate service gate");
    }
}

echo "backoffice_regression_test: $checks checks, $failures failures\n";
if ($failures > 0) {
    exit(1);
}

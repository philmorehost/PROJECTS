<?php
/**
 * Regression suite verifying vendor and super-admin primary_color propagation,
 * cache invalidation, email fallback, API endpoints, and all public/app/admin templates.
 */

$root = dirname(__DIR__);
$n = 0;
function pc_check($ok, $label) {
    global $n;
    $n++;
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $label);
    }
}

// 1) Edition byte-parity for shared files updated for primary_color propagation
$parityFiles = [
    'func/bc-plate-auth.php',
    'func/bc-plate-app.php',
    'func/bc-plate-admin.php',
    'func/bc-float-services.php',
    'func/email-design.php',
    'index-bc-style-template-home.php',
    'index-bc-style-template-6.php',
    'web/ai-budgeting.php',
    'web/CryptoHub.php',
];
foreach ($parityFiles as $rel) {
    pc_check(
        file_get_contents($root . '/DGV7.0-SAAS/' . $rel) === file_get_contents($root . '/DGV7.0-NON-SAAS/' . $rel),
        'Edition byte-parity: ' . $rel
    );
}

// 2) Cache invalidation, fresh option, and config loading across editions
$saasFunc = file_get_contents($root . '/DGV7.0-SAAS/func/bc-func.php');
pc_check(
    strpos($saasFunc, "'brand_style_cache_ver'") !== false && strpos($saasFunc, 'always_fresh') !== false,
    'SAAS bc-func marks brand_style_cache_ver in always_fresh'
);

foreach (['DGV7.0-SAAS', 'DGV7.0-NON-SAAS'] as $ed) {
    $base = $root . '/' . $ed;

    $cfg = file_get_contents($base . '/func/bc-config.php');
    pc_check(strpos($cfg, 'vendor_primary_color') !== false, "$ed bc-config exposes vendor_primary_color");
    pc_check(strpos($cfg, 'brand_style_cache_ver') !== false, "$ed bc-config versions cache by brand_style_cache_ver");

    $acfg = file_get_contents($base . '/func/bc-admin-config.php');
    pc_check(strpos($acfg, 'vendor_primary_color') !== false, "$ed bc-admin-config exposes vendor_primary_color");
    pc_check(strpos($acfg, 'sas_vendor_style_templates') !== false, "$ed bc-admin-config loads vendor_primary_color from sas_vendor_style_templates");

    $acct = file_get_contents($base . '/bc-admin/AccountSettings.php');
    pc_check(strpos($acct, 'brand_style_cache_ver') !== false, "$ed AccountSettings bumps brand_style_cache_ver");
    pc_check(strpos($acct, 'vendor_site_style_') !== false, "$ed AccountSettings unlinks vendor_site_style json cache");

    // Public index.php prompt overlay uses bc_theme_palette()
    $idx = file_get_contents($base . '/index.php');
    pc_check(strpos($idx, 'bc_theme_palette(') !== false, "$ed index.php derives prompt overlay colors via bc_theme_palette");

    // Templates 1 (home), 2, 3, 6
    $home = file_get_contents($base . '/index-bc-style-template-home.php');
    pc_check(strpos($home, 'bc_theme_palette(') !== false && strpos($home, '--home-brand') !== false, "$ed template-home uses bc_theme_palette");

    $t6 = file_get_contents($base . '/index-bc-style-template-6.php');
    pc_check(strpos($t6, 'bc_theme_palette(') !== false && strpos($t6, 'primary_color') !== false, "$ed template-6 uses bc_theme_palette");

    foreach ([2, 3] as $tid) {
        $tpl = file_get_contents($base . '/index-bc-style-template-' . $tid . '.php');
        pc_check(strpos($tpl, 'vst.primary_color') !== false, "$ed template-$tid queries vst.primary_color");
        pc_check(strpos($tpl, 'ORDER BY vst.id DESC LIMIT 1') !== false, "$ed template-$tid orders by vst.id DESC LIMIT 1");
        pc_check(strpos($tpl, 'bc_theme_palette(') !== false && strpos($tpl, '--primary:') !== false, "$ed template-$tid wires bc_theme_palette CSS vars");
    }

    // Feature & admin pages: no hardcoded #6366f1 or #287bff fallback accents left un-tokenized
    foreach (['bc-admin/AIMarketing.php', 'bc-admin/AISettings.php', 'web/ai-budgeting.php', 'web/CryptoHub.php', 'web/GiftCard.php'] as $rel) {
        $src = file_get_contents($base . '/' . $rel);
        pc_check(strpos($src, '#6366f1') === false, "$ed $rel removes hardcoded #6366f1");
        pc_check(strpos($src, '#287bff') === false, "$ed $rel removes hardcoded #287bff");
        pc_check(
            strpos($src, '--brand') !== false || strpos($src, 'bc_app_colour') !== false || strpos($src, 'bc_adm_colour') !== false,
            "$ed $rel uses --brand tokens or colour helper"
        );
    }
}

// SAAS-specific: spadmin config + admin-config RenewSubscription ordering
$spcfg = file_get_contents($root . '/DGV7.0-SAAS/func/bc-spadmin-config.php');
pc_check(strpos($spcfg, 'spadmin_primary_color') !== false, 'SAAS bc-spadmin-config resolves spadmin_primary_color');

$saasAdminCfg = file_get_contents($root . '/DGV7.0-SAAS/func/bc-admin-config.php');
$posColor = strpos($saasAdminCfg, 'vendor_primary_color');
$posRenew = strpos($saasAdminCfg, 'RenewSubscription.php');
pc_check($posColor !== false && $posRenew !== false && $posColor < $posRenew, 'SAAS bc-admin-config loads vendor_primary_color before RenewSubscription early return');

// NON-SAAS templates 4 & 5 + API endpoints
foreach ([4, 5] as $tid) {
    $tpl = file_get_contents($root . '/DGV7.0-NON-SAAS/index-bc-style-template-' . $tid . '.php');
    pc_check(strpos($tpl, 'vst.primary_color') !== false, "NON-SAAS template-$tid queries vst.primary_color");
    pc_check(strpos($tpl, 'ORDER BY vst.id DESC LIMIT 1') !== false, "NON-SAAS template-$tid orders by vst.id DESC LIMIT 1");
    pc_check(strpos($tpl, 'bc_theme_palette(') !== false && strpos($tpl, '--primary:') !== false, "NON-SAAS template-$tid wires bc_theme_palette CSS vars");
}
$nonSaasT5 = file_get_contents($root . '/DGV7.0-NON-SAAS/index-bc-style-template-5.php');
pc_check(strpos($nonSaasT5, 'rgba(79, 70, 229') === false && strpos($nonSaasT5, 'rgba(99, 102, 241') === false, 'NON-SAAS template-5 replaces hardcoded indigo rgba with --primary-rgb');

$nonSaasThemeApi = file_get_contents($root . '/DGV7.0-NON-SAAS/web/api/theme.php');
pc_check(strpos($nonSaasThemeApi, 'ORDER BY v.id ASC LIMIT 1') !== false, 'NON-SAAS web/api/theme.php has active-vendor fallback');

$nonSaasGuestApi = file_get_contents($root . '/DGV7.0-NON-SAAS/web/guest-api/site-info.php');
pc_check(strpos($nonSaasGuestApi, 'bc_theme_palette(') !== false && strpos($nonSaasGuestApi, '"primary_color"') !== false && strpos($nonSaasGuestApi, '"theme"') !== false, 'NON-SAAS web/guest-api/site-info.php includes primary_color and theme palette');

// 3) Functional tests for Plate Auth / App / Admin colour helpers
require_once $root . '/DGV7.0-SAAS/func/bc-plate-auth.php';
require_once $root . '/DGV7.0-SAAS/func/bc-plate-app.php';
require_once $root . '/DGV7.0-SAAS/func/bc-plate-admin.php';

$GLOBALS['vendor_primary_color'] = '#E11D48';
$GLOBALS['spadmin_primary_color'] = '#7C3AED';

$_SERVER['PHP_SELF'] = '/web/Login.php';
pc_check(bc_plate_auth_colour() === '#E11D48', 'bc_plate_auth_colour uses vendor_primary_color on user pages');
pc_check(bc_app_colour() === '#E11D48', 'bc_app_colour uses vendor_primary_color');

$_SERVER['PHP_SELF'] = '/bc-admin/Dashboard.php';
pc_check(bc_adm_colour() === '#E11D48', 'bc_adm_colour uses vendor_primary_color in bc-admin');
pc_check(bc_plate_auth_colour() === '#E11D48', 'bc_plate_auth_colour uses vendor_primary_color in bc-admin');

$_SERVER['PHP_SELF'] = '/bc-spadmin/Login.php';
pc_check(bc_adm_colour() === '#7C3AED', 'bc_adm_colour uses spadmin_primary_color in bc-spadmin');
pc_check(bc_plate_auth_colour() === '#7C3AED', 'bc_plate_auth_colour uses spadmin_primary_color in bc-spadmin');

$GLOBALS['vendor_primary_color'] = 'not-a-hex';
pc_check(bc_app_colour(['primary_color' => '#059669']) === '#059669', 'bc_app_colour rejects invalid global and falls back to details');
pc_check(bc_app_colour(['primary_color' => 'javascript:1']) === '#0f766e', 'bc_app_colour rejects XSS/invalid color');

// 4) Functional test for email-design.php when sas_site_details lacks primary_color
if (!class_exists('mysqli', false)) {
    class mysqli {}
}
$GLOBALS['connection_server'] = new mysqli();
$GLOBALS['vendor_id'] = 12;
if (!function_exists('mysqli_query')) {
    function mysqli_query($db, $sql) {
        // Simulate real DB schema where sas_site_details has NO primary_color column
        if (strpos($sql, 'SELECT site_title, primary_color, apk_download_url') !== false) {
            return false;
        }
        if (strpos($sql, 'SELECT site_title, apk_download_url FROM sas_site_details') !== false) {
            return ['site_title' => 'Ruby Telecom', 'apk_download_url' => ''];
        }
        if (strpos($sql, 'FROM sas_vendor_style_templates') !== false) {
            return ['primary_color' => '#DC2626'];
        }
        if (strpos($sql, 'SELECT email, website_url') !== false) {
            return ['email' => 'care@ruby.example.test', 'website_url' => 'https://ruby.example.test'];
        }
        return false;
    }
    function mysqli_fetch_assoc($row) { return $row; }
}
require_once $root . '/DGV7.0-SAAS/func/email-design.php';
$emailBrand = bc_email_brand();
pc_check($emailBrand['site_name'] === 'Ruby Telecom', 'email-design falls back cleanly when sas_site_details lacks primary_color');
pc_check(strtoupper($emailBrand['color']) === '#DC2626', 'email-design reads primary_color from sas_vendor_style_templates');
$emailHtml = mailDesignTemplate('Test Notice', 'Hello', [], true);
pc_check(stripos($emailHtml, '#DC2626') !== false, 'mailDesignTemplate renders vendor template primary_color');

echo "$n primary_color propagation checks passed\n";

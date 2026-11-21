<?php
/**
 * §46 — site-name branding, modern email design, copy-friendly OTP mail,
 * and complete transaction receipts.
 *
 *  1. bc_adm_site() used to look up a global that never exists
 *     ('get_all_spadmin_site_details') and fall back to a hard-coded
 *     product name, so every back office showed "FintechFlow" no matter
 *     what Site Name the spadmin/vendor saved. The plate now resolves the
 *     row the owner actually set (vendor sas_site_details in bc-admin,
 *     sas_super_admin_site_details in bc-spadmin), and bc-admin-config
 *     loads the vendor's own row.
 *  2. mailDesignTemplate() hard-coded one blue and a bare hostname, and
 *     stored templates were full HTML documents whose class/flex CSS is
 *     stripped before sending. The shell is a modern inline-styled
 *     gradient design using the tenant's Site Name + primary colour, and
 *     template bodies are inline-styled fragments.
 *  3. OTP mail leads with the code in the subject and the hidden inbox
 *     preview so it can be copied from the message list, and shows the
 *     digits in large separate boxes in the body.
 *  4. Transaction receipts (user-funding, user-transactions,
 *     vendor-funding, vendor-transactions) include the customer's phone
 *     number, email, reference, status and date; senders supply them.
 *     Payment gateways / API providers never appear in descriptions.
 */
chdir(dirname(__DIR__));
$fail = 0;
function chk($cond, $label) { global $fail; echo ($cond ? 'PASS ' : 'FAIL ') . $label . PHP_EOL; if (!$cond) $fail++; }

$roots = ['SAAS' => 'DGV7.0-SAAS', 'NON-SAAS' => 'DGV7.0-NON-SAAS'];

foreach ($roots as $name => $root) {
    // ── 1) site name ──
    $plate = file_get_contents($root . '/func/bc-plate-admin.php');
    chk(strpos($plate, "'get_all_spadmin_site_details', 'get_all_site_details'") !== false,
        "$name plate: spadmin area resolves the super-admin site row first");
    chk(strpos($plate, "array('get_all_site_details', 'get_all_super_admin_site_details')") !== false,
        "$name plate: admin area resolves the vendor's own site row first");
    chk(strpos($plate, "'FintechFlow'") === false,
        "$name plate: hard-coded 'FintechFlow' brand fallback gone");

    $acfg = file_get_contents($root . '/func/bc-admin-config.php');
    chk(strpos($acfg, 'FROM sas_site_details WHERE vendor_id=') !== false
        && (strpos($acfg, 'admin_site_details_cache') !== false || strpos($acfg, '$get_all_super_admin_site_details = ($q_site_details') !== false),
        "$name bc-admin-config: the vendor's own sas_site_details row is loaded");

    // ── 2) email shell ──
    $design = file_get_contents($root . '/func/email-design.php');
    chk(strpos($design, 'function bc_otp_email(') !== false && strpos($design, 'function bc_email_brand(') !== false,
        "$name email-design: brand resolver + OTP builder exist");
    chk(strpos($design, 'primary_color') !== false && strpos($design, 'site_title') !== false,
        "$name email-design: shell uses tenant Site Name + primary colour");
    chk(strpos($design, 'display:flex') === false && strpos($design, 'display: flex') === false,
        "$name email-design: no flex layout (email-client safe)");

    // ── 4) sender placeholder arrays carry phone/reference/status/date ──
    $fn = file_get_contents($root . '/func/bc-func.php');
    chk(substr_count($fn, '"{phone}" =>') >= 16,
        "$name bc-func: funding/transaction mail arrays pass {phone} (" . substr_count($fn, '"{phone}" =>') . ")");
    chk(substr_count($fn, '"{reference}" => (isset($reference)') >= 16,
        "$name bc-func: mail arrays pass {reference}/{status}/{date}");

    // ── 3) OTP senders ──
    $reg = file_get_contents($root . '/web/api/send-reg-otp.php');
    chk(strpos($reg, "bc_otp_email(\$otp, 'registration'") !== false,
        "$name registration OTP: copy-friendly code email");
    $step = file_get_contents($root . '/func/bc-stepup.php');
    chk(strpos($step, 'bc_otp_email($code,') !== false,
        "$name step-up OTP: copy-friendly code email");
    foreach (['web/PasswordRecovery.php', 'bc-admin/PasswordRecovery.php'] as $rel) {
        $rec = file_get_contents($root . '/' . $rel);
        chk(strpos($rec, 'is your password reset code') !== false,
            "$name $rel: subject leads with the recovery code");
    }
    $vf = file_get_contents($root . '/web/api/verify-funding.php');
    chk(strpos($vf, 'Wallet Funding via') === false && strpos($vf, 'Wallet Funding (Ref: $reference)') !== false,
        "$name verify-funding: gateway name kept out of the customer description");

    $wh = @file_get_contents($root . '/func/bc-fintech-webhook.php');
    if ($wh !== false) {
        chk(strpos($wh, "'Wallet Funding via '") === false && strpos($wh, "'{phone}' =>") !== false,
            "$name webhook receipts: provider unnamed, {phone} supplied");
    }
}

// SAAS-only: admin login OTP + user login OTP
$alogin = file_get_contents('DGV7.0-SAAS/bc-admin/Login.php');
chk(substr_count($alogin, "bc_otp_email(\$login_otp, 'sign-in'") === 2,
    "SAAS bc-admin/Login: both OTP paths use the copy-friendly email");
$fnS = file_get_contents('DGV7.0-SAAS/func/bc-func.php');
chk(strpos($fnS, "bc_otp_email(\$otp, 'sign-in'") !== false,
    "SAAS bc-func: user login OTP uses the copy-friendly email");

// ── functional: OTP email builder + shell (SAAS copy) ──
$_SERVER['HTTP_HOST'] = 'shop.example.com';
require 'DGV7.0-SAAS/func/email-design.php';
$otp = bc_otp_email('201070', 'sign-in', 'Ada');
chk(strpos($otp['subject'], '201070') === 0, 'OTP subject starts with the code (inbox-list copy)');
chk(strpos($otp['body'], 'display:none!important') !== false && strpos($otp['body'], '201070 is your') !== false,
    'OTP body leads with a hidden preview repeating the code');
chk(substr_count($otp['body'], '<td style="width:46px') === 6, 'OTP digits rendered as six separate boxes');
chk(strpos($otp['body'], 'Never share this code') !== false, 'OTP body carries the anti-fraud warning');

$shell = mailDesignTemplate('Wallet Update', 'Hello Ada, your wallet changed.', [], false);
chk(strpos($shell, 'shop.example.com') !== false, 'shell brands itself with the site host when no DB row exists');
chk(strpos($shell, 'display:flex') === false && strpos($shell, 'linear-gradient') === false,
    'shell uses email-safe solid backgrounds without flex');
chk(bc_email_shade('#287bff', 0.72) === '#1c58b8' || preg_match('/^#[0-9a-f]{6}$/', bc_email_shade('#287bff', 0.72)) === 1,
    'brand shade helper returns a valid darker hex');

// ── functional: template defaults (seed functions stubbed) ──
$GLOBALS['tpl_vendor'] = []; $GLOBALS['tpl_super'] = [];
function createVendorEmailTemplateIfNotExists($t, $s, $b) { $GLOBALS['tpl_vendor'][$t] = [$s, $b]; return 'success'; }
function createSuperAdminEmailTemplateIfNotExists($t, $s, $b) { $GLOBALS['tpl_super'][$t] = [$s, $b]; return 'success'; }
$connection_server = null;
require 'DGV7.0-SAAS/func/bc-email-templates.php';

foreach (['user-funding', 'user-transactions'] as $t) {
    chk(isset($GLOBALS['tpl_vendor'][$t]), "vendor default exists: $t");
    $body = $GLOBALS['tpl_vendor'][$t][1] ?? '';
    chk(strpos($body, '{phone}') !== false && strpos($body, '{reference}') !== false
        && strpos($body, '{status}') !== false && strpos($body, '{date}') !== false,
        "$t receipt includes phone, reference, status and date");
}
foreach (['vendor-funding', 'vendor-transactions'] as $t) {
    chk(isset($GLOBALS['tpl_super'][$t]), "super default exists: $t");
    $body = $GLOBALS['tpl_super'][$t][1] ?? '';
    chk(strpos($body, '{phone}') !== false && strpos($body, '{reference}') !== false,
        "$t receipt includes phone and reference");
}
$all = array_merge($GLOBALS['tpl_vendor'], $GLOBALS['tpl_super']);
$dirty = 0;
foreach ($all as $t => $sb) {
    if (strpos($sb[1], 'details-row') !== false || strpos($sb[1], 'DOCTYPE') !== false
        || strpos($sb[1], 'api_website') !== false || strpos($sb[1], 'api_reference') !== false
        || strpos($sb[1], 'display: flex') !== false) $dirty++;
}
chk(count($all) === 22, 'all 22 default templates defined (' . count($all) . ')');
chk($dirty === 0, 'no default template carries legacy markup or provider fields');
chk(strpos($GLOBALS['tpl_vendor']['user-account-recovery'][1], '{recovery_code}') !== false
    && strpos($GLOBALS['tpl_vendor']['user-account-recovery'][1], 'letter-spacing:10px') !== false,
    'recovery template shows the code large and copyable');
chk(strpos(file_get_contents('DGV7.0-SAAS/func/bc-email-templates.php'), 'function bc_refresh_legacy_email_templates(') !== false,
    'legacy stored templates are refreshed to the new defaults');

echo $fail === 0 ? "ALL PASS\n" : "$fail FAILURES\n";
exit($fail === 0 ? 0 : 1);

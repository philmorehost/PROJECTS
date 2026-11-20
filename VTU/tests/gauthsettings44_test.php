<?php
/**
 * §44 — two fixes:
 *
 * 1) Forced-password-change redirect loop (ERR_TOO_MANY_REDIRECTS on
 *    /web/AccountSettings.php?force_password=1). The §29 confinement sent
 *    users to AccountSettings, but the security-question gate sent them to
 *    SecurityQuest — which §29 bounced straight back. The minimum-funding
 *    gate (Fund.php) and, in bc-admin, the subscription-expiry / overdue-
 *    billing gates (RenewSubscription / Dashboard) fought the confinement
 *    the same way. Confinement targets must be reachable and §29 must win
 *    while the flag is on.
 *
 * 2) Google Authenticator is now required to SAVE bc-admin / bc-spadmin
 *    Account Settings changes ("sudo mode"): enrol once, enter a fresh code
 *    to open a 10-minute window; a hijacked session alone cannot save.
 */
chdir(dirname(__DIR__));
$fail = 0;
function chk($cond, $label) { global $fail; echo ($cond ? 'PASS ' : 'FAIL ') . $label . PHP_EOL; if (!$cond) $fail++; }

$roots = ['SAAS' => 'DGV7.0-SAAS', 'NON-SAAS' => 'DGV7.0-NON-SAAS'];

foreach ($roots as $name => $root) {
    /* ---- 1) the loop fix, web side ---- */
    $cfg = file_get_contents($root . '/func/bc-config.php');
    chk(strpos($cfg, '$sec29_confined = function_exists') !== false, "$name web: confinement computed once");
    chk(strpos($cfg, "array('AccountSettings.php', 'SecurityQuest.php', 'logout.php')") !== false,
        "$name web: confinement allows AccountSettings + SecurityQuest + logout (no gate fight)");
    chk(strpos($cfg, 'empty($sec29_confined) && (!isset($_COOKIE["security_answer"])') !== false,
        "$name web: security-question gate suppressed while confined");
    chk(strpos($cfg, '$config_user_total_funding < $min_funding && empty($sec29_confined)') !== false,
        "$name web: minimum-funding gate suppressed while confined");
    if ($name === 'SAAS') {
        chk(strpos($cfg, 'if ($is_suspended && empty($sec29_confined))') !== false,
            "$name web: suspension gate suppressed while confined");
    }

    /* ---- 1) the loop fix, admin side ---- */
    $acfg = file_get_contents($root . '/func/bc-admin-config.php');
    chk(strpos($acfg, '$sec29_admin_confined = function_exists') !== false, "$name admin: confinement computed once");
    chk(strpos($acfg, "array('AccountSettings.php', 'admin-logout.php', 'logout.php')") !== false,
        "$name admin: confinement allows AccountSettings + logout");
    chk(strpos($acfg, '$bc_admin_confined = !empty($sec29_admin_confined) || !empty($sec33_admin_confined);') !== false,
        "$name admin: password + forced-TOTP confinement combined");
    if ($name === 'SAAS') {
        chk(strpos($acfg, 'if ($is_expired && !isset($_SESSION["spadmin_vendor_auth"]) && empty($bc_admin_confined))') !== false,
            "$name admin: subscription-expiry gate suppressed while confined");
        chk(strpos($acfg, 'empty($bc_admin_confined) && !in_array(explode("?", trim($_SERVER["REQUEST_URI"]))[0], array("/bc-admin/Dashboard.php")))') !== false,
            "$name admin: overdue-billing gate suppressed while confined");
    }

    /* ---- 2) Google Auth settings gate ---- */
    $step = file_get_contents($root . '/func/bc-stepup.php');
    foreach (['bc_gauth_enrolled', 'bc_gauth_unlocked_until', 'bc_gauth_verify_unlock', 'bc_gauth_settings_gate', 'bc_gauth_settings_banner'] as $fn) {
        chk(strpos($step, "function $fn(") !== false, "$name: $fn exists");
    }
    chk(strpos($step, "time() + 600") !== false, "$name: unlock window is 10 minutes");
    chk(strpos($step, "bc_totp_verify(\$secret, (string)\$code, (int)(\$actor_row['totp_last_step'] ?? 0))") !== false,
        "$name: unlock verifies a fresh TOTP code with replay protection");

    $adm = file_get_contents($root . '/bc-admin/AccountSettings.php');
    chk(strpos($adm, "bc_gauth_settings_gate('bc_admin_settings', 'sas_vendors'") !== false, "$name bc-admin AccountSettings: gate wired to vendor identity");
    chk(strpos($adm, "bc_gauth_settings_banner('bc_admin_settings'") !== false, "$name bc-admin AccountSettings: lock banner shown");
    chk(strpos($adm, "'totp-confirm', 'totp-disable', 'change-password'") !== false,
        "$name bc-admin AccountSettings: enrolment + password change stay reachable while locked (no lockout)");
}

$sp = file_get_contents('DGV7.0-SAAS/bc-spadmin/AccountSettings.php');
chk(strpos($sp, "bc_gauth_settings_gate('spadmin_settings', 'sas_super_admin'") !== false, "SAAS bc-spadmin AccountSettings: gate wired to super-admin identity");
chk(strpos($sp, "bc_gauth_settings_banner('spadmin_settings'") !== false, "SAAS bc-spadmin AccountSettings: lock banner shown");
chk(strpos($sp, "'totp-confirm', 'totp-disable', 'change-password'") !== false, "SAAS bc-spadmin AccountSettings: enrolment + password change stay reachable while locked");

/* gate + step-up module must stay byte-identical across editions */
chk(file_get_contents('DGV7.0-SAAS/func/bc-stepup.php') === file_get_contents('DGV7.0-NON-SAAS/func/bc-stepup.php'), 'bc-stepup.php identical in both editions');

/* ---- behavioural: TOTP + gate helpers actually work ---- */
require 'DGV7.0-SAAS/func/bc-stepup.php';
$secret = bc_totp_generate_secret();
$code = bc_totp_code($secret, time());
chk(bc_totp_verify($secret, $code, 0) !== false, 'behaviour: fresh authenticator code verifies');
chk(bc_totp_verify($secret, $code, (int)bc_totp_verify($secret, $code, 0)) === false, 'behaviour: the same code cannot be replayed');
chk(bc_totp_verify($secret, '000000', 0) === false, 'behaviour: wrong code rejected');
chk(bc_gauth_enrolled(['two_factor_type' => 'totp', 'totp_secret' => 'sealed']) === true, 'behaviour: enrolled actor detected');
chk(bc_gauth_enrolled(['two_factor_type' => 'none', 'totp_secret' => '']) === false, 'behaviour: unenrolled actor detected');
chk(bc_gauth_enrolled(['two_factor_type' => 'pending', 'totp_secret' => 'sealed']) === false, 'behaviour: pending enrolment does not count as enrolled');
$_SESSION['bc_gauth_unlock']['test_scope'] = time() + 600;
chk(bc_gauth_unlocked_until('test_scope') > 0, 'behaviour: open window reported');
$_SESSION['bc_gauth_unlock']['test_scope'] = time() - 5;
chk(bc_gauth_unlocked_until('test_scope') === 0, 'behaviour: expired window reported locked');
$banner = bc_gauth_settings_banner('test_scope', ['two_factor_type' => 'none', 'totp_secret' => '']);
chk(strpos($banner, 'Settings are locked') !== false && strpos($banner, 'Enrol it') !== false, 'behaviour: unenrolled banner explains enrolment is required');

echo PHP_EOL . ($fail === 0 ? 'gauthsettings44: all checks passed' : "gauthsettings44: $fail FAILURES") . PHP_EOL;
exit($fail === 0 ? 0 : 1);

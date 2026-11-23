<?php
/**
 * §38 — Google auth for the admin surfaces + NON-SAAS Portal removal.
 *
 * - NON-SAAS (standalone) must not show the SAAS Portal button in the top nav.
 * - bc-admin (both editions) and bc-spadmin get "Sign in with Google", backed by
 *   a STRICT token check (aud/iss/email_verified/exp) — verifyGoogleToken()
 *   alone only fetches Google's tokeninfo response and proves nothing.
 * - Both password-recovery pages accept a Google-verified reset: Google proves
 *   the mailbox, the emailed code is waived, a new password is still required.
 * - Google buttons render only when a Client ID is configured (per-vendor for
 *   bc-admin, platform option for bc-spadmin).
 */
chdir(dirname(__DIR__));
$fail = 0;
function chk($cond, $label) { global $fail; echo ($cond ? 'PASS ' : 'FAIL ') . $label . PHP_EOL; if (!$cond) $fail++; }

$roots = ['SAAS' => 'DGV7.0-SAAS', 'NON-SAAS' => 'DGV7.0-NON-SAAS'];

foreach ($roots as $name => $root) {
    $func   = file_get_contents($root . '/func/bc-func.php');
    $config = file_get_contents($root . '/func/bc-config.php');
    $plate  = file_get_contents($root . '/func/bc-plate-admin.php');

    /* strict validator */
    chk(strpos($func, 'function bc_google_payload_valid') !== false, "$name: bc_google_payload_valid() exists");
    chk(strpos($func, "hash_equals(trim((string)\$client_id), (string)\$payload['aud'])") !== false, "$name: validator binds token audience to the configured Client ID");
    chk(strpos($func, "accounts.google.com") !== false && strpos($func, 'email_verified') !== false, "$name: validator checks issuer + verified mailbox");
    chk(strpos($config, '"google_id" => "VARCHAR(255)"') !== false, "$name: google_id column ensured (vendors + super admin)");

    /* Portal button is SAAS-only (runtime edition gate in the shared shell) */
    chk(strpos($plate, '$bc_top_saas = function_exists("bc_is_saas_edition")') !== false, "$name: topbar resolves the edition");
    chk(strpos($plate, "if (\$area === 'admin' && \$bc_top_saas)") !== false, "$name: Portal button gated to SAAS");

    /* bc-admin login */
    $login = file_get_contents($root . '/bc-admin/Login.php');
    chk(strpos($login, 'if(isset($_POST["google_token"]))') !== false, "$name bc-admin Login: google_token handler");
    chk(strpos($login, 'bc_google_payload_valid($bc_g_payload, $bc_g_client_id)') !== false, "$name bc-admin Login: strict payload validation");
    chk(strpos($login, 'No admin account found with this Google email.') !== false, "$name bc-admin Login: unknown Google account rejected");
    chk(strpos($login, "UPDATE sas_vendors SET google_id=") !== false, "$name bc-admin Login: google_id linked on first Google sign-in");
    chk(strpos($login, 'g_id_onload') !== false && strpos($login, "\$select_vendor_table['google_client_id']") !== false, "$name bc-admin Login: GSI button only when the vendor configured a Client ID");
    chk(strpos($login, 'isIPBlocked($bc_g_ip, $bc_g_vendor_id)') !== false && strpos($login, 'isAccountLocked($bc_g_payload') !== false, "$name bc-admin Login: brute-force lockouts still apply to Google sign-in");

    /* bc-admin recovery */
    $rec = file_get_contents($root . '/bc-admin/PasswordRecovery.php');
    chk(strpos($rec, "bc_google_recovery_grant('admin',") !== false && strpos($rec, '$_SESSION["admin-recovery-google"] = 1;') === false, "$name bc-admin Recovery: scoped expiring grant replaces boolean approval");
    chk(strpos($rec, "bc_google_recovery_matches('admin',") !== false && strpos($rec, "bc_recovery_code_valid('admin',") !== false && strpos($rec, '$_SESSION["admin-recovery-code"] == $recovery_code') === false, "$name bc-admin Recovery: current identity proof or verified email challenge required");
    chk(strpos($rec, '($bc_google_verified || (!empty($recovery_code)') !== false, "$name bc-admin Recovery: code format still enforced for email resets");
    chk(strpos($rec, 'g_id_onload') !== false && strpos($rec, 'empty($_SESSION["admin-recovery-username"])') !== false, "$name bc-admin Recovery: Google reset offered on step 1 only");
    chk(strpos($rec, 'unset($_SESSION["admin-recovery-google"]);') !== false, "$name bc-admin Recovery: google flag cleared after use");
}
chk(strpos(file_get_contents('DGV7.0-SAAS/bc-admin/Login.php'), 'bc_admin_login_otp_required($bc_g_admin)') !== false,
    'SAAS bc-admin Login: Google sign-in still honours the login-OTP step');

/* ---- SAAS platform (bc-spadmin) ---- */
$spLogin = file_get_contents('DGV7.0-SAAS/bc-spadmin/Login.php');
chk(strpos($spLogin, "getSuperAdminOption('spadmin_google_client_id', '')") !== false, 'spadmin Login: platform Client ID');
chk(strpos($spLogin, 'bc_google_payload_valid($bc_g_payload, $bc_g_client_id)') !== false, 'spadmin Login: strict payload validation');
chk(strpos($spLogin, "UPDATE sas_super_admin SET google_id=") !== false, 'spadmin Login: google_id linked');
chk(strpos($spLogin, 'g_id_onload') !== false, 'spadmin Login: GSI button rendered');

$spRec = file_get_contents('DGV7.0-SAAS/bc-spadmin/PasswordRecovery.php');
chk(strpos($spRec, "bc_google_recovery_grant('spadmin',0,") !== false && strpos($spRec, '$_SESSION["spadmin-recovery-google"] = 1;') === false, 'spadmin Recovery: scoped grant replaces boolean approval');
chk(strpos($spRec, "bc_google_recovery_matches('spadmin',0,") !== false && strpos($spRec, "bc_recovery_code_valid('spadmin',0,") !== false && strpos($spRec, '$_SESSION["spadmin-recovery-code"] == $recovery_code') === false, 'spadmin Recovery: current identity proof or verified email challenge required');
chk(strpos($spRec, 'g_id_onload') !== false, 'spadmin Recovery: GSI button rendered');

$spSet = file_get_contents('DGV7.0-SAAS/bc-spadmin/AccountSettings.php');
chk(strpos($spSet, "setSuperAdminOption('spadmin_google_client_id', \$spadmin_google_client_id)") !== false, 'spadmin Settings: Client ID saved');
chk(strpos($spSet, 'name="spadmin_google_client_id"') !== false, 'spadmin Settings: Client ID field rendered');

/* The shared shell file must stay byte-identical across editions. */
chk(file_get_contents('DGV7.0-SAAS/func/bc-plate-admin.php') === file_get_contents('DGV7.0-NON-SAAS/func/bc-plate-admin.php'),
    'plate bc-plate-admin.php byte-identical across editions');

echo PHP_EOL . ($fail === 0 ? 'googleauth38: all checks passed' : "googleauth38: $fail FAILURES") . PHP_EOL;
exit($fail === 0 ? 0 : 1);

<?php
/**
 * Regression & security test for bc-admin/UserEdit.php:
 * - Edition parity across SAAS and NON-SAAS.
 * - Page is gated behind vendor-admin security unlock (bc_gauth_settings_gate /
 *   bc_action_settings_gate + bc_gauth_settings_banner + locked UI).
 * - Vendor admin can set a new 4-digit security PIN or clear the user's PIN.
 * - All POST handlers and forms require CSRF + active unlock window + demo guard.
 * - Password reset uses bc_hash_password() instead of raw MD5.
 * - Scope isolation between bc_admin_settings and bc_admin_user_edit.
 */
chdir(dirname(__DIR__));
$fail = 0;
function ue_chk($cond, $label) {
    global $fail;
    echo ($cond ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    if (!$cond) $fail++;
}

// 1. Edition parity & syntax check
$ue_saas = file_get_contents('DGV7.0-SAAS/bc-admin/UserEdit.php');
$ue_nonsaas = file_get_contents('DGV7.0-NON-SAAS/bc-admin/UserEdit.php');
ue_chk($ue_saas === $ue_nonsaas, 'bc-admin/UserEdit.php identical in both SAAS and NON-SAAS');
token_get_all($ue_saas, TOKEN_PARSE);
ue_chk(true, 'bc-admin/UserEdit.php parses cleanly');

// 2. Gating & fail-closed UI checks
ue_chk(strpos($ue_saas, "bc_gauth_settings_gate(\$user_edit_scope, 'sas_vendors'") !== false, 'UserEdit wires bc_gauth_settings_gate with sas_vendors');
ue_chk(strpos($ue_saas, "bc_gauth_settings_banner(\$user_edit_scope, \$get_logged_admin_details)") !== false, 'UserEdit renders bc_gauth_settings_banner');
ue_chk(strpos($ue_saas, "bc_gauth_unlocked_until(\$user_edit_scope) > 0") !== false, 'UserEdit computes $user_edit_unlocked fail-closed');
ue_chk(strpos($ue_saas, 'if(!$user_edit_unlocked)') !== false, 'UserEdit hides edit forms when locked');
ue_chk(strpos($ue_saas, 'AccountSettings.php#tab-security') !== false, 'UserEdit links unenrolled admins to AccountSettings authenticator setup');

foreach (['update-profile', 'reset-security-pin', 'clear-security-pin', 'change-password'] as $handler) {
    ue_chk(strpos($ue_saas, "\$user_edit_unlocked && isset(\$_POST[\"$handler\"])") !== false, "Handler $handler requires unlocked window");
}
ue_chk(substr_count($ue_saas, 'bc_validate_csrf()') >= 4, 'All 4 UserEdit POST handlers validate CSRF');
ue_chk(substr_count($ue_saas, 'bc_demo_guard_edit(') >= 4, 'All 4 UserEdit POST handlers call bc_demo_guard_edit');

// Verify every <form method="post"> in UserEdit.php includes bc_csrf_field()
preg_match_all('/<form\b[^>]*method=["\']post["\'][^>]*>(.*?)(?=<\/form>)/is', $ue_saas, $forms);
ue_chk(count($forms[0]) >= 5, 'UserEdit contains all expected POST forms (' . count($forms[0]) . ')');
foreach ($forms[1] as $idx => $body) {
    ue_chk(strpos($body, 'bc_csrf_field()') !== false, 'UserEdit POST form #' . ($idx + 1) . ' includes bc_csrf_field()');
}

// 3. Reset & Clear User PIN + Password Reset checks
ue_chk(strpos($ue_saas, "preg_match('/\\A[0-9]{4}\\z/D', \$new_pin)") !== false, 'User PIN reset enforces exact 4-digit format');
ue_chk(strpos($ue_saas, "'0000','1111'") !== false, 'User PIN reset blocks weak guessable PINs');
ue_chk(strpos($ue_saas, "hash_equals(\$new_pin, \$con_new_pin)") !== false, 'User PIN reset uses timing-safe confirmation match');
ue_chk(strpos($ue_saas, "password_hash(\$new_pin, PASSWORD_DEFAULT)") !== false, 'User PIN reset hashes security_pin with PASSWORD_DEFAULT');
ue_chk(strpos($ue_saas, "SET security_pin=?, transaction_pin=?, failed_pin_count=0, last_failed_pin=NULL WHERE vendor_id=? AND id=?") !== false, 'User PIN reset updates both security_pin and transaction_pin and clears failed counts');
ue_chk(strpos($ue_saas, "SET security_pin=NULL, transaction_pin=NULL, failed_pin_count=0, last_failed_pin=NULL WHERE vendor_id=? AND id=?") !== false, 'User PIN clear resets both security_pin and transaction_pin to NULL');
ue_chk(strpos($ue_saas, "bc_web_session_revoke_actor('user'") !== false, 'User PIN/password reset revokes active user sessions');
ue_chk(strpos($ue_saas, "'user_security_pin_reset'") !== false && strpos($ue_saas, "'user_security_pin_cleared'") !== false, 'User PIN reset/clear writes security audit events');
ue_chk(strpos($ue_saas, 'bc_hash_password($new_pass)') !== false, 'User password reset uses bc_hash_password()');

// 4. Behavioral checks on bc_gauth_settings_banner with bc_admin_user_edit scope
require_once 'DGV7.0-SAAS/func/bc-stepup.php';
$_SESSION = array('_csrf_token' => 'test-csrf-token');
if (!function_exists('bc_csrf_field')) {
    function bc_csrf_field() { return '<input type="hidden" name="_csrf_token" value="test-csrf-token">'; }
}

$adm_unenrolled = array('id' => 9, 'email' => 'vendor@example.test', 'two_factor_type' => 'none', 'totp_secret' => '', 'security_pin' => '');
$adm_enrolled = array('id' => 9, 'email' => 'vendor@example.test', 'two_factor_type' => 'totp', 'totp_secret' => 'sealed', 'security_pin' => '');

$banner_locked = bc_gauth_settings_banner('bc_admin_user_edit', $adm_unenrolled);
ue_chk(strpos($banner_locked, 'Settings are locked') !== false, 'Behaviour: locked banner shown for bc_admin_user_edit when unenrolled');

$banner_prompt = bc_gauth_settings_banner('bc_admin_user_edit', $adm_enrolled);
ue_chk(strpos($banner_prompt, 'name="gauth-unlock"') !== false && strpos($banner_prompt, 'name="gauth_code"') !== false, 'Behaviour: enrolled vendor admin gets TOTP unlock form for bc_admin_user_edit');

$_SESSION['bc_gauth_unlock']['bc_admin_user_edit'] = time() + 600;
ue_chk(bc_gauth_unlocked_until('bc_admin_user_edit') > 0, 'Behaviour: bc_admin_user_edit unlock window active');
$banner_unlocked = bc_gauth_settings_banner('bc_admin_user_edit', $adm_enrolled);
ue_chk(strpos($banner_unlocked, 'Settings unlocked') !== false && strpos($banner_unlocked, 'name="gauth-lock"') !== false, 'Behaviour: unlocked banner includes Lock now form');

// Verify bc_admin_settings unlock does NOT unlock bc_admin_user_edit
unset($_SESSION['bc_gauth_unlock']);
$_SESSION['bc_gauth_unlock']['bc_admin_settings'] = time() + 600;
ue_chk(bc_gauth_unlocked_until('bc_admin_user_edit') === 0, 'Behaviour: unlocking bc_admin_settings does not bypass bc_admin_user_edit gate');

echo PHP_EOL . ($fail === 0 ? 'admin_user_edit_pin_gate: all checks passed' : "admin_user_edit_pin_gate: $fail FAILURES") . PHP_EOL;
exit($fail === 0 ? 0 : 1);

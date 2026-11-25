<?php
/**
 * Regression & security test for bc-spadmin/VendorEdit.php:
 * - Page is gated behind super-admin security unlock (bc_gauth_settings_gate /
 *   bc_action_settings_gate + bc_gauth_settings_banner + locked UI).
 * - Super admin can set a new 4-digit security PIN or clear the vendor's PIN.
 * - All POST handlers and forms require CSRF + active unlock window.
 * - bc-action-settings-unlock.php preserves REQUEST_URI on redirect and maps
 *   spadmin_* scopes to sas_super_admin (identical across SAAS and NON-SAAS).
 */
chdir(dirname(__DIR__));
$fail = 0;
function ve_chk($cond, $label) {
    global $fail;
    echo ($cond ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    if (!$cond) $fail++;
}

// 1. Edition parity for func/bc-action-settings-unlock.php
$unlock_saas = file_get_contents('DGV7.0-SAAS/func/bc-action-settings-unlock.php');
$unlock_nonsaas = file_get_contents('DGV7.0-NON-SAAS/func/bc-action-settings-unlock.php');
ve_chk($unlock_saas === $unlock_nonsaas, 'bc-action-settings-unlock.php identical in both editions');
ve_chk(strpos($unlock_saas, "strpos((string)\$scope,'spadmin_')===0") !== false, 'bc_action_settings_banner resolves spadmin_* scopes to sas_super_admin');
ve_chk(strpos($unlock_saas, "\$_SERVER['REQUEST_URI']") !== false, 'bc_action_settings_gate preserves current REQUEST_URI on locked POST redirect');

// 2. VendorEdit.php static security & feature contract
$ve = file_get_contents('DGV7.0-SAAS/bc-spadmin/VendorEdit.php');
ve_chk(strpos($ve, "bc_gauth_settings_gate(\$vendor_edit_scope, 'sas_super_admin'") !== false, 'VendorEdit wires bc_gauth_settings_gate with sas_super_admin');
ve_chk(strpos($ve, "bc_gauth_settings_banner(\$vendor_edit_scope, \$get_logged_spadmin_details)") !== false, 'VendorEdit renders bc_gauth_settings_banner');
ve_chk(strpos($ve, "bc_gauth_unlocked_until(\$vendor_edit_scope) > 0") !== false, 'VendorEdit computes $vendor_edit_unlocked fail-closed');
ve_chk(strpos($ve, 'if(!$vendor_edit_unlocked)') !== false, 'VendorEdit hides edit forms when locked');
ve_chk(strpos($ve, 'AccountSettings.php#tab-security') !== false, 'VendorEdit links unenrolled super admins to AccountSettings authenticator setup');

foreach (['update-profile', 'update-subscription', 'update-app-orders', 'toggle-nin-card', 'update-ai-settings', 'reset-security-pin', 'clear-security-pin', 'change-password'] as $handler) {
    ve_chk(strpos($ve, "\$vendor_edit_unlocked && isset(\$_POST[\"$handler\"])") !== false, "Handler $handler requires unlocked window");
}

// Verify every <form method="post"> in VendorEdit.php includes bc_csrf_field()
preg_match_all('/<form\b[^>]*method=["\']post["\'][^>]*>(.*?)(?=<\/form>)/is', $ve, $forms);
ve_chk(count($forms[0]) >= 8, 'VendorEdit contains all expected POST forms (' . count($forms[0]) . ')');
foreach ($forms[1] as $idx => $body) {
    ve_chk(strpos($body, 'bc_csrf_field()') !== false, "VendorEdit POST form #" . ($idx + 1) . " includes bc_csrf_field()");
}

// 3. Reset & Clear Security PIN logic checks
ve_chk(strpos($ve, "preg_match('/\\A[0-9]{4}\\z/D', \$new_pin)") !== false, 'PIN reset enforces exact 4-digit format preserving leading zeros');
ve_chk(strpos($ve, "hash_equals(\$new_pin, \$con_new_pin)") !== false, 'PIN reset uses timing-safe confirmation match');
ve_chk(strpos($ve, "password_hash(\$new_pin, PASSWORD_DEFAULT)") !== false, 'PIN reset hashes with PASSWORD_DEFAULT');
ve_chk(strpos($ve, "SET security_pin=?, failed_pin_count=0, last_failed_pin=NULL WHERE id=?") !== false, 'PIN reset clears failed_pin_count and last_failed_pin');
ve_chk(strpos($ve, "SET security_pin=NULL, failed_pin_count=0, last_failed_pin=NULL WHERE id=?") !== false, 'PIN clear resets security_pin to NULL so vendor re-enrols');
ve_chk(strpos($ve, "bc_web_session_revoke_actor('admin'") !== false, 'PIN reset/clear revokes active vendor admin sessions');
ve_chk(strpos($ve, "'vendor_security_pin_reset'") !== false && strpos($ve, "'vendor_security_pin_cleared'") !== false, 'PIN reset/clear writes security audit events');

// 4. Behavioral checks on bc_action_settings_banner & bc_gauth_settings_banner with spadmin_vendor_edit scope
require_once 'DGV7.0-SAAS/func/bc-stepup.php';
$_SESSION = array('_csrf_token' => 'test-csrf-token');
if (!function_exists('bc_csrf_field')) {
    function bc_csrf_field() { return '<input type="hidden" name="_csrf_token" value="test-csrf-token">'; }
}

$sp_unenrolled = array('id' => 1, 'email' => 'sp@example.test', 'two_factor_type' => 'none', 'totp_secret' => '', 'security_pin' => '');
$sp_enrolled = array('id' => 1, 'email' => 'sp@example.test', 'two_factor_type' => 'totp', 'totp_secret' => 'sealed', 'security_pin' => '');

$banner_locked = bc_gauth_settings_banner('spadmin_vendor_edit', $sp_unenrolled);
ve_chk(strpos($banner_locked, 'Settings are locked') !== false, 'Behaviour: locked banner shown for spadmin_vendor_edit when unenrolled');

$banner_prompt = bc_gauth_settings_banner('spadmin_vendor_edit', $sp_enrolled);
ve_chk(strpos($banner_prompt, 'name="gauth-unlock"') !== false && strpos($banner_prompt, 'name="gauth_code"') !== false, 'Behaviour: enrolled super admin gets TOTP unlock form for spadmin_vendor_edit');

$_SESSION['bc_gauth_unlock']['spadmin_vendor_edit'] = time() + 600;
ve_chk(bc_gauth_unlocked_until('spadmin_vendor_edit') > 0, 'Behaviour: spadmin_vendor_edit unlock window active');
$banner_unlocked = bc_gauth_settings_banner('spadmin_vendor_edit', $sp_enrolled);
ve_chk(strpos($banner_unlocked, 'Settings unlocked') !== false && strpos($banner_unlocked, 'name="gauth-lock"') !== false, 'Behaviour: unlocked banner includes Lock now form');

// Verify spadmin_settings unlock does NOT unlock spadmin_vendor_edit
unset($_SESSION['bc_gauth_unlock']);
$_SESSION['bc_gauth_unlock']['spadmin_settings'] = time() + 600;
ve_chk(bc_gauth_unlocked_until('spadmin_vendor_edit') === 0, 'Behaviour: unlocking spadmin_settings does not bypass spadmin_vendor_edit gate');

echo PHP_EOL . ($fail === 0 ? 'vendor_edit_security_pin: all checks passed' : "vendor_edit_security_pin: $fail FAILURES") . PHP_EOL;
exit($fail === 0 ? 0 : 1);

<?php
/**
 * Regression test for bc-spadmin/AccountSettings.php Security tab:
 * - No nested <form> tags (specifically Global Security Policy <form> does not
 *   wrap bc_totp_setup_widget() or totp-disable <form>).
 * - bc_stepup_send_email_otp routes vendor_id === 0 to sendSuperAdminEmail
 *   and vendor_id > 0 to sendVendorEmail, and surfaces mail delivery failure.
 * - bc-action-policy-settings.php, bc-security-question-settings.php, and
 *   bc-action-settings-unlock.php return JSON on CSRF errors (bc_validate_csrf(true)).
 * - Shared files have SAAS/NON-SAAS parity and PHP 8.2+ null-safe phone handling.
 */
chdir(dirname(__DIR__));
$fail = 0;
function sp_chk($cond, $label) {
    global $fail;
    echo ($cond ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    if (!$cond) $fail++;
}

function sp_has_nested_forms($html) {
    // Strip PHP tags and HTML comments so only HTML <form> tags are checked, then track depth.
    $clean = preg_replace('/<\?(?:php|=).*?\?>/is', '', $html);
    $clean = preg_replace('/<!--.*?-->/s', '', $clean);
    if (!preg_match_all('/<\/?form\b[^>]*>/i', $clean, $matches)) return false;
    $depth = 0;
    foreach ($matches[0] as $tag) {
        if (stripos($tag, '</form') === 0) {
            $depth = max(0, $depth - 1);
        } else {
            if ($depth > 0) return true;
            $depth++;
        }
    }
    return false;
}

// 1. No nested <form> tags in AccountSettings.php across spadmin, admin, and web
foreach ([
    'DGV7.0-SAAS/bc-spadmin/AccountSettings.php',
    'DGV7.0-SAAS/bc-admin/AccountSettings.php',
    'DGV7.0-SAAS/web/AccountSettings.php',
    'DGV7.0-NON-SAAS/bc-admin/AccountSettings.php',
    'DGV7.0-NON-SAAS/web/AccountSettings.php',
] as $rel) {
    $src = file_get_contents($rel);
    sp_chk($src !== false, "Read $rel");
    sp_chk(!sp_has_nested_forms($src), "$rel has no nested <form> tags");
}

// Verify bc-spadmin/AccountSettings.php places My Super Admin Authenticator App (2FA) after </form> of update-security
$sp_as = file_get_contents('DGV7.0-SAAS/bc-spadmin/AccountSettings.php');
token_get_all($sp_as, TOKEN_PARSE);
sp_chk(true, 'DGV7.0-SAAS/bc-spadmin/AccountSettings.php parses cleanly');
$pos_btn = strpos($sp_as, 'name="update-security"');
$pos_close = $pos_btn !== false ? strpos($sp_as, '</form>', $pos_btn) : false;
$pos_totp = strpos($sp_as, 'My Super Admin Authenticator App (2FA)');
$pos_widget = strpos($sp_as, 'bc_totp_setup_widget();');
sp_chk($pos_btn !== false && $pos_close !== false && $pos_totp !== false && $pos_totp > $pos_close, 'bc-spadmin/AccountSettings.php places Authenticator card after Global Security Policy </form>');
sp_chk($pos_widget !== false && $pos_widget > $pos_close, 'bc-spadmin/AccountSettings.php places bc_totp_setup_widget() outside Global Security Policy <form>');

// 2. Edition parity and JSON CSRF handling on action-policy, security-question, unlock, stepup
foreach ([
    'func/bc-stepup.php',
    'func/bc-action-policy-settings.php',
    'func/bc-security-question-settings.php',
    'func/bc-action-settings-unlock.php',
] as $rel) {
    $saas = file_get_contents('DGV7.0-SAAS/' . $rel);
    $nonsaas = file_get_contents('DGV7.0-NON-SAAS/' . $rel);
    sp_chk($saas === $nonsaas, "$rel identical across SAAS and NON-SAAS");
    token_get_all($saas, TOKEN_PARSE);
    sp_chk(true, "$rel parses cleanly");
}

$aps = file_get_contents('DGV7.0-SAAS/func/bc-action-policy-settings.php');
sp_chk(strpos($aps, 'bc_validate_csrf(true)') !== false, 'bc-action-policy-settings.php uses bc_validate_csrf(true)');
sp_chk(strpos($aps, "header('Content-Type: application/json; charset=UTF-8')") < strpos($aps, 'bc_validate_csrf(true)'), 'bc-action-policy-settings.php sends JSON header before CSRF validation');
sp_chk(strpos($aps, 'autocomplete="one-time-code"') !== false, 'bc-action-policy-settings.php sets autocomplete="one-time-code" on otp_code');

$sqs = file_get_contents('DGV7.0-SAAS/func/bc-security-question-settings.php');
sp_chk(strpos($sqs, 'bc_validate_csrf();') !== false, 'bc-security-question-settings.php preserves bc_validate_csrf() contract');
sp_chk(strpos($sqs, "header('Content-Type: application/json; charset=UTF-8')") < strpos($sqs, 'bc_validate_csrf();'), 'bc-security-question-settings.php sends JSON header before CSRF validation');
sp_chk(strpos($sqs, 'autocomplete="one-time-code"') !== false, 'bc-security-question-settings.php sets autocomplete="one-time-code" on otp_code');

foreach (['DGV7.0-SAAS/func/bc-security.php', 'DGV7.0-NON-SAAS/func/bc-security.php'] as $sec_path) {
    $sec = file_get_contents($sec_path);
    token_get_all($sec, TOKEN_PARSE);
    sp_chk(strpos($sec, 'HTTP_X_REQUESTED_WITH') !== false, "$sec_path returns JSON for XMLHttpRequest CSRF failures and parses cleanly");
}

$asu = file_get_contents('DGV7.0-SAAS/func/bc-action-settings-unlock.php');
sp_chk(substr_count($asu, 'bc_validate_csrf(true)') >= 2, 'bc-action-settings-unlock.php uses bc_validate_csrf(true) for send_settings_code and gauth-unlock');

// 3. Null-safe phone handling in bc-func.php for both editions
foreach (['DGV7.0-SAAS/func/bc-func.php', 'DGV7.0-NON-SAAS/func/bc-func.php'] as $fn_path) {
    $fn = file_get_contents($fn_path);
    token_get_all($fn, TOKEN_PARSE);
    sp_chk(substr_count($fn, '$logged_account_details["phone_number"] ?? \'\'') >= 2, "$fn_path guards phone_number against null in sendVendorEmail and sendSuperAdminEmail and parses cleanly");
}

// 4. Behavioral test of bc_stepup_send_email_otp
$GLOBALS['sp_mail_calls'] = [];
$GLOBALS['sp_mail_ret'] = true;
function bc_issue_otp($purpose, $identity, $vid, $meta = [], $ttl = 600) {
    return '482913';
}
function bc_otp_email($code, $label) {
    return ['subject' => 'Verification Code — ' . $label, 'body' => 'Code: ' . $code];
}
function sendSuperAdminEmail($email, $subject, $body) {
    $GLOBALS['sp_mail_calls'][] = ['superadmin', $email, $subject, $body];
    return $GLOBALS['sp_mail_ret'];
}
function sendVendorEmail($email, $subject, $body) {
    $GLOBALS['sp_mail_calls'][] = ['vendor', $email, $subject, $body];
    return $GLOBALS['sp_mail_ret'];
}

require_once 'DGV7.0-SAAS/func/bc-stepup.php';

// Super admin ($vendor_id === 0) must route to sendSuperAdminEmail
$GLOBALS['sp_mail_calls'] = [];
$GLOBALS['sp_mail_ret'] = true;
$err = bc_stepup_send_email_otp(0, 'sp@example.test', 'sp@example.test', 'action_channel_policy', 'Change action verification policy');
sp_chk($err === '', 'bc_stepup_send_email_otp(0) returns empty error when sendSuperAdminEmail succeeds');
sp_chk(count($GLOBALS['sp_mail_calls']) === 1 && $GLOBALS['sp_mail_calls'][0][0] === 'superadmin', 'bc_stepup_send_email_otp(0) uses sendSuperAdminEmail, not sendVendorEmail');

// Vendor ($vendor_id > 0) must route to sendVendorEmail
$GLOBALS['sp_mail_calls'] = [];
$err = bc_stepup_send_email_otp(12, 'vendor@example.test', 'vendor@example.test', 'action_channel_policy', 'Change action verification policy');
sp_chk($err === '', 'bc_stepup_send_email_otp(12) returns empty error when sendVendorEmail succeeds');
sp_chk(count($GLOBALS['sp_mail_calls']) === 1 && $GLOBALS['sp_mail_calls'][0][0] === 'vendor', 'bc_stepup_send_email_otp(12) uses sendVendorEmail');

// Mail failure must be surfaced rather than returning empty string
$GLOBALS['sp_mail_ret'] = false;
$err = bc_stepup_send_email_otp(0, 'sp@example.test', 'sp@example.test', 'action_channel_policy', 'Change action verification policy');
sp_chk($err !== '', 'bc_stepup_send_email_otp surfaces failure when email sender returns false');

// Invalid email must be rejected
$err = bc_stepup_send_email_otp(0, 'sp', 'not-an-email', 'action_channel_policy', 'Change action verification policy');
sp_chk($err !== '', 'bc_stepup_send_email_otp rejects invalid email address');

// 5. PHP 8.2+ / 8.4 dynamic property & JSON purity checks across SAAS and NON-SAAS
foreach (['DGV7.0-SAAS', 'DGV7.0-NON-SAAS'] as $ed) {
    $mailer_src = file_get_contents($ed . '/func/bc-mailer.php');
    token_get_all($mailer_src, TOKEN_PARSE);
    sp_chk(strpos($mailer_src, '$smtpMAIL->Timelimit') === false, "$ed/func/bc-mailer.php does not set undeclared PHPMailer::\$Timelimit dynamic property");
    sp_chk(strpos($mailer_src, '$smtpMAIL->getSMTPInstance()->Timelimit') !== false, "$ed/func/bc-mailer.php configures Timelimit on SMTP instance");

    $conn_src = file_get_contents($ed . '/func/bc-connect.php');
    token_get_all($conn_src, TOKEN_PARSE);
    sp_chk(strpos($conn_src, 'HTTP_X_REQUESTED_WITH') !== false && strpos($conn_src, "ini_set('display_errors', '0')") !== false, "$ed/func/bc-connect.php suppresses display_errors on XMLHttpRequest/JSON requests");

    $compat_src = file_get_contents($ed . '/func/bc-php-compat.php');
    token_get_all($compat_src, TOKEN_PARSE);
    sp_chk(strpos($compat_src, 'E_STRICT') === false, "$ed/func/bc-php-compat.php avoids deprecated E_STRICT constant");

    foreach (['bc-totp-setup.php', 'bc-action-policy-settings.php', 'bc-security-question-settings.php', 'bc-action-settings-unlock.php'] as $json_handler) {
        $jh_src = file_get_contents($ed . '/func/' . $json_handler);
        sp_chk(strpos($jh_src, "ini_set('display_errors', '0')") !== false, "$ed/func/$json_handler suppresses display_errors for JSON responses");
    }
}

// Verify PHPMailer configuration matching bc_deliver_prepared_mail emits zero PHP 8.x warnings/deprecations
require_once 'DGV7.0-SAAS/func/bc-mailer.php';
$captured_php_issues = [];
set_error_handler(function ($errno, $errstr, $errfile, $errline) use (&$captured_php_issues) {
    $captured_php_issues[] = "$errno: $errstr ($errfile:$errline)";
    return true;
}, E_ALL);
$pm = new PHPMailer\PHPMailer\PHPMailer(true);
$pm->isSMTP();
$pm->Host = 'mail.example.com';
$pm->SMTPAuth = true;
$pm->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
$pm->Port = 465;
$pm->CharSet = 'UTF-8';
$pm->Timeout = 5;
$pm->getSMTPInstance()->Timelimit = 8;
$pm->Username = 'noreply@example.com';
$pm->Password = 'secret';
$pm->setFrom('noreply@example.com', 'Platform');
$pm->addAddress('spadmin@example.com');
$pm->addReplyTo('noreply@example.com');
$pm->IsHTML(true);
$pm->Encoding = 'base64';
$pm->Subject = '123456 is your Set up Google Authenticator code';
$pm->Body = '<p>Code 123456</p>';
$pm->AltBody = 'Code 123456';
$pm->preSend();
restore_error_handler();
sp_chk($captured_php_issues === [], 'PHPMailer configuration matching bc_deliver_prepared_mail emits zero PHP warnings/deprecations: ' . implode('; ', $captured_php_issues));

echo PHP_EOL . ($fail === 0 ? 'spadmin_account_settings_totp_otp: all checks passed' : "spadmin_account_settings_totp_otp: $fail FAILURES") . PHP_EOL;
exit($fail === 0 ? 0 : 1);

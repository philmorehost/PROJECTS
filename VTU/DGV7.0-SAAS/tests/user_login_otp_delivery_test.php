<?php
/** Run with php -n tests/user_login_otp_delivery_test.php. */
$GLOBALS['issued'] = '123456';
$GLOBALS['sent'] = true;
$GLOBALS['mail_calls'] = 0;
function bc_issue_otp($purpose, $username, $vendor_id, $meta, $ttl) {
    if ($purpose !== 'user_login' || $username !== 'alice' || $vendor_id !== 7 || $ttl !== 600) throw new Exception('Incorrect OTP scope');
    return $GLOBALS['issued'];
}
function sendVendorEmail($email, $subject, $body) {
    $GLOBALS['mail_calls']++;
    return $GLOBALS['sent'];
}
$src = file_get_contents(dirname(__DIR__) . '/func/bc-func.php');
$start = strpos($src, 'function bc_send_user_login_otp(');
$end = strpos($src, 'function seedVendorBlog(', $start);
if ($start === false || $end === false) exit(1);
eval(substr($src, $start, $end - $start));
$user = ['username' => 'alice', 'email' => 'alice@example.org', 'firstname' => 'Alice'];
function check_delivery($label, $actual, $expected) {
    if ($actual !== $expected) { echo "FAIL $label\n"; exit(1); }
    echo "ok $label\n";
}
check_delivery('code is issued and delivered', bc_send_user_login_otp($user, 7), '123456');
$GLOBALS['sent'] = false;
check_delivery('mail failure is reported', bc_send_user_login_otp($user, 7), '');
$GLOBALS['issued'] = '';
$before = $GLOBALS['mail_calls'];
check_delivery('code storage failure is reported', bc_send_user_login_otp($user, 7), '');
check_delivery('storage failure does not send mail', $GLOBALS['mail_calls'], $before);
$user['email'] = 'not-an-address';
check_delivery('invalid email is rejected', bc_send_user_login_otp($user, 7), '');

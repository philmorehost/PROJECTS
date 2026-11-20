<?php
$root = dirname(__DIR__) . '/DGV7.0-SAAS';
require $root . '/func/bc-vendor-expiry.php';
$n = 0; $failed = 0;
function expiry_check($ok, $label) {
    global $n, $failed; $n++; if (!$ok) $failed++;
    echo ($ok ? 'ok ' : 'FAIL ') . $label . "\n";
}
$tz = new DateTimeZone('Africa/Lagos');
$now = function ($date) use ($tz) { return (new DateTimeImmutable($date, $tz))->getTimestamp(); };
foreach (array(null, '', '0000-00-00') as $date) expiry_check(!bc_vendor_subscription_expired(array('expiry_date'=>$date)), 'legacy unlimited');
expiry_check(!bc_vendor_subscription_expired(array('expiry_date'=>'2026-11-18'), $now('2026-11-18 23:59:59')), 'valid through expiry day');
expiry_check(bc_vendor_subscription_expired(array('expiry_date'=>'2026-11-18'), $now('2026-11-19 00:00:00')), 'suspended at next midnight');
expiry_check(!bc_vendor_subscription_expired(array('expiry_date'=>'2026-12-18'), $now('2026-11-19')), 'renewed row immediately active');
expiry_check(bc_vendor_subscription_expired(array('expiry_date'=>'2026-02-30')), 'invalid date fails closed');
foreach (array('/bc-admin/Login.php', '/bc-admin/PasswordRecovery.php', '/bc-admin/RenewSubscription.php', '/admin-logout.php') as $p) expiry_check(bc_vendor_expiry_route($p)==='recovery', $p);
foreach (array('/bc-admin/Dashboard.php','/bc-admin/AccountSettings.php','/bc-admin/Fund.php','/bc-admin/ajax_upload.php') as $p) expiry_check(bc_vendor_expiry_route($p)==='admin', $p.' locked');
foreach (array('/index.php','/web/Login.php','/web/AISuite.php','/web/api/buy-data.php','/api.php','/web/guest-api/order.php') as $p) expiry_check(bc_vendor_expiry_route($p)==='public', $p.' suspended');
expiry_check(bc_vendor_expiry_route('/index.php?next=/bc-spadmin/Login.php')==='public', 'no substring exemption');
expiry_check(bc_vendor_expiry_route('/bc-spadmin/Dashboard.php')==='platform', 'platform administration intact');
expiry_check(bc_vendor_expiry_route('/vendors-paystack.php')==='settlement', 'incoming vendor settlement intact');
ob_start(); require $root . '/func/bc-vendor-expired-view.php'; $html=ob_get_clean();
expiry_check(strpos($html,'/bc-admin/RenewSubscription.php')!==false, 'owner renewal action');
expiry_check(strpos($html,'noindex,nofollow')!==false, 'suspension not indexed');
$s=file_get_contents($root.'/func/bc-connect.php');
expiry_check(strpos($s,'bc_enforce_vendor_expiry($connection_server)')!==false, 'shared bootstrap enforced');
$s=file_get_contents($root.'/bc-admin/RenewSubscription.php');
expiry_check(strpos($s,"isset(\$_POST['buy'])")!==false && strpos($s,'bc_validate_csrf()')!==false, 'renewal requires CSRF protected POST');
expiry_check(strpos($s,'if (!$renewal_only)')!==false, 'fund link hidden during suspension');
echo "$n checks, $failed failures\n"; exit($failed ? 1 : 0);

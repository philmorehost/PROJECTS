<?php
$n=0;
function expect_otp($ok,$label){$GLOBALS['n']++;if(!$ok)throw new RuntimeException($label);}
foreach(['SAAS','NON-SAAS'] as $edition){
 $root=__DIR__.'/../DGV7.0-'.$edition;
 $s=file_get_contents($root.'/bc-admin/UserUpgrade.php');
 expect_otp(strpos($s,"bc_admin_inline_otp_dispatch(\$get_logged_admin_details,false,'user_personal_conversion')")!==false,'fixed conversion purpose');
 expect_otp(strpos($s,"'factor_method'=>(string)(\$_POST['factor_method']??'auto')")!==false,'conversion explicit factor method');
 expect_otp(strpos($s,'name="sec33_send_code"')!==false && strpos($s,'../asset/bc-admin-inline-otp.js')!==false,'conversion inline rendering');
 expect_otp(strpos($s,"\$conversion_channels['email']")!==false && strpos($s,"\$conversion_channels['google']")!==false,'channel-aware conversion UI');
 $s=file_get_contents($root.'/bc-admin/Withdrawals.php');
 $pause=strpos($s,"Withdrawal mutations are paused.");$dispatch=strpos($s,"if (isset(\$_POST['withdrawal_action'])");
 expect_otp($pause!==false && $pause<$dispatch && strpos($s,"(\$_SERVER['REQUEST_METHOD'] ?? '') !== 'GET'")!==false,'withdrawal non-GET pause before every dispatch');
 expect_otp(strpos($s,'name="send_stepup_code" value="1" disabled')!==false && strpos($s,'>Paused</button>')!==false,'paused UI cannot send');
}
$s=file_get_contents(__DIR__.'/../DGV7.0-SAAS/bc-spadmin/VendorKYC.php');
expect_otp(strpos($s,"bc_admin_inline_otp_dispatch(\$get_logged_spadmin_details,true,'vendor_kyc_decision')")<strpos($s,"if (isset(\$_POST['vendor_kyc_decision']))"),'vendor KYC dispatch before mutation');
expect_otp(substr_count($s,"'factor_method'=>(string)(\$_POST['factor_method']??'auto')")===2,'vendor KYC approve and reject carry explicit method');
$s=file_get_contents(__DIR__.'/../DGV7.0-SAAS/web/Login.php');
expect_otp(strpos($s,'bc_login_resend($login_otp_pending')!==false && strpos($s,'../asset/bc-login-resend.js')!==false,'login resend helper and inline asset');
expect_otp(strpos($s,"if (isset(\$_POST['verify-login-otp']) && is_array(\$login_otp_pending)) {\n        bc_validate_csrf();")!==false && strpos($s,'<?php echo bc_csrf_field(); ?>')!==false,'login OTP CSRF verification and form');
echo "$n OTP follow-up integration checks passed\n";

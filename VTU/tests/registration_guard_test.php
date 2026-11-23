<?php
require __DIR__.'/../DGV7.0-SAAS/func/bc-registration-guard.php';
$n=0;function reg_check($ok,$msg){$GLOBALS['n']++;if(!$ok)throw new RuntimeException($msg);}
function reg_fixture(){$_SESSION=['reg_pending'=>['vendor_id'=>7],'reg_otp'=>'012345','reg_otp_expires'=>time()+600,'reg_otp_attempts'=>0];}
reg_fixture();reg_check(bc_registration_code_check(7,'012345')==='','leading-zero code valid');
reg_check(bc_registration_code_check(8,'012345')!=='','tenant isolation');
$_SESSION['reg_otp_expires']=time()-1;reg_check(bc_registration_code_check(7,'012345')!=='','expiry');
reg_fixture();for($i=0;$i<5;$i++)reg_check(bc_registration_code_check(7,'wrong')!=='','incorrect refused');
reg_check(bc_registration_code_check(7,'012345')!=='','attempt budget');
reg_fixture();unset($_SESSION['reg_otp']);reg_check(bc_registration_code_check(7,'012345')!=='','consumed code refused');
foreach(['SAAS','NON-SAAS'] as $edition){
 $root=__DIR__.'/../DGV7.0-'.$edition;
 foreach(['send','verify'] as $action){$s=file_get_contents($root.'/web/api/'.$action.'-reg-otp.php');reg_check(strpos($s,'bc_validate_csrf(true)')!==false && strpos($s,'bc_registration_enabled($current_reg_vendor)')!==false,'CSRF and current service guard');}
 $s=file_get_contents($root.'/web/api/send-reg-otp.php');reg_check(strpos($s,'if(!sendVendorEmail($email, $subject, $body))')!==false,'mail failure checked');
 $s=file_get_contents($root.'/web/api/verify-reg-otp.php');reg_check(strpos($s,"unset(\$_SESSION['reg_otp'])")<strpos($s,'$reg_inserted=mysqli_query'),'consume before insert');reg_check(strpos($s,'if(!$reg_inserted)')<strpos($s,'$_SESSION["user_session"]'),'insert failure cannot login');
 $s=file_get_contents($root.'/web/Register.php');reg_check(strpos($s,'Email verification is required.')!==false && strpos($s,'bc_csrf_field()')!==false,'fallback cannot skip enabled OTP');
}
echo "$n registration guard checks passed\n";

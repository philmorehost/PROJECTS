<?php
require __DIR__.'/../DGV7.0-SAAS/func/bc-recovery-inline.php';
$_SESSION=[];$n=0;$csrf=0;
function bc_validate_csrf($json=false){$GLOBALS['csrf']++;}
function check_recovery($ok,$label){$GLOBALS['n']++;if(!$ok)throw new RuntimeException($label);}
foreach(['user','admin','spadmin'] as $role){
 $_SESSION[$role.'-recovery-username']='fixture';$_SESSION[$role.'-recovery-code']='123456';$_SESSION[$role.'-recovery-google']=true;
 bc_recovery_mail_state($role,7,true);
 check_recovery(!isset($_SESSION[$role.'-recovery-google']),'email issue removes stale Google authorization');
 check_recovery(!bc_recovery_code_valid($role,8,'123456'),'tenant binding');
 check_recovery(!bc_recovery_code_valid($role,7,'12345e'),'strict digits');
 check_recovery(bc_recovery_code_valid($role,7,'123456'),'valid code');
 $_SESSION['recovery_challenges'][$role]['expires']=time()-1;check_recovery(!bc_recovery_code_valid($role,7,'123456'),'expiry');
 bc_recovery_mail_state($role,7,true);$_SESSION[$role.'-recovery-username']='another';check_recovery(!bc_recovery_code_valid($role,7,'123456'),'identity binding');
 $_SESSION[$role.'-recovery-username']='fixture';bc_recovery_mail_state($role,7,true);
 for($i=0;$i<5;$i++)bc_recovery_code_valid($role,7,'654321');
 check_recovery(!bc_recovery_code_valid($role,7,'123456'),'five-attempt budget');
 bc_recovery_mail_state($role,7,true);bc_recovery_consume($role);check_recovery(!bc_recovery_code_valid($role,7,'123456')&&!isset($_SESSION[$role.'-recovery-code']),'consumption prevents replay');
 $_SESSION[$role.'-recovery-code']='123456';bc_recovery_mail_state($role,7,false);check_recovery(!isset($_SESSION[$role.'-recovery-username'],$_SESSION[$role.'-recovery-code']),'mail failure clears challenge');
}
$_POST=['send-code'=>'1'];bc_recovery_post_guard();check_recovery($csrf===1,'send uses CSRF');$_POST=['verify-code'=>'1'];bc_recovery_post_guard();check_recovery($csrf===2,'verify uses CSRF');
foreach(['SAAS','NON-SAAS'] as $ed)foreach(['web','bc-admin','bc-spadmin'] as $area){
 $file=__DIR__.'/../DGV7.0-'.$ed.'/'.$area.'/PasswordRecovery.php';if(!file_exists($file))continue;$s=file_get_contents($file);
 check_recovery(strpos($s,'bc_recovery_post_guard();')<strpos($s,'if(isset($_POST["send-code"]))'),'guard before dispatch');
 check_recovery(strpos($s,'random_int(100000,999999)')!==false && strpos($s,'str_shuffle')===false,'secure code generator');
 check_recovery(strpos($s,'bc_recovery_code_valid(')!==false && strpos($s,'bc_recovery_consume(')!==false,'expiry and replay wired');
 check_recovery(strpos($s,'bc_csrf_field()')!==false && strpos($s,'../asset/bc-recovery-inline.js')!==false,'CSRF form and script');
 check_recovery(strpos($s,'if($get_user_details!==true)')!==false,'SQL failure not success');
}
echo "$n recovery controller and integration checks passed\n";

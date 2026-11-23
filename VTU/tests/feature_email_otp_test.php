<?php
require __DIR__.'/../DGV7.0-SAAS/func/bc-feature-email-otp.php';
$_SESSION=[];$n=0;$mail=true;$calls=0;$csrf=true;
function bc_validate_csrf($json){if(!$json||!$GLOBALS['csrf'])throw new RuntimeException('csrf');}
function sendVendorEmail($email,$subject,$body){$GLOBALS['calls']++;if($email!=='fixture@example.test')throw new RuntimeException('recipient');return $GLOBALS['mail'];}
function feature_check($ok,$msg){$GLOBALS['n']++;if(!$ok)throw new RuntimeException($msg);}
$actor=['id'=>11,'vendor_id'=>7,'status'=>1,'email'=>'fixture@example.test'];
foreach(['withdrawal'=>'withdrawal_otp','giftcard'=>'giftcard_otp'] as $purpose=>$key){
 $_SESSION=[];$before=$calls;
 feature_check(bc_feature_email_otp($actor,$purpose)['status']==='success' && preg_match('/\A[0-9]{6}\z/D',$_SESSION[$key]) && $_SESSION[$key.'_time']<=time(),'dispatch and legacy challenge');
 feature_check(bc_feature_email_otp_scope($actor,$purpose),'issued scope matches');
 $other=$actor;$other['vendor_id']=8;feature_check(!bc_feature_email_otp_scope($other,$purpose),'different tenant rejected');
 $other=$actor;$other['id']=12;feature_check(!bc_feature_email_otp_scope($other,$purpose),'different user rejected');
 feature_check(bc_feature_email_otp($actor,$purpose)['status']==='error' && $calls===$before+1,'cooldown');
 $_SESSION['feature_otp_sends']=[];$mail=false;
 feature_check(bc_feature_email_otp($actor,$purpose)['status']==='error' && !isset($_SESSION[$key]),'mail failure clears unusable code');$mail=true;
 foreach(['is_blocked'=>1,'force_password_change'=>1,'status'=>2] as $k=>$v){$blocked=$actor;$blocked[$k]=$v;$before=$calls;feature_check(bc_feature_email_otp($blocked,$purpose)['status']==='error' && $calls===$before,'restricted actor no mail');}
}
$csrf=false;try{bc_feature_email_otp($actor,'withdrawal');throw new LogicException('accepted');}catch(RuntimeException $e){feature_check($e->getMessage()==='csrf','CSRF required');}
foreach(['SAAS','NON-SAAS'] as $edition){
 $root=__DIR__.'/../DGV7.0-'.$edition;
 foreach(['SendFund.php','GiftCard.php'] as $file){$s=file_get_contents($root.'/web/'.$file);feature_check(strpos($s,'bc_feature_email_otp(')!==false && strpos($s,'data-feature-otp=')!==false && strpos($s,'bc-feature-email-otp.js')!==false,'handler and inline UI wiring');}
 $s=file_get_contents($root.'/web/CryptoHub.php');feature_check(strpos($s,"Crypto services are being provisioned")!==false && strpos($s,'disabled title="Crypto services are not provisioned')!==false && strpos($s,'function sendWithdrawalOTP')===false,'crypto gate unchanged; no send');
 $s=file_get_contents($root.'/bc-admin/Withdrawals.php');feature_check(strpos($s,'Withdrawal mutations are paused.')!==false,'administrative withdrawal pause retained');
}
echo "$n feature email OTP checks passed\n";

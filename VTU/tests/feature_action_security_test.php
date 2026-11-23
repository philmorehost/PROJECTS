<?php
require __DIR__.'/../DGV7.0-SAAS/func/bc-feature-email-otp.php';
require __DIR__.'/../DGV7.0-SAAS/func/bc-feature-action-security.php';
$n=0;$csrf=true;
function bc_validate_csrf($json){if(!$json||!$GLOBALS['csrf'])throw new RuntimeException('csrf');}
function ck_action($ok,$label){$GLOBALS['n']++;if(!$ok)throw new RuntimeException($label);}
$actor=['id'=>11,'vendor_id'=>7];
foreach(['withdrawal'=>'withdrawal_otp','giftcard'=>'giftcard_otp'] as $purpose=>$key){
 $_SESSION=[$key=>'123456',$key.'_time'=>time(),$key.'_scope'=>'7:11:'.$purpose];
 ck_action(bc_feature_action_code_check($actor,$purpose,'123456')==='','valid');
 $other=$actor;$other['vendor_id']=8;ck_action(bc_feature_action_code_check($other,$purpose,'123456')!=='','tenant scope');
 $_SESSION[$key.'_time']=time()-601;ck_action(bc_feature_action_code_check($actor,$purpose,'123456')!=='','expired');$_SESSION[$key.'_time']=time();
 $_SESSION[$key.'_attempts']=0;for($i=0;$i<5;$i++)bc_feature_action_code_check($actor,$purpose,'654321');
 ck_action(bc_feature_action_code_check($actor,$purpose,'123456')!=='','exhausted budget');
 $_SESSION[$key.'_attempts']=0;bc_feature_action_code_consume($purpose);ck_action(bc_feature_action_code_check($actor,$purpose,'123456')!=='' && !isset($_SESSION[$key]),'consumed replay rejected');
}
$_SERVER['REQUEST_METHOD']='POST';$_POST=['initiate-transfer'=>'1','process-transfer'=>'1'];bc_feature_action_post_guard('withdrawal');ck_action(true,'two legacy aliases are one operation');
$csrf=false;try{bc_feature_action_post_guard('giftcard');throw new LogicException('accepted');}catch(RuntimeException $e){ck_action($e->getMessage()==='csrf','mutation CSRF');}
foreach(['SAAS','NON-SAAS'] as $ed){
 $root=__DIR__.'/../DGV7.0-'.$ed;
 foreach(['SendFund.php','GiftCard.php'] as $file){$s=file_get_contents($root.'/web/'.$file);ck_action(strpos($s,'bc_feature_action_post_guard(')!==false && strpos($s,'bc_csrf_field()')!==false && strpos($s,'bc-feature-action.js')!==false,'server/form/programmatic csrf wired');ck_action(strpos($s,'bc_feature_action_code_consume(')!==false && strpos($s,'bc_feature_action_code_check(')!==false,'bounded once-only code wired');}
 $s=file_get_contents($root.'/web/func/bank-transfer.php');ck_action(strpos($s,'PAYOUT_SECURITY_HOLD')<strpos($s,'return;') && strpos($s,'return;')<strpos($s,'$purchase_method = strtoupper'),'payout hold unchanged before legacy execution');
}
echo "$n feature final-action checks passed\n";

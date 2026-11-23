<?php
require __DIR__.'/../DGV7.0-SAAS/func/bc-action-channels.php';
$n=0;function channel_check($v,$m){global $n;if(!$v)throw new RuntimeException($m);$n++;}
foreach([true,false] as $g)foreach([true,false] as $e)foreach([true,false] as $enrolled){
 $p=['google'=>$g,'email'=>$e];$expected=!$g&&!$e?'none':($g&&$enrolled?'google':($e?'email':'invalid'));
 channel_check(bc_action_channel_choice($p,$enrolled)===$expected,'effective default');
 channel_check(bc_action_channel_choice($p,$enrolled,'email')===(!$g&&!$e?'none':($e?'email':'invalid')),'explicit email policy');
 channel_check(bc_action_channel_choice($p,$enrolled,'google')===(!$g&&!$e?'none':($g&&$enrolled?'google':'invalid')),'explicit Google policy');
}
foreach(['bad','{"google":0,"email":true}','{"google":false}','{"google":true,"email":true,"extra":true}'] as $v){$failed=false;try{bc_action_channel_decode($v);}catch(Throwable $e){$failed=true;}channel_check($failed,'invalid policy fails closed');}
foreach(['SAAS','NON-SAAS'] as $ed){$root=__DIR__.'/../DGV7.0-'.$ed;
 foreach(['func/bc-admin-wallet-adjustment.php','func/bc-action-channels.php','func/bc-action-policy-settings.php','asset/bc-admin-wallet.js'] as $file)channel_check(file_get_contents($root.'/'.$file)===file_get_contents(__DIR__.'/../DGV7.0-SAAS/'.$file),'edition parity');
 $wallet=file_get_contents($root.'/bc-admin/ShareFund.php');channel_check(strpos($wallet,'bc_admin_wallet_handle(')!==false && strpos($wallet,'chargeOtherUser(')===false,'explicit wallet controller');
 $js=file_get_contents($root.'/asset/bc-admin-wallet.js');channel_check(strpos($js,"?'SENT':label")!==false && strpos($js,'location.reload')===false && strpos($js,'alert(')===false,'inline SENT no reload/alert');
}
echo "$n action channel/source checks passed\n";

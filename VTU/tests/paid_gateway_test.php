<?php
require __DIR__.'/../DGV7.0-SAAS/func/bc-paid-entitlements.php';
$n=0;function paid_source_check($v,$m){global $n;if(!$v)throw new RuntimeException($m);$n++;}
foreach(['0'=>'0.00','50000'=>'50000.00','150000.25'=>'150000.25'] as $v=>$expected)paid_source_check(bc_paid_money($v)===$expected,'valid amount');
foreach(['-1','NaN','INF','1e3','1,000','0.001','',[],true] as $v){$failed=false;try{bc_paid_money($v);}catch(Throwable $e){$failed=true;}paid_source_check($failed,'invalid amount refused');}
paid_source_check(bc_fintech_api_price('bybit')===150000.0 && bc_fintech_api_price('klasha')===50000.0,'per-module defaults');
foreach(['SAAS','NON-SAAS'] as $ed){
 $root=__DIR__.'/../DGV7.0-'.$ed;
 $page=file_get_contents($root.'/bc-admin/PaymentGateway.php');
 paid_source_check(strpos($page,'bc_gateway_api_cards(')!==false,'independent cards rendered');
 paid_source_check(strpos($page,'bc_paid_module_purchase(')!==false && strpos($page,'chargeVendor("debit", "fintech_api_access"')===false,'atomic module purchase replaces split debit/grant');
 paid_source_check(strpos($page,'bc_paid_verify(')<strpos($page,'bc_paid_module_purchase('),'verification precedes purchase');
 $core=file_get_contents($root.'/func/bc-paid-entitlements.php');
 paid_source_check(strpos($core,'FOR UPDATE')!==false && strpos($core,'mysqli_rollback')!==false && strpos($core,'INNODB')!==false,'locked rollback-safe transactional core');
 paid_source_check(strpos($core,'SET account_level=$target WHERE')!==false && strpos($core,'SET account_type=')===false,'pricing only never promotes account type');
 $cards=file_get_contents($root.'/func/bc-gateway-api-settings.php');
 paid_source_check(strpos($cards,"['klasha'=>'Klasha','bybit'=>'Bybit']")!==false,'both provider cards');
 paid_source_check(strpos($cards,"value=\"\"")!==false && strpos($cards,"'gcm:'")!==false,'secrets write-only and encrypted');
 paid_source_check(strpos($cards,'Crypto staging and live activation remain paused')!==false,'paused activation explicit');
 $security=file_get_contents($root.'/func/bc-paid-security.php');
 paid_source_check(strpos($security,'OTP SENT')!==false && strpos($security,'location.reload')===false && strpos($security,'alert(')===false,'OTP inline no reload or alert');
 paid_source_check(strpos($security,"['_csrf_token','paid_send_otp']")!==false,'OTP request strips all secrets');
 foreach(['func/bc-paid-entitlements.php','func/bc-paid-security.php','func/bc-gateway-api-settings.php','web/handle_upgrade.php'] as $rel)paid_source_check(file_get_contents($root.'/'.$rel)===file_get_contents(__DIR__.'/../DGV7.0-SAAS/'.$rel),'edition parity '.$rel);
}
echo "$n paid gateway source checks passed\n";

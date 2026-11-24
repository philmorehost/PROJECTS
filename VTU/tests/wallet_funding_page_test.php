<?php
require __DIR__.'/../DGV7.0-SAAS/func/bc-wallet-funding-page.php';
$n=0;function fcheck($ok,$message){global $n;if(!$ok)throw new RuntimeException($message);$n++;}
function frefuse($f,$message){try{$f();}catch(Throwable $e){fcheck(true,$message);return;}throw new RuntimeException('accepted '.$message);}
foreach(['100'=>10000,'100.01'=>10001,'1000000.00'=>100000000] as $value=>$minor)fcheck(bc_fund_amount((string)$value)===$minor,'exact planned amount');
foreach([null,[],true,100,100.0,'','99.99','1000000.01','1000001','1e4','+100','-100','100.001','100,000',' 100','100 ','0100','10000000','NaN','Infinity'] as $value)fcheck(bc_fund_amount($value)===null,'reject malformed/bounded amount');
$a=['id'=>1,'vendor_id'=>7,'username'=>'alice','status'=>1,'kyc_status'=>2];$session=['user_session'=>'alice','_csrf_token'=>'csrf-token'];$post=['gateway'=>'payhub','amount'=>'100.25','_csrf_token'=>'csrf-token','username'=>'attacker','vendor_id'=>999,'reference'=>'attacker-selected'];
fcheck(bc_fund_validate_post($a,$session,$post)===10025,'authenticated amount; posted identity/reference have no authority');
foreach([[],['user_session'=>'other'],['user_session'=>['alice']]] as $bad)fcheck(!bc_fund_actor_allowed($a,$bad),'session identity required');
foreach(['status'=>2,'is_blocked'=>1,'force_password_change'=>1,'vendor_id'=>0,'id'=>0] as $key=>$value){$bad=$a;$bad[$key]=$value;frefuse(fn()=>bc_fund_validate_post($bad,$session,$post),'restricted actor');}
foreach([null,[],false,'','wrong'] as $token){$bad=$post;$bad['_csrf_token']=$token;frefuse(fn()=>bc_fund_validate_post($a,$session,$bad),'CSRF rejects missing/array/mismatch');}
foreach(['paystack','flutterwave','monnify','beewave','evil',[],null] as $gateway){$bad=$post;$bad['gateway']=$gateway;frefuse(fn()=>bc_fund_validate_post($a,$session,$bad),'held gateway cannot be submitted');}
foreach([['kyc_status'=>0],['kyc_refresh_required'=>1],['kyc_id_expiry'=>'2000-01-01']] as $change)frefuse(fn()=>bc_fund_validate_post(array_merge($a,$change),$session,$post),'current KYC required');
fcheck(bc_fund_public_error(new RuntimeException('SQL secret detail'))==='Funding configuration is unavailable. Contact support; do not transfer funds yet.','never disclose DB/config exception');
ob_start();bc_fund_render(['available'=>true,'csrf'=>'x" autofocus onfocus="alert(1)','amount'=>'"><img src=x onerror=alert(1)>','error'=>'<script>alert(1)</script>','bank'=>['bank_name'=>'<img src=x>','account_name'=>'<script>x</script>','account_number'=>'0123456789']]);$html=ob_get_clean();
fcheck(strpos($html,'<script>')===false && strpos($html,'<img')===false,'escape rendered errors/input/bank data');
fcheck(strpos($html,'&quot;')!==false && strpos($html,'name="_csrf_token"')!==false,'escaped explicit CSRF field');
fcheck(strpos($html,'type="submit"')!==false && strpos($html,'name="amount"')!==false && strpos($html,'name="gateway"')!==false,'native POST form works without JavaScript');
foreach(['DGV7.0-SAAS','DGV7.0-NON-SAAS'] as $ed){
 $root=__DIR__.'/../'.$ed;
 $page=file_get_contents($root.'/web/Fund.php');fcheck(strpos($page,'bc_fund_validate_post(')!==false && strpos($page,'bc_fund_actor_allowed(')!==false,'server controller guards');
 foreach(['gateway-encrypt','gateway-public','gateway-int','tickPaymentGateway','checkPaymentGatewayDetails','num-ref','pointer-events: none','$_POST[\'username\']'] as $removed)fcheck(strpos($page,$removed)===false,'removed insecure legacy field/wiring');
 fcheck(strpos($page,'Cache-Control: no-store')!==false,'private no-store response');
}
foreach(['web/Fund.php','func/bc-wallet-funding-page.php','jsfile/bc-wallet-funding.js'] as $file)fcheck(file_get_contents(__DIR__.'/../DGV7.0-SAAS/'.$file)===file_get_contents(__DIR__.'/../DGV7.0-NON-SAAS/'.$file),'edition parity');
echo "PASS $n wallet funding page checks\n";

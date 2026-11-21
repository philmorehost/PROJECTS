<?php
set_exception_handler(function(Throwable $e) {fwrite(STDERR,'::error::'.str_replace(["\r","\n"],' ',$e->getMessage().' at '.basename($e->getFile()).':'.$e->getLine())."\n");exit(1);});
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-bybit.php';
$n=0;
function bybit_check($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;}
function bybit_reject($call,$label){try{$call();}catch(RuntimeException $e){bybit_check(strpos($e->getMessage(),'bybit_')===0,$label);return;}throw new RuntimeException('Expected rejection: '.$label);}
$key='test-api-key';$secret='test-secret-not-a-real-key';
bybit_check(bc_bybit_signature('1700000000000',$key,$secret,'coin=USDT')==='7c64aa836ecbedecd1c48ca07ee0917a98c4466b5a5c8f63bdf78279647b3f31','independently calculated HMAC vector');
$config=['environment'=>'testnet','base_url'=>'https://api-testnet.bybit.com','key'=>$key,'secret'=>$secret];
$spec=bc_bybit_request_spec($config,'coins',['coin'=>'USDT'],'1700000000000');
bybit_check($spec['method']==='GET' && $spec['url']==='https://api-testnet.bybit.com/v5/asset/coin/query-info?coin=USDT','exact read-only documented URL');
bybit_check(in_array('X-BAPI-SIGN: 7c64aa836ecbedecd1c48ca07ee0917a98c4466b5a5c8f63bdf78279647b3f31',$spec['headers'],true),'exact query bytes signed');
bybit_check(strpos($spec['url'],$key)===false && strpos($spec['url'],$secret)===false,'credentials never in query');
foreach(['withdraw','trade','/v5/asset/withdraw/create','https://evil.test','deposit'] as $op)bybit_reject(function()use($config,$op){bc_bybit_request_spec($config,$op,[],'1700000000000');},'money operation forbidden');
bybit_reject(function()use($config){$config['base_url']='https://evil.test';bc_bybit_request_spec($config,'coins',[],'1700000000000');},'arbitrary provider URL refused');
foreach(['usdt',"USDT\r\nHeader:x",'../BTC','<script>'] as $coin)bybit_reject(function()use($config,$coin){bc_bybit_request_spec($config,'coins',['coin'=>$coin],'1700000000000');},'invalid coin rejected');
bybit_reject(function()use($config){bc_bybit_request_spec($config,'key_info',['coin'=>'USDT'],'1700000000000');},'unexpected parameters');
$ready=bc_bybit_key_readiness(['readOnly'=>1,'ips'=>['192.0.2.1'],'apiKey'=>'sensitive','userID'=>42],['192.0.2.1']);
bybit_check($ready===['read_only'=>true,'ip_binding_verified'=>true,'money_movement_enabled'=>false],'only sanitized readiness leaves key verifier');
foreach([['readOnly'=>0,'ips'=>['192.0.2.1']],['readOnly'=>'1','ips'=>['192.0.2.1']],['readOnly'=>1,'ips'=>[]],['readOnly'=>1,'ips'=>['*']],['readOnly'=>1,'ips'=>['192.0.2.2']],['readOnly'=>1,'ips'=>['192.0.2.1','192.0.2.2']]] as $bad)bybit_reject(function()use($bad){bc_bybit_key_readiness($bad,['192.0.2.1']);},'unsafe key/IP configuration');
foreach([[429,'{}'],[500,'{}'],[302,'{}'],[200,'not-json'],[200,'{"retCode":10002,"result":{}}'],[200,'{"retCode":"0","result":{}}'],[200,'{"retCode":0}'],[200,str_repeat('x',2097153)]] as $bad)bybit_reject(function()use($bad){bc_bybit_decode($bad[0],$bad[1]);},'invalid transport/provider envelope fails closed');
bybit_check(bc_bybit_decode(200,'{"retCode":0,"result":{"rows":[]}}')===['rows'=>[]],'success envelope accepted');
$chain=['chain'=>'TRX','chainDeposit'=>'1','chainWithdraw'=>'1','confirmation'=>'20','safeConfirmNumber'=>'40','minAccuracy'=>'8','withdrawFee'=>'0.00000001','withdrawPercentageFee'=>'0.022','depositMin'=>'0.00000001','withdrawMin'=>'1.00000000'];
$result=['rows'=>[['coin'=>'USDT','chains'=>[$chain]]]];
$catalogue=bc_bybit_normalize_coins($result);
bybit_check($catalogue[0]['asset_network']==='USDT:TRX','coin and chain are separate identifiers');
bybit_check($catalogue[0]['withdraw_fee']==='0.00000001' && $catalogue[0]['withdraw_min']==='1.00000000','exact decimal strings retained without float conversion');
bybit_check($catalogue[0]['platform_enabled']===false,'discovery never enables platform service');
$result['rows'][0]['chains'][0]['chainWithdraw']='0';
bybit_check(!bc_bybit_normalize_coins($result)[0]['provider_withdraw_available'],'suspended network withdraw status retained');
$result['rows'][0]['chains'][0]['chainWithdraw']='1';$result['rows'][0]['chains'][0]['withdrawFee']='';
bybit_check(!bc_bybit_normalize_coins($result)[0]['provider_withdraw_available'],'missing fee means withdrawal not supported');
foreach(['-1','1e-8','NaN','0;DROP TABLE','01.2',1.5] as $bad)bybit_reject(function()use($bad){bc_bybit_decimal($bad);},'ambiguous decimal rejected');
foreach([['chainDeposit'=>'yes'],['minAccuracy'=>'31'],['safeConfirmNumber'=>'-1'],['withdrawMin'=>'1e2'],['chain'=>'TRX/<script>']] as $changes){$bad=$chain;foreach($changes as $k=>$v)$bad[$k]=$v;bybit_reject(function()use($bad){bc_bybit_normalize_coins(['rows'=>[['coin'=>'USDT','chains'=>[$bad]]]]);},'invalid network rules rejected');}
bybit_reject(function()use($chain){bc_bybit_normalize_coins(['rows'=>[['coin'=>'USDT','chains'=>[$chain,$chain]]]]);},'duplicate asset/network rejected');
putenv('BC_BYBIT_VENDOR_77_KEY='.$key);putenv('BC_BYBIT_VENDOR_77_SECRET='.$secret);putenv('BC_BYBIT_VENDOR_77_EXPECTED_IPS=192.0.2.1');
bybit_check(bc_bybit_config(77)['environment']==='testnet','testnet default');
bybit_reject(function(){bc_bybit_config(78);},'no credential fallback across vendors');
putenv('BC_BYBIT_VENDOR_77_ENV=https://evil.test');bybit_reject(function(){bc_bybit_config(77);},'arbitrary environment rejected');
putenv('BC_BYBIT_VENDOR_77_ENV=mainnet');bybit_check(bc_bybit_config(77)['base_url']==='https://api.bybit.com','mainnet is explicit fixed origin');
foreach(['KEY','SECRET','EXPECTED_IPS','ENV'] as $name)putenv('BC_BYBIT_VENDOR_77_'.$name);
foreach(['DGV7.0-SAAS','DGV7.0-NON-SAAS'] as $ed){
 $root=dirname(__DIR__).'/'.$ed;
 $module=file_get_contents($root.'/func/bc-bybit.php');$page=file_get_contents($root.'/bc-admin/CryptoBybit.php');
 bybit_check(strpos($module,'CURLOPT_FOLLOWLOCATION=>false')!==false && strpos($module,'CURLOPT_SSL_VERIFYPEER=>true')!==false,'TLS/redirect restrictions');
 bybit_check(strpos($module,'CURLOPT_TIMEOUT=>8')!==false && strpos($module,'2097152')!==false,'time and memory budget');
 foreach(['bc_validate_csrf();','bc_gauth_enrolled($actor)','bc_stepup_admin_verify($actor','bc_is_rate_limited(','bc_sec_audit(','Cache-Control: no-store, private','htmlspecialchars('] as $required)bybit_check(strpos($page,$required)!==false,'admin security boundary '.$required);
 bybit_check(strpos($module,'bc_fintech_ledger_post')===false && strpos($module,'UPDATE ')===false && strpos($module,'withdraw/create')===false,'read-only adapter cannot move balances or withdraw');
 bybit_check(strpos(file_get_contents($root.'/bc-admin/CryptoHub.php'),'href="CryptoBybit.php"')!==false,'admin entry point');
 token_get_all($module,TOKEN_PARSE);token_get_all($page,TOKEN_PARSE);
}
bybit_check(file_get_contents(dirname(__DIR__).'/DGV7.0-SAAS/func/bc-bybit.php')===file_get_contents(dirname(__DIR__).'/DGV7.0-NON-SAAS/func/bc-bybit.php'),'adapter edition parity');
echo "$n Bybit discovery checks passed; no live HTTP requests performed\n";

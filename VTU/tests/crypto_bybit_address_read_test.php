<?php
set_exception_handler(function(Throwable $e){fwrite(STDERR,'::error::'.str_replace(["\r","\n"],' ',$e->getMessage().' at '.basename($e->getFile()).':'.$e->getLine())."\n");exit(1);});
/** Real signing/transport/identity/parser with synthetic cURL only. No live requests. */
if(extension_loaded('curl'))throw new RuntimeException('Run php -n -d extension=tokenizer.');
foreach(['CURLOPT_HTTPHEADER','CURLOPT_FOLLOWLOCATION','CURLOPT_SSL_VERIFYPEER','CURLOPT_SSL_VERIFYHOST','CURLOPT_SSLVERSION','CURL_SSLVERSION_TLSv1_2','CURLOPT_PROTOCOLS','CURLPROTO_HTTPS','CURLOPT_CONNECTTIMEOUT','CURLOPT_TIMEOUT','CURLOPT_WRITEFUNCTION','CURLINFO_HTTP_CODE'] as $i=>$c)define($c,100+$i);
function curl_init($url){return (object)['url'=>$url];}
function curl_setopt_array($h,$opts){$h->options=$opts;return true;}
function curl_exec($h){
 $GLOBALS['requests'][]=$h;
 $index=count($GLOBALS['requests'])-1;
 if(($GLOBALS['rotate']??false)&&$index===1)putenv('BC_BYBIT_VENDOR_77_SECRET=rotated-synthetic-secret');
 if(($GLOBALS['fail_at']??-1)===$index)return false;
 $response=$GLOBALS['responses'][$index]??null;
 $body=is_string($response)?$response:json_encode(['retCode'=>0,'result'=>$response]);
 return $h->options[CURLOPT_WRITEFUNCTION]($h,$body)===strlen($body);
}
function curl_getinfo($h,$key){return $GLOBALS['status']??200;}
function curl_close($h){}
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-crypto-bybit-address-read.php';
$n=0;function ar_check($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;}
function ar_reject($fn,$error){try{$fn();}catch(Throwable $e){ar_check($e->getMessage()===$error,$error.' got '.$e->getMessage());return;}throw new RuntimeException('Unexpected success '.$error);}
$key=['userID'=>1234567,'parentUid'=>'0','isMaster'=>true,'type'=>1,'readOnly'=>1,'ips'=>['192.0.2.1'],'apiKey'=>'synthetic-key','secret'=>'','note'=>'RAW NOTE NOT FOR STORAGE'];
$a='T9yD14Nj9j7xAB4dbGeiX9h8unkKHxuWwb';
$address=['coin'=>'USDT','chains'=>[['chain'=>'TRX','chainType'=>'Tron label','addressDeposit'=>$a,'tagDeposit'=>'','contractAddress'=>'ignore-me','batchReleaseLimit'=>'-1']]];
$id=bc_crypto_custody_identity('testnet','1234567');
function ar_reset(){global $key,$address;foreach(['KEY'=>'synthetic-key','SECRET'=>'synthetic-secret-not-real','EXPECTED_IPS'=>'192.0.2.1','ENV'=>'testnet'] as $k=>$v)putenv('BC_BYBIT_VENDOR_77_'.$k.'='.$v);$GLOBALS['requests']=[];$GLOBALS['responses']=[$key,$address,$key];$GLOBALS['rotate']=false;$GLOBALS['fail_at']=-1;$GLOBALS['status']=200;}
$read=fn()=>bc_crypto_bybit_read_master_address(77,'testnet',$id,'USDT','TRX');
ar_reset();$r=$read();
ar_check(count($GLOBALS['requests'])===3,'identity/address/identity sequence');
ar_check($r['address']===$a&&$r['asset_network']==='USDT:TRX'&&$r['tag']===''&&$r['authenticated_read_completed']===true,'exact syntactically validated address snapshot');
ar_check($r['expires_at']===$r['started_at']+900&&$r['verified_at']>=$r['started_at'],'bounded point-in-time freshness only');
foreach(['customer_attribution_verified','custody_eligibility_verified','provider_write_capabilities_verified','ownership_verified','live_enabled'] as $flag)ar_check($r[$flag]===false,'no authority inferred '.$flag);
foreach(['1234567','192.0.2.1','synthetic-key','synthetic-secret','RAW NOTE','contractAddress','ignore-me','batchReleaseLimit'] as $secret)ar_check(strpos(json_encode($r),$secret)===false,'raw provider field omitted '.$secret);
$urls=array_map(fn($h)=>$h->url,$GLOBALS['requests']);
ar_check($urls===['https://api-testnet.bybit.com/v5/user/query-api','https://api-testnet.bybit.com/v5/asset/deposit/query-address?chainType=TRX&coin=USDT','https://api-testnet.bybit.com/v5/user/query-api'],'fixed exact GET URLs with canonical query');
foreach($GLOBALS['requests'] as $h){ar_check($h->options[CURLOPT_FOLLOWLOCATION]===false&&$h->options[CURLOPT_SSL_VERIFYPEER]===true&&$h->options[CURLOPT_SSL_VERIFYHOST]===2&&$h->options[CURLOPT_CONNECTTIMEOUT]===3&&$h->options[CURLOPT_TIMEOUT]===8,'bounded TLS transport');}
$config=bc_bybit_config(77);$spec=bc_bybit_request_spec($config,'master_deposit_address',['coin'=>'USDT','chainType'=>'TRX'],'1700000000000');
ar_check(in_array('X-BAPI-SIGN: '.bc_bybit_signature('1700000000000',$config['key'],$config['secret'],'chainType=TRX&coin=USDT'),$spec['headers'],true),'canonical address query signed');
foreach([[],['coin'=>'USDT'],['coin'=>'USDT','chainType'=>null],['coin'=>'BTC','chainType'=>'TRX'],['coin'=>'USDT','chainType'=>'ETH']] as $params)ar_reject(fn()=>bc_bybit_request_spec($config,'master_deposit_address',$params,'1700000000000'),'bybit_address_scope_invalid');
ar_reject(fn()=>bc_bybit_request_spec($config,'master_deposit_address',['coin'=>'USDT','chainType'=>'TRX','url'=>'https://evil.test'],'1700000000000'),'bybit_parameters_invalid');
foreach([['USDT','ETH'],['BTC','TRX'],['usdt','TRX']] as $scope){ar_reset();ar_reject(fn()=>bc_crypto_bybit_read_master_address(77,'testnet',$id,...$scope),'crypto_address_network_unsupported');ar_check(!$GLOBALS['requests'],'unsupported scope before network');}
ar_reset();ar_reject(fn()=>bc_crypto_bybit_read_master_address(77,'mainnet',$id,'USDT','TRX'),'crypto_bybit_environment_mismatch');ar_check(!$GLOBALS['requests'],'environment before network');
ar_reset();ar_reject(fn()=>bc_crypto_bybit_read_master_address(78,'testnet',$id,'USDT','TRX'),'bybit_credentials_not_configured');ar_check(!$GLOBALS['requests'],'no tenant fallback');
ar_reset();$GLOBALS['responses'][0]['isMaster']=false;$GLOBALS['responses'][0]['parentUid']='999';ar_reject($read,'crypto_address_master_required');ar_check(count($GLOBALS['requests'])===1,'subaccount never reaches master endpoint');
foreach([0,2] as $position){
 foreach([['userID'=>9876543],['readOnly'=>0],['ips'=>['192.0.2.2']],['isMaster'=>false,'parentUid'=>'999']] as $change){ar_reset();$GLOBALS['responses'][$position]=array_merge($key,$change);$error=isset($change['userID'])?'crypto_bybit_identity_mismatch':(isset($change['readOnly'])?'bybit_read_only_key_required':(isset($change['ips'])?'bybit_ip_binding_mismatch':($position===0?'crypto_address_master_required':'crypto_address_identity_changed')));ar_reject($read,$error);}
}
ar_reset();$GLOBALS['responses'][1]['chains'][0]['tagDeposit']='0';ar_reject($read,'crypto_address_tag_unsupported');ar_check(count($GLOBALS['requests'])===2,'bad address has no successful evidence');
ar_reset();$GLOBALS['responses'][1]['chains'][0]['addressDeposit']=substr($a,0,-1).'c';ar_reject($read,'crypto_address_invalid');
ar_reset();$GLOBALS['rotate']=true;ar_reject($read,'crypto_address_config_changed');
foreach([0,1,2] as $position){ar_reset();$GLOBALS['fail_at']=$position;ar_reject($read,'bybit_transport_failed');ar_check(count($GLOBALS['requests'])===$position+1,'failure no retry or extra query');}
ar_reset();$GLOBALS['status']=429;ar_reject($read,'bybit_rate_limited');
ar_reset();$GLOBALS['responses'][1]='{"retCode":10005,"result":{}}';ar_reject($read,'bybit_response_rejected');
foreach(['SAAS','NON-SAAS'] as $ed){$base=dirname(__DIR__).'/DGV7.0-'.$ed.'/func/';$s=file_get_contents($base.'bc-crypto-bybit-address-read.php');token_get_all($s,TOKEN_PARSE);ar_check($s===file_get_contents(dirname(__DIR__).'/DGV7.0-SAAS/func/bc-crypto-bybit-address-read.php'),'edition parity');ar_check(!preg_match('/INSERT INTO|UPDATE |bc_fintech_ledger_post|chargeUser/',$s),'no persistence, activation or funds in read adapter');$s=file_get_contents($base.'bc-crypto-custody-management.php');ar_check(strpos($s,"bc_crypto_custody_evidence_store($"."authority,$"."identity,$"."env,bc_crypto_gov_json($"."snapshot),$"."revision)")!==false,'evidence storage remains exact revision pinned');ar_check(strpos(file_get_contents($base.'bc-crypto-custody-page.php'),'name="action" value="inspect_master_address"')!==false,'explicit stepped-up form only');}
echo "$n master address read checks passed; synthetic cURL only, no customer attribution or activation.\n";

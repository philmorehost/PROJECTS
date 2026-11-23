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
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-crypto-bybit-parent-read.php';
$n=0;function pr_check($v,$label){global $n;if(!$v)throw new RuntimeException($label);$n++;}
function pr_bad($fn,$code){try{$fn();}catch(Throwable $e){pr_check($e->getMessage()===$code,$code.' got '.$e->getMessage());return;}throw new RuntimeException('Unexpected acceptance '.$code);}
$sub=['userID'=>1234567,'parentUid'=>'7654321','isMaster'=>false,'type'=>1,'readOnly'=>1,'ips'=>['192.0.2.1'],'apiKey'=>'synthetic-sub-key','secret'=>''];
$master=array_merge($sub,['userID'=>7654321,'parentUid'=>'0','isMaster'=>true,'apiKey'=>'synthetic-master-key']);
$subId=bc_crypto_custody_identity('testnet','1234567');$parentId=bc_crypto_custody_identity('testnet','7654321');
function pr_reset(){global $sub,$master;foreach([77=>'synthetic-sub-key',78=>'synthetic-master-key'] as $id=>$key)foreach(['KEY'=>$key,'SECRET'=>'synthetic-secret-not-real','EXPECTED_IPS'=>'192.0.2.1','ENV'=>'testnet'] as $k=>$v)putenv('BC_BYBIT_VENDOR_'.$id.'_'.$k.'='.$v);$GLOBALS['requests']=[];$GLOBALS['responses']=[$sub,$master,$sub,$master];$GLOBALS['rotate']=false;$GLOBALS['fail_at']=-1;$GLOBALS['status']=200;}
$read=fn()=>bc_crypto_bybit_read_parent(77,78,'testnet',$subId,$parentId);
pr_reset();$r=$read();pr_check(count($GLOBALS['requests'])===4,'exactly four bounded GETs');
pr_check($r['reported_parent_relationship_compared']===true&&$r['reported_parent_account']===$parentId,'authenticated relationship matches');
foreach(['customer_allocation_verified','custody_eligibility_verified','ownership_verified','provider_write_capabilities_verified','live_enabled'] as $k)pr_check($r[$k]===false,'no authority '.$k);
foreach(['1234567','7654321','synthetic-sub-key','synthetic-master-key','192.0.2.1','synthetic-secret'] as $v)pr_check(strpos(json_encode($r),$v)===false,'raw identity or secret omitted');
foreach($GLOBALS['requests'] as $h){pr_check($h->url==='https://api-testnet.bybit.com/v5/user/query-api','identity endpoint only');pr_check($h->options[CURLOPT_FOLLOWLOCATION]===false&&$h->options[CURLOPT_SSL_VERIFYPEER]===true&&$h->options[CURLOPT_TIMEOUT]===8,'bounded verified TLS');}
pr_reset();pr_bad(fn()=>bc_crypto_bybit_read_parent(77,77,'testnet',$subId,$parentId),'crypto_parent_distinct_required');pr_check(!$GLOBALS['requests'],'same source refused before network');
pr_reset();putenv('BC_BYBIT_VENDOR_78_ENV=mainnet');pr_bad($read,'crypto_bybit_environment_mismatch');pr_check(!$GLOBALS['requests'],'environment checked before network');
pr_reset();$GLOBALS['responses'][0]['parentUid']='999';pr_bad($read,'crypto_parent_relationship_mismatch');pr_check(count($GLOBALS['requests'])===1,'wrong parent stops early');
pr_reset();$GLOBALS['responses'][0]['isMaster']=true;$GLOBALS['responses'][0]['parentUid']='0';pr_bad($read,'crypto_parent_relationship_mismatch');
foreach([0,1,2,3] as $pos){pr_reset();$GLOBALS['fail_at']=$pos;pr_bad($read,'bybit_transport_failed');pr_check(count($GLOBALS['requests'])===$pos+1,'no retry');pr_reset();$GLOBALS['responses'][$pos]['readOnly']=0;pr_bad($read,'bybit_read_only_key_required');}
pr_reset();$GLOBALS['responses'][2]['parentUid']='999';pr_bad($read,'crypto_parent_identity_changed');
pr_reset();$GLOBALS['responses'][3]['isMaster']=false;$GLOBALS['responses'][3]['parentUid']='999';pr_bad($read,'crypto_parent_identity_changed');
pr_reset();$GLOBALS['rotate']=true;pr_bad($read,'crypto_parent_config_changed');
pr_reset();$GLOBALS['status']=429;pr_bad($read,'bybit_rate_limited');pr_check(count($GLOBALS['requests'])===1,'rate limit no retry');
foreach(['SAAS','NON-SAAS'] as $ed){$base=dirname(__DIR__).'/DGV7.0-'.$ed.'/func/';$source=file_get_contents($base.'bc-crypto-bybit-parent-read.php');token_get_all($source,TOKEN_PARSE);pr_check($source===file_get_contents(dirname(__DIR__).'/DGV7.0-SAAS/func/bc-crypto-bybit-parent-read.php'),'parity');pr_check(!preg_match('/INSERT INTO|UPDATE |deposit_routes|bc_fintech_ledger_post|chargeUser/',$source),'no writes or funds');}
echo "$n parent relationship checks passed; synthetic transport only, no allocation.\n";

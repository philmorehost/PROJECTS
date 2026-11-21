<?php
set_exception_handler(function(Throwable $e) {fwrite(STDERR,'::error::'.str_replace(["\r","\n"],' ',$e->getMessage().' at '.basename($e->getFile()).':'.$e->getLine())."\n");exit(1);});
/** Real bounded adapter with synthetic cURL responses only. Run php -n. */
if(extension_loaded('curl'))throw new RuntimeException('Run php -n.');
foreach(['CURLOPT_HTTPHEADER','CURLOPT_FOLLOWLOCATION','CURLOPT_SSL_VERIFYPEER','CURLOPT_SSL_VERIFYHOST','CURLOPT_SSLVERSION','CURL_SSLVERSION_TLSv1_2','CURLOPT_PROTOCOLS','CURLPROTO_HTTPS','CURLOPT_CONNECTTIMEOUT','CURLOPT_TIMEOUT','CURLOPT_WRITEFUNCTION','CURLINFO_HTTP_CODE'] as $i=>$c)define($c,100+$i);
function curl_init($url){$GLOBALS['url']=$url;return (object)['options'=>[]];}
function curl_setopt_array($h,$opts){$h->options=$opts;$GLOBALS['options']=$opts;return true;}
function curl_exec($h){$GLOBALS['requests']++;if($GLOBALS['fail']??false)return false;$body=json_encode(['retCode'=>0,'result'=>$GLOBALS['reply']]);$h->options[CURLOPT_WRITEFUNCTION]($h,$body);return true;}
function curl_getinfo($h,$key){return $GLOBALS['status']??200;}
function curl_close($h){}
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-crypto-bybit-identity.php';
$n=0;function bi_check($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;}
function bi_reject($fn,$error){try{$fn();}catch(Throwable $e){bi_check($e->getMessage()===$error,$error.' got '.$e->getMessage());return;}throw new RuntimeException('Unexpected success '.$error);}
$config=['vendor_id'=>77,'environment'=>'testnet','expected_ips'=>['192.0.2.1'],'key'=>'synthetic-key'];
$r=['userID'=>1234567,'parentUid'=>'0','isMaster'=>true,'type'=>1,'readOnly'=>1,'ips'=>['192.0.2.1'],'apiKey'=>'synthetic-key','secret'=>'','permissions'=>['Wallet'=>['Withdraw']],'note'=>'DO NOT EXPOSE THIS RAW NOTE','kycRegion'=>'raw-region'];
$identity=bc_crypto_custody_identity('testnet','1234567');$now=1800000000;
$snapshot=bc_crypto_bybit_identity_normalize($config,$r,$identity,$now);
bi_check($snapshot['custody_account']===$identity&&$snapshot['reported_account_kind']==='master'&&$snapshot['reported_parent_account']===null,'Master UID compared to exact canonical registry');
bi_check($snapshot['expires_at']===$now+900&&!$snapshot['live_enabled']&&!$snapshot['custody_eligibility_verified']&&!$snapshot['provider_write_capabilities_verified'],'No write/eligibility activation inferred from identity or permission labels');
$serialized=json_encode($snapshot);
foreach(['1234567','synthetic-key','DO NOT EXPOSE','Withdraw','raw-region','192.0.2.1','apiKey','secret'] as $sensitive)bi_check(strpos($serialized,$sensitive)===false,'Sanitized identity document excludes '.$sensitive);
$sub=$r;$sub['userID']='2234567';$sub['parentUid']='1234567';$sub['isMaster']=false;
$s=bc_crypto_bybit_identity_normalize($config,$sub,bc_crypto_custody_identity('testnet','2234567'),$now);
bi_check($s['reported_account_kind']==='subaccount'&&$s['reported_parent_account']===$identity,'Subaccount parent is fingerprinted, never raw UID');
foreach([1.5,true,0,-1,'001',' 1','1e3',str_repeat('1',21),null] as $uid){$bad=$r;$bad['userID']=$uid;bi_reject(fn()=>bc_crypto_bybit_identity_normalize($config,$bad,$identity,$now),'crypto_bybit_identity_invalid');}
foreach([['type'=>2],['type'=>'1'],['isMaster'=>'true'],['apiKey'=>'other-key'],['secret'=>'unexpected-secret'],['userID'=>null]] as $change){$bad=array_merge($r,$change);bi_reject(fn()=>bc_crypto_bybit_identity_normalize($config,$bad,$identity,$now),'crypto_bybit_identity_invalid');}
$bad=$r;$bad['parentUid']='1';bi_reject(fn()=>bc_crypto_bybit_identity_normalize($config,$bad,$identity,$now),'crypto_bybit_parent_invalid');
$bad=$sub;$bad['parentUid']='2234567';bi_reject(fn()=>bc_crypto_bybit_identity_normalize($config,$bad,bc_crypto_custody_identity('testnet','2234567'),$now),'crypto_bybit_parent_invalid');
bi_reject(fn()=>bc_crypto_bybit_identity_normalize($config,$r,str_repeat('a',64),$now),'crypto_bybit_identity_mismatch');
$bad=$r;$bad['readOnly']=0;bi_reject(fn()=>bc_crypto_bybit_identity_normalize($config,$bad,$identity,$now),'bybit_read_only_key_required');
$bad=$r;$bad['ips']=['192.0.2.2'];bi_reject(fn()=>bc_crypto_bybit_identity_normalize($config,$bad,$identity,$now),'bybit_ip_binding_mismatch');
// Existing transport/signing runs against fake cURL; no provider endpoint is contacted.
foreach(['KEY'=>'synthetic-key','SECRET'=>'synthetic-secret-not-real','EXPECTED_IPS'=>'192.0.2.1','ENV'=>'testnet'] as $k=>$v)putenv('BC_BYBIT_VENDOR_77_'.$k.'='.$v);
$GLOBALS['reply']=$r;$GLOBALS['requests']=0;
$found=bc_crypto_bybit_verify_identity(77,'testnet',$identity);
bi_check($GLOBALS['requests']===1&&$GLOBALS['url']==='https://api-testnet.bybit.com/v5/user/query-api','One existing allowlisted signed GET only');
bi_check($GLOBALS['options'][CURLOPT_FOLLOWLOCATION]===false&&$GLOBALS['options'][CURLOPT_TIMEOUT]===8&&$GLOBALS['options'][CURLOPT_SSL_VERIFYPEER]===true,'Existing bounded TLS/no redirect transport retained');
$GLOBALS['requests']=0;
bi_reject(fn()=>bc_crypto_bybit_verify_identity(77,'mainnet',$identity),'crypto_bybit_environment_mismatch');bi_check($GLOBALS['requests']===0,'Environment mismatch refused before network');
bi_reject(fn()=>bc_crypto_bybit_verify_identity(78,'testnet',$identity),'bybit_credentials_not_configured');bi_check($GLOBALS['requests']===0,'No cross-vendor credential fallback');
$GLOBALS['status']=429;bi_reject(fn()=>bc_crypto_bybit_verify_identity(77,'testnet',$identity),'bybit_rate_limited');$GLOBALS['status']=200;
$GLOBALS['fail']=true;bi_reject(fn()=>bc_crypto_bybit_verify_identity(77,'testnet',$identity),'bybit_transport_failed');$GLOBALS['fail']=false;
foreach(['SAAS','NON-SAAS'] as $ed){
 $root=dirname(__DIR__).'/DGV7.0-'.$ed.'/func/';$src=file_get_contents($root.'bc-crypto-bybit-identity.php');token_get_all($src,TOKEN_PARSE);
 bi_check($src===file_get_contents(dirname(__DIR__).'/DGV7.0-SAAS/func/bc-crypto-bybit-identity.php'),'Edition parity');
 bi_check(strpos($src,"bc_bybit_get($"."config,'key_info')")!==false&&strpos($src,'UPDATE ')===false,'No money-moving operations');
 $page=file_get_contents($root.'bc-crypto-custody-page.php');bi_check(strpos($page,'name="action" value="verify_provider"')!==false,'Explicit stepped-up POST form');
}
echo "$n Bybit identity checks passed; synthetic responses, no provider account verified.\n";

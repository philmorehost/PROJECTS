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
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-crypto-bybit-sub-address-read.php';
$n=0;function sr_check($v,$s){global $n;if(!$v)throw new RuntimeException($s);$n++;}
function sr_bad($fn,$code){try{$fn();}catch(Throwable $e){sr_check($e->getMessage()===$code,$code.' got '.$e->getMessage());return;}throw new RuntimeException('Unexpected acceptance '.$code);}
$sub=['userID'=>1234567,'parentUid'=>'7654321','isMaster'=>false,'type'=>1,'readOnly'=>1,'ips'=>['192.0.2.1'],'apiKey'=>'synthetic-sub-key','secret'=>''];
$master=array_merge($sub,['userID'=>7654321,'parentUid'=>'0','isMaster'=>true,'apiKey'=>'synthetic-master-key']);
$subId=bc_crypto_custody_identity('testnet','1234567');$parentId=bc_crypto_custody_identity('testnet','7654321');
function pr_reset(){global $sub,$master;foreach([77=>'synthetic-sub-key',78=>'synthetic-master-key'] as $id=>$key)foreach(['KEY'=>$key,'SECRET'=>'synthetic-secret-not-real','EXPECTED_IPS'=>'192.0.2.1','ENV'=>'testnet'] as $k=>$v)putenv('BC_BYBIT_VENDOR_'.$id.'_'.$k.'='.$v);$GLOBALS['requests']=[];$GLOBALS['responses']=[$sub,$master,['coin'=>'USDT','chains'=>['chain'=>'TRX','chainType'=>'TRC20','addressDeposit'=>'T9yD14Nj9j7xAB4dbGeiX9h8unkKHxuWwb','tagDeposit'=>'']],$sub,$master];$GLOBALS['rotate']=false;$GLOBALS['fail_at']=-1;$GLOBALS['status']=200;}

pr_reset();$rel=['sub_source_vendor_id'=>77,'master_source_vendor_id'=>78,'reported_parent_account'=>$parentId];
foreach(['sub'=>77,'master'=>78] as $kind=>$source){$c=bc_bybit_config($source);$rel[$kind.'_config_fingerprint']=hash('sha256',bc_crypto_gov_json([$source,'testnet',$c['key'],$c['expected_ips']]));}
$read=fn()=>bc_crypto_bybit_read_sub_address($rel,$subId,'testnet','USDT','TRX');
$r=$read();sr_check(count($GLOBALS['requests'])===5,'exactly five reads');sr_check($GLOBALS['requests'][2]->url==='https://api-testnet.bybit.com/v5/asset/deposit/query-sub-member-address?chainType=TRX&coin=USDT&subMemberId=1234567','authenticated sub UID used with fixed canonical endpoint');
sr_check($r['address']==='T9yD14Nj9j7xAB4dbGeiX9h8unkKHxuWwb'&&$r['live_enabled']===false&&$r['customer_allocation_verified']===false,'evidence only');
foreach(['1234567','7654321','synthetic-sub-key','synthetic-master-key','192.0.2.1','synthetic-secret'] as $secret)sr_check(strpos(json_encode($r),$secret)===false,'raw identity secret omitted');
foreach([0,1,2,3,4] as $pos){pr_reset();$GLOBALS['fail_at']=$pos;sr_bad($read,'bybit_transport_failed');sr_check(count($GLOBALS['requests'])===$pos+1,'no retry or fallback');}
pr_reset();$GLOBALS['responses'][0]['parentUid']='999';sr_bad($read,'crypto_parent_relationship_mismatch');sr_check(count($GLOBALS['requests'])===1,'wrong parent stops address request');
pr_reset();$GLOBALS['responses'][3]['parentUid']='999';sr_bad($read,'crypto_parent_identity_changed');
pr_reset();$GLOBALS['responses'][2]['chains']['tagDeposit']='1';sr_bad($read,'crypto_address_tag_unsupported');
pr_reset();$GLOBALS['rotate']=true;sr_bad($read,'crypto_parent_config_changed');
pr_reset();$GLOBALS['status']=429;sr_bad($read,'bybit_rate_limited');
pr_reset();$config=bc_bybit_config(78);
foreach([[],['coin'=>'USDT','chainType'=>'TRX'],['coin'=>'USDT','chainType'=>'ETH','subMemberId'=>'123'],['coin'=>'USDT','chainType'=>'TRX','subMemberId'=>123]] as $p)sr_bad(fn()=>bc_bybit_request_spec($config,'sub_deposit_address',$p,'1700000000000'),'bybit_sub_address_scope_invalid');
sr_bad(fn()=>bc_bybit_request_spec($config,'sub_deposit_address',['coin'=>'USDT','chainType'=>'TRX','subMemberId'=>'123','url'=>'evil'],'1700000000000'),'bybit_parameters_invalid');
sr_check(file_get_contents(dirname(__DIR__).'/DGV7.0-SAAS/func/bc-crypto-bybit-sub-address-read.php')===file_get_contents(dirname(__DIR__).'/DGV7.0-NON-SAAS/func/bc-crypto-bybit-sub-address-read.php'),'edition parity');
echo "$n bound sub-address synthetic transport checks passed; no allocation or live requests.\n";

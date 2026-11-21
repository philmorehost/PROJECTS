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
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-crypto-bybit-deposit-read.php';
$n=0;function pr_check($v,$label){global $n;if(!$v)throw new RuntimeException($label);$n++;}
function pr_reject($fn,$error){try{$fn();}catch(Throwable $e){pr_check($e->getMessage()===$error,$error.' got '.$e->getMessage());return;}throw new RuntimeException('Unexpected success '.$error);}
$key=['userID'=>1234567,'parentUid'=>'0','isMaster'=>true,'type'=>1,'readOnly'=>1,'ips'=>['192.0.2.1'],'apiKey'=>'synthetic-key','secret'=>'','note'=>'DO NOT COPY RAW NOTE'];
$row=['id'=>'160237231','coin'=>'USDT','chain'=>'TRX','amount'=>'1.000001','txID'=>str_repeat('a',64),'txIndex'=>'0','status'=>3,'toAddress'=>'T9yD14Nj9j7xAB4dbGeiX9h8unkKHxuWwb','tag'=>'','depositFee'=>'','successAt'=>'1700000000000','confirmations'=>'50','depositType'=>'0'];
$id=bc_crypto_custody_identity('testnet','1234567');
function pr_reset(){global $key,$row;foreach(['KEY'=>'synthetic-key','SECRET'=>'synthetic-secret-not-real','EXPECTED_IPS'=>'192.0.2.1','ENV'=>'testnet'] as $k=>$v)putenv('BC_BYBIT_VENDOR_77_'.$k.'='.$v);$GLOBALS['requests']=[];$GLOBALS['responses']=[$key,['rows'=>[$row],'nextPageCursor'=>'opaque+/=='],$key];$GLOBALS['rotate']=false;$GLOBALS['fail_at']=-1;$GLOBALS['status']=200;}
$read=fn()=>bc_crypto_bybit_read_deposit_page(77,'testnet',$id,'USDT','1699999900000','1700000000000','');
pr_reset();$e=$read();
pr_check(count($GLOBALS['requests'])===3,'exact identity/page/identity sequence');
pr_check($GLOBALS['requests'][1]->url==='https://api-testnet.bybit.com/v5/asset/deposit/query-record?coin=USDT&endTime=1700000000000&limit=5&startTime=1699999900000','fixed GET path, complete canonical query');
pr_check($e['page']['records'][0]['units']==='1000001'&&$e['page']['records'][0]['risk']==='unknown','real parser leaves risk unknown and exact units');
pr_check($e['authenticated_read_completed']&&$e['identity_checked_before_and_after'],'transport completed, not allocation');
foreach(['history_complete','query_window_membership_verified','cursor_chain_verified','cursor_advance_authorized','customer_attribution_verified','custody_eligibility_verified','import_enabled','settlement_enabled','live_enabled'] as $flag)pr_check($e[$flag]===false,'no authority inferred '.$flag);
foreach(['synthetic-key','synthetic-secret','192.0.2.1','1234567','DO NOT COPY RAW NOTE'] as $raw)pr_check(strpos(json_encode($e),$raw)===false,'secrets/raw identity omitted');
foreach($GLOBALS['requests'] as $h)pr_check($h->options[CURLOPT_TIMEOUT]===8&&$h->options[CURLOPT_CONNECTTIMEOUT]===3&&$h->options[CURLOPT_SSL_VERIFYPEER]&&$h->options[CURLOPT_SSL_VERIFYHOST]===2&&!$h->options[CURLOPT_FOLLOWLOCATION],'bounded no-redirect TLS');
pr_check($e['expires_at']===$e['started_at']+900&&strlen(bc_crypto_gov_json($e))<=16384,'bounded freshness/evidence');
$params=bc_bybit_deposit_query('USDT','1699913600000','1700000000000','a+/==','1700000000000');
pr_check($params['limit']==='5'&&$params['cursor']==='a+/==','exact one-day boundary accepted; cursor opaque');
$config=bc_bybit_config(77);$spec=bc_bybit_request_spec($config,'deposit_records',$params,'1700000000000');
pr_check(strpos($spec['url'],'cursor=a%2B%2F%3D%3D')!==false,'cursor RFC3986 encoded, not decoded or concatenated');
$q=parse_url($spec['url'],PHP_URL_QUERY);pr_check(in_array('X-BAPI-SIGN: '.bc_bybit_signature('1700000000000',$config['key'],$config['secret'],$q),$spec['headers'],true),'exact query bytes signed');
foreach([
 ['1699913599000','1700000000000'],['1700000000000','1700000000000'],['1700000001000','1700000000000'],
 ['1699999900001','1700000000000'],['1699999900000','1700000000001'],['1699999900000','1700000001000'],
 [1699999900000,'1700000000000'],['1699999900000',null],['01699999900000','1700000000000']
] as $window)pr_reject(fn()=>bc_bybit_deposit_query('USDT',$window[0],$window[1],'','1700000000000'),'bybit_deposit_window_invalid');
// String arithmetic crosses a decimal carry without platform integer casts.
pr_check(bc_bybit_deposit_query('TRX','1999999999000','2000000000000','','2000000000000')['startTime']==='1999999999000','fixed-width subtraction across carry');
foreach([null,' bad',"x\nx",str_repeat('x',2049)] as $c)pr_reject(fn()=>bc_bybit_deposit_query('USDT','1699999900000','1700000000000',$c,'1700000000000'),'bybit_deposit_cursor_invalid');
pr_reject(fn()=>bc_bybit_deposit_query('BTC','1699999900000','1700000000000','','1700000000000'),'bybit_deposit_scope_invalid');
foreach([5,'50',null] as $limit){$x=$params;$x['limit']=$limit;pr_reject(fn()=>bc_bybit_request_spec($config,'deposit_records',$x,'1700000000000'),'bybit_deposit_limit_invalid');}
$x=$params;$x['id']='123';pr_reject(fn()=>bc_bybit_request_spec($config,'deposit_records',$x,'1700000000000'),'bybit_parameters_invalid');
$x=$params;$x['cursor']=null;pr_reject(fn()=>bc_bybit_request_spec($config,'deposit_records',$x,'1700000000000'),'bybit_deposit_cursor_invalid');
pr_reset();pr_reject(fn()=>bc_crypto_bybit_read_deposit_page(77,'mainnet',$id,'USDT','1699999900000','1700000000000',''),'crypto_bybit_environment_mismatch');pr_check(!$GLOBALS['requests'],'environment mismatch before network');
pr_reset();pr_reject(fn()=>bc_crypto_bybit_read_deposit_page(78,'testnet',$id,'USDT','1699999900000','1700000000000',''),'bybit_credentials_not_configured');pr_check(!$GLOBALS['requests'],'no source credential fallback');
foreach([0,2] as $position){foreach([['userID'=>999],['readOnly'=>0],['ips'=>['192.0.2.2']]] as $change){pr_reset();$GLOBALS['responses'][$position]=array_merge($key,$change);pr_reject($read,isset($change['userID'])?'crypto_bybit_identity_mismatch':(isset($change['readOnly'])?'bybit_read_only_key_required':'bybit_ip_binding_mismatch'));}}
pr_reset();$GLOBALS['responses'][2]['isMaster']=false;$GLOBALS['responses'][2]['parentUid']='999';pr_reject($read,'crypto_deposit_inspection_identity_changed');
pr_reset();foreach([0,2] as $p){$GLOBALS['responses'][$p]['isMaster']=false;$GLOBALS['responses'][$p]['parentUid']='999';}pr_check($read()['reported_account_kind']==='subaccount','subaccount reads its own records, not parent account');
pr_reset();$GLOBALS['responses'][1]['rows']=array_fill(0,6,$row);pr_reject($read,'crypto_deposit_inspection_limit_exceeded');pr_check(count($GLOBALS['requests'])===2,'over-limit response not truncated');
pr_reset();$GLOBALS['responses'][1]['rows'][0]['chain']='ETH';pr_reject($read,'crypto_bybit_deposit_scope_mismatch');
pr_reset();$GLOBALS['responses'][1]['rows'][0]['status']=999;pr_reject($read,'crypto_bybit_deposit_status_unknown');
pr_reset();$GLOBALS['responses'][1]['nextPageCursor']='repeat';pr_reject(fn()=>bc_crypto_bybit_read_deposit_page(77,'testnet',$id,'USDT','1699999900000','1700000000000','repeat'),'crypto_bybit_deposit_cursor_repeated');
pr_reset();$GLOBALS['responses'][1]['rows']=[];pr_check($read()['page']['has_next_page'],'empty page with continuation not history-complete');
pr_reset();$GLOBALS['rotate']=true;pr_reject($read,'crypto_deposit_inspection_config_changed');
foreach([0,1,2] as $position){pr_reset();$GLOBALS['fail_at']=$position;pr_reject($read,'bybit_transport_failed');pr_check(count($GLOBALS['requests'])===$position+1,'transport failure never retries');}
pr_reset();$GLOBALS['status']=429;pr_reject($read,'bybit_rate_limited');
pr_reset();$GLOBALS['responses'][1]='{"retCode":10005,"result":{}}';pr_reject($read,'bybit_response_rejected');
foreach(['SAAS','NON-SAAS'] as $edition){$base=dirname(__DIR__).'/DGV7.0-'.$edition.'/func/';$s=file_get_contents($base.'bc-crypto-bybit-deposit-read.php');token_get_all($s,TOKEN_PARSE);pr_check($s===file_get_contents(dirname(__DIR__).'/DGV7.0-SAAS/func/bc-crypto-bybit-deposit-read.php'),'edition parity');pr_check(!preg_match('/INSERT INTO|UPDATE |bc_crypto_deposit_observe\s*\(|chargeUser\s*\(/',$s),'no importer/checkpoint/funds');$s=file_get_contents($base.'bc-crypto-custody-management.php');pr_check(strpos($s,'crypto_deposit_page_requested')!==false&&strpos($s,'bc_crypto_custody_evidence_store(')!==false,'mandatory audit and protected evidence wiring');pr_check(strpos(file_get_contents($base.'bc-crypto-custody-page.php'),'name="action" value="inspect_deposit_page"')!==false,'explicit form');}
echo "$n authenticated deposit-page inspection checks passed; synthetic cURL only, no import or checkpoint.\n";

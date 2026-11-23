<?php
// The former transport contract has been replaced by a permanent retirement boundary.
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-bybit.php';
$n=0;function bybit_check($ok,$label){$GLOBALS['n']++;if(!$ok)throw new RuntimeException($label);}
foreach([0,7,77] as $vid){try{bc_bybit_config($vid);throw new LogicException('retired config accepted');}catch(RuntimeException $e){bybit_check($e->getMessage()==='bybit_retired','credentials never loaded');}}
foreach(['key_info','coins','deposit_records','withdraw'] as $operation){try{bc_bybit_get(['key'=>'not-used','secret'=>'not-used'],$operation);throw new LogicException('retired transport accepted');}catch(RuntimeException $e){bybit_check($e->getMessage()==='bybit_retired','all operations refused without transport');}}
foreach([
 fn()=>bc_bybit_signature('1700000000000','unused-key','unused-secret',''),
 fn()=>bc_bybit_request_spec(['key'=>'unused-key','secret'=>'unused-secret'],'key_info',[],'1700000000000')
] as $request){try{$request();throw new LogicException('retired request builder accepted');}catch(RuntimeException $e){bybit_check($e->getMessage()==='bybit_retired','no request signing or URL building');}}
// Every legacy authenticated reader is now a direct tombstone, even with malformed input.
foreach(['identity','parent-read','sub-address-read','address-read','deposit-read'] as $file) require_once dirname(__DIR__).'/DGV7.0-SAAS/func/bc-crypto-bybit-'.$file.'.php';
foreach([
 fn()=>bc_crypto_bybit_verify_identity(null,null,null),
 fn()=>bc_crypto_bybit_read_parent(null,null,null,null,null),
 fn()=>bc_crypto_bybit_read_sub_address(null,null,null,null,null),
 fn()=>bc_crypto_bybit_read_master_address(null,null,null,null,null),
 fn()=>bc_crypto_bybit_read_deposit_page(null,null,null,null,null,null,null)
] as $read){try{$read();throw new LogicException('retired reader accepted');}catch(RuntimeException $e){bybit_check($e->getMessage()==='bybit_retired','reader refuses before parsing credentials or issuing requests');}}
foreach(['SAAS','NON-SAAS'] as $ed){$root=dirname(__DIR__).'/DGV7.0-'.$ed;
 $s=file_get_contents($root.'/func/bc-bybit.php');bybit_check(strpos($s,'curl_exec')===false&&strpos($s,'getenv(')===false&&strpos($s,'bybit.com')===false&&strpos($s,'X-BAPI-')===false,'no credential loading or HTTP implementation');
 $page=file_get_contents($root.'/bc-admin/CryptoBybit.php');bybit_check(strpos($page,'http_response_code(410)')!==false&&strpos($page,'require')===false,'old URL gone before bootstrap');
 $custody=file_get_contents($root.'/func/bc-crypto-custody-page.php');
 foreach(['verify_provider','verify_parent','inspect_master_address','inspect_deposit_page','inspect_bound_sub_address','create_poll','fetch_poll','grant_poll_worker'] as $action)bybit_check(strpos($custody,'name="action" value="'.$action.'"')===false,'retired operational control removed '.$action);
 bybit_check(strpos($custody,'value="stop_poll"')!==false&&strpos($custody,'value="revoke_poll_worker"')!==false,'stop and revocation controls preserved');
 foreach(['identity','parent-read','sub-address-read','address-read','deposit-read'] as $file){$reader=file_get_contents($root.'/func/bc-crypto-bybit-'.$file.'.php');bybit_check(!preg_match('/\bbc_bybit_(?:get|config)\s*\(/',$reader),'reader no longer calls transport/config');}
 foreach(['crypto-poll-worker.php','crypto-poll-alert-deliver.php'] as $launcher){
  $entry=file_get_contents($root.'/func/'.$launcher);token_get_all($entry,TOKEN_PARSE);
  bybit_check(strpos($entry,'http_response_code(410)')!==false&&strpos($entry,'exit(2)')!==false,'old launcher explicitly retired');
  bybit_check(!preg_match('/\b(?:require|require_once|include|include_once|getenv)\b/',$entry),'no launcher bootstrap or enable-flag bypass');
  if(function_exists('proc_open') && PHP_BINARY!==''){
   $pipes=[];$process=proc_open([PHP_BINARY,$root.'/func/'.$launcher,str_repeat('a',32),'1'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,array_merge(getenv(),['BC_CRYPTO_POLL_WORKER_ENABLED'=>'1','BC_CRYPTO_POLL_ALERT_DELIVERY_ENABLED'=>'1']));
   bybit_check(is_resource($process),'launch isolated retired entry');fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);
   bybit_check($code===2&&$out===''&&strpos($err,'No work performed.')!==false,'enabled stale cron refuses without database or work');
  }
 }
 $cards=file_get_contents($root.'/func/bc-gateway-api-settings.php');bybit_check(stripos($cards,'bybit')===false,'no retired configuration card or credential path');
 $catalogue=file_get_contents($root.'/func/bc-fintech-api-access.php');bybit_check(strpos($catalogue,"'bybit'")===false,'not sold or granted');
 bybit_check(strpos(file_get_contents($root.'/bc-admin/CryptoHub.php'),'href="CryptoBybit.php"')===false,'no discovery link');
}
echo "$n retired provider boundary checks passed; no external requests\n";

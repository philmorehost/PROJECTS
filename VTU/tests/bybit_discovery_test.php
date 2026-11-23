<?php
// The former transport contract has been replaced by a permanent retirement boundary.
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-bybit.php';
$n=0;function bybit_check($ok,$label){$GLOBALS['n']++;if(!$ok)throw new RuntimeException($label);}
foreach([0,7,77] as $vid){try{bc_bybit_config($vid);throw new LogicException('retired config accepted');}catch(RuntimeException $e){bybit_check($e->getMessage()==='bybit_retired','credentials never loaded');}}
foreach(['key_info','coins','deposit_records','withdraw'] as $operation){try{bc_bybit_get(['key'=>'not-used','secret'=>'not-used'],$operation);throw new LogicException('retired transport accepted');}catch(RuntimeException $e){bybit_check($e->getMessage()==='bybit_retired','all operations refused without transport');}}
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
 $s=file_get_contents($root.'/func/bc-bybit.php');bybit_check(strpos($s,'curl_exec')===false&&strpos($s,'getenv(')===false,'no credential loading or HTTP implementation');
 $page=file_get_contents($root.'/bc-admin/CryptoBybit.php');bybit_check(strpos($page,'http_response_code(410)')!==false&&strpos($page,'require')===false,'old URL gone before bootstrap');
 $custody=file_get_contents($root.'/func/bc-crypto-custody-page.php');
 foreach(['verify_provider','verify_parent','inspect_master_address','inspect_deposit_page','inspect_bound_sub_address','create_poll','fetch_poll','grant_poll_worker'] as $action)bybit_check(strpos($custody,'name="action" value="'.$action.'"')===false,'retired operational control removed '.$action);
 bybit_check(strpos($custody,'value="stop_poll"')!==false&&strpos($custody,'value="revoke_poll_worker"')!==false,'stop and revocation controls preserved');
 foreach(['identity','parent-read','sub-address-read','address-read','deposit-read'] as $file){$reader=file_get_contents($root.'/func/bc-crypto-bybit-'.$file.'.php');bybit_check(!preg_match('/\bbc_bybit_(?:get|config)\s*\(/',$reader),'reader no longer calls transport/config');}
 $cards=file_get_contents($root.'/func/bc-gateway-api-settings.php');bybit_check(stripos($cards,'bybit')===false,'no retired configuration card or credential path');
 $catalogue=file_get_contents($root.'/func/bc-fintech-api-access.php');bybit_check(strpos($catalogue,"'bybit'")===false,'not sold or granted');
 bybit_check(strpos(file_get_contents($root.'/bc-admin/CryptoHub.php'),'href="CryptoBybit.php"')===false,'no discovery link');
}
echo "$n retired provider boundary checks passed; no external requests\n";

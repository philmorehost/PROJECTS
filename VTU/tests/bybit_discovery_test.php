<?php
// The former transport contract has been replaced by a permanent retirement boundary.
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-bybit.php';
$n=0;function bybit_check($ok,$label){$GLOBALS['n']++;if(!$ok)throw new RuntimeException($label);}
foreach([0,7,77] as $vid){try{bc_bybit_config($vid);throw new LogicException('retired config accepted');}catch(RuntimeException $e){bybit_check($e->getMessage()==='bybit_retired','credentials never loaded');}}
foreach(['key_info','coins','deposit_records','withdraw'] as $operation){try{bc_bybit_get(['key'=>'not-used','secret'=>'not-used'],$operation);throw new LogicException('retired transport accepted');}catch(RuntimeException $e){bybit_check($e->getMessage()==='bybit_retired','all operations refused without transport');}}
foreach(['SAAS','NON-SAAS'] as $ed){$root=dirname(__DIR__).'/DGV7.0-'.$ed;
 $s=file_get_contents($root.'/func/bc-bybit.php');bybit_check(strpos($s,'curl_exec')===false&&strpos($s,'getenv(')===false,'no credential loading or HTTP implementation');
 $page=file_get_contents($root.'/bc-admin/CryptoBybit.php');bybit_check(strpos($page,'http_response_code(410)')!==false&&strpos($page,'require')===false,'old URL gone before bootstrap');
 $cards=file_get_contents($root.'/func/bc-gateway-api-settings.php');bybit_check(stripos($cards,'bybit')===false,'no retired configuration card or credential path');
 $catalogue=file_get_contents($root.'/func/bc-fintech-api-access.php');bybit_check(strpos($catalogue,"'bybit'")===false,'not sold or granted');
 bybit_check(strpos(file_get_contents($root.'/bc-admin/CryptoHub.php'),'href="CryptoBybit.php"')===false,'no discovery link');
}
echo "$n retired provider boundary checks passed; no external requests\n";

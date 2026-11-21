<?php
if(extension_loaded('mysqli'))throw new RuntimeException('Use php -n.');
class mysqli {}
if(!defined('MYSQLI_TRANS_START_READ_ONLY'))define('MYSQLI_TRANS_START_READ_ONLY',4);
$connection_server=new mysqli();$checks=[];$commits=0;$rollbacks=0;$query_count=0;
function bc_q($sql,$params=[]){if($sql!=='SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')throw new RuntimeException('Unexpected mutation');return true;}
function bc_q_row($sql,$params=[]){
 if(strpos($sql,'@@session')!==false)return ['in_tx'=>0,'auto_commit'=>1];
 $GLOBALS['query_count']++;
 if($params!==[7,'testnet'])throw new RuntimeException('Missing tenant/environment scope');
 $GLOBALS['checks'][]=$sql;
 return ($GLOBALS['query_count']===($GLOBALS['missing_at']??-1))?null:['failures'=>$GLOBALS['query_count']===($GLOBALS['mismatch_at']??-1)?'1':'0'];
}
function mysqli_begin_transaction($db,$flags){if($flags!==MYSQLI_TRANS_START_READ_ONLY)throw new RuntimeException('Not read-only');return true;}
function mysqli_commit($db){$GLOBALS['commits']++;return true;}
function mysqli_rollback($db){$GLOBALS['rollbacks']++;return true;}
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-crypto-v2-reconcile.php';
$n=0;function integrity_check($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;}
$r=bc_crypto_v2_integrity(7,'testnet');
integrity_check($r['internal_integrity_ok']&&count($r['checks'])===5,'five clean integrity checks');
integrity_check($r['provider_reserves_verified']===false,'empty/clean journal never verifies external reserves');
integrity_check($commits===1&&$rollbacks===0,'snapshot completes');
for($i=1;$i<=5;$i++){
 $GLOBALS['query_count']=0;$GLOBALS['mismatch_at']=$i;$r=bc_crypto_v2_integrity(7,'testnet');
 integrity_check(!$r['internal_integrity_ok'],'each mismatch prevents clean result');
}
$GLOBALS['query_count']=0;$GLOBALS['missing_at']=3;
try{bc_crypto_v2_integrity(7,'testnet');throw new RuntimeException('Query failed open');}catch(RuntimeException $e){integrity_check($e->getMessage()==='crypto_integrity_query_failed'&&$rollbacks===1,'failed query aborts read snapshot');}
echo "$n reconciliation snapshot checks passed (query double, not live MySQL)\n";

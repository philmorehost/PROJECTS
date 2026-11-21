<?php
/** Real wallet implementation with SQL doubles. Not proof of MySQL concurrency. */
error_reporting(E_ALL);
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-incident-wallet.php';
$n=0;
function wcheck($ok,$label){global $n;$n++;if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
class WRows{public $rows;function __construct($r){$this->rows=$r;}}
function mysqli_real_escape_string($db,$v){return addslashes($v);}
function mysqli_fetch_assoc($q){return array_shift($q->rows);}
function mysqli_num_rows($q){return count($q->rows);}
function mysqli_affected_rows($db){return $db->affected;}
function mysqli_begin_transaction($db){$db->snapshot=array($db->balance,$db->events,$db->history);$db->active=1;return true;}
function mysqli_commit($db){if($db->fail==='commit')return false;$db->active=0;return true;}
function mysqli_rollback($db){if($db->snapshot!==null)list($db->balance,$db->events,$db->history)=$db->snapshot;$db->active=0;$db->rollbacks++;return true;}
function mysqli_query($db,$sql){
 $db->queries[]=$sql;
 if(strpos($sql,'@@session.in_transaction')!==false)return new WRows(array(array('active'=>$db->active)));
 if(strpos($sql,'information_schema.TABLES')!==false){$rows=array();foreach(array('sas_users','sas_vendors','sas_transactions','sas_vendor_transactions','sas_wallet_mutation_events') as $t)$rows[]=array('TABLE_NAME'=>$t,'ENGINE'=>$db->engine);return new WRows($rows);}
 if(strpos($sql,'SELECT balance,status,email')===0)return new WRows(array(array('balance'=>(string)$db->balance,'status'=>1,'email'=>'not-a-mail')));
 if(preg_match("/SELECT (payload_hash|amount) FROM sas_wallet_mutation_events WHERE event_key='([a-f0-9]+)'/",$sql,$m))return new WRows(isset($db->events[$m[2]])?array($db->events[$m[2]]):array());
 if(strpos($sql,'SELECT reference FROM sas_')===0)return new WRows($db->existing?array(array('reference'=>'existing')):array());
 if(preg_match('/^UPDATE sas_(?:users|vendors) SET balance=balance([+-])([0-9.]+)/',$sql,$m)){
   $amt=(float)$m[2];if($db->fail==='zero_update'||($m[1]==='-'&&$db->balance<$amt)){$db->affected=0;return true;}
   $db->balance+=($m[1]==='-'?-$amt:$amt);$db->affected=1;return true;
 }
 if(strpos($sql,'SELECT balance FROM sas_')===0)return new WRows(array(array('balance'=>(string)$db->balance)));
 if(preg_match('/^INSERT INTO sas_(?:transactions|vendor_transactions) /',$sql)){
   if($db->fail==='receipt')return false;$db->history[]=$sql;return true;
 }
 if(preg_match("/^INSERT INTO sas_wallet_mutation_events .*VALUES \('([a-f0-9]+)','([a-f0-9]+)',.*,'([0-9.]+)'\)$/",$sql,$m)){
   if($db->fail==='event')return false;if(isset($db->events[$m[1]]))return false;
   $db->events[$m[1]]=array('payload_hash'=>$m[2],'amount'=>$m[3]);return true;
 }
 throw new RuntimeException('Unexpected SQL in wallet fixture: '.$sql);
}
function wallet_fixture(){return (object)array('balance'=>100.0,'events'=>array(),'history'=>array(),'snapshot'=>null,'active'=>0,'engine'=>'InnoDB','fail'=>'','affected'=>0,'queries'=>array(),'existing'=>false,'rollbacks'=>0);}
function post_wallet($direction,$ref,$amount=25,$api='',$label='Wallet Credit',$desc='Fixture'){
 return bc_incident_wallet_post(7,'ada',$direction,'Provider',$label,$ref,$api,$amount,$amount,$desc,'WEB','merchant.invalid',1);
}
foreach(array(-1,0,'-5','1e3','NaN',INF,true,'0.0000000001',array()) as $v)wcheck(bc_incident_decimal($v)===null,'Malformed amount refused');
wcheck(bc_incident_decimal('00025.0000')==='25','Canonical decimal for replay fingerprints');
$connection_server=wallet_fixture();
wcheck(post_wallet('debit','D1',25)==='success' && $connection_server->balance===75.0,'Atomic debit posts balance and receipt');
wcheck(count($connection_server->events)===1&&count($connection_server->history)===1,'Debit includes durable replay event and receipt');
wcheck(post_wallet('debit','D1',25)==='failed'&&$connection_server->balance===75.0,'Replayed debit cannot authorize another service delivery');
wcheck(post_wallet('credit','C1',10,'provider-ref')==='success'&&$connection_server->balance===85.0,'Provider credit succeeds once');
wcheck(post_wallet('credit','C2',10,'provider-ref')==='success'&&$connection_server->balance===85.0,'Provider reference replay does not credit twice');
wcheck(post_wallet('credit','C3',11,'provider-ref')==='failed'&&$connection_server->balance===85.0,'Changed replay amount denied');
wcheck(post_wallet('credit','R1',25,'','Refund',"Refund for Ref:'D1'")==='success'&&$connection_server->balance===110.0,'Refund requires matching durable debit');
wcheck(post_wallet('credit','R2',25,'','Refund',"Refund for Ref:'D1'")==='success'&&$connection_server->balance===110.0,'Different refund reference cannot duplicate original refund');
wcheck(post_wallet('credit','R3',24,'','Refund',"Refund for Ref:'D1'")==='failed','Changed refund amount refused');
wcheck(post_wallet('credit','R4',10,'','Refund',"Refund for Ref:'PRE-UPGRADE'")==='failed','Unknown historical debit requires manual reconciliation');
wcheck(post_wallet('credit','R5',10,'','Refund','No linked debit')==='failed','Unbound refund denied');
foreach(array('receipt','event','commit','zero_update') as $failure){$connection_server=wallet_fixture();$connection_server->fail=$failure;wcheck(post_wallet('debit','F-'.$failure)==='failed'&&$connection_server->balance===100.0&&count($connection_server->events)===0&&count($connection_server->history)===0,'Rollback on '.$failure);}
$connection_server=wallet_fixture();wcheck(post_wallet('debit','OVER',101)==='failed'&&$connection_server->balance===100.0,'Insufficient balance cannot debit');
$connection_server=wallet_fixture();$connection_server->existing=true;wcheck(post_wallet('credit','OLD')==='failed'&&$connection_server->balance===100.0,'Pre-upgrade reference never silently re-credited');
$connection_server=wallet_fixture();$connection_server->engine='MyISAM';wcheck(post_wallet('credit','ENGINE')==='failed'&&$connection_server->balance===100.0,'Nontransactional schema fails closed');
$connection_server=wallet_fixture();$connection_server->active=1;wcheck(post_wallet('credit','NESTED')==='failed'&&$connection_server->active===1,'Never implicitly commit an outer transaction');
wcheck(file_get_contents(dirname(__DIR__).'/DGV7.0-SAAS/func/bc-incident-wallet.php')===file_get_contents(dirname(__DIR__).'/DGV7.0-NON-SAAS/func/bc-incident-wallet.php'),'Both editions use identical wallet implementation');
echo "$n wallet checks passed; production MySQL concurrency and integration tests remain required.\n";

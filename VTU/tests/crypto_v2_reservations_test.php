<?php
// Real reservation implementation with transactional storage double. No MySQL server/provider calls.
if (extension_loaded('mysqli')) throw new RuntimeException('Run with php -n or PHP-WASM.');
class mysqli {}
$connection_server = new mysqli();
$GLOBALS['db'] = [];
function mysqli_begin_transaction($db) { $GLOBALS['snapshot']=$GLOBALS['db']; return true; }
function mysqli_commit($db) { return empty($GLOBALS['commit_fail']); }
function mysqli_rollback($db) { $GLOBALS['db']=$GLOBALS['snapshot']; return true; }
function bc_q_row($sql,$p=[]) {
    $db=$GLOBALS['db'];
    if (strpos($sql,'@@session')!==false) return ['in_tx'=>$GLOBALS['in_tx']??0,'auto_commit'=>1];
    if (strpos($sql,'FROM sas_users')!==false) return $db['user'];
    if (strpos($sql,'FROM sas_crypto_v2_asset_policies')!==false) return $db['policy'];
    if (strpos($sql,'FROM sas_crypto_v2_accounts')!==false) return $db['accounts'][strpos($sql,"bucket='available'")!==false?1:2];
    if (strpos($sql,'WHERE parent_id=')!==false) {foreach($db['ops'] as $op)if($op['parent_id']===$p[0])return ['id'=>$op['id']];return null;}
    if (strpos($sql,'FROM sas_crypto_v2_operations')!==false) {
        $op=$db['ops'][$p[2]]??null;
        if ($op && strpos($sql,"kind='reserve'")!==false && $op['kind']!=='reserve')return null;
        return $op;
    }
    throw new RuntimeException('Unhandled test SQL '.$sql);
}
function bc_q($sql,$p=[],&$affected=null) {
    $GLOBALS['calls']++;
    if (($GLOBALS['fail_at']??-1)===$GLOBALS['calls'])return null;
    if (strpos($sql,'INSERT INTO sas_crypto_v2_operations')===0) {
        [$vid,$uid,$env,$asset,$ref,$kind,$units,$hash,$parent]=$p;
        if(isset($GLOBALS['db']['ops'][$ref]))return null;
        $GLOBALS['db']['ops'][$ref]=['id'=>count($GLOBALS['db']['ops'])+1,'vendor_id'=>$vid,'user_id'=>$uid,'environment'=>$env,'asset_network'=>$asset,'reference'=>$ref,'kind'=>$kind,'units'=>$units,'payload_hash'=>$hash,'parent_id'=>$parent];return true;
    }
    if(strpos($sql,'UPDATE sas_crypto_v2_accounts')===0){
        [$next,$id,$old]=$p;$affected=$GLOBALS['db']['accounts'][$id]['units']===$old?1:0;
        if($affected)$GLOBALS['db']['accounts'][$id]['units']=$next;return true;
    }
    if(strpos($sql,'INSERT INTO sas_crypto_v2_entries')===0){$GLOBALS['db']['entries'][]=$p;return true;}
    throw new RuntimeException('Unhandled write SQL');
}
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-crypto-reservations.php';
$n=0;function reserve_check($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;}
function reset_reservation_db(){
    $a=['id'=>1,'vendor_id'=>7,'user_id'=>9,'environment'=>'testnet','asset_network'=>'USDT:TRX','scale'=>'6','bucket'=>'available','units'=>'20000000'];
    $b=$a;$b['id']=2;$b['bucket']='reserved';$b['units']='0';
    $GLOBALS['db']=['user'=>['id'=>9,'status'=>1,'is_blocked'=>0,'force_password_change'=>0,'kyc_status'=>2],
       'policy'=>['enabled'=>1,'withdrawals_enabled'=>1,'reconciliation_status'=>'matched','valid_until_epoch'=>time()+600,'max_reservation_units'=>'10000000','scale'=>6],
       'accounts'=>[1=>$a,2=>$b],'ops'=>[],'entries'=>[]];
    $GLOBALS['calls']=0;$GLOBALS['fail_at']=-1;$GLOBALS['commit_fail']=false;$GLOBALS['in_tx']=0;
}
function reserve_reject($call,$label){try{$call();}catch(Throwable $e){reserve_check(strpos($e->getMessage(),'crypto_')===0,$label);return;}throw new RuntimeException('Expected refusal '.$label);}
$scope=['vendor_id'=>7,'user_id'=>9,'environment'=>'testnet','asset_network'=>'USDT:TRX'];
reset_reservation_db();
$r=bc_crypto_v2_reservation($scope,'reserve-0001','7000000');
reserve_check($r['ok']&&!$r['duplicate'],'reserve commits');
reserve_check($GLOBALS['db']['accounts'][1]['units']==='13000000'&&$GLOBALS['db']['accounts'][2]['units']==='7000000','exact balance movement');
reserve_check(count($GLOBALS['db']['entries'])===2 && $GLOBALS['db']['entries'][0][3]===$GLOBALS['db']['entries'][1][3],'balanced journal pair');
$before=$GLOBALS['db'];$r=bc_crypto_v2_reservation($scope,'reserve-0001','7000000');reserve_check($r['duplicate']&&$before===$GLOBALS['db'],'idempotent replay no new entries');
reserve_reject(function()use($scope){bc_crypto_v2_reservation($scope,'reserve-0001','8000000');},'conflicting replay rejected');
reserve_check($before===$GLOBALS['db'],'conflict leaves state intact');
reserve_reject(function()use($scope){bc_crypto_v2_reservation($scope,'release-0001','6000000','reserve-0001');},'release must match original full amount');
$r=bc_crypto_v2_reservation($scope,'release-0001','7000000','reserve-0001');
reserve_check($r['ok']&&$GLOBALS['db']['accounts'][1]['units']==='20000000'&&$GLOBALS['db']['accounts'][2]['units']==='0','exact original reservation released');
$before=$GLOBALS['db'];
reserve_reject(function()use($scope){bc_crypto_v2_reservation($scope,'release-0002','7000000','reserve-0001');},'cannot refund reservation twice with new reference');
reserve_check($before===$GLOBALS['db'],'double release leaves journal unchanged');
foreach(['is_blocked'=>1,'force_password_change'=>1,'status'=>0,'kyc_status'=>1] as $field=>$value){reset_reservation_db();$GLOBALS['db']['user'][$field]=$value;$before=$GLOBALS['db'];reserve_reject(function()use($scope){bc_crypto_v2_reservation($scope,'restricted-0001','1');},'restricted user '.$field);reserve_check($before===$GLOBALS['db'],'restriction cannot mutate');}
foreach(['enabled'=>0,'withdrawals_enabled'=>0,'reconciliation_status'=>'blocked','valid_until_epoch'=>time()-1,'max_reservation_units'=>'0','scale'=>8] as $field=>$value){reset_reservation_db();$GLOBALS['db']['policy'][$field]=$value;$before=$GLOBALS['db'];reserve_reject(function()use($scope){bc_crypto_v2_reservation($scope,'policy-test-0001','1');},'policy gate '.$field);reserve_check($before===$GLOBALS['db'],'policy failure cannot mutate');}
foreach(['vendor_id'=>8,'user_id'=>10,'environment'=>'mainnet','asset_network'=>'USDT:ETH'] as $field=>$value){reset_reservation_db();$GLOBALS['db']['accounts'][2][$field]=$value;$before=$GLOBALS['db'];reserve_reject(function()use($scope){bc_crypto_v2_reservation($scope,'scope-test-0001','1');},'account scope '.$field);reserve_check($before===$GLOBALS['db'],'scope failure rollback');}
for($fail=1;$fail<=5;$fail++){reset_reservation_db();$GLOBALS['fail_at']=$fail;$before=$GLOBALS['db'];reserve_reject(function()use($scope){bc_crypto_v2_reservation($scope,'failure-0001','1');},'injected write failure '.$fail);reserve_check($before===$GLOBALS['db'],'all-or-nothing rollback at write '.$fail);}
reset_reservation_db();$GLOBALS['commit_fail']=true;$before=$GLOBALS['db'];reserve_reject(function()use($scope){bc_crypto_v2_reservation($scope,'commit-fail-0001','1');},'commit failure');reserve_check($before===$GLOBALS['db'],'commit failure rollback');
reset_reservation_db();$GLOBALS['in_tx']=1;reserve_reject(function()use($scope){bc_crypto_v2_reservation($scope,'nested-test-0001','1');},'nested transaction refused');
reset_reservation_db();$GLOBALS['db']['accounts'][1]['units']='0';reserve_reject(function()use($scope){bc_crypto_v2_reservation($scope,'insufficient-0001','1');},'insufficient available funds');
reset_reservation_db();$GLOBALS['db']['policy']=null;reserve_reject(function()use($scope){bc_crypto_v2_reservation($scope,'missing-policy-0001','1');},'missing policy defaults closed');
reserve_reject(function()use($scope){bc_crypto_v2_reservation($scope,'zero-test-0001','0');},'zero refused');
echo "$n V2 transactional reservation checks passed (storage double, not live MySQL)\n";

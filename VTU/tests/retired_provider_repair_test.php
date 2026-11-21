<?php
/** Offline tests: real repair planner/executor, SQL fixture doubles; no live MySQL/provider. */
error_reporting(E_ALL);
$root = dirname(__DIR__);
require $root.'/DGV7.0-SAAS/func/bc-retired-provider-repair.php';
$n = 0;
function check_repair($ok, $label) { global $n; $n++; if (!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
class RepairRows { public $rows; function __construct($r) { $this->rows=$r; } }
function mysqli_fetch_assoc($result) { return array_shift($result->rows); }
function repair_matches($row, $sql) {
    if (strpos($sql, '0=1') !== false) return false;
    if (preg_match_all('/vendor_id=(\d+) AND (api_id|id) IN \(([\d,]+)\)/', $sql, $groups, PREG_SET_ORDER)) {
        foreach ($groups as $g) if ((int)$row['vendor_id']===(int)$g[1] && in_array((int)$row[$g[2]], array_map('intval',explode(',',$g[3])), true)) return true;
        return false;
    }
    if (preg_match('/id IN \(([\d,]+)\)/', $sql, $g)) return in_array((int)$row['id'],array_map('intval',explode(',',$g[1])),true);
    return true;
}
function mysqli_query($db, $sql) {
    $db->queries[]=$sql;
    if (strpos($sql, 'information_schema.TABLES')!==false) { $r=array(); foreach ($db->tables as $t=>$rows) $r[]=array('TABLE_NAME'=>$t,'ENGINE'=>$db->engines[$t] ?? 'InnoDB'); return new RepairRows($r); }
    if (preg_match('/^SELECT id, vendor_id, api_base_url FROM sas_apis/', $sql)) {
        $rows=$db->tables['sas_apis'];
        if (preg_match('/WHERE vendor_id=(\d+)/',$sql,$m)) $rows=array_values(array_filter($rows,function($r)use($m){return $r['vendor_id']==$m[1];}));
        return new RepairRows($rows);
    }
    if (strpos($sql,'SELECT id, api_website FROM sas_api_marketplace_listings')===0) return new RepairRows($db->tables['sas_api_marketplace_listings']);
    if (preg_match('/^SELECT COUNT\(\*\) AS n FROM `([^`]+)` WHERE /',$sql,$m)) return new RepairRows(array(array('n'=>count(array_filter($db->tables[$m[1]],function($r)use($sql){return repair_matches($r,$sql);})))));
    if (preg_match('/^DELETE FROM `([^`]+)` WHERE /',$sql,$m)) {
        if ($db->fail_table===$m[1]) return false;
        $before=count($db->tables[$m[1]]);
        $db->tables[$m[1]]=array_values(array_filter($db->tables[$m[1]],function($r)use($sql){return !repair_matches($r,$sql);}));
        $db->affected=$before-count($db->tables[$m[1]]);return true;
    }
    throw new RuntimeException('Unexpected fixture SQL');
}
function mysqli_begin_transaction($db) { $db->snapshot=$db->tables; return true; }
function mysqli_commit($db) { $db->snapshot=null; return true; }
function mysqli_rollback($db) { $db->tables=$db->snapshot; $db->snapshot=null; $db->rollbacks++; return true; }
function mysqli_affected_rows($db) { return $db->affected; }
function repair_fixture() {
    return (object)array('tables'=>array(
        'sas_apis'=>array(array('id'=>1,'vendor_id'=>10,'api_base_url'=>'https://api.LegitDataWay.com/'),array('id'=>2,'vendor_id'=>20,'api_base_url'=>'bilalsadasub.com'),array('id'=>3,'vendor_id'=>10,'api_base_url'=>'hdkdata.com')),
        'sas_sme_data_status'=>array(array('api_id'=>1,'vendor_id'=>10),array('api_id'=>1,'vendor_id'=>20),array('api_id'=>2,'vendor_id'=>20),array('api_id'=>3,'vendor_id'=>10)),
        'sas_api_parameter_values'=>array(array('api_id'=>1,'vendor_id'=>10),array('api_id'=>3,'vendor_id'=>10)),
        'sas_api_marketplace_listings'=>array(array('id'=>5,'api_website'=>'https://www.bilalsadasub.com/'),array('id'=>6,'api_website'=>'legitdataway.com.attacker.invalid')),
        'sas_transactions'=>array(array('id'=>1,'vendor_id'=>10,'api_id'=>1,'balance_after'=>9000)),
        'sas_users'=>array(array('id'=>1,'balance'=>9000))
    ),'engines'=>array(),'queries'=>array(),'snapshot'=>null,'affected'=>0,'fail_table'=>'','rollbacks'=>0);
}
foreach (array('legitdataway.com','BILALSADASUB.COM','https://api.legitdataway.com:443/api/','www.bilalsadasub.com/','https://legitdataway.com./','legitdataway-com') as $v) check_repair(bc_retired_vtu_provider($v),'retired host recognized '.$v);
foreach (array('hdkdata.com','legitdataway.com.attacker.invalid','https://legitdataway.com@hdkdata.com/','https://hdkdata.com/?next=bilalsadasub.com','notbilalsadasub.com','') as $v) check_repair(!bc_retired_vtu_provider($v),'unrelated host retained '.$v);
$db=repair_fixture();$original=$db->tables;$plan=bc_retired_repair_plan($db);
check_repair($db->tables===$original,'Preview makes no changes');
check_repair(count($plan['apis'])===2,'Both providers selected');
check_repair(count($plan['operations'])===4,'Only configuration tables planned');
check_repair(strpos(json_encode($plan),'balance_after')===false,'History excluded from repair plan');
$scoped=bc_retired_repair_plan($db,10);
check_repair(count($scoped['apis'])===1 && strpos(json_encode($scoped),'sas_api_marketplace_listings')===false,'Tenant-scoped helper cannot purge global marketplace');
$result=bc_retired_repair_execute($db,null,$plan['hash']);
check_repair(count($db->tables['sas_apis'])===1 && $db->tables['sas_apis'][0]['id']===3,'Retired API credentials removed, unrelated API kept');
check_repair(count($db->tables['sas_sme_data_status'])===2,'Dependent mappings scoped by tenant AND API');
check_repair($db->tables['sas_transactions']===$original['sas_transactions'] && $db->tables['sas_users']===$original['sas_users'],'Financial history and balances unchanged');
check_repair(count(bc_retired_repair_plan($db)['operations'])===0,'Repeated cleanup is a no-op');
$db=repair_fixture();$before=$db->tables;$plan=bc_retired_repair_plan($db);$db->fail_table='sas_api_parameter_values';
try { bc_retired_repair_execute($db,null,$plan['hash']); check_repair(false,'Failure should throw'); } catch(RuntimeException $e) { check_repair($db->tables===$before && $db->rollbacks===1,'Query failure rolls back prior deletes'); }
$db=repair_fixture();$plan=bc_retired_repair_plan($db);$db->tables['sas_apis'][]=array('id'=>4,'vendor_id'=>10,'api_base_url'=>'bilalsadasub.com');$before=$db->tables;
try { bc_retired_repair_execute($db,null,$plan['hash']); check_repair(false,'Stale plan should throw'); } catch(RuntimeException $e) { check_repair($db->tables===$before && $db->rollbacks===1,'Changed plan refused with no deletion'); }
$db=repair_fixture();$db->engines['sas_sme_data_status']='MyISAM';
try { bc_retired_repair_plan($db); check_repair(false,'MyISAM should throw'); } catch(RuntimeException $e) { check_repair(true,'Nontransactional affected table refused'); }
foreach (array('DGV7.0-SAAS','DGV7.0-NON-SAAS') as $ed) {
    foreach (array('legitdataway','bilalsadasub') as $slug) {
        check_repair(!file_exists($root.'/'.$ed.'/webhook/'.$slug.'-com.php'),'Callback removed '.$ed.' '.$slug);
        foreach (array('sme-data','cg-data','datacard','rechargecard') as $type) {
            check_repair(!file_exists($root.'/'.$ed.'/func/api-gateway/'.$type.'-'.$slug.'-com.php'),'Adapter removed '.$type.' '.$ed.' '.$slug);
            check_repair(!file_exists($root.'/'.$ed.'/func/api-gateway/requery/'.$type.'-'.$slug.'-com.php'),'Requery removed '.$type.' '.$ed.' '.$slug);
        }
    }
    $view=file_get_contents($root.'/'.$ed.'/func/bc-retired-provider-repair-view.php');
    check_repair(strpos($view,'bc_validate_csrf();')!==false && strpos($view,"$"."verifier($"."repair_actor, $"."factors,")!==false,'Repair uses CSRF and whitelisted factor input '.$ed);
    check_repair(!file_exists($root.'/'.$ed.'/api.php'),'Callback debug harness removed '.$ed);
    check_repair(file_get_contents($root.'/'.$ed.'/func/bc-retired-provider-repair.php')===file_get_contents($root.'/DGV7.0-SAAS/func/bc-retired-provider-repair.php'),'Repair helper parity '.$ed);
}
echo "$n checks passed. SQL doubles do not prove live MySQL concurrency or deployment.\n";

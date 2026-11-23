<?php
if(!isset($created,$name)||!$created||!preg_match('/^crypto_ci_[a-f0-9]{24}$/D',$name))throw new RuntimeException('Disposable runner required');
require_once $base.'bc-crypto-dashboard.php';
// Extend only disposable legacy fixtures for fresh-actor checks; production requests never DDL.
bc_q('ALTER TABLE sas_vendors ADD force_password_change INT NOT NULL DEFAULT 0');
bc_q('CREATE TABLE sas_super_admin(id BIGINT UNSIGNED PRIMARY KEY,status INT,is_blocked INT) ENGINE=InnoDB');bc_q('INSERT INTO sas_super_admin VALUES(1,1,0)');
bc_q('INSERT INTO sas_users(id,vendor_id,status,is_blocked,force_password_change,kyc_status) VALUES(901,8,1,0,0,2),(902,7,1,0,0,2)');
foreach([[7,11,'mainnet','DASH:TRX','available','123456789012345678901234567890123456'],[7,902,'mainnet','OTHER:TRX','held','22'],[8,901,'mainnet','TENANT:TRX','held','33'],[7,11,'testnet','TEST:TRX','available','44']] as $r)bc_q('INSERT INTO sas_crypto_v2_accounts(vendor_id,user_id,environment,asset_network,bucket,units,scale) VALUES(?,?,?,?,?,?,18)',$r);
bc_q('UPDATE sas_users SET status=1,is_blocked=0,force_password_change=0 WHERE id=11');
$u=bc_crypto_dashboard_identity('user',['id'=>11,'vendor_id'=>7]);$v=bc_crypto_dashboard_identity('vendor',['id'=>7]);$p=bc_crypto_dashboard_identity('platform',['id'=>1]);
$before=bc_q_row('SELECT COUNT(*) AS n,SUM(units) AS total FROM sas_crypto_v2_accounts');$ops=scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_operations');$dep=scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_deposits');$streams=scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_poll_streams');
$m=bc_crypto_dashboard_load($u,'mainnet');check_db($m['balances']['available']&&count($m['balances']['rows'])===1&&$m['balances']['rows'][0]['asset_network']==='DASH:TRX','user buckets bind tenant,user and environment');
check_db(bc_crypto_dashboard_amount($m['balances']['rows'][0]['units'],$m['balances']['rows'][0]['scale'])==='123456789012345678.901234567890123456','large displayed balance exact to18 places, no float');
$t=bc_crypto_dashboard_load($u,'testnet');check_db(!in_array('DASH:TRX',array_column($t['balances']['rows'],'asset_network'),true),'testnet/mainnet cannot be blended');
$vendor=bc_crypto_dashboard_load($v,'testnet');$platform=bc_crypto_dashboard_load($p,'testnet');check_db(!isset($vendor['balances'])&&!isset($platform['balances'])&&isset($vendor['streams']),'operations views do not expose arbitrary customer balance selector');
foreach(['policies','deposits','operations','streams'] as $section)check_db($vendor[$section]['available']&&$platform[$section]['available'],'real schema query valid: '.$section);
foreach($vendor['streams']['rows'] as $s)check_db((int)$s['vendor_id']===7,'vendor collection metadata isolated by source vendor');
// Deposit and operation fixtures outside this user scope must remain absent.
foreach([[7,11,'mainnet','dash-user'],[7,902,'mainnet','dash-other-user'],[8,901,'mainnet','dash-other-tenant'],[7,11,'testnet','dash-testnet']] as $r)bc_q("INSERT INTO sas_crypto_v2_operations(vendor_id,user_id,environment,reference,asset_network,kind,units,payload_hash) VALUES(?,?,?,?,'DASH:TRX','reserve',1,?)",array_merge($r,[str_repeat('a',64)]));
$m=bc_crypto_dashboard_load($u,'mainnet');check_db(array_column($m['operations']['rows'],'reference')===['dash-user'],'customer operation history cannot enumerate another user,tenant or environment');
$vendor=bc_crypto_dashboard_load($v,'mainnet');check_db(count($vendor['operations']['rows'])===2,'vendor history limited to own tenant');
// Sections return explicit unavailable on failed table access, never zero.
bc_q('RENAME TABLE sas_crypto_v2_asset_policies TO fixture_hidden_policies');$missing=bc_crypto_dashboard_load($u,'mainnet');check_db(!$missing['policies']['available']&&$missing['balances']['available'],'missing section schema is distinct from empty and other sections remain readable');bc_q('RENAME TABLE fixture_hidden_policies TO sas_crypto_v2_asset_policies');
foreach(['status','is_blocked','force_password_change'] as $field){bc_q('UPDATE sas_users SET '.$field.'=? WHERE id=11',[$field==='status'?0:1]);reject_db(fn()=>bc_crypto_dashboard_identity('user',['id'=>11,'vendor_id'=>7]),'crypto_dashboard_access_denied');bc_q('UPDATE sas_users SET '.$field.'=? WHERE id=11',[$field==='status'?1:0]);}
bc_q('UPDATE sas_vendors SET is_blocked=1 WHERE id=7');reject_db(fn()=>bc_crypto_dashboard_identity('user',['id'=>11,'vendor_id'=>7]),'crypto_dashboard_access_denied');bc_q('UPDATE sas_vendors SET is_blocked=0 WHERE id=7');
reject_db(fn()=>bc_crypto_dashboard_identity('user',['id'=>11,'vendor_id'=>8]),'crypto_dashboard_access_denied');
// Bounded sections clearly announce truncation.
for($i=0;$i<51;$i++)bc_q("INSERT INTO sas_crypto_v2_operations(vendor_id,user_id,environment,reference,asset_network,kind,units,payload_hash) VALUES(7,11,'mainnet',?,'DASH:TRX','reserve',1,?)",['dash-limit-'.$i,str_repeat('b',64)]);
$m=bc_crypto_dashboard_load($u,'mainnet');check_db(count($m['operations']['rows'])===50&&$m['operations']['truncated'],'bounded user history advertises omitted rows');
check_db(bc_q_row('SELECT COUNT(*) AS n,SUM(units) AS total FROM sas_crypto_v2_accounts')===$before&&scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_operations')===$ops+55&&scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_deposits')===$dep&&scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_poll_streams')===$streams,'only explicit fixture writes, dashboard reads leave balances/holds/deposits/streams/journal unchanged');
// Observation receipts bind the same tenant/customer/environment before fetching an ID.
$receiptIds=[];
foreach([[7,11,'mainnet'],[7,902,'mainnet'],[8,901,'mainnet'],[7,11,'testnet']] as $i=>$scope){
    bc_q("INSERT INTO sas_crypto_v2_deposits(vendor_id,user_id,environment,custody_account,provider_record_id,asset_network,scale,units,identity_hash,last_observation_hash,state,reason) VALUES(?,?,?, ?,?,'DASH:TRX',6,1234567,?,?,'quarantined','attribution_unapproved')",array_merge($scope,[str_repeat('c',64),'receipt-fixture-'.$i,str_repeat('d',64),str_repeat('e',64)]));
    $receiptIds[]=(string)$connection_server->insert_id;
}
$r=bc_crypto_dashboard_receipt($u,'mainnet',$receiptIds[0]);check_db($r['available']&&count($r['rows'])===1&&$r['rows'][0]['state']==='quarantined','receipt shows real review status, not settlement');
foreach(array_slice($receiptIds,1) as $rid)check_db(bc_crypto_dashboard_receipt($u,'mainnet',$rid)['rows']===[],'receipt hides other customer/tenant/environment');
check_db(count(bc_crypto_dashboard_receipt($v,'mainnet',$receiptIds[1])['rows'])===1,'vendor can inspect own customer observation');
check_db(bc_crypto_dashboard_receipt($v,'mainnet',$receiptIds[2])['rows']===[],'vendor cannot inspect foreign tenant observation');
check_db(count(bc_crypto_dashboard_receipt($p,'mainnet',$receiptIds[2])['rows'])===1,'platform can inspect scoped environment observation');
foreach(['0','01','-1','1 OR 1=1',[],1] as $rid)reject_db(fn()=>bc_crypto_dashboard_receipt($u,'mainnet',$rid),'crypto_dashboard_receipt_invalid');
foreach(['user','vendor','platform'] as $role){$id=$role==='user'?$u:($role==='vendor'?$v:$p);$m=bc_crypto_dashboard_load($id,'testnet');if($role==='user')check_db(!isset($m['candidates']),'customer cannot enumerate attribution candidates');else{check_db($m['candidates']['available'],'route review queue uses actual schema');foreach($m['candidates']['rows'] as $c)if($role==='vendor')check_db((int)$c['vendor_id']===7,'candidate metadata tenant isolated');}}
check_db(bc_q_row('SELECT COUNT(*) AS n,SUM(units) AS total FROM sas_crypto_v2_accounts')===$before,'receipt and candidate views preserve exact bucket units and holds');

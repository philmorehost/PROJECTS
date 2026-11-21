<?php
if (!isset($created,$name)||!$created||!preg_match('/^crypto_ci_[a-f0-9]{24}$/D',$name))throw new RuntimeException('Disposable runner required');
$alert=bc_q_row('SELECT * FROM sas_crypto_v2_poll_alerts ORDER BY created_at_epoch DESC LIMIT 1');
check_db($alert&&$alert['kind']==='retry_escalation'&&$alert['acknowledged_by']===null,'third failed attempt retains durable unacknowledged escalation');
$id=$alert['stream_id'];$ar=(int)$alert['revision'];$cr=(int)bc_q_row('SELECT revision FROM sas_crypto_v2_custody_accounts WHERE custody_account=?',[$pi])['revision'];
$stream_before=poll_row_fixture($id);$history=scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_poll_lease_events');
reject_db(fn()=>bc_crypto_poll_alert_ack($pa,str_repeat('f',64),'testnet',$cr,$id,$ar),'crypto_poll_scope_mismatch');
reject_db(fn()=>bc_crypto_poll_alert_ack($pa,$pi,'mainnet',$cr,$id,$ar),'crypto_poll_scope_mismatch');
reject_db(fn()=>bc_crypto_poll_alert_ack($pa,$pi,'testnet',$cr+1,$id,$ar),'crypto_custody_edit_conflict');
reject_db(fn()=>bc_crypto_poll_alert_ack(['role'=>'vendor','id'=>7],$pi,'testnet',$cr,$id,$ar),'crypto_custody_platform_authority_required');
reject_db(fn()=>bc_crypto_poll_alert_ack($pa,$pi,'testnet',$cr,$id,$ar+100),'crypto_poll_alert_missing');
$connection_server->query("CREATE TRIGGER fail_alert_ack BEFORE UPDATE ON sas_crypto_v2_poll_alerts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected ack failure'");
try{bc_crypto_poll_alert_ack($pa,$pi,'testnet',$cr,$id,$ar);throw new LogicException('Ack unexpectedly succeeded');}catch(mysqli_sql_exception $e){check_db($e->getSqlState()==='45000','ack storage failure surfaced');}$connection_server->query('DROP TRIGGER fail_alert_ack');
check_db(bc_q_row('SELECT acknowledged_by FROM sas_crypto_v2_poll_alerts WHERE stream_id=? AND revision=?',[$id,$ar])['acknowledged_by']===null,'failed acknowledgement leaves alert unacknowledged');
$x=bc_crypto_poll_alert_ack($pa,$pi,'testnet',$cr,$id,$ar);check_db($x['alert_acknowledged']&&!$x['settlement_enabled'],'ack grants no settlement authority');
$a=bc_q_row('SELECT * FROM sas_crypto_v2_poll_alerts WHERE stream_id=? AND revision=?',[$id,$ar]);check_db((int)$a['acknowledged_by']===$pa['id']&&(int)$a['acknowledged_at_epoch']>0,'ack records server-derived actor and timestamp');
reject_db(fn()=>bc_crypto_poll_alert_ack(['role'=>'spadmin','id'=>2],$pi,'testnet',$cr,$id,$ar),'crypto_poll_alert_already_acknowledged');
check_db(poll_row_fixture($id)===$stream_before&&scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_poll_lease_events')===$history,'ack cannot reset stream or retry history');
$connection_server->begin_transaction();reject_db(fn()=>bc_crypto_poll_alert_ack($pa,$pi,'testnet',$cr,$id,$ar),'crypto_transaction_unavailable');$connection_server->rollback();
// Release and alert creation are atomic. Test-only low-level claims avoid sleeping through backoff.
$c=poll_create_fixture();$ci=$c['stream_id'];for($i=0;$i<2;$i++){$t=bc_crypto_poll_claim($pa,$ci,1);bc_crypto_poll_release_failed($pa,$ci,1,$t);}
$t=bc_crypto_poll_claim($pa,$ci,1);$before=scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_poll_lease_events');
$connection_server->query("CREATE TRIGGER fail_alert_create BEFORE INSERT ON sas_crypto_v2_poll_alerts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected alert storage failure'");
try{bc_crypto_poll_release_failed($pa,$ci,1,$t);throw new LogicException('Release unexpectedly succeeded');}catch(mysqli_sql_exception $e){check_db($e->getSqlState()==='45000','alert creation failure surfaces');}$connection_server->query('DROP TRIGGER fail_alert_create');
check_db(scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_poll_lease_events')===$before&&(int)bc_q_row('SELECT expires_at_epoch FROM sas_crypto_v2_poll_leases WHERE stream_id=?',[$ci])['expires_at_epoch']>0,'failed alert insertion rolls back failed-release audit and lease release');
bc_crypto_poll_release_failed($pa,$ci,1,$t);check_db((int)scalar_db("SELECT COUNT(*) FROM sas_crypto_v2_poll_alerts WHERE stream_id='".$ci."'")===1,'retry retains one escalation alert per revision');
$report=json_decode(bc_crypto_poll_review($pa,$pi,'testnet',$cr),true);check_db(count($report['alerts'])>0&&count($report['alerts'])<=50&&is_bool($report['alerts_truncated']),'protected audited report exposes bounded local alert receipts');
check_db(scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_operations')===$opsBefore&&scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_deposits')===$depBefore&&scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_deposit_routes')===$routesBefore,'alerts and acknowledgements leave financial objects unchanged');

$payload=json_encode(['actor'=>$pa,'identity'=>$pi,'environment'=>'testnet','custody_revision'=>$cr,'id'=>$ci,'revision'=>1]);$wa=start_worker('ack_poll_alert',$payload);$wb=start_worker('ack_poll_alert',$payload);$rs=[finish_worker($wa),finish_worker($wb)];
check_db(count(array_filter($rs,fn($r)=>isset($r['result'])))===1&&count(array_filter($rs,fn($r)=>($r['error']??'')==='crypto_poll_alert_already_acknowledged'))===1,'concurrent acknowledgements preserve one immutable first receipt');

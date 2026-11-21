<?php
if (!isset($created,$name)||!$created||!preg_match('/^crypto_ci_[a-f0-9]{24}$/D',$name))throw new RuntimeException('Disposable runner required');
$rev=(int)bc_q_row('SELECT revision FROM sas_crypto_v2_custody_accounts WHERE custody_account=?',[$pi])['revision'];
$before=scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_poll_review_events');
$text=bc_crypto_poll_review($pa,$pi,'testnet',$rev);$report=json_decode($text,true,512,JSON_THROW_ON_ERROR);
check_db(count($report['sightings'])>0&&count($report['sightings'])<=50&&!$report['history_complete']&&!$report['conflicts_resolved']&&!$report['settlement_enabled'],'bounded review report grants no completeness/resolution/settlement authority');
check_db(strpos($text,'T9yD14')===false&&strpos($text,'synthetic-key')===false&&strpos($text,'sealed_state')===false,'review contains no raw address/config/ciphertext');
check_db(scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_poll_review_events')===$before+1&&bc_q_row('SELECT report_hash FROM sas_crypto_v2_poll_review_events ORDER BY id DESC LIMIT 1')['report_hash']===hash('sha256',$text),'audit binds exact returned report before disclosure');
foreach($report['sightings'] as $s)check_db(count($s['competing_pairs'])<=10&&$s['current_conflict']===(count($s['competing_pairs'])>0),'current conflicts derived from bounded associations');
reject_db(fn()=>bc_crypto_poll_review($pa,$pi,'mainnet',$rev),'crypto_poll_scope_mismatch');
reject_db(fn()=>bc_crypto_poll_review($pa,$pi,'testnet',$rev+1),'crypto_custody_edit_conflict');
reject_db(fn()=>bc_crypto_poll_review(['role'=>'vendor','id'=>7],$pi,'testnet',$rev),'crypto_custody_platform_authority_required');
$connection_server->query("CREATE TRIGGER fail_poll_review BEFORE INSERT ON sas_crypto_v2_poll_review_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected review audit failure'");
try{bc_crypto_poll_review($pa,$pi,'testnet',$rev);throw new LogicException('Report returned despite failed audit');}catch(mysqli_sql_exception $e){check_db($e->getSqlState()==='45000','failed review audit prevents report return');}$connection_server->query('DROP TRIGGER fail_poll_review');
check_db(scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_poll_review_events')===$before+1,'failed review leaves no successful access audit');
$connection_server->begin_transaction();reject_db(fn()=>bc_crypto_poll_review($pa,$pi,'testnet',$rev),'crypto_transaction_unavailable');$connection_server->rollback();
check_db(scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_operations')===$opsBefore&&scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_deposits')===$depBefore&&scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_deposit_routes')===$routesBefore,'review does not import/route/settle');

check_db(count($report['streams'])<=20&&is_bool($report['streams_truncated']),'health report bounds stream monitoring independently of sightings');
foreach($report['streams'] as $stream){
    check_db($stream['recent_lease_event_count']<=100&&is_bool($stream['attempt_history_truncated']),'attempt monitoring bounded and truncation explicit');
    check_db(!$stream['health']['automatic_retry_authorized']&&!$stream['health']['replacement_window_authorized']&&!$stream['health']['settlement_enabled'],'health advice cannot grant worker/replacement/settlement authority');
}

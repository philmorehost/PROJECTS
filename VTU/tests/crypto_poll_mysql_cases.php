<?php
if (!isset($created,$name)||!$created||!preg_match('/^crypto_ci_[a-f0-9]{24}$/D',$name)) throw new RuntimeException('Disposable runner required');
require_once __DIR__.'/crypto_poll_fixture.php';
foreach(['KEY'=>'synthetic-key','SECRET'=>'synthetic-secret-not-real','EXPECTED_IPS'=>'192.0.2.1','ENV'=>'testnet'] as $k=>$v)putenv('BC_BYBIT_VENDOR_7_'.$k.'='.$v);
$pa=['role'=>'spadmin','id'=>1];$pi=bc_crypto_custody_identity('testnet','1234567');
$po=scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_operations');$pd=scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_deposits');$pr=scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_deposit_routes');
$pb=bc_q_row("SELECT GROUP_CONCAT(CONCAT(bucket,':',units) ORDER BY bucket) AS b FROM sas_crypto_v2_accounts");
function poll_create_fixture(){global $pa,$pi;return bc_crypto_poll_create($pa,'testnet',$pi,4,7,'USDT','1699999900000','1700000000000');}
function poll_row_fixture($id){return bc_q_row('SELECT * FROM sas_crypto_v2_poll_streams WHERE stream_id=?',[$id]);}
function poll_evidence_fixture($id,$next='',$record='1',$index='0'){$r=poll_row_fixture($id);return poll_fixture(bc_crypto_poll_open($r),$r,$next,$record,$index);}
$s=poll_create_fixture();$sid=$s['stream_id'];$r=poll_row_fixture($sid);
check_db($s['revision']===1&&$s['state']==='ready'&&!$s['import_enabled'],'new stream immutable window begins without import authority');
check_db(strpos($r['sealed_state'],'1699999900000')===false&&strpos($r['sealed_state'],'synthetic-key')===false,'query/config details encrypted or fingerprinted');
$beforeViews=scalar_db("SELECT COUNT(*) FROM sas_crypto_v2_custody_evidence_events WHERE action='viewed'");
$p=bc_crypto_poll_prepare($pa,$sid,1);check_db($p['cursor']===''&&$p['query']['limit']==='5','initial cursor from encrypted server state only');
check_db(scalar_db("SELECT COUNT(*) FROM sas_crypto_v2_custody_evidence_events WHERE action='viewed'")===$beforeViews,'internal cursor read never creates human review receipt');
$e=poll_evidence_fixture($sid,'secret-opaque-cursor');$x=bc_crypto_poll_commit($pa,$sid,1,$e);
check_db($x['revision']===2&&$x['state']==='ready'&&!$x['history_complete']&&!$x['settlement_enabled'],'atomic first page progress is not settlement/history completeness');
$r=poll_row_fixture($sid);check_db((int)$r['page_count']===1&&strpos(json_encode($r),'secret-opaque-cursor')===false,'no cursor plaintext in stream metadata');
$stored=bc_q_row('SELECT * FROM sas_crypto_v2_custody_evidence WHERE custody_account=? AND evidence_hash=?',[$pi,$x['evidence_hash']]);
check_db($stored&&strpos($stored['sealed_document'],$e['page']['records'][0]['address'])===false,'page record address encrypted in evidence');
check_db(bc_crypto_poll_prepare($pa,$sid,2)['cursor']==='secret-opaque-cursor','resume returns committed cursor after audited read');
reject_db(fn()=>bc_crypto_poll_commit($pa,$sid,1,$e),'crypto_poll_edit_conflict');
$bad=poll_evidence_fixture($sid,'next','1','1');reject_db(fn()=>bc_crypto_poll_commit($pa,$sid,2,$bad),'crypto_poll_duplicate_record');
$bad=poll_evidence_fixture($sid,'next','2','0');reject_db(fn()=>bc_crypto_poll_commit($pa,$sid,2,$bad),'crypto_poll_duplicate_record');
check_db((int)poll_row_fixture($sid)['revision']===2,'cross-page id/transfer aliases never advance progress');
$end=poll_evidence_fixture($sid,'','2','1');$x=bc_crypto_poll_commit($pa,$sid,2,$end);
check_db($x['state']==='exhausted'&&(int)poll_row_fixture($sid)['page_count']===2,'empty next token exhausts fixed window, not account history');
reject_db(fn()=>bc_crypto_poll_prepare($pa,$sid,3),'crypto_poll_not_ready');
// A three-page cursor cycle is refused before any checkpoint/evidence change.
$c=poll_create_fixture();$ci=$c['stream_id'];bc_crypto_poll_commit($pa,$ci,1,poll_evidence_fixture($ci,'A','10','10'));bc_crypto_poll_commit($pa,$ci,2,poll_evidence_fixture($ci,'B','11','11'));
reject_db(fn()=>bc_crypto_poll_commit($pa,$ci,3,poll_evidence_fixture($ci,'A','12','12')),'crypto_poll_cursor_cycle');
// Audit failure after writes must roll back evidence, identities and cursor together.
$f=poll_create_fixture();$fi=$f['stream_id'];$before=poll_row_fixture($fi);$ec=scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_custody_evidence');$seen=scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_poll_seen');
$connection_server->query("CREATE TRIGGER fail_poll_commit BEFORE INSERT ON sas_crypto_v2_poll_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected poll audit failure'");
try{bc_crypto_poll_commit($pa,$fi,1,poll_evidence_fixture($fi,'next','20','20'));throw new LogicException('Poll audit unexpectedly succeeded');}catch(mysqli_sql_exception $e){check_db($e->getSqlState()==='45000','checkpoint audit failure surfaced');}
check_db(poll_row_fixture($fi)===$before&&scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_custody_evidence')===$ec&&scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_poll_seen')===$seen,'audit failure rolls back checkpoint, encrypted evidence and seen identities');
try{bc_crypto_poll_prepare($pa,$fi,1);throw new LogicException('Prepare audit unexpectedly succeeded');}catch(mysqli_sql_exception $e){check_db($e->getSqlState()==='45000','failed pre-read audit prevents cursor return');}
$connection_server->query('DROP TRIGGER fail_poll_commit');
// Concurrent delivery of the same expected revision: one atomic winner, one stale loser.
$e=poll_evidence_fixture($fi,'next','20','20');$payload=json_encode(['actor'=>$pa,'id'=>$fi,'revision'=>1,'evidence'=>$e]);$a=start_worker('poll',$payload);$b=start_worker('poll',$payload);$rs=[finish_worker($a),finish_worker($b)];
check_db(count(array_filter($rs,fn($v)=>isset($v['result'])))===1&&count(array_filter($rs,fn($v)=>($v['error']??'')==='crypto_poll_edit_conflict'))===1,'competing connections commit one checkpoint page only');
check_db((int)poll_row_fixture($fi)['page_count']===1,'concurrent duplicate does not double-advance');
putenv('BC_BYBIT_VENDOR_7_SECRET=rotated-synthetic-secret');reject_db(fn()=>bc_crypto_poll_prepare($pa,$fi,2),'crypto_poll_config_changed');putenv('BC_BYBIT_VENDOR_7_SECRET=synthetic-secret-not-real');
bc_q('UPDATE sas_vendors SET is_blocked=1 WHERE id=7');reject_db(fn()=>bc_crypto_poll_prepare($pa,$fi,2),'crypto_poll_vendor_restricted');bc_q('UPDATE sas_vendors SET is_blocked=0 WHERE id=7');
$control=bc_crypto_gov_row(0,'testnet','control');bc_q('UPDATE sas_crypto_v2_governance SET document=? WHERE vendor_id=0 AND policy_key=?',['{"stopped":true}','control']);reject_db(fn()=>bc_crypto_poll_prepare($pa,$fi,2),'crypto_emergency_stop');bc_q('UPDATE sas_crypto_v2_governance SET document=? WHERE vendor_id=0 AND policy_key=?',[$control['document'],'control']);
bc_q('UPDATE sas_crypto_v2_custody_accounts SET revision=5 WHERE custody_account=?',[$pi]);reject_db(fn()=>bc_crypto_poll_prepare($pa,$fi,2),'crypto_custody_edit_conflict');check_db(bc_crypto_poll_stop($pa,$fi,2)['state']==='stopped','stop works after custody/config/policy changes');bc_q('UPDATE sas_crypto_v2_custody_accounts SET revision=4 WHERE custody_account=?',[$pi]);
$expired=poll_create_fixture();bc_q('UPDATE sas_crypto_v2_poll_streams SET valid_until_epoch=0 WHERE stream_id=?',[$expired['stream_id']]);reject_db(fn()=>bc_crypto_poll_prepare($pa,$expired['stream_id'],1),'crypto_poll_not_ready');
$key=getenv('BC_CRYPTO_CUSTODY_EVIDENCE_KEY_V1');putenv('BC_CRYPTO_CUSTODY_EVIDENCE_KEY_V1');reject_db(fn()=>bc_crypto_poll_prepare($pa,$ci,3),'crypto_evidence_key_unavailable');check_db(bc_crypto_poll_stop($pa,$ci,3)['state']==='stopped','stop remains available with missing evidence key');putenv('BC_CRYPTO_CUSTODY_EVIDENCE_KEY_V1='.$key);
$connection_server->begin_transaction();reject_db(fn()=>bc_crypto_poll_prepare($pa,$ci,3),'crypto_transaction_unavailable');$connection_server->rollback();
check_db(scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_operations')===$po&&scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_deposits')===$pd&&scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_deposit_routes')===$pr&&bc_q_row("SELECT GROUP_CONCAT(CONCAT(bucket,':',units) ORDER BY bucket) AS b FROM sas_crypto_v2_accounts")===$pb,'all checkpoint paths leave observations, routes, journals, balances and holds unchanged');

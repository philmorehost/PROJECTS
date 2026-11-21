<?php
// Included ONLY by the guarded disposable MySQL runner after accounting tests.
if (!isset($created,$name) || !$created || !preg_match('/^crypto_ci_[a-f0-9]{24}$/D',$name)) throw new RuntimeException('Disposable runner required');
$context=['vendor_id'=>7,'environment'=>'testnet','custody_account'=>str_repeat('c',64)];
$deposit=['record_id'=>'provider-record-001','asset_network'=>'USDT:TRX','scale'=>6,'amount'=>'2.000001','address'=>'SyntheticAddress','tag'=>'123','txid'=>'synthetic-chain-id-001','confirmations'=>'5','status'=>'pending','risk'=>'clear','evidence_hash'=>str_repeat('d',64)];
$balances=bc_q_row("SELECT GROUP_CONCAT(CONCAT(bucket,':',units) ORDER BY bucket) AS balances FROM sas_crypto_v2_accounts");
$operations=scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_operations');
// No source code provisions routes or enables real deposits. Fixtures exist ONLY in this random database.
$routing=bc_crypto_deposit_route_hash('USDT:TRX',$deposit['address'],$deposit['tag']);
bc_q("INSERT INTO sas_crypto_v2_deposit_routes(vendor_id,user_id,environment,custody_account,asset_network,routing_hash,scale,attribution_evidence_hash,valid_until_epoch) VALUES(7,11,'testnet',?,'USDT:TRX',?,6,?,?)",[$context['custody_account'],$routing,str_repeat('e',64),time()+3600]);
$closed=bc_crypto_deposit_observe($context,$deposit);
check_db($closed['state']==='quarantined' && $closed['reason']==='attribution_unapproved','default-disabled route quarantines even with provider-like claims');
bc_q('UPDATE sas_crypto_v2_deposit_routes SET enabled=1');
$again=bc_crypto_deposit_observe($context,$deposit);
check_db($again['state']==='quarantined' && $again['duplicate'],'enabling route does not unfreeze old observation');
$deposit['record_id']='provider-record-002';$deposit['txid']='synthetic-chain-id-002';
$r=bc_crypto_deposit_observe($context,$deposit);
check_db($r['state']==='observed' && !$r['settlement_enabled'],'valid attribution and pending confirmations remain observation-only');
$deposit['status']='complete';$deposit['confirmations']='40';
$r=bc_crypto_deposit_observe($context,$deposit);
check_db($r['state']==='review_ready' && !$r['duplicate'] && !$r['settlement_enabled'],'confirmation progress reaches review queue, never a wallet credit');
$count=scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_deposit_events');
check_db(bc_crypto_deposit_observe($context,$deposit)['duplicate'] && scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_deposit_events')===$count,'exact duplicate creates no extra audit event');
// Changed immutable amount cannot overwrite the original normalized economics.
$conflict=$deposit;$conflict['amount']='3';
$r=bc_crypto_deposit_observe($context,$conflict);
check_db($r['state']==='quarantined' && $r['reason']==='identity_conflict','same record with changed amount quarantines');
$row=bc_q_row('SELECT * FROM sas_crypto_v2_deposits WHERE id=?',[$r['deposit_id']]);
check_db($row['units']==='2000001' && (int)$row['user_id']===11,'conflict preserves original exact units and owner');
$other=$context;$other['vendor_id']=8;
reject_db(fn()=>bc_crypto_deposit_observe($other,$deposit),'crypto_deposit_scope_conflict');
check_db((int)scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_deposits')===2,'cross-vendor replay creates no second claim');
$wrong=$deposit;$wrong['record_id']='wrong-tag-001';$wrong['tag']='124';
check_db(bc_crypto_deposit_observe($context,$wrong)['reason']==='attribution_unapproved','wrong shared-address memo cannot route');
$wrong['record_id']='wrong-network-001';$wrong['asset_network']='USDT:ETH';$wrong['tag']='123';
check_db(bc_crypto_deposit_observe($context,$wrong)['reason']==='attribution_unapproved','wrong network cannot route');
$wrong=$deposit;$wrong['record_id']='wrong-env-001';$test_context=$context;$test_context['environment']='mainnet';
check_db(bc_crypto_deposit_observe($test_context,$wrong)['reason']==='attribution_unapproved','environment cannot borrow testnet attribution');
$regression=$deposit;$regression['record_id']='regression-001';
check_db(bc_crypto_deposit_observe($context,$regression)['state']==='review_ready','complete observation before regression');
$regression['confirmations']='39';
check_db(bc_crypto_deposit_observe($context,$regression)['reason']==='provider_regression','lower/reordered confirmation report quarantines');
$failed=$deposit;$failed['record_id']='failed-001';$failed['status']='failed';
check_db(bc_crypto_deposit_observe($context,$failed)['reason']==='provider_failed','failed provider state quarantines');
$failed['record_id']='reorg-001';$failed['status']='reorged';
check_db(bc_crypto_deposit_observe($context,$failed)['reason']==='provider_reorged','reorg provider state quarantines');
// Atomic audit failure must also roll back a newly inserted observation.
$before=scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_deposits');
$connection_server->query("CREATE TRIGGER fail_deposit_audit BEFORE INSERT ON sas_crypto_v2_deposit_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected deposit audit failure'");
$failure=$deposit;$failure['record_id']='audit-failure-001';
try{bc_crypto_deposit_observe($context,$failure);throw new LogicException('Deposit audit succeeded');}catch(mysqli_sql_exception $e){check_db($e->getSqlState()==='45000','server rejects deposit audit insert');}
check_db(scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_deposits')===$before,'audit failure rolls back new deposit record');
$connection_server->query('DROP TRIGGER fail_deposit_audit');
// Competing independent processes share one external record identity.
$concurrent=$deposit;$concurrent['record_id']='deposit-concurrent-001';
$a=start_worker('deposit',json_encode($context),json_encode($concurrent));$b=start_worker('deposit',json_encode($context),json_encode($concurrent));
$ra=finish_worker($a);$rb=finish_worker($b);
check_db(isset($ra['result'],$rb['result']) && $ra['result']['duplicate']!==$rb['result']['duplicate'],'concurrent observation import returns one duplicate');
check_db((int)scalar_db("SELECT COUNT(*) FROM sas_crypto_v2_deposits WHERE provider_record_id='deposit-concurrent-001'")===1,'concurrent external record stored once');
// Identical repeat MUST recheck current policy, not preserve a stale green label through a stop.
bc_q("UPDATE sas_crypto_v2_governance SET document='{\"stopped\":true}' WHERE vendor_id=0 AND policy_key='control'");
$r=bc_crypto_deposit_observe($context,$concurrent);
check_db($r['state']==='quarantined' && $r['reason']==='crypto_emergency_stop' && !$r['duplicate'],'duplicate during stop reevaluates safety and quarantines');
$connection_server->begin_transaction();
reject_db(fn()=>bc_crypto_deposit_observe($context,$deposit),'crypto_transaction_unavailable');
bc_q('INSERT INTO sas_users VALUES(98,7,1,0,0,2)');$connection_server->rollback();
check_db((int)scalar_db('SELECT COUNT(*) FROM sas_users WHERE id=98')===0,'deposit intake preserves caller-owned transaction');
check_db($balances===bc_q_row("SELECT GROUP_CONCAT(CONCAT(bucket,':',units) ORDER BY bucket) AS balances FROM sas_crypto_v2_accounts") && $operations===scalar_db('SELECT COUNT(*) FROM sas_crypto_v2_operations'),'all deposit paths leave balances, holds and accounting journal untouched');

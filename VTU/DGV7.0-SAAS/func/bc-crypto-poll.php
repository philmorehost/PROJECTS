<?php
/** Internal checkpoint protocol, not authentication. No public route, scheduler, import or credit. */
require_once __DIR__.'/bc-crypto-bybit-deposit-read.php';
require_once __DIR__.'/bc-crypto-custody-evidence.php';
require_once __DIR__.'/bc-crypto-poll-overlap.php';
function bc_crypto_poll_id($id) {
    if (!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id)) throw new RuntimeException('crypto_poll_id_invalid');
    return $id;
}
function bc_crypto_poll_config($vendor,$env) {
    $c=bc_bybit_config($vendor);
    if ($c['environment']!==$env) throw new RuntimeException('crypto_bybit_environment_mismatch');
    $ips=$c['expected_ips'];sort($ips);
    return ['hash'=>hash('sha256',bc_crypto_gov_json($c)),
        'public'=>hash('sha256',bc_crypto_gov_json([$vendor,$env,$c['key'],$ips]))];
}
function bc_crypto_poll_engines() {
    bc_crypto_custody_evidence_engines();
    foreach(['sas_crypto_v2_poll_identities','sas_crypto_v2_poll_sightings','sas_crypto_v2_poll_streams','sas_crypto_v2_poll_events','sas_crypto_v2_poll_seen','sas_crypto_v2_poll_leases','sas_crypto_v2_poll_lease_events','sas_vendors','sas_crypto_v2_governance','sas_crypto_v2_catalogues'] as $table) {
        $r=bc_q_row('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table]);
        if (!$r||strtoupper($r['engine']??'')!=='INNODB') throw new RuntimeException('crypto_poll_transactional_schema_required');
    }
}
/** Caller transaction owns custody lock first, then stream, vendor and governance locks. */
function bc_crypto_poll_guard($row,$doc) {
    $custody=bc_q_row('SELECT * FROM sas_crypto_v2_custody_accounts WHERE custody_account=? FOR UPDATE',[$row['custody_account']]);
    if (!$custody||$custody['provider']!=='bybit'||$custody['environment']!==$row['environment']||(int)$custody['revision']!==(int)$row['custody_revision']) throw new RuntimeException('crypto_custody_edit_conflict');
    $vid=(int)$row['vendor_id'];$env=$row['environment'];$now=time();
    if (!bc_crypto_custody_eligible(bc_crypto_gov_decode($custody),$vid,$now)) throw new RuntimeException('crypto_poll_custody_unapproved');
    $v=bc_q_row('SELECT id,status,is_blocked FROM sas_vendors WHERE id=? FOR UPDATE',[$vid]);
    if (!$v||(int)$v['status']!==1||!empty($v['is_blocked'])) throw new RuntimeException('crypto_poll_vendor_restricted');
    $pc=bc_crypto_gov_decode(bc_crypto_gov_row(0,$env,'control',true));$tc=bc_crypto_gov_decode(bc_crypto_gov_row($vid,$env,'control',true));
    $asset=$doc['query']['coin'].':TRX';
    $p=bc_crypto_gov_decode(bc_crypto_gov_row(0,$env,$asset,true));$t=bc_crypto_gov_decode(bc_crypto_gov_row($vid,$env,$asset,true));
    $snapshot=bc_crypto_gov_snapshot($vid,$env,true);
    $policy=bc_crypto_gov_evaluate($p,$t,$pc,$tc,bc_crypto_gov_network($snapshot,$asset,$now),$now,'deposit');
    if ($policy['scale']!==6) throw new RuntimeException('crypto_poll_scale_mismatch');
    $config=bc_crypto_poll_config($vid,$env);
    if (!hash_equals($row['config_hash'],$config['hash'])||!hash_equals($doc['config_fingerprint'],$config['public'])) throw new RuntimeException('crypto_poll_config_changed');
    return min($now+3600,bc_crypto_gov_decode($custody)['valid_until'],bc_crypto_gov_decode($custody)['bindings'][(string)$vid]['valid_until'],$p['expires_at'],$t['expires_at'],(int)$snapshot['expires_at_epoch']);
}
/** Pure page validation. Supplied evidence is an INTERNAL trusted-reader assertion, not provider proof. */
function bc_crypto_poll_next($doc,$row,$e,$now) {
    if (($row['state']??'')!=='ready'||(int)$row['valid_until_epoch']<=$now||(int)$row['page_count']>=100) throw new RuntimeException('crypto_poll_not_ready');
    if (($doc['stream_id']??null)!==$row['stream_id']) throw new RuntimeException('crypto_poll_state_invalid');
    $q=$doc['query'];if ($doc['cursor']!=='')$q['cursor']=$doc['cursor'];
    foreach(['provider'=>'bybit','evidence_kind'=>'deposit_page_inspection_v1','environment'=>$row['environment'],'custody_account'=>$row['custody_account'],'source_vendor_id'=>(int)$row['vendor_id'],'source_config_fingerprint'=>$doc['config_fingerprint'],'authenticated_read_completed'=>true,'identity_checked_before_and_after'=>true,'import_enabled'=>false,'settlement_enabled'=>false,'live_enabled'=>false,'history_complete'=>false,'query_window_membership_verified'=>false,'cursor_chain_verified'=>false,'cursor_advance_authorized'=>false,'customer_attribution_verified'=>false,'custody_eligibility_verified'=>false] as $k=>$value) if (!array_key_exists($k,$e)||$e[$k]!==$value) throw new RuntimeException('crypto_poll_evidence_mismatch');
    if (($e['query']??null)!==$q||!is_int($e['verified_at']??null)||!is_int($e['started_at']??null)||!is_int($e['expires_at']??null)||$e['verified_at']>$now||$e['started_at']>$e['verified_at']||$e['verified_at']-$e['started_at']>30||$e['expires_at']!==$e['started_at']+900||$e['expires_at']<=$now) throw new RuntimeException('crypto_poll_evidence_mismatch');
    $page=$e['page']??null;
    if (!is_array($page)||!is_array($page['records']??null)||count($page['records'])>5) throw new RuntimeException('crypto_poll_evidence_mismatch');
    $raw=[];
    foreach($page['records'] as $r) {
        $x=['coin'=>$doc['query']['coin'],'chain'=>'TRX','id'=>$r['provider_id']??null,'txID'=>$r['txid']??null,'txIndex'=>$r['tx_index']??null,'toAddress'=>$r['address']??null,'tag'=>$r['tag']??null,'amount'=>$r['amount']??null,'depositFee'=>$r['deposit_fee']??null,'status'=>$r['provider_status']??null,'confirmations'=>$r['confirmations']??null,'successAt'=>$r['success_at_ms']??null,'depositType'=>$r['deposit_type']??null];
        if (($r['tax_status']??null)!==null)$x['taxStatus']=$r['tax_status'];
        if (($r['travel_rule_status']??null)!==null)$x['travelRuleStatus']=$r['travel_rule_status'];
        $raw[]=$x;
    }
    if (bc_crypto_bybit_deposit_page(['rows'=>$raw,'nextPageCursor'=>$page['next_cursor']??null],$doc['query']['coin'],6,$doc['cursor'])!==$page) throw new RuntimeException('crypto_poll_evidence_mismatch');
    $kind=$e['reported_account_kind']??null;$parent=$e['reported_parent_account']??null;
    if (!in_array($kind,['master','subaccount'],true)||($kind==='master'&&$parent!==null)||($kind==='subaccount'&&(!is_string($parent)||!preg_match('/^[a-f0-9]{64}$/D',$parent)))) throw new RuntimeException('crypto_poll_evidence_mismatch');
    if (isset($doc['account_kind'])&&($doc['account_kind']!==$kind||$doc['parent_account']!==$parent)) throw new RuntimeException('crypto_poll_identity_changed');
    $doc['account_kind']=$kind;$doc['parent_account']=$parent;
    $next=$page['next_cursor'];$hash=hash('sha256',$next);
    if ($next!==''&&in_array($hash,$doc['cursor_hashes'],true)) throw new RuntimeException('crypto_poll_cursor_cycle');
    if ($next!==''&&(int)$row['page_count']+1>=100) throw new RuntimeException('crypto_poll_page_limit');
    $doc['cursor']=$next;if($next!=='')$doc['cursor_hashes'][]=$hash;
    return ['document'=>$doc,'state'=>$next===''?'exhausted':'ready'];
}
function bc_crypto_poll_event($id,$revision,$actor,$action,$hash=null) {
    bc_crypto_custody_must('INSERT INTO sas_crypto_v2_poll_events(stream_id,revision,actor_id,action,evidence_hash,occurred_at_epoch) VALUES(?,?,?,?,?,?)',[$id,$revision,$actor,$action,$hash,time()]);
}
function bc_crypto_poll_begin() {
    global $connection_server;
    if (!bc_crypto_transaction_ready()) throw new RuntimeException('crypto_transaction_unavailable');
    bc_crypto_poll_engines();
    if (!mysqli_begin_transaction($connection_server)) throw new RuntimeException('crypto_transaction_unavailable');
}
function bc_crypto_poll_open($row) {
    $doc=json_decode(bc_crypto_custody_evidence_open($row['custody_account'],$row['environment'],$row['state_hash'],$row['sealed_state']),true);
    if (!is_array($doc)||($doc['stream_id']??null)!==$row['stream_id']) throw new RuntimeException('crypto_poll_state_invalid');
    return $doc;
}
/** Immutable bounded window; no renewal/reset/reassignment or caller-selected cursor. */
function bc_crypto_poll_create($authority,$env,$identity,$custody_revision,$vendor,$coin,$start,$end) {
    global $connection_server;
    $actor=bc_crypto_custody_actor($authority);bc_crypto_gov_environment($env);bc_crypto_custody_digest($identity);
    if (!is_int($vendor)||$vendor<=0||!is_int($custody_revision)||$custody_revision<1) throw new RuntimeException('crypto_poll_scope_invalid');
    $query=bc_bybit_deposit_query($coin,$start,$end,'',sprintf('%.0f',floor(microtime(true)*1000)));
    $config=bc_crypto_poll_config($vendor,$env);$id=bin2hex(random_bytes(16));
    $doc=['stream_id'=>$id,'query'=>$query,'cursor'=>'','cursor_hashes'=>[],'config_fingerprint'=>$config['public']];
    $row=['stream_id'=>$id,'environment'=>$env,'custody_account'=>$identity,'custody_revision'=>$custody_revision,'vendor_id'=>$vendor,'config_hash'=>$config['hash']];
    bc_crypto_poll_begin();
    try {
        $until=bc_crypto_poll_guard($row,$doc);
        $sealed=bc_crypto_custody_evidence_seal($identity,$env,bc_crypto_gov_json($doc));
        bc_crypto_custody_must('INSERT INTO sas_crypto_v2_poll_streams(stream_id,custody_account,environment,custody_revision,vendor_id,config_hash,state_hash,sealed_state,valid_until_epoch,created_by) VALUES(?,?,?,?,?,?,?,?,?,?)',[$id,$identity,$env,$custody_revision,$vendor,$config['hash'],$sealed['hash'],$sealed['sealed'],$until,$actor]);
        bc_crypto_poll_event($id,1,$actor,'created');
        if (!mysqli_commit($connection_server))throw new RuntimeException('crypto_poll_storage_failed');
        return ['stream_id'=>$id,'revision'=>1,'state'=>'ready','import_enabled'=>false];
    } catch(Throwable $e){mysqli_rollback($connection_server);throw $e;}
}
/** Lock custody before stream consistently across prepare/commit/stop. No network while locked. */
function bc_crypto_poll_locked($id,$revision) {
    bc_crypto_poll_id($id);
    if (!is_int($revision)||$revision<1)throw new RuntimeException('crypto_poll_revision_invalid');
    $initial=bc_q_row('SELECT custody_account FROM sas_crypto_v2_poll_streams WHERE stream_id=?',[$id]);
    if (!$initial)throw new RuntimeException('crypto_poll_missing');
    bc_q_row('SELECT custody_account FROM sas_crypto_v2_custody_accounts WHERE custody_account=? FOR UPDATE',[$initial['custody_account']]);
    $row=bc_q_row('SELECT * FROM sas_crypto_v2_poll_streams WHERE stream_id=? FOR UPDATE',[$id]);
    if (!$row||(int)$row['revision']!==$revision)throw new RuntimeException('crypto_poll_edit_conflict');
    return $row;
}
/** Audited internal cursor read, never a human evidence-view/review receipt. */
function bc_crypto_poll_prepare($authority,$id,$revision,$lease_token=null) {
    global $connection_server;$actor=bc_crypto_custody_actor($authority);bc_crypto_poll_begin();
    try {
        $row=bc_crypto_poll_locked($id,$revision);bc_crypto_poll_lease_check($row,$lease_token,$actor);$doc=bc_crypto_poll_open($row);
        if ($row['state']!=='ready'||(int)$row['valid_until_epoch']<=time())throw new RuntimeException('crypto_poll_not_ready');
        bc_crypto_poll_guard($row,$doc);bc_crypto_poll_event($id,$revision,$actor,'read_requested');
        if (!mysqli_commit($connection_server))throw new RuntimeException('crypto_poll_storage_failed');
        return ['vendor_id'=>(int)$row['vendor_id'],'environment'=>$row['environment'],'custody_account'=>$row['custody_account'],'query'=>$doc['query'],'cursor'=>$doc['cursor']];
    } catch(Throwable $e){mysqli_rollback($connection_server);throw $e;}
}
/** INTERNAL reader result only. Atomic evidence + seen identities + cursor + audit; no imports. */
function bc_crypto_poll_commit($authority,$id,$revision,$evidence,$lease_token=null) {
    global $connection_server;$actor=bc_crypto_custody_actor($authority);bc_crypto_poll_begin();
    try {
        $row=bc_crypto_poll_locked($id,$revision);bc_crypto_poll_lease_check($row,$lease_token,$actor);$doc=bc_crypto_poll_open($row);bc_crypto_poll_guard($row,$doc);
        $next=bc_crypto_poll_next($doc,$row,$evidence,time());
        foreach($evidence['page']['records'] as $r) {
            $record_hash=hash('sha256',$r['provider_record_key']);
            $seen=bc_q_row('SELECT record_hash FROM sas_crypto_v2_poll_seen WHERE stream_id=? AND (record_hash=? OR transfer_hash=?)',[$id,$record_hash,$r['transfer_fingerprint']]);
            if ($seen)throw new RuntimeException('crypto_poll_duplicate_record');
            bc_crypto_custody_must('INSERT INTO sas_crypto_v2_poll_seen(stream_id,record_hash,transfer_hash) VALUES(?,?,?)',[$id,$record_hash,$r['transfer_fingerprint']]);
        }
        $evidence['poll_stream_id']=$id;$evidence['poll_revision']=$revision;$evidence['registry_revision']=(int)$row['custody_revision'];$evidence['verified_by_actor']=$actor;
        $sealed=bc_crypto_custody_evidence_seal($row['custody_account'],$row['environment'],bc_crypto_gov_json($evidence));
        bc_crypto_custody_must('INSERT INTO sas_crypto_v2_custody_evidence(custody_account,evidence_hash,environment,sealed_document,stored_by) VALUES(?,?,?,?,?)',[$row['custody_account'],$sealed['hash'],$row['environment'],$sealed['sealed'],$actor]);
        bc_crypto_custody_must("INSERT INTO sas_crypto_v2_custody_evidence_events(custody_account,evidence_hash,actor_id,revision,action,occurred_at_epoch) VALUES(?,?,?,0,'stored',?)",[$row['custody_account'],$sealed['hash'],$actor,time()]);
        bc_crypto_poll_overlap_store($row,$revision+1,$evidence['page']['records'],$sealed['hash']);
        $state=bc_crypto_custody_evidence_seal($row['custody_account'],$row['environment'],bc_crypto_gov_json($next['document']));
        bc_crypto_custody_must('UPDATE sas_crypto_v2_poll_streams SET sealed_state=?,state_hash=?,state=?,page_count=page_count+1,revision=revision+1 WHERE stream_id=?',[$state['sealed'],$state['hash'],$next['state'],$id]);
        bc_crypto_poll_event($id,$revision+1,$actor,'page_committed',$sealed['hash']);
        if ($lease_token!==null) bc_crypto_poll_lease_finish($id,$revision,$actor,$lease_token,'released');
        if (!mysqli_commit($connection_server))throw new RuntimeException('crypto_poll_storage_failed');
        return ['stream_id'=>$id,'revision'=>$revision+1,'state'=>$next['state'],'evidence_hash'=>$sealed['hash'],'import_enabled'=>false,'settlement_enabled'=>false,'history_complete'=>false];
    } catch(Throwable $e){mysqli_rollback($connection_server);throw $e;}
}
/** Existing lease row permanently opts the stream into token-fenced prepare/commit. */
function bc_crypto_poll_lease_valid($lease,$revision,$actor,$token,$now) {
    return is_array($lease)&&is_string($token)&&preg_match('/^[a-f0-9]{64}$/D',$token)
        &&(int)$lease['revision']===$revision&&(int)$lease['actor_id']===$actor
        &&(int)$lease['expires_at_epoch']>$now&&hash_equals($lease['token_hash'],hash('sha256',$token));
}
function bc_crypto_poll_lease_check($row,$token,$actor) {
    $lease=bc_q_row('SELECT * FROM sas_crypto_v2_poll_leases WHERE stream_id=? FOR UPDATE',[$row['stream_id']]);
    if (!$lease&&$token===null) return; // Internal, not-yet-enrolled foundation streams only.
    if (!bc_crypto_poll_lease_valid($lease,(int)$row['revision'],$actor,$token,time())) throw new RuntimeException('crypto_poll_lease_invalid');
}
/** Owner transaction required. Release audit failure rolls back checkpoint progress too. */
function bc_crypto_poll_lease_finish($id,$revision,$actor,$token,$action) {
    $lease=bc_q_row('SELECT * FROM sas_crypto_v2_poll_leases WHERE stream_id=? FOR UPDATE',[$id]);
    if (!$lease||!is_string($token)||!hash_equals($lease['token_hash'],hash('sha256',$token))||(int)$lease['revision']!==$revision||(int)$lease['actor_id']!==$actor) throw new RuntimeException('crypto_poll_lease_invalid');
    if ($action==='released'&&(int)$lease['expires_at_epoch']<=time())throw new RuntimeException('crypto_poll_lease_invalid');
    if (!in_array($action,['released','failed'],true))throw new RuntimeException('crypto_poll_lease_invalid');
    bc_crypto_custody_must('UPDATE sas_crypto_v2_poll_leases SET expires_at_epoch=0 WHERE stream_id=?',[$id]);
    bc_crypto_custody_must('INSERT INTO sas_crypto_v2_poll_lease_events(stream_id,revision,actor_id,action,token_hash,occurred_at_epoch) VALUES(?,?,?,?,?,?)',[$id,$revision,$actor,$action,$lease['token_hash'],time()]);
}
function bc_crypto_poll_stop($authority,$id,$revision) {
    global $connection_server;$actor=bc_crypto_custody_actor($authority);bc_crypto_poll_begin();
    try {
        bc_crypto_poll_locked($id,$revision);
        bc_crypto_custody_must("UPDATE sas_crypto_v2_poll_streams SET state='stopped',revision=revision+1 WHERE stream_id=?",[$id]);
        bc_crypto_poll_event($id,$revision+1,$actor,'stopped');
        if (!mysqli_commit($connection_server))throw new RuntimeException('crypto_poll_storage_failed');
        return ['stream_id'=>$id,'revision'=>$revision+1,'state'=>'stopped','import_enabled'=>false];
    } catch(Throwable $e){mysqli_rollback($connection_server);throw $e;}
}
/** Authenticated operator wrapper supplies the lease. Legacy internal calls cannot bypass an enrolled lease. */
function bc_crypto_poll_fetch($authority,$id,$revision,$lease_token=null) {
    $p=bc_crypto_poll_prepare($authority,$id,$revision,$lease_token);$q=$p['query'];
    $e=bc_crypto_bybit_read_deposit_page($p['vendor_id'],$p['environment'],$p['custody_account'],$q['coin'],$q['startTime'],$q['endTime'],$p['cursor']);
    return bc_crypto_poll_commit($authority,$id,$revision,$e,$lease_token);
}

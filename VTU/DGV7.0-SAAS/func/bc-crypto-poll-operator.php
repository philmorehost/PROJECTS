<?php
/** Called ONLY after existing platform session/POST/CSRF/PIN/TOTP authorization. */
require_once __DIR__.'/bc-crypto-poll.php';
function bc_crypto_poll_claim($authority,$id,$revision) {
    global $connection_server;$actor=bc_crypto_custody_actor($authority);bc_crypto_poll_begin();
    try {
        $row=bc_crypto_poll_locked($id,$revision);$doc=bc_crypto_poll_open($row);
        if ($row['state']!=='ready'||(int)$row['valid_until_epoch']<=time())throw new RuntimeException('crypto_poll_not_ready');
        bc_crypto_poll_guard($row,$doc);
        $lease=bc_q_row('SELECT * FROM sas_crypto_v2_poll_leases WHERE stream_id=? FOR UPDATE',[$id]);
        if ($lease&&(int)$lease['expires_at_epoch']>time())throw new RuntimeException('crypto_poll_lease_busy');
        $token=bin2hex(random_bytes(32));$hash=hash('sha256',$token);$until=time()+60;
        bc_crypto_custody_must('INSERT INTO sas_crypto_v2_poll_leases(stream_id,token_hash,revision,actor_id,expires_at_epoch) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE token_hash=VALUES(token_hash),revision=VALUES(revision),actor_id=VALUES(actor_id),expires_at_epoch=VALUES(expires_at_epoch)',[$id,$hash,$revision,$actor,$until]);
        bc_crypto_custody_must("INSERT INTO sas_crypto_v2_poll_lease_events(stream_id,revision,actor_id,action,token_hash,occurred_at_epoch) VALUES(?,?,?,'claimed',?,?)",[$id,$revision,$actor,$hash,time()]);
        if (!mysqli_commit($connection_server))throw new RuntimeException('crypto_poll_storage_failed');
        return $token; // Internal only; NEVER send to a browser, session flash, URL or log.
    }catch(Throwable $e){mysqli_rollback($connection_server);throw $e;}
}
function bc_crypto_poll_release_failed($authority,$id,$revision,$token) {
    global $connection_server;$actor=bc_crypto_custody_actor($authority);bc_crypto_poll_begin();
    try {
        // Serializes with stop/progress/reclaims. The original stream revision may already have changed.
        bc_q_row('SELECT stream_id FROM sas_crypto_v2_poll_streams WHERE stream_id=? FOR UPDATE',[bc_crypto_poll_id($id)]);
        bc_crypto_poll_lease_finish($id,$revision,$actor,$token,'failed');
        if (!mysqli_commit($connection_server))throw new RuntimeException('crypto_poll_storage_failed');
    }catch(Throwable $e){mysqli_rollback($connection_server);throw $e;}
}
function bc_crypto_poll_operator_fetch($authority,$id,$revision) {
    $token=bc_crypto_poll_claim($authority,$id,$revision);
    try {return bc_crypto_poll_fetch($authority,$id,$revision,$token);}
    catch(Throwable $e) {
        try {bc_crypto_poll_release_failed($authority,$id,$revision,$token);}catch(Throwable $release_error) {
            // No unsafe retry or exception payload logging. Unreleased lease expires after 60s.
        }
        throw $e;
    }
}
/** Server-selected account/env/revision; posted stream/query/authority cannot rebind a stream. */
function bc_crypto_poll_manage($authority,$env,$identity,$custody_revision,$action,$input) {
    bc_crypto_custody_actor($authority);bc_crypto_gov_environment($env);bc_crypto_custody_digest($identity);
    foreach(['cursor','evidence','page','query','lease_token'] as $field)if(array_key_exists($field,$input))throw new RuntimeException('crypto_poll_override_forbidden');
    if ($action==='create_poll') {
        $vendor=bc_crypto_gov_uint($input['source_vendor_id']??null,1000000000);
        return bc_crypto_poll_create($authority,$env,$identity,$custody_revision,$vendor,$input['coin']??null,$input['start_ms']??null,$input['end_ms']??null);
    }
    if (!in_array($action,['fetch_poll','stop_poll'],true))throw new RuntimeException('crypto_poll_action_invalid');
    foreach(['source_vendor_id','coin','start_ms','end_ms'] as $field)if(array_key_exists($field,$input))throw new RuntimeException('crypto_poll_override_forbidden');
    $id=bc_crypto_poll_id($input['stream_id']??null);
    $revision=bc_crypto_gov_uint($input['stream_revision']??null,1000000000);
    if ($revision<1)throw new RuntimeException('crypto_poll_revision_invalid');
    $row=bc_q_row('SELECT custody_account,environment FROM sas_crypto_v2_poll_streams WHERE stream_id=?',[$id]);
    if (!$row||$row['custody_account']!==$identity||$row['environment']!==$env)throw new RuntimeException('crypto_poll_scope_mismatch');
    return $action==='stop_poll'?bc_crypto_poll_stop($authority,$id,$revision):bc_crypto_poll_operator_fetch($authority,$id,$revision);
}

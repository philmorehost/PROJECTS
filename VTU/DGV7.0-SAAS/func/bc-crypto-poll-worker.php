<?php
require_once __DIR__.'/bc-crypto-poll-operator.php';
/** Existing stepped-up management boundary only. Grant is one attempt, max five minutes. */
function bc_crypto_poll_worker_manage($authority,$identity,$env,$custody_revision,$input,$revoke=false) {
    global $connection_server;$actor=bc_crypto_custody_actor($authority);
    bc_crypto_custody_digest($identity);bc_crypto_gov_environment($env);
    foreach(['credential','credential_hash','worker_name','state','cursor','query','evidence','lease_token'] as $field)if(array_key_exists($field,$input))throw new RuntimeException('crypto_poll_override_forbidden');
    $id=bc_crypto_poll_id($input[$revoke?'grant_id':'stream_id']??null);bc_crypto_poll_begin();
    try {
        $c=bc_q_row('SELECT revision,environment FROM sas_crypto_v2_custody_accounts WHERE custody_account=? FOR UPDATE',[$identity]);
        if(!$c||$c['environment']!==$env)throw new RuntimeException('crypto_poll_scope_mismatch');
        if((int)$c['revision']!==$custody_revision)throw new RuntimeException('crypto_custody_edit_conflict');
        if($revoke){
            $initial=bc_q_row('SELECT stream_id FROM sas_crypto_v2_poll_worker_grants WHERE grant_id=?',[$id]);
            if(!$initial)throw new RuntimeException('crypto_poll_worker_grant_invalid');
            $sid=$initial['stream_id'];
        }else $sid=$id;
        $s=bc_q_row('SELECT * FROM sas_crypto_v2_poll_streams WHERE stream_id=? FOR UPDATE',[$sid]);
        if(!$s||$s['custody_account']!==$identity||$s['environment']!==$env)throw new RuntimeException('crypto_poll_scope_mismatch');
        if($revoke){
            $g=bc_q_row('SELECT state FROM sas_crypto_v2_poll_worker_grants WHERE grant_id=? FOR UPDATE',[$id]);
            if(!in_array($g['state']??null,['pending','running'],true))throw new RuntimeException('crypto_poll_worker_grant_invalid');
            bc_crypto_custody_must("UPDATE sas_crypto_v2_poll_worker_grants SET state='revoked',revoked_by=?,revoked_at_epoch=? WHERE grant_id=?",[$actor,time(),$id]);
        }else{
            $revision=bc_crypto_gov_uint($input['stream_revision']??null,1000000000);
            if($revision!==(int)$s['revision'])throw new RuntimeException('crypto_poll_edit_conflict');
            if($s['state']!=='ready'||(int)$s['valid_until_epoch']<=time())throw new RuntimeException('crypto_poll_not_ready');
            $doc=bc_crypto_poll_open($s);bc_crypto_poll_guard($s,$doc);bc_crypto_poll_retry_check($sid,time());
            $config=bc_crypto_poll_worker_config();$id=bin2hex(random_bytes(16));
            bc_crypto_custody_must('INSERT INTO sas_crypto_v2_poll_worker_grants(grant_id,stream_id,revision,worker_name,credential_hash,expires_at_epoch,created_by,created_at_epoch) VALUES(?,?,?,?,?,?,?,?)',[$id,$sid,$revision,$config['name'],$config['hash'],min(time()+300,(int)$s['valid_until_epoch']),$actor,time()]);
        }
        if(!mysqli_commit($connection_server))throw new RuntimeException('crypto_poll_storage_failed');
        return ['custody_account'=>$identity,'worker_grant_id'=>$id,'worker_grant_updated'=>true,'settlement_enabled'=>false];
    }catch(Throwable $e){mysqli_rollback($connection_server);throw $e;}
}
/** Single network attempt, no scheduler, sleep loop, window creation or settlement. */
function bc_crypto_poll_worker_once($grant_id) {
    $authority=BcCryptoPollWorkerAuthority::authenticate($grant_id);
    $g=bc_q_row('SELECT stream_id,revision FROM sas_crypto_v2_poll_worker_grants WHERE grant_id=?',[$grant_id]);
    return bc_crypto_poll_operator_fetch($authority,$g['stream_id'],(int)$g['revision']);
}

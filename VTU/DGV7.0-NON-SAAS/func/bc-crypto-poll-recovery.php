<?php
require_once __DIR__.'/bc-crypto-poll-worker.php';
/** Recover only an unchanged checkpoint and expired exact grant-bound lease. Never reset progress. */
function bc_crypto_poll_recover($authority,$identity,$env,$custody_revision,$input) {
    global $connection_server;$actor=bc_crypto_custody_actor($authority);
    bc_crypto_custody_digest($identity);bc_crypto_gov_environment($env);
    foreach(['cursor','query','evidence','lease_token','state','credential','outcome','recovered_by'] as $field)if(array_key_exists($field,$input))throw new RuntimeException('crypto_poll_override_forbidden');
    $id=bc_crypto_poll_id($input['grant_id']??null);$revision=bc_crypto_gov_uint($input['stream_revision']??null,1000000000);
    if($revision<1)throw new RuntimeException('crypto_poll_revision_invalid');
    bc_crypto_poll_begin();
    try {
        $c=bc_q_row('SELECT revision,environment FROM sas_crypto_v2_custody_accounts WHERE custody_account=? FOR UPDATE',[$identity]);
        if(!$c||$c['environment']!==$env)throw new RuntimeException('crypto_poll_scope_mismatch');
        if((int)$c['revision']!==$custody_revision)throw new RuntimeException('crypto_custody_edit_conflict');
        $initial=bc_q_row('SELECT stream_id FROM sas_crypto_v2_poll_worker_grants WHERE grant_id=?',[$id]);
        if(!$initial)throw new RuntimeException('crypto_poll_worker_grant_invalid');
        $s=bc_q_row('SELECT * FROM sas_crypto_v2_poll_streams WHERE stream_id=? FOR UPDATE',[$initial['stream_id']]);
        if(!$s||$s['custody_account']!==$identity||$s['environment']!==$env)throw new RuntimeException('crypto_poll_scope_mismatch');
        if((int)$s['revision']!==$revision)throw new RuntimeException('crypto_poll_edit_conflict');
        $g=bc_q_row('SELECT * FROM sas_crypto_v2_poll_worker_grants WHERE grant_id=? FOR UPDATE',[$id]);
        if(!in_array($g['state'],['running','revoked'],true)||(int)$g['revision']!==$revision||!is_string($g['lease_token_hash']))throw new RuntimeException('crypto_poll_recovery_not_eligible');
        $l=bc_q_row('SELECT * FROM sas_crypto_v2_poll_leases WHERE stream_id=? FOR UPDATE',[$s['stream_id']]);
        $now=time();
        if(!$l||(int)$l['revision']!==$revision||(int)$l['actor_id']!==0||!hash_equals($g['lease_token_hash'],$l['token_hash'])||(int)$l['expires_at_epoch']<=0||(int)$l['expires_at_epoch']>$now)throw new RuntimeException('crypto_poll_recovery_not_eligible');
        // This grant can never resume. Preserve an earlier revocation's actor/time.
        if($g['state']==='running')bc_crypto_custody_must("UPDATE sas_crypto_v2_poll_worker_grants SET state='revoked',revoked_by=?,revoked_at_epoch=? WHERE grant_id=?",[$actor,$now,$id]);
        bc_crypto_custody_must('INSERT INTO sas_crypto_v2_poll_recoveries(grant_id,stream_revision,recovered_by,recovered_at_epoch,outcome) VALUES(?,?,?,?,\'unknown_expired_attempt\')',[$id,$revision,$actor,$now]);
        bc_crypto_custody_must('UPDATE sas_crypto_v2_poll_leases SET expires_at_epoch=0 WHERE stream_id=?',[$s['stream_id']]);
        // Existing retry policy groups by token hash, so unknown claim + failed closure is one attempt.
        bc_crypto_custody_must("INSERT INTO sas_crypto_v2_poll_lease_events(stream_id,revision,actor_id,action,token_hash,occurred_at_epoch) VALUES(?,?,0,'failed',?,?)",[$s['stream_id'],$revision,$l['token_hash'],$now]);
        bc_crypto_poll_alert_capture($s['stream_id'],$revision);
        if(!mysqli_commit($connection_server))throw new RuntimeException('crypto_poll_storage_failed');
        return ['custody_account'=>$identity,'worker_recovered'=>true,'settlement_enabled'=>false];
    }catch(Throwable $e){mysqli_rollback($connection_server);throw $e;}
}

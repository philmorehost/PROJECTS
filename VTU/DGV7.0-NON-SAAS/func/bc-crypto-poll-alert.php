<?php
require_once __DIR__.'/bc-crypto-poll-retry.php';
/** Internal owner transaction holds stream lock. No message body, records or tokens retained. */
function bc_crypto_poll_alert_capture($id,$revision) {
    $d=bc_crypto_poll_retry_history($id,time());
    if (!$d['escalation_required'])return;
    $existing=bc_q_row("SELECT stream_id FROM sas_crypto_v2_poll_alerts WHERE stream_id=? AND revision=? AND kind='retry_escalation' FOR UPDATE",[$id,$revision]);
    if (!$existing)bc_crypto_custody_must("INSERT INTO sas_crypto_v2_poll_alerts(stream_id,revision,kind,created_at_epoch) VALUES(?,?,'retry_escalation',?)",[$id,$revision,time()]);
}
/** Acknowledgement is single-shot receipt, never retry reset/resolution/delivery confirmation. */
function bc_crypto_poll_alert_ack($authority,$identity,$env,$custody_revision,$id,$revision) {
    global $connection_server;$actor=bc_crypto_custody_actor($authority);
    bc_crypto_custody_digest($identity);bc_crypto_gov_environment($env);bc_crypto_poll_id($id);
    if (!is_int($revision)||$revision<1)throw new RuntimeException('crypto_poll_revision_invalid');
    bc_crypto_poll_begin();
    try {
        $c=bc_q_row('SELECT environment,revision FROM sas_crypto_v2_custody_accounts WHERE custody_account=? FOR UPDATE',[$identity]);
        if (!$c||$c['environment']!==$env)throw new RuntimeException('crypto_poll_scope_mismatch');
        if ((int)$c['revision']!==$custody_revision)throw new RuntimeException('crypto_custody_edit_conflict');
        $s=bc_q_row('SELECT custody_account,environment FROM sas_crypto_v2_poll_streams WHERE stream_id=? FOR UPDATE',[$id]);
        if (!$s||$s['custody_account']!==$identity||$s['environment']!==$env)throw new RuntimeException('crypto_poll_scope_mismatch');
        $a=bc_q_row("SELECT acknowledged_by FROM sas_crypto_v2_poll_alerts WHERE stream_id=? AND revision=? AND kind='retry_escalation' FOR UPDATE",[$id,$revision]);
        if (!$a)throw new RuntimeException('crypto_poll_alert_missing');
        if ($a['acknowledged_by']!==null)throw new RuntimeException('crypto_poll_alert_already_acknowledged');
        bc_crypto_custody_must("UPDATE sas_crypto_v2_poll_alerts SET acknowledged_by=?,acknowledged_at_epoch=? WHERE stream_id=? AND revision=? AND kind='retry_escalation'",[$actor,time(),$id,$revision]);
        if (!mysqli_commit($connection_server))throw new RuntimeException('crypto_poll_storage_failed');
        return ['custody_account'=>$identity,'alert_acknowledged'=>true,'import_enabled'=>false,'settlement_enabled'=>false];
    }catch(Throwable $e){mysqli_rollback($connection_server);throw $e;}
}

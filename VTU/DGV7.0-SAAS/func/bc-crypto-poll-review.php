<?php
require_once __DIR__.'/bc-crypto-poll.php';
/** Caller authenticates with POST/CSRF/fresh platform PIN/TOTP. No plaintext record decrypt. */
function bc_crypto_poll_review($authority,$identity,$env,$revision) {
    global $connection_server;$actor=bc_crypto_custody_actor($authority);
    bc_crypto_custody_digest($identity);bc_crypto_gov_environment($env);
    if (!is_int($revision)||$revision<1)throw new RuntimeException('crypto_poll_revision_invalid');
    bc_crypto_poll_begin();
    try {
        $engine=bc_q_row("SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sas_crypto_v2_poll_review_events'");
        if (!$engine||strtoupper($engine['engine']??'')!=='INNODB')throw new RuntimeException('crypto_poll_transactional_schema_required');
        $account=bc_q_row('SELECT environment,revision FROM sas_crypto_v2_custody_accounts WHERE custody_account=? FOR UPDATE',[$identity]);
        if (!$account||$account['environment']!==$env)throw new RuntimeException('crypto_poll_scope_mismatch');
        if ((int)$account['revision']!==$revision)throw new RuntimeException('crypto_custody_edit_conflict');
        // Shared custody lock serializes with checkpoint insertion. Current reads avoid old snapshots.
        $rows=bc_q('SELECT p.stream_id,p.revision,p.record_hash,p.transfer_hash,p.snapshot_hash,p.evidence_hash,p.classification,s.state,s.custody_revision FROM sas_crypto_v2_poll_sightings p JOIN sas_crypto_v2_poll_streams s ON s.stream_id=p.stream_id WHERE s.custody_account=? AND s.environment=? ORDER BY s.created_at DESC,p.stream_id,p.revision DESC,p.record_hash LIMIT 51 FOR UPDATE',[$identity,$env]);
        if (!($rows instanceof mysqli_result))throw new RuntimeException('crypto_poll_storage_failed');
        $sightings=[];while($r=mysqli_fetch_assoc($rows))$sightings[]=$r;
        $truncated=count($sightings)>50;$sightings=array_slice($sightings,0,50);
        foreach($sightings as &$s) {
            $pairs=bc_q('SELECT record_hash,transfer_hash FROM sas_crypto_v2_poll_identities WHERE custody_account=? AND environment=? AND ((record_hash=? AND transfer_hash<>?) OR (transfer_hash=? AND record_hash<>?)) ORDER BY record_hash,transfer_hash LIMIT 11 FOR UPDATE',[$identity,$env,$s['record_hash'],$s['transfer_hash'],$s['transfer_hash'],$s['record_hash']]);
            if (!($pairs instanceof mysqli_result))throw new RuntimeException('crypto_poll_storage_failed');
            $competing=[];while($p=mysqli_fetch_assoc($pairs))$competing[]=$p;
            $s['current_conflict']=count($competing)>0;$s['competing_pairs_truncated']=count($competing)>10;$s['competing_pairs']=array_slice($competing,0,10);
        }unset($s);
        $report=['kind'=>'poll_conflict_review_v1','custody_account'=>$identity,'environment'=>$env,'custody_revision'=>$revision,'generated_at'=>time(),'sightings'=>$sightings,'truncated'=>$truncated,'history_complete'=>false,'conflicts_resolved'=>false,'import_enabled'=>false,'settlement_enabled'=>false];
        $json=bc_crypto_gov_json($report);
        bc_crypto_custody_must('INSERT INTO sas_crypto_v2_poll_review_events(custody_account,environment,custody_revision,actor_id,report_hash,occurred_at_epoch) VALUES(?,?,?,?,?,?)',[$identity,$env,$revision,$actor,hash('sha256',$json),time()]);
        if (!mysqli_commit($connection_server))throw new RuntimeException('crypto_poll_storage_failed');
        return $json; // Only render on stepped-up no-store POST response, never redirect/flash/log.
    }catch(Throwable $e){mysqli_rollback($connection_server);throw $e;}
}

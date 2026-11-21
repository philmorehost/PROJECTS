<?php
/** Standalone staging reference; no listener, application bootstrap or financial authority. */
function bc_alert_receiver_verify($body,$timestamp,$idempotency,$signature,$key,$now) {
    if(!is_string($key)||!preg_match('/^[a-f0-9]{64}$/D',$key)||!is_int($now)||$now<1)throw new RuntimeException('receiver_configuration_invalid');
    if(!is_string($body)||strlen($body)>1024||!is_string($timestamp)||!preg_match('/^[1-9][0-9]{9}$/D',$timestamp)||abs($now-(int)$timestamp)>120||!is_string($idempotency)||!preg_match('/^[a-f0-9]{32}$/D',$idempotency)||!is_string($signature)||!preg_match('/^[a-f0-9]{64}$/D',$signature))throw new RuntimeException('receiver_request_denied');
    if(!hash_equals(hash_hmac('sha256',$timestamp."\n".$idempotency."\n".$body,$key),$signature))throw new RuntimeException('receiver_request_denied');
    $p=json_decode($body,true);
    if(!is_array($p)||count($p)!==6||json_encode($p,JSON_UNESCAPED_SLASHES)!==$body||($p['version']??null)!==1||($p['delivery_id']??null)!==$idempotency||!is_string($p['stream_id']??null)||!preg_match('/^[a-f0-9]{32}$/D',$p['stream_id'])||!is_int($p['revision']??null)||$p['revision']<1||$p['revision']>1000000000||($p['kind']??null)!=='retry_escalation'||!is_int($p['created_at_epoch']??null)||$p['created_at_epoch']<1||$p['created_at_epoch']>$now+120)throw new RuntimeException('receiver_payload_denied');
    return ['id'=>$idempotency,'payload_hash'=>hash('sha256',$body),'key_hash'=>hash('sha256',$key)];
}
function bc_alert_receiver_schema() {
    return ["CREATE TABLE IF NOT EXISTS staging_alert_inbox (
        delivery_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        accepted_at_epoch BIGINT UNSIGNED NOT NULL
    ) ENGINE=InnoDB",
    "CREATE TABLE IF NOT EXISTS staging_alert_acceptance_events (
        delivery_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        accepted_at_epoch BIGINT UNSIGNED NOT NULL,
        FOREIGN KEY(delivery_id) REFERENCES staging_alert_inbox(delivery_id)
    ) ENGINE=InnoDB"];
}
/** Separate receiver DB connection. Verify every retry before deduplication; receipt only after commit. */
function bc_alert_receiver_accept($db,$body,$timestamp,$idempotency,$signature,$key,$now) {
    $v=bc_alert_receiver_verify($body,$timestamp,$idempotency,$signature,$key,$now);
    try {
        $state=$db->query('SELECT @@session.autocommit AS auto_commit')->fetch_assoc();
        // MySQL has no MariaDB @@in_transaction. This next-transaction-only SET refuses
        // active caller transactions without committing/rolling back their work.
        if((int)$state['auto_commit']!==1||$db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')!==true)throw new RuntimeException('receiver_transaction_unavailable');
    }catch(Throwable $e){throw new RuntimeException('receiver_transaction_unavailable');}
    foreach(['staging_alert_inbox','staging_alert_acceptance_events'] as $table){$q=$db->prepare('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->bind_param('s',$table);$q->execute();$e=$q->get_result()->fetch_assoc();$q->close();if(!$e||strtoupper($e['engine'])!=='INNODB')throw new RuntimeException('receiver_transactional_schema_required');}
    if(!$db->begin_transaction())throw new RuntimeException('receiver_storage_failed');
    try {
        // Unique insert serializes concurrent duplicates without first taking competing gap locks.
        $q=$db->prepare('INSERT INTO staging_alert_inbox(delivery_id,payload_hash,key_hash,accepted_at_epoch) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE delivery_id=VALUES(delivery_id)');$q->bind_param('sssi',$v['id'],$v['payload_hash'],$v['key_hash'],$now);if(!$q->execute())throw new RuntimeException('receiver_storage_failed');$q->close();
        $q=$db->prepare('SELECT payload_hash,key_hash FROM staging_alert_inbox WHERE delivery_id=? FOR UPDATE');$q->bind_param('s',$v['id']);$q->execute();$r=$q->get_result()->fetch_assoc();$q->close();
        if(!$r||!hash_equals($r['payload_hash'],$v['payload_hash'])||!hash_equals($r['key_hash'],$v['key_hash']))throw new RuntimeException('receiver_identity_conflict');
        $q=$db->prepare('SELECT delivery_id FROM staging_alert_acceptance_events WHERE delivery_id=? FOR UPDATE');$q->bind_param('s',$v['id']);$q->execute();$event=$q->get_result()->fetch_assoc();$q->close();
        if(!$event){$q=$db->prepare('INSERT INTO staging_alert_acceptance_events(delivery_id,accepted_at_epoch) VALUES(?,?)');$q->bind_param('si',$v['id'],$now);if(!$q->execute())throw new RuntimeException('receiver_storage_failed');$q->close();}
        if(!$db->commit())throw new RuntimeException('receiver_storage_failed');
        return json_encode(['delivery_id'=>$v['id'],'accepted'=>true,'signature'=>hash_hmac('sha256',"receipt\n".$v['id']."\n".$v['payload_hash'],$key)],JSON_UNESCAPED_SLASHES);
    }catch(Throwable $e){$db->rollback();throw $e;}
}

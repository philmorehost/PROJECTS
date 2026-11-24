<?php
require_once __DIR__.'/webhook-signing.php';

/** Hash named row values consistently across PDO native/emulated fetch types. No raw keys stored. */
function payhub_outbox_binding(array $row) {
    $fields=[];
    foreach($row as $key=>$value)if(is_string($key) && !in_array($key,['webhook_url','secret_key','test_secret_key'],true))$fields[$key]=$value===null?null:(string)$value;
    ksort($fields,SORT_STRING);
    $mode=$row['is_test']??null;
    if(!in_array($mode,[0,1,'0','1',false,true],true))throw new RuntimeException('Unknown outbox mode.');
    $key=$row[($mode===1||$mode==='1'||$mode===true)?'test_secret_key':'secret_key']??null;
    // Missing notifier credentials do not erase the accounting event; dispatch still refuses.
    $key=is_string($key)?$key:'';
    return ['snapshot_sha256'=>hash('sha256',json_encode($fields,JSON_THROW_ON_ERROR)),
        'endpoint_sha256'=>hash('sha256',(string)($row['webhook_url']??'')),
        'merchant_key_sha256'=>hash('sha256',$key)];
}

/** Caller owns accounting transaction. This helper NEVER begins/commits/rolls back it.
 * Missing storage or invalid evidence throws so the caller can roll back payment+event together.
 */
function payhub_enqueue_verify_outbox(PDO $db, $transactionId, $body) {
    if(!$db->inTransaction())throw new RuntimeException('Outbox enqueue requires the accounting transaction.');
    if(!is_int($transactionId)||$transactionId<1||!is_string($body)||strlen($body)>60000)throw new RuntimeException('Invalid or oversized outbox event.');
    $engines=$db->query("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('transactions','users','ledger','webhook_outbox')")->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach(['transactions','users','ledger','webhook_outbox'] as $table)if(strtolower((string)($engines[$table]??''))!=='innodb')throw new RuntimeException('Transactional outbox schema unavailable.');
    $q=$db->prepare('SELECT t.*,u.webhook_url,u.secret_key,u.test_secret_key FROM transactions t JOIN users u ON t.user_id=u.id WHERE t.id=? FOR UPDATE');
    $q->execute([$transactionId]);$row=$q->fetch(PDO::FETCH_ASSOC);
    $payload=json_decode($body,true);
    if(!payhub_verify_payload_matches($row,$payload) || ($payload['data']['domain']??null)!==(((int)($row['is_test']??-1)===1)?'test':'live'))throw new RuntimeException('Outbox evidence mismatch.');
    $binding=payhub_outbox_binding($row);
    $insert=$db->prepare("INSERT INTO webhook_outbox(transaction_id,producer,payload,payload_sha256,snapshot_sha256,endpoint_sha256,merchant_key_sha256) VALUES(?,'browser_verify',?,?,?,?,?)");
    $insert->execute([$transactionId,$body,hash('sha256',$body),$binding['snapshot_sha256'],$binding['endpoint_sha256'],$binding['merchant_key_sha256']]);
}

/** Called under the existing payment lock by every protected initial sender. */
function payhub_check_queued_webhook(PDO $db, array $row, $body) {
    $q=$db->prepare('SELECT * FROM webhook_outbox WHERE transaction_id=? FOR UPDATE');
    $q->execute([(int)$row['id']]);$event=$q->fetch(PDO::FETCH_ASSOC);
    if(!$event)return; // Other producers are not yet transactional-outbox producers.
    $binding=payhub_outbox_binding($row);
    if($event['payload']!==$body || !hash_equals($event['payload_sha256'],hash('sha256',$body)))throw new RuntimeException('Queued payload differs; no substitute delivery allowed.');
    foreach($binding as $field=>$digest)if(!hash_equals($event[$field],$digest))throw new RuntimeException('Queued payment/merchant binding changed; reconciliation required.');
}

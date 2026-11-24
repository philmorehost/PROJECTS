<?php
/** Non-authoritative investigation journal. Never consulted to permit sending or settlement. */
function payhub_review_input(array $input){
    if(array_keys($input)!==['request_id','actor_ref','evidence_ref','evidence_sha256','observation_sha256','finding'])throw new InvalidArgumentException('Invalid review fields.');
    foreach(['request_id'=>64,'actor_ref'=>80,'evidence_ref'=>120] as $field=>$max){
        if(!is_string($input[$field])||strlen($input[$field])<8||strlen($input[$field])>$max||!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]*\z/D',$input[$field]))throw new InvalidArgumentException('Invalid opaque reference.');
    }
    foreach(['evidence_sha256','observation_sha256'] as $field)if(!is_string($input[$field])||!preg_match('/\A[a-f0-9]{64}\z/D',$input[$field]))throw new InvalidArgumentException('Invalid digest.');
    if(!in_array($input['finding'],['recipient_reports_received','receipt_not_confirmed','escalated'],true))throw new InvalidArgumentException('Invalid finding.');
}
function payhub_review_id($id){if(!is_int($id)||$id<1||$id>2147483647)throw new InvalidArgumentException('Invalid transaction identifier.');}

/** Caller owns transaction. Locks serialize with protected senders; no payload/key output. */
function payhub_review_snapshot(PDO $db,$id){
    payhub_review_id($id);if(!$db->inTransaction())throw new RuntimeException('Snapshot requires transaction.');
    $q=$db->prepare("SELECT t.*,SHA2(COALESCE(u.webhook_url,''),256) AS review_endpoint_digest,SHA2(COALESCE(CASE WHEN t.is_test=1 THEN u.test_secret_key ELSE u.secret_key END,''),256) AS review_key_digest FROM transactions t JOIN users u ON t.user_id=u.id WHERE t.id=? FOR UPDATE");$q->execute([$id]);$payment=$q->fetch(PDO::FETCH_ASSOC);
    if(!$payment)throw new RuntimeException('Payment unavailable.');
    $q=$db->prepare('SELECT transaction_id,producer,payload_sha256,SHA2(payload,256) AS actual_payload_digest,snapshot_sha256,endpoint_sha256,merchant_key_sha256 FROM webhook_outbox WHERE transaction_id=? FOR UPDATE');$q->execute([$id]);$event=$q->fetch(PDO::FETCH_ASSOC);
    if(!$event)throw new RuntimeException('Review requires committed outbox evidence.');
    $q=$db->prepare('SELECT transaction_id,log_id,state,delivery_kind,http_code,claimed_at,completed_at,payload_sha256,endpoint_sha256 FROM webhook_retry_claims WHERE transaction_id=? FOR UPDATE');$q->execute([$id]);$claim=$q->fetch(PDO::FETCH_ASSOC);
    // Hash potentially sensitive historical bodies/responses in SQL, never return them.
    $q=$db->prepare('SELECT id,status,attempt_count,last_attempt_at,SHA2(payload,256) AS body_digest,SHA2(response,256) AS response_digest FROM webhook_logs WHERE transaction_id=? ORDER BY id LIMIT 201 FOR UPDATE');$q->execute([$id]);$logs=$q->fetchAll(PDO::FETCH_ASSOC);
    if(count($logs)>200)throw new RuntimeException('Evidence exceeds bounded review capacity.');
    // Normalize PDO numeric representation across operator connection settings.
    $normalize=static function($row){if($row===false)return null;ksort($row,SORT_STRING);foreach($row as &$value)if($value!==null)$value=(string)$value;return $row;};
    $observation=['payment'=>$normalize($payment),'event'=>$normalize($event),'claim'=>$normalize($claim),'logs'=>array_map($normalize,$logs)];
    return ['transaction_id'=>$id,'observation_sha256'=>hash('sha256',json_encode($observation,JSON_THROW_ON_ERROR)),'claim_state'=>$claim===false?'absent':$claim['state'],'log_count'=>count($logs),'advisory_only'=>true];
}
function payhub_inspect_webhook_review(PDO $db,$id){
    if($db->inTransaction())throw new RuntimeException('Independent review connection required.');
    $db->beginTransaction();try{$result=payhub_review_snapshot($db,$id);$db->rollBack();return $result;}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function payhub_record_webhook_review(PDO $db,$id,array $input){
    payhub_review_id($id);payhub_review_input($input);
    if($db->inTransaction())throw new RuntimeException('Independent review connection required.');
    $tables=['transactions','users','webhook_outbox','webhook_retry_claims','webhook_logs','webhook_review_journal'];
    $engines=$db->query("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('transactions','users','webhook_outbox','webhook_retry_claims','webhook_logs','webhook_review_journal')")->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach($tables as $table)if(strtolower((string)($engines[$table]??''))!=='innodb')throw new RuntimeException('Transactional review schema unavailable.');
    $db->beginTransaction();try{
        // Idempotent acknowledgement of the SAME already committed record; never a new observation.
        $q=$db->prepare('SELECT transaction_id,request_id,actor_ref,evidence_ref,evidence_sha256,observation_sha256,finding FROM webhook_review_journal WHERE request_id=?');$q->execute([$input['request_id']]);$existing=$q->fetch(PDO::FETCH_ASSOC);
        if($existing){$priorId=(int)$existing['transaction_id'];unset($existing['transaction_id']);if($priorId!==$id||$existing!==$input)throw new RuntimeException('Request reference conflict.');$db->rollBack();return 'already_recorded';}
        $snapshot=payhub_review_snapshot($db,$id);
        if(!hash_equals($snapshot['observation_sha256'],$input['observation_sha256']))throw new RuntimeException('Observation changed; inspect again.');
        $q=$db->prepare('INSERT INTO webhook_review_journal(transaction_id,request_id,actor_ref,evidence_ref,evidence_sha256,observation_sha256,finding) VALUES(?,?,?,?,?,?,?)');
        $q->execute(array_merge([$id],array_values($input)));$db->commit();return 'recorded';
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}

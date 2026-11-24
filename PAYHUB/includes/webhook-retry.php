<?php
require_once __DIR__.'/webhook-signing.php';

/** Own a short DB transaction; NEVER hold it open while contacting a merchant.
 * No lease expiry/reclaim: attempting after a crash is an uncertain external outcome.
 */
function payhub_claim_webhook_retry(PDO $db, $logId, $reconciled) {
    if ($db->inTransaction()) throw new RuntimeException('Retry requires a separate database transaction.');
    if ($reconciled !== true || !is_int($logId) || $logId < 1) throw new RuntimeException('Confirm independent reconciliation before requesting a retry.');
    // Missing migration/nontransactional tables must never fall back to unclaimed HTTP.
    $engines=$db->query("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('transactions','users','webhook_logs','webhook_retry_claims')")->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach (['transactions','users','webhook_logs','webhook_retry_claims'] as $table) {
        if (strtolower((string)($engines[$table]??''))!=='innodb') throw new RuntimeException('Retry schema is unavailable; apply and verify the required InnoDB migrations.');
    }
    if ((int)$db->query('SELECT @@autocommit')->fetchColumn()!==1) throw new RuntimeException('Retry requires autocommit before claiming.');
    $db->beginTransaction();
    try {
        $stmt=$db->prepare("SELECT w.id, w.transaction_id, w.status AS delivery_status, w.payload, w.response, w.attempt_count, w.last_attempt_at, t.reference, t.status AS transaction_status, t.is_test, u.webhook_url, u.secret_key, u.test_secret_key FROM webhook_logs w JOIN transactions t ON w.transaction_id=t.id JOIN users u ON t.user_id=u.id WHERE w.id=? FOR UPDATE");
        $stmt->execute([$logId]);$delivery=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!$delivery || $delivery['transaction_status']!=='success' || $delivery['delivery_status']!=='failed') throw new RuntimeException('Only a linked failed delivery for a successful payment can be retried.');
        $ack=$db->prepare("SELECT id FROM webhook_logs WHERE transaction_id=? AND status='success' LIMIT 1");
        $ack->execute([(int)$delivery['transaction_id']]);
        if ($ack->fetchColumn()!==false) throw new RuntimeException('This payment already has an acknowledged delivery; do not replay it.');
        $payload=(string)$delivery['payload'];$data=json_decode($payload,true);
        if (!is_array($data) || ($data['data']['reference']??null)!==$delivery['reference']) throw new RuntimeException('Stored payload does not match this transaction.');
        $url=(string)$delivery['webhook_url'];
        // No redirects, credentials in URLs, or non-HTTPS endpoints for this protected path.
        $parts=parse_url($url);
        if (!$parts || strtolower($parts['scheme']??'')!=='https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) throw new RuntimeException('Retry requires a valid canonical HTTPS merchant endpoint.');
        $signature=payhub_merchant_webhook_signature($payload,$delivery,$delivery['is_test']??null);
        if ($signature==='') throw new RuntimeException('Webhook signing refused: check mode-specific merchant key and payload domain.');
        $token=bin2hex(random_bytes(32));
        $insert=$db->prepare('INSERT INTO webhook_retry_claims(transaction_id,log_id,claim_token,payload_sha256,endpoint_sha256,prior_response,prior_status,prior_attempt_count,prior_last_attempt_at) VALUES(?,?,?,?,?,?,?,?,?)');
        $insert->execute([(int)$delivery['transaction_id'],$logId,$token,hash('sha256',$payload),hash('sha256',$url),$delivery['response'],$delivery['delivery_status'],$delivery['attempt_count'],$delivery['last_attempt_at']]);
        // Count the reserved attempt before HTTP so crashes do not erase its existence.
        $update=$db->prepare('UPDATE webhook_logs SET attempt_count=COALESCE(attempt_count,0)+1,last_attempt_at=CURRENT_TIMESTAMP WHERE id=? AND transaction_id=?');
        $update->execute([$logId,(int)$delivery['transaction_id']]);
        $db->commit();
        return ['delivery'=>$delivery,'token'=>$token,'signature'=>$signature];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        if ($e instanceof PDOException && (int)($e->errorInfo[1]??0)===1062) throw new RuntimeException('A retry is already claimed for this payment. Reconcile its outcome; do not replay it.',0,$e);
        throw $e;
    }
}

/** Atomic compare-and-set finalization; any failure leaves the durable claim blocked. */
function payhub_finish_webhook_retry(PDO $db, array $claim, $httpCode, $transportOk) {
    if ($db->inTransaction()) throw new RuntimeException('Retry completion requires a separate database transaction.');
    $code=is_int($httpCode)?$httpCode:0;
    $ok=$transportOk===true && $code>=200 && $code<300;
    $db->beginTransaction();
    try {
        $update=$db->prepare("UPDATE webhook_retry_claims SET state=?,http_code=?,completed_at=CURRENT_TIMESTAMP WHERE transaction_id=? AND log_id=? AND claim_token=? AND state='attempting'");
        $delivery=$claim['delivery'];
        $update->execute([$ok?'succeeded':'unknown',$code,(int)$delivery['transaction_id'],(int)$delivery['id'],$claim['token']]);
        if ($update->rowCount()!==1) throw new RuntimeException('Retry completion no longer owns this attempt.');
        $log=$db->prepare('UPDATE webhook_logs SET status=?,response=? WHERE id=? AND transaction_id=? AND SHA2(payload,256)=?');
        $log->execute([$ok?'success':'failed','HTTP '.$code.($ok?' | Claimed retry acknowledged.':' | Retry outcome uncertain; independent reconciliation required.'),(int)$delivery['id'],(int)$delivery['transaction_id'],hash('sha256',(string)$delivery['payload'])]);
        if ($log->rowCount()!==1) throw new RuntimeException('Delivery record changed; retry completion requires reconciliation.');
        $db->commit();return $ok;
    } catch (Throwable $e) {if($db->inTransaction())$db->rollBack();throw $e;}
}

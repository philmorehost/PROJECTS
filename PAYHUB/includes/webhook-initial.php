<?php
require_once __DIR__.'/webhook-money.php';
require_once __DIR__.'/webhook-retry.php';
require_once __DIR__.'/webhook-outbox.php';

/** Persist exact payload + exclusive reservation BEFORE protected initial delivery.
 * This is not an outbox worker: there is no automatic replay, including after process death.
 * Calls inside accounting transactions fail closed without committing the caller's work.
 */
function payhub_claim_initial_webhook(PDO $db, array $expected, $body) {
    if ($db->inTransaction() || (int)$db->query('SELECT @@autocommit')->fetchColumn()!==1) throw new RuntimeException('Initial delivery requires committed accounting and autocommit.');
    $engines=$db->query("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('transactions','users','webhook_logs','webhook_retry_claims','webhook_outbox')")->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach(['transactions','users','webhook_logs','webhook_retry_claims','webhook_outbox'] as $table)if(strtolower((string)($engines[$table]??''))!=='innodb')throw new RuntimeException('Initial delivery schema unavailable.');
    if (!is_string($body) || empty($expected['id'])) throw new RuntimeException('Invalid initial delivery.');
    $db->beginTransaction();
    try {
        $q=$db->prepare('SELECT t.*,u.webhook_url,u.secret_key,u.test_secret_key FROM transactions t JOIN users u ON t.user_id=u.id WHERE t.id=? FOR UPDATE');
        $q->execute([(int)$expected['id']]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['status']!=='success') throw new RuntimeException('Payment no longer eligible for initial delivery.');
        // Snapshot-bound: no stale amount, account, mode, merchant destination or credential.
        foreach($row as $key=>$value)if(!array_key_exists($key,$expected) || $value!==$expected[$key])throw new RuntimeException('Payment snapshot changed before delivery.');
        $data=json_decode($body,true);
        if(!is_array($data) || ($data['data']['reference']??null)!==$row['reference'])throw new RuntimeException('Initial payload reference mismatch.');
        $signature=payhub_merchant_webhook_signature($body,$row,$row['is_test']??null);
        if($signature==='')throw new RuntimeException('Initial signing refused.');
        $url=(string)$row['webhook_url'];$parts=parse_url($url);
        if(!$parts || strtolower($parts['scheme']??'')!=='https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']))throw new RuntimeException('Initial delivery requires canonical HTTPS.');
        payhub_check_queued_webhook($db,$row,$body);
        $existing=$db->prepare('SELECT id FROM webhook_logs WHERE transaction_id=? LIMIT 1');$existing->execute([(int)$row['id']]);
        if($existing->fetchColumn()!==false)throw new RuntimeException('Existing delivery evidence requires reconciliation, not an automatic initial replay.');
        $log=$db->prepare("INSERT INTO webhook_logs(transaction_id,status,attempt_count,last_attempt_at,payload,response) VALUES(?,'failed',1,CURRENT_TIMESTAMP,?,'Initial delivery reserved; outcome unknown.')");
        $log->execute([(int)$row['id'],$body]);$logId=(int)$db->lastInsertId();$token=bin2hex(random_bytes(32));
        $claim=$db->prepare("INSERT INTO webhook_retry_claims(transaction_id,log_id,claim_token,payload_sha256,endpoint_sha256,delivery_kind) VALUES(?,?,?,?,?,'initial')");
        $claim->execute([(int)$row['id'],$logId,$token,hash('sha256',$body),hash('sha256',$url)]);
        $db->commit();
        return ['delivery'=>['id'=>$logId,'transaction_id'=>(int)$row['id'],'payload'=>$body],'token'=>$token,'signature'=>$signature];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}

/** Bind the forwarding payload to current persisted settlement evidence.
 * Uses the existing PayHub conversion policy; does not calculate or change settlement.
 */
function payhub_forwarded_payload_matches($row, $payload) {
    if (!is_array($row) || !is_array($payload) || ($payload['event']??null)!=='charge.success' || !is_array($payload['data']??null)) return false;
    $data=$payload['data'];
    if (($row['status']??null)!=='success' || ($data['status']??null)!=='success'
        || ($data['reference']??null)!==($row['reference']??null)
        || !is_string($row['currency']??null) || $row['currency']===''
        || ($data['currency']??null)!==$row['currency']
        || ($data['receiving_account_number']??null)!==($row['receiving_account_number']??null)) return false;
    foreach (['amount'=>'gateway_amount','gateway_amount'=>'gateway_amount','requested_amount'=>'amount','fee_amount'=>'fee_amount','settled_amount'=>'settled_amount'] as $field=>$column) {
        if (!isset($row[$column]) || !is_numeric($row[$column]) || !is_int($data[$field]??null)
            || $data[$field]!==to_minor_units($row[$column])) return false;
    }
    return true;
}

/** Verify-page keeps its legacy requested-naira amount and requested-kobo alias.
 * Normalize only a private validation copy; never rewrite the emitted/stored body.
 */
function payhub_verify_payload_matches($row, $payload) {
    if (!is_array($row) || !is_array($payload) || !is_array($payload['data']??null)) return false;
    $data=$payload['data'];$legacy=$data['amount']??null;
    if ((!is_int($legacy) && !is_float($legacy) && !is_string($legacy)) || !is_numeric($legacy)
        || !isset($row['amount']) || !is_numeric($row['amount'])
        || to_minor_units($legacy)!==to_minor_units($row['amount'])
        || ($data['amount_kobo']??null)!==to_minor_units($row['amount'])
        || ($data['amount_unit']??null)!=='major' || ($data['amount_basis']??null)!=='requested'
        || ($data['money_contract_version']??null)!==1) return false;
    $normalized=$payload;
    $normalized['data']['amount']=$data['gateway_amount']??null;
    // This legacy payload does not publish receiving-account evidence. Do not add it to the body.
    $normalized['data']['receiving_account_number']=$row['receiving_account_number']??null;
    return payhub_forwarded_payload_matches($row,$normalized);
}

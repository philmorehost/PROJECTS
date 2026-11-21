<?php
/** Incident remediation: NGN mutation, receipt and replay key commit together.
 * No DDL here, no nested transaction and no provider calls while a row is locked.
 */
function bc_incident_wallet_failure($code) {
    error_log('[WALLET_MUTATION_REFUSED] '.$code);
    $GLOBALS['bc_charge_failure_reason']='Unable to update wallet safely. Please contact support with code WALLET_'.strtoupper($code).'.';
    return 'failed';
}
function bc_incident_decimal($value) {
    if (is_bool($value) || !is_scalar($value) || !is_numeric($value) || !is_finite((float)$value) || (float)$value <= 0) return null;
    if (is_float($value)) $value = sprintf('%.8F', $value);
    $value = trim((string)$value);
    if (!preg_match('/^[0-9]{1,12}(?:\.[0-9]{1,8})?$/D', $value)) return null;
    $parts=explode('.',$value,2);
    $whole=ltrim($parts[0],'0'); if ($whole==='') $whole='0';
    $fraction=isset($parts[1])?rtrim($parts[1],'0'):'';
    return $whole.($fraction!==''?'.'.$fraction:'');
}
function bc_incident_refund_source($description) {
    $plain = strip_tags((string)$description);
    if (preg_match("/Ref:?\s*'([^']+)'/i", $plain, $m)) return $m[1];
    if (preg_match('/withdrawal\s+([A-Za-z0-9_-]+)$/i', $plain, $m)) return $m[1];
    return null;
}
function bc_incident_wallet_post($vendor, $username, $direction, $product, $label, $reference, $api_reference, $amount, $charged, $description, $mode, $website, $status, $is_vendor = false) {
    global $connection_server;
    $started=microtime(true);
    $GLOBALS['bc_charge_failure_reason']=null;
    $vid=(int)$vendor; $direction=strtolower((string)$direction);
    $amount=bc_incident_decimal($amount); $charged=bc_incident_decimal($charged);
    $username=strtolower(trim((string)$username)); $reference=trim((string)$reference);
    if (!$connection_server || $vid<=0 || (!$is_vendor && $username==='') || $amount===null || $charged===null || !in_array($direction,array('credit','debit'),true) || !in_array((int)$status,array(1,2,3),true) || $reference==='' || strlen($reference)>225) return 'failed';
    $table=$is_vendor?'sas_vendors':'sas_users'; $history=$is_vendor?'sas_vendor_transactions':'sas_transactions';
    // Fail closed on missing/nontransactional migrations. Never silently commit caller work.
    try {
        $q=mysqli_query($connection_server,"SELECT @@session.autocommit AS auto_commit");
        $tx=$q?mysqli_fetch_assoc($q):null;
        // MySQL has no MariaDB @@in_transaction. This next-transaction statement
        // refuses an active outer transaction without committing it (not SET SESSION).
        if (!$tx || (int)$tx['auto_commit']!==1 || mysqli_query($connection_server,'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')!==true) {
            return bc_incident_wallet_failure('transaction_unavailable');
        }
    } catch(Throwable $e) { return bc_incident_wallet_failure('transaction_unavailable'); }
    $q=mysqli_query($connection_server,"SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('$table','$history','sas_wallet_mutation_events')");
    $engines=array(); while($q && ($r=mysqli_fetch_assoc($q))) $engines[$r['TABLE_NAME']]=strtoupper((string)$r['ENGINE']);
    foreach(array($table,$history,'sas_wallet_mutation_events') as $t) if (($engines[$t]??'')!=='INNODB') return bc_incident_wallet_failure('transactional_schema_required');
    if (!$is_vendor && $direction==='debit' && $label!=='Charge' && function_exists('bc_tx_failure_guard_check') && bc_tx_failure_guard_check($vid,$username,$label)!==true) return 'failed';
    $refund=$direction==='credit' && strcasecmp(trim((string)$label),'Refund')===0;
    $original=$refund?bc_incident_refund_source($description):null;
    if ($refund && !$original) return 'failed';
    $canonical=$refund?'refund:'.$original:($direction==='credit' && (string)$api_reference!==''?'provider:'.(string)$api_reference:'reference:'.$reference);
    $actor=$is_vendor?'vendor':'user:'.$username;
    $key=hash('sha256',json_encode(array($vid,$actor,$direction,$canonical)));
    $fingerprint=hash('sha256',json_encode(array($vid,$actor,$direction,(string)$charged,$refund?$original:$canonical)));
    $esc=function($v)use($connection_server){return mysqli_real_escape_string($connection_server,(string)$v);};
    $u=$esc($username);$ref=$esc($reference);$api=$esc($api_reference);$lab=$esc(strip_tags((string)$label));
    $where=$is_vendor?"id=$vid":"vendor_id=$vid AND username='$u'";
    if (!mysqli_begin_transaction($connection_server)) return bc_incident_wallet_failure('transaction_unavailable');
    try {
        $q=mysqli_query($connection_server,"SELECT balance,status,email FROM $table WHERE $where LIMIT 1 FOR UPDATE");
        $row=$q?mysqli_fetch_assoc($q):null;
        if (!$row || (int)$row['status']!==1) throw new RuntimeException('inactive_wallet');
        $before=(string)$row['balance'];
        $q=mysqli_query($connection_server,"SELECT payload_hash FROM sas_wallet_mutation_events WHERE event_key='$key' LIMIT 1");
        if (!$q) throw new RuntimeException('event_lookup_failed');
        if ($old=mysqli_fetch_assoc($q)) {
            mysqli_rollback($connection_server);
            // A replayed debit MUST NOT authorize another provider delivery.
            return $direction==='credit' && hash_equals($old['payload_hash'],$fingerprint)?'success':'failed';
        }
        if ($refund) {
            $debit_key=hash('sha256',json_encode(array($vid,$actor,'debit','reference:'.$original)));
            $q=mysqli_query($connection_server,"SELECT amount FROM sas_wallet_mutation_events WHERE event_key='$debit_key' LIMIT 1");
            $source=$q?mysqli_fetch_assoc($q):null;
            if (!$source || (float)$charged!==(float)$source['amount']) throw new RuntimeException('refund_has_no_matching_debit');
        }
        // Protect pre-upgrade receipts too. Legacy uncertain/refunded cases need reconciliation.
        $scope=$is_vendor?'':" AND username='$u'";
        $q=mysqli_query($connection_server,"SELECT reference FROM $history WHERE vendor_id=$vid$scope AND (reference='$ref'".(!$is_vendor && $api!==''?" OR (api_reference='$api' AND type_alternative='$lab' AND status=1)":'').") LIMIT 1");
        if (!$q || mysqli_num_rows($q)>0) throw new RuntimeException('historical_reference_requires_reconciliation');
        $delta=$direction==='debit'?'-':'+';
        $guard=$direction==='debit'?" AND balance >= $charged":'';
        if (!mysqli_query($connection_server,"UPDATE $table SET balance=balance$delta$charged WHERE $where$guard") || mysqli_affected_rows($connection_server)!==1) throw new RuntimeException('debit_or_credit_failed');
        $q=mysqli_query($connection_server,"SELECT balance FROM $table WHERE $where LIMIT 1");
        $fresh=$q?mysqli_fetch_assoc($q):null;
        if (!$fresh) throw new RuntimeException('balance_read_failed');
        $after=(string)$fresh['balance'];
        $fields="vendor_id,product_unique_id,type_alternative,reference,amount,discounted_amount,balance_before,balance_after,description,api_website,status";
        $values="$vid,'".$esc(strip_tags((string)$product))."','$lab','$ref','$amount','$charged','".$esc($before)."','".$esc($after)."','".$esc(strip_tags((string)$description))."','".$esc($website)."',".(int)$status;
        if (!$is_vendor) { $fields.=',username,api_reference,mode';$values.=",'$u','$api','".$esc($mode)."'"; }
        if (!mysqli_query($connection_server,"INSERT INTO $history ($fields) VALUES ($values)")) throw new RuntimeException('receipt_failed');
        if (!mysqli_query($connection_server,"INSERT INTO sas_wallet_mutation_events (event_key,payload_hash,vendor_id,actor,direction,reference,amount) VALUES ('$key','$fingerprint',$vid,'".$esc($actor)."','$direction','$ref','$charged')")) throw new RuntimeException('replay_claim_failed');
        if (!mysqli_commit($connection_server)) throw new RuntimeException('commit_failed');
    } catch(Throwable $e) {
        mysqli_rollback($connection_server);
        return bc_incident_wallet_failure(preg_match('/^[a-z_]+$/D',$e->getMessage()) ? $e->getMessage() : 'storage_error');
    }
    $committed_at=microtime(true);
    // Notify only AFTER the wallet, replay claim and receipt have committed.
    try {
        if (function_exists('sendVendorEmail')) {
            $recipient=(string)($row['email'] ?? '');
            if (is_string($recipient) && filter_var($recipient,FILTER_VALIDATE_EMAIL)) sendVendorEmail($recipient,'Wallet transaction recorded',htmlspecialchars("Reference: $reference | $direction NGN $charged | Balance: $after | $description",ENT_QUOTES,'UTF-8'));
        }
    } catch(Throwable $ignored) { error_log('[WALLET_NOTICE] Delivery failed after committed transaction.'); }
    $finished=microtime(true);
    if ($finished-$started>=1) error_log('[WALLET_TIMING] '.json_encode(['mutation_ms'=>(int)round(1000*($committed_at-$started)),'notice_ms'=>(int)round(1000*($finished-$committed_at))]));
    return 'success';
}

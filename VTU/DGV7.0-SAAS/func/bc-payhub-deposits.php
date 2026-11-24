<?php
/** PayHub dedicated-account deposits only. No checkout, payout or legacy settlement fallback. */
function bc_ph_query($db, $sql, $args = []) {
    $s = $db->prepare($sql);
    if (!$s) throw new RuntimeException('storage_unavailable');
    try {
        if ($args) { $types = str_repeat('s', count($args)); $s->bind_param($types, ...$args); }
        if (!$s->execute()) throw new RuntimeException('storage_unavailable');
        return $s->get_result();
    } finally { $s->close(); }
}
function bc_ph_minor($v, $zero = false) {
    if (is_int($v)) $v = (string)$v;
    if (!is_string($v) || !preg_match('/\A(?:0|[1-9][0-9]{0,11})\z/D', $v)) return null;
    $n = (int)$v;
    return $n > 0 || ($zero && $n === 0) ? $n : null;
}
function bc_ph_decimal($n) { return intdiv($n, 100).'.'.str_pad((string)($n % 100), 2, '0', STR_PAD_LEFT); }
function bc_ph_parse($raw) {
    if (!is_string($raw) || strlen($raw) > 131072) throw new RuntimeException('invalid_event');
    $e = json_decode($raw, true, 32);
    $d = is_array($e) ? ($e['data'] ?? null) : null;
    if (($e['event'] ?? '') !== 'charge.success' || !is_array($d) || !is_string($d['reference'] ?? null) || !preg_match('/\A[A-Za-z0-9_.:-]{1,128}\z/D', $d['reference'])) throw new RuntimeException('invalid_event');
    // Only a routing hint until signature and independent verification succeed.
    $account = $d['receiving_account_number'] ?? ($d['dedicated_account']['account_number'] ?? ($d['authorization']['receiver_bank_account_number'] ?? ($d['metadata']['receiver_account_number'] ?? '')));
    if (!is_string($account) || !preg_match('/\A[0-9]{10}\z/D', $account)) throw new RuntimeException('account_evidence_missing');
    return ['reference'=>$d['reference'], 'account'=>$account];
}
function bc_ph_binding($db, $account, $lock = false) {
    $suffix = $lock ? ' FOR UPDATE' : '';
    $found = [];
    foreach (['user'=>'sas_user_banks','vendor'=>'sas_vendor_banks'] as $target=>$table) {
        $q = bc_ph_query($db, "SELECT * FROM $table WHERE account_number=? AND LOWER(TRIM(gateway_name))='payhub' LIMIT 2".$suffix, [$account]);
        while ($r = $q->fetch_assoc()) { $r['target'] = $target; $found[] = $r; }
    }
    if (count($found) !== 1 || (int)$found[0]['status'] !== 1 || (int)$found[0]['vendor_id'] <= 0) throw new RuntimeException('account_binding_requires_review');
    $r = $found[0];
    if ($r['target'] === 'user' && (!is_string($r['username'] ?? null) || trim($r['username']) === '')) throw new RuntimeException('account_binding_requires_review');
    return $r;
}
function bc_ph_keys($db, $b, $lock = false) {
    if ($b['target'] === 'vendor') {
        $sql = "SELECT secret_key,status FROM sas_super_admin_payment_gateways WHERE LOWER(TRIM(gateway_name))='payhub'"; $args=[];
    } else {
        $sql = "SELECT secret_key,status FROM sas_payment_gateways WHERE vendor_id=? AND LOWER(TRIM(gateway_name))='payhub'"; $args=[(string)$b['vendor_id']];
    }
    $q = bc_ph_query($db, $sql.' LIMIT 2'.($lock ? ' FOR UPDATE' : ''), $args);
    $rows = $q->fetch_all(MYSQLI_ASSOC);
    if (count($rows) !== 1 || (int)$rows[0]['status'] !== 1 || !is_string($rows[0]['secret_key']) || !preg_match('/\Ask_live_[A-Za-z0-9_-]{8,200}\z/D', $rows[0]['secret_key'])) throw new RuntimeException('merchant_configuration_requires_review');
    return $rows[0]['secret_key'];
}
function bc_ph_verified($v, $hint) {
    $d = is_array($v) ? ($v['data'] ?? null) : null;
    if (($v['status'] ?? null) !== true || ($v['paid'] ?? null) !== true || !is_array($d) || ($d['status'] ?? '') !== 'success' || ($d['paid'] ?? null) !== true || ($d['domain'] ?? '') !== 'live' || ($d['currency'] ?? '') !== 'NGN' || ($d['reference'] ?? null) !== $hint['reference'] || ($d['receiving_account_number'] ?? null) !== $hint['account'] || ($d['deposit_evidence_version'] ?? null) !== 1) throw new RuntimeException('payment_evidence_requires_review');
    $gross = bc_ph_minor($d['gateway_amount'] ?? null);
    $fee = bc_ph_minor($d['fee_amount'] ?? null, true);
    $net = bc_ph_minor($d['settled_amount'] ?? null);
    if ($gross === null || $fee === null || $net === null || $net + $fee !== $gross) throw new RuntimeException('payment_amount_requires_review');
    // Provider amounts are explicitly KOBO. No float/naira heuristics or metadata beneficiaries.
    return $hint + ['gross'=>$gross, 'fee'=>$fee, 'net'=>$net];
}
function bc_ph_fetch($base, $secret, $reference) {
    // The audited PayHub host only. Do not send merchant credentials to arbitrary config URLs.
    if (!is_string($base) || !preg_match('~\Ahttps://merchant\.payhub\.com\.ng/?\z~D', $base)) throw new RuntimeException('provider_endpoint_requires_review');
    $ch = curl_init(rtrim($base,'/').'/api/transaction/verify/'.rawurlencode($reference));
    if (!$ch) throw new RuntimeException('verification_unavailable');
    $body='';
    try {
        curl_setopt_array($ch, [CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$secret,'Accept: application/json'], CURLOPT_FOLLOWLOCATION=>false, CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>15, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS, CURLOPT_WRITEFUNCTION=>function($c,$chunk)use(&$body){if(strlen($body)+strlen($chunk)>131072)return 0;$body.=$chunk;return strlen($chunk);}]);
        $ok = curl_exec($ch);
        if ($ok === false || (int)curl_getinfo($ch,CURLINFO_HTTP_CODE) !== 200) throw new RuntimeException('verification_unavailable');
    } finally { curl_close($ch); }
    $v=json_decode($body,true,32);
    if (!is_array($v)) throw new RuntimeException('verification_unavailable');
    return $v;
}
function bc_ph_schema($db) {
    $tables=['sas_payhub_deposits','sas_users','sas_vendors','sas_user_banks','sas_vendor_banks','sas_transactions','sas_vendor_transactions','sas_wallet_mutation_events','sas_payment_gateways','sas_super_admin_payment_gateways'];
    $names="'".implode("','",$tables)."'";
    $q=bc_ph_query($db,"SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ($names)");
    $engines=[];while($r=$q->fetch_assoc())$engines[$r['TABLE_NAME']]=strtoupper($r['ENGINE']);
    foreach($tables as $t)if(($engines[$t]??'')!=='INNODB')throw new RuntimeException('transactional_schema_required');
    $q=bc_ph_query($db,"SHOW INDEX FROM sas_payhub_deposits WHERE Key_name='PRIMARY'");
    $primary=$q->fetch_all(MYSQLI_ASSOC);
    if(count($primary)!==1 || $primary[0]['Column_name']!=='provider_reference' || (int)$primary[0]['Non_unique']!==0)throw new RuntimeException('transactional_schema_required');
    $q=bc_ph_query($db,'SELECT @@session.autocommit AS ac');
    if((int)$q->fetch_assoc()['ac']!==1)throw new RuntimeException('transaction_unavailable');
    // Fails if caller already has a transaction; never commits caller-owned work.
    bc_ph_query($db,'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
}
function bc_ph_binding_hash($b) {
    return hash('sha256',json_encode([$b['target'],(string)$b['id'],(string)$b['vendor_id'],(string)($b['username']??''),$b['account_number'],(string)$b['status']]));
}
function bc_ph_settle($db, $b, $secret, $p) {
    bc_ph_schema($db);
    $vid=(int)$b['vendor_id'];$vendor=$b['target']==='vendor';$u=$vendor?'':(string)$b['username'];
    $reference='PH_'.hash('sha256',$p['reference']);
    $fingerprint=hash('sha256',json_encode([$p,bc_ph_binding_hash($b),hash('sha256',$secret)]));
    if(!$db->begin_transaction())throw new RuntimeException('transaction_unavailable');
    try {
        // Global provider reference claim serializes deliveries across routes/tenants/beneficiaries.
        bc_ph_query($db,'INSERT INTO sas_payhub_deposits (provider_reference,fingerprint,vendor_id,target,username,account_number,gross_minor,fee_minor,net_minor,local_reference,state) VALUES (?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE provider_reference=provider_reference',[$p['reference'],$fingerprint,(string)$vid,$b['target'],$u,$p['account'],(string)$p['gross'],(string)$p['fee'],(string)$p['net'],$reference,'processing']);
        $q=bc_ph_query($db,'SELECT fingerprint,state,local_reference FROM sas_payhub_deposits WHERE provider_reference=? FOR UPDATE',[$p['reference']]);$claim=$q->fetch_assoc();
        if(!$claim || !hash_equals($claim['fingerprint'],$fingerprint))throw new RuntimeException('payment_claim_conflict');
        $fresh=bc_ph_binding($db,$p['account'],true);
        if(!hash_equals(bc_ph_binding_hash($b),bc_ph_binding_hash($fresh)) || !hash_equals($secret,bc_ph_keys($db,$fresh,true)))throw new RuntimeException('binding_changed');
        $q=bc_ph_query($db,'SELECT id,status,is_blocked FROM sas_vendors WHERE id=? FOR UPDATE',[(string)$vid]);$tenant=$q->fetch_assoc();
        if(!$tenant || (int)$tenant['status']!==1 || !empty($tenant['is_blocked']))throw new RuntimeException('wallet_restricted');
        $table=$vendor?'sas_vendors':'sas_users';$history=$vendor?'sas_vendor_transactions':'sas_transactions';
        $args=$vendor?[(string)$vid]:[(string)$vid,$u];
        $where=$vendor?'id=?':'vendor_id=? AND username=?';
        $q=bc_ph_query($db,"SELECT id,balance,status,is_blocked FROM $table WHERE $where LIMIT 2 FOR UPDATE",$args);$wallets=$q->fetch_all(MYSQLI_ASSOC);
        if(count($wallets)!==1 || (int)$wallets[0]['status']!==1 || !empty($wallets[0]['is_blocked']))throw new RuntimeException('wallet_restricted');
        $wallet=$wallets[0];
        if($claim['state']==='credited'){$db->rollback();return ['status'=>'success','code'=>'ALREADY_CREDITED','reference'=>$claim['local_reference']];}
        // Legacy receipts/events cannot silently be credited again. No auto backfill of old references.
        $q=bc_ph_query($db,'SELECT reference FROM sas_transactions WHERE api_reference=? OR reference=? OR reference=? LIMIT 1',[$p['reference'],$p['reference'],$reference]);
        if($q->fetch_assoc())throw new RuntimeException('historical_payment_requires_reconciliation');
        $q=bc_ph_query($db,'SELECT reference FROM sas_vendor_transactions WHERE reference=? OR reference=? LIMIT 1',[$p['reference'],$reference]);
        if($q->fetch_assoc())throw new RuntimeException('historical_payment_requires_reconciliation');
        $actor=$vendor?'vendor':'user:'.strtolower($u);$canonical='provider:'.$p['reference'];
        $event=hash('sha256',json_encode([$vid,$actor,'credit',$canonical]));
        $q=bc_ph_query($db,'SELECT event_key FROM sas_wallet_mutation_events WHERE event_key=? LIMIT 1',[$event]);
        if($q->fetch_assoc())throw new RuntimeException('historical_payment_requires_reconciliation');
        $amount=bc_ph_decimal($p['net']);$gross=bc_ph_decimal($p['gross']);
        bc_ph_query($db,"UPDATE $table SET balance=balance+? WHERE id=?",[$amount,(string)$wallet['id']]);
        $q=bc_ph_query($db,"SELECT balance FROM $table WHERE id=?",[(string)$wallet['id']]);$after=$q->fetch_assoc()['balance'];
        $fields='vendor_id,product_unique_id,type_alternative,reference,amount,discounted_amount,balance_before,balance_after,description,api_website,status';
        $values=[(string)$vid,'wallet_funding','Wallet Funding',$reference,$gross,$amount,(string)$wallet['balance'],(string)$after,'Verified PayHub virtual-account deposit; provider fees deducted','PAYHUB','1'];
        if(!$vendor){$fields.=',username,api_reference,mode';$values=array_merge($values,[$u,$p['reference'],'WEBHOOK']);}
        bc_ph_query($db,"INSERT INTO $history ($fields) VALUES (".implode(',',array_fill(0,count($values),'?')).')',$values);
        $eventHash=hash('sha256',json_encode([$vid,$actor,'credit',rtrim(rtrim($amount,'0'),'.'),$canonical]));
        bc_ph_query($db,'INSERT INTO sas_wallet_mutation_events (event_key,payload_hash,vendor_id,actor,direction,reference,amount) VALUES (?,?,?,?,?,?,?)',[$event,$eventHash,(string)$vid,$actor,'credit',$reference,$amount]);
        bc_ph_query($db,"UPDATE sas_payhub_deposits SET state='credited',credited_at=CURRENT_TIMESTAMP WHERE provider_reference=?",[$p['reference']]);
        if(!$db->commit())throw new RuntimeException('commit_uncertain');
        return ['status'=>'success','code'=>'CREDITED','reference'=>$reference];
    } catch(Throwable $e) { $db->rollback();throw $e; }
}
function bc_ph_receive($db, $raw, $signature, $route, $verify = null) {
    $hint=bc_ph_parse($raw);$b=bc_ph_binding($db,$hint['account']);
    if($b['target']!==$route)throw new RuntimeException('wrong_receiver');
    $secret=bc_ph_keys($db,$b);
    if(!is_string($signature) || !preg_match('/\A[a-f0-9]{64}\z/D',$signature) || !hash_equals(hash_hmac('sha256',$raw,$secret),$signature))throw new RuntimeException('invalid_signature');
    if($verify===null){
        $q=bc_ph_query($db,"SELECT option_value FROM sas_super_admin_options WHERE option_name='payhub_base_url' LIMIT 2");$options=$q->fetch_all(MYSQLI_ASSOC);
        if(count($options)>1)throw new RuntimeException('provider_endpoint_requires_review');
        $base=$options[0]['option_value']??'https://merchant.payhub.com.ng/';
        $verified=bc_ph_fetch($base,$secret,$hint['reference']);
    } else $verified=$verify($secret,$hint['reference']);
    return bc_ph_settle($db,$b,$secret,bc_ph_verified($verified,$hint));
}

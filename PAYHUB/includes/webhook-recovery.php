<?php
require_once __DIR__.'/webhook-initial.php';

/** Advisory only. The initial-claim helper rechecks all mutable state under lock. */
function payhub_outbox_recovery_candidate(PDO $db, $id) {
    if($db->inTransaction())throw new RuntimeException('Recovery requires an independent connection scope.');
    $q=$db->prepare('SELECT * FROM webhook_outbox WHERE transaction_id=?');$q->execute([$id]);$event=$q->fetch(PDO::FETCH_ASSOC);
    if(!$event)return ['status'=>'not_found'];
    $q=$db->prepare('SELECT state FROM webhook_retry_claims WHERE transaction_id=?');$q->execute([$id]);
    if($q->fetchColumn()!==false)return ['status'=>'already_claimed'];
    $q=$db->prepare('SELECT id FROM webhook_logs WHERE transaction_id=? LIMIT 1');$q->execute([$id]);
    if($q->fetchColumn()!==false)return ['status'=>'existing_delivery_evidence'];
    $q=$db->prepare('SELECT t.*,u.webhook_url,u.secret_key,u.test_secret_key FROM transactions t JOIN users u ON t.user_id=u.id WHERE t.id=?');$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
    $producers=['browser_verify','paystack_forward','api_verify','merchant_reconcile','api_reconcile','va_reconcile','dva_reconcile','expiry_cron','simulation'];
    if(!$row || $row['status']!=='success' || !in_array($event['producer'],$producers,true))return ['status'=>'needs_review'];
    $body=$event['payload'];$data=json_decode($body,true);
    if(strlen($body)>60000 || ($data['data']['money_contract_version']??null)!==1 || !hash_equals($event['payload_sha256'],hash('sha256',$body)))return ['status'=>'needs_review'];
    $valid=$event['producer']==='browser_verify'?payhub_verify_payload_matches($row,$data):payhub_forwarded_payload_matches($row,$data);
    if(!$valid || payhub_merchant_webhook_signature($body,$row,$row['is_test']??null)==='')return ['status'=>'needs_review'];
    if($event['producer']==='simulation' && !in_array($row['is_test'],[1,'1',true],true))return ['status'=>'needs_review'];
    foreach(payhub_outbox_binding($row) as $field=>$digest)if(!hash_equals($event[$field],$digest))return ['status'=>'needs_review'];
    return ['status'=>'ready','snapshot'=>$row,'payload'=>$body];
}

function payhub_recovery_host($url) {
    $p=parse_url($url);$host=strtolower((string)($p['host']??''));
    $allowed=array_filter(array_map('trim',explode(',',strtolower((string)getenv('PAYHUB_OUTBOX_RECOVERY_HOSTS')))));
    if(!$p || ($p['scheme']??'')!=='https' || ($p['port']??443)!==443 || isset($p['user']) || isset($p['pass']) || isset($p['fragment'])
        || !preg_match('/\A[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\z/D',$host) || !in_array($host,$allowed,true) || filter_var($host,FILTER_VALIDATE_IP))throw new RuntimeException('Destination not approved.');
    return $host;
}
function payhub_recovery_public_ipv4($ip) {
    if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))return false;
    // Octets avoid signed-width/bitmask differences on 32-bit PHP builds.
    [$a,$b,$c]=array_map('intval',explode('.',$ip));
    return $a!==0 && $a!==127 && $a<224
        && !($a===100 && $b>=64 && $b<=127)
        && !($a===198 && ($b===18 || $b===19 || ($b===51 && $c===100)))
        && !($a===192 && ($b===0 || ($b===88 && $c===99)))
        && !($a===203 && $b===0 && $c===113);
}
/** Public IPv4 pinning; no redirects, proxy, IPv6 fallback or unbounded response buffer. */
function payhub_recovery_network_plan($url) {
    if(!function_exists('curl_init'))throw new RuntimeException('cURL unavailable.');
    $host=payhub_recovery_host($url);$ips=gethostbynamel($host);
    if(!$ips)throw new RuntimeException('Destination unavailable.');
    foreach($ips as $ip)if(!payhub_recovery_public_ipv4($ip))throw new RuntimeException('Non-public destination refused.');
    return ['host'=>$host,'ip'=>$ips[0]];
}
function payhub_recovery_http($url,$body,$signature,array $plan) {
    if(getenv('PAYHUB_OUTBOX_RECOVERY_ENABLED')!=='1' || payhub_recovery_host($url)!==$plan['host'] || !payhub_recovery_public_ipv4($plan['ip']))throw new RuntimeException('Recovery disabled.');
    $ch=curl_init($url);if($ch===false)return [0,false];$bytes=0;
    try {
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-Payhub-Signature: '.$signature],
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>10,CURLOPT_PROXY=>'',CURLOPT_RESOLVE=>[$plan['host'].':443:'.$plan['ip']],
            CURLOPT_WRITEFUNCTION=>static function($ch,$chunk)use(&$bytes){$bytes+=strlen($chunk);return $bytes>65536?0:strlen($chunk);}]);
        $ok=curl_exec($ch)!==false && curl_errno($ch)===0;$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);return [$code,$ok];
    }finally{curl_close($ch);}
}

/** Transport injection is an internal test seam, never a request parameter. */
function payhub_recover_outbox(PDO $db,$id,$execute=false,?callable $transport=null) {
    $candidate=payhub_outbox_recovery_candidate($db,$id);
    if($candidate['status']!=='ready')return $candidate['status'];
    if(!$execute)return 'ready_dry_run';
    if(getenv('PAYHUB_OUTBOX_RECOVERY_ENABLED')!=='1')return 'disabled';
    $row=$candidate['snapshot'];$body=$candidate['payload'];
    $plan=$transport===null?payhub_recovery_network_plan($row['webhook_url']):null;
    // No helper here can reclaim an old claim. Races lose the unique initial claim before HTTP.
    $claim=payhub_claim_initial_webhook($db,$row,$body);
    try {
        if(getenv('PAYHUB_OUTBOX_RECOVERY_ENABLED')!=='1')throw new RuntimeException('Recovery disabled after reservation.');
        [$code,$ok]=$transport!==null?$transport($row['webhook_url'],$body,$claim['signature']):payhub_recovery_http($row['webhook_url'],$body,$claim['signature'],$plan);
        return payhub_finish_webhook_retry($db,$claim,$code,$ok)?'acknowledged':'unknown';
    }catch(Throwable $e){
        // Keep attempting on exception/completion failure; never infer it is safe to resend.
        return 'claimed_requires_reconciliation';
    }
}

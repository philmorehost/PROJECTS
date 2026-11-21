<?php
require_once __DIR__.'/bc-crypto-poll.php';
function bc_crypto_poll_delivery_config() {
    if(PHP_SAPI!=='cli'||getenv('BC_CRYPTO_POLL_ALERT_DELIVERY_ENABLED')!=='1')throw new RuntimeException('crypto_poll_delivery_disabled');
    return bc_crypto_poll_delivery_validate_config(getenv('BC_CRYPTO_POLL_ALERT_URL'),getenv('BC_CRYPTO_POLL_ALERT_KEY'));
}
function bc_crypto_poll_delivery_validate_config($url,$key) {
    if(!is_string($url)||strlen($url)>512||!preg_match('~^https://([a-z0-9](?:[a-z0-9.-]*[a-z0-9])?)(/[a-zA-Z0-9/_-]*)$~D',$url,$m)||strpos($m[1],'.')===false||filter_var($m[1],FILTER_VALIDATE_IP)||!is_string($key)||!preg_match('/^[a-f0-9]{64}$/D',$key))throw new RuntimeException('crypto_poll_delivery_config_invalid');
    return ['url'=>$url,'host'=>$m[1],'key'=>$key,'hash'=>hash('sha256',$url.'\n'.$key)];
}
function bc_crypto_poll_delivery_public_ip($ip) {
    if(!is_string($ip)||!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))return false;
    $n=sprintf('%u',ip2long($ip));
    // Also exclude CGNAT, documentation, benchmark, multicast and unspecified ranges.
    foreach([['0.0.0.0',8],['100.64.0.0',10],['192.0.0.0',24],['192.0.2.0',24],['198.18.0.0',15],['198.51.100.0',24],['203.0.113.0',24],['224.0.0.0',3]] as $r){$size=2**(32-$r[1]);$base=sprintf('%u',ip2long($r[0]));if($n>=$base&&$n<$base+$size)return false;}
    return true;
}
function bc_crypto_poll_delivery_receipt($body,$id,$payload,$key) {
    if(!is_string($body)||strlen($body)>4096)return false;
    $r=json_decode($body,true);
    return is_array($r)&&count($r)===3&&($r['delivery_id']??null)===$id&&($r['accepted']??null)===true&&is_string($r['signature']??null)&&hash_equals(hash_hmac('sha256',"receipt\n".$id."\n".hash('sha256',$payload),$key),$r['signature']);
}
/** One transaction claims a bounded attempt; no network while locks are held. */
function bc_crypto_poll_delivery_claim($stream,$revision,$config) {
    global $connection_server;bc_crypto_poll_id($stream);
    if(!is_int($revision)||$revision<1)throw new RuntimeException('crypto_poll_revision_invalid');
    bc_crypto_poll_begin();
    try {
        $s=bc_q_row('SELECT custody_account FROM sas_crypto_v2_poll_streams WHERE stream_id=?',[$stream]);
        if(!$s)throw new RuntimeException('crypto_poll_missing');
        bc_q_row('SELECT custody_account FROM sas_crypto_v2_custody_accounts WHERE custody_account=? FOR UPDATE',[$s['custody_account']]);
        bc_q_row('SELECT stream_id FROM sas_crypto_v2_poll_streams WHERE stream_id=? FOR UPDATE',[$stream]);
        $a=bc_q_row("SELECT created_at_epoch FROM sas_crypto_v2_poll_alerts WHERE stream_id=? AND revision=? AND kind='retry_escalation' FOR UPDATE",[$stream,$revision]);
        if(!$a)throw new RuntimeException('crypto_poll_alert_missing');
        $d=bc_q_row("SELECT * FROM sas_crypto_v2_poll_deliveries WHERE stream_id=? AND revision=? AND kind='retry_escalation' FOR UPDATE",[$stream,$revision]);$now=time();
        if($d&&($d['destination_hash']!==$config['hash']||$d['state']==='confirmed'||(int)$d['attempts']>=3||(int)$d['lease_until']>$now||(int)$d['next_attempt_at']>$now))throw new RuntimeException('crypto_poll_delivery_not_eligible');
        $id=$d?$d['delivery_id']:bin2hex(random_bytes(16));$attempt=$d?(int)$d['attempts']+1:1;
        if($d)bc_crypto_custody_must("UPDATE sas_crypto_v2_poll_deliveries SET state='sending',attempts=?,lease_until=?,next_attempt_at=? WHERE delivery_id=?",[$attempt,$now+60,$now+60*(2**($attempt-1)),$id]);
        else bc_crypto_custody_must("INSERT INTO sas_crypto_v2_poll_deliveries(delivery_id,stream_id,revision,kind,destination_hash,state,attempts,lease_until,next_attempt_at) VALUES(?,?,?,'retry_escalation',?,'sending',1,?,?)",[$id,$stream,$revision,$config['hash'],$now+60,$now+60]);
        bc_crypto_custody_must("INSERT INTO sas_crypto_v2_poll_delivery_events(delivery_id,attempt,action,occurred_at_epoch) VALUES(?,?,'claimed',?)",[$id,$attempt,$now]);
        $payload=bc_crypto_gov_json(['version'=>1,'delivery_id'=>$id,'stream_id'=>$stream,'revision'=>$revision,'kind'=>'retry_escalation','created_at_epoch'=>(int)$a['created_at_epoch']]);
        if(!mysqli_commit($connection_server))throw new RuntimeException('crypto_poll_storage_failed');
        return ['id'=>$id,'attempt'=>$attempt,'payload'=>$payload];
    }catch(Throwable $e){mysqli_rollback($connection_server);throw $e;}
}
function bc_crypto_poll_delivery_finish($claim,$confirmed) {
    global $connection_server;
    if(!is_bool($confirmed))throw new RuntimeException('crypto_poll_delivery_receipt_invalid');
    bc_crypto_poll_begin();
    try {
        $d=bc_q_row('SELECT * FROM sas_crypto_v2_poll_deliveries WHERE delivery_id=? FOR UPDATE',[$claim['id']]);
        if(!$d||$d['state']!=='sending'||(int)$d['attempts']!==$claim['attempt']||(int)$d['lease_until']<=time())throw new RuntimeException('crypto_poll_delivery_not_eligible');
        bc_crypto_custody_must('UPDATE sas_crypto_v2_poll_deliveries SET state=?,lease_until=0 WHERE delivery_id=?',[$confirmed?'confirmed':'retryable',$claim['id']]);
        bc_crypto_custody_must('INSERT INTO sas_crypto_v2_poll_delivery_events(delivery_id,attempt,action,occurred_at_epoch) VALUES(?,?,?,?)',[$claim['id'],$claim['attempt'],$confirmed?'confirmed':'unconfirmed',time()]);
        if(!mysqli_commit($connection_server))throw new RuntimeException('crypto_poll_storage_failed');
    }catch(Throwable $e){mysqli_rollback($connection_server);throw $e;}
}
/** No redirects/proxies/private destinations; DNS pinned to one validated public IPv4 address. */
function bc_crypto_poll_delivery_send($config,$claim) {
    $ips=gethostbynamel($config['host']);if(!$ips)throw new RuntimeException('crypto_poll_delivery_destination_invalid');
    foreach($ips as $ip)if(!bc_crypto_poll_delivery_public_ip($ip))throw new RuntimeException('crypto_poll_delivery_destination_invalid');
    return bc_crypto_poll_delivery_send_to_ip($config,$claim,$ips[0]);
}
/** Internal transport seam; a validated public IP is still mandatory. */
function bc_crypto_poll_delivery_send_to_ip($config,$claim,$ip) {
    if(!bc_crypto_poll_delivery_public_ip($ip))throw new RuntimeException('crypto_poll_delivery_destination_invalid');
    $body='';$ch=curl_init($config['url']);if($ch===false)throw new RuntimeException('crypto_poll_delivery_transport_failed');
    $at=(string)time();$sig=hash_hmac('sha256',$at."\n".$claim['id']."\n".$claim['payload'],$config['key']);
    try {
        if(!curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$claim['payload'],CURLOPT_HTTPHEADER=>['Content-Type: application/json','Idempotency-Key: '.$claim['id'],'X-Crypto-Timestamp: '.$at,'X-Crypto-Signature: '.$sig],CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROXY=>'',CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_SSLVERSION=>CURL_SSLVERSION_TLSv1_2,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>8,CURLOPT_RESOLVE=>[$config['host'].':443:'.$ip],CURLOPT_WRITEFUNCTION=>function($curl,$chunk)use(&$body){if(strlen($body)+strlen($chunk)>4096)return 0;$body.=$chunk;return strlen($chunk);}]))throw new RuntimeException('crypto_poll_delivery_transport_failed');
        $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        return $ok!==false&&$status>=200&&$status<300&&bc_crypto_poll_delivery_receipt($body,$claim['id'],$claim['payload'],$config['key']);
    }finally{curl_close($ch);}
}
function bc_crypto_poll_deliver_once($stream,$revision) {
    $config=bc_crypto_poll_delivery_config();$claim=bc_crypto_poll_delivery_claim($stream,$revision,$config);$confirmed=false;
    try{$confirmed=bc_crypto_poll_delivery_send($config,$claim);}catch(Throwable $e){/* Retain only generic unconfirmed result; never response/errors/URL/key. */}
    bc_crypto_poll_delivery_finish($claim,$confirmed);
    return ['delivery_id'=>$claim['id'],'confirmed'=>$confirmed,'operator_acknowledged'=>false];
}

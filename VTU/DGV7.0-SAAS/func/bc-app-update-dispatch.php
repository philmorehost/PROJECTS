<?php
/** Durable, at-most-one local send attempt per vendor/Android version. No automatic retries. */
function bc_app_update_dispatch($db,$vendor,$version,$project,$payload,$send) {
    $vid=(int)$vendor;$code=(string)$version;
    if($vid<=0 || !preg_match('/\A[1-9][0-9]{0,9}\z/D',$code) || (float)$code>2147483647 || !is_string($project) || !preg_match('/\A[a-z][a-z0-9-]{4,28}[a-z0-9]\z/D',$project) || !is_array($payload) || !is_callable($send))return 'unavailable';
    foreach($payload as $key=>$value)if(!is_string($key) || !is_string($value))return 'unavailable';
    if(($payload['version_code']??'')!==$code || ($payload['type']??'')!=='app_update')return 'unavailable';
    ksort($payload);
    try { $fingerprint=hash('sha256',json_encode([$project,'app_updates',$payload],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)); }
    catch(Throwable $e) { return 'unavailable'; }
    $event=hash('sha256','android-update:'.$vid.':'.$code);
    try {
        // Never dispatch before a durable claim. Refuse nested transactions/unsafe engines.
        $q=mysqli_query($db,'SELECT @@session.autocommit AS auto_commit');$tx=$q?mysqli_fetch_assoc($q):null;
        if(!$tx || (int)$tx['auto_commit']!==1 || mysqli_query($db,'SET TRANSACTION ISOLATION LEVEL READ COMMITTED')!==true)return 'unavailable';
        $q=mysqli_query($db,"SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sas_app_update_dispatches'");$engine=$q?mysqli_fetch_assoc($q):null;
        if(strtoupper((string)($engine['ENGINE']??''))!=='INNODB')return 'unavailable';
        // No UPSERT/reset/expiry: uncertainty must never become an automatic resend.
        $ok=mysqli_query($db,"INSERT INTO sas_app_update_dispatches (event_key,vendor_id,version_code,payload_hash,state) VALUES ('$event','$vid','$code','$fingerprint','dispatching')");
        if(!$ok)return mysqli_errno($db)===1062?'already_attempted':'unavailable';
        if(mysqli_affected_rows($db)!==1)return 'unavailable';
    } catch(Throwable $e) { return (int)$e->getCode()===1062?'already_attempted':'unavailable'; }
    // From this point any crash/timeout leaves dispatching (uncertain), NEVER retryable.
    $accepted=false;$receipt='';
    try {
        $result=$send();
        $name=$result['body']['name']??null;
        $accepted=($result['code']??0)===200 && is_string($name) && strpos($name,'projects/'.$project.'/messages/')===0 && strlen($name)<=1024;
        if($accepted)$receipt=hash('sha256',$name);
    } catch(Throwable $e) { /* Provider may have accepted before connection loss. */ }
    $state=$accepted?'accepted':'unknown';
    try {
        $saved=mysqli_query($db,"UPDATE sas_app_update_dispatches SET state='$state',provider_receipt_hash='$receipt' WHERE event_key='$event' AND payload_hash='$fingerprint' AND state='dispatching'");
        if(!$saved || mysqli_affected_rows($db)!==1)return 'unknown';
    } catch(Throwable $e) { return 'unknown'; }
    return $state;
}

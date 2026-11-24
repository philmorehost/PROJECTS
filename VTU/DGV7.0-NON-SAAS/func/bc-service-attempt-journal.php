<?php
/** Internal outcome journal ONLY. No dispatch/activation/debit/settlement authority or HTTP route. */
require_once __DIR__.'/bc-service-request-idempotency.php';
function bc_saj_sql($sql,$params=[]) {
    global $connection_server;
    $stmt=$connection_server->prepare($sql);if(!$stmt)throw new RuntimeException('unavailable');
    try {
        if($params){$types='';foreach($params as $p)$types.=is_int($p)?'i':'s';$stmt->bind_param($types,...$params);}
        if(!$stmt->execute())throw new RuntimeException('unavailable');
        $result=$stmt->get_result();return $result?$result->fetch_all(MYSQLI_ASSOC):$stmt->affected_rows;
    } finally {$stmt->close();}
}
function bc_saj_begin() {
    global $connection_server;
    if(!($connection_server instanceof mysqli))throw new RuntimeException('unavailable');
    $auto=bc_saj_sql('SELECT @@autocommit enabled');if((int)($auto[0]['enabled']??0)!==1)throw new RuntimeException('outer_transaction');
    if(!$connection_server->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED'))throw new RuntimeException('outer_transaction');
    $engines=bc_saj_sql("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('sas_service_attempts','sas_service_attempt_events','sas_service_requests')");
    if(count($engines)!==3)throw new RuntimeException('schema_unavailable');
    foreach($engines as $e)if(strtolower($e['ENGINE'])!=='innodb')throw new RuntimeException('nontransactional');
    if(!$connection_server->begin_transaction())throw new RuntimeException('unavailable');
}
function bc_saj_fingerprint($request) {
    $payload=json_decode((string)$request['payload'],true,512,JSON_THROW_ON_ERROR);
    return hash('sha256',json_encode([(int)$request['vendor_id'],(int)$request['user_id'],(int)$request['id'],(string)$request['type'],(string)$request['provider'],(string)$request['reference'],bc_svc_canonical_payload($payload)],JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION));
}
/** Register a request on HOLD. Replays recover the same provider key, never create another attempt. */
function bc_saj_hold($vendor,$request_id) {
    global $connection_server;$begun=false;
    if(!is_int($vendor)||$vendor<=0||!is_int($request_id)||$request_id<=0)return ['ok'=>false,'code'=>'invalid'];
    try {
        bc_saj_begin();$begun=true;
        $r=bc_saj_sql('SELECT * FROM sas_service_requests WHERE vendor_id=? AND id=? FOR UPDATE',[$vendor,$request_id])[0]??null;
        if(!$r)throw new RuntimeException('not_found');$hash=bc_saj_fingerprint($r);
        $old=bc_saj_sql('SELECT * FROM sas_service_attempts WHERE vendor_id=? AND request_id=? FOR UPDATE',[$vendor,$request_id])[0]??null;
        if($old){if(!hash_equals($old['input_hash'],$hash))throw new RuntimeException('conflict');}
        else {
            $key=bin2hex(random_bytes(32));
            bc_saj_sql('INSERT INTO sas_service_attempts(vendor_id,request_id,user_id,provider,input_hash,provider_key,state,version) VALUES(?,?,?,?,?,?,?,0)',[$vendor,$request_id,(int)$r['user_id'],(string)$r['provider'],$hash,$key,'held']);
            $old=['provider_key'=>$key,'state'=>'held','version'=>0];
        }
        if(!$connection_server->commit())throw new RuntimeException('unconfirmed');$begun=false;
        return ['ok'=>true,'code'=>'registered','provider_key'=>$old['provider_key'],'state'=>$old['state'],'version'=>(int)$old['version']];
    }catch(Throwable $e){if($begun)try{$connection_server->rollback();}catch(Throwable $ignored){}return ['ok'=>false,'code'=>in_array($e->getMessage(),['not_found','conflict','outer_transaction','schema_unavailable','nontransactional'],true)?$e->getMessage():'unconfirmed'];}
}
function bc_saj_outcome($state,$outcome) {
    if(!in_array($outcome,['unknown','confirmed_success','confirmed_failure'],true))return ['code'=>'invalid','state'=>$state];
    if($state==='held')return ['code'=>'held','state'=>$state];
    if(in_array($state,['confirmed_success','confirmed_failure'],true))return ['code'=>$state===$outcome?'already_final':'conflict','state'=>$state];
    if(!in_array($state,['in_flight','unknown'],true))return ['code'=>'invalid','state'=>$state];
    return ['code'=>'recorded','state'=>$outcome];
}
/** Caller MUST authenticate/verify provider evidence first. This helper does not verify a webhook. */
function bc_saj_observe($vendor,$request_id,$event_key,$outcome,$evidence_hash,$provider_reference,$expected_version) {
    global $connection_server;$begun=false;
    if(!is_int($vendor)||$vendor<=0||!is_int($request_id)||$request_id<=0||!is_int($expected_version)||$expected_version<0||!is_string($event_key)||!preg_match('/\A[a-f0-9]{64}\z/D',$event_key)||!is_string($evidence_hash)||!preg_match('/\A[a-f0-9]{64}\z/D',$evidence_hash)||!is_string($provider_reference)||strlen($provider_reference)>160||($provider_reference!==''&&!preg_match('/\A[A-Za-z0-9_.:-]+\z/D',$provider_reference))||!in_array($outcome,['unknown','confirmed_success','confirmed_failure'],true))return ['ok'=>false,'code'=>'invalid'];
    // Digest binds the complete observation, not only the externally assigned event id.
    $digest=hash('sha256',json_encode([$outcome,$evidence_hash,$provider_reference,$expected_version],JSON_THROW_ON_ERROR));
    try {
        bc_saj_begin();$begun=true;
        $request=bc_saj_sql('SELECT * FROM sas_service_requests WHERE vendor_id=? AND id=? FOR UPDATE',[$vendor,$request_id])[0]??null;
        $a=bc_saj_sql('SELECT * FROM sas_service_attempts WHERE vendor_id=? AND request_id=? FOR UPDATE',[$vendor,$request_id])[0]??null;
        if(!$request||!$a)throw new RuntimeException('not_found');
        if(!hash_equals($a['input_hash'],bc_saj_fingerprint($request)))throw new RuntimeException('conflict');
        $event=bc_saj_sql('SELECT * FROM sas_service_attempt_events WHERE vendor_id=? AND request_id=? AND event_key=? FOR UPDATE',[$vendor,$request_id,$event_key])[0]??null;
        if($event){
            if(!hash_equals($event['observation_hash'],$digest))throw new RuntimeException('conflict');
            $result=['code'=>$event['disposition'],'state'=>$event['result_state'],'version'=>(int)$event['result_version'],'replayed'=>true];
        }else{
            $result=bc_saj_outcome($a['state'],$outcome);$version=(int)$a['version'];
            if($expected_version!==$version)$result=['code'=>'stale','state'=>$a['state']];
            if($a['provider_reference']!==null&&$provider_reference!==''&&!hash_equals($a['provider_reference'],$provider_reference))$result=['code'=>'conflict','state'=>$a['state']];
            $reference=$a['provider_reference'];
            if($result['code']==='recorded'){
                if($reference===null&&$provider_reference!=='')$reference=$provider_reference;
                $version++;
                bc_saj_sql('UPDATE sas_service_attempts SET state=?,provider_reference=?,version=?,updated_at=NOW() WHERE vendor_id=? AND request_id=?',[$result['state'],$reference,$version,$vendor,$request_id]);
            }
            bc_saj_sql('INSERT INTO sas_service_attempt_events(vendor_id,request_id,event_key,observation_hash,evidence_hash,observed_outcome,observed_reference,disposition,result_state,result_version) VALUES(?,?,?,?,?,?,?,?,?,?)',[$vendor,$request_id,$event_key,$digest,$evidence_hash,$outcome,$provider_reference,$result['code'],$result['state'],$version]);
            $result['version']=$version;$result['replayed']=false;
        }
        if(!$connection_server->commit())throw new RuntimeException('unconfirmed');$begun=false;
        $result['ok']=in_array($result['code'],['recorded','already_final'],true);return $result;
    }catch(Throwable $e){if($begun)try{$connection_server->rollback();}catch(Throwable $ignored){}return ['ok'=>false,'code'=>in_array($e->getMessage(),['not_found','conflict','outer_transaction','schema_unavailable','nontransactional'],true)?$e->getMessage():'unconfirmed'];}
}

<?php
/** Durable request intake only. Does not authorize or retry provider execution. */
require_once __DIR__.'/bc-fintech-services.php';
function bc_svc_canonical_payload($value){
 if(!is_array($value))return $value;
 if(!array_is_list($value))ksort($value,SORT_STRING);
 foreach($value as $key=>$entry)$value[$key]=bc_svc_canonical_payload($entry);
 return $value;
}
function bc_svc_form_key($posted){return is_string($posted)&&preg_match('/\A[a-f0-9]{64}\z/D',$posted)?$posted:bin2hex(random_bytes(32));}
function bc_svc_request_once($vendor,$user,$type,$provider,array $payload,$key){
 global $connection_server;
 $fail=fn($code)=>['ok'=>false,'reference'=>'','replayed'=>false,'code'=>$code];
 if(!is_string($key)||!preg_match('/\A[a-f0-9]{64}\z/D',$key))return $fail('invalid_key');
 if(!is_array($user)||(int)$vendor<=0||(int)($user['id']??0)<=0||(int)($user['vendor_id']??0)!==(int)$vendor||!is_string($type)||!is_string($provider))return $fail('restricted');
 $vendor=(int)$vendor;$uid=(int)$user['id'];$begun=false;
 try{
  if(!($connection_server instanceof mysqli))return $fail('unavailable');
  $outer=$connection_server->query('SELECT @@autocommit AS enabled')->fetch_assoc();if((int)($outer['enabled']??0)!==1)return $fail('outer_transaction');
  // MySQL refuses SET TRANSACTION inside an active transaction, before any DDL can commit it.
  $connection_server->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
  $encoded=json_encode(bc_svc_canonical_payload($payload),JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION);
  if(strlen($encoded)>60000)return $fail('invalid_payload');
  $digest=hash('sha256',json_encode([$type,$provider,$encoded],JSON_THROW_ON_ERROR));$key_hash=hash('sha256',$key);
  if(!bc_svc_ensure_schema()||!$connection_server->query("CREATE TABLE IF NOT EXISTS sas_service_request_keys (
   vendor_id INT UNSIGNED NOT NULL,user_id INT UNSIGNED NOT NULL,key_hash CHAR(64) NOT NULL,
   payload_hash CHAR(64) NOT NULL,reference VARCHAR(64) NOT NULL DEFAULT '',created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
   PRIMARY KEY(vendor_id,user_id,key_hash)
  ) ENGINE=InnoDB"))return $fail('unavailable');
  $engines=$connection_server->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('sas_service_requests','sas_service_request_keys','sas_users')")->fetch_all(MYSQLI_ASSOC);
  if(count($engines)!==3)return $fail('unavailable');foreach($engines as $engine)if(strtolower($engine['ENGINE'])!=='innodb')return $fail('nontransactional');
  $connection_server->begin_transaction();$begun=true;
  $stmt=$connection_server->prepare('SELECT id FROM sas_users WHERE vendor_id=? AND id=? AND status=1 AND COALESCE(is_blocked,0)=0 AND COALESCE(force_password_change,0)=0 FOR UPDATE');
  $stmt->bind_param('ii',$vendor,$uid);$stmt->execute();$allowed=$stmt->get_result()->fetch_assoc();$stmt->close();if(!$allowed)throw new RuntimeException('restricted');
  $stmt=$connection_server->prepare('INSERT INTO sas_service_request_keys(vendor_id,user_id,key_hash,payload_hash) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE key_hash=key_hash');
  $stmt->bind_param('iiss',$vendor,$uid,$key_hash,$digest);$stmt->execute();$stmt->close();
  $stmt=$connection_server->prepare('SELECT payload_hash,reference FROM sas_service_request_keys WHERE vendor_id=? AND user_id=? AND key_hash=? FOR UPDATE');
  $stmt->bind_param('iis',$vendor,$uid,$key_hash);$stmt->execute();$record=$stmt->get_result()->fetch_assoc();$stmt->close();
  if(!$record||!hash_equals($record['payload_hash'],$digest))throw new RuntimeException('conflict');
  $reference=$record['reference'];$replayed=$reference!=='';
  if($replayed){
   $stmt=$connection_server->prepare('SELECT id FROM sas_service_requests WHERE vendor_id=? AND user_id=? AND reference=? AND type=? AND provider=? LIMIT 1 FOR UPDATE');
   $stmt->bind_param('iisss',$vendor,$uid,$reference,$type,$provider);$stmt->execute();$original=$stmt->get_result()->fetch_assoc();$stmt->close();if(!$original)throw new RuntimeException('unavailable');
  }
  if(!$replayed){
   // Schema was established BEFORE the transaction. No DDL can implicitly commit this insert.
   $reference=bc_svc_request_create($vendor,$user,$type,$provider,bc_svc_canonical_payload($payload),true);
   if($reference==='')throw new RuntimeException('unavailable');
   $stmt=$connection_server->prepare('UPDATE sas_service_request_keys SET reference=? WHERE vendor_id=? AND user_id=? AND key_hash=?');
   $stmt->bind_param('siis',$reference,$vendor,$uid,$key_hash);$stmt->execute();$stmt->close();
  }
  $connection_server->commit();$begun=false;
  return ['ok'=>true,'reference'=>$reference,'replayed'=>$replayed,'code'=>$replayed?'existing':'created'];
 }catch(Throwable $e){
  if($begun)try{$connection_server->rollback();}catch(Throwable $ignored){}
  return $fail(in_array($e->getMessage(),['restricted','conflict'],true)?$e->getMessage():'unconfirmed');
 }
}
function bc_svc_retry_message($result){
 return ($result['code']??'')==='conflict'?'This request key already belongs to different details. Check your request history; use Start a new request only for a genuinely new request.':'The result could not be confirmed. Retry the unchanged form with the same request key, or check request history. Do not assume the original submission failed.';
}

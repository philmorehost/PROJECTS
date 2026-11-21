<?php
/** Process identity is distinct from a human. Never accept credential material in HTTP/argv. */
final class BcCryptoPollWorkerAuthority {
    private $grant;private $credential;
    private function __construct($grant,$credential){$this->grant=$grant;$this->credential=$credential;}
    private function __clone(){}
    public function __serialize(){throw new RuntimeException('crypto_poll_worker_serialization_denied');}
    public function __unserialize($data){throw new RuntimeException('crypto_poll_worker_serialization_denied');}
    public static function authenticate($id) {
        if(PHP_SAPI!=='cli'||getenv('BC_CRYPTO_POLL_WORKER_ENABLED')!=='1')throw new RuntimeException('crypto_poll_worker_disabled');
        bc_crypto_poll_id($id);$c=bc_crypto_poll_worker_config();
        $g=bc_q_row('SELECT worker_name,credential_hash FROM sas_crypto_v2_poll_worker_grants WHERE grant_id=?',[$id]);
        if(!$g||$g['worker_name']!==$c['name']||!hash_equals($g['credential_hash'],$c['hash']))throw new RuntimeException('crypto_poll_worker_denied');
        return new self($id,$c['hash']);
    }
    /** Owner transaction already holds custody then stream lock. Revocation uses same ordering. */
    public function bindLease($row,$token) {
        bc_crypto_custody_must('UPDATE sas_crypto_v2_poll_worker_grants SET lease_token_hash=? WHERE grant_id=?',[hash('sha256',$token),$this->grant]);
    }
    public function check($row,$phase,$token=null) {
        if(PHP_SAPI!=='cli'||getenv('BC_CRYPTO_POLL_WORKER_ENABLED')!=='1')throw new RuntimeException('crypto_poll_worker_disabled');
        $c=bc_crypto_poll_worker_config();
        $g=bc_q_row('SELECT * FROM sas_crypto_v2_poll_worker_grants WHERE grant_id=? FOR UPDATE',[$this->grant]);
        if(!$g||!hash_equals($g['credential_hash'],$this->credential)||!hash_equals($c['hash'],$this->credential)||$g['worker_name']!==$c['name']||$g['stream_id']!==$row['stream_id']||(int)$g['revision']!==(int)$row['revision']||(int)$g['expires_at_epoch']<=time()||$g['state']!==($phase==='claim'?'pending':'running'))throw new RuntimeException('crypto_poll_worker_grant_invalid');
        if($phase!=='claim'&&(!is_string($token)||!is_string($g['lease_token_hash'])||!hash_equals($g['lease_token_hash'],hash('sha256',$token))))throw new RuntimeException('crypto_poll_worker_grant_invalid');
        if($phase==='claim')bc_crypto_custody_must("UPDATE sas_crypto_v2_poll_worker_grants SET state='running' WHERE grant_id=?",[$this->grant]);
        if($phase==='commit')bc_crypto_custody_must("UPDATE sas_crypto_v2_poll_worker_grants SET state='consumed',completed_at_epoch=? WHERE grant_id=?",[time(),$this->grant]);
    }
}
function bc_crypto_poll_worker_config() {
    $name=getenv('BC_CRYPTO_POLL_WORKER_NAME');$key=getenv('BC_CRYPTO_POLL_WORKER_KEY');
    if(!is_string($name)||!preg_match('/^[a-z][a-z0-9_]{2,31}$/D',$name)||!is_string($key)||!preg_match('/^[a-f0-9]{64}$/D',$key))throw new RuntimeException('crypto_poll_worker_config_invalid');
    return ['name'=>$name,'hash'=>hash('sha256',$key)];
}
/** Actor 0 is explicitly service-only; grant table supplies identity/provenance, never a spadmin ID. */
function bc_crypto_poll_execution_actor($authority) {
    if($authority instanceof BcCryptoPollWorkerAuthority)return 0;
    return bc_crypto_custody_actor($authority);
}
function bc_crypto_poll_worker_fence($authority,$row,$phase,$token=null) {
    if($authority instanceof BcCryptoPollWorkerAuthority)$authority->check($row,$phase,$token);
}

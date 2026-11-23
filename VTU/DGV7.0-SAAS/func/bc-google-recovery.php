<?php
/** Google ID tokens must already pass signature verification by verifyGoogleToken. */
function bc_google_recovery_scope($role,$tenant){return ($role==='admin'&&(int)$tenant>0)||($role==='spadmin'&&(int)$tenant===0);}
function bc_google_recovery_nonce($role,$tenant){
    if(!bc_google_recovery_scope($role,$tenant))throw new RuntimeException('Invalid recovery scope.');
    $old=$_SESSION['google_recovery_nonces'][$role]??null;
    if(!$old||$old['tenant']!==(int)$tenant||$old['expires']<=time())$_SESSION['google_recovery_nonces'][$role]=['tenant'=>(int)$tenant,'expires'=>time()+600,'nonce'=>bin2hex(random_bytes(32))];
    return $_SESSION['google_recovery_nonces'][$role]['nonce'];
}
function bc_google_recovery_nonce_check($role,$tenant,$payload){
    $old=$_SESSION['google_recovery_nonces'][$role]??null;
    unset($_SESSION['google_recovery_nonces'][$role],$_SESSION[$role.'-recovery-google']);
    return bc_google_recovery_scope($role,$tenant)&&$old&&$old['tenant']===(int)$tenant&&$old['expires']>time()
        &&is_array($payload)&&is_string($payload['nonce']??null)&&hash_equals($old['nonce'],$payload['nonce']);
}
function bc_google_recovery_fingerprint($row){
    $values=[];foreach(['id','email','google_id','password','security_pin','status','is_blocked','force_password_change'] as $key)$values[$key]=(string)($row[$key]??'');
    return hash('sha256',serialize($values));
}
function bc_google_recovery_grant($role,$tenant,$payload,$row,$client){
    if(!bc_google_recovery_scope($role,$tenant)||!bc_google_payload_valid($payload,$client)||!is_array($row)||empty($row['id'])||(int)($row['status']??0)!==1||!empty($row['is_blocked']))return false;
    if($role==='admin'&&(int)$row['id']!==(int)$tenant)return false;
    if(!isset($payload['iat'],$payload['exp'])||!is_numeric($payload['iat'])||!is_numeric($payload['exp'])||(int)$payload['iat']<time()-300||(int)$payload['iat']>time()+60||(int)$payload['exp']<=time())return false;
    if(strtolower((string)$row['email'])!==strtolower((string)$payload['email'])||(!empty($row['google_id'])&&!hash_equals((string)$row['google_id'],(string)$payload['sub'])))return false;
    unset($_SESSION[$role.'-recovery-code'],$_SESSION['recovery_challenges'][$role]);
    $_SESSION[$role.'-recovery-username']=(string)$row['email'];
    $_SESSION[$role.'-recovery-google']=['tenant'=>(int)$tenant,'expires'=>min(time()+300,(int)$payload['exp']),'identity'=>(string)$row['email'],'fingerprint'=>bc_google_recovery_fingerprint($row),'client'=>(string)$client];
    return true;
}
function bc_google_recovery_valid($role,$tenant,$client){
    $proof=$_SESSION[$role.'-recovery-google']??null;
    return bc_google_recovery_scope($role,$tenant)&&is_array($proof)&&($proof['tenant']??null)===(int)$tenant&&($proof['expires']??0)>time()
        &&($proof['client']??null)===(string)$client&&($proof['identity']??null)===($_SESSION[$role.'-recovery-username']??null);
}
function bc_google_recovery_matches($role,$tenant,$client,$row){
    return bc_google_recovery_valid($role,$tenant,$client)&&is_array($row)&&(int)($row['status']??0)===1&&empty($row['is_blocked'])
        &&hash_equals($_SESSION[$role.'-recovery-google']['fingerprint'],bc_google_recovery_fingerprint($row));
}
/** Compare identity/security fields in the write itself; never clear forced-reset flags. */
function bc_google_recovery_password_update($table,$row,$newHash){
    if(!in_array($table,['sas_vendors','sas_super_admin'],true)||!is_array($row)||empty($row['id'])||!is_string($newHash)||$newHash==='')return false;
    $where=['id=?'];$params=[$newHash,(int)$row['id']];
    foreach(['email','password','google_id','security_pin','status','is_blocked','force_password_change'] as $field){
        if(array_key_exists($field,$row)){$where[]="`$field` <=> ?";$params[]=$row[$field];}
    }
    $changed=null;
    return bc_q("UPDATE `$table` SET password=? WHERE ".implode(' AND ',$where),$params,$changed)===true && $changed===1;
}

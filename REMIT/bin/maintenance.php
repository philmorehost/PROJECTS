<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') {http_response_code(404);exit;}
const ROOT=__DIR__.'/..';require ROOT.'/app/Database.php';
$db=Database::get();
$get=function(string $key)use($db):int{$s=$db->prepare('SELECT setting_value FROM security_settings WHERE setting_key=?');$s->execute([$key]);return (int)$s->fetchColumn();};
// Do not let short display-retention preferences disable brute-force enforcement.
$minutes=max(15,$get('log_retention'),$get('account_window'),$get('ip_window'));
$s=$db->prepare("DELETE FROM security_audit_logs WHERE event_type IN ('failed_login','pin_failure','login_attempt') AND created_at < ?");
$s->execute([date('Y-m-d H:i:s',time()-$minutes*60)]);
echo $s->rowCount()." expired failure records removed. Other audit events retained.\n";

if ($get('church_portal_schema')===2) {
 $s=$db->prepare("DELETE FROM registration_limits WHERE window_started < ?");
 $s->execute([date('Y-m-d H:i:s',time()-86400)]);
 echo $s->rowCount()." expired registration rate-limit entries removed.\n";
}

require ROOT.'/app/PaymentProof.php';
if($get('payment_proof_schema')===1){
 // Minimal setting helper for the private-file maintenance task.
 function setting(PDO $db,string $key,string $default=''): string { $s=$db->prepare('SELECT setting_value FROM security_settings WHERE setting_key=?');$s->execute([$key]);return (string)($s->fetchColumn() ?: $default); }
 echo PaymentProof::prune($db)." orphan receipt files removed (24-hour grace).\n";
}

if($get('password_reset_schema')===1){
 $cutoff=date('Y-m-d H:i:s',time()-86400);
 foreach(['password_resets'=>'expires_at','password_reset_limits'=>'window_started'] as $table=>$column){$s=$db->prepare("DELETE FROM $table WHERE $column < ?");$s->execute([$cutoff]);echo $s->rowCount()." expired $table records removed.\n";}
}

$s=$db->prepare("DELETE FROM security_audit_logs WHERE event_type='registration_attempt' AND created_at < ?");$s->execute([date('Y-m-d H:i:s',time()-86400)]);echo $s->rowCount()." expired registration attempt counters removed.\n";

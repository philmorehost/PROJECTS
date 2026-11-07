<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') {http_response_code(404);exit;}
const ROOT=__DIR__.'/..';require ROOT.'/app/Database.php';
$db=Database::get();
$get=function(string $key)use($db):int{$s=$db->prepare('SELECT setting_value FROM security_settings WHERE setting_key=?');$s->execute([$key]);return (int)$s->fetchColumn();};
// Do not let short display-retention preferences disable brute-force enforcement.
$minutes=max(15,$get('log_retention'),$get('account_window'),$get('ip_window'));
$s=$db->prepare("DELETE FROM security_audit_logs WHERE event_type IN ('failed_login','pin_failure') AND created_at < ?");
$s->execute([date('Y-m-d H:i:s',time()-$minutes*60)]);
echo $s->rowCount()." expired failure records removed. Other audit events retained.\n";

if ($get('church_portal_schema')===2) {
 $s=$db->prepare("DELETE FROM registration_limits WHERE window_started < ?");
 $s->execute([date('Y-m-d H:i:s',time()-86400)]);
 echo $s->rowCount()." expired registration rate-limit entries removed.\n";
}

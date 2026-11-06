<?php
declare(strict_types=1);
final class Security {
 // Never trust client-provided forwarded headers. Configure trusted proxies at the web-server layer.
 public static function ip(): string { $ip=$_SERVER['REMOTE_ADDR'] ?? ''; return filter_var($ip,FILTER_VALIDATE_IP) ? $ip : '0.0.0.0'; }
 private static function count(PDO $db,string $field,string $value,int $minutes,string $event='failed_login'): int {
  if (!in_array($field,['ip_address','username_attempted'],true)) throw new LogicException('Invalid field');
  $since=date('Y-m-d H:i:s',time()-$minutes*60);
  $s=$db->prepare("SELECT COUNT(*) FROM security_audit_logs WHERE $field=? AND event_type=? AND created_at>=?"); $s->execute([$value,$event,$since]); return (int)$s->fetchColumn();
 }
 public static function block(PDO $db,string $email): void {
  $days=(int)setting($db,'block_days'); if (!in_array($days,[1,7,30,365],true)) $days=1;
  $until=date('Y-m-d H:i:s',time()+$days*86400);
  $db->prepare('UPDATE security_ips SET blocked_until=? WHERE ip_address=?')->execute([$until,self::ip()]);
  $db->prepare('INSERT INTO firewall_jobs(ip_address,blocked_until) VALUES(?,?)')->execute([self::ip(),$until]);
  audit($db,'ip_blocked',$email,'Application block applied; firewall job queued.');
  notify($db,setting($db,'notification_email'),'Security: IP blocked',self::ip().' blocked until '.$until);
 }
 public static function login(PDO $db,string $email,string $password): ?array {
  $ip=self::ip();
  $db->beginTransaction();
  try {
   $db->prepare('INSERT IGNORE INTO security_ips(ip_address) VALUES(?)')->execute([$ip]);
   $s=$db->prepare('SELECT * FROM security_ips WHERE ip_address=? FOR UPDATE'); $s->execute([$ip]); $state=$s->fetch();
   if ($state['blocked_until'] && strtotime($state['blocked_until'])>time()) { $db->commit(); return null; }
   $s=$db->prepare('SELECT * FROM users WHERE email=? FOR UPDATE'); $s->execute([$email]); $user=$s->fetch();
   // Valid fixed bcrypt hash equalizes password-check work for unknown users.
   $valid=password_verify($password,$user['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi');
   if (!$user || !$valid || $user['is_suspended']) {
    audit($db,'failed_login',$email);
    $db->prepare('UPDATE security_ips SET clean_sessions=0 WHERE ip_address=?')->execute([$ip]);
    $local=!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE);
    $accountScope=setting($db,'local_only')!=='1' || $local;
    $privileged=$user && in_array($user['role'],['provincial_admin','provincial_treasurer'],true);
    if ($user && !$user['is_suspended'] && setting($db,'username_protection')==='1' && $accountScope && (!$privileged || setting($db,'protect_privileged')==='1') && self::count($db,'username_attempted',$email,(int)setting($db,'account_window')) >= (int)setting($db,'account_max')) {
     $db->prepare('UPDATE users SET is_suspended=1 WHERE id=?')->execute([$user['id']]); audit($db,'account_suspended',$email);
     notify($db,setting($db,'notification_email'),'Security: account suspended',$email.' was suspended.');
    }
    // Recognized IPs remain subject to blocking; whitelist is an indicator, not an auth bypass.
    if (setting($db,'ip_protection')==='1' && (!$user || self::count($db,'ip_address',$ip,(int)setting($db,'ip_window')) >= (int)setting($db,'ip_max'))) self::block($db,$email);
    $db->commit(); return null;
   }
   $clean=self::count($db,'ip_address',$ip,(int)setting($db,'ip_window'))===0;
   if ($clean && empty($_SESSION['counted_login'])) {
    $db->prepare('UPDATE security_ips SET clean_sessions=clean_sessions+1 WHERE ip_address=?')->execute([$ip]);
    if (!$state['whitelisted'] && (int)$state['clean_sessions']+1>=5) { $db->prepare('UPDATE security_ips SET whitelisted=1 WHERE ip_address=?')->execute([$ip]); audit($db,'whitelisted_ip',$email); }
   }
   if ((int)$state['clean_sessions']===0) notify($db,setting($db,'notification_email'),'Security: unrecognized IP login',$email.' signed in from '.$ip);
   $db->prepare('UPDATE users SET successful_sessions_count=successful_sessions_count+1 WHERE id=?')->execute([$user['id']]);
   audit($db,'success',$email); $db->commit();
   session_regenerate_id(true); $_SESSION['user_id']=$user['id']; $_SESSION['counted_login']=true; $_SESSION['last_activity']=time(); $_SESSION['csrf']=bin2hex(random_bytes(32)); return $user;
  } catch(Throwable $e) { if($db->inTransaction()) $db->rollBack(); throw $e; }
 }
 public static function verifyPin(PDO $db,array $user,string $pin): bool {
  if (self::count($db,'username_attempted',$user['email'],15,'pin_failure')>=5) return false;
  if (!preg_match('/^\d{4}$/D',$pin) || !password_verify($pin,$user['transaction_pin_hash'] ?? '')) { audit($db,'pin_failure',$user['email']); return false; }
  return true;
 }
}

<?php
declare(strict_types=1);
final class Security {
 private static bool $limited=false;
 public static function loginMessage(): string {return self::$limited?'Sign-in is temporarily limited. Try again later, or reset your password and sign in in the same browser.':'Unable to sign in. Check your details or contact your administrator.';}
 private static function scopedFailures(PDO $db,string $email,int $minutes,?string $ip=null): int {
  $s=$db->prepare("SELECT COALESCE(MAX(id),0) FROM security_audit_logs WHERE username_attempted=? AND event_type IN ('success','password_reset','login_recovery')");$s->execute([$email]);$boundary=(int)$s->fetchColumn();
  $sql="SELECT COUNT(*) FROM security_audit_logs WHERE username_attempted=? AND event_type='failed_login' AND created_at>=? AND id>?";$params=[$email,date('Y-m-d H:i:s',time()-$minutes*60),$boundary];
  if($ip!==null){$sql.=' AND ip_address=?';$params[]=$ip;}$s=$db->prepare($sql);$s->execute($params);return (int)$s->fetchColumn();
 }
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
 public static function sessionPasswordMatches(array $user,array $session): bool {
  return is_string($session['password_stamp'] ?? null) && hash_equals(hash('sha256',$user['password_hash']),$session['password_stamp']);
 }
 public static function login(PDO $db,string $email,string $password): ?array {
  self::$limited=false;$ip=self::ip();
  $db->beginTransaction();
  try {
   $db->prepare('INSERT IGNORE INTO security_ips(ip_address) VALUES(?)')->execute([$ip]);
   $s=$db->prepare('SELECT * FROM security_ips WHERE ip_address=? FOR UPDATE'); $s->execute([$ip]); $state=$s->fetch();
   $blocked=$state['blocked_until'] && strtotime($state['blocked_until'])>time();
   $permit=$_SESSION['reset_login_permit'] ?? null;
   $candidate=is_array($permit) && ($permit['email'] ?? null)===$email && ($permit['ip'] ?? null)===$ip && ($permit['expires'] ?? 0)>time() && ($permit['attempts'] ?? 5)<5;
   $legacy=setting($db,'login_lock_mode')==='legacy';
   if($legacy && $blocked && !$candidate){self::$limited=true;$db->commit();return null;}
   if(!$legacy){
    // One-minute flood bound, not a day-long shared-IP ban. Denials do not extend the window.
    if(!$candidate && self::count($db,'ip_address',$ip,1,'login_attempt')>=60){self::$limited=true;$db->commit();return null;}
    audit($db,'login_attempt',$email);
   }
   $s=$db->prepare('SELECT * FROM users WHERE email=? FOR UPDATE'); $s->execute([$email]); $user=$s->fetch();
   $recovery=$candidate && $user && (int)$permit['user_id']===(int)$user['id'] && hash_equals($permit['password_stamp'],hash('sha256',$user['password_hash']));
   if($candidate)$_SESSION['reset_login_permit']['attempts']++;
   if($legacy && $blocked && !$recovery){self::$limited=true;$db->commit();return null;}
   if(!$legacy && !$recovery){
    $local=!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE);
    $privileged=$user && in_array($user['role'],['provincial_admin','provincial_treasurer'],true);
    $accountProtection=setting($db,'username_protection')==='1' && (setting($db,'local_only')!=='1'||$local) && (!$privileged||setting($db,'protect_privileged')==='1');
    $accountWindow=max(1,min(15,(int)setting($db,'account_window')));$ipWindow=max(1,min(15,(int)setting($db,'ip_window')));
    $accountMax=max(1,(int)setting($db,'account_max'));$ipMax=max(1,(int)setting($db,'ip_max'));
    if(($accountProtection && self::scopedFailures($db,$email,$accountWindow)>=$accountMax) || (setting($db,'ip_protection')==='1' && self::scopedFailures($db,$email,$ipWindow,$ip)>=$ipMax)){
     self::$limited=true;$db->commit();return null;
    }
   }
   // Valid fixed bcrypt hash equalizes password-check work for unknown users.
   $valid=password_verify($password,$user['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi');
   if (!$user || !$valid || $user['is_suspended']) {
    audit($db,'failed_login',$email);
    $db->prepare('UPDATE security_ips SET clean_sessions=0 WHERE ip_address=?')->execute([$ip]);
    $local=!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE);
    $accountScope=setting($db,'local_only')!=='1' || $local;
    $privileged=$user && in_array($user['role'],['provincial_admin','provincial_treasurer'],true);
    if ($legacy && $user && !$user['is_suspended'] && setting($db,'username_protection')==='1' && $accountScope && (!$privileged || setting($db,'protect_privileged')==='1') && self::count($db,'username_attempted',$email,(int)setting($db,'account_window')) >= (int)setting($db,'account_max')) {
     $db->prepare('UPDATE users SET is_suspended=1 WHERE id=?')->execute([$user['id']]); audit($db,'account_suspended',$email);
     notify($db,setting($db,'notification_email'),'Security: account suspended',$email.' was suspended.');
    }
    // Recognized IPs remain subject to blocking; whitelist is an indicator, not an auth bypass.
    if ($legacy && setting($db,'ip_protection')==='1' && (!$user || self::count($db,'ip_address',$ip,(int)setting($db,'ip_window')) >= (int)setting($db,'ip_max'))) self::block($db,$email);
    $db->commit(); return null;
   }
   $clean=self::count($db,'ip_address',$ip,(int)setting($db,'ip_window'))===0;
   if ($clean && empty($_SESSION['counted_login'])) {
    $db->prepare('UPDATE security_ips SET clean_sessions=clean_sessions+1 WHERE ip_address=?')->execute([$ip]);
    if (!$state['whitelisted'] && (int)$state['clean_sessions']+1>=5) { $db->prepare('UPDATE security_ips SET whitelisted=1 WHERE ip_address=?')->execute([$ip]); audit($db,'whitelisted_ip',$email); }
   }
   if ((int)$state['clean_sessions']===0) notify($db,setting($db,'notification_email'),'Security: unrecognized IP login',$email.' signed in from '.$ip);
   $db->prepare('UPDATE users SET successful_sessions_count=successful_sessions_count+1 WHERE id=?')->execute([$user['id']]);
   if($recovery)audit($db,'password_reset_login',$email,'One-time OTP-verified recovery sign-in; shared IP protections unchanged.');
   audit($db,'success',$email); $db->commit();
   unset($_SESSION['reset_login_permit']);
   session_regenerate_id(true); $_SESSION['user_id']=$user['id']; $_SESSION['password_stamp']=hash('sha256',$user['password_hash']); $_SESSION['counted_login']=true; $_SESSION['last_activity']=time(); $_SESSION['csrf']=bin2hex(random_bytes(32)); return $user;
  } catch(Throwable $e) { if($db->inTransaction()) $db->rollBack(); throw $e; }
 }
 public static function verifyPin(PDO $db,array $user,string $pin): bool {
  if (self::count($db,'username_attempted',$user['email'],15,'pin_failure')>=5) return false;
  if (!preg_match('/^\d{4}$/D',$pin) || !password_verify($pin,$user['transaction_pin_hash'] ?? '')) { audit($db,'pin_failure',$user['email']); return false; }
  return true;
 }
}

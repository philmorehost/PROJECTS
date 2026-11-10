<?php
declare(strict_types=1);
// Hosting-owner recovery only. Never expose this as a web endpoint.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
const ROOT=__DIR__.'/..';require ROOT.'/app/Database.php';require ROOT.'/app/Security.php';require ROOT.'/app/helpers.php';
$options=getopt('', ['email:','unlock-admin','confirm:','use-scoped','ip:']);
$email=PasswordReset::email($options['email']??'');$ip=$options['ip']??'';
if(!filter_var($email,FILTER_VALIDATE_EMAIL)||($ip!==''&&(!is_string($ip)||!filter_var($ip,FILTER_VALIDATE_IP)))){fwrite(STDERR,"Usage: php bin/login-recovery.php --email=admin@example.org [--unlock-admin --confirm=UNLOCK --use-scoped] [--ip=CLIENT_IP]\n");exit(1);}
try{
 $db=Database::get();$s=$db->prepare('SELECT id,email,role,is_suspended FROM users WHERE email=?');$s->execute([$email]);$user=$s->fetch();
 if(!$user)throw new RuntimeException('No account matches that email. Check the installation email.');
 echo 'Account: '.$user['email'].' | role: '.$user['role'].' | suspended: '.((int)$user['is_suspended']?'yes':'no')."\n";
 $s=$db->prepare("SELECT COUNT(*) FROM security_audit_logs WHERE username_attempted=? AND event_type='failed_login' AND created_at>=?");$s->execute([$email,date('Y-m-d H:i:s',time()-900)]);echo 'Failed login audit events for this email in the last 15 minutes: '.$s->fetchColumn()."\n";
 echo 'Login policy: '.(setting($db,'login_lock_mode')==='legacy'?'legacy strict IP bans':'shared-connection safe')."\n";
 $s=$db->prepare('SELECT ip_address,blocked_until FROM security_ips WHERE blocked_until>? ORDER BY blocked_until DESC LIMIT 20');$s->execute([date('Y-m-d H:i:s')]);foreach($s->fetchAll() as $row)echo 'Recorded IP block: '.$row['ip_address'].' until '.$row['blocked_until']." (legacy enforcement only)\n";
 if(!isset($options['unlock-admin'])){echo "Read-only diagnosis. No passwords, locks or accounts changed.\n";exit;}
 if(($options['confirm']??'')!=='UNLOCK')throw new RuntimeException('Explicit --confirm=UNLOCK is required.');
 $db->beginTransaction();$s=$db->prepare('SELECT id,role FROM users WHERE email=? FOR UPDATE');$s->execute([$email]);$fresh=$s->fetch();
 if(!$fresh||$fresh['role']!=='provincial_admin')throw new RuntimeException('This recovery command may unlock only a provincial admin.');
 $db->prepare('UPDATE users SET is_suspended=0 WHERE id=?')->execute([$fresh['id']]);
 if(isset($options['use-scoped']))$db->exec("INSERT INTO security_settings(setting_key,setting_value) VALUES('login_lock_mode','scoped') ON DUPLICATE KEY UPDATE setting_value='scoped'");
 if($ip!==''){
  $db->prepare('UPDATE security_ips SET blocked_until=NULL WHERE ip_address=?')->execute([$ip]);
  $db->prepare("UPDATE firewall_jobs SET status='failed' WHERE ip_address=? AND status='pending'")->execute([$ip]);
 }
 audit($db,'login_recovery',$email,json_encode(['method'=>'hosting CLI','admin'=>$fresh['id'],'scoped'=>isset($options['use-scoped']),'cleared_ip'=>$ip?:null]));
 $db->commit();echo "Admin suspension and account cooldown cleared; password unchanged. Sign in with the current password or request an email OTP reset. Other accounts were not unsuspended.\n";
 if($ip!=='')echo "Selected application IP block cleared and pending firewall requests cancelled. Already-applied server/firewall rules need hosting review.\n";
}catch(Throwable $e){if(isset($db)&&$db->inTransaction())$db->rollBack();fwrite(STDERR,'Recovery failed: '.$e->getMessage()."\n");exit(1);}

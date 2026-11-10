<?php
declare(strict_types=1);
session_start();ob_start();require __DIR__.'/church-portal.php';ob_end_clean();
$db->exec("ALTER TABLE users ADD is_suspended INTEGER DEFAULT 0;ALTER TABLE users ADD successful_sessions_count INTEGER DEFAULT 0;
CREATE TABLE security_ips(ip_address TEXT PRIMARY KEY,blocked_until TEXT,whitelisted INTEGER DEFAULT 0,clean_sessions INTEGER DEFAULT 0);
CREATE TABLE firewall_jobs(id INTEGER PRIMARY KEY,ip_address TEXT,blocked_until TEXT,status TEXT DEFAULT 'pending');
CREATE TABLE password_resets(email TEXT PRIMARY KEY,user_id INTEGER,request_key TEXT,code_hash TEXT,password_stamp TEXT,expires_at TEXT,sent_at TEXT,attempts INTEGER DEFAULT 0);
CREATE TABLE password_reset_limits(bucket_key TEXT PRIMARY KEY,window_started TEXT,hits INTEGER DEFAULT 0);
INSERT INTO security_settings(setting_key,setting_value) VALUES('password_reset_schema','1'),('password_reset_sender','no-reply@example.org'),('ip_protection','1'),('ip_window','15'),('ip_max','10'),('username_protection','1'),('protect_privileged','0'),('account_window','15'),('account_max','5'),('block_days','1');
DELETE FROM security_audit_logs WHERE event_type='failed_login';UPDATE users SET role='provincial_admin' WHERE id=1");
$email='officer@example.org';$password='new-recovered-password-123';$ip=Security::ip();
$db->prepare('UPDATE users SET password_hash=? WHERE email=?')->execute([password_hash('old-admin-password',PASSWORD_DEFAULT),$email]);
for($i=0;$i<10;$i++)check(Security::login($db,$email,'wrong-password')===null,'wrong password denied');
$block=$db->query('SELECT blocked_until FROM security_ips')->fetchColumn();check(strtotime($block)>time(),'failed attempts cause real IP block');
check(Security::login($db,$email,'old-admin-password')===null,'blocked IP rejects even correct old password');
$messages=[];$transport=function($to,$subject,$body)use(&$messages){$messages[]=$body;return true;};
PasswordReset::request($db,$email,$transport);preg_match('/code is: ([0-9]{6})/',$messages[0],$m);$otp=$m[1];
check(PasswordReset::complete($db,$email,$otp,$password,$password),'OTP reset succeeds from blocked IP');$permit=$_SESSION['reset_login_permit'];
check(!isset($_SESSION['user_id']),'OTP reset does not automatically authenticate');
// Reproduce the reset-view session rotation while retaining only the validated recovery grant.
$_SESSION=[];session_regenerate_id(true);$_SESSION['reset_login_permit']=$permit;
check(Security::login($db,'unknown@example.org',$password)===null,'permit cannot recover another email');
$_SESSION['reset_login_permit']['expires']=time()-1;check(Security::login($db,$email,$password)===null,'expired permit denied');$_SESSION['reset_login_permit']=$permit;
$db->prepare('INSERT INTO security_ips(ip_address,blocked_until) VALUES(?,?)')->execute(['192.0.2.99',$block]);$_SERVER['REMOTE_ADDR']='192.0.2.99';check(Security::login($db,$email,$password)===null,'permit cannot move to another blocked IP');$_SERVER['REMOTE_ADDR']=$ip;
$_SESSION['reset_login_permit']['password_stamp']=str_repeat('a',64);check(Security::login($db,$email,$password)===null,'password stamp binding enforced');$_SESSION['reset_login_permit']=$permit;
$db->exec('UPDATE users SET is_suspended=1 WHERE id=1');check(Security::login($db,$email,$password)===null,'recovery does not override suspension');$db->exec('UPDATE users SET is_suspended=0 WHERE id=1');
check(Security::login($db,$email,'old-admin-password')===null,'recovery still requires new password');
$beforeLoginBlock=$db->query('SELECT blocked_until FROM security_ips WHERE ip_address=\''.$ip.'\'')->fetchColumn();
check(Security::login($db,$email,$password)!==null,'new password sign-in succeeds after reset despite IP block');
check((int)$_SESSION['user_id']===1&&Security::sessionPasswordMatches($db->query('SELECT * FROM users WHERE id=1')->fetch(),$_SESSION),'authenticated session survives password-stamp check');check(!isset($_SESSION['reset_login_permit']),'permit consumed at login');
check($db->query('SELECT blocked_until FROM security_ips WHERE ip_address=\''.$ip.'\'')->fetchColumn()===$beforeLoginBlock,'shared IP block not removed');
check((int)$db->query("SELECT COUNT(*) FROM security_audit_logs WHERE event_type='password_reset_login'")->fetchColumn()===1,'recovery sign-in audited');
$_SESSION=[];check(Security::login($db,$email,$password)===null,'other browser session retains IP restriction');check(!PasswordReset::complete($db,$email,$otp,$password,$password),'used OTP cannot mint another permit');
// A permit may only be tried five times, even if the account remains privileged.
$_SESSION['reset_login_permit']=$permit;for($i=0;$i<5;$i++)check(Security::login($db,$email,'wrong-password')===null,'limited recovery attempt');check(Security::login($db,$email,$password)===null,'recovery attempt cap enforced');
// Without a block, ordinary password sign-in remains available and needs no permit.
$_SESSION=[];$db->exec('UPDATE security_ips SET blocked_until=NULL');check(Security::login($db,$email,$password)!==null,'ordinary unblocked login unaffected');
echo "PASS: $checks combined portal/reset-to-login assertions (SQLite compatibility; real session rotation, mocked mail)\n";

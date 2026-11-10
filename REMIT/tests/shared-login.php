<?php
declare(strict_types=1);
ob_start();require __DIR__.'/password-reset-login.php';ob_end_clean();
$db->exec("UPDATE security_settings SET setting_value='scoped' WHERE setting_key='login_lock_mode';DELETE FROM security_audit_logs WHERE event_type IN ('failed_login','login_attempt');UPDATE security_ips SET blocked_until='2099-01-01 00:00:00'");$_SESSION=[];
$otherPassword='second-account-password-123';$hash=password_hash($otherPassword,PASSWORD_DEFAULT);
$db->prepare("INSERT INTO users(id,full_name,email,password_hash,role,hierarchy_id,is_suspended) VALUES(200,'SECOND REP','second-login@example.org',?,'parish_officer',2,0),(201,'SUSPENDED REP','suspended-login@example.org',?,'parish_officer',2,1)")->execute([$hash,$hash]);
$jobs=(int)$db->query('SELECT COUNT(*) FROM firewall_jobs')->fetchColumn();
check(Security::login($db,'typo@example.org','bad-password')===null,'unknown email rejected');check((int)$db->query('SELECT COUNT(*) FROM firewall_jobs')->fetchColumn()===$jobs,'one typo never queues blanket network ban');
check(Security::login($db,'second-login@example.org',$otherPassword)!==null,'rep with correct password survives historical shared-IP block');$_SESSION=[];
for($i=0;$i<10;$i++)check(Security::login($db,$email,'wrong-password')===null,'bad admin password rejected');check(Security::login($db,$email,$password)===null,'account/connection cooldown enforces real threshold');check(str_contains(Security::loginMessage(),'temporarily limited'),'cooldown has actionable generic message');
check(Security::login($db,'second-login@example.org',$otherPassword)!==null,'admin mistakes do not lock another rep on same IP');$_SESSION=[];
check(Security::login($db,'suspended-login@example.org',$otherPassword)===null,'manual suspension still enforced');
$db->exec("UPDATE security_audit_logs SET created_at='2000-01-01 00:00:00' WHERE event_type='failed_login'");check(Security::login($db,$email,$password)!==null,'cooldown expires without reset or manual IP clearing');$_SESSION=[];
// A representative's repeated failures are temporary, not a permanent account suspension.
for($i=0;$i<5;$i++)check(Security::login($db,'second-login@example.org','wrong-password')===null,'rep failures counted');check((int)$db->query('SELECT is_suspended FROM users WHERE id=200')->fetchColumn()===0,'failed passwords do not permanently suspend account');
$db->exec("UPDATE security_audit_logs SET created_at='2000-01-01 00:00:00' WHERE event_type='failed_login'");check(Security::login($db,'second-login@example.org',$otherPassword)!==null,'rep can sign in once cooldown expires');$_SESSION=[];
check((int)$db->query('SELECT COUNT(*) FROM firewall_jobs')->fetchColumn()===$jobs,'safe mode creates no network-wide block jobs');
// Real account recovery resets only that email's failure boundary without deleting audit history.
for($i=0;$i<5;$i++)Security::login($db,'second-login@example.org','wrong-password');audit($db,'login_recovery','second-login@example.org','Test operator recovery boundary.');check(Security::login($db,'second-login@example.org',$otherPassword)!==null,'recovery marker clears counters without deleting failures');$_SESSION=[];
$db->exec("DELETE FROM security_audit_logs WHERE event_type='login_attempt'");for($i=0;$i<60;$i++)audit($db,'login_attempt','flood@example.org');check(Security::login($db,$email,$password)===null,'one-minute flood cap still enforced');$before=(int)$db->query("SELECT COUNT(*) FROM security_audit_logs WHERE event_type='login_attempt'")->fetchColumn();Security::login($db,$email,$password);check((int)$db->query("SELECT COUNT(*) FROM security_audit_logs WHERE event_type='login_attempt'")->fetchColumn()===$before,'throttled requests do not extend flood window');$db->exec("UPDATE security_audit_logs SET created_at='2000-01-01 00:00:00' WHERE event_type='login_attempt'");check(Security::login($db,$email,$password)!==null,'flood cap expires without day-long lockout');
echo "PASS: $checks combined portal/recovery/shared-login assertions (SQLite harness, real password/session checks)\n";

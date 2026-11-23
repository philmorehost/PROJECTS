<?php
if(getenv('BC_VENDOR_REG_CI')!=='1')throw new RuntimeException('Disposable CI only');
$edition=getenv('BC_TEST_EDITION')?:'SAAS';if(!in_array($edition,['SAAS','NON-SAAS'],true))throw new RuntimeException('edition');
$root=__DIR__.'/../DGV7.0-'.$edition.'/func/';
// Factor, demo, mail and security-DDL doubles only. Challenges, policy, locks, audit and rate storage are real SQL.
function bc_stepup_spadmin_verify($actor,$input,$context){return $GLOBALS['factor_error']??'';}
function bc_demo_edits_allowed($db){return true;}
function bc_security_ensure_schema(){}
function bc_ensure_security_tables(){}
function bc_validate_csrf(){if(!is_string($_POST['_csrf_token']??null)||!hash_equals($_SESSION['_csrf_token']??'',$_POST['_csrf_token']))throw new RuntimeException('csrf');}
require $root.'bc-stepup.php';require $root.'bc-paid-security.php';require $root.'bc-vendor-registration.php';require $root.'bc-vendor-registration-commerce.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=$connection_server=new mysqli('127.0.0.1','root','disposable-ci-only','',3306);
$_SERVER['REQUEST_METHOD']='POST';$_SERVER['REMOTE_ADDR']='192.0.2.7';$_SESSION=['spadmin_session'=>'owner@example.test','_csrf_token'=>'token'];$_POST=['_csrf_token'=>'token'];
if(($argv[1]??'')==='race'){
 $name=$argv[2]??'';if(!preg_match('/\Abc_vreg_ci_[a-f0-9]{12}\z/D',$name))throw new RuntimeException('db');$db->select_db($name);
 $_SESSION['vreg_challenge']=$argv[3];$_SESSION['vreg_binding']=$argv[4];echo bc_vreg_consume('ada@example.com',$argv[5],bc_vreg_policy())?'consumed':'refused';exit;
}
$name='bc_vreg_ci_'.bin2hex(random_bytes(6));$db->query("CREATE DATABASE `$name`");$db->select_db($name);
$n=0;function vm_check($ok,$msg){$GLOBALS['n']++;if(!$ok)throw new RuntimeException($msg);}
function vm_policy($key,$value){bc_q('DELETE FROM sas_super_admin_options WHERE option_name=?',[$key]);bc_q('INSERT INTO sas_super_admin_options(option_name,option_value) VALUES(?,?)',[$key,$value]);}
$deliver=function($email,$code){$GLOBALS['sent_code']=$code;return true;};
try{
 $db->query('CREATE TABLE sas_super_admin_options(option_name VARCHAR(191),option_value TEXT) ENGINE=InnoDB');
 $db->query('CREATE TABLE sas_super_admin_kyc_verifications(verification_name VARCHAR(100),status INT) ENGINE=InnoDB');
 $db->query('CREATE TABLE sas_super_admin(id INT PRIMARY KEY,email VARCHAR(191),status INT,is_blocked INT,force_password_change INT,totp_secret VARCHAR(191),two_factor_type VARCHAR(20)) ENGINE=InnoDB');
 $db->query("INSERT INTO sas_super_admin VALUES(1,'owner@example.test',1,0,0,'FIXTURE','totp')");
 $db->query('CREATE TABLE sas_rate_limits(action VARCHAR(100),rate_key VARCHAR(150),attempted_at DATETIME,KEY lookup_idx(action,rate_key,attempted_at)) ENGINE=InnoDB');
 $db->query('CREATE TABLE sas_security_audit_log(vendor_id INT,actor_type VARCHAR(20),actor_ref VARCHAR(190),action VARCHAR(80),subject VARCHAR(190),details VARCHAR(500),ip VARCHAR(64)) ENGINE=InnoDB');
 $source=file_get_contents($root.'bc-tables.php');if(!preg_match('/CREATE TABLE IF NOT EXISTS sas_vendor_signup_challenges \(.*?\) ENGINE=InnoDB/s',$source,$ddl))throw new RuntimeException('challenge DDL missing');$db->query($ddl[0]);

 // Actual pending DDL followed by its production additive migration declarations (fresh install).
 if(!preg_match('/CREATE TABLE IF NOT EXISTS sas_pending_vendors \(.*?\) ENGINE=InnoDB/s',$source,$pending_ddl))throw new RuntimeException('pending DDL missing');$db->query($pending_ddl[0]);
 preg_match_all('/ALTER TABLE sas_pending_vendors ADD COLUMN ([a-z_]+) ([^"\n]+)/',$source,$pending_alters,PREG_SET_ORDER);
 foreach($pending_alters as $alter){$column=$alter[1];if(!$db->query("SHOW COLUMNS FROM sas_pending_vendors LIKE '$column'")->num_rows)$db->query('ALTER TABLE sas_pending_vendors ADD COLUMN '.$column.' '.$alter[2]);}
 foreach(['email_verified_at','business_name','business_registration_no','compliance_review'] as $column)vm_check($db->query("SHOW COLUMNS FROM sas_pending_vendors LIKE '$column'")->num_rows===1,'fresh schema '.$column);
 vm_check(bc_vreg_policy()['enabled']===true,'compatibility default enabled with mandatory mailbox proof');
 $p=bc_vreg_policy();bc_vreg_send('ada@example.com',$p,$deliver);$code=$sent_code;$token=$_SESSION['vreg_challenge'];
 $stored=bc_q_row('SELECT * FROM sas_vendor_signup_challenges WHERE token=?',[$token]);vm_check($stored['code_hash']!==$code&&password_verify($code,$stored['code_hash']),'code hashed at rest');
 $_SESSION['vreg_binding']='wrong-browser';vm_check(!bc_vreg_consume('ada@example.com',$code,$p),'browser binding');unset($_SESSION['vreg_binding']);$_SESSION['vreg_binding']='fixture-browser';
 $db->query('DELETE FROM sas_rate_limits');bc_vreg_send('ada@example.com',$p,$deliver);$code=$sent_code;
 vm_check(!bc_vreg_consume('other@example.com',$code,$p),'cannot swap address');
 for($i=0;$i<5;$i++)vm_check(!bc_vreg_consume('ada@example.com','000000',$p),'wrong-code attempt '.$i);
 vm_check(!bc_vreg_consume('ada@example.com',$code,$p),'five attempts lock even correct code');
 $db->query('DELETE FROM sas_rate_limits');bc_vreg_send('ada@example.com',$p,$deliver);$code=$sent_code;
 bc_q('UPDATE sas_vendor_signup_challenges SET expires_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE token=?',[$_SESSION['vreg_challenge']]);vm_check(!bc_vreg_consume('ada@example.com',$code,$p),'expired code');
 $db->query('DELETE FROM sas_rate_limits');bc_vreg_send('ada@example.com',$p,$deliver);$old=$sent_code;$oldtoken=$_SESSION['vreg_challenge'];bc_vreg_send('ada@example.com',$p,$deliver);
 vm_check(bc_q_row('SELECT * FROM sas_vendor_signup_challenges WHERE token=?',[$oldtoken])===null,'resend invalidates prior challenge');
 vm_check(bc_vreg_consume('ada@example.com',$sent_code,$p),'valid code consumed');vm_check(!bc_vreg_consume('ada@example.com',$sent_code,$p),'no replay');
 $db->query('DELETE FROM sas_rate_limits');bc_vreg_send('ada@example.com',$p,$deliver);$before=$_SESSION['vreg_challenge'];$beforecode=$sent_code;
 $input=['vendor_registration_enabled'=>'0','vendor_require_kyb'=>'1','vendor_registration_blocked_domains'=>'blocked.example'];
 $factor_error='bad factor';vm_check(bc_vreg_settings_save(['id'=>1],$input)==='bad factor'&&bc_vreg_policy()['enabled'],'factor refusal unchanged');$factor_error='';
 vm_check(bc_vreg_settings_save(['id'=>1],$input)===''&&!bc_vreg_policy()['enabled']&&bc_vreg_policy()['kyb'],'real policy saved');
 vm_check(!bc_vreg_consume('ada@example.com',$beforecode,bc_vreg_policy()),'OFF refuses code completion');
 try{bc_vreg_send('ada@example.com',bc_vreg_policy(),$deliver);vm_check(false,'disabled send');}catch(RuntimeException $e){vm_check(strpos($e->getMessage(),'closed')!==false,'OFF blocks mail');}
 $input['vendor_registration_enabled']='1';vm_check(bc_vreg_settings_save(['id'=>1],$input)==='','re-enable');$_SESSION['vreg_challenge']=$before;
 vm_check(!bc_vreg_consume('ada@example.com',$beforecode,bc_vreg_policy()),'old codes stay revoked after re-enable epoch');
 vm_check((int)$db->query("SELECT COUNT(*) n FROM sas_security_audit_log WHERE action='vendor_registration_policy'")->fetch_assoc()['n']===2,'policy changes audited');
 $db->query("INSERT INTO sas_super_admin_kyc_verifications VALUES('govt_id',1)");vm_policy('force_kyc','1');vm_check(bc_vreg_policy()['kyc']&&bc_vreg_policy()['checks']===['govt_id'],'fresh existing platform KYC policy');
 $db->query("INSERT INTO sas_super_admin_options VALUES('force_kyc','0')");try{bc_vreg_policy();vm_check(false,'duplicate policy');}catch(RuntimeException $e){vm_check(strpos($e->getMessage(),'Duplicate')!==false,'duplicate policy fails closed');}vm_policy('force_kyc','1');
 $db->query('DELETE FROM sas_rate_limits');bc_vreg_send('ada@example.com',bc_vreg_policy(),$deliver);$token=$_SESSION['vreg_challenge'];$code=$sent_code;
 $children=[];foreach([1,2] as $i){$pipes=[];$proc=proc_open([PHP_BINARY,__FILE__,'race',$name,$token,$_SESSION['vreg_binding'],$code],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$children[]=[$proc,$pipes];}
 $results=[];foreach($children as [$proc,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);vm_check(proc_close($proc)===0,'race child '.$err);$results[]=$out;}
 sort($results);vm_check($results===['consumed','refused'],'two independent connections consume exactly once');
 $db->query('DELETE FROM sas_rate_limits');try{bc_vreg_send('failed@example.com',bc_vreg_policy(),fn($email,$code)=>false);vm_check(false,'delivery failure');}catch(RuntimeException $e){vm_check((int)$db->query("SELECT COUNT(*) n FROM sas_vendor_signup_challenges WHERE email='failed@example.com'")->fetch_assoc()['n']===0,'failed mail leaves no usable challenge');}
 for($i=0;$i<3;$i++)bc_vreg_send('limited@example.com',bc_vreg_policy(),$deliver);
 try{bc_vreg_send('limited@example.com',bc_vreg_policy(),$deliver);vm_check(false,'mail flood');}catch(RuntimeException $e){vm_check(strpos($e->getMessage(),'Too many')!==false,'persistent email flood limit');}

 $db->query('DELETE FROM sas_rate_limits');
 $db->query('UPDATE sas_super_admin SET is_blocked=1 WHERE id=1');vm_check(bc_vreg_settings_save(['id'=>1],$input)!=='','fresh blocked platform owner refused');$db->query('UPDATE sas_super_admin SET is_blocked=0 WHERE id=1');
 $_POST['_csrf_token']='wrong';try{bc_vreg_settings_save(['id'=>1],$input);vm_check(false,'csrf');}catch(RuntimeException $e){vm_check($e->getMessage()==='csrf','settings csrf');}$_POST['_csrf_token']='token';
 $prior=bc_vreg_policy();$db->query("CREATE TRIGGER policy_write_failure BEFORE INSERT ON sas_super_admin_options FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected policy write failure'");
 $input['vendor_registration_enabled']='0';vm_check(bc_vreg_settings_save(['id'=>1],$input)!==''&&bc_vreg_policy()===$prior,'failed settings write rolls back policy and epoch');$db->query('DROP TRIGGER policy_write_failure');

 // Integer invoice, durable daily limits, OTP-first application and payment-evidence integration.
 foreach(['sas_vendor_signup_daily','sas_vendor_signup_receipts'] as $table){if(!preg_match('/CREATE TABLE IF NOT EXISTS '.$table.'\(.*?\) ENGINE=InnoDB/s',$source,$definition))throw new RuntimeException('commerce DDL');$db->query($definition[0]);}
 $db->query('CREATE TABLE sas_billing_packages(id INT PRIMARY KEY,price DECIMAL(12,2),duration_days INT) ENGINE=InnoDB');$db->query('INSERT INTO sas_billing_packages VALUES(1,5000,30)');
 $db->query('CREATE TABLE sas_billing_addons(id INT PRIMARY KEY,price DECIMAL(12,2)) ENGINE=InnoDB');$db->query('INSERT INTO sas_billing_addons VALUES(2,250.50)');
 vm_policy('apk_development_price','1000.00');
 $quote=bc_vreg_quote(['billing_package_id'=>'1','order_apk'=>'1','addons'=>['2'],'total_amount'=>'-100'],'0.00');vm_check($quote['total']===625050,'SQL prices ignore negative browser total and include options');
 $snapshot=json_encode($quote,JSON_THROW_ON_ERROR);$escaped=mysqli_real_escape_string($db,$snapshot);
 $application_sql="INSERT INTO sas_pending_vendors(website_url,email,password,firstname,lastname,phone_number,home_address,billing_package_id,payment_method,email_verified_at,price_snapshot,total_amount,domain_registration_fee,selected_addons,order_apk) VALUES('fixture-owned.com','application@example.com','fixture-hash','Ada','Okafor','+2348031234567','12 Example Street',1,'paystack',NOW(),'$escaped','6250.50',0,'2',1)";
 unset($_SESSION['vreg_verified_token']);vm_check(bc_vreg_insert_application($application_sql,'application@example.com','+2348031234567','device-A',bc_vreg_policy())===false,'cannot insert before OTP verification');
 $db->query('DELETE FROM sas_rate_limits');bc_vreg_send('application@example.com',bc_vreg_policy(),$deliver);vm_check(bc_vreg_consume('application@example.com',$sent_code,bc_vreg_policy()),'mailbox verified before application');
 $application_id=bc_vreg_insert_application($application_sql,'application@example.com','+2348031234567','device-A',bc_vreg_policy());vm_check(is_int($application_id)&&$application_id>0,'application and quotas committed together');vm_check(bc_vreg_verified_mailbox(bc_vreg_policy())===null,'application consumes verified proof');
 foreach([['application@example.com','+2348039999998','device-B'],['other@example.com','+2348031234567','device-B'],['other@example.com','+2348039999998','device-A']] as [$email,$phone,$device]){$db->begin_transaction();try{bc_vreg_daily_claim($email,$phone,$device);vm_check(false,'daily duplicate');}catch(RuntimeException $e){$db->rollback();vm_check(true,'daily email/phone/browser rule independent of IP');}}
 $reference='vendor_reg_fixture_'.bin2hex(random_bytes(16));bc_q('UPDATE sas_pending_vendors SET paystack_reference=? WHERE id=?',[$reference,$application_id]);
 $payment=['status'=>'success','reference'=>$reference,'amount'=>625050,'currency'=>'NGN','customer'=>['email'=>'application@example.com'],'metadata'=>['type'=>'vendor_subscription','pending_vendor_id'=>$application_id]];
 foreach(['amount'=>1,'currency'=>'USD','reference'=>'other-reference'] as $field=>$bad){$attempt=$payment;$attempt[$field]=$bad;vm_check(!bc_vreg_online_receipt($attempt,$reference),'reject mismatched verified payment '.$field);}
 $attempt=$payment;$attempt['customer']['email']='someone@example.com';vm_check(!bc_vreg_online_receipt($attempt,$reference),'wrong payer email');$attempt=$payment;$attempt['metadata']['pending_vendor_id']=999;vm_check(!bc_vreg_online_receipt($attempt,$reference),'metadata cannot redirect receipt');
 vm_check(bc_vreg_online_receipt($payment,$reference),'exact verified receipt accepted');vm_check(bc_vreg_online_receipt($payment,$reference),'verified replay is idempotent');
 $paid=bc_q_row('SELECT * FROM sas_pending_vendors WHERE id=?',[$application_id]);vm_check(bc_vreg_receipt_matches($paid),'persisted matching payment evidence');
 bc_q("UPDATE sas_pending_vendors SET payment_method='bank_deposit',payment_status='pending',payment_evidence=NULL WHERE id=?",[$application_id]);$manual=['deposit_amount'=>'6250.50','deposit_reference'=>'bank-statement-fixture','deposit_confirmed'=>'1'];
 try{bc_vreg_manual_receipt($application_id,['id'=>1],$manual);vm_check(false,'bare manual action');}catch(RuntimeException $e){vm_check(true,'bare manual action refused');}
 $GLOBALS['bc_vreg_verified_payment_actor']=['actor'=>1,'pending'=>$application_id];try{bc_vreg_manual_receipt($application_id,['id'=>1],$manual);vm_check(false,'online-only manual attempt');}catch(RuntimeException $e){vm_check(true,'online-only policy refuses manual deposits');}
 vm_policy('vendor_registration_payment_mode','both');$db->query('DELETE FROM sas_vendor_signup_receipts');$GLOBALS['bc_vreg_verified_payment_actor']=['actor'=>1,'pending'=>$application_id];bc_vreg_manual_receipt($application_id,['id'=>1],$manual);$paid=bc_q_row('SELECT * FROM sas_pending_vendors WHERE id=?',[$application_id]);vm_check(bc_vreg_receipt_matches($paid),'manual exact amount and statement reference audited and bound');
 // Exercise the exact production activation INSERT (not external hosting/payment side effects).
 $approval=file_get_contents(__DIR__.'/../DGV7.0-SAAS/bc-spadmin/VendorRegistrations.php');
 if(!preg_match('/\$insert_sql = "(INSERT INTO sas_vendors.*?)";/s',$approval,$insert))throw new RuntimeException('activation SQL missing');
 preg_match('/INSERT INTO sas_vendors \(([^)]*)\)/',$insert[1],$columns);$defs=[];foreach(explode(',',$columns[1]) as $column){$column=trim($column);$defs[]="`$column` ".($column==='onboarding_compliance_review'?'TEXT':'VARCHAR(255)');}
 $db->query('CREATE TABLE sas_vendors(id INT AUTO_INCREMENT PRIMARY KEY,'.implode(',',$defs).') ENGINE=InnoDB');
 $review=json_encode(['review'=>['kyc'=>'fixture-kyc-case','kyb'=>'fixture-kyb-case','reviewer'=>1]]);
 $params=[$review,'2','ada@example.com',str_repeat('a',64),'fixture-hash','Ada','Okafor','08031234567','example.com','Example address','1000','50000','10','example.com','0','0','0','0',''];
 vm_check(substr_count($insert[1],'?')===count($params)&&bc_q($insert[1],$params)===true,'actual activation INSERT binds all proof and identity fields');
 $created=bc_q_row('SELECT * FROM sas_vendors LIMIT 1');vm_check($created['onboarding_compliance_review']===$review&&$created['kyc_status']==='2'&&!empty($created['email_verified_at']),'activation retains proof and completed KYC state');
 echo "$n $edition real MySQL vendor signup checks passed; no real mail/provider calls\n";
}finally{$db->query("DROP DATABASE `$name`");$db->close();}

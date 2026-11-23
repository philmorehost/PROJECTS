<?php
if(getenv('BC_ADMIN_WALLET_CI')!=='1')throw new RuntimeException('Disposable CI only.');
set_exception_handler(function($e){echo '::error::'.str_replace(["\n","\r"],' ',$e->getMessage()).' at line '.$e->getLine()."\n";exit(1);});
$edition=getenv('BC_TEST_EDITION')?:'SAAS';if(!in_array($edition,['SAAS','NON-SAAS'],true))throw new RuntimeException('edition');
require __DIR__.'/../DGV7.0-'.$edition.'/func/bc-admin-wallet-adjustment.php';
require __DIR__.'/../DGV7.0-'.$edition.'/func/bc-stepup.php';
require __DIR__.'/../DGV7.0-'.$edition.'/func/bc-action-policy-settings.php';
function verifyUserPIN($pin,$actor){return password_verify($pin,$actor['security_pin']);}
function bc_validate_csrf(){if(($_POST['_csrf_token']??'')!=='fixture-csrf')throw new RuntimeException('CSRF refused');}
function bc_verify_password($plain,$hash){return password_verify($plain,$hash);}
function bc_sec_audit(){return true;} // No schema or external audit transports in the fixture.
function bc_verify_otp($purpose,$identity,$vid,$code){return $purpose==='stepup_wallet_probe' && $identity==='vendor@example.test' && $vid===7 && $code==='654321';}
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
if(($argv[1]??'')==='policy'){
 $name=$argv[2]??'';if(!preg_match('/\Abc_admin_wallet_ci_[a-f0-9]{12}\z/D',$name))throw new RuntimeException('db scope');
 $connection_server=new mysqli('127.0.0.1','root','disposable-ci-only',$name,3306);
 $actor=$connection_server->query('SELECT * FROM sas_vendors WHERE id=7')->fetch_assoc();
 $_POST=['save-action-channels'=>'1','_csrf_token'=>'fixture-csrf','current_password'=>($argv[3]??'')==='good'?'fixture-password':'incorrect','pin'=>'9362','otp_code'=>bc_totp_code('JBSWY3DPEHPK3PXP',time()),'ack_pin_only'=>'1'];
 bc_action_policy_handle(false,$actor);exit(3);
}
$db=new mysqli('127.0.0.1','root','disposable-ci-only','',3306);$connection_server=$db;$name='bc_admin_wallet_ci_'.bin2hex(random_bytes(6));$db->query("CREATE DATABASE `$name`");$db->select_db($name);
$n=0;function wallet_check($v,$m){global $n;if(!$v)throw new RuntimeException($m);$n++;}
function wallet_bal($db,$t,$where){return (float)$db->query("SELECT balance FROM $t WHERE $where")->fetch_assoc()['balance'];}
try{
 $db->query('CREATE TABLE sas_vendors (id INT PRIMARY KEY,email VARCHAR(225),balance DECIMAL(30,8),status INT,is_blocked INT) ENGINE=InnoDB');
 $db->query("INSERT INTO sas_vendors VALUES (7,'vendor@example.test',1000,1,0),(8,'other@example.test',1000,1,0)");
 $db->query('CREATE TABLE sas_users (id INT PRIMARY KEY,vendor_id INT,username VARCHAR(225),email VARCHAR(225),balance DECIMAL(30,8),status INT,is_blocked INT) ENGINE=InnoDB');
 $db->query("INSERT INTO sas_users VALUES (11,7,'customer','user@example.test',1000,1,0),(12,8,'customer','other-user@example.test',2000,1,0)");
 $cols='vendor_id INT,product_unique_id VARCHAR(225),type_alternative VARCHAR(225),reference VARCHAR(225),amount DECIMAL(30,8),discounted_amount DECIMAL(30,8),balance_before DECIMAL(30,8),balance_after DECIMAL(30,8),description TEXT,api_website VARCHAR(225),status INT';
 $db->query("CREATE TABLE sas_vendor_transactions ($cols) ENGINE=InnoDB");$db->query("CREATE TABLE sas_transactions ($cols,username VARCHAR(225),api_reference VARCHAR(225),mode VARCHAR(225)) ENGINE=InnoDB");
 $source=file_get_contents(__DIR__.'/../DGV7.0-'.$edition.'/func/bc-tables.php');preg_match('/CREATE TABLE IF NOT EXISTS sas_wallet_mutation_events \(.*?\) ENGINE=InnoDB/s',$source,$ddl);$db->query($ddl[0]);
 $db->query('CREATE TABLE sas_super_admin_options (option_name VARCHAR(255) PRIMARY KEY,option_value TEXT) ENGINE=InnoDB');
 $db->query('CREATE TABLE sas_settings (vendor_id INT,setting_name VARCHAR(225),setting_value VARCHAR(225),UNIQUE KEY(vendor_id,setting_name)) ENGINE=InnoDB');
 $request=bc_admin_wallet_request('spadmin',['id'=>1],['target'=>'vendor','identifier'=>'vendor@example.test','type'=>'credit','amount'=>'100']);
 wallet_check(bc_admin_wallet_apply($db,$request,'vendor-credit')['ok'] && wallet_bal($db,'sas_vendors','id=7')===1100.0,'vendor EMAIL resolves to numeric wallet id');
 wallet_check(bc_admin_wallet_apply($db,$request,'vendor-credit')['ok'] && wallet_bal($db,'sas_vendors','id=7')===1100.0,'same credit replay never duplicates');
 $changed=$request;$changed['recipient_id']=8;wallet_check(!bc_admin_wallet_apply($db,$changed,'changed-identity')['ok'],'recipient identity change cannot reuse an authorized request');
 $request['type']='debit';wallet_check(bc_admin_wallet_apply($db,$request,'vendor-debit')['ok'] && wallet_bal($db,'sas_vendors','id=7')===1000.0,'vendor debit');
 $request['type']='refund';$request['original']='vendor-debit';wallet_check(bc_admin_wallet_apply($db,$request,'vendor-refund')['ok'] && wallet_bal($db,'sas_vendors','id=7')===1100.0,'vendor refund matched debit');
 wallet_check(bc_admin_wallet_apply($db,$request,'vendor-refund-other-form')['ok'] && wallet_bal($db,'sas_vendors','id=7')===1100.0,'same debit cannot be refunded twice');
 $request['amount']='99';wallet_check(!bc_admin_wallet_apply($db,$request,'vendor-bad-refund')['ok'] && wallet_bal($db,'sas_vendors','id=7')===1100.0,'wrong refund amount refused');
 $GLOBALS['vendor_id']=8; // Simulate a stale platform-host resolver: helper must ignore it.
 $request=bc_admin_wallet_request('spadmin',['id'=>1],['target'=>'user','vid'=>'7','identifier'=>'customer','type'=>'credit','amount'=>'50']);
 wallet_check(bc_admin_wallet_apply($db,$request,'user-credit')['ok'] && wallet_bal($db,'sas_users','id=11')===1050.0 && wallet_bal($db,'sas_users','id=12')===2000.0,'explicit tenant beats stale global/host resolver');
 $request=bc_admin_wallet_request('admin',['id'=>7],['target'=>'vendor','vid'=>'8','user'=>'customer','type'=>'debit','amount'=>'50']);
 wallet_check($request['target']==='user' && $request['vendor_id']===7 && bc_admin_wallet_apply($db,$request,'user-debit')['ok'],'vendor admin cannot choose another tenant or vendor target');
 $request['type']='refund';$request['original']='user-debit';wallet_check(bc_admin_wallet_apply($db,$request,'user-refund')['ok'] && wallet_bal($db,'sas_users','id=11')===1050.0,'tenant user refund');
 $request['type']='debit';$request['amount']='100000';wallet_check(!bc_admin_wallet_apply($db,$request,'no-overdraft')['ok'] && wallet_bal($db,'sas_users','id=11')===1050.0,'insufficient debit leaves balance unchanged');
 $db->query('UPDATE sas_vendors SET is_blocked=1 WHERE id=7');$request['type']='credit';$request['amount']='1';wallet_check(!bc_admin_wallet_apply($db,$request,'blocked')['ok'],'suspected vendor block preserved');$db->query('UPDATE sas_vendors SET is_blocked=0 WHERE id=7');
 $invalid=false;try{bc_admin_wallet_request('admin',['id'=>7],['user'=>'customer','type'=>'credit','amount'=>'-100']);}catch(Throwable $e){$invalid=true;}wallet_check($invalid,'negative amount cannot be stripped into credit');
 $invalid=false;try{bc_admin_wallet_request('admin',['id'=>7],['user'=>'customer','type'=>'refund','amount'=>'10']);}catch(Throwable $e){$invalid=true;}wallet_check($invalid,'refund requires source reference');
 wallet_check(bc_action_channels('sas_vendors',['id'=>7])===['google'=>true,'email'=>true],'legacy secure defaults');
 $db->query("INSERT INTO sas_super_admin_options VALUES ('action_security_channels','{\"google\":false,\"email\":true}')");
 wallet_check(bc_action_channels('sas_vendors',['id'=>7])===['google'=>false,'email'=>true],'platform default inherited');
 $db->query("INSERT INTO sas_settings VALUES (7,'action_security_channels','{\"google\":true,\"email\":false}')");
 wallet_check(bc_action_channels('sas_vendors',['id'=>7])===['google'=>true,'email'=>false] && bc_action_channels('sas_vendors',['id'=>8])===['google'=>false,'email'=>true],'tenant policy independent');
 wallet_check(bc_action_channels('sas_super_admin',['id'=>1])===['google'=>false,'email'=>true],'platform admin own channel scope');
 $db->query('ALTER TABLE sas_vendors ADD security_pin VARCHAR(255),ADD two_factor_type VARCHAR(20),ADD totp_secret VARCHAR(255),ADD totp_last_step BIGINT DEFAULT 0');
 $pin=$db->real_escape_string(password_hash('9362',PASSWORD_DEFAULT));
 $db->query("UPDATE sas_vendors SET security_pin='$pin',two_factor_type='totp',totp_secret='JBSWY3DPEHPK3PXP' WHERE id=7");
 $actor=$db->query('SELECT * FROM sas_vendors WHERE id=7')->fetch_assoc();
 $code=bc_totp_code('JBSWY3DPEHPK3PXP',time());
 wallet_check(bc_stepup_admin_verify($actor,['pin'=>'9362','totp_code'=>$code,'factor_method'=>'google'],'wallet_probe')==='','Google code verifies and is claimed');
 wallet_check(bc_stepup_admin_verify($actor,['pin'=>'9362','totp_code'=>$code,'factor_method'=>'google'],'wallet_probe')!=='','Google replay rejected');
 $db->query("UPDATE sas_settings SET setting_value='{\"google\":true,\"email\":true}' WHERE vendor_id=7 AND setting_name='action_security_channels'");
 wallet_check(bc_stepup_admin_verify($actor,['pin'=>'9362','otp_code'=>'654321','factor_method'=>'email'],'wallet_probe')==='','explicit email works even when Google enrolled');
 $db->query("UPDATE sas_settings SET setting_value='{\"google\":false,\"email\":false}' WHERE vendor_id=7 AND setting_name='action_security_channels'");
 wallet_check(bc_stepup_admin_verify($actor,['pin'=>'9362'],'wallet_probe')==='','explicit PIN-only policy works');
 wallet_check(bc_stepup_admin_verify($actor,['pin'=>'bad'],'wallet_probe')!=='','PIN-only never bypasses PIN');
 wallet_check(bc_stepup_admin_verify($actor,['pin'=>'9362'],'action_channel_policy')!=='','policy change still requires existing factor in PIN-only mode');
 $db->query('ALTER TABLE sas_vendors ADD password VARCHAR(255)');
 $password=$db->real_escape_string(password_hash('fixture-password',PASSWORD_DEFAULT));
 $db->query("UPDATE sas_vendors SET password='$password',totp_last_step=0 WHERE id=7");
 $run_policy=function($mode)use($name){
   $pipes=[];$p=proc_open([PHP_BINARY,__FILE__,'policy',$name,$mode],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);
   $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0)throw new RuntimeException('policy child '.$err);
   return json_decode($out,true,512,JSON_THROW_ON_ERROR);
 };
 // Start with a strict tenant policy; policy edits still need the old enrolled factor.
 $db->query("UPDATE sas_settings SET setting_value='{\"google\":true,\"email\":false}' WHERE vendor_id=7 AND setting_name='action_security_channels'");
 wallet_check($run_policy('bad')['status']==='error' && bc_action_channels('sas_vendors',['id'=>7])['google']===true,'wrong password cannot change channel policy');
 wallet_check($run_policy('good')['status']==='success' && bc_action_channels('sas_vendors',['id'=>7])===['google'=>false,'email'=>false],'verified policy form commits PIN-only choice with acknowledgement');
 wallet_check(bc_action_channels('sas_vendors',['id'=>8])===['google'=>false,'email'=>true],'saving tenant override does not alter another tenant');
 if($edition==='SAAS'){
  require __DIR__.'/../DGV7.0-SAAS/func/bc-complimentary-upgrade.php';
  $db->query('CREATE TABLE sas_super_admin LIKE sas_vendors');
  $db->query("INSERT INTO sas_super_admin (id,email,status,is_blocked,security_pin) VALUES (1,'platform@example.test',1,0,'$pin')");
  $db->query("UPDATE sas_super_admin_options SET option_value='{\"google\":false,\"email\":false}' WHERE option_name='action_security_channels'");
  $db->query('ALTER TABLE sas_users ADD account_level INT DEFAULT 1,ADD api_status INT DEFAULT 2,ADD force_password_change INT DEFAULT 1');
  $_SESSION=[];$_POST=['_csrf_token'=>'fixture-csrf'];
  $sp=$db->query('SELECT * FROM sas_super_admin WHERE id=1')->fetch_assoc();$user=$db->query('SELECT * FROM sas_users WHERE id=11')->fetch_assoc();
  $p=['upgrade_nonce'=>bc_complimentary_nonce($sp,$user),'upgrade-type'=>'api','sec_pin'=>'9362'];
  wallet_check(strpos(bc_complimentary_upgrade($sp,11,$p),'Pricing level changed')===0,'native verified complimentary upgrade');
  $after=$db->query('SELECT * FROM sas_users WHERE id=11')->fetch_assoc();
  wallet_check((int)$after['account_level']===3 && (int)$after['api_status']===2 && (int)$after['force_password_change']===1 && $after['balance']===$user['balance'],'complimentary grant changes pricing only');
  wallet_check(strpos(bc_complimentary_upgrade($sp,11,$p),'Invalid')!==false,'native nonce replay rejected');
  $p['upgrade_nonce']=bc_complimentary_nonce($sp,$user);
  wallet_check(strpos(bc_complimentary_upgrade($sp,11,$p),'No change')===0,'native stale level cannot change recipient');
  $p['upgrade_nonce']=bc_complimentary_nonce($sp,$after);$p['upgrade-type']='agent';
  wallet_check(strpos(bc_complimentary_upgrade($sp,12,$p),'Invalid')!==false,'native token cannot cross recipient tenant');
  $db->query('UPDATE sas_super_admin SET is_blocked=1 WHERE id=1');
  wallet_check(strpos(bc_complimentary_upgrade($sp,11,$p),'restricted')!==false,'fresh blocked platform actor rejected');
 }
 require __DIR__.'/../DGV7.0-'.$edition.'/func/bc-google-recovery.php';
 $db->query('ALTER TABLE sas_vendors ADD google_id VARCHAR(225), ADD force_password_change INT DEFAULT 1');
 $original=$db->query('SELECT * FROM sas_vendors WHERE id=7')->fetch_assoc();
 wallet_check(bc_google_recovery_password_update('sas_vendors',$original,'new-fixture-hash'),'native bound password write');
 wallet_check(!bc_google_recovery_password_update('sas_vendors',$original,'stale-fixture-hash'),'native stale password cannot overwrite new reset');
 $fresh=$db->query('SELECT * FROM sas_vendors WHERE id=7')->fetch_assoc();
 wallet_check($fresh['password']==='new-fixture-hash' && (int)$fresh['force_password_change']===1,'reset leaves forced setup requirement');
 $db->query('UPDATE sas_vendors SET security_pin=\'changed-pin\' WHERE id=7');
 wallet_check(!bc_google_recovery_password_update('sas_vendors',$fresh,'blocked-hash'),'native PIN change invalidates reset write');
 wallet_check($db->query('SELECT password FROM sas_vendors WHERE id=8')->fetch_assoc()['password']!=='new-fixture-hash','reset does not alter other tenant');
 echo "$n $edition admin wallet and channel policy MySQL checks passed\n";
}finally{$db->query("DROP DATABASE `$name`");$db->close();}

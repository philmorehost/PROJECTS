<?php
if(getenv('BC_ADMIN_WALLET_CI')!=='1')throw new RuntimeException('Disposable CI only.');
set_exception_handler(function($e){echo '::error::'.str_replace(["\n","\r"],' ',$e->getMessage()).' at line '.$e->getLine()."\n";exit(1);});
$edition=getenv('BC_TEST_EDITION')?:'SAAS';if(!in_array($edition,['SAAS','NON-SAAS'],true))throw new RuntimeException('edition');
require __DIR__.'/../DGV7.0-'.$edition.'/func/bc-admin-wallet-adjustment.php';
require __DIR__.'/../DGV7.0-'.$edition.'/func/bc-stepup.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
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
 echo "$n $edition admin wallet and channel policy MySQL checks passed\n";
}finally{$db->query("DROP DATABASE `$name`");$db->close();}

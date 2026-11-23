<?php
if(getenv('BC_PAID_GATEWAY_CI')!=='1')throw new RuntimeException('Disposable CI only.');
set_exception_handler(function($e){echo '::error::'.str_replace(["\n","\r",'%'],[' ',' ','pct'],$e->getMessage()).' at line '.$e->getLine()."\n";exit(1);});
$edition=getenv('BC_TEST_EDITION')?:'SAAS';if(!in_array($edition,['SAAS','NON-SAAS'],true))throw new RuntimeException('edition');
require __DIR__.'/../DGV7.0-'.$edition.'/func/bc-gateway-api-settings.php';
require __DIR__.'/../DGV7.0-'.$edition.'/func/bc-paid-security.php';
require __DIR__.'/../DGV7.0-'.$edition.'/func/bc-stepup.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
function paid_db($name=''){return new mysqli('127.0.0.1','root','disposable-ci-only',$name,3306);}
if(($argv[1]??'')==='--race'){
 $name=$argv[2]??'';if(!preg_match('/\Abc_paid_ci_[a-f0-9]{12}\z/D',$name))throw new RuntimeException('db');
 $db=paid_db($name);$GLOBALS['bc_paid_action_verified']=['role'=>'admin','actor'=>['id'=>7]];
 echo bc_paid_module_purchase($db,7,'bybit','150000.00');exit;
}
$db=paid_db();$connection_server=$db;$name='bc_paid_ci_'.bin2hex(random_bytes(6));$db->query("CREATE DATABASE `$name`");$db->select_db($name);
$n=0;function paid_check($v,$m){global $n;if(!$v)throw new RuntimeException($m);$n++;}
function paid_balance($db,$table,$id){return (string)$db->query("SELECT balance FROM $table WHERE id=$id")->fetch_assoc()['balance'];}
try{
 $db->query('CREATE TABLE sas_vendors (id INT PRIMARY KEY,balance DECIMAL(30,8),status INT,is_blocked INT,force_password_change INT) ENGINE=InnoDB');
 $db->query('INSERT INTO sas_vendors VALUES (7,500000,1,0,0),(8,500000,1,0,0)');
 $db->query("CREATE TABLE sas_users (id INT PRIMARY KEY,vendor_id INT,username VARCHAR(225),balance DECIMAL(30,8),status INT,is_blocked INT,force_password_change INT,account_level INT,account_type VARCHAR(225),api_status INT,kyb_status INT,tx_lock_reason VARCHAR(225)) ENGINE=InnoDB");
 $db->query("INSERT INTO sas_users VALUES (2,7,'customer',10000,1,0,0,1,'personal',2,0,''),(3,8,'other',10000,1,0,0,1,'personal',2,0,'')");
 $db->query('CREATE TABLE sas_user_upgrade_price (vendor_id INT,account_type VARCHAR(225),price VARCHAR(225)) ENGINE=InnoDB');
 $db->query("INSERT INTO sas_user_upgrade_price VALUES (7,'1','9999'),(7,'2','500'),(7,'3','0')");
 $db->query('CREATE TABLE sas_settings (vendor_id INT,setting_name VARCHAR(225),setting_value VARCHAR(225),UNIQUE KEY idx_vendor_setting(vendor_id,setting_name)) ENGINE=InnoDB');
 $db->query('CREATE TABLE sas_super_admin_options (option_name VARCHAR(255) PRIMARY KEY,option_value TEXT) ENGINE=InnoDB');
 $db->query('CREATE TABLE sas_super_admin (id INT PRIMARY KEY,status INT,is_blocked INT,totp_secret VARCHAR(225),two_factor_type VARCHAR(20)) ENGINE=InnoDB');
 $db->query("INSERT INTO sas_super_admin VALUES (1,1,0,'FIXTURE','totp')");
 $source=file_get_contents(__DIR__.'/../DGV7.0-'.$edition.'/func/bc-tables.php');
 foreach(['sas_wallet_mutation_events','sas_gateway_api_credentials'] as $table){$start=strpos($source,"CREATE TABLE IF NOT EXISTS $table (");$end=strpos($source,'");',$start);if($start===false||$end===false)throw new RuntimeException('ddl');$db->query(substr($source,$start,$end-$start));}
 $columns='vendor_id INT,product_unique_id VARCHAR(225),type_alternative VARCHAR(225),reference VARCHAR(225),amount DECIMAL(30,8),discounted_amount DECIMAL(30,8),balance_before DECIMAL(30,8),balance_after DECIMAL(30,8),description TEXT,api_website VARCHAR(225),status INT';
 $db->query("CREATE TABLE sas_vendor_transactions ($columns) ENGINE=InnoDB");
 $db->query("CREATE TABLE sas_transactions ($columns,username VARCHAR(225),api_reference VARCHAR(225),mode VARCHAR(50)) ENGINE=InnoDB");
 paid_check(bc_fintech_api_price('bybit')===150000.0 && bc_fintech_api_price('klasha')===50000.0,'default prices');
 paid_check(bc_paid_level_change($db,7,2,'agent','500',1)==='verification_required','authorization required');
 $GLOBALS['bc_paid_action_verified']=['role'=>'admin','actor'=>['id'=>7]];
 paid_check(bc_paid_level_change($db,7,2,'agent','500',1)==='success' && (float)paid_balance($db,'sas_users',2)===9500.0,'atomic paid upgrade');
 paid_check(bc_paid_level_change($db,7,2,'agent','500',1)==='already_active' && (float)paid_balance($db,'sas_users',2)===9500.0,'repeat never bills twice');
 $row=$db->query('SELECT * FROM sas_users WHERE id=2')->fetch_assoc();paid_check($row['account_type']==='personal' && (int)$row['api_status']===2 && (int)$row['kyb_status']===0,'pricing cannot self-promote business or API');
 paid_check(bc_paid_level_change($db,7,2,'api','0',2)==='success' && (float)paid_balance($db,'sas_users',2)===9500.0,'zero configured price accepted');
 paid_check(bc_paid_level_change($db,7,2,'smart','0',3)==='success','Smart always free despite stale configured fee');
 paid_check(bc_paid_level_change($db,7,2,'agent','499',1)==='price_changed','stale price refused');
 paid_check(bc_paid_level_change($db,7,2,'agent','500',3)==='price_changed','stale level refused');
 paid_check(bc_paid_level_change($db,7,3,'agent','500',1)==='account_restricted' && (float)paid_balance($db,'sas_users',3)===10000.0,'tenant isolation');
 $db->query("UPDATE sas_users SET tx_lock_reason='incident hold' WHERE id=2");paid_check(bc_paid_level_change($db,7,2,'agent','500',1)==='security_hold','financial hold preserved');$db->query("UPDATE sas_users SET tx_lock_reason='' WHERE id=2");
 $count=(int)$db->query('SELECT COUNT(*) n FROM sas_transactions')->fetch_assoc()['n'];
 $fail=function(){throw new RuntimeException('injected_failure');};
 $result=bc_paid_purchase($db,7,2,'rollback','500',function(){return '500';},$fail);
 paid_check($result==='unavailable' && (float)paid_balance($db,'sas_users',2)===9500.0 && (int)$db->query('SELECT COUNT(*) n FROM sas_transactions')->fetch_assoc()['n']===$count,'failed grant rolls back wallet receipt and event');
 $db->query('ALTER TABLE sas_transactions ENGINE=MyISAM');paid_check(bc_paid_level_change($db,7,2,'agent','500',1)==='unavailable','nontransactional schema denied');$db->query('ALTER TABLE sas_transactions ENGINE=InnoDB');
 $db->begin_transaction();paid_check(bc_paid_level_change($db,7,2,'agent','500',1)==='unavailable','outer transaction denied');$db->rollback();
 if($edition==='SAAS'){
  paid_check(!bc_gateway_api_entitled($db,7,'bybit') && !bc_gateway_api_entitled($db,7,'klasha'),'both modules locked before purchase');
  $procs=[];foreach([1,2] as $i){$pipes=[];$p=proc_open([PHP_BINARY,__FILE__,'--race',$name],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$procs[]=[$p,$pipes];}
  $results=[];foreach($procs as [$p,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);paid_check($exit===0,'race process '.$err);$results[]=$out;}
  sort($results);paid_check($results===['already_active','success'] && (float)paid_balance($db,'sas_vendors',7)===350000.0,'concurrent purchases debit and grant exactly once locally');
  paid_check(bc_gateway_api_entitled($db,7,'bybit') && !bc_gateway_api_entitled($db,8,'bybit'),'grant scoped to vendor');
  paid_check(bc_paid_module_purchase($db,7,'klasha','50000')==='success' && (float)paid_balance($db,'sas_vendors',7)===300000.0,'Klasha purchase atomic');
 }else{paid_check(bc_gateway_api_entitled($db,7,'bybit') && bc_gateway_api_entitled($db,7,'klasha'),'NON-SAAS included');paid_check(bc_paid_module_purchase($db,7,'bybit','150000')==='already_active' && (float)paid_balance($db,'sas_vendors',7)===500000.0,'NON-SAAS never billed');}
 $mySqlPass='disposable-local-encryption-fixture';
 $input=['gateway_environment'=>'testnet','gateway_key'=>'fixtureBybitKey','gateway_secret'=>str_repeat('s',32),'gateway_expected_ips'=>'192.0.2.10'];
 paid_check(bc_gateway_api_save($db,['id'=>7],'bybit',$input)==='success','encrypted Bybit save');
 $stored=$db->query("SELECT sealed_config FROM sas_gateway_api_credentials WHERE vendor_id=7 AND gateway_slug='bybit'")->fetch_assoc()['sealed_config'];paid_check(strpos($stored,'gcm:')===0 && strpos($stored,$input['gateway_secret'])===false,'no plaintext secrets at rest');
 paid_check(bc_gateway_api_save($db,['id'=>7],'bybit',[])==='success' && bc_gateway_api_read($db,7,'bybit')['key']==='fixtureBybitKey','blank save retains keys');
 require __DIR__.'/../DGV7.0-'.$edition.'/func/bc-bybit.php';
 paid_check(bc_bybit_config(7)['environment']==='testnet' && bc_bybit_config(7)['expected_ips']===['192.0.2.10'],'saved Bybit config consumed locally without transport');
 $klasha=['gateway_public_key'=>'fixture-public','gateway_secret_key'=>'fixture-password','gateway_encrypt_key'=>'fixture-encryption','gateway_login_email'=>'fixture@example.test'];
 paid_check(bc_gateway_api_save($db,['id'=>7],'klasha',$klasha)==='success','Klasha encrypted config saved');
 require __DIR__.'/../DGV7.0-'.$edition.'/func/bc-fintech-providers.php';
 $cred=bc_fintech_provider_credentials(7,'klasha');paid_check($cred['found'] && $cred['secret_key']==='fixture-password' && $cred['login_email']==='fixture@example.test','Klasha adapter reads encrypted config');
 $GLOBALS['bc_paid_action_verified']=['role'=>'spadmin','actor'=>['id'=>1,'totp_secret'=>'FIXTURE','two_factor_type'=>'totp']];
 $prices=[];foreach(bc_fintech_api_providers() as $slug=>$m)$prices['fintech_api_price_'.$slug]=(string)$m['default_price'];$prices['fintech_api_price_bybit']='125000';$prices['fintech_api_price_klasha']='0';
 paid_check(bc_gateway_prices_save($db,$GLOBALS['bc_paid_action_verified']['actor'],$prices) && bc_fintech_api_price('bybit')===125000.0 && bc_fintech_api_price('klasha')===0.0,'spadmin prices adjustable including zero');
 $prices['fintech_api_price_bybit']='-1';paid_check(!bc_gateway_prices_save($db,$GLOBALS['bc_paid_action_verified']['actor'],$prices) && bc_fintech_api_price('bybit')===125000.0,'invalid price leaves all prices unchanged');
 echo "$n $edition paid gateway/upgrade MySQL checks passed\n";
}finally{$db->query("DROP DATABASE `$name`");$db->close();}

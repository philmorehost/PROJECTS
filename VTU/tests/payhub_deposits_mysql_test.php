<?php
if(getenv('BC_PAYHUB_DEPOSITS_CI')!=='1')throw new RuntimeException('Disposable CI only.');
set_exception_handler(function($e){echo '::error::'.str_replace(["\n","\r"], ' ', $e->getMessage()).' line '.$e->getLine()."\n";exit(1);});
$edition=getenv('BC_TEST_EDITION')?:'SAAS';if(!in_array($edition,['SAAS','NON-SAAS'],true))throw new RuntimeException('edition');
require __DIR__.'/../DGV7.0-'.$edition.'/func/bc-wallet-funding-page.php';
$GLOBALS['phd_services']=true;
function isServiceEnabled($service,$vid){return $GLOBALS['phd_services'] && $vid===7 && in_array($service,['payhub','virtual_bank_display'],true);}
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
function phd_db($name=''){return new mysqli('127.0.0.1','root','disposable-ci-only',$name,3306);}
function phd_payload($ref,$account='0123456789'){return json_encode(['event'=>'charge.success','data'=>['reference'=>$ref,'receiving_account_number'=>$account,'metadata'=>['username'=>'attacker','vendor_id'=>8],'amount'=>999999999]]);}
function phd_verify($ref,$account='0123456789'){return ['status'=>true,'paid'=>true,'data'=>['paid'=>true,'status'=>'success','domain'=>'live','currency'=>'NGN','reference'=>$ref,'receiving_account_number'=>$account,'deposit_evidence_version'=>1,'gateway_amount'=>10000,'fee_amount'=>100,'settled_amount'=>9900]];}
$secret='sk_live_'.str_repeat('a',32);
function phd_receive($db,$ref,$account='0123456789',$route='user',$verify=null){global $secret;$raw=phd_payload($ref,$account);return bc_ph_receive($db,$raw,hash_hmac('sha256',$raw,$secret),$route,$verify??fn($key,$r)=>phd_verify($r,$account));}
if(($argv[1]??'')==='race'){
 $name=$argv[2]??'';if(!preg_match('/\Abc_ph_ci_[a-f0-9]{12}\z/D',$name))throw new RuntimeException('db');
 try{echo json_encode(phd_receive(phd_db($name),'race-1'));}catch(Throwable $e){echo json_encode(['error'=>$e->getMessage()]);exit(1);}exit;
}
$db=phd_db();$name='bc_ph_ci_'.bin2hex(random_bytes(6));$db->query("CREATE DATABASE `$name`");$db->select_db($name);
$n=0;function phd_check($ok,$m){global $n;if(!$ok)throw new RuntimeException($m);$n++;}
function phd_refused($f,$code){try{$f();throw new RuntimeException('unexpected acceptance '.$code);}catch(Throwable $e){phd_check($e->getMessage()===$code,'expected '.$code.' got '.$e->getMessage());}}
function phd_balance($db,$id=1,$table='sas_users'){return (string)$db->query("SELECT balance FROM $table WHERE id=$id")->fetch_assoc()['balance'];}
try {
 $db->query('CREATE TABLE sas_users(id INT PRIMARY KEY,vendor_id INT,username VARCHAR(225),balance DECIMAL(30,2),status INT,is_blocked INT) ENGINE=InnoDB');
 $db->query('CREATE TABLE sas_vendors(id INT PRIMARY KEY,balance DECIMAL(30,2),status INT,is_blocked INT) ENGINE=InnoDB');
 $db->query("INSERT INTO sas_users VALUES(1,7,'alice',100,1,0),(2,8,'other',100,1,0)");$db->query('INSERT INTO sas_vendors VALUES(7,100,1,0),(8,100,1,0)');
 foreach(['sas_user_banks','sas_vendor_banks'] as $table)$db->query("CREATE TABLE $table(id INT PRIMARY KEY,vendor_id INT,username VARCHAR(225),account_number VARCHAR(225),gateway_name VARCHAR(100),status INT,bank_name VARCHAR(225) DEFAULT 'Fixture Bank',account_name VARCHAR(225) DEFAULT 'Alice Fixture') ENGINE=InnoDB");
 $db->query("INSERT INTO sas_user_banks(id,vendor_id,username,account_number,gateway_name,status) VALUES(1,7,'alice','0123456789','payhub',1)");$db->query("INSERT INTO sas_vendor_banks(id,vendor_id,username,account_number,gateway_name,status) VALUES(2,7,'','1234567890','payhub',1)");
 foreach(['sas_payment_gateways','sas_super_admin_payment_gateways'] as $table){$db->query("CREATE TABLE $table(vendor_id INT,gateway_name VARCHAR(100),secret_key VARCHAR(225),status INT) ENGINE=InnoDB");$db->query("INSERT INTO $table VALUES(7,'payhub','$secret',1)");}
 $history='vendor_id INT,product_unique_id VARCHAR(225),type_alternative VARCHAR(225),reference VARCHAR(225),amount DECIMAL(30,2),discounted_amount DECIMAL(30,2),balance_before DECIMAL(30,2),balance_after DECIMAL(30,2),description TEXT,api_website VARCHAR(225),status INT';
 $db->query("CREATE TABLE sas_vendor_transactions($history) ENGINE=InnoDB");$db->query("CREATE TABLE sas_transactions($history,username VARCHAR(225),api_reference VARCHAR(225),mode VARCHAR(225)) ENGINE=InnoDB");
 $db->query('CREATE TABLE sas_wallet_mutation_events(event_key CHAR(64) PRIMARY KEY,payload_hash CHAR(64),vendor_id INT,actor VARCHAR(225),direction VARCHAR(10),reference VARCHAR(225),amount DECIMAL(30,2)) ENGINE=InnoDB');
 $db->query(file_get_contents(__DIR__.'/../migrations/20261118_payhub_deposits.sql'));
 $r=phd_receive($db,'first');phd_check($r['code']==='CREDITED' && phd_balance($db)==='199.00','net credit, ignoring attacker-supplied amount and beneficiary');
 phd_check(phd_balance($db,2)==='100.00','other tenant untouched');
 phd_check(phd_receive($db,'first')['code']==='ALREADY_CREDITED' && phd_balance($db)==='199.00','replay one credit');
 $old=phd_verify('first');$old['data']['gateway_amount']=10100;$old['data']['settled_amount']=10000;
 phd_refused(fn()=>phd_receive($db,'first','0123456789','user',fn()=> $old),'payment_claim_conflict');
 $raw=phd_payload('unsigned');$calls=0;
 phd_refused(function()use($db,$raw,&$calls){bc_ph_receive($db,$raw,str_repeat('0',64),'user',function()use(&$calls){$calls++;});},'invalid_signature');phd_check($calls===0,'no verification network call on invalid signature');
 foreach(['domain'=>'test','currency'=>'USD','status'=>'pending','paid'=>false,'receiving_account_number'=>'9999999999','deposit_evidence_version'=>null] as $k=>$v){$bad=phd_verify('bad');$bad['data'][$k]=$v;phd_refused(fn()=>phd_receive($db,'bad','0123456789','user',fn()=> $bad),'payment_evidence_requires_review');}
 foreach(['gateway_amount'=>null,'settled_amount'=>10000,'fee_amount'=>-1] as $k=>$v){$bad=phd_verify('bad');$bad['data'][$k]=$v;phd_refused(fn()=>phd_receive($db,'bad','0123456789','user',fn()=> $bad),'payment_amount_requires_review');}
 phd_refused(fn()=>phd_receive($db,'route','0123456789','vendor'),'wrong_receiver');
 $db->query("UPDATE sas_users SET is_blocked=1 WHERE id=1");phd_refused(fn()=>phd_receive($db,'blocked'),'wallet_restricted');$db->query('UPDATE sas_users SET is_blocked=0 WHERE id=1');
 $db->query("INSERT INTO sas_user_banks(id,vendor_id,username,account_number,gateway_name,status) VALUES(3,8,'other','0123456789','payhub',1)");phd_refused(fn()=>phd_receive($db,'ambiguous'),'account_binding_requires_review');$db->query('DELETE FROM sas_user_banks WHERE id=3');
 phd_refused(fn()=>phd_receive($db,'changed','0123456789','user',function($key,$ref)use($db){$db->query("UPDATE sas_user_banks SET username='other' WHERE id=1");return phd_verify($ref);}),'binding_changed');$db->query("UPDATE sas_user_banks SET username='alice' WHERE id=1");
 $db->query("INSERT INTO sas_transactions(reference,api_reference,vendor_id,username,status) VALUES('legacy-local','legacy',7,'alice',1)");phd_refused(fn()=>phd_receive($db,'legacy'),'historical_payment_requires_reconciliation');
 $before=phd_balance($db);
 $db->query("CREATE TRIGGER ph_fail BEFORE INSERT ON sas_transactions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture insert failure'");
 try{phd_receive($db,'rollback');throw new RuntimeException('expected injected failure');}catch(mysqli_sql_exception $e){phd_check(true,'injected receipt failure');}$db->query('DROP TRIGGER ph_fail');
 phd_check(phd_balance($db)===$before && $db->query("SELECT COUNT(*) n FROM sas_payhub_deposits WHERE provider_reference='rollback'")->fetch_assoc()['n']==0,'balance and provider claim roll back with receipt');
 phd_check(phd_receive($db,'rollback')['code']==='CREDITED','retry after rollback can credit once');
 $db->begin_transaction();$db->query('UPDATE sas_users SET balance=1 WHERE id=2');$refused=false;try{phd_receive($db,'outer');}catch(Throwable $e){$refused=true;}phd_check($refused,'caller transaction refused');$db->rollback();phd_check(phd_balance($db,2)==='100.00','caller work not committed');
 phd_check(phd_receive($db,'vendor','1234567890','vendor')['code']==='CREDITED' && phd_balance($db,7,'sas_vendors')==='199.00','platform-key vendor funding');
 $before=phd_balance($db);$children=[];
 foreach([1,2] as $i){$pipes=[];$p=proc_open([PHP_BINARY,__FILE__,'race',$name],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$children[]=[$p,$pipes];}
 $codes=[];foreach($children as [$p,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);phd_check(proc_close($p)===0,'race worker '.$err.$out);$codes[]=json_decode($out,true)['code'];}
 sort($codes);phd_check($codes===['ALREADY_CREDITED','CREDITED'],'concurrent deliveries one credit one replay');
 phd_check(phd_balance($db)===number_format((float)$before+99,2,'.',''),'race changes balance exactly once');

 // Fund.php presents only this authenticated customer's ready existing bank account, read-only.
 $db->query('CREATE TABLE sas_super_admin_options(option_name VARCHAR(225),option_value VARCHAR(225)) ENGINE=InnoDB');
 $actor=['id'=>1,'vendor_id'=>7,'username'=>'alice','status'=>1,'kyc_status'=>2];
 putenv('BC_PAYHUB_DEPOSITS_ENABLED=1');
 $balance=phd_balance($db);$receipts=$db->query('SELECT COUNT(*) n FROM sas_payhub_deposits')->fetch_assoc()['n'];
 $details=bc_fund_instructions($db,$actor);phd_check($details===['bank_name'=>'Fixture Bank','account_name'=>'Alice Fixture','account_number'=>'0123456789'],'Fund instructions exact stored owner, no credential projection');
 phd_check(phd_balance($db)===$balance && $db->query('SELECT COUNT(*) n FROM sas_payhub_deposits')->fetch_assoc()['n']===$receipts,'view instructions never credits/reserves/creates receipt');
 $db->begin_transaction();$db->query('UPDATE sas_users SET balance=2 WHERE id=2');bc_fund_instructions($db,$actor);$db->rollback();phd_check(phd_balance($db,2)==='100.00','instruction readiness never commits caller transaction');
 $GLOBALS['phd_services']=false;phd_refused(fn()=>bc_fund_instructions($db,$actor),'PayHub funding is unavailable for your vendor. Contact support.');$GLOBALS['phd_services']=true;
 $db->query("INSERT INTO sas_super_admin_options VALUES('payhub_base_url','https://evil.test/')");phd_refused(fn()=>bc_fund_instructions($db,$actor),'provider_endpoint_requires_review');$db->query('DELETE FROM sas_super_admin_options');
 $db->query('UPDATE sas_payment_gateways SET status=0 WHERE vendor_id=7');phd_refused(fn()=>bc_fund_instructions($db,$actor),'merchant_configuration_requires_review');$db->query('UPDATE sas_payment_gateways SET status=1 WHERE vendor_id=7');
 putenv('BC_PAYHUB_DEPOSITS_ENABLED=0');phd_refused(fn()=>bc_fund_instructions($db,$actor),'Automatic PayHub crediting is not yet enabled. Do not transfer funds until support confirms funding is available.');
 $db->query("UPDATE sas_user_banks SET username='other',vendor_id=8 WHERE id=1");phd_refused(fn()=>phd_receive($db,'first'),'merchant_configuration_requires_review');
 $db->query("INSERT INTO sas_payment_gateways VALUES(8,'payhub','$secret',1)");phd_refused(fn()=>phd_receive($db,'first'),'payment_claim_conflict');phd_check(phd_balance($db,2)==='100.00','provider reference cannot rebind across tenants');
 $db->query('ALTER TABLE sas_payhub_deposits ENGINE=MyISAM');phd_refused(fn()=>phd_receive($db,'engine'),'transactional_schema_required');
 echo "PASS $n PayHub native MySQL deposit checks ($edition)\n";
} finally { $db->query("DROP DATABASE `$name`"); }

<?php
declare(strict_types=1);
$root=sys_get_temp_dir().'/remit-church-'.bin2hex(random_bytes(8));mkdir($root.'/config',0700,true);define('ROOT',$root);
require __DIR__.'/../app/ManagerClient.php';require __DIR__.'/../app/LicenseManager.php';require __DIR__.'/../app/Money.php';require __DIR__.'/../app/ChurchPortal.php';require __DIR__.'/../app/Security.php';require __DIR__.'/../app/helpers.php';require __DIR__.'/../app/actions.php';
$checks=0;
function check(bool $value,string $label):void{global $checks;$checks++;if(!$value)throw new RuntimeException('FAIL: '.$label);}
function refuses(Closure $call,string $label):void{$no=false;try{$call();}catch(RuntimeException|InvalidArgumentException $e){$no=true;}check($no,$label);}
// SQLite exercises actual SQL/data mutations below with only MySQL syntax differences adapted.
// It does not test MySQL row-locking, DDL, engine constraints, or concurrent requests.
class CompatPDO extends PDO {
 public function prepare(string $query,array $options=[]): PDOStatement|false {return parent::prepare(str_replace([' FOR UPDATE','INSERT IGNORE INTO','NOW()'],['','INSERT OR IGNORE INTO','CURRENT_TIMESTAMP'],$query),$options);}
 public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs): PDOStatement|false {$query=str_replace(' FOR UPDATE','',$query);return $fetchMode===null?parent::query($query):parent::query($query,$fetchMode,...$fetchModeArgs);}
}
try{
 foreach(['0'=>0,'20'=>2000,'20.55'=>2055,'100'=>10000] as $text=>$value)check(ChurchPortal::percentage((string)$text)===$value,'percentage '.$text);
 foreach(['100.01','-5','1e2','20.555',''] as $value)refuses(fn()=>ChurchPortal::percentage($value),'invalid percent');
 $split=ChurchPortal::split(10000000,8000);check($split===['total'=>10000000,'payable'=>8000000,'retained'=>2000000],'100000 offering retains20 pays80');
 check(ChurchPortal::split(100,0)['payable']===0,'100 percent retained');check(ChurchPortal::split(100,10000)['retained']===0,'zero retained');
 for($i=0;$i<100;$i++){$gross=random_int(0,999999999999999);$split=ChurchPortal::split($gross,random_int(0,10000));check($split['retained']+$split['payable']===$gross,'cent conservation');}
 $cipher=ChurchPortal::encrypt('0123456789');check(!str_contains($cipher,'0123456789'),'bank account encrypted');check(ChurchPortal::decrypt($cipher)==='0123456789','leading zero roundtrip');
 $raw=base64_decode($cipher);$raw[30]=chr(ord($raw[30])^1);refuses(fn()=>ChurchPortal::decrypt(base64_encode($raw)),'authenticated encryption rejects tampering');
 unlink(ROOT.'/config/bank.key');refuses(fn()=>ChurchPortal::decrypt($cipher),'missing key fails without regeneration');check(!file_exists(ROOT.'/config/bank.key'),'decrypt did not regenerate key');
 $db=new CompatPDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
 $db->exec("CREATE TABLE security_settings(id INTEGER,setting_key TEXT PRIMARY KEY,setting_value TEXT);INSERT INTO security_settings(setting_key,setting_value) VALUES('church_portal_schema','2'),('officer_registration_schema','1'),('registration_enabled','1'),('notification_email','treasury@example.org'),('provincial_account_number','0123456789'),('levy_basis_points','8000');
 CREATE TABLE church_hierarchy(id INTEGER PRIMARY KEY,node_name TEXT,node_type TEXT,parent_id INTEGER,parish_code TEXT);INSERT INTO church_hierarchy(id,node_name,node_type) VALUES(1,'Hope Parish','parish'),(2,'Grace Parish','parish'),(3,'A zone','zone');
 CREATE TABLE users(id INTEGER PRIMARY KEY AUTOINCREMENT,hierarchy_id INTEGER,full_name TEXT,email TEXT UNIQUE,password_hash TEXT,role TEXT,transaction_pin_hash TEXT);
 CREATE TABLE officer_profiles(user_id INTEGER PRIMARY KEY,phone TEXT,bank_name TEXT,account_name TEXT,account_ciphertext TEXT,account_last4 TEXT);
 CREATE TABLE church_registrations(id INTEGER PRIMARY KEY AUTOINCREMENT,parish_id INTEGER,requested_church_name TEXT,requested_church_type TEXT,full_name TEXT,email TEXT UNIQUE,phone TEXT,password_hash TEXT,bank_name TEXT,account_name TEXT,account_ciphertext TEXT,account_last4 TEXT,status TEXT DEFAULT 'pending',reviewed_by INTEGER,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
 CREATE TABLE registration_limits(ip_address TEXT PRIMARY KEY,attempts INTEGER DEFAULT 0,window_started TEXT);
 CREATE TABLE notification_outbox(id INTEGER PRIMARY KEY,recipient TEXT,subject TEXT,body TEXT);
 CREATE TABLE security_audit_logs(id INTEGER PRIMARY KEY,ip_address TEXT,username_attempted TEXT,event_type TEXT,details TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
 CREATE TABLE remittance_ledgers(id INTEGER PRIMARY KEY AUTOINCREMENT,parish_id INTEGER,provincial_levy_due TEXT,amount_paid TEXT DEFAULT '0.00',status TEXT,payment_reference TEXT UNIQUE,gross_income TEXT,period_month INTEGER,period_year INTEGER,UNIQUE(parish_id,period_month,period_year));
 CREATE TABLE report_allocations(ledger_id INTEGER PRIMARY KEY,payable_basis_points INTEGER,retained_amount TEXT);
 CREATE TABLE payment_notifications(id INTEGER PRIMARY KEY AUTOINCREMENT,ledger_id INTEGER,submitted_by INTEGER,amount TEXT,transfer_reference TEXT UNIQUE,transfer_date TEXT,status TEXT DEFAULT 'pending',review_note TEXT,reviewed_by INTEGER,reviewed_at TEXT);");
 $_SERVER['REMOTE_ADDR']='192.0.2.25';
 $input=['full_name'=>'Hope Officer','email'=>'officer@example.org','phone'=>'08012345678','password'=>'test-password-1234','password_confirm'=>'test-password-1234','parish_id'=>'1','bank_name'=>'Example Bank','account_name'=>'Hope Church','account_number'=>'0123456789'];
 ChurchPortal::register($db,$input);check((int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn()===0,'registration cannot log in before approval');
 check($db->query('SELECT status FROM church_registrations')->fetchColumn()==='pending','pending approval');
 check($db->query('SELECT account_last4 FROM church_registrations')->fetchColumn()==='','registration no longer collects bank accounts');
 $admin=['id'=>100,'role'=>'provincial_admin'];$officer=['id'=>1,'role'=>'parish_officer','hierarchy_id'=>1,'email'=>'officer@example.org'];
 refuses(fn()=>ChurchPortal::reviewRegistration($db,$officer,1,'approved'),'officer cannot approve registration');
 ChurchPortal::reviewRegistration($db,$admin,1,'approved');check($db->query('SELECT role FROM users WHERE id=1')->fetchColumn()==='parish_officer','approval restricts role');
 check((int)$db->query('SELECT hierarchy_id FROM users WHERE id=1')->fetchColumn()===1,'approval binds selected parish');
 check($db->query('SELECT password_hash FROM church_registrations WHERE id=1')->fetchColumn()===null,'pending password hash cleared');
 check($db->query('SELECT account_ciphertext FROM officer_profiles')->fetchColumn()==='','approved profile starts without bank details');
 $db->prepare('UPDATE officer_profiles SET account_ciphertext=? WHERE user_id=1')->execute([ChurchPortal::encrypt('0123456789')]);
 refuses(fn()=>ChurchPortal::reviewRegistration($db,$admin,1,'approved'),'approval cannot replay');
 ChurchPortal::register($db,$input);check((int)$db->query('SELECT COUNT(*) FROM church_registrations')->fetchColumn()===1,'duplicate does not create account');
 $other=$input;$other['email']='second@example.org';$other['parish_id']='999';refuses(fn()=>ChurchPortal::register($db,$other),'cannot select a missing church');
 $other['parish_id']='2';ChurchPortal::register($db,$other);ChurchPortal::reviewRegistration($db,$admin,2,'rejected');check((int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn()===1,'rejection does not create login');
 $db->exec("UPDATE registration_limits SET attempts=5");refuses(fn()=>ChurchPortal::register($db,$input),'registration throttling');
 $db->exec("INSERT INTO remittance_ledgers(id,parish_id,provincial_levy_due,amount_paid,status,payment_reference) VALUES(1,1,'80000.00','0.00','pending','RMT-TEST'),(2,2,'10000.00','0.00','pending','RMT-OTHER')");
 $payment=['ledger_id'=>1,'amount'=>'30000.00','transfer_reference'=>'BANK-ONE','transfer_date'=>date('Y-m-d')];
 ChurchPortal::submitPayment($db,$officer,$payment);check($db->query('SELECT amount_paid FROM remittance_ledgers WHERE id=1')->fetchColumn()==='0.00','submission is not settlement');
 $bad=$payment;$bad['ledger_id']=2;refuses(fn()=>ChurchPortal::submitPayment($db,$officer,$bad),'cannot pay other parish report');
 $bad=$payment;$bad['amount']='60000';refuses(fn()=>ChurchPortal::submitPayment($db,$officer,$bad),'pending amount limits outstanding');
 $bad=$payment;$bad['transfer_date']='2030-02-31';refuses(fn()=>ChurchPortal::submitPayment($db,$officer,$bad),'invalid transfer date');
 $s=$db->prepare('INSERT INTO users(id,full_name,email,role,transaction_pin_hash) VALUES(100,?,?,?,?)');$s->execute(['Treasurer','treasurer@example.org','provincial_admin',password_hash('1234',PASSWORD_DEFAULT)]);$admin['email']='treasurer@example.org';
 $review=['notification_id'=>1,'decision'=>'approved','pin'=>'0000'];refuses(fn()=>ChurchPortal::reviewPayment($db,$admin,$review),'bad PIN rejected');
 check((int)$db->query("SELECT COUNT(*) FROM security_audit_logs WHERE event_type='pin_failure'")->fetchColumn()===1,'PIN failure committed');
 $review['pin']='1234';ChurchPortal::reviewPayment($db,$admin,$review);check($db->query('SELECT amount_paid FROM remittance_ledgers WHERE id=1')->fetchColumn()==='30000.00','approved payment added once');
 refuses(fn()=>ChurchPortal::reviewPayment($db,$admin,$review),'payment approval cannot replay');
 $payment['amount']='50000';$payment['transfer_reference']='BANK-TWO';ChurchPortal::submitPayment($db,$officer,$payment);$review['notification_id']=2;ChurchPortal::reviewPayment($db,$admin,$review);
 check($db->query('SELECT status FROM remittance_ledgers WHERE id=1')->fetchColumn()==='verified','full settlement verified');
 check($db->query('SELECT amount_paid FROM remittance_ledgers WHERE id=1')->fetchColumn()==='80000.00','full payment correct');
 refuses(fn()=>ChurchPortal::submitPayment($db,$officer,$payment),'settled ledger cannot overpay');
 check((int)$db->query('SELECT COUNT(*) FROM security_audit_logs WHERE event_type=\'remittance_verified\'')->fetchColumn()===2,'both settlements audited');
 // Exercise the real report action using a recent valid local licence cache (no external calls).
 $_SERVER['HTTP_HOST']='church.example';$key='TEST-KEY';LicenseManager::writeJson(ROOT.'/config/license.json',['key'=>$key,'domain'=>'church.example']);LicenseManager::writeJson(ROOT.'/config/license-cache.json',['fingerprint'=>hash('sha256',$key."\0".'church.example'),'checked_at'=>time(),'valid'=>true]);
 $_SESSION=[];$_POST=['csrf'=>csrf(),'action'=>'report','gross'=>'100000','month'=>'1','year'=>date('Y')];
 action($db,$officer,[1]);$saved=$db->query('SELECT * FROM remittance_ledgers WHERE id=3')->fetch();
 check($saved['provincial_levy_due']==='80000.00','report uses 100 percent less20 retention');
 check($db->query('SELECT retained_amount FROM report_allocations WHERE ledger_id=3')->fetchColumn()==='20000.00','retained snapshot saved');
 $db->exec("UPDATE security_settings SET setting_value='1000' WHERE setting_key='levy_basis_points'");
 $_POST['month']='2';action($db,$officer,[1]);
 check($db->query('SELECT provincial_levy_due FROM remittance_ledgers WHERE id=3')->fetchColumn()==='80000.00','old report unaffected by rate change');
 check($db->query('SELECT provincial_levy_due FROM remittance_ledgers WHERE id=4')->fetchColumn()==='10000.00','new report uses changed rate');
 refuses(fn()=>action($db,$officer,[1]),'duplicate monthly report rejected');
 refuses(fn()=>action($db,$officer,[2]),'report requires own parish scope');
 $db->exec("UPDATE security_settings SET setting_value='0' WHERE setting_key='levy_basis_points'");$_POST['month']='3';action($db,$officer,[1]);
 check($db->query('SELECT status FROM remittance_ledgers WHERE id=5')->fetchColumn()==='verified','zero payable needs no payment');
 // Render the new production views with strict warning handling and the test database.
 function hiddenAction(string $a):void{echo '<input name="csrf" value="'.e(csrf()).'"><input name="action" value="'.e($a).'">';}
 $portalReady=true;$ids=[1];$placeholders='?';$rows=[];$treasurer=false;$user=$officer;
 set_error_handler(function(int $severity,string $message,string $file,int $line):never{throw new ErrorException($message,0,$severity,$file,$line);});
 $page='remittances';ob_start();require __DIR__.'/../app/offering-view.php';$html=ob_get_clean();check(str_contains($html,'id="levy-preview"'),'offering calculator renders payment preview');
 ob_start();require __DIR__.'/../app/profile-view.php';$html=ob_get_clean();check(str_contains($html,'0123456789'),'own profile renders decrypted account');
 ob_start();require __DIR__.'/../app/payments-view.php';$html=ob_get_clean();check(str_contains($html,'Automated deposit · PayHub'),'payment page renders explicit gateway state');
 $user=$admin;ob_start();require __DIR__.'/../app/registrations-view.php';$html=ob_get_clean();check(str_contains($html,'Officer registrations'),'admin approval queue renders');
 ob_start();require __DIR__.'/../app/portal-settings.php';$html=ob_get_clean();check(str_contains($html,'Church retention / deduction (%)'),'admin uses human readable percentages');
 $_SERVER['REQUEST_METHOD']='GET';ob_start();require __DIR__.'/../app/register.php';$html=ob_get_clean();check(str_contains($html,'name="parish_id"'),'public registration includes parish selection');
 check(!str_contains($html,'value="0123456789"'),'public registration never leaks bank accounts');restore_error_handler();
 echo "PASS: $checks church portal assertions (SQLite compatibility harness; not a MySQL concurrency test)\n";
}finally{foreach(glob(ROOT.'/config/*') as $path)unlink($path);rmdir(ROOT.'/config');rmdir(ROOT);}

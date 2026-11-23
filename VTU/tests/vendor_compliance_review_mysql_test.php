<?php
if(getenv('BC_VENDOR_COMPLIANCE_CI')!=='1')throw new RuntimeException('Disposable CI only');
set_exception_handler(function($e){echo '::error::'.$e->getMessage().' line '.$e->getLine()."\n";exit(1);});
require __DIR__.'/../DGV7.0-SAAS/func/bc-vendor-compliance-review.php';
// Isolate transport: required-check policy fixture, actual review transaction and evidence SQL.
function bc_vendor_kyc_pending($v){return $GLOBALS['missing']??[];}
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);$db=$connection_server=new mysqli('127.0.0.1','root','disposable-ci-only','',3306);$name='bc_vcr_'.bin2hex(random_bytes(6));$db->query("CREATE DATABASE `$name`");$db->select_db($name);$n=0;
function vm($ok,$label){$GLOBALS['n']++;if(!$ok)throw new RuntimeException($label);}
try{
$db->query('CREATE TABLE sas_vendors(id INT PRIMARY KEY,email VARCHAR(100),bvn VARCHAR(30),nin VARCHAR(30),kyc_status INT,kyc_reject_reason VARCHAR(255)) ENGINE=InnoDB');
$db->query('CREATE TABLE sas_vendor_kyc_documents(id INT PRIMARY KEY,vendor_id INT,doc_type VARCHAR(50),file_name VARCHAR(100),status VARCHAR(20),reviewed_at DATETIME NULL,reviewed_by INT DEFAULT 0,review_note VARCHAR(255),KEY(vendor_id,doc_type)) ENGINE=InnoDB');
$db->query("INSERT INTO sas_vendors VALUES(7,'applicant@test','','',1,NULL)");$db->query("INSERT INTO sas_vendor_kyc_documents VALUES(1,7,'business_registration','cac.pdf','pending',NULL,0,NULL)");
$a=['id'=>1,'email'=>'reviewer@test'];$state=function()use($db){return bc_vcr_state($db->query('SELECT * FROM sas_vendors WHERE id=7')->fetch_assoc(),$db->query('SELECT * FROM sas_vendor_kyc_documents WHERE vendor_id=7')->fetch_all(MYSQLI_ASSOC));};
$before=$state();$db->query("UPDATE sas_vendor_kyc_documents SET file_name='changed.pdf' WHERE id=1");vm(bc_vcr_decide(7,'approve','',$a,$before)!==true,'stale evidence refused');
$GLOBALS['missing']=['govt_id'];vm(bc_vcr_decide(7,'approve','',$a,$state())!==true,'missing required refused');$GLOBALS['missing']=[];
$self=$a;$self['email']='applicant@test';vm(bc_vcr_decide(7,'approve','',$self,$state())!==true,'self review refused');
$db->query("CREATE TRIGGER fail_review BEFORE UPDATE ON sas_vendor_kyc_documents FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture'");vm(bc_vcr_decide(7,'approve','',$a,$state())!==true,'write failure refused');vm((int)$db->query('SELECT kyc_status FROM sas_vendors WHERE id=7')->fetch_assoc()['kyc_status']===1,'vendor rolled back');$db->query('DROP TRIGGER fail_review');
$snapshot=$state();vm(bc_vcr_decide(7,'approve','',$a,$snapshot)===true,'approve current evidence');$doc=$db->query('SELECT * FROM sas_vendor_kyc_documents WHERE id=1')->fetch_assoc();vm($doc['status']==='approved'&&(int)$doc['reviewed_by']===1&&$doc['reviewed_at']!==null,'durable reviewer identity');vm(bc_vcr_decide(7,'approve','',$a,$snapshot)!==true,'replay refused');
vm(bc_vcr_decide(7,'reject','',$a,$state())!==true,'rejection needs reason');vm(bc_vcr_decide(7,'reject','renew evidence',$a,$state())===true,'revoke current approval');
$db->begin_transaction();$db->query("UPDATE sas_vendors SET email='temporary@test' WHERE id=7");vm(bc_vcr_decide(7,'reject','reason',$a,$state())!==true,'outer transaction refused');$db->rollback();vm($db->query('SELECT email FROM sas_vendors WHERE id=7')->fetch_assoc()['email']==='applicant@test','no implicit commit');
$db->query('ALTER TABLE sas_vendor_kyc_documents ENGINE=MyISAM');vm(bc_vcr_decide(7,'reject','reason',$a,$state())!==true,'nontransactional review refused');
echo "$n real MySQL vendor compliance review checks passed\n";
}finally{$db->query("DROP DATABASE `$name`");$db->close();}

<?php
require __DIR__.'/../DGV7.0-SAAS/func/bc-vendor-registration.php';
require __DIR__.'/../DGV7.0-SAAS/func/bc-vendor-registration-commerce.php';
$n=0;function vr_check($ok,$message){$GLOBALS['n']++;if(!$ok)throw new RuntimeException($message);}
$p=['enabled'=>true,'blocked'=>'blocked.example','kyc'=>false,'kyb'=>false,'checks'=>[],'epoch'=>'fixture'];
$mx=fn($domain)=>[['target'=>'mx.example.test']];
foreach(['person@example.com','first.last+tag@example.com','Person@EXAMPLE.COM'] as $email)vr_check(bc_vreg_email($email,$p,$mx)===strtolower($email),'valid address '.$email);
foreach([null,[],42,'','invalid','a@localhost','a@example..com','a@-bad.com','a@bad-.com','a@[127.0.0.1]','a@blocked.example','a@sub.blocked.example','a@mailinator.com',"a@example.com\r\nBcc:x@y.com",'a b@example.com',' a@example.com',str_repeat('a',65).'@example.com'] as $email)vr_check(bc_vreg_email($email,$p,$mx)==='','invalid/untrusted email refused');
vr_check(bc_vreg_email('a@example.com',$p,fn($d)=>[['target'=>'.']])==='','null MX refused');
vr_check(bc_vreg_email('a@example.com',$p,fn($d)=>false)==='','DNS failure refuses');
$input=['first'=>'Ada','last'=>'Okafor','address'=>'12 Example Street','email'=>'ada@example.com','pass'=>'Strong-Password-123','phone'=>'+2348031234567','billing_package_id'=>'1','payment_method'=>'bank_deposit','website-url'=>'','existing_url'=>'example.com','addons'=>[]];
vr_check(bc_vreg_input($input)==='','valid scalar input');
foreach(['first'=>[],'pass'=>'short','phone'=>'not-a-phone','payment_method'=>'hacked','billing_package_id'=>'1 OR 1=1','addons'=>[['nested']],'business_name'=>[],'email'=>"x\0@y.com"] as $key=>$bad){$copy=$input;$copy[$key]=$bad;vr_check(bc_vreg_input($copy)!=='','malformed input '.$key);}
$row=['id'=>7,'email'=>'ada@example.com','email_verified_at'=>'2026-11-18 12:00:00','firstname'=>'Ada','lastname'=>'Okafor','phone_number'=>'08031234567','home_address'=>'Example','website_url'=>'example.com','business_name'=>'Example Ltd','business_registration_no'=>'RC123'];
$invoice=['version'=>1,'currency'=>'NGN','total'=>10000,'items'=>['package'=>10000,'domain'=>0],'package_id'=>1,'duration_days'=>30,'flags'=>['order_apk'=>0],'addons'=>''];
$row+=['billing_package_id'=>1,'total_amount'=>'100.00','domain_registration_fee'=>'0.00','selected_addons'=>'','price_snapshot'=>json_encode($invoice),'payment_method'=>'paystack','payment_status'=>'paid'];
$row['payment_evidence']=json_encode(['method'=>'paystack','amount'=>10000,'currency'=>'NGN','invoice_hash'=>hash('sha256',$row['price_snapshot']),'reference'=>'fixture-reference','verified_at'=>'2026-11-18']);
vr_check(bc_vreg_approval_error($row,$p)==='','no forced compliance still requires mailbox');$unverified=$row;unset($unverified['email_verified_at']);vr_check(bc_vreg_approval_error($unverified,$p)!=='','legacy unverified never grandfathered');
foreach(['kyc','kyb'] as $type){$policy=$p;$policy[$type]=true;vr_check(bc_vreg_approval_error($row,$policy)!=='','forced '.$type.' refuses unreviewed');
 $review=['digest'=>bc_vreg_compliance_digest($row,$policy),'reviewer'=>1,'at'=>'2026-11-18','kyc'=>'kyc-case-123','kyb'=>'kyb-case-123'];$reviewed=$row;$reviewed['compliance_review']=json_encode($review);
 vr_check(bc_vreg_approval_error($reviewed,$policy)==='','approved '.$type.' reference accepted');
 foreach(['email','firstname','lastname','home_address','business_name','business_registration_no','phone_number','website_url'] as $field){$edited=$reviewed;$edited[$field].='changed';vr_check(bc_vreg_approval_error($edited,$policy)!=='','identity change invalidates '.$field);}
 $changed=$policy;$changed['checks'][]='govt_id';vr_check(bc_vreg_approval_error($reviewed,$changed)!=='','changed required checks invalidate approval');
}
$root=__DIR__.'/../DGV7.0-SAAS/';$reg=file_get_contents($root.'bc-spadmin/VendorReg.php');
vr_check(strpos($reg,'bc_vreg_consume(')<strpos($reg,'INSERT INTO sas_pending_vendors'),'proof required before write');
vr_check(strpos($reg,"bc_hash_password(\$_POST['pass'])")!==false,'password hashed raw not SQL transformed');
$queue=file_get_contents($root.'bc-spadmin/VendorRegistrations.php');vr_check(strpos($queue,'bc_vreg_approval_error(')<strpos($queue,'INSERT INTO sas_vendors'),'compliance before activation');
vr_check(strpos($queue,'onboarding_compliance_review')!==false,'review evidence preserved on vendor');
vr_check(strpos(file_get_contents($root.'web/WebsiteOrderRequest.php'),'INSERT INTO sas_vendors')===false,'alternate activation path removed');
vr_check(strpos(file_get_contents(__DIR__.'/../DGV7.0-NON-SAAS/web/WebsiteOrderRequest.php'),'http_response_code(410)')!==false,'single-owner public signup closed');
vr_check(strpos(file_get_contents($root.'bc-spadmin/VendorRegistrationSettings.php'),'bc_paid_fields(')!==false,'settings action verification UI');
vr_check(file_get_contents($root.'func/bc-vendor-registration.php')===file_get_contents(__DIR__.'/../DGV7.0-NON-SAAS/func/bc-vendor-registration.php'),'shared helper parity');
foreach(['SAAS','NON-SAAS'] as $edition){$schema=file_get_contents(__DIR__.'/../DGV7.0-'.$edition.'/func/bc-tables.php');vr_check(strpos($schema,'CREATE TABLE IF NOT EXISTS sas_pending_vendors')<strpos($schema,'// Add columns to pending vendors as well'),'fresh pending table exists before additive migration');}
echo "$n vendor registration boundary checks passed\n";

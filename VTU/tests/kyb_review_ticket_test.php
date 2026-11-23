<?php
require __DIR__.'/../DGV7.0-SAAS/func/bc-kyb-review-ticket.php';
$n=0;function kt_check($ok,$msg){$GLOBALS['n']++;if(!$ok)throw new RuntimeException($msg);}
$_SESSION=[];$actor=['id'=>7,'email'=>'admin@example.test','username'=>'reviewer','password'=>'hash','security_pin'=>'pin'];$row=['id'=>11,'vendor_id'=>7,'username'=>'applicant','email'=>'applicant@example.test','kyb_reference'=>'ref1','kyb_status'=>1,'kyb_document'=>'doc1','kyb_business_name'=>'Fixture','kyc_status'=>2];
$token=bc_kyb_review_ticket($row,$actor);kt_check(bc_kyb_review_consume($token,$row,$actor)===bc_kyb_review_fingerprint($row),'current review permitted');kt_check(bc_kyb_review_consume($token,$row,$actor)===null,'replay refused');
foreach(['vendor_id'=>8,'kyb_reference'=>'ref2','kyb_document'=>'doc2','kyb_business_name'=>'replacement','kyc_status'=>0,'is_blocked'=>1,'kyb_status'=>3,'kyc_id_expiry'=>'2001-01-01'] as $key=>$value){$token=bc_kyb_review_ticket($row,$actor);$changed=$row;$changed[$key]=$value;kt_check(bc_kyb_review_consume($token,$changed,$actor)===null,'changed '.$key);}
foreach(['id'=>8,'password'=>'changed','security_pin'=>'changed','email'=>'other@example.test'] as $key=>$value){$token=bc_kyb_review_ticket($row,$actor);$changed=$actor;$changed[$key]=$value;kt_check(bc_kyb_review_consume($token,$row,$changed)===null,'reviewer changed '.$key);}
foreach(['email','username'] as $key){$self=$row;$self[$key]=strtoupper($actor[$key]);$token=bc_kyb_review_ticket($self,$actor);kt_check(bc_kyb_review_consume($token,$self,$actor)===null,'self-review refused '.$key);}
$token=bc_kyb_review_ticket($row,$actor);$_SESSION['kyb_review_tickets'][$token]['until']=time()-1;kt_check(bc_kyb_review_consume($token,$row,$actor)===null,'expired review');
$token=bc_kyb_review_ticket($row,$actor);$_SESSION=[];kt_check(bc_kyb_review_consume($token,$row,$actor)===null,'other session');
for($i=0;$i<205;$i++)bc_kyb_review_ticket($row,$actor);kt_check(count($_SESSION['kyb_review_tickets'])===200,'bounded grants');
foreach(['SAAS','NON-SAAS'] as $ed){$root=__DIR__.'/../DGV7.0-'.$ed;$page=file_get_contents($root.'/bc-admin/BusinessKYB.php');kt_check(substr_count($page,'name="review_ticket"')===2,'both forms carry ticket');kt_check(strpos($page,'bc_kyb_review_consume(')<strpos($page,'$result = bc_fintech_kyb_approve('),'ticket before decision');$s=file_get_contents($root.'/func/bc-fintech-accounts.php');kt_check(substr_count($s,'hash_equals($expected_review,bc_kyb_review_fingerprint($row))')===2,'both helper rereads compare expected review');}
echo "$n KYB review ticket checks passed\n";

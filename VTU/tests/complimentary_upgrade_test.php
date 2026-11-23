<?php
// Isolated controller contract; native SQL remains covered by existing syntax/DB suites.
$_SESSION=[];$changed=1;$writes=0;$audits=0;$csrf=true;$factor='';
function bc_validate_csrf(){global $csrf;if(!$csrf)throw new RuntimeException('csrf');}
function bc_q_row($sql,$args){return $GLOBALS['actor'];}
function bc_stepup_spadmin_verify($actor,$input,$purpose){global $factor;if($purpose!=='complimentary_upgrade')throw new RuntimeException('purpose');return $factor;}
function bc_q($sql,$args,&$count){global $changed,$writes;if(strpos($sql,'AND vendor_id=? AND account_level=? AND status=1')===false)throw new RuntimeException('scope');if($args!==[3,42,7,1])throw new RuntimeException('args');$writes++;$count=$changed;return true;}
function bc_sec_audit(){global $audits;$audits++;}
require __DIR__.'/../DGV7.0-SAAS/func/bc-complimentary-upgrade.php';
$actor=['id'=>1,'email'=>'fixture@example.test','status'=>1];$user=['id'=>42,'vendor_id'=>7,'account_level'=>1];$n=0;
function check($ok,$msg){global $n;$n++;if(!$ok)throw new RuntimeException($msg);}
function input(){global $actor,$user;return ['upgrade_nonce'=>bc_complimentary_nonce($actor,$user),'upgrade-type'=>'api','sec_pin'=>'1234','sec_factor'=>'123456'];}
$p=input();$csrf=false;try{bc_complimentary_upgrade($actor,42,$p);throw new LogicException('csrf missing');}catch(RuntimeException $e){check($writes===0,'csrf no write');}$csrf=true;
check(strpos(bc_complimentary_upgrade($actor,43,$p),'Invalid')!==false && $writes===0,'target binding');
$factor='Invalid code';check(bc_complimentary_upgrade($actor,42,$p)==='Invalid code' && $writes===0,'factor required');$factor='';
check(strpos(bc_complimentary_upgrade($actor,42,$p),'Pricing level changed')===0 && $writes===1 && $audits===1,'verified mutation');
check(strpos(bc_complimentary_upgrade($actor,42,$p),'Invalid')!==false && $writes===1,'nonce replay');
$p=input();$changed=0;check(strpos(bc_complimentary_upgrade($actor,42,$p),'No change')===0 && $audits===1,'stale CAS no success');
$p=input();$actor['force_password_change']=1;check(strpos(bc_complimentary_upgrade($actor,42,$p),'restricted')!==false && $writes===2,'reset restriction');
$actor['force_password_change']=0;for($i=0;$i<40;$i++)input();check(count($_SESSION['complimentary_upgrades'])===32,'bounded session');
foreach(['SAAS','NON-SAAS'] as $ed){$s=file_get_contents(__DIR__.'/../DGV7.0-'.$ed.'/api/app-backend/clone/upgrade-user.php');check(strpos($s,'http_response_code(410)')!==false && strpos($s,'central_billing')===false && strpos($s,'config.php')===false,'legacy no side effects');}
echo "$n complimentary upgrade checks passed\n";

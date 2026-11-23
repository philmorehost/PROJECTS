<?php
require __DIR__.'/../DGV7.0-SAAS/func/bc-kyc-enforcement.php';
$row=['tenant_force'=>'0','platform_force'=>'0'];$reads=0;
function bc_q_row($sql,$params){$GLOBALS['reads']++;if($params!==[7]||strpos($sql,'sas_vendors')===false||strpos($sql,'sas_super_admin_options')===false)throw new RuntimeException('scope');return $GLOBALS['row'];}
$n=0;function kf_check($ok,$msg){$GLOBALS['n']++;if(!$ok)throw new RuntimeException($msg);}
$_SESSION=['kyc_enforced_cache'=>[7=>false],'kyc_enforced_time'=>[7=>time()]];
kf_check(!bc_kyc_enforcement_current(7),'both disabled');
$row['platform_force']='1';kf_check(bc_kyc_enforcement_current(7),'platform change immediately visible');
$row=['tenant_force'=>'1','platform_force'=>'0'];kf_check(bc_kyc_enforcement_current(7),'tenant mandate independent');
$row=['tenant_force'=>'0','platform_force'=>null];kf_check(!bc_kyc_enforcement_current(7),'absent platform option defaults off');
kf_check($reads===4,'no same-request or session policy cache');
foreach([null,['tenant_force'=>'bad','platform_force'=>'0'],['tenant_force'=>'0','platform_force'=>2]] as $bad){$row=$bad;try{bc_kyc_enforcement_current(7);throw new LogicException('authorized invalid policy');}catch(RuntimeException $e){kf_check(true,'invalid policy fails closed');}}
foreach(['SAAS','NON-SAAS'] as $ed){$root=__DIR__.'/../DGV7.0-'.$ed.'/func/';$s=file_get_contents($root.'bc-func.php');$a=strpos($s,'function isKYCEnforced(');$b=strpos($s,'function base64url_encode(',$a);$fn=substr($s,$a,$b-$a);kf_check(strpos($fn,'bc_kyc_enforcement_current')!==false&&strpos($fn,'SESSION')===false,'fresh wrapper');kf_check(strpos(file_get_contents($root.'bc-security.php'),"if (!\$q) throw new RuntimeException('KYC checks are unavailable.');")!==false,'failed check lookup cannot mean disabled');}
echo "$n fresh KYC policy checks passed\n";

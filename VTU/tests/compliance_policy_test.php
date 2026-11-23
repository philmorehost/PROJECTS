<?php
require __DIR__.'/../DGV7.0-SAAS/func/bc-compliance-policy.php';
$n=0;function cp_check($ok,$msg){$GLOBALS['n']++;if(!$ok)throw new RuntimeException($msg);}
$now=strtotime('2026-11-18 12:00:00 UTC');$row=['kyc_status'=>2];
cp_check(bc_kyc_approval_current($row,$now),'approved without required expiry');
foreach([0,1,3] as $status)cp_check(!bc_kyc_approval_current(['kyc_status'=>$status],$now),'unapproved restricted');
foreach(['2026-11-17','invalid-date',[]] as $expiry)cp_check(!bc_kyc_approval_current($row+['kyc_id_expiry'=>$expiry],$now),'expired/malformed ID restricted');
cp_check(bc_kyc_approval_current($row+['kyc_id_expiry'=>'2026-11-20'],$now),'future ID valid');
cp_check(!bc_kyc_approval_current($row+['kyc_refresh_required'=>1],$now),'refresh mandate restricted');
foreach(['/web/api/kyc.php','/web/api/profile.php','/web/api/logout.php'] as $path)cp_check(bc_kyc_app_route_allowed($path),'compliance path reachable');
foreach(['/other/kyc.php','/web/api/mobile-home.php','/web/api/transactions.php','','/web/api/kyc.php/extra'] as $path)cp_check(!bc_kyc_app_route_allowed($path),'no basename bypass');
foreach(['SAAS','NON-SAAS'] as $ed){$root=__DIR__.'/../DGV7.0-'.$ed.'/func/';
 cp_check(file_get_contents($root.'bc-compliance-policy.php')===file_get_contents(__DIR__.'/../DGV7.0-SAAS/func/bc-compliance-policy.php'),'policy parity');
 cp_check(strpos(file_get_contents($root.'bc-security.php'),'if (bc_kyc_approval_current($user)) return false;')!==false,'app approval checks expiry');
 $gate=file_get_contents($root.'bc-fintech-gate.php');cp_check(substr_count($gate,'bc_fintech_app_compliance_guard($user);')===2,'both authenticators guarded');
 $config=file_get_contents($root.'bc-config.php');cp_check(strpos($config,'$min_funding > 0 && !($master_force_kyc')!==false,'funding cannot redirect out of required KYC');
}
echo "$n compliance policy checks passed\n";

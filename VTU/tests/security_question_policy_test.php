<?php
require __DIR__.'/../DGV7.0-SAAS/func/bc-security-question-policy.php';
require __DIR__.'/../DGV7.0-SAAS/func/bc-compliance-policy.php';
$n=0;function qs($ok,$msg){$GLOBALS['n']++;if(!$ok)throw new RuntimeException($msg);}
function getVendorOption($vid,$name,$default=''){return $GLOBALS['policies'][$vid]??$default;}
foreach([null,'',[],false,'{"web":false}','{"web":0,"app":false}','bad'] as $p)qs(bc_security_question_decode($p)===['web'=>true,'app'=>true],'fail closed defaults');
$GLOBALS['policies']=[7=>'{"web":false,"app":true}',8=>'{"web":true,"app":false}'];
qs(bc_security_question_policy(7)===['web'=>false,'app'=>true],'web independent');qs(bc_security_question_policy(8)===['web'=>true,'app'=>false],'tenant/app independent');
$GLOBALS['policies'][7]='{"web":true,"app":false}';qs(!bc_security_question_policy(7)['app'],'fresh policy');
qs(bc_kyc_app_route_allowed('/web/api/security-quest.php'),'KYC cannot deadlock unlock');qs(!bc_kyc_app_route_allowed('/web/api/transfer.php'),'no money route relaxation');
foreach(['SAAS','NON-SAAS'] as $ed){$base=__DIR__.'/../DGV7.0-'.$ed.'/';
 $api=file_get_contents($base.'web/api/security-quest.php');qs(strpos($api,'bc_fintech_require_app_account((int)$vendor_id, $data)')!==false,'persisted scoped app authentication');qs(strpos($api,'bc_fintech_api_credential(')===false,'no personal merchant key bridge');qs(strpos($api,"'enabled'=>false")!==false,'explicit disabled response');qs(strpos($api,'bc_is_rate_limited(')!==false,'answer throttle');qs(strpos($api,'WHERE id=? AND vendor_id=?')!==false,'atomic tenant-scoped question writes');
 qs(strpos(file_get_contents($base.'func/bc-config.php'),'bc_security_question_policy((int)$get_logged_user_details["vendor_id"])["web"]')!==false,'web enforcement');
 $settings=file_get_contents($base.'func/bc-security-question-settings.php');foreach(['bc_validate_csrf();','bc_verify_password(','bc_stepup_admin_verify(','bc_demo_guard_edit','mysqli_commit(','ack_question_off','question_web','question_app','ALTER TABLE `$t` ENGINE=InnoDB','UPDATE $store SET $col='] as $needle)qs(strpos($settings,$needle)!==false,'protected policy save '.$needle);
 qs(strpos($settings,'bc_demo_edits_allowed(')!==false,'demo mode guard replies with JSON for AJAX form');
 qs(strpos(file_get_contents($base.'func/bc-stepup.php'),"\$context==='security_question_policy'")!==false,'policy changes always require second factor');
 $web=file_get_contents($base.'web/SecurityQuest.php');qs(substr_count($web,'bc_csrf_field()')===substr_count($web,'<form '),'CSRF all question forms');
}
foreach(['DG7-Android/app/src/main/java/com/datagifting/app','JIKABIZ-Android/app/src/main/java/com/jikabiz/app','MZEEVTU-Android/app/src/main/java/com/mzeevtu'] as $package){
 $app=file_get_contents(__DIR__.'/../ANDROID - iOS/'.$package.'/ui/security/LockActivity.kt');
 foreach(['if (body["enabled"] == false)','resp.errorBody()?.string()','resp.code() == 401','KEY_IS_LOGGED_IN, false','KEY_SECURITY_VERIFIED_AT, 0L','questBank.isEmpty()'] as $needle)qs(strpos($app,$needle)!==false,'Android safe recovery '.$needle);
}
echo "$n security question policy/recovery checks passed\n";

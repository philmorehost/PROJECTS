<?php
require __DIR__.'/../DGV7.0-SAAS/func/bc-app-update-worker.php';
$n=0;function worker_source_check($v,$m){global $n;if(!$v)throw new RuntimeException($m);$n++;}
$snap=['release'=>['code'=>2],'approval'=>'v1:2:'.str_repeat('a',64)];$now=1800000000;
$token=bc_app_update_approval_token($snap,$now);
worker_source_check(bc_app_update_approval_matches($snap,$token,$now),'exact approval valid');
worker_source_check(!bc_app_update_approval_matches($snap,bc_app_update_approval_token($snap,$now-604801),$now),'seven-day expiry enforced');
worker_source_check(!bc_app_update_approval_matches($snap,bc_app_update_approval_token($snap,$now+61),$now),'future approval rejected');
worker_source_check(!bc_app_update_approval_matches($snap,str_replace('v1:2:','v1:3:',$token),$now),'other version rejected');
worker_source_check(!bc_app_update_approval_matches($snap,substr($token,0,-1).'b',$now),'changed digest rejected');
worker_source_check(!bc_app_update_approval_matches($snap,'',$now),'empty approval off');
worker_source_check(bc_app_update_actor_hash(['password'=>'old'])!==bc_app_update_actor_hash(['password'=>'new']),'password reset invalidates fingerprint');
foreach(['SAAS','NON-SAAS'] as $ed){
 $root=__DIR__.'/../DGV7.0-'.$ed;
 $cli=file_get_contents($root.'/cron/process_app_updates.php');
 worker_source_check(strpos($cli,"PHP_SAPI!=='cli'")<strpos($cli,"require_once"),'HTTP denied before bootstrap');
 worker_source_check(strpos($cli,"if(\$send && getenv('BC_APP_UPDATE_WORKER_ENABLED')!=='1')")<strpos($cli,"require_once"),'default-disabled send exits before DB/network');
 worker_source_check(strpos($cli,'A single --vendor=ID is required.')!==false,'bounded single vendor per invocation');
 worker_source_check(!preg_match('/(?:include|require)(?:_once)?\s*[^;\n]*[\'"]bc-tables\.php/',$cli),'worker does not invoke full schema migration');
 $page=file_get_contents($root.'/bc-admin/AppUpdateBroadcast.php');
 worker_source_check(strpos($page,"bc_app_update_approval_write(\$connection_server,\$vendor_id,'');")<strpos($page,'INSERT INTO sas_app_update_settings'),'old approval revoked before release write');
 worker_source_check(strpos($page,'approve-auto-update')!==false && strpos($page,'seven days')!==false,'explicit bounded approval UI');
 worker_source_check(strpos($page,"'disable-update-automation'")!==false,'independent security-gated stop action');
 worker_source_check(strpos($page,'bc_app_update_approval_token($approved)')!==false,'grant binds validated current snapshot');
 $transport=file_get_contents($root.'/func/bc-app-update-transport.php');
 worker_source_check(strpos($transport,'https://oauth2.googleapis.com/token')!==false && strpos($transport,'https://fcm.googleapis.com/v1/projects/')!==false,'fixed Google transport endpoints retained');
 worker_source_check(strpos($transport,'CURLOPT_CONNECTTIMEOUT => 5')!==false,'connect timeout retained');
 foreach(['func/bc-app-update-worker.php','func/bc-app-update-transport.php','cron/process_app_updates.php'] as $rel)worker_source_check(file_get_contents($root.'/'.$rel)===file_get_contents(__DIR__.'/../DGV7.0-SAAS/'.$rel),'edition parity '.$rel);
}
echo "$n app-update worker source/approval checks passed\n";

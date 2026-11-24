<?php
require __DIR__.'/../DGV7.0-SAAS/func/bc-service-attempt-journal.php';
$n=0;function aj($ok,$msg){$GLOBALS['n']++;if(!$ok)throw new RuntimeException($msg);}
foreach(['unknown','confirmed_success','confirmed_failure'] as $outcome){aj(bc_saj_outcome('held',$outcome)['state']==='held','no activation from hold');aj(bc_saj_outcome('in_flight',$outcome)['state']===$outcome,'outcomes classified');}
aj(bc_saj_outcome('unknown','confirmed_success')['state']==='confirmed_success','reconcile unknown success');aj(bc_saj_outcome('unknown','in_flight')['code']==='invalid','no uncertain resend');
aj(bc_saj_outcome('confirmed_success','confirmed_failure')['state']==='confirmed_success','terminal success preserved');aj(bc_saj_outcome('confirmed_failure','confirmed_success')['state']==='confirmed_failure','contradiction cannot rewrite terminal');aj(bc_saj_outcome('confirmed_success','confirmed_success')['code']==='already_final','terminal repeated evidence');
$r=['id'=>4,'vendor_id'=>7,'user_id'=>2,'type'=>'esim','provider'=>'platnova','reference'=>'fixture','payload'=>'{"z":2,"a":1}'];$hash=bc_saj_fingerprint($r);$r['payload']='{"a":1,"z":2}';aj(bc_saj_fingerprint($r)===$hash,'canonical snapshot');$r['provider']='other';aj(bc_saj_fingerprint($r)!==$hash,'provider pinned');
aj(!bc_saj_hold(0,1)['ok'],'invalid scope');aj(bc_saj_observe(7,1,'invalid','unknown',str_repeat('a',64),'',0)['code']==='invalid','invalid event refused');
foreach(['SAAS','NON-SAAS'] as $ed){$s=file_get_contents(__DIR__.'/../DGV7.0-'.$ed.'/func/bc-service-attempt-journal.php');aj($s===file_get_contents(__DIR__.'/../DGV7.0-SAAS/func/bc-service-attempt-journal.php'),'parity');foreach(['curl_exec','chargeUser(','chargeVendor(','DELETE FROM','CREATE TABLE','ALTER TABLE'] as $forbidden)aj(strpos($s,$forbidden)===false,'no dispatch money deletion or implicit DDL');}
echo "$n service attempt journal contracts passed\n";

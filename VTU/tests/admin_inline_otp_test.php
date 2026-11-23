<?php
if(($argv[1]??'')==='child'){
    function bc_validate_csrf(){if(($GLOBALS['argv'][2]??'')==='csrf')throw new RuntimeException('CSRF rejected');}
    function bc_action_channels($table,$actor){if($table!==((($GLOBALS['argv'][4]??'')==='vendor_kyc_decision')?'sas_super_admin':'sas_vendors'))throw new RuntimeException('table');return ['email'=>($GLOBALS['argv'][2]??'')!=='disabled'];}
    function bc_stepup_send_email_otp($vid,$identity,$email,$purpose,$label){if($vid!==((($GLOBALS['argv'][4]??'')==='vendor_kyc_decision')?0:7)||$identity!=='fixture@example.test'||$purpose!==($GLOBALS['argv'][4]??'kyc_decision'))throw new RuntimeException('wrong scope');return ($GLOBALS['argv'][2]??'')==='throttle'?'Wait before retrying.':'';}
    require __DIR__.'/../DGV7.0-'.($argv[3]??'SAAS').'/func/bc-admin-inline-otp.php';
    $_POST=['sec33_send_code'=>'1','approve'=>'99','sec_pin'=>'secret'];
    $actor=['id'=>7,'status'=>1,'email'=>'fixture@example.test'];if($argv[2]==='blocked')$actor['is_blocked']=1;
    bc_admin_inline_otp_dispatch($actor,($argv[4]??'')==='vendor_kyc_decision',$argv[4]??'kyc_decision');throw new LogicException('Dispatch fell through to mutation');
}
$n=0;
foreach(['SAAS','NON-SAAS'] as $edition)foreach(['kyc_decision','user_personal_conversion','vendor_kyc_decision'] as $purpose)foreach(['good'=>'success','disabled'=>'error','blocked'=>'error','throttle'=>'error','csrf'=>'nonzero'] as $mode=>$expected){
    $pipes=[];$p=proc_open([PHP_BINARY,__FILE__,'child',$mode,$edition,$purpose],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);$code=proc_close($p);
    if($expected==='nonzero'){if($code===0)throw new RuntimeException('CSRF bypass');}
    elseif($code!==0||(json_decode($out,true)['status']??'')!==$expected)throw new RuntimeException($edition.' '.$mode.': '.$out.$err);
    $n++;
}
echo "$n administrative OTP dispatch checks passed\n";

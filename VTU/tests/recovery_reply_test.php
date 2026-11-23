<?php
if(($argv[1]??'')==='child'){
 require __DIR__.'/../DGV7.0-'.($argv[2]??'SAAS').'/func/bc-recovery-inline.php';
 function bc_csrf_field(){return '<input type="hidden" name="_csrf_token" value="fixture">';}
 function bc_validate_csrf($json=false){if(!$json)throw new RuntimeException('JSON guard expected');}
 $_SERVER['HTTP_X_REQUESTED_WITH']='XMLHttpRequest';
 $_SESSION=['user-recovery-code'=>'123456','user-recovery-username'=>'private-fixture'];
 if($argv[3]==='mixed'){$_POST=['send-code'=>1,'verify-code'=>1];bc_recovery_post_guard();throw new RuntimeException('mixed fell through');}
 bc_recovery_reply($argv[3]==='success','Fixture response');throw new RuntimeException('reply fell through');
}
$n=0;
foreach(['SAAS','NON-SAAS'] as $edition)foreach(['success','error','mixed'] as $mode){
 $pipes=[];$p=proc_open([PHP_BINARY,__FILE__,'child',$edition,$mode],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
 if(proc_close($p)!==0)throw new RuntimeException($out.$err);
 $data=json_decode($out,true,512,JSON_THROW_ON_ERROR);
 if($data['status']!==($mode==='success'?'success':'error'))throw new RuntimeException('wrong status');
 if(strpos($out,'123456')!==false||strpos($out,'private-fixture')!==false)throw new RuntimeException('secret leakage');
 if($mode==='success')foreach(['name="_csrf_token"','name="code"','name="pass"','name="confirm-pass"','name="verify-code"','name="reset-recovery"','>SENT</button>'] as $field){if(strpos($data['form_html'],$field)===false)throw new RuntimeException('missing '.$field);}
 elseif($data['form_html']!=='')throw new RuntimeException('failure exposed reset stage');
 $n++;
}
echo "$n recovery JSON reply contracts passed\n";

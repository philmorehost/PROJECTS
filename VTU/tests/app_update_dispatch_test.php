<?php
require __DIR__.'/../DGV7.0-SAAS/func/bc-app-update-dispatch.php';
$db=(object)[];$events=[];$affected=0;$mode='normal';$errno=0;
function mysqli_errno($db){global $errno;return $errno;}
function mysqli_affected_rows($db){global $affected;return $affected;}
function mysqli_fetch_assoc($q){return array_shift($q->rows);}
function mysqli_query($db,$sql){
 global $events,$affected,$mode,$errno;
 $affected=0;$errno=0;
 if(strpos($sql,'@@session')!==false)return (object)['rows'=>[['auto_commit'=>$mode==='no_auto'?0:1]]];
 if(strpos($sql,'SET TRANSACTION')===0)return $mode!=='nested';
 if(strpos($sql,'information_schema')!==false)return (object)['rows'=>[['ENGINE'=>$mode==='unsafe_engine'?'MyISAM':'InnoDB']]];
 preg_match("/([a-f0-9]{64})/",$sql,$m);$event=$m[1]??'';
 if(strpos($sql,'INSERT')===0){
  if($mode==='insert_error')return false;
  if(isset($events[$event])){$errno=1062;return false;}
  $events[$event]='dispatching';$affected=1;return true;
 }
 if($mode==='finish_error')return false;
 preg_match("/SET state='([^']+)'/",$sql,$state);$events[$event]=$state[1];$affected=1;return true;
}
$n=0;function dispatch_check($v,$m){global $n;if(!$v)throw new RuntimeException($m);$n++;}
$payload=['type'=>'app_update','version_code'=>'2','version_name'=>'1.2','update_source'=>'play','store_url'=>'https://play.google.com/store/apps/details?id=com.example.app'];
$sent=0;$ok=function()use(&$sent){$sent++;return ['code'=>200,'body'=>['name'=>'projects/example-project/messages/1']];};
foreach(['no_auto','nested','unsafe_engine','insert_error'] as $mode){$sent=0;$events=[];dispatch_check(bc_app_update_dispatch($db,7,2,'example-project',$payload,$ok)==='unavailable' && $sent===0,'unsafe DB cannot send: '.$mode);}
$mode='normal';$sent=0;$events=[];
dispatch_check(bc_app_update_dispatch($db,7,2,'example-project',$payload,$ok)==='accepted' && $sent===1,'one accepted send');
dispatch_check(bc_app_update_dispatch($db,7,2,'example-project',$payload,$ok)==='already_attempted' && $sent===1,'repeat suppressed');
$changed=$payload;$changed['store_url']='https://different.test';
dispatch_check(bc_app_update_dispatch($db,7,2,'other-project',$changed,$ok)==='already_attempted' && $sent===1,'project/payload changes never reset release claim');
$events=[];$sent=0;$mode='finish_error';
dispatch_check(bc_app_update_dispatch($db,7,2,'example-project',$payload,$ok)==='unknown' && $sent===1,'accepted provider plus DB failure unconfirmed');
$mode='normal';dispatch_check(bc_app_update_dispatch($db,7,2,'example-project',$payload,$ok)==='already_attempted' && $sent===1,'finalization failure cannot resend');
foreach([function(){throw new RuntimeException('PRIVATE transport error');},function(){return ['code'=>0,'body'=>null];},function(){return ['code'=>200,'body'=>['name'=>'wrong-project']];}] as $failure){
 $events=[];dispatch_check(bc_app_update_dispatch($db,7,2,'example-project',$payload,$failure)==='unknown','ambiguous/invalid result retained as unknown');
 dispatch_check(bc_app_update_dispatch($db,7,2,'example-project',$payload,$ok)==='already_attempted','unknown outcome cannot retry');
}
$events=[];$count=0;$overlap=function()use($db,$payload,&$count,$ok){
 $count++;dispatch_check(bc_app_update_dispatch($db,7,2,'example-project',$payload,$ok)==='already_attempted','overlapping attempt sees durable claim');
 return ['code'=>200,'body'=>['name'=>'projects/example-project/messages/2']];
};
dispatch_check(bc_app_update_dispatch($db,7,2,'example-project',$payload,$overlap)==='accepted' && $count===1,'first overlapping sender completes');
$bad=$payload;$bad['version_code']='3';$sent=0;dispatch_check(bc_app_update_dispatch($db,7,2,'example-project',$bad,$ok)==='unavailable' && $sent===0,'version/payload mismatch cannot send');
dispatch_check(file_get_contents(__DIR__.'/../DGV7.0-SAAS/func/bc-app-update-dispatch.php')===file_get_contents(__DIR__.'/../DGV7.0-NON-SAAS/func/bc-app-update-dispatch.php'),'edition parity');
echo "$n update dispatch checks passed\n";

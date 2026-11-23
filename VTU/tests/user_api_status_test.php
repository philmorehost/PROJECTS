<?php
require __DIR__.'/../DGV7.0-SAAS/func/bc-user-api-status.php';
$db=(object)[];$rows=[];$mode='normal';$queries=[];
function mysqli_real_escape_string($db,$value){return addslashes($value);}
function mysqli_num_rows($q){return count($q->rows);}
function mysqli_fetch_assoc($q){return array_shift($q->rows);}
function mysqli_query($db,$sql){
 global $rows,$mode,$queries;
 $queries[]=$sql;
 if(strpos($sql,'SELECT')===0){
  if($mode==='read_error')return false;
  if($mode==='readback_error' && count($queries)>2)return false;
  $matches=array_values(array_filter($rows,function($r)use($sql){
   if(strpos($sql,"vendor_id='".$r['vendor_id']."'")===false)return false;
   if(preg_match("/ AND id='([0-9]+)'/",$sql,$m))return (int)$m[1]===(int)$r['id'];
   return strpos($sql,"username='".addslashes($r['username'])."'")!==false;
  }));
  return (object)['rows'=>$matches];
 }
 if($mode==='write_error')return false;
 if($mode==='exception')throw new RuntimeException('PRIVATE database error');
 if($mode==='miss')return true;
 preg_match("/api_status='([12])'/",$sql,$value);preg_match("/vendor_id='([0-9]+)' AND id='([0-9]+)'/",$sql,$target);
 foreach($rows as &$r)if((int)$r['vendor_id']===(int)$target[1] && (int)$r['id']===(int)$target[2]){
  $r['api_status']=(int)$value[1];
  if($mode==='policy_changed'){$r['account_type']='personal';$r['api_status']=2;}
 }
 return true;
}
$n=0;function api_check($ok,$m){global $n;if(!$ok)throw new RuntimeException($m);$n++;}
$base=['id'=>42,'vendor_id'=>7,'username'=>"customer+'name",'api_status'=>2,'account_type'=>'business','account_level'=>2,'status'=>1,'is_blocked'=>0];
$rows=[$base];$error='';
api_check(!bc_user_api_status_save($db,7,42,'','1',$error) && !$queries,'missing step-up never queries or writes');
$GLOBALS['sec33_verified']=true;
foreach(['normal','miss','write_error','read_error','readback_error','exception','policy_changed'] as $mode){
 $rows=[$base];$queries=[];$error='';
 $ok=bc_user_api_status_save($db,7,42,'wrong supplied name','1',$error);
 api_check($ok===($mode==='normal'),'success only after matching saved status and eligible readback: '.$mode);
 api_check($ok? $error==='':$error!=='','explicit failure message: '.$mode);
 api_check(strpos($error,'PRIVATE')===false,'raw DB error hidden');
 if($mode==='normal'){
  api_check($rows[0]['api_status']===1,'API enabled by immutable tenant and user IDs');
  api_check(strpos($queries[1],"COALESCE(account_type,'')='business'")!==false,'enable CAS bound to account type');
  api_check(strpos($queries[1],"account_level='2' AND status='1'")!==false,'enable CAS bound to account state');
  api_check(strpos($queries[1],'balance')===false,'wallet untouched');
 }
}
$mode='normal';
foreach([['account_type'=>'personal'],['status'=>2],['is_blocked'=>1],['account_type'=>'invalid']] as $change){
 $rows=[array_merge($base,$change)];$queries=[];
 api_check(!bc_user_api_status_save($db,7,42,'',1,$error),'policy-ineligible enable rejected');
 api_check(count($queries)===1 && $rows[0]['api_status']===2,'policy rejection never writes');
 api_check(bc_user_api_status_save($db,7,42,'',2,$error),'disable allowed even for restricted account');
}
$rows=[$base];$queries=[];
api_check(!bc_user_api_status_save($db,8,42,'',1,$error),'cross-tenant target rejected');
api_check(count($queries)===1,'cross-tenant never writes');
$queries=[];api_check(bc_user_api_status_save($db,7,0,$base['username'],1,$error),'legacy username with quote/plus safely resolved');
api_check(bc_user_api_status_save($db,7,42,'',1,$error),'already enabled idempotent readback succeeds');
$rows=[$base,array_merge($base,['id'=>43])];$queries=[];
api_check(!bc_user_api_status_save($db,7,0,$base['username'],1,$error) && count($queries)===1,'ambiguous username rejected without mutation');
foreach([0,3,-1,'garbage'] as $invalid){$queries=[];api_check(!bc_user_api_status_save($db,7,42,'',$invalid,$error) && !$queries,'invalid status rejected before SQL');}
api_check(file_get_contents(__DIR__.'/../DGV7.0-SAAS/func/bc-user-api-status.php')===file_get_contents(__DIR__.'/../DGV7.0-NON-SAAS/func/bc-user-api-status.php'),'edition parity');
echo "$n user API status persistence checks passed\n";

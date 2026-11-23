<?php
require __DIR__.'/../DGV7.0-SAAS/func/bc-transaction-receipt.php';
$source=file_get_contents(__DIR__.'/../DGV7.0-SAAS/web/api/transactions.php');
$body=substr($source,strpos($source,'$limit ='));
$body=str_replace("require_once __DIR__ . '/../../func/bc-transaction-receipt.php';",'', $body);
$body=str_replace('?>','',$body);
$connection_server=(object)[];$queries=[];$fail_history=false;$fail_utility=false;
function mysqli_real_escape_string($db,$s){return addslashes($s);}
function mysqli_query($db,$sql){
 global $queries,$fail_history,$fail_utility;
 $queries[]=$sql;
 if(strpos($sql,'FROM sas_transactions')!==false){
  if($fail_history)return false;
  $row=['vendor_id'=>7,'username'=>'customer','reference'=>'r1','product_unique_id'=>'01234567890','type_alternative'=>'Electric Charges','amount'=>'2000.00','discounted_amount'=>'1900.00','balance_before'=>'3000','balance_after'=>'1100','description'=>'PRIVATE UPSTREAM | Meter Token: SECRET_POSTPAID_TOKEN','status'=>1,'mode'=>'APP','date'=>'2026-11-18 12:00:00'];
  $row2=$row;$row2['reference']='r2';return (object)['rows'=>[$row,$row2]];
 }
 if($fail_utility)return false;
 return (object)['rows'=>[
  ['id'=>3,'reference'=>'r1','meter_type'=>'postpaid','meter_owner_name'=>'Customer','meter_owner_address'=>'Address','meter_number'=>'1234','meter_token'=>'SECRET_POSTPAID_TOKEN','token_unit'=>'5'],
  ['id'=>2,'reference'=>'r2','meter_type'=>'prepaid','meter_owner_name'=>'Customer','meter_owner_address'=>'Address','meter_number'=>'5678','meter_token'=>'PREPAID_TOKEN','token_unit'=>'5']
 ]];
}
function mysqli_fetch_assoc($q){return array_shift($q->rows);}
function mysqli_close($db){}
function tranStatus($status){return 'Completed';}
$n=0;function history_check($ok,$m){global $n;if(!$ok)throw new RuntimeException($m);$n++;}
$vendor_id=7;$username='customer';$input=['limit'=>9999,'offset'=>-20];
ob_start();eval($body);$json=ob_get_clean();$data=json_decode($json,true);
history_check($data['status']==='success' && count($data['data'])===2,'actual history shape uses data array');
history_check(strpos($queries[0],'LIMIT 100 OFFSET 0')!==false,'bounded pagination');
history_check(count($queries)===2,'one history and one utility lookup, no per-row query');
history_check(strpos($queries[1],"vendor_id='7' AND username='customer'")!==false,'utility details bound to same tenant and owner');
history_check($data['data'][0]['recipient']==='01234567890','credited number retains leading zero');
history_check(strpos($json,'SECRET_POSTPAID_TOKEN')===false,'postpaid token suppressed including raw description');
history_check(strpos($json,'PRIVATE UPSTREAM')===false,'raw provider wording not exposed');
history_check(strpos($json,'PREPAID_TOKEN')!==false,'successful prepaid token available');
history_check($data['data'][0]['receipt_details'][1]['label']==='Customer name','identity on separate labeled lines');
$fail_history=true;ob_start();eval($body);$error=json_decode(ob_get_clean(),true);
history_check($error['status']==='error','database history error not silent empty success');
$fail_history=false;$fail_utility=true;ob_start();eval($body);$error=json_decode(ob_get_clean(),true);
history_check($error['status']==='error','utility DB error not partial successful receipt');
history_check(strpos($source,'bc_fintech_api_credential')!==false,'existing session bridge retained');
$non=file_get_contents(__DIR__.'/../DGV7.0-NON-SAAS/web/api/transactions.php');
history_check(substr($non,strpos($non,'if (!$q)'))===substr($source,strpos($source,'if (!$q)')),'shared receipt behavior parity; NON-SAAS existing history filters retained');
echo "$n mobile history/receipt endpoint checks passed\n";

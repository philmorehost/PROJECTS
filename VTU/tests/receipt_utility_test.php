<?php
require __DIR__.'/../DGV7.0-SAAS/func/bc-transaction-receipt.php';
$n=0;function receipt_check($v,$m){global $n;if(!$v)throw new RuntimeException($m);$n++;}
$row=['reference'=>'test-ref','vendor_id'=>7,'username'=>'customer','product_unique_id'=>'01234567890','status'=>1,'description'=>'PRIVATE UPSTREAM WORDING','api_reference'=>'PRIVATE REF','api_website'=>'provider.invalid','utility_purchase'=>['meter_owner_name'=>'Real customer','meter_owner_address'=>'Real address','meter_number'=>'12345','meter_type'=>'prepaid','meter_token'=>'1000-2000','token_unit'=>'12']];
$p=bc_receipt_payload($row,false,'Brand');
receipt_check($p['recipient']==='01234567890','destination leading zero retained');
receipt_check($p['utility']['customer_name']==='Real customer' && $p['utility']['customer_address']==='Real address','stored utility identity provided separately');
receipt_check($p['utility']['token']==='1000-2000','successful prepaid token provided');
receipt_check(!isset($p['provider_site'],$p['provider_reference'],$p['description']),'raw upstream wording and references not exposed to customer');
foreach(['postpaid','', 'unknown'] as $type){$r=$row;$r['utility_purchase']['meter_type']=$type;$p=bc_receipt_payload($r,false,'Brand');receipt_check($p['utility']['token']==='' && $p['utility']['units']==='','postpaid/unknown never displays token');}
foreach([2,3] as $status){$r=$row;$r['status']=$status;receipt_check(bc_receipt_payload($r,false,'Brand')['utility']['token']==='','pending/failed does not display vend token');}
$r=$row;unset($r['utility_purchase']);receipt_check(!isset(bc_receipt_payload($r,false,'Brand')['utility']),'no invented utility record');
receipt_check(file_get_contents(__DIR__.'/../DGV7.0-SAAS/func/bc-transaction-receipt.php')===file_get_contents(__DIR__.'/../DGV7.0-NON-SAAS/func/bc-transaction-receipt.php'),'receipt edition parity');
echo "$n utility receipt checks passed\n";

<?php
class mysqli {}
$connection_server=new mysqli();
$get_logged_user_details=['vendor_id'=>7,'id'=>9];$rows=[];$db_ok=true;$query='';
function mysqli_query($db,$sql){global $rows,$query,$db_ok;$query=$sql;return $db_ok?(object)['rows'=>$rows]:false;}
function mysqli_fetch_assoc($result){return array_shift($result->rows);}
function bc_app_menu(){return [['items'=>[
 ['label'=>'Buy Data Bundle','href'=>'Data.php','icon'=>'diagram-3'],
 ['label'=>'Virtual Card','href'=>'VirtualCard.php','icon'=>'credit-card','blocked'=>true],
 ['label'=>'Gift Card','href'=>'GiftCard.php','icon'=>'gift'],
 ['label'=>'Missing route','href'=>'does-not-exist.php','icon'=>'x'],
 ['label'=>'Bulk SMS','href'=>'BulkSMS.php','icon'=>'chat']
]]];}
function bc_app_item_visible($item,$user){return empty($item['blocked']);}
function bc_app_url($href){return '/web/'.$href;}
function bc_app_icon($name){return '<i></i>';}
require __DIR__.'/../DGV7.0-SAAS/func/bc-float-services.php';
$n=0;function float_check($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;}
$rows=array_map(function($v){return ['service_name'=>$v];},['Buy Data Bundle','Virtual Card','Gift Card','Missing route','Send SMS','<script>alert(1)</script>']);
ob_start();bc_float_services_render();$html=ob_get_clean();
float_check(strpos($html,'/web/Data.php')!==false,'configured real route displayed');
float_check(strpos($html,'/web/BulkSMS.php')!==false,'legacy label mapped to current gated route');
float_check(strpos($html,'/web/VirtualCard.php')===false,'policy-disabled service hidden');
float_check(strpos($html,'does-not-exist')===false,'missing route hidden');
float_check(strpos($html,'alert(1)')===false,'untrusted database label not rendered');
float_check(strpos($query,"vendor_id='7' AND status=1")!==false,'query scoped to active tenant entries');
$rows=[];ob_start();bc_float_services_render();float_check(ob_get_clean()==='','empty configuration hides menu');
$db_ok=false;ob_start();bc_float_services_render();float_check(ob_get_clean()==='','database error hides rather than fabricates shortcuts');
float_check(file_get_contents(__DIR__.'/../DGV7.0-SAAS/func/bc-float-services.php')===file_get_contents(__DIR__.'/../DGV7.0-NON-SAAS/func/bc-float-services.php'),'edition parity');
echo "$n floating shortcut behavior checks passed\n";

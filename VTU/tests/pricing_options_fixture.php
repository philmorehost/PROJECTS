<?php
function mysqli_query($db,$sql){return ['price'=>100];}
function mysqli_fetch_array($result){return $result;}
function toDecimal($amount,$precision){return number_format($amount,$precision,'.','');}
$connection_server=null;$get_logged_admin_details=['id'=>7];
$source=file_get_contents(dirname(__DIR__).'/DGV7.0-SAAS/bc-admin/UserUpgrade.php');
if (!preg_match('/<select name="upgrade-type"[\s\S]*?<\/select>/', $source, $match)) throw new RuntimeException('select missing');
$out=[];
foreach([1,2,3] as $level){
    $get_user_details=['account_level'=>$level];
    ob_start();eval('?>'.$match[0]);$out[(string)$level]=ob_get_clean();
}
echo json_encode($out);

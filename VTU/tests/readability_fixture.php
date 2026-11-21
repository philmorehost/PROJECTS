<?php
// Offline CSS fixture: no login, database access or setting mutations.
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-plate-app.php';
require dirname(__DIR__).'/DGV7.0-SAAS/func/bc-plate-admin.php';
$out=array();
foreach(array('#10756F','#FFFFFF','#000000','#FFFF00','#00FF00','#777777','#FF0000','#287BFF') as $color){
 ob_start();bc_app_css($color);bc_adm_css($color,'spadmin');$out[$color]=ob_get_clean();
}
$source=file_get_contents(dirname(__DIR__).'/DGV7.0-SAAS/bc-spadmin/AccountSettings.php');
$start=strpos($source,'<div class="bg-primary bg-opacity-10 p-4');
$end=strpos($source,'<div class="card border border-primary', $start);
$panel=preg_replace('/<\?php.*?\?>/s','',substr($source,$start,$end-$start));
echo json_encode(array('styles'=>$out,'panel'=>$panel));

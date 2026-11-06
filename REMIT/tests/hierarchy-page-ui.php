<?php
declare(strict_types=1);
require __DIR__.'/../app/helpers.php';
const ROOT=__DIR__.'/..';
$role=$argv[1] ?? 'provincial_admin';$user=['role'=>$role];$admin=$role==='provincial_admin';$portalReady=false;
function hiddenAction(string $action):void{echo '<input type="hidden" name="csrf" value="test-csrf"><input type="hidden" name="action" value="'.e($action).'">';}
$nodes=[['id'=>1,'node_type'=>'zone','node_name'=>'Zone Alpha','parent_id'=>null,'parent_name'=>null,'parent_type'=>null,'zone_name'=>'Zone Alpha'],['id'=>2,'node_type'=>'zone','node_name'=>'Zone Beta','parent_id'=>null,'parent_name'=>null,'parent_type'=>null,'zone_name'=>'Zone Beta'],['id'=>3,'node_type'=>'area','node_name'=>'Hope Area','parent_id'=>1,'parent_name'=>'Zone Alpha','parent_type'=>'zone','zone_name'=>'Zone Alpha'],['id'=>4,'node_type'=>'parish','node_name'=>'Living Hope Parish','parish_code'=>'LHP001','parent_id'=>3,'parent_name'=>'Hope Area','parent_type'=>'area','zone_name'=>'Zone Alpha','contact_person'=>'Test Officer','phone_number'=>'08000000000']];
for($i=0;$i<8;$i++)$nodes[]=['id'=>10+$i,'node_type'=>'parish','node_name'=>'Planted Parish '.$i,'parish_code'=>'P00'.$i,'parent_id'=>2,'parent_name'=>'Zone Beta','parent_type'=>'zone','zone_name'=>'Zone Beta'];
if(($argv[3] ?? '')==='edit')$_GET['edit_unit']='4';if(($argv[3] ?? '')==='delete')$_GET['delete_unit']='4';
ob_start();?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><div class="workspace"><main class="main"><p>TEST FIXTURE — fictional church data</p><?php require ROOT.'/app/hierarchy-page.php'; ?></main></div></body></html><?php $html=ob_get_clean();
if(isset($argv[2]))file_put_contents($argv[2],$html);
if(str_contains($html,'id="hierarchy-editor"')!==$admin || str_contains($html,'Review deletion of')!==$admin)throw new RuntimeException('Role-specific directory controls are incorrect.');
echo "PASS: hierarchy workspace rendered for $role\n";

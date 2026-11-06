<?php
declare(strict_types=1);
require __DIR__.'/../app/helpers.php';
$user=['role'=>'provincial_admin'];
function hiddenAction(string $action):void{echo '<input type="hidden" name="action" value="'.e($action).'">';}
$nodes=[['id'=>1,'node_type'=>'zone','node_name'=>'Zone Alpha','zone_name'=>'Zone Alpha'],['id'=>2,'node_type'=>'zone','node_name'=>'Zone Beta','zone_name'=>'Zone Beta'],['id'=>3,'node_type'=>'area','node_name'=>'Area Hope','parent_name'=>'Zone Alpha','parent_type'=>'zone','zone_name'=>'Zone Alpha'],['id'=>4,'node_type'=>'parish','node_name'=>'Planted Parish','parish_code'=>'BABY001','parent_name'=>'Zone Alpha','parent_type'=>'zone','zone_name'=>'Zone Alpha']];
for($i=1;$i<=50;$i++)$nodes[]=['id'=>100+$i,'node_type'=>'parish','node_name'=>'Example Parish '.str_pad((string)$i,2,'0',STR_PAD_LEFT),'parish_code'=>'CODE'.$i,'parent_name'=>'Area Hope','parent_type'=>'area','zone_name'=>'Zone Alpha'];
ob_start();?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><main class="main"><p>TEST FIXTURE — fictional church units only</p><form id="single-unit"><label>Church unit<select name="hierarchy_id" data-unit-search required><option value="">Choose a unit</option><?php foreach($nodes as $node): ?><option value="<?= (int)$node['id'] ?>"><?= e(unitLabel($node)) ?></option><?php endforeach ?></select></label><button>Submit</button></form><?php require __DIR__.'/../app/hierarchy-tools.php'; ?></main></body></html><?php
$html=ob_get_clean();
if(isset($argv[1]))file_put_contents($argv[1],$html);
if(!str_contains($html,'data-unit-search') || !str_contains($html,'data-unit-bulk'))throw new RuntimeException('Missing search or assignment controls.');
echo "PASS: hierarchy search and bulk-assignment view rendered\n";

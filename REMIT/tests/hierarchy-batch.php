<?php
declare(strict_types=1);
ob_start();require __DIR__.'/hierarchy.php';$base=ob_get_clean();require __DIR__.'/../app/HierarchyBatch.php';
$count=0;
$legacyId=(int)$db->query("SELECT id FROM church_hierarchy WHERE parish_code='P001'")->fetchColumn();$legacyCode=$db->query("SELECT parish_code FROM church_hierarchy WHERE id=$legacyId")->fetchColumn();
$db->prepare('UPDATE church_hierarchy SET node_name=? WHERE id=?')->execute(['Legacy mixed name',$legacyId]);
$text="alpha zone, hope area, grace parish\r\nalpha zone, waiting area\r\nALPHA ZONE, HOPE AREA, new life parish\r\nalpha zone, hope area, grace parish\r\nalpha zone\r\nstandalone zone\r\n\r\n";
$result=HierarchyBatch::add($db,$admin,$text);check($result['lines']===6 && $result['created']===6,'six user-example lines create shared ancestors once');
function findUnit(string $name,string $type,?int $parent):array{global $db;$s=$db->prepare('SELECT * FROM church_hierarchy WHERE node_name=? AND node_type=? AND parent_id <=> ?');$s->execute([$name,$type,$parent]);return $s->fetch()?:[];}
$zone=findUnit('ALPHA ZONE','zone',null);$area=findUnit('HOPE AREA','area',(int)$zone['id']);$waiting=findUnit('WAITING AREA','area',(int)$zone['id']);$parish=findUnit('GRACE PARISH','parish',(int)$area['id']);
check((bool)$waiting && !(bool)findUnit('WAITING AREA','parish',(int)$zone['id']),'zone/area line creates no placeholder parish');check(str_starts_with($parish['parish_code'],'AUTO'),'parishes receive generated code');
$amount=$db->query('SELECT SUM(CAST(amount_paid AS REAL)) FROM remittance_ledgers')->fetchColumn();
check($db->query("SELECT node_name FROM church_hierarchy WHERE id=$legacyId")->fetchColumn()==='LEGACY MIXED NAME','existing names become CAPS');check($db->query("SELECT parish_code FROM church_hierarchy WHERE id=$legacyId")->fetchColumn()===$legacyCode,'legacy codes retained');
$before=(int)$db->query('SELECT COUNT(*) FROM church_hierarchy')->fetchColumn();$again=HierarchyBatch::add($db,$admin,strtolower($text));check($again['created']===0 && $again['reused']===6,'repeat input is idempotent');check((int)$db->query('SELECT COUNT(*) FROM church_hierarchy')->fetchColumn()===$before,'repeat does not duplicate');
HierarchyBatch::add($db,$admin,'alpha zone, waiting area, later parish');check((bool)findUnit('LATER PARISH','parish',(int)$waiting['id']),'missing parish can be added later under same area');
HierarchyBatch::add($db,$admin,'other zone, hope area, grace parish');$other=findUnit('OTHER ZONE','zone',null);$otherArea=findUnit('HOPE AREA','area',(int)$other['id']);check((bool)findUnit('GRACE PARISH','parish',(int)$otherArea['id']),'same names under different parents stay distinct');
HierarchyBatch::add($db,$admin,'alpha zone, , direct parish');check((bool)findUnit('DIRECT PARISH','parish',(int)$zone['id']),'direct zone parish supported');
HierarchyBatch::add($db,$admin,'"COMMA, ZONE", "COMMA, AREA", "COMMA, PARISH"');check((bool)findUnit('COMMA, ZONE','zone',null),'quoted comma names supported');
$next=(int)$db->query('SELECT MAX(id)+3 FROM church_hierarchy')->fetchColumn();$db->prepare('UPDATE church_hierarchy SET parish_code=? WHERE id=?')->execute(['AUTO'.$next,$parish['id']]);HierarchyBatch::add($db,$admin,'collision zone, collision area, collision parish');$cz=findUnit('COLLISION ZONE','zone',null);$ca=findUnit('COLLISION AREA','area',(int)$cz['id']);$cp=findUnit('COLLISION PARISH','parish',(int)$ca['id']);check($cp['parish_code']==='AUTO'.$cp['id'].'-1','generated code avoids existing unique-code collision');
$before=(int)$db->query('SELECT COUNT(*) FROM church_hierarchy')->fetchColumn();
foreach(['',"\n \n",'"UNCLOSED ZONE,AREA',',AREA,PARISH','ZONE,AREA,PARISH,EXTRA','ZONE,'.str_repeat('A',151),"ZONE,\xFF",['BAD'],str_repeat('X',2097153),str_repeat("ZONE\n",5001)] as $bad)denied(fn()=>HierarchyBatch::add($db,$admin,$bad),'malformed/oversized input rejected');
check((int)$db->query('SELECT COUNT(*) FROM church_hierarchy')->fetchColumn()===$before,'invalid input adds nothing');
foreach(['parish_officer','area_rep','zonal_rep','provincial_treasurer'] as $role)denied(fn()=>HierarchyBatch::add($db,['role'=>$role],'FORBIDDEN ZONE'),'batch is admin-only');
// Ambiguous legacy rows must roll back all earlier lines and normalization.
$db->exec("INSERT INTO church_hierarchy(node_name,node_type) VALUES('ambiguous zone','zone'),('AMBIGUOUS ZONE','zone')");$before=(int)$db->query('SELECT COUNT(*) FROM church_hierarchy')->fetchColumn();
denied(fn()=>HierarchyBatch::add($db,$admin,"ROLLBACK ZONE,ROLLBACK AREA,ROLLBACK PARISH\nAMBIGUOUS ZONE,AN AREA"),'ambiguous legacy parent rejected');check((int)$db->query('SELECT COUNT(*) FROM church_hierarchy')->fetchColumn()===$before && !findUnit('ROLLBACK ZONE','zone',null),'earlier valid lines roll back');
check($db->query('SELECT SUM(CAST(amount_paid AS REAL)) FROM remittance_ledgers')->fetchColumn()===$amount,'financial values unchanged');
check(ChurchNames::normalize('ọlá parish')==='ỌLÁ PARISH' && ChurchNames::normalize('straße parish')==='STRASSE PARISH','Unicode uppercase without mbstring');
// CSV must preserve generated codes and reject attempts to create a same-parent/name duplicate.
$stream=fopen('php://temp','w+');fwrite($stream,"zone,area,parish,parish_code,contact_person,phone_number\nALPHA ZONE,WAITING AREA,LATER PARISH,DIFFERENT,,\n");rewind($stream);denied(fn()=>HierarchyImporter::import($db,$stream),'CSV cannot duplicate existing bulk parish with a different code');fclose($stream);
$directory=ChurchPortal::directory($db);$found=[];foreach($directory as $unit)if(in_array((int)$unit['id'],[(int)$zone['id'],(int)$waiting['id'],(int)$parish['id']],true))$found[]=$unit['node_type'];sort($found);check($found===['area','parish','zone'],'registration discovery contains every new hierarchy level');
check(!str_contains(file_get_contents(__DIR__.'/../app/registration-form.php'),'name="bank_name"'),'registration remains officer-only');
echo $base;echo "PASS: $count multiline hierarchy and CAPS assertions (SQLite compatibility harness)\n";

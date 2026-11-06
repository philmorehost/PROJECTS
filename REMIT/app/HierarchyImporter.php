<?php
declare(strict_types=1);
final class HierarchyImporter {
 /** @param resource $stream */
 public static function import(PDO $db,$stream): int {
  $header=fgetcsv($stream,4096,',','"','');
  if($header!==['zone','area','parish','parish_code','contact_person','phone_number']) throw new InvalidArgumentException('CSV header must be: zone,area,parish,parish_code,contact_person,phone_number');
  $count=0;$db->beginTransaction();
  try {
   // Serialize directory imports, including shared ancestors, using an existing singleton setting row.
   $db->query("SELECT id FROM security_settings WHERE setting_key='site_title' FOR UPDATE")->fetch();
   while(($row=fgetcsv($stream,4096,',','"',''))!==false) {
    if($row===[null])continue;
    if(++$count>5000 || count($row)!==6) throw new InvalidArgumentException('CSV must contain at most 5,000 rows, each with six columns.');
    [$zone,$area,$parish,$code,$contact,$phone]=array_map('trim',$row);
    foreach([$zone,$area,$parish] as $name) if($name==='' || strlen($name)>150)throw new InvalidArgumentException('Invalid church name at row '.($count+1));
    if($code==='' || strlen($code)>20 || strlen($contact)>100 || strlen($phone)>25) throw new InvalidArgumentException('Invalid code or contact fields at row '.($count+1));
    $parent=null;
    foreach(['zone'=>$zone,'area'=>$area] as $type=>$name) {
     $s=$db->prepare('SELECT id FROM church_hierarchy WHERE node_type=? AND node_name=? AND parent_id <=> ?');$s->execute([$type,$name,$parent]);$id=$s->fetchColumn();
     if(!$id){$db->prepare('INSERT INTO church_hierarchy(node_name,node_type,parent_id) VALUES(?,?,?)')->execute([$name,$type,$parent]);$id=$db->lastInsertId();}
     $parent=(int)$id;
    }
    $s=$db->prepare('SELECT * FROM church_hierarchy WHERE parish_code=?');$s->execute([$code]);$existing=$s->fetch();
    if($existing) {
     if((int)$existing['parent_id']!==$parent || $existing['node_name']!==$parish) throw new InvalidArgumentException('Parish code '.$code.' conflicts with an existing parish.');
     $db->prepare('UPDATE church_hierarchy SET contact_person=?,phone_number=? WHERE id=?')->execute([$contact,$phone,$existing['id']]);
    }else $db->prepare("INSERT INTO church_hierarchy(node_name,node_type,parent_id,parish_code,contact_person,phone_number) VALUES(?,'parish',?,?,?,?)")->execute([$parish,$parent,$code,$contact,$phone]);
   }
   if($count===0)throw new InvalidArgumentException('CSV contains no parishes.');
   $db->commit();return $count;
  }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 }
}

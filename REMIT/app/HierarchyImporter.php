<?php
declare(strict_types=1);
final class HierarchyImporter {
 public const CSV_HEADER = ['zone','area','parish','parish_code','contact_person','phone_number'];
 /** A generated, BOM-free template containing fictional examples only. */
 public static function sampleCsv(array $user): string {
  requireRole($user,['provincial_admin']);
  $stream=fopen('php://temp','w+');
  if(!$stream)throw new RuntimeException('Cannot generate the sample CSV.');
  try {
   $rows=[self::CSV_HEADER,
    ['Example Zone','Example Area One','Example Parish One','SAMPLE001','Example Officer One','08000000000'],
    ['Example Zone','Example Area One','Example Parish Two','SAMPLE002','Example Officer Two','08000000000'],
    ['Example Zone','Example Area Two','Example Parish Three','SAMPLE003','Example Officer Three','08000000000'],
   ];
   foreach($rows as $row)if(fputcsv($stream,$row,',','"','',"\r\n")===false)throw new RuntimeException('Cannot generate the sample CSV.');
   rewind($stream);$csv=stream_get_contents($stream);
   if($csv===false)throw new RuntimeException('Cannot read the sample CSV.');
   return $csv;
  }finally{fclose($stream);}
 }

 /** @param resource $stream */
 public static function import(PDO $db,$stream): int {
  $header=fgetcsv($stream,4096,',','"','');
  if($header!==self::CSV_HEADER) throw new InvalidArgumentException('CSV header must be: zone,area,parish,parish_code,contact_person,phone_number');
  $count=0;$db->beginTransaction();
  try {
   // Serialize directory imports, including shared ancestors, using an existing singleton setting row.
   $db->query("SELECT id FROM security_settings WHERE setting_key='site_title' FOR UPDATE")->fetch();
   while(($row=fgetcsv($stream,4096,',','"',''))!==false) {
    if($row===[null])continue;
    if(++$count>5000 || count($row)!==6) throw new InvalidArgumentException('CSV must contain at most 5,000 rows, each with six columns.');
    [$zone,$area,$parish,$code,$contact,$phone]=array_map('trim',$row);
    foreach([$zone,$parish] as $name) if($name==='' || strlen($name)>150)throw new InvalidArgumentException('Invalid church name at row '.($count+1));
    if($code==='' || strlen($code)>20 || strlen($contact)>100 || strlen($phone)>25) throw new InvalidArgumentException('Invalid code or contact fields at row '.($count+1));
    if(strlen($area)>150)throw new InvalidArgumentException('Area name is too long at row '.($count+1));
    $parent=null;
    foreach(['zone'=>$zone,'area'=>$area] as $type=>$name) {
     if($type==='area' && $name==='')continue;
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

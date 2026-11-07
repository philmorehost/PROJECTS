<?php
declare(strict_types=1);
require __DIR__.'/../app/helpers.php';require __DIR__.'/../app/HierarchyManager.php';require __DIR__.'/../app/HierarchyImporter.php';
$count=0;
function check(bool $ok,string $label):void{global $count;$count++;if(!$ok)throw new RuntimeException('FAIL: '.$label);}
function denied(Closure $call,string $label):void{$no=false;try{$call();}catch(RuntimeException|InvalidArgumentException $e){$no=true;}check($no,$label);}
class TreePDO extends PDO {
 public function prepare(string $q,array $options=[]):PDOStatement|false{return parent::prepare(str_replace(['<=>',' FOR UPDATE'],['IS',''],$q),$options);}
 public function query(string $q,?int $mode=null,mixed ...$args):PDOStatement|false{$q=str_replace(' FOR UPDATE','',$q);return $mode===null?parent::query($q):parent::query($q,$mode,...$args);}
}
$db=new TreePDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$db->exec('PRAGMA foreign_keys=ON');
$db->exec("CREATE TABLE security_settings(id INTEGER PRIMARY KEY,setting_key TEXT);INSERT INTO security_settings VALUES(1,'site_title');CREATE TABLE church_hierarchy(id INTEGER PRIMARY KEY AUTOINCREMENT,node_name TEXT,node_type TEXT,parent_id INTEGER REFERENCES church_hierarchy(id),parish_code TEXT UNIQUE,contact_person TEXT,phone_number TEXT);CREATE TABLE remittance_ledgers(id INTEGER PRIMARY KEY,parish_id INTEGER REFERENCES church_hierarchy(id),amount_paid TEXT);CREATE TABLE users(id INTEGER PRIMARY KEY,hierarchy_id INTEGER REFERENCES church_hierarchy(id),role TEXT);");
$admin=['id'=>1,'role'=>'provincial_admin'];$officer=['id'=>2,'role'=>'parish_officer'];
function createUnit(string $name,string $type,int $parent=0,string $code=''):int{global $db,$admin;HierarchyManager::create($db,$admin,['node_name'=>$name,'node_type'=>$type,'parent_id'=>$parent,'parish_code'=>$code]);return (int)$db->lastInsertId();}
function parentOf(int $id):int{global $db;$s=$db->prepare('SELECT parent_id FROM church_hierarchy WHERE id=?');$s->execute([$id]);return (int)$s->fetchColumn();}
$a=createUnit('Zone A','zone');$b=createUnit('Zone B','zone');$area1=createUnit('Area 1','area',$a);$area2=createUnit('Area 2','area',$a);$area3=createUnit('Area 3','area',$b);
$p1=createUnit('Parish 1','parish',$area1,'P001');$p2=createUnit('Parish 2','parish',$area2,'P002');$baby=createUnit('Baby Parish','parish',$a,'BABY');
check(parentOf($baby)===$a,'direct zone parish accepted');check(HierarchyManager::validParent('parish','zone'),'zone parenting rule');check(!HierarchyManager::validParent('area','area'),'area nesting rejected');
$nodesBefore=(int)$db->query('SELECT COUNT(*) FROM church_hierarchy')->fetchColumn();
denied(fn()=>createUnit('Wrong','area',$area1),'area cannot belong to area');denied(fn()=>createUnit('Wrong','parish',$p1,'WRONG'),'parish cannot parent parish');
denied(fn()=>HierarchyManager::create($db,$officer,['node_name'=>'Hacked','node_type'=>'zone']),'creation requires admin');check((int)$db->query('SELECT COUNT(*) FROM church_hierarchy')->fetchColumn()===$nodesBefore,'failed creation is atomic');
denied(fn()=>HierarchyManager::move($db,$admin,['unit_ids'=>[$area1,$p1],'destination_id'=>$b]),'selected ancestor and child batch refused');check(parentOf($p1)===$area1 && parentOf($area1)===$a,'ambiguous batch changes nothing');
$move=['unit_ids'=>[$area1,$area2],'destination_id'=>$b];HierarchyManager::move($db,$admin,$move);
check(parentOf($area1)===$b && parentOf($area2)===$b,'multiple areas assigned together');check(parentOf($p1)===$area1 && parentOf($p2)===$area2,'child parishes stay with their areas');
check(in_array($p1,scope($db,['role'=>'zonal_rep','hierarchy_id'=>$b]),true),'new zone sees descendant report scope');check(!in_array($p1,scope($db,['role'=>'zonal_rep','hierarchy_id'=>$a]),true),'old zone loses moved scope');
HierarchyManager::move($db,$admin,['unit_ids'=>[$p1,$p2],'destination_id'=>$a]);check(parentOf($p1)===$a && parentOf($p2)===$a,'multiple parishes assigned directly to zone');check(in_array($p1,scope($db,['role'=>'zonal_rep','hierarchy_id'=>$a]),true),'direct parish included in zone scope');
check(!in_array($p1,scope($db,['role'=>'area_rep','hierarchy_id'=>$area1]),true),'former area loses parish scope');
HierarchyManager::move($db,$admin,['unit_ids'=>[$p1,$p2],'destination_id'=>$area3]);check(parentOf($p1)===$area3 && parentOf($p2)===$area3,'parishes assigned to area');
$before=parentOf($baby);denied(fn()=>HierarchyManager::move($db,$admin,['unit_ids'=>[$baby,$area1],'destination_id'=>$area3]),'invalid mixed batch rejected');check(parentOf($baby)===$before,'mixed failure changes nothing');
foreach([['unit_ids'=>[$a],'destination_id'=>$b],['unit_ids'=>[$baby],'destination_id'=>$baby],['unit_ids'=>[$baby,99999],'destination_id'=>$b],['unit_ids'=>[],'destination_id'=>$a],['unit_ids'=>['abc'],'destination_id'=>$a],['unit_ids'=>array_fill(0,501,$baby),'destination_id'=>$a]] as $input)denied(fn()=>HierarchyManager::move($db,$admin,$input),'invalid assignment rejected');
denied(fn()=>HierarchyManager::move($db,$officer,$move),'move requires admin');
$db->prepare('INSERT INTO remittance_ledgers VALUES(1,?,?)')->execute([$baby,'12000.00']);$db->prepare("INSERT INTO users VALUES(2,?,'parish_officer')")->execute([$baby]);
HierarchyManager::upgrade($db,$admin,['parish_id'=>$baby,'area_name'=>'New Growth Area']);$newArea=parentOf($baby);
check($newArea!==$a && parentOf($newArea)===$a,'new area remains in original zone');check($db->query('SELECT parish_id FROM remittance_ledgers WHERE id=1')->fetchColumn()===$baby,'financial history retains parish ID');
check((int)$db->query('SELECT hierarchy_id FROM users WHERE id=2')->fetchColumn()===$baby,'officer account retains parish ID');check(in_array($baby,scope($db,['role'=>'area_rep','hierarchy_id'=>$newArea]),true),'new area representative scope includes headquarters');
check($db->query("SELECT parish_code FROM church_hierarchy WHERE id=$baby")->fetchColumn()==='BABY','parish code preserved');
denied(fn()=>HierarchyManager::upgrade($db,$admin,['parish_id'=>$baby,'area_name'=>'Duplicate Area']),'repeat upgrade refused');denied(fn()=>HierarchyManager::upgrade($db,$officer,['parish_id'=>$p1,'area_name'=>'Hacked']),'upgrade requires admin');
$csv=fopen('php://temp','w+');fputcsv($csv,HierarchyImporter::CSV_HEADER,',','"','');fputcsv($csv,['Import Zone','','Planted Parish','DIRECT001','Officer','08000000000'],',','"','');rewind($csv);
check(HierarchyImporter::import($db,$csv)===1,'blank area CSV accepted');$row=$db->query("SELECT p.node_type parish_type,z.node_type parent_type FROM church_hierarchy p JOIN church_hierarchy z ON p.parent_id=z.id WHERE p.parish_code='DIRECT001'")->fetch();check($row['parish_type']==='parish'&&$row['parent_type']==='zone','blank area creates zone to parish relation');
rewind($csv);HierarchyImporter::import($db,$csv);check((int)$db->query("SELECT COUNT(*) FROM church_hierarchy WHERE parish_code='DIRECT001'")->fetchColumn()===1,'direct zone import repeatable');fclose($csv);
check(str_contains(unitLabel(['id'=>1,'node_type'=>'parish','node_name'=>'Hope','parish_code'=>'H01','parent_name'=>'Area 1','zone_name'=>'Zone B']),'Zone B'),'labels include zone context');
require_once __DIR__.'/../app/ChurchPortal.php';
check(method_exists(ChurchPortal::class,'directory'),'registration discovery query available');
$directory=ChurchPortal::directory($db);$byCode=[];foreach($directory as $record)if($record['parish_code']!==null)$byCode[$record['parish_code']]=$record;
check($byCode['DIRECT001']['parent_name']==='Import Zone','registration labels direct zone parish correctly');
check($byCode['P001']['parent_name']==='Area 3' && $byCode['P001']['zone_name']==='Zone B','registration labels moved parish correctly');
check($byCode['BABY']['parent_name']==='New Growth Area' && $byCode['BABY']['zone_name']==='Zone A','registration labels upgraded headquarters correctly');
$source=file_get_contents(__DIR__.'/../index.php');preg_match('/\$nodes=\$db->query\("([^\"]+)"\)/',$source,$nodeMatch);check(!empty($nodeMatch[1]),'production directory query found');
$directory=$db->query($nodeMatch[1])->fetchAll();$byId=[];foreach($directory as $record)$byId[(int)$record['id']]=$record;
check($byId[$p1]['parent_name']==='Area 3' && $byId[$p1]['zone_name']==='Zone B','directory shows parent and zone after move');
check($byId[$baby]['parent_type']==='area' && $byId[$newArea]['parent_type']==='zone','directory shows headquarters and upgraded area');
echo "PASS: $count hierarchy assertions (SQLite compatibility harness, not MySQL concurrency)\n";

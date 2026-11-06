<?php
declare(strict_types=1);
require __DIR__.'/../app/helpers.php';require __DIR__.'/../app/HierarchyImporter.php';
$checks=0;
function verify(bool $ok,string $label):void {global $checks;$checks++;if(!$ok)throw new RuntimeException('FAIL: '.$label);}
// Adapt MySQL's locking/null-safe equality syntax only; no MySQL concurrency claims.
class SamplePDO extends PDO {
 public function prepare(string $query,array $options=[]): PDOStatement|false {return parent::prepare(str_replace('<=>','IS',$query),$options);}
 public function query(string $query,?int $fetchMode=null,mixed ...$args): PDOStatement|false {$query=str_replace(' FOR UPDATE','',$query);return $fetchMode===null?parent::query($query):parent::query($query,$fetchMode,...$args);}
}
foreach(['provincial_treasurer','parish_officer','zonal_rep','area_rep',''] as $role){$denied=false;try{HierarchyImporter::sampleCsv(['role'=>$role]);}catch(RuntimeException){$denied=true;}verify($denied,'non-admin download denied: '.$role);}
$csv=HierarchyImporter::sampleCsv(['role'=>'provincial_admin']);
verify(!str_starts_with($csv,"\xEF\xBB\xBF"),'no UTF-8 BOM');verify(str_ends_with($csv,"\r\n"),'CSV line endings');
$stream=fopen('php://temp','w+');fwrite($stream,$csv);rewind($stream);
$header=fgetcsv($stream,4096,',','"','');verify($header===HierarchyImporter::CSV_HEADER,'same header as importer');
$rows=[];while(($row=fgetcsv($stream,4096,',','"',''))!==false)$rows[]=$row;
verify(count($rows)===3,'three fictional examples');verify(count(array_unique(array_column($rows,3)))===3,'unique parish codes');
foreach($rows as $row){verify(count($row)===6,'six columns');verify(str_starts_with($row[3],'SAMPLE'),'fictional code');verify($row[5]==='08000000000','placeholder phone preserves leading zero');}
$db=new SamplePDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec("CREATE TABLE security_settings(id INTEGER PRIMARY KEY,setting_key TEXT);INSERT INTO security_settings VALUES(1,'site_title');CREATE TABLE church_hierarchy(id INTEGER PRIMARY KEY AUTOINCREMENT,node_name TEXT,node_type TEXT,parent_id INTEGER,parish_code TEXT UNIQUE,contact_person TEXT,phone_number TEXT);");
rewind($stream);verify(HierarchyImporter::import($db,$stream)===3,'sample accepted by the real importer');
verify((int)$db->query("SELECT COUNT(*) FROM church_hierarchy WHERE node_type='zone'")->fetchColumn()===1,'shared zone reused');
verify((int)$db->query("SELECT COUNT(*) FROM church_hierarchy WHERE node_type='area'")->fetchColumn()===2,'two parent areas');
verify((int)$db->query("SELECT COUNT(*) FROM church_hierarchy WHERE node_type='parish'")->fetchColumn()===3,'all parish examples built');
rewind($stream);verify(HierarchyImporter::import($db,$stream)===3,'repeat sample accepted');
verify((int)$db->query('SELECT COUNT(*) FROM church_hierarchy')->fetchColumn()===6,'reimport creates no duplicates');
fclose($stream);
verify(str_contains(file_get_contents(__DIR__.'/../index.php'),'download=church-import-sample'),'import form links to download');
echo "PASS: $checks sample CSV assertions (SQLite compatibility harness)\n";

<?php
declare(strict_types=1);
require __DIR__.'/../app/ChurchPortal.php';require __DIR__.'/../app/helpers.php';
// SQL recording harness only: deployment must still verify actual MySQL/MariaDB DDL.
class UpgradeStatement extends PDOStatement {
 public function __construct(private array $values){}
 public function execute(?array $params=null):bool{return true;}
 public function fetchColumn(int $column=0):mixed{return $this->values[0] ?? false;}
 public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return $this->values;}
}
class UpgradePDO extends PDO {
 public array $columns=['id','parish_id'];public array $sql=[];public bool $enabled=false;public bool $fail=false;
 public function __construct(){}
 public function prepare(string $query,array $options=[]):PDOStatement|false{return new UpgradeStatement(['2']);}
 public function query(string $query,?int $mode=null,mixed ...$args):PDOStatement|false{$this->sql[]=$query;return new UpgradeStatement(str_starts_with($query,'SHOW')?$this->columns:[1]);}
 public function exec(string $statement):int|false {
  $this->sql[]=$statement;
  if(str_contains($statement,'ADD requested_church_type') && $this->fail){$this->fail=false;throw new RuntimeException('Simulated interrupted DDL');}
  if(preg_match('/ADD (requested_church_\w+)/',$statement,$m))$this->columns[]=$m[1];
  if(str_contains($statement,"'officer_registration_schema'"))$this->enabled=true;
  return 1;
 }
}
$db=new UpgradePDO();$count=0;
function verifyUpgrade(bool $ok):void{global $count;$count++;if(!$ok)throw new RuntimeException('Upgrade assertion failed.');}
$db->fail=true;try{ChurchPortal::migrate($db);}catch(RuntimeException){}
verifyUpgrade(!$db->enabled);verifyUpgrade(in_array('requested_church_name',$db->columns,true));verifyUpgrade(str_contains(end($db->sql),'RELEASE_LOCK'));
ChurchPortal::migrate($db);verifyUpgrade($db->enabled);verifyUpgrade(count(array_keys($db->columns,'requested_church_name',true))===1);verifyUpgrade(in_array('requested_church_type',$db->columns,true));
$before=$db->columns;ChurchPortal::migrate($db);verifyUpgrade($before===$db->columns);
foreach($db->sql as $sql)verifyUpgrade(!str_contains($sql,'DROP ') && !str_contains($sql,'DELETE ') && !str_contains($sql,"setting_value='2'"));
echo "PASS: $count registration upgrade SQL-recording assertions (not live MySQL DDL)\n";

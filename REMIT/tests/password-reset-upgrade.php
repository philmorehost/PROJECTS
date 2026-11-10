<?php
declare(strict_types=1);
require __DIR__.'/../app/PasswordReset.php';
class ResetUpgradeStatement extends PDOStatement {public function __construct(private int $value){}public function fetchColumn(int $column=0):mixed{return $this->value;}}
class ResetUpgradePDO extends PDO {
 public array $sql=[];public bool $locked=true;public bool $fail=false;public string $enabled='';public string $ready='';
 public function __construct(){}
 public function query(string $query,?int $mode=null,mixed ...$args):PDOStatement|false{$this->sql[]=$query;return new ResetUpgradeStatement($this->locked?1:0);}
 public function exec(string $statement):int|false{$this->sql[]=$statement;if($this->fail&&str_contains($statement,'password_reset_limits'))throw new RuntimeException('Simulated DDL failure');if(str_contains($statement,"'password_reset_schema','1'"))$this->ready='1';return 0;}
}
function setting(PDO $db,string $key):string{return $key==='password_reset_enabled'?$db->enabled:($key==='password_reset_schema'?$db->ready:'');}

$checks=0;function verify(bool $test,string $label):void{global $checks;$checks++;if(!$test)throw new RuntimeException($label);}
$db=new ResetUpgradePDO();PasswordReset::migrate($db);$sql=implode("\n",$db->sql);verify(str_contains($sql,'GET_LOCK'),'migration serialized');verify(str_contains($sql,'CREATE TABLE IF NOT EXISTS password_resets'),'idempotent reset table');verify(str_contains($sql,'CREATE TABLE IF NOT EXISTS password_reset_limits'),'idempotent rate table');verify(str_contains($sql,"'password_reset_schema','1'"),'ready marker written');verify(str_contains(end($db->sql),'RELEASE_LOCK'),'lock released');verify(!preg_match('/\b(DROP|DELETE|TRUNCATE|ALTER)\b/',$sql),'no destructive migration');
$db->sql=[];PasswordReset::migrate($db);verify(count($db->sql)===5,'repeat migration same bounded SQL');
$db=new ResetUpgradePDO();$db->fail=true;$failed=false;try{PasswordReset::migrate($db);}catch(RuntimeException){$failed=true;}verify($failed,'DDL failure propagated');verify(str_contains(end($db->sql),'RELEASE_LOCK'),'failure releases lock');verify(!str_contains(implode("\n",$db->sql),'INSERT INTO security_settings'),'no ready marker on failed migration');
$db=new ResetUpgradePDO();$db->locked=false;$failed=false;try{PasswordReset::migrate($db);}catch(RuntimeException){$failed=true;}verify($failed&&count($db->sql)===1,'lock contention performs no DDL');
$db=new ResetUpgradePDO();PasswordReset::initialize($db);verify($db->ready==='1','anonymous recovery initializes missing schema');$count=count($db->sql);PasswordReset::initialize($db);verify(count($db->sql)===$count,'ready recovery does not repeat DDL');
$db=new ResetUpgradePDO();$db->enabled='0';PasswordReset::initialize($db);verify(count($db->sql)===0,'explicit disabled recovery does not auto-upgrade');
echo "PASS: $checks password reset migration assertions (SQL recording, not live MySQL DDL)\n";

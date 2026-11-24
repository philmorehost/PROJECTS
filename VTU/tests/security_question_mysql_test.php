<?php
if(getenv('BC_QUESTION_CI')!=='1')throw new RuntimeException('Disposable CI only');
set_exception_handler(function($e){echo '::error::'.$e->getMessage().' line '.$e->getLine()."\n";exit(1);});
$edition=getenv('BC_TEST_EDITION')?:'SAAS';if(!in_array($edition,['SAAS','NON-SAAS'],true))throw new RuntimeException('edition');
$base=__DIR__.'/../DGV7.0-'.$edition.'/';
require $base.'func/bc-security-question-policy.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);$db=$connection_server=new mysqli('127.0.0.1','root','disposable-ci-only','',3306);$name='bc_question_'.bin2hex(random_bytes(6));$db->query("CREATE DATABASE `$name`");$db->select_db($name);$n=0;
function qm($ok,$msg){$GLOBALS['n']++;if(!$ok)throw new RuntimeException($msg);}
// Execute the actual prepared-query helpers and endpoint dispatch against real SQL.
$src=file_get_contents($base.'func/bc-stepup.php');foreach(['bc_q','bc_q_row'] as $fn){$start=strpos($src,'    function '.$fn.'(');$open=strpos($src,'{',$start);$depth=1;$end=$open+1;while($depth){if($src[$end]==='{')$depth++;if($src[$end]==='}')$depth--;$end++;}eval(substr($src,$start,$end-$start));}
function sq_json(array $data):void{$GLOBALS['response']=$data;}
function bc_verify_password($plain,$hash){return password_verify($plain,$hash);}
function getVendorOption($vid,$key,$default=''){$row=bc_q_row('SELECT setting_value FROM sas_settings WHERE vendor_id=? AND setting_name=?',[$vid,$key]);return $row['setting_value']??$default;}
try{
$db->query('CREATE TABLE sas_users(id INT PRIMARY KEY,vendor_id INT,username VARCHAR(60),security_quest VARCHAR(20),security_answer VARCHAR(60),password VARCHAR(255)) ENGINE=InnoDB');
$db->query('CREATE TABLE sas_security_quests(id INT PRIMARY KEY,quest VARCHAR(255)) ENGINE=InnoDB');$db->query("INSERT INTO sas_security_quests VALUES(1,'Test question?')");
$db->query('CREATE TABLE sas_settings(vendor_id INT,setting_name VARCHAR(100),setting_value TEXT,UNIQUE(vendor_id,setting_name)) ENGINE=InnoDB');
$hash=password_hash('Fixture password',PASSWORD_DEFAULT);$stmt=$db->prepare("INSERT INTO sas_users VALUES(2,7,'same','','',?),(3,8,'same','1','untouched',?)");$stmt->bind_param('ss',$hash,$hash);$stmt->execute();
$src=file_get_contents($base.'web/api/security-quest.php');$dispatch=substr($src,strpos($src,'$has_quest ='));$dispatch=substr($dispatch,0,strpos($dispatch,'mysqli_close('));
$run=function($act,$input=[])use($db,$dispatch,$connection_server){$vendor_id=7;$user=$db->query('SELECT * FROM sas_users WHERE id=2')->fetch_assoc();$data=$input;$action=$act;$GLOBALS['response']=null;eval($dispatch);return $GLOBALS['response'];};
$r=$run('get');qm($r['status']==='success'&&!$r['has_quest']&&count($r['quest_bank'])===1,'setup catalogue');
qm($run('set',['quest_id'=>'99','answer'=>'answer'])['status']==='failed','invalid question rejected');
qm($run('set',['quest_id'=>'1','answer'=>'Answer'])['status']==='success','setup stored');
qm($db->query('SELECT security_answer FROM sas_users WHERE id=3')->fetch_assoc()['security_answer']==='untouched','same username other tenant unchanged');
qm($run('get')['question']==='Test question?','existing question loads');qm($run('verify',['answer'=>'ANSWER'])['status']==='success','normalized verification');qm($run('verify',['answer'=>'wrong'])['status']==='failed','wrong answer refused');
qm($run('reset',['quest_id'=>'1','answer'=>'reset','password'=>'wrong'])['status']==='failed','reset password required');qm($run('reset',['quest_id'=>'1','answer'=>'reset','password'=>'Fixture password'])['status']==='success','bcrypt reset works');
$db->query("INSERT INTO sas_settings VALUES(7,'security_question_policy','{\"web\":false,\"app\":true}'),(8,'security_question_policy','{\"web\":true,\"app\":false}')");qm(!bc_security_question_policy(7)['web']&&bc_security_question_policy(7)['app'],'separate tenant web/app policy');qm(bc_security_question_policy(8)['web']&&!bc_security_question_policy(8)['app'],'other tenant independent');
$db->query("UPDATE sas_settings SET setting_value='invalid' WHERE vendor_id=7");qm(bc_security_question_policy(7)===['web'=>true,'app'=>true],'malformed fails enabled');
$db->query('DELETE FROM sas_security_quests');qm($run('get')['status']==='failed','empty catalogue explicit error');
echo "$n $edition native MySQL security question dispatch checks passed\n";
}finally{$db->query("DROP DATABASE `$name`");$db->close();}

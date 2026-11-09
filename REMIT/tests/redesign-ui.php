<?php
declare(strict_types=1);
require __DIR__.'/church-portal.php';require __DIR__.'/../app/UpdateManager.php';
$dir=$argv[1] ?? sys_get_temp_dir().'/remit-redesign';if(!is_dir($dir))mkdir($dir,0700,true);
$db->exec('CREATE TABLE security_ips(ip_address TEXT,whitelisted INTEGER,clean_sessions INTEGER,blocked_until TEXT,updated_at TEXT);ALTER TABLE users ADD is_suspended INTEGER DEFAULT 0;UPDATE officer_profiles SET account_ciphertext=\'\'');
$fixtureRoot=realpath(__DIR__.'/..');$appDir=$fixtureRoot.'/app';mkdir(ROOT);symlink($appDir,ROOT.'/app');$source=file_get_contents($fixtureRoot.'/index.php');
$start=strpos($source,'<!doctype html',strpos($source,'$flash='));$shell=str_replace('ROOT.','$fixtureRoot.',substr($source,$start));
$title='TEST PROVINCE — FICTIONAL DATA';$flash='';$error='';$due=10000000;$paid=7500000;$compliance=75;$totals=['reports'=>4,'verified'=>3];$ids=[1];$placeholders='?';$rows=[];$portalReady=true;
$nodes=$db->query("SELECT h.*,p.node_name parent_name,p.node_type parent_type,CASE WHEN h.node_type='zone' THEN h.node_name WHEN p.node_type='zone' THEN p.node_name ELSE z.node_name END zone_name FROM church_hierarchy h LEFT JOIN church_hierarchy p ON h.parent_id=p.id LEFT JOIN church_hierarchy z ON p.parent_id=z.id")->fetchAll();
$_GET=[];$_POST=[];$_SERVER['REQUEST_METHOD']='GET';
foreach(['overview','remittances','hierarchy','settings','security','profile'] as $page){$user=['id'=>$page==='profile'?1:100,'role'=>$page==='profile'?'parish_officer':'provincial_admin','full_name'=>'Test Officer','email'=>'test@example.org','hierarchy_id'=>1];$admin=$user['role']==='provincial_admin';$treasurer=$admin;ob_start();eval('?>'.$shell);$html=ob_get_clean();file_put_contents($dir.'/'.$page.'.html',$html);}
$db->exec("UPDATE security_settings SET setting_value='8000' WHERE setting_key='levy_basis_points'");
// Representative routes exercise real own-church forms, not admin-only views.
$db->exec("INSERT INTO church_hierarchy(id,node_name,node_type,parent_id) VALUES(10,'AREA WORSHIP CENTRE','area',3);UPDATE church_hierarchy SET parent_id=10 WHERE id=1;INSERT INTO remittance_ledgers(parish_id,provincial_levy_due,amount_paid,status,payment_reference,gross_income,period_month,period_year) VALUES(3,'800.00','0.00','pending','TEST-ZONE','1000.00',8,2026),(10,'800.00','0.00','pending','TEST-AREA','1000.00',8,2026)");
foreach(['parish_officer'=>1,'area_rep'=>10,'zonal_rep'=>3] as $role=>$unit){
 $user=['id'=>1,'role'=>$role,'full_name'=>'TEST REPRESENTATIVE','email'=>'test@example.org','hierarchy_id'=>$unit];$admin=false;$treasurer=false;$ids=financialScope($db,$user);$placeholders=implode(',',array_fill(0,count($ids),'?'));
 foreach(['overview','remittances'] as $page){ob_start();eval('?>'.$shell);file_put_contents($dir.'/'.$role.'-'.$page.'.html',ob_get_clean());}
}
// Render actual public templates with only service state supplied by fictional fixtures.
$page='register';$enabled=true;$submitted=false;$churches=ChurchPortal::directory($db);$publicSource=file_get_contents($appDir.'/register.php');$htmlSource=str_replace('__DIR__','$appDir',substr($publicSource,strpos($publicSource,'<!doctype html')));ob_start();eval('?>'.$htmlSource);file_put_contents($dir.'/register.html',ob_get_clean());
$page='login';$start=strpos($source,'<!doctype html');$end=strpos($source,'</html>',$start)+7;$login=str_replace('ROOT.','$fixtureRoot.',substr($source,$start,$end-$start));ob_start();eval('?>'.$login);file_put_contents($dir.'/login.html',ob_get_clean());
ob_start();require $appDir.'/installer.php';file_put_contents($dir.'/setup.html',ob_get_clean());
unlink(ROOT.'/app');rmdir(ROOT);
echo "PASS: fifteen production page/representative fixtures rendered\n";

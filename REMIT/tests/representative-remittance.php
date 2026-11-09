<?php
declare(strict_types=1);
ob_start();require __DIR__.'/church-portal.php';$base=ob_get_clean();
$checks=0;
mkdir(ROOT.'/config',0700,true);$key='TEST-KEY';
LicenseManager::writeJson(ROOT.'/config/license.json',['key'=>$key,'domain'=>'church.example']);LicenseManager::writeJson(ROOT.'/config/license-cache.json',['fingerprint'=>hash('sha256',$key."\0".'church.example'),'checked_at'=>time(),'valid'=>true]);
$db->exec("INSERT INTO church_hierarchy(id,node_name,node_type,parent_id) VALUES(10,'AREA WORSHIP CENTRE','area',3),(11,'CHILD PARISH','parish',10),(12,'OTHER ZONE','zone',NULL);UPDATE church_hierarchy SET parent_id=10 WHERE id=1;");
$before=$db->query('SELECT COUNT(*) FROM remittance_ledgers')->fetchColumn();
$reportIds=[];$reviewer=$admin;
foreach(['zonal_rep'=>3,'area_rep'=>10,'parish_officer'=>11] as $role=>$unit){
 $db->prepare('INSERT INTO users(id,hierarchy_id,full_name,email,role) VALUES(?,?,?,?,?)')->execute([200+$unit,$unit,'TEST REP',$role.'@example.org',$role]);
 $rep=['id'=>200+$unit,'hierarchy_id'=>$unit,'role'=>$role,'email'=>$role.'@example.org'];$ids=financialScope($db,$rep);
 check(in_array($unit,$ids,true),'financial scope includes own unit');check(!in_array(12,$ids,true),'unrelated zone excluded');
 if($role==='zonal_rep')check(in_array(10,$ids,true) && in_array(11,$ids,true),'zone supervises area and parish financial reports');
 if($role==='area_rep')check(in_array(11,$ids,true) && !in_array(3,$ids,true),'area scope excludes its parent zone');
 $_POST=['csrf'=>csrf(),'action'=>'report','gross'=>'1000.00','month'=>6,'year'=>date('Y'),'parish_id'=>12];
 action($db,$rep,$ids);$ledger=$db->query('SELECT * FROM remittance_ledgers ORDER BY id DESC LIMIT 1')->fetch();$reportIds[$role]=(int)$ledger['id'];
 check((int)$ledger['parish_id']===$unit,'report always binds own church, ignoring forged unit');
 check($ledger['provincial_levy_due']==='0.00','configured allocation applies to all levels');
 refuses(fn()=>action($db,$rep,$ids),'same church/month duplicate rejected');
 $_POST['month']=7;$db->exec("UPDATE security_settings SET setting_value='8000' WHERE setting_key='levy_basis_points'");action($db,$rep,$ids);$ledger=$db->query('SELECT * FROM remittance_ledgers ORDER BY id DESC LIMIT 1')->fetch();
 check($ledger['provincial_levy_due']==='800.00','exact 80 percent allocation retained');
 $notification=['ledger_id'=>$ledger['id'],'amount'=>'800.00','transfer_reference'=>'REP-'.$unit,'transfer_date'=>date('Y-m-d')];ChurchPortal::submitPayment($db,$rep,$notification);
 check($db->query('SELECT amount_paid FROM remittance_ledgers WHERE id='.(int)$ledger['id'])->fetchColumn()==='0.00','notification never settles payment');
 $bad=$notification;$bad['ledger_id']=1;$bad['transfer_reference']='FORGED-'.$unit;refuses(fn()=>ChurchPortal::submitPayment($db,$rep,$bad),'other church payment refused');
 refuses(fn()=>ChurchPortal::submitPayment($db,$rep,$notification),'pending deposit prevents excess payment');
 $db->exec("UPDATE security_settings SET setting_value='0' WHERE setting_key='levy_basis_points'");
 // Production views expose the form, short CTA and profile for each representative.
 $user=$rep;$admin=false;$treasurer=false;$page='remittances';$rows=[$ledger];$placeholders=implode(',',array_fill(0,count($ids),'?'));$portalReady=true;
 ob_start();require __DIR__.'/../app/offering-view.php';$form=ob_get_clean();check(str_contains($form,'id="income-form"') && str_contains($form,'Start remittance'),'all representative types get offering form');
 ob_start();require __DIR__.'/../app/payments-view.php';$payments=ob_get_clean();check(str_contains($payments,'Awaiting treasury confirmation') && !str_contains($payments,'name="ledger_id"'),'fully pending own payment is not offered twice');
 ob_start();require __DIR__.'/../app/dashboard-actions.php';$dashboard=ob_get_clean();check(str_contains($dashboard,'Start remittance'),'start remittance visible on dashboard');
 $nid=(int)$db->query('SELECT id FROM payment_notifications WHERE ledger_id='.(int)$ledger['id'].' ORDER BY id DESC LIMIT 1')->fetchColumn();
 $review=['notification_id'=>$nid,'decision'=>'approved','pin'=>'1234'];
 refuses(fn()=>ChurchPortal::reviewPayment($db,$rep,$review),'representative cannot self-confirm payment');
 ChurchPortal::reviewPayment($db,$reviewer,$review);check($db->query('SELECT amount_paid FROM remittance_ledgers WHERE id='.(int)$ledger['id'])->fetchColumn()==='800.00','treasury confirms own church payment at every level');
 refuses(fn()=>ChurchPortal::reviewPayment($db,$reviewer,$review),'treasury approval cannot replay');

}
$all=financialScope($db,['role'=>'provincial_admin']);check(in_array(3,$all,true) && in_array(10,$all,true) && in_array(11,$all,true),'treasury scope includes every level');
refuses(fn()=>remittingUnit($db,['role'=>'area_rep','hierarchy_id'=>3]),'role/unit type mismatch blocked');refuses(fn()=>remittingUnit($db,['role'=>'zonal_rep','hierarchy_id'=>9999]),'missing church assignment blocked');
refuses(fn()=>remittingUnit($db,['role'=>'provincial_admin','hierarchy_id'=>3]),'admin cannot submit as church representative');
check((int)$db->query('SELECT COUNT(*) FROM remittance_ledgers')->fetchColumn()===(int)$before+6,'separate reports for each worshipping church');
foreach(glob(ROOT.'/config/*') as $file)unlink($file);rmdir(ROOT.'/config');rmdir(ROOT);
echo $base;echo "PASS: $checks representative remittance assertions (SQLite harness, not MySQL concurrency)\n";

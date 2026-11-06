<?php
declare(strict_types=1);
// Reuse the existing SQL compatibility harness and its full finance/portal regressions.
require __DIR__.'/church-portal.php';
$checks=0;
$db->exec("INSERT INTO church_hierarchy(id,node_name,node_type,parent_id,parish_code) VALUES(10,'Living Hope Parish','parish',11,'LHP'),(11,'Hope Area','area',3,NULL),(12,'NEW LIFE ZONE','zone',NULL,NULL)");
function applyOfficer(array $changes):void{global $db,$input;$db->exec('UPDATE registration_limits SET attempts=0');ChurchPortal::register($db,array_replace($input,['bank_name'=>'','account_name'=>'','account_number'=>''],$changes));}
function registration(string $email):array{global $db;$s=$db->prepare('SELECT * FROM church_registrations WHERE email=?');$s->execute([$email]);return $s->fetch()?:[];}
$total=(int)$db->query('SELECT COUNT(*) FROM church_hierarchy')->fetchColumn();
applyOfficer(['email'=>'existing@example.org','parish_id'=>10]);$row=registration('existing@example.org');check((int)$row['parish_id']===10 && $row['requested_church_name']===null,'existing parish reused');check($row['account_ciphertext']==='' && $row['account_last4']==='','bank not needed or stored');
applyOfficer(['email'=>'normalized@example.org','parish_id'=>'','church_unlisted'=>'1','church_name'=>' living--HOPE   parish ','church_type'=>'area']);$row=registration('normalized@example.org');check((int)$row['parish_id']===10 && $row['requested_church_name']===null,'case/spacing/punctuation exact name reused across levels');
applyOfficer(['email'=>'missing@example.org','parish_id'=>'','church_unlisted'=>'1','church_name'=>' new dawn parish ','church_type'=>'parish']);$row=registration('missing@example.org');check($row['parish_id']===null && $row['requested_church_name']==='NEW DAWN PARISH' && $row['requested_church_type']==='parish','unlisted request normalized to CAPS');check((int)$db->query('SELECT COUNT(*) FROM church_hierarchy')->fetchColumn()===$total,'registration never inserts a church');
$id=(int)$row['id'];refuses(fn()=>ChurchPortal::reviewRegistration($db,$admin,$id,'approved'),'unlinked approval blocked');check(registration('missing@example.org')['status']==='pending','failed approval rolls back');
refuses(fn()=>ChurchPortal::reviewRegistration($db,$admin,$id,'approved',99999),'missing resolved church blocked');
ChurchPortal::reviewRegistration($db,$admin,$id,'approved',10);$s=$db->query("SELECT hierarchy_id,role FROM users WHERE email='missing@example.org'")->fetch();check((int)$s['hierarchy_id']===10 && $s['role']==='parish_officer','admin can resolve request to existing parish');check(registration('missing@example.org')['requested_church_name']==='NEW DAWN PARISH','submitted name retained for history');
foreach([11=>'area_rep',12=>'zonal_rep'] as $unit=>$role){$email=$role.'@example.org';applyOfficer(['email'=>$email,'parish_id'=>$unit,'role'=>'provincial_admin']);$row=registration($email);ChurchPortal::reviewRegistration($db,$admin,(int)$row['id'],'approved');$user=$db->query("SELECT * FROM users WHERE email='$email'")->fetch();check($user['role']===$role && (int)$user['hierarchy_id']===$unit,'role derived only from verified level');if($unit===11)check(in_array(10,scope($db,$user),true) && !in_array(1,scope($db,$user),true),'area scope includes only descendant parishes');}
foreach([
 ['parish_id'=>99999],['parish_id'=>''],['parish_id'=>'','church_unlisted'=>'1','church_name'=>'','church_type'=>'zone'],
 ['parish_id'=>'','church_unlisted'=>'1','church_name'=>'---','church_type'=>'zone'],
 ['parish_id'=>'','church_unlisted'=>'1','church_name'=>str_repeat('A',151),'church_type'=>'zone'],
 ['parish_id'=>'','church_unlisted'=>'1','church_name'=>'New Dawn','church_type'=>'province'],
 ['parish_id'=>'','church_unlisted'=>'1','church_name'=>"bad\x00name",'church_type'=>'parish'],
 ['parish_id'=>'','church_unlisted'=>'1','church_name'=>['x'],'church_type'=>'parish']
] as $changes)refuses(fn()=>applyOfficer(array_merge(['email'=>'invalid@example.org'],$changes)),'invalid church choice rejected');
$db->exec("INSERT INTO church_hierarchy(id,node_name,node_type,parent_id) VALUES(20,'LIVING HOPE PARISH','parish',12)");
refuses(fn()=>applyOfficer(['email'=>'ambiguous@example.org','parish_id'=>'','church_unlisted'=>'1','church_name'=>'Living Hope Parish','church_type'=>'parish']),'ambiguous exact names require explicit selection');
applyOfficer(['email'=>'ambiguous@example.org','parish_id'=>20]);check((int)registration('ambiguous@example.org')['parish_id']===20,'explicit selection disambiguates identical names');
applyOfficer(['email'=>'typo@example.org','parish_id'=>'','church_unlisted'=>'1','church_name'=>'LIVNG HOPE PARISH','church_type'=>'parish']);check(registration('typo@example.org')['parish_id']===null,'fuzzy match never silently assigns wrong church');
applyOfficer(['email'=>'typo@example.org','parish_id'=>10]);check((int)$db->query("SELECT COUNT(*) FROM church_registrations WHERE email='typo@example.org'")->fetchColumn()===1,'repeated email does not duplicate application');
ChurchPortal::reviewRegistration($db,$admin,(int)registration('typo@example.org')['id'],'rejected');check(registration('typo@example.org')['status']==='rejected','unlisted application can be rejected without linking');
applyOfficer(['email'=>'legacy@example.org','parish_id'=>1]);$legacy=registration('legacy@example.org');$db->prepare('UPDATE church_registrations SET bank_name=?,account_name=?,account_ciphertext=?,account_last4=? WHERE id=?')->execute(['Legacy Bank','Legacy Church','legacy-encrypted-value','6789',$legacy['id']]);ChurchPortal::reviewRegistration($db,$admin,(int)$legacy['id'],'approved');check($db->query("SELECT p.account_ciphertext FROM officer_profiles p JOIN users u ON u.id=p.user_id WHERE u.email='legacy@example.org'")->fetchColumn()==='legacy-encrypted-value','legacy pending account ciphertext preserved on approval');
$keys=array_keys(ChurchPortal::directory($db)[0]);check(!in_array('phone_number',$keys,true) && !in_array('email',$keys,true) && !in_array('contact_person',$keys,true),'discovery data excludes private details');
applyOfficer(['email'=>'pending-unlisted@example.org','parish_id'=>'','church_unlisted'=>'1','church_name'=>'UNLISTED GRACE AREA','church_type'=>'area']);
applyOfficer(['email'=>'native-choice@example.org','parish_id'=>1,'church_unlisted'=>'1','church_name'=>'NATIVE NEW CHURCH','church_type'=>'zone']);check(registration('native-choice@example.org')['parish_id']===null,'explicit unlisted mode overrides stale native dropdown choice');
refuses(fn()=>applyOfficer(['email'=>['bad']]),'non-scalar account input rejected');
// Render production form/queue with strict warnings and hostile text; never retain passwords.
$db->exec("INSERT INTO church_hierarchy(id,node_name,node_type) VALUES(30,'<script>alert(1)</script>','zone')");
$churches=ChurchPortal::directory($db);$_POST=['full_name'=>'Example Officer','password'=>'secret-never-echo'];
set_error_handler(function(int $s,string $m,string $f,int $l):never{throw new ErrorException($m,0,$s,$f,$l);});
ob_start();require __DIR__.'/../app/registration-form.php';$html=ob_get_clean();check(!str_contains($html,'<script>') && str_contains($html,'&lt;script&gt;'),'church labels and submitted names escaped');check(!str_contains($html,'secret-never-echo'),'passwords never repopulated');check(!str_contains($html,'name="bank_name"') && !str_contains($html,'name="account_number"'),'officer form has no bank fields');check(str_contains($html,'Area') && str_contains($html,'Zone'),'all three levels discoverable');
if(isset($argv[1]))file_put_contents($argv[1],'<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><body class="setup registration-page"><main class="setup-card registration-card"><h1>Create your officer account</h1>'.$html.'</main></body></html>');
$user=$admin;$portalReady=true;ob_start();require __DIR__.'/../app/registrations-view.php';$queue=ob_get_clean();check(str_contains($queue,'name="resolved_unit_id"'),'admin queue includes unlinked applications and verified church selector');restore_error_handler();
echo "PASS: $checks officer registration assertions (SQLite compatibility harness)\n";

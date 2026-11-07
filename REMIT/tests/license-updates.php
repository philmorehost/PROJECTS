<?php
declare(strict_types=1);
require __DIR__.'/../app/ManagerClient.php';
require __DIR__.'/../app/LicenseManager.php';
require __DIR__.'/../app/UpdateManager.php';
$checks = 0;
function test(bool $result, string $label): void { global $checks; $checks++; if (!$result) throw new RuntimeException('FAIL: '.$label); }
function rejects(Closure $call, string $label): void { $rejected = false; try { $call(); } catch (RuntimeException) { $rejected = true; } test($rejected, $label); }
function removeTree(string $dir): void { foreach (scandir($dir) as $entry) { if ($entry==='.' || $entry==='..') continue; $path=$dir.'/'.$entry; if(is_dir($path)&&!is_link($path))removeTree($path);else unlink($path); } rmdir($dir); }
$root = sys_get_temp_dir().'/remit-tests-'.bin2hex(random_bytes(8));
mkdir($root.'/config',0700,true);mkdir($root.'/storage',0700,true);mkdir($root.'/app',0700,true);
try {
 test(LicenseManager::domain('Church.Example:443')==='church.example','hostname normalization');
 test(LicenseManager::domain('church.example.')==='church.example','trailing dot');
 foreach (['https://church.example','bad/host','evil@host','host:abc','evil..host','evil host'] as $host) {
  rejects(fn()=>LicenseManager::domain($host),'invalid domain '.$host);
 }
 $calls=0; $status=1; $offline=false;
 $transport=function(string $endpoint,array $fields)use(&$calls,&$status,&$offline):array {
  $calls++;test($endpoint==='/api.php','documented licence endpoint');
  test($fields===['key'=>'TEST-PRIVATE-KEY','domain'=>'church.example'],'documented form fields');
  if($offline)throw new RuntimeException('Simulated timeout');return ['status'=>$status];
 };
 $license=new LicenseManager($root,$transport);
 $license->activate('TEST-PRIVATE-KEY','church.example');
 test($license->credentials()['domain']==='church.example','activation persisted');
 test($license->check('church.example')['valid']===true && $calls===1,'one-hour cache');
 test($license->check('different.example')['valid']===false,'domain binding');
 $status='suspended';test($license->check('church.example',true)['valid']===false,'suspension is not truthy success');
 $offline=true;rejects(fn()=>$license->check('church.example',true),'forced check denies outage');
 $cache=json_decode(file_get_contents($root.'/config/license-cache.json'),true);
 $cache['checked_at']=time()-120;$cache['valid']=false;LicenseManager::writeJson($root.'/config/license-cache.json',$cache);
 rejects(fn()=>$license->check('church.example'),'rejected key never gets outage grace');
 $cache['valid']=true;$cache['checked_at']=time()-4000;LicenseManager::writeJson($root.'/config/license-cache.json',$cache);
 test($license->check('church.example')['valid']===true,'recent valid cache gets outage grace');
 $cache['checked_at']=time()-86401;LicenseManager::writeJson($root.'/config/license-cache.json',$cache);
 rejects(fn()=>$license->check('church.example'),'grace expires at 24 hours');
 $offline=false;$status=1;$license->activate('TEST-PRIVATE-KEY','church.example');
 $status=true;rejects(fn()=>$license->check('church.example',true),'boolean malformed status is rejected');$status=1;
 ManagerClient::downloadUrl('https://manager.pmhserver.name.ng/download.php?token='.str_repeat('a',64));test(true,'documented download URL accepted');
 foreach(['http://manager.pmhserver.name.ng/download.php?token=abc','https://evil.example/download.php?token='.str_repeat('a',64),'https://manager.pmhserver.name.ng.evil.example/download.php?token='.str_repeat('a',64),'https://manager.pmhserver.name.ng/download.php?token='.str_repeat('a',64).'&url=x','https://user@manager.pmhserver.name.ng/download.php?token='.str_repeat('a',64)] as $url)rejects(fn()=>ManagerClient::downloadUrl($url),'untrusted download URL');
 foreach(['../index.php','/index.php','app/../../x','app\\x.php','app//x.php','app/./x.php'] as $path) rejects(fn()=>UpdateManager::safePath($path),'unsafe ZIP path');
 foreach(['config/database.php','config/license.json','storage/receipts/test.php','.git/config','public/assets/.user.ini'] as $path)test(!UpdateManager::allowed($path),'protected payload '.$path);
 test(UpdateManager::allowed('app/LicenseManager.php'),'application code accepted');
 if(!class_exists('ZipArchive'))throw new RuntimeException('Tests require ZIP extension.');
 $payload=['manifest.json'=>json_encode(['version'=>'0.2.0','database_queries'=>[],'files_to_delete'=>[]]),'version.json'=>json_encode(['product'=>'remit','version'=>'0.2.0']),'index.php'=>'<?php echo "new";','app/bootstrap.php'=>'<?php // new bootstrap'];
 $make=function(string $path,array $files,string $prefix=''):void{$zip=new ZipArchive();$zip->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE);foreach($files as $name=>$body)$zip->addFromString($prefix.$name,$body);$zip->close();};
 $updater=new UpdateManager($root,null,$license);$zipPath=$root.'/test.zip';
 $make($zipPath,$payload);test(count($updater->inspect($zipPath,'0.2.0'))===3,'root package accepted');
 $make($zipPath,$payload,'REMIT/');test(count($updater->inspect($zipPath,'0.2.0'))===3,'REMIT wrapper accepted');
 $make($zipPath,$payload,'PROJECTS-main/REMIT/');$z=new ZipArchive();$z->open($zipPath);$z->addFromString('PROJECTS-main/LM/index.php','sibling');$z->close();test(count($updater->inspect($zipPath,'0.2.0'))===3,'GitHub wrapper isolates sibling projects');
 $auto=$payload;unset($auto['manifest.json']);$make($zipPath,$auto,'PROJECTS-main/REMIT/');$z=new ZipArchive();$z->open($zipPath);$z->addFromString('manifest.json',$payload['manifest.json']);$z->close();test(count($updater->inspect($zipPath,'0.2.0'))===3,'LM root auto-manifest accepted for GitHub subtree');
 $bad=$payload;$bad['../outside.php']='bad';$make($zipPath,$bad);rejects(fn()=>$updater->inspect($zipPath,'0.2.0'),'zip-slip rejected');
 $bad=$payload;$bad['config/database.php']='bad';$make($zipPath,$bad);rejects(fn()=>$updater->inspect($zipPath,'0.2.0'),'configuration overwrite rejected');
 $bad=$payload;$bad['manifest.json']=json_encode(['version'=>'0.2.0','database_queries'=>['DROP TABLE users']]);$make($zipPath,$bad);rejects(fn()=>$updater->inspect($zipPath,'0.2.0'),'SQL is not executed');
 $bad=$payload;$bad['manifest.json']=json_encode(['version'=>'0.2.0','files_to_delete'=>['index.php']]);$make($zipPath,$bad);rejects(fn()=>$updater->inspect($zipPath,'0.2.0'),'deletions refused');
 $bad=$payload;$bad['version.json']=json_encode(['product'=>'other','version'=>'0.2.0']);$make($zipPath,$bad);rejects(fn()=>$updater->inspect($zipPath,'0.2.0'),'wrong product rejected');
 $make($zipPath,$payload);rejects(fn()=>$updater->inspect($zipPath,'0.3.0'),'version mismatch rejected');
 $z=new ZipArchive();$z->open($zipPath);$z->addFromString('app/link.php','/tmp/target');$z->setExternalAttributesName('app/link.php',ZipArchive::OPSYS_UNIX,0120777<<16);$z->close();rejects(fn()=>$updater->inspect($zipPath,'0.2.0'),'symlink ZIP entry rejected');
 // End-to-end file install with only the remote licence response mocked; no real external calls.
 $id=str_repeat('b',24);mkdir($root.'/storage/updates/'.$id,0700,true);
 $archive=$root.'/storage/updates/'.$id.'/package.zip';$make($archive,$payload);
 file_put_contents($root.'/version.json',json_encode(['product'=>'remit','version'=>'0.1.0']));
 file_put_contents($root.'/index.php','old index');file_put_contents($root.'/app/bootstrap.php','old bootstrap');file_put_contents($root.'/config/database.php','private credentials');
 LicenseManager::writeJson($root.'/storage/update-pending.json',['id'=>$id,'version'=>'0.2.0','checksum'=>hash_file('sha256',$archive)]);
 $_SERVER['HTTP_HOST']='church.example';
 $pending=json_decode(file_get_contents($root.'/storage/update-pending.json'),true);
 $badPending=$pending;$badPending['checksum']=str_repeat('0',64);LicenseManager::writeJson($root.'/storage/update-pending.json',$badPending);
 rejects(fn()=>$updater->install(),'changed checksum blocks installation');
 test(file_get_contents($root.'/index.php')==='old index','checksum failure touches no code');
 test(!is_file($root.'/storage/maintenance.json'),'checksum failure does not strand maintenance');
 LicenseManager::writeJson($root.'/storage/update-pending.json',$pending);
 $message=$updater->install();
 test(str_contains($message,'0.2.0 installed'),'file install succeeds');
 test(file_get_contents($root.'/index.php')===$payload['index.php'],'new code written');
 test(file_get_contents($root.'/storage/updates/'.$id.'/backup/index.php')==='old index','previous code backed up');
 test(file_get_contents($root.'/config/database.php')==='private credentials','database config preserved');
 test($license->credentials()['key']==='TEST-PRIVATE-KEY','licence credentials preserved');
 test(!is_file($root.'/storage/maintenance.json'),'maintenance removed after success');
 test(!is_file($root.'/storage/update-pending.json'),'installed release cannot replay');
 echo "PASS: $checks licence/update assertions\n";
} finally { removeTree($root); }

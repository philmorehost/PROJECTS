<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/app/bootstrap.php';
use KB\{Settings,Mail};
if (!KB\installed() || !KB\config('local_preview',false) || !str_ends_with(KB\config('db')['name'],'_test')) { fwrite(STDERR,"Disposable local *_test configuration required.\n"); exit(1); }
KB\DB::run('DELETE FROM mail_queue');
$port=(int)getenv('KB_TEST_SMTP_PORT');
if ($port<1 || $port>65535) exit(1);
foreach (['smtp_enabled'=>true,'smtp_host'=>'127.0.0.1','smtp_port'=>$port,'smtp_security'=>'none','smtp_username'=>'','smtp_secret'=>KB\Security::seal(['value'=>'app-password-never-print']),'from_email'=>'notifications@example.test','from_name'=>'KINGDOMBUILDERS Test'] as $key=>$value) Settings::put($key,$value);
Mail::queue('recipient@example.test','Test notification · ñ',"First line\n.Second line\nUnicode: Ẹ̀kọ́",'smtp-fixture:'.getenv('KB_TEST_RUN'));
$stats=Mail::process(1);
// A refused port must produce a safe, actionable error without leaking the password.
Settings::preview(['smtp_port'=>1,'smtp_username'=>'legacy-no-auth']);
$failure=null;
try { Mail::sendTest('recipient@example.test'); }
catch (KB\MailError $e) { $failure=['stage'=>$e->stage,'message'=>$e->getMessage(),'retryable'=>$e->retryable]; }
echo json_encode(['process'=>$stats,'failure'=>$failure]),PHP_EOL;

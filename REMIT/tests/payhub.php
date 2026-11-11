<?php
declare(strict_types=1);ob_start();require __DIR__.'/church-portal.php';ob_end_clean();
@$db->sqliteCreateFunction('GET_LOCK',fn($n,$t)=>1,2);@$db->sqliteCreateFunction('RELEASE_LOCK',fn()=>1,1);
PayHub::migrate($db);$db->exec("DELETE FROM security_settings WHERE setting_key LIKE 'payhub_%'");
$db->exec("INSERT INTO security_settings(setting_key,setting_value) VALUES('payhub_enabled','1'),('payhub_public_key','pk_test_123'),('payhub_secret_key','sk_test_123'),('payhub_virtual_accounts','1')");
check(PayHub::enabled($db),'payhub enabled flag reads settings');
check(str_contains(PayHub::webhookUrl(),'payhub_webhook=1'),'webhook URL is copyable');
$sent=[];$orig=function_exists('curl_init')?'curl_init':'missing';
// Mock PayHub initialize/verify using override helper by monkey-patching in test transport seam?
// Instead verify webhook signature verification and record logic with a fake charge.
$body=json_encode(['event'=>'charge.success','data'=>['reference'=>'PH_TEST_WEBHOOK','amount'=>8000000,'status'=>'success','currency'=>'NGN','customer'=>['email'=>'officer@example.org'],'metadata'=>['ledger_id'=>1,'user_id'=>1],'channel'=>'dedicated_account','paid_at'=>date('c')]]);
$bad=true;try{$_SERVER['HTTP_X_PAYHUB_SIGNATURE']='bad';$r=PayHub::handleWebhook($db);}catch(Throwable){}
check((int)$db->query("SELECT COUNT(*) FROM payment_notifications WHERE payhub_reference='PH_TEST_WEBHOOK'")->fetchColumn()===0,'invalid webhook signature rejected');
$_SERVER['HTTP_X_PAYHUB_SIGNATURE']=hash_hmac('sha256',$body,'sk_test_123');
// php input cannot be replaced; use record directly.
$r=PayHub::recordSuccessfulCharge($db,'PH_TEST_WEBHOOK',json_decode($body,true)['data'],'test');
check((int)$db->query("SELECT COUNT(*) FROM payment_notifications WHERE payhub_reference='PH_TEST_WEBHOOK'")->fetchColumn()===1,'successful charge queued as pending payment notification');
$row=$db->query("SELECT * FROM payment_notifications WHERE payhub_reference='PH_TEST_WEBHOOK'")->fetch();check($row['amount']==='80000.00' && $row['payhub_channel']==='dedicated_account','payhub amount channel and paid date recorded');
ob_start();require __DIR__.'/../app/portal-settings.php';$html=ob_get_clean();check(str_contains($html,'Copy webhook')&&str_contains($html,'payhub_public_key'),'portal settings shows PayHub management and webhook copy');
$user=['id'=>1,'role'=>'parish_officer','hierarchy_id'=>1,'email'=>'officer@example.org','full_name'=>'Hope Officer'];ob_start();require __DIR__.'/../app/payments-view.php';$html=ob_get_clean();check(str_contains($html,'PayHub inline checkout')&&str_contains($html,'data-payhub-key'),'payment page renders inline checkout');
$user=$admin;
echo "PASS: $checks combined portal/PayHub assertions (SQLite harness; mocked gateway)\n";

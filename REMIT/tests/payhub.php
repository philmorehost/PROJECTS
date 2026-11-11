<?php
declare(strict_types=1);ob_start();require __DIR__.'/church-portal.php';ob_end_clean();
@$db->sqliteCreateFunction('GET_LOCK',fn($n,$t)=>1,2);@$db->sqliteCreateFunction('RELEASE_LOCK',fn()=>1,1);
PayHub::migrate($db);$db->exec("DELETE FROM security_settings WHERE setting_key LIKE 'payhub_%'");
$db->exec("INSERT INTO security_settings(setting_key,setting_value) VALUES('payhub_enabled','1'),('payhub_public_key','pk_test_123'),('payhub_secret_key','sk_test_123'),('payhub_virtual_accounts','1'),('payhub_auto_approve','1')");
check(PayHub::enabled($db)&&PayHub::autoApproveEnabled($db),'payhub enabled auto flag reads settings');
check(str_contains(PayHub::webhookUrl(),'payhub_webhook=1'),'webhook URL copyable');
$db->prepare('INSERT INTO remittance_ledgers(parish_id,provincial_levy_due,amount_paid,status,payment_reference,gross_income,period_month,period_year) VALUES(1,?,?,?,?,?,?,?)')->execute(['80000.00','0.00','pending','RMT-PAYHUB-AUTO','100000.00',date('n'),date('Y')]);$autoLedger=(int)$db->lastInsertId();
$_SERVER['HTTP_X_PAYHUB_SIGNATURE']=hash_hmac('sha256',json_encode(['event'=>'charge.success','data'=>['reference'=>'PH_AUTO','amount'=>8000000,'status'=>'success','currency'=>'NGN','customer'=>['email'=>'officer@example.org'],'metadata'=>['ledger_id'=>$autoLedger,'user_id'=>1],'channel'=>'dedicated_account','paid_at'=>date('c')]]),'sk_test_123');
$r=PayHub::recordSuccessfulCharge($db,'PH_AUTO',['reference'=>'PH_AUTO','amount'=>8000000,'status'=>'success','currency'=>'NGN','customer'=>['email'=>'officer@example.org'],'metadata'=>['ledger_id'=>$autoLedger,'user_id'=>1],'channel'=>'dedicated_account','paid_at'=>date('c')],'test');
check((int)$db->query("SELECT COUNT(*) FROM payment_notifications WHERE payhub_reference='PH_AUTO'")->fetchColumn()===1,'successful charge queued');
$reviewer=['id'=>100,'role'=>'provincial_admin','email'=>'admin@example.org'];
ChurchPortal::reviewPayment($db,$reviewer,['notification_id'=>(int)$db->query('SELECT id FROM payment_notifications WHERE payhub_reference=\'PH_AUTO\'')->fetchColumn(),'decision'=>'approved','review_note'=>'Auto-approved by PayHub verified charge/virtual account.','pin'=>'skip','auto'=>'1']);
check($db->query('SELECT status FROM remittance_ledgers WHERE id='.$autoLedger)->fetchColumn()==='verified','payhub successful payment auto-approves when enabled');
ob_start();require __DIR__.'/../app/portal-settings.php';$html=ob_get_clean();check(str_contains($html,'payhub_auto_approve')&&str_contains($html,'Copy webhook'),'portal settings renders auto-approval and webhook copy');
$user=['id'=>1,'role'=>'parish_officer','hierarchy_id'=>1,'email'=>'officer@example.org','full_name'=>'Hope Officer'];ob_start();require __DIR__.'/../app/welcome-guide.php';$guide=ob_get_clean();check(str_contains($guide,'quick-guide-modal')&&str_contains($guide,'data-guide-open'),'quick guide rendered as modal launcher');
ob_start();require __DIR__.'/../app/payments-view.php';$html=ob_get_clean();check(str_contains($html,'payment-tabs')&&str_contains($html,'Bank Transfer')&&str_contains($html,'PayHub Checkout & Virtual Account')&&str_contains($html,'payhub-virtual-box'),'payment page renders tabs and virtual account panel');
echo "PASS: $checks combined portal/PayHub assertions (SQLite harness; mocked gateway)\n";

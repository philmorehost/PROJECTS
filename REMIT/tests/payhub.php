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

// Test DVA fee settlement & gross remittance credit (₦100.00 due, ₦1.60 PayHub fee, ₦98.40 net settled)
$db->prepare('INSERT INTO remittance_ledgers(parish_id,provincial_levy_due,amount_paid,status,payment_reference,gross_income,period_month,period_year) VALUES(1,?,?,?,?,?,?,?)')->execute(['100.00','0.00','pending','RMT-DVA-FEE','200.00',9,2025]);$dvaLedger=(int)$db->lastInsertId();
PayHub::recordSuccessfulCharge($db,'1000049991',[
 'reference'=>'1000049991',
 'amount'=>10000,
 'gateway_amount'=>10000,
 'fee_amount'=>160,
 'settled_amount'=>9840,
 'status'=>'success',
 'domain'=>'test',
 'channel'=>'dedicated_nuban',
 'paid_at'=>date('c'),
 'customer'=>['email'=>'officer@example.org'],
 'metadata'=>['ledger_id'=>$dvaLedger,'checkout_reference'=>'PH_DVA_1']
],'test');
check(PayHub::autoApproveIfEnabled($db,'1000049991','test'),'autoApproveIfEnabled approves DVA payment cleanly');
$dvaNote=$db->query("SELECT * FROM payment_notifications WHERE payhub_reference='1000049991'")->fetch();
check(Money::cents((string)$dvaNote['amount'])===10000 && Money::cents((string)$dvaNote['payhub_fee'])===160 && Money::cents((string)$dvaNote['payhub_net_amount'])===9840,'DVA payment credits full 100.00 NGN and tracks 1.60 fee & 98.40 net');
$dvaLedgerRow=$db->query('SELECT status,amount_paid FROM remittance_ledgers WHERE id='.$dvaLedger)->fetch();
check($dvaLedgerRow['status']==='verified' && Money::cents((string)$dvaLedgerRow['amount_paid'])===10000,'DVA payment verifies church ledger at full 100.00 NGN');

// Test card payment with fee passed on top (₦100.00 requested, ₦101.53 charged, ₦1.53 fee, ₦100.00 settled)
$cardAmt=PayHub::extractAmounts(['amount'=>10153,'requested_amount'=>10000,'gateway_amount'=>10153,'fee_amount'=>153,'settled_amount'=>10000],10000);
check($cardAmt['credit_kobo']===10000 && $cardAmt['fee_kobo']===153 && $cardAmt['net_kobo']===10000,'fee-on-top card payment preserves 100.00 NGN credit and 100.00 NGN net settlement');

// Test sandbox charge rejection under sk_live_ key
$db->exec("UPDATE security_settings SET setting_value='sk_live_prod' WHERE setting_key='payhub_secret_key'");
$liveReject=PayHub::recordSuccessfulCharge($db,'PH_SANDBOX_DROP',['reference'=>'PH_SANDBOX_DROP','amount'=>10000,'domain'=>'test','metadata'=>['ledger_id'=>$dvaLedger]],'test');
check($liveReject['status']==='ignored','test-domain payment rejected under sk_live key');
$db->exec("UPDATE security_settings SET setting_value='sk_test_123' WHERE setting_key='payhub_secret_key'");

// Verify ledger fee aggregation & strict role visibility (treasurer sees fees; parish rep never sees fees)
$treasRows=$db->query("SELECT l.*,h.node_name,h.parish_code FROM remittance_ledgers l JOIN church_hierarchy h ON h.id=l.parish_id WHERE l.id=$dvaLedger")->fetchAll();
PayHub::attachLedgerFees($db,$treasRows);
check($treasRows[0]['payhub_fee_total']==='1.60' && $treasRows[0]['net_settled_total']==='98.40','attachLedgerFees summarizes fee and net per ledger');
$actualPaidCents=0;foreach($db->query("SELECT amount_paid FROM remittance_ledgers WHERE parish_id=1")->fetchAll(PDO::FETCH_COLUMN) as $ap)$actualPaidCents+=Money::cents((string)$ap);
$treasSummary=PayHub::treasuryFeeSummary($db,[1],$actualPaidCents);
check($treasSummary['payhub_fee_total']==='1.60' && $treasSummary['net_settled_total']===Money::decimal($actualPaidCents-160),'treasuryFeeSummary reconciles province net after fees');
$ledgerRows=$treasRows;$portalReady=true;$payhubReady=true;
$treasurer=true;$user=['id'=>100,'role'=>'provincial_treasurer','hierarchy_id'=>null];
ob_start();require __DIR__.'/../app/ledger-table.php';$treasHtml=ob_get_clean();
check(str_contains($treasHtml,'GATEWAY FEES')&&str_contains($treasHtml,'NET SETTLED')&&str_contains($treasHtml,'1.60')&&str_contains($treasHtml,'98.40'),'treasurer ledger table displays PayHub fee & net settled');
$treasurer=false;$user=['id'=>1,'role'=>'parish_officer','hierarchy_id'=>1,'email'=>'officer@example.org','full_name'=>'Hope Officer'];
ob_start();require __DIR__.'/../app/ledger-table.php';$repHtml=ob_get_clean();
check(!str_contains($repHtml,'GATEWAY FEES')&&!str_contains($repHtml,'NET SETTLED')&&!str_contains($repHtml,'1.60')&&!str_contains($repHtml,'98.40'),'representative ledger table strictly hides gateway fees');

// Test double-inclusion guard on app/actions.php and GET return verification without CSRF failure
require __DIR__.'/../app/actions.php';
check(function_exists('action')&&function_exists('payhubVerifyAndProcess'),'re-requiring app/actions.php does not cause Cannot redeclare action()');
$_SESSION['user_id']=1;$_SESSION['csrf']='valid-session-csrf';$_POST=[];$_GET=['page'=>'remittances','pay'=>'payhub','payhub_ref'=>'PH_AUTO'];
$retVerify=payhubVerifyAndProcess($db,$officer,'PH_AUTO','return',true);
check(!empty($retVerify['paid'])&&str_contains($retVerify['message'],'PayHub payment confirmed'),'GET payhub_ref return verifies without CSRF session-expired error');

// Test cached session invalidation when a newer DVA notification exists on the ledger
$db->prepare('INSERT INTO remittance_ledgers(parish_id,provincial_levy_due,amount_paid,status,payment_reference,gross_income,period_month,period_year) VALUES(1,?,?,?,?,?,?,?)')->execute(['50000.00','0.00','pending','RMT-CACHE-DVA','100000.00',10,2025]);$cacheLedger=(int)$db->lastInsertId();
$_SESSION['payhub_sessions'][$cacheLedger]=['unit'=>1,'kobo'=>5000000,'created_at'=>time(),'last_notif_id'=>0,'payload'=>['reference'=>'RMT-OLD-CACHE','payhub_reference'=>'PH_OLD_CACHE']];
PayHub::recordSuccessfulCharge($db,'VA_NEW_99',['reference'=>'VA_NEW_99','amount'=>1000000,'gateway_amount'=>1000000,'fee_amount'=>15000,'settled_amount'=>985000,'status'=>'success','domain'=>'test','channel'=>'dedicated_nuban','paid_at'=>date('c'),'customer'=>['email'=>'officer@example.org'],'metadata'=>['ledger_id'=>$cacheLedger]],'test');
$_GET=['payhub_ref'=>'VA_NEW_99'];
$pollHit=payhubVerifyAndProcess($db,$officer,'RMT-OLD-CACHE','poll',false,$cacheLedger);
check(!empty($pollHit['paid'])&&!isset($_SESSION['payhub_sessions'][$cacheLedger]),'DVA notification on ledger clears stale cached session cleanly');

// Test log redaction & portal settings log panel
PayHub::log($db,'unit_test_secret',['authorization'=>'Bearer sk_live_SECRET123456','ref'=>'RMT-TEST']);
$logs=PayHub::recentLogs(10);
check(!empty($logs)&&str_contains((string)json_encode($logs),'[REDACTED]')&&!str_contains((string)json_encode($logs),'sk_live_SECRET123456'),'PayHub log redacts secret keys');

$user=$admin;ob_start();require __DIR__.'/../app/portal-settings.php';$html=ob_get_clean();check(str_contains($html,'payhub_auto_approve')&&str_contains($html,'Copy webhook')&&str_contains($html,'Recent PayHub activity logs')&&str_contains($html,'storage/logs/payhub.log'),'portal settings renders auto-approval, webhook copy and redacted PayHub logs with file path');
$user=['id'=>1,'role'=>'parish_officer','hierarchy_id'=>1,'email'=>'officer@example.org','full_name'=>'Hope Officer'];ob_start();require __DIR__.'/../app/welcome-guide.php';$guide=ob_get_clean();check(str_contains($guide,'quick-guide-modal')&&str_contains($guide,'data-guide-open'),'quick guide rendered as modal launcher');
ob_start();require __DIR__.'/../app/payments-view.php';$html=ob_get_clean();check(str_contains($html,'payment-tabs')&&str_contains($html,'Bank Transfer')&&str_contains($html,'PayHub Checkout & Virtual Account')&&str_contains($html,'payhub-virtual-box')&&str_contains($html,'stopPolling')&&!str_contains($html,"includes('automatically approved')"),'payment page renders tabs, virtual account panel, and loop-free JSON poller');
if(is_dir(ROOT.'/storage')){foreach([ROOT.'/storage/logs/payhub.log',ROOT.'/storage/logs/.htaccess'] as $lf)if(is_file($lf))@unlink($lf);@rmdir(ROOT.'/storage/logs');@rmdir(ROOT.'/storage');@rmdir(ROOT);}
echo "PASS: $checks combined portal/PayHub assertions (SQLite harness; mocked gateway)\n";

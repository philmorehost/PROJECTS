<?php
declare(strict_types=1);ob_start();require __DIR__.'/church-portal.php';ob_end_clean();
$db->exec("DELETE FROM payment_notifications WHERE ledger_id=1");$db->exec("DELETE FROM report_allocations WHERE ledger_id=1");$db->exec("DELETE FROM remittance_ledgers WHERE id=1");
$db->prepare('INSERT INTO remittance_ledgers(parish_id,period_month,period_year,gross_income,provincial_levy_due,amount_paid,status,payment_reference) VALUES(?,?,?,?,?,?,?,?)')->execute([1,11,2026,'200.00','100.00','0.00','pending','RMT-DELETE-1']);$pending=(int)$db->lastInsertId();
$db->prepare('INSERT INTO remittance_ledgers(parish_id,period_month,period_year,gross_income,provincial_levy_due,amount_paid,status,payment_reference) VALUES(?,?,?,?,?,?,?,?)')->execute([1,10,2026,'200.00','100.00','100.00','verified','RMT-DELETE-2']);$verified=(int)$db->lastInsertId();
$admin=['id'=>100,'role'=>'provincial_admin','email'=>'admin@example.org'];
$_POST=['csrf'=>csrf(),'report_ids'=>[$verified]];$bad=true;try{ChurchPortal::deletePendingReports($db,$admin,$_POST);}catch(RuntimeException $e){$bad=str_contains($e->getMessage(),'Only pending');}
check($bad,'non-pending reports cannot be deleted');
$_POST=['csrf'=>csrf(),'report_ids'=>[$pending]];$count=ChurchPortal::deletePendingReports($db,$admin,$_POST);check($count===1,'pending reports deleted permanently');check((int)$db->query('SELECT COUNT(*) FROM remittance_ledgers WHERE id='.$pending)->fetchColumn()===0,'deleted pending ledger removed');
check((int)$db->query('SELECT COUNT(*) FROM remittance_ledgers WHERE id='.$verified)->fetchColumn()===1,'verified history preserved');
$treasurer=true;$history=new FinancialHistory($db,[1],['reports_page'=>1],'reports');$ledgerRows=$history->items;ob_start();require __DIR__.'/../app/history-reports.php';$html=ob_get_clean();check(str_contains($html,'delete-reports-form')&&str_contains($html,'report_ids[]')&&str_contains($html,'Delete selected pending'),'admin history shows bulk pending delete controls');
echo "PASS: $checks delete pending remittance assertions\n";

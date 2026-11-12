<?php
declare(strict_types=1);
ob_start();
require __DIR__ . '/church-portal.php';
ob_end_clean();

// Helper to inflate all PDF content streams for content & layout verification
function pdfStreamsText(string $pdf): array {
    check(str_starts_with($pdf, "%PDF-1.4\n"), 'PDF starts with %PDF-1.4');
    check(str_ends_with($pdf, "%%EOF\n"), 'PDF ends with %%EOF');
    check(preg_match('/startxref\s+(\d+)\s+%%EOF/', $pdf, $m) === 1, 'PDF contains startxref offset');
    $xrefPos = (int)$m[1];
    check(substr($pdf, $xrefPos, 4) === 'xref', 'startxref points directly to xref table');

    // Verify every active xref object offset starts with "<id> 0 obj"
    $xrefBlock = substr($pdf, $xrefPos);
    preg_match_all('/^(\d{10}) 00000 n /m', $xrefBlock, $offMatches);
    check(count($offMatches[1]) >= 6, 'xref lists catalog, pages, fonts, info, content, page objects');
    foreach ($offMatches[1] as $idx => $offStr) {
        $off = (int)$offStr;
        $objId = $idx + 1;
        check(str_starts_with(substr($pdf, $off, 16), $objId . ' 0 obj'), "xref offset accurate for object $objId");
    }

    preg_match_all('/<<([^>]*)>>\s*stream\n(.*?)\nendstream/s', $pdf, $streams, PREG_SET_ORDER);
    $texts = [];
    foreach ($streams as $st) {
        $raw = $st[2];
        $decoded = str_contains($st[1], '/FlateDecode') ? @gzuncompress($raw) : $raw;
        check(is_string($decoded) && $decoded !== '', 'PDF page stream inflates cleanly');
        $texts[] = $decoded;
    }
    return $texts;
}

// Build realistic hierarchy: Zone (3) -> Area (10) -> Parish 1 (1, Hope Parish) & Parish 11 (East Parish), plus separate Zone 2 / Parish 2 (Grace Parish)
$db->exec("UPDATE church_hierarchy SET parish_code='HOP001' WHERE id=1");
$db->exec("UPDATE church_hierarchy SET parish_code='GRC002' WHERE id=2");
$db->exec("INSERT INTO church_hierarchy(id,node_name,node_type,parent_id,parish_code) VALUES
    (10,'HOPE CENTRAL AREA','area',3,'ARE010'),
    (11,'LIVING WATER PARISH','parish',10,'LWP011'),
    (12,'WEST PROVINCE ZONE','zone',NULL,'ZON012')");
$db->exec("UPDATE church_hierarchy SET parent_id=10 WHERE id=1");
$db->exec("UPDATE church_hierarchy SET parent_id=12 WHERE id=2");
$db->exec("DELETE FROM payment_notifications");
$db->exec("DELETE FROM report_allocations");
$db->exec("DELETE FROM remittance_ledgers");
PayHub::migrate($db);

// Seed multi-year, multi-month ledgers across parishes & statuses
$seed = [
    // parish_id, year, month, gross, due, paid, status, ref
    [1, 2024, 11, '50000.00', '10000.00', '10000.00', 'verified', 'RMT-202411-01'],
    [1, 2025, 1,  '100000.00', '20000.00', '20000.00', 'verified', 'RMT-202501-01'],
    [1, 2025, 6,  '120000.00', '24000.00', '12000.00', 'partial',  'RMT-202506-01'],
    [1, 2026, 1,  '200000.00', '40000.00', '40000.00', 'verified', 'RMT-202601-01'],
    [1, 2026, 3,  '150000.00', '30000.00', '30000.00', 'verified', 'RMT-202603-01'],
    [1, 2026, 5,  '80000.00',  '16000.00', '0.00',     'pending',  'RMT-202605-01'],
    [11, 2026, 2, '300000.00', '60000.00', '60000.00', 'paid',     'RMT-202602-11'],
    [10, 2026, 4, '90000.00',  '18000.00', '18000.00', 'verified', 'RMT-202604-10'],
    [3, 2026, 3,  '250000.00', '50000.00', '50000.00', 'verified', 'RMT-202603-03'],
    [2, 2026, 3,  '400000.00', '80000.00', '80000.00', 'verified', 'RMT-202603-02'],
];
$ins = $db->prepare('INSERT INTO remittance_ledgers(parish_id,period_year,period_month,gross_income,provincial_levy_due,amount_paid,status,payment_reference) VALUES(?,?,?,?,?,?,?,?)');
foreach ($seed as $row) {
    $ins->execute($row);
}

// Add approved PayHub fee notification on Hope Parish Jan 2026 (ledger id for RMT-202601-01)
$janLedgerId = (int)$db->query("SELECT id FROM remittance_ledgers WHERE payment_reference='RMT-202601-01'")->fetchColumn();
$db->prepare("INSERT INTO payment_notifications(ledger_id,submitted_by,amount,transfer_reference,transfer_date,status,payhub_reference,payhub_channel,payhub_paid_at,payhub_fee,payhub_net_amount) VALUES(?,?,?,?,?,?,?,?,?,?,?)")
   ->execute([$janLedgerId, 1, '40000.00', 'PH_JAN26', '2026-01-15', 'approved', 'PH_JAN26', 'dedicated_nuban', '2026-01-15 10:30:00', '600.00', '39400.00']);

$adminUser = ['id' => 100, 'role' => 'provincial_admin', 'full_name' => 'Provincial Admin', 'email' => 'admin@example.org', 'hierarchy_id' => null];
$treasUser = ['id' => 101, 'role' => 'provincial_treasurer', 'full_name' => 'Provincial Treasurer', 'email' => 'treasurer@example.org', 'hierarchy_id' => null];
$zonalUser = ['id' => 102, 'role' => 'zonal_rep', 'full_name' => 'Zonal Rep', 'email' => 'zone@example.org', 'hierarchy_id' => 3];
$areaUser  = ['id' => 103, 'role' => 'area_rep', 'full_name' => 'Area Rep', 'email' => 'area@example.org', 'hierarchy_id' => 10];
$parishUser= ['id' => 1,   'role' => 'parish_officer', 'full_name' => 'Hope Officer', 'email' => 'officer@example.org', 'hierarchy_id' => 1];

// 1. Role & hierarchy scoping checks
$adminAll = RemittanceExport::fetch($db, $adminUser, []);
check($adminAll['summary']['count'] === 10 && $adminAll['treasurer'] === true, 'admin export sees all 10 province reports');
$treasAll = RemittanceExport::fetch($db, $treasUser, []);
check($treasAll['summary']['count'] === 10 && $treasAll['treasurer'] === true, 'treasurer export sees all 10 province reports');

$zonalAll = RemittanceExport::fetch($db, $zonalUser, []);
check($zonalAll['summary']['count'] === 9 && $zonalAll['treasurer'] === false, 'zonal rep sees zone+area+parishes (9) and hides Grace Parish');

$areaAll = RemittanceExport::fetch($db, $areaUser, []);
check($areaAll['summary']['count'] === 8 && $areaAll['treasurer'] === false, 'area rep sees area+child parishes (8) and excludes zone/other zone');

$parishAll = RemittanceExport::fetch($db, $parishUser, []);
check($parishAll['summary']['count'] === 6 && $parishAll['treasurer'] === false, 'parish officer sees only own parish (6)');

// 2. Period filters: Year only, Month range within year, Single month, Open-ended, Swapped range, All-years month slice, Status, Search + SQL injection guard
$y2025 = RemittanceExport::fetch($db, $parishUser, ['export_year' => '2025']);
check($y2025['summary']['count'] === 2 && $y2025['period_label'] === 'Full Year 2025', 'year filter isolates 2025 reports');

$range2026 = RemittanceExport::fetch($db, $parishUser, ['export_year' => '2026', 'export_from_month' => '1', 'export_to_month' => '3']);
check($range2026['summary']['count'] === 2 && $range2026['summary']['due_cents'] === 7000000, 'from-month to to-month filter selects Jan-Mar 2026');

$singleMonth = RemittanceExport::fetch($db, $parishUser, ['export_year' => '2026', 'export_from_month' => '3', 'export_to_month' => '3']);
check($singleMonth['summary']['count'] === 1 && $singleMonth['rows'][0]['payment_reference'] === 'RMT-202603-01', 'single month filter selects March 2026');

$reportsFallback = RemittanceExport::fetch($db, $parishUser, ['reports_year' => '2026', 'reports_month' => '5']);
check($reportsFallback['summary']['count'] === 1 && $reportsFallback['rows'][0]['payment_reference'] === 'RMT-202605-01', 'reports_year/reports_month fallback works for history export');

$openFrom = RemittanceExport::fetch($db, $parishUser, ['export_year' => '2026', 'export_from_month' => '3']);
check($openFrom['summary']['count'] === 2, 'open-ended from_month exports March onward');

$openTo = RemittanceExport::fetch($db, $parishUser, ['export_year' => '2026', 'export_to_month' => '3']);
check($openTo['summary']['count'] === 2, 'open-ended to_month exports up to March');

$swappedRange = RemittanceExport::fetch($db, $parishUser, ['export_year' => '2026', 'export_from_month' => '5', 'export_to_month' => '2']);
check($swappedRange['filters']['from_month'] === 2 && $swappedRange['filters']['to_month'] === 5 && $swappedRange['summary']['count'] === 2, 'reversed month range normalizes cleanly');

$allYearsQ1 = RemittanceExport::fetch($db, $parishUser, ['export_from_month' => '1', 'export_to_month' => '3']);
check($allYearsQ1['summary']['count'] === 3, 'month range with All Years selects Jan-Mar across 2025 and 2026');

$statusPending = RemittanceExport::fetch($db, $adminUser, ['export_status' => 'pending']);
check($statusPending['summary']['count'] === 1 && $statusPending['rows'][0]['payment_reference'] === 'RMT-202605-01', 'status filter matches pending');

$searchGrace = RemittanceExport::fetch($db, $adminUser, ['export_q' => 'GRC002']);
check($searchGrace['summary']['count'] === 1 && $searchGrace['rows'][0]['node_name'] === 'Grace Parish', 'search filter matches parish code');

$sqlInj = RemittanceExport::fetch($db, $adminUser, ['export_q' => "%' OR 1=1 --"]);
check($sqlInj['summary']['count'] === 0, 'SQL wildcard/injection query returns zero safely');

// 3. Available years & Filename formatting
$years = RemittanceExport::availableYears($db, financialScope($db, $parishUser));
check(in_array(2024, $years, true) && in_array(2025, $years, true) && in_array(2026, $years, true), 'availableYears includes all recorded years');
check(RemittanceExport::filename(RemittanceExport::parseFilters(['export_year' => '2026', 'export_from_month' => '1', 'export_to_month' => '6']), 'pdf') === 'remit-reports-2026-jan-jun.pdf', 'filename formats year and month range cleanly');
check(RemittanceExport::filename(RemittanceExport::parseFilters(['export_year' => '2026', 'export_month' => '3', 'export_status' => 'verified']), 'csv') === 'remit-reports-2026-03-verified.csv', 'filename formats single month and status');

// 4. CSV generation, Fee column visibility & CSV Formula Injection protection
$adminCsv = RemittanceExport::csv($db, $adminUser, ['export_year' => '2026']);
check(str_contains($adminCsv, 'Gateway Fees (NGN)') && str_contains($adminCsv, 'Net Settled (NGN)') && str_contains($adminCsv, '600.00') && str_contains($adminCsv, '39400.00'), 'admin CSV includes Gateway Fees and Net Settled columns');

$repCsv = RemittanceExport::csv($db, $parishUser, ['export_year' => '2026']);
check(!str_contains($repCsv, 'Gateway Fees') && !str_contains($repCsv, 'Net Settled') && !str_contains($repCsv, '39400.00') && !str_contains($repCsv, 'Grace Parish'), 'representative CSV hides gateway fees and out-of-scope parishes');
$csvStream = fopen('php://temp', 'r+');fwrite($csvStream, $repCsv);rewind($csvStream);
$csvRows = []; while (($c = fgetcsv($csvStream, 0, ',', '"', '')) !== false) { $csvRows[] = $c; } fclose($csvStream);
check(count($csvRows) === 4 && $csvRows[0][0] === 'Period' && count($csvRows[0]) === 16, 'representative CSV parses cleanly with 1 header + 3 filtered rows (16 columns)');

// Formula-injection safety check in church name
$db->exec("INSERT INTO church_hierarchy(id,node_name,node_type,parent_id,parish_code) VALUES(99,'=CMD|''/C CALC''!A0','parish',10,'=MAL99')");
$db->prepare('INSERT INTO remittance_ledgers(parish_id,period_year,period_month,gross_income,provincial_levy_due,amount_paid,status,payment_reference) VALUES(?,?,?,?,?,?,?,?)')
   ->execute([99, 2026, 7, '1000.00', '200.00', '0.00', 'pending', '+RMT-INJ-99']);
$injCsv = RemittanceExport::csv($db, $adminUser, ['export_year' => '2026', 'export_from_month' => '7', 'export_to_month' => '7']);
check(str_contains($injCsv, "\"'=CMD|") && str_contains($injCsv, "'=MAL99") && str_contains($injCsv, "'+RMT-INJ-99"), 'CSV neutralizes spreadsheet formula prefixes = + - @');

// 5. Bank-Statement PDF structure, layout & content verification
$repPdf = RemittanceExport::pdf($db, $parishUser, ['export_year' => '2026', 'export_from_month' => '1', 'export_to_month' => '6']);
$repStreams = pdfStreamsText($repPdf);
check(count($repStreams) === 1, 'short representative statement fits cleanly on 1 page');
$repTxt = $repStreams[0];
check(str_contains($repTxt, 'REMITTANCE TRANSACTION STATEMENT')
    && str_contains($repTxt, 'ACCOUNT / CHURCH SCOPE')
    && str_contains($repTxt, 'STATEMENT FILTERS & CRITERIA')
    && str_contains($repTxt, 'TOTAL OFFERING \\(100%\\)')
    && str_contains($repTxt, 'CONFIRMED PAID')
    && str_contains($repTxt, 'STATEMENT TOTAL \\(3 REPORTS\\)')
    && str_contains($repTxt, 'Page 1 of 1')
    && str_contains($repTxt, 'HOPE PARISH')
    && !str_contains($repTxt, 'TREASURY SETTLEMENT RECONCILIATION')
    && !str_contains($repTxt, 'NET SETTLED'),
    'representative bank-statement PDF renders all statement blocks without gateway fee exposure');

$adminPdf = RemittanceExport::pdf($db, $adminUser, ['export_year' => '2026', 'export_from_month' => '1', 'export_to_month' => '6']);
$adminStreams = pdfStreamsText($adminPdf);
check(str_contains($adminStreams[0], 'TREASURY SETTLEMENT RECONCILIATION')
    && str_contains($adminStreams[0], 'PayHub Gateway Fees: NGN 600.00')
    && str_contains($adminStreams[0], 'Net Settled to Province')
    && str_contains($adminStreams[0], 'NET SETTLED'),
    'admin/treasurer PDF displays treasury settlement reconciliation and net settled figures');

// Empty-result PDF handles zero rows gracefully
$emptyPdf = RemittanceExport::pdf($db, $parishUser, ['export_year' => '2029']);
$emptyStreams = pdfStreamsText($emptyPdf);
check(str_contains($emptyStreams[0], 'No remittance reports match the selected month, year or status filters.') && str_contains($emptyStreams[0], 'Page 1 of 1'), 'empty filter PDF renders clean empty-statement notice');

// Multi-page PDF pagination (seed 36 additional ledgers)
$bulkIns = $db->prepare('INSERT INTO remittance_ledgers(parish_id,period_year,period_month,gross_income,provincial_levy_due,amount_paid,status,payment_reference) VALUES(?,?,?,?,?,?,?,?)');
for ($k = 0; $k < 36; $k++) {
    $yr = 2027 + intdiv($k, 12);
    $mo = ($k % 12) + 1;
    $bulkIns->execute([1, $yr, $mo, '50000.00', '10000.00', '10000.00', 'verified', 'RMT-PAGE-' . $k]);
}
$multiPdf = RemittanceExport::pdf($db, $adminUser, []);
$multiStreams = pdfStreamsText($multiPdf);
$totalPages = count($multiStreams);
check($totalPages >= 2
    && str_contains($multiStreams[0], 'Page 1 of ' . $totalPages)
    && str_contains($multiStreams[1], 'REMITTANCE TRANSACTION STATEMENT \\(CONTINUED\\)')
    && str_contains($multiStreams[$totalPages - 1], 'Page ' . $totalPages . ' of ' . $totalPages)
    && str_contains($multiStreams[$totalPages - 1], 'END OF STATEMENT'),
    'multi-page bank-statement PDF repeats continuation headers and stamps exact Page X of Y footers');

// Unauthorized role refused
refuses(fn() => RemittanceExport::fetch($db, ['id' => 999, 'role' => 'guest'], []), 'unauthorized role rejected');

// 6. UI view rendering checks across roles
$user = $parishUser; $ids = financialScope($db, $user); $treasurer = false;
ob_start(); require __DIR__ . '/../app/export-reports-view.php'; $expRepHtml = ob_get_clean();
check(str_contains($expRepHtml, 'id="export-reports"')
    && str_contains($expRepHtml, 'name="download" value="remittance-reports"')
    && str_contains($expRepHtml, 'name="export_year"')
    && str_contains($expRepHtml, 'name="export_from_month"')
    && str_contains($expRepHtml, 'name="export_to_month"')
    && str_contains($expRepHtml, 'value="pdf"')
    && str_contains($expRepHtml, 'value="csv"')
    && str_contains($expRepHtml, 'Your parish'),
    'export-reports-view renders month-range, year, status, PDF and CSV export form for representative');

$user = $adminUser; $ids = financialScope($db, $user); $treasurer = true; $_GET = ['reports_year' => '2026', 'reports_month' => '3', 'reports_status' => 'verified'];
ob_start(); require __DIR__ . '/../app/export-reports-view.php'; $expAdminHtml = ob_get_clean();
check(str_contains($expAdminHtml, 'All province churches') && str_contains($expAdminHtml, 'value="2026" selected'), 'export-reports-view reflects province scope and active filters for admin');

ob_start(); require __DIR__ . '/../app/history-reports.php'; $histHtml = ob_get_clean();
check(str_contains($histHtml, 'Export matching PDF')
    && str_contains($histHtml, 'Export matching CSV')
    && str_contains($histHtml, 'export_year=2026')
    && str_contains($histHtml, 'export_from_month=3')
    && str_contains($histHtml, 'delete-reports-form')
    && substr_count($histHtml, '<form') === 2,
    'history-reports renders synced export links and non-nested filter + delete forms');

$_GET = [];
$portalReady = true; $admin = true;
ob_start(); require __DIR__ . '/../app/dashboard-actions.php'; $dashAdmin = ob_get_clean();
$admin = false; $user = $parishUser;
ob_start(); require __DIR__ . '/../app/dashboard-actions.php'; $dashRep = ob_get_clean();
check(str_contains($dashAdmin, 'page=remittances#export-reports') && str_contains($dashRep, 'page=remittances#export-reports'), 'overview quick actions link both admin and representatives to export reports');

echo "PASS: $checks remittance CSV and bank-statement PDF export assertions\n";

<?php
/** Shared reporting helpers for secure transaction CSV and PDF exports. */
function transaction_report_filters($input) {
    $validDate = static function ($value) {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return '';
        $date = DateTime::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $value : '';
    };
    $status = trim((string)($input['status'] ?? ''));
    if (!in_array($status, ['', 'success', 'pending', 'failed', 'refunded'], true)) $status = '';
    return [
        'search' => trim(substr((string)($input['search'] ?? ''), 0, 200)),
        'status' => $status,
        'from' => $validDate((string)($input['from'] ?? '')),
        'to' => $validDate((string)($input['to'] ?? '')),
    ];
}

function transaction_report_where($alias, $filters, $includeStatus = true) {
    $alias = preg_replace('/[^A-Za-z0-9_]/', '', (string)$alias);
    $where = ' WHERE 1=1';
    $params = [];
    if ($filters['search'] !== '') {
        $where .= " AND ($alias.reference LIKE ? OR $alias.customer_email LIKE ? OR $alias.customer_name LIKE ? OR u.business_name LIKE ? OR u.email LIKE ?)";
        $term = '%' . $filters['search'] . '%';
        array_push($params, $term, $term, $term, $term, $term);
    }
    if ($includeStatus && $filters['status'] !== '') {
        $where .= " AND $alias.status = ?";
        $params[] = $filters['status'];
    }
    if ($filters['from'] !== '') {
        $where .= " AND $alias.created_at >= ?";
        $params[] = $filters['from'] . ' 00:00:00';
    }
    if ($filters['to'] !== '') {
        $where .= " AND $alias.created_at < DATE_ADD(?, INTERVAL 1 DAY)";
        $params[] = $filters['to'];
    }
    return [$where, $params];
}

function transaction_report_csv($rows, $filename, $merchantView = false) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '-', $filename) . '.csv"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    $out = fopen('php://output', 'w');
    // UTF-8 BOM lets desktop spreadsheet apps render names and references correctly.
    fwrite($out, "\xEF\xBB\xBF");
    $columns = ['Date (UTC)', 'Reference', 'Gateway reference', 'Customer name', 'Customer email'];
    if (!$merchantView) $columns[] = 'Merchant';
    array_push($columns, 'Amount (NGN)', 'Fee (NGN)', 'Settled (NGN)', 'Currency', 'Payment method', 'Status', 'Failure reason', 'Test mode');
    fputcsv($out, $columns);
    foreach ($rows as $row) {
        $line = [$row['created_at'] ?? '', $row['reference'] ?? '', $row['gateway_reference'] ?? '', $row['customer_name'] ?? '', $row['customer_email'] ?? ''];
        if (!$merchantView) $line[] = $row['business_name'] ?? 'Unknown merchant';
        array_push($line, $row['amount'] ?? 0, $row['fee_amount'] ?? 0, $row['settled_amount'] ?? 0, $row['currency'] ?? 'NGN', $row['payment_method'] ?? '', $row['status'] ?? '', $row['failure_reason'] ?? '', !empty($row['is_test']) ? 'Test' : 'Live');
        // Prevent spreadsheet formula execution for any string fields sourced from users.
        $line = array_map(static function ($value) {
            if (is_string($value) && preg_match('/^[\s]*[=+@-]/', $value)) return "'" . $value;
            return $value;
        }, $line);
        fputcsv($out, $line);
    }
    fclose($out);
}

function transaction_pdf_text($text) {
    $text = (string)$text;
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        if ($converted !== false) $text = $converted;
    }
    $text = preg_replace('/[^\x20-\x7E\xA0-\xFF]/', '?', $text);
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
}

/** Compact vector-based, multi-page statement PDF (no third-party runtime dependency). */
function transaction_report_pdf($rows, $filename, $title, $merchantView = false, $filters = []) {
    $pageWidth = 792; $pageHeight = 612; $pages = [];
    $chunks = array_chunk($rows, 24);
    if (!$chunks) $chunks = [[]];
    $totalVolume = 0.0;
    foreach ($rows as $row) if (($row['status'] ?? '') === 'success') $totalVolume += (float)($row['amount'] ?? 0);
    $range = ($filters['from'] ?: 'Beginning') . ' to ' . ($filters['to'] ?: 'Present');
    foreach ($chunks as $pageIndex => $chunk) {
        $content = "q\n";
        // Bank-statement inspired dark header, report metadata and summary strip.
        $content .= "0.059 0.090 0.165 rg 0 516 792 96 re f\n";
        $content .= "1 1 1 rg BT /F2 21 Tf 34 574 Td (" . transaction_pdf_text($title) . ") Tj ET\n";
        $content .= "0.78 0.84 0.92 rg BT /F1 9 Tf 34 553 Td (Transaction statement  |  " . transaction_pdf_text($range) . ") Tj ET\n";
        $content .= "0.16 0.22 0.34 rg 34 467 724 34 re f\n";
        $summary = count($rows) . ' transactions     Successful volume: NGN ' . number_format($totalVolume, 2) . '     ' . strtoupper($filters['status'] ?: 'ALL STATUSES');
        $content .= "1 1 1 rg BT /F2 9 Tf 46 480 Td (" . transaction_pdf_text($summary) . ") Tj ET\n";
        $content .= "0.39 0.45 0.55 rg BT /F1 8 Tf 34 444 Td (Generated " . transaction_pdf_text(gmdate('Y-m-d H:i')) . " UTC  |  Page " . ($pageIndex + 1) . ' of ' . count($chunks) . ") Tj ET\n";
        $headerY = 419; $rowTop = 397; $rowHeight = 14.5;
        $cols = $merchantView
            ? [['Date',72],['Reference',112],['Customer',158],['Amount',78],['Fee',60],['Net',78],['Status',76],['Method',90]]
            : [['Date',72],['Reference',100],['Merchant',100],['Customer',128],['Amount',74],['Fee',55],['Net',74],['Status',63],['Method',58]];
        $content .= "0.93 0.95 0.98 rg 34 " . ($headerY - 9) . ' ' . array_sum(array_column($cols, 1)) . " 21 re f\n";
        $x = 39;
        foreach ($cols as [$label, $width]) {
            $content .= "0.28 0.34 0.44 rg BT /F2 7 Tf $x $headerY Td (" . transaction_pdf_text($label) . ") Tj ET\n";
            $x += $width;
        }
        foreach ($chunk as $idx => $row) {
            $y = $rowTop - ($idx * $rowHeight);
            if ($idx % 2 === 1) $content .= "0.975 0.98 0.99 rg 34 " . ($y - 4) . ' ' . array_sum(array_column($cols, 1)) . " 14 re f\n";
            $date = !empty($row['created_at']) ? gmdate('d M y H:i', strtotime($row['created_at'])) : '';
            $customer = trim((string)($row['customer_name'] ?? '') . ' ' . (string)($row['customer_email'] ?? ''));
            $values = $merchantView
                ? [$date, $row['reference'] ?? '', $customer, number_format((float)($row['amount'] ?? 0), 2), number_format((float)($row['fee_amount'] ?? 0), 2), number_format((float)($row['settled_amount'] ?? 0), 2), strtoupper($row['status'] ?? ''), $row['payment_method'] ?? '']
                : [$date, $row['reference'] ?? '', $row['business_name'] ?? 'Unknown', $customer, number_format((float)($row['amount'] ?? 0), 2), number_format((float)($row['fee_amount'] ?? 0), 2), number_format((float)($row['settled_amount'] ?? 0), 2), strtoupper($row['status'] ?? ''), $row['payment_method'] ?? ''];
            $x = 39;
            foreach ($cols as $i => [$label, $width]) {
                $value = (string)($values[$i] ?? '');
                $maxChars = max(7, (int)floor(($width - 8) / 4.2));
                if (strlen($value) > $maxChars) $value = substr($value, 0, $maxChars - 1) . '.';
                $color = ($label === 'Status' && strtolower($value) === 'success') ? '0.04 0.48 0.34' : '0.16 0.20 0.27';
                $content .= "$color rg BT /F1 6.8 Tf $x " . ($y + 1) . ' Td (' . transaction_pdf_text($value) . ") Tj ET\n";
                $x += $width;
            }
            $content .= "0.88 0.90 0.93 RG 34 " . ($y - 5) . ' m ' . (34 + array_sum(array_column($cols, 1))) . ' ' . ($y - 5) . " l S\n";
        }
        $content .= "0.39 0.45 0.55 rg BT /F1 7 Tf 34 28 Td (Confidential account activity report  |  Amounts shown in NGN unless specified) Tj ET\nQ\n";
        $pages[] = $content;
    }
    $objects = [];
    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $fontRegularId = 3; $fontBoldId = 4;
    $objects[$fontRegularId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
    $objects[$fontBoldId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
    $kids = [];
    foreach ($pages as $i => $stream) {
        $pageId = 5 + ($i * 2); $contentId = $pageId + 1;
        $kids[] = "$pageId 0 R";
        $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . $pageWidth . ' ' . $pageHeight . '] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $contentId . ' 0 R >>';
        $objects[$contentId] = '<< /Length ' . strlen($stream) . ">>\nstream\n" . $stream . 'endstream';
    }
    $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
    ksort($objects);
    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $offsets = [0];
    $maxId = max(array_keys($objects));
    for ($i = 1; $i <= $maxId; $i++) {
        $offsets[$i] = strlen($pdf);
        $pdf .= $i . " 0 obj\n" . $objects[$i] . "\nendobj\n";
    }
    $xref = strlen($pdf); $pdf .= "xref\n0 " . ($maxId + 1) . "\n0000000000 65535 f \n";
    for ($i = 1; $i <= $maxId; $i++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    $pdf .= 'trailer << /Size ' . ($maxId + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '-', $filename) . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo $pdf;
}

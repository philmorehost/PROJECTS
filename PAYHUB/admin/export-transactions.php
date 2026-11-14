<?php
require_once '../includes/functions.php';
require_once '../includes/transaction-report.php';
if (!isLoggedIn() || !isAdmin()) { http_response_code(403); exit('Unauthorized'); }

$db = Database::connect();
$filters = transaction_report_filters($_GET);
[$where, $params] = transaction_report_where('t', $filters, true);
$where .= admin_tx_lens_sql('t');
$stmt = $db->prepare("SELECT t.*, COALESCE(NULLIF(u.business_name, ''), CONCAT('Merchant #', t.user_id), 'Unknown merchant') AS business_name
    FROM transactions t LEFT JOIN users u ON u.id = t.user_id$where ORDER BY t.created_at DESC, t.id DESC");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$format = strtolower((string)($_GET['format'] ?? 'csv'));
$stamp = gmdate('Ymd-His');
$filename = 'payhub-' . admin_reporting_mode() . '-transactions-' . $stamp;
if ($format === 'pdf') transaction_report_pdf($rows, $filename, 'PayHub ' . strtoupper(admin_reporting_mode()) . ' transaction report', false, $filters);
else transaction_report_csv($rows, $filename, false);

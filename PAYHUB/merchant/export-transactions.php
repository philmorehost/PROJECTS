<?php
require_once '../includes/functions.php';
require_once '../includes/transaction-report.php';
if (!isLoggedIn() || isAdmin()) { http_response_code(403); exit('Unauthorized'); }
$user = getAuthUser();
requireCompliance();
$db = Database::connect();
$filters = transaction_report_filters($_GET);
[$where, $params] = transaction_report_where('t', $filters, true);
$where .= ' AND t.user_id = ? AND t.is_test = ?';
$params[] = $user['id']; $params[] = (int)$user['is_test_mode'];
$stmt = $db->prepare("SELECT t.*, u.business_name FROM transactions t LEFT JOIN users u ON u.id = t.user_id$where ORDER BY t.created_at DESC, t.id DESC");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$format = strtolower((string)($_GET['format'] ?? 'csv'));
$stamp = gmdate('Ymd-His');
$mode = (int)$user['is_test_mode'] ? 'test' : 'live';
$filename = 'payhub-merchant-' . $mode . '-transactions-' . $stamp;
if ($format === 'pdf') transaction_report_pdf($rows, $filename, 'Merchant ' . strtoupper($mode) . ' transaction report', true, $filters);
else transaction_report_csv($rows, $filename, true);

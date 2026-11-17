<?php
require_once '../includes/functions.php';
require_once '../includes/transaction-report.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!isLoggedIn() || isAdmin()) { http_response_code(403); echo json_encode(['status' => false, 'message' => 'Unauthorized']); exit; }
$user = getAuthUser();
requireCompliance();
$db = Database::connect();
$filters = transaction_report_filters($_GET);
$limit = filter_var($_GET['limit'] ?? 25, FILTER_VALIDATE_INT);
$limit = max(10, min(100, $limit === false ? 25 : $limit));
$offset = filter_var($_GET['offset'] ?? 0, FILTER_VALIDATE_INT);
$offset = max(0, min(10000000, $offset === false ? 0 : $offset));
try {
    [$where, $params] = transaction_report_where('t', $filters, true);
    $where .= ' AND t.user_id = ? AND t.is_test = ?';
    $params[] = $user['id']; $params[] = (int)$user['is_test_mode'];
    $list = $db->prepare("SELECT t.id, t.user_id, t.reference, t.amount, t.status, t.customer_email, t.customer_name,
        t.payment_method, t.created_at, t.fee_amount, t.settled_amount, t.currency, t.is_test, u.business_name
        FROM transactions t LEFT JOIN users u ON u.id = t.user_id$where ORDER BY t.created_at DESC, t.id DESC LIMIT $limit OFFSET $offset");
    $list->execute($params);
    $rows = $list->fetchAll(PDO::FETCH_ASSOC);
    $count = $db->prepare("SELECT COUNT(*) FROM transactions t LEFT JOIN users u ON u.id = t.user_id$where");
    $count->execute($params);
    $total = (int)$count->fetchColumn();
    [$summaryWhere, $summaryParams] = transaction_report_where('t', $filters, false);
    $summaryWhere .= ' AND t.user_id = ? AND t.is_test = ?';
    $summaryParams[] = $user['id']; $summaryParams[] = (int)$user['is_test_mode'];
    $summaryStmt = $db->prepare("SELECT COUNT(*) AS total,
        SUM(CASE WHEN t.status = 'success' THEN 1 ELSE 0 END) AS successful,
        SUM(CASE WHEN t.status = 'pending' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN t.status = 'failed' THEN 1 ELSE 0 END) AS failed,
        SUM(CASE WHEN t.status = 'refunded' THEN 1 ELSE 0 END) AS refunded,
        COALESCE(SUM(CASE WHEN t.status = 'success' THEN t.amount ELSE 0 END), 0) AS volume
        FROM transactions t LEFT JOIN users u ON u.id = t.user_id$summaryWhere");
    $summaryStmt->execute($summaryParams);
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    echo json_encode([
        'status' => true, 'data' => $rows,
        'summary' => ['total' => (int)($summary['total'] ?? 0), 'successful' => (int)($summary['successful'] ?? 0), 'pending' => (int)($summary['pending'] ?? 0), 'failed' => (int)($summary['failed'] ?? 0), 'refunded' => (int)($summary['refunded'] ?? 0), 'volume' => (float)($summary['volume'] ?? 0)],
        'pagination' => ['total' => $total, 'per_page' => $limit, 'current_page' => (int)floor($offset / $limit) + 1, 'total_pages' => max(1, (int)ceil($total / $limit)), 'from' => $total ? $offset + 1 : 0, 'to' => min($offset + count($rows), $total)],
        'mode' => (int)$user['is_test_mode'] ? 'test' : 'live'
    ], JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('[PayHub] Merchant transaction report error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => false, 'message' => 'Unable to load transaction report. Please retry.']);
}

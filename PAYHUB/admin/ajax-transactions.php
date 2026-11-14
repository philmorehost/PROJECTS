<?php
require_once '../includes/functions.php';
require_once '../includes/transaction-report.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!isLoggedIn() || !isAdmin()) {
    http_response_code(403);
    echo json_encode(['status' => false, 'message' => 'Unauthorized']);
    exit;
}

$db = Database::connect();
$filters = transaction_report_filters($_GET);
$limit = filter_var($_GET['limit'] ?? 25, FILTER_VALIDATE_INT);
$limit = max(10, min(100, $limit === false ? 25 : $limit));
$offset = filter_var($_GET['offset'] ?? 0, FILTER_VALIDATE_INT);
$offset = max(0, min(10000000, $offset === false ? 0 : $offset));
$mismatch = (($_GET['mismatch'] ?? '') === '1');

try {
    // Keep a LEFT JOIN: older/imported transactions may have no corresponding merchant row.
    [$where, $params] = transaction_report_where('t', $filters, true);
    if ($mismatch) $where .= " AND (t.failure_reason IS NOT NULL OR (t.gateway_amount IS NOT NULL AND t.gateway_amount < t.amount))";
    $where .= admin_tx_lens_sql('t');
    $list = $db->prepare("SELECT t.*, COALESCE(NULLIF(u.business_name, ''), CONCAT('Merchant #', t.user_id), 'Unknown merchant') AS business_name,
        u.email AS merchant_email
        FROM transactions t LEFT JOIN users u ON u.id = t.user_id$where ORDER BY t.created_at DESC, t.id DESC LIMIT $limit OFFSET $offset");
    $list->execute($params);
    $rows = $list->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) unset($row['checkout_token']);
    unset($row);

    $count = $db->prepare("SELECT COUNT(*) FROM transactions t LEFT JOIN users u ON u.id = t.user_id$where");
    $count->execute($params);
    $total = (int)$count->fetchColumn();

    // Cards always reflect the selected search/date range, not only one clicked status.
    [$summaryWhere, $summaryParams] = transaction_report_where('t', $filters, false);
    if ($mismatch) $summaryWhere .= " AND (t.failure_reason IS NOT NULL OR (t.gateway_amount IS NOT NULL AND t.gateway_amount < t.amount))";
    $summaryWhere .= admin_tx_lens_sql('t');
    $summaryStmt = $db->prepare("SELECT COUNT(*) AS total,
        SUM(CASE WHEN t.status = 'success' THEN 1 ELSE 0 END) AS successful,
        SUM(CASE WHEN t.status = 'pending' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN t.status = 'failed' THEN 1 ELSE 0 END) AS failed,
        SUM(CASE WHEN t.status = 'refunded' THEN 1 ELSE 0 END) AS refunded,
        COALESCE(SUM(CASE WHEN t.status = 'success' THEN t.amount ELSE 0 END), 0) AS volume
        FROM transactions t LEFT JOIN users u ON u.id = t.user_id$summaryWhere");
    $summaryStmt->execute($summaryParams);
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $pages = max(1, (int)ceil($total / $limit));
    echo json_encode([
        'status' => true,
        'data' => $rows,
        'summary' => [
            'total' => (int)($summary['total'] ?? 0),
            'successful' => (int)($summary['successful'] ?? 0),
            'pending' => (int)($summary['pending'] ?? 0),
            'failed' => (int)($summary['failed'] ?? 0),
            'refunded' => (int)($summary['refunded'] ?? 0),
            'volume' => (float)($summary['volume'] ?? 0),
        ],
        'pagination' => [
            'total' => $total,
            'per_page' => $limit,
            'current_page' => (int)floor($offset / $limit) + 1,
            'total_pages' => $pages,
            'from' => $total ? $offset + 1 : 0,
            'to' => min($offset + count($rows), $total),
        ],
        'mode' => admin_reporting_mode(),
    ], JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('[PayHub] Admin transaction report error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => false, 'message' => 'Unable to load transaction report. Please retry or contact support.']);
}

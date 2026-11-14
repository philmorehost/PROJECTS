<?php
require_once '../includes/functions.php';
if (!isLoggedIn() || isAdmin()) { http_response_code(403); exit; }
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$id || $id < 1) { http_response_code(400); echo json_encode(['error' => 'Invalid transaction']); exit; }
$user = getAuthUser();
requireCompliance();
$db = Database::connect();
try {
    $stmt = $db->prepare('SELECT * FROM transactions WHERE id = ? AND user_id = ? AND is_test = ? LIMIT 1');
    $stmt->execute([$id, $user['id'], (int)$user['is_test_mode']]);
    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$transaction) { http_response_code(404); echo json_encode(['error' => 'Transaction not found']); exit; }
    unset($transaction['checkout_token']);
    try {
        $timelineStmt = $db->prepare('SELECT event_type, description, created_at FROM transaction_timeline WHERE transaction_id = ? ORDER BY created_at ASC, id ASC');
        $timelineStmt->execute([$id]);
        $timeline = $timelineStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $ignored) { $timeline = []; }
    try {
        $webhookStmt = $db->prepare('SELECT status, attempt_count, last_attempt_at, payload, response, created_at FROM webhook_logs WHERE transaction_id = ? ORDER BY created_at ASC, id ASC');
        $webhookStmt->execute([$id]);
        $webhooks = $webhookStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $ignored) { $webhooks = []; }
    try {
        $reversalStmt = $db->prepare('SELECT reversal_reference, kind, amount, created_at FROM transaction_reversals WHERE transaction_id = ? ORDER BY created_at ASC, id ASC');
        $reversalStmt->execute([$id]);
        $reversals = $reversalStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $ignored) { $reversals = []; }
    echo json_encode(['transaction' => $transaction, 'timeline' => $timeline, 'webhooks' => $webhooks, 'reversals' => $reversals], JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('[PayHub] Merchant transaction detail error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Unable to load transaction detail']);
}

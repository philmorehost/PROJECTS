<?php
/**
 * admin/invalidate_token.php — revoke a payment auto-login link.
 */

require_once __DIR__ . '/../includes/security.php';
lm_session_start();

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit();
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/lm_config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !lm_verify_csrf()) {
    header('Location: licenses.php');
    exit();
}

$id = $_POST['id'] ?? null;
if (!$id) {
    header('Location: transactions.php');
    exit();
}

try {
    $stmt = $pdo->prepare("UPDATE transactions SET auto_login_token = NULL, token_created_at = NULL WHERE id = ?");
    $stmt->execute([$id]);
} catch (PDOException $e) {
    // ignore
}

header('Location: transactions.php');
exit();

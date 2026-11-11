<?php
require_once __DIR__ . '/../includes/security.php';
lm_session_start();
lm_security_headers();

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit();
}

require_once('../db.php');

if (!lm_verify_csrf()) {
    header('Location: transactions.php?error=csrf');
    exit();
}

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
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

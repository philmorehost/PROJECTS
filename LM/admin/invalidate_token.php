<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit();
}

require_once('../db.php');

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

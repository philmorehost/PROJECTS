<?php
session_start();
require_once('../db.php');

// Accept token via POST or GET
$token = $_POST['token'] ?? $_GET['token'] ?? '';

if (empty($token)) {
    http_response_code(400);
    echo "Invalid token";
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM transactions WHERE auto_login_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $trans = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$trans) {
        http_response_code(404);
        echo "Token not found or already used";
        exit;
    }

    // Check token age (allow up to 10 minutes)
    $created = $trans['token_created_at'] ?? null;
    if ($created) {
        $created_ts = strtotime($created);
        if (time() - $created_ts > 600) {
            http_response_code(410);
            echo "Token expired";
            exit;
        }
    }

    // Fetch license
    if (empty($trans['license_id'])) {
        http_response_code(500);
        echo "No license associated with token";
        exit;
    }

    $licStmt = $pdo->prepare("SELECT * FROM licenses WHERE id = ? LIMIT 1");
    $licStmt->execute([$trans['license_id']]);
    $license = $licStmt->fetch(PDO::FETCH_ASSOC);

    if (!$license) {
        http_response_code(500);
        echo "License not found";
        exit;
    }

    // Set session
    $_SESSION['user_email'] = $license['customer_email'];
    $_SESSION['user_license_id'] = $license['id'];

    // Invalidate token (single use)
    $update = $pdo->prepare("UPDATE transactions SET auto_login_token = NULL, token_created_at = NULL WHERE id = ?");
    $update->execute([$trans['id']]);

    // Redirect to dashboard
    header('Location: ../user/index.php');
    exit;

} catch (PDOException $e) {
    http_response_code(500);
    echo "Server error";
    exit;
}

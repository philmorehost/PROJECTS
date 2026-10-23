<?php
header('Content-Type: application/json');

require_once('db.php');

$ref = $_GET['ref'] ?? '';

if (empty($ref)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No transaction reference provided']);
    exit;
}

try {
    // Check if transaction exists
    $stmt = $pdo->prepare("SELECT * FROM transactions WHERE transaction_ref = ?");
    $stmt->execute([$ref]);
    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$transaction) {
        echo json_encode(['success' => false, 'message' => 'Processing payment...']);
        exit;
    }
    
    // Transaction exists, now check if license_id is set
    if (empty($transaction['license_id'])) {
        echo json_encode(['success' => false, 'message' => 'Processing your license...']);
        exit;
    }
    
    // Get the license
    $license_stmt = $pdo->prepare("SELECT license_key FROM licenses WHERE id = ?");
    $license_stmt->execute([$transaction['license_id']]);
    $license = $license_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($license && !empty($license['license_key'])) {
        echo json_encode([
            'success' => true,
            'license_key' => $license['license_key'],
            'message' => 'License found!'
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Finalizing your license...']);
    }
    
} catch (PDOException $e) {
    error_log("License check error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Processing payment...']);
}

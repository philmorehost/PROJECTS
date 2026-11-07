<?php
require_once __DIR__ . '/config/config.php';
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    $stmt = $pdo->prepare("SELECT class FROM sm_teachers WHERE id_number = '260003'");
    $stmt->execute();
    echo json_encode($stmt->fetch());
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

<?php
require_once 'includes/functions.php';
header('Content-Type: application/json');

$db = Database::connect();
$stmt = $db->query("SELECT * FROM webhook_logs ORDER BY id DESC LIMIT 5");
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($logs, JSON_PRETTY_PRINT);

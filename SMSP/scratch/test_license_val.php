<?php
require_once 'config/config.php';
require_once 'include/db.php';

try {
    $stmtKey = $pdo->prepare("SELECT setting_value FROM sm_global_settings WHERE setting_key = 'license_key' LIMIT 1");
    $stmtKey->execute();
    $db_key = $stmtKey->fetchColumn();
    echo "DB License Key: " . ($db_key ?: 'Not set') . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "Config LICENSE_KEY Constant: " . (defined('LICENSE_KEY') ? LICENSE_KEY : 'Not defined') . "\n";
echo "Config MAIN_DOMAIN Constant: " . (defined('MAIN_DOMAIN') ? MAIN_DOMAIN : 'Not defined') . "\n";

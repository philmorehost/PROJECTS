<?php
// Secure Dynamic App Download router
session_start();
define('PORTAL_PATH', __DIR__);
require_once PORTAL_PATH . '/config/config.php';
require_once PORTAL_PATH . '/include/db.php';
require_once PORTAL_PATH . '/include/addon_helper.php';

if (!isset($_SESSION['user_session'])) {
    header("Location: login.php");
    exit;
}

$school_id = $_SESSION['school_id'];
if (!$school_id) {
    die("Access denied. Invalid session parameters.");
}

// Super Admin has access to download anything centrally
if ($school_id !== 'global') {
    // Check if the school has the active Mobile App addon
    if (!AddonHelper::isAddonActive($pdo, $school_id, 'Mobile App')) {
        die("Access denied. The Mobile Application Module is not active/purchased for your school. Please contact your administrator or buy the module from the Addons Marketplace.");
    }
}

$platform = isset($_GET['platform']) ? trim($_GET['platform']) : 'android';
$key = ($platform === 'ios') ? 'mobile_app_ios_url' : 'mobile_app_apk_url';

try {
    $stmt = $pdo->prepare("SELECT `setting_value` FROM `sm_global_settings` WHERE `setting_key` = ?");
    $stmt->execute([$key]);
    $url = $stmt->fetchColumn();
    
    if (empty($url)) {
        die("The download link for this platform has not been configured by the system administrator.");
    }

    header("Location: " . $url);
    exit;
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>

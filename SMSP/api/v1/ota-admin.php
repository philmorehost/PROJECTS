<?php
/**
 * SchoolApp (SMSP) — Super Admin OTA AJAX Controller
 *
 * Powers the 3-step manual Update Centre UI (check, download, backup, apply, settings).
 */

header('Content-Type: application/json');

define('PORTAL_PATH', dirname(__DIR__, 2));

if (!file_exists(PORTAL_PATH . '/config/config.php')) {
    http_response_code(503);
    echo json_encode(['status' => 'error', 'message' => 'Application not installed.']);
    exit();
}

require_once PORTAL_PATH . '/config/config.php';
require_once PORTAL_PATH . '/include/db.php';
require_once PORTAL_PATH . '/include/update_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['user_session']) || ($_SESSION['user_role'] ?? '') !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized: Super Admin session required.']);
    exit();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit();
}

set_time_limit(0);
ini_set('memory_limit', '512M');

$action = trim($_POST['ota_action'] ?? '');

// Release session lock during heavy I/O after reading/writing session state
switch ($action) {
    case 'save_settings':
        $autoUpdate = !empty($_POST['auto_update_enabled']) && $_POST['auto_update_enabled'] === '1' ? '1' : '0';
        $signalEnabled = !empty($_POST['ota_signal_enabled']) && $_POST['ota_signal_enabled'] === '1' ? '1' : '0';
        $editionTier = strtoupper(trim($_POST['edition_tier'] ?? 'SMSP-SAAS'));
        if (!in_array($editionTier, ['SMSP-SAAS', 'SMSP-NON-SAAS'], true)) {
            $editionTier = 'SMSP-SAAS';
        }
        $serverUrl = rtrim(trim($_POST['update_server_url'] ?? UpdateHelper::DEFAULT_UPDATE_SERVER), '/');
        if ($serverUrl === '' || !preg_match('#^https?://#i', $serverUrl)) {
            echo json_encode(['status' => 'error', 'message' => 'Please enter a valid http(s) Update Server URL.']);
            exit();
        }

        UpdateHelper::setSetting($pdo, 'auto_update_enabled', $autoUpdate);
        UpdateHelper::setSetting($pdo, 'ota_signal_enabled', $signalEnabled);
        UpdateHelper::setSetting($pdo, 'edition_tier', $editionTier);
        UpdateHelper::setSetting($pdo, 'update_server_url', $serverUrl);

        echo json_encode([
            'status'  => 'success',
            'message' => 'OTA update preferences saved successfully.',
        ]);
        exit();

    case 'check':
        $forceVersion = trim($_POST['force_version'] ?? '');
        session_write_close();
        $res = UpdateHelper::checkUpdate($pdo, $forceVersion !== '' ? $forceVersion : null);
        echo json_encode($res);
        exit();

    case 'download':
        $downloadUrl = trim($_POST['download_url'] ?? '');
        $checksum = trim($_POST['checksum'] ?? '');
        session_write_close();

        if (!UpdateHelper::acquireLock()) {
            echo json_encode(['status' => 'error', 'message' => 'Another update process is currently running.']);
            exit();
        }
        try {
            $res = UpdateHelper::downloadUpdate($downloadUrl, $checksum);
            echo json_encode($res);
        } finally {
            UpdateHelper::releaseLock();
        }
        exit();

    case 'backup':
        $settings = UpdateHelper::getSettings($pdo);
        $curVer = $settings['system_version'] ?? '2.0.0';

        if (!UpdateHelper::acquireLock()) {
            echo json_encode(['status' => 'error', 'message' => 'Another update process is currently running.']);
            exit();
        }
        try {
            $res = UpdateHelper::createBackup($pdo, $curVer);
            if (($res['status'] ?? '') === 'success') {
                $_SESSION['ota_pending_backup'] = [
                    'sql_file' => $res['sql_file'],
                    'zip_file' => $res['zip_file'],
                    'sql_name' => $res['sql_name'],
                    'zip_name' => $res['zip_name'],
                ];
            }
            session_write_close();
            echo json_encode($res);
        } finally {
            UpdateHelper::releaseLock();
        }
        exit();

    case 'apply':
        $pendingBackup = $_SESSION['ota_pending_backup'] ?? [];
        unset($_SESSION['ota_pending_backup']);
        session_write_close();

        if (!UpdateHelper::acquireLock()) {
            echo json_encode(['status' => 'error', 'message' => 'Another update process is currently running.']);
            exit();
        }
        try {
            $zipFile = PORTAL_PATH . '/tmp_update/update_package.zip';
            $res = UpdateHelper::applyUpdate($pdo, $zipFile, is_array($pendingBackup) ? $pendingBackup : [], 'manual');
            echo json_encode($res);
        } finally {
            UpdateHelper::releaseLock();
        }
        exit();

    default:
        echo json_encode(['status' => 'error', 'message' => 'Unknown OTA action.']);
        exit();
}

<?php
/**
 * SchoolApp (SMSP) — Super Admin Cron AJAX Controller
 *
 * Powers the "Run now" buttons and settings controls in
 * Super Admin → ⏰ Cron Jobs (views/cron_settings.php).
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
require_once PORTAL_PATH . '/include/cron_helper.php';

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
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method. Use POST.']);
    exit();
}

set_time_limit(0);
ini_set('memory_limit', '512M');

// Release the session lock before heavy work so the rest of the UI stays responsive
if (function_exists('session_write_close')) {
    session_write_close();
}

$action = trim((string)($_POST['cron_action'] ?? ''));

switch ($action) {
    case 'run_job':
        $jobKey = trim((string)($_POST['job_key'] ?? ''));
        $catalog = CronHelper::jobCatalog();

        if ($jobKey === 'all') {
            // Explicit user action: run every enabled job regardless of cadence
            $settings = CronHelper::getSettings($pdo);
            $results = [];
            foreach ($catalog as $key => $job) {
                if ($key === 'ota_update' && !$settings['cron_ota_enabled']) {
                    continue;
                }
                if ($key === 'maintenance' && !$settings['cron_maintenance_enabled']) {
                    continue;
                }
                $results[$key] = CronHelper::runJob($pdo, $key, 'manual');
            }

            if (empty($results)) {
                echo json_encode([
                    'status'      => 'success',
                    'message'     => 'All jobs are disabled. Enable them under Cron Settings first.',
                    'output'      => 'No enabled jobs to run.',
                    'duration_ms' => 0,
                ]);
                exit();
            }

            $output = '';
            $anyError = false;
            $totalMs = 0;
            foreach ($results as $key => $res) {
                $totalMs += (int)$res['duration_ms'];
                $anyError = $anyError || ($res['status'] === 'error');
                $output .= "===== {$key}: " . strtoupper($res['status']) . " ({$res['duration_ms']} ms) =====\n" . $res['output'] . "\n";
            }

            echo json_encode([
                'status'      => $anyError ? 'error' : 'success',
                'message'     => count($results) . ' job(s) executed.',
                'output'      => $output,
                'duration_ms' => $totalMs,
            ]);
            exit();
        }

        if (!isset($catalog[$jobKey])) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Unknown job key.']);
            exit();
        }

        $result = CronHelper::runJob($pdo, $jobKey, 'manual');
        echo json_encode([
            'status'      => $result['status'],
            'message'     => $catalog[$jobKey]['name'] . ' finished with status ' . strtoupper($result['status']) . '.',
            'output'      => $result['output'],
            'duration_ms' => $result['duration_ms'],
        ]);
        exit();

    case 'save_settings':
        CronHelper::setSetting($pdo, 'cron_ota_enabled', !empty($_POST['cron_ota_enabled']) ? '1' : '0');
        CronHelper::setSetting($pdo, 'cron_maintenance_enabled', !empty($_POST['cron_maintenance_enabled']) ? '1' : '0');
        CronHelper::setSetting($pdo, 'cron_email_reports', !empty($_POST['cron_email_reports']) ? '1' : '0');
        CronHelper::setSetting($pdo, 'cron_report_email', trim((string)($_POST['cron_report_email'] ?? '')));
        CronHelper::setSetting($pdo, 'cron_log_retention_days', (string)max(1, min(365, (int)($_POST['cron_log_retention_days'] ?? 30))));
        echo json_encode(['status' => 'success', 'message' => 'Cron settings saved.']);
        exit();

    case 'regenerate_token':
        $token = CronHelper::webCronToken($pdo, true);
        echo json_encode([
            'status'  => 'success',
            'message' => 'A new web-cron token was generated.',
            'token'   => $token,
            'url'     => CronHelper::webCronUrl($pdo, 'all'),
        ]);
        exit();

    case 'clear_logs':
        $cleared = CronHelper::clearLogs($pdo, 0);
        echo json_encode(['status' => 'success', 'message' => "Cleared {$cleared} cron log row(s)."]);
        exit();

    default:
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Unknown cron_action.']);
        exit();
}

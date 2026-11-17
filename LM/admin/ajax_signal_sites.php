<?php
/**
 * admin/ajax_signal_sites.php
 *
 * Send the "update available" signal for a release, and read the delivery log.
 *
 * The signal itself lives in includes/ota_signals.php; this file only checks
 * the session and CSRF token and turns the result into JSON.
 *
 * @package LicenseManager
 */

require_once __DIR__ . '/../includes/security.php';
lm_session_start();
lm_security_headers();

lm_request_decode();
header('Content-Type: application/json; charset=utf-8');
@ini_set('display_errors', '0');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/updates.php';
require_once __DIR__ . '/../includes/ota_signals.php';

lm_require_admin(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    lm_json(array('status' => 'error', 'message' => 'Invalid request method.'), 405);
}

if (!lm_verify_csrf()) {
    lm_json(array('status' => 'error', 'message' => 'Session not saved — reload and try again.'), 419);
}

$action = (string) ($_POST['action'] ?? '');
$updateId = (int) ($_POST['update_id'] ?? 0);

if ($action === 'send') {
    if ($updateId <= 0) {
        lm_json(array('status' => 'error', 'message' => 'Choose a release first.'), 400);
    }

    // force = "send even if the portal already reports this version"
    $force = !empty($_POST['force']);

    try {
        $result = lm_ota_signal_send($pdo, $updateId, array(
            'source' => 'manual',
            'force'  => $force,
        ));
    } catch (Throwable $e) {
        lm_json(array('status' => 'error', 'message' => $e->getMessage()), 500);
    }

    lm_json(array(
        'status'  => empty($result['failed']) ? 'success' : 'partial',
        'message' => $result['message'],
        'detail'  => $result,
    ));
}

if ($action === 'targets') {
    if ($updateId <= 0) {
        lm_json(array('status' => 'error', 'message' => 'Choose a release first.'), 400);
    }

    $stmt = $pdo->prepare('SELECT tier_id FROM script_updates WHERE id = ?');
    $stmt->execute(array($updateId));
    $tierId = (int) $stmt->fetchColumn();

    if ($tierId <= 0) {
        lm_json(array('status' => 'error', 'message' => 'Release not found.'), 404);
    }

    lm_json(array(
        'status'  => 'success',
        'targets' => lm_ota_signal_targets($pdo, $tierId),
    ));
}

if ($action === 'save_config') {
    $tierId = (int) ($_POST['tier_id'] ?? 0);
    if ($tierId <= 0) {
        lm_json(array('status' => 'error', 'message' => 'Choose a project first.'), 400);
    }

    $endpoint = trim((string) ($_POST['signal_endpoint'] ?? ''));
    if ($endpoint === '') {
        $endpoint = lm_ota_signal_default_endpoint();
    }
    if (strpos($endpoint, '://') !== false) {
        $endpoint = (string) parse_url($endpoint, PHP_URL_PATH);
    }
    if ($endpoint === '' || $endpoint[0] !== '/') {
        lm_json(array('status' => 'error', 'message' => 'The endpoint must be a path such as /api/v1/ota-signal.php.'), 400);
    }

    $autoSignal = !empty($_POST['auto_signal']) ? 1 : 0;

    try {
        $stmt = $pdo->prepare('UPDATE script_tiers SET signal_endpoint = ?, auto_signal = ? WHERE id = ?');
        $stmt->execute(array($endpoint, $autoSignal, $tierId));
    } catch (PDOException $e) {
        lm_json(array('status' => 'error', 'message' => 'Could not save: ' . $e->getMessage()), 500);
    }

    lm_json(array(
        'status'  => 'success',
        'message' => 'Signal settings saved for this project.',
        'endpoint' => $endpoint,
        'auto_signal' => $autoSignal,
    ));
}

if ($action === 'clear_log') {
    try {
        $deleted = (int) $pdo->exec('DELETE FROM ota_signal_logs');
    } catch (PDOException $e) {
        lm_json(array('status' => 'error', 'message' => $e->getMessage()), 500);
    }

    lm_json(array('status' => 'success', 'message' => $deleted . ' signal log row(s) cleared.'));
}

lm_json(array('status' => 'error', 'message' => 'Unknown action.'), 400);

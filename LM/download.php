<?php
/**
 * download.php — stream an update package against a one-time token.
 *
 * Tokens are issued by check-update.php and by update notification emails.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/updates.php';

$token = trim((string) ($_GET['token'] ?? ''));

if ($token === '') {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('status' => 'error', 'message' => 'Download token is missing.'));
    exit;
}

lm_log('ota', 'Download attempt with token ' . substr($token, 0, 12) . '…');

try {
    // Housekeeping: only remove tokens that expired more than a day ago so a
    // just-expired token can still return the correct "expired" message.
    try {
        $pdo->exec("DELETE FROM download_tokens WHERE expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");
    } catch (PDOException $e) {
        // ignore
    }

    $stmt = $pdo->prepare(
        "SELECT dt.id, dt.is_used, dt.expires_at, dt.license_id, su.*, t.tier_code
         FROM download_tokens dt
         JOIN script_updates su ON dt.update_id = su.id
         LEFT JOIN script_tiers t ON su.tier_id = t.id
         WHERE dt.token = ?
         LIMIT 1"
    );
    $stmt->execute(array($token));
    $record = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$record) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('status' => 'error', 'message' => 'Invalid or expired download token.'));
        exit;
    }

    if ((int) $record['is_used'] === 1) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('status' => 'error', 'message' => 'This download link has already been used. Sign in to your dashboard to download again.'));
        exit;
    }

    if (strtotime($record['expires_at']) < time()) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('status' => 'error', 'message' => 'This download link has expired.'));
        exit;
    }

    $resolved = lm_resolve_update_path($record['zip_path'], $record['tier_code'] ?? null);
    $filePath = $resolved['path'];

    if ($filePath === null) {
        lm_log('ota', 'Download failed: package missing for update ' . $record['id'] . ' (stored: ' . $record['zip_path'] . ')');
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('status' => 'error', 'message' => 'Update file not found on the server. Please contact support.'));
        exit;
    }

    // Mark as used before streaming (prevents parallel reuse).
    try {
        $pdo->prepare("UPDATE download_tokens SET is_used = 1, used_at = NOW() WHERE id = ?")->execute(array((int) $record['id']));
    } catch (PDOException $e) {
        // Older schema without used_at — still burn the token.
        $pdo->prepare("UPDATE download_tokens SET is_used = 1 WHERE id = ?")->execute(array((int) $record['id']));
    }

    lm_log('ota', 'Streaming ' . $filePath . ' (v' . $record['version_number'] . ')');

    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Description: File Transfer');
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="update_v' . preg_replace('/[^A-Za-z0-9._-]/', '_', $record['version_number']) . '.zip"');
    header('Content-Length: ' . filesize($filePath));
    header('X-Content-Type-Options: nosniff');
    header('Expires: 0');
    header('Cache-Control: must-revalidate, no-store');
    header('Pragma: public');

    $handle = fopen($filePath, 'rb');
    if ($handle) {
        while (!feof($handle)) {
            echo fread($handle, 8192);
            flush();
        }
        fclose($handle);
    }
    exit;

} catch (PDOException $e) {
    lm_log('ota', 'Download database error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('status' => 'error', 'message' => 'Internal server error during download.'));
    exit;
}

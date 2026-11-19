<?php
// ajax_delete_update.php
require_once __DIR__ . '/../includes/security.php';
lm_session_start();
header('Content-Type: application/json');

// 1. Admin Authentication Check
if (!isset($_SESSION['admin_logged_in'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit();
}

require_once('../db.php');

// Deleting a package is a state change: require the token.
if (!lm_verify_csrf(false)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Security token expired. Reload the page and try again.']);
    exit();
}

// 2. Validate Request Method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit();
}

$update_id = intval($_POST['update_id'] ?? 0);
$action = $_POST['action'] ?? 'delete_single';

// 3. Process Action
if ($action === 'delete_single') {
    if ($update_id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid update ID.']);
        exit();
    }

    try {
        // Fetch update details to get the physical file path
        $stmt = $pdo->prepare("SELECT zip_path, version_number FROM script_updates WHERE id = ?");
        $stmt->execute([$update_id]);
        $update = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$update) {
            echo json_encode(['status' => 'error', 'message' => 'Update record not found in the database.']);
            exit();
        }

        // Delete the update file from disk if it exists
        $file_deleted = false;
        if (!empty($update['zip_path']) && file_exists($update['zip_path'])) {
            if (@unlink($update['zip_path'])) {
                $file_deleted = true;
            }
        }

        // Delete record from database
        $stmt = $pdo->prepare("DELETE FROM script_updates WHERE id = ?");
        $stmt->execute([$update_id]);

        $message = "Update v{$update['version_number']} successfully deleted.";
        if ($file_deleted) {
            $message .= " Physical update archive file removed.";
        } else {
            $message .= " (Physical archive file was not found or already deleted)";
        }

        echo json_encode([
            'status' => 'success',
            'message' => $message
        ]);
        exit();

    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        exit();
    }
} elseif ($action === 'cleanup_old') {
    /*
     * The button on Push Updates. It used to carry its own copy of the rule
     * (keep MAX(id) per project, delete the ZIP if unlink() happens to work,
     * delete the row regardless) — which is how a release could disappear from
     * the panel while its 250 MB package stayed on disk, with nothing said.
     *
     * It now runs the same rule as an automatic publish, so the button and the
     * automation can never disagree: the configured number of releases is kept,
     * the newest is never touched, and anything that cannot be deleted is
     * reported with the path and the reason instead of being ignored.
     */
    try {
        require_once __DIR__ . '/../includes/updates.php';
        $result = lm_retention_prune_all($pdo);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'message' => 'Cleanup failed: ' . $e->getMessage()]);
        exit();
    }

    $message = 'Cleanup finished: kept the newest '
        . (function_exists('lm_retention_settings') ? (int) lm_retention_settings()['keep'] : 1)
        . ' release(s) per project, deleted ' . (int) $result['deleted']
        . ' release(s) and ' . (int) $result['files'] . ' path(s).';

    if ((int) $result['failed'] > 0) {
        // Not a success with a footnote: this is the case that used to pass
        // silently, so it is reported as an error the operator must act on.
        $first = $result['errors'][0] ?? 'unknown reason';
        echo json_encode([
            'status' => 'error',
            'message' => $message . ' ' . (int) $result['failed']
                . ' could not be deleted — ' . $first
                . '. Check the file owner in cPanel (it must match the PHP user).',
            'deleted_count' => (int) $result['deleted'],
            'failed_count' => (int) $result['failed'],
            'errors' => array_slice($result['errors'], 0, 10),
        ]);
        exit();
    }

    echo json_encode([
        'status' => 'success',
        'message' => $message,
        'deleted_count' => (int) $result['deleted'],
        'failed_count' => 0,
    ]);
    exit();

} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid action.']);
    exit();
}

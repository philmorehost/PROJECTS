<?php
// ajax_delete_update.php
session_start();
header('Content-Type: application/json');

// 1. Admin Authentication Check
if (!isset($_SESSION['admin_logged_in'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit();
}

require_once('../db.php');

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
    try {
        // Keep ONLY the latest update for each tier_id
        // Group by tier_id and get the max ID
        $stmt = $pdo->query("SELECT MAX(id) as latest_id, tier_id FROM script_updates GROUP BY tier_id");
        $latest_updates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $latest_ids = array_column($latest_updates, 'latest_id');
        
        if (empty($latest_ids)) {
            echo json_encode(['status' => 'success', 'message' => 'No updates found to clean up.', 'deleted_count' => 0]);
            exit();
        }

        // Find all updates that are NOT the latest for their respective script tiers
        $in_placeholder = implode(',', array_fill(0, count($latest_ids), '?'));
        $stmt = $pdo->prepare("SELECT id, zip_path, version_number FROM script_updates WHERE id NOT IN ($in_placeholder)");
        $stmt->execute($latest_ids);
        $old_updates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($old_updates)) {
            echo json_encode(['status' => 'success', 'message' => 'Only the latest updates exist. No archives to clean up.', 'deleted_count' => 0]);
            exit();
        }

        $deleted_files_count = 0;
        $deleted_records_count = 0;

        foreach ($old_updates as $old) {
            // Delete ZIP file from storage
            if (!empty($old['zip_path']) && file_exists($old['zip_path'])) {
                if (@unlink($old['zip_path'])) {
                    $deleted_files_count++;
                }
            }
            // Delete from database
            $del_stmt = $pdo->prepare("DELETE FROM script_updates WHERE id = ?");
            $del_stmt->execute([$old['id']]);
            $deleted_records_count++;
        }

        echo json_encode([
            'status' => 'success',
            'message' => "Clean up completed: Deleted {$deleted_files_count} physical update ZIP file(s) and {$deleted_records_count} older update database record(s).",
            'deleted_count' => $deleted_records_count
        ]);
        exit();

    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'Database error during cleanup: ' . $e->getMessage()]);
        exit();
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid action.']);
    exit();
}

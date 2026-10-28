<?php
/**
 * admin/ajax_delete_update.php
 *
 * Delete one update / clean up old updates — and physically remove the
 * matching packages from the cPanel file manager (secure_updates/<TIER>/).
 *
 * Also exposes a storage inspector so the admin can see exactly which files
 * exist on disk, which are orphaned, and which records have lost their file.
 */

require_once __DIR__ . '/../includes/security.php';
lm_session_start();
lm_security_headers();

// A host-level WAF can block a POST before PHP sees it; the console retries
// with encoded values, and this turns them back into ordinary fields.
lm_request_decode();
header('Content-Type: application/json; charset=utf-8');

// A PHP warning printed into the body turns a valid JSON response into
// "Unexpected server response". Keep warnings in the error log instead.
@ini_set('display_errors', '0');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/updates.php';
require_once __DIR__ . '/../includes/notifications.php';

lm_require_admin(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    lm_json(array('status' => 'error', 'message' => 'Invalid request method.'), 405);
}

if (!lm_verify_csrf()) {
    lm_json(array('status' => 'error', 'message' => 'Session not saved — reload and try again. If it repeats, open System Check → PHP & sessions.'), 419);
}

$action = (string) ($_POST['action'] ?? 'delete_single');
$updateId = (int) ($_POST['update_id'] ?? 0);
$tierId = isset($_POST['tier_id']) && $_POST['tier_id'] !== '' ? (int) $_POST['tier_id'] : null;

if ($action === 'delete_single') {
    if ($updateId <= 0) {
        lm_json(array('status' => 'error', 'message' => 'Invalid update ID.'));
    }

    $report = lm_delete_update($pdo, $updateId);

    if (!$report['ok']) {
        lm_json(array('status' => 'error', 'message' => $report['error']));
    }

    $removed = count($report['removed']);
    $message = 'Update v' . $report['version'] . ' deleted.';
    $message .= $removed > 0
        ? ' ' . $removed . ' file/folder(s) removed from cPanel storage.'
        : ' No physical files were found (already removed).';
    if (!empty($report['notifications_deleted'])) {
        $message .= ' ' . $report['notifications_deleted'] . ' queued notification(s) cancelled.';
    }

    lm_json(array(
        'status' => 'success',
        'message' => $message,
        'removed' => $report['removed'],
        'missing' => $report['missing'],
        'version' => $report['version'],
    ));
}

if ($action === 'cleanup_old') {
    $report = lm_cleanup_old_updates($pdo, $tierId);

    if (!$report['ok'] && $report['deleted'] === 0) {
        lm_json(array('status' => 'error', 'message' => 'Cleanup failed: ' . implode(' | ', $report['errors'])));
    }

    if ($report['deleted'] === 0) {
        lm_json(array('status' => 'success', 'message' => 'Nothing to clean up — only the latest version of each project is stored.', 'deleted' => 0, 'removed' => array()));
    }

    $message = 'Cleanup complete: ' . $report['deleted'] . ' old update(s) deleted and '
        . count($report['removed']) . ' file/folder(s) physically removed from cPanel.';

    if (!empty($report['errors'])) {
        $message .= ' Warnings: ' . implode(' | ', $report['errors']);
    }

    lm_json(array(
        'status' => 'success',
        'message' => $message,
        'deleted' => $report['deleted'],
        'removed' => $report['removed'],
        'errors' => $report['errors'],
    ));
}

if ($action === 'inspect') {
    if ($updateId <= 0) {
        lm_json(array('status' => 'error', 'message' => 'Invalid update ID.'));
    }

    $stmt = $pdo->prepare(
        "SELECT u.*, t.tier_code, t.tier_name FROM script_updates u
         LEFT JOIN script_tiers t ON u.tier_id = t.id WHERE u.id = ?"
    );
    $stmt->execute(array($updateId));
    $update = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$update) {
        lm_json(array('status' => 'error', 'message' => 'Update not found.'));
    }

    $resolved = lm_resolve_update_path($update['zip_path'], $update['tier_code'] ?? null);
    $path = $resolved['path'];

    lm_json(array(
        'status' => 'success',
        'update' => array(
            'id' => (int) $update['id'],
            'version' => $update['version_number'],
            'tier' => $update['tier_code'],
            'source' => $update['source'] ?? 'manual',
            'commit' => $update['commit_sha'] ?? '',
            'changelog' => $update['changelog'],
            'release_date' => $update['release_date'],
        ),
        'stored_path' => $update['zip_path'],
        'absolute_path' => $path !== null ? $path : $update['zip_path'],
        'exists' => $resolved['exists'],
        'size' => $path !== null ? filesize($path) : 0,
        'size_human' => $path !== null ? lm_format_bytes(filesize($path)) : '—',
        'checksum' => $update['checksum'],
        'candidates' => $resolved['candidates'],
        'notifications' => lm_notification_summary($pdo, (int) $update['id']),
    ));
}

if ($action === 'storage_report') {
    $root = lm_updates_root();
    $files = array();
    $total = 0;

    if (is_dir($root)) {
        $tierDirs = glob($root . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
        if (!is_array($tierDirs)) {
            $tierDirs = array();
        }
        foreach ($tierDirs as $dir) {
            $items = glob($dir . DIRECTORY_SEPARATOR . '*.zip');
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                $size = (int) @filesize($item);
                $total += $size;
                $files[] = array(
                    'path' => $item,
                    'name' => basename($item),
                    'tier' => basename($dir),
                    'size' => $size,
                    'size_human' => lm_format_bytes($size),
                    'modified' => gmdate('Y-m-d H:i', (int) @filemtime($item)),
                    'tracked' => false,
                );
            }
        }
    }

    // Match physical files to DB records.
    $stmt = $pdo->query("SELECT u.id, u.version_number, u.zip_path, t.tier_code FROM script_updates u LEFT JOIN script_tiers t ON u.tier_id = t.id");
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $missing = array();
    $orphans = array();

    foreach ($records as $record) {
        $resolved = lm_resolve_update_path($record['zip_path'], $record['tier_code'] ?? null);
        if ($resolved['exists']) {
            foreach ($files as &$file) {
                if ($file['path'] === $resolved['path']) {
                    $file['tracked'] = true;
                    $file['version'] = $record['version_number'];
                    $file['update_id'] = (int) $record['id'];
                    break;
                }
            }
            unset($file);
        } else {
            $missing[] = array(
                'update_id' => (int) $record['id'],
                'version' => $record['version_number'],
                'tier' => $record['tier_code'],
                'stored_path' => $record['zip_path'],
            );
        }
    }

    foreach ($files as $file) {
        if (empty($file['tracked'])) {
            $orphans[] = $file;
        }
    }

    lm_json(array(
        'status' => 'success',
        'root' => $root,
        'writable' => is_writable($root),
        'total_files' => count($files),
        'total_size' => lm_format_bytes($total),
        'files' => $files,
        'missing' => $missing,
        'orphans' => $orphans,
    ));
}

if ($action === 'preview_email') {
    if ($updateId <= 0) {
        lm_json(array('status' => 'error', 'message' => 'Invalid update ID.'));
    }

    $stmt = $pdo->prepare("SELECT u.*, t.tier_code, t.tier_name FROM script_updates u JOIN script_tiers t ON u.tier_id = t.id WHERE u.id = ?");
    $stmt->execute(array($updateId));
    $update = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$update) {
        lm_json(array('status' => 'error', 'message' => 'Update not found.'));
    }

    // A real customer on this project — not a placeholder domain.
    $license = lm_preview_license($pdo, $update, (int) ($_POST['license_id'] ?? 0));
    $kind = ($_POST['kind'] ?? 'initial') === 'reminder' ? 'reminder' : 'initial';

    $email = lm_render_update_email($update, $update, $license, $kind, array(), $pdo);

    lm_json(array(
        'status' => 'success',
        'subject' => $email['subject'],
        'html' => $email['html'],
        'alt' => $email['alt'],
        'domain' => $email['domain'],
        'license_id' => (int) ($license['id'] ?? 0),
        'customer_email' => (string) ($license['customer_email'] ?? ''),
        'sample' => empty($license['id']) || empty($license['domain']),
    ));
}

lm_json(array('status' => 'error', 'message' => 'Unknown action.'), 400);

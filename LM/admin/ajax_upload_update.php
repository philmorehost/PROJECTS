<?php
require_once __DIR__ . '/../includes/security.php';
lm_session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['admin_logged_in'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit();
}

require_once('../db.php');

// Every handler is a state change: refuse a post without the token.
if (!lm_verify_csrf()) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Security token expired. Reload the page and try again.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit();
}

// 1. Validate Inputs
$tier_id = intval($_POST['tier_id'] ?? 0);
$version_number = trim($_POST['version_number'] ?? '');
$changelog = trim($_POST['changelog'] ?? '');

if ($tier_id <= 0 || empty($version_number)) {
    echo json_encode(['status' => 'error', 'message' => 'Tier and Version number are required.']);
    exit();
}

// Fetch the tier code to create the folder name
$stmt = $pdo->prepare("SELECT tier_code FROM script_tiers WHERE id = ?");
$stmt->execute([$tier_id]);
$tier = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tier) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid script tier selected.']);
    exit();
}

$tier_code = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '_', $tier['tier_code']));

// 2. Validate File Upload
if (!isset($_FILES['update_zip']) || $_FILES['update_zip']['error'] !== UPLOAD_ERR_OK) {
    $upload_err = $_FILES['update_zip']['error'] ?? 'Unknown error';
    echo json_encode(['status' => 'error', 'message' => "File upload failed with error code: {$upload_err}"]);
    exit();
}

$file = $_FILES['update_zip'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

if ($ext !== 'zip') {
    echo json_encode(['status' => 'error', 'message' => 'Only .zip files are allowed.']);
    exit();
}

// 3. Setup Secure Storage Directory Outside Web Root
// We assume 'lm' is inside the web root, so we go up two levels from 'lm/admin' to the root, then create 'secure_updates'.
// e.g. /home/user/public_html/lm/admin/ajax_upload_update.php -> /home/user/secure_updates/
$lm_dir = dirname(dirname(__DIR__)); // Gets to the parent of 'lm'
$secure_updates_dir = $lm_dir . DIRECTORY_SEPARATOR . 'secure_updates';
$tier_dir = $secure_updates_dir . DIRECTORY_SEPARATOR . $tier_code;

// Create directories if they don't exist
if (!is_dir($secure_updates_dir)) {
    if (!@mkdir($secure_updates_dir, 0755, true)) {
        echo json_encode(['status' => 'error', 'message' => "Failed to create secure_updates directory at: {$secure_updates_dir}. Check server permissions."]);
        exit();
    }
    // Create an index.php to prevent directory listing just in case it ends up in a public place
    @file_put_contents($secure_updates_dir . DIRECTORY_SEPARATOR . 'index.php', '<?php exit("Forbidden"); ?>');
}

if (!is_dir($tier_dir)) {
    if (!@mkdir($tier_dir, 0755, true)) {
        echo json_encode(['status' => 'error', 'message' => 'Failed to create tier directory.']);
        exit();
    }
}

// 4. Move File
$safe_version = preg_replace('/[^a-zA-Z0-9._-]/', '_', $version_number);
$filename = "update_{$safe_version}.zip";
$target_path = $tier_dir . DIRECTORY_SEPARATOR . $filename;

if (!move_uploaded_file($file['tmp_name'], $target_path)) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to move uploaded file to secure storage.']);
    exit();
}

// 5. Auto-inject manifest.json if missing from the ZIP
$zip = new ZipArchive();
if ($zip->open($target_path) === TRUE) {
    $has_manifest = false;

    // Check root level and one level deep for manifest.json
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        // Match manifest.json at root or one subfolder deep
        if ($name === 'manifest.json' || preg_match('#^[^/]+/manifest\.json$#', $name)) {
            $has_manifest = true;
            break;
        }
    }

    if (!$has_manifest) {
        // Build and inject a default manifest.json at the ZIP root
        $manifest = [
            'version'          => ltrim(strtolower($version_number), 'v'),
            'tier'             => strtoupper($tier['tier_code']),
            'edition'          => strtoupper($tier['tier_code']),
            'changelog'        => $changelog,
            'files_to_delete'  => [],
            'database_queries' => []
        ];
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    } else {
        // A bundled manifest must not override the tier this zip was uploaded
        // to: a NON-SAAS package uploaded under the SAAS tier would otherwise
        // keep declaring the wrong edition (and newer clients refuse it).
        // Restamp tier/edition to the target tier, in place.
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === 'manifest.json' || preg_match('#^[^/]+/manifest\.json$#', $name)) {
                $decoded = json_decode((string) $zip->getFromIndex($i), true);
                if (is_array($decoded)) {
                    $decoded['tier'] = strtoupper($tier['tier_code']);
                    $decoded['edition'] = strtoupper($tier['tier_code']);
                    $zip->addFromString($name, json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                }
                break;
            }
        }
    }

    $zip->close();
} else {
    @unlink($target_path);
    echo json_encode(['status' => 'error', 'message' => 'Could not open the uploaded ZIP file to validate it.']);
    exit();
}

// 6. Generate Checksum (after any manifest injection)
$checksum = hash_file('sha256', $target_path);

/*
 * 7. Publish through the shared function, not a local INSERT.
 *
 * This endpoint used to write the row itself: no checksum column refresh, no
 * changelog_text/format, no source/commit, and — the reason it changed — no
 * retention. A manual upload left every previous release on disk while a GitHub
 * pull removed them, so "it does not clear" came back as soon as one package
 * was published by hand.
 */
require_once __DIR__ . '/../includes/updates.php';

// The publish path needs the project's own code and name; the local sanitised
// $tier_code is a folder name and must not be written back to the row.
$tier_row = array(
    'id' => $tier_id,
    'tier_code' => (string) ($tier['tier_code'] ?? $tier_code),
    'tier_name' => (string) ($tier['tier_name'] ?? ($tier['tier_code'] ?? $tier_code)),
    'is_active' => 1,
);

try {
    $tier_stmt = $pdo->prepare("SELECT * FROM script_tiers WHERE id = ?");
    $tier_stmt->execute(array($tier_id));
    $row = $tier_stmt->fetch(PDO::FETCH_ASSOC);
    if (is_array($row)) {
        $tier_row = $row;
    }
} catch (PDOException $e) {
    // Keep the minimal row: the publish only needs the id and the code.
}

$publish = lm_publish_update($pdo, $tier_row, $version_number, $target_path, $changelog, array(
    'source' => 'manual',
    'notify' => !empty($_POST['notify']),
));

if (empty($publish['ok'])) {
    // Nothing was recorded, so the package is an orphan: remove it.
    @unlink($target_path);
    echo json_encode(['status' => 'error', 'message' => (string) $publish['error']]);
    exit();
}

$message = "Update v{$version_number} successfully published!";
if (isset($publish['retention'])) {
    $message .= ' ' . lm_retention_summary_line($publish);
}

/*
 * The publish itself succeeded — always status "success" so the page reloads and
 * the new release is visible. A cleanup failure is reported alongside it as a
 * `warning`, which the toast shows in full: calling the whole upload a failure
 * would invite a retry that publishes the same version again.
 */
echo json_encode([
    'status' => 'success',
    'message' => $message,
    'warning' => (string) ($publish['retention_warning'] ?? ''),
]);

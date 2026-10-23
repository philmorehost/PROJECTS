<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['admin_logged_in'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit();
}

require_once('../db.php');

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
            'changelog'        => $changelog,
            'files_to_delete'  => [],
            'database_queries' => []
        ];
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    $zip->close();
} else {
    @unlink($target_path);
    echo json_encode(['status' => 'error', 'message' => 'Could not open the uploaded ZIP file to validate it.']);
    exit();
}

// 6. Generate Checksum (after any manifest injection)
$checksum = hash_file('sha256', $target_path);

// 7. Update Database
try {
    // Check if this version already exists
    $check_stmt = $pdo->prepare("SELECT id FROM script_updates WHERE tier_id = ? AND version_number = ?");
    $check_stmt->execute([$tier_id, $version_number]);
    $existing = $check_stmt->fetch();
    
    if ($existing) {
        // Update existing record
        $update_stmt = $pdo->prepare("UPDATE script_updates SET zip_path = ?, checksum = ?, changelog = ?, release_date = CURRENT_TIMESTAMP, is_released = 1 WHERE id = ?");
        $update_stmt->execute([$target_path, $checksum, $changelog, $existing['id']]);
    } else {
        // Insert new record
        $insert_stmt = $pdo->prepare("INSERT INTO script_updates (tier_id, version_number, zip_path, checksum, changelog, is_released) VALUES (?, ?, ?, ?, ?, 1)");
        $insert_stmt->execute([$tier_id, $version_number, $target_path, $checksum, $changelog]);
    }
    
    echo json_encode([
        'status' => 'success', 
        'message' => "Update v{$version_number} successfully published!"
    ]);

} catch (PDOException $e) {
    // Attempt to delete the file if DB insert fails
    @unlink($target_path);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}

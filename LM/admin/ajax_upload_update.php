<?php
/**
 * admin/ajax_upload_update.php
 * Uploads a manual OTA package and publishes it (optionally notifying customers).
 */

session_start();
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
    lm_json(array('status' => 'error', 'message' => 'Your session expired. Refresh the page and try again.'), 419);
}

if (!lm_zip_available()) {
    lm_json(array('status' => 'error', 'message' => 'ZIP support is unavailable: enable the PHP zlib extension.'));
}

$tierId = (int) ($_POST['tier_id'] ?? 0);
$versionInput = trim((string) ($_POST['version_number'] ?? ''));
$changelog = trim((string) ($_POST['changelog'] ?? ''));
$changelogFormat = (($_POST['changelog_format'] ?? 'html') === 'text') ? 'text' : 'html';
$autoVersion = !empty($_POST['auto_version']);
$notify = !isset($_POST['notify']) || (string) $_POST['notify'] !== '0';
$delayMinutes = isset($_POST['notify_delay_minutes']) && $_POST['notify_delay_minutes'] !== ''
    ? max(0, (int) $_POST['notify_delay_minutes'])
    : null;
$forceVersion = !empty($_POST['force_version']);

if ($tierId <= 0) {
    lm_json(array('status' => 'error', 'message' => 'Choose the target script project.'));
}

$stmt = $pdo->prepare("SELECT * FROM script_tiers WHERE id = ?");
$stmt->execute(array($tierId));
$tier = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tier) {
    lm_json(array('status' => 'error', 'message' => 'The selected script project does not exist.'));
}

if (!isset($_FILES['update_zip']) || !is_array($_FILES['update_zip'])) {
    lm_json(array('status' => 'error', 'message' => 'No ZIP file was uploaded.'));
}

$file = $_FILES['update_zip'];

switch ((int) $file['error']) {
    case UPLOAD_ERR_OK:
        break;
    case UPLOAD_ERR_INI_SIZE:
    case UPLOAD_ERR_FORM_SIZE:
        lm_json(array('status' => 'error', 'message' => 'The ZIP is larger than the server upload limit (' . ini_get('upload_max_filesize') . '). Increase upload_max_filesize / post_max_size or use GitHub pull.'));
        break;
    case UPLOAD_ERR_NO_FILE:
        lm_json(array('status' => 'error', 'message' => 'Please select a ZIP file.'));
        break;
    default:
        lm_json(array('status' => 'error', 'message' => 'Upload failed (PHP error code ' . (int) $file['error'] . ').'));
}

if (!is_uploaded_file($file['tmp_name'])) {
    lm_json(array('status' => 'error', 'message' => 'Invalid upload.'), 400);
}

$extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if ($extension !== 'zip') {
    lm_json(array('status' => 'error', 'message' => 'Only .zip packages are accepted.'));
}

$settings = lm_settings();
$maxBytes = max(1, (int) ($settings['max_update_size_mb'] ?? 128)) * 1024 * 1024;
if ((int) $file['size'] > $maxBytes) {
    lm_json(array('status' => 'error', 'message' => 'The ZIP is larger than the configured limit (' . lm_format_bytes($maxBytes) . ').'));
}

$tmpDir = lm_storage_dir() . DIRECTORY_SEPARATOR . 'tmp';
lm_ensure_dir($tmpDir, 0700);
$tmpZip = $tmpDir . DIRECTORY_SEPARATOR . 'upload_' . bin2hex(random_bytes(6)) . '.zip';

if (!move_uploaded_file($file['tmp_name'], $tmpZip)) {
    lm_json(array('status' => 'error', 'message' => 'Could not move the uploaded file into private storage. Check folder permissions.'));
}

// Validate the archive (structure + zip-slip protection).
$inspect = lm_zip_inspect($tmpZip);
if (!$inspect['ok']) {
    @unlink($tmpZip);
    lm_json(array('status' => 'error', 'message' => $inspect['error']));
}
if ($inspect['files'] === 0) {
    @unlink($tmpZip);
    lm_json(array('status' => 'error', 'message' => 'The ZIP is empty.'));
}

// Version selection.
if ($autoVersion || $versionInput === '') {
    $version = lm_next_version($pdo, (int) $tier['id'], 'patch');
} else {
    $version = lm_version_clean($versionInput);
}

if ($version === '') {
    @unlink($tmpZip);
    lm_json(array('status' => 'error', 'message' => 'Enter a valid version number such as 7.03 or 1.4.0.'));
}

$latest = lm_latest_update_for_tier($pdo, (int) $tier['id'], false);
if (!$forceVersion && $latest && lm_version_compare($version, $latest['version_number']) <= 0) {
    @unlink($tmpZip);
    lm_json(array(
        'status' => 'error',
        'message' => 'v' . $version . ' is not newer than the current v' . $latest['version_number']
            . '. Use "Force re-publish" if you really want to overwrite it.',
    ));
}

if ($changelog === '') {
    $changelog = $changelogFormat === 'text'
        ? "- Maintenance improvements\n- Bug fixes"
        : '<ul><li>Maintenance improvements</li><li>Bug fixes</li></ul>';
}

// Anything that belongs to an install rather than to the code (settings.json,
// the encryption key, logs) is stripped when the manifest is injected below.
$runtimeFiles = lm_zip_runtime_files($tmpZip);

// Manifest so the target script knows exactly what to do.
$changelogText = $changelogFormat === 'text' ? $changelog : lm_changelog_plain($changelog, 'html');
$manifest = array(
    'version' => $version,
    'tier' => strtoupper((string) $tier['tier_code']),
    'project' => (string) $tier['tier_name'],
    'source' => 'manual',
    'released_at' => gmdate('c'),
    'changelog' => $changelogText,
    'files_to_delete' => array(),
    'database_queries' => array(),
);

if (!lm_zip_inject_manifest($tmpZip, $manifest, true)) {
    @unlink($tmpZip);
    lm_json(array('status' => 'error', 'message' => 'Could not add manifest.json to the ZIP.'));
}

$tierDir = lm_tier_storage_dir($tier, true);
$targetFile = $tierDir . DIRECTORY_SEPARATOR . 'update_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $version) . '.zip';

// Replace an older file with the same version name, if present.
$resolvedExisting = lm_resolve_update_path($targetFile, $tier['tier_code']);
if ($resolvedExisting['exists'] && $resolvedExisting['path'] !== $targetFile) {
    @unlink($resolvedExisting['path']);
}

if (!@rename($tmpZip, $targetFile)) {
    if (!@copy($tmpZip, $targetFile)) {
        @unlink($tmpZip);
        lm_json(array('status' => 'error', 'message' => 'Could not move the package to ' . $tierDir . '. Check folder permissions.'));
    }
    @unlink($tmpZip);
}
@chmod($targetFile, 0644);

$publish = lm_publish_update($pdo, $tier, $version, $targetFile, $changelogFormat === 'text' ? lm_changelog_from_text($changelog) : $changelog, array(
    'source' => 'manual',
    'changelog_format' => $changelogFormat,
    'changelog_text' => $changelogText,
    'notify' => $notify,
    'notify_delay_minutes' => $delayMinutes,
    'actor' => $_SESSION['admin_username'] ?? 'admin',
));

if (!$publish['ok']) {
    @unlink($targetFile);
    lm_json(array('status' => 'error', 'message' => $publish['error']));
}

$queue = $publish['notification_queue'] ?? array('queued' => 0, 'skipped' => 0);

$successMessage = 'v' . $version . ' published for ' . $tier['tier_name'] . '. ';
$successMessage .= $notify
    ? (int) $queue['queued'] . ' notification(s) queued' . (!empty($queue['skipped']) ? ', ' . (int) $queue['skipped'] . ' skipped' : '') . '.'
    : 'Notifications were not sent.';
if (!empty($runtimeFiles)) {
    $successMessage .= ' ' . count($runtimeFiles) . ' install file(s) were removed from the package ('
        . implode(', ', array_slice($runtimeFiles, 0, 3))
        . (count($runtimeFiles) > 3 ? ', …' : '') . ').';
}

lm_json(array(
    'stripped' => array_values($runtimeFiles),
    'status' => 'success',
    'message' => $successMessage,
    'version' => $version,
    'update_id' => $publish['id'],
    'checksum' => $publish['checksum'],
    'file_size' => $publish['file_size'],
    'zip_path' => $targetFile,
    'cpanel_dir' => $tierDir,
    'files_in_zip' => $inspect['files'],
    'had_manifest' => $inspect['has_manifest'],
    'notifications' => $queue,
));

<?php
/**
 * admin/ajax_projects.php
 *
 * Create / edit / archive script projects (script_tiers). A project row is
 * what makes a new script appear in the "Push Updates" dropdown — no code
 * changes are needed when you integrate another project.
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/updates.php';

lm_require_admin(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    lm_json(array('status' => 'error', 'message' => 'Invalid request method.'), 405);
}
if (!lm_verify_csrf()) {
    lm_json(array('status' => 'error', 'message' => 'Your session expired. Refresh the page and try again.'), 419);
}

$action = (string) ($_POST['action'] ?? '');
$id = (int) ($_POST['id'] ?? 0);

/**
 * Validate a project code.
 *
 * @param string $code
 * @return string
 */
function lm_project_code($code)
{
    $code = strtoupper(trim((string) $code));
    $code = preg_replace('/[^A-Z0-9_-]/', '', $code);
    return substr($code, 0, 20);
}

/**
 * Build a unique project key.
 *
 * @param PDO    $pdo
 * @param string $code
 * @return string
 */
function lm_project_key(PDO $pdo, $code)
{
    $base = 'PRJ-' . preg_replace('/[^A-Z0-9]/', '', strtoupper($code));
    if (strlen($base) < 6) {
        $base = 'PRJ-PROJECT';
    }
    $base = substr($base, 0, 40);

    for ($i = 0; $i < 20; $i++) {
        $candidate = $i === 0 ? $base : $base . '-' . $i;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM script_tiers WHERE project_key = ?");
        $stmt->execute(array($candidate));
        if ((int) $stmt->fetchColumn() === 0) {
            return $candidate;
        }
    }

    return 'PRJ-' . strtoupper(bin2hex(random_bytes(5)));
}

if ($action === 'create') {
    $name = trim((string) ($_POST['tier_name'] ?? ''));
    $code = lm_project_code($_POST['tier_code'] ?? '');
    $description = trim((string) ($_POST['description'] ?? ''));
    $price = trim((string) ($_POST['license_price'] ?? ''));
    $maxDomains = isset($_POST['max_domains']) && $_POST['max_domains'] !== '' ? max(1, (int) $_POST['max_domains']) : null;

    if ($name === '' || strlen($name) > 50) {
        lm_json(array('status' => 'error', 'message' => 'Project name is required (max 50 characters).'));
    }
    if (strlen($code) < 2) {
        lm_json(array('status' => 'error', 'message' => 'Project code must be at least 2 characters (A-Z, 0-9, _ or -).'));
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM script_tiers WHERE tier_code = ?");
    $stmt->execute(array($code));
    if ((int) $stmt->fetchColumn() > 0) {
        lm_json(array('status' => 'error', 'message' => 'The project code "' . $code . '" is already in use.'));
    }

    $projectKey = lm_project_key($pdo, $code);

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO script_tiers
                (tier_name, tier_code, description, project_key, is_active, license_price, max_domains, created_at)
             VALUES (?, ?, ?, ?, 1, ?, ?, NOW())"
        );
        $stmt->execute(array(
            $name,
            $code,
            $description !== '' ? $description : null,
            $projectKey,
            $price !== '' ? number_format((float) $price, 2, '.', '') : null,
            $maxDomains,
        ));
        $newId = (int) $pdo->lastInsertId();
    } catch (PDOException $e) {
        lm_json(array('status' => 'error', 'message' => 'Could not create the project: ' . $e->getMessage()));
    }

    // Create its private storage folder right away so cPanel shows it.
    $dir = lm_tier_storage_dir(array('tier_code' => $code), true);

    lm_log('projects', 'Created project ' . $name . ' (' . $code . ') id=' . $newId);

    lm_json(array(
        'status' => 'success',
        'message' => $name . ' created. It is now available in Push Updates.',
        'id' => $newId,
        'tier_code' => $code,
        'project_key' => $projectKey,
        'cpanel_dir' => $dir,
    ));
}

if ($action === 'update') {
    if ($id <= 0) {
        lm_json(array('status' => 'error', 'message' => 'Invalid project.'));
    }

    $stmt = $pdo->prepare("SELECT * FROM script_tiers WHERE id = ?");
    $stmt->execute(array($id));
    $tier = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tier) {
        lm_json(array('status' => 'error', 'message' => 'Project not found.'));
    }

    $name = trim((string) ($_POST['tier_name'] ?? $tier['tier_name']));
    $description = trim((string) ($_POST['description'] ?? (string) $tier['description']));
    $price = isset($_POST['license_price']) ? trim((string) $_POST['license_price']) : null;
    $maxDomains = isset($_POST['max_domains']) && $_POST['max_domains'] !== '' ? max(1, (int) $_POST['max_domains']) : null;
    $sortOrder = isset($_POST['sort_order']) ? (int) $_POST['sort_order'] : (int) ($tier['sort_order'] ?? 0);

    if ($name === '' || strlen($name) > 50) {
        lm_json(array('status' => 'error', 'message' => 'Project name is required (max 50 characters).'));
    }

    try {
        $stmt = $pdo->prepare(
            "UPDATE script_tiers
             SET tier_name = ?, description = ?, license_price = ?, max_domains = ?, sort_order = ?
             WHERE id = ?"
        );
        $stmt->execute(array(
            $name,
            $description !== '' ? $description : null,
            ($price !== null && $price !== '') ? number_format((float) $price, 2, '.', '') : null,
            $maxDomains,
            $sortOrder,
            $id,
        ));
    } catch (PDOException $e) {
        lm_json(array('status' => 'error', 'message' => 'Could not update the project: ' . $e->getMessage()));
    }

    lm_json(array('status' => 'success', 'message' => $name . ' updated.'));
}

if ($action === 'toggle') {
    if ($id <= 0) {
        lm_json(array('status' => 'error', 'message' => 'Invalid project.'));
    }

    $active = !empty($_POST['is_active']) ? 1 : 0;
    $stmt = $pdo->prepare("UPDATE script_tiers SET is_active = ? WHERE id = ?");
    $stmt->execute(array($active, $id));

    lm_json(array(
        'status' => 'success',
        'message' => $active ? 'Project enabled — it will appear in Push Updates.' : 'Project archived. Existing licences keep working, but new updates cannot be published.',
        'is_active' => $active,
    ));
}

if ($action === 'rotate_key') {
    if ($id <= 0) {
        lm_json(array('status' => 'error', 'message' => 'Invalid project.'));
    }

    $stmt = $pdo->prepare("SELECT * FROM script_tiers WHERE id = ?");
    $stmt->execute(array($id));
    $tier = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tier) {
        lm_json(array('status' => 'error', 'message' => 'Project not found.'));
    }

    $key = lm_project_key($pdo, $tier['tier_code'] . '-' . substr(bin2hex(random_bytes(3)), 0, 4));
    $pdo->prepare("UPDATE script_tiers SET project_key = ? WHERE id = ?")->execute(array($key, $id));

    lm_json(array('status' => 'success', 'message' => 'New project key generated.', 'project_key' => $key));
}

if ($action === 'delete') {
    if ($id <= 0) {
        lm_json(array('status' => 'error', 'message' => 'Invalid project.'));
    }

    $stmt = $pdo->prepare("SELECT * FROM script_tiers WHERE id = ?");
    $stmt->execute(array($id));
    $tier = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tier) {
        lm_json(array('status' => 'error', 'message' => 'Project not found.'));
    }

    $licenseCount = (int) $pdo->query("SELECT COUNT(*) FROM licenses WHERE tier_id = " . (int) $id)->fetchColumn();
    $updateCount = (int) $pdo->query("SELECT COUNT(*) FROM script_updates WHERE tier_id = " . (int) $id)->fetchColumn();

    if ($licenseCount > 0 || $updateCount > 0) {
        lm_json(array(
            'status' => 'error',
            'message' => 'This project still has ' . $licenseCount . ' licence(s) and ' . $updateCount
                . ' update(s). Archive it instead, or move/delete those records first.',
        ));
    }

    $dir = lm_tier_storage_dir($tier, false);
    $removedFiles = 0;

    if (is_dir($dir)) {
        foreach ((array) glob($dir . DIRECTORY_SEPARATOR . '*') as $file) {
            if (basename($file) === 'index.php') {
                continue;
            }
            if (is_file($file) && @unlink($file)) {
                $removedFiles++;
            }
        }
        @rmdir($dir);
    }

    $pdo->prepare("DELETE FROM script_tiers WHERE id = ?")->execute(array($id));
    lm_log('projects', 'Deleted project ' . $tier['tier_code'] . ' (id=' . $id . '), removed ' . $removedFiles . ' file(s).');

    lm_json(array(
        'status' => 'success',
        'message' => 'Project deleted.' . ($removedFiles > 0 ? ' ' . $removedFiles . ' stored package(s) removed from cPanel.' : ''),
    ));
}

lm_json(array('status' => 'error', 'message' => 'Unknown action.'), 400);

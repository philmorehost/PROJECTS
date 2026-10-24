<?php
/**
 * admin/ajax_projects.php
 *
 * Create / edit / archive script projects (script_tiers). A project row is
 * what makes a new script appear in the "Push Updates" dropdown — no code
 * changes are needed when you integrate another project.
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

    /*
     * Deleting a project is allowed even when it has licences and packages —
     * it is the admin's panel. What it must never do is destroy customer keys
     * silently, so the first call returns a plan and the second one carries an
     * explicit decision about the licences.
     */
    $count = function ($sql, array $params) use ($pdo) {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            return 0; // older schema: the table is not there yet
        }
    };

    $licences = $count("SELECT COUNT(*) FROM licenses WHERE tier_id = ?", array($id));
    $updates = $count("SELECT COUNT(*) FROM script_updates WHERE tier_id = ?", array($id));
    $pulls = $count("SELECT COUNT(*) FROM github_pulls WHERE tier_id = ?", array($id));
    $jobs = $count("SELECT COUNT(*) FROM github_pull_jobs WHERE tier_id = ?", array($id));
    $jobsRunning = $count("SELECT COUNT(*) FROM github_pull_jobs WHERE tier_id = ? AND status IN ('queued','running')", array($id));
    $notifications = $count("SELECT COUNT(*) FROM update_notifications WHERE tier_id = ?", array($id));

    $dir = lm_tier_storage_dir($tier, false);
    $packageFiles = 0;
    $packageBytes = 0;
    if (is_dir($dir)) {
        foreach ((array) glob($dir . DIRECTORY_SEPARATOR . '*') as $file) {
            if (is_file($file) && basename($file) !== 'index.php') {
                $packageFiles++;
                $packageBytes += (int) @filesize($file);
            }
        }
    }

    $confirm = (string) ($_POST['confirm'] ?? '') === '1';
    $licenceMode = (string) ($_POST['licence_mode'] ?? '');

    // Where could the licences go instead? Any other project.
    $targets = array();
    try {
        $stmt = $pdo->prepare("SELECT id, tier_name, tier_code FROM script_tiers WHERE id <> ? ORDER BY sort_order ASC, id ASC");
        $stmt->execute(array($id));
        foreach ((array) $stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $targets[] = array(
                'id' => (int) $row['id'],
                'name' => (string) $row['tier_name'],
                'code' => (string) $row['tier_code'],
            );
        }
    } catch (PDOException $e) {
        // sort_order may not exist on a very old schema; the select above is optional
    }

    if (!$confirm) {
        lm_json(array(
            'status' => 'confirm',
            'code' => 'delete_needs_confirmation',
            'project' => array(
                'id' => $id,
                'name' => (string) $tier['tier_name'],
                'code' => (string) $tier['tier_code'],
            ),
            'targets' => $targets,
            'impact' => array(
                'licences' => $licences,
                'updates' => $updates,
                'package_files' => $packageFiles,
                'package_bytes' => $packageBytes,
                'package_size' => lm_format_bytes($packageBytes),
                'pulls' => $pulls,
                'jobs' => $jobs,
                'jobs_running' => $jobsRunning,
                'notifications' => $notifications,
            ),
            'message' => ($licences > 0
                ? 'This project has ' . $licences . ' licence' . ($licences === 1 ? '' : 's') . '. Decide whether they go with it.'
                : 'Ready to delete.')
                . ($jobsRunning > 0 ? ' A GitHub pull is running for it and will be cancelled.' : ''),
        ));
    }

    if ($licences > 0 && $licenceMode !== 'detach' && $licenceMode !== 'delete') {
        lm_json(array(
            'status' => 'error',
            'message' => 'Choose what happens to the ' . $licences . ' licence(s): keep them (detach) or delete them.',
            'code' => 'licence_mode_required',
        ));
    }

    // A licence can move to another project instead of being left unassigned.
    $moveTo = (int) ($_POST['move_to'] ?? 0);
    $moveTarget = null;
    if ($moveTo > 0 && $moveTo !== $id) {
        try {
            $stmt = $pdo->prepare("SELECT id, tier_name FROM script_tiers WHERE id = ?");
            $stmt->execute(array($moveTo));
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $moveTarget = $row;
            }
        } catch (PDOException $e) {
            $moveTarget = null;
        }
    }

    $detached = 0;
    $moved = 0;
    $licencesRemoved = 0;

    try {
        $pdo->beginTransaction();

        if ($licences > 0) {
            if ($licenceMode === 'delete') {
                /*
                 * Deleting the keys is a real loss for the customer, so the
                 * history that explains them stays: transactions keep their
                 * row with license_id cleared, and requests keep their note.
                 */
                $stmt = $pdo->prepare("SELECT id FROM licenses WHERE tier_id = ?");
                $stmt->execute(array($id));
                $licenceIds = array_map('intval', (array) $stmt->fetchAll(PDO::FETCH_COLUMN));

                if (!empty($licenceIds)) {
                    $in = implode(',', $licenceIds);
                    $pdo->exec("DELETE FROM licensed_domains WHERE license_id IN (" . $in . ")");
                    $pdo->exec("UPDATE transactions SET license_id = NULL WHERE license_id IN (" . $in . ")");
                    $pdo->exec("UPDATE license_requests SET license_id = NULL WHERE license_id IN (" . $in . ")");
                    $pdo->exec("DELETE FROM download_tokens WHERE license_id IN (" . $in . ")");
                    $pdo->exec("DELETE FROM licenses WHERE id IN (" . $in . ")");
                    $licencesRemoved = count($licenceIds);
                }
            } elseif ($moveTarget !== null) {
                $stmt = $pdo->prepare("UPDATE licenses SET tier_id = ? WHERE tier_id = ?");
                $stmt->execute(array((int) $moveTarget['id'], $id));
                $moved = $stmt->rowCount();
            } else {
                $stmt = $pdo->prepare("UPDATE licenses SET tier_id = NULL WHERE tier_id = ?");
                $stmt->execute(array($id));
                $detached = $stmt->rowCount();
            }
        }

        // Packages that will never be served again.
        try {
            $pdo->prepare("DELETE FROM download_tokens WHERE update_id IN (SELECT id FROM script_updates WHERE tier_id = ?)")
                ->execute(array($id));
        } catch (PDOException $e) {
            // download_tokens may not exist on a very old install
        }

        $stmt = $pdo->prepare("DELETE FROM update_notifications WHERE tier_id = ?");
        $stmt->execute(array($id));
        $notificationsRemoved = $stmt->rowCount();

        $stmt = $pdo->prepare("DELETE FROM script_updates WHERE tier_id = ?");
        $stmt->execute(array($id));
        $updatesRemoved = $stmt->rowCount();

        try {
            $pdo->prepare("DELETE FROM github_pulls WHERE tier_id = ?")->execute(array($id));
        } catch (PDOException $e) {
            // ignore
        }
        try {
            $pdo->prepare("DELETE FROM github_pull_jobs WHERE tier_id = ?")->execute(array($id));
        } catch (PDOException $e) {
            // ignore
        }

        $pdo->prepare("DELETE FROM script_tiers WHERE id = ?")->execute(array($id));

        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        lm_log('projects', 'Delete failed for tier ' . $id . ': ' . $e->getMessage());
        lm_json(array(
            'status' => 'error',
            'message' => 'The database refused the delete: ' . $e->getMessage()
                . ' Nothing was removed. Run the database repair tool, then try again.',
        ));
    }

    // Files after the row is gone: a failed unlink is a warning, not a failure.
    $removedFiles = 0;
    $removedBytes = 0;
    $keptFiles = array();
    if (is_dir($dir)) {
        foreach ((array) glob($dir . DIRECTORY_SEPARATOR . '*') as $file) {
            if (basename($file) === 'index.php') {
                continue;
            }
            if (is_file($file) && @unlink($file)) {
                $removedFiles++;
                $removedBytes += (int) @filesize($file);
            } elseif (is_file($file)) {
                $keptFiles[] = basename($file);
            }
        }
        @rmdir($dir);
    }

    $summary = array();
    if ($moved > 0) {
        $summary[] = $moved . ' licence' . ($moved === 1 ? '' : 's') . ' moved to '
            . (string) ($moveTarget['tier_name'] ?? 'another project');
    }
    if ($detached > 0) {
        $summary[] = $detached . ' licence' . ($detached === 1 ? '' : 's') . ' kept and detached from the project';
    }
    if ($licencesRemoved > 0) {
        $summary[] = $licencesRemoved . ' licence' . ($licencesRemoved === 1 ? '' : 's') . ' deleted';
    }
    if ($updatesRemoved > 0) {
        $summary[] = $updatesRemoved . ' version' . ($updatesRemoved === 1 ? '' : 's') . ' removed';
    }
    if ($removedFiles > 0) {
        $summary[] = $removedFiles . ' package file' . ($removedFiles === 1 ? '' : 's') . ' (' . lm_format_bytes($removedBytes) . ') deleted from cPanel';
    }
    if ($notificationsRemoved > 0) {
        $summary[] = $notificationsRemoved . ' queued notice' . ($notificationsRemoved === 1 ? '' : 's') . ' cancelled';
    }
    if ($jobs > 0) {
        $summary[] = $jobs . ' pull job' . ($jobs === 1 ? '' : 's') . ' cleared';
    }

    lm_log('projects', 'Deleted project ' . $tier['tier_code'] . ' (id=' . $id . '): '
        . ($summary ? implode('; ', $summary) : 'no records to remove') . '.');

    lm_json(array(
        'status' => 'success',
        'message' => 'Project ' . $tier['tier_name'] . ' deleted.'
            . ($summary ? ' ' . implode('. ', $summary) . '.' : '')
            . ($keptFiles ? ' ' . count($keptFiles) . ' file(s) could not be removed — delete them in cPanel file manager.' : ''),
        'deleted' => array(
            'licences_moved' => $moved,
            'moved_to' => $moveTarget !== null ? (string) $moveTarget['tier_name'] : '',
            'licences_detached' => $detached,
            'licences_deleted' => $licencesRemoved,
            'updates' => $updatesRemoved,
            'files' => $removedFiles,
            'notifications' => $notificationsRemoved,
            'jobs' => $jobs,
        ),
        'kept_files' => $keptFiles,
    ));
}

lm_json(array('status' => 'error', 'message' => 'Unknown action.'), 400);

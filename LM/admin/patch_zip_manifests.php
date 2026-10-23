<?php
// patch_zip_manifests.php
// Patches all stored update ZIPs that are missing manifest.json.
// Run once, then DELETE this file.

session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    die('Unauthorized. Log in to the License Manager admin first.');
}

require_once('../db.php');

$results = [];
$action = $_POST['action'] ?? '';

if ($action === 'patch') {
    // Fetch all update records
    $stmt = $pdo->query("SELECT u.*, t.tier_code FROM script_updates u JOIN script_tiers t ON u.tier_id = t.id ORDER BY u.id ASC");
    $updates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($updates as $upd) {
        $zip_path = $upd['zip_path'];
        $result = ['id' => $upd['id'], 'version' => $upd['version_number'], 'tier' => $upd['tier_code'], 'zip' => basename($zip_path)];

        if (!file_exists($zip_path)) {
            // Try relative path fallback
            $relative = dirname(dirname(__DIR__)) . '/' . ltrim($zip_path, '/\\');
            if (file_exists($relative)) {
                $zip_path = $relative;
            } else {
                $result['status'] = 'error';
                $result['message'] = 'ZIP file not found at: ' . $upd['zip_path'];
                $results[] = $result;
                continue;
            }
        }

        $zip = new ZipArchive();
        if ($zip->open($zip_path) !== TRUE) {
            $result['status'] = 'error';
            $result['message'] = 'Could not open ZIP file.';
            $results[] = $result;
            continue;
        }

        // Check if manifest.json already exists
        $has_manifest = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === 'manifest.json' || preg_match('#^[^/]+/manifest\.json$#', $name)) {
                $has_manifest = true;
                break;
            }
        }

        if ($has_manifest) {
            $zip->close();
            $result['status'] = 'skipped';
            $result['message'] = 'Already has manifest.json — no patch needed.';
            $results[] = $result;
            continue;
        }

        // Inject manifest.json
        $manifest = [
            'version'          => ltrim(strtolower($upd['version_number']), 'v'),
            'tier'             => strtoupper($upd['tier_code']),
            'changelog'        => $upd['changelog'],
            'files_to_delete'  => [],
            'database_queries' => []
        ];
        $added = $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $zip->close();

        if (!$added) {
            $result['status'] = 'error';
            $result['message'] = 'Failed to inject manifest.json into ZIP.';
            $results[] = $result;
            continue;
        }

        // Regenerate checksum
        $new_checksum = hash_file('sha256', $zip_path);

        // Update DB checksum
        $upd_stmt = $pdo->prepare("UPDATE script_updates SET checksum = ? WHERE id = ?");
        $upd_stmt->execute([$new_checksum, $upd['id']]);

        $result['status'] = 'patched';
        $result['message'] = 'manifest.json injected. New checksum: ' . substr($new_checksum, 0, 16) . '...';
        $results[] = $result;
    }
}

// Fetch current state for display
$all_updates = $pdo->query("SELECT u.*, t.tier_code FROM script_updates u JOIN script_tiers t ON u.tier_id = t.id ORDER BY u.id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Patch ZIP Manifests – License Manager</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f1f5f9; margin: 0; padding: 2rem; color: #1e293b; }
        h1 { color: #0f172a; margin-bottom: 0.25rem; }
        .subtitle { color: #64748b; margin-bottom: 2rem; font-size: 0.9rem; }
        .card { background: white; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); padding: 1.5rem 2rem; margin-bottom: 2rem; }
        h2 { font-size: 1.1rem; margin: 0 0 1rem; color: #334155; }
        table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
        th { background: #f8fafc; padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #475569; border-bottom: 2px solid #e2e8f0; }
        td { padding: 0.75rem 1rem; border-bottom: 1px solid #f1f5f9; }
        .badge { padding: 0.2rem 0.6rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 700; }
        .badge-patched { background: #dcfce7; color: #15803d; }
        .badge-skipped { background: #e0e7ff; color: #3730a3; }
        .badge-error { background: #fee2e2; color: #b91c1c; }
        .btn-patch { background: linear-gradient(135deg, #ef4444, #b91c1c); color: white; border: none; padding: 0.9rem 2rem; border-radius: 10px; font-size: 1rem; font-weight: 700; cursor: pointer; }
        .btn-patch:hover { opacity: 0.9; }
        .warning { background: #fef3c7; border: 1px solid #fcd34d; border-radius: 8px; padding: 1rem 1.25rem; margin-bottom: 1.5rem; font-size: 0.875rem; color: #78350f; }
        .result-msg { font-size: 0.8rem; color: #64748b; }
    </style>
</head>
<body>
<h1>🩹 Patch ZIP Manifests</h1>
<p class="subtitle">Injects a <code>manifest.json</code> into any stored update ZIP that is missing one, then regenerates the checksum. <strong>Delete this file after use.</strong></p>

<div class="warning">
    ⚠️ <strong>This operation modifies the stored ZIP files on disk.</strong> It is safe to run — it only adds the manifest if missing and never removes existing files. Run this once to fix ZIPs uploaded before the auto-inject feature was added.
</div>

<?php if (!empty($results)): ?>
<div class="card">
    <h2>Patch Results</h2>
    <table>
        <thead><tr><th>ID</th><th>Version</th><th>Tier</th><th>File</th><th>Result</th><th>Detail</th></tr></thead>
        <tbody>
        <?php foreach ($results as $r): ?>
        <tr>
            <td><?= $r['id'] ?></td>
            <td><strong>v<?= htmlspecialchars($r['version']) ?></strong></td>
            <td><?= htmlspecialchars($r['tier']) ?></td>
            <td><code style="font-size:0.75rem"><?= htmlspecialchars($r['zip']) ?></code></td>
            <td><span class="badge badge-<?= $r['status'] ?>"><?= strtoupper($r['status']) ?></span></td>
            <td class="result-msg"><?= htmlspecialchars($r['message']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div class="card">
    <h2>All Stored Update Packages</h2>
    <table>
        <thead><tr><th>ID</th><th>Tier</th><th>Version</th><th>ZIP Path</th><th>Is Released</th></tr></thead>
        <tbody>
        <?php foreach ($all_updates as $u): ?>
        <tr>
            <td><?= $u['id'] ?></td>
            <td><span class="badge" style="background:#e0e7ff;color:#3730a3"><?= htmlspecialchars($u['tier_code']) ?></span></td>
            <td><strong>v<?= htmlspecialchars($u['version_number']) ?></strong></td>
            <td><code style="font-size:0.75rem;color:#94a3b8"><?= htmlspecialchars($u['zip_path']) ?></code></td>
            <td><?= $u['is_released'] ? '✅ Yes' : '❌ No' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<form method="POST">
    <input type="hidden" name="action" value="patch">
    <button type="submit" class="btn-patch">🩹 Patch All Missing Manifests Now</button>
</form>

</body>
</html>

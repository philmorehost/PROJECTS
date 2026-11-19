<?php
/**
 * admin/patch_zip_manifests.php — add a manifest.json to older packages.
 *
 * Releases uploaded before the auto-manifest feature have no manifest.json
 * inside the ZIP. This injects one, then rewrites the stored checksum so the
 * package still verifies. Additive: nothing is ever removed from a ZIP.
 *
 * @package LicenseManager
 */

require_once __DIR__ . '/../includes/app_ui.php';
lm_session_start();
lm_security_headers();

$pdo = lm_app_boot();

$results = array();
$updates = array();
$problem = '';

if (!$pdo instanceof PDO) {
    $problem = 'The database is not connected, so there is nothing to patch.';
} elseif (extension_loaded('zip') === false) {
    $problem = 'The PHP zip extension is not loaded, so ZIP files cannot be opened on this server.';
} else {
    if (lm_app_handle_post() && (string) ($_POST['action'] ?? '') === 'patch') {
        try {
            $rows = $pdo->query(
                'SELECT u.*, t.tier_code FROM script_updates u '
                . 'JOIN script_tiers t ON u.tier_id = t.id ORDER BY u.id ASC'
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $rows = array();
            $problem = 'The releases could not be read: ' . $e->getMessage();
        }

        foreach ($rows as $row) {
            $zipPath = (string) $row['zip_path'];
            $result = array(
                'id'      => (int) $row['id'],
                'version' => (string) $row['version_number'],
                'tier'    => (string) $row['tier_code'],
                'zip'     => basename($zipPath),
                'status'  => 'error',
                'message' => '',
            );

            if (!is_file($zipPath)) {
                // Older rows store a path relative to the site root.
                $candidate = dirname(dirname(__DIR__)) . '/' . ltrim($zipPath, '/\\');
                if (is_file($candidate)) {
                    $zipPath = $candidate;
                } else {
                    $result['message'] = 'ZIP not found at ' . $row['zip_path'];
                    $results[] = $result;
                    continue;
                }
            }

            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) {
                $result['message'] = 'The ZIP could not be opened (it may be corrupt).';
                $results[] = $result;
                continue;
            }

            $hasManifest = false;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if ($name === 'manifest.json' || preg_match('#^[^/]+/manifest\.json$#', $name)) {
                    $hasManifest = true;
                    break;
                }
            }

            if ($hasManifest) {
                $zip->close();
                $result['status'] = 'skipped';
                $result['message'] = 'Already has manifest.json.';
                $results[] = $result;
                continue;
            }

            $manifest = array(
                'version'          => ltrim(strtolower((string) $row['version_number']), 'v'),
                'tier'             => strtoupper((string) $row['tier_code']),
                'edition'          => strtoupper((string) $row['tier_code']),
                'changelog'        => (string) ($row['changelog'] ?? ''),
                'files_to_delete'  => array(),
                'database_queries' => array(),
            );

            $added = $zip->addFromString(
                'manifest.json',
                (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );
            $zip->close();

            if (!$added) {
                $result['message'] = 'manifest.json could not be written into the ZIP.';
                $results[] = $result;
                continue;
            }

            $checksum = hash_file('sha256', $zipPath);
            try {
                $stmt = $pdo->prepare('UPDATE script_updates SET checksum = ? WHERE id = ?');
                $stmt->execute(array($checksum, (int) $row['id']));
            } catch (PDOException $e) {
                $result['message'] = 'Manifest added but the checksum row was not updated: ' . $e->getMessage();
                $results[] = $result;
                continue;
            }

            $result['status'] = 'patched';
            $result['message'] = 'manifest.json injected. New checksum: ' . substr($checksum, 0, 16) . '…';
            $results[] = $result;
        }
    }

    try {
        $updates = $pdo->query(
            'SELECT u.id, u.version_number, u.zip_path, u.is_released, t.tier_code '
            . 'FROM script_updates u JOIN script_tiers t ON u.tier_id = t.id ORDER BY u.id DESC'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $updates = array();
    }
}

$actionsHtml = '<a class="lm-btn lm-btn--ghost" href="updates.php">' . lm_icon('rocket') . ' Push updates</a>';

lm_app_start('Package manifests', array(
    'subtitle' => 'Add a manifest.json to releases that were uploaded before it was generated automatically.',
    'active'   => 'manifests',
    'actions'  => $actionsHtml,
));
?>

<?php if ($problem !== ''): ?>
    <?php lm_alert('error', $problem); ?>
<?php endif; ?>

<div class="lm-note lm-note--warn">
    <?= lm_icon('alert') ?>
    <span><strong>This edits the ZIP files on disk.</strong> It only ever adds a missing
        <code>manifest.json</code> and rewrites the checksum afterwards — it never removes a file.
        Run it once, then delete this page from the server if you do not want it reachable.</span>
</div>

<?php if (!empty($results)): ?>
    <?php
    $patched = 0;
    $skipped = 0;
    $failed = 0;
    foreach ($results as $row) {
        if ($row['status'] === 'patched') {
            $patched++;
        } elseif ($row['status'] === 'skipped') {
            $skipped++;
        } else {
            $failed++;
        }
    }
    ?>
    <div class="lm-stats">
        <?php
        lm_app_stat('Patched', (string) $patched, 'Manifest injected', $patched > 0 ? 'green' : '');
        lm_app_stat('Skipped', (string) $skipped, 'Already had one');
        lm_app_stat('Failed', (string) $failed, 'Could not be opened or written', $failed > 0 ? 'red' : '');
        ?>
    </div>

    <section class="lm-panel">
        <div class="lm-panel__head">
            <div>
                <h2><?= lm_icon('check') ?> Patch results</h2>
                <p>One row per stored package.</p>
            </div>
        </div>
        <div class="lm-panel__body lm-panel__body--flush">
            <div class="lm-tablewrap">
                <table class="lm-table lm-table--stack">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Version</th>
                            <th>Project</th>
                            <th>Package</th>
                            <th>Result</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results as $row): ?>
                            <?php
                            $tone = $row['status'] === 'patched' ? 'ok' : ($row['status'] === 'skipped' ? 'info' : 'bad');
                            ?>
                            <tr>
                                <td data-label="ID"><?= (int) $row['id'] ?></td>
                                <td data-label="Version"><strong>v<?= lm_e($row['version']) ?></strong></td>
                                <td data-label="Project"><?= lm_app_badge($row['tier'], 'info') ?></td>
                                <td data-label="Package"><code class="lm-tiny"><?= lm_e($row['zip']) ?></code></td>
                                <td data-label="Result">
                                    <?= lm_app_badge(ucfirst($row['status']), $tone) ?>
                                    <span class="lm-help" style="display:block"><?= lm_e($row['message']) ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
<?php endif; ?>

<section class="lm-panel">
    <div class="lm-panel__head">
        <div>
            <h2><?= lm_icon('layers') ?> Stored packages</h2>
            <p><?= count($updates) ?> release(s) on record.</p>
        </div>
    </div>
    <div class="lm-panel__body lm-panel__body--flush">
        <?php if (empty($updates)): ?>
            <?php lm_app_empty('layers', 'No packages', 'Nothing has been published on this install yet.', ''); ?>
        <?php else: ?>
            <div class="lm-tablewrap">
                <table class="lm-table lm-table--stack">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Project</th>
                            <th>Version</th>
                            <th>In folder</th>
                            <th>Released</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($updates as $update): ?>
                            <?php $path = (string) $update['zip_path']; ?>
                            <tr>
                                <td data-label="ID"><?= (int) $update['id'] ?></td>
                                <td data-label="Project"><?= lm_app_badge((string) $update['tier_code'], 'info') ?></td>
                                <td data-label="Version"><strong>v<?= lm_e((string) $update['version_number']) ?></strong></td>
                                <td data-label="In folder">
                                    <?= lm_app_badge(is_file($path) ? 'Present' : 'Missing', is_file($path) ? 'ok' : 'bad') ?>
                                    <span class="lm-help" style="display:block;word-break:break-all"><?= lm_e($path) ?></span>
                                </td>
                                <td data-label="Released"><?= !empty($update['is_released']) ? 'Yes' : 'No' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<section class="lm-panel">
    <div class="lm-panel__head">
        <div>
            <h2><?= lm_icon('refresh') ?> Run the patch</h2>
            <p>Every package is checked; only the ones with no manifest are touched.</p>
        </div>
    </div>
    <div class="lm-panel__body">
        <form method="POST">
            <?= lm_csrf_field() ?>
            <input type="hidden" name="action" value="patch">
            <button type="submit" class="lm-btn lm-btn--glow">
                <?= lm_icon('check') ?> Patch missing manifests
            </button>
        </form>
    </div>
</section>

<?php lm_app_end(); ?>

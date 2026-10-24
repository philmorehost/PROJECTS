<?php
/**
 * admin/projects.php — manage the script projects served by this License Manager.
 *
 * A project is a row in script_tiers. As soon as one exists it shows up in
 * Push Updates and GitHub Pull, gets its own storage folder in cPanel, and can
 * be queried by the OTA endpoint with its project key.
 */

require_once __DIR__ . '/../includes/security.php';
lm_session_start();
lm_security_headers();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit();
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/_ui.php';
require_once __DIR__ . '/../includes/updates.php';

$tiers = $pdo->query("SELECT * FROM script_tiers ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
$siteUrl = lm_site_url();

$licenceCounts = array();
$versionCounts = array();
$latestVersions = array();

try {
    foreach ($pdo->query("SELECT tier_id, COUNT(*) AS total FROM licenses WHERE tier_id IS NOT NULL GROUP BY tier_id") as $row) {
        $licenceCounts[(int) $row['tier_id']] = (int) $row['total'];
    }
} catch (PDOException $e) {
    // licenses.tier_id may not exist on a very old schema
}

try {
    foreach ($pdo->query("SELECT tier_id, COUNT(*) AS total FROM script_updates GROUP BY tier_id") as $row) {
        $versionCounts[(int) $row['tier_id']] = (int) $row['total'];
    }
} catch (PDOException $e) {
    // ignore
}

foreach ($tiers as $tier) {
    $latest = lm_latest_update_for_tier($pdo, (int) $tier['id'], false);
    $latestVersions[(int) $tier['id']] = $latest ? $latest['version_number'] : '';
}

$totalProjects = count($tiers);
$activeProjects = 0;
foreach ($tiers as $tier) {
    if (!isset($tier['is_active']) || (int) $tier['is_active'] === 1) {
        $activeProjects++;
    }
}

$totalLicences = array_sum($licenceCounts);
$totalVersions = array_sum($versionCounts);
$storageRoot = lm_updates_root();

lm_admin_page_start('Script projects', 'projects');
?>

<div class="page-head">
    <div>
        <h1>Script projects</h1>
        <p>Every project you add here becomes a target for Push Updates and GitHub Pull. Each one gets its own storage folder in cPanel, its own version history and its own licence pool.</p>
    </div>
    <div class="head-actions">
        <?= lm_guide_help_button('projects') ?>
        <button class="btn btn-primary" type="button" onclick="newProject()">＋ New project</button>
    </div>
</div>

<div class="stats">
    <div class="stat">
        <div class="label">Projects</div>
        <div class="value"><?= $totalProjects ?></div>
        <div class="sub"><?= $activeProjects ?> active, <?= $totalProjects - $activeProjects ?> archived</div>
    </div>
    <div class="stat">
        <div class="label">Licences</div>
        <div class="value"><?= $totalLicences ?></div>
        <div class="sub">across all projects</div>
    </div>
    <div class="stat">
        <div class="label">Published versions</div>
        <div class="value"><?= $totalVersions ?></div>
        <div class="sub">stored as ZIP packages</div>
    </div>
    <div class="stat">
        <div class="label">Storage folder</div>
        <div class="value" style="font-size:14px;font-weight:700;letter-spacing:0;line-height:1.5;word-break:break-all;margin-top:12px;"><?= htmlspecialchars($storageRoot) ?></div>
        <div class="sub">visible in cPanel → File Manager</div>
    </div>
</div>

<?php if (empty($tiers)): ?>
    <div class="card">
        <div class="empty">
            <div style="font-size:34px;margin-bottom:10px;">🧩</div>
            No script projects yet. Create one to start publishing updates.
            <div style="margin-top:16px;"><button class="btn btn-primary" type="button" onclick="newProject()">Create the first project</button></div>
        </div>
    </div>
<?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(330px,1fr));gap:18px;">
        <?php foreach ($tiers as $tier): ?>
            <?php
            $tierId = (int) $tier['id'];
            $isActive = !isset($tier['is_active']) || (int) $tier['is_active'] === 1;
            $latest = isset($latestVersions[$tierId]) ? $latestVersions[$tierId] : '';
            $licences = isset($licenceCounts[$tierId]) ? $licenceCounts[$tierId] : 0;
            $versions = isset($versionCounts[$tierId]) ? $versionCounts[$tierId] : 0;
            $hasRepo = trim((string) ($tier['github_repo'] ?? '')) !== '';
            $tierDir = lm_tier_storage_dir($tier, false);
            $size = 0;
            foreach ((array) glob($tierDir . '/*.zip') as $file) {
                $size += (int) @filesize($file);
            }
            ?>
            <div class="card" style="margin:0;<?= $isActive ? '' : 'opacity:.72;' ?>">
                <div class="card-body">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;">
                        <div>
                            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                <span class="badge badge-indigo"><?= htmlspecialchars($tier['tier_code']) ?></span>
                                <?php if ($isActive): ?>
                                    <span class="badge badge-green">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-slate">Archived</span>
                                <?php endif; ?>
                            </div>
                            <h3 style="margin-top:10px;font-size:17px;font-weight:800;letter-spacing:-0.3px;"><?= htmlspecialchars($tier['tier_name']) ?></h3>
                            <?php if (!empty($tier['description'])): ?>
                                <p class="tiny muted" style="margin-top:5px;line-height:1.6;"><?= htmlspecialchars($tier['description']) ?></p>
                            <?php endif; ?>
                        </div>
                        <div style="text-align:right;">
                            <div class="tiny muted">Latest</div>
                            <div class="mono" style="font-weight:800;font-size:15px;margin-top:3px;"><?= $latest !== '' ? 'v' . htmlspecialchars($latest) : '—' ?></div>
                        </div>
                    </div>

                    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:18px;">
                        <div style="background:#f8fafc;border-radius:10px;padding:10px 12px;">
                            <div class="tiny muted">Licences</div>
                            <div style="font-weight:800;font-size:16px;margin-top:3px;"><?= $licences ?></div>
                        </div>
                        <div style="background:#f8fafc;border-radius:10px;padding:10px 12px;">
                            <div class="tiny muted">Versions</div>
                            <div style="font-weight:800;font-size:16px;margin-top:3px;"><?= $versions ?></div>
                        </div>
                        <div style="background:#f8fafc;border-radius:10px;padding:10px 12px;">
                            <div class="tiny muted">Packages</div>
                            <div style="font-weight:800;font-size:16px;margin-top:3px;"><?= htmlspecialchars(lm_format_bytes($size)) ?></div>
                        </div>
                    </div>

                    <div class="tiny muted mono" style="margin-top:14px;word-break:break-all;line-height:1.6;">
                        🔑 <?= htmlspecialchars((string) ($tier['project_key'] ?? '—')) ?>
                    </div>
                    <div class="tiny muted" style="margin-top:6px;">
                        <?php if ($hasRepo): ?>
                            🐙 <?= htmlspecialchars((string) $tier['github_repo']) ?><?= !empty($tier['github_branch']) ? ' @ ' . htmlspecialchars((string) $tier['github_branch']) : '' ?>
                        <?php else: ?>
                            No GitHub repository linked
                        <?php endif; ?>
                    </div>

                    <div style="display:flex;gap:7px;flex-wrap:wrap;margin-top:18px;">
                        <button class="btn btn-ghost btn-sm" type="button" onclick='openEdit(<?= json_encode(array(
                            'id' => $tierId,
                            'tier_name' => $tier['tier_name'],
                            'tier_code' => $tier['tier_code'],
                            'description' => (string) ($tier['description'] ?? ''),
                            'license_price' => (string) ($tier['license_price'] ?? ''),
                            'max_domains' => (string) ($tier['max_domains'] ?? ''),
                            'sort_order' => (string) ($tier['sort_order'] ?? '0'),
                        ), JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Edit</button>
                        <button class="btn btn-ghost btn-sm" type="button" onclick='openGuide(<?= json_encode(array(
                            'id' => $tierId,
                            'name' => $tier['tier_name'],
                            'code' => $tier['tier_code'],
                            'key' => (string) ($tier['project_key'] ?? ''),
                            'dir' => $tierDir,
                            'next' => lm_next_version($pdo, $tierId, 'patch'),
                        ), JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Integration</button>
                        <button class="btn btn-ghost btn-sm" type="button" onclick="toggleProject(<?= $tierId ?>, <?= $isActive ? 0 : 1 ?>)"><?= $isActive ? 'Archive' : 'Restore' ?></button>
                        <button class="btn btn-ghost btn-sm" type="button" onclick="rotateKey(<?= $tierId ?>)">Rotate key</button>
                        <button class="btn btn-danger btn-sm" type="button" onclick="deleteProject(<?= $tierId ?>, <?= json_encode($tier['tier_name'], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)">Delete</button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Create / edit modal -->
<div class="modal" id="projectModal">
    <div class="modal-card" style="max-width:600px;">
        <div class="modal-head">
            <div>
                <h3 id="projectModalTitle">New project</h3>
                <p class="tiny muted" style="margin-top:5px;">The project code is permanent and is used by the script when it checks for updates.</p>
            </div>
            <button class="modal-close" type="button" onclick="closeModal('projectModal')">✕</button>
        </div>
        <form id="projectForm">
            <input type="hidden" name="id" id="projectId" value="0">
            <div class="grid-2">
                <div class="field">
                    <label for="projectName">Project name</label>
                    <input class="input" type="text" id="projectName" name="tier_name" maxlength="50" required placeholder="DGV7.0 SAAS">
                </div>
                <div class="field">
                    <label for="projectCode">Project code</label>
                    <input class="input mono" type="text" id="projectCode" name="tier_code" maxlength="20" required placeholder="SAAS">
                    <div class="hint">A–Z, 0–9, _ and -. Cannot be changed later.</div>
                </div>
            </div>
            <div class="field">
                <label for="projectDescription">Description <span class="muted tiny">(optional)</span></label>
                <input class="input" type="text" id="projectDescription" name="description" maxlength="255" placeholder="SaaS edition of the DGV7 platform">
            </div>
            <div class="grid-2">
                <div class="field">
                    <label for="projectPrice">Licence price (NGN)</label>
                    <input class="input" type="number" step="0.01" min="0" id="projectPrice" name="license_price" placeholder="5000.00">
                    <div class="hint">Leave empty to use the global price from Settings.</div>
                </div>
                <div class="field">
                    <label for="projectDomains">Max domains per licence</label>
                    <input class="input" type="number" min="1" max="100" id="projectDomains" name="max_domains" placeholder="1">
                    <div class="hint">Leave empty to use the global limit.</div>
                </div>
            </div>
            <div class="field" id="sortOrderField" style="display:none;">
                <label for="projectSort">Sort order</label>
                <input class="input" type="number" id="projectSort" name="sort_order" value="0">
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:8px;">
                <button class="btn btn-ghost" type="button" onclick="closeModal('projectModal')">Cancel</button>
                <button class="btn btn-primary" type="submit" id="projectSaveBtn">Save project</button>
            </div>
        </form>
    </div>
</div>

<!-- Integration guide -->
<div class="modal" id="guideModal">
    <div class="modal-card">
        <div class="modal-head">
            <div>
                <h3>Connect <span id="guideName">project</span></h3>
                <p class="tiny muted" style="margin-top:5px;">Three steps: call the OTA endpoint, download the package, apply it.</p>
            </div>
            <button class="modal-close" type="button" onclick="closeModal('guideModal')">✕</button>
        </div>

        <div class="banner banner-info" style="font-weight:500;">
            The target script only needs to know its <strong>project code</strong> and its <strong>licence key</strong>. The License Manager handles versions, packages, emails and reminders.
        </div>

        <div class="field">
            <label>1. Check for an update</label>
            <pre class="code" id="guideEndpoint"></pre>
        </div>

        <div class="field">
            <label>2. Drop-in client for the target script</label>
            <pre class="code" id="guideSnippet"></pre>
        </div>

        <div class="field">
            <label>3. Package manifest (added automatically)</label>
            <pre class="code">{
  "version": "1.0.1",
  "tier": "PROJECT_CODE",
  "changelog": "What changed",
  "files_to_delete": [],
  "database_queries": []
}</pre>
        </div>

        <div class="field" style="margin-bottom:0;">
            <label>Storage folder in cPanel</label>
            <div class="input mono" style="background:#f1f5f9;word-break:break-all;" id="guideDir"></div>
            <div class="hint">Packages are written here and deleted from here when you delete or clean up an update.</div>
        </div>
    </div>
</div>

<!-- Delete confirmation: the impact is fetched first, never guessed -->
<div class="modal" id="deleteModal">
    <div class="modal-card" style="max-width:560px;">
        <div class="modal-head">
            <div>
                <h3 id="deleteTitle">Delete project</h3>
                <p class="tiny muted" style="margin-top:5px;">This removes the project, its published versions and its packages from cPanel. It cannot be undone.</p>
            </div>
            <button class="modal-close" type="button" onclick="closeModal('deleteModal')">✕</button>
        </div>

        <div id="deleteImpact" style="display:grid;gap:9px;margin-bottom:18px;"></div>

        <div id="deleteLicenceChoice" style="margin-bottom:16px;">
            <label style="display:block;font-size:12.5px;font-weight:700;color:#334155;margin-bottom:8px;">What happens to the licences?</label>
            <label class="switch" style="margin-bottom:9px;">
                <input type="radio" name="deleteLicenceMode" value="detach" checked>
                <span class="txt">
                    <strong>Keep them</strong>
                    <small>Customer keys keep working and stay in Licences. They stop receiving updates from this project.</small>
                    <small style="margin-top:9px;">
                        <span style="display:block;font-weight:700;color:#334155;margin-bottom:5px;">Move them to</span>
                        <select class="select" id="deleteMoveTo"><option value="0">— No project (unassigned) —</option></select>
                    </small>
                </span>
            </label>
            <label class="switch">
                <input type="radio" name="deleteLicenceMode" value="delete">
                <span class="txt"><strong>Delete them too</strong><small>The keys stop validating immediately and the customer cannot recover them. Payment history is kept.</small></span>
            </label>
        </div>

        <label class="switch" style="margin-bottom:18px;">
            <input type="checkbox" id="deleteUnderstand">
            <span class="txt"><strong>I understand this cannot be undone</strong><small id="deleteUnderstandHint">Tick to enable the delete button.</small></span>
        </label>

        <div style="display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;">
            <button class="btn btn-ghost" type="button" onclick="closeModal('deleteModal')">Cancel</button>
            <button class="btn btn-danger" type="button" id="deleteConfirmBtn" disabled onclick="confirmDeleteProject()">Delete project</button>
        </div>
    </div>
</div>

<script>
    let editingId = 0;

    function newProject() {
        editingId = 0;
        document.getElementById('projectModalTitle').textContent = 'New project';
        document.getElementById('projectForm').reset();
        document.getElementById('projectId').value = '0';
        document.getElementById('projectCode').readOnly = false;
        document.getElementById('sortOrderField').style.display = 'none';
        openModal('projectModal');
    }

    function openEdit(project) {
        editingId = project.id;
        document.getElementById('projectModalTitle').textContent = 'Edit ' + project.tier_name;
        document.getElementById('projectId').value = project.id;
        document.getElementById('projectName').value = project.tier_name;
        document.getElementById('projectCode').value = project.tier_code;
        document.getElementById('projectCode').readOnly = true;
        document.getElementById('projectDescription').value = project.description || '';
        document.getElementById('projectPrice').value = project.license_price || '';
        document.getElementById('projectDomains').value = project.max_domains || '';
        document.getElementById('projectSort').value = project.sort_order || '0';
        document.getElementById('sortOrderField').style.display = 'block';
        openModal('projectModal');
    }

    document.getElementById('projectForm').addEventListener('submit', async function (event) {
        event.preventDefault();
        const button = document.getElementById('projectSaveBtn');
        button.disabled = true;
        button.textContent = 'Saving…';

        const data = Object.fromEntries(new FormData(this).entries());
        data.action = editingId > 0 ? 'update' : 'create';

        const result = await lmPost('ajax_projects.php', data);
        toast(result.message, result.status === 'error' ? 'err' : 'ok');

        button.disabled = false;
        button.textContent = 'Save project';

        if (result.status !== 'error') {
            closeModal('projectModal');
            setTimeout(() => window.location.reload(), 900);
        }
    });

    async function toggleProject(id, active) {
        const verb = active ? 'restore' : 'archive';
        if (!confirm('Really ' + verb + ' this project?')) return;
        const result = await lmPost('ajax_projects.php', { action: 'toggle', id: id, is_active: active });
        toast(result.message, result.status === 'error' ? 'err' : 'ok');
        if (result.status !== 'error') setTimeout(() => window.location.reload(), 900);
    }

    async function rotateKey(id) {
        if (!confirm('Rotate the project key? Scripts using the old key must be updated.')) return;
        const result = await lmPost('ajax_projects.php', { action: 'rotate_key', id: id });
        toast(result.message, result.status === 'error' ? 'err' : 'ok');
        if (result.status !== 'error') setTimeout(() => window.location.reload(), 900);
    }

    let deleteProjectId = 0;
    let deleteProjectName = '';

    function impactRow(label, value, tone) {
        const row = document.createElement('div');
        row.style.cssText = 'display:flex;justify-content:space-between;gap:14px;padding:10px 13px;border-radius:10px;background:#f8fafc;font-size:13px;';
        const left = document.createElement('span');
        left.style.color = '#475569';
        left.textContent = label;
        const right = document.createElement('strong');
        right.style.color = tone === 'bad' ? '#b91c1c' : (tone === 'muted' ? '#94a3b8' : '#0f172a');
        right.textContent = value;
        row.appendChild(left);
        row.appendChild(right);
        return row;
    }

    /**
     * Ask the server what deleting this project would destroy, then show it.
     * The confirmation is a second request that carries the licence decision —
     * the panel never deletes a customer key without that being chosen.
     */
    async function deleteProject(id, name) {
        deleteProjectId = id;
        deleteProjectName = name;

        const result = await lmPost('ajax_projects.php', { action: 'delete', id: id });
        if (result.status === 'error') {
            toast(result.message, 'err');
            return;
        }

        const impact = result.impact || {};
        const list = document.getElementById('deleteImpact');
        list.innerHTML = '';

        document.getElementById('deleteTitle').textContent = 'Delete ' + name + '?';
        list.appendChild(impactRow('Licences', String(impact.licences || 0), impact.licences ? 'bad' : 'muted'));
        list.appendChild(impactRow('Published versions', String(impact.updates || 0), impact.updates ? 'bad' : 'muted'));
        list.appendChild(impactRow('Packages on disk', (impact.package_files || 0) + ' file(s) · ' + (impact.package_size || '0 B'), impact.package_files ? 'bad' : 'muted'));
        list.appendChild(impactRow('Queued customer notices', String(impact.notifications || 0), impact.notifications ? 'bad' : 'muted'));
        list.appendChild(impactRow('Pull history / jobs', (impact.pulls || 0) + ' / ' + (impact.jobs || 0), 'muted'));
        if (impact.jobs_running) {
            list.appendChild(impactRow('Pull running right now', String(impact.jobs_running) + ' — it will be cancelled', 'bad'));
        }

        const choice = document.getElementById('deleteLicenceChoice');
        choice.style.display = (impact.licences || 0) > 0 ? 'block' : 'none';
        const radios = choice.querySelectorAll('input[name="deleteLicenceMode"]');
        if (radios.length) radios[0].checked = true;

        // Licences can be handed to another project instead of left unassigned.
        const moveTo = document.getElementById('deleteMoveTo');
        moveTo.innerHTML = '';
        const none = document.createElement('option');
        none.value = '0';
        none.textContent = '— No project (unassigned) —';
        moveTo.appendChild(none);
        (result.targets || []).forEach(function (target) {
            const option = document.createElement('option');
            option.value = String(target.id);
            option.textContent = target.name + ' (' + target.code + ')';
            moveTo.appendChild(option);
        });
        moveTo.disabled = false;

        const understand = document.getElementById('deleteUnderstand');
        understand.checked = false;
        const button = document.getElementById('deleteConfirmBtn');
        button.disabled = true;
        button.textContent = 'Delete project';

        openModal('deleteModal');
    }

    document.querySelectorAll('input[name="deleteLicenceMode"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            const moveTo = document.getElementById('deleteMoveTo');
            moveTo.disabled = this.value !== 'detach';
        });
    });

    document.getElementById('deleteUnderstand').addEventListener('change', function () {
        const button = document.getElementById('deleteConfirmBtn');
        button.disabled = !this.checked;
        document.getElementById('deleteUnderstandHint').textContent = this.checked
            ? 'Deleting ' + deleteProjectName + '.'
            : 'Tick to enable the delete button.';
    });

    async function confirmDeleteProject() {
        const button = document.getElementById('deleteConfirmBtn');
        const chosen = document.querySelector('input[name="deleteLicenceMode"]:checked');
        const licenceMode = chosen ? chosen.value : 'detach';
        const moveTo = document.getElementById('deleteMoveTo');

        button.disabled = true;
        button.textContent = 'Deleting…';

        const result = await lmPost('ajax_projects.php', {
            action: 'delete',
            id: deleteProjectId,
            confirm: '1',
            licence_mode: licenceMode,
            move_to: licenceMode === 'detach' && moveTo ? moveTo.value : '0'
        });

        toast(result.message, result.status === 'error' ? 'err' : 'ok');

        if (result.status === 'error') {
            button.disabled = false;
            button.textContent = 'Delete project';
            return;
        }

        closeModal('deleteModal');
        setTimeout(() => window.location.reload(), 1100);
    }

    function openGuide(project) {
        document.getElementById('guideName').textContent = project.name;

        const base = <?= json_encode($siteUrl, JSON_UNESCAPED_SLASHES) ?>;
        const endpoint = base + '/check-update.php?license_key=YOUR_LICENSE_KEY&domain=example.com&current_version=CURRENT&project=' + encodeURIComponent(project.code);
        document.getElementById('guideEndpoint').textContent = 'GET ' + endpoint;

        const snippet = [
            '\x3C?php',
            '// Save as includes/ota-client.php inside ' + project.name,
            '',
            'function ota_check($licenseKey, $domain, $currentVersion) {',
            '    $url = ' + JSON.stringify(base + '/check-update.php') + ' . "?" . http_build_query(array(',
            '        "license_key"     => $licenseKey,',
            '        "domain"          => $domain,',
            '        "current_version" => $currentVersion,',
            '        "project"         => ' + JSON.stringify(project.code) + ',',
            '    ));',
            '',
            '    $context = stream_context_create(array("http" => array("timeout" => 12)));',
            '    $raw = @file_get_contents($url, false, $context);',
            '    if ($raw === false) { return null; }',
            '',
            '    $data = json_decode($raw, true);',
            '    if (!is_array($data) || empty($data["update_required"])) { return null; }',
            '',
            '    return $data; // latest_version, changelog, download_url, checksum',
            '}',
            '',
            '// Download the package with the one-time token',
            'function ota_download($downloadUrl, $target) {',
            '    $context = stream_context_create(array("http" => array("timeout" => 120)));',
            '    $raw = @file_get_contents($downloadUrl, false, $context);',
            '    if ($raw === false) { return false; }',
            '    return file_put_contents($target, $raw) !== false;',
            '}'
        ].join('\n');

        document.getElementById('guideSnippet').textContent = snippet;
        document.getElementById('guideDir').textContent = project.dir;
        openModal('guideModal');
    }
</script>

<?php lm_admin_page_end(); ?>

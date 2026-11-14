<?php
/**
 * admin/projects.php — the script projects this manager serves.
 *
 * A project is a row in script_tiers. The moment one exists it appears in Push
 * Updates and GitHub Pull, gets its own storage folder, its own version history
 * and its own licence pool. Create, edit, archive, rotate the key and delete
 * all go through ajax_projects.php; the delete flow asks the server what it
 * would destroy before it lets you confirm.
 *
 * @package LicenseManager
 */

require_once __DIR__ . '/../includes/app_ui.php';
lm_session_start();
lm_security_headers();

$pdo = lm_app_boot();

require_once __DIR__ . '/../includes/updates.php';

$siteUrl = lm_site_url();
$tiers = array();
$licenceCounts = array();
$versionCounts = array();
$latestVersions = array();
$problem = '';

if ($pdo instanceof PDO) {
    try {
        $tiers = $pdo->query('SELECT * FROM script_tiers ORDER BY sort_order ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $problem = 'The script_tiers table could not be read. Run Database Repair, then reload.';
    }

    try {
        foreach ($pdo->query('SELECT tier_id, COUNT(*) AS total FROM licenses WHERE tier_id IS NOT NULL GROUP BY tier_id') as $row) {
            $licenceCounts[(int) $row['tier_id']] = (int) $row['total'];
        }
    } catch (PDOException $e) {
        // licenses.tier_id does not exist on a very old schema
    }

    try {
        foreach ($pdo->query('SELECT tier_id, COUNT(*) AS total FROM script_updates GROUP BY tier_id') as $row) {
            $versionCounts[(int) $row['tier_id']] = (int) $row['total'];
        }
    } catch (PDOException $e) {
        // no releases yet
    }

    foreach ($tiers as $tier) {
        $latest = lm_latest_update_for_tier($pdo, (int) $tier['id'], false);
        $latestVersions[(int) $tier['id']] = $latest ? (string) $latest['version_number'] : '';
    }
}

$activeProjects = 0;
foreach ($tiers as $tier) {
    if (!isset($tier['is_active']) || (int) $tier['is_active'] === 1) {
        $activeProjects++;
    }
}

$storageRoot = lm_updates_root();

$actionsHtml = '<a class="lm-btn lm-btn--ghost" href="github-updates.php">' . lm_icon('layers') . ' GitHub pull</a>'
    . '<button class="lm-btn lm-btn--glow" type="button" data-lm-new-project>' . lm_icon('plus') . ' New project</button>';

lm_app_start('Script projects', array(
    'subtitle' => 'Each project is a target for updates, a storage folder in cPanel and its own licence pool.',
    'active'   => 'projects',
    'actions'  => $actionsHtml,
));
?>

<?php if ($problem !== ''): ?>
    <?php lm_alert('error', $problem); ?>
<?php endif; ?>

<div class="lm-stats">
    <?php
    lm_app_stat('Projects', (string) count($tiers), $activeProjects . ' active · ' . (count($tiers) - $activeProjects) . ' archived');
    lm_app_stat('Licences', (string) array_sum($licenceCounts), 'Across every project');
    lm_app_stat('Published', (string) array_sum($versionCounts), 'Packages on record', 'indigo');
    ?>
</div>

<div class="lm-note">
    <?= lm_icon('database') ?>
    <span><strong>Storage folder:</strong> <code><?= lm_e($storageRoot) ?></code> — visible in cPanel → File Manager.
        Packages are written here and removed from here.</span>
</div>

<?php if (empty($tiers)): ?>
    <section class="lm-panel">
        <?php lm_app_empty(
            'layers',
            'No projects yet',
            'Create one to start publishing. The code is permanent: the target script sends it with every update check.',
            '<button class="lm-btn lm-btn--glow" type="button" data-lm-new-project>' . lm_icon('plus') . ' Create the first project</button>'
        ); ?>
    </section>
<?php else: ?>
    <div class="lm-grid-2" style="grid-template-columns:repeat(auto-fit,minmax(320px,1fr))">
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

            $editPayload = array(
                'id'            => $tierId,
                'tier_name'     => (string) $tier['tier_name'],
                'tier_code'     => (string) $tier['tier_code'],
                'description'   => (string) ($tier['description'] ?? ''),
                'license_price' => (string) ($tier['license_price'] ?? ''),
                'max_domains'   => (string) ($tier['max_domains'] ?? ''),
                'sort_order'    => (string) ($tier['sort_order'] ?? '0'),
            );

            $guidePayload = array(
                'id'   => $tierId,
                'name' => (string) $tier['tier_name'],
                'code' => (string) $tier['tier_code'],
                'key'  => (string) ($tier['project_key'] ?? ''),
                'dir'  => $tierDir,
                'next' => lm_next_version($pdo, $tierId, 'patch'),
            );
            ?>
            <section class="lm-panel" <?= $isActive ? '' : 'style="opacity:.72"' ?>>
                <div class="lm-panel__head">
                    <div>
                        <div class="lm-flex" style="gap:6px;margin-bottom:8px">
                            <?= lm_app_badge((string) $tier['tier_code'], 'info') ?>
                            <?= lm_app_badge($isActive ? 'Active' : 'Archived', $isActive ? 'ok' : '') ?>
                        </div>
                        <h2 style="font-size:17px"><?= lm_e((string) $tier['tier_name']) ?></h2>
                        <?php if (!empty($tier['description'])): ?>
                            <p><?= lm_e((string) $tier['description']) ?></p>
                        <?php endif; ?>
                    </div>
                    <div style="text-align:right">
                        <span class="lm-help">Latest</span>
                        <div class="lm-mono" style="font-weight:700;font-size:15px">
                            <?= $latest !== '' ? 'v' . lm_e($latest) : '—' ?>
                        </div>
                    </div>
                </div>

                <div class="lm-panel__body">
                    <dl class="lm-deflist">
                        <div>
                            <dt>Licences</dt>
                            <dd><?= (int) $licences ?></dd>
                        </div>
                        <div>
                            <dt>Versions</dt>
                            <dd><?= (int) $versions ?></dd>
                        </div>
                        <div>
                            <dt>Packages</dt>
                            <dd><?= lm_e(lm_format_bytes($size)) ?></dd>
                        </div>
                    </dl>

                    <div class="lm-field lm-mt-16" style="margin-bottom:10px">
                        <span class="lm-label">Project key</span>
                        <div class="lm-keyrow">
                            <code class="lm-key"><?= lm_e((string) ($tier['project_key'] ?? '—')) ?></code>
                            <?= lm_app_copy((string) ($tier['project_key'] ?? ''), 'Copy') ?>
                        </div>
                    </div>

                    <p class="lm-help lm-mb-0">
                        <?php if ($hasRepo): ?>
                            <?= lm_icon('layers') ?>
                            <?= lm_e((string) $tier['github_repo']) ?><?= !empty($tier['github_branch']) ? ' @ ' . lm_e((string) $tier['github_branch']) : '' ?>
                        <?php else: ?>
                            No GitHub repository linked.
                        <?php endif; ?>
                    </p>

                    <div class="lm-btnrow lm-mt-16" data-project="<?= $tierId ?>"
                         data-edit="<?= lm_e(json_encode($editPayload, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>"
                         data-guide="<?= lm_e(json_encode($guidePayload, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>"
                         data-name="<?= lm_e((string) $tier['tier_name']) ?>"
                         data-active="<?= $isActive ? '1' : '0' ?>">
                        <button class="lm-btn lm-btn--sm lm-btn--ghost" type="button" data-lm-edit>Edit</button>
                        <button class="lm-btn lm-btn--sm lm-btn--ghost" type="button" data-lm-guide>Integration</button>
                        <button class="lm-btn lm-btn--sm lm-btn--ghost" type="button" data-lm-toggle>
                            <?= $isActive ? 'Archive' : 'Restore' ?>
                        </button>
                        <button class="lm-btn lm-btn--sm lm-btn--ghost" type="button" data-lm-rotate>Rotate key</button>
                        <button class="lm-btn lm-btn--sm lm-btn--danger" type="button" data-lm-delete>Delete</button>
                    </div>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Create / edit -->
<div class="lm-modal" id="projectModal" role="dialog" aria-modal="true" aria-labelledby="projectModalTitle">
    <div class="lm-modal__card" style="max-width:600px">
        <div class="lm-modal__head">
            <div>
                <h3 id="projectModalTitle">New project</h3>
                <p class="lm-help" style="margin-top:5px">The project code is permanent — the target script sends it on every check.</p>
            </div>
            <button class="lm-modal__close" type="button" data-lm-modal-close aria-label="Close"><?= lm_icon('close') ?></button>
        </div>
        <form id="projectForm" class="lm-form">
            <input type="hidden" name="id" id="projectId" value="0">
            <div class="lm-formgrid">
                <div class="lm-field">
                    <label for="projectName">Project name</label>
                    <input class="lm-input" type="text" id="projectName" name="tier_name" maxlength="50" required placeholder="DGV7.0 SAAS">
                </div>
                <div class="lm-field">
                    <label for="projectCode">Project code</label>
                    <input class="lm-input lm-mono" type="text" id="projectCode" name="tier_code" maxlength="20" required placeholder="SAAS">
                    <p class="lm-help">A–Z, 0–9, _ and -. Cannot be changed later.</p>
                </div>
            </div>
            <div class="lm-field">
                <label for="projectDescription">Description <span class="lm-help">(optional)</span></label>
                <input class="lm-input" type="text" id="projectDescription" name="description" maxlength="255"
                       placeholder="SaaS edition of the DGV7 platform">
            </div>
            <div class="lm-formgrid">
                <div class="lm-field">
                    <label for="projectPrice">Licence price (NGN)</label>
                    <input class="lm-input" type="number" step="0.01" min="0" id="projectPrice" name="license_price" placeholder="5000.00">
                    <p class="lm-help">Empty = the global price from Settings.</p>
                </div>
                <div class="lm-field">
                    <label for="projectDomains">Max domains per licence</label>
                    <input class="lm-input" type="number" min="1" max="100" id="projectDomains" name="max_domains" placeholder="1">
                    <p class="lm-help">Empty = the global limit.</p>
                </div>
            </div>
            <div class="lm-field" id="sortOrderField" hidden>
                <label for="projectSort">Sort order</label>
                <input class="lm-input" type="number" id="projectSort" name="sort_order" value="0">
            </div>
            <div class="lm-btnrow" style="justify-content:flex-end">
                <button class="lm-btn lm-btn--ghost" type="button" data-lm-modal-close>Cancel</button>
                <button class="lm-btn lm-btn--glow" type="submit" id="projectSaveBtn">Save project</button>
            </div>
        </form>
    </div>
</div>

<!-- Integration guide -->
<div class="lm-modal" id="guideModal" role="dialog" aria-modal="true" aria-labelledby="guideHeading">
    <div class="lm-modal__card">
        <div class="lm-modal__head">
            <div>
                <h3 id="guideHeading">Connect <?= lm_icon('plug') ?> <span id="guideName">project</span></h3>
                <p class="lm-help" style="margin-top:5px">Three steps: call the OTA endpoint, download the package, apply it.</p>
            </div>
            <button class="lm-modal__close" type="button" data-lm-modal-close aria-label="Close"><?= lm_icon('close') ?></button>
        </div>

        <div class="lm-note lm-note--ok">
            <?= lm_icon('spark') ?>
            <span>The target script only needs its <strong>project code</strong> and its <strong>licence key</strong>.
                The License Manager handles versions, packages, emails and reminders.</span>
        </div>

        <div class="lm-field">
            <span class="lm-label">1. Check for an update</span>
            <div class="lm-keyrow">
                <code class="lm-key" id="guideEndpoint"></code>
                <button class="lm-copybtn lm-copybtn--light" type="button" id="guideEndpointCopy">
                    <?= lm_icon('copy') ?><span>Copy</span>
                </button>
            </div>
            <p class="lm-help">The same call the script makes: licence key, domain, current version, project code.</p>
        </div>

        <div class="lm-field">
            <span class="lm-label">2. Drop-in client for the target script</span>
            <pre class="lm-code" id="guideSnippet"></pre>
        </div>

        <div class="lm-field">
            <span class="lm-label">3. Package manifest (added automatically when missing)</span>
            <pre class="lm-code">{
  "version": "1.0.1",
  "tier": "PROJECT_CODE",
  "changelog": "What changed",
  "files_to_delete": [],
  "database_queries": []
}</pre>
        </div>

        <div class="lm-field" style="margin-bottom:0">
            <span class="lm-label">Storage folder in cPanel</span>
            <code class="lm-key" id="guideDir"></code>
            <p class="lm-help">Packages are written here and deleted from here when you remove or clean up an update.</p>
        </div>
    </div>
</div>

<!-- Delete confirmation: impact comes from the server, never guessed -->
<div class="lm-modal" id="deleteModal" role="dialog" aria-modal="true" aria-labelledby="deleteTitle">
    <div class="lm-modal__card" style="max-width:560px">
        <div class="lm-modal__head">
            <div>
                <h3 id="deleteTitle">Delete project</h3>
                <p class="lm-help" style="margin-top:5px">This removes the project, its versions and its packages. It cannot be undone.</p>
            </div>
            <button class="lm-modal__close" type="button" data-lm-modal-close aria-label="Close"><?= lm_icon('close') ?></button>
        </div>

        <div id="deleteImpact" style="display:grid;gap:9px;margin-bottom:18px"></div>

        <div id="deleteLicenceChoice" style="margin-bottom:16px">
            <span class="lm-label" style="display:block;margin-bottom:8px">What happens to the licences?</span>

            <label class="lm-check" style="margin-bottom:10px">
                <input type="radio" name="deleteLicenceMode" value="detach" checked>
                <span class="txt">
                    <strong>Keep them</strong>
                    <small>Customer keys keep working and stay in Licences. They stop receiving updates from this project.</small>
                    <span style="display:block;margin-top:10px">
                        <span class="lm-label" style="display:block;margin-bottom:5px">Move them to</span>
                        <select class="lm-select" id="deleteMoveTo">
                            <option value="0">— No project (unassigned) —</option>
                        </select>
                    </span>
                </span>
            </label>

            <label class="lm-check">
                <input type="radio" name="deleteLicenceMode" value="delete">
                <span class="txt">
                    <strong>Delete them too</strong>
                    <small>The keys stop validating immediately and the customer cannot recover them. Payment history is kept.</small>
                </span>
            </label>
        </div>

        <label class="lm-check" style="margin-bottom:18px">
            <input type="checkbox" id="deleteUnderstand">
            <span class="txt">
                <strong>I understand this cannot be undone</strong>
                <small id="deleteUnderstandHint">Tick to enable the delete button.</small>
            </span>
        </label>

        <div class="lm-btnrow" style="justify-content:flex-end">
            <button class="lm-btn lm-btn--ghost" type="button" data-lm-modal-close>Cancel</button>
            <button class="lm-btn lm-btn--danger" type="button" id="deleteConfirmBtn" disabled>Delete project</button>
        </div>
    </div>
</div>

<script>
(function () {
    var siteUrl = <?= json_encode($siteUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var editingId = 0;
    var pendingDelete = { id: 0, name: '' };

    function el(id) { return document.getElementById(id); }

    function post(data) {
        if (!window.lmPost) {
            return Promise.reject(new Error('The console script did not load. Reload the page.'));
        }
        return window.lmPost('ajax_projects.php', data);
    }

    function done(result) {
        if (window.toast) { window.toast(result.message, result.status === 'error' ? 'err' : 'ok'); }
    }

    function reloadSoon() {
        window.setTimeout(function () { window.location.reload(); }, 900);
    }

    /* ------------------------------------------------------------- create */

    function openCreate() {
        editingId = 0;
        el('projectModalTitle').textContent = 'New project';
        el('projectForm').reset();
        el('projectId').value = '0';
        el('projectCode').readOnly = false;
        el('sortOrderField').hidden = true;
        if (window.openModal) { window.openModal('projectModal'); }
    }

    function openEdit(project) {
        editingId = project.id;
        el('projectModalTitle').textContent = 'Edit ' + project.tier_name;
        el('projectId').value = project.id;
        el('projectName').value = project.tier_name;
        el('projectCode').value = project.tier_code;
        el('projectCode').readOnly = true;
        el('projectDescription').value = project.description || '';
        el('projectPrice').value = project.license_price || '';
        el('projectDomains').value = project.max_domains || '';
        el('projectSort').value = project.sort_order || '0';
        el('sortOrderField').hidden = false;
        if (window.openModal) { window.openModal('projectModal'); }
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-lm-new-project]'), function (button) {
        button.addEventListener('click', openCreate);
    });

    el('projectForm').addEventListener('submit', function (event) {
        event.preventDefault();

        var button = el('projectSaveBtn');
        button.disabled = true;
        button.textContent = 'Saving…';

        var data = Object.fromEntries(new FormData(el('projectForm')).entries());
        data.action = editingId > 0 ? 'update' : 'create';

        post(data).then(function (result) {
            done(result);
            button.disabled = false;
            button.textContent = 'Save project';
            if (result.status !== 'error') {
                if (window.closeModal) { window.closeModal('projectModal'); }
                reloadSoon();
            }
        }).catch(function (error) {
            button.disabled = false;
            button.textContent = 'Save project';
            if (window.toast) { window.toast(error.message || 'Save failed.', 'err'); }
        });
    });

    /* ------------------------------------------------------- per project */

    Array.prototype.forEach.call(document.querySelectorAll('[data-project]'), function (bar, index) {
        var id = parseInt(bar.getAttribute('data-project'), 10);
        var name = bar.getAttribute('data-name') || '';
        var active = bar.getAttribute('data-active') === '1';
        var edit = JSON.parse(bar.getAttribute('data-edit') || '{}');
        var guide = JSON.parse(bar.getAttribute('data-guide') || '{}');

        bar.querySelector('[data-lm-edit]').addEventListener('click', function () { openEdit(edit); });
        bar.querySelector('[data-lm-guide]').addEventListener('click', function () { openGuide(guide); });

        bar.querySelector('[data-lm-toggle]').addEventListener('click', function () {
            var verb = active ? 'archive' : 'restore';
            if (!window.confirm('Really ' + verb + ' ' + name + '?')) { return; }
            post({ action: 'toggle', id: id, is_active: active ? 1 : 0 }).then(function (result) {
                done(result);
                if (result.status !== 'error') { reloadSoon(); }
            }).catch(function (error) {
                if (window.toast) { window.toast(error.message || 'Failed.', 'err'); }
            });
        });

        bar.querySelector('[data-lm-rotate]').addEventListener('click', function () {
            if (!window.confirm('Rotate the key for ' + name + '?\n\nEvery script still sending the old key stops receiving updates until you update it.')) {
                return;
            }
            post({ action: 'rotate_key', id: id }).then(function (result) {
                done(result);
                if (result.status !== 'error') { reloadSoon(); }
            }).catch(function (error) {
                if (window.toast) { window.toast(error.message || 'Failed.', 'err'); }
            });
        });

        bar.querySelector('[data-lm-delete]').addEventListener('click', function () {
            startDelete(id, name);
        });

        // The "new project" button in the empty state is separate.
        void index;
    });

    /* ------------------------------------------------------------- guide */

    function openGuide(project) {
        el('guideName').textContent = project.name;

        var endpoint = siteUrl + '/check-update.php?license_key=YOUR_LICENSE_KEY&domain=example.com'
            + '&current_version=CURRENT&project=' + encodeURIComponent(project.code);

        el('guideEndpoint').textContent = 'GET ' + endpoint;
        el('guideDir').textContent = project.dir;

        var lines = [
            '\x3C?php',
            '// Save as includes/ota-client.php inside ' + project.name,
            '',
            'function ota_check($licenseKey, $domain, $currentVersion) {',
            '    $url = ' + JSON.stringify(siteUrl + '/check-update.php') + ' . "?" . http_build_query(array(',
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
        ];

        el('guideSnippet').textContent = lines.join('\n');

        // A copy button per open, because the URL changes with the project.
        var copyButton = el('guideEndpointCopy');
        copyButton.setAttribute('data-lm-copy', endpoint);
        copyButton.setAttribute('type', 'button');
        if (window.openModal) { window.openModal('guideModal'); }
    }

    /* ------------------------------------------------------------ delete */

    function impactRow(label, value, bad) {
        var row = document.createElement('div');
        row.style.cssText = 'display:flex;justify-content:space-between;gap:14px;padding:10px 13px;border-radius:10px;background:#f8fafc;font-size:13px;';

        var left = document.createElement('span');
        left.style.color = '#475569';
        left.textContent = label;

        var right = document.createElement('strong');
        right.style.color = bad ? '#b91c1c' : '#0f172a';
        right.textContent = value;

        row.appendChild(left);
        row.appendChild(right);
        return row;
    }

    /**
     * Ask what deleting this project would destroy, show it, then send the
     * licence decision with the confirmation. The panel never deletes a
     * customer key without that being chosen first.
     */
    function startDelete(id, name) {
        pendingDelete = { id: id, name: name };

        post({ action: 'delete', id: id }).then(function (result) {
            if (result.status === 'error') {
                done(result);
                return;
            }

            var impact = result.impact || {};
            var list = el('deleteImpact');
            list.innerHTML = '';

            el('deleteTitle').textContent = 'Delete ' + name + '?';
            list.appendChild(impactRow('Licences', String(impact.licences || 0), impact.licences));
            list.appendChild(impactRow('Published versions', String(impact.updates || 0), impact.updates));
            list.appendChild(impactRow('Packages on disk',
                (impact.package_files || 0) + ' file(s) · ' + (impact.package_size || '0 B'), impact.package_files));
            list.appendChild(impactRow('Queued customer notices', String(impact.notifications || 0), impact.notifications));
            list.appendChild(impactRow('Pull history / jobs', (impact.pulls || 0) + ' / ' + (impact.jobs || 0), false));
            if (impact.jobs_running) {
                list.appendChild(impactRow('Pull running right now',
                    impact.jobs_running + ' — it will be cancelled', true));
            }

            el('deleteLicenceChoice').hidden = !(impact.licences > 0);
            var radios = el('deleteLicenceChoice').querySelectorAll('input[name="deleteLicenceMode"]');
            if (radios.length) { radios[0].checked = true; }

            var moveTo = el('deleteMoveTo');
            moveTo.innerHTML = '';
            var none = document.createElement('option');
            none.value = '0';
            none.textContent = '— No project (unassigned) —';
            moveTo.appendChild(none);

            (result.targets || []).forEach(function (target) {
                var option = document.createElement('option');
                option.value = String(target.id);
                option.textContent = target.name + ' (' + target.code + ')';
                moveTo.appendChild(option);
            });
            moveTo.disabled = false;

            el('deleteUnderstand').checked = false;
            var confirmButton = el('deleteConfirmBtn');
            confirmButton.disabled = true;
            confirmButton.textContent = 'Delete project';

            if (window.openModal) { window.openModal('deleteModal'); }
        }).catch(function (error) {
            if (window.toast) { window.toast(error.message || 'Could not read the impact.', 'err'); }
        });
    }

    Array.prototype.forEach.call(document.querySelectorAll('input[name="deleteLicenceMode"]'), function (radio) {
        radio.addEventListener('change', function () {
            el('deleteMoveTo').disabled = this.value !== 'detach';
        });
    });

    el('deleteUnderstand').addEventListener('change', function () {
        el('deleteConfirmBtn').disabled = !this.checked;
        el('deleteUnderstandHint').textContent = this.checked
            ? 'Deleting ' + pendingDelete.name + '.'
            : 'Tick to enable the delete button.';
    });

    el('deleteConfirmBtn').addEventListener('click', function () {
        var button = this;
        var chosen = document.querySelector('input[name="deleteLicenceMode"]:checked');
        var mode = chosen ? chosen.value : 'detach';
        var moveTo = el('deleteMoveTo');

        button.disabled = true;
        button.textContent = 'Deleting…';

        post({
            action: 'delete',
            id: pendingDelete.id,
            confirm: '1',
            licence_mode: mode,
            move_to: mode === 'detach' ? moveTo.value : '0'
        }).then(function (result) {
            done(result);
            if (result.status === 'error') {
                button.disabled = false;
                button.textContent = 'Delete project';
                return;
            }
            if (window.closeModal) { window.closeModal('deleteModal'); }
            window.setTimeout(function () { window.location.reload(); }, 1100);
        }).catch(function (error) {
            button.disabled = false;
            button.textContent = 'Delete project';
            if (window.toast) { window.toast(error.message || 'Delete failed.', 'err'); }
        });
    });
})();
</script>

<?php lm_app_end(); ?>

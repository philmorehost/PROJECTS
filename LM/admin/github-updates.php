<?php
/**
 * admin/github-updates.php — publish an update straight from a GitHub branch.
 *
 * Works with public repositories and private ones (fine-grained or classic
 * personal access token). The version number and changelog are generated
 * automatically, then the ZIP is normalised, given a manifest and published
 * through exactly the same pipeline as a manual upload.
 */

session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit();
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/_ui.php';
require_once __DIR__ . '/../includes/updates.php';
require_once __DIR__ . '/../includes/github.php';

$tiers = $pdo->query("SELECT * FROM script_tiers ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);

$projects = array();
foreach ($tiers as $tier) {
    $config = lm_github_tier_config($tier);
    $projects[] = array(
        'id' => (int) $tier['id'],
        'name' => $tier['tier_name'],
        'code' => $tier['tier_code'],
        'is_active' => !isset($tier['is_active']) || (int) $tier['is_active'] === 1,
        'repo' => $config['repo_input'],
        'repo_full' => ($config['owner'] !== '' ? $config['owner'] . '/' . $config['repo'] : ''),
        'branch' => $config['branch'],
        'subdir' => rtrim($config['subdir'], '/'),
        'has_token' => $config['has_token'],
        'version_source' => $config['version_source'],
        'increment' => $config['increment'],
        'changelog_format' => $config['changelog_format'],
        'changelog_mode' => $config['changelog_mode'],
        'auto_pull' => $config['auto_pull'],
        'last_sha' => $config['last_sha'],
        'last_pull_at' => $config['last_pull_at'],
        'next_version' => lm_next_version($pdo, (int) $tier['id'], $config['increment']),
    );
}

$cronUrl = lm_site_url('cron.php?key=' . rawurlencode(lm_get_cron_secret()));

lm_admin_page_start('GitHub Pull', 'github');
?>

<div class="page-head">
    <div>
        <h1>GitHub Pull</h1>
        <p>Publish directly from a repository branch. The package is normalised (wrapper folder removed, monorepo sub-folder supported), the version number is incremented from the previous release and the changelog is generated from the commits.</p>
    </div>
    <div class="head-actions">
        <a class="btn btn-ghost" href="updates.php">📦 Manual upload</a>
        <a class="btn btn-dark" href="projects.php">🧩 Projects</a>
    </div>
</div>

<?php if (empty($projects)): ?>
    <div class="card"><div class="empty">Create a script project first. <a href="projects.php" style="color:#4f46e5;font-weight:700;">Go to Script Projects →</a></div></div>
<?php else: ?>

<div class="card">
    <div class="card-body" style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;padding-bottom:14px;">
        <div style="flex:1;min-width:240px;">
            <label style="font-size:12.5px;font-weight:700;color:#334155;display:block;margin-bottom:7px;">Script project</label>
            <select class="select" id="tierSelect">
                <?php foreach ($projects as $project): ?>
                    <option value="<?= $project['id'] ?>"<?= $project['is_active'] ? '' : ' disabled' ?>>
                        <?= htmlspecialchars($project['name']) ?> (<?= htmlspecialchars($project['code']) ?>)<?= $project['is_active'] ? '' : ' — archived' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="min-width:160px;">
            <div class="tiny muted">Linked repository</div>
            <div class="mono" style="font-weight:700;margin-top:6px;font-size:13px;" id="repoLabel">—</div>
        </div>
        <div style="min-width:130px;">
            <div class="tiny muted">Last pull</div>
            <div style="font-weight:700;margin-top:6px;font-size:13px;" id="lastPullLabel">never</div>
        </div>
    </div>
</div>

<div style="display:grid;grid-template-columns:minmax(320px,430px) minmax(320px,1fr);gap:20px;align-items:start;">
    <!-- CONFIG -->
    <div class="card" style="margin:0;">
        <div class="card-head">
            <div>
                <h2>Repository settings</h2>
                <p>Token is encrypted at rest with the LM application secret.</p>
            </div>
            <span class="badge badge-cyan" id="tokenBadge">No token</span>
        </div>
        <div class="card-body">
            <form id="configForm">
                <div class="field">
                    <label for="githubRepo">Repository</label>
                    <input class="input mono" type="text" id="githubRepo" name="github_repo" placeholder="owner/repo or https://github.com/owner/repo">
                    <div class="hint">Private repositories work too — paste a PAT below with <strong>Contents: Read</strong> permission.</div>
                </div>
                <div class="grid-2">
                    <div class="field">
                        <label for="githubBranch">Branch</label>
                        <input class="input mono" type="text" id="githubBranch" name="github_branch" placeholder="main">
                        <div class="hint">Empty = repository default branch.</div>
                    </div>
                    <div class="field">
                        <label for="githubSubdir">Sub-folder <span class="muted tiny">(monorepos)</span></label>
                        <input class="input mono" type="text" id="githubSubdir" name="github_subdir" placeholder="backend">
                    </div>
                </div>
                <div class="field">
                    <label for="githubToken">Personal access token</label>
                    <input class="input mono" type="password" id="githubToken" name="github_token" autocomplete="new-password" placeholder="ghp_… or github_pat_…">
                    <div class="hint" style="display:flex;justify-content:space-between;gap:10px;align-items:center;">
                        <span>Leave blank to keep the stored token.</span>
                        <button class="btn btn-ghost btn-sm" type="button" id="clearTokenBtn">Remove stored token</button>
                    </div>
                </div>
                <div class="grid-2">
                    <div class="field">
                        <label for="githubIncrement">Version bump</label>
                        <select class="select" id="githubIncrement" name="github_increment">
                            <option value="patch">Patch — 7.02 → 7.03</option>
                            <option value="minor">Minor — 1.2.9 → 1.3.0</option>
                            <option value="major">Major — 1.2.9 → 2.0.0</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="githubVersionSource">Version source</label>
                        <select class="select" id="githubVersionSource" name="github_version_source">
                            <option value="auto_increment">Auto-increment from last release</option>
                            <option value="git_tag">Use latest git tag when newer</option>
                        </select>
                    </div>
                </div>
                <div class="grid-2">
                    <div class="field">
                        <label for="githubChangelogFormat">Changelog format</label>
                        <select class="select" id="githubChangelogFormat" name="github_changelog_format">
                            <option value="html">HTML</option>
                            <option value="text">Plain text</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="githubChangelogMode">Changelog source</label>
                        <select class="select" id="githubChangelogMode" name="github_changelog_mode">
                            <option value="commits">Commit messages</option>
                            <option value="files">Changed files</option>
                            <option value="both">Both</option>
                        </select>
                    </div>
                </div>
                <label class="switch" style="margin-bottom:16px;">
                    <input type="checkbox" id="githubAutoPull" name="github_auto_pull" value="1">
                    <span class="txt">
                        <strong>Auto-pull every 30 minutes</strong>
                        <small>Only when <code>cron.php</code> runs. Leave off until you trust the branch — pulled commits are published immediately.</small>
                    </span>
                </label>
                <div style="display:flex;gap:9px;flex-wrap:wrap;">
                    <button class="btn btn-primary" type="submit">Save settings</button>
                    <button class="btn btn-ghost" type="button" id="testBtn">Test connection</button>
                </div>
            </form>
        </div>
    </div>

    <!-- PREVIEW / PULL -->
    <div>
        <div class="card" style="margin:0 0 20px;">
            <div class="card-head">
                <div>
                    <h2>Next release</h2>
                    <p id="previewSubtitle">Load the changes waiting on the branch.</p>
                </div>
                <button class="btn btn-ghost btn-sm" type="button" id="previewBtn">↻ Refresh preview</button>
            </div>
            <div class="card-body" id="previewBody">
                <div class="empty" style="padding:30px 0;">Choose a project and press “Refresh preview”.</div>
            </div>
        </div>

        <div class="card" style="margin:0;">
            <div class="card-head">
                <div>
                    <h2>Pull history</h2>
                    <p>Audit trail of every GitHub publish.</p>
                </div>
            </div>
            <div style="overflow-x:auto;" id="pullsBody">
                <div class="empty">No pulls yet.</div>
            </div>
        </div>
    </div>
</div>

<div class="banner banner-info" style="margin-top:22px;">
    Cron URL for auto-pull: <code style="word-break:break-all;"><?= htmlspecialchars($cronUrl) ?></code>
</div>

<script>
    const PROJECTS = <?= json_encode($projects, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?>;
    let currentProject = null;
    let previewData = null;

    const el = id => document.getElementById(id);

    function projectById(id) {
        return PROJECTS.find(p => String(p.id) === String(id)) || null;
    }

    function fillForm(project) {
        el('githubRepo').value = project.repo || '';
        el('githubBranch').value = project.branch || '';
        el('githubSubdir').value = project.subdir || '';
        el('githubToken').value = '';
        el('githubIncrement').value = project.increment || 'patch';
        el('githubVersionSource').value = project.version_source || 'auto_increment';
        el('githubChangelogFormat').value = project.changelog_format || 'html';
        el('githubChangelogMode').value = project.changelog_mode || 'commits';
        el('githubAutoPull').checked = !!project.auto_pull;
        el('repoLabel').textContent = project.repo_full || '—';
        el('lastPullLabel').textContent = project.last_pull_at ? formatDate(project.last_pull_at) : 'never';
        el('tokenBadge').textContent = project.has_token ? 'Token saved' : 'No token';
        el('tokenBadge').className = 'badge ' + (project.has_token ? 'badge-green' : 'badge-slate');
    }

    function formatDate(value) {
        if (!value) return '—';
        const date = new Date(String(value).replace(' ', 'T') + 'Z');
        if (isNaN(date.getTime())) return value;
        return date.toLocaleString();
    }

    function selectProject(id) {
        currentProject = projectById(id);
        if (!currentProject) return;
        fillForm(currentProject);
        el('previewBody').innerHTML = '<div class="empty" style="padding:30px 0;">Loading preview…</div>';
        loadPreview();
        loadPulls();
    }

    el('tierSelect').addEventListener('change', function () { selectProject(this.value); });

    el('configForm').addEventListener('submit', async function (event) {
        event.preventDefault();
        const data = Object.fromEntries(new FormData(this).entries());
        data.action = 'save_config';
        data.tier_id = currentProject.id;
        data.github_auto_pull = el('githubAutoPull').checked ? '1' : '0';

        const result = await lmPost('ajax_github.php', data);
        toast(result.message, result.status === 'error' ? 'err' : 'ok');

        if (result.status !== 'error') {
            currentProject.repo = result.repo || data.github_repo;
            currentProject.has_token = !!result.has_token;
            el('githubToken').value = '';
            el('tokenBadge').textContent = result.has_token ? 'Token saved' : 'No token';
            el('tokenBadge').className = 'badge ' + (result.has_token ? 'badge-green' : 'badge-slate');
        }
    });

    el('clearTokenBtn').addEventListener('click', async function () {
        if (!confirm('Remove the stored GitHub token?')) return;
        const result = await lmPost('ajax_github.php', { action: 'clear_token', tier_id: currentProject.id });
        toast(result.message, result.status === 'error' ? 'err' : 'ok');
        if (result.status !== 'error') {
            currentProject.has_token = false;
            el('tokenBadge').textContent = 'No token';
            el('tokenBadge').className = 'badge badge-slate';
        }
    });

    el('testBtn').addEventListener('click', async function () {
        const button = this;
        button.disabled = true;
        button.textContent = 'Testing…';
        const result = await lmPost('ajax_github.php', { action: 'test', tier_id: currentProject.id });
        button.disabled = false;
        button.textContent = 'Test connection';

        if (result.status === 'error') {
            toast(result.message, 'err');
            return;
        }

        const repo = result.repo || {};
        const head = result.head || {};
        const box = document.createElement('div');
        box.className = 'banner banner-ok';
        box.style.marginTop = '14px';
        box.innerHTML = '✓ Connected to <strong>' + escapeHtml(repo.full_name || '') + '</strong>'
            + (repo.private ? ' <span class="badge badge-amber">private</span>' : ' <span class="badge badge-green">public</span>')
            + '<br><span class="tiny">Branch <code>' + escapeHtml(result.branch || '') + '</code> · HEAD '
            + '<code>' + escapeHtml(String(head.sha || '').slice(0, 7)) + '</code> · '
            + escapeHtml(head.title || '') + '</span>';
        const existing = document.getElementById('testResult');
        if (existing) existing.remove();
        box.id = 'testResult';
        el('configForm').appendChild(box);

        toast('Repository reachable.', 'ok');
    });

    async function loadPreview() {
        if (!currentProject) return;
        el('previewBtn').disabled = true;
        const result = await lmPost('ajax_github.php', { action: 'preview', tier_id: currentProject.id });
        el('previewBtn').disabled = false;

        if (result.status === 'error') {
            el('previewBody').innerHTML = '<div class="banner banner-err" style="margin:0;">' + escapeHtml(result.message) + '</div>';
            return;
        }

        previewData = result;
        renderPreview(result);
    }

    function renderPreview(data) {
        const head = data.head || {};
        const commits = Array.isArray(data.commits) ? data.commits : [];
        const files = Array.isArray(data.files) ? data.files : [];
        el('previewSubtitle').textContent = data.repo.full_name + ' · ' + data.branch;

        let html = '';

        if (data.up_to_date) {
            html += '<div class="banner banner-ok" style="margin-bottom:16px;">✓ Branch is already published at this commit. '
                + '<span class="tiny">Nothing to pull.</span></div>';
        }

        html += '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px;">'
            + '<span class="badge badge-indigo">' + escapeHtml((data.previous_version ? 'v' + data.previous_version + ' → ' : '') + 'v' + data.next_version) + '</span>'
            + '<span class="badge badge-slate">' + commits.length + ' commit(s)</span>'
            + '<span class="badge badge-slate">' + (data.files_count || files.length) + ' file(s)</span>'
            + '<span class="badge badge-cyan mono">' + escapeHtml(String(head.sha || '').slice(0, 7)) + '</span>'
            + '</div>';

        html += '<div class="field"><label>HEAD commit</label><div class="tiny" style="line-height:1.6;">'
            + '<strong>' + escapeHtml(head.title || '') + '</strong><br>'
            + '<span class="muted">' + escapeHtml(head.author || '') + ' · ' + escapeHtml(formatDate(head.date || '')) + '</span>'
            + '</div></div>';

        if (commits.length) {
            html += '<div class="field"><label>Commits since the last release</label><div style="max-height:230px;overflow:auto;border:1px solid var(--line);border-radius:12px;">';
            commits.forEach(commit => {
                html += '<div style="padding:10px 13px;border-bottom:1px solid #f1f5f9;display:flex;gap:10px;align-items:flex-start;">'
                    + '<code style="flex:0 0 auto;">' + escapeHtml(String(commit.sha || '').slice(0, 7)) + '</code>'
                    + '<div style="flex:1;min-width:0;"><div style="font-size:12.5px;font-weight:600;line-height:1.45;">' + escapeHtml(commit.title || '') + '</div>'
                    + '<div class="tiny muted" style="margin-top:2px;">' + escapeHtml(commit.author || '') + '</div></div></div>';
            });
            html += '</div></div>';
        }

        if (files.length) {
            html += '<div class="field"><label>Changed files</label><div class="tiny mono muted" style="max-height:130px;overflow:auto;line-height:1.8;">';
            files.forEach(file => { html += escapeHtml(file.filename || file) + '<br>'; });
            html += '</div></div>';
        }

        html += '<div class="field">'
            + '<label for="pullVersion">Version to publish</label>'
            + '<input class="input mono" type="text" id="pullVersion" value="' + escapeHtml(data.next_version) + '">'
            + '<div class="hint">Pre-filled from the previous release. Edit it if you tag releases manually.</div>'
            + '</div>';

        html += '<div class="field">'
            + '<label for="pullChangelog">Changelog <span class="chips"><label><input type="radio" name="pullFormat" value="html" checked> HTML</label><label><input type="radio" name="pullFormat" value="text"> Plain text</label></span></label>'
            + '<textarea class="input mono" id="pullChangelog" style="min-height:170px;">' + escapeHtml(data.changelog_text || '') + '</textarea>'
            + '<div class="hint">Edit before publishing — this is what customers see in the email.</div>'
            + '</div>';

        html += '<label class="switch" style="margin-bottom:16px;">'
            + '<input type="checkbox" id="pullNotify" value="1" checked>'
            + '<span class="txt"><strong>Email customers after publishing</strong>'
            + '<small>One email per licence, plus a reminder the next day if the site has not reported the new version.</small></span>'
            + '</label>';

        html += '<div style="display:flex;gap:10px;flex-wrap:wrap;">'
            + '<button class="btn btn-primary" type="button" id="pullBtn"' + (data.up_to_date ? '' : '') + '>🚀 Pull &amp; publish v' + escapeHtml(data.next_version) + '</button>'
            + '<button class="btn btn-ghost" type="button" id="forcePullBtn">Force re-publish</button>'
            + '</div>';

        el('previewBody').innerHTML = html;

        document.querySelectorAll('input[name="pullFormat"]').forEach(radio => {
            radio.addEventListener('change', () => {});
        });

        el('pullBtn').addEventListener('click', () => doPull(false));
        el('forcePullBtn').addEventListener('click', () => doPull(true));
    }

    async function doPull(force) {
        if (!currentProject) return;
        const button = force ? el('forcePullBtn') : el('pullBtn');
        if (!confirm((force ? 'Force publish ' : 'Publish ') + el('pullVersion').value + ' for ' + currentProject.name + '?')) return;

        button.disabled = true;
        const original = button.textContent;
        button.textContent = 'Pulling from GitHub…';

        const format = document.querySelector('input[name="pullFormat"]:checked');
        const result = await lmPost('ajax_github.php', {
            action: 'pull',
            tier_id: currentProject.id,
            version: el('pullVersion').value,
            changelog: el('pullChangelog').value,
            changelog_format: format ? format.value : 'html',
            increment: el('githubIncrement').value,
            notify: el('pullNotify').checked ? '1' : '0',
            force: force ? '1' : '0'
        });

        button.disabled = false;
        button.textContent = original;
        toast(result.message, result.status === 'error' ? 'err' : 'ok');

        if (result.status === 'success' && !result.up_to_date) {
            el('previewBody').innerHTML = '<div class="banner banner-ok" style="margin:0 0 14px;">✓ Published <strong>v'
                + escapeHtml(result.version) + '</strong> from <code>' + escapeHtml(String(result.commit || '').slice(0, 7)) + '</code>. '
                + escapeHtml(result.bytes_human || '') + ' written to <code>' + escapeHtml(result.cpanel_dir || '') + '</code>.</div>'
                + '<a class="btn btn-ghost" href="updates.php">View in Push Updates</a>';
            loadPulls();
        }
    }

    async function loadPulls() {
        if (!currentProject) return;
        const result = await lmPost('ajax_github.php', { action: 'pulls', tier_id: currentProject.id });

        if (result.status === 'error' || !result.pulls || !result.pulls.length) {
            el('pullsBody').innerHTML = '<div class="empty">No pulls yet.</div>';
            return;
        }

        let html = '<table><thead><tr><th>When</th><th>Version</th><th>Commit</th><th>Result</th></tr></thead><tbody>';
        result.pulls.forEach(pull => {
            const ok = pull.status === 'success';
            html += '<tr>'
                + '<td class="tiny">' + escapeHtml(formatDate(pull.created_at)) + '</td>'
                + '<td class="mono tiny">' + (pull.version_number ? 'v' + escapeHtml(pull.version_number) : '—') + '</td>'
                + '<td><code>' + escapeHtml(String(pull.commit_sha || '').slice(0, 7)) + '</code>'
                + '<div class="tiny muted" style="margin-top:3px;">' + escapeHtml(String(pull.commit_title || '').slice(0, 70)) + '</div></td>'
                + '<td><span class="badge ' + (ok ? 'badge-green' : 'badge-red') + '">' + (ok ? 'Published' : 'Failed') + '</span>'
                + '<div class="tiny muted" style="margin-top:4px;">' + escapeHtml(String(pull.message || '').slice(0, 90)) + '</div></td>'
                + '</tr>';
        });
        html += '</tbody></table>';
        el('pullsBody').innerHTML = html;
    }

    selectProject(el('tierSelect').value);
</script>

<?php endif; ?>

<?php lm_admin_page_end(); ?>

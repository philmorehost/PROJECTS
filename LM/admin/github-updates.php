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
        'token_source' => $config['token_source'],
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
        <?= lm_guide_help_button('github') ?>
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
                <p>Tokens are encrypted at rest. Projects without their own token use the shared one from Settings.</p>
            </div>
            <span class="badge badge-cyan" id="tokenBadge">No token</span>
        </div>
        <div class="card-body">
            <form id="configForm">
                <div class="field">
                    <label for="githubRepo">Repository</label>
                    <div class="repo-picker" id="repoPicker">
                        <div class="repo-input-row">
                            <input class="input mono" type="text" id="githubRepo" name="github_repo"
                                   placeholder="Start typing — or pick from the list" autocomplete="off" spellcheck="false">
                            <button class="btn btn-ghost btn-sm" type="button" id="repoRefreshBtn" title="Reload the list from GitHub">↻</button>
                        </div>
                        <div class="repo-menu" id="repoMenu" hidden>
                            <div class="repo-menu-head">
                                <input type="search" id="repoFilter" placeholder="Search repositories…" autocomplete="off">
                                <span class="repo-menu-count" id="repoCount">—</span>
                            </div>
                            <div class="repo-menu-list" id="repoList"></div>
                            <div class="repo-menu-foot" id="repoFoot"></div>
                        </div>
                    </div>
                    <div class="hint" id="repoHint">Loading the repositories your token can see…</div>
                </div>
                <div class="grid-2">
                    <div class="field">
                        <label for="githubBranch">Branch</label>
                        <input class="input mono" type="text" id="githubBranch" name="github_branch" list="branchList" placeholder="main" autocomplete="off">
                        <datalist id="branchList"></datalist>
                        <div class="hint">Picked automatically from the repository — empty = its default branch.</div>
                    </div>
                    <div class="field">
                        <label for="githubSubdir">Default folder <span class="muted tiny">(optional)</span></label>
                        <input class="input mono" type="text" id="githubSubdir" name="github_subdir" placeholder="LM">
                        <div class="hint">Applied to auto-pull. Pick it visually in the file tree on the right.</div>
                    </div>
                </div>
                <div class="field">
                    <label for="githubToken">Personal access token</label>
                    <input class="input mono" type="password" id="githubToken" name="github_token" autocomplete="new-password" placeholder="ghp_… or github_pat_…">
                    <div class="hint" style="display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap;">
                        <span>Leave blank to keep this project on the <a href="settings.php#github" style="color:#4f46e5;font-weight:700;">shared token from Settings</a>.</span>
                        <button class="btn btn-ghost btn-sm" type="button" id="clearTokenBtn">Remove project token</button>
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

    <!-- PICKER / PREVIEW / PULL -->
    <div>
        <div class="card" style="margin:0 0 20px;">
            <div class="card-head">
                <div>
                    <h2>What goes into the update</h2>
                    <p>Tick the folders and files to package. One repository can hold every project.</p>
                </div>
                <button class="btn btn-ghost btn-sm" type="button" id="reloadTreeBtn">↻ Load files</button>
            </div>
            <div class="card-body" style="padding-bottom:16px;">
                <div class="tree-bar">
                    <input class="input" type="text" id="treeSearch" placeholder="Filter files… (e.g. updates.php, .php)" style="flex:1;min-width:170px;padding:9px 12px;">
                    <button class="btn btn-ghost btn-sm" type="button" id="selectAllBtn">Select all</button>
                    <button class="btn btn-ghost btn-sm" type="button" id="clearSelBtn">Clear</button>
                </div>

                <div class="tree" id="treeBox">
                    <div class="empty" style="padding:26px 18px;font-size:12.5px;">
                        Press <strong>Load files</strong> to browse the repository.
                    </div>
                </div>

                <div class="tree-status" id="treeStatus">
                    No file list loaded yet.
                </div>

                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;">
                    <button class="btn btn-ghost btn-sm" type="button" id="saveSelectionBtn">💾 Save selection</button>
                    <button class="btn btn-ghost btn-sm" type="button" id="selectSubdirBtn">Select the “sub-folder” above</button>
                </div>
            </div>
        </div>

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

    /* =====================================================================
     * Repository file picker
     * =====================================================================
     * Selection state is a set of file paths. Folder checkboxes tick/untick
     * every descendant, and render as indeterminate when only part of the
     * folder is picked. When the selection is sent to PHP it is compressed
     * back into folder selectors, so a 5,000-file folder costs one entry.
     */
    const PICKER = {
        loaded: false,
        loading: false,
        files: [],          // [{path, size}]
        dirs: [],           // ['LM', 'LM/admin', ...]
        children: {},       // dirPath -> { dirs: [], files: [] }
        dirFiles: {},       // dirPath -> [file paths]
        dirBytes: {},       // dirPath -> total bytes
        expanded: new Set(),
        selected: new Set(),
        query: ''
    };

    function fmtBytes(bytes) {
        bytes = Number(bytes) || 0;
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        if (bytes < 1073741824) return (bytes / 1048576).toFixed(1) + ' MB';
        return (bytes / 1073741824).toFixed(2) + ' GB';
    }

    function dirOf(path) {
        const i = path.lastIndexOf('/');
        return i === -1 ? '' : path.slice(0, i);
    }

    function baseOf(path) {
        const i = path.lastIndexOf('/');
        return i === -1 ? path : path.slice(i + 1);
    }

    function buildIndex() {
        PICKER.children = { '': { dirs: [], files: [] } };
        PICKER.dirFiles = {};
        PICKER.dirBytes = {};

        PICKER.dirs.forEach(dir => {
            if (!PICKER.children[dir]) PICKER.children[dir] = { dirs: [], files: [] };
            PICKER.dirFiles[dir] = [];
            PICKER.dirBytes[dir] = 0;
            const parent = dirOf(dir);
            if (!PICKER.children[parent]) PICKER.children[parent] = { dirs: [], files: [] };
            PICKER.children[parent].dirs.push(dir);
        });

        PICKER.files.forEach(file => {
            const parent = dirOf(file.path);
            if (!PICKER.children[parent]) PICKER.children[parent] = { dirs: [], files: [] };
            PICKER.children[parent].files.push(file);
        });

        // Descendant file list per folder (used by checkboxes and totals).
        const dirsByDepth = PICKER.dirs.slice().sort((a, b) => b.split('/').length - a.split('/').length);
        dirsByDepth.forEach(dir => {
            PICKER.dirFiles[dir] = [];
            PICKER.dirBytes[dir] = 0;
            PICKER.children[dir].files.forEach(file => {
                PICKER.dirFiles[dir].push(file.path);
                PICKER.dirBytes[dir] += Number(file.size) || 0;
            });
            PICKER.children[dir].dirs.forEach(child => {
                PICKER.dirFiles[dir] = PICKER.dirFiles[dir].concat(PICKER.dirFiles[child] || []);
                PICKER.dirBytes[dir] += PICKER.dirBytes[child] || 0;
            });
        });

        Object.keys(PICKER.children).forEach(dir => {
            PICKER.children[dir].dirs.sort();
            PICKER.children[dir].files.sort((a, b) => a.path.localeCompare(b.path));
        });
    }

    function dirState(dir) {
        const files = PICKER.dirFiles[dir] || [];
        if (!files.length) return 'none';
        let checked = 0;
        files.forEach(path => { if (PICKER.selected.has(path)) checked++; });
        if (checked === 0) return 'none';
        return checked === files.length ? 'all' : 'some';
    }

    function toggleDir(dir, on) {
        (PICKER.dirFiles[dir] || []).forEach(path => {
            if (on) PICKER.selected.add(path); else PICKER.selected.delete(path);
        });
    }

    function renderTree() {
        const box = el('treeBox');
        if (!PICKER.loaded) return;

        if (!PICKER.files.length) {
            box.innerHTML = '<div class="empty" style="padding:24px;font-size:12.5px;">The repository is empty on this branch.</div>';
            return;
        }

        // Filter mode: flat list of matching files.
        if (PICKER.query !== '') {
            const term = PICKER.query.toLowerCase();
            const matches = PICKER.files.filter(file => file.path.toLowerCase().indexOf(term) !== -1).slice(0, 400);
            if (!matches.length) {
                box.innerHTML = '<div class="empty" style="padding:24px;font-size:12.5px;">No file matches “' + escapeHtml(PICKER.query) + '”.</div>';
                return;
            }
            let html = '';
            matches.forEach(file => {
                const on = PICKER.selected.has(file.path);
                html += '<div class="tree-row">'
                    + '<button class="twisty empty" type="button"></button>'
                    + '<input type="checkbox" class="tree-check" data-type="file" data-path="' + escapeHtml(file.path) + '"' + (on ? ' checked' : '') + '>'
                    + '<span class="fname" title="' + escapeHtml(file.path) + '">' + escapeHtml(file.path) + '</span>'
                    + '<span class="fmeta">' + fmtBytes(file.size) + '</span>'
                    + '</div>';
            });
            if (matches.length === 400) {
                html += '<div class="tree-row" style="justify-content:center;color:#94a3b8;font-size:11px;">Showing the first 400 matches — refine the filter.</div>';
            }
            box.innerHTML = html;
            return;
        }

        let html = '';
        const root = PICKER.children[''] || { dirs: [], files: [] };

        root.files.forEach(file => {
            html += fileRow(file, 0);
        });
        root.dirs.forEach(dir => {
            html += dirRows(dir, 0);
        });

        box.innerHTML = html;
        syncIndeterminate();
    }

    function fileRow(file, depth) {
        const on = PICKER.selected.has(file.path);
        return '<div class="tree-row" style="padding-left:' + (10 + depth * 16) + 'px;">'
            + '<button class="twisty empty" type="button"></button>'
            + '<input type="checkbox" class="tree-check" data-type="file" data-path="' + escapeHtml(file.path) + '"' + (on ? ' checked' : '') + '>'
            + '<span class="fname" title="' + escapeHtml(file.path) + '">' + escapeHtml(baseOf(file.path)) + '</span>'
            + '<span class="fmeta">' + fmtBytes(file.size) + '</span>'
            + '</div>';
    }

    function dirRows(dir, depth) {
        const expanded = PICKER.expanded.has(dir);
        const count = (PICKER.dirFiles[dir] || []).length;
        const state = dirState(dir);

        let html = '<div class="tree-row is-dir" style="padding-left:' + (10 + depth * 16) + 'px;">'
            + '<button class="twisty" type="button" data-toggle="' + escapeHtml(dir) + '">' + (expanded ? '▼' : '▶') + '</button>'
            + '<input type="checkbox" class="tree-check" data-type="dir" data-path="' + escapeHtml(dir) + '"'
            + (state === 'all' ? ' checked' : '') + ' data-state="' + state + '">'
            + '<span class="fname" title="' + escapeHtml(dir) + '">' + escapeHtml(baseOf(dir)) + '</span>'
            + '<span class="fcount">' + count + ' file' + (count === 1 ? '' : 's') + '</span>'
            + '</div>';

        if (expanded) {
            const node = PICKER.children[dir] || { dirs: [], files: [] };
            node.dirs.forEach(child => { html += dirRows(child, depth + 1); });
            node.files.forEach(file => { html += fileRow(file, depth + 1); });
        }

        return html;
    }

    function syncIndeterminate() {
        document.querySelectorAll('#treeBox .tree-check[data-type="dir"]').forEach(input => {
            const state = dirState(input.getAttribute('data-path'));
            input.checked = state === 'all';
            input.indeterminate = state === 'some';
        });
    }

    function projectRootField() {
        return (el('githubSubdir').value || '').trim().replace(/^\/+|\/+$/g, '');
    }

    function selectionSummary() {
        if (!PICKER.loaded) return { count: 0, bytes: 0, root: '', selectors: [], all: false };

        let count = 0;
        let bytes = 0;
        const sizes = {};

        PICKER.files.forEach(file => { sizes[file.path] = Number(file.size) || 0; });
        PICKER.selected.forEach(path => {
            if (sizes[path] !== undefined) {
                count++;
                bytes += sizes[path];
            }
        });

        // Compress full folders into single selectors.
        const selectors = [];
        const covered = new Set();
        const dirsByDepth = PICKER.dirs.slice().sort((a, b) => a.split('/').length - b.split('/').length);

        dirsByDepth.forEach(dir => {
            const files = PICKER.dirFiles[dir] || [];
            if (!files.length) return;
            let allSelected = true;
            let alreadyCovered = true;
            files.forEach(path => {
                if (!PICKER.selected.has(path)) allSelected = false;
                if (!covered.has(path)) alreadyCovered = false;
            });
            if (allSelected && !alreadyCovered) {
                selectors.push(dir);
                files.forEach(path => covered.add(path));
            }
        });

        PICKER.selected.forEach(path => {
            if (sizes[path] !== undefined && !covered.has(path)) selectors.push(path);
        });

        // Prefer the project's own folder when everything selected lives inside
        // it, so ticking one file keeps its path (admin/updates.php) instead of
        // collapsing it to the archive root.
        const selectedFiles = Array.from(PICKER.selected);
        let root = commonRoot(selectedFiles);
        const projectRoot = projectRootField();

        if (projectRoot && selectedFiles.length) {
            const inside = selectedFiles.every(path => path.indexOf(projectRoot + '/') === 0);
            if (inside) root = projectRoot;
        }

        return {
            count: count,
            bytes: bytes,
            root: root,
            selectors: selectors,
            all: count === 0
        };
    }

    function commonRoot(paths) {
        if (!paths.length) return '';
        let common = null;
        paths.forEach(path => {
            const dir = dirOf(path);
            if (common === null) { common = dir; return; }
            if (common === '') return;
            const a = common.split('/');
            const b = dir === '' ? [] : dir.split('/');
            const shared = [];
            const limit = Math.min(a.length, b.length);
            for (let i = 0; i < limit; i++) {
                if (a[i] !== b[i]) break;
                shared.push(a[i]);
            }
            common = shared.join('/');
        });
        return common || '';
    }

    function updateStatus() {
        const status = el('treeStatus');
        if (!PICKER.loaded) {
            status.innerHTML = 'No file list loaded yet.';
            return;
        }

        const summary = selectionSummary();
        const strip = summary.root ? summary.root + '/' : 'repository root';

        if (summary.all) {
            status.innerHTML = '⚠ Nothing ticked — the <strong>entire repository</strong> will be packaged '
                + '(' + PICKER.files.length + ' files). Tick a folder to narrow it down.';
        } else {
            status.innerHTML = '📦 <strong>' + summary.count + '</strong> file' + (summary.count === 1 ? '' : 's')
                + ' · ' + fmtBytes(summary.bytes)
                + ' · root <strong>' + escapeHtml(strip) + '</strong>'
                + (summary.root ? ' <span style="color:#64748b;">(files land at the ZIP root)</span>' : '')
                + '<br><span style="color:#94a3b8;">Saved as ' + summary.selectors.length + ' selector'
                + (summary.selectors.length === 1 ? '' : 's') + '</span>';
        }
    }

    /* -----------------------------------------------------------------
     * Repository list — every repo the shared token can see, fetched once
     * per page load (server caches for 5 minutes) and searchable.
     * ----------------------------------------------------------------- */
    const REPOS = {
        loaded: false,
        loading: false,
        items: [],
        query: '',
        truncated: false,
        fetchedAt: 0,
        error: ''
    };

    function repoMenuOpen() {
        el('repoMenu').hidden = false;
    }

    function repoMenuClose() {
        el('repoMenu').hidden = true;
    }

    function repoMatches(item, query) {
        if (!query) return true;
        const haystack = (item.full_name + ' ' + (item.description || '') + ' ' + (item.owner || '')).toLowerCase();
        return query.toLowerCase().split(/\s+/).filter(Boolean).every(part => haystack.indexOf(part) !== -1);
    }

    function timeAgo(unix) {
        if (!unix) return 'just now';
        const seconds = Math.max(0, Math.round(Date.now() / 1000 - unix));
        if (seconds < 60) return 'just now';
        if (seconds < 3600) return Math.round(seconds / 60) + ' min ago';
        if (seconds < 86400) return Math.round(seconds / 3600) + ' h ago';
        return Math.round(seconds / 86400) + ' d ago';
    }

    function renderRepoMenu() {
        const box = el('repoList');

        if (REPOS.loading) {
            box.innerHTML = '<div class="repo-empty">Loading repositories from GitHub…</div>';
            el('repoCount').textContent = '…';
            return;
        }

        if (REPOS.error) {
            box.innerHTML = '<div class="repo-empty">' + escapeHtml(REPOS.error) + '</div>';
            el('repoCount').textContent = '—';
            return;
        }

        const items = REPOS.items.filter(item => repoMatches(item, REPOS.query));
        el('repoCount').textContent = items.length + ' / ' + REPOS.items.length;

        if (!items.length) {
            box.innerHTML = REPOS.items.length
                ? '<div class="repo-empty">No repository matches “' + escapeHtml(REPOS.query) + '”.</div>'
                : '<div class="repo-empty">This token cannot see any repository.</div>';
            return;
        }

        const current = (el('githubRepo').value || '').trim().toLowerCase();
        let html = '';

        items.slice(0, 200).forEach(item => {
            const active = item.full_name.toLowerCase() === current;
            html += '<div class="repo-item' + (active ? ' active' : '') + '" data-repo="' + escapeHtml(item.full_name) + '"'
                + ' data-branch="' + escapeHtml(item.default_branch || '') + '" tabindex="0">'
                + '<span aria-hidden="true">' + (item.private ? '🔒' : '🌐') + '</span>'
                + '<div style="flex:1;min-width:0;">'
                + '<div class="name">' + escapeHtml(item.full_name) + '</div>'
                + (item.description ? '<div class="meta">' + escapeHtml(item.description) + '</div>' : '')
                + '</div>'
                + (item.fork ? '<span class="badge badge-slate">fork</span>' : '')
                + (item.archived ? '<span class="badge badge-amber">archived</span>' : '')
                + '</div>';
        });

        if (items.length > 200) {
            html += '<div class="repo-empty">' + (items.length - 200) + ' more matches — keep typing to narrow it down.</div>';
        }

        box.innerHTML = html;
        el('repoFoot').textContent = REPOS.truncated
            ? 'Showing the 500 most recently updated repositories.'
            : '';
    }

    async function loadRepos(force) {
        if (REPOS.loading) return;
        if (REPOS.loaded && !force) {
            renderRepoMenu();
            return;
        }

        REPOS.loading = true;
        REPOS.error = '';
        renderRepoMenu();

        const result = await lmPost('ajax_github.php', {
            action: 'repos',
            tier_id: currentProject ? currentProject.id : 0,
            refresh: force ? 1 : 0
        });

        REPOS.loading = false;

        if (result.status === 'error') {
            REPOS.loaded = false;
            REPOS.error = result.message;
            el('repoHint').innerHTML = 'Could not list repositories — you can still type <code>owner/repo</code>.';
            renderRepoMenu();
            return;
        }

        REPOS.items = result.repos || [];
        REPOS.loaded = true;
        REPOS.truncated = !!result.truncated;
        REPOS.fetchedAt = result.fetched_at || 0;

        el('repoHint').innerHTML = '📚 <strong>' + REPOS.items.length + '</strong> repositor'
            + (REPOS.items.length === 1 ? 'y' : 'ies')
            + ' visible to your ' + (result.token_source === 'project' ? 'project' : 'shared') + ' token'
            + (REPOS.truncated ? ' (newest 500)' : '')
            + ' · updated ' + timeAgo(REPOS.fetchedAt) + '. Click the field to browse.';

        renderRepoMenu();
    }

    async function loadBranches() {
        if (!currentProject) return;

        const repo = el('githubRepo').value.trim();
        if (!repo) return;

        const result = await lmPost('ajax_github.php', {
            action: 'branches',
            tier_id: currentProject.id,
            repo: repo,
            branch: el('githubBranch').value.trim()
        });

        if (result.status !== 'success') return;

        el('branchList').innerHTML = (result.branches || [])
            .map(branch => '<option value="' + escapeHtml(branch) + '"></option>')
            .join('');

        if (!el('githubBranch').value && result.branch) {
            el('githubBranch').placeholder = result.branch;
        }
    }

    async function chooseRepo(fullName, defaultBranch) {
        el('githubRepo').value = fullName;
        el('githubBranch').value = '';
        el('githubBranch').placeholder = defaultBranch || 'default branch';
        repoMenuClose();
        REPOS.query = '';
        el('repoFilter').value = '';

        await loadBranches();
        await loadTree();
        loadPreview(true);
    }

    el('githubRepo').addEventListener('focus', function () {
        repoMenuOpen();
        loadRepos(false);
    });

    el('githubRepo').addEventListener('input', function () {
        REPOS.query = this.value.trim();
        repoMenuOpen();
        renderRepoMenu();
    });

    el('githubRepo').addEventListener('keydown', function (event) {
        if (event.key !== 'Enter') return;
        event.preventDefault();

        const first = el('repoList').querySelector('.repo-item');
        if (!el('repoMenu').hidden && first) {
            chooseRepo(first.dataset.repo, first.dataset.branch);
        } else {
            repoMenuClose();
            loadBranches();
            loadTree();
        }
    });

    el('repoFilter').addEventListener('input', function () {
        REPOS.query = this.value.trim();
        renderRepoMenu();
    });

    el('repoFilter').addEventListener('keydown', function (event) {
        if (event.key !== 'Enter') return;
        event.preventDefault();
        const first = el('repoList').querySelector('.repo-item');
        if (first) chooseRepo(first.dataset.repo, first.dataset.branch);
    });

    el('repoList').addEventListener('click', function (event) {
        const item = event.target.closest('.repo-item');
        if (item) chooseRepo(item.dataset.repo, item.dataset.branch);
    });

    el('repoList').addEventListener('keydown', function (event) {
        if (event.key !== 'Enter') return;
        const item = event.target.closest('.repo-item');
        if (item) chooseRepo(item.dataset.repo, item.dataset.branch);
    });

    el('repoRefreshBtn').addEventListener('click', async function () {
        repoMenuOpen();
        await loadRepos(true);
    });

    document.addEventListener('click', function (event) {
        if (!event.target.closest('#repoPicker')) repoMenuClose();
    });

    async function loadTree() {
        if (!currentProject) return;
        const button = el('reloadTreeBtn');
        button.disabled = true;
        button.textContent = 'Loading…';
        PICKER.loading = true;

        const result = await lmPost('ajax_github.php', {
            action: 'tree',
            tier_id: currentProject.id,
            repo: el('githubRepo').value.trim(),
            branch: el('githubBranch').value.trim()
        });

        PICKER.loading = false;
        button.disabled = false;
        button.textContent = '↻ Load files';

        if (result.status === 'error') {
            el('treeBox').innerHTML = '<div class="banner banner-err" style="margin:12px;">' + escapeHtml(result.message) + '</div>';
            el('treeStatus').textContent = 'Could not load the file list.';
            return;
        }

        PICKER.files = result.files || [];
        PICKER.dirs = result.dirs || [];
        PICKER.selected = new Set();
        PICKER.expanded = new Set();
        PICKER.query = '';
        el('treeSearch').value = '';
        PICKER.loaded = true;

        // Remember the project root so cron auto-pull and later pulls agree.
        if (!projectRootField() && result.selection && result.selection.root) {
            el('githubSubdir').value = result.selection.root;
        }

        buildIndex();

        // Restore the saved selection, then fall back to the default folder.
        const stored = (result.includes && result.includes.length) ? result.includes : [];
        if (stored.length) {
            stored.forEach(selector => selectPath(selector, true));
        } else if (result.selection && result.selection.root) {
            selectPath(result.selection.root, true);
        }

        // Open the first two levels so the structure is obvious.
        PICKER.dirs.forEach(dir => {
            if (dir.split('/').length <= 2) PICKER.expanded.add(dir);
        });

        renderTree();
        updateStatus();

        if (result.truncated) {
            el('treeStatus').innerHTML += '<br><span style="color:#b45309;">This repository is very large — use the filter box; only the first '
                + PICKER.files.length + ' entries were loaded.</span>';
        }
    }

    function selectPath(path, expand) {
        path = String(path || '').replace(/^\/+|\/+$/g, '');
        if (!path) return false;

        let matched = false;

        if (PICKER.dirFiles[path]) {
            toggleDir(path, true);
            matched = true;
        }

        PICKER.files.forEach(file => {
            if (file.path === path || file.path.indexOf(path + '/') === 0) {
                PICKER.selected.add(file.path);
                matched = true;
            }
        });

        if (expand && matched) {
            let dir = PICKER.dirFiles[path] ? path : dirOf(path);
            while (dir) {
                PICKER.expanded.add(dir);
                dir = dirOf(dir);
            }
        }

        return matched;
    }

    function tokenBadge(project) {
        const badge = el('tokenBadge');
        if (project.token_source === 'project') {
            badge.textContent = 'Project token';
            badge.className = 'badge badge-green';
        } else if (project.token_source === 'default' || project.token_source === 'default_after_save') {
            badge.textContent = 'Shared token';
            badge.className = 'badge badge-indigo';
        } else if (project.has_token) {
            badge.textContent = 'Token saved';
            badge.className = 'badge badge-green';
        } else {
            badge.textContent = 'No token';
            badge.className = 'badge badge-slate';
        }
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
        tokenBadge(project);
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

        PICKER.loaded = false;
        PICKER.files = [];
        PICKER.dirs = [];
        PICKER.selected = new Set();
        PICKER.expanded = new Set();
        el('treeBox').innerHTML = '<div class="empty" style="padding:26px 18px;font-size:12.5px;">Press <strong>Load files</strong> to browse the repository.</div>';
        el('treeStatus').textContent = 'No file list loaded yet.';

        el('previewBody').innerHTML = '<div class="empty" style="padding:30px 0;">Loading preview…</div>';
        loadPreview();
        loadPulls();

        if (currentProject.repo_full) {
            loadBranches();
            loadTree();
        }
    }

    el('tierSelect').addEventListener('change', function () { selectProject(this.value); });

    el('configForm').addEventListener('submit', async function (event) {
        event.preventDefault();
        const data = Object.fromEntries(new FormData(this).entries());
        data.action = 'save_config';
        data.tier_id = currentProject.id;
        data.github_auto_pull = el('githubAutoPull').checked ? '1' : '0';

        // Persist the ticked folders/files (cron auto-pull repeats them).
        const summary = selectionSummary();
        summary.selectors.forEach(selector => {
            if (!Array.isArray(data['github_includes[]'])) data['github_includes[]'] = [];
            data['github_includes[]'].push(selector);
        });

        const result = await lmPost('ajax_github.php', data);
        toast(result.message, result.status === 'error' ? 'err' : 'ok');

        if (result.status !== 'error') {
            currentProject.repo = result.repo || data.github_repo;
            currentProject.has_token = !!result.has_token;
            currentProject.token_source = result.token_source || (result.has_token ? 'project' : 'none');
            el('githubToken').value = '';
            tokenBadge(currentProject);
        }
    });

    el('clearTokenBtn').addEventListener('click', async function () {
        if (!confirm('Remove the stored GitHub token?')) return;
        const result = await lmPost('ajax_github.php', { action: 'clear_token', tier_id: currentProject.id });
        toast(result.message, result.status === 'error' ? 'err' : 'ok');
        if (result.status !== 'error') {
            currentProject.has_token = false;
            currentProject.token_source = result.token_source || 'none';
            tokenBadge(currentProject);
        }
    });

    /* ---------- tree interactions ---------- */
    el('reloadTreeBtn').addEventListener('click', loadTree);

    el('treeBox').addEventListener('click', function (event) {
        const twisty = event.target.closest('.twisty');
        if (twisty && twisty.dataset.toggle) {
            const dir = twisty.dataset.toggle;
            if (PICKER.expanded.has(dir)) PICKER.expanded.delete(dir); else PICKER.expanded.add(dir);
            renderTree();
            updateStatus();
        }
    });

    el('treeBox').addEventListener('change', function (event) {
        const input = event.target.closest('.tree-check');
        if (!input) return;

        const path = input.dataset.path;
        if (input.dataset.type === 'dir') {
            toggleDir(path, input.checked);

            // First tick on a folder seeds the project root.
            if (input.checked && !projectRootField()) {
                el('githubSubdir').value = path;
            }
        } else if (input.checked) {
            PICKER.selected.add(path);
        } else {
            PICKER.selected.delete(path);
        }

        renderTree();
        updateStatus();
        schedulePreviewRefresh();
    });

    el('treeSearch').addEventListener('input', function () {
        PICKER.query = this.value.trim();
        renderTree();
    });

    el('selectAllBtn').addEventListener('click', function () {
        if (!PICKER.loaded) return;
        if (PICKER.query !== '') {
            const term = PICKER.query.toLowerCase();
            PICKER.files.forEach(file => {
                if (file.path.toLowerCase().indexOf(term) !== -1) PICKER.selected.add(file.path);
            });
        } else {
            PICKER.files.forEach(file => PICKER.selected.add(file.path));
        }
        renderTree();
        updateStatus();
        schedulePreviewRefresh();
    });

    el('clearSelBtn').addEventListener('click', function () {
        PICKER.selected = new Set();
        renderTree();
        updateStatus();
        schedulePreviewRefresh();
    });

    el('selectSubdirBtn').addEventListener('click', function () {
        if (!PICKER.loaded) {
            toast('Load the file list first.', 'err');
            return;
        }
        const value = el('githubSubdir').value.trim();
        if (!value) {
            toast('Type a folder in “Default folder” first, e.g. LM', 'err');
            return;
        }
        PICKER.selected = new Set();
        if (!selectPath(value, true)) {
            toast('“' + value + '” is not in this repository.', 'err');
        }
        renderTree();
        updateStatus();
        schedulePreviewRefresh();
    });

    el('saveSelectionBtn').addEventListener('click', async function () {
        if (!currentProject) return;
        if (!PICKER.loaded) {
            toast('Load the file list first.', 'err');
            return;
        }

        const summary = selectionSummary();
        const data = {
            action: 'save_config',
            tier_id: currentProject.id,
            github_repo: currentProject.repo || '',
            github_branch: el('githubBranch').value.trim(),
            github_subdir: summary.root,
            github_version_source: el('githubVersionSource').value,
            github_increment: el('githubIncrement').value,
            github_changelog_format: el('githubChangelogFormat').value,
            github_changelog_mode: el('githubChangelogMode').value,
            github_auto_pull: el('githubAutoPull').checked ? '1' : '0'
        };
        data['github_includes[]'] = summary.selectors;

        const result = await lmPost('ajax_github.php', data);
        toast(result.message, result.status === 'error' ? 'err' : 'ok');
        if (result.status !== 'error') {
            currentProject.includes = summary.selectors;
            currentProject.subdir = summary.root;
            el('githubSubdir').value = summary.root;
        }
    });

    el('testBtn').addEventListener('click', async function () {
        const button = this;
        button.disabled = true;
        button.textContent = 'Testing…';
        const result = await lmPost('ajax_github.php', {
            action: 'test',
            tier_id: currentProject.id,
            repo: el('githubRepo').value.trim(),
            branch: el('githubBranch').value.trim()
        });
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

    let previewTimer = null;

    function schedulePreviewRefresh() {
        if (!currentProject || !PICKER.loaded) return;
        clearTimeout(previewTimer);
        previewTimer = setTimeout(() => { loadPreview(true); }, 600);
    }

    async function loadPreview(silent) {
        if (!currentProject) return;
        if (!silent) {
            el('previewBtn').disabled = true;
        }

        const summary = selectionSummary();
        const payload = {
            action: 'preview',
            tier_id: currentProject.id,
            repo: el('githubRepo').value.trim(),
            branch: el('githubBranch').value.trim()
        };
        if (PICKER.loaded && summary.count > 0) {
            payload['paths[]'] = summary.selectors;
        } else if (PICKER.loaded) {
            payload.selection_cleared = '1';
        }

        const result = await lmPost('ajax_github.php', payload);
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
        } else if (data.selection_changed) {
            html += '<div class="banner banner-info" style="margin-bottom:16px;">ℹ This commit was already published, but the selected files changed — '
                + 'pulling publishes a new package with the current selection.</div>';
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

        const summary = selectionSummary();
        let packageLine = '';

        if (!PICKER.loaded) {
            packageLine = '<div class="tree-status" style="margin:0 0 16px;">Press <strong>Load files</strong> above to choose what goes into the package. '
                + 'Until then the project\'s saved selection is used.</div>';
        } else if (summary.all) {
            packageLine = '<div class="tree-status" style="margin:0 0 16px;">⚠ Nothing ticked: the whole repository will be packaged ('
                + PICKER.files.length + ' files). Tick folders above to narrow it down.</div>';
        } else {
            packageLine = '<div class="tree-status" style="margin:0 0 16px;">📦 Packaging <strong>' + summary.count + '</strong> file'
                + (summary.count === 1 ? '' : 's') + ' · ' + fmtBytes(summary.bytes)
                + (summary.root ? ' · root <strong>' + escapeHtml(summary.root + '/') + '</strong>' : '') + '</div>';
        }

        html += packageLine;

        html += '<div style="display:flex;gap:10px;flex-wrap:wrap;">'
            + '<button class="btn btn-primary" type="button" id="pullBtn">🚀 Pull &amp; publish v' + escapeHtml(data.next_version) + '</button>'
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
        const summary = selectionSummary();

        const payload = {
            action: 'pull',
            tier_id: currentProject.id,
            version: el('pullVersion').value,
            changelog: el('pullChangelog').value,
            changelog_format: format ? format.value : 'html',
            increment: el('githubIncrement').value,
            notify: el('pullNotify').checked ? '1' : '0',
            force: force ? '1' : '0',
            root: summary.root
        };

        if (PICKER.loaded) {
            if (summary.count > 0) {
                payload['paths[]'] = summary.selectors;
            } else {
                // Explicit: the admin cleared every tick on purpose.
                payload.selection_cleared = '1';
            }
        }

        const result = await lmPost('ajax_github.php', payload);

        button.disabled = false;
        button.textContent = original;
        toast(result.message, result.status === 'error' ? 'err' : 'ok');

        if (result.status === 'success' && !result.up_to_date) {
            el('previewBody').innerHTML = '<div class="banner banner-ok" style="margin:0 0 14px;">✓ Published <strong>v'
                + escapeHtml(result.version) + '</strong> from <code>' + escapeHtml(String(result.commit || '').slice(0, 7)) + '</code>. '
                + (result.files_packaged ? '<strong>' + result.files_packaged + ' files</strong> packaged'
                    + (result.package_root ? ' from <code>' + escapeHtml(result.package_root + '/') + '</code>' : '') + '. '
                    : '')
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
    loadRepos(false);
</script>

<?php endif; ?>

<?php lm_admin_page_end(); ?>

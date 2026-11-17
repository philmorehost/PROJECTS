<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit();
}

require_once('../db.php');
require_once('../includes/ota_github_helper.php');

$settings = OTAGitHubHelper::loadSettings();
$defaultRepo = $settings['github_default_repo'] ?? 'philmorehost/PROJECTS';
$defaultBranch = $settings['github_default_branch'] ?? 'main';

$flash = null;

// Handle Add / Edit / Delete Project Tier
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = $_POST['form_action'] ?? '';

    if ($formAction === 'save_project') {
        $id = intval($_POST['id'] ?? 0);
        $tier_name = trim($_POST['tier_name'] ?? '');
        $tier_code = strtoupper(preg_replace('/[^a-zA-Z0-9_-]/', '-', trim($_POST['tier_code'] ?? '')));
        $description = trim($_POST['description'] ?? '');
        $github_repo = trim($_POST['github_repo'] ?? '');
        $github_branch = trim($_POST['github_branch'] ?? 'main') ?: 'main';
        $github_subdir = trim(str_replace('\\', '/', trim($_POST['github_subdir'] ?? '')), '/');
        $exclude_paths = trim($_POST['exclude_paths'] ?? '');
        $signal_endpoint = '/' . ltrim(trim($_POST['signal_endpoint'] ?? '/api/v1/ota-signal.php') ?: '/api/v1/ota-signal.php', '/');
        $auto_pull_github = isset($_POST['auto_pull_github']) ? 1 : 0;
        $auto_signal = isset($_POST['auto_signal']) ? 1 : 0;

        if ($tier_name === '' || $tier_code === '') {
            $flash = ['type' => 'error', 'text' => 'Project Name and Tier Code are required.'];
        } else {
            try {
                if ($id > 0) {
                    // Protect legacy DGV7.0 tier codes on IDs 1 & 2 from accidental rename
                    if ($id === 1) {
                        $tier_code = 'SAAS';
                    } elseif ($id === 2) {
                        $tier_code = 'NON-SAAS';
                    }

                    $stmt = $pdo->prepare("
                        UPDATE script_tiers
                        SET tier_name = ?, tier_code = ?, description = ?, github_repo = ?, github_branch = ?,
                            github_subdir = ?, exclude_paths = ?, signal_endpoint = ?, auto_pull_github = ?, auto_signal = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $tier_name, $tier_code, $description, $github_repo ?: null, $github_branch,
                        $github_subdir ?: null, $exclude_paths ?: null, $signal_endpoint, $auto_pull_github, $auto_signal, $id
                    ]);
                    header('Location: projects.php?saved=1');
                    exit();
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO script_tiers
                            (tier_name, tier_code, description, github_repo, github_branch, github_subdir, exclude_paths, signal_endpoint, auto_pull_github, auto_signal)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $tier_name, $tier_code, $description, $github_repo ?: null, $github_branch,
                        $github_subdir ?: null, $exclude_paths ?: null, $signal_endpoint, $auto_pull_github, $auto_signal
                    ]);
                    header('Location: projects.php?added=1');
                    exit();
                }
            } catch (PDOException $e) {
                $flash = ['type' => 'error', 'text' => 'Database error (check if Tier Code already exists): ' . $e->getMessage()];
            }
        }
    } elseif ($formAction === 'delete_project') {
        $delId = intval($_POST['id'] ?? 0);
        if (in_array($delId, [1, 2], true)) {
            $flash = ['type' => 'error', 'text' => 'Default DGV7.0 tiers (IDs 1 & 2) are protected to preserve backward compatibility.'];
        } elseif ($delId > 2) {
            try {
                $licCountStmt = $pdo->prepare("SELECT COUNT(*) FROM licenses WHERE tier_id = ?");
                $licCountStmt->execute([$delId]);
                $licCount = (int)$licCountStmt->fetchColumn();
                if ($licCount > 0) {
                    $flash = ['type' => 'error', 'text' => "Cannot delete this project while {$licCount} license(s) are assigned to it. Reassign those licenses first."];
                } else {
                    $delStmt = $pdo->prepare("DELETE FROM script_tiers WHERE id = ?");
                    $delStmt->execute([$delId]);
                    header('Location: projects.php?deleted=1');
                    exit();
                }
            } catch (PDOException $e) {
                $flash = ['type' => 'error', 'text' => 'Delete failed: ' . $e->getMessage()];
            }
        }
    }
}

if (isset($_GET['saved'])) {
    $flash = ['type' => 'success', 'text' => 'Project configuration updated successfully.'];
} elseif (isset($_GET['added'])) {
    $flash = ['type' => 'success', 'text' => 'New project registered successfully! You can now assign licenses and pull updates from GitHub.'];
} elseif (isset($_GET['deleted'])) {
    $flash = ['type' => 'success', 'text' => 'Project removed successfully.'];
}

$editProject = null;
if (isset($_GET['edit'])) {
    $editStmt = $pdo->prepare("SELECT * FROM script_tiers WHERE id = ? LIMIT 1");
    $editStmt->execute([intval($_GET['edit'])]);
    $editProject = $editStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Fetch all projects with license & update stats
$projects = $pdo->query("
    SELECT t.*,
           (SELECT COUNT(*) FROM licenses l WHERE l.tier_id = t.id AND l.status = 'active') AS active_licenses,
           (SELECT u.version_number FROM script_updates u WHERE u.tier_id = t.id AND u.is_released = 1 ORDER BY u.release_date DESC, u.id DESC LIMIT 1) AS latest_version
    FROM script_tiers t
    ORDER BY t.id ASC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Projects - License Manager</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%); display: flex; min-height: 100vh; color: #1e293b; }
        .sidebar { width: 260px; background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%); color: #fff; display: flex; flex-direction: column; position: fixed; height: 100vh; overflow-y: auto; box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1); }
        .sidebar h1 { font-size: 1.5rem; padding: 2rem 1.5rem; text-align: center; background: rgba(15, 23, 42, 0.5); margin: 0; border-bottom: 1px solid rgba(255, 255, 255, 0.1); }
        .sidebar nav { flex-grow: 1; padding: 2rem 0; }
        .sidebar nav a { display: flex; align-items: center; padding: 1rem 1.5rem; color: #cbd5e1; text-decoration: none; transition: all 0.3s ease; }
        .sidebar nav a:hover, .sidebar nav a.active { background: rgba(59, 130, 246, 0.2); color: #fff; border-left: 3px solid #3b82f6; padding-left: calc(1.5rem - 3px); }
        .main-content { margin-left: 260px; flex-grow: 1; padding: 2rem; overflow-y: auto; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.75rem; flex-wrap: wrap; gap: 1rem; }
        .header h2 { font-size: 2rem; font-weight: 700; }
        .card { background: white; border-radius: 12px; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08); overflow: hidden; margin-bottom: 2rem; }
        .card-header { padding: 1.25rem 2rem; border-bottom: 1px solid #e2e8f0; background: linear-gradient(135deg, #f8fafc 0%, #eef2ff 100%); display: flex; justify-content: space-between; align-items: center; }
        .card-header h3 { font-size: 1.15rem; font-weight: 700; }
        .card-body { padding: 2rem; }
        .grid-2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1.25rem; }
        .grid-3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1.25rem; }
        .form-group { margin-bottom: 1.25rem; }
        .form-group label { display: block; margin-bottom: 0.4rem; font-weight: 600; color: #334155; font-size: 0.875rem; }
        .form-group small { display: block; margin-top: 0.35rem; color: #64748b; font-size: 0.78rem; line-height: 1.4; }
        .form-control { width: 100%; padding: 0.72rem 0.95rem; border: 1px solid #cbd5e1; border-radius: 8px; font-family: inherit; font-size: 0.875rem; }
        .form-control:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.12); }
        .toggle-box { display: flex; align-items: center; gap: 0.65rem; padding: 0.85rem 1rem; border: 1px solid #e2e8f0; border-radius: 8px; background: #f8fafc; font-size: 0.875rem; font-weight: 500; }
        .btn { display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.7rem 1.35rem; border-radius: 8px; font-weight: 600; cursor: pointer; text-decoration: none; border: none; font-size: 0.875rem; transition: all 0.2s ease; }
        .btn-primary { background: #3b82f6; color: white; }
        .btn-primary:hover { background: #2563eb; }
        .btn-secondary { background: #e2e8f0; color: #334155; }
        .btn-danger { background: #fee2e2; color: #b91c1c; }
        .btn-danger:hover { background: #ef4444; color: white; }
        .btn-sm { padding: 0.38rem 0.75rem; font-size: 0.75rem; border-radius: 6px; }
        .alert { padding: 1rem 1.25rem; border-radius: 10px; margin-bottom: 1.5rem; font-size: 0.9rem; font-weight: 500; }
        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f8fafc; padding: 0.9rem 1rem; text-align: left; font-weight: 600; color: #475569; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 2px solid #e2e8f0; }
        td { padding: 0.95rem 1rem; border-bottom: 1px solid #e2e8f0; font-size: 0.85rem; vertical-align: middle; }
        .badge { display: inline-block; padding: 0.22rem 0.6rem; border-radius: 999px; font-size: 0.73rem; font-weight: 700; background: #e0e7ff; color: #3730a3; }
        .badge-green { background: #dcfce7; color: #166534; }
        .badge-gray { background: #f1f5f9; color: #64748b; }
        @media (max-width: 900px) { .sidebar { position: relative; width: 100%; height: auto; } .main-content { margin-left: 0; } .grid-2, .grid-3 { grid-template-columns: 1fr; } body { flex-direction: column; } }
    </style>
</head>
<body>
    <div class="sidebar">
        <h1>🔐 License Manager</h1>
        <nav>
            <a href="dashboard.php">📊 Dashboard</a>
            <a href="projects.php" class="active">📦 Projects</a>
            <a href="licenses.php">📜 Licenses</a>
            <a href="updates.php">🚀 Push Updates</a>
            <a href="transactions.php">💳 Transactions</a>
            <a href="settings.php">⚙️ Settings</a>
            <a href="profile.php">👤 Profile</a>
            <hr style="border: none; border-top: 1px solid rgba(255,255,255,0.2); margin: 0.5rem 0;">
            <a href="logout.php">🚪 Logout</a>
        </nav>
    </div>

    <div class="main-content">
        <div class="header">
            <div>
                <h2>Projects &amp; GitHub Directory Mapping</h2>
                <p style="color:#64748b; font-size:0.9rem; margin-top:0.25rem;">Register any software project, map it to its GitHub directory (e.g. <code>philmorehost/PROJECTS</code> &rarr; <code>SMSP</code>), and configure live client update signals.</p>
            </div>
            <?php if ($editProject): ?>
                <a href="projects.php" class="btn btn-secondary">+ Add New Project</a>
            <?php endif; ?>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>">
                <?= htmlspecialchars($flash['text']) ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h3><?= $editProject ? '✏️ Edit Project: ' . htmlspecialchars($editProject['tier_name']) : '➕ Register New Project / Script Tier' ?></h3>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="form_action" value="save_project">
                    <input type="hidden" name="id" value="<?= intval($editProject['id'] ?? 0) ?>">

                    <div class="grid-2">
                        <div class="form-group">
                            <label>Project / Edition Name *</label>
                            <input type="text" name="tier_name" class="form-control" required
                                   placeholder="e.g. SchoolApp Multi-Tenant SaaS"
                                   value="<?= htmlspecialchars($editProject['tier_name'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>Unique Tier Code *</label>
                            <?php $lockedCode = isset($editProject['id']) && in_array((int)$editProject['id'], [1, 2], true); ?>
                            <input type="text" name="tier_code" class="form-control" required
                                   placeholder="e.g. SMSP-SAAS or HOSPITAL-ERP"
                                   value="<?= htmlspecialchars($editProject['tier_code'] ?? '') ?>"
                                   <?= $lockedCode ? 'readonly style="background:#f1f5f9;"' : '' ?>>
                            <small><?= $lockedCode ? 'Protected legacy code for live DGV7.0 backward compatibility.' : 'Used by client scripts in expected_tier / project_family (uppercase letters, numbers, hyphens).' ?></small>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Description</label>
                        <input type="text" name="description" class="form-control"
                               placeholder="Brief summary of this project or license edition"
                               value="<?= htmlspecialchars($editProject['description'] ?? '') ?>">
                    </div>

                    <div class="grid-3">
                        <div class="form-group">
                            <label>GitHub Repository (owner/repo)</label>
                            <input type="text" name="github_repo" class="form-control"
                                   placeholder="<?= htmlspecialchars($defaultRepo) ?>"
                                   value="<?= htmlspecialchars($editProject['github_repo'] ?? $defaultRepo) ?>">
                            <small>Repository containing this project.</small>
                        </div>
                        <div class="form-group">
                            <label>GitHub Branch</label>
                            <input type="text" name="github_branch" class="form-control"
                                   placeholder="main"
                                   value="<?= htmlspecialchars($editProject['github_branch'] ?? $defaultBranch) ?>">
                            <small>Branch tracked for releases (e.g. <code>main</code>).</small>
                        </div>
                        <div class="form-group">
                            <label>Subdirectory inside Repo</label>
                            <input type="text" name="github_subdir" class="form-control"
                                   placeholder="e.g. SMSP or VTU/DGV7.0-SAAS"
                                   value="<?= htmlspecialchars($editProject['github_subdir'] ?? '') ?>">
                            <small>Leave blank only if the entire repository is this single project.</small>
                        </div>
                    </div>

                    <div class="grid-2">
                        <div class="form-group">
                            <label>Excluded Paths when Packaging from GitHub</label>
                            <textarea name="exclude_paths" class="form-control" rows="3" placeholder="config/config.php, install/, dataimg/, uploads/, mobile-android/, mobile-ios/"><?= htmlspecialchars($editProject['exclude_paths'] ?? 'config/config.php, config/.sys_cache, install/, backups/, tmp_update/, logs/, dataimg/, uploads/') ?></textarea>
                            <small>Comma- or newline-separated files/folders relative to the project folder that must NOT overwrite live tenant configs or uploads.</small>
                        </div>
                        <div class="form-group">
                            <label>Client Website Update Signal Endpoint</label>
                            <input type="text" name="signal_endpoint" class="form-control"
                                   placeholder="/api/v1/ota-signal.php"
                                   value="<?= htmlspecialchars($editProject['signal_endpoint'] ?? '/api/v1/ota-signal.php') ?>">
                            <small>Relative URL on licensed websites where LM POSTs signed <code>X-LM-Signature</code> update notifications.</small>

                            <div class="grid-2" style="margin-top: 0.85rem;">
                                <label class="toggle-box">
                                    <input type="checkbox" name="auto_pull_github" value="1" <?= (!isset($editProject) || !empty($editProject['auto_pull_github'])) ? 'checked' : '' ?>>
                                    <span>Auto-pull on GitHub push</span>
                                </label>
                                <label class="toggle-box">
                                    <input type="checkbox" name="auto_signal" value="1" <?= (!isset($editProject) || !empty($editProject['auto_signal'])) ? 'checked' : '' ?>>
                                    <span>Auto-signal licensed sites</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div style="display:flex; gap:0.75rem; justify-content:flex-end;">
                        <?php if ($editProject): ?>
                            <a href="projects.php" class="btn btn-secondary">Cancel</a>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-primary"><?= $editProject ? '💾 Save Project Changes' : '➕ Register Project' ?></button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3>📦 Registered Projects (<?= count($projects) ?>)</h3>
                <a href="updates.php" class="btn btn-primary btn-sm">🚀 Go to Push / Pull Updates</a>
            </div>
            <div class="card-body" style="padding: 0; overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Project &amp; Tier Code</th>
                            <th>GitHub Source</th>
                            <th>Signal Endpoint</th>
                            <th>Automation</th>
                            <th>Active Licenses</th>
                            <th>Latest Release</th>
                            <th style="text-align:right; padding-right:1.5rem;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($projects as $p): ?>
                        <tr>
                            <td>#<?= (int)$p['id'] ?></td>
                            <td>
                                <div style="font-weight:700; color:#0f172a;"><?= htmlspecialchars($p['tier_name']) ?></div>
                                <span class="badge" style="margin-top:0.25rem;"><?= htmlspecialchars($p['tier_code']) ?></span>
                            </td>
                            <td>
                                <?php if (!empty($p['github_repo'])): ?>
                                    <code style="font-size:0.78rem; color:#334155;"><?= htmlspecialchars($p['github_repo']) ?>@<?= htmlspecialchars($p['github_branch'] ?: 'main') ?></code>
                                    <div style="font-size:0.75rem; color:#64748b; margin-top:0.2rem;">
                                        📁 <code><?= htmlspecialchars($p['github_subdir'] ?: '(repo root)') ?></code>
                                        <?php if (!empty($p['last_github_sha'])): ?>
                                            &bull; SHA <code><?= htmlspecialchars(substr($p['last_github_sha'], 0, 7)) ?></code>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span style="color:#94a3b8;">Manual ZIP only</span>
                                <?php endif; ?>
                            </td>
                            <td><code style="font-size:0.76rem; color:#475569;"><?= htmlspecialchars($p['signal_endpoint'] ?? '/api/v1/ota-signal.php') ?></code></td>
                            <td>
                                <span class="badge <?= !empty($p['auto_pull_github']) ? 'badge-green' : 'badge-gray' ?>">GH Push: <?= !empty($p['auto_pull_github']) ? 'ON' : 'OFF' ?></span>
                                <span class="badge <?= !empty($p['auto_signal']) ? 'badge-green' : 'badge-gray' ?>" style="margin-top:0.25rem;">Signal: <?= !empty($p['auto_signal']) ? 'ON' : 'OFF' ?></span>
                            </td>
                            <td><strong><?= (int)$p['active_licenses'] ?></strong></td>
                            <td>
                                <?php if (!empty($p['latest_version'])): ?>
                                    <span class="badge badge-green">v<?= htmlspecialchars($p['latest_version']) ?></span>
                                <?php else: ?>
                                    <span style="color:#94a3b8;">None yet</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:right; padding-right:1.5rem; white-space:nowrap;">
                                <a href="projects.php?edit=<?= (int)$p['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
                                <?php if (!in_array((int)$p['id'], [1, 2], true)): ?>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete project <?= htmlspecialchars(addslashes($p['tier_name'])) ?>?');">
                                        <input type="hidden" name="form_action" value="delete_project">
                                        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>

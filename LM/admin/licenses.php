<?php
require_once __DIR__ . '/../includes/security.php';
lm_session_start();
lm_security_headers();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit();
}

require_once('../db.php');

/*
 * The switches need three columns. If the panel files were uploaded but
 * includes/migrations.php was not, say so instead of dying on a SQL error —
 * and render the column read-only rather than showing a button that cannot
 * work. Checked on every request, not just on POST: the page itself draws
 * the switches.
 */
$updatesColumnReady = function_exists('lm_db_has_column')
    ? lm_db_has_column($pdo, 'licenses', 'updates_enabled')
    : true;

// Handle POST requests for license management
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!lm_verify_csrf()) {
        header('Location: licenses.php?error=csrf');
        exit();
    }

    if (isset($_POST['add_license'])) {
        $stmt = $pdo->prepare("INSERT INTO licenses (license_key, domain, customer_email, status, license_type) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$_POST['license_key'], $_POST['domain'], $_POST['customer_email'], $_POST['status'], $_POST['license_type'] ?? 'standard']);
    } elseif (isset($_POST['edit_license'])) {
        $allowUpdates = !empty($_POST['updates_enabled']) ? 1 : 0;
        $blockNote = trim((string) ($_POST['updates_block_note'] ?? ''));
        if (function_exists('mb_substr')) {
            $blockNote = mb_substr($blockNote, 0, 255);
        } else {
            $blockNote = substr($blockNote, 0, 255);
        }

        if ($updatesColumnReady) {
            $stmt = $pdo->prepare(
                "UPDATE licenses
                 SET license_key = ?, domain = ?, customer_email = ?, status = ?, license_type = ?,
                     updates_enabled = ?, updates_block_note = ?
                 WHERE id = ?"
            );
            $stmt->execute([
                $_POST['license_key'],
                $_POST['domain'],
                $_POST['customer_email'],
                $_POST['status'],
                $_POST['license_type'] ?? 'standard',
                $allowUpdates,
                $blockNote !== '' ? $blockNote : null,
                $_POST['id'],
            ]);
        } else {
            $stmt = $pdo->prepare("UPDATE licenses SET license_key = ?, domain = ?, customer_email = ?, status = ?, license_type = ? WHERE id = ?");
            $stmt->execute([
                $_POST['license_key'],
                $_POST['domain'],
                $_POST['customer_email'],
                $_POST['status'],
                $_POST['license_type'] ?? 'standard',
                $_POST['id'],
            ]);
        }

        // Going from "on hold" to "allowed" should not leave a stale date on
        // the customer's update page.
        if ($allowUpdates === 1 && $updatesColumnReady) {
            $pdo->prepare("UPDATE licenses SET updates_blocked_at = NULL WHERE id = ?")->execute([$_POST['id']]);
        }
    } elseif (isset($_POST['toggle_status'])) {
        $stmt = $pdo->prepare("UPDATE licenses SET status = ? WHERE id = ?");
        $new_status = $_POST['current_status'] === 'active' ? 'inactive' : 'active';
        $stmt->execute([$new_status, $_POST['id']]);
    } elseif (isset($_POST['toggle_updates'])) {
        if (!$updatesColumnReady) {
            header('Location: licenses.php?error=migration');
            exit();
        }

        // The switch behind "I do not want them pulling this release yet".
        // Status stays active: the site keeps working and keeps validating —
        // only the update download is held.
        $allow = (int) ($_POST['current_updates'] ?? 1) === 0 ? 1 : 0;
        $note = trim((string) ($_POST['updates_block_note'] ?? ''));

        if ($allow === 1) {
            $pdo->prepare("UPDATE licenses SET updates_enabled = 1, updates_blocked_at = NULL WHERE id = ?")->execute([$_POST['id']]);
        } else {
            $pdo->prepare("UPDATE licenses SET updates_enabled = 0, updates_blocked_at = NOW() WHERE id = ?")->execute([$_POST['id']]);
            if ($note !== '') {
                $pdo->prepare("UPDATE licenses SET updates_block_note = ? WHERE id = ?")->execute([$note, $_POST['id']]);
            }
        }
    } elseif (isset($_POST['bulk_updates'])) {
        if (!$updatesColumnReady) {
            header('Location: licenses.php?error=migration');
            exit();
        }

        // Holding a release for a whole project one licence at a time is the
        // workflow this exists for: block them all, then allow each as it pays.
        $tierId = (int) ($_POST['tier_id'] ?? 0);
        $mode = ($_POST['bulk_mode'] ?? 'hold') === 'allow' ? 'allow' : 'hold';

        if ($tierId > 0) {
            if ($mode === 'allow') {
                $pdo->prepare("UPDATE licenses SET updates_enabled = 1, updates_blocked_at = NULL WHERE tier_id = ?")->execute([$tierId]);
            } else {
                $pdo->prepare("UPDATE licenses SET updates_enabled = 0, updates_blocked_at = NOW() WHERE tier_id = ?")->execute([$tierId]);
            }
        }
    }
    header('Location: licenses.php?saved=1');
    exit();
}

// Fetch pending license requests for admin review
$reqStmt = $pdo->query("SELECT * FROM license_requests ORDER BY created_at DESC LIMIT 100");
$license_requests = $reqStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all licenses
$stmt = $pdo->query("SELECT * FROM licenses ORDER BY created_at DESC");
$licenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
 * Update access is per licence, so the page has to make one licence easy to
 * find among hundreds. These counts drive the filter chips above the table.
 */
$updatesFilter = isset($_GET['updates']) ? (string) $_GET['updates'] : 'all';
if (!in_array($updatesFilter, array('all', 'allowed', 'hold'), true)) {
    $updatesFilter = 'all';
}

$countOnHold = 0;
foreach ($licenses as $licenseRow) {
    if (isset($licenseRow['updates_enabled']) && (int) $licenseRow['updates_enabled'] === 0) {
        $countOnHold++;
    }
}
$countAllowed = count($licenses) - $countOnHold;

$filteredLicenses = array();
foreach ($licenses as $licenseRow) {
    $isOn = !isset($licenseRow['updates_enabled']) || (int) $licenseRow['updates_enabled'] !== 0;
    if ($updatesFilter === 'hold' && $isOn) {
        continue;
    }
    if ($updatesFilter === 'allowed' && !$isOn) {
        continue;
    }
    $filteredLicenses[] = $licenseRow;
}
// Projects, with how many of their licences are on hold (for the bulk control).
$tierCounts = array();
try {
    $tierStmt = $pdo->query(
        "SELECT t.id, t.tier_name, t.tier_code,
                COUNT(l.id) AS licences,
                SUM(CASE WHEN l.updates_enabled = 0 THEN 1 ELSE 0 END) AS on_hold
         FROM script_tiers t
         LEFT JOIN licenses l ON l.tier_id = t.id
         GROUP BY t.id, t.tier_name, t.tier_code
         ORDER BY t.tier_name"
    );
    $tierCounts = $tierStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $tierCounts = array();
}

// Fetch pending manual requests
$reqStmt = $pdo->query("SELECT * FROM license_requests WHERE status = 'pending' ORDER BY created_at DESC");
$requests = $reqStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Licenses - License Manager</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            display: flex;
            min-height: 100vh;
        }
        .sidebar {
            width: 260px;
            background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%);
            color: #fff;
            display: flex;
            flex-direction: column;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
        }
        .sidebar h1 {
            font-size: 1.5rem;
            padding: 2rem 1.5rem;
            text-align: center;
            background: rgba(15, 23, 42, 0.5);
            margin: 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        .sidebar nav {
            flex-grow: 1;
            padding: 2rem 0;
        }
        .sidebar nav a {
            display: flex;
            align-items: center;
            padding: 1rem 1.5rem;
            color: #cbd5e1;
            text-decoration: none;
            transition: all 0.3s ease;
            position: relative;
        }
        .sidebar nav a:hover,
        .sidebar nav a.active {
            background: rgba(59, 130, 246, 0.2);
            color: #fff;
            border-left: 3px solid #3b82f6;
            padding-left: calc(1.5rem - 3px);
        }
        .main-content {
            margin-left: 260px;
            flex-grow: 1;
            padding: 2rem;
            overflow-y: auto;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }
        .header h2 {
            font-size: 2rem;
            color: #1e293b;
            font-weight: 700;
        }
        .btn {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: #fff;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            text-decoration: none;
            border: none;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(59, 130, 246, 0.3);
        }
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            margin-bottom: 2rem;
        }
        .card-header {
            padding: 1.5rem 2rem;
            border-bottom: 1px solid #e2e8f0;
            background: linear-gradient(135deg, #f8fafc 0%, #eef2ff 100%);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .card-header h3 {
            font-size: 1.25rem;
            color: #1e293b;
            margin: 0;
            font-weight: 700;
        }
        .card-body {
            padding: 2rem;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th {
            background: #f8fafc;
            padding: 1rem;
            text-align: left;
            font-weight: 700;
            color: #475569;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 2px solid #e2e8f0;
        }
        td {
            padding: 1rem;
            border-bottom: 1px solid #e2e8f0;
            color: #475569;
        }
        tr:hover {
            background: #f8fafc;
        }
        .action-links {
            display: flex;
            gap: 1rem;
            align-items: center;
        }
        .action-links a,
        .link-button {
            color: #3b82f6;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            background: none;
            border: none;
            cursor: pointer;
            padding: 0;
            font-size: 0.9rem;
        }
        .action-links a:hover,
        .link-button:hover {
            color: #2563eb;
        }
        .input-group {
            margin-bottom: 1.5rem;
        }
        .input-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #1e293b;
        }
        .input-group input,
        .input-group select {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 1rem;
            font-family: 'Inter', sans-serif;
            transition: all 0.3s ease;
        }
        .input-group input:focus,
        .input-group select:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        .status-badge {
            display: inline-block;
            padding: 0.4rem 0.8rem;
            border-radius: 6px;
            font-size: 0.875rem;
            font-weight: 600;
        }
        .status-active {
            background: #d1fae5;
            color: #065f46;
        }
        .status-inactive {
            background: #fee2e2;
            color: #991b1b;
        }
        .status-onhold {
            background: #ffedd5;
            color: #9a3412;
        }
        .tiny-note {
            margin-top: 0.35rem;
            font-size: 0.78rem;
            color: #b45309;
            max-width: 220px;
            white-space: normal;
        }
        .link-danger {
            color: #b45309;
        }
        .notice-ok {
            background: #d1fae5;
            color: #065f46;
            border-radius: 8px;
            padding: 0.85rem 1.2rem;
            font-weight: 600;
        }
        .notice-warn {
            background: #fff7ed;
            color: #9a3412;
            border: 1px solid #fed7aa;
            border-radius: 8px;
            padding: 0.9rem 1.2rem;
            font-weight: 600;
        }
        .filter-chips {
            display: flex;
            gap: 0.4rem;
            flex-wrap: wrap;
        }
        .chip {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.35rem 0.75rem;
            border-radius: 999px;
            background: #eef2f7;
            color: #475569;
            font-size: 0.82rem;
            font-weight: 600;
            text-decoration: none;
        }
        .chip:hover {
            background: #e2e8f0;
            color: #1e293b;
        }
        .chip-active,
        .chip-active:hover {
            background: #1e293b;
            color: #fff;
        }
        .chip-count {
            background: #dbe3ec;
            color: #334155;
            border-radius: 999px;
            padding: 0 0.45rem;
            font-size: 0.75rem;
        }
        .chip-active .chip-count {
            background: rgba(255, 255, 255, 0.25);
            color: #fff;
        }
        .updates-cell {
            white-space: nowrap;
        }
        .switch-form {
            display: inline-block;
            margin: 0;
        }
        .updates-switch {
            display: inline-flex;
            align-items: center;
            gap: 0.55rem;
            padding: 0.35rem 0.8rem 0.35rem 0.4rem;
            border-radius: 999px;
            border: 1px solid transparent;
            cursor: pointer;
            font-family: 'Inter', sans-serif;
            font-size: 0.82rem;
            font-weight: 700;
            transition: background 0.15s ease, border-color 0.15s ease;
        }
        .updates-switch.is-on {
            background: #ecfdf5;
            color: #047857;
            border-color: #a7f3d0;
        }
        .updates-switch.is-off {
            background: #fff7ed;
            color: #b45309;
            border-color: #fed7aa;
        }
        .updates-switch:hover {
            filter: brightness(0.97);
        }
        .switch-track {
            position: relative;
            display: inline-block;
            width: 34px;
            height: 18px;
            border-radius: 999px;
            background: #cbd5e1;
            transition: background 0.15s ease;
        }
        .updates-switch.is-on .switch-track {
            background: #10b981;
        }
        .updates-switch.is-off .switch-track {
            background: #f59e0b;
        }
        .switch-knob {
            position: absolute;
            top: 2px;
            left: 2px;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            background: #fff;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
            transition: left 0.15s ease;
        }
        .updates-switch.is-on .switch-knob {
            left: 18px;
        }
        .switch-hint {
            font-size: 0.7rem;
            color: #94a3b8;
            margin-top: 0.25rem;
        }
        .bulk-box {
            margin-top: 1.75rem;
            border-top: 1px dashed #e2e8f0;
            padding-top: 1.1rem;
        }
        .bulk-box summary {
            cursor: pointer;
            font-weight: 700;
            color: #475569;
            font-size: 0.88rem;
        }
        .bulk-box summary:hover {
            color: #1e293b;
        }
        .bulk-intro {
            margin: 0.7rem 0 0.9rem;
            font-size: 0.82rem;
            color: #64748b;
            max-width: 620px;
            line-height: 1.5;
        }
        .empty-row {
            padding: 2rem 0;
            text-align: center;
            color: #64748b;
            font-size: 0.9rem;
        }
        .licence-key {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 0.85rem;
            color: #1e293b;
        }
        .hold-form {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            flex-wrap: wrap;
        }
        .hold-form select {
            padding: 0.55rem 0.75rem;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-family: 'Inter', sans-serif;
        }
        .btn-hold {
            background: #f59e0b;
            padding: 0.55rem 1rem;
        }
        .btn-hold:hover {
            box-shadow: 0 10px 20px rgba(245, 158, 11, 0.3);
        }
        .btn-allow {
            background: #10b981;
            padding: 0.55rem 1rem;
        }
        .btn-allow:hover {
            box-shadow: 0 10px 20px rgba(16, 185, 129, 0.3);
        }
        .checkbox-row {
            display: flex;
            gap: 0.6rem;
            align-items: center;
        }
        .input-group small {
            display: block;
            margin-top: 0.4rem;
            color: #64748b;
            font-size: 0.8rem;
            line-height: 1.45;
        }
        @media (max-width: 768px) {
            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
            }
            .main-content {
                margin-left: 0;
            }
            .header h2 {
                font-size: 1.5rem;
            }
            .header {
                flex-direction: column;
                gap: 1rem;
            }
            .btn {
                width: 100%;
                text-align: center;
            }
        }
    </style>
</head>
<body>
<?php require __DIR__ . '/_mobile_nav.php'; ?>
    <div class="sidebar">
        <h1>🔐 License Manager</h1>
        <nav>
            <a href="dashboard.php">📊 Dashboard</a>
            <a href="licenses.php" class="active">📜 Licenses</a>
            <a href="updates.php">🚀 Push Updates</a>
            <a href="github-updates.php">🐙 GitHub Pull</a>
            <a href="update-notifications.php">🔔 Notifications</a>
            <a href="projects.php">🧩 Script Projects</a>
            <a href="transactions.php">💳 Transactions</a>
            <a href="guide.php">📖 Admin Guide</a>
            <a href="settings.php">⚙️ Settings</a>
            <a href="profile.php">👤 Profile</a>
            <a href="logout.php">🚪 Logout</a>
        </nav>
    </div>
    <div class="main-content">
        <div class="header">
            <h2>Manage Licenses</h2>
            <a href="licenses.php?action=add" class="btn">Add New License</a>
        </div>

        <?php if (isset($_GET['action']) && $_GET['action'] == 'add'): ?>
        <div class="card">
            <div class="card-header"><h3>Add New License</h3></div>
            <div class="card-body">
                <form method="POST">
    <?= lm_csrf_field() ?>
                    <input type="hidden" name="add_license" value="1">
                    <div class="input-group"><label>License Key</label><input type="text" name="license_key" required></div>
                    <div class="input-group"><label>Domain</label><input type="text" name="domain" required></div>
                    <div class="input-group"><label>Customer Email</label><input type="email" name="customer_email" required></div>
                    <div class="input-group">
                        <label>License Type</label>
                        <select name="license_type">
                            <option value="standard">Standard</option>
                            <option value="extended">Extended</option>
                        </select>
                    </div>
                    <div class="input-group"><label>Status</label><select name="status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
                    <button type="submit" class="btn">Save License</button>
                </form>
            </div>
        </div>
        <?php elseif (isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['id'])):
            $stmt = $pdo->prepare("SELECT * FROM licenses WHERE id = ?");
            $stmt->execute([$_GET['id']]);
            $license = $stmt->fetch(PDO::FETCH_ASSOC);
        ?>
        <div class="card">
            <div class="card-header"><h3>Edit License</h3></div>
            <div class="card-body">
                <form method="POST">
    <?= lm_csrf_field() ?>
                    <input type="hidden" name="edit_license" value="1">
                    <input type="hidden" name="id" value="<?= $license['id'] ?>">
                    <div class="input-group"><label>License Key</label><input type="text" name="license_key" value="<?= htmlspecialchars($license['license_key']) ?>" required></div>
                    <div class="input-group"><label>Domain</label><input type="text" name="domain" value="<?= htmlspecialchars($license['domain']) ?>" required></div>
                    <div class="input-group"><label>Customer Email</label><input type="email" name="customer_email" value="<?= htmlspecialchars($license['customer_email'] ?? '') ?>" required></div>
                    <div class="input-group">
                        <label>License Type</label>
                        <select name="license_type">
                            <option value="standard" <?= ($license['license_type'] ?? 'standard') === 'standard' ? 'selected' : '' ?>>Standard</option>
                            <option value="extended" <?= ($license['license_type'] ?? 'standard') === 'extended' ? 'selected' : '' ?>>Extended</option>
                        </select>
                    </div>
                    <div class="input-group"><label>Status</label><select name="status">
                        <option value="active" <?= $license['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $license['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select></div>

                    <div class="input-group">
                        <label>Update downloads</label>
                        <div class="checkbox-row">
                            <input type="checkbox" name="updates_enabled" value="1" id="edit-updates-enabled"
                                <?= (isset($license['updates_enabled']) && (int) $license['updates_enabled'] === 0) ? '' : 'checked' ?>>
                            <span><strong>Allow this licence to pull updates</strong></span>
                        </div>
                        <small>
                            Unticking this keeps the site and its licence working, but the vendor's update page
                            stops offering the package and shows a “contact the developer” card instead — with the
                            WhatsApp button from Settings. Use it after publishing a release they have not paid for,
                            then tick it again for each vendor as they pay.
                        </small>
                    </div>

                    <div class="input-group">
                        <label>Message shown while on hold (optional)</label>
                        <input type="text" name="updates_block_note" maxlength="255"
                               value="<?= htmlspecialchars((string) ($license['updates_block_note'] ?? '')) ?>"
                               placeholder="e.g. Update access for v7.41 — balance NGN 15,000">
                        <small>Appears on their update page and nowhere else. Leave empty for the standard wording.</small>
                    </div>

                    <button type="submit" class="btn">Save Changes</button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if (isset($_GET['saved'])): ?>
            <div class="notice-ok" style="margin-bottom:1.5rem;">Saved.</div>
        <?php endif; ?>

        <?php if (isset($_GET['error']) && $_GET['error'] === 'migration'): ?>
            <div class="notice-warn" style="margin-bottom:1.5rem;">
                The update-access columns are not in the database yet. Upload
                <code>includes/migrations.php</code> and reload this page once — the migration runs by itself.
            </div>
        <?php endif; ?>

        <div class="card" style="margin-top: 2rem;" id="licences">
            <div class="card-header" style="flex-wrap:wrap;gap:1rem;">
                <div>
                    <h3>All Licenses</h3>
                    <span style="font-size:0.85rem;color:#64748b;">Every licence has its own <strong>Updates</strong> switch — one vendor at a time.</span>
                </div>
                <div class="filter-chips">
                    <?php
                    $chipBase = 'licenses.php?updates=';
                    $chips = array(
                        'all' => array('label' => 'All', 'count' => count($licenses)),
                        'allowed' => array('label' => 'Allowed', 'count' => $countAllowed),
                        'hold' => array('label' => 'On hold', 'count' => $countOnHold),
                    );
                    foreach ($chips as $key => $chip):
                    ?>
                        <a class="chip <?= $updatesFilter === $key ? 'chip-active' : '' ?>"
                           href="<?= $chipBase . $key ?>"><?= htmlspecialchars($chip['label']) ?>
                           <span class="chip-count"><?= (int) $chip['count'] ?></span></a>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="card-body">
                <?php if (!$updatesColumnReady): ?>
                    <div class="notice-warn" style="margin-bottom:1.5rem;">
                        These switches are read-only until the database has the new columns. Upload
                        <code>includes/migrations.php</code> and reload.
                    </div>
                <?php endif; ?>

                <?php if (empty($filteredLicenses)): ?>
                    <div class="empty-row">
                        <?= $updatesFilter === 'all' ? 'No licences yet.' : 'No licence matches this filter.' ?>
                        <?php if ($updatesFilter !== 'all'): ?>
                            <a href="licenses.php">Show all</a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>License Key</th>
                            <th>Domain</th>
                            <th>Email</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Updates</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($filteredLicenses as $license): ?>
                            <?php $updatesOn = !isset($license['updates_enabled']) || (int) $license['updates_enabled'] !== 0; ?>
                            <tr>
                                <td><span class="licence-key"><?= htmlspecialchars($license['license_key']) ?></span></td>
                                <td><?= htmlspecialchars($license['domain']) ?></td>
                                <td><?= htmlspecialchars($license['customer_email'] ?? '') ?></td>
                                <td><span class="status-badge <?= ($license['license_type'] ?? 'standard') === 'extended' ? 'status-active' : 'status-inactive' ?>"><?= htmlspecialchars(ucfirst($license['license_type'] ?? 'standard')) ?></span></td>
                                <td><?= htmlspecialchars($license['status']) ?></td>
                                <td class="updates-cell">
                                    <?php if ($updatesColumnReady): ?>
                                    <form method="POST" class="switch-form">
    <?= lm_csrf_field() ?>
                                        <input type="hidden" name="toggle_updates" value="1">
                                        <input type="hidden" name="id" value="<?= (int) $license['id'] ?>">
                                        <input type="hidden" name="current_updates" value="<?= $updatesOn ? 1 : 0 ?>">
                                        <input type="hidden" name="updates_block_note" value="<?= htmlspecialchars((string) ($license['updates_block_note'] ?? '')) ?>">
                                        <button type="submit"
                                                class="updates-switch <?= $updatesOn ? 'is-on' : 'is-off' ?>"
                                                aria-label="<?= $updatesOn ? 'Updates allowed for' : 'Updates on hold for' ?> <?= htmlspecialchars($license['license_key']) ?>. Click to <?= $updatesOn ? 'hold them' : 'allow them' ?>."
                                                title="<?= $updatesOn ? 'Only this licence: click to hold its updates' : 'Only this licence: click to allow its updates' ?>">
                                            <span class="switch-track"><span class="switch-knob"></span></span>
                                            <span class="switch-label"><?= $updatesOn ? 'Allowed' : 'On hold' ?></span>
                                        </button>
                                    </form>
                                    <div class="switch-hint"><?= $updatesOn ? 'click to hold this one' : 'click to allow this one' ?></div>
                                    <?php else: ?>
                                        <span class="status-badge <?= $updatesOn ? 'status-active' : 'status-onhold' ?>"><?= $updatesOn ? 'Allowed' : 'On hold' ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($license['updates_block_note'])): ?>
                                        <div class="tiny-note"><?= htmlspecialchars($license['updates_block_note']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="action-links">
                                    <a href="licenses.php?action=edit&id=<?= (int) $license['id'] ?>">Edit</a>
                                    <form method="POST" style="display:inline;">
    <?= lm_csrf_field() ?>
                                        <input type="hidden" name="toggle_status" value="1">
                                        <input type="hidden" name="id" value="<?= (int) $license['id'] ?>">
                                        <input type="hidden" name="current_status" value="<?= htmlspecialchars($license['status']) ?>">
                                        <button type="submit" class="link-button"><?= $license['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>

                <?php if (!empty($tierCounts) && $updatesColumnReady): ?>
                <details class="bulk-box">
                    <summary>Bulk action — every licence in one project</summary>
                    <p class="bulk-intro">
                        Only needed for the first step of a batch: hold a whole project before releasing a paid
                        update, then switch each vendor back on individually with the row switch above.
                    </p>
                    <form method="POST" class="hold-form"
                          onsubmit="return confirm('This changes update access for EVERY licence in the selected project, not one of them. Continue?');">
    <?= lm_csrf_field() ?>
                        <input type="hidden" name="bulk_updates" value="1">
                        <select name="tier_id" required>
                            <?php foreach ($tierCounts as $tc): ?>
                                <option value="<?= (int) $tc['id'] ?>">
                                    <?= htmlspecialchars($tc['tier_name']) ?>
                                    (<?= (int) $tc['licences'] ?> licence<?= (int) $tc['licences'] === 1 ? '' : 's' ?><?= (int) $tc['on_hold'] > 0 ? ', ' . (int) $tc['on_hold'] . ' on hold' : '' ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" name="bulk_mode" value="hold" class="btn btn-hold">Hold every licence</button>
                        <button type="submit" name="bulk_mode" value="allow" class="btn btn-allow">Allow every licence</button>
                    </form>
                </details>
                <?php endif; ?>
            </div>
        </div>

        <div class="card" style="margin-top: 2rem;">
            <div class="card-header">
                <h3>Pending License Requests</h3>
            </div>
            <div class="card-body">
                <table>
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Email</th>
                            <th>Domain</th>
                            <th>Submitted</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($requests)): ?>
                            <?php foreach ($requests as $r): ?>
                                <tr>
                                    <td><?= htmlspecialchars($r['request_ref']) ?></td>
                                    <td><?= htmlspecialchars($r['customer_email']) ?></td>
                                    <td><?= htmlspecialchars($r['domain']) ?></td>
                                    <td><?= htmlspecialchars($r['created_at']) ?></td>
                                    <td class="action-links">
                                        <form method="POST" action="approve_request.php" style="display:inline">
    <?= lm_csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                            <button type="submit" name="action" value="approve" class="link-button">Approve</button>
                                        </form>
                                        <form method="POST" action="approve_request.php" style="display:inline">
    <?= lm_csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                            <button type="submit" name="action" value="decline" class="link-button">Decline</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="empty-state">No pending requests.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <style>.input-group{margin-bottom:1rem} .link-button{background:none;border:none;color:#3b82f6;cursor:pointer;padding:0;font-size:1em;}</style>
    </div>
    <script>
        // Background email queue processing
        fetch('process_email_queue.php').catch(() => {});
    </script>
</body>
</html>

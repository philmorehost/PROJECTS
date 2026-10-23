<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit();
}

require_once('../db.php');

// Handle POST requests for license management
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_license'])) {
        $stmt = $pdo->prepare("INSERT INTO licenses (license_key, domain, customer_email, status, license_type) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$_POST['license_key'], $_POST['domain'], $_POST['customer_email'], $_POST['status'], $_POST['license_type'] ?? 'standard']);
    } elseif (isset($_POST['edit_license'])) {
        $stmt = $pdo->prepare("UPDATE licenses SET license_key = ?, domain = ?, customer_email = ?, status = ?, license_type = ? WHERE id = ?");
        $stmt->execute([$_POST['license_key'], $_POST['domain'], $_POST['customer_email'], $_POST['status'], $_POST['license_type'] ?? 'standard', $_POST['id']]);
    } elseif (isset($_POST['toggle_status'])) {
        $stmt = $pdo->prepare("UPDATE licenses SET status = ? WHERE id = ?");
        $new_status = $_POST['current_status'] === 'active' ? 'inactive' : 'active';
        $stmt->execute([$new_status, $_POST['id']]);
    }
    header('Location: licenses.php');
    exit();
}

// Fetch pending license requests for admin review
$reqStmt = $pdo->query("SELECT * FROM license_requests ORDER BY created_at DESC LIMIT 100");
$license_requests = $reqStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all licenses
$stmt = $pdo->query("SELECT * FROM licenses ORDER BY created_at DESC");
$licenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
    <div class="sidebar">
        <h1>🔐 License Manager</h1>
        <nav>
            <a href="dashboard.php">📊 Dashboard</a>
            <a href="licenses.php" class="active">📜 Licenses</a>
            <a href="updates.php">🚀 Push Updates</a>
            <a href="transactions.php">💳 Transactions</a>
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
                    <button type="submit" class="btn">Save Changes</button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <div class="card" style="margin-top: 2rem;">
            <div class="card-header">
                <h3>All Licenses</h3>
            </div>
            <div class="card-body">
                <table>
                    <thead>
                        <tr>
                            <th>License Key</th>
                            <th>Domain</th>
                            <th>Email</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($licenses as $license): ?>
                            <tr>
                                <td><?= htmlspecialchars($license['license_key']) ?></td>
                                <td><?= htmlspecialchars($license['domain']) ?></td>
                                <td><?= htmlspecialchars($license['customer_email'] ?? '') ?></td>
                                <td><span class="status-badge <?= ($license['license_type'] ?? 'standard') === 'extended' ? 'status-active' : 'status-inactive' ?>"><?= htmlspecialchars(ucfirst($license['license_type'] ?? 'standard')) ?></span></td>
                                <td><?= htmlspecialchars($license['status']) ?></td>
                                <td class="action-links">
                                    <a href="licenses.php?action=edit&id=<?= $license['id'] ?>">Edit</a>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="toggle_status" value="1">
                                        <input type="hidden" name="id" value="<?= $license['id'] ?>">
                                        <input type="hidden" name="current_status" value="<?= $license['status'] ?>">
                                        <button type="submit" class="link-button"><?= $license['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
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
                                            <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                            <button type="submit" name="action" value="approve" class="link-button">Approve</button>
                                        </form>
                                        <form method="POST" action="approve_request.php" style="display:inline">
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

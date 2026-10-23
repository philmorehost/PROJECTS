<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit();
}

require_once('../db.php');

// Fetch licenses from the database
$stmt = $pdo->query("SELECT * FROM licenses ORDER BY created_at DESC");
$licenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get stats
$total_licenses = count($licenses);
$active_licenses = count(array_filter($licenses, fn($l) => $l['status'] === 'active'));
$inactive_licenses = count(array_filter($licenses, fn($l) => $l['status'] === 'inactive'));

// Get transaction count
$stmt = $pdo->query("SELECT COUNT(*) as count FROM transactions");
$transaction_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - License Manager</title>
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
        .logout-btn {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: #fff;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            text-decoration: none;
            border: none;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(239, 68, 68, 0.3);
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            border-left: 4px solid #3b82f6;
            transition: all 0.3s ease;
        }
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
        }
        .stat-card h3 {
            font-size: 0.875rem;
            color: #64748b;
            font-weight: 500;
            margin-bottom: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .stat-card .value {
            font-size: 2.5rem;
            color: #1e293b;
            font-weight: 700;
        }
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        .card-header {
            padding: 1.5rem 2rem;
            border-bottom: 1px solid #e2e8f0;
            background: linear-gradient(135deg, #f8fafc 0%, #eef2ff 100%);
        }
        .card-header h3 {
            font-size: 1.25rem;
            color: #1e293b;
            margin: 0;
            font-weight: 600;
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
            font-weight: 600;
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
        .status-badge {
            display: inline-block;
            padding: 0.4rem 0.8rem;
            border-radius: 6px;
            font-size: 0.875rem;
            font-weight: 500;
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
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <h1>🔐 License Manager</h1>
        <nav>
            <a href="dashboard.php" class="active">📊 Dashboard</a>
            <a href="licenses.php">📜 Licenses</a>
            <a href="updates.php">🚀 Push Updates</a>
            <a href="transactions.php">💳 Transactions</a>
            <a href="settings.php">⚙️ Settings</a>
            <a href="profile.php">👤 Profile</a>
            <hr style="border: none; border-top: 1px solid rgba(255,255,255,0.2); margin: 0.5rem 0;">
            <a href="system-check.php" style="font-size: 0.875rem; opacity: 0.8;">✓ System Check</a>
            <a href="database-repair.php" style="font-size: 0.875rem; opacity: 0.8;">🔧 Database Repair</a>
            <a href="payment-debug.php" style="font-size: 0.875rem; opacity: 0.8;">🐛 Debug Logs</a>
            <hr style="border: none; border-top: 1px solid rgba(255,255,255,0.2); margin: 0.5rem 0;">
            <a href="logout.php">🚪 Logout</a>
        </nav>
    </div>
    <div class="main-content">
        <div class="header">
            <h2>Dashboard</h2>
            <a href="logout.php" class="logout-btn">Logout</a>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <h3>Total Licenses</h3>
                <div class="value"><?= $total_licenses ?></div>
            </div>
            <div class="stat-card" style="border-left-color: #10b981;">
                <h3>Active Licenses</h3>
                <div class="value" style="color: #10b981;"><?= $active_licenses ?></div>
            </div>
            <div class="stat-card" style="border-left-color: #f59e0b;">
                <h3>Inactive Licenses</h3>
                <div class="value" style="color: #f59e0b;"><?= $inactive_licenses ?></div>
            </div>
            <div class="stat-card" style="border-left-color: #8b5cf6;">
                <h3>Transactions</h3>
                <div class="value" style="color: #8b5cf6;"><?= $transaction_count ?></div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3>📋 Recent Licenses</h3>
            </div>
            <div class="card-body">
                <table>
                    <thead>
                        <tr>
                            <th>License Key</th>
                            <th>Domain</th>
                            <th>Email</th>
                            <th>Status</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($licenses, 0, 10) as $license): ?>
                            <tr>
                                <td><code style="background: #f1f5f9; padding: 0.25rem 0.5rem; border-radius: 4px;"><?= htmlspecialchars(substr($license['license_key'], 0, 16)) ?>...</code></td>
                                <td><?= htmlspecialchars($license['domain']) ?></td>
                                <td><?= htmlspecialchars($license['customer_email'] ?? 'N/A') ?></td>
                                <td>
                                    <span class="status-badge <?= $license['status'] === 'active' ? 'status-active' : 'status-inactive' ?>">
                                        <?= ucfirst($license['status']) ?>
                                    </span>
                                </td>
                                <td><?= date('M d, Y', strtotime($license['created_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>

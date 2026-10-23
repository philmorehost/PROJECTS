<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit();
}

require_once('../db.php');

try {
    $stmt = $pdo->query(
        "SELECT t.id, t.created_at, t.amount, t.currency, t.transaction_ref, " .
        "t.auto_login_token, t.token_created_at FROM transactions t ORDER BY t.created_at DESC"
    );
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Handle database error
    $error = "Database error: " . $e->getMessage();
    $transactions = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaction History - License Manager</title>
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
        .error {
            color: #991b1b;
            background-color: #fee2e2;
            border: 1px solid #fca5a5;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }
        .empty-state {
            text-align: center;
            padding: 2rem;
            color: #64748b;
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
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <h1>🔐 License Manager</h1>
        <nav>
            <a href="dashboard.php">📊 Dashboard</a>
            <a href="licenses.php">📜 Licenses</a>
            <a href="updates.php">🚀 Push Updates</a>
            <a href="transactions.php" class="active">💳 Transactions</a>
            <a href="settings.php">⚙️ Settings</a>
            <a href="profile.php">👤 Profile</a>
            <a href="logout.php">🚪 Logout</a>
        </nav>
    </div>
    <div class="main-content">
        <div class="header">
            <h2>💳 Transaction History</h2>
        </div>
        <div class="card">
            <div class="card-header">
                <h3>All Transactions</h3>
            </div>
            <div class="card-body">
                <?php if (!empty($error)): ?>
                    <div class="error">✗ <?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Amount</th>
                            <th>Reference</th>
                            <th>Token</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($transactions)): ?>
                            <?php foreach ($transactions as $tx): ?>
                                <tr>
                                    <td><?= htmlspecialchars(date('M d, Y H:i', strtotime($tx['created_at']))) ?></td>
                                    <td><strong><?= htmlspecialchars(number_format($tx['amount'], 2)) ?> <?= htmlspecialchars($tx['currency']) ?></strong></td>
                                    <td><?= htmlspecialchars($tx['transaction_ref']) ?></td>
                                                <td>
                                                    <?php if (!empty($tx['auto_login_token'])): ?>
                                                        <?php $masked = substr($tx['auto_login_token'], 0, 6) . '...' . substr($tx['auto_login_token'], -4); ?>
                                                        <div style="font-family: monospace;"><?= htmlspecialchars($masked) ?></div>
                                                        <div style="font-size: 0.85rem; color: #64748b;"><?= htmlspecialchars($tx['token_created_at']) ?></div>
                                                    <?php else: ?>
                                                        <span style="color: #94a3b8;">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($tx['auto_login_token'])): ?>
                                                        <form method="POST" action="invalidate_token.php" style="display:inline">
                                                            <input type="hidden" name="id" value="<?= htmlspecialchars($tx['id']) ?>">
                                                            <button type="submit" style="background:#ef4444; color:#fff; border:none; padding:6px 10px; border-radius:6px; cursor:pointer;">Revoke</button>
                                                        </form>
                                                        <a href="../user/auto_login.php?token=<?= rawurlencode($tx['auto_login_token']) ?>" target="_blank" style="margin-left:8px; font-size:0.85rem; color:#2563eb;">Test link</a>
                                                    <?php else: ?>
                                                        <span style="color: #94a3b8;">—</span>
                                                    <?php endif; ?>
                                                </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="3" class="empty-state">📭 No transactions found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>

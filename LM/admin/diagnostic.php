<?php
session_start();

// Check if user is admin
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit;
}

require_once('../db.php');

$log_content = '';
$webhook_log = '';
$error_log = '';
$success_log = '';
$transactions = [];
$licenses = [];

// Read logs
if (file_exists('../webhook.log')) {
    $webhook_log = file_get_contents('../webhook.log');
}
if (file_exists('../error.log')) {
    $error_log = file_get_contents('../error.log');
}
if (file_exists('../success.log')) {
    $success_log = file_get_contents('../success.log');
}

// Get recent transactions
try {
    $stmt = $pdo->query("SELECT * FROM transactions ORDER BY id DESC LIMIT 10");
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_log .= "Error fetching transactions: " . $e->getMessage();
}

// Get recent licenses
try {
    $stmt = $pdo->query("SELECT * FROM licenses ORDER BY id DESC LIMIT 10");
    $licenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_log .= "Error fetching licenses: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Diagnostic - License Manager</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 2rem;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        h1 {
            color: white;
            margin-bottom: 2rem;
            font-size: 2.5rem;
        }
        .diagnostic-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }
        .diagnostic-section {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }
        .diagnostic-section h2 {
            font-size: 1.5rem;
            color: #1e293b;
            margin-bottom: 1rem;
            border-bottom: 2px solid #667eea;
            padding-bottom: 0.5rem;
        }
        .log-content {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 1rem;
            max-height: 300px;
            overflow-y: auto;
            font-family: 'Courier New', monospace;
            font-size: 0.875rem;
            color: #1e293b;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        .log-content:empty::after {
            content: '(No logs yet)';
            color: #94a3b8;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }
        th {
            background: #eef2ff;
            color: #3b82f6;
            padding: 0.75rem;
            text-align: left;
            border-bottom: 2px solid #bfdbfe;
            font-weight: 600;
        }
        td {
            padding: 0.75rem;
            border-bottom: 1px solid #e2e8f0;
        }
        tr:hover {
            background: #f9fafb;
        }
        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
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
        .status-success {
            background: #d1fae5;
            color: #065f46;
        }
        .back-link {
            display: inline-block;
            margin-bottom: 2rem;
            padding: 0.75rem 1.5rem;
            background: white;
            color: #667eea;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        .back-link:hover {
            background: #f1f5f9;
            transform: translateY(-2px);
        }
        .full-width {
            grid-column: 1 / -1;
        }
        .null-value {
            color: #ef4444;
            font-weight: 600;
        }
        @media (max-width: 1024px) {
            .diagnostic-grid {
                grid-template-columns: 1fr;
            }
            .full-width {
                grid-column: auto;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="dashboard.php" class="back-link">← Back to Dashboard</a>
        <h1>🔍 Diagnostic Center</h1>

        <div class="diagnostic-grid">
            <div class="diagnostic-section">
                <h2>Webhook Logs</h2>
                <div class="log-content"><?= htmlspecialchars($webhook_log) ?></div>
            </div>

            <div class="diagnostic-section">
                <h2>Success Page Logs</h2>
                <div class="log-content"><?= htmlspecialchars($success_log) ?></div>
            </div>

            <div class="diagnostic-section">
                <h2>Error Logs</h2>
                <div class="log-content"><?= htmlspecialchars($error_log) ?></div>
            </div>

            <div class="diagnostic-section">
                <h2>System Info</h2>
                <div class="log-content">PHP Version: <?= phpversion() ?>
Database: MySQL
Files Checked:
- webhook.log (<?= file_exists('../webhook.log') ? 'EXISTS' : 'MISSING' ?>)
- error.log (<?= file_exists('../error.log') ? 'EXISTS' : 'MISSING' ?>)
- success.log (<?= file_exists('../success.log') ? 'EXISTS' : 'MISSING' ?>)</div>
            </div>

            <div class="diagnostic-section full-width">
                <h2>Recent Licenses (Last 10)</h2>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>License Key</th>
                            <th>Domain</th>
                            <th>Customer Email</th>
                            <th>Status</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($licenses)): ?>
                            <?php foreach ($licenses as $lic): ?>
                                <tr>
                                    <td><?= htmlspecialchars($lic['id']) ?></td>
                                    <td><code><?= htmlspecialchars(substr($lic['license_key'], 0, 20) . '...') ?></code></td>
                                    <td><?= htmlspecialchars($lic['domain']) ?></td>
                                    <td><?= htmlspecialchars($lic['customer_email'] ?? '') ?></td>
                                    <td><span class="status-badge status-<?= htmlspecialchars($lic['status']) ?>"><?= htmlspecialchars($lic['status']) ?></span></td>
                                    <td><?= htmlspecialchars($lic['created_at']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" style="text-align: center; color: #94a3b8;">No licenses found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="diagnostic-section full-width">
                <h2>Recent Transactions (Last 10)</h2>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>License ID</th>
                            <th>Transaction Ref</th>
                            <th>Amount</th>
                            <th>Currency</th>
                            <th>Status</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($transactions)): ?>
                            <?php foreach ($transactions as $tx): ?>
                                <tr>
                                    <td><?= htmlspecialchars($tx['id']) ?></td>
                                    <td><?php if (empty($tx['license_id'])): ?><span class="null-value">NULL</span><?php else: ?><?= htmlspecialchars($tx['license_id']) ?><?php endif; ?></td>
                                    <td><code><?= htmlspecialchars($tx['transaction_ref']) ?></code></td>
                                    <td><?= htmlspecialchars($tx['amount']) ?></td>
                                    <td><?= htmlspecialchars($tx['currency']) ?></td>
                                    <td><span class="status-badge status-<?= htmlspecialchars($tx['status']) ?>"><?= htmlspecialchars($tx['status']) ?></span></td>
                                    <td><?= htmlspecialchars($tx['created_at']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="7" style="text-align: center; color: #94a3b8;">No transactions found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>

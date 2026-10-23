<?php
session_start();

// Check if user is admin
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit;
}

require_once('../db.php');

// Read PHP error log
$error_logs = '';
$latest_errors = '';

// Try to get PHP error log
$error_log_path = ini_get('error_log');
if ($error_log_path && file_exists($error_log_path)) {
    $all_errors = file_get_contents($error_log_path);
    $lines = explode("\n", $all_errors);
    $latest_errors = implode("\n", array_slice($lines, -50)); // Last 50 lines
}

// Also check for our custom logs
$webhook_log = '';
$success_log = '';
$payment_log = '';

if (file_exists('../webhook.log')) {
    $all_webhook = file_get_contents('../webhook.log');
    $lines = explode("\n", $all_webhook);
    $webhook_log = implode("\n", array_slice($lines, -30)); // Last 30 lines
}

if (file_exists('../success.log')) {
    $all_success = file_get_contents('../success.log');
    $lines = explode("\n", $all_success);
    $success_log = implode("\n", array_slice($lines, -20)); // Last 20 lines
}

// Check recent transactions
$transactions = [];
$licenses = [];

try {
    $stmt = $pdo->query("SELECT * FROM transactions ORDER BY id DESC LIMIT 5");
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->query("SELECT * FROM licenses ORDER BY id DESC LIMIT 5");
    $licenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error_logs .= "Database error: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Debug - License Manager</title>
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
        h1 {
            color: white;
            margin-bottom: 2rem;
            font-size: 2.5rem;
        }
        .section {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }
        .section h2 {
            font-size: 1.5rem;
            color: #1e293b;
            margin-bottom: 1rem;
            border-bottom: 2px solid #667eea;
            padding-bottom: 0.5rem;
        }
        .log-content {
            background: #1e293b;
            color: #e2e8f0;
            padding: 1.5rem;
            border-radius: 8px;
            overflow-x: auto;
            font-family: 'Courier New', monospace;
            font-size: 0.875rem;
            max-height: 400px;
            overflow-y: auto;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        .log-content:empty::after {
            content: '(No logs found)';
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
        .status {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 500;
        }
        .status-success {
            background: #d1fae5;
            color: #065f46;
        }
        .status-error {
            background: #fee2e2;
            color: #991b1b;
        }
        .info-box {
            background: #eff6ff;
            border: 1px solid #93c5fd;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            color: #1e40af;
        }
        .code {
            background: #f3f4f6;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-family: monospace;
            font-size: 0.875rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="dashboard.php" class="back-link">← Back to Dashboard</a>
        <h1>🐛 Payment Debug Center</h1>

        <div class="section">
            <h2>🆘 Quick Diagnostics</h2>
            <div class="info-box">
                <strong>If you see "Error processing payment":</strong>
                <ol style="margin-top: 0.5rem; padding-left: 1.5rem;">
                    <li>Check <strong>PHP Error Logs</strong> below for cURL or API errors</li>
                    <li>Verify your <strong>Paystack Secret Key</strong> is correct in Settings</li>
                    <li>Make sure <strong>cURL</strong> is enabled on your server</li>
                    <li>Check <strong>Recent Transactions</strong> to see if transaction was created</li>
                </ol>
            </div>
        </div>

        <div class="section">
            <h2>⚙️ PHP Error Logs (Last 50 lines)</h2>
            <p style="margin-bottom: 1rem; color: #666;">
                These logs from your PHP server may contain cURL errors or API issues:
            </p>
            <div class="log-content"><?= htmlspecialchars($latest_errors) ?></div>
        </div>

        <div class="section">
            <h2>🎫 Recent Transactions</h2>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Reference</th>
                        <th>License ID</th>
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
                                <td><code class="code"><?= htmlspecialchars($tx['transaction_ref']) ?></code></td>
                                <td><?= $tx['license_id'] ?? '—' ?></td>
                                <td><?= htmlspecialchars($tx['amount']) ?></td>
                                <td><?= htmlspecialchars($tx['currency']) ?></td>
                                <td><span class="status status-<?= $tx['status'] === 'success' ? 'success' : 'error' ?>"><?= htmlspecialchars($tx['status']) ?></span></td>
                                <td><?= htmlspecialchars($tx['created_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="7" style="text-align: center; color: #94a3b8;">No transactions found</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="section">
            <h2>🔐 Recent Licenses</h2>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>License Key</th>
                        <th>Domain</th>
                        <th>Email</th>
                        <th>Status</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($licenses)): ?>
                        <?php foreach ($licenses as $lic): ?>
                            <tr>
                                <td><?= htmlspecialchars($lic['id']) ?></td>
                                <td><code class="code"><?= htmlspecialchars(substr($lic['license_key'], 0, 15) . '...') ?></code></td>
                                <td><?= htmlspecialchars($lic['domain']) ?></td>
                                <td><?= htmlspecialchars($lic['customer_email'] ?? '') ?></td>
                                <td><span class="status status-<?= $lic['status'] === 'active' ? 'success' : 'error' ?>"><?= htmlspecialchars($lic['status']) ?></span></td>
                                <td><?= htmlspecialchars($lic['created_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6" style="text-align: center; color: #94a3b8;">No licenses found</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="section">
            <h2>📋 Success Page Logs (Last 20 lines)</h2>
            <div class="log-content"><?= htmlspecialchars($success_log) ?></div>
        </div>

        <div class="section">
            <h2>🪝 Webhook Logs (Last 30 lines)</h2>
            <div class="log-content"><?= htmlspecialchars($webhook_log) ?></div>
        </div>

        <div class="section">
            <h2>📝 Troubleshooting Steps</h2>
            <div style="background: #f9fafb; padding: 1.5rem; border-radius: 8px;">
                <h3 style="margin-bottom: 1rem;">If payment fails:</h3>
                <ol style="padding-left: 1.5rem; line-height: 1.8;">
                    <li>Go to <strong>Admin Settings</strong> and verify:
                        <ul style="margin-top: 0.5rem; padding-left: 1.5rem;">
                            <li>Paystack Secret Key is set and correct</li>
                            <li>SMTP credentials are configured</li>
                        </ul>
                    </li>
                    <li>Check the <strong>PHP Error Logs</strong> above for cURL errors</li>
                    <li>If cURL is disabled, contact your hosting provider to enable it</li>
                    <li>Test a webhook using <a href="webhook-tester.php" style="color: #3b82f6; font-weight: 500;">Webhook Tester</a></li>
                    <li>Monitor this page in real-time during a test payment</li>
                </ol>
            </div>
        </div>
    </div>
</body>
</html>

<?php
session_start();

// Check if user is admin
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit;
}

require_once('../db.php');

$diagnostics = [
    'curl_enabled' => extension_loaded('curl'),
    'paystack_configured' => false,
    'paystack_reachable' => false,
    'settings_file' => file_exists('../settings.json'),
    'smtp_configured' => false,
    'licenses_table_ok' => false,
    'transactions_table_ok' => false,
];

$settings = [];
if (file_exists('../settings.json')) {
    $settings = json_decode(file_get_contents('../settings.json'), true);
    $diagnostics['paystack_configured'] = !empty($settings['paystack_secret_key']);
    $diagnostics['smtp_configured'] = !empty($settings['smtp_host']) && !empty($settings['smtp_user']);
}

// Check database tables
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM `licenses` WHERE Field IN ('customer_email', 'license_key', 'domain')");
    $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $diagnostics['licenses_table_ok'] = count($cols) >= 3;
    
    $stmt = $pdo->query("SHOW COLUMNS FROM `transactions` WHERE Field IN ('license_id', 'transaction_ref', 'status')");
    $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $diagnostics['transactions_table_ok'] = count($cols) >= 3;
} catch (Exception $e) {
    // Database error
}

// Test Paystack connectivity if curl is available
if ($diagnostics['curl_enabled'] && $diagnostics['paystack_configured']) {
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => "https://api.paystack.co/transaction/verify/test",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => array(
            "Authorization: Bearer " . $settings['paystack_secret_key'],
        ),
    ));
    
    $response = curl_exec($curl);
    $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    
    // Any response from Paystack API means it's reachable
    $diagnostics['paystack_reachable'] = ($http_code > 0);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Check - License Manager</title>
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
            max-width: 700px;
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
        .card {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }
        .check-item {
            display: flex;
            align-items: center;
            padding: 1rem;
            border-bottom: 1px solid #e5e7eb;
            gap: 1rem;
        }
        .check-item:last-child {
            border-bottom: none;
        }
        .check-icon {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 14px;
            flex-shrink: 0;
        }
        .check-icon.pass {
            background: #10b981;
        }
        .check-icon.fail {
            background: #ef4444;
        }
        .check-label {
            flex-grow: 1;
        }
        .check-label strong {
            display: block;
            color: #1e293b;
            margin-bottom: 0.25rem;
        }
        .check-label small {
            color: #666;
            font-size: 0.875rem;
        }
        .next-step {
            background: #eff6ff;
            border: 1px solid #93c5fd;
            border-radius: 8px;
            padding: 1.5rem;
            margin-top: 1.5rem;
            color: #1e40af;
        }
        .next-step h3 {
            margin-bottom: 0.75rem;
            color: #1e40af;
        }
        .next-step ol {
            padding-left: 1.5rem;
        }
        .next-step li {
            margin-bottom: 0.5rem;
            line-height: 1.6;
        }
        .action-link {
            display: inline-block;
            margin-top: 1rem;
            padding: 0.75rem 1.5rem;
            background: #3b82f6;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        .action-link:hover {
            background: #2563eb;
            transform: translateY(-2px);
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="dashboard.php" class="back-link">← Back to Dashboard</a>
        <h1>✓ System Check</h1>

        <div class="card">
            <h2 style="font-size: 1.5rem; color: #1e293b; margin-bottom: 1rem;">Payment System Status</h2>

            <div class="check-item">
                <div class="check-icon <?= $diagnostics['curl_enabled'] ? 'pass' : 'fail' ?>">
                    <?= $diagnostics['curl_enabled'] ? '✓' : '✗' ?>
                </div>
                <div class="check-label">
                    <strong>cURL Extension</strong>
                    <small><?= $diagnostics['curl_enabled'] ? 'Enabled and ready' : 'NOT INSTALLED - Contact hosting provider' ?></small>
                </div>
            </div>

            <div class="check-item">
                <div class="check-icon <?= $diagnostics['settings_file'] ? 'pass' : 'fail' ?>">
                    <?= $diagnostics['settings_file'] ? '✓' : '✗' ?>
                </div>
                <div class="check-label">
                    <strong>Settings File</strong>
                    <small><?= $diagnostics['settings_file'] ? 'Found and readable' : 'Missing - Run setup' ?></small>
                </div>
            </div>

            <div class="check-item">
                <div class="check-icon <?= $diagnostics['paystack_configured'] ? 'pass' : 'fail' ?>">
                    <?= $diagnostics['paystack_configured'] ? '✓' : '✗' ?>
                </div>
                <div class="check-label">
                    <strong>Paystack Secret Key</strong>
                    <small><?= $diagnostics['paystack_configured'] ? 'Configured' : 'NOT SET - Update in Settings' ?></small>
                </div>
            </div>

            <div class="check-item">
                <div class="check-icon <?= $diagnostics['paystack_reachable'] ? 'pass' : 'fail' ?>">
                    <?= $diagnostics['paystack_reachable'] ? '✓' : '✗' ?>
                </div>
                <div class="check-label">
                    <strong>Paystack API Reachable</strong>
                    <small><?= $diagnostics['paystack_reachable'] ? 'Successfully connected' : 'Connection failed - Check secret key' ?></small>
                </div>
            </div>

            <div class="check-item">
                <div class="check-icon <?= $diagnostics['smtp_configured'] ? 'pass' : 'fail' ?>">
                    <?= $diagnostics['smtp_configured'] ? '✓' : '✗' ?>
                </div>
                <div class="check-label">
                    <strong>SMTP Email</strong>
                    <small><?= $diagnostics['smtp_configured'] ? 'Configured' : 'NOT SET - Customers won\'t receive emails' ?></small>
                </div>
            </div>

            <div class="check-item">
                <div class="check-icon <?= $diagnostics['licenses_table_ok'] ? 'pass' : 'fail' ?>">
                    <?= $diagnostics['licenses_table_ok'] ? '✓' : '✗' ?>
                </div>
                <div class="check-label">
                    <strong>Licenses Database</strong>
                    <small><?= $diagnostics['licenses_table_ok'] ? 'Schema is correct' : 'Schema is incomplete - Run repair' ?></small>
                </div>
            </div>

            <div class="check-item">
                <div class="check-icon <?= $diagnostics['transactions_table_ok'] ? 'pass' : 'fail' ?>">
                    <?= $diagnostics['transactions_table_ok'] ? '✓' : '✗' ?>
                </div>
                <div class="check-label">
                    <strong>Transactions Database</strong>
                    <small><?= $diagnostics['transactions_table_ok'] ? 'Schema is correct' : 'Schema is incomplete - Run repair' ?></small>
                </div>
            </div>

            <?php if ($diagnostics['curl_enabled'] && $diagnostics['paystack_configured'] && $diagnostics['paystack_reachable'] && $diagnostics['smtp_configured'] && $diagnostics['licenses_table_ok'] && $diagnostics['transactions_table_ok']): ?>
                <div class="next-step" style="background: #d1fae5; border-color: #a7f3d0; color: #065f46;">
                    <h3>✓ All Systems Ready!</h3>
                    <p>Your payment system is fully configured. You're ready to process payments.</p>
                    <a href="../order.php" class="action-link" style="background: #10b981;">Try Test Payment</a>
                </div>
            <?php else: ?>
                <div class="next-step">
                    <h3>⚠️ Issues Found</h3>
                    <ol>
                        <?php if (!$diagnostics['curl_enabled']): ?>
                            <li><strong>Enable cURL:</strong> Contact your hosting provider - cURL is required for Paystack API integration</li>
                        <?php endif; ?>
                        <?php if (!$diagnostics['paystack_configured']): ?>
                            <li><strong>Add Paystack Secret Key:</strong> Go to <a href="settings.php" style="color: #3b82f6; font-weight: 500;">Settings</a> and paste your Paystack Secret Key</li>
                        <?php endif; ?>
                        <?php if ($diagnostics['paystack_configured'] && !$diagnostics['paystack_reachable']): ?>
                            <li><strong>Check Secret Key:</strong> Your Paystack Secret Key appears to be invalid. Verify it in <a href="settings.php" style="color: #3b82f6; font-weight: 500;">Settings</a></li>
                        <?php endif; ?>
                        <?php if (!$diagnostics['smtp_configured']): ?>
                            <li><strong>Configure SMTP:</strong> Go to <a href="settings.php" style="color: #3b82f6; font-weight: 500;">Settings</a> and add your SMTP details so customers receive license emails</li>
                        <?php endif; ?>
                        <?php if (!$diagnostics['licenses_table_ok'] || !$diagnostics['transactions_table_ok']): ?>
                            <li><strong>Database Schema Issue:</strong> <a href="database-repair.php" style="color: #3b82f6; font-weight: 500;">Run Database Repair</a> to fix missing columns</li>
                        <?php endif; ?>
                    </ol>
                    <a href="settings.php" class="action-link">Go to Settings</a>
                    <?php if (!$diagnostics['licenses_table_ok'] || !$diagnostics['transactions_table_ok']): ?>
                        <a href="database-repair.php" class="action-link" style="margin-left: 0.5rem;">Run Database Repair</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="next-step" style="background: #fef3c7; border-color: #fcd34d; color: #92400e; margin-top: 1.5rem;">
                <h3>📊 Debug Information</h3>
                <p>If you still have issues after checking all items above, use these pages:</p>
                <ul style="margin-top: 0.75rem; padding-left: 1.5rem;">
                    <li><a href="payment-debug.php" style="color: #b45309; font-weight: 500;">View Payment Debug Logs</a> - See error messages</li>
                    <li><a href="diagnostic.php" style="color: #b45309; font-weight: 500;">View System Diagnostic</a> - Database and transaction info</li>
                </ul>
            </div>
        </div>
    </div>
</body>
</html>

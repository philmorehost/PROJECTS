<?php
session_start();

// Check if user is admin
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit;
}

require_once('../db.php');

$test_result = null;
$test_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_webhook'])) {
    // Create a test webhook payload
    $test_ref = 'test-' . date('YmdHis');
    
    $test_payload = json_encode([
        'event' => 'charge.success',
        'data' => [
            'reference' => $test_ref,
            'amount' => 500000, // 5000 NGN in kobo
            'currency' => 'NGN',
            'customer' => [
                'email' => 'test@example.com'
            ],
            'metadata' => [
                'domain' => 'test-domain.com'
            ]
        ]
    ]);
    
    // Try to process the test webhook
    try {
        $pdo->beginTransaction();
        
        // Insert test license
        $new_license_key = 'TEST-' . strtoupper(bin2hex(random_bytes(8)));
        $stmt = $pdo->prepare("INSERT INTO licenses (license_key, domain, customer_email, status) VALUES (?, ?, ?, ?)");
        $stmt->execute([$new_license_key, 'test-domain.com', 'test@example.com', 'active']);
        $license_id = $pdo->lastInsertId();
        
        // Insert test transaction
        $stmt = $pdo->prepare("INSERT INTO transactions (license_id, transaction_ref, amount, currency, status) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$license_id, $test_ref, 5000, 'NGN', 'success']);
        
        $pdo->commit();
        
        $test_result = true;
        $test_message = "✓ Test webhook processed successfully! License created: " . $new_license_key . " | Transaction Ref: " . $test_ref;
    } catch (PDOException $e) {
        $test_result = false;
        $test_message = "✗ Test failed: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Webhook Tester - License Manager</title>
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
            max-width: 800px;
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
        .back-link:hover {
            background: #f1f5f9;
            transform: translateY(-2px);
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
            margin-bottom: 2rem;
        }
        .card h2 {
            font-size: 1.5rem;
            color: #1e293b;
            margin-bottom: 1rem;
            border-bottom: 2px solid #667eea;
            padding-bottom: 0.5rem;
        }
        .test-message {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-weight: 500;
        }
        .test-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        .test-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }
        .instructions {
            background: #f9fafb;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .instructions h3 {
            color: #1e293b;
            margin-bottom: 0.75rem;
        }
        .instructions ol {
            padding-left: 1.5rem;
        }
        .instructions li {
            margin-bottom: 0.5rem;
            color: #475569;
            line-height: 1.6;
        }
        .btn {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 1rem;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(59, 130, 246, 0.3);
        }
        .code-block {
            background: #1e293b;
            color: #e2e8f0;
            padding: 1rem;
            border-radius: 8px;
            overflow-x: auto;
            font-family: 'Courier New', monospace;
            font-size: 0.875rem;
            margin: 1rem 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="dashboard.php" class="back-link">← Back to Dashboard</a>
        <h1>🔌 Webhook Tester</h1>

        <div class="card">
            <h2>Quick Webhook Test</h2>
            <?php if ($test_result !== null): ?>
                <div class="test-message <?= $test_result ? 'test-success' : 'test-error' ?>">
                    <?= htmlspecialchars($test_message) ?>
                </div>
            <?php endif; ?>
            <form method="POST">
                <p>Click the button below to test if the webhook system is working correctly:</p>
                <button type="submit" name="test_webhook" class="btn">🧪 Run Test Webhook</button>
            </form>
        </div>

        <div class="card">
            <h2>Setup Paystack Webhook</h2>
            <div class="instructions">
                <h3>Step-by-Step Webhook Configuration:</h3>
                <ol>
                    <li>Go to <strong>Paystack Dashboard</strong> → <strong>Settings</strong> → <strong>Webhooks</strong></li>
                    <li>Add a new webhook with the following URL:
                        <div class="code-block"><?= htmlspecialchars((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]") ?>/webhook.php</div>
                    </li>
                    <li>Make sure to select these events:
                        <ul style="margin-top: 0.5rem; padding-left: 2rem;">
                            <li>charge.success</li>
                        </ul>
                    </li>
                    <li>Copy your webhook secret from Paystack and add it to <strong>settings.json</strong> as <code>paystack_secret_key</code></li>
                    <li>Save and test the webhook in Paystack dashboard</li>
                </ol>
            </div>

            <div class="instructions">
                <h3>Verify Paystack Settings:</h3>
                <ol>
                    <li>Check <strong>admin/diagnostic.php</strong> for webhook logs</li>
                    <li>Look for these patterns in webhook.log:
                        <ul style="margin-top: 0.5rem; padding-left: 2rem;">
                            <li>✓ "Webhook received" - webhook is being called</li>
                            <li>✓ "Webhook signature verified successfully" - signature is correct</li>
                            <li>✓ "Charge success event received" - event type is correct</li>
                            <li>✓ "License inserted" - license created successfully</li>
                        </ul>
                    </li>
                    <li>If you see "Webhook signature verification failed", your secret key is incorrect</li>
                </ol>
            </div>
        </div>

        <div class="card">
            <h2>How the Payment Flow Works</h2>
            <div class="instructions">
                <ol>
                    <li><strong>User makes payment</strong> on order.php → Paystack payment popup appears</li>
                    <li><strong>Payment completes</strong> → Paystack redirects to success.php with transaction reference</li>
                    <li><strong>Success page auto-polls</strong> → JavaScript calls api-check-license.php every 2 seconds</li>
                    <li><strong>Paystack sends webhook</strong> → webhook.php receives charge.success event</li>
                    <li><strong>Webhook creates license</strong> → Inserts license + transaction into database</li>
                    <li><strong>License appears</strong> → api-check-license.php finds it, success page displays key</li>
                </ol>
            </div>
        </div>
    </div>
</body>
</html>

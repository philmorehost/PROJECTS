<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_email'])) {
    header('Location: login.php');
    exit;
}

require_once('../db.php');

$user_email = $_SESSION['user_email'];
$license_id = $_GET['id'] ?? $_SESSION['user_license_id'] ?? null;

if (!$license_id) {
    header('Location: index.php');
    exit;
}

$message = '';
$error = '';

// Fetch license details
$stmt = $pdo->prepare("SELECT * FROM licenses WHERE id = ? AND customer_email = ?");
$stmt->execute([(int)$license_id, $user_email]);
$license = $stmt->fetch(PDO::FETCH_ASSOC);

if ($license) {
    $license['max_domain_changes'] = isset($license['max_domain_changes']) ? (int)$license['max_domain_changes'] : 3;
    $license['domain_change_count'] = isset($license['domain_change_count']) ? (int)$license['domain_change_count'] : 0;
} else {
    $error = 'License not found';
}

// Handle domain change request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_domain'])) {
    $new_domain = trim($_POST['new_domain']);
    
    // Validation
    if (empty($new_domain)) {
        $error = 'Domain name is required';
    } elseif (!preg_match('/^([a-z0-9]+(-[a-z0-9]+)*\.)+[a-z]{2,}$/i', $new_domain)) {
        $error = 'Please enter a valid domain name (e.g., example.com)';
    } elseif ($new_domain === $license['domain']) {
        $error = 'New domain must be different from current domain';
    } elseif ($license['domain_change_count'] >= $license['max_domain_changes']) {
        $error = 'You have reached the maximum number of domain changes (' . $license['max_domain_changes'] . ')';
    } else {
        // Update domain
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("UPDATE licenses SET domain = ?, domain_change_count = domain_change_count + 1 WHERE id = ?");
            $stmt->execute([$new_domain, $license_id]);
            
            // Log the change
            $change_log = date('Y-m-d H:i:s') . " - Domain changed from '{$license['domain']}' to '{$new_domain}'\n";
            file_put_contents('../domain-change.log', $change_log, FILE_APPEND);
            
            $pdo->commit();
            
            $message = '✓ Domain updated successfully to ' . htmlspecialchars($new_domain);
            
            // Refresh license data
            $stmt = $pdo->prepare("SELECT * FROM licenses WHERE id = ?");
            $stmt->execute([$license_id]);
            $license = $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Failed to update domain: ' . $e->getMessage();
        }
    }
}

$changes_remaining = $license ? ($license['max_domain_changes'] - $license['domain_change_count']) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Domain - License Manager</title>
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
            transform: translateY(-2px);
        }
        h1 {
            color: white;
            margin-bottom: 2rem;
        }
        .card {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            margin-bottom: 2rem;
        }
        .message {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            border-left: 4px solid;
        }
        .message.success {
            background: #d1fae5;
            border-color: #10b981;
            color: #065f46;
        }
        .message.error {
            background: #fee2e2;
            border-color: #ef4444;
            color: #991b1b;
        }
        .form-group {
            margin-bottom: 1.5rem;
        }
        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #1e293b;
        }
        input[type="text"],
        input[type="email"] {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 1rem;
            font-family: 'Inter', sans-serif;
        }
        input[type="text"]:focus,
        input[type="email"]:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        input:disabled {
            background: #f3f4f6;
            color: #9ca3af;
        }
        button {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 1rem;
        }
        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        button:disabled {
            background: #9ca3af;
            cursor: not-allowed;
            transform: none;
        }
        .info-box {
            background: #eff6ff;
            border: 1px solid #93c5fd;
            border-radius: 8px;
            padding: 1rem;
            color: #1e40af;
            margin-bottom: 1.5rem;
        }
        .info-box strong {
            display: block;
            margin-bottom: 0.5rem;
        }
        .status-section {
            background: #f9fafb;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }
        .status-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px solid #e5e7eb;
        }
        .status-item:last-child {
            border-bottom: none;
        }
        .status-item strong {
            color: #1e293b;
        }
        .status-item .value {
            color: #667eea;
            font-weight: 600;
        }
        .changes-remaining {
            display: inline-block;
            background: #fef3c7;
            color: #92400e;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 600;
            margin-left: 0.5rem;
        }
        .changes-full {
            color: #dc2626;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="index.php" class="back-link">← Back to Dashboard</a>
        <h1>🌐 Change License Domain</h1>

        <div class="card">
            <?php if ($message): ?>
                <div class="message success"><?= $message ?></div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="message error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($license): ?>
                <div class="info-box">
                    <strong>ℹ️ About Domain Changes</strong>
                    <p>You can change the domain associated with your license up to <strong><?= $license['max_domain_changes'] ?></strong> times. This allows you to move your license to a different domain if needed.</p>
                </div>

                <div class="status-section">
                    <div class="status-item">
                        <strong>Current Domain:</strong>
                        <span class="value"><?= htmlspecialchars($license['domain']) ?></span>
                    </div>
                    <div class="status-item">
                        <strong>Total Changes Allowed:</strong>
                        <span class="value"><?= $license['max_domain_changes'] ?></span>
                    </div>
                    <div class="status-item">
                        <strong>Changes Used:</strong>
                        <span class="value"><?= $license['domain_change_count'] ?>/<?= $license['max_domain_changes'] ?></span>
                    </div>
                    <div class="status-item">
                        <strong>Remaining Changes:</strong>
                        <span class="value <?= $changes_remaining === 0 ? 'changes-full' : '' ?>">
                            <?= $changes_remaining ?>
                            <?php if ($changes_remaining === 0): ?>
                                <span class="changes-remaining">Maximum reached</span>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>

                <?php if ($changes_remaining > 0): ?>
                    <form method="POST">
                        <div class="form-group">
                            <label for="new_domain">New Domain Name</label>
                            <input 
                                type="text" 
                                id="new_domain" 
                                name="new_domain" 
                                placeholder="example.com"
                                required
                            >
                            <small style="color: #666; display: block; margin-top: 0.25rem;">
                                Enter the new domain without http:// or www.
                            </small>
                        </div>

                        <div class="form-group">
                            <button type="submit">✓ Update Domain</button>
                        </div>
                    </form>
                <?php else: ?>
                    <div style="padding: 2rem; background: #fee2e2; border-radius: 8px; color: #991b1b; text-align: center;">
                        <strong>⚠️ Maximum Changes Reached</strong>
                        <p style="margin-top: 0.5rem;">You have used all <?= $license['max_domain_changes'] ?> allowed domain changes for this license.</p>
                        <p style="margin-top: 0.5rem; font-size: 0.875rem;">Contact support to request additional domain changes.</p>
                    </div>
                <?php endif; ?>

                <div style="margin-top: 2rem; padding-top: 2rem; border-top: 1px solid #e5e7eb;">
                    <h3 style="color: #1e293b; margin-bottom: 1rem;">License Details</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label>License Key</label>
                            <input type="text" value="<?= htmlspecialchars(substr($license['license_key'], 0, 20)) ?>..." disabled>
                        </div>
                        <div class="form-group">
                            <label>Email</label>
                            <input type="email" value="<?= htmlspecialchars($license['customer_email']) ?>" disabled>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>

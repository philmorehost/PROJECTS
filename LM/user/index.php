<?php
session_start();

// Check if user is logged in via email
$user_email = $_SESSION['user_email'] ?? null;

if (!$user_email) {
    header('Location: login.php');
    exit();
}

require_once('../db.php');

// Fetch user's active/inactive licenses
$stmt = $pdo->prepare("SELECT * FROM licenses WHERE customer_email = ? ORDER BY created_at DESC");
$stmt->execute([$user_email]);
$licenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch user's pending license requests
$stmt_req = $pdo->prepare("SELECT * FROM license_requests WHERE customer_email = ? AND status = 'pending' ORDER BY created_at DESC");
$stmt_req->execute([$user_email]);
$pending_requests = $stmt_req->fetchAll(PDO::FETCH_ASSOC);

// Fetch user profile data
$profile = [
    'email' => $user_email,
    'total_licenses' => count($licenses),
    'active_licenses' => count(array_filter($licenses, fn($l) => $l['status'] === 'active')),
    'pending_requests' => count($pending_requests)
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Licenses - License Manager</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        .navbar {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            padding: 1rem 2rem;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .navbar h1 {
            font-size: 1.5rem;
            color: #667eea;
            margin: 0;
        }
        .navbar-links {
            display: flex;
            gap: 2rem;
            align-items: center;
        }
        .navbar-links a {
            color: #475569;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s ease;
        }
        .navbar-links a:hover {
            color: #667eea;
        }
        .logout-btn {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            padding: 0.6rem 1.2rem;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
        }
        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(239, 68, 68, 0.3);
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 3rem 2rem;
        }
        .header {
            color: white;
            margin-bottom: 3rem;
        }
        .header h2 {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
        }
        .header p {
            font-size: 1.1rem;
            opacity: 0.9;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 3rem;
        }
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            border-left: 4px solid #667eea;
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
            margin-bottom: 2rem;
        }
        .card-header {
            padding: 2rem;
            border-bottom: 1px solid #e2e8f0;
            background: linear-gradient(135deg, #f8fafc 0%, #eef2ff 100%);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .card-header h3 {
            font-size: 1.5rem;
            color: #1e293b;
            margin: 0;
            font-weight: 700;
        }
        .card-body {
            padding: 2rem;
        }
        .license-item {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            transition: all 0.3s ease;
        }
        .license-item:hover {
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.15);
            border-color: #667eea;
        }
        .license-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        .license-key {
            font-family: 'Courier New', monospace;
            background: white;
            padding: 0.75rem 1rem;
            border-radius: 6px;
            font-weight: 600;
            color: #667eea;
            word-break: break-all;
        }
        .status-badge {
            display: inline-block;
            padding: 0.5rem 1rem;
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
        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }
        .license-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }
        .detail {
            display: flex;
            flex-direction: column;
        }
        .detail-label {
            font-size: 0.875rem;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.25rem;
        }
        .detail-value {
            font-size: 1rem;
            color: #1e293b;
            font-weight: 500;
        }
        .button-group {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }
        .btn {
            padding: 0.6rem 1.2rem;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 500;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }
        .btn-secondary {
            background: #e2e8f0;
            color: #475569;
        }
        .btn-secondary:hover {
            background: #cbd5e1;
        }
        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
        }
        .empty-state-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
        }
        .empty-state h4 {
            font-size: 1.5rem;
            color: #1e293b;
            margin-bottom: 0.5rem;
        }
        .empty-state p {
            color: #64748b;
            margin-bottom: 1.5rem;
        }
        .copy-btn {
            background: none;
            border: none;
            color: #667eea;
            cursor: pointer;
            font-weight: 600;
            padding: 0;
            text-decoration: underline;
            transition: all 0.2s ease;
        }
        .copy-btn:hover {
            color: #764ba2;
        }
        @media (max-width: 768px) {
            .navbar {
                flex-direction: column;
                gap: 1rem;
            }
            .navbar-links {
                flex-direction: column;
                width: 100%;
                gap: 0.5rem;
            }
            .navbar-links a,
            .logout-btn {
                width: 100%;
                text-align: center;
            }
            .header h2 {
                font-size: 1.8rem;
            }
            .stats-grid {
                grid-template-columns: 1fr;
            }
            .license-header {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <div class="navbar">
        <h1>🔐 License Manager</h1>
        <div class="navbar-links">
            <a href="profile.php">👤 Profile</a>
            <a href="index.php">📜 My Licenses</a>
            <a href="logout.php" class="logout-btn">Logout</a>
        </div>
    </div>

    <div class="container">
        <div class="header">
            <h2>My Licenses</h2>
            <p>Manage and monitor all your active licenses</p>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <h3>Total Licenses</h3>
                <div class="value"><?= $profile['total_licenses'] ?></div>
            </div>
            <div class="stat-card" style="border-left-color: #10b981;">
                <h3>Active Licenses</h3>
                <div class="value" style="color: #10b981;"><?= $profile['active_licenses'] ?></div>
            </div>
            <div class="stat-card" style="border-left-color: #f59e0b;">
                <h3>Pending Requests</h3>
                <div class="value" style="color: #f59e0b;"><?= $profile['pending_requests'] ?></div>
            </div>
        </div>

        <?php if (!empty($pending_requests)): ?>
        <div class="card" style="border: 2px solid #fef3c7;">
            <div class="card-header" style="background: #fef3c7;">
                <h3>⏳ Pending License Requests</h3>
            </div>
            <div class="card-body">
                <?php foreach ($pending_requests as $req): ?>
                    <div class="license-item" style="background: white;">
                        <div class="license-header">
                            <div>
                                <div style="margin-bottom: 0.5rem;">
                                    <strong style="color: #1e293b;">Request Ref:</strong>
                                </div>
                                <div class="license-key" style="background: #f8fafc;">
                                    <?= htmlspecialchars($req['request_ref']) ?>
                                    <button class="copy-btn" onclick="copyToClipboard('<?= htmlspecialchars($req['request_ref']) ?>')">📋 Copy</button>
                                </div>
                            </div>
                            <span class="status-badge status-pending">Pending Approval</span>
                        </div>

                        <div class="license-details">
                            <div class="detail">
                                <span class="detail-label">Domain</span>
                                <span class="detail-value"><?= htmlspecialchars($req['domain']) ?></span>
                            </div>
                            <div class="detail">
                                <span class="detail-label">Requested Date</span>
                                <span class="detail-value"><?= date('M d, Y', strtotime($req['created_at'])) ?></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h3>📋 Your Licenses</h3>
            </div>
            <div class="card-body">
                <?php if (empty($licenses)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">📭</div>
                        <h4>No Licenses Found</h4>
                        <p>You don't have any active licenses yet.</p>
                        <a href="../order.php" class="btn btn-primary">Request a License</a>
                    </div>
                <?php else: ?>
                    <?php foreach ($licenses as $license): ?>
                        <div class="license-item">
                            <div class="license-header">
                                <div>
                                    <div style="margin-bottom: 0.5rem;">
                                        <strong style="color: #1e293b;">License Key:</strong>
                                    </div>
                                    <div class="license-key">
                                        <?= htmlspecialchars($license['license_key']) ?>
                                        <button class="copy-btn" onclick="copyToClipboard('<?= htmlspecialchars($license['license_key']) ?>')">📋 Copy</button>
                                    </div>
                                </div>
                                <span class="status-badge <?= $license['status'] === 'active' ? 'status-active' : 'status-inactive' ?>">
                                    <?= ucfirst($license['status']) ?>
                                </span>
                            </div>

                            <div class="license-details">
                                <div class="detail">
                                    <span class="detail-label">Domain</span>
                                    <span class="detail-value"><?= htmlspecialchars($license['domain']) ?></span>
                                </div>
                                <div class="detail">
                                    <span class="detail-label">Created Date</span>
                                    <span class="detail-value"><?= date('M d, Y', strtotime($license['created_at'])) ?></span>
                                </div>
                            </div>

                            <div class="button-group">
                                <a href="license-details.php?id=<?= $license['id'] ?>" class="btn btn-primary">View Details</a>
                                <a href="change-domain.php?id=<?= $license['id'] ?>" class="btn btn-secondary">🌐 Change Domain</a>
                                <a href="api-integration.php?license_id=<?= $license['id'] ?>" class="btn btn-secondary">API Integration</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => {
                alert('Copied to clipboard!');
            }).catch(err => {
                console.error('Failed to copy:', err);
            });
        }
    </script>
</body>
</html>

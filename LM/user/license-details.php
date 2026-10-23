<?php
session_start();

$user_email = $_SESSION['user_email'] ?? null;

if (!$user_email) {
    header('Location: login.php');
    exit();
}

require_once('../db.php');

$license_id = $_GET['id'] ?? null;

if (!$license_id) {
    header('Location: index.php');
    exit();
}

// Fetch the specific license
$stmt = $pdo->prepare("SELECT * FROM licenses WHERE id = ? AND customer_email = ?");
$stmt->execute([$license_id, $user_email]);
$license = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$license) {
    header('Location: index.php');
    exit();
}

// Parse domain and sites from the license (sites are tracked via API usage)
$api_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]/api.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>License Details - License Manager</title>
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
            max-width: 900px;
            margin: 0 auto;
            padding: 3rem 2rem;
        }
        .back-btn {
            color: white;
            text-decoration: none;
            font-weight: 500;
            margin-bottom: 2rem;
            display: inline-block;
            transition: all 0.3s ease;
        }
        .back-btn:hover {
            transform: translateX(-4px);
        }
        .header {
            color: white;
            margin-bottom: 2rem;
        }
        .header h2 {
            font-size: 2rem;
            margin-bottom: 0.5rem;
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
            background: linear-gradient(135deg, #f8fafc 0%, #eef2ff 100%);
            border-bottom: 1px solid #e2e8f0;
        }
        .card-header h3 {
            font-size: 1.3rem;
            color: #1e293b;
            margin: 0 0 0.5rem 0;
        }
        .card-body {
            padding: 2rem;
        }
        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
        }
        .detail-item {
            display: flex;
            flex-direction: column;
        }
        .detail-label {
            font-size: 0.875rem;
            color: #64748b;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.5rem;
        }
        .detail-value {
            font-size: 1.1rem;
            color: #1e293b;
            font-weight: 600;
            word-break: break-all;
        }
        .status-badge {
            display: inline-block;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-size: 0.875rem;
            font-weight: 600;
            width: fit-content;
        }
        .status-active {
            background: #d1fae5;
            color: #065f46;
        }
        .status-inactive {
            background: #fee2e2;
            color: #991b1b;
        }
        .code-block {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 1rem;
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
            color: #1e293b;
            word-break: break-all;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .copy-btn {
            background: #667eea;
            color: white;
            border: none;
            padding: 0.4rem 0.8rem;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        .copy-btn:hover {
            background: #764ba2;
        }
        .button-group {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
        }
        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 1rem;
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
                font-size: 1.5rem;
            }
            .detail-grid {
                grid-template-columns: 1fr;
            }
            .code-block {
                flex-direction: column;
                gap: 1rem;
            }
            .button-group {
                flex-direction: column;
            }
            .btn {
                width: 100%;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <div class="navbar">
        <h1>🔐 License Manager</h1>
        <div class="navbar-links">
            <a href="index.php">📜 My Licenses</a>
            <a href="profile.php">👤 Profile</a>
            <a href="logout.php" class="logout-btn">Logout</a>
        </div>
    </div>

    <div class="container">
        <a href="index.php" class="back-btn">← Back to Licenses</a>

        <div class="header">
            <h2>📋 License Details</h2>
        </div>

        <div class="card">
            <div class="card-header">
                <h3>License Information</h3>
            </div>
            <div class="card-body">
                <div class="detail-grid">
                    <div class="detail-item">
                        <span class="detail-label">License Key</span>
                        <span class="detail-value" style="font-family: 'Courier New', monospace;"><?= htmlspecialchars($license['license_key']) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Domain</span>
                        <span class="detail-value"><?= htmlspecialchars($license['domain']) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Status</span>
                        <span class="status-badge <?= $license['status'] === 'active' ? 'status-active' : 'status-inactive' ?>">
                            <?= ucfirst($license['status']) ?>
                        </span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Email</span>
                        <span class="detail-value"><?= htmlspecialchars($license['customer_email'] ?? 'N/A') ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Created Date</span>
                        <span class="detail-value"><?= date('F d, Y', strtotime($license['created_at'])) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Expiry Date</span>
                        <span class="detail-value"><?= $license['expiry_date'] ? date('F d, Y', strtotime($license['expiry_date'])) : 'Never' ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3>🌐 API Integration</h3>
            </div>
            <div class="card-body">
                <p style="color: #64748b; margin-bottom: 1.5rem;">
                    Use the following details to integrate your license validation into your website:
                </p>
                
                <div style="margin-bottom: 1.5rem;">
                    <strong style="color: #1e293b;">API Endpoint:</strong>
                    <div class="code-block">
                        <span><?= htmlspecialchars($api_url) ?></span>
                        <button class="copy-btn" onclick="copyToClipboard('<?= htmlspecialchars($api_url) ?>')">📋 Copy</button>
                    </div>
                </div>

                <div style="margin-bottom: 1.5rem;">
                    <strong style="color: #1e293b;">License Key:</strong>
                    <div class="code-block">
                        <span><?= htmlspecialchars($license['license_key']) ?></span>
                        <button class="copy-btn" onclick="copyToClipboard('<?= htmlspecialchars($license['license_key']) ?>')">📋 Copy</button>
                    </div>
                </div>

                <div>
                    <strong style="color: #1e293b;">Example Request (JavaScript):</strong>
                    <pre style="background: #f8fafc; padding: 1rem; border-radius: 8px; overflow-x: auto; border: 1px solid #e2e8f0;"><code>fetch('<?= htmlspecialchars($api_url) ?>', {
    method: 'POST',
    body: new URLSearchParams({
        key: '<?= htmlspecialchars(substr($license['license_key'], 0, 20)) ?>...',
        domain: '<?= htmlspecialchars($license['domain']) ?>'
    })
})
.then(r => r.json())
.then(data => console.log(data));</code></pre>
                </div>

                <div class="button-group">
                    <a href="../api-docs.php" class="btn btn-primary">📚 View Full API Docs</a>
                    <a href="index.php" class="btn btn-secondary">← Back to Licenses</a>
                </div>
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

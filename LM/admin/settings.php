<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit();
}

require_once('../db.php');

// For simplicity, we'll store settings in a JSON file.
// In a real application, you might use a database table.
$settings_file = '../settings.json';
$settings = [];
if (file_exists($settings_file)) {
    $settings = json_decode(file_get_contents($settings_file), true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $settings['site_name'] = $_POST['site_name'] ?? '';
    $settings['paystack_public_key'] = $_POST['paystack_public_key'] ?? '';
    $settings['paystack_secret_key'] = $_POST['paystack_secret_key'] ?? '';
    $settings['license_price'] = $_POST['license_price'] ?? '5000.00';
        $settings['payment_gateway_enabled'] = isset($_POST['payment_gateway_enabled']) ? true : false;
    $settings['max_domain_changes'] = (int)($_POST['max_domain_changes'] ?? 3);
    $settings['api_secret_key'] = $_POST['api_secret_key'] ?? '';
    
    $whitelisted_ips_raw = $_POST['whitelisted_ips'] ?? '';
    $settings['whitelisted_ips'] = array_values(array_filter(array_map('trim', explode(',', $whitelisted_ips_raw))));

    $settings['smtp_host'] = $_POST['smtp_host'] ?? '';
    $settings['smtp_port'] = $_POST['smtp_port'] ?? '';
    $settings['smtp_user'] = $_POST['smtp_user'] ?? '';
    $settings['smtp_pass'] = $_POST['smtp_pass'] ?? '';
    $settings['admin_email'] = $_POST['admin_email'] ?? '';

    if (isset($_FILES['site_logo']) && $_FILES['site_logo']['error'] == 0) {
        $target_dir = "../uploads/";
        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0755, true);
        }
        $target_file = $target_dir . basename($_FILES["site_logo"]["name"]);
        if (move_uploaded_file($_FILES["site_logo"]["tmp_name"], $target_file)) {
            $settings['site_logo'] = 'uploads/' . basename($_FILES["site_logo"]["name"]);
        }
    }

    file_put_contents($settings_file, json_encode($settings, JSON_PRETTY_PRINT));
    header('Location: settings.php');
    exit();
}

$webhook_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]/webhook.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - License Manager</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');

        :root {
            color-scheme: light;
            font-family: 'Inter', sans-serif;
            font-size: 16px;
            color: #102a43;
            background: #e2e8f0;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html, body {
            min-height: 100%;
            background: #f1f5f9;
        }

        body {
            font-family: 'Inter', sans-serif;
            color: #102a43;
        }

        .sidebar {
            position: fixed;
            inset: 0 auto auto 0;
            width: 240px;
            min-height: 100vh;
            background: #0f172a;
            color: #f8fafc;
            display: flex;
            flex-direction: column;
            padding: 2rem 1rem;
            gap: 1rem;
            box-shadow: 4px 0 40px rgba(15, 23, 42, 0.12);
        }

        .sidebar h1 {
            font-size: 1.5rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            text-align: center;
            margin-bottom: 1.5rem;
            color: #f8fafc;
        }

        .sidebar nav {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .sidebar nav a {
            display: block;
            padding: 0.95rem 1rem;
            border-radius: 0.85rem;
            color: #cbd5e1;
            text-decoration: none;
            transition: all 0.25s ease;
            font-weight: 500;
        }

        .sidebar nav a.active,
        .sidebar nav a:hover {
            color: #fff;
            background: rgba(59, 130, 246, 0.18);
            box-shadow: inset 3px 0 0 #3b82f6;
            padding-left: 1.1rem;
        }

        .main-content {
            margin-left: 260px;
            padding: 2.5rem;
            min-height: 100vh;
        }

        .header {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: flex-end;
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .header h2 {
            font-size: 2.25rem;
            line-height: 1.1;
            color: #102a43;
        }

        .header p {
            color: #64748b;
            max-width: 44rem;
            margin-top: 0.5rem;
        }

        .page-grid {
            display: grid;
            gap: 1.5rem;
            grid-template-columns: minmax(0, 1fr);
        }

        .section-grid {
            display: grid;
            gap: 1.5rem;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .card {
            background: #ffffff;
            border: 1px solid rgba(148, 163, 184, 0.18);
            border-radius: 1.25rem;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(15, 23, 42, 0.08);
        }

        .card-header {
            padding: 1.75rem 2rem;
            background: linear-gradient(135deg, #eff6ff 0%, #e0f2fe 100%);
            border-bottom: 1px solid #e2e8f0;
        }

        .card-header h3 {
            margin: 0;
            font-size: 1.2rem;
            color: #0f172a;
            font-weight: 700;
        }

        .card-body {
            padding: 2rem;
        }

        .input-grid {
            display: grid;
            gap: 1.25rem;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .input-group {
            display: flex;
            flex-direction: column;
            gap: 0.55rem;
        }

        .input-group label {
            font-size: 0.95rem;
            font-weight: 600;
            color: #0f172a;
        }

        .input-group input,
        .input-group textarea {
            width: 100%;
            min-height: 3rem;
            padding: 0.95rem 1rem;
            border-radius: 0.95rem;
            border: 1px solid #cbd5e1;
            font-size: 0.96rem;
            color: #0f172a;
            background: #f8fafc;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .input-group input:focus,
        .input-group textarea:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.12);
        }

        .input-group small {
            color: #64748b;
            line-height: 1.5;
        }

        .checkbox-row {
            display: flex;
            align-items: center;
            gap: 0.85rem;
            padding: 1rem 1.1rem;
            border: 1px solid #cbd5e1;
            border-radius: 0.95rem;
            background: #f8fafc;
        }

        .checkbox-row input {
            width: auto;
            margin: 0;
        }

        .checkbox-row span {
            color: #334155;
            font-weight: 500;
        }

        .form-footer {
            margin-top: 1.5rem;
            display: flex;
            justify-content: flex-end;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 3rem;
            padding: 0 1.5rem;
            border-radius: 999px;
            border: none;
            color: #ffffff;
            font-weight: 700;
            font-size: 0.98rem;
            background: linear-gradient(135deg, #2563eb 0%, #3b82f6 100%);
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 20px 40px rgba(59, 130, 246, 0.18);
        }

        .wide-input {
            grid-column: 1 / -1;
        }

        .wide-card {
            grid-column: 1 / -1;
        }

        .logo-preview {
            max-height: 90px;
            border-radius: 1rem;
            border: 1px solid rgba(148, 163, 184, 0.3);
            margin-top: 0.75rem;
        }

        @media (max-width: 1024px) {
            .section-grid {
                grid-template-columns: minmax(0, 1fr);
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                position: relative;
                width: 100%;
                min-height: auto;
                padding: 1.5rem 1rem;
            }

            .main-content {
                margin-left: 0;
                padding: 1.5rem;
            }

            .header h2 {
                font-size: 1.75rem;
            }

            .input-grid {
                grid-template-columns: minmax(0, 1fr);
            }

            .form-footer {
                justify-content: stretch;
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
            <a href="transactions.php">💳 Transactions</a>
            <a href="settings.php" class="active">⚙️ Settings</a>
            <a href="profile.php">👤 Profile</a>
            <a href="logout.php">🚪 Logout</a>
        </nav>
    </div>
    <div class="main-content">
        <div class="header">
            <h2>Settings</h2>
        </div>

        <div class="card wide-card">
            <div class="card-header"><h3>Webhook URL</h3></div>
            <div class="card-body">
                <p>Copy this URL and paste it into your Paystack webhook settings.</p>
                <div class="input-group wide-input">
                    <input type="text" value="<?= htmlspecialchars($webhook_url) ?>" readonly>
                </div>
            </div>
        </div>

        <form method="POST" enctype="multipart/form-data">
            <div class="section-grid">
                <div class="card">
                    <div class="card-header"><h3>Site Settings</h3></div>
                    <div class="card-body">
                        <div class="input-grid">
                            <div class="input-group wide-input">
                                <label>Site Name</label>
                                <input type="text" name="site_name" value="<?= htmlspecialchars($settings['site_name'] ?? '') ?>">
                            </div>
                            <div class="input-group wide-input">
                                <label>Site Logo</label>
                                <input type="file" name="site_logo">
                                <?php if (isset($settings['site_logo'])): ?>
                                    <img class="logo-preview" src="../<?= htmlspecialchars($settings['site_logo']) ?>" alt="Current Logo">
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h3>Payment Gateway</h3></div>
                    <div class="card-body">
                        <div class="input-grid">
                            <div class="input-group wide-input">
                                <label>Paystack Public Key</label>
                                <input type="text" name="paystack_public_key" value="<?= htmlspecialchars($settings['paystack_public_key'] ?? '') ?>">
                            </div>
                            <div class="input-group wide-input">
                                <label>Paystack Secret Key</label>
                                <input type="text" name="paystack_secret_key" value="<?= htmlspecialchars($settings['paystack_secret_key'] ?? '') ?>">
                            </div>
                            <div class="input-group">
                                <label>License Price (NGN)</label>
                                <input type="number" step="0.01" name="license_price" value="<?= htmlspecialchars($settings['license_price'] ?? '5000.00') ?>">
                            </div>
                            <div class="input-group">
                                <label>Gateway Status</label>
                                <div class="checkbox-row">
                                    <input type="checkbox" name="payment_gateway_enabled" value="1" <?= !isset($settings['payment_gateway_enabled']) || $settings['payment_gateway_enabled'] ? 'checked' : '' ?>>
                                    <span>Enable Paystack checkout</span>
                                </div>
                                <small>If disabled, users submit a manual license request and will wait for admin approval.</small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h3>License Settings</h3></div>
                    <div class="card-body">
                        <div class="input-grid">
                            <div class="input-group wide-input">
                                <label>Maximum Domain Changes</label>
                                <input type="number" min="1" max="100" name="max_domain_changes" value="<?= htmlspecialchars($settings['max_domain_changes'] ?? 3) ?>">
                                <small>How many times can users change domains for one license?</small>
                            </div>
                            <div class="input-group wide-input">
                                <label>API Secret Key (for CodeVault Integration)</label>
                                <input type="text" name="api_secret_key" value="<?= htmlspecialchars($settings['api_secret_key'] ?? '') ?>" placeholder="Enter a secret key used by CodeVault to create licenses">
                                <small>Provide this secret key in your CodeVault product configuration to allow automatic license generation.</small>
                            </div>
                            <div class="input-group wide-input">
                                <label>OTA Whitelisted IPs (comma-separated)</label>
                                <input type="text" name="whitelisted_ips" value="<?= htmlspecialchars(isset($settings['whitelisted_ips']) ? implode(', ', (array)$settings['whitelisted_ips']) : '') ?>" placeholder="e.g. 127.0.0.1, ::1">
                                <small>IP addresses allowed to download SAAS unlicensed packages directly without key verification.</small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h3>SMTP Settings</h3></div>
                    <div class="card-body">
                        <div class="input-grid">
                            <div class="input-group">
                                <label>SMTP Host</label>
                                <input type="text" name="smtp_host" value="<?= htmlspecialchars($settings['smtp_host'] ?? '') ?>">
                            </div>
                            <div class="input-group">
                                <label>SMTP Port</label>
                                <input type="text" name="smtp_port" value="<?= htmlspecialchars($settings['smtp_port'] ?? '') ?>">
                            </div>
                            <div class="input-group">
                                <label>SMTP User</label>
                                <input type="text" name="smtp_user" value="<?= htmlspecialchars($settings['smtp_user'] ?? '') ?>">
                            </div>
                            <div class="input-group">
                                <label>SMTP Pass</label>
                                <input type="password" name="smtp_pass" value="<?= htmlspecialchars($settings['smtp_pass'] ?? '') ?>">
                            </div>
                            <div class="input-group wide-input">
                                <label>Admin Email</label>
                                <input type="email" name="admin_email" value="<?= htmlspecialchars($settings['admin_email'] ?? '') ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-footer">
                <button type="submit" class="btn">Save Settings</button>
            </div>
        </form>
    </div>
</body>
</html>

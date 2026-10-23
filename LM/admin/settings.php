<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit();
}

require_once('../db.php');
require_once('../includes/mailer.php');
require_once('../includes/updates.php');
require_once('../includes/guide.php');

$settings_file = '../settings.json';
$settings = lm_settings(true);
$saveError = '';
$saveOk = isset($_GET['saved']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!lm_verify_csrf()) {
        $saveError = 'Your session expired. Refresh the page and try again.';
    } else {
        $new = $settings;

        $new['site_name'] = trim($_POST['site_name'] ?? '');
        $new['site_url'] = rtrim(trim($_POST['site_url'] ?? ''), '/');
        $new['paystack_public_key'] = trim($_POST['paystack_public_key'] ?? '');
        $new['paystack_secret_key'] = trim($_POST['paystack_secret_key'] ?? '');
        $new['license_price'] = $_POST['license_price'] ?? '0';
        $new['payment_gateway_enabled'] = isset($_POST['payment_gateway_enabled']);
        $new['max_domain_changes'] = max(1, (int) ($_POST['max_domain_changes'] ?? 3));
        $new['api_secret_key'] = trim($_POST['api_secret_key'] ?? '');

        $whitelistedIps = $_POST['whitelisted_ips'] ?? '';
        $new['whitelisted_ips'] = array_values(array_filter(array_map('trim', explode(',', (string) $whitelistedIps))));

        $new['smtp_host'] = trim($_POST['smtp_host'] ?? '');
        $new['smtp_port'] = trim($_POST['smtp_port'] ?? '587');
        $new['smtp_user'] = trim($_POST['smtp_user'] ?? '');
        $new['smtp_pass'] = (string) ($_POST['smtp_pass'] ?? '');
        $new['smtp_encryption'] = in_array(($_POST['smtp_encryption'] ?? 'auto'), array('auto', 'ssl', 'tls', 'none'), true)
            ? $_POST['smtp_encryption'] : 'auto';
        $new['smtp_timeout'] = max(5, min(120, (int) ($_POST['smtp_timeout'] ?? 20)));
        $new['smtp_verify_peer'] = isset($_POST['smtp_verify_peer']);
        $new['smtp_debug'] = isset($_POST['smtp_debug']);
        $new['smtp_from_email'] = trim($_POST['smtp_from_email'] ?? '');
        $new['smtp_from_name'] = trim($_POST['smtp_from_name'] ?? '');
        $new['admin_email'] = trim($_POST['admin_email'] ?? '');

        $new['update_notify_enabled'] = isset($_POST['update_notify_enabled']);
        $new['update_skip_if_downloaded'] = isset($_POST['update_skip_if_downloaded']);
        $new['update_notify_delay_minutes'] = max(0, (int) ($_POST['update_notify_delay_minutes'] ?? 0));
        $new['update_reminder_hours'] = max(1, min(336, (int) ($_POST['update_reminder_hours'] ?? 24)));
        $new['update_reminder_max'] = max(0, min(5, (int) ($_POST['update_reminder_max'] ?? 1)));
        $new['update_email_batch_size'] = max(5, min(100, (int) ($_POST['update_email_batch_size'] ?? 25)));

        $new['updates_storage_dir'] = trim($_POST['updates_storage_dir'] ?? '');
        $new['max_update_size_mb'] = max(1, min(2048, (int) ($_POST['max_update_size_mb'] ?? 128)));

        if (!empty($_POST['regenerate_cron'])) {
            try {
                $new['cron_secret'] = bin2hex(random_bytes(24));
            } catch (Exception $e) {
                $new['cron_secret'] = hash('sha256', uniqid('cron', true));
            }
        }

        if (isset($_FILES['site_logo']) && $_FILES['site_logo']['error'] === UPLOAD_ERR_OK) {
            $targetDir = '../uploads/';
            lm_ensure_dir($targetDir, 0755);
            $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($_FILES['site_logo']['name']));
            if ($safeName !== '' && move_uploaded_file($_FILES['site_logo']['tmp_name'], $targetDir . $safeName)) {
                $new['site_logo'] = 'uploads/' . $safeName;
            }
        }

        if (lm_save_settings($new)) {
            header('Location: settings.php?saved=1');
            exit();
        }
        $saveError = 'Could not write settings.json. Check the file permissions (0640) on the LM folder.';
    }

    $settings = lm_settings(true);
}

$cronSecret = lm_get_cron_secret();
$cronUrl = lm_site_url('cron.php?key=' . rawurlencode($cronSecret));
$smtpDescription = lm_smtp_describe($settings);
$detectedUrl = lm_site_url();
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
            <a href="github-updates.php">🐙 GitHub Pull</a>
            <a href="update-notifications.php">🔔 Notifications</a>
            <a href="projects.php">🧩 Script Projects</a>
            <a href="transactions.php">💳 Transactions</a>
            <a href="guide.php">📖 Admin Guide</a>
            <a href="settings.php" class="active">⚙️ Settings</a>
            <a href="profile.php">👤 Profile</a>
            <a href="logout.php">🚪 Logout</a>
        </nav>
    </div>
    <div class="main-content">
        <div class="header" style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;">
            <div>
                <h2>Settings</h2>
                <p style="color:#64748b;margin-top:8px;max-width:620px;">Delivery, automation and storage for the whole License Manager.</p>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <?= lm_guide_help_button('settings') ?>
                <a href="guide.php#cron" style="display:inline-flex;align-items:center;gap:7px;padding:10px 15px;border-radius:10px;border:1px solid #d7deea;background:#fff;color:#334155;font-size:13px;font-weight:700;text-decoration:none;white-space:nowrap;">⏱️ Cron setup guide</a>
            </div>
        </div>

        <?php if ($saveOk): ?>
            <div style="background:#ecfdf5;border:1px solid #bbf7d0;color:#166534;padding:14px 18px;border-radius:14px;margin-bottom:20px;font-size:14px;font-weight:600;">✓ Settings saved.</div>
        <?php endif; ?>
        <?php if ($saveError !== ''): ?>
            <div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:14px 18px;border-radius:14px;margin-bottom:20px;font-size:14px;font-weight:600;"><?= htmlspecialchars($saveError) ?></div>
        <?php endif; ?>

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
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(lm_csrf_token()) ?>">
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
                                <label>Public Site URL</label>
                                <input type="text" name="site_url" value="<?= htmlspecialchars($settings['site_url'] ?? '') ?>" placeholder="<?= htmlspecialchars($detectedUrl) ?>">
                                <small>Used in emails, OTA responses and cron output. Detected automatically as <strong><?= htmlspecialchars($detectedUrl) ?></strong> — set it explicitly if cron runs on a different host.</small>
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

                <div class="card" id="smtp">
                    <div class="card-header"><h3>SMTP &amp; Email Delivery</h3></div>
                    <div class="card-body">
                        <div style="background:#eff6ff;border:1px solid #dbeafe;color:#1e40af;border-radius:12px;padding:12px 14px;font-size:13px;line-height:1.6;margin-bottom:18px;">
                            Currently resolved transport: <strong><?= htmlspecialchars($smtpDescription) ?></strong><br>
                            <span style="font-size:12px;">Port 465 requires <strong>SSL/TLS</strong>; port 587 uses <strong>STARTTLS</strong>. “Auto” picks the right one, which fixes <code>SMTP connect() failed</code>.</span>
                        </div>
                        <div class="input-grid">
                            <div class="input-group">
                                <label>SMTP Host</label>
                                <input type="text" name="smtp_host" value="<?= htmlspecialchars($settings['smtp_host'] ?? '') ?>" placeholder="mail.yourdomain.com">
                            </div>
                            <div class="input-group">
                                <label>SMTP Port</label>
                                <input type="text" name="smtp_port" value="<?= htmlspecialchars($settings['smtp_port'] ?? '587') ?>" placeholder="465 or 587">
                            </div>
                            <div class="input-group">
                                <label>Encryption</label>
                                <select name="smtp_encryption" style="width:100%;min-height:3rem;padding:0.95rem 1rem;border-radius:0.95rem;border:1px solid #cbd5e1;background:#f8fafc;">
                                    <?php foreach (array('auto' => 'Auto (recommended)', 'ssl' => 'SSL/TLS — port 465', 'tls' => 'STARTTLS — port 587', 'none' => 'None') as $value => $label): ?>
                                        <option value="<?= $value ?>" <?= ($settings['smtp_encryption'] ?? 'auto') === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small>This was the cause of the queued email failures — port 465 was being used with STARTTLS.</small>
                            </div>
                            <div class="input-group">
                                <label>Timeout (seconds)</label>
                                <input type="number" min="5" max="120" name="smtp_timeout" value="<?= (int) ($settings['smtp_timeout'] ?? 20) ?>">
                            </div>
                            <div class="input-group">
                                <label>SMTP User</label>
                                <input type="text" name="smtp_user" value="<?= htmlspecialchars($settings['smtp_user'] ?? '') ?>" autocomplete="off">
                            </div>
                            <div class="input-group">
                                <label>SMTP Pass</label>
                                <input type="password" name="smtp_pass" value="<?= htmlspecialchars($settings['smtp_pass'] ?? '') ?>" autocomplete="new-password">
                            </div>
                            <div class="input-group">
                                <label>From Email (optional)</label>
                                <input type="email" name="smtp_from_email" value="<?= htmlspecialchars($settings['smtp_from_email'] ?? '') ?>" placeholder="Defaults to the admin email">
                            </div>
                            <div class="input-group">
                                <label>From Name (optional)</label>
                                <input type="text" name="smtp_from_name" value="<?= htmlspecialchars($settings['smtp_from_name'] ?? '') ?>" placeholder="Defaults to the site name">
                            </div>
                            <div class="input-group wide-input">
                                <label>Admin Email</label>
                                <input type="email" name="admin_email" value="<?= htmlspecialchars($settings['admin_email'] ?? '') ?>">
                            </div>
                        </div>

                        <div class="checkbox-row" style="margin-top:4px;">
                            <input type="checkbox" name="smtp_verify_peer" value="1" <?= !isset($settings['smtp_verify_peer']) || $settings['smtp_verify_peer'] ? 'checked' : '' ?>>
                            <span>Verify the server TLS certificate <small style="color:#64748b;">(leave on unless your host uses a self-signed certificate)</small></span>
                        </div>
                        <div class="checkbox-row" style="margin-top:10px;">
                            <input type="checkbox" name="smtp_debug" value="1" <?= !empty($settings['smtp_debug']) ? 'checked' : '' ?>>
                            <span>Log the full SMTP conversation <small style="color:#64748b;">(for troubleshooting; written to storage/logs)</small></span>
                        </div>

                        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:18px;">
                            <input type="email" id="testEmail" value="<?= htmlspecialchars($settings['admin_email'] ?? '') ?>" placeholder="you@example.com" style="flex:1;min-width:220px;min-height:3rem;padding:0.95rem 1rem;border-radius:0.95rem;border:1px solid #cbd5e1;background:#f8fafc;">
                            <button type="button" class="btn" id="testSmtpBtn" style="background:linear-gradient(135deg,#0f172a,#334155);">Send test email</button>
                        </div>
                        <div id="smtpTestResult" style="margin-top:14px;font-size:13px;line-height:1.65;"></div>
                        <small style="display:block;margin-top:8px;color:#94a3b8;">Save the new SMTP values first — the test uses the saved settings.</small>
                    </div>
                </div>

                <div class="card" id="notifications">
                    <div class="card-header"><h3>Update Notifications</h3></div>
                    <div class="card-body">
                        <div class="checkbox-row">
                            <input type="checkbox" name="update_notify_enabled" value="1" <?= !empty($settings['update_notify_enabled']) ? 'checked' : '' ?>>
                            <span>Email customers when a new update is published</span>
                        </div>
                        <div class="checkbox-row" style="margin-top:10px;">
                            <input type="checkbox" name="update_skip_if_downloaded" value="1" <?= !empty($settings['update_skip_if_downloaded']) ? 'checked' : '' ?>>
                            <span>Skip the reminder when the package was already downloaded</span>
                        </div>
                        <div class="input-grid" style="margin-top:18px;">
                            <div class="input-group">
                                <label>First email delay (minutes)</label>
                                <input type="number" min="0" name="update_notify_delay_minutes" value="<?= (int) ($settings['update_notify_delay_minutes'] ?? 0) ?>">
                            </div>
                            <div class="input-group">
                                <label>Reminder interval (hours)</label>
                                <input type="number" min="1" max="336" name="update_reminder_hours" value="<?= (int) ($settings['update_reminder_hours'] ?? 24) ?>">
                                <small>24 = “the next day”.</small>
                            </div>
                            <div class="input-group">
                                <label>Maximum reminders per release</label>
                                <input type="number" min="0" max="5" name="update_reminder_max" value="<?= (int) ($settings['update_reminder_max'] ?? 1) ?>">
                            </div>
                            <div class="input-group">
                                <label>Batch size per cron run</label>
                                <input type="number" min="5" max="100" name="update_email_batch_size" value="<?= (int) ($settings['update_email_batch_size'] ?? 25) ?>">
                            </div>
                        </div>
                        <small style="color:#64748b;">Reminders are only sent to customers whose site has not reported the new version. <a href="update-notifications.php" style="color:#4f46e5;font-weight:600;">View the delivery log →</a></small>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h3>Update Storage</h3></div>
                    <div class="card-body">
                        <div class="input-grid">
                            <div class="input-group wide-input">
                                <label>Storage directory (absolute or relative to /LM)</label>
                                <input type="text" name="updates_storage_dir" value="<?= htmlspecialchars($settings['updates_storage_dir'] ?? '') ?>" placeholder="<?= htmlspecialchars(lm_updates_root()) ?>">
                                <small>Default: <code><?= htmlspecialchars(lm_updates_root()) ?></code> — outside the public web root. Packages are deleted from here when you delete or clean up updates.</small>
                            </div>
                            <div class="input-group">
                                <label>Maximum package size (MB)</label>
                                <input type="number" min="1" max="2048" name="max_update_size_mb" value="<?= (int) ($settings['max_update_size_mb'] ?? 128) ?>">
                                <small>Applies to uploads and GitHub archives.</small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card" id="automation">
                    <div class="card-header"><h3>Automation &amp; Cron</h3></div>
                    <div class="card-body">
                        <p style="color:#475569;font-size:14px;line-height:1.7;margin-bottom:14px;">The cron job sends queued emails, update notifications and the next-day reminders. Run it every 10 minutes.</p>
                        <div class="input-group wide-input">
                            <label>Cron command (cPanel → Cron Jobs)</label>
                            <input type="text" readonly value="*/10 * * * * /usr/local/bin/php <?= htmlspecialchars(lm_lm_dir()) ?>/cron.php" onclick="this.select()">
                        </div>
                        <div class="input-group wide-input">
                            <label>Web cron URL (if CLI cron is unavailable)</label>
                            <input type="text" readonly value="<?= htmlspecialchars($cronUrl) ?>" onclick="this.select()">
                        </div>
                        <div class="checkbox-row" style="margin-top:6px;">
                            <input type="checkbox" name="regenerate_cron" value="1">
                            <span>Generate a new cron key <small style="color:#64748b;">(existing cron jobs must be updated)</small></span>
                        </div>
                        <p style="margin-top:14px;font-size:12.5px;color:#475569;line-height:1.6;">
                            Step-by-step setup, the task breakdown and a live tester are in the
                            <a href="guide.php#cron" style="color:#4f46e5;font-weight:700;">Admin guide → Cron</a>.
                        </p>
                    </div>
                </div>
            </div>

            <div class="form-footer">
                <button type="submit" class="btn">Save Settings</button>
            </div>
        </form>
    </div>

    <script>
    document.getElementById('testSmtpBtn').addEventListener('click', async function () {
        const button = this;
        const email = document.getElementById('testEmail').value.trim();
        const box = document.getElementById('smtpTestResult');
        button.disabled = true;
        button.textContent = 'Sending…';
        box.innerHTML = '<span style="color:#64748b;">Connecting to the mail server…</span>';

        const body = new FormData();
        body.append('email', email);
        body.append('csrf_token', <?= json_encode(lm_csrf_token()) ?>);

        try {
            const response = await fetch('ajax_test_email.php', { method: 'POST', body: body, credentials: 'same-origin' });
            const text = await response.text();
            let result;
            try { result = JSON.parse(text); } catch (e) { result = { status: 'error', message: 'Unexpected response: ' + text.slice(0, 300) }; }

            if (result.status === 'success') {
                box.innerHTML = '<div style="background:#ecfdf5;border:1px solid #bbf7d0;color:#166534;padding:12px 14px;border-radius:10px;">✓ ' + result.message + '</div>';
            } else {
                box.innerHTML = '<div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:12px 14px;border-radius:10px;">' + result.message +
                    (result.debug ? '<pre style="white-space:pre-wrap;font-size:11px;margin-top:8px;max-height:220px;overflow:auto;">' + result.debug.replace(/</g, '&lt;') + '</pre>' : '') + '</div>';
            }
        } catch (e) {
            box.innerHTML = '<div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:12px 14px;border-radius:10px;">Network error: ' + e.message + '</div>';
        }

        button.disabled = false;
        button.textContent = 'Send test email';
    });
    </script>
</body>
</html>

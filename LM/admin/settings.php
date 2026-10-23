<?php
require_once __DIR__ . '/../includes/security.php';
lm_session_start();
lm_security_headers();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit();
}

require_once('../db.php');
require_once('../includes/mailer.php');
require_once('../includes/updates.php');
require_once('../includes/guide.php');
require_once('../includes/github.php');

$settings_file = '../settings.json';
$settings = lm_settings(true);
$secretHealth = lm_secret_health();
$saveError = '';
$saveOk = isset($_GET['saved']);

$restoreNotice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_backup'])) {
    if (!lm_verify_csrf()) {
        $restoreNotice = 'error:Your session expired. Refresh the page and try again.';
    } else {
        $restored = lm_settings_restore((string) $_POST['restore_backup']);
        if ($restored['ok']) {
            header('Location: settings.php?restored=1');
            exit();
        }
        $restoreNotice = 'error:' . $restored['error'];
    }
}

$saveScope = isset($_POST['save_scope']) ? preg_replace('/[^a-z_]/', '', (string) $_POST['save_scope']) : 'all';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!lm_verify_csrf()) {
        $saveError = 'Your session expired. Refresh the page and try again.';
    } elseif ($saveScope === 'email_help') {
        // Wording of the "how to update" block inside the customer e-mail.
        // Blank falls back to the built-in text, which follows the project.
        if (lm_update_setting(array(
            'update_instructions_admin' => trim((string) ($_POST['update_instructions_admin'] ?? '')),
            'update_instructions_cron' => trim((string) ($_POST['update_instructions_cron'] ?? '')),
        ))) {
            header('Location: settings.php?saved=email_help#email-help');
            exit();
        }
        $saveError = 'Could not write settings.json. Check the file permissions (0640) on the LM folder.';
    } elseif ($saveScope === 'storage') {
        // Dedicated button on the Update Storage card: only these two fields
        // are touched, so a half-filled form elsewhere cannot fail the save.
        $newStorageDir = trim((string) ($_POST['updates_storage_dir'] ?? ''));
        $newMaxMb = max(1, min(4096, (int) ($_POST['max_update_size_mb'] ?? 128)));
        $storageCheck = lm_updates_storage_check($newStorageDir, true);

        if (!$storageCheck['ok']) {
            $saveError = 'Storage not saved. ' . $storageCheck['error'];
        } elseif (lm_update_setting(array(
            'updates_storage_dir' => $newStorageDir,
            'max_update_size_mb' => $newMaxMb,
        ))) {
            header('Location: settings.php?saved=storage#update-storage');
            exit();
        } else {
            $saveError = 'Could not write settings.json. Check the file permissions (0640) on the LM folder.';
        }
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

        // Shared GitHub token: write-only field. Blank keeps whatever is on disk
        // right now — not the snapshot taken when the page loaded, otherwise the
        // "Save token" button would be undone by the big form's Save Settings.
        $tokenOnDisk = lm_settings(true);
        $new['github_token'] = (string) ($tokenOnDisk['github_token'] ?? '');
        $new['github_token_saved_at'] = (string) ($tokenOnDisk['github_token_saved_at'] ?? '');
        $incomingGithubToken = trim((string) ($_POST['github_token'] ?? ''));

        if ($incomingGithubToken !== '') {
            // A freshly pasted token wins over the "remove" tick.
            if (strlen($incomingGithubToken) < 20) {
                $saveError = 'That does not look like a GitHub token. Paste the full token (ghp_… or github_pat_…).';
            } elseif ($secretHealth['ok'] === false) {
                $saveError = 'GitHub token not saved. ' . $secretHealth['message'];
            } else {
                $cipher = lm_encrypt($incomingGithubToken);
                if ($cipher === '' || lm_decrypt($cipher) !== $incomingGithubToken) {
                    $saveError = 'GitHub token not saved. It could not be encrypted consistently — check that '
                        . 'LM/storage is writable and LM/storage/app_secret.key is readable.';
                } else {
                    $new['github_token'] = $cipher;
                    $new['github_token_saved_at'] = date('c');
                }
            }
        } elseif (!empty($_POST['clear_github_token'])) {
            $new['github_token'] = '';
            $new['github_token_saved_at'] = '';
        }

        $whitelistedIps = $_POST['whitelisted_ips'] ?? '';
        $new['whitelisted_ips'] = array_values(array_filter(array_map('trim', explode(',', (string) $whitelistedIps))));

        // Only these peers may set X-Forwarded-For. Blank = trust nobody.
        $trustedProxies = trim((string) ($_POST['trusted_proxies'] ?? ''));
        $proxyList = array_values(array_filter(array_map('trim', explode(',', $trustedProxies))));
        foreach ($proxyList as $proxy) {
            if (strpos($proxy, '/') === false && filter_var($proxy, FILTER_VALIDATE_IP) === false) {
                $saveError = 'Trusted proxies must be IP addresses or CIDR ranges (for example 10.0.0.0/8).';
                break;
            }
            if (strpos($proxy, '/') !== false) {
                list($net, $bits) = explode('/', $proxy, 2);
                if (filter_var($net, FILTER_VALIDATE_IP) === false || !ctype_digit($bits)) {
                    $saveError = 'Trusted proxies must be IP addresses or CIDR ranges (for example 10.0.0.0/8).';
                    break;
                }
            }
        }
        $new['trusted_proxies'] = implode(', ', $proxyList);

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
            $extension = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));

            // A logo is an image. Accepting any extension here would let a
            // .php file be written into the web root and requested directly.
            $allowedExtensions = array('png', 'jpg', 'jpeg', 'gif', 'webp', 'svg');

            if (!in_array($extension, $allowedExtensions, true)) {
                $saveError = 'The logo must be a PNG, JPG, GIF, WebP or SVG image.';
            } elseif ((int) $_FILES['site_logo']['size'] > 2097152) {
                $saveError = 'The logo must be smaller than 2 MB.';
            } elseif ($extension === 'svg' && preg_match('/<script|onload=|onerror=/i', (string) file_get_contents($_FILES['site_logo']['tmp_name'])) === 1) {
                // An SVG is XML: it can carry script that runs on the admin origin.
                $saveError = 'That SVG contains script and cannot be used as a logo.';
            } else {
                $safeName = 'logo-' . bin2hex(random_bytes(6)) . '.' . $extension;
                if (move_uploaded_file($_FILES['site_logo']['tmp_name'], $targetDir . $safeName)) {
                    $new['site_logo'] = 'uploads/' . $safeName;
                } else {
                    $saveError = 'The logo could not be written to uploads/. Check the folder permissions.';
                }
            }
        }

        if ($saveError === '') {
            if (lm_save_settings($new)) {
                header('Location: settings.php?saved=1');
                exit();
            }
            $saveError = 'Could not write settings.json. Check the file permissions (0640) on the LM folder.';
        }
    }

    $settings = lm_settings(true);
}

$gitHubTokenState = lm_github_default_token_state();
$sharedGithubMasked = $gitHubTokenState['masked'];
$sharedGithubSavedAt = '';
if (!empty($gitHubTokenState['saved_at'])) {
    $stamp = strtotime((string) $gitHubTokenState['saved_at']);
    $sharedGithubSavedAt = $stamp ? date('j M Y, H:i', $stamp) : (string) $gitHubTokenState['saved_at'];
}
$githubProjectsUsingDefault = 0;
try {
    $stmtDefaultProjects = $pdo->query(
        "SELECT COUNT(*) FROM script_tiers WHERE github_repo <> '' AND (github_token IS NULL OR github_token = '')"
    );
    $githubProjectsUsingDefault = (int) $stmtDefaultProjects->fetchColumn();
} catch (Exception $e) {
    $githubProjectsUsingDefault = 0;
}

$settingsFilePath = lm_settings_file();
$settingsFileExists = is_file($settingsFilePath);
$settingsFileMtime = $settingsFileExists ? (int) filemtime($settingsFilePath) : 0;
$settingsFileSize = $settingsFileExists ? (int) filesize($settingsFilePath) : 0;
$keyFilePath = lm_storage_dir() . DIRECTORY_SEPARATOR . 'app_secret.key';
$keyFileMtime = is_file($keyFilePath) ? (int) filemtime($keyFilePath) : 0;
$keyFingerprint = lm_secret_fingerprint(lm_app_secret());
$archivedKeyCount = count(lm_archived_secrets());
$settingsBackups = lm_settings_backups(8);

// Does a snapshot still hold a token this install can read?
$recoverableBackups = array();
foreach ($settingsBackups as $backup) {
    if ($backup['token_ok'] && $gitHubTokenState['state'] !== 'ok') {
        $recoverableBackups[] = $backup;
    }
}

$currentFilled = 0;
foreach ($settings as $value) {
    if (is_string($value) ? trim($value) !== '' : !empty($value)) {
        $currentFilled++;
    }
}

$storageCheck = lm_updates_storage_check((string) ($settings['updates_storage_dir'] ?? ''), false);
$uploadMaxBytes = lm_ini_bytes((string) ini_get('upload_max_filesize'));
$postMaxBytes = lm_ini_bytes((string) ini_get('post_max_size'));
$configuredMaxBytes = (int) ($settings['max_update_size_mb'] ?? 128) * 1024 * 1024;
$phpUploadLimit = min(
    $uploadMaxBytes > 0 ? $uploadMaxBytes : PHP_INT_MAX,
    $postMaxBytes > 0 ? $postMaxBytes : PHP_INT_MAX
);
$phpLimitBites = $phpUploadLimit !== PHP_INT_MAX && $configuredMaxBytes > $phpUploadLimit;
$storageSize = lm_updates_storage_size();
$savedScope = isset($_GET['saved']) ? (string) $_GET['saved'] : '';

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
<?php require __DIR__ . '/_mobile_nav.php'; ?>
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

        <?php if (isset($_GET['restored'])): ?>
            <div style="background:#ecfdf5;border:1px solid #bbf7d0;color:#166534;padding:14px 18px;border-radius:14px;margin-bottom:20px;font-size:14px;font-weight:600;">✓ Settings restored from a backup.</div>
        <?php endif; ?>
        <?php if ($restoreNotice !== ''): ?>
            <?php $isError = strpos($restoreNotice, 'error:') === 0; ?>
            <div style="background:<?= $isError ? '#fef2f2;border:1px solid #fecaca;color:#991b1b' : '#ecfdf5;border:1px solid #bbf7d0;color:#166534' ?>;padding:14px 18px;border-radius:14px;margin-bottom:20px;font-size:14px;font-weight:600;">
                <?= htmlspecialchars(preg_replace('/^error:/', '', $restoreNotice)) ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($recoverableBackups)): ?>
            <div style="background:#fffbeb;border:1px solid #fde68a;color:#92400e;padding:16px 18px;border-radius:14px;margin-bottom:20px;font-size:14px;line-height:1.65;">
                <strong>⚠ Your stored settings were replaced, probably by an update.</strong><br>
                <code>settings.json</code> no longer contains a GitHub token, but a backup from
                <strong><?= htmlspecialchars(date('j M Y, H:i', $recoverableBackups[0]['time'])) ?></strong> does — with a token this install can still decrypt.
                Restoring brings back the token, the site URL and the cron key.
                <form method="POST" style="margin-top:12px;"
                      onsubmit="return confirm('Restore the settings from <?= htmlspecialchars(date('j M Y, H:i', $recoverableBackups[0]['time'])) ?>?');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(lm_csrf_token()) ?>">
                    <input type="hidden" name="restore_backup" value="<?= htmlspecialchars($recoverableBackups[0]['name']) ?>">
                    <button type="submit" class="btn" style="background:linear-gradient(135deg,#b45309,#92400e);">Restore that backup</button>
                </form>

            </div>
        <?php endif; ?>
        <?php if ($savedScope === 'storage'): ?>
            <div style="background:#ecfdf5;border:1px solid #bbf7d0;color:#166534;padding:14px 18px;border-radius:14px;margin-bottom:20px;font-size:14px;font-weight:600;">
                ✓ Storage settings saved — the new limit applies to the next upload or GitHub pull.
            </div>
        <?php elseif ($savedScope === 'email_help'): ?>
            <div style="background:#ecfdf5;border:1px solid #bbf7d0;color:#166534;padding:14px 18px;border-radius:14px;margin-bottom:20px;font-size:14px;font-weight:600;">
                ✓ Email instructions saved — the next update email uses this wording.
            </div>
        <?php elseif ($saveOk): ?>
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
                            <div class="input-group wide-input">
                                <label>Trusted proxies (comma-separated IPs or CIDR ranges)</label>
                                <input type="text" name="trusted_proxies" value="<?= htmlspecialchars((string) ($settings['trusted_proxies'] ?? '')) ?>" placeholder="Leave empty unless you are behind Cloudflare, a load balancer or a reverse proxy">
                                <small>
                                    Only these addresses are allowed to set <code>X-Forwarded-For</code>. Leave it empty and the client address is always
                                    <code>REMOTE_ADDR</code> — which is what stops a visitor from claiming to be one of the whitelisted IPs above.
                                    Behind Cloudflare use their published ranges, e.g. <code>173.245.48.0/20, 103.21.244.0/22</code>.
                                </small>
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

                <div class="card" id="update-storage">
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
                                <input type="number" min="1" max="4096" name="max_update_size_mb" value="<?= (int) ($settings['max_update_size_mb'] ?? 128) ?>">
                                <small>Applies to uploads and GitHub archives. 512 MB is fine for a monorepo.</small>
                            </div>
                        </div>

                        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:16px;padding-top:16px;border-top:1px solid #eef2f8;">
                            <button type="submit" name="save_scope" value="storage" class="btn"
                                    style="background:linear-gradient(135deg,#0ea5e9,#2563eb);">
                                💾 Save storage settings
                            </button>
                            <span style="font-size:12.5px;color:#64748b;">
                                Saves only this card — the rest of the page is left untouched.
                            </span>
                        </div>

                        <?php if ($phpLimitBites): ?>
                            <div style="margin-top:14px;background:#fffbeb;border:1px solid #fde68a;color:#92400e;padding:12px 14px;border-radius:10px;font-size:12.5px;line-height:1.6;">
                                <strong>Headroom check:</strong> PHP accepts at most <strong><?= lm_format_bytes($phpUploadLimit) ?></strong> per
                                upload request (<code>post_max_size</code> <?= htmlspecialchars(ini_get('post_max_size')) ?>,
                                <code>upload_max_filesize</code> <?= htmlspecialchars(ini_get('upload_max_filesize')) ?>).
                                The <?= (int) ($settings['max_update_size_mb'] ?? 128) ?> MB limit is above that, so a manual upload that big
                                will be rejected before it reaches the LM. Raise both values in cPanel → MultiPHP INI Editor, or publish
                                through <a href="github-updates.php" style="color:#92400e;font-weight:700;">GitHub pull</a>, which streams
                                the archive to disk and is not bound by the upload limits.
                            </div>
                        <?php endif; ?>

                        <div style="margin-top:14px;display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:10px;">
                            <div style="border:1px solid <?= $storageCheck['ok'] ? '#e2e8f0' : '#fecaca' ?>;border-radius:10px;padding:10px 12px;background:<?= $storageCheck['ok'] ? '#f8fafc' : '#fef2f2' ?>;">
                                <div style="font-size:10.5px;letter-spacing:.8px;text-transform:uppercase;color:#94a3b8;font-weight:800;">Effective folder</div>
                                <div style="font-size:12px;color:<?= $storageCheck['ok'] ? '#0f172a' : '#991b1b' ?>;margin-top:4px;word-break:break-all;">
                                    <?= htmlspecialchars($storageCheck['path']) ?>
                                </div>
                                <?php if (!$storageCheck['ok']): ?>
                                    <div style="font-size:11.5px;color:#991b1b;margin-top:6px;line-height:1.5;"><?= htmlspecialchars($storageCheck['error']) ?></div>
                                <?php endif; ?>
                            </div>
                            <div style="border:1px solid #e2e8f0;border-radius:10px;padding:10px 12px;background:#f8fafc;">
                                <div style="font-size:10.5px;letter-spacing:.8px;text-transform:uppercase;color:#94a3b8;font-weight:800;">Space</div>
                                <div style="font-size:12px;color:#0f172a;margin-top:4px;">
                                    <strong><?= (int) $storageSize['files'] ?></strong> package<?= (int) $storageSize['files'] === 1 ? '' : 's' ?>
                                    · <?= lm_format_bytes((int) $storageSize['bytes']) ?> used
                                </div>
                                <div style="font-size:11.5px;color:#64748b;margin-top:4px;">
                                    <?= $storageCheck['free'] > 0 ? lm_format_bytes($storageCheck['free']) . ' free on the disk' : 'Free space unavailable' ?>
                                </div>
                            </div>
                            <div style="border:1px solid #e2e8f0;border-radius:10px;padding:10px 12px;background:#f8fafc;">
                                <div style="font-size:10.5px;letter-spacing:.8px;text-transform:uppercase;color:#94a3b8;font-weight:800;">Active limit</div>
                                <div style="font-size:12px;color:#0f172a;margin-top:4px;">
                                    <strong><?= (int) ($settings['max_update_size_mb'] ?? 128) ?> MB</strong> per package
                                </div>
                                <div style="font-size:11.5px;color:#64748b;margin-top:4px;">
                                    Uploads, GitHub archives and pulls.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card" id="github">
                    <div class="card-header"><h3>GitHub Access Token</h3></div>
                    <div class="card-body">
                        <p style="color:#475569;font-size:14px;line-height:1.7;margin-bottom:14px;">
                            Create <strong>one</strong> token for the whole installation. Every project without its own
                            token uses this one, so a new project only needs a repository name.
                            A <em>classic</em> token with the <code>repo</code> scope, or a <em>fine-grained</em> token with
                            repository access set to <strong>All repositories</strong>, sees every repository you own —
                            including ones you create later.
                        </p>

                        <div id="ghTokenState" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;padding:12px 14px;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc;margin-bottom:14px;">
                            <?php if ($gitHubTokenState['state'] === 'ok'): ?>
                                <span style="display:inline-flex;align-items:center;gap:6px;background:#ecfdf5;color:#047857;padding:5px 11px;border-radius:999px;font-size:12px;font-weight:800;">✓ Token stored</span>
                                <code id="ghTokenMasked" style="font-size:12px;color:#0f172a;"><?= htmlspecialchars($sharedGithubMasked) ?></code>
                                <span style="font-size:12px;color:#64748b;" id="ghTokenMeta">
                                    <?= $sharedGithubSavedAt !== '' ? 'saved ' . htmlspecialchars($sharedGithubSavedAt) : '' ?>
                                    <?= $githubProjectsUsingDefault > 0 ? ' · used by ' . (int) $githubProjectsUsingDefault . ' project' . ($githubProjectsUsingDefault === 1 ? '' : 's') : '' ?>
                                </span>
                            <?php elseif ($gitHubTokenState['state'] === 'unreadable'): ?>
                                <span style="display:inline-flex;align-items:center;gap:6px;background:#fef2f2;color:#b91c1c;padding:5px 11px;border-radius:999px;font-size:12px;font-weight:800;">⚠ Token stored but unreadable</span>
                                <span style="font-size:12px;color:#991b1b;line-height:1.6;" id="ghTokenMeta">
                                    No key on this install can open the stored value — not <code>storage/app_secret.key</code> and not any archived key.
                                    Paste the token again and press <strong>Save token</strong>.
                                    <?php if (!empty($recoverableBackups)): ?>A backup below still holds a readable token.<?php endif; ?>
                                </span>
                            <?php else: ?>
                                <span style="display:inline-flex;align-items:center;gap:6px;background:#f1f5f9;color:#475569;padding:5px 11px;border-radius:999px;font-size:12px;font-weight:800;">No token stored</span>
                                <span style="font-size:12px;color:#64748b;" id="ghTokenMeta">Only public repositories work until you add one. Leave the field below empty if you do not need private repos.</span>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($gitHubTokenState['healed'])): ?>
                            <div style="background:#ecfdf5;border:1px solid #bbf7d0;color:#166534;padding:12px 14px;border-radius:10px;font-size:13px;line-height:1.6;margin-bottom:14px;">
                                <strong>Token recovered.</strong>
                                It was encrypted with a key this install no longer uses, so it has been decrypted with the archived key
                                and re-encrypted with the current one. Nothing to do.
                            </div>
                        <?php endif; ?>

                        <?php if (!$secretHealth['ok']): ?>
                            <div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:12px 14px;border-radius:10px;font-size:13px;line-height:1.6;margin-bottom:14px;">
                                <strong>Encryption key problem:</strong> <?= htmlspecialchars($secretHealth['message']) ?>
                                Tokens cannot be stored safely until this is fixed.
                            </div>
                        <?php endif; ?>

                        <div class="input-group wide-input">
                            <label for="githubTokenDefault">Personal access token</label>
                            <div style="display:flex;gap:8px;align-items:stretch;flex-wrap:wrap;">
                                <input type="password" id="githubTokenDefault" name="github_token" value=""
                                       autocomplete="new-password" placeholder="ghp_… or github_pat_…"
                                       style="flex:1;min-width:220px;">
                                <button type="button" class="btn" id="ghRevealBtn" title="Show or hide the token"
                                        style="background:#fff;border:1px solid #cbd5e1;color:#334155;box-shadow:none;">👁 Show</button>
                                <button type="button" class="btn" id="ghCopyBtn" title="Copy the token"
                                        style="background:#fff;border:1px solid #cbd5e1;color:#334155;box-shadow:none;">📋 Copy</button>
                            </div>
                            <small>
                                The field is write-only: leave it empty to keep the stored token.
                                <strong>Show</strong> reveals the saved token so you can copy it; it hides again after 60 seconds.
                            </small>
                        </div>

                        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:16px;">
                            <button type="button" class="btn" id="ghSaveTokenBtn" style="background:linear-gradient(135deg,#4f46e5,#3730a3);">Save token</button>
                            <button type="button" class="btn" id="testGithubTokenBtn" style="background:linear-gradient(135deg,#0f172a,#334155);">Test token</button>
                            <button type="button" class="btn" id="ghRemoveTokenBtn"
                                    style="background:#fff;border:1px solid #fecaca;color:#b91c1c;box-shadow:none;">Remove</button>
                            <a href="github-updates.php" style="color:#4f46e5;font-weight:600;font-size:13px;">Open GitHub Pull →</a>
                        </div>
                        <div id="githubTokenResult" style="margin-top:12px;font-size:13px;line-height:1.65;"></div>
                        <small style="display:block;margin-top:8px;color:#94a3b8;">
                            <strong>Save token</strong> stores this one value immediately and verifies it can be read back.
                            The main <em>Save Settings</em> button at the bottom saves it too — the two do not conflict.
                        </small>
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
        <div style="margin-top:24px;">
                <div class="card" id="email-help">
                    <div class="card-header"><h3>Email update instructions</h3></div>
                    <div class="card-body">
                        <p style="color:#475569;font-size:14px;line-height:1.7;margin-bottom:14px;">
                            Every update email tells the customer how to install it: from their admin dashboard, or
                            with a daily cron job in cPanel / Plesk / DirectAdmin. Leave a box empty to use the built-in
                            wording; fill it in when a project names its screens differently.
                        </p>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(lm_csrf_token()) ?>">
                            <input type="hidden" name="save_scope" value="email_help">
                            <div class="input-group wide-input">
                                <label>How to update from the admin dashboard</label>
                                <textarea name="update_instructions_admin" rows="4" style="font-size:13.5px;line-height:1.6;"><?= htmlspecialchars((string) ($settings['update_instructions_admin'] ?? '')) ?></textarea>
                                <small style="color:#64748b;font-size:12px;">Plain text. Example: Sign in to the super admin, open System Update Centre, press Install Update Automatically.</small>
                            </div>
                            <div class="input-group wide-input">
                                <label>How to automate it from the control panel</label>
                                <textarea name="update_instructions_cron" rows="4" style="font-size:13.5px;line-height:1.6;"><?= htmlspecialchars((string) ($settings['update_instructions_cron'] ?? '')) ?></textarea>
                                <small style="color:#64748b;font-size:12px;">Plain text. The command itself is always included from the project's own cron job.</small>
                            </div>
                            <div class="form-footer" style="margin-top:4px;">
                                <button type="submit" class="btn">Save email instructions</button>
                            </div>
                        </form>
                    </div>
                </div>
                <div class="card" id="safety">
                    <div class="card-header"><h3>Settings File &amp; Recovery</h3></div>
                    <div class="card-body">
                        <p style="color:#475569;font-size:14px;line-height:1.7;margin-bottom:14px;">
                            Everything on this page — the GitHub token, SMTP password, site URL and cron key — lives in
                            <code>settings.json</code>. It is <strong>not</strong> part of the LM code, so uploading a new
                            version must never replace it. A snapshot is written to <code>storage/backups/</code> every time it changes.
                        </p>

                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;margin-bottom:16px;">
                            <div style="border:1px solid #e2e8f0;border-radius:12px;padding:12px 14px;background:#f8fafc;">
                                <div style="font-size:11px;letter-spacing:.8px;text-transform:uppercase;color:#94a3b8;font-weight:800;">settings.json</div>
                                <div style="font-size:13px;margin-top:6px;color:#0f172a;">
                                    <?= $settingsFileExists ? htmlspecialchars(date('j M Y, H:i', $settingsFileMtime)) : 'missing' ?>
                                    <?= $settingsFileExists ? '<span style="color:#94a3b8;">· ' . number_format($settingsFileSize) . ' bytes · ' . (int) $currentFilled . ' fields</span>' : '' ?>
                                </div>
                                <div style="font-size:11.5px;color:#64748b;margin-top:4px;word-break:break-all;"><?= htmlspecialchars($settingsFilePath) ?></div>
                            </div>
                            <div style="border:1px solid #e2e8f0;border-radius:12px;padding:12px 14px;background:#f8fafc;">
                                <div style="font-size:11px;letter-spacing:.8px;text-transform:uppercase;color:#94a3b8;font-weight:800;">Encryption key</div>
                                <div style="font-size:13px;margin-top:6px;color:#0f172a;">
                                    <code><?= htmlspecialchars($keyFingerprint) ?></code>
                                    <span style="color:#94a3b8;"><?= $keyFileMtime ? '· ' . htmlspecialchars(date('j M Y', $keyFileMtime)) : '' ?></span>
                                </div>
                                <div style="font-size:11.5px;color:#64748b;margin-top:4px;">
                                    <?= $archivedKeyCount ?> archived key<?= $archivedKeyCount === 1 ? '' : 's' ?> available for recovery
                                </div>
                            </div>
                        </div>

                        <?php if (empty($settingsBackups)): ?>
                            <p style="font-size:12.5px;color:#64748b;">No snapshots yet — one is created the next time settings are saved.</p>
                        <?php else: ?>
                            <div style="border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;">
                                <table style="width:100%;border-collapse:collapse;font-size:12.5px;">
                                    <thead>
                                        <tr style="background:#f8fafc;color:#64748b;font-size:10.5px;text-transform:uppercase;letter-spacing:.8px;">
                                            <th style="text-align:left;padding:9px 12px;">Snapshot</th>
                                            <th style="text-align:left;padding:9px 12px;">Contains</th>
                                            <th style="text-align:right;padding:9px 12px;">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($settingsBackups as $index => $backup): ?>
                                        <tr style="border-top:1px solid #eef2f8;">
                                            <td style="padding:9px 12px;color:#0f172a;">
                                                <?= htmlspecialchars(date('j M Y, H:i', $backup['time'])) ?>
                                                <span style="color:#94a3b8;font-size:11px;">· <?= number_format($backup['size']) ?> B</span>
                                            </td>
                                            <td style="padding:9px 12px;color:#475569;">
                                                <?= (int) $backup['fields'] ?> fields
                                                <?php if ($backup['token_ok']): ?>
                                                    <span style="background:#ecfdf5;color:#047857;padding:2px 8px;border-radius:999px;font-size:10.5px;font-weight:800;margin-left:6px;">token ✓</span>
                                                <?php elseif ($backup['has_token']): ?>
                                                    <span style="background:#fef2f2;color:#b91c1c;padding:2px 8px;border-radius:999px;font-size:10.5px;font-weight:800;margin-left:6px;">token unreadable</span>
                                                <?php endif; ?>
                                                <?php if ($backup['has_cron']): ?>
                                                    <span style="background:#eef2ff;color:#4338ca;padding:2px 8px;border-radius:999px;font-size:10.5px;font-weight:800;margin-left:4px;">cron key</span>
                                                <?php endif; ?>
                                                <?php if ($backup['smtp_host'] !== ''): ?>
                                                    <span style="background:#f1f5f9;color:#475569;padding:2px 8px;border-radius:999px;font-size:10.5px;font-weight:800;margin-left:4px;">SMTP</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="padding:9px 12px;text-align:right;">
                                                <form method="POST" style="display:inline;"
                                                      onsubmit="return confirm('Restore settings from <?= htmlspecialchars(date('j M Y, H:i', $backup['time'])) ?>?\n\nThe current file is copied to storage/backups first, so this can be undone.');">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(lm_csrf_token()) ?>">
                                                    <input type="hidden" name="restore_backup" value="<?= htmlspecialchars($backup['name']) ?>">
                                                    <button type="submit" class="btn" style="background:#fff;border:1px solid #cbd5e1;color:#334155;box-shadow:none;padding:6px 12px;font-size:12px;">Restore</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <small style="display:block;margin-top:10px;color:#94a3b8;">
                                Restoring merges the snapshot over the current values — fields you changed since then are kept unless the snapshot has them too.
                                The current file is snapshotted first, so a restore can be undone.
                            </small>
                        <?php endif; ?>
                    </div>
                </div>
        </div>

    </div>

    <script>
    /* ---------------- shared GitHub token ---------------- */
    const ghToken = {
        field: document.getElementById('githubTokenDefault'),
        revealBtn: document.getElementById('ghRevealBtn'),
        copyBtn: document.getElementById('ghCopyBtn'),
        saveBtn: document.getElementById('ghSaveTokenBtn'),
        removeBtn: document.getElementById('ghRemoveTokenBtn'),
        result: document.getElementById('githubTokenResult'),
        stateBox: document.getElementById('ghTokenState'),
        hideTimer: null,
        revealedFromServer: false
    };

    function ghTokenShow(kind, message, extra) {
        const palette = {
            ok: 'background:#ecfdf5;border:1px solid #bbf7d0;color:#166534;',
            err: 'background:#fef2f2;border:1px solid #fecaca;color:#991b1b;',
            info: 'background:#eef2ff;border:1px solid #dbe3ff;color:#3730a3;'
        };
        ghToken.result.innerHTML = '<div style="' + (palette[kind] || palette.info) + 'padding:12px 14px;border-radius:10px;">'
            + message + (extra ? '<div style="margin-top:6px;font-size:12.5px;">' + extra + '</div>' : '') + '</div>';
    }

    function ghTokenMaskForDisplay() {
        const value = ghToken.field.value.trim();
        if (value.length < 14) return value;
        return value.slice(0, 7) + '…' + value.slice(-4);
    }

    function ghTokenStateLine(state, masked, savedAt, projects) {
        const box = ghToken.stateBox;
        if (!box) return;

        if (state === 'ok') {
            box.innerHTML = '<span style="display:inline-flex;align-items:center;gap:6px;background:#ecfdf5;color:#047857;padding:5px 11px;border-radius:999px;font-size:12px;font-weight:800;">✓ Token stored</span>'
                + '<code style="font-size:12px;color:#0f172a;">' + masked + '</code>'
                + '<span style="font-size:12px;color:#64748b;">'
                + (savedAt ? 'saved ' + savedAt : '')
                + (projects ? ' · used by ' + projects + ' project' + (projects === 1 ? '' : 's') : '')
                + '</span>';
        } else if (state === 'unreadable') {
            box.innerHTML = '<span style="display:inline-flex;align-items:center;gap:6px;background:#fef2f2;color:#b91c1c;padding:5px 11px;border-radius:999px;font-size:12px;font-weight:800;">⚠ Token stored but unreadable</span>'
                + '<span style="font-size:12px;color:#991b1b;">The encryption key changed. Paste the token again and press Save token.</span>';
        } else {
            box.innerHTML = '<span style="display:inline-flex;align-items:center;gap:6px;background:#f1f5f9;color:#475569;padding:5px 11px;border-radius:999px;font-size:12px;font-weight:800;">No token stored</span>'
                + '<span style="font-size:12px;color:#64748b;">Only public repositories work until you add one.</span>';
        }
    }

    function ghTokenHide(clearServerValue) {
        ghToken.field.type = 'password';
        ghToken.revealBtn.textContent = '👁 Show';
        if (clearServerValue && ghToken.revealedFromServer) {
            ghToken.field.value = '';
        }
        ghToken.revealedFromServer = false;
        if (ghToken.hideTimer) {
            clearTimeout(ghToken.hideTimer);
            ghToken.hideTimer = null;
        }
    }

    function ghTokenScheduleHide() {
        if (ghToken.hideTimer) clearTimeout(ghToken.hideTimer);
        ghToken.hideTimer = setTimeout(function () {
            ghTokenHide(true);
            ghTokenShow('info', 'Hidden again. Press <strong>Show</strong> to reveal the saved token.');
        }, 60000);
    }

    async function ghFetch(action, payload) {
        const body = new FormData();
        body.append('action', action);
        body.append('csrf_token', <?= json_encode(lm_csrf_token()) ?>);
        Object.keys(payload || {}).forEach(key => body.append(key, payload[key]));

        const response = await fetch('ajax_github_token.php', { method: 'POST', body: body, credentials: 'same-origin' });
        const text = await response.text();
        try {
            return JSON.parse(text);
        } catch (e) {
            return { status: 'error', message: 'Unexpected response: ' + text.slice(0, 200) };
        }
    }

    ghToken.revealBtn.addEventListener('click', async function () {
        const button = this;

        // Hiding
        if (ghToken.field.type === 'text') {
            ghTokenHide(true);
            ghTokenShow('info', 'Token hidden.');
            return;
        }

        // Reveal whatever is typed but not saved yet
        if (ghToken.field.value.trim() !== '') {
            ghToken.field.type = 'text';
            button.textContent = '🙈 Hide';
            ghTokenScheduleHide();
            return;
        }

        button.disabled = true;
        button.textContent = '…';
        const result = await ghFetch('reveal', {});
        button.disabled = false;

        if (result.status === 'error') {
            button.textContent = '👁 Show';
            ghTokenShow('err', result.message);
            ghTokenStateLine('unreadable', '', '', 0);
            return;
        }

        ghToken.field.value = result.token;
        ghToken.field.type = 'text';
        ghToken.revealedFromServer = true;
        button.textContent = '🙈 Hide';
        ghTokenScheduleHide();
        ghTokenShow('info', 'Saved token revealed: <code>' + result.masked + '</code>'
            + (result.saved_at ? ' <span style="color:#64748b;">(saved ' + result.saved_at + ')</span>' : '')
            + '. It hides again after 60 seconds.');
    });

    ghToken.copyBtn.addEventListener('click', async function () {
        const button = this;

        button.disabled = true;
        let value = ghToken.field.value.trim();

        if (value === '') {
            const result = await ghFetch('reveal', {});
            if (result.status === 'error') {
                button.disabled = false;
                ghTokenShow('err', result.message);
                return;
            }
            value = result.token;
        }

        try {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                await navigator.clipboard.writeText(value);
            } else {
                const scratch = document.createElement('textarea');
                scratch.value = value;
                scratch.style.position = 'fixed';
                scratch.style.opacity = '0';
                document.body.appendChild(scratch);
                scratch.select();
                document.execCommand('copy');
                document.body.removeChild(scratch);
            }
            button.textContent = '✓ Copied';
            ghTokenShow('ok', 'Token copied to the clipboard.');
        } catch (e) {
            ghTokenShow('err', 'The browser blocked clipboard access. Press <strong>Show</strong> and copy the value manually.');
        }

        setTimeout(function () { button.textContent = '📋 Copy'; button.disabled = false; }, 1600);
    });

    ghToken.saveBtn.addEventListener('click', async function () {
        const button = this;
        const value = ghToken.field.value.trim();

        if (value === '') {
            ghTokenShow('err', 'Paste the token into the field first.');
            ghToken.field.focus();
            return;
        }

        button.disabled = true;
        button.textContent = 'Saving…';
        const result = await ghFetch('save', { token: value });
        button.disabled = false;
        button.textContent = 'Save token';

        if (result.status === 'error') {
            ghTokenShow('err', result.message);
            return;
        }

        ghTokenHide(false);
        ghToken.field.value = '';
        ghTokenShow('ok', '✓ ' + result.message, 'Stored value: <code>' + result.masked + '</code>');
        ghTokenStateLine(result.token_state, result.masked, result.saved_at, 0);
    });

    ghToken.removeBtn.addEventListener('click', async function () {
        if (!confirm('Remove the shared GitHub token? Projects with their own token keep working.')) return;

        const button = this;
        button.disabled = true;
        const result = await ghFetch('clear', {});
        button.disabled = false;

        if (result.status === 'error') {
            ghTokenShow('err', result.message);
            return;
        }

        ghTokenHide(true);
        ghToken.field.value = '';
        ghTokenShow('ok', '✓ ' + result.message);
        ghTokenStateLine('none', '', '', 0);
    });

    // The token field is write-only; a page reload clears it again.
    window.addEventListener('pageshow', function () { ghToken.field.type = 'password'; });

    document.getElementById('testGithubTokenBtn').addEventListener('click', async function () {
        const button = this;
        const typed = document.getElementById('githubTokenDefault').value.trim();
        const box = document.getElementById('githubTokenResult');
        button.disabled = true;
        button.textContent = 'Checking…';
        box.innerHTML = '<span style="color:#64748b;">Asking GitHub who this token belongs to…</span>';

        const body = new FormData();
        body.append('token', typed);
        body.append('csrf_token', <?= json_encode(lm_csrf_token()) ?>);

        try {
            const response = await fetch('ajax_github_token.php', { method: 'POST', body: body, credentials: 'same-origin' });
            const text = await response.text();
            let result;
            try { result = JSON.parse(text); } catch (e) { result = { status: 'error', message: 'Unexpected response: ' + text.slice(0, 300) }; }

            if (result.status === 'success') {
                const bits = [];
                if (result.login) bits.push('@' + result.login);
                if (result.kind) bits.push(result.kind + ' token');
                if (result.repositories !== null && result.repositories !== undefined) {
                    bits.push(result.repositories + ' repositories visible');
                }
                if (result.remaining !== null && result.remaining !== undefined) {
                    bits.push(result.remaining + '/' + result.limit + ' API calls left');
                }
                box.innerHTML = '<div style="background:#ecfdf5;border:1px solid #bbf7d0;color:#166534;padding:12px 14px;border-radius:10px;">✓ ' + result.message +
                    (bits.length ? '<div style="margin-top:6px;font-size:12.5px;">' + bits.join(' · ') + '</div>' : '') + '</div>';
            } else {
                box.innerHTML = '<div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:12px 14px;border-radius:10px;">' + result.message + '</div>';
            }
        } catch (e) {
            box.innerHTML = '<div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:12px 14px;border-radius:10px;">Network error: ' + e.message + '</div>';
        }

        button.disabled = false;
        button.textContent = 'Test token';
    });

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

<?php
// UpdateSystem.php
session_start();
include("../func/bc-spadmin-config.php");

// Force clear cache
if (isset($_SESSION)) {
    unset($_SESSION['super_admin_options_cache']);
}

$current_version = getSuperAdminOption('system_version', '7.00');
$license_key = getSuperAdminOption('license_key', '');
$license_domain = getSuperAdminOption('license_domain', $_SERVER['HTTP_HOST']);

// Check for updates from License Manager
$api_url = "https://manager.pmhserver.name.ng/check-update.php";
$latest_version = $current_version;
$changelog = "<li>Checking for updates...</li>";
$checksum = "";
$update_available = false;
$error_msg = "";

$force_version = trim($_GET['force_version'] ?? '');

$post_fields = [
    'license_key' => $license_key,
    'domain' => $license_domain
];
if (!empty($force_version)) {
    $post_fields['force_version'] = $force_version;
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $api_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_fields));
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code === 200 && !empty($response)) {
    $res_data = json_decode($response, true);
    if ($res_data && $res_data['status'] === 'success') {
        $latest_version = $res_data['latest_version'] ?? $current_version;
        $changelog = $res_data['changelog'] ?? '<li>Bug fixes and performance improvements.</li>';
        $checksum = $res_data['checksum'] ?? '';
        
        $clean_latest = ltrim(strtolower($latest_version), 'v');
        $clean_current = ltrim(strtolower($current_version), 'v');
        
        if (version_compare($clean_latest, $clean_current, '>') || !empty($force_version)) {
            $update_available = true;
        }
    } else {
        $error_msg = $res_data['message'] ?? 'Inactive or invalid license.';
    }
} else {
    $error_msg = 'Could not establish connection to the License Manager server.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>System Update Centre | Super Admin</title>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="/cssfile/bc-style.css">
    <style>
        .update-header-gradient {
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            color: white;
            border-radius: 1.5rem 1.5rem 0 0;
            padding: 2.5rem 2rem;
            text-align: center;
        }
        .icon-circle {
            width: 80px;
            height: 80px;
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            margin-bottom: 1rem;
            box-shadow: 0 8px 16px rgba(0,0,0,0.1);
        }
        .version-badge {
            padding: 0.5rem 1.2rem;
            border-radius: 50rem;
            font-weight: 700;
            font-size: 1.1rem;
        }
        .version-old { background: #f3f4f6; color: #6b7280; }
        .version-new { background: #dcfce7; color: #16a34a; box-shadow: 0 4px 10px rgba(22, 163, 74, 0.2); }
        .changelog-box {
            background: #f8fafc;
            border-radius: 1rem;
            border: 1px solid #e2e8f0;
            padding: 1.5rem;
        }
        .changelog-box ul {
            padding-left: 1.2rem;
            margin-bottom: 0;
        }
        .changelog-box li {
            margin-bottom: 0.5rem;
        }
        .stepper { list-style: none; padding: 0; margin: 0; }
        .step-item {
            display: flex;
            align-items: center;
            color: #9ca3af;
            margin-bottom: 1.2rem;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        .step-item i {
            font-size: 1.5rem;
            margin-right: 12px;
            transition: transform 0.3s ease;
        }
        .step-item.active { color: #4f46e5; }
        .step-item.active i { animation: pulseIcon 1.5s infinite; }
        .step-item.completed { color: #16a34a; }
        .step-item.error { color: #ef4444; }
        .btn-update {
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            border: none;
            border-radius: 1rem;
            padding: 1rem;
            font-size: 1.1rem;
            font-weight: 600;
            color: white;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .btn-update:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(124, 58, 237, 0.3);
            color: white;
        }
        .btn-update:disabled { opacity: 0.7; transform: none; box-shadow: none; }
        
        @keyframes pulseIcon {
            0% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.1); opacity: 0.7; }
            100% { transform: scale(1); opacity: 1; }
        }
        .spin-icon {
            display: inline-block;
            animation: spin 2s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
<?php include("../func/bc-spadmin-header.php"); ?>
<div class="pagetitle"><h1>System Update Centre</h1></div>

<section class="section">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            
            <?php if (!empty($error_msg)): ?>
                <div class="alert alert-danger rounded-4 shadow-sm p-4 mb-4">
                    <div class="d-flex align-items-center">
                        <i class="bi bi-exclamation-triangle-fill fs-3 me-3"></i>
                        <div>
                            <h5 class="fw-bold mb-1">Update Check Failed</h5>
                            <p class="mb-0 small"><?= htmlspecialchars($error_msg) ?></p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="card border-0 rounded-4 shadow-sm overflow-hidden mb-5">
                <div class="update-header-gradient">
                    <div class="icon-circle">
                        <i class="bi bi-cloud-arrow-down"></i>
                    </div>
                    <h3 class="fw-bold mb-1">Over-The-Air Update Engine</h3>
                    <p class="mb-0 text-white-50">DataGifting SaaS Platform Updater</p>
                </div>
                
                <div class="card-body p-4 p-md-5">
                    
                    <?php if ($update_available): ?>
                        
                        <!-- Version comparison -->
                        <div class="d-flex justify-content-center align-items-center mb-5">
                            <div class="text-center">
                                <span class="d-block small text-muted mb-1">Current Version</span>
                                <div class="version-badge version-old">v<?= htmlspecialchars(ltrim(strtolower($current_version), 'v')) ?></div>
                            </div>
                            <i class="bi bi-arrow-right fs-4 text-muted mx-4"></i>
                            <div class="text-center">
                                <span class="d-block small text-muted mb-1">Latest Version</span>
                                <div class="version-badge version-new">v<?= htmlspecialchars(ltrim(strtolower($latest_version), 'v')) ?></div>
                            </div>
                        </div>

                        <!-- Changelog -->
                        <div id="changelog-container" class="changelog-box mb-5">
                            <h6 class="fw-bold text-uppercase text-muted mb-3" style="font-size: 0.85rem; letter-spacing: 1px;">What's New in this release</h6>
                            <ul>
                                <?= $changelog ?>
                            </ul>
                        </div>

                        <!-- Stepper Progress Tracker (Hidden by default) -->
                        <div id="progress-container" class="d-none mb-5 p-4 border rounded-3 bg-light">
                            <h6 class="fw-bold text-muted text-uppercase mb-4" style="font-size: 0.85rem; letter-spacing: 1px;">Installation Progress</h6>
                            <ul class="stepper">
                                <li id="step-1" class="step-item">
                                    <i id="icon-step-1" class="bi bi-circle"></i>
                                    <span>Downloading Update Package...</span>
                                </li>
                                <li id="step-2" class="step-item">
                                    <i id="icon-step-2" class="bi bi-circle"></i>
                                    <span>Creating System Backup & Pruning Old Archives...</span>
                                </li>
                                <li id="step-3" class="step-item">
                                    <i id="icon-step-3" class="bi bi-circle"></i>
                                    <span>Extracting, Overwriting Files & Running Migrations...</span>
                                </li>
                            </ul>
                            <small class="text-danger mt-3 d-block"><i class="bi bi-exclamation-triangle-fill"></i> Please do not close, refresh, or navigate away from this page during the update process.</small>
                        </div>

                        <!-- Alert Box for Error / Success feedback -->
                        <div id="update-alert" class="alert d-none rounded-3 shadow-sm border-0 p-4 mb-4" role="alert"></div>

                        <!-- Trigger Button -->
                        <div class="form-check form-switch mb-3 d-flex justify-content-center align-items-center">
                            <input class="form-check-input me-2" type="checkbox" id="enable-backup" checked style="cursor: pointer; width: 2.5em; height: 1.25em;">
                            <label class="form-check-label text-muted" for="enable-backup" style="cursor: pointer; font-size: 0.95rem;">
                                Perform full system backup before updating
                            </label>
                        </div>
                        <button id="btn-start-update" class="btn btn-update w-100 py-3">
                            <i class="bi bi-cloud-arrow-down-fill me-2"></i> Install Update Automatically
                        </button>

                    <?php else: ?>

                        <!-- System is up to date -->
                        <div class="text-center py-5">
                            <div class="text-success mb-4" style="font-size: 5rem;">
                                <i class="bi bi-shield-check"></i>
                            </div>
                            <h4 class="fw-bold mb-2">System is Up to Date</h4>
                            <p class="text-muted mb-4">Your platform is running the latest version <strong>v<?= htmlspecialchars($current_version) ?></strong>. No updates are currently available.</p>
                            
                            <form method="GET" class="d-flex justify-content-center align-items-center gap-2 mx-auto mb-4" style="max-width: 350px;">
                                <input type="text" name="force_version" class="form-control rounded-pill px-3" placeholder="Force Version (e.g. 7.01)" required style="border-radius: 50rem; text-align: center;">
                                <button type="submit" class="btn btn-warning rounded-pill px-4 text-nowrap" style="border-radius: 50rem; color: #1e293b; font-weight: 600;">Force Check</button>
                            </form>
                            
                            <a href="Dashboard.php" class="btn btn-primary rounded-pill px-4 py-2">Go to Dashboard</a>
                        </div>

                    <?php endif; ?>

                </div>
            </div>

        </div>
    </div>
</section>

<?php include("../func/bc-spadmin-footer.php"); ?>

<!-- confetti library for wow factor on success -->
<script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const btn = document.getElementById('btn-start-update');
    if (!btn) return;

    btn.addEventListener('click', async function() {
        const changelog = document.getElementById('changelog-container');
        const progressContainer = document.getElementById('progress-container');
        const alertBox = document.getElementById('update-alert');
        
        const expectedHash = "<?= htmlspecialchars($checksum) ?>";

        // Transition UI: Hide changelog, show stepper
        changelog.classList.add('d-none');
        progressContainer.classList.remove('d-none');
        alertBox.classList.add('d-none');
        
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span> Installing Update...';

        // Helper to update stepper UI
        function setStepStatus(stepNum, status) {
            const item = document.getElementById(`step-${stepNum}`);
            const icon = document.getElementById(`icon-step-${stepNum}`);
            
            if (status === 'active') {
                item.className = 'step-item active';
                icon.className = 'bi bi-arrow-repeat spin-icon';
            } else if (status === 'completed') {
                item.className = 'step-item completed';
                icon.className = 'bi bi-check-circle-fill';
            } else if (status === 'error') {
                item.className = 'step-item error';
                icon.className = 'bi bi-x-circle-fill';
            }
        }

        try {
            // STEP 1: DOWNLOAD PACKAGE
            setStepStatus(1, 'active');
            let formData = new FormData();
            formData.append('expected_hash', expectedHash);
            <?php if (!empty($force_version)): ?>
            formData.append('force_version', "<?= htmlspecialchars($force_version) ?>");
            <?php endif; ?>

            let downloadResponse = await fetch('ajax_download_update.php', { method: 'POST', body: formData });
            let downloadData = await downloadResponse.json();
            if (downloadData.status !== 'success') {
                throw new Error(downloadData.message || 'Failed to download update package.');
            }
            setStepStatus(1, 'completed');

            // STEP 2: CREATE SNAPSHOT BACKUP
            const performBackup = document.getElementById('enable-backup') ? document.getElementById('enable-backup').checked : true;
            let backupData = { status: 'success', backup_zip: '', backup_sql: '' };

            if (performBackup) {
                setStepStatus(2, 'active');
                let backupResponse = await fetch('ajax_backup.php', { method: 'POST' });
                backupData = await backupResponse.json();
                if (backupData.status !== 'success') {
                    throw new Error(backupData.message || 'Backup failed. Update aborted for safety.');
                }
                setStepStatus(2, 'completed');
            } else {
                setStepStatus(2, 'completed');
                const step2El = document.getElementById('step-2');
                if (step2El) step2El.innerHTML += ' <span class="badge bg-secondary ms-2" style="font-size: 0.7rem;">Skipped</span>';
            }

            // STEP 3: EXTRACT AND EXECUTE MIGRATIONS
            setStepStatus(3, 'active');
            let executeFormData = new FormData();
            executeFormData.append('backup_zip', backupData.backup_zip || '');
            executeFormData.append('backup_sql', backupData.backup_sql || '');

            let executeResponse = await fetch('execute_update.php', { method: 'POST', body: executeFormData });
            let executeData = await executeResponse.json();
            if (executeData.status !== 'success') {
                throw new Error(executeData.message || 'Failed to execute system update.');
            }
            setStepStatus(3, 'completed');

            // SUCCESS FLOW
            confetti({
                particleCount: 150,
                spread: 85,
                origin: { y: 0.6 },
                colors: ['#4f46e5', '#7c3aed', '#22c55e', '#fbbf24'],
                disableForReducedMotion: true
            });

            btn.innerHTML = '<i class="bi bi-check2-all me-2"></i> Update Successful!';
            btn.classList.replace('btn-update', 'btn-success');
            
            alertBox.className = 'alert alert-success mt-4 rounded-3 shadow-sm border-0 p-4';
            alertBox.innerHTML = '<h5 class="fw-bold mb-1"><i class="bi bi-shield-check me-2"></i>Success!</h5><p class="mb-0 small">Your platform has been successfully updated to version <b>v' + executeData.message.split('version ').pop() + '</b>. Reloading system...</p>';
            alertBox.classList.remove('d-none');
            
            setTimeout(() => {
                window.location.reload();
            }, 4000);

        } catch (error) {
            // ERROR FLOW
            setStepStatus(1, 'error');
            setStepStatus(2, 'error');
            setStepStatus(3, 'error');
            
            alertBox.className = 'alert alert-danger mt-4 rounded-3 shadow-sm border-0 p-4';
            alertBox.innerHTML = '<h5 class="fw-bold mb-1"><i class="bi bi-exclamation-triangle-fill me-2"></i>Installation Failed</h5><p class="mb-0 small">' + error.message + '</p>';
            alertBox.classList.remove('d-none');
            
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-cloud-arrow-down-fill me-2"></i> Try Again';
        }
    });
});
</script>
</body>
</html>

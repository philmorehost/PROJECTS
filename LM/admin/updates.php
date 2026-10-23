<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit();
}

require_once('../db.php');

// Fetch script tiers
$stmt = $pdo->query("SELECT * FROM script_tiers ORDER BY id ASC");
$tiers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch recent updates
$stmt = $pdo->query("SELECT u.*, t.tier_code FROM script_updates u JOIN script_tiers t ON u.tier_id = t.id ORDER BY u.release_date DESC LIMIT 10");
$recent_updates = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Push Updates - License Manager</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%); display: flex; min-height: 100vh; }
        .sidebar { width: 260px; background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%); color: #fff; display: flex; flex-direction: column; position: fixed; height: 100vh; overflow-y: auto; box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1); }
        .sidebar h1 { font-size: 1.5rem; padding: 2rem 1.5rem; text-align: center; background: rgba(15, 23, 42, 0.5); margin: 0; border-bottom: 1px solid rgba(255, 255, 255, 0.1); }
        .sidebar nav { flex-grow: 1; padding: 2rem 0; }
        .sidebar nav a { display: flex; align-items: center; padding: 1rem 1.5rem; color: #cbd5e1; text-decoration: none; transition: all 0.3s ease; position: relative; }
        .sidebar nav a:hover, .sidebar nav a.active { background: rgba(59, 130, 246, 0.2); color: #fff; border-left: 3px solid #3b82f6; padding-left: calc(1.5rem - 3px); }
        .main-content { margin-left: 260px; flex-grow: 1; padding: 2rem; overflow-y: auto; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; }
        .header h2 { font-size: 2rem; color: #1e293b; font-weight: 700; }
        .card { background: white; border-radius: 12px; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1); overflow: hidden; margin-bottom: 2rem; }
        .card-header { padding: 1.5rem 2rem; border-bottom: 1px solid #e2e8f0; background: linear-gradient(135deg, #f8fafc 0%, #eef2ff 100%); }
        .card-header h3 { font-size: 1.25rem; color: #1e293b; margin: 0; font-weight: 600; }
        .card-body { padding: 2rem; }
        
        .form-group { margin-bottom: 1.5rem; }
        .form-group label { display: block; margin-bottom: 0.5rem; font-weight: 600; color: #475569; font-size: 0.875rem; }
        .form-control { width: 100%; padding: 0.75rem 1rem; border: 1px solid #cbd5e1; border-radius: 8px; font-family: inherit; font-size: 0.875rem; transition: border-color 0.3s ease; }
        .form-control:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }
        select.form-control { appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%2364748b'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 1rem center; background-size: 1rem; }
        textarea.form-control { resize: vertical; min-height: 120px; }
        
        .btn { display: inline-block; padding: 0.75rem 1.5rem; border-radius: 8px; font-weight: 600; cursor: pointer; text-align: center; border: none; transition: all 0.3s ease; font-size: 0.875rem; }
        .btn-primary { background: #3b82f6; color: white; }
        .btn-primary:hover { background: #2563eb; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3); }
        .btn-danger { background: #ef4444; color: white; }
        .btn-danger:hover { background: #dc2626; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3); }
        .btn-outline-danger { background: transparent; border: 1px solid #ef4444; color: #ef4444; }
        .btn-outline-danger:hover { background: #ef4444; color: white; }
        .btn-sm { padding: 0.4rem 0.8rem; font-size: 0.75rem; border-radius: 6px; }
        .btn:disabled { opacity: 0.7; cursor: not-allowed; transform: none; box-shadow: none; }
        
        .progress-container { display: none; margin-top: 1.5rem; }
        .progress-bar-bg { width: 100%; height: 8px; background: #e2e8f0; border-radius: 4px; overflow: hidden; margin-top: 0.5rem; }
        .progress-bar-fill { height: 100%; background: #10b981; width: 0%; transition: width 0.3s ease; }
        .status-message { font-size: 0.875rem; font-weight: 500; margin-top: 0.5rem; }
        .text-success { color: #10b981; }
        .text-danger { color: #ef4444; }

        table { width: 100%; border-collapse: collapse; }
        th { background: #f8fafc; padding: 1rem; text-align: left; font-weight: 600; color: #475569; font-size: 0.875rem; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 2px solid #e2e8f0; }
        td { padding: 1rem; border-bottom: 1px solid #e2e8f0; color: #475569; font-size: 0.875rem; }
        .badge { padding: 0.25rem 0.5rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; background: #e0e7ff; color: #4338ca; }
    </style>
</head>
<body>
    <div class="sidebar">
        <h1>🔐 License Manager</h1>
        <nav>
            <a href="dashboard.php">📊 Dashboard</a>
            <a href="licenses.php">📜 Licenses</a>
            <a href="updates.php" class="active">🚀 Push Updates</a>
            <a href="transactions.php">💳 Transactions</a>
            <a href="settings.php">⚙️ Settings</a>
            <a href="profile.php">👤 Profile</a>
            <hr style="border: none; border-top: 1px solid rgba(255,255,255,0.2); margin: 0.5rem 0;">
            <a href="logout.php">🚪 Logout</a>
        </nav>
    </div>
    
    <div class="main-content">
        <div class="header">
            <h2>Push Updates (OTA)</h2>
        </div>

        <div class="card">
            <div class="card-header">
                <h3>Upload New Update Package</h3>
            </div>
            <div class="card-body">
                <form id="updateForm" enctype="multipart/form-data">
                    <div class="form-group">
                        <label>Target Script Tier</label>
                        <select name="tier_id" class="form-control" required>
                            <option value="">-- Select Script --</option>
                            <?php foreach($tiers as $t): ?>
                                <option value="<?= htmlspecialchars($t['id']) ?>"><?= htmlspecialchars($t['tier_name']) ?> (<?= htmlspecialchars($t['tier_code']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Version Number</label>
                        <input type="text" name="version_number" class="form-control" placeholder="e.g. 7.02" required>
                    </div>

                    <div class="form-group">
                        <label>Changelog (HTML or plain text)</label>
                        <textarea name="changelog" class="form-control" placeholder="- Added new gateway&#10;- Fixed bugs..." required></textarea>
                    </div>

                    <div class="form-group">
                        <label>Update ZIP File</label>
                        <input type="file" name="update_zip" accept=".zip" class="form-control" required style="padding: 0.5rem 1rem;">
                        <small style="color: #64748b; margin-top: 0.5rem; display: block;">Max Upload Size: <?= ini_get('upload_max_filesize') ?></small>
                        <div style="margin-top: 0.75rem; padding: 0.75rem 1rem; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; font-size: 0.8rem; color: #166534;">
                            <strong>✅ Auto-Manifest:</strong> If your ZIP does not include a <code>manifest.json</code>, one will be automatically generated from the version number and changelog above.<br>
                            <strong>📦 Recommended ZIP structure:</strong>
                            <pre style="margin: 0.5rem 0 0; font-size: 0.75rem; color: #1e293b; background: #f8fafc; padding: 0.5rem; border-radius: 4px;">update.zip
├── manifest.json        ← auto-generated if missing
└── files/              ← your updated PHP/CSS/JS files
    ├── func/
    │   └── bc-func.php
    └── bc-admin/
        └── SomePage.php</pre>
                        </div>
                    </div>

                    <button type="submit" id="submitBtn" class="btn btn-primary">Upload &amp; Publish Update</button>

                    <div class="progress-container" id="progressContainer">
                        <div class="status-message" id="statusMessage">Uploading...</div>
                        <div class="progress-bar-bg">
                            <div class="progress-bar-fill" id="progressBar"></div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
                <h3>Recent Updates</h3>
                <button type="button" id="cleanupBtn" class="btn btn-outline-danger btn-sm" onclick="cleanupOldUpdates()">🧹 Clean Up Old Updates</button>
            </div>
            <div class="card-body" style="padding: 0;">
                <table>
                    <thead>
                        <tr>
                            <th>Script Tier</th>
                            <th>Version</th>
                            <th>File Path</th>
                            <th>Date Published</th>
                            <th style="text-align: right; padding-right: 2rem;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($recent_updates)): ?>
                            <tr><td colspan="5" style="text-align: center;">No updates published yet.</td></tr>
                        <?php else: ?>
                            <?php foreach($recent_updates as $u): ?>
                            <tr>
                                <td><span class="badge"><?= htmlspecialchars($u['tier_code']) ?></span></td>
                                <td><strong>v<?= htmlspecialchars($u['version_number']) ?></strong></td>
                                <td><code style="font-size: 0.75rem; color: #94a3b8;"><?= htmlspecialchars($u['zip_path']) ?></code></td>
                                <td><?= date('M d, Y H:i', strtotime($u['release_date'])) ?></td>
                                <td style="text-align: right; padding-right: 2rem;">
                                    <button type="button" class="btn btn-danger btn-sm" onclick="deleteUpdate(<?= $u['id'] ?>, '<?= htmlspecialchars($u['version_number']) ?>')">Delete</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
    document.getElementById('updateForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const form = this;
        const btn = document.getElementById('submitBtn');
        const progressContainer = document.getElementById('progressContainer');
        const progressBar = document.getElementById('progressBar');
        const statusMessage = document.getElementById('statusMessage');
        
        btn.disabled = true;
        btn.textContent = 'Processing...';
        progressContainer.style.display = 'block';
        statusMessage.textContent = 'Uploading... Please wait.';
        statusMessage.className = 'status-message';
        progressBar.style.width = '0%';
        progressBar.style.background = '#3b82f6';
        
        const formData = new FormData(form);
        const xhr = new XMLHttpRequest();
        
        xhr.open('POST', 'ajax_upload_update.php', true);
        
        xhr.upload.onprogress = function(e) {
            if (e.lengthComputable) {
                const percent = Math.round((e.loaded / e.total) * 100);
                progressBar.style.width = percent + '%';
                if(percent === 100) {
                    statusMessage.textContent = 'Upload complete. Processing ZIP file...';
                }
            }
        };
        
        xhr.onload = function() {
            btn.disabled = false;
            btn.textContent = 'Upload & Publish Update';
            
            try {
                const res = JSON.parse(xhr.responseText);
                if(res.status === 'success') {
                    progressBar.style.background = '#10b981';
                    statusMessage.textContent = res.message;
                    statusMessage.className = 'status-message text-success';
                    form.reset();
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    progressBar.style.background = '#ef4444';
                    statusMessage.textContent = 'Error: ' + res.message;
                    statusMessage.className = 'status-message text-danger';
                }
            } catch(err) {
                progressBar.style.background = '#ef4444';
                statusMessage.textContent = 'A critical server error occurred. Check PHP logs.';
                statusMessage.className = 'status-message text-danger';
            }
        };
        
        xhr.onerror = function() {
            btn.disabled = false;
            btn.textContent = 'Upload & Publish Update';
            progressBar.style.background = '#ef4444';
            statusMessage.textContent = 'Network error occurred during upload.';
            statusMessage.className = 'status-message text-danger';
        };
        
        xhr.send(formData);
    });

    function deleteUpdate(id, version) {
        if (!confirm('Are you sure you want to delete update version ' + version + '? This will physically delete the update ZIP file from the server and remove its record in the database.')) {
            return;
        }
        
        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'ajax_delete_update.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        
        xhr.onload = function() {
            try {
                const res = JSON.parse(xhr.responseText);
                if (res.status === 'success') {
                    alert(res.message);
                    window.location.reload();
                } else {
                    alert('Error: ' + res.message);
                }
            } catch(e) {
                alert('A server error occurred. Check PHP error logs.');
            }
        };
        
        xhr.send('action=delete_single&update_id=' + id);
    }
    
    function cleanupOldUpdates() {
        if (!confirm('Are you sure you want to clean up old updates? This will delete all physical update ZIP files and database records of older versions, keeping only the latest version for each script tier.')) {
            return;
        }
        
        const cleanupBtn = document.getElementById('cleanupBtn');
        cleanupBtn.disabled = true;
        cleanupBtn.textContent = 'Cleaning...';
        
        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'ajax_delete_update.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        
        xhr.onload = function() {
            cleanupBtn.disabled = false;
            cleanupBtn.textContent = '🧹 Clean Up Old Updates';
            
            try {
                const res = JSON.parse(xhr.responseText);
                if (res.status === 'success') {
                    alert(res.message);
                    window.location.reload();
                } else {
                    alert('Error: ' + res.message);
                }
            } catch(e) {
                alert('A server error occurred. Check PHP error logs.');
            }
        };
        
        xhr.send('action=cleanup_old');
    }
    </script>
</body>
</html>

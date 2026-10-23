<?php
// run_migrations.php
// Runs the missing DB migrations for the v7.01 update.
// DELETE THIS FILE after running.

session_start();
if (!isset($_SESSION['admin_session']) && !isset($_SESSION['spadmin_session'])) {
    die('Unauthorized. Log in first.');
}

require_once("../func/bc-admin-config.php");

// Detect which script we're running under
$is_saas = file_exists(dirname(__DIR__) . '/bc-spadmin');

// ── Migrations needed for v7.01 ───────────────────────────────────────────────
$migrations = [
    // AI columns for sas_users
    "ALTER TABLE `sas_users` ADD COLUMN `ai_status` TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE `sas_users` ADD COLUMN `ai_voice_status` TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE `sas_users` ADD COLUMN `ai_token_balance` INT NOT NULL DEFAULT 0",

    // AI columns for sas_vendors
    "ALTER TABLE `sas_vendors` ADD COLUMN `ai_status` TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE `sas_vendors` ADD COLUMN `voice_tx_threshold` INT NOT NULL DEFAULT 50",
    "ALTER TABLE `sas_vendors` ADD COLUMN `ai_user_token_price` DECIMAL(10,2) NOT NULL DEFAULT 150.00",
    "ALTER TABLE `sas_vendors` ADD COLUMN `ai_token_balance` INT NOT NULL DEFAULT 0",
    "ALTER TABLE `sas_vendors` ADD COLUMN `ai_voice_fee_tokens` INT NOT NULL DEFAULT 0",
];

$results = [];
$action = $_POST['action'] ?? '';

if ($action === 'run') {
    $safe_errors = [
        'duplicate column name',
        'already exists',
        'duplicate key name',
        'multiple primary keys',
        "doesn't exist",
    ];

    foreach ($migrations as $sql) {
        $res = mysqli_query($connection_server, $sql);
        if ($res) {
            $results[] = ['sql' => $sql, 'status' => 'ok', 'msg' => 'Applied successfully.'];
        } else {
            $err = strtolower(mysqli_error($connection_server));
            $safe = false;
            foreach ($safe_errors as $pattern) {
                if (strpos($err, $pattern) !== false) {
                    $safe = true;
                    break;
                }
            }
            $results[] = [
                'sql' => $sql,
                'status' => $safe ? 'skipped' : 'error',
                'msg' => $safe ? 'Already exists — skipped.' : mysqli_error($connection_server),
            ];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Run v7.01 DB Migrations</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f1f5f9; margin: 0; padding: 2rem; color: #1e293b; }
        h1 { color: #0f172a; margin-bottom: 0.25rem; }
        .subtitle { color: #64748b; margin-bottom: 2rem; font-size: 0.9rem; }
        .card { background: white; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); padding: 1.5rem 2rem; margin-bottom: 2rem; }
        h2 { font-size: 1.1rem; margin: 0 0 1rem; color: #334155; }
        table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
        th { background: #f8fafc; padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #475569; border-bottom: 2px solid #e2e8f0; }
        td { padding: 0.75rem 1rem; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
        .badge { padding: 0.2rem 0.6rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 700; }
        .ok { background: #dcfce7; color: #15803d; }
        .skipped { background: #e0e7ff; color: #3730a3; }
        .error { background: #fee2e2; color: #b91c1c; }
        .btn { background: linear-gradient(135deg, #4f46e5, #7c3aed); color: white; border: none; padding: 0.9rem 2rem; border-radius: 10px; font-size: 1rem; font-weight: 700; cursor: pointer; }
        .btn:hover { opacity: 0.9; }
        code { font-size: 0.8rem; color: #475569; word-break: break-all; }
        .warning { background: #fef3c7; border: 1px solid #fcd34d; border-radius: 8px; padding: 1rem 1.25rem; margin-bottom: 1.5rem; font-size: 0.875rem; color: #78350f; }
    </style>
</head>
<body>
<h1>🗄️ v7.01 DB Migration Runner</h1>
<p class="subtitle">Applies missing database columns for the AI and Voice features added in v7.01. <strong>Delete this file after running.</strong></p>

<div class="warning">
    ⚠️ <strong>Safe to run multiple times.</strong> Already-existing columns are automatically skipped. No data is lost.
</div>

<?php if (!empty($results)): ?>
<div class="card">
    <h2>Migration Results</h2>
    <table>
        <thead><tr><th>SQL</th><th>Status</th><th>Message</th></tr></thead>
        <tbody>
        <?php foreach ($results as $r): ?>
        <tr>
            <td><code><?= htmlspecialchars($r['sql']) ?></code></td>
            <td><span class="badge <?= $r['status'] ?>"><?= strtoupper($r['status']) ?></span></td>
            <td style="font-size:0.8rem;color:#64748b"><?= htmlspecialchars($r['msg']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php
$errors = array_filter($results, fn($r) => $r['status'] === 'error');
if (empty($errors)):
?>
<div style="background:#dcfce7;border:1px solid #bbf7d0;border-radius:8px;padding:1rem;margin-bottom:1.5rem;color:#166534;font-weight:600;">
    ✅ All migrations completed successfully! You can now delete this file.
</div>
<?php endif; ?>
<?php endif; ?>

<div class="card">
    <h2>Pending Migrations (<?= count($migrations) ?> queries)</h2>
    <table>
        <thead><tr><th>#</th><th>SQL</th></tr></thead>
        <tbody>
        <?php foreach ($migrations as $i => $sql): ?>
        <tr><td><?= $i + 1 ?></td><td><code><?= htmlspecialchars($sql) ?></code></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<form method="POST">
    <input type="hidden" name="action" value="run">
    <button type="submit" class="btn">▶ Run All Migrations Now</button>
</form>
</body>
</html>

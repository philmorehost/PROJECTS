<?php
// fix_license_tiers.php
// Diagnostic & repair tool for license tier assignments
// DELETE THIS FILE after use.

session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    die('Unauthorized. Log in to the License Manager admin first.');
}

require_once('../db.php');

$action = $_POST['action'] ?? '';
$messages = [];

// ── REPAIR: assign correct tier_id to a license ───────────────────────────────
if ($action === 'assign_tier') {
    $license_id = intval($_POST['license_id'] ?? 0);
    $new_tier_id = intval($_POST['tier_id'] ?? 0);
    if ($license_id && $new_tier_id) {
        $stmt = $pdo->prepare("UPDATE licenses SET tier_id = ? WHERE id = ?");
        $stmt->execute([$new_tier_id, $license_id]);
        $messages[] = ['type' => 'success', 'text' => "License ID #{$license_id} updated to tier_id = {$new_tier_id}."];
    }
}

// ── Fetch all licenses with their tier info ────────────────────────────────────
$licenses = $pdo->query("
    SELECT l.id, l.license_key, l.license_type, l.status, l.tier_id,
           t.tier_code, t.tier_name
    FROM licenses l
    LEFT JOIN script_tiers t ON l.tier_id = t.id
    ORDER BY l.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

// ── Fetch all tiers for the repair dropdown ────────────────────────────────────
$tiers = $pdo->query("SELECT * FROM script_tiers ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

// ── Fetch last 30 lines of the update check log ───────────────────────────────
$log_file = dirname(__DIR__) . '/api_check_update.log';
$log_lines = [];
if (file_exists($log_file)) {
    $all_lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $log_lines = array_slice($all_lines, -30);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>License Tier Diagnostic – License Manager</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f1f5f9; margin: 0; padding: 2rem; color: #1e293b; }
        h1 { color: #0f172a; margin-bottom: 0.25rem; }
        .subtitle { color: #64748b; margin-bottom: 2rem; font-size: 0.9rem; }
        .card { background: white; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); padding: 1.5rem 2rem; margin-bottom: 2rem; }
        h2 { font-size: 1.1rem; margin: 0 0 1rem; color: #334155; border-bottom: 1px solid #e2e8f0; padding-bottom: 0.5rem; }
        table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
        th { background: #f8fafc; padding: 0.75rem 1rem; text-align: left; font-weight: 600; color: #475569; border-bottom: 2px solid #e2e8f0; }
        td { padding: 0.75rem 1rem; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
        tr:hover td { background: #f8fafc; }
        .badge { padding: 0.2rem 0.6rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 700; }
        .badge-saas { background: #dbeafe; color: #1d4ed8; }
        .badge-nonsaas { background: #fef9c3; color: #a16207; }
        .badge-null { background: #fee2e2; color: #b91c1c; }
        .badge-active { background: #dcfce7; color: #15803d; }
        .badge-inactive { background: #f3f4f6; color: #6b7280; }
        select { padding: 0.3rem 0.5rem; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.8rem; }
        button { padding: 0.3rem 0.8rem; background: #3b82f6; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 0.8rem; font-weight: 600; }
        button:hover { background: #2563eb; }
        .alert { padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem; font-size: 0.875rem; }
        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .log-box { background: #0f172a; color: #94a3b8; font-family: monospace; font-size: 0.78rem; padding: 1rem; border-radius: 8px; max-height: 350px; overflow-y: auto; white-space: pre-wrap; word-break: break-all; }
        .log-warn { color: #fbbf24; }
        .log-info { color: #6ee7b7; }
        .warning-bar { background: #fef3c7; border: 1px solid #fcd34d; border-radius: 8px; padding: 0.75rem 1rem; font-size: 0.85rem; color: #78350f; margin-bottom: 1.5rem; }
    </style>
</head>
<body>
<h1>🔧 License Tier Diagnostic & Repair</h1>
<p class="subtitle">Use this tool to inspect and correct license tier assignments. <strong>Delete this file after use.</strong></p>

<div class="warning-bar">
    ⚠️ <strong>Important:</strong> Each license must have the correct <code>tier_id</code> assigned.
    SAAS licenses → <strong>tier_id = 1 (SAAS)</strong> &nbsp;|&nbsp; NON-SAAS licenses → <strong>tier_id = 2 (NON-SAAS)</strong>.<br>
    A <span style="background:#fee2e2;color:#b91c1c;padding:0 4px;border-radius:4px;">NULL tier</span> means the fallback logic is used, which relies on <code>license_type</code> (<code>extended</code> = SAAS, anything else = NON-SAAS).
</div>

<?php foreach ($messages as $msg): ?>
    <div class="alert alert-<?= $msg['type'] ?>"><?= htmlspecialchars($msg['text']) ?></div>
<?php endforeach; ?>

<div class="card">
    <h2>📜 All Licenses & Their Tier Assignments</h2>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>License Key</th>
                <th>Type</th>
                <th>Status</th>
                <th>Tier ID</th>
                <th>Tier Code</th>
                <th>Effective Tier (Resolved)</th>
                <th>Fix Tier</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($licenses as $lic): 
            // Compute the effective tier the same way check-update.php does
            if (!empty($lic['tier_code'])) {
                $effective = $lic['tier_code'];
                $effective_id = $lic['tier_id'];
            } elseif ($lic['license_type'] === 'extended') {
                $effective = 'SAAS (fallback)';
                $effective_id = 1;
            } else {
                $effective = 'NON-SAAS (fallback)';
                $effective_id = 2;
            }
            $is_null_tier = empty($lic['tier_id']);
        ?>
        <tr>
            <td><?= $lic['id'] ?></td>
            <td><code style="font-size:0.75rem"><?= htmlspecialchars(substr($lic['license_key'], 0, 24)) ?>...</code></td>
            <td><?= htmlspecialchars($lic['license_type'] ?? '—') ?></td>
            <td><span class="badge badge-<?= $lic['status'] === 'active' ? 'active' : 'inactive' ?>"><?= htmlspecialchars($lic['status']) ?></span></td>
            <td>
                <?php if ($is_null_tier): ?>
                    <span class="badge badge-null">NULL ⚠️</span>
                <?php else: ?>
                    <?= htmlspecialchars($lic['tier_id']) ?>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($lic['tier_code']): ?>
                    <span class="badge badge-<?= strtolower(str_replace('-', '', $lic['tier_code'])) ?>"><?= htmlspecialchars($lic['tier_code']) ?></span>
                <?php else: ?>
                    <span style="color:#94a3b8">—</span>
                <?php endif; ?>
            </td>
            <td>
                <strong style="color:<?= str_contains($effective, 'fallback') ? '#a16207' : '#166534' ?>">
                    <?= htmlspecialchars($effective) ?>
                </strong>
            </td>
            <td>
                <form method="POST" style="display:flex;gap:0.4rem;align-items:center;">
                    <input type="hidden" name="action" value="assign_tier">
                    <input type="hidden" name="license_id" value="<?= $lic['id'] ?>">
                    <select name="tier_id">
                        <?php foreach ($tiers as $t): ?>
                            <option value="<?= $t['id'] ?>" <?= ($t['id'] == $lic['tier_id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($t['tier_code']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit">Fix</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="card">
    <h2>📋 Recent Update Check Log (last 30 lines)</h2>
    <?php if (empty($log_lines)): ?>
        <p style="color:#94a3b8">No log file found at: <code><?= htmlspecialchars($log_file) ?></code></p>
    <?php else: ?>
        <div class="log-box"><?php foreach ($log_lines as $line): 
            $cls = str_contains($line, 'WARNING') ? 'log-warn' : (str_contains($line, 'INFO') ? 'log-info' : '');
        ?><span class="<?= $cls ?>"><?= htmlspecialchars($line) ?></span>
<?php endforeach; ?></div>
    <?php endif; ?>
</div>
</body>
</html>

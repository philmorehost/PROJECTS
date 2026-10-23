<?php session_start();
include("../func/bc-spadmin-config.php");
include_once("../func/bc-ai-engine.php");

// Force clear cache on load to ensure we see latest settings
if (isset($_SESSION)) {
    unset($_SESSION['super_admin_options_cache']);
}

// ── Handle: Global AI Toggle ───────────────────────────────
if (isset($_POST["toggle-global-ai"])) {
    $val = (int)($_POST["ai_global_enabled"] ?? 0) ? '1' : '0';
    mysqli_query($connection_server,
        "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('ai_global_enabled', '$val')
         ON DUPLICATE KEY UPDATE option_value='$val'"
    );
    $_SESSION["response"] = "✅ Global AI " . ($val ? "Enabled" : "Disabled") . ".";
    unset($_SESSION['super_admin_options_cache']); 
    header("Location: AIManagement.php"); exit();
}

// ── Handle: Test Connection ───────────────────────────────
if (isset($_POST["test-connection"])) {
    $ai = ai_engine();
    $provider = $ai->getProvider();
    $url = $ai->getBaseUrl();
    
    if ($provider === 'gemini') {
        // Use the list models endpoint to check connectivity & key validity
        $url = "https://generativelanguage.googleapis.com/v1beta/models?key=" . $ai->getApiKey();
    } else {
        $url = rtrim($url, '/') . '/models';
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    if (in_array($provider, ['deepseek', 'groq'])) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . $ai->getApiKey()]);
    }

    $res = curl_exec($ch);
    $err = curl_error($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);

    if ($res !== false && $info['http_code'] < 400) {
        $_SESSION["response"] = "✅ Connection Successful! " . ucfirst($provider) . " is reachable.";
    } else {
        $msg = $err ?: "HTTP Error " . $info['http_code'];
        if ($info['http_code'] == 401 || $info['http_code'] == 403) $msg .= " (Invalid API Key)";
        $_SESSION["response"] = "❌ Connection Failed for " . ucfirst($provider) . ": $msg";
    }
    header("Location: AIManagement.php"); exit();
}

// ── Handle: Approve/Reject Vendor Request ─────────────────
if (isset($_GET['approve'])) {
    $v_id = (int)$_GET['approve'];
    $bonus = (int)getSuperAdminOption('ai_default_token_bonus', 1000);
    $v_q = mysqli_query($connection_server, "SELECT ai_pending_tokens FROM sas_vendors WHERE id='$v_id'");
    $v_data = mysqli_fetch_assoc($v_q);
    $requested_tokens = (int)($v_data['ai_pending_tokens'] ?? 0);
    $total_grant = $requested_tokens + $bonus;

    mysqli_query($connection_server, "UPDATE sas_vendors SET ai_status=1, ai_request_status='approved', ai_token_balance = ai_token_balance + $total_grant, ai_pending_tokens=0, ai_pending_cost=0 WHERE id='$v_id'");
    $_SESSION["response"] = "✅ Vendor AI activation approved. $total_grant tokens granted.";
    header("Location: AIManagement.php"); exit();
}
if (isset($_GET['reject'])) {
    $v_id = (int)$_GET['reject'];
    $v_q = mysqli_query($connection_server, "SELECT ai_pending_cost, email FROM sas_vendors WHERE id='$v_id'");
    $v_data = mysqli_fetch_assoc($v_q);
    $refund_amount = (float)($v_data['ai_pending_cost'] ?? 0);
    $v_email = $v_data['email'] ?? '';

    if ($refund_amount > 0) {
        $ref = "RFND_AI_" . time();
        chargeVendor("credit", "ai_refund", "AI Refund", $ref, $refund_amount, $refund_amount, "Refund for rejected AI activation request", $_SERVER["HTTP_HOST"], 1);
    }

    mysqli_query($connection_server, "UPDATE sas_vendors SET ai_request_status='rejected', ai_pending_cost=0, ai_pending_tokens=0 WHERE id='$v_id'");
    if (!empty($v_email)) sendVendorEmail($v_email, "AI Request Update", "Your AI activation request has been reviewed and rejected.");

    $_SESSION["response"] = "❌ Vendor AI request rejected. Refund of ₦" . number_format($refund_amount, 2) . " processed.";
    header("Location: AIManagement.php"); exit();
}

// ── Handle: Update AI Pricing ──────────────────────────────
if (isset($_POST["update-ai-pricing"])) {
    $price_1k  = bc_sanitize_number($_POST["price_per_1k"] ?? 100);
    $per_tx    = (int)($_POST["per_tx_cost"] ?? 2);
    $exec_fee  = (int)($_POST["execution_fee"] ?? 0);
    $voice_thr = (int)($_POST["voice_threshold"] ?? 100);
    $token_bonus = (int)($_POST["token_bonus"] ?? 1000);
    $copilot_cost = max(0, (int)($_POST["marketing_copilot_cost"] ?? 3));

    $opts = [
        'ai_price_per_request' => $per_tx,
        'ai_execution_fee_tokens' => $exec_fee,
        'ai_voice_unlock_threshold' => $voice_thr,
        'ai_default_token_bonus' => $token_bonus
    ];
    foreach ($opts as $k => $v) {
        $esc_k = mysqli_real_escape_string($connection_server, $k);
        $esc_v = mysqli_real_escape_string($connection_server, $v);
        mysqli_query($connection_server, "REPLACE INTO sas_super_admin_options (option_name, option_value) VALUES ('$esc_k','$esc_v')");
    }
    // Marketing Copilot fee is a flat platform-wide rate read directly via getSuperAdminOption()
    // by AIMarketing.php/SendMail.php (not copied into every sas_vendors row like the others
    // above) — setSuperAdminOption() upserts correctly regardless of which sas_super_admin_options
    // schema variant is live (see its doc comment in bc-func.php).
    setSuperAdminOption('ai_marketing_copilot_cost', $copilot_cost);
    mysqli_query($connection_server, "UPDATE sas_vendors SET ai_price_per_1k_tokens='$price_1k', ai_per_tx_cost='$per_tx', ai_voice_fee_tokens='$exec_fee', voice_tx_threshold='$voice_thr'");
    $_SESSION["response"] = "✅ AI pricing updated for all vendors.";
    unset($_SESSION['super_admin_options_cache']);
    header("Location: AIManagement.php"); exit();
}

// ── Handle: Update AI Connection ──────────────────────────
if (isset($_POST["update-ai-connection"])) {
    $provider = bc_sanitize($_POST["ai_provider"] ?? 'gemini');
    $key      = bc_sanitize($_POST["ai_api_key"] ?? '');
    $opts = ['ai_provider' => $provider, "ai_{$provider}_api_key" => $key, 'ai_api_key' => $key];
    
    // Cleanup any duplicates
    mysqli_query($connection_server, "DELETE FROM sas_super_admin_options WHERE option_name='ai_provider' AND id NOT IN (SELECT id FROM (SELECT MAX(id) as id FROM sas_super_admin_options WHERE option_name='ai_provider') x)");

    foreach ($opts as $k => $v) {
        $esc_k = mysqli_real_escape_string($connection_server, $k);
        $esc_v = mysqli_real_escape_string($connection_server, $v);
        mysqli_query($connection_server, "REPLACE INTO sas_super_admin_options (option_name, option_value) VALUES ('$esc_k', '$esc_v')");
    }
    $_SESSION["response"] = "✅ AI connection updated to " . ucfirst($provider) . ".";
    unset($_SESSION['super_admin_options_cache']);
    header("Location: AIManagement.php"); exit();
}

// ── Handle: Set Active Model ──────────────────────────────
if (isset($_POST["set-active-model"])) {
    $model = bc_sanitize($_POST["active_model_name"] ?? '');
    if (!empty($model)) {
        mysqli_query($connection_server, "REPLACE INTO sas_super_admin_options (option_name, option_value) VALUES ('ai_default_model', '$model')");
        $_SESSION["response"] = "✅ Active AI Model set to '$model'.";
        unset($_SESSION['super_admin_options_cache']);
    }
    header("Location: AIManagement.php"); exit();
}

// ── Load current data ──────────────────────────────────────
$ai_global  = getSuperAdminOption('ai_global_enabled', '0');
$ai_provider= getSuperAdminOption('ai_provider', 'gemini');
$ai         = ai_engine();
$model_raw  = getSuperAdminOption('ai_default_model', '');
if (empty($model_raw) || !$ai->isModelCompatible($model_raw)) {
    $active_model = $ai->getDefaultModel();
} else {
    $active_model = $model_raw;
}
$ai_key     = getSuperAdminOption('ai_api_key', '');

$gemini_key   = getSuperAdminOption('ai_gemini_api_key', '');
$deepseek_key = getSuperAdminOption('ai_deepseek_api_key', '');
$groq_key     = getSuperAdminOption('ai_groq_api_key', '');

$ai = ai_engine();
$ai_up = $ai->isAiOnline();

$model_catalog = [];
if ($ai_provider === 'gemini') {
    $model_catalog = [
        ['name' => 'gemini-1.5-flash', 'size' => 'Cloud', 'desc' => 'Default fast model. Excellent for most tasks.', 'tier' => 'Free'],
        ['name' => 'gemini-1.5-pro',   'size' => 'Cloud', 'desc' => 'High intelligence for complex reasoning.',   'tier' => 'Premium'],
        ['name' => 'gemini-1.0-pro',   'size' => 'Cloud', 'desc' => 'Stable production-grade model.',         'tier' => 'Standard'],
    ];
} elseif ($ai_provider === 'deepseek') {
    $model_catalog = [
        ['name' => 'deepseek-chat',    'size' => 'Cloud', 'desc' => 'Powerful reasoning & chat model.',        'tier' => 'Premium'],
        ['name' => 'deepseek-coder',   'size' => 'Cloud', 'desc' => 'Specialized for technical & logic tasks.', 'tier' => 'Standard'],
    ];
} else {
    $model_catalog = [
        ['name' => 'llama3-70b-8192',  'size' => 'Cloud', 'desc' => 'Llama 3 70B — extremely capable.',        'tier' => 'Premium'],
        ['name' => 'llama3-8b-8192',   'size' => 'Cloud', 'desc' => 'Llama 3 8B — ultra fast performance.',     'tier' => 'Standard'],
    ];
}

$ai_rev_q = mysqli_query($connection_server, "SELECT SUM(cost_naira) as revenue, COUNT(*) as calls FROM sas_ai_transactions WHERE MONTH(created_at)=MONTH(NOW()) AND status='success'");
$ai_rev = $ai_rev_q ? mysqli_fetch_assoc($ai_rev_q) : ['revenue' => 0, 'calls' => 0];

// Intelligence Hub Data (Last 30 Days for visibility)
$top_consumers_q = mysqli_query($connection_server, "SELECT COALESCE(v.company_name, t.username) as name, v.website_url, SUM(t.tokens_burned) as total FROM sas_ai_transactions t LEFT JOIN sas_vendors v ON v.id=t.vendor_id WHERE t.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY t.vendor_id, t.username ORDER BY total DESC LIMIT 5");
$recent_logs_q = mysqli_query($connection_server, "SELECT t.*, COALESCE(v.company_name, 'System Admin') as vendor_name, v.website_url FROM sas_ai_transactions t LEFT JOIN sas_vendors v ON v.id=t.vendor_id ORDER BY t.id DESC LIMIT 10");

// Live Health Metrics
$health_q = mysqli_query($connection_server, "SELECT AVG(duration_ms) as avg_lat, COUNT(*) as total_calls FROM sas_ai_transactions WHERE status='success' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
$health = ($health_q) ? mysqli_fetch_assoc($health_q) : ['avg_lat' => 0, 'total_calls' => 0];
$avg_latency = ($health && $health['avg_lat'] > 0) ? round($health['avg_lat']) : 450;

$blocked_q = mysqli_query($connection_server, "SELECT COUNT(*) as blocked FROM sas_ai_transactions WHERE status='blocked' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
$blocked_count = ($blocked_q && $row_b = mysqli_fetch_assoc($blocked_q)) ? $row_b['blocked'] : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>AI Management | Super Admin</title>
    <meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="/cssfile/bc-style.css">
    <style>
        .ai-header{background:linear-gradient(135deg,#1e1b4b,#3730a3);color:#fff;border-radius:1.5rem;padding:2rem;}
        .status-dot{width:10px;height:10px;border-radius:50%;display:inline-block;}
        .dot-green{background:#22c55e;box-shadow:0 0 8px #22c55e;}
        .dot-red{background:#ef4444;box-shadow:0 0 8px #ef4444;}
        .model-card{border:1px solid #e5e7eb;border-radius:1rem;padding:1rem;transition:.2s;cursor:default;}
        .model-card:hover{border-color:#6366f1;background:#faf5ff;}
        .tier-badge{font-size:.65rem;font-weight:700;border-radius:2rem;padding:.15rem .6rem;}
        .hub-stat{background:#f8fafc;border-radius:.75rem;padding:1rem;border-left:4px solid #6366f1;}
        .log-item{font-size:.75rem;padding:.5rem;border-bottom:1px solid #f1f5f9;transition:.2s;}
        .log-item:hover{background:#f8fafc;}
    </style>
</head>
<body>
<?php include("../func/bc-spadmin-header.php"); ?>
<div class="pagetitle"><h1>AI MANAGEMENT CENTER</h1></div>

<section class="section">
<?php if (isset($_SESSION["response"])): ?>
    <div class="alert alert-info alert-dismissible fade show rounded-4"><?php echo $_SESSION["response"]; unset($_SESSION["response"]); ?><button class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="ai-header mb-4 shadow">
    <div class="row align-items-center g-3">
        <div class="col-md-6">
            <h4 class="fw-bold mb-1"><i class="bi bi-cpu me-2"></i>Cloud AI Ecosystem</h4>
            <p class="opacity-75 mb-0">Manage cloud-based AI providers, pricing, and revenue.</p>
        </div>
        <div class="col-md-6 text-md-end">
            <div class="d-inline-block me-4">
                <div class="fw-bold fs-4">₦<?php echo number_format((float)$ai_rev['revenue'], 0); ?></div>
                <div class="small opacity-75">Revenue MTD</div>
            </div>
            <div class="d-inline-block">
                <div class="fw-bold fs-4"><?php echo number_format((int)$ai_rev['calls']); ?></div>
                <div class="small opacity-75">Calls MTD</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Left Column: Status & Economics -->
    <div class="col-lg-4">
        <div class="card border-0 rounded-4 shadow-sm mb-4">
            <div class="card-header bg-white border-0 py-3"><h5 class="fw-bold mb-0"><i class="bi bi-activity me-2 text-primary"></i>System Status</h5></div>
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                    <span class="fw-bold small">Active Provider: <?php echo ucfirst($ai_provider); ?></span>
                    <span class="status-dot <?php echo $ai_up ? 'dot-green' : 'dot-red'; ?> me-2"></span>
                    <span class="small <?php echo $ai_up ? 'text-success' : 'text-danger'; ?>"><?php echo $ai_up ? 'Online' : 'Offline'; ?></span>
                </div>
                <form method="post">
                    <input type="hidden" name="ai_global_enabled" value="<?php echo $ai_global ? 0 : 1; ?>">
                    <div class="d-flex gap-2">
                        <button type="submit" name="toggle-global-ai" class="btn w-100 rounded-pill fw-bold <?php echo $ai_global ? 'btn-outline-danger' : 'btn-success'; ?>">
                            <?php echo $ai_global ? 'Disable Global AI' : 'Enable Global AI'; ?>
                        </button>
                        <button type="submit" name="test-connection" class="btn btn-light rounded-pill px-3 shadow-sm"><i class="bi bi-broadcast"></i></button>
                    </div>
                </form>
                <hr class="my-4">
                <h6 class="fw-bold small text-muted text-uppercase mb-3">Connection Settings</h6>
                <form method="post">
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Provider</label>
                        <select name="ai_provider" class="form-select rounded-3 small">
                            <option value="gemini" <?php echo ($ai_provider=='gemini')?'selected':''; ?>>Google Gemini</option>
                            <option value="deepseek" <?php echo ($ai_provider=='deepseek')?'selected':''; ?>>DeepSeek</option>
                            <option value="groq" <?php echo ($ai_provider=='groq')?'selected':''; ?>>Groq</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">API Key</label>
                        <input type="password" name="ai_api_key" id="ai_api_key_field" class="form-control rounded-3 small" value="<?php echo htmlspecialchars($ai_key); ?>">
                    </div>
                    <button type="submit" name="update-ai-connection" class="btn btn-dark btn-sm w-100 rounded-pill">Update Cloud Connection</button>
                </form>
            </div>
        </div>
        
        <div class="card border-0 rounded-4 shadow-sm">
            <div class="card-header bg-white border-0 py-3"><h5 class="fw-bold mb-0"><i class="bi bi-currency-exchange me-2 text-warning"></i>Economics</h5></div>
            <div class="card-body p-4">
                <form method="post">
                    <div class="mb-3"><label class="form-label small fw-bold text-muted">Price per 1k Tokens (₦)</label><input type="number" name="price_per_1k" class="form-control rounded-3" value="<?php echo getSuperAdminOption('ai_price_per_1k_tokens', '100'); ?>" step="0.01"></div>
                    <div class="mb-3"><label class="form-label small fw-bold text-muted">General Chat Fee (Tokens)</label><input type="number" name="per_tx_cost" class="form-control rounded-3" value="<?php echo getSuperAdminOption('ai_price_per_request', '2'); ?>"></div>
                    <div class="mb-3"><label class="form-label small fw-bold text-muted">Successful Execution Fee (Tokens)</label><input type="number" name="execution_fee" class="form-control rounded-3" value="<?php echo getSuperAdminOption('ai_execution_fee_tokens', '0'); ?>"></div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">Marketing AI Copilot Fee (Tokens)</label>
                        <input type="number" name="marketing_copilot_cost" min="0" class="form-control rounded-3" value="<?php echo getSuperAdminOption('ai_marketing_copilot_cost', '3'); ?>">
                        <div class="form-text x-small">Charged per "Help me write" / "Refine content" use on AI Marketing Studio and the Mail Sender composer — a flat platform-wide rate, separate from the General Chat Fee above.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">Approval Bonus Tokens</label>
                        <input type="number" name="token_bonus" class="form-control rounded-3" value="<?php echo getSuperAdminOption('ai_default_token_bonus', '1000'); ?>">
                        <div class="form-text x-small">Bonus tokens granted when a vendor AI request is approved.</div>
                    </div>
                    <button type="submit" name="update-ai-pricing" class="btn btn-primary w-100 rounded-pill fw-bold">Save Economics</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Right Column: Requests, Analytics Hub & Catalog -->
    <div class="col-lg-8">
        <div class="row g-4">
            <!-- Activation Requests -->
            <div class="col-12">
                <div class="card border-0 rounded-4 shadow-sm">
                    <div class="card-header bg-white border-0 py-3"><h5 class="fw-bold mb-0"><i class="bi bi-person-check me-2 text-success"></i>Activation Requests</h5></div>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="table-light"><tr class="small text-muted"><th>Vendor</th><th>Date</th><th class="text-end pe-4">Action</th></tr></thead>
                            <tbody>
                                <?php 
                                $req_q = mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE ai_request_status='pending' ORDER BY id DESC");
                                if ($req_q && mysqli_num_rows($req_q) > 0):
                                    while($req = mysqli_fetch_assoc($req_q)): ?>
                                    <tr>
                                        <td class="ps-4"><strong><?php echo htmlspecialchars($req['company_name'] ?: ($req['firstname'].' '.$req['lastname'])); ?></strong></td>
                                        <td class="small"><?php echo date('M j', strtotime($req['reg_date'])); ?></td>
                                        <td class="text-end pe-4">
                                            <a href="AIManagement.php?approve=<?php echo $req['id']; ?>" class="btn btn-success btn-sm rounded-pill px-3">Approve</a>
                                            <a href="AIManagement.php?reject=<?php echo $req['id']; ?>" class="btn btn-danger btn-sm rounded-pill px-3">Reject</a>
                                        </td>
                                    </tr>
                                <?php endwhile; else: ?>
                                    <tr><td colspan="3" class="text-center py-4 text-muted">No pending requests.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- NEW FEATURE: Real-Time Intelligence Hub -->
            <div class="col-12">
                <div class="card border-0 rounded-4 shadow-sm">
                    <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                        <h5 class="fw-bold mb-0"><i class="bi bi-cpu-fill me-2 text-primary"></i>Real-Time Intelligence Hub</h5>
                        <span class="badge bg-primary-subtle text-primary rounded-pill small">LIVE AUDIT</span>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <h6 class="small fw-bold text-muted text-uppercase mb-3">Top AI Consumers (Month)</h6>
                                <?php if ($top_consumers_q && mysqli_num_rows($top_consumers_q) > 0): while($tc = mysqli_fetch_assoc($top_consumers_q)): ?>
                                <div class="mb-2 p-2 bg-light rounded-3">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="small fw-bold"><?php echo htmlspecialchars($tc['name'] ?: 'Unknown Actor'); ?></span>
                                        <span class="badge bg-dark rounded-pill"><?php echo number_format($tc['total']); ?> tkns</span>
                                    </div>
                                    <div class="x-small text-muted opacity-75 mt-1"><?php echo htmlspecialchars($tc['website_url'] ?: 'internal-request'); ?></div>
                                </div>
                                <?php endwhile; else: ?>
                                <p class="small text-muted italic">No usage data yet.</p>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <h6 class="small fw-bold text-muted text-uppercase mb-3">Service Health Metrics</h6>
                                <div class="hub-stat mb-2">
                                    <div class="small text-muted">Latency (Avg 24h)</div>
                                    <div class="fw-bold <?php echo $avg_latency > 1000 ? 'text-warning' : 'text-success'; ?>"><?php echo $avg_latency; ?>ms</div>
                                </div>
                                <div class="hub-stat">
                                    <div class="small text-muted">Security Sentinel</div>
                                    <div class="fw-bold <?php echo $blocked_count > 0 ? 'text-danger' : 'text-success'; ?>">
                                        <?php echo $blocked_count > 0 ? "$blocked_count Blocked Attempts" : "Active & Protected"; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <h6 class="small fw-bold text-muted text-uppercase mb-2">Recent Intelligence Logs</h6>
                        <div class="border rounded-3 overflow-hidden">
                            <?php if ($recent_logs_q && mysqli_num_rows($recent_logs_q) > 0): while($log = mysqli_fetch_assoc($recent_logs_q)): ?>
                            <div class="log-item d-flex justify-content-between">
                                <span>
                                    <i class="bi bi-lightning-fill text-warning me-1"></i> 
                                    <strong><?php echo htmlspecialchars($log['vendor_name']); ?></strong> 
                                    <span class="small opacity-50">(<?php echo htmlspecialchars($log['website_url'] ?: $log['username']); ?>)</span>: 
                                    <?php echo ucfirst($log['action_type']); ?>
                                </span>
                                <span class="text-muted"><?php echo number_format($log['tokens_burned']); ?> tokens · <?php echo date('H:i', strtotime($log['created_at'])); ?></span>
                            </div>
                            <?php endwhile; else: ?>
                            <div class="p-3 text-center text-muted small">Awaiting first transactions...</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- AI Cloud Models -->
            <div class="col-12">
                <div class="card border-0 rounded-4 shadow-sm">
                    <div class="card-header bg-white border-0 py-3"><h5 class="fw-bold mb-0"><i class="bi bi-grid me-2 text-primary"></i>AI Cloud Models</h5></div>
                    <div class="card-body p-4">
                        <div class="row g-3">
                            <?php foreach ($model_catalog as $mc): $is_active = ($mc['name'] === $active_model); ?>
                            <div class="col-md-6">
                                <div class="model-card <?php echo $is_active ? 'border-primary bg-primary bg-opacity-10' : ''; ?>">
                                    <div class="d-flex justify-content-between mb-2">
                                        <code class="fw-bold small"><?php echo $mc['name']; ?></code>
                                        <?php if ($is_active): ?><span class="badge bg-primary rounded-pill small">ACTIVE</span><?php endif; ?>
                                    </div>
                                    <p class="text-muted small mb-2"><?php echo $mc['desc']; ?></p>
                                    <form method="post"><input type="hidden" name="active_model_name" value="<?php echo $mc['name']; ?>"><button type="submit" name="set-active-model" class="btn btn-<?php echo $is_active?'primary':'outline-primary'; ?> btn-sm w-100 rounded-pill"><?php echo $is_active?'Live Now':'Activate'; ?></button></form>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</section>
<?php include("../func/bc-spadmin-footer.php"); ?>
</body>
</html>

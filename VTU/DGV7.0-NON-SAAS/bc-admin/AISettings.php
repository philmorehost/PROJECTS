<?php session_start();
include("../func/bc-admin-config.php");
include_once("../func/bc-ai-engine.php");

$vid = $get_logged_admin_details["id"];
$esc_vid = (int)$vid;

// Handle AI Credentials Update (New for Standalone)
if (isset($_POST['update-ai-credentials'])) {
    bc_validate_csrf();
    $provider = bc_sanitize($_POST['ai_provider'] ?? 'gemini');
    $gemini_key = bc_sanitize($_POST['ai_gemini_api_key'] ?? '');
    $deepseek_key = bc_sanitize($_POST['ai_deepseek_api_key'] ?? '');
    $groq_key = bc_sanitize($_POST['ai_groq_api_key'] ?? '');
    $model_assigned = bc_sanitize($_POST['ai_model_assigned'] ?? 'gemini-1.5-flash');

    setVendorOption(1, 'ai_provider', $provider);
    setVendorOption(1, 'ai_gemini_api_key', $gemini_key);
    setVendorOption(1, 'ai_deepseek_api_key', $deepseek_key);
    setVendorOption(1, 'ai_groq_api_key', $groq_key);
    
    // Also save default or global `ai_api_key` based on current selection
    $active_key = ($provider === 'gemini') ? $gemini_key : (($provider === 'deepseek') ? $deepseek_key : $groq_key);
    setVendorOption(1, 'ai_api_key', $active_key);

    mysqli_query($connection_server, "UPDATE sas_vendors SET ai_model_assigned='$model_assigned' WHERE id='$esc_vid'");

    $_SESSION['product_purchase_response'] = "✅ AI Provider credentials updated successfully!";
    header("Location: AISettings.php");
    exit();
}

// ── Handle: Buy AI Tokens (Free for Standalone) ─────────────
if (isset($_POST["buy-ai-tokens"])) {
    bc_validate_csrf();
    $token_amount = (int)($_POST["token_amount"] ?? 0);

    if ($token_amount > 0) {
        $new_bal = (int)$get_logged_admin_details["ai_token_balance"] + $token_amount;
        mysqli_query($connection_server, "UPDATE sas_vendors SET ai_token_balance='$new_bal' WHERE id='$esc_vid'");
        $_SESSION["product_purchase_response"] = "✅ $token_amount AI Tokens added to system balance successfully (Free of charge)!";
    } else {
        $_SESSION["product_purchase_response"] = "❌ Please enter a valid number of tokens.";
    }
    header("Location: AISettings.php");
    exit();
}

// ── Handle: Update AI Pricing for Users ──────────────────────
if (isset($_POST["update-ai-pricing"])) {
    bc_validate_csrf();
    $price = (float)($_POST["ai_user_token_price"] ?? 150.00);
    $mode  = (int)($_POST["ai_paid_usage"] ?? 1);
    
    mysqli_query($connection_server, "UPDATE sas_vendors SET ai_user_token_price='$price', ai_paid_usage='$mode' WHERE id='$esc_vid'");
    $_SESSION["product_purchase_response"] = "AI Commercialization settings updated!";
    header("Location: AISettings.php");
    exit();
}

// ── Handle: Toggle AI On/Off ─────────────────────────────────
if (isset($_POST["toggle-ai"])) {
    bc_validate_csrf();
    $new_status = (int)($_POST["ai_status"] ?? 0) === 1 ? 1 : 0;
    mysqli_query($connection_server, "UPDATE sas_vendors SET ai_status='$new_status' WHERE id='$esc_vid'");
    // Also update the vendor's own user row if they have one
    $esc_email = mysqli_real_escape_string($connection_server, $get_logged_admin_details["email"]);
    mysqli_query($connection_server, "UPDATE sas_users SET ai_status='$new_status' WHERE vendor_id='$esc_vid' AND email='$esc_email'");
    $_SESSION["product_purchase_response"] = "AI features " . ($new_status ? "enabled" : "disabled") . ".";
    header("Location: " . $_SERVER["REQUEST_URI"]);
    exit();
}

// ── Handle: Add VIP Whitelist ─────────────────────────────────
if (isset($_POST["add-whitelist"])) {
    bc_validate_csrf();
    $pid    = bc_sanitize($_POST["product_id"] ?? '');
    $limit  = bc_sanitize_number($_POST["limit_override"] ?? 0);
    $expiry = bc_sanitize($_POST["expiry"] ?? '');
    if (!empty($pid)) {
        $esc_pid = mysqli_real_escape_string($connection_server, $pid);
        $esc_exp = !empty($expiry) ? "'" . mysqli_real_escape_string($connection_server, $expiry) . "'" : "NULL";
        mysqli_query($connection_server,
            "INSERT INTO sas_customer_whitelist (vendor_id, product_id, is_whitelisted, daily_limit_override, override_expiry)
             VALUES ('$esc_vid', '$esc_pid', 1, '$limit', $esc_exp)
             ON DUPLICATE KEY UPDATE is_whitelisted=1, daily_limit_override='$limit', override_expiry=$esc_exp"
        );
        $_SESSION["product_purchase_response"] = "✅ VIP entry added for: $pid";
    }
    header("Location: " . $_SERVER["REQUEST_URI"]);
    exit();
}

// ── Handle: Remove Whitelist ──────────────────────────────────
if (isset($_GET["remove-whitelist"])) {
    $pid = bc_sanitize($_GET["remove-whitelist"]);
    $esc_pid = mysqli_real_escape_string($connection_server, $pid);
    mysqli_query($connection_server, "DELETE FROM sas_customer_whitelist WHERE vendor_id='$esc_vid' AND product_id='$esc_pid'");
    $_SESSION["product_purchase_response"] = "VIP entry removed.";
    header("Location: AISettings.php");
    exit();
}

// Handle Voice & Billing Settings Update
if (isset($_POST['set-voice-limit'])) {
    bc_validate_csrf();
    $new_min = (int)$_POST['voice_tx_threshold'];
    $new_fee = (int)$_POST['ai_voice_fee_tokens'];
    $chat_fee = (int)$_POST['ai_per_tx_cost'];
    $bonus = (int)$_POST['ai_bonus_tokens'];
    mysqli_query($connection_server, "UPDATE sas_vendors SET voice_tx_threshold='$new_min', ai_voice_fee_tokens='$new_fee', ai_per_tx_cost='$chat_fee', ai_bonus_tokens='$bonus' WHERE id='$esc_vid'");
    $_SESSION['product_purchase_response'] = "✅ AI Billing & Voice settings updated.";
    header("Location: AISettings.php");
    exit();
}

// ── Handle: Process Voice Apps ────────────────────────────────
if (isset($_POST["process-voice-app"])) {
    bc_validate_csrf();
    $uid = (int)$_POST["user_id"];
    $act = $_POST["app_action"];
    $new_stat = ($act === "approve") ? 2 : 0;
    mysqli_query($connection_server, "UPDATE sas_users SET ai_voice_status='$new_stat' WHERE id='$uid' AND vendor_id='$esc_vid'");
    $_SESSION["product_purchase_response"] = "Application " . ucfirst($act) . "d.";
    header("Location: AISettings.php");
    exit();
}

// ── Load data ─────────────────────────────────────────────────
$ai_status  = (int)($get_logged_admin_details["ai_status"] ?? 0);
$token_bal  = (int)($get_logged_admin_details["ai_token_balance"] ?? 0);
$price_1k   = (float)($get_logged_admin_details["ai_price_per_1k_tokens"] ?? 100.00);
$model_raw  = $get_logged_admin_details["ai_model_assigned"] ?? "";
$ai_engine  = ai_engine();
$model = (!empty($model_raw) && $ai_engine->isModelCompatible($model_raw)) ? $model_raw : $ai_engine->getDefaultModel();

$stored_provider = getSuperAdminOption('ai_provider', 'gemini');
$stored_gemini_key = getSuperAdminOption('ai_gemini_api_key', '');
$stored_deepseek_key = getSuperAdminOption('ai_deepseek_api_key', '');
$stored_groq_key = getSuperAdminOption('ai_groq_api_key', '');

$tx_q = mysqli_query($connection_server, "SELECT * FROM sas_ai_transactions WHERE vendor_id='$esc_vid' ORDER BY id DESC LIMIT 20");
$wl_q = mysqli_query($connection_server, "SELECT * FROM sas_customer_whitelist WHERE vendor_id='$esc_vid' ORDER BY created_at DESC LIMIT 50");
$flags_q = mysqli_query($connection_server, "SELECT * FROM sas_ai_audit_log WHERE actor LIKE '$esc_vid:%' AND event_type='SENTINEL_FLAGGED' ORDER BY created_at DESC LIMIT 10");
$usage_q = mysqli_query($connection_server, "SELECT SUM(tokens_burned) as used, COUNT(*) as calls FROM sas_ai_transactions WHERE vendor_id='$esc_vid' AND MONTH(created_at)=MONTH(NOW()) AND status='success'");
$usage = $usage_q ? mysqli_fetch_assoc($usage_q) : ['used' => 0, 'calls' => 0];

$voice_min_tx = (int)($get_logged_admin_details["voice_tx_threshold"] ?? 2);
$voice_fee_tokens = (int)($get_logged_admin_details['ai_voice_fee_tokens'] ?? 0);
$chat_fee_tokens = (int)($get_logged_admin_details['ai_per_tx_cost'] ?? 2);
$bonus_tokens = (int)($get_logged_admin_details['ai_bonus_tokens'] ?? 500);
$voice_apps_q = mysqli_query($connection_server, "SELECT id, username, email, phone_number, ai_voice_status FROM sas_users WHERE vendor_id='$esc_vid' AND ai_voice_status IN (1,2) ORDER BY ai_voice_status ASC, id DESC LIMIT 20");

$top_consumers_q = mysqli_query($connection_server, "SELECT username, SUM(tokens_burned) as total FROM sas_ai_transactions WHERE vendor_id='$esc_vid' AND MONTH(created_at)=MONTH(NOW()) AND status='success' GROUP BY username ORDER BY total DESC LIMIT 5");
$recent_intelligence_q = mysqli_query($connection_server, "SELECT * FROM sas_ai_transactions WHERE vendor_id='$esc_vid' ORDER BY id DESC LIMIT 5");

$health_q = mysqli_query($connection_server, "SELECT AVG(duration_ms) as avg_lat FROM sas_ai_transactions WHERE vendor_id='$esc_vid' AND status='success' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
$health = ($health_q) ? mysqli_fetch_assoc($health_q) : ['avg_lat' => 0];
$v_avg_latency = ($health && $health['avg_lat'] > 0) ? round($health['avg_lat']) : 450;

$blocked_q = mysqli_query($connection_server, "SELECT COUNT(*) as blocked FROM sas_ai_transactions WHERE vendor_id='$esc_vid' AND status='blocked' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
$v_blocked_count = ($blocked_q && $row_b = mysqli_fetch_assoc($blocked_q)) ? $row_b['blocked'] : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>AI Control Center | <?php echo $get_all_super_admin_site_details["site_title"] ?? 'Vendor Dashboard'; ?></title>
    <meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
    <style>
        :root { 
            --ai-primary: #6366f1; --ai-secondary: #a855f7; 
            --ai-dark: #0f172a; --ai-glass: rgba(255, 255, 255, 0.9);
            --ai-accent: <?php echo $vendor_primary_color ?? '#6366f1'; ?>;
        }
        body { background: #f8fafc; font-family: 'Inter', sans-serif; }
        .ai-header-banner {
            background: linear-gradient(135deg, var(--ai-dark), #1e293b);
            border-radius: 2rem; padding: 3.5rem 2.5rem; color: white;
            position: relative; overflow: hidden; margin-bottom: 2rem;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
        }
        .ai-header-banner::before {
            content: 'CORE'; position: absolute; right: -20px; top: -20px;
            font-size: 12rem; font-weight: 900; opacity: 0.03; font-style: italic;
        }
        .ai-glass-card {
            background: var(--ai-glass); backdrop-filter: blur(12px);
            border: 1px solid rgba(255,255,255,0.4); border-radius: 1.5rem;
            transition: all 0.3s ease; box-shadow: 0 4px 15px rgba(0,0,0,0.03);
        }
        .ai-glass-card:hover { transform: translateY(-5px); box-shadow: 0 10px 25px rgba(0,0,0,0.06); }
        .nav-tabs-ai { border: none; background: #e2e8f0; border-radius: 1rem; padding: 0.5rem; display: inline-flex; }
        .nav-tabs-ai .nav-link { 
            border: none; border-radius: 0.75rem; color: #64748b; font-weight: 600;
            padding: 0.6rem 1.5rem; transition: all 0.2s;
        }
        .nav-tabs-ai .nav-link.active { background: white; color: var(--ai-dark); shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .ai-btn-primary {
            background: linear-gradient(135deg, var(--ai-primary), var(--ai-secondary));
            border: none; color: white; font-weight: 700; border-radius: 1rem;
            padding: 0.8rem 2rem; transition: transform 0.2s;
        }
        .ai-btn-primary:hover { transform: scale(1.02); color: white; opacity: 0.9; }
        .token-stat-pill { background: rgba(255,255,255,0.1); border-radius: 1rem; padding: 1rem; border: 1px solid rgba(255,255,255,0.1); }
        .log-row { border-left: 3px solid transparent; transition: all 0.2s; }
        .log-row:hover { border-left-color: var(--ai-primary); background: #f1f5f9; }
        .pulse-active { width: 10px; height: 10px; background: #10b981; border-radius: 50%; display: inline-block; box-shadow: 0 0 0 rgba(16,185,129,0.4); animation: pulse 2s infinite; }
        @keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(16,185,129,0.7); } 70% { box-shadow: 0 0 0 10px rgba(16,185,129,0); } 100% { box-shadow: 0 0 0 0 rgba(16,185,129,0); } }
    </style>
</head>
<body>
<?php include("../func/bc-admin-header.php"); ?>

    <div class="pagetitle mb-4">
        <h1 class="fw-900">Intelligence Command Center</h1>
        <nav><ol class="breadcrumb"><li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li><li class="breadcrumb-item active">AI Control</li></ol></nav>
    </div>

    <section class="section dashboard">
        <?php if (isset($_SESSION["product_purchase_response"])): ?>
            <div class="alert alert-info border-0 rounded-4 shadow-sm animate__animated animate__fadeInDown">
                <i class="bi bi-info-circle me-2"></i> <?php echo $_SESSION["product_purchase_response"]; unset($_SESSION["product_purchase_response"]); ?>
            </div>
        <?php endif; ?>

        <!-- PREMIUM HERO BANNER -->
        <div class="ai-header-banner animate__animated animate__fadeIn">
        <div class="row align-items-center">
            <div class="col-lg-7">
                <div class="d-flex align-items-center mb-3">
                    <span class="badge bg-primary rounded-pill px-3 py-2 me-3"><i class="bi bi-shield-fill-check me-1"></i> Core v6.9.6</span>
                    <div class="pulse-active me-2"></div> <small class="text-white opacity-75">AI System Online</small>
                </div>
                <h1 class="display-5 fw-bold mb-3">Optimize Your Platform with Artificial Intelligence</h1>
                <p class="lead opacity-75 mb-4">You are currently running <strong><?php echo htmlspecialchars($model); ?></strong>. Your platform is protected by the Security Sentinel and commercialized for user tokens.</p>
                <div class="d-flex gap-3">
                    <form method="post">
                        <?php echo bc_csrf_field(); ?>
                        <input type="hidden" name="ai_status" value="<?php echo $ai_status ? 0 : 1; ?>">
                        <button type="submit" name="toggle-ai" class="btn btn-<?php echo $ai_status ? 'outline-warning' : 'light'; ?> rounded-pill px-4 fw-bold">
                            <?php echo $ai_status ? 'Pause AI Engine' : 'Resume AI Engine'; ?>
                        </button>
                    </form>
                    <button class="btn btn-glass bg-white bg-opacity-10 text-white rounded-pill px-4 border-0" onclick="window.location.reload()"><i class="bi bi-arrow-repeat me-1"></i> Sync Status</button>
                </div>
            </div>
            <div class="col-lg-5 mt-4 mt-lg-0">
                <div class="row g-3">
                    <div class="col-6">
                        <div class="token-stat-pill h-100">
                            <div class="small opacity-50 mb-1">Available Tokens</div>
                            <h3 class="fw-bold mb-0"><?php echo number_format($token_bal); ?></h3>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="token-stat-pill h-100">
                            <div class="small opacity-50 mb-1">Monthly Requests</div>
                            <h3 class="fw-bold mb-0"><?php echo number_format($usage['calls']); ?></h3>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="token-stat-pill">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="small opacity-50">Assigned Model</span>
                                <span class="badge bg-white text-dark rounded-pill"><?php echo strtoupper($model); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- TABS NAVIGATION -->
    <div class="text-center mb-5">
        <div class="nav nav-tabs-ai shadow-sm" role="tablist">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-commerce">Commerce & Tokens</button>
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-overview">Intelligence Hub</button>
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-security">Security Sentinel</button>
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-voice">Voice Control</button>
        </div>
    </div>

    <div class="tab-content animate__animated animate__fadeInUp">
        
        <!-- OVERVIEW TAB -->
        <div class="tab-pane fade" id="tab-overview">
            <div class="row g-4">
                <div class="col-lg-8">
                    <div class="ai-glass-card p-4">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h5 class="fw-bold mb-0"><i class="bi bi-graph-up-arrow me-2 text-primary"></i>Real-Time Activity</h5>
                            <span class="x-small text-muted">Auto-refreshing every 60s</span>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-borderless align-middle mb-0">
                                <thead><tr class="x-small text-muted text-uppercase"><th>Actor</th><th>Action</th><th>Cost</th><th class="text-end">Time</th></tr></thead>
                                <tbody>
                                    <?php if ($recent_intelligence_q && mysqli_num_rows($recent_intelligence_q) > 0): while($log = mysqli_fetch_assoc($recent_intelligence_q)): ?>
                                    <tr class="log-row">
                                        <td><div class="small fw-bold text-dark"><?php echo htmlspecialchars($log['username'] ?? 'System'); ?></div></td>
                                        <td><span class="badge bg-light text-primary rounded-pill"><?php echo ucfirst($log['action_type'] ?? 'query'); ?></span></td>
                                        <td><small class="text-muted"><?php echo number_format($log['tokens_burned'] ?? 0); ?> tkns</small></td>
                                        <td class="text-end text-muted x-small"><?php echo date('H:i', strtotime($log['created_at'])); ?></td>
                                    </tr>
                                    <?php endwhile; else: ?>
                                    <tr><td colspan="4" class="text-center py-5 text-muted">Awaiting first transactions...</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="ai-glass-card p-4 mb-4">
                        <h6 class="fw-bold mb-3">System Health</h6>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between small mb-1"><span>Latency (Avg 24h)</span><span class="fw-bold"><?php echo $v_avg_latency; ?>ms</span></div>
                            <div class="progress" style="height: 6px;"><div class="progress-bar bg-primary" style="width: <?php echo min(100, $v_avg_latency/10); ?>%"></div></div>
                        </div>
                        <div class="p-3 bg-light rounded-4">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="small">Shield Status</span>
                                <span class="badge bg-success-subtle text-success rounded-pill px-3">PROTECTED</span>
                            </div>
                        </div>
                    </div>
                    <div class="ai-glass-card p-4">
                        <h6 class="fw-bold mb-3">Top Consumers (Month)</h6>
                        <?php if ($top_consumers_q && mysqli_num_rows($top_consumers_q) > 0): while($tc = mysqli_fetch_assoc($top_consumers_q)): ?>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="small fw-bold"><?php echo htmlspecialchars($tc['username']); ?></span>
                            <span class="text-muted small"><?php echo number_format($tc['total']); ?> tkns</span>
                        </div>
                        <?php endwhile; else: ?>
                        <p class="small text-muted italic">No usage data yet.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- COMMERCE TAB -->
        <div class="tab-pane fade show active" id="tab-commerce">
            <div class="row g-4">
                <div class="col-md-6">
                    <div class="ai-glass-card p-4 h-100 animate__animated animate__fadeInLeft">
                        <h5 class="fw-bold mb-4 text-primary"><i class="bi bi-cpu-fill me-2"></i>AI Provider & Credentials</h5>
                        <form method="post">
                            <?php echo bc_csrf_field(); ?>
                            <div class="mb-3">
                                <label class="form-label small fw-bold">Active AI Provider</label>
                                <select name="ai_provider" class="form-select border-0 bg-light rounded-4" onchange="toggleProviderFields(this.value)">
                                    <option value="gemini" <?php echo $stored_provider === 'gemini' ? 'selected' : ''; ?>>Google Gemini</option>
                                    <option value="deepseek" <?php echo $stored_provider === 'deepseek' ? 'selected' : ''; ?>>DeepSeek AI</option>
                                    <option value="groq" <?php echo $stored_provider === 'groq' ? 'selected' : ''; ?>>Groq (Llama / Mixtral)</option>
                                </select>
                            </div>
                            
                            <div class="mb-3 provider-field field-gemini">
                                <label class="form-label small fw-bold">Gemini API Key</label>
                                <input type="password" name="ai_gemini_api_key" class="form-control border-0 bg-light rounded-4" value="<?php echo htmlspecialchars($stored_gemini_key); ?>" placeholder="AIzaSy...">
                            </div>

                            <div class="mb-3 provider-field field-deepseek" style="display:none;">
                                <label class="form-label small fw-bold">DeepSeek API Key</label>
                                <input type="password" name="ai_deepseek_api_key" class="form-control border-0 bg-light rounded-4" value="<?php echo htmlspecialchars($stored_deepseek_key); ?>" placeholder="sk-...">
                            </div>

                            <div class="mb-3 provider-field field-groq" style="display:none;">
                                <label class="form-label small fw-bold">Groq API Key</label>
                                <input type="password" name="ai_groq_api_key" class="form-control border-0 bg-light rounded-4" value="<?php echo htmlspecialchars($stored_groq_key); ?>" placeholder="gsk_...">
                            </div>

                            <div class="mb-4">
                                <label class="form-label small fw-bold">AI Model Assigned</label>
                                <input type="text" name="ai_model_assigned" class="form-control border-0 bg-light rounded-4" value="<?php echo htmlspecialchars($model); ?>" placeholder="e.g. gemini-1.5-flash">
                                <small class="text-muted">Will use defaults if empty.</small>
                            </div>

                            <button type="submit" name="update-ai-credentials" class="ai-btn-primary w-100 py-3">Save AI Credentials</button>
                        </form>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <!-- Token Refill -->
                    <div class="ai-glass-card p-4 mb-4 animate__animated animate__fadeInRight">
                        <h5 class="fw-bold mb-3 text-warning"><i class="bi bi-gift-fill me-2"></i>Free AI Tokens Refill</h5>
                        <form method="post">
                            <?php echo bc_csrf_field(); ?>
                            <div class="mb-3">
                                <label class="form-label small fw-bold">Refill Amount</label>
                                <input type="number" name="token_amount" class="form-control border-0 bg-light rounded-4" value="50000" min="1000" step="1000">
                            </div>
                            <div class="p-3 bg-success bg-opacity-10 rounded-4 mb-3 border border-success border-opacity-20">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="small text-success fw-bold"><i class="bi bi-check-circle-fill me-1"></i> Cost Mode</span>
                                    <span class="badge bg-success rounded-pill px-3 py-2">₦0.00 (FREE)</span>
                                </div>
                            </div>
                            <button type="submit" name="buy-ai-tokens" class="btn btn-warning w-100 py-2 rounded-4 fw-bold">Refill Tokens for Free</button>
                        </form>
                    </div>
                    
                    <!-- User Monetization -->
                    <div class="ai-glass-card p-4 animate__animated animate__fadeInRight" style="animation-delay: 0.1s;">
                        <h5 class="fw-bold mb-3 text-success"><i class="bi bi-cash-stack me-2"></i>User Monetization</h5>
                        <form method="post">
                            <?php echo bc_csrf_field(); ?>
                            <div class="mb-3">
                                <label class="form-label small fw-bold">Your Retail Price (per 1k tokens)</label>
                                <div class="input-group">
                                    <span class="input-group-text border-0 bg-light">₦</span>
                                    <input type="number" name="ai_user_token_price" class="form-control border-0 bg-light" value="<?php echo $get_logged_admin_details['ai_user_token_price'] ?? 50.00; ?>" step="any">
                                </div>
                                <small class="text-muted">Pure profit when your users use AI services.</small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-bold">Billing Mode</label>
                                <select name="ai_paid_usage" class="form-select border-0 bg-light rounded-4">
                                    <option value="1" <?php echo ($get_logged_admin_details['ai_paid_usage'] ?? 1) == 1 ? 'selected' : ''; ?>>Strict Paid (Users must buy tokens)</option>
                                    <option value="0" <?php echo ($get_logged_admin_details['ai_paid_usage'] ?? 1) == 0 ? 'selected' : ''; ?>>Free Capped (Demo Mode)</option>
                                </select>
                            </div>
                            <button name="update-ai-pricing" type="submit" class="btn btn-dark w-100 py-2 rounded-4 fw-bold">Save Monetization Settings</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- SECURITY TAB -->
        <div class="tab-pane fade" id="tab-security">
            <div class="row g-4">
                <div class="col-lg-6">
                    <div class="ai-glass-card p-4 h-100">
                        <h5 class="fw-bold mb-4"><i class="bi bi-shield-lock me-2 text-danger"></i>VIP Whitelist</h5>
                        <form method="post" class="mb-4">
                            <?php echo bc_csrf_field(); ?>
                            <div class="row g-2">
                                <div class="col-8"><input type="text" name="product_id" class="form-control rounded-4" placeholder="Phone or Account Number" required></div>
                                <div class="col-4"><button type="submit" name="add-whitelist" class="btn btn-primary w-100 rounded-4">Add VIP</button></div>
                            </div>
                        </form>
                        <div class="table-responsive" style="max-height: 300px;">
                            <table class="table table-sm align-middle">
                                <thead class="x-small text-muted text-uppercase"><tr><th>Target</th><th>Expires</th><th></th></tr></thead>
                                <tbody>
                                    <?php if ($wl_q && mysqli_num_rows($wl_q) > 0): while($wl = mysqli_fetch_assoc($wl_q)): ?>
                                    <tr>
                                        <td><span class="fw-bold small"><?php echo htmlspecialchars($wl['product_id']); ?></span></td>
                                        <td><small><?php echo $wl['override_expiry'] ? date('M j Y', strtotime($wl['override_expiry'])) : 'Permanent'; ?></small></td>
                                        <td class="text-end"><a href="AISettings.php?remove-whitelist=<?php echo urlencode($wl['product_id']); ?>" class="btn btn-light btn-sm rounded-circle">✕</a></td>
                                    </tr>
                                    <?php endwhile; else: ?>
                                    <tr><td colspan="3" class="text-center py-4 text-muted small">No VIPs added yet.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="ai-glass-card p-4 h-100">
                        <h5 class="fw-bold mb-4"><i class="bi bi-flag me-2 text-danger"></i>Sentinel Flagged Events</h5>
                        <?php if ($flags_q && mysqli_num_rows($flags_q) > 0): while($flag = mysqli_fetch_assoc($flags_q)): ?>
                        <div class="p-3 mb-2 rounded-4 bg-danger bg-opacity-10 border border-danger border-opacity-10">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="fw-bold small"><?php echo htmlspecialchars($flag['action']); ?></span>
                                <span class="x-small text-muted"><?php echo date('H:i', strtotime($flag['created_at'])); ?></span>
                            </div>
                            <div class="x-small opacity-75"><?php echo htmlspecialchars(substr($flag['detail'], 0, 150)); ?></div>
                        </div>
                        <?php endwhile; else: ?>
                        <div class="text-center py-5">
                            <i class="bi bi-shield-check text-success display-5 mb-3 d-block"></i>
                            <p class="text-muted">No suspicious activity detected. System is clean.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- VOICE TAB -->
        <div class="tab-pane fade" id="tab-voice">
            <div class="row g-4">
                <div class="col-lg-4">
                    <div class="ai-glass-card p-4">
                        <h5 class="fw-bold mb-4"><i class="bi bi-mic me-2 text-primary"></i>Billing & Thresholds</h5>
                        <form method="post">
                            <?php echo bc_csrf_field(); ?>
                            <div class="mb-3">
                                <label class="small fw-bold">General Chat Fee (Tokens)</label>
                                <input type="number" name="ai_per_tx_cost" class="form-control rounded-4" value="<?php echo $chat_fee_tokens; ?>">
                                <small class="text-muted">Charged for every AI response in chat.</small>
                            </div>
                            <div class="mb-3">
                                <label class="small fw-bold">Successful Execution Fee (Tokens)</label>
                                <input type="number" name="ai_voice_fee_tokens" class="form-control rounded-4" value="<?php echo $voice_fee_tokens; ?>">
                                <small class="text-muted">Extra charge for successful VTU execution.</small>
                            </div>
                            <div class="mb-3">
                                <label class="small fw-bold">First Purchase Bonus Tokens</label>
                                <input type="number" name="ai_bonus_tokens" class="form-control rounded-4" value="<?php echo $bonus_tokens; ?>">
                                <small class="text-muted">Bonus tokens added to a user's first AI token purchase.</small>
                            </div>
                            <div class="mb-4">
                                <label class="small fw-bold">Min Success Tx for User Activation</label>
                                <input type="number" name="voice_tx_threshold" class="form-control rounded-4" value="<?php echo $voice_min_tx; ?>">
                            </div>
                            <button type="submit" name="set-voice-limit" class="ai-btn-primary w-100">Save AI Billing Rules</button>
                        </form>
                    </div>
                </div>
                <div class="col-lg-8">
                    <div class="ai-glass-card p-4">
                        <h5 class="fw-bold mb-4">Pending Approvals</h5>
                        <div class="table-responsive">
                            <table class="table align-middle">
                                <thead class="x-small text-muted text-uppercase"><tr><th>User</th><th>Status</th><th class="text-end">Action</th></tr></thead>
                                <tbody>
                                    <?php if ($voice_apps_q && mysqli_num_rows($voice_apps_q) > 0): while($app = mysqli_fetch_assoc($voice_apps_q)): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold small"><?php echo htmlspecialchars($app['username']); ?></div>
                                            <div class="x-small text-muted"><?php echo htmlspecialchars($app['phone_number']); ?></div>
                                        </td>
                                        <td><span class="badge <?php echo $app['ai_voice_status'] == 1 ? 'bg-warning text-dark' : 'bg-success'; ?> rounded-pill"><?php echo $app['ai_voice_status'] == 1 ? 'Pending' : 'Approved'; ?></span></td>
                                        <td class="text-end">
                                            <?php if ($app['ai_voice_status'] == 1): ?>
                                            <form method="post" class="d-inline">
                                                <?php echo bc_csrf_field(); ?>
                                                <input type="hidden" name="user_id" value="<?php echo $app['id']; ?>">
                                                <input type="hidden" name="app_action" value="approve" id="act_<?php echo $app['id']; ?>">
                                                <button type="submit" name="process-voice-app" onclick="document.getElementById('act_<?php echo $app['id']; ?>').value='approve'" class="btn btn-success btn-sm rounded-circle shadow-sm me-1"><i class="bi bi-check2"></i></button>
                                                <button type="submit" name="process-voice-app" onclick="document.getElementById('act_<?php echo $app['id']; ?>').value='reject'" class="btn btn-light btn-sm rounded-circle shadow-sm">✕</button>
                                            </form>
                                            <?php else: ?>
                                            <i class="bi bi-patch-check-fill text-primary"></i>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endwhile; else: ?>
                                    <tr><td colspan="3" class="text-center py-4 text-muted">No applications found.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- USAGE HISTORY FOOTER -->
    <div class="mt-5">
        <div class="ai-glass-card p-4 overflow-hidden">
            <h5 class="fw-bold mb-4"><i class="bi bi-clock-history me-2"></i>Global Usage History</h5>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light x-small text-muted text-uppercase">
                        <tr><th>Action</th><th>Model</th><th>Tokens</th><th>Status</th><th class="text-end">Timestamp</th></tr>
                    </thead>
                    <tbody>
                        <?php if ($tx_q && mysqli_num_rows($tx_q) > 0): while($h = mysqli_fetch_assoc($tx_q)): ?>
                        <tr>
                            <td><span class="fw-bold small"><?php echo ucfirst($h['action_type']); ?></span></td>
                            <td><small class="text-muted"><?php echo $h['model_used'] ?: 'Default'; ?></small></td>
                            <td><span class="badge bg-light text-dark border"><?php echo number_format($h['tokens_burned']); ?></span></td>
                            <td><i class="bi bi-check-circle-fill text-success small"></i> Success</td>
                            <td class="text-end x-small text-muted"><?php echo date('M j, Y H:i', strtotime($h['created_at'])); ?></td>
                        </tr>
                        <?php endwhile; else: ?>
                        <tr><td colspan="5" class="text-center py-5 text-muted">No history found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

<script src="../assets-2/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script>
    function toggleProviderFields(provider) {
        document.querySelectorAll('.provider-field').forEach(function(el) {
            el.style.display = 'none';
        });
        const activeField = document.querySelector('.field-' + provider);
        if (activeField) {
            activeField.style.display = 'block';
        }
    }
    
    // Run on page load to show the saved provider's key field
    document.addEventListener('DOMContentLoaded', function() {
        const provider = document.querySelector('select[name="ai_provider"]').value;
        toggleProviderFields(provider);
    });
</script>

<?php include("../func/bc-admin-footer.php"); ?>
</body>
</html>

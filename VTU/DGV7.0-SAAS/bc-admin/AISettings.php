<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
bc_secure_session_start();
include("../func/bc-admin-config.php");
include_once("../func/bc-ai-engine.php");
include_once("../func/bc-kyc-av-scanner.php");
include_once("../func/bc-support-chat.php");
include_once("../func/bc-security.php");

$vid = (int)$get_logged_admin_details["id"];
$esc_vid = $vid;
$is_saas = bc_ai_is_saas();

if (function_exists('bc_kyc_av_ensure_schema')) bc_kyc_av_ensure_schema($connection_server);
if (function_exists('bc_kyc_ai_ensure_schema')) bc_kyc_ai_ensure_schema($connection_server);
if (function_exists('bc_chat_schema_ready') && !bc_chat_schema_ready() && function_exists('bc_chat_ensure_schema')) {
    bc_chat_ensure_schema();
}

$active_tab = trim((string)($_GET['tab'] ?? 'billing'));
if (!in_array($active_tab, ['billing', 'support', 'sentinel', 'reports'], true)) {
    $active_tab = 'billing';
}

// ── Handle: Toggle Master AI Engine On/Off ──────────────────────────────────
if (isset($_POST["toggle-ai"])) {
    bc_validate_csrf();
    $new_status = (int)($_POST["ai_status"] ?? 0) === 1 ? 1 : 0;
    @mysqli_query($connection_server, "UPDATE sas_vendors SET ai_status='$new_status', kyc_ai_enabled='$new_status' WHERE id='$esc_vid'");
    $_SESSION["product_purchase_response"] = "AI Business Suite " . ($new_status ? "enabled." : "paused.");
    header("Location: AISettings.php?tab=" . urlencode($active_tab));
    exit();
}

// ── Handle: SAAS Activation Request ─────────────────────────────────────────
if ($is_saas && isset($_POST['request-ai-activation'])) {
    bc_validate_csrf();
    $package = $_POST['token_package'] ?? 'starter';
    $packages = [
        'starter'  => ['tokens' => 5000,   'price' => 500],
        'business' => ['tokens' => 20000,  'price' => 1800],
        'scale'    => ['tokens' => 100000, 'price' => 7500]
    ];
    $p = $packages[$package] ?? $packages['starter'];
    $tokens = $p['tokens'];
    $cost = $p['price'];

    if ($get_logged_admin_details['balance'] >= $cost) {
        $ref = "AIREQ_" . time();
        $desc = "AI Business Suite Activation ($tokens tokens)";
        $charge = chargeVendor("debit", "ai_activation", "AI Suite", $ref, $cost, $cost, $desc, $_SERVER["HTTP_HOST"], 1);
        if ($charge === 'success') {
            @mysqli_query($connection_server, "UPDATE sas_vendors SET ai_request_status='pending', ai_pending_cost='$cost', ai_pending_tokens='$tokens' WHERE id='$esc_vid'");
            bc_trigger_high_alert("AI_ACTIVATION_REQUEST", "AI activation request from " . ($get_logged_admin_details['company_name'] ?: $get_logged_admin_details['email']) . " (" . number_format($tokens) . " tokens / NGN " . number_format($cost, 2) . ")");
            $_SESSION['product_purchase_response'] = "✅ Request submitted! Super Admin has been notified for approval.";
        } else {
            $_SESSION['product_purchase_response'] = "❌ Transaction failed. Please try again.";
        }
    } else {
        $_SESSION['product_purchase_response'] = "❌ Insufficient wallet balance to activate AI. Please fund your wallet first.";
    }
    header("Location: AISettings.php?tab=billing");
    exit();
}

// ── Handle: SAAS Purchase AI Tokens ─────────────────────────────────────────
if ($is_saas && isset($_POST["buy-ai-tokens"])) {
    bc_validate_csrf();
    $token_amount = (int)($_POST["token_amount"] ?? 0);
    $price_per_1k = (float)($get_logged_admin_details["ai_price_per_1k_tokens"] ?? getSuperAdminOption('ai_price_per_1k_tokens', 100));
    if ($price_per_1k <= 0) $price_per_1k = 100.0;
    $cost = ($token_amount / 1000) * $price_per_1k;

    if ($token_amount >= 100 && $cost > 0 && $get_logged_admin_details["balance"] >= $cost) {
        $ref = "AITKN_" . time() . rand(10, 99);
        $desc = "Purchase of $token_amount AI Business Suite Tokens";
        $charge = chargeVendor("debit", "ai_tokens", "AI Tokens", $ref, $cost, $cost, $desc, $_SERVER["HTTP_HOST"], 1);
        if ($charge === "success") {
            $new_bal = (int)$get_logged_admin_details["ai_token_balance"] + $token_amount;
            @mysqli_query($connection_server, "UPDATE sas_vendors SET ai_token_balance='$new_bal' WHERE id='$esc_vid'");
            $_SESSION["product_purchase_response"] = "✅ " . number_format($token_amount) . " AI Tokens added to your vendor balance!";
        } else {
            $_SESSION["product_purchase_response"] = "❌ Token purchase failed. Check your balance.";
        }
    } else {
        $_SESSION["product_purchase_response"] = "❌ Minimum purchase is 100 tokens. Ensure you have sufficient wallet balance.";
    }
    header("Location: AISettings.php?tab=billing");
    exit();
}

// ── Handle: NON-SAAS Direct API Credentials & Connection Test ───────────────
if (!$is_saas && isset($_POST['update-ai-credentials'])) {
    bc_validate_csrf();
    $provider       = bc_sanitize($_POST['ai_provider'] ?? 'gemini');
    $model_assigned = bc_sanitize($_POST['ai_model_assigned'] ?? '');
    $vision_prov    = bc_sanitize($_POST['ai_vision_provider'] ?? '');
    $vision_model   = bc_sanitize($_POST['ai_vision_model'] ?? '');

    $key_fields = [
        'gemini'      => trim((string)($_POST['ai_gemini_api_key'] ?? '')),
        'openai'      => trim((string)($_POST['ai_openai_api_key'] ?? '')),
        'anthropic'   => trim((string)($_POST['ai_anthropic_api_key'] ?? '')),
        'deepseek'    => trim((string)($_POST['ai_deepseek_api_key'] ?? '')),
        'groq'        => trim((string)($_POST['ai_groq_api_key'] ?? '')),
        'huggingface' => trim((string)($_POST['ai_huggingface_api_key'] ?? '')),
    ];
    $ollama_url = trim((string)($_POST['ai_ollama_url'] ?? 'http://localhost:11434'));

    setVendorOption(1, 'ai_provider', $provider);
    setVendorOption(1, 'ai_ollama_url', $ollama_url);
    setVendorOption(1, 'ai_vision_provider', $vision_prov);
    setVendorOption(1, 'ai_vision_model', $vision_model);

    foreach ($key_fields as $slug => $val) {
        if ($val !== '') {
            setVendorOption(1, "ai_{$slug}_api_key", $val);
        }
    }
    $active_key = $key_fields[$provider] ?? '';
    if ($active_key === '' && function_exists('getSuperAdminOption')) {
        $active_key = (string)getSuperAdminOption("ai_{$provider}_api_key", '');
    }
    if ($active_key !== '') {
        setVendorOption(1, 'ai_api_key', $active_key);
    }

    if ($model_assigned !== '') {
        $esc_m = mysqli_real_escape_string($connection_server, $model_assigned);
        @mysqli_query($connection_server, "UPDATE sas_vendors SET ai_model_assigned='$esc_m', ai_status=1, kyc_ai_enabled=1 WHERE id='$esc_vid'");
    } else {
        @mysqli_query($connection_server, "UPDATE sas_vendors SET ai_status=1, kyc_ai_enabled=1 WHERE id='$esc_vid'");
    }
    $_SESSION['product_purchase_response'] = "✅ Direct AI API credentials saved & engine activated!";
    header("Location: AISettings.php?tab=billing");
    exit();
}

if (!$is_saas && isset($_POST['test-ai-connection'])) {
    bc_validate_csrf();
    $ai_test = new AIEngine();
    $prov = $ai_test->getProvider();
    $url = $ai_test->getBaseUrlForProvider($prov);
    $key = $ai_test->getApiKeyForProvider($prov);
    $headers = [];
    if ($prov === 'gemini') {
        $url = "https://generativelanguage.googleapis.com/v1beta/models?key=" . rawurlencode($key);
    } elseif ($prov === 'anthropic') {
        $url = rtrim($url, '/') . '/models';
        $headers[] = "x-api-key: $key";
        $headers[] = "anthropic-version: 2023-06-01";
    } elseif ($prov === 'ollama') {
        $url = rtrim($url, '/') . '/api/tags';
    } else {
        $url = rtrim($url, '/') . '/models';
        if ($key !== '') $headers[] = "Authorization: Bearer $key";
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);
    if ($res !== false && ($info['http_code'] ?? 500) < 400) {
        $_SESSION['product_purchase_response'] = "✅ Direct connection successful! " . ucfirst($prov) . " is reachable.";
    } else {
        $_SESSION['product_purchase_response'] = "❌ Connection failed for " . ucfirst($prov) . ": " . ($err ?: "HTTP " . ($info['http_code'] ?? 0));
    }
    header("Location: AISettings.php?tab=billing");
    exit();
}

// ── Handle: Merged AI Support Settings (replaces standalone ChatSettings) ────
if (isset($_POST['save-support-ai-settings'])) {
    bc_validate_csrf();
    $enabled     = isset($_POST['chat_enabled']) ? '1' : '0';
    $ai_on       = isset($_POST['chat_ai_enabled']) ? '1' : '0';
    $ctx_on      = isset($_POST['chat_ai_context_enabled']) ? '1' : '0';
    $welcome     = trim((string)($_POST['chat_welcome'] ?? ''));
    $handoff     = trim((string)($_POST['chat_handoff'] ?? ''));
    $quick_raw   = trim((string)($_POST['chat_quick_replies'] ?? ''));
    $quick_lines = array_slice(array_values(array_filter(array_map(function ($l) {
        return substr(trim(strip_tags($l)), 0, 40);
    }, preg_split('/\r\n|\r|\n/', $quick_raw)))), 0, 5);

    bc_chat_setting_save($vid, 'chat_enabled', $enabled);
    bc_chat_setting_save($vid, 'chat_ai_enabled', $ai_on);
    bc_chat_setting_save($vid, 'chat_ai_context_enabled', $ctx_on);
    if ($welcome !== '') bc_chat_setting_save($vid, 'chat_welcome', $welcome);
    if ($handoff !== '') bc_chat_setting_save($vid, 'chat_handoff', $handoff);
    bc_chat_setting_save($vid, 'chat_quick_replies', implode("\n", $quick_lines));

    if (isset($_POST['chat_ai_key_clear'])) {
        bc_chat_setting_save($vid, 'chat_ai_key', '');
    } else {
        $fallback_key = trim((string)($_POST['chat_ai_key'] ?? ''));
        if ($fallback_key !== '') bc_chat_setting_save($vid, 'chat_ai_key', $fallback_key);
    }

    $_SESSION["product_purchase_response"] = "✅ AI Support settings saved.";
    header("Location: AISettings.php?tab=support");
    exit();
}

// ── Handle: Security Sentinel Settings & Account Enforcement ────────────────
if (isset($_POST['save-sentinel-settings'])) {
    bc_validate_csrf();
    bc_ai_sentinel_save_settings($connection_server, $vid, [
        'sentinel_auto_block'      => isset($_POST['sentinel_auto_block']) ? '1' : '0',
        'sentinel_block_threshold' => (string)max(5, min(60, (int)($_POST['sentinel_block_threshold'] ?? 20))),
        'sentinel_anomaly_alert'   => isset($_POST['sentinel_anomaly_alert']) ? '1' : '0',
        'sentinel_av_auto_flag'    => isset($_POST['sentinel_av_auto_flag']) ? '1' : '0',
    ]);
    $_SESSION["product_purchase_response"] = "✅ Security Sentinel rules updated.";
    header("Location: AISettings.php?tab=sentinel");
    exit();
}

if (isset($_POST['sentinel_user_action']) && isset($_POST['target_username'])) {
    bc_validate_csrf();
    $target_u = trim((string)$_POST['target_username']);
    $s_act = trim((string)$_POST['sentinel_user_action']);
    $esc_u = mysqli_real_escape_string($connection_server, $target_u);
    if ($target_u !== '') {
        if ($s_act === 'block') {
            bc_ai_sentinel_auto_block($connection_server, $vid, $target_u, 'Manually blocked by Admin via Security Sentinel', 0);
            $_SESSION["product_purchase_response"] = "🔒 Account @{$target_u} has been blocked.";
        } elseif ($s_act === 'unblock') {
            @mysqli_query($connection_server, "UPDATE sas_users SET is_blocked=0, failed_login_count=0 WHERE vendor_id='$esc_vid' AND username='$esc_u'");
            @mysqli_query($connection_server, "DELETE FROM sas_blocked_accounts WHERE vendor_id='$esc_vid' AND username='$esc_u'");
            if (function_exists('bc_log_security_event')) {
                bc_log_security_event('SENTINEL_UNBLOCKED', 'admin_unblock', $esc_vid . ':' . $target_u, 'Unblocked from AI Security Sentinel');
            }
            $_SESSION["product_purchase_response"] = "🔓 Account @{$target_u} unblocked.";
        } elseif ($s_act === 'reset_trust') {
            @mysqli_query($connection_server, "UPDATE sas_users SET trust_score=65, is_blocked=0 WHERE vendor_id='$esc_vid' AND username='$esc_u'");
            @mysqli_query($connection_server, "DELETE FROM sas_blocked_accounts WHERE vendor_id='$esc_vid' AND username='$esc_u'");
            if (function_exists('bc_log_security_event')) {
                bc_log_security_event('SENTINEL_TRUST_RESET', 'admin_reset_trust', $esc_vid . ':' . $target_u, 'Trust score reset to 65');
            }
            $_SESSION["product_purchase_response"] = "✅ Trust score reset to 65 for @{$target_u}.";
        }
    }
    header("Location: AISettings.php?tab=sentinel");
    exit();
}

// ── Handle: VIP Whitelist ───────────────────────────────────────────────────
if (isset($_POST["add-whitelist"])) {
    bc_validate_csrf();
    $pid    = bc_sanitize($_POST["product_id"] ?? '');
    $limit  = bc_sanitize_number($_POST["limit_override"] ?? 0);
    $expiry = bc_sanitize($_POST["expiry"] ?? '');
    if (!empty($pid)) {
        $esc_pid = mysqli_real_escape_string($connection_server, $pid);
        $esc_exp = !empty($expiry) ? "'" . mysqli_real_escape_string($connection_server, $expiry) . "'" : "NULL";
        @mysqli_query($connection_server,
            "INSERT INTO sas_customer_whitelist (vendor_id, product_id, is_whitelisted, daily_limit_override, override_expiry)
             VALUES ('$esc_vid', '$esc_pid', 1, '$limit', $esc_exp)
             ON DUPLICATE KEY UPDATE is_whitelisted=1, daily_limit_override='$limit', override_expiry=$esc_exp"
        );
        $_SESSION["product_purchase_response"] = "✅ VIP entry added for: $pid";
    }
    header("Location: AISettings.php?tab=sentinel");
    exit();
}

if (isset($_GET["remove-whitelist"])) {
    $pid = bc_sanitize($_GET["remove-whitelist"]);
    $esc_pid = mysqli_real_escape_string($connection_server, $pid);
    @mysqli_query($connection_server, "DELETE FROM sas_customer_whitelist WHERE vendor_id='$esc_vid' AND product_id='$esc_pid'");
    $_SESSION["product_purchase_response"] = "VIP entry removed.";
    header("Location: AISettings.php?tab=sentinel");
    exit();
}

// ── Load Data for Unified AI Business Suite ─────────────────────────────────
$v_refresh = @mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$esc_vid' LIMIT 1");
if ($v_refresh && ($vr = mysqli_fetch_assoc($v_refresh))) {
    $get_logged_admin_details = array_merge($get_logged_admin_details, $vr);
}

$ai_status       = (int)($get_logged_admin_details["ai_status"] ?? 0);
$ai_req_status   = (string)($get_logged_admin_details["ai_request_status"] ?? 'none');
$token_bal       = (int)($get_logged_admin_details["ai_token_balance"] ?? 0);
$price_1k        = (float)($get_logged_admin_details["ai_price_per_1k_tokens"] ?? getSuperAdminOption('ai_price_per_1k_tokens', 100));
if ($price_1k <= 0) $price_1k = 100.0;

$ai_engine = new AIEngine();
$plat_status = bc_ai_platform_status($connection_server, $vid, 'support_reply');
$model_raw = $get_logged_admin_details["ai_model_assigned"] ?? "";
$model = (!empty($model_raw) && $ai_engine->isModelCompatible($model_raw)) ? $model_raw : $ai_engine->getDefaultModel();

// SAAS per-action fees
$fee_av_scan   = bc_ai_get_action_cost('av_scan');
$fee_kyc_rep   = bc_ai_get_action_cost('kyc_report');
$fee_support   = bc_ai_get_action_cost('support_reply');
$fee_copilot   = bc_ai_get_action_cost('marketing_copilot');

// NON-SAAS direct provider values
$stored_provider     = function_exists('getSuperAdminOption') ? getSuperAdminOption('ai_provider', 'gemini') : 'gemini';
$stored_gemini_key   = function_exists('getSuperAdminOption') ? getSuperAdminOption('ai_gemini_api_key', '') : '';
$stored_openai_key   = function_exists('getSuperAdminOption') ? getSuperAdminOption('ai_openai_api_key', '') : '';
$stored_anth_key     = function_exists('getSuperAdminOption') ? getSuperAdminOption('ai_anthropic_api_key', '') : '';
$stored_deepseek_key = function_exists('getSuperAdminOption') ? getSuperAdminOption('ai_deepseek_api_key', '') : '';
$stored_groq_key     = function_exists('getSuperAdminOption') ? getSuperAdminOption('ai_groq_api_key', '') : '';
$stored_hf_key       = function_exists('getSuperAdminOption') ? getSuperAdminOption('ai_huggingface_api_key', '') : '';
$stored_ollama_url   = function_exists('getSuperAdminOption') ? getSuperAdminOption('ai_ollama_url', 'http://localhost:11434') : 'http://localhost:11434';
$stored_vision_prov  = function_exists('getSuperAdminOption') ? getSuperAdminOption('ai_vision_provider', '') : '';
$stored_vision_mod   = function_exists('getSuperAdminOption') ? getSuperAdminOption('ai_vision_model', '') : '';

// Support settings
$chat_settings = bc_chat_settings($vid);
$has_legacy_chat_key = trim((string)($chat_settings['chat_ai_key'] ?? '')) !== '';
$legacy_key_tail = $has_legacy_chat_key ? substr(trim((string)$chat_settings['chat_ai_key']), -4) : '';

// Sentinel data
$sentinel_cfg = bc_ai_sentinel_settings($connection_server, $vid);
$wl_q = @mysqli_query($connection_server, "SELECT * FROM sas_customer_whitelist WHERE vendor_id='$esc_vid' ORDER BY created_at DESC LIMIT 50");
$sec_logs_q = @mysqli_query($connection_server, "SELECT * FROM sas_ai_audit_log WHERE actor LIKE '$esc_vid:%' OR event_type IN ('SENTINEL_FLAGGED','SENTINEL_AUTO_BLOCKED','AV_BLOCKED','PROMPT_BLOCKED','SPENDING_ANOMALY') ORDER BY id DESC LIMIT 40");
$flagged_users_q = @mysqli_query($connection_server, "SELECT id, username, email, trust_score, is_blocked, reg_date FROM sas_users WHERE vendor_id='$esc_vid' AND (is_blocked=1 OR trust_score < 45) ORDER BY is_blocked DESC, trust_score ASC LIMIT 40");

// Reports data
$av_filter = trim((string)($_GET['av_verdict'] ?? 'all'));
$av_sql_filter = ($av_filter === 'blocked') ? "AND verdict='blocked'" : (($av_filter === 'clean') ? "AND verdict='clean'" : "");
$av_logs_q = @mysqli_query($connection_server, "SELECT * FROM sas_kyc_av_logs WHERE vendor_id='$esc_vid' $av_sql_filter ORDER BY id DESC LIMIT 40");
$av_stats_q = @mysqli_query($connection_server, "SELECT COUNT(*) as total, SUM(CASE WHEN verdict='blocked' THEN 1 ELSE 0 END) as blocked, SUM(tokens_charged) as tokens FROM sas_kyc_av_logs WHERE vendor_id='$esc_vid'");
$av_stats = ($av_stats_q && ($ar = mysqli_fetch_assoc($av_stats_q))) ? $ar : ['total' => 0, 'blocked' => 0, 'tokens' => 0];
$kyc_ai_logs_q = @mysqli_query($connection_server, "SELECT r.*, u.username FROM sas_kyc_ai_results r LEFT JOIN sas_users u ON u.id=r.user_id WHERE r.vendor_id='$esc_vid' ORDER BY r.id DESC LIMIT 25");

// Usage & billing ledger
$usage_q = @mysqli_query($connection_server, "SELECT SUM(tokens_burned) as used, COUNT(*) as calls FROM sas_ai_transactions WHERE vendor_id='$esc_vid' AND MONTH(created_at)=MONTH(NOW()) AND status='success'");
$usage = ($usage_q && ($ur = mysqli_fetch_assoc($usage_q))) ? $ur : ['used' => 0, 'calls' => 0];
$tx_q = @mysqli_query($connection_server, "SELECT * FROM sas_ai_transactions WHERE vendor_id='$esc_vid' ORDER BY id DESC LIMIT 30");
?>
<?php include_once(__DIR__ . "/../func/bc-plate-admin.php");
bc_adm_head("AI Business Suite | " . ($get_all_super_admin_site_details["site_title"] ?? 'Admin'), array("area" => "admin"));
?>
<style>
    :root {
        --ai-primary: var(--brand); --ai-secondary: var(--brand-dark);
        --ai-dark: #0f172a; --ai-glass: rgba(255, 255, 255, 0.94);
    }
    body { background: #f8fafc; font-family: 'Inter', sans-serif; }
    .ai-header-banner {
        background: linear-gradient(135deg, var(--ai-dark), #1e293b);
        border-radius: 1.75rem; padding: 2.5rem 2.25rem; color: white;
        position: relative; overflow: hidden; margin-bottom: 1.75rem;
        box-shadow: 0 20px 40px rgba(0,0,0,0.1);
    }
    .ai-glass-card {
        background: var(--ai-glass); border: 1px solid rgba(15,23,42,0.06);
        border-radius: 1.35rem; box-shadow: 0 6px 20px rgba(0,0,0,0.04);
    }
    .nav-tabs-ai { border: none; background: #e2e8f0; border-radius: 1rem; padding: 0.45rem; display: inline-flex; flex-wrap: wrap; gap: 0.25rem; }
    .nav-tabs-ai .nav-link {
        border: none; border-radius: 0.75rem; color: #475569; font-weight: 700;
        padding: 0.55rem 1.25rem; transition: all 0.2s; font-size: 0.9rem;
    }
    .nav-tabs-ai .nav-link.active { background: white; color: var(--ai-dark); box-shadow: 0 4px 10px rgba(0,0,0,0.06); }
    .ai-btn-primary {
        background: linear-gradient(135deg, var(--ai-primary), var(--ai-secondary));
        border: none; color: white; font-weight: 700; border-radius: 0.9rem;
        padding: 0.75rem 1.5rem;
    }
    .token-stat-pill { background: rgba(255,255,255,0.1); border-radius: 1rem; padding: 1rem; border: 1px solid rgba(255,255,255,0.12); }
    .pulse-active { width: 10px; height: 10px; background: #10b981; border-radius: 50%; display: inline-block; animation: pulse 2s infinite; }
    @keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(16,185,129,0.7); } 70% { box-shadow: 0 0 0 10px rgba(16,185,129,0); } 100% { box-shadow: 0 0 0 0 rgba(16,185,129,0); } }
</style>
<?php bc_adm_sidebar(array("area" => "admin")); ?>
<?php bc_adm_topbar(array("area" => "admin", "title" => "AI Business Suite", "crumb" => "Home / AI Business Suite")); ?>

<section class="section dashboard">
    <?php if (isset($_SESSION["product_purchase_response"])): ?>
        <div class="alert alert-info border-0 rounded-4 shadow-sm">
            <i class="bi bi-info-circle me-2"></i><?php echo htmlspecialchars((string)$_SESSION["product_purchase_response"], ENT_QUOTES, 'UTF-8'); unset($_SESSION["product_purchase_response"]); ?>
        </div>
    <?php endif; ?>

    <!-- UNIFIED AI BUSINESS SUITE BANNER -->
    <div class="ai-header-banner">
        <div class="row align-items-center g-4">
            <div class="col-lg-7">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                    <span class="badge bg-primary rounded-pill px-3 py-2">
                        <i class="bi bi-cpu-fill me-1"></i><?php echo $is_saas ? 'SAAS Central AI Engine' : 'Direct Owner API Engine'; ?>
                    </span>
                    <span class="badge bg-<?php echo !empty($plat_status['available']) ? 'success' : 'warning text-dark'; ?> rounded-pill px-3 py-2">
                        <?php echo !empty($plat_status['available']) ? 'Connected & Active' : htmlspecialchars((string)$plat_status['reason']); ?>
                    </span>
                </div>
                <h1 class="h2 fw-bold mb-2">AI Business Suite</h1>
                <p class="opacity-75 mb-3">
                    Unified command center for <?php echo $is_saas ? 'AI tokens & Super Admin billing' : 'direct AI API credentials'; ?>,
                    account-aware AI Customer Support, Security Sentinel &amp; auto-blocking, and Antivirus + KYC/KYB compliance reports.
                </p>

                <!-- ALL AI SUITE ACTION BUTTONS -->
                <div class="d-flex flex-wrap gap-2">
                    <form method="post" class="d-inline">
                        <?php echo bc_csrf_field(); ?>
                        <input type="hidden" name="ai_status" value="<?php echo $ai_status ? 0 : 1; ?>">
                        <button type="submit" name="toggle-ai" class="btn btn-<?php echo $ai_status ? 'outline-warning' : 'light'; ?> rounded-pill px-3 fw-bold btn-sm">
                            <i class="bi bi-power me-1"></i><?php echo $ai_status ? 'Pause AI Engine' : 'Activate AI Engine'; ?>
                        </button>
                    </form>
                    <a href="SupportChat.php" class="btn btn-outline-light rounded-pill px-3 btn-sm fw-bold">
                        <i class="bi bi-chat-dots me-1"></i>Support Console
                    </a>
                    <a href="AIMarketing.php" class="btn btn-outline-light rounded-pill px-3 btn-sm fw-bold">
                        <i class="bi bi-megaphone me-1"></i>AI Marketing Studio
                    </a>
                    <a href="KYCManagement.php" class="btn btn-outline-light rounded-pill px-3 btn-sm fw-bold">
                        <i class="bi bi-person-check me-1"></i>KYC Review
                    </a>
                    <a href="BusinessKYB.php" class="btn btn-outline-light rounded-pill px-3 btn-sm fw-bold">
                        <i class="bi bi-building-check me-1"></i>KYB Review
                    </a>
                    <a href="BruteForceSecurity.php" class="btn btn-outline-light rounded-pill px-3 btn-sm fw-bold">
                        <i class="bi bi-shield-lock me-1"></i>Session Lockouts
                    </a>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="row g-3">
                    <?php if ($is_saas): ?>
                    <div class="col-6">
                        <div class="token-stat-pill h-100">
                            <div class="small opacity-75 mb-1">Vendor AI Tokens</div>
                            <h3 class="fw-bold mb-0"><?php echo number_format($token_bal); ?></h3>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="col-6">
                        <div class="token-stat-pill h-100">
                            <div class="small opacity-75 mb-1">Direct Provider</div>
                            <h4 class="fw-bold mb-0"><?php echo ucfirst($stored_provider); ?></h4>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="col-6">
                        <div class="token-stat-pill h-100">
                            <div class="small opacity-75 mb-1">Monthly AI Calls</div>
                            <h3 class="fw-bold mb-0"><?php echo number_format((int)($usage['calls'] ?? 0)); ?></h3>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="token-stat-pill h-100">
                            <div class="small opacity-75 mb-1">AV Threats Blocked</div>
                            <h3 class="fw-bold mb-0 text-warning"><?php echo number_format((int)($av_stats['blocked'] ?? 0)); ?></h3>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="token-stat-pill h-100">
                            <div class="small opacity-75 mb-1">Active Model</div>
                            <div class="fw-bold small text-truncate"><?php echo htmlspecialchars(strtoupper($model)); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- UNIFIED TABS NAVIGATION -->
    <div class="text-center mb-4">
        <div class="nav nav-tabs-ai shadow-sm" role="tablist">
            <button class="nav-link <?php echo $active_tab==='billing'?'active':''; ?>" data-bs-toggle="tab" data-bs-target="#tab-billing">
                <i class="bi bi-<?php echo $is_saas?'coin':'key'; ?> me-1"></i><?php echo $is_saas ? 'Tokens & Billing' : 'Direct AI API'; ?>
            </button>
            <button class="nav-link <?php echo $active_tab==='support'?'active':''; ?>" data-bs-toggle="tab" data-bs-target="#tab-support">
                <i class="bi bi-headset me-1"></i>AI Support Settings
            </button>
            <button class="nav-link <?php echo $active_tab==='sentinel'?'active':''; ?>" data-bs-toggle="tab" data-bs-target="#tab-sentinel">
                <i class="bi bi-shield-exclamation me-1"></i>Security Sentinel
            </button>
            <button class="nav-link <?php echo $active_tab==='reports'?'active':''; ?>" data-bs-toggle="tab" data-bs-target="#tab-reports">
                <i class="bi bi-file-earmark-medical me-1"></i>Antivirus &amp; AI Reports
            </button>
        </div>
    </div>

    <div class="tab-content">
        <!-- TAB 1: TOKENS & BILLING (SAAS) OR DIRECT AI API (NON-SAAS) -->
        <div class="tab-pane fade <?php echo $active_tab==='billing'?'show active':''; ?>" id="tab-billing">
            <div class="row g-4">
                <?php if ($is_saas): ?>
                <div class="col-lg-6">
                    <div class="ai-glass-card p-4 h-100">
                        <h5 class="fw-bold mb-3"><i class="bi bi-cart-plus me-2 text-warning"></i>Purchase AI Tokens</h5>
                        <p class="small text-muted mb-3">
                            Your platform connects directly to the Super Admin AI Engine. Purchase tokens from your vendor wallet to power
                            Antivirus AI scanning, KYC/KYB compliance reports, Customer Support AI, and Marketing Copilot. End users are never billed.
                        </p>
                        <?php if ($ai_status === 0 && $ai_req_status !== 'approved'): ?>
                            <form method="post" class="mb-4 p-3 rounded-4 bg-light border">
                                <?php echo bc_csrf_field(); ?>
                                <h6 class="fw-bold mb-2">Activate AI Business Suite</h6>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold">Activation Package</label>
                                    <select name="token_package" class="form-select rounded-3">
                                        <option value="starter">Starter — 5,000 Tokens (₦500)</option>
                                        <option value="business">Business — 20,000 Tokens (₦1,800)</option>
                                        <option value="scale">Scale — 100,000 Tokens (₦7,500)</option>
                                    </select>
                                </div>
                                <?php if ($ai_req_status === 'pending'): ?>
                                    <div class="alert alert-warning small mb-0">Activation request pending Super Admin approval.</div>
                                <?php else: ?>
                                    <button type="submit" name="request-ai-activation" class="ai-btn-primary w-100">Request Activation &amp; Pay</button>
                                <?php endif; ?>
                            </form>
                        <?php endif; ?>

                        <form method="post">
                            <?php echo bc_csrf_field(); ?>
                            <div class="mb-3">
                                <label class="form-label small fw-bold">Top-Up Token Amount</label>
                                <input type="number" name="token_amount" id="tk_amt" class="form-control form-control-lg border-0 bg-light rounded-4" value="1000" min="100" step="100">
                            </div>
                            <div class="p-3 bg-warning bg-opacity-10 rounded-4 mb-3 border border-warning border-opacity-25">
                                <div class="d-flex justify-content-between">
                                    <span class="small">Wallet Cost (₦<?php echo number_format($price_1k, 2); ?> / 1k tokens)</span>
                                    <span class="fw-bold text-dark" id="tk_cost">₦<?php echo number_format($price_1k, 2); ?></span>
                                </div>
                            </div>
                            <button type="submit" name="buy-ai-tokens" class="ai-btn-primary w-100">Buy Tokens Now</button>
                        </form>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="ai-glass-card p-4 h-100">
                        <h5 class="fw-bold mb-3"><i class="bi bi-receipt me-2 text-primary"></i>Super Admin AI Service Rates</h5>
                        <p class="small text-muted mb-3">Transparent token rates set by Super Admin for your SAAS AI usage:</p>
                        <div class="list-group list-group-flush">
                            <div class="list-group-item px-0 d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fw-bold small">Pre-Upload Antivirus AI Scan</div>
                                    <div class="x-small text-muted">Stage-1 malware/blank check always runs free; Stage-2 AI vision inspection</div>
                                </div>
                                <span class="badge bg-primary rounded-pill px-3 py-2"><?php echo (int)$fee_av_scan; ?> token(s)/file</span>
                            </div>
                            <div class="list-group-item px-0 d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fw-bold small">KYC / KYB AI Compliance Report &amp; Rating</div>
                                    <div class="x-small text-muted">ID-vs-selfie/video face match, 3-month POA check &amp; document scoring</div>
                                </div>
                                <span class="badge bg-primary rounded-pill px-3 py-2"><?php echo (int)$fee_kyc_rep; ?> token(s)/report</span>
                            </div>
                            <div class="list-group-item px-0 d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fw-bold small">AI Customer Support Reply</div>
                                    <div class="x-small text-muted">Live support chat answers using customer profile, transactions &amp; site info</div>
                                </div>
                                <span class="badge bg-primary rounded-pill px-3 py-2"><?php echo (int)$fee_support; ?> token(s)/reply</span>
                            </div>
                            <div class="list-group-item px-0 d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fw-bold small">AI Marketing Copilot</div>
                                    <div class="x-small text-muted">Campaign writer &amp; email refiner in AI Marketing Studio / Mail Sender</div>
                                </div>
                                <span class="badge bg-primary rounded-pill px-3 py-2"><?php echo (int)$fee_copilot; ?> token(s)/use</span>
                            </div>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="col-lg-8 mx-auto">
                    <div class="ai-glass-card p-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <h5 class="fw-bold mb-1 text-primary"><i class="bi bi-key-fill me-2"></i>Direct AI API Connection (NON-SAAS)</h5>
                                <p class="small text-muted mb-0">Standalone installations connect directly to your own AI API keys — zero token billing.</p>
                            </div>
                            <form method="post" class="d-inline">
                                <?php echo bc_csrf_field(); ?>
                                <button type="submit" name="test-ai-connection" class="btn btn-outline-primary btn-sm rounded-pill fw-bold">
                                    <i class="bi bi-broadcast me-1"></i>Test Connection
                                </button>
                            </form>
                        </div>
                        <form method="post">
                            <?php echo bc_csrf_field(); ?>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">Primary AI Provider (Support &amp; Marketing)</label>
                                    <select name="ai_provider" class="form-select rounded-3">
                                        <?php foreach (['gemini' => 'Google Gemini', 'openai' => 'OpenAI (GPT-4o)', 'anthropic' => 'Anthropic (Claude)', 'deepseek' => 'DeepSeek', 'groq' => 'Groq', 'ollama' => 'Ollama (Local)', 'huggingface' => 'HuggingFace'] as $pk => $pl): ?>
                                            <option value="<?php echo $pk; ?>" <?php echo $stored_provider===$pk?'selected':''; ?>><?php echo $pl; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">Default Model (optional)</label>
                                    <input type="text" name="ai_model_assigned" class="form-control rounded-3" value="<?php echo htmlspecialchars((string)$model); ?>" placeholder="e.g. gemini-1.5-flash, gpt-4o-mini">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">Vision Provider (Antivirus &amp; KYC/KYB)</label>
                                    <select name="ai_vision_provider" class="form-select rounded-3">
                                        <option value="">— Use Primary Provider —</option>
                                        <?php foreach (['gemini' => 'Google Gemini Vision', 'openai' => 'OpenAI GPT-4o Vision', 'anthropic' => 'Anthropic Claude Vision', 'ollama' => 'Ollama (Local Vision)', 'huggingface' => 'HuggingFace Vision'] as $vk => $vl): ?>
                                            <option value="<?php echo $vk; ?>" <?php echo $stored_vision_prov===$vk?'selected':''; ?>><?php echo $vl; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">Vision Model (optional)</label>
                                    <input type="text" name="ai_vision_model" class="form-control rounded-3" value="<?php echo htmlspecialchars((string)$stored_vision_mod); ?>" placeholder="Auto-detected from provider">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">Gemini API Key <?php echo $stored_gemini_key!==''?'<span class="text-success">(saved)</span>':''; ?></label>
                                    <input type="password" name="ai_gemini_api_key" class="form-control rounded-3" autocomplete="new-password" placeholder="<?php echo $stored_gemini_key!==''?'Leave blank to keep stored key':'AIzaSy...'; ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">OpenAI API Key <?php echo $stored_openai_key!==''?'<span class="text-success">(saved)</span>':''; ?></label>
                                    <input type="password" name="ai_openai_api_key" class="form-control rounded-3" autocomplete="new-password" placeholder="<?php echo $stored_openai_key!==''?'Leave blank to keep stored key':'sk-...'; ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">Anthropic API Key <?php echo $stored_anth_key!==''?'<span class="text-success">(saved)</span>':''; ?></label>
                                    <input type="password" name="ai_anthropic_api_key" class="form-control rounded-3" autocomplete="new-password" placeholder="<?php echo $stored_anth_key!==''?'Leave blank to keep stored key':'sk-ant-...'; ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">DeepSeek API Key <?php echo $stored_deepseek_key!==''?'<span class="text-success">(saved)</span>':''; ?></label>
                                    <input type="password" name="ai_deepseek_api_key" class="form-control rounded-3" autocomplete="new-password" placeholder="<?php echo $stored_deepseek_key!==''?'Leave blank to keep stored key':'sk-...'; ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">Groq API Key <?php echo $stored_groq_key!==''?'<span class="text-success">(saved)</span>':''; ?></label>
                                    <input type="password" name="ai_groq_api_key" class="form-control rounded-3" autocomplete="new-password" placeholder="<?php echo $stored_groq_key!==''?'Leave blank to keep stored key':'gsk_...'; ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">Ollama URL (Local)</label>
                                    <input type="text" name="ai_ollama_url" class="form-control rounded-3" value="<?php echo htmlspecialchars((string)$stored_ollama_url); ?>">
                                </div>
                            </div>
                            <button type="submit" name="update-ai-credentials" class="ai-btn-primary w-100 mt-4">Save Direct AI API Settings</button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- TAB 2: MERGED AI SUPPORT SETTINGS -->
        <div class="tab-pane fade <?php echo $active_tab==='support'?'show active':''; ?>" id="tab-support">
            <div class="row g-4">
                <div class="col-lg-8">
                    <div class="ai-glass-card p-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="fw-bold mb-0"><i class="bi bi-headset me-2 text-primary"></i>Customer Support Chat &amp; AI Assistant</h5>
                            <a href="SupportChat.php" class="btn btn-sm btn-outline-primary rounded-pill fw-bold">Open Support Queue</a>
                        </div>
                        <p class="small text-muted">
                            <?php if ($is_saas): ?>
                                Powered by the <strong>Super Admin AI Engine</strong> (billed at <?php echo (int)$fee_support; ?> token(s) per AI reply).
                            <?php else: ?>
                                Powered directly by your configured <strong>Direct AI API</strong> key (zero token fees).
                            <?php endif; ?>
                            The AI securely looks up the customer's verified account tier, wallet balance, KYC status, recent transactions, payment orders, and your website info to resolve issues before handing off to a human.
                        </p>
                        <form method="post">
                            <?php echo bc_csrf_field(); ?>
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="chat_enabled" name="chat_enabled" <?php echo ($chat_settings['chat_enabled'] ?? '0') === '1' ? 'checked' : ''; ?>>
                                <label class="form-check-label fw-bold" for="chat_enabled">Show live support chat widget to signed-in users</label>
                            </div>
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="chat_ai_enabled" name="chat_ai_enabled" <?php echo ($chat_settings['chat_ai_enabled'] ?? '0') === '1' ? 'checked' : ''; ?>>
                                <label class="form-check-label fw-bold" for="chat_ai_enabled">Enable AI First-Line Support Agent</label>
                            </div>
                            <div class="form-check form-switch mb-4">
                                <input class="form-check-input" type="checkbox" id="chat_ai_context_enabled" name="chat_ai_context_enabled" <?php echo ($chat_settings['chat_ai_context_enabled'] ?? '1') === '1' ? 'checked' : ''; ?>>
                                <label class="form-check-label fw-bold" for="chat_ai_context_enabled">Allow AI to read signed-in customer's account summary, recent transactions &amp; KYC status (never passwords/PINs)</label>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-bold" for="chat_welcome">Welcome Message</label>
                                <textarea class="form-control rounded-3" id="chat_welcome" name="chat_welcome" rows="2"><?php echo htmlspecialchars((string)($chat_settings['chat_welcome'] ?? '')); ?></textarea>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-bold" for="chat_handoff">Human Agent Handoff Message</label>
                                <textarea class="form-control rounded-3" id="chat_handoff" name="chat_handoff" rows="2"><?php echo htmlspecialchars((string)($chat_settings['chat_handoff'] ?? '')); ?></textarea>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-bold" for="chat_quick_replies">Quick Reply Suggestions (one per line, max 5)</label>
                                <textarea class="form-control rounded-3" id="chat_quick_replies" name="chat_quick_replies" rows="3" placeholder="Check my last transaction&#10;What is my KYC status?&#10;My funding is pending"><?php echo htmlspecialchars((string)($chat_settings['chat_quick_replies'] ?? '')); ?></textarea>
                            </div>

                            <details class="mb-3 p-3 bg-light rounded-3">
                                <summary class="small fw-bold text-muted" style="cursor:pointer;">Optional Override Key (not needed when central AI Engine is active)</summary>
                                <div class="mt-2">
                                    <input class="form-control" type="password" name="chat_ai_key" autocomplete="new-password" placeholder="<?php echo $has_legacy_chat_key ? 'Key ending in ' . htmlspecialchars($legacy_key_tail) . ' stored — leave blank to keep' : 'Optional standalone API key'; ?>">
                                    <?php if ($has_legacy_chat_key): ?>
                                    <div class="form-check mt-2">
                                        <input class="form-check-input" type="checkbox" id="chat_ai_key_clear" name="chat_ai_key_clear">
                                        <label class="form-check-label small text-danger" for="chat_ai_key_clear">Clear override key and use central AI Engine</label>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </details>

                            <button type="submit" name="save-support-ai-settings" class="ai-btn-primary">Save AI Support Settings</button>
                        </form>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="ai-glass-card p-4 h-100">
                        <h6 class="fw-bold mb-3"><i class="bi bi-check2-circle me-2 text-success"></i>What AI Support Answers</h6>
                        <ul class="small text-muted ps-3 mb-0">
                            <li class="mb-2">Recent transaction status, reference, amount &amp; date lookup</li>
                            <li class="mb-2">Deposit / submitted payment order review status</li>
                            <li class="mb-2">KYC verification status, rejection reasons &amp; blocked upload explanations</li>
                            <li class="mb-2">Wallet balance, account tier &amp; platform contact info</li>
                            <li>Seamless human handoff to <strong>Support Chat</strong> when manual action is needed</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB 3: SECURITY SENTINEL (LOGS, FLAGGED ACCOUNTS, AUTO-BLOCK, WHITELIST) -->
        <div class="tab-pane fade <?php echo $active_tab==='sentinel'?'show active':''; ?>" id="tab-sentinel">
            <div class="row g-4">
                <div class="col-lg-4">
                    <div class="ai-glass-card p-4 mb-4">
                        <h5 class="fw-bold mb-3"><i class="bi bi-robot me-2 text-danger"></i>AI Auto-Block &amp; Sentinel Rules</h5>
                        <form method="post">
                            <?php echo bc_csrf_field(); ?>
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="sentinel_auto_block" name="sentinel_auto_block" <?php echo ($sentinel_cfg['sentinel_auto_block'] ?? '0') === '1' ? 'checked' : ''; ?>>
                                <label class="form-check-label fw-bold" for="sentinel_auto_block">AI Auto-Block High-Risk Users</label>
                                <div class="form-text x-small">Automatically blocks users flagged for spending anomalies, malware uploads, or trust scores below threshold.</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-bold">Trust Score Auto-Block Threshold (5–60)</label>
                                <input type="number" name="sentinel_block_threshold" min="5" max="60" class="form-control rounded-3" value="<?php echo (int)($sentinel_cfg['sentinel_block_threshold'] ?? 20); ?>">
                            </div>
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="sentinel_av_auto_flag" name="sentinel_av_auto_flag" <?php echo ($sentinel_cfg['sentinel_av_auto_flag'] ?? '1') === '1' ? 'checked' : ''; ?>>
                                <label class="form-check-label small fw-bold" for="sentinel_av_auto_flag">Flag/Block users uploading malware/trojans</label>
                            </div>
                            <div class="form-check form-switch mb-4">
                                <input class="form-check-input" type="checkbox" id="sentinel_anomaly_alert" name="sentinel_anomaly_alert" <?php echo ($sentinel_cfg['sentinel_anomaly_alert'] ?? '1') === '1' ? 'checked' : ''; ?>>
                                <label class="form-check-label small fw-bold" for="sentinel_anomaly_alert">Send high-risk anomaly email alerts</label>
                            </div>
                            <button type="submit" name="save-sentinel-settings" class="ai-btn-primary w-100">Save Sentinel Rules</button>
                        </form>
                    </div>

                    <div class="ai-glass-card p-4">
                        <h6 class="fw-bold mb-3"><i class="bi bi-shield-check me-2 text-primary"></i>VIP Whitelist</h6>
                        <form method="post" class="mb-3">
                            <?php echo bc_csrf_field(); ?>
                            <div class="input-group">
                                <input type="text" name="product_id" class="form-control rounded-start-3" placeholder="Phone or Account #" required>
                                <button type="submit" name="add-whitelist" class="btn btn-primary">Add</button>
                            </div>
                        </form>
                        <div class="table-responsive" style="max-height:220px;">
                            <table class="table table-sm align-middle mb-0">
                                <tbody>
                                    <?php if ($wl_q && mysqli_num_rows($wl_q) > 0): while($wl = mysqli_fetch_assoc($wl_q)): ?>
                                    <tr>
                                        <td class="small fw-bold"><?php echo htmlspecialchars((string)$wl['product_id']); ?></td>
                                        <td class="text-end"><a href="AISettings.php?tab=sentinel&remove-whitelist=<?php echo urlencode((string)$wl['product_id']); ?>" class="btn btn-light btn-sm">✕</a></td>
                                    </tr>
                                    <?php endwhile; else: ?>
                                    <tr><td class="text-center py-3 text-muted small">No VIP numbers whitelisted.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="col-lg-8">
                    <div class="ai-glass-card p-4 mb-4">
                        <h5 class="fw-bold mb-3"><i class="bi bi-person-exclamation me-2 text-danger"></i>Flagged &amp; Auto-Blocked Accounts</h5>
                        <div class="table-responsive" style="max-height:260px;">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light x-small text-uppercase text-muted">
                                    <tr><th>User</th><th>Trust Score</th><th>Status</th><th class="text-end">Sentinel Actions</th></tr>
                                </thead>
                                <tbody>
                                    <?php if ($flagged_users_q && mysqli_num_rows($flagged_users_q) > 0): while($fu = mysqli_fetch_assoc($flagged_users_q)): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold small">@<?php echo htmlspecialchars((string)$fu['username']); ?></div>
                                            <div class="x-small text-muted"><?php echo htmlspecialchars((string)$fu['email']); ?></div>
                                        </td>
                                        <td><span class="badge bg-<?php echo (int)$fu['trust_score'] < 25 ? 'danger' : 'warning text-dark'; ?> rounded-pill"><?php echo (int)$fu['trust_score']; ?>/100</span></td>
                                        <td>
                                            <span class="badge bg-<?php echo (int)$fu['is_blocked'] === 1 ? 'danger' : 'warning text-dark'; ?> rounded-pill">
                                                <?php echo (int)$fu['is_blocked'] === 1 ? 'Blocked' : 'Low Trust Flag'; ?>
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <form method="post" class="d-inline">
                                                <?php echo bc_csrf_field(); ?>
                                                <input type="hidden" name="target_username" value="<?php echo htmlspecialchars((string)$fu['username']); ?>">
                                                <?php if ((int)$fu['is_blocked'] === 1): ?>
                                                    <button type="submit" name="sentinel_user_action" value="unblock" class="btn btn-success btn-sm rounded-pill px-3">Unblock</button>
                                                <?php else: ?>
                                                    <button type="submit" name="sentinel_user_action" value="block" class="btn btn-danger btn-sm rounded-pill px-3">Block</button>
                                                <?php endif; ?>
                                                <button type="submit" name="sentinel_user_action" value="reset_trust" class="btn btn-outline-secondary btn-sm rounded-pill px-2">Reset Trust</button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endwhile; else: ?>
                                    <tr><td colspan="4" class="text-center py-4 text-muted small"><i class="bi bi-shield-check text-success me-1"></i>No flagged or blocked user accounts.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="ai-glass-card p-4">
                        <h5 class="fw-bold mb-3"><i class="bi bi-journal-text me-2 text-warning"></i>Security Logs &amp; Flagged Actions</h5>
                        <div class="table-responsive" style="max-height:320px;">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light x-small text-uppercase text-muted">
                                    <tr><th>Event</th><th>Action</th><th>Actor</th><th>Detail</th><th class="text-end">Time</th></tr>
                                </thead>
                                <tbody>
                                    <?php if ($sec_logs_q && mysqli_num_rows($sec_logs_q) > 0): while($sl = mysqli_fetch_assoc($sec_logs_q)): ?>
                                    <tr>
                                        <td><span class="badge bg-danger-subtle text-danger rounded-pill x-small"><?php echo htmlspecialchars((string)$sl['event_type']); ?></span></td>
                                        <td class="small fw-bold"><?php echo htmlspecialchars((string)$sl['action']); ?></td>
                                        <td class="x-small text-muted"><?php echo htmlspecialchars((string)$sl['actor']); ?></td>
                                        <td class="x-small"><?php echo htmlspecialchars(substr((string)$sl['detail'], 0, 120)); ?></td>
                                        <td class="text-end x-small text-muted"><?php echo !empty($sl['created_at']) ? date('M j H:i', strtotime((string)$sl['created_at'])) : ''; ?></td>
                                    </tr>
                                    <?php endwhile; else: ?>
                                    <tr><td colspan="5" class="text-center py-4 text-muted small">No security events logged yet.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB 4: ANTIVIRUS SCAN REPORTS & KYC/KYB AI COMPLIANCE REPORTS -->
        <div class="tab-pane fade <?php echo $active_tab==='reports'?'show active':''; ?>" id="tab-reports">
            <div class="row g-4">
                <div class="col-12">
                    <div class="ai-glass-card p-4">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                            <div>
                                <h5 class="fw-bold mb-0"><i class="bi bi-shield-fill-check me-2 text-success"></i>Pre-Upload Antivirus &amp; Blank-File Scan Reports</h5>
                                <div class="x-small text-muted">
                                    Total Scans: <strong><?php echo number_format((int)($av_stats['total'] ?? 0)); ?></strong> &middot;
                                    Blocked: <strong class="text-danger"><?php echo number_format((int)($av_stats['blocked'] ?? 0)); ?></strong> &middot;
                                    Tokens Billed: <strong><?php echo number_format((int)($av_stats['tokens'] ?? 0)); ?></strong>
                                </div>
                            </div>
                            <div class="btn-group btn-group-sm">
                                <a href="AISettings.php?tab=reports&av_verdict=all" class="btn btn-<?php echo $av_filter==='all'?'dark':'outline-secondary'; ?>">All</a>
                                <a href="AISettings.php?tab=reports&av_verdict=blocked" class="btn btn-<?php echo $av_filter==='blocked'?'danger':'outline-danger'; ?>">Blocked</a>
                                <a href="AISettings.php?tab=reports&av_verdict=clean" class="btn btn-<?php echo $av_filter==='clean'?'success':'outline-success'; ?>">Clean</a>
                            </div>
                        </div>
                        <div class="table-responsive" style="max-height:320px;">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light x-small text-uppercase text-muted">
                                    <tr><th>User</th><th>Document</th><th>File</th><th>Mode</th><th>Verdict</th><th>Details</th><th class="text-end">Date</th></tr>
                                </thead>
                                <tbody>
                                    <?php if ($av_logs_q && mysqli_num_rows($av_logs_q) > 0): while($av = mysqli_fetch_assoc($av_logs_q)): ?>
                                    <tr>
                                        <td class="small fw-bold"><?php echo htmlspecialchars((string)($av['username'] ?: ('user#' . (int)$av['user_id']))); ?></td>
                                        <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars((string)$av['subject_type'] . ':' . $av['doc_key']); ?></span></td>
                                        <td class="x-small text-muted"><?php echo htmlspecialchars((string)$av['filename']); ?></td>
                                        <td><span class="badge bg-info-subtle text-info small"><?php echo htmlspecialchars((string)$av['scan_mode']); ?></span></td>
                                        <td><span class="badge bg-<?php echo $av['verdict']==='blocked'?'danger':'success'; ?> rounded-pill"><?php echo strtoupper((string)$av['verdict']); ?></span></td>
                                        <td class="x-small text-muted"><?php echo htmlspecialchars(substr((string)$av['reason'], 0, 110)); ?></td>
                                        <td class="text-end x-small text-muted"><?php echo !empty($av['created_at']) ? date('M j H:i', strtotime((string)$av['created_at'])) : ''; ?></td>
                                    </tr>
                                    <?php endwhile; else: ?>
                                    <tr><td colspan="7" class="text-center py-4 text-muted small">No antivirus scan reports recorded yet.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <div class="ai-glass-card p-4">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                            <h5 class="fw-bold mb-0"><i class="bi bi-file-earmark-check me-2 text-primary"></i>KYC &amp; KYB AI Compliance Reports</h5>
                            <div class="d-flex gap-2">
                                <a href="KYCManagement.php" class="btn btn-sm btn-outline-primary rounded-pill">Open KYC Queue</a>
                                <a href="BusinessKYB.php" class="btn btn-sm btn-outline-dark rounded-pill">Open KYB Queue</a>
                            </div>
                        </div>
                        <div class="table-responsive" style="max-height:300px;">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light x-small text-uppercase text-muted">
                                    <tr><th>Applicant</th><th>Decision</th><th>Confidence</th><th>Report Summary</th><th class="text-end">Inspect</th></tr>
                                </thead>
                                <tbody>
                                    <?php if ($kyc_ai_logs_q && mysqli_num_rows($kyc_ai_logs_q) > 0): while($kr = mysqli_fetch_assoc($kyc_ai_logs_q)):
                                        $dec = (string)($kr['ai_status'] ?? ($kr['decision'] ?? ''));
                                        $kr_reason = (string)($kr['decision_reason'] ?? ($kr['reason'] ?? ''));
                                        $is_kyb = (strpos($dec, 'kyb') === 0);
                                        $badge = (strpos($dec, 'approve') !== false) ? 'success' : ((strpos($dec, 'reject') !== false) ? 'danger' : 'warning text-dark');
                                    ?>
                                    <tr>
                                        <td class="small fw-bold">@<?php echo htmlspecialchars((string)($kr['username'] ?: ('user#' . (int)$kr['user_id']))); ?></td>
                                        <td><span class="badge bg-<?php echo $badge; ?> rounded-pill"><?php echo htmlspecialchars($dec ?: 'analyzed'); ?></span></td>
                                        <td class="small fw-bold"><?php echo (int)($kr['confidence'] ?? 0); ?>%</td>
                                        <td class="x-small text-muted"><?php echo htmlspecialchars(substr($kr_reason, 0, 120)); ?></td>
                                        <td class="text-end">
                                            <a href="<?php echo $is_kyb ? 'BusinessKYB.php' : ('KYCManagement.php?review_user=' . (int)$kr['user_id']); ?>" class="btn btn-sm btn-light rounded-pill">View</a>
                                        </td>
                                    </tr>
                                    <?php endwhile; else: ?>
                                    <tr><td colspan="5" class="text-center py-4 text-muted small">No KYC/KYB AI compliance reports generated yet.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- USAGE & BILLING LEDGER FOOTER -->
    <div class="mt-4">
        <div class="ai-glass-card p-4 overflow-hidden">
            <h5 class="fw-bold mb-3"><i class="bi bi-clock-history me-2"></i>AI Usage &amp; Billing History</h5>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light x-small text-muted text-uppercase">
                        <tr><th>Feature / Action</th><th>Model</th><th>Tokens</th><?php if ($is_saas): ?><th>Cost (₦)</th><?php endif; ?><th>Status</th><th class="text-end">Timestamp</th></tr>
                    </thead>
                    <tbody>
                        <?php if ($tx_q && mysqli_num_rows($tx_q) > 0): while($h = mysqli_fetch_assoc($tx_q)): ?>
                        <tr>
                            <td><span class="fw-bold small"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)($h['action_type'] ?: 'ai_call')))); ?></span></td>
                            <td><small class="text-muted"><?php echo htmlspecialchars((string)($h['model_used'] ?: 'Default')); ?></small></td>
                            <td><span class="badge bg-light text-dark border"><?php echo number_format((int)($h['tokens_burned'] ?? 0)); ?></span></td>
                            <?php if ($is_saas): ?><td class="small fw-bold">₦<?php echo number_format((float)($h['cost_naira'] ?? 0), 2); ?></td><?php endif; ?>
                            <td><span class="badge bg-<?php echo ($h['status'] ?? 'success') === 'success' ? 'success' : 'danger'; ?>-subtle text-<?php echo ($h['status'] ?? 'success') === 'success' ? 'success' : 'danger'; ?> rounded-pill"><?php echo ucfirst((string)($h['status'] ?? 'success')); ?></span></td>
                            <td class="text-end x-small text-muted"><?php echo !empty($h['created_at']) ? date('M j, Y H:i', strtotime((string)$h['created_at'])) : ''; ?></td>
                        </tr>
                        <?php endwhile; else: ?>
                        <tr><td colspan="<?php echo $is_saas ? 6 : 5; ?>" class="text-center py-4 text-muted">No AI usage recorded yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>

<script src="../assets-2/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script>
    const pricePer1k = <?php echo json_encode((float)$price_1k); ?>;
    const tkAmtInput = document.getElementById('tk_amt');
    const tkCostDisplay = document.getElementById('tk_cost');
    if (tkAmtInput && tkCostDisplay) {
        tkAmtInput.addEventListener('input', function() {
            const amt = parseInt(this.value, 10) || 0;
            const cost = (amt / 1000) * pricePer1k;
            tkCostDisplay.innerText = '₦' + cost.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
        });
    }
</script>

<?php bc_adm_foot(array("area" => "admin")); ?>

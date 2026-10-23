<?php
/**
 * ai-guide-cache.php — DGV6.90 AI Edition
 * Serves pre-generated AI page guides from DB cache.
 * Falls back to Cloud AI generation only if no cache exists.
 */
session_start();
include_once(__DIR__ . "/../func/bc-config.php");
include_once(__DIR__ . "/../func/bc-ai-engine.php");

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

if ((empty($_SESSION['user_session']) && empty($_SESSION['admin_session'])) || !$connection_server) {
    echo json_encode(['guide' => null]);
    exit;
}

$page_slug = bc_sanitize($_GET['page'] ?? '');
$page_slug = preg_replace('/[^a-z0-9_]/', '_', strtolower($page_slug));

if (empty($page_slug)) {
    echo json_encode(['guide' => null]);
    exit;
}

$vendor_id = resolveVendorID();
$safe_vid  = (int)$vendor_id;

// Check AI status
$context = $_GET['context'] ?? 'user';
if ($context === 'admin' && !empty($_SESSION['admin_session'])) {
    // Admin Path
    $email = $_SESSION['admin_session'];
    $esc_email = mysqli_real_escape_string($connection_server, $email);
    $ai_chk = mysqli_query($connection_server, "SELECT ai_status FROM sas_vendors WHERE id='$safe_vid' AND email='$esc_email' LIMIT 1");
} else {
    // User Path
    $username  = $_SESSION['user_session'];
    $esc_user  = mysqli_real_escape_string($connection_server, $username);
    $ai_chk = mysqli_query($connection_server, "SELECT ai_status FROM sas_users WHERE vendor_id='$safe_vid' AND username='$esc_user' LIMIT 1");
}

$ai_row = $ai_chk ? mysqli_fetch_assoc($ai_chk) : null;
if (!$ai_row || (int)$ai_row['ai_status'] !== 1) {
    echo json_encode(['guide' => null]);
    exit;
}

// ─── SMART ASSIST: Proactive Failure Detection ────────────────
// If there's an immediate failure context, use it as priority over page guides
$user_data = ['username' => $_SESSION['user_session'] ?? ''];
$ai_context = bc_get_ai_user_context($user_data);

if (!empty($ai_context['smart_explanation'])) {
    $explanation = $ai_context['smart_explanation'];
    
    // Log this intelligence for the Blueprint
    bc_log_ai_intelligence($safe_vid, 'smart_assist_intervention', $explanation, [
        'page' => $page_slug,
        'reason' => $ai_context['last_fail_reason'],
        'product' => $ai_context['last_fail_plan']
    ]);

    echo json_encode([
        'status' => 'success',
        'guide' => $explanation,
        'is_intervention' => true,
        'type' => 'failure_recovery'
    ]);
    exit;
}

// Check DB cache (valid for 24 hours) for normal page guides
$esc_slug = mysqli_real_escape_string($connection_server, $page_slug);
$cache_q  = mysqli_query($connection_server, "SELECT guide_text FROM sas_ai_page_guides WHERE page_slug='$esc_slug' AND vendor_id='$safe_vid' AND last_updated >= DATE_SUB(NOW(), INTERVAL 24 HOUR) LIMIT 1");
if ($cache_q && mysqli_num_rows($cache_q) > 0) {
    $cached = mysqli_fetch_assoc($cache_q);
    echo json_encode(['status' => 'success', 'guide' => $cached['guide_text'], 'is_intervention' => false, 'from_cache' => true]);
    exit;
}

// Cache miss — generate from Cloud AI
$ai     = ai_engine();
$vendor = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT ai_model_assigned FROM sas_vendors WHERE id='$safe_vid' LIMIT 1"));
$model  = $vendor['ai_model_assigned'] ?? getSuperAdminOption('ai_default_model', 'gemini-1.5-flash');

$page_name = str_replace('_', ' ', ucwords($page_slug));
$last_action = !empty($ai_context['recent_history'][0]) ? " (Last action: ".$ai_context['recent_history'][0].")" : "";

$prompt = "You are a friendly Nigerian VTU business assistant.
           User is currently on the '$page_name' page$last_action.
           1. Mention their last action briefly.
           2. List services they can buy: Airtime, Data, Electricity, Cable, Exam PINs.
           3. Tell them they can type commands like 'Buy MTN 500 airtime to 080...' or use the voice button.
           4. Provide ONE concise business tip for this page.
           Max 120 words. Use emojis.";

$result = $ai->chat($model, $prompt, ['temperature' => 0.75]);

if ($result['status'] === 'success') {
    $guide_text = $result['response'];
    $esc_guide  = mysqli_real_escape_string($connection_server, $guide_text);
    mysqli_query($connection_server, "INSERT INTO sas_ai_page_guides (page_slug, vendor_id, guide_text) VALUES ('$esc_slug', '$safe_vid', '$esc_guide') ON DUPLICATE KEY UPDATE guide_text='$esc_guide', last_updated=NOW()");
    echo json_encode(['status' => 'success', 'guide' => $guide_text, 'is_intervention' => false, 'from_cache' => false]);
} else {
    echo json_encode(['status' => 'error', 'guide' => null]);
}

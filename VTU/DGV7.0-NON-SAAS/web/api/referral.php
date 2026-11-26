<?php
require_once __DIR__ . '/../../func/bc-secure-session.php';

/** Authenticated referral growth and locked/unlocked coin rewards. */
bc_secure_session_start();
header("Content-Type: application/json");
include_once("../../func/bc-connect.php");

// Accept input from the app bridge or a raw JSON body, matching the other app endpoints.
if (isset($api_post_info_from_app) && is_array($api_post_info_from_app)) {
    $purchase_method = "app";
    $input = $api_post_info_from_app;
} else {
    $purchase_method = "api";
    $input = json_decode(file_get_contents('php://input'), true);
    if (empty($input)) $input = $_REQUEST;
}

$vendor_id = resolveVendorID();
$api_key = mysqli_real_escape_string($connection_server, bc_fintech_api_credential(trim(strip_tags($input["api_key"] ?? '')), $vendor_id));
$purchase_method = bc_api_is_app_session(isset($api_post_info_from_app) && is_array($api_post_info_from_app)) ? "app" : "api";
if (empty($api_key)) {
    echo json_encode(["status" => "error", "message" => "API Key is required"]);
    exit;
}

$get_vendor = mysqli_fetch_array(mysqli_query($connection_server, "SELECT id FROM sas_vendors WHERE id='$vendor_id' AND status=1 LIMIT 1"));
if (!$get_vendor) {
    echo json_encode(["status" => "error", "message" => "Vendor not found"]);
    exit;
}

$check_user = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' AND api_key='$api_key' LIMIT 1");
if (mysqli_num_rows($check_user) != 1) {
    echo json_encode(["status" => "error", "message" => "Invalid API Key"]);
    exit;
}
$user = mysqli_fetch_assoc($check_user);
if ((int)($user['status'] ?? 0) !== 1 || !empty($user['is_blocked'])) {
    echo json_encode(["status" => "error", "message" => "Account is not active"]);
    exit;
}

$username = (string) $user['username'];
$user_id  = (string) (int) $user['id'];

/**
 * Run a prepared statement and return its first row, or null.
 *
 * PHP 8 promotes "argument must be of type mysqli_result, bool given" to a fatal TypeError, so the
 * old "mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))" pattern turned any query failure into an
 * HTTP 500 that the apps could only report as "The server returned an error". Returning null lets
 * each caller fall back to a sane default instead of killing the request.
 *
 * Guarded with function_exists because the endpoint is included more than once by
 * tests/app_referral_api_test.php in a single process.
 */
if (!function_exists('bc_referral_fetch_row')) {
    function bc_referral_fetch_row($stmt) {
        if ($stmt === false || $stmt === null) { return null; }
        if (!mysqli_stmt_execute($stmt)) { return null; }
        $result = mysqli_stmt_get_result($stmt);
        if ($result === false || $result === null) { return null; }
        return mysqli_fetch_assoc($result);
    }
}

// ── Self-heal the referral schema ─────────────────────────────────────────────
// /web/api/* only loads bc-connect.php, which does NOT pull in bc-tables.php, so the column
// migration that every web/admin surface gets never runs for app traffic. An install upgraded from
// a build that predates "Refer and Earn" therefore had no referral_id / referral_bonus_awarded
// column, every referral query failed, and the app showed HTTP 500. CREATE TABLE IF NOT EXISTS in
// bc-tables.php cannot fix that either - it never alters an existing table.
$bc_ref_columns = [];
$bc_ref_columns_result = mysqli_query($connection_server, "SHOW COLUMNS FROM sas_users");
if ($bc_ref_columns_result) {
    while ($bc_ref_column = mysqli_fetch_assoc($bc_ref_columns_result)) {
        $bc_ref_columns[] = $bc_ref_column['Field'];
    }
}
if (!empty($bc_ref_columns)) {
    if (!in_array('referral_id', $bc_ref_columns)) {
        @mysqli_query($connection_server, "ALTER TABLE sas_users ADD COLUMN referral_id VARCHAR(225)");
        $bc_ref_columns[] = 'referral_id';
    }
    if (!in_array('referral_bonus_awarded', $bc_ref_columns)) {
        @mysqli_query($connection_server, "ALTER TABLE sas_users ADD COLUMN referral_bonus_awarded TINYINT(1) DEFAULT 0");
        $bc_ref_columns[] = 'referral_bonus_awarded';
    }
}
$bc_ref_schema_ready = in_array('referral_id', $bc_ref_columns)
    && in_array('referral_bonus_awarded', $bc_ref_columns);

require_once __DIR__ . '/../../func/bc-fintech-services.php';
try {
    $growth = bc_referral_progress_for_user((int)$vendor_id, (int)$user_id);
    $cfg = bc_referral_settings((int)$vendor_id);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(array('status'=>'error','message'=>'Referral progress is temporarily unavailable.'));
    exit;
}
$total_referrals=$growth['referred'];
$qualified_referrals=$growth['qualified'];
$pending_referrals=max(0,$total_referrals-$qualified_referrals);
$coins_from_referrals=$growth['earned_coins'];
$referral_bonus=$cfg['reward_coins'];

// get_user_vtu_details() and isServiceEnabled() live in bc-func.php and query sas_points_log /
// sas_conversions / sas_services directly. On an install where any of those is missing they raise
// a TypeError (mysqli_* receive `false`), and PHP 8 turns that into an uncaught fatal - the same
// HTTP 500 this screen was reporting. Both are wrapped so a broken aux table can only cost the
// coin figure, never the whole screen.
try {
    $vtu_details = function_exists('get_user_vtu_details') ? get_user_vtu_details($username) : [];
} catch (Throwable $bc_ref_error) {
    $vtu_details = [];
}
$coins_balance = (int) (is_array($vtu_details) ? ($vtu_details['total_points'] ?? 0) : 0);

try {
    $coins_enabled = function_exists('isServiceEnabled') ? (bool) isServiceEnabled('vtu_coins') : true;
} catch (Throwable $bc_ref_error) {
    $coins_enabled = true;
}

// ── Share link — byte-identical in shape to web/Dashboard.php copyReferLink() ──
$host_base = !empty($web_http_host) ? rtrim($web_http_host, '/') : ("https://" . bc_safe_host());
$referral_link = $host_base . "/web/Register.php?referral=" . rawurlencode($username);

$referred_users = array_map(function($r) {
    return array('username'=>$r['referee_name'], 'name'=>$r['referee_name'], 'joined'=>$r['reg_date'],
        'qualified'=>$r['rewarded'] ? 'Yes' : 'No', 'qualifying_usd'=>$r['qualifying_usd'],
        'remaining_usd'=>$r['remaining_usd'], 'progress_percent'=>$r['progress_percent'],
        'reward_coins'=>$r['coins'], 'reward_status'=>$r['reward_status']);
}, $growth['rows']);

echo json_encode([
    "status" => "success",
    "data"   => [
        "referral_code"         => $username,
        "referral_link"         => $referral_link,
        "share_message"         => "Join me on " . ($get_all_site_details["site_title"] ?? "") . " and get started. Use my referral code: " . $username,
        "referral_bonus"        => $referral_bonus,
        "locked_referral_coins" => $growth['locked_coins'],
        "legacy_locked_referral_coins" => $growth['legacy_locked_coins'],
        "unlocked_referral_coins" => $growth['earned_coins'],
        "referral_reward_usd"     => function_exists('bc_referral_settings') ? (float) bc_referral_settings((int) $vendor_id)['reward_usd'] : 10.0,
        "referral_qualifying_usd" => function_exists('bc_referral_settings') ? (float) bc_referral_settings((int) $vendor_id)['qualifying_usd'] : 100.0,
        "coins_enabled"         => $coins_enabled,
        "coins_balance"         => $coins_balance,
        "coins_from_referrals"  => $coins_from_referrals,
        "total_referrals"       => $total_referrals,
        "qualified_referrals"   => $qualified_referrals,
        "pending_referrals"     => $pending_referrals,
        "referred_users"        => $referred_users,
    ]
]);

mysqli_close($connection_server);

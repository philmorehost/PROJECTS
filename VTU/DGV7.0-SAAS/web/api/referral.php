<?php
/**
 * web/api/referral.php
 *
 * App/API endpoint for the "Refer and Earn" referral feature.
 *
 * Everything here mirrors the web implementation exactly, so the app and the website can never
 * disagree about a number:
 *
 *   code        the referral code IS the username        (web/Register.php matches username=)
 *   link        <host>/web/Register.php?referral=<username>
 *                                                        (web/Dashboard.php copyReferLink())
 *   reward      the vendor's first_purchase_bonus VTU Coins, paid to the REFERRER when the
 *               referred user completes their FIRST successful transaction
 *                                                        (func/bc-func.php first-purchase block)
 *   count       rows in sas_users whose referral_id is this user
 *                                                        (web/Dashboard.php referral card)
 *
 * GET -> {
 *   status, data: {
 *     referral_code, referral_link, share_message,
 *     referral_bonus, coins_enabled, coins_balance, coins_from_referrals,
 *     total_referrals, qualified_referrals, pending_referrals,
 *     referred_users: [ { username, name, joined, qualified } ]
 *   }
 * }
 *
 * A referral starts as "pending" and becomes "qualified" once that user's first transaction
 * succeeds and the bonus is actually paid (referral_bonus_awarded flips to 1). Reporting the two
 * separately matters: showing everyone the number of people who signed up would promise coins
 * that have not been earned yet.
 */
session_start();
header("Content-Type: application/json");
include_once("../../func/bc-connect.php");

// Accept input from the app bridge or a raw JSON body, matching the other app endpoints.
if (isset($api_post_info_from_app) && is_array($api_post_info_from_app)) {
    $purchase_method = "app";
    $input = $api_post_info_from_app;
} else {
    $purchase_method = (($_SERVER['HTTP_X_APP_SOURCE'] ?? '') === 'dgv6-android') ? "app" : "api";
    $input = json_decode(file_get_contents('php://input'), true);
    if (empty($input)) $input = $_REQUEST;
}

$api_key = mysqli_real_escape_string($connection_server, trim(strip_tags($input["api_key"] ?? '')));
if (empty($api_key)) {
    echo json_encode(["status" => "error", "message" => "API Key is required"]);
    exit;
}

$vendor_id = resolveVendorID();
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
if ($user['status'] != 1) {
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

// ── Referral totals ───────────────────────────────────────────────────────────
// referral_bonus_awarded lives on the REFERRED user and means "this user's first purchase has
// already paid their referrer", so qualified counts the referrals that actually earned coins.
$total_referrals = 0; $qualified_referrals = 0;
$stmt = mysqli_prepare($connection_server,
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN referral_bonus_awarded = 1 THEN 1 ELSE 0 END) AS qualified
     FROM sas_users WHERE vendor_id = ? AND referral_id = ?");
if ($stmt) { mysqli_stmt_bind_param($stmt, "is", $vendor_id, $user_id); }
$totals = bc_referral_fetch_row($stmt);
if ($totals) {
    $total_referrals     = (int) ($totals['total'] ?? 0);
    $qualified_referrals = (int) ($totals['qualified'] ?? 0);
}
$pending_referrals = max(0, $total_referrals - $qualified_referrals);

// ── Coins earned specifically from referrals (the ledger IS the source of truth) ──
$coins_from_referrals = 0;
$stmt = mysqli_prepare($connection_server,
    "SELECT COALESCE(SUM(point_amount), 0) AS coins FROM sas_points_log
     WHERE vendor_id = ? AND username = ? AND log_type = 'REFERRAL_BONUS'");
if ($stmt) { mysqli_stmt_bind_param($stmt, "is", $vendor_id, $username); }
$earned = bc_referral_fetch_row($stmt);
if ($earned) { $coins_from_referrals = (int) ($earned['coins'] ?? 0); }

// ── Current bonus amount, coin balance and whether coins are switched on ──────
$referral_bonus = 100;
$stmt = mysqli_prepare($connection_server, "SELECT first_purchase_bonus FROM sas_loyalty_bonus_settings WHERE vendor_id = ? LIMIT 1");
if ($stmt) { mysqli_stmt_bind_param($stmt, "i", $vendor_id); }
$lr = bc_referral_fetch_row($stmt);
if ($lr && isset($lr['first_purchase_bonus'])) { $referral_bonus = (int) $lr['first_purchase_bonus']; }

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

// ── The people this user referred ─────────────────────────────────────────────
$referred_users = [];
$stmt = mysqli_prepare($connection_server,
    "SELECT username, firstname, lastname, referral_bonus_awarded, reg_date
     FROM sas_users WHERE vendor_id = ? AND referral_id = ? ORDER BY id DESC LIMIT 50");
if ($stmt) { mysqli_stmt_bind_param($stmt, "is", $vendor_id, $user_id); }
if ($stmt !== false && $stmt !== null && mysqli_stmt_execute($stmt)) {
    $rq = mysqli_stmt_get_result($stmt);
    if ($rq) {
        while ($row = mysqli_fetch_assoc($rq)) {
            $name = trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? ''));
            $referred_users[] = [
                "username"  => $row['username'],
                "name"      => $name !== '' ? $name : $row['username'],
                "joined"    => $row['reg_date'],
                "qualified" => ((int) ($row['referral_bonus_awarded'] ?? 0) === 1) ? "Yes" : "No",
            ];
        }
    }
}

echo json_encode([
    "status" => "success",
    "data"   => [
        "referral_code"         => $username,
        "referral_link"         => $referral_link,
        "share_message"         => "Join me on " . ($get_all_site_details["site_title"] ?? "") . " and get started. Use my referral code: " . $username,
        "referral_bonus"        => $referral_bonus,
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

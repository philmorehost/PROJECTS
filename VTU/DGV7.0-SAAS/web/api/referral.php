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

// ── Referral totals ───────────────────────────────────────────────────────────
// referral_bonus_awarded lives on the REFERRED user and means "this user's first purchase has
// already paid their referrer", so qualified counts the referrals that actually earned coins.
$total_referrals = 0; $qualified_referrals = 0;
$stmt = mysqli_prepare($connection_server,
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN referral_bonus_awarded = 1 THEN 1 ELSE 0 END) AS qualified
     FROM sas_users WHERE vendor_id = ? AND referral_id = ?");
mysqli_stmt_bind_param($stmt, "is", $vendor_id, $user_id);
mysqli_stmt_execute($stmt);
$totals = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
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
mysqli_stmt_bind_param($stmt, "is", $vendor_id, $username);
mysqli_stmt_execute($stmt);
$earned = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
if ($earned) { $coins_from_referrals = (int) ($earned['coins'] ?? 0); }

// ── Current bonus amount, coin balance and whether coins are switched on ──────
$referral_bonus = 100;
$stmt = mysqli_prepare($connection_server, "SELECT first_purchase_bonus FROM sas_loyalty_bonus_settings WHERE vendor_id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, "i", $vendor_id);
mysqli_stmt_execute($stmt);
$lr = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
if ($lr && isset($lr['first_purchase_bonus'])) { $referral_bonus = (int) $lr['first_purchase_bonus']; }

$vtu_details   = get_user_vtu_details($username);
$coins_balance = (int) ($vtu_details['total_points'] ?? 0);
$coins_enabled = function_exists('isServiceEnabled') ? (bool) isServiceEnabled('vtu_coins') : true;

// ── Share link — byte-identical in shape to web/Dashboard.php copyReferLink() ──
$host_base = !empty($web_http_host) ? rtrim($web_http_host, '/') : ("https://" . bc_safe_host());
$referral_link = $host_base . "/web/Register.php?referral=" . rawurlencode($username);

// ── The people this user referred ─────────────────────────────────────────────
$referred_users = [];
$stmt = mysqli_prepare($connection_server,
    "SELECT username, firstname, lastname, referral_bonus_awarded, reg_date
     FROM sas_users WHERE vendor_id = ? AND referral_id = ? ORDER BY id DESC LIMIT 50");
mysqli_stmt_bind_param($stmt, "is", $vendor_id, $user_id);
mysqli_stmt_execute($stmt);
$rq = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($rq)) {
    $name = trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? ''));
    $referred_users[] = [
        "username"  => $row['username'],
        "name"      => $name !== '' ? $name : $row['username'],
        "joined"    => $row['reg_date'],
        "qualified" => ((int) ($row['referral_bonus_awarded'] ?? 0) === 1) ? "Yes" : "No",
    ];
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

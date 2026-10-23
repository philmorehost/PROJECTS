<?php
/**
 * web/api/coin-conversion.php
 * App/API endpoint for the VTU Coins -> Wallet conversion feature.
 *
 * GET (no action): returns the user's coin balance, conversion rate, minimum
 *   conversion points, the email-alert threshold, and conversion history so the
 *   app can render the conversion screen.
 * POST (action=submit, points=N): submits a conversion request (same rules as the
 *   web page) and automatically notifies the vendor admin by email.
 *
 * The "coins reached threshold" guide email and the "request approved/declined"
 * emails are sent server-side automatically, so apps benefit with no extra work.
 */
session_start();
header("Content-Type: application/json");
include_once("../../func/bc-connect.php");

// Accept input from the app bridge (api_post_info_from_app) or a raw JSON body.
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

if (!isServiceEnabled('vtu_coins')) {
    echo json_encode(["status" => "error", "message" => "Coin conversion is currently offline"]);
    exit;
}

$username = $user['username'];
$settings = bc_get_coin_settings($vendor_id);
$rate = (float)$settings['points_conversion_rate'];
$min_points = (int)$settings['min_points_conversion'];
$vtu = get_user_vtu_details($username);
$balance = (int)($vtu['total_points'] ?? 0);

$action = strtolower(trim($input['action'] ?? ''));

if ($action === 'submit') {
    $points = (int)($input['points'] ?? 0);
    if ($points <= 0) {
        echo json_encode(["status" => "error", "message" => "Invalid point amount"]);
        exit;
    }
    if ($points < $min_points) {
        echo json_encode(["status" => "error", "message" => "Minimum conversion is " . $min_points . " points"]);
        exit;
    }
    if ($points > $balance) {
        echo json_encode(["status" => "error", "message" => "Insufficient points balance"]);
        exit;
    }

    $amount = $points / ($rate > 0 ? $rate : 1);
    $stmt = mysqli_prepare($connection_server, "INSERT INTO sas_conversions (vendor_id, username, points, amount, status) VALUES (?, ?, ?, ?, 'pending')");
    mysqli_stmt_bind_param($stmt, "isid", $vendor_id, $username, $points, $amount);
    if (mysqli_stmt_execute($stmt)) {
        // Notify the vendor admin of the new conversion request (same as the web flow).
        bc_send_admin_coin_conversion_email($vendor_id, $username, $points, $amount);
        echo json_encode([
            "status" => "success",
            "message" => "Conversion request submitted and pending admin approval",
            "points" => $points,
            "amount" => round($amount, 2)
        ]);
    } else {
        echo json_encode(["status" => "error", "message" => "Failed to submit conversion request"]);
    }
    exit;
}

// Default (GET): settings + balance + history for the app's conversion screen.
$history = [];
// request_date is included because completion_date is NULL until an admin approves or declines,
// so a pending request would otherwise be returned with no date at all.
$stmt = mysqli_prepare($connection_server, "SELECT id, points, amount, status, request_date, completion_date FROM sas_conversions WHERE vendor_id = ? AND username = ? ORDER BY id DESC LIMIT 20");
mysqli_stmt_bind_param($stmt, "is", $vendor_id, $username);
mysqli_stmt_execute($stmt);
$hq = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($hq)) {
    $history[] = $row;
}

echo json_encode([
    "status" => "success",
    "data" => [
        "points_balance" => $balance,
        "conversion_rate" => $rate,
        "min_points_conversion" => $min_points,
        "coins_email_threshold" => (int)$settings['coins_email_threshold'],
        "history" => $history
    ]
]);

mysqli_close($connection_server);

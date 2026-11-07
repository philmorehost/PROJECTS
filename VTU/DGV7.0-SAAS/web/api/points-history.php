<?php
/**
 * web/api/points-history.php
 *
 * App/API endpoint for the VTU Coins history, mirroring web/PointsHistory.php so the app and the
 * website list exactly the same rows.
 *
 * The web page's query is deliberately not a plain SELECT: the daily purchase-streak bonus can be
 * written more than once per day, and the page collapses those to one row per date using MAX(id).
 * Reproducing that here (rather than a naive "all rows") is what keeps the app's history from
 * showing a pile of duplicate "Daily Purchase Bonus" entries.
 *
 * GET -> { status, data: { points_balance, entries: [ { date, log_type, label, points, direction } ] } }
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

/** Same pretty-printing the web page applies: "REFERRAL_BONUS" -> "Referral Bonus". */
if (!function_exists('bc_points_log_label')) {
    function bc_points_log_label($log_type) {
        return ucwords(str_replace('_', ' ', strtolower((string) $log_type)));
    }
}

// Pagination for the app: newest first, capped so a long-lived account cannot return megabytes.
$limit  = (int) ($input['limit'] ?? 100);
$offset = (int) ($input['offset'] ?? 0);
if ($limit  <= 0 || $limit  > 200) { $limit  = 100; }
if ($offset < 0) { $offset = 0; }

$entries = [];
$sql = "
    SELECT id, point_amount, log_type, date
    FROM sas_points_log
    WHERE username = ? AND vendor_id = ? AND log_type <> 'DAILY_PURCHASE_BONUS'
    UNION
    SELECT l1.id, l1.point_amount, l1.log_type, l1.date
    FROM sas_points_log l1
    INNER JOIN (
        SELECT MAX(id) as max_id
        FROM sas_points_log
        WHERE username = ? AND vendor_id = ? AND log_type = 'DAILY_PURCHASE_BONUS'
        GROUP BY DATE(date)
    ) l2 ON l1.id = l2.max_id
    ORDER BY date DESC
    LIMIT ? OFFSET ?";

$stmt = mysqli_prepare($connection_server, $sql);
mysqli_stmt_bind_param($stmt, "sisiii", $username, $vendor_id, $username, $vendor_id, $limit, $offset);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($result)) {
    $points = (int) $row['point_amount'];
    $entries[] = [
        "date"      => $row['date'],
        "log_type"  => $row['log_type'],
        "label"     => bc_points_log_label($row['log_type']),
        "points"    => $points,
        "direction" => $points >= 0 ? "in" : "out",
    ];
}

$vtu_details   = get_user_vtu_details($username);
$coins_balance = (int) ($vtu_details['total_points'] ?? 0);

echo json_encode([
    "status" => "success",
    "data"   => [
        "points_balance" => $coins_balance,
        "streak_day"     => (int) ($vtu_details['streak_day'] ?? 0),
        "is_eligible"    => !empty($vtu_details['is_eligible']) ? "Yes" : "No",
        "next_bonus"     => $vtu_details['next_bonus_time'] ?? null,
        "entries"        => $entries,
    ]
]);

mysqli_close($connection_server);

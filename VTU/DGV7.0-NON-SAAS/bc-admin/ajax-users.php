<?php
session_start();
header("Content-Type: application/json");
include("../func/bc-admin-config.php");

if (!isset($get_logged_admin_details["id"])) {
    echo json_encode(["status" => "error", "message" => "Unauthorized access"]);
    exit;
}

$vid = $get_logged_admin_details["id"];
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;
$search = isset($_GET['searchq']) ? mysqli_real_escape_string($connection_server, trim($_GET['searchq'])) : '';
$status = isset($_GET['status']) ? mysqli_real_escape_string($connection_server, $_GET['status']) : '1';

$search_statement = "";
if (!empty($search)) {
    $search_statement = " AND (u.email LIKE '%$search%' OR u.phone_number LIKE '%$search%' OR u.username LIKE '%$search%' OR u.firstname LIKE '%$search%' OR u.lastname LIKE '%$search%' OR u.othername LIKE '%$search%')";
}

$status_statement = " AND u.status='$status'";
if ($status === 'all') {
    $status_statement = "";
}

// Get total for current view
$total_query = mysqli_query($connection_server, "SELECT COUNT(*) as count FROM sas_users u WHERE u.vendor_id='$vid' $status_statement $search_statement");
if (!$total_query) {
    echo json_encode(["status" => "error", "message" => "Total query failed: " . mysqli_error($connection_server)]);
    exit;
}
$total_records = mysqli_fetch_assoc($total_query)['count'];
$total_pages = ceil($total_records / $limit);

// Get users
$sql = "SELECT u.*, r.username as referral_username_raw
        FROM sas_users u
        LEFT JOIN sas_users r ON u.referral_id = r.id
        WHERE u.vendor_id='$vid' $status_statement $search_statement
        ORDER BY u.reg_date DESC
        LIMIT $limit OFFSET $offset";
$result = mysqli_query($connection_server, $sql);
if (!$result) {
    echo json_encode(["status" => "error", "message" => "Users query failed: " . mysqli_error($connection_server)]);
    exit;
}

$users = [];
while ($row = mysqli_fetch_assoc($result)) {
    // Basic formatting for JSON
    $row['fullname'] = ucwords(trim(($row['firstname'] ?? '') . " " . ($row['lastname'] ?? '')));
    $row['reg_date_formatted'] = $row['reg_date'] ? date('d M Y', strtotime($row['reg_date'])) : 'N/A';
    $row['last_login_formatted'] = ($row['last_login'] && $row['last_login'] != '0000-00-00 00:00:00') ? date('d M Y H:i', strtotime($row['last_login'])) : 'Never';
    $row['balance_formatted'] = number_format((float)($row['balance'] ?? 0), 2);
    $row['level_name'] = accountLevel($row['account_level'] ?? 1);

    // Resolve referral
    $row['referral_username'] = $row['referral_username_raw'] ? "@" . $row['referral_username_raw'] : 'None';

    // Sanitize fields for JS
    $row['username'] = $row['username'] ?? '';
    $row['api_key'] = $row['api_key'] ?? '';

    $users[] = $row;
}

// Get global stats (Total, Active, Blocked, Deleted)
$stats_q = mysqli_query($connection_server, "SELECT
    COUNT(*) as total,
    COALESCE(SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END), 0) as active,
    COALESCE(SUM(CASE WHEN status = 2 THEN 1 ELSE 0 END), 0) as blocked,
    COALESCE(SUM(CASE WHEN status = 3 THEN 1 ELSE 0 END), 0) as deleted
    FROM sas_users WHERE vendor_id='$vid'");
$stats = mysqli_fetch_assoc($stats_q);

echo json_encode([
    "status" => "success",
    "users" => $users,
    "pagination" => [
        "current_page" => $page,
        "total_pages" => $total_pages,
        "total_records" => $total_records
    ],
    "stats" => $stats
]);
exit;

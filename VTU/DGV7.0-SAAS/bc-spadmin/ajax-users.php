<?php
session_start();
header("Content-Type: application/json");
include("../func/bc-spadmin-config.php");

// Super Admin authorization check
if (!isset($get_logged_spadmin_details["id"])) {
    echo json_encode(["status" => "error", "message" => "Unauthorized access"]);
    exit;
}

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;
$search = isset($_GET['searchq']) ? mysqli_real_escape_string($connection_server, trim($_GET['searchq'])) : '';
$status = isset($_GET['status']) ? mysqli_real_escape_string($connection_server, $_GET['status']) : 'all';
$vid = isset($_GET['vid']) ? (int)$_GET['vid'] : 0;

$search_statement = "";
if (!empty($search)) {
    $search_statement = " AND (u.email LIKE '%$search%' OR u.phone_number LIKE '%$search%' OR u.username LIKE '%$search%' OR u.firstname LIKE '%$search%' OR u.lastname LIKE '%$search%' OR v.website_url LIKE '%$search%')";
}

$status_statement = ($status !== 'all') ? " AND u.status='$status'" : "";
$vendor_statement = ($vid > 0) ? " AND u.vendor_id='$vid'" : "";

// Get total for current view
$total_query = mysqli_query($connection_server, "SELECT COUNT(*) as count FROM sas_users u LEFT JOIN sas_vendors v ON u.vendor_id = v.id WHERE 1=1 $vendor_statement $status_statement $search_statement");
$total_records = mysqli_fetch_assoc($total_query)['count'] ?? 0;
$total_pages = ceil($total_records / $limit);

// Get users
$sql = "SELECT u.*, v.website_url, v.id as vid, r.username as referral_username_raw
        FROM sas_users u
        LEFT JOIN sas_vendors v ON u.vendor_id = v.id
        LEFT JOIN sas_users r ON u.referral_id = r.id
        WHERE 1=1 $vendor_statement $status_statement $search_statement
        ORDER BY u.reg_date DESC
        LIMIT $limit OFFSET $offset";
$result = mysqli_query($connection_server, $sql);

$users = [];
while ($row = mysqli_fetch_assoc($result)) {
    $row['fullname'] = ucwords(trim(($row['firstname'] ?? '') . " " . ($row['lastname'] ?? '')));
    $row['reg_date_formatted'] = $row['reg_date'] ? date('d M Y', strtotime($row['reg_date'])) : 'N/A';
    $row['last_login_formatted'] = ($row['last_login'] && $row['last_login'] != '0000-00-00 00:00:00') ? date('d M Y H:i', strtotime($row['last_login'])) : 'Never';
    $row['balance_formatted'] = number_format((float)($row['balance'] ?? 0), 2);
    $row['level_name'] = accountLevel($row['account_level'] ?? 1);
    $row['referral_username'] = $row['referral_username_raw'] ? "@" . $row['referral_username_raw'] : 'None';
    
    $users[] = $row;
}

// Get global stats based on current filters (Vendor selection)
$stats_vendor_stmt = ($vid > 0) ? " WHERE vendor_id='$vid'" : "";
$stats_q = mysqli_query($connection_server, "SELECT
    COUNT(*) as total,
    COALESCE(SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END), 0) as active,
    COALESCE(SUM(CASE WHEN status = 2 THEN 1 ELSE 0 END), 0) as blocked,
    COALESCE(SUM(CASE WHEN status = 3 THEN 1 ELSE 0 END), 0) as deleted
    FROM sas_users $stats_vendor_stmt");

if (!$stats_q) {
    echo json_encode(["status" => "error", "message" => "Stats query failed: " . mysqli_error($connection_server)]);
    exit;
}

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

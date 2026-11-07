<?php
session_start();
header("Content-Type: application/json");
include("../func/bc-admin-config.php");

if (!isset($get_logged_admin_details["id"])) {
    echo json_encode(["status" => "error", "message" => "Unauthorized access"]);
    exit;
}

$vid = $get_logged_admin_details["id"];
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Free-text search (email / phone / username / name) plus the dedicated "filter by name" box.
$search = isset($_GET['searchq']) ? mysqli_real_escape_string($connection_server, trim($_GET['searchq'])) : '';
$name = isset($_GET['name']) ? mysqli_real_escape_string($connection_server, trim($_GET['name'])) : '';

// Status is a closed set - the request never reaches the SQL.
$status = isset($_GET['status']) ? trim($_GET['status']) : '1';
if (!in_array($status, array('1', '2', '3', 'all'), true)) {
    $status = '1';
}

// Wallet range. An empty box means "no bound" (not zero) so either end can be used alone.
$wallet_min = (isset($_GET['wallet_min']) && trim($_GET['wallet_min']) !== '') ? (float)$_GET['wallet_min'] : null;
$wallet_max = (isset($_GET['wallet_max']) && trim($_GET['wallet_max']) !== '') ? (float)$_GET['wallet_max'] : null;

$search_statement = "";
if (!empty($search)) {
    $search_statement = " AND (u.email LIKE '%$search%' OR u.phone_number LIKE '%$search%' OR u.username LIKE '%$search%' OR u.firstname LIKE '%$search%' OR u.lastname LIKE '%$search%' OR u.othername LIKE '%$search%')";
}

$name_statement = "";
if (!empty($name)) {
    $name_statement = " AND (u.firstname LIKE '%$name%' OR u.lastname LIKE '%$name%' OR u.othername LIKE '%$name%' OR CONCAT_WS(' ', u.firstname, u.lastname, u.othername) LIKE '%$name%')";
}

$wallet_statement = "";
if ($wallet_min !== null) {
    $wallet_statement .= " AND u.balance >= $wallet_min";
}
if ($wallet_max !== null) {
    $wallet_statement .= " AND u.balance <= $wallet_max";
}

$status_statement = ($status === 'all') ? "" : " AND u.status='$status'";

// ORDER BY can never take request text - fixed whitelist only, defaulting to newest first.
$sort_options = array(
    'newest'      => "u.reg_date DESC, u.id DESC",
    'oldest'      => "u.reg_date ASC, u.id ASC",
    'name_az'     => "u.firstname ASC, u.lastname ASC, u.username ASC",
    'name_za'     => "u.firstname DESC, u.lastname DESC, u.username DESC",
    'username_az' => "u.username ASC",
    'username_za' => "u.username DESC",
    'wallet_high' => "u.balance DESC, u.username ASC",
    'wallet_low'  => "u.balance ASC, u.username ASC",
);
$sort = isset($_GET['sort']) ? trim($_GET['sort']) : 'newest';
$order_by = isset($sort_options[$sort]) ? $sort_options[$sort] : $sort_options['newest'];

$where = "u.vendor_id='$vid' $status_statement $search_statement $name_statement $wallet_statement";

// Get total for current view
$total_query = mysqli_query($connection_server, "SELECT COUNT(*) as count FROM sas_users u WHERE $where");
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
        WHERE $where
        ORDER BY $order_by
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

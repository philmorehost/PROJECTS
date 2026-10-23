<?php
session_start();
header("Content-Type: application/json");
include("../func/bc-spadmin-config.php");

// Super Admin authorization check
if (!isset($get_logged_spadmin_details["id"])) {
    echo json_encode([]);
    exit;
}

$query = isset($_GET['q']) ? mysqli_real_escape_string($connection_server, trim($_GET['q'])) : '';
$target = isset($_GET['target']) ? $_GET['target'] : 'vendor';
$vid = isset($_GET['vid']) ? (int)$_GET['vid'] : 0;

if (strlen($query) < 2) {
    echo json_encode([]);
    exit;
}

$suggestions = [];

if ($target === 'vendor') {
    $res = mysqli_query($connection_server, "SELECT email, website_url, firstname, lastname FROM sas_vendors WHERE (email LIKE '%$query%' OR website_url LIKE '%$query%' OR firstname LIKE '%$query%' OR lastname LIKE '%$query%') LIMIT 10");
    while ($row = mysqli_fetch_assoc($res)) {
        $suggestions[] = [
            "value" => $row['email'],
            "label" => $row['firstname'] . " " . $row['lastname'] . " (" . $row['website_url'] . ")"
        ];
    }
} else if ($target === 'user' && $vid > 0) {
    $res = mysqli_query($connection_server, "SELECT username, firstname, lastname FROM sas_users WHERE vendor_id='$vid' AND (username LIKE '%$query%' OR firstname LIKE '%$query%' OR lastname LIKE '%$query%') LIMIT 10");
    while ($row = mysqli_fetch_assoc($res)) {
        $suggestions[] = [
            "value" => $row['username'],
            "label" => $row['firstname'] . " " . $row['lastname'] . " (@" . $row['username'] . ")"
        ];
    }
}

echo json_encode($suggestions);
exit;

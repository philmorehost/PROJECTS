<?php
session_start();
include("../func/bc-admin-config.php");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_bank_status'])) {
    $reference = mysqli_real_escape_string($connection_server, $_POST['bank_id']);
    $status = (int)$_POST['status'];
    $type = mysqli_real_escape_string($connection_server, $_POST['type']); // 'user' or 'vendor'
    $vid = $get_logged_admin_details['id'];

    if ($type === 'user') {
        $query = "UPDATE sas_user_banks SET status = $status WHERE reference = '$reference' AND vendor_id = $vid";
    } else {
        $query = "UPDATE sas_vendor_banks SET status = $status WHERE reference = '$reference' AND vendor_id = $vid";
    }

    if (mysqli_query($connection_server, $query)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => mysqli_error($connection_server)]);
    }
    exit();
}
?>

<?php
session_start();
include("../func/bc-spadmin-config.php");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_bank_status'])) {
    $reference = mysqli_real_escape_string($connection_server, $_POST['reference']);
    $status = (int)$_POST['status'];
    
    // Super Admin can toggle any vendor's bank
    $query = "UPDATE sas_vendor_banks SET status = $status WHERE reference = '$reference'";

    if (mysqli_query($connection_server, $query)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => mysqli_error($connection_server)]);
    }
    exit();
}
?>

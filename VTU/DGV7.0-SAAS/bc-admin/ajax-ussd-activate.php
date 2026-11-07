<?php session_start();
include("../func/bc-admin-config.php");

header('Content-Type: application/json');

// Ensure vendor session exists
if (!isset($_SESSION["admin_session"]) || !isset($get_logged_admin_details['id'])) {
    exit(json_encode(["status" => "error", "message" => "Unauthorized access."]));
}

$vid = $get_logged_admin_details['id'];

// Get USSD Channel settings
$enabled = getSuperAdminOption('ussd_access_enabled', '0');
$fee = (float)getSuperAdminOption('ussd_access_fee', '0');
$min_deposit = (float)getSuperAdminOption('ussd_min_deposit', '0');
$total_payable = $fee + $min_deposit;

if ($enabled != '1') {
    exit(json_encode(["status" => "error", "message" => "USSD Channel activation is currently disabled globally by the administrator."]));
}

// Check if already active
if ($get_logged_admin_details['ussd_access'] == 1) {
    exit(json_encode(["status" => "success", "message" => "USSD Channel is already active."]));
}

// Check vendor balance
$balance = (float)$get_logged_admin_details['balance'];
if ($balance < $total_payable) {
    exit(json_encode(["status" => "error", "message" => "Insufficient wallet balance. You need at least ₦" . number_format($total_payable, 2) . " (₦" . number_format($fee, 2) . " activation fee + ₦" . number_format($min_deposit, 2) . " minimum initial deposit) to activate this service. Current Balance: ₦" . number_format($balance, 2)]));
}

// Charge vendor (activation fee only, initial deposit remains in their wallet)
if ($fee > 0) {
    $reference = 'USSD_' . time();
    $charge = chargeVendor('debit', 'USSD_ACCESS', 'USSD Channel Activation', $reference, $fee, $fee, 'USSD Channel Access Fee', $_SERVER['HTTP_HOST'], 1);
    
    if ($charge !== 'success') {
        exit(json_encode(["status" => "error", "message" => "Payment processing failed. Please try again or contact support."]));
    }
}

// Update vendor access status
$update = mysqli_query($connection_server, "UPDATE sas_vendors SET ussd_access=1 WHERE id='$vid'");

if ($update) {
    echo json_encode(["status" => "success", "message" => "USSD Channel activated successfully!"]);
} else {
    echo json_encode(["status" => "error", "message" => "Failed to update access status. Please contact support."]);
}
<?php
include("../func/bc-config.php");

// Mocking session and vendor for verification
$_SESSION["user_session"] = "demouser";
$get_logged_user_details = [
    'vendor_id' => 1,
    'username' => 'demouser',
    'balance' => 50000,
    'firstname' => 'John',
    'lastname' => 'Doe',
    'account_level' => 1,
    'kyc_status' => 2,
    'email' => 'demouser@example.com'
];
$vendor_primary_color = "#287bff";
$select_vendor_table = ['crypto_swap_fee' => 2.5];

$title = "Your Transaction was Successful";
$message = "Hello John,\n\nYour purchase of 1GB Data for 08124232128 was successful. Your new balance is N15,000.00.\n\nThank you for choosing Philmore Codes!";
$details = ["2348124232128"];

echo mailDesignTemplate($title, $message, $details);
?>
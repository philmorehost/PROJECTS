<?php
session_start();
include("../func/bc-connect.php");

$reference = isset($_GET['reference']) ? $_GET['reference'] : '';

if (empty($reference)) {
    die("No reference provided.");
}

// Get Paystack secret key
$gateway_query = mysqli_query($connection_server, "SELECT secret_key FROM sas_super_admin_payment_gateways WHERE gateway_name = 'paystack'");
if (!$gateway_query || mysqli_num_rows($gateway_query) == 0) {
    die("Paystack gateway not configured.");
}
$gateway = mysqli_fetch_assoc($gateway_query);
$secret_key = $gateway['secret_key'];

$curl = curl_init();
curl_setopt_array($curl, array(
    CURLOPT_URL => "https://api.paystack.co/transaction/verify/" . rawurlencode($reference),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer $secret_key",
        "Cache-Control: no-cache",
    ],
));

$response = curl_exec($curl);
$err = curl_error($curl);
curl_close($curl);

if ($err) {
    // cURL error
    // You can log this error for debugging
    header("Location: /bc-spadmin/VendorReg.php?status=error&message=" . urlencode("Payment verification failed. Please contact support."));
    exit();
}

$result = json_decode($response);

if ($result->status && $result->data->status == 'success') {
    // Payment was successful
    $db_reference = mysqli_real_escape_string($connection_server, $result->data->reference);
    
    // Update the pending vendor's status
    $sql = "UPDATE sas_pending_vendors SET status = 'payment_successful' WHERE payment_reference = '$db_reference'";
    
    if (mysqli_query($connection_server, $sql)) {
        // Redirect to a success page with a thank you message
        $_SESSION['product_purchase_response'] = "Payment successful! Your registration is now pending admin approval.";
        header("Location: /bc-spadmin/VendorReg.php?status=success");
        exit();
    } else {
        // Database update error
        // You can log this error for debugging
        header("Location: /bc-spadmin/VendorReg.php?status=error&message=" . urlencode("Could not update your registration status. Please contact support."));
        exit();
    }
} else {
    // Payment was not successful
    header("Location: /bc-spadmin/VendorReg.php?status=failed&message=" . urlencode($result->data->gateway_response));
    exit();
}
?>

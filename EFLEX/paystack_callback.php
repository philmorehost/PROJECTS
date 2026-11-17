<?php
session_start();
require_once 'includes/db_connect.php';

// Retrieve the transaction reference from the URL
if (!isset($_GET['reference'])) {
    die("No reference supplied.");
}
$reference = $_GET['reference'];

// 1. Fetch Paystack secret key
$sql_settings = "SELECT setting_value FROM settings WHERE setting_key = 'paystack_secret_key'";
$result_settings = $mysqli->query($sql_settings);
$settings = $result_settings->fetch_assoc();
$paystack_secret_key = $settings['setting_value'] ?? '';

if (empty($paystack_secret_key)) {
    die("Paystack payment gateway is not configured.");
}

// 2. Verify the transaction with Paystack
$url = 'https://api.paystack.co/transaction/verify/' . rawurlencode($reference);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $paystack_secret_key,
]);

$result = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

if ($err) {
    die("cURL Error: " . $err);
}

$response = json_decode($result, true);

if ($response['status'] == true && $response['data']['status'] == 'success') {
    // 3. Payment was successful
    $transaction_data = $response['data'];
    $order_id = $transaction_data['metadata']['order_id'];
    $transaction_reference = $transaction_data['reference'];

    // 4. Update the order in the database
    // NOTE: the column is `transaction_id` (an older schema used `transaction_reference`,
    // which db_setup.php renames on upgrade).
    $sql_update = "UPDATE orders SET status = 'Completed', transaction_id = ? WHERE id = ?";
    if ($stmt_update = $mysqli->prepare($sql_update)) {
        $stmt_update->bind_param("si", $transaction_reference, $order_id);
        $stmt_update->execute();
        $stmt_update->close();

        // 5. Unset session and redirect to success page
        unset($_SESSION['order_id']);
        header("Location: order_success.php?id=" . $order_id);
        exit();
    } else {
        // Handle DB error
        die("Database update failed.");
    }
} else {
    // 6. Payment failed or was abandoned
    $order_id = $_SESSION['order_id'] ?? null;
    if($order_id){
         // Optionally update status to 'Failed'
        $stmt_fail = $mysqli->prepare("UPDATE orders SET status = 'Failed' WHERE id = ?");
        $stmt_fail->bind_param("i", $order_id);
        $stmt_fail->execute();
        $stmt_fail->close();
    }
    unset($_SESSION['order_id']);
    header("Location: my_orders.php"); // Redirect to a generic failure/orders page
    exit();
}
?>

<?php
session_start();
require_once 'includes/db_connect.php';

// Retrieve the session ID from the URL
if (!isset($_GET['session_id'])) {
    die("No session ID supplied.");
}
$session_id = $_GET['session_id'];

// 1. Fetch Stripe secret key
$settings_sql = "SELECT setting_value FROM settings WHERE setting_key = 'stripe_secret_key'";
$result_settings = $mysqli->query($settings_sql);
$settings = $result_settings->fetch_assoc();
$stripe_secret_key = $settings['setting_value'] ?? '';

if (empty($stripe_secret_key)) {
    die("Stripe payment gateway is not configured.");
}

// 2. Verify the session with Stripe
$url = 'https://api.stripe.com/v1/checkout/sessions/' . $session_id;

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_USERPWD, $stripe_secret_key . ":");

$result = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

if ($err) {
    die("cURL Error: " . $err);
}

$response = json_decode($result, true);

if (isset($response['id']) && $response['payment_status'] === 'paid') {
    // 3. Payment was successful
    $order_id = $response['client_reference_id'];
    $transaction_reference = $response['payment_intent'];

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
        die("Database update failed.");
    }
} else {
    // 6. Payment failed or was not completed
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

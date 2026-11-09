<?php
session_start();
require_once 'includes/db_connect.php';

if (!isset($_GET['status']) || !isset($_GET['tx_ref']) || !isset($_GET['transaction_id'])) {
    header("Location: my_orders.php");
    exit();
}

if ($_GET['status'] === 'successful') {
    $transaction_id = $_GET['transaction_id'];
    $tx_ref = $_GET['tx_ref'];

    // 1. Fetch Flutterwave secret key
    $settings_sql = "SELECT setting_value FROM settings WHERE setting_key = 'flutterwave_secret_key'";
    $result_settings = $mysqli->query($settings_sql);
    $settings = $result_settings->fetch_assoc();
    $flutterwave_secret_key = $settings['setting_value'] ?? '';

    if (empty($flutterwave_secret_key)) {
        die("Flutterwave payment gateway is not configured.");
    }

    // 2. Verify the transaction with Flutterwave
    $url = "https://api.flutterwave.com/v3/transactions/" . $transaction_id . "/verify";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        "Authorization: Bearer " . $flutterwave_secret_key
    ));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $result = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        die("cURL Error: " . $err);
    }

    $response = json_decode($result, true);

    if ($response['status'] === 'success') {
        $verified_tx_ref = $response['data']['tx_ref'];
        $verified_amount = $response['data']['amount'];
        $verified_currency = $response['data']['currency'];

        // 3. Get our order details based on the original tx_ref
        // NOTE: the column is `transaction_id` (an older schema used `transaction_reference`).
        $sql_order = "SELECT id, total_amount FROM orders WHERE transaction_id = ?";
        $stmt_order = $mysqli->prepare($sql_order);
        $stmt_order->bind_param("s", $tx_ref);
        $stmt_order->execute();
        $order = $stmt_order->get_result()->fetch_assoc();
        $stmt_order->close();

        if ($order && $verified_tx_ref == $tx_ref && $verified_amount == $order['total_amount']) {
            // 4. Payment is valid. Update order status.
            $order_id = $order['id'];
            $sql_update = "UPDATE orders SET status = 'Completed' WHERE id = ?";
            if ($stmt_update = $mysqli->prepare($sql_update)) {
                $stmt_update->bind_param("i", $order_id);
                $stmt_update->execute();
                $stmt_update->close();

                unset($_SESSION['order_id']);
                header("Location: order_success.php?id=" . $order_id);
                exit();
            }
        }
    }
}

// If status is not successful or verification fails, redirect
header("Location: my_orders.php");
exit();

?>

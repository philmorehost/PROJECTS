<?php
session_start();
require_once 'includes/db_connect.php';

// Check if user is logged in and order_id is in session
if (!isset($_SESSION["loggedin"]) || !isset($_SESSION['order_id'])) {
    header("location: index.php");
    exit;
}

$order_id = $_SESSION['order_id'];
$user_id = $_SESSION['id'];
$user_email = $_SESSION['email'];

// 1. Fetch order details
$sql_order = "SELECT total_amount FROM orders WHERE id = ? AND user_id = ?";
$stmt_order = $mysqli->prepare($sql_order);
$stmt_order->bind_param("ii", $order_id, $user_id);
$stmt_order->execute();
$result = $stmt_order->get_result();
$order = $result->fetch_assoc();
$stmt_order->close();

if (!$order) {
    die("Invalid Order.");
}
$amount_in_kobo = $order['total_amount'] * 100; // Paystack requires amount in kobo

// 2. Fetch Paystack secret key
$sql_settings = "SELECT setting_value FROM settings WHERE setting_key = 'paystack_secret_key'";
$result_settings = $mysqli->query($sql_settings);
$settings = $result_settings->fetch_assoc();
$paystack_secret_key = $settings['setting_value'] ?? '';

if (empty($paystack_secret_key)) {
    die("Paystack payment gateway is not configured.");
}

// 3. Initialize Transaction with Paystack
$url = "https://api.paystack.co/transaction/initialize";
$fields = [
    'email' => $user_email,
    'amount' => $amount_in_kobo,
    'order_id' => $order_id,
    'callback_url' => 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/paystack_callback.php',
    'metadata' => [
        'order_id' => $order_id,
        'user_id' => $user_id
    ]
];

$fields_string = http_build_query($fields);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_string);
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    "Authorization: Bearer " . $paystack_secret_key,
    "Cache-Control: no-cache",
));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$result = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

if ($err) {
    die("cURL Error: " . $err);
}

$response = json_decode($result, true);

if ($response['status'] == true) {
    // 4. Redirect user to Paystack payment page
    $auth_url = $response['data']['authorization_url'];
    header('Location: ' . $auth_url);
    exit();
} else {
    // Handle API error
    die("Paystack API Error: " . $response['message']);
}
?>

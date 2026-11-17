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
$username = $_SESSION['username'];

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
$amount = $order['total_amount'];

// 2. Fetch Flutterwave secret key and other settings
$settings_sql = "SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('flutterwave_secret_key', 'currency_code', 'site_name')";
$result_settings = $mysqli->query($settings_sql);
$settings = [];
while($row = $result_settings->fetch_assoc()){
    $settings[$row['setting_key']] = $row['setting_value'];
}
$flutterwave_secret_key = $settings['flutterwave_secret_key'] ?? '';
$currency_code = $settings['currency_code'] ?? 'USD';
$site_name = $settings['site_name'] ?? 'Eflex';

if (empty($flutterwave_secret_key)) {
    die("Flutterwave payment gateway is not configured.");
}

// 3. Initialize Transaction with Flutterwave
$url = "https://api.flutterwave.com/v3/payments";
$tx_ref = "eflex-" . $order_id . "-" . time(); // Unique transaction reference

$fields = [
    'tx_ref' => $tx_ref,
    'amount' => $amount,
    'currency' => $currency_code,
    'redirect_url' => 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/flutterwave_callback.php',
    'customer' => [
        'email' => $user_email,
        'name' => $username,
    ],
    'customizations' => [
        'title' => $site_name . ' Checkout',
    ]
];

$fields_string = json_encode($fields);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_string);
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    "Authorization: Bearer " . $flutterwave_secret_key,
    "Content-Type: application/json"
));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$result = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

if ($err) {
    die("cURL Error: " . $err);
}

$response = json_decode($result, true);

if (isset($response['status']) && $response['status'] == 'success') {
    // Save the tx_ref to the order for verification
    // NOTE: the column is `transaction_id` (an older schema used `transaction_reference`,
    // which db_setup.php renames on upgrade).
    $stmt_update = $mysqli->prepare("UPDATE orders SET transaction_id = ? WHERE id = ?");
    $stmt_update->bind_param("si", $tx_ref, $order_id);
    $stmt_update->execute();
    $stmt_update->close();

    // 4. Redirect user to Flutterwave payment page
    $auth_url = $response['data']['link'];
    header('Location: ' . $auth_url);
    exit();
} else {
    // Handle API error
    die("Flutterwave API Error: " . ($response['message'] ?? 'Unknown error'));
}
?>

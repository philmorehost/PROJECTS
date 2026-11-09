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

// 1. Fetch order details
$sql_order = "SELECT * FROM orders WHERE id = ? AND user_id = ?";
$stmt_order = $mysqli->prepare($sql_order);
$stmt_order->bind_param("ii", $order_id, $user_id);
$stmt_order->execute();
$order = $stmt_order->get_result()->fetch_assoc();
$stmt_order->close();

if (!$order) {
    die("Invalid Order.");
}

// 2. Fetch order items
$sql_items = "SELECT oi.*, p.name as product_name
              FROM order_items oi
              JOIN products p ON oi.product_id = p.id
              WHERE oi.order_id = ?";
$stmt_items = $mysqli->prepare($sql_items);
$stmt_items->bind_param("i", $order_id);
$stmt_items->execute();
$order_items = $stmt_items->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_items->close();

// 3. Fetch Stripe secret key and currency settings
$settings_sql = "SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('stripe_secret_key', 'currency_code')";
$result_settings = $mysqli->query($settings_sql);
$settings = [];
while($row = $result_settings->fetch_assoc()){
    $settings[$row['setting_key']] = $row['setting_value'];
}
$stripe_secret_key = $settings['stripe_secret_key'] ?? '';
$currency_code = strtolower($settings['currency_code'] ?? 'usd');

if (empty($stripe_secret_key)) {
    die("Stripe payment gateway is not configured.");
}

// 4. Create line items array for Stripe
$line_items = [];
foreach ($order_items as $item) {
    $line_items[] = [
        'price_data' => [
            'currency' => $currency_code,
            'product_data' => [
                'name' => $item['product_name'],
            ],
            'unit_amount' => $item['price'] * 100, // Amount in cents
        ],
        'quantity' => $item['quantity'],
    ];
}

// 5. Initialize Checkout Session with Stripe
$url = "https://api.stripe.com/v1/checkout/sessions";
$post_data = [
    'payment_method_types' => ['card'],
    'line_items' => $line_items,
    'mode' => 'payment',
    'success_url' => 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/stripe_callback.php?session_id={CHECKOUT_SESSION_ID}',
    'cancel_url' => 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/checkout.php',
    'client_reference_id' => $order_id,
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
curl_setopt($ch, CURLOPT_USERPWD, $stripe_secret_key . ":"); // Basic Auth

$result = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

if ($err) {
    die("cURL Error: " . $err);
}

$response = json_decode($result, true);

if (isset($response['id'])) {
    // Save the session ID to the order
    $stripe_session_id = $response['id'];
    $stmt_update = $mysqli->prepare("UPDATE orders SET stripe_payment_intent_id = ? WHERE id = ?");
    $stmt_update->bind_param("si", $stripe_session_id, $order_id);
    $stmt_update->execute();
    $stmt_update->close();

    // 6. Redirect user to Stripe payment page
    header('Location: ' . $response['url']);
    exit();
} else {
    // Handle API error
    die("Stripe API Error: " . ($response['error']['message'] ?? 'Unknown error'));
}
?>

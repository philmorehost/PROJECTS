<?php
// We need to start the session to access the cart
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ==========================================================================================
// SERVER-SIDE PAYMENT INTENT CREATION (PLACEHOLDER)
// ==========================================================================================
//
// NOTE: This script cannot be fully implemented because the Stripe PHP SDK is not
// available in the current environment (the 'php' and 'composer' commands are missing).
//
// To make this script functional, you must:
// 1. Install Composer in your project directory.
// 2. Run `composer require stripe/stripe-php`.
// 3. Ensure this script can access the 'vendor/autoload.php' file created by Composer.
//
// The code below serves as a guide for the final implementation.

// 1. REQUIRE COMPOSER AUTOLOADER & CONFIG
// require_once 'vendor/autoload.php'; // Uncomment this line after installing the SDK
require_once 'includes/config.php';
require_once 'includes/db_connect.php'; // Needed to get product prices

// 2. CALCULATE ORDER AMOUNT
$total_price_cents = 0;
if(!empty($_SESSION['cart'])){
    // In a real implementation, you should always fetch the prices from your database
    // on the server side to prevent customers from manipulating the price on the client side.
    //
    // NOTE: cart keys are composite - 'p_<product_id>' or 'v_<variant_id>' - so
    // array_keys() cannot be used as ids directly (bind_param would cast them to 0,
    // the IN() clause would match nothing, and the total would stay at 0).
    $product_ids = [];
    $variant_ids = [];
    foreach($_SESSION['cart'] as $key => $qty) {
        if (strpos($key, 'p_') === 0) {
            $product_ids[] = (int)substr($key, 2);
        } elseif (strpos($key, 'v_') === 0) {
            $variant_ids[] = (int)substr($key, 2);
        }
    }

    // Simple products
    if (!empty($product_ids)) {
        $placeholders = implode(',', array_fill(0, count($product_ids), '?'));
        $sql = "SELECT id, price FROM products WHERE id IN ($placeholders)";
        if($stmt = $mysqli->prepare($sql)){
            $types = str_repeat('i', count($product_ids));
            $stmt->bind_param($types, ...$product_ids);
            $stmt->execute();
            $result = $stmt->get_result();

            while($row = $result->fetch_assoc()){
                $quantity = $_SESSION['cart']['p_' . $row['id']];
                // Stripe requires the amount in the smallest currency unit (e.g., cents for USD)
                $total_price_cents += ($row['price'] * 100) * $quantity;
            }
            $stmt->close();
        }
    }

    // Variant products (a variant may override the base price)
    if (!empty($variant_ids)) {
        $placeholders = implode(',', array_fill(0, count($variant_ids), '?'));
        $sql = "SELECT v.id AS variant_id, v.price, p.price AS base_price
                FROM product_variants v
                JOIN products p ON v.product_id = p.id
                WHERE v.id IN ($placeholders)";
        if($stmt = $mysqli->prepare($sql)){
            $types = str_repeat('i', count($variant_ids));
            $stmt->bind_param($types, ...$variant_ids);
            $stmt->execute();
            $result = $stmt->get_result();

            while($row = $result->fetch_assoc()){
                $quantity = $_SESSION['cart']['v_' . $row['variant_id']];
                $price = $row['price'] ?? $row['base_price'];
                $total_price_cents += ($price * 100) * $quantity;
            }
            $stmt->close();
        }
    }
}

// 3. INITIALIZE STRIPE CLIENT (PLACEHOLDER)
// \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY); // Uncomment this

// 4. CREATE A PAYMENT INTENT (PLACEHOLDER)
/* // Uncomment this block
try {
    $paymentIntent = \Stripe\PaymentIntent::create([
        'amount' => $total_price_cents,
        'currency' => 'usd',
        'automatic_payment_methods' => [
            'enabled' => true,
        ],
    ]);

    $output = [
        'clientSecret' => $paymentIntent->client_secret,
    ];

    echo json_encode($output);

} catch (Error $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
*/

// 5. MOCK OUTPUT FOR FRONTEND SCAFFOLDING
// This is a placeholder to allow the frontend to initialize without error.
// It will not work for actual payments.
// Replace this with the real implementation above.
if (true) {
     echo json_encode(['clientSecret' => 'pi_12345_secret_67890']);
}

?>

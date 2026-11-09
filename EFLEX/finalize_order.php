<?php
// We need to start the session to access cart data and user ID
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ==========================================================================================
// SERVER-SIDE ORDER FINALIZATION (PLACEHOLDER)
// ==========================================================================================
//
// NOTE: This script is a placeholder. It requires the Stripe PHP SDK to be installed.
//
// This script should be called by the `order_success.php` page after the customer
// returns from the Stripe payment flow. It's responsible for:
// 1. Retrieving the PaymentIntent from Stripe.
// 2. Verifying the payment was successful.
// 3. Saving the order details to the database if it hasn't been saved already.
// 4. Clearing the user's cart.

// 1. REQUIRE DEPENDENCIES
// require_once 'vendor/autoload.php'; // Uncomment after SDK installation
require_once 'includes/config.php';
require_once 'includes/db_connect.php';

// 2. RETRIEVE PAYMENT INTENT ID FROM POST DATA
// $payment_intent_id = isset($_POST['payment_intent']) ? $_POST['payment_intent'] : '';

// 3. VERIFY PAYMENT WITH STRIPE (PLACEHOLDER)
/* // Uncomment this block
try {
    \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
    $paymentIntent = \Stripe\PaymentIntent::retrieve($payment_intent_id);

    if ($paymentIntent->status == 'succeeded') {
        // Payment was successful. Now, save the order to our database.

        // First, check if this order has already been processed to prevent duplicates
        $sql_check = "SELECT id FROM orders WHERE stripe_payment_intent_id = ?";
        if($stmt_check = $mysqli->prepare($sql_check)){
            $stmt_check->bind_param("s", $payment_intent_id);
            $stmt_check->execute();
            $stmt_check->store_result();
            if($stmt_check->num_rows == 0){
                // Order not yet in DB, so proceed to save it.
                // The logic from the old checkout.php goes here:
                // - Calculate total from session cart
                // - Insert into `orders` table with payment intent ID
                // - Insert into `order_items` table
                // - Clear the cart session
                // ...
            }
            $stmt_check->close();
        }

        // Return a success message
        echo json_encode(['status' => 'success', 'order_id' => 'YOUR_NEW_DB_ORDER_ID']);

    } else {
        // Payment was not successful
        echo json_encode(['status' => 'error', 'message' => 'Payment was not successful.']);
    }

} catch (Error $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
*/

// 4. MOCK OUTPUT FOR FRONTEND SCAFFOLDING
if (true) {
    // This is a placeholder response.
    echo json_encode(['status' => 'success', 'orderId' => 'MOCK_ORDER_123']);
}

?>

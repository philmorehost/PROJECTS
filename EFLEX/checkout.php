<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    $_SESSION['redirect_to_url'] = 'checkout.php';
    header("location: login.php");
    exit;
}

require_once 'includes/db_connect.php';
require_once 'includes/send_email.php';
require_once 'includes/send_notification.php';
require_once 'includes/helpers.php';

if(empty($_SESSION['cart'])){
    header("location: cart.php");
    exit;
}

// Fetch site settings for payment gateways and email
$settings_sql = "SELECT setting_key, setting_value FROM settings";
$result = $mysqli->query($settings_sql);
$settings = [];
while($row = $result->fetch_assoc()){
    $settings[$row['setting_key']] = $row['setting_value'];
}
$paystack_enabled = !empty($settings['paystack_enabled']);
$bank_transfer_enabled = !empty($settings['bank_transfer_enabled']);
$stripe_enabled = !empty($settings['stripe_enabled']);
$flutterwave_enabled = !empty($settings['flutterwave_enabled']);
$admin_email = $settings['admin_notification_email'] ?? ($settings['from_email'] ?? ''); // Fallback to from_email if not set
$site_name = $settings['site_name'] ?? 'Eflex';


// Fetch cart items and calculate total
$cart_items = [];
$total_price = 0;
if(!empty($_SESSION['cart'])){
    // This logic is now duplicated from cart.php and should be refactored in a real-world scenario
    $product_ids = [];
    $variant_ids = [];
    foreach($_SESSION['cart'] as $key => $qty) {
        if (strpos($key, 'p_') === 0) {
            $product_ids[] = (int)substr($key, 2);
        } elseif (strpos($key, 'v_') === 0) {
            $variant_ids[] = (int)substr($key, 2);
        }
    }

    if (!empty($product_ids)) {
        $placeholders = implode(',', array_fill(0, count($product_ids), '?'));
        $sql = "SELECT id, name, price, image FROM products WHERE id IN ($placeholders)";
        if($stmt = $mysqli->prepare($sql)){
            $stmt->bind_param(str_repeat('i', count($product_ids)), ...$product_ids);
            $stmt->execute();
            $result = $stmt->get_result();
            while($row = $result->fetch_assoc()){
                $cart_key = 'p_' . $row['id'];
                $quantity = $_SESSION['cart'][$cart_key];
                $subtotal = $row['price'] * $quantity;
                $total_price += $subtotal;
                $cart_items[$cart_key] = ['name' => $row['name'], 'price' => $row['price'], 'quantity' => $quantity, 'subtotal' => $subtotal, 'is_variant' => false, 'product_id' => $row['id'], 'variant_id' => null];
            }
            $stmt->close();
        }
    }

    if (!empty($variant_ids)) {
        $placeholders = implode(',', array_fill(0, count($variant_ids), '?'));
        $sql = "
            SELECT v.id as variant_id, p.id as product_id, p.name, v.price, p.image,
                   (SELECT p_base.price FROM products p_base WHERE p_base.id = v.product_id) as base_price,
                   GROUP_CONCAT(CONCAT(pa.name, ': ', av.value) SEPARATOR ', ') as options
            FROM product_variants v JOIN products p ON v.product_id = p.id JOIN product_variant_options pvo ON v.id = pvo.variant_id
            JOIN attribute_values av ON pvo.value_id = av.id JOIN product_attributes pa ON av.attribute_id = pa.id
            WHERE v.id IN ($placeholders) GROUP BY v.id
        ";
        if($stmt = $mysqli->prepare($sql)){
            $stmt->bind_param(str_repeat('i', count($variant_ids)), ...$variant_ids);
            $stmt->execute();
            $result = $stmt->get_result();
            while($row = $result->fetch_assoc()){
                $cart_key = 'v_' . $row['variant_id'];
                $quantity = $_SESSION['cart'][$cart_key];
                $price = $row['price'] ?? $row['base_price'];
                $subtotal = $price * $quantity;
                $total_price += $subtotal;
                $cart_items[$cart_key] = ['name' => $row['name'] . ' (' . $row['options'] . ')', 'price' => $price, 'quantity' => $quantity, 'subtotal' => $subtotal, 'is_variant' => true, 'product_id' => $row['product_id'], 'variant_id' => $row['variant_id']];
            }
            $stmt->close();
        }
    }
}


// Order processing
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['place_order'])){
    // Re-validate stock levels before processing the order
    foreach ($cart_items as $key => $item) {
        $stock_to_check = 0;
        $product_name_for_error = $item['name'];

        if ($item['is_variant']) {
            $stmt = $mysqli->prepare("SELECT stock FROM product_variants WHERE id = ?");
            $stmt->bind_param("i", $item['variant_id']);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $stock_to_check = $row['stock'];
            }
            $stmt->close();
        } else {
            // Check if the product has variants, if so, stock is managed at the variant level
            $p_stmt = $mysqli->prepare("SELECT has_variants, stock FROM products WHERE id = ?");
            $p_stmt->bind_param("i", $item['product_id']);
            $p_stmt->execute();
            $p_result = $p_stmt->get_result();
            if ($p_row = $p_result->fetch_assoc()) {
                if (!$p_row['has_variants']) {
                    $stock_to_check = $p_row['stock'];
                }
            }
            $p_stmt->close();
        }

        if ($item['quantity'] > $stock_to_check) {
            $_SESSION['cart_error'] = "Sorry, the quantity for '" . htmlspecialchars($product_name_for_error) . "' is no longer available. Please adjust your cart.";
            header("Location: cart.php");
            exit();
        }
    }

    $user_id = $_SESSION['id'];
    $payment_method = $_POST['payment_method'];
    $order_notes = !empty($_POST['order_notes']) ? trim($_POST['order_notes']) : null;
    $status = ($payment_method === 'bank_transfer') ? 'Awaiting Payment' : 'Pending';
    $transaction_id = generate_unique_transaction_id($mysqli);

    $mysqli->begin_transaction();
    try {
        $sql_order = "INSERT INTO orders (user_id, total_amount, payment_method, status, transaction_id, order_notes) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt_order = $mysqli->prepare($sql_order);
        $stmt_order->bind_param("idssss", $user_id, $total_price, $payment_method, $status, $transaction_id, $order_notes);
        $stmt_order->execute();
        $order_id = $mysqli->insert_id;
        $_SESSION['order_id'] = $order_id;

        $sql_items = "INSERT INTO order_items (order_id, product_id, variant_id, quantity, price) VALUES (?, ?, ?, ?, ?)";
        $stmt_items = $mysqli->prepare($sql_items);

        foreach($cart_items as $item){
            // Add to order items table
            $stmt_items->bind_param("iiiid", $order_id, $item['product_id'], $item['variant_id'], $item['quantity'], $item['price']);
            $stmt_items->execute();

            // Decrement stock immediately upon order placement
            if ($item['is_variant']) {
                $sql_update_stock = "UPDATE product_variants SET stock = stock - ? WHERE id = ?";
                $stmt_stock = $mysqli->prepare($sql_update_stock);
                $stmt_stock->bind_param("ii", $item['quantity'], $item['variant_id']);
                $stmt_stock->execute();
                $stmt_stock->close();
            } else {
                $sql_update_stock = "UPDATE products SET stock = stock - ? WHERE id = ? AND has_variants = 0";
                $stmt_stock = $mysqli->prepare($sql_update_stock);
                $stmt_stock->bind_param("ii", $item['quantity'], $item['product_id']);
                $stmt_stock->execute();
                $stmt_stock->close();
            }
        }
        $stmt_items->close();
        $stmt_order->close();

        $mysqli->commit();
        unset($_SESSION['cart']);

        // Send confirmation email to user
        $currency_symbol = htmlspecialchars($_SESSION['currency_symbol'] ?? '$');
        $user_email = $_SESSION['email'] ?? null;
        if($user_email) {
            $user_subject = "Your Order Confirmation from " . $site_name;
            $user_body = "<h1>Thank You for Your Order!</h1>"
                       . "<p>Hi " . htmlspecialchars($_SESSION['username']) . ",</p>"
                       . "<p>We've received your order (#" . $order_id . ") and are getting it ready.</p>"
                       . "<p><strong>Transaction ID:</strong> " . htmlspecialchars($transaction_id) . "</p>"
                       . "<p><strong>Total:</strong> " . $currency_symbol . number_format($total_price, 2) . "</p>"
                       . "<p><strong>Payment Method:</strong> " . ucfirst($payment_method) . "</p>"
                       . "<p>You can view your order details here: <a href='http://".$_SERVER['HTTP_HOST']."/my_orders.php'>My Orders</a></p>";
            send_email($user_email, $user_subject, $user_body);
        }

        // Send notification email to admin
        $admin_subject = "New Order Notification: #" . $order_id;
        $admin_body = "<h1>New Order Received</h1>"
                    . "<p>A new order has been placed on your website.</p>"
                    . "<p><strong>Order ID:</strong> #" . $order_id . "</p>"
                    . "<p><strong>Transaction ID:</strong> " . htmlspecialchars($transaction_id) . "</p>"
                    . "<p><strong>Customer:</strong> " . htmlspecialchars($_SESSION['username']) . "</p>"
                    . "<p><strong>Total:</strong> " . $currency_symbol . number_format($total_price, 2) . "</p>"
                    . "<p>You can view the full details in the admin panel.</p>";
        send_admin_notification($admin_subject, $admin_body);

        // Send push notification to all staff
        $sql_staff_players = "SELECT onesignal_player_id FROM users WHERE role_id IS NOT NULL AND onesignal_player_id IS NOT NULL";
        $result_players = $mysqli->query($sql_staff_players);
        $player_ids = [];
        while($row = $result_players->fetch_assoc()){
            $player_ids[] = $row['onesignal_player_id'];
        }
        if(!empty($player_ids)){
            $currency_symbol = htmlspecialchars($_SESSION['currency_symbol']);
            $push_message = "New order (#" . $order_id . ") placed for " . $currency_symbol . number_format($total_price, 2);
            send_push_notification($player_ids, $push_message, "New Order Received!");
        }


        if($payment_method === 'paystack'){
            header("location: paystack_charge.php");
        } elseif ($payment_method === 'stripe') {
            header("location: stripe_charge.php");
        } elseif ($payment_method === 'flutterwave') {
            header("location: flutterwave_charge.php");
        } elseif ($payment_method === 'bank_transfer') {
             header("location: order_details_bank.php?id=" . $order_id);
        } else {
            header("location: order_success.php?id=" . $order_id);
        }
        exit();

    } catch (mysqli_sql_exception $exception) {
        $mysqli->rollback();
        die('Order failed. Please try again. Error: ' . $exception->getMessage());
    }
}

include 'includes/header.php';
?>

<h2>Checkout</h2>
<div class="row g-5">
    <!-- Order Summary -->
    <div class="col-md-5 col-lg-4 order-md-last">
        <h4 class="d-flex justify-content-between align-items-center mb-3">
            <span class="text-primary">Your cart</span>
            <span class="badge bg-primary rounded-pill"><?php echo count($_SESSION['cart'] ?? []); ?></span>
        </h4>
        <ul class="list-group mb-3">
            <?php foreach($cart_items as $item): ?>
                 <li class="list-group-item d-flex justify-content-between lh-sm">
                    <div>
                        <h6 class="my-0"><?php echo htmlspecialchars($item['name']); ?> (x<?php echo $item['quantity']; ?>)</h6>
                    </div>
                    <span class="text-muted"><?php echo htmlspecialchars($_SESSION['currency_symbol']); ?><?php echo number_format($item['subtotal'], 2); ?></span>
                </li>
            <?php endforeach; ?>
            <li class="list-group-item d-flex justify-content-between">
                <span>Total (<?php echo htmlspecialchars($_SESSION['currency_code']); ?>)</span>
                <strong><?php echo htmlspecialchars($_SESSION['currency_symbol']); ?><?php echo number_format($total_price, 2); ?></strong>
            </li>
        </ul>
    </div>

    <!-- Shipping and Payment Form -->
    <div class="col-md-7 col-lg-8">
        <h4 class="mb-3">Shipping & Payment</h4>
        <form action="checkout.php" method="post" id="checkout-form">
            <h5 class="mb-3">Shipping address</h5>
            <div class="row g-3">
                 <div class="col-12"><label for="email" class="form-label">Email</label><input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($_SESSION['email'] ?? ''); ?>" required></div>
            </div>
            <hr class="my-4">

            <h5 class="mb-3">Order Notes (Optional)</h5>
            <div class="mb-3">
                <textarea class="form-control" name="order_notes" rows="3" placeholder="Add any special instructions for your order here..."></textarea>
            </div>

            <h5 class="mb-3">Payment Method</h5>
            <div class="my-3">
                <?php $is_first = true; ?>
                <?php if($bank_transfer_enabled): ?>
                <div class="form-check">
                    <input id="bank_transfer" name="payment_method" type="radio" class="form-check-input" value="bank_transfer" required <?php if($is_first){ echo 'checked'; $is_first = false; } ?>>
                    <label class="form-check-label" for="bank_transfer">Bank Transfer</label>
                </div>
                <?php endif; ?>
                <?php if($paystack_enabled): ?>
                <div class="form-check">
                    <input id="paystack" name="payment_method" type="radio" class="form-check-input" value="paystack" required <?php if($is_first){ echo 'checked'; $is_first = false; } ?>>
                    <label class="form-check-label" for="paystack">Paystack (Card, Bank, USSD)</label>
                </div>
                <?php endif; ?>
                <?php if($stripe_enabled): ?>
                <div class="form-check">
                    <input id="stripe" name="payment_method" type="radio" class="form-check-input" value="stripe" required <?php if($is_first){ echo 'checked'; $is_first = false; } ?>>
                    <label class="form-check-label" for="stripe">Stripe (Credit/Debit Card)</label>
                </div>
                <?php endif; ?>
                 <?php if($flutterwave_enabled): ?>
                <div class="form-check">
                    <input id="flutterwave" name="payment_method" type="radio" class="form-check-input" value="flutterwave" required <?php if($is_first){ echo 'checked'; $is_first = false; } ?>>
                    <label class="form-check-label" for="flutterwave">Flutterwave (Card, Bank, etc)</label>
                </div>
                <?php endif; ?>
            </div>

            <hr class="my-4">

            <button class="w-100 btn btn-primary btn-lg" type="submit" name="place_order">Place Order</button>
        </form>
    </div>
</div>

<?php
include 'includes/footer.php';
?>

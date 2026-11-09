<?php
// Include the new admin header
include 'includes/admin_header.php';
require_permission('manage_orders');
require_once '../includes/send_email.php';

// Check if Order ID is provided
if(!isset($_GET['id']) || empty($_GET['id'])){
    echo "<script>window.location.href='manage_orders.php';</script>";
    exit;
}
$order_id = $_GET['id'];
$message = "";

// Handle status update from both dropdown and approve/reject buttons
if($_SERVER["REQUEST_METHOD"] == "POST"){
    // We need to fetch the order details BEFORE the update to get the old status and user email
    $sql_old_order = "SELECT o.status, u.email, u.username FROM orders o JOIN users u ON o.user_id = u.id WHERE o.id = ?";
    $stmt_old = $mysqli->prepare($sql_old_order);
    $stmt_old->bind_param("i", $order_id);
    $stmt_old->execute();
    $old_order_result = $stmt_old->get_result()->fetch_assoc();
    // Guard against a bogus/removed ?id=: without this the next line threw
    // "Trying to access array offset on null" and an email was sent to a null address.
    if (!$old_order_result) {
        echo "<script>window.location.href='manage_orders.php';</script>";
        exit;
    }
    $old_status = $old_order_result['status'];
    $user_email = $old_order_result['email'];
    $username = $old_order_result['username'];
    $stmt_old->close();

    $new_status = "";
    $send_notification = false;

    if(isset($_POST['update_status'])){
        $new_status = $_POST['status'];
    } elseif(isset($_POST['approve_payment'])){
        $new_status = 'Completed';
        $message = '<div class="alert alert-success">Payment approved and order marked as Completed.</div>';
    } elseif(isset($_POST['reject_payment'])){
        $new_status = 'Awaiting Payment';
        // Also clear the payment proof on rejection
        $stmt_clear_proof = $mysqli->prepare("UPDATE orders SET payment_proof = NULL WHERE id = ?");
        $stmt_clear_proof->bind_param("i", $order_id);
        $stmt_clear_proof->execute();
        $stmt_clear_proof->close();
        $message = '<div class="alert alert-warning">Payment rejected. Status set to Awaiting Payment and proof has been removed.</div>';
    }

    if(!empty($new_status) && $new_status !== $old_status){
        $mysqli->begin_transaction();
        try {
            // If the order is being cancelled, restore stock
            if ($new_status === 'Cancelled') {
                // Check if stock has already been restored for this order
                $check_stock_sql = "SELECT stock_restored FROM orders WHERE id = ?";
                $stmt_check_stock = $mysqli->prepare($check_stock_sql);
                $stmt_check_stock->bind_param("i", $order_id);
                $stmt_check_stock->execute();
                $stock_restored_result = $stmt_check_stock->get_result()->fetch_assoc();
                $stmt_check_stock->close();

                if (!$stock_restored_result['stock_restored']) {
                    $sql_items_for_stock = "SELECT product_id, variant_id, quantity FROM order_items WHERE order_id = ?";
                    $stmt_items_stock = $mysqli->prepare($sql_items_for_stock);
                    $stmt_items_stock->bind_param("i", $order_id);
                $stmt_items_stock->execute();
                $items_to_restock = $stmt_items_stock->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt_items_stock->close();

                foreach ($items_to_restock as $item) {
                    if (!empty($item['variant_id'])) {
                        $stmt_update_stock = $mysqli->prepare("UPDATE product_variants SET stock = stock + ? WHERE id = ?");
                        $stmt_update_stock->bind_param("ii", $item['quantity'], $item['variant_id']);
                        $stmt_update_stock->execute();
                        $stmt_update_stock->close();
                    } else {
                        $stmt_update_stock = $mysqli->prepare("UPDATE products SET stock = stock + ? WHERE id = ? AND has_variants = 0");
                        $stmt_update_stock->bind_param("ii", $item['quantity'], $item['product_id']);
                        $stmt_update_stock->execute();
                        $stmt_update_stock->close();
                    }
                }

                // Mark stock as restored
                $mysqli->query("UPDATE orders SET stock_restored = 1 WHERE id = $order_id");

                $message .= '<div class="alert alert-success">Stock has been restored for the cancelled order.</div>';
              }
            }

            // Stock is now deducted at checkout, so this is no longer needed.
            // The logic to restore stock on cancellation remains.

        $sql_update = "UPDATE orders SET status = ? WHERE id = ?";
        if($stmt_update = $mysqli->prepare($sql_update)){
            $stmt_update->bind_param("si", $new_status, $order_id);
            if($stmt_update->execute()){
                $subject = "Your Order Status Has Been Updated";
                $body = "";
                $invoice_url = 'http://'.$_SERVER['HTTP_HOST']."/invoice.php?id=" . $order_id;

                if($new_status === 'Completed'){
                    $subject = "Your Order is Complete & Payment Receipt";
                    $body = "<h1>Your Order is Complete!</h1>"
                          . "<p>Hi " . htmlspecialchars($username) . ",</p>"
                          . "<p>We've finished processing your order #" . $order_id . ". Thank you for your payment.</p>"
                          . "<p>You can view and print your full invoice and payment receipt here: <a href='" . $invoice_url . "'>" . $invoice_url . "</a></p>";
                } else {
                    $body = "<h1>Order Update</h1>"
                          . "<p>Hi " . htmlspecialchars($username) . ",</p>"
                          . "<p>The status of your order #" . $order_id . " has been updated to: <strong>" . htmlspecialchars($new_status) . "</strong></p>"
                          . "<p>You can view your order details here: <a href='http://".$_SERVER['HTTP_HOST']."/my_orders.php'>My Orders</a></p>";
                }

                send_email($user_email, $subject, $body);
                $message .= '<div class="alert alert-info">User has been notified of the status change.</div>';
            }
            $stmt_update->close();
        }
        $mysqli->commit();
    } catch (mysqli_sql_exception $exception) {
            $mysqli->rollback();
            $message = '<div class="alert alert-danger">Error updating order status: ' . $exception->getMessage() . '</div>';
        }
    } elseif(!empty($new_status) && $new_status === $old_status) {
        //If status is the same, no need to update or send email.
    }
}


// Fetch Order and Customer Details
$sql_order = "SELECT o.*, u.username, u.email FROM orders o JOIN users u ON o.user_id = u.id WHERE o.id = ?";
$order = null;
if($stmt_order = $mysqli->prepare($sql_order)){
    $stmt_order->bind_param("i", $order_id);
    $stmt_order->execute();
    $result_order = $stmt_order->get_result();
    if($result_order->num_rows == 1){
        $order = $result_order->fetch_assoc();
    }
    $stmt_order->close();
}

if(!$order){ echo "Order not found."; exit; }

// Fetch Order Items and their variations
$sql_items = "
    SELECT
        oi.id as order_item_id,
        oi.quantity,
        oi.price,
        p.name as product_name,
        p.image as product_image,
        pa.name as attribute_name,
        av.value as attribute_value
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    LEFT JOIN product_variants pv ON oi.variant_id = pv.id
    LEFT JOIN product_variant_options pvo ON pv.id = pvo.variant_id
    LEFT JOIN attribute_values av ON pvo.value_id = av.id
    LEFT JOIN product_attributes pa ON av.attribute_id = pa.id
    WHERE oi.order_id = ?
    ORDER BY oi.id";

$order_items = [];
if($stmt_items = $mysqli->prepare($sql_items)){
    $stmt_items->bind_param("i", $order_id);
    $stmt_items->execute();
    $result_items = $stmt_items->get_result();
    $items_raw = $result_items->fetch_all(MYSQLI_ASSOC);
    $stmt_items->close();

    // Process the raw items to group variations under each item
    foreach ($items_raw as $item) {
        $item_id = $item['order_item_id'];
        if (!isset($order_items[$item_id])) {
            $order_items[$item_id] = [
                'product_name' => $item['product_name'],
                'product_image' => $item['product_image'],
                'quantity' => $item['quantity'],
                'price' => $item['price'],
                'variations' => []
            ];
        }
        if ($item['attribute_name'] && $item['attribute_value']) {
            $order_items[$item_id]['variations'][] = [
                'name' => $item['attribute_name'],
                'value' => $item['attribute_value']
            ];
        }
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Order Details for #<?php echo $order['id']; ?></h2>
    <a href="manage_orders.php" class="btn btn-secondary">Back to Orders</a>
</div>

<?php echo $message; ?>

<div class="row">
    <div class="col-lg-8">
        <div class="card shadow mb-4">
            <div class="card-header">Order Items</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Quantity</th>
                                <th class="text-end">Price</th>
                                <th class="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($order_items as $item): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <img src="../uploads/<?php echo htmlspecialchars($item['product_image']); ?>" class="me-3" style="width: 60px; height: 60px; object-fit: cover;">
                                        <div>
                                            <?php echo htmlspecialchars($item['product_name']); ?>
                                            <?php if (!empty($item['variations'])): ?>
                                                <div class="small text-muted">
                                                    <?php foreach ($item['variations'] as $variation): ?>
                                                        <strong><?php echo htmlspecialchars($variation['name']); ?>:</strong> <?php echo htmlspecialchars($variation['value']); ?><br>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td><?php echo $item['quantity']; ?></td>
                                <td class="text-end"><?php echo htmlspecialchars($_SESSION['currency_symbol']); ?><?php echo number_format($item['price'], 2); ?></td>
                                <td class="text-end"><?php echo htmlspecialchars($_SESSION['currency_symbol']); ?><?php echo number_format($item['price'] * $item['quantity'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php if (!empty($order['order_notes'])): ?>
        <div class="card shadow mb-4">
            <div class="card-header">Order Notes</div>
            <div class="card-body">
                <p><?php echo nl2br(htmlspecialchars($order['order_notes'])); ?></p>
            </div>
        </div>
        <?php endif; ?>

        <?php if($order['payment_proof']): ?>
        <div class="card shadow mb-4">
            <div class="card-header bg-info text-white">Payment Proof Submitted</div>
            <div class="card-body text-center">
                <a href="../uploads/payment_proofs/<?php echo htmlspecialchars($order['payment_proof']); ?>" target="_blank">
                    <img src="../uploads/payment_proofs/<?php echo htmlspecialchars($order['payment_proof']); ?>" class="img-fluid" style="max-height: 400px;" alt="Payment Proof">
                </a>
                <?php if($order['status'] == 'Processing'): ?>
                <form action="order_detail.php?id=<?php echo $order_id; ?>" method="post" class="mt-3 d-flex justify-content-center">
                    <button type="submit" name="reject_payment" class="btn btn-danger me-2">Reject Payment</button>
                    <button type="submit" name="approve_payment" class="btn btn-success">Approve Payment</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>
    <div class="col-lg-4">
        <div class="card shadow mb-4">
            <div class="card-header">Order Summary</div>
            <div class="card-body">
                <p><strong>Order ID:</strong> #<?php echo $order['id']; ?></p>
                <?php if (!empty($order['transaction_id'])): ?>
                    <p><strong>Transaction ID:</strong> <?php echo htmlspecialchars($order['transaction_id']); ?></p>
                <?php endif; ?>
                <p><strong>Date:</strong> <?php echo $order['created_at']; ?></p>
                <p><strong>Total:</strong> <span class="fw-bold fs-5"><?php echo htmlspecialchars($_SESSION['currency_symbol']); ?><?php echo number_format($order['total_amount'], 2); ?></span></p>
                <p><strong>Payment Method:</strong> <?php echo htmlspecialchars($order['payment_method']); ?></p>
                <p><strong>Status:</strong> <span class="badge bg-primary"><?php echo htmlspecialchars($order['status']); ?></span></p>
            </div>
        </div>
        <div class="card shadow mb-4">
            <div class="card-header">Customer Details</div>
            <div class="card-body">
                <p><strong>Username:</strong> <?php echo htmlspecialchars($order['username']); ?></p>
                <p><strong>Email:</strong> <?php echo htmlspecialchars($order['email']); ?></p>
            </div>
        </div>
         <div class="card shadow">
            <div class="card-header">Update Status</div>
            <div class="card-body">
                <form action="order_detail.php?id=<?php echo $order_id; ?>" method="post">
                    <select name="status" class="form-select">
                        <option value="Awaiting Payment" <?php if($order['status'] == 'Awaiting Payment') echo 'selected'; ?>>Awaiting Payment</option>
                        <option value="Processing" <?php if($order['status'] == 'Processing') echo 'selected'; ?>>Processing</option>
                        <option value="Completed" <?php if($order['status'] == 'Completed') echo 'selected'; ?>>Completed</option>
                        <option value="Cancelled" <?php if($order['status'] == 'Cancelled') echo 'selected'; ?>>Cancelled</option>
                    </select>
                    <button type="submit" name="update_status" class="btn btn-primary mt-2 w-100">Update Status Manually</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
// Include the new admin footer
include 'includes/admin_footer.php';
?>

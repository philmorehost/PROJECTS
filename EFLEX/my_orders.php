<?php
// We need to start the session on all pages to access session variables
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is logged in, if not then redirect to login page
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: login.php");
    exit;
}

// Include the header and database connection
include 'includes/header.php';
require_once 'includes/db_connect.php';
require_once 'includes/send_email.php';

$user_id = $_SESSION['id'];
$message = "";

// Handle Cancel Order
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['cancel_order'])){
    $order_id_to_cancel = $_POST['order_id'];

    // --- Begin transaction
    $mysqli->begin_transaction();

    try {
        // Fetch status AND stock_restored together: only a cancellable order may be
        // restocked. Previously the restock loop ran for *any* order with
        // stock_restored = 0 (including Completed ones), so POSTing cancel_order for
        // a completed order silently inflated inventory.
        $check_stock_sql = "SELECT status, stock_restored FROM orders WHERE id = ? AND user_id = ?";
        $stmt_check_stock = $mysqli->prepare($check_stock_sql);
        $stmt_check_stock->bind_param("ii", $order_id_to_cancel, $user_id);
        $stmt_check_stock->execute();
        $stock_restored_result = $stmt_check_stock->get_result()->fetch_assoc();
        $stmt_check_stock->close();

        $can_cancel = $stock_restored_result
            && in_array($stock_restored_result['status'], ['Pending', 'Awaiting Payment'], true);

        if (!$can_cancel) {
            $mysqli->rollback();
            $message = '<div class="alert alert-warning">This order can no longer be cancelled.</div>';
        } else {
            $restocked = false;

            if (!$stock_restored_result['stock_restored']) {
                // Fetch the items from the order to be cancelled
                $sql_items = "SELECT product_id, variant_id, quantity FROM order_items WHERE order_id = ?";
                $stmt_items = $mysqli->prepare($sql_items);
                $stmt_items->bind_param("i", $order_id_to_cancel);
                $stmt_items->execute();
                $items_to_restock = $stmt_items->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt_items->close();

                // Loop through items and restore stock
                foreach ($items_to_restock as $item) {
                    if (!empty($item['variant_id'])) {
                        $sql_update_stock = "UPDATE product_variants SET stock = stock + ? WHERE id = ?";
                        $stmt_stock = $mysqli->prepare($sql_update_stock);
                        $stmt_stock->bind_param("ii", $item['quantity'], $item['variant_id']);
                        $stmt_stock->execute();
                        $stmt_stock->close();
                    } else {
                        $sql_update_stock = "UPDATE products SET stock = stock + ? WHERE id = ? AND has_variants = 0";
                        $stmt_stock = $mysqli->prepare($sql_update_stock);
                        $stmt_stock->bind_param("ii", $item['quantity'], $item['product_id']);
                        $stmt_stock->execute();
                        $stmt_stock->close();
                    }
                }
                $restocked = true;
            }

            // Mark stock as restored and update status
            $sql_cancel = "UPDATE orders SET status = 'Cancelled', stock_restored = 1 WHERE id = ? AND user_id = ? AND (status = 'Pending' OR status = 'Awaiting Payment')";
            $stmt_cancel = $mysqli->prepare($sql_cancel);
            $stmt_cancel->bind_param("ii", $order_id_to_cancel, $user_id);
            $stmt_cancel->execute();
            $stmt_cancel->close();

            // --- If everything is fine, commit the transaction
            $mysqli->commit();
            $message = '<div class="alert alert-info">Order #' . $order_id_to_cancel . ' has been cancelled'
                     . ($restocked ? ' and stock has been restored.' : '.') . '</div>';
        }

    } catch (Exception $e) {
        // --- An error occurred, roll back the transaction
        $mysqli->rollback();
        $message = '<div class="alert alert-danger">There was an error cancelling your order. Please try again.</div>';
    }
}

// Handle Payment Proof Upload
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['upload_proof'])){
    $order_id = $_POST['order_id'];

    if(isset($_FILES["payment_proof"]) && $_FILES["payment_proof"]["error"] == 0){
        $allowed = [
            "jpg" => "image/jpeg",
            "jpeg" => "image/jpeg",
            "png" => "image/png",
            "gif" => "image/gif",
            "webp" => "image/webp",
            "bmp" => "image/bmp",
            "pdf" => "application/pdf"
        ];
        $filename = $_FILES["payment_proof"]["name"];
        $filetype = $_FILES["payment_proof"]["type"];
        $filesize = $_FILES["payment_proof"]["size"];

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if(!array_key_exists($ext, $allowed) || !in_array($filetype, $allowed)) {
            $message = '<div class="alert alert-danger">Error: Invalid file type. Please upload a valid image (JPG, JPEG, PNG, GIF, WEBP, BMP) or a PDF.</div>';
        }

        $maxsize = 5 * 1024 * 1024;
        if($filesize > $maxsize) $message = '<div class="alert alert-danger">Error: File size is larger than 5MB.</div>';

        if(in_array($filetype, $allowed) && empty($message)){
            $new_filename = "proof_" . $order_id . "_" . uniqid() . "." . $ext;
            if(move_uploaded_file($_FILES["payment_proof"]["tmp_name"], "uploads/payment_proofs/" . $new_filename)){
                // File uploaded successfully, now update the order
                $sql_update = "UPDATE orders SET payment_proof = ?, status = 'Processing' WHERE id = ? AND user_id = ?";
                if($stmt_update = $mysqli->prepare($sql_update)){
                    $stmt_update->bind_param("sii", $new_filename, $order_id, $user_id);
                    if($stmt_update->execute()){
                        $message = '<div class="alert alert-success">Payment proof uploaded successfully. Your order is now being processed.</div>';

                        // Send notification to admin
                        $settings_sql = "SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('from_email', 'site_name')";
                        $result_settings = $mysqli->query($settings_sql);
                        $settings = [];
                        while($row = $result_settings->fetch_assoc()){
                            $settings[$row['setting_key']] = $row['setting_value'];
                        }
                        $admin_email = $settings['from_email'] ?? '';
                        $site_name = $settings['site_name'] ?? 'Eflex';

                        if(!empty($admin_email)){
                            $admin_subject = "Payment Proof Uploaded for Order #" . $order_id;
                            $admin_body = "<h1>Payment Proof Submitted</h1>"
                                        . "<p>A customer has uploaded a payment proof for order #" . $order_id . ".</p>"
                                        . "<p><strong>Customer:</strong> " . $_SESSION['username'] . "</p>"
                                        . "<p>Please log in to the admin panel to review and approve the payment.</p>";
                            send_email($admin_email, $admin_subject, $admin_body);
                        }
                    }
                    $stmt_update->close();
                }
            } else {
                $message = '<div class="alert alert-danger">Error: There was a problem uploading your file.</div>';
            }
        }
    } else {
        $message = '<div class="alert alert-danger">Error: No file was uploaded or there was an upload error.</div>';
    }
}


// Pagination and fetching orders logic...
// ... (same as before) ...
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$records_per_page = 5;
$offset = ($page - 1) * $records_per_page;
$sql_total = "SELECT COUNT(*) FROM orders WHERE user_id = ?";
if($stmt_total = $mysqli->prepare($sql_total)){
    $stmt_total->bind_param("i", $user_id);
    $stmt_total->execute();
    $total_records = $stmt_total->get_result()->fetch_row()[0];
    $stmt_total->close();
}
$total_pages = ceil($total_records / $records_per_page);
$sql = "SELECT id, created_at, total_amount, status, transaction_id FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?";
$orders = [];
if($stmt = $mysqli->prepare($sql)){
    $stmt->bind_param("iii", $user_id, $records_per_page, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    $orders = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
?>

<div class="container mt-5">
    <h2>My Orders</h2>
    <?php echo $message; ?>
    <div class="table-responsive">
        <table class="table table-striped">
            <thead>
                <tr><th>Order ID</th><th>Transaction ID</th><th>Date</th><th>Total</th><th>Status</th><th>Action</th></tr>
            </thead>
            <tbody>
                <?php if(count($orders) > 0): ?>
                    <?php foreach($orders as $order): ?>
                    <tr>
                        <td>#<?php echo htmlspecialchars($order['id']); ?></td>
                        <td><?php echo htmlspecialchars($order['transaction_id'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($order['created_at']); ?></td>
                        <td><?php echo htmlspecialchars($_SESSION['currency_symbol']); ?><?php echo number_format($order['total_amount'], 2); ?></td>
                        <td><span class="badge bg-primary"><?php echo htmlspecialchars($order['status']); ?></span></td>
                        <td>
                            <a href="view_order.php?id=<?php echo $order['id']; ?>" class="btn btn-sm btn-info">View Details</a>
                            <a href="invoice.php?id=<?php echo $order['id']; ?>" class="btn btn-sm btn-secondary" target="_blank">View Invoice</a>
                            <?php if($order['status'] == 'Awaiting Payment'): ?>
                                <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#uploadModal<?php echo $order['id']; ?>">Upload Proof</button>
                            <?php endif; ?>
                            <?php if($order['status'] == 'Pending' || $order['status'] == 'Awaiting Payment'): ?>
                                <form action="my_orders.php" method="post" class="d-inline">
                                    <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                    <button type="submit" name="cancel_order" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to cancel this order?')">Cancel</button>
                                </form>
                            <?php endif; ?>

                            <!-- Upload Proof Modal -->
                            <?php if($order['status'] == 'Awaiting Payment'): ?>
                            <div class="modal fade" id="uploadModal<?php echo $order['id']; ?>" tabindex="-1">
                              <div class="modal-dialog">
                                <div class="modal-content">
                                  <div class="modal-header">
                                    <h5 class="modal-title">Upload Payment Proof for Order #<?php echo $order['id']; ?></h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                  </div>
                                  <div class="modal-body">
                                    <form action="my_orders.php" method="post" enctype="multipart/form-data">
                                        <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                        <div class="mb-3">
                                            <label for="payment_proof" class="form-label">Select file (JPG, PNG, GIF, WEBP, BMP, PDF)</label>
                                            <input class="form-control" type="file" name="payment_proof" required>
                                        </div>
                                        <button type="submit" name="upload_proof" class="btn btn-primary">Upload</button>
                                    </form>
                                  </div>
                                </div>
                              </div>
                            </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="6">You have not placed any orders yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <!-- Pagination -->
    <?php render_pagination('my_orders.php', $total_pages, $page); ?>
</div>

<?php
// Include the footer
include 'includes/footer.php';
?>

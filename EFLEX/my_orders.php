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
require_once 'includes/order_stock.php';

$user_id = $_SESSION['id'];
$message = "";

// Handle Cancel Order
//
// Only ONE cancellation may return stock. The old code read `stock_restored`,
// added the quantities back and then flipped the flag, so a double click (or the
// admin cancelling at the same moment) added the stock twice — that is the
// "each cancel returns double to stock" bug. eflex_cancel_order() locks the
// order row (SELECT ... FOR UPDATE), checks the flag under the lock and makes
// the stock_restored 0->1 flip part of the same transaction, so a second
// attempt is a no-op.
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['cancel_order'])){
    $order_id_to_cancel = (int)($_POST['order_id'] ?? 0);

    // Make sure the order belongs to the signed-in customer (and get its status).
    $owns_order = false;
    $current_status = '';
    if ($order_id_to_cancel > 0) {
        $stmt_own = $mysqli->prepare("SELECT status FROM orders WHERE id = ? AND user_id = ?");
        $stmt_own->bind_param("ii", $order_id_to_cancel, $user_id);
        $stmt_own->execute();
        $own_row = $stmt_own->get_result()->fetch_assoc();
        $stmt_own->close();
        if ($own_row) {
            $owns_order = true;
            $current_status = $own_row['status'];
        }
    }

    if (!$owns_order) {
        $message = '<div class="alert alert-warning">Order not found.</div>';
    } else {
        // Customers may cancel anything that is still unpaid; Processing means a
        // payment proof is waiting for review, so it is not cancellable here.
        $cancellable = eflex_unpaid_order_statuses();

        if (!in_array($current_status, $cancellable, true)) {
            $message = '<div class="alert alert-warning">This order can no longer be cancelled.</div>';
        } else {
            $cancel = eflex_cancel_order($mysqli, $order_id_to_cancel, 'Cancelled by the customer');

            if (!empty($cancel['ok'])) {
                $restocked = !empty($cancel['restocked']);
                $summary = $restocked ? eflex_restock_summary($cancel['restored']) : '';
                $message = '<div class="alert alert-info">Order #' . $order_id_to_cancel . ' has been cancelled'
                         . ($restocked ? ' and its stock has been restored' : '')
                         . ($summary !== '' ? ' (' . htmlspecialchars($summary) . ')' : '')
                         . '.</div>';
            } else {
                $reason = $cancel['message'] !== '' ? $cancel['message'] : 'This order can no longer be cancelled.';
                $message = '<div class="alert alert-warning">' . htmlspecialchars($reason) . '</div>';
            }
        }
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


// Automatic cancellation of unpaid orders (runs at most once every 15 minutes,
// independent of any cron job, so the setting also works on shared hosting).
// The customer's own cancellation above always wins: the order row is locked
// and stock is only returned once.
require_once 'includes/order_cancellation.php';
eflex_run_auto_cancel_if_due($mysqli, isset($settings) && is_array($settings) ? $settings : null);

// Pagination and fetching orders logic...
// ... (same as before) ...
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$records_per_page = 5;
$offset = ($page - 1) * $records_per_page;
$sql_total = "SELECT COUNT(*) FROM orders WHERE user_id = ?";
$total_records = 0;
if($stmt_total = $mysqli->prepare($sql_total)){
    $stmt_total->bind_param("i", $user_id);
    $stmt_total->execute();
    $count_row = $stmt_total->get_result()->fetch_row();
    $total_records = $count_row ? (int)$count_row[0] : 0;
    $stmt_total->close();
}
$total_pages = $total_records > 0 ? (int)ceil($total_records / $records_per_page) : 1;
$sql = "SELECT id, created_at, total_amount, status, transaction_id FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?";
$orders = [];
if($stmt = $mysqli->prepare($sql)){
    $stmt->bind_param("iii", $user_id, $records_per_page, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        $orders = $result->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
}
?>

<div class="container mt-5">
    <h2>My Orders</h2>
    <?php echo $message; ?>
    <?php
    // Explain the automatic cancellation rule to the customer up front, so an
    // order that disappears is not a surprise.
    $customer_cancel_config = eflex_cancel_settings($mysqli, isset($settings) && is_array($settings) ? $settings : null);
    if (!empty($customer_cancel_config['enabled'])):
    ?>
    <div class="alert alert-secondary small">
        <i class="fas fa-clock"></i>
        Unpaid orders (Pending / Awaiting Payment) are cancelled automatically after
        <strong><?php echo htmlspecialchars($customer_cancel_config['label']); ?></strong> and the items are returned to stock.
        Pay or upload your payment proof before then to keep your order.
    </div>
    <?php endif; ?>
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

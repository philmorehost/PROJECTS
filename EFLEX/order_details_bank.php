<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: login.php");
    exit;
}

require_once 'includes/db_connect.php';
require_once 'includes/send_email.php';
require_once 'includes/send_notification.php';

$message = "";
$error = "";

if(!isset($_GET['id']) && !isset($_POST['order_id'])){
    header("location: my_orders.php");
    exit;
}
$order_id = $_GET['id'] ?? $_POST['order_id'];
$user_id = $_SESSION['id'];

// Handle Payment Proof Upload
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['upload_proof'])){
    if(isset($_FILES["payment_proof"]) && $_FILES["payment_proof"]["error"] == 0){
        $allowed = ["jpg" => "image/jpeg", "jpeg" => "image/jpeg", "png" => "image/png", "gif" => "image/gif", "webp" => "image/webp", "bmp" => "image/bmp", "pdf" => "application/pdf"];
        $filename = $_FILES["payment_proof"]["name"];
        $filetype = $_FILES["payment_proof"]["type"];
        $filesize = $_FILES["payment_proof"]["size"];

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if(!array_key_exists($ext, $allowed) || !in_array($filetype, $allowed)) {
            $error = "Invalid file type. Please upload a valid image (JPG, PNG, GIF, WEBP, BMP) or a PDF.";
        }

        $maxsize = 5 * 1024 * 1024;
        if($filesize > $maxsize) $error = "File size is larger than the 5MB limit.";

        if(empty($error)){
            $new_filename = "proof_" . $order_id . "_" . uniqid() . "." . $ext;
            if(move_uploaded_file($_FILES["payment_proof"]["tmp_name"], "uploads/payment_proofs/" . $new_filename)){
                $sql_update = "UPDATE orders SET payment_proof = ?, status = 'Processing' WHERE id = ? AND user_id = ?";
                if($stmt_update = $mysqli->prepare($sql_update)){
                    $stmt_update->bind_param("sii", $new_filename, $order_id, $user_id);
                    if($stmt_update->execute()){
                        // Send notification to admin
                        $settings_sql = "SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('admin_notification_email', 'site_name')";
                        $result_settings = $mysqli->query($settings_sql);
                        $settings = array_column($result_settings->fetch_all(MYSQLI_ASSOC), 'setting_value', 'setting_key');
                        $admin_email = $settings['admin_notification_email'] ?? '';

                        if(!empty($admin_email)){
                            $admin_subject = "Payment Proof Uploaded for Order #" . $order_id;
                            $admin_body = "A customer has uploaded a payment proof for order #" . $order_id . ". Please log in to the admin panel to review and approve the payment.";
                            send_admin_notification($admin_subject, $admin_body);
                        }

                        // Redirect to the order view page
                        header("Location: view_order.php?id=" . $order_id . "&upload_success=1");
                        exit;
                    }
                    $stmt_update->close();
                }
            } else {
                $error = "There was a problem uploading your file.";
            }
        }
    } else {
        $error = "No file was uploaded or there was an upload error. Please try again.";
    }
}

// Fetch order details and verify ownership
$sql_order = "SELECT * FROM orders WHERE id = ? AND user_id = ?";
if($stmt_order = $mysqli->prepare($sql_order)){
    $stmt_order->bind_param("ii", $order_id, $user_id);
    $stmt_order->execute();
    $result_order = $stmt_order->get_result();
    if($result_order->num_rows == 1){
        $order = $result_order->fetch_assoc();
    } else {
        header("location: my_orders.php"); // Redirect if order doesn't exist or belong to user
        exit;
    }
    $stmt_order->close();
}

// Fetch bank details from settings
$settings_sql = "SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('bank_name', 'bank_account_name', 'bank_account_number', 'bank_payment_instructions')";
$result = $mysqli->query($settings_sql);
$bank_settings = array_column($result->fetch_all(MYSQLI_ASSOC), 'setting_value', 'setting_key');

include 'includes/header.php';
?>

<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="alert alert-info text-center">
                <h4 class="alert-heading">Step 1: Make Your Payment</h4>
                <p>Your order <strong>#<?php echo htmlspecialchars($order_id); ?></strong> has been placed. Please transfer the total amount of <strong><?php echo htmlspecialchars($_SESSION['currency_symbol'] . number_format($order['total_amount'], 2)); ?></strong> to the account below.</p>
            </div>

            <div class="card mb-4">
                <div class="card-header">
                    <h4>Bank Account Details</h4>
                </div>
                <div class="card-body">
                    <p><strong>Bank Name:</strong> <?php echo htmlspecialchars($bank_settings['bank_name'] ?? 'N/A'); ?></p>
                    <p><strong>Account Name:</strong> <?php echo htmlspecialchars($bank_settings['bank_account_name'] ?? 'N/A'); ?></p>
                    <p><strong>Account Number:</strong> <?php echo htmlspecialchars($bank_settings['bank_account_number'] ?? 'N/A'); ?></p>
                    <hr>
                    <h5><strong>Instructions:</strong></h5>
                    <p class="mb-0"><?php echo nl2br(htmlspecialchars($bank_settings['bank_payment_instructions'] ?? 'Please use your Order ID as the payment reference.')); ?></p>
                </div>
            </div>

            <div class="alert alert-warning text-center">
                 <h4 class="alert-heading">Step 2: Confirm Your Payment</h4>
                 <p>After making the payment, please upload a screenshot or proof of the transaction below and click "I Have Paid".</p>
            </div>

            <div class="card">
                <div class="card-header">
                    <h4>Upload Payment Proof</h4>
                </div>
                <div class="card-body">
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?php echo $error; ?></div>
                    <?php endif; ?>
                    <form action="order_details_bank.php" method="post" enctype="multipart/form-data">
                        <input type="hidden" name="order_id" value="<?php echo $order_id; ?>">
                        <div class="mb-3">
                            <label for="payment_proof" class="form-label">Select your payment proof file:</label>
                            <input class="form-control" type="file" name="payment_proof" id="payment_proof" required>
                            <div class="form-text">Accepted formats: JPG, PNG, GIF, WEBP, BMP, PDF. Max size: 5MB.</div>
                        </div>
                        <div class="d-grid">
                           <button type="submit" name="upload_proof" class="btn btn-primary btn-lg">I Have Paid - Complete Order</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
include 'includes/footer.php';
?>

<?php session_start();
include("../func/bc-admin-config.php");

// If not logged in or doesn't exist, redirect to login
if (!isset($get_logged_admin_details)) {
    header("Location: Login.php");
    exit();
}

$vendor_id = $get_logged_admin_details['id'];

// Handle Plan Purchase
if (isset($_POST['buy'])) {
    bc_validate_csrf();
    $plan_id = (int)$_POST['buy'];
    $plan_q = mysqli_query($connection_server, "SELECT * FROM sas_billing_packages WHERE id='$plan_id' LIMIT 1");

    if ($plan_q && ($plan_row = mysqli_fetch_assoc($plan_q)) && (float)$plan_row['price'] > 0 && (int)$plan_row['duration_days'] > 0) {
        $amount = $plan_row['price'];
        $days = $plan_row['duration_days'];

        if (vendorBalance(1) >= $amount) {
            $reference = "SUB-" . substr(str_shuffle("1234567890ABCDEF"), 0, 10);
            $desc = "Subscription Renewal: " . $plan_row['name'];

            $debit = chargeVendor("debit", "Subscription", "Subscription", $reference, $amount, $amount, $desc, $_SERVER['HTTP_HOST'], 1);

            if ($debit === "success") {
                // Update vendor expiry date
                $current_expiry = $get_logged_admin_details['expiry_date'];
                $base_date = ($current_expiry && strtotime($current_expiry) > time()) ? $current_expiry : date('Y-m-d');
                $new_expiry = date('Y-m-d', strtotime($base_date . " + $days days"));

                mysqli_query($connection_server, "UPDATE sas_vendors SET expiry_date='$new_expiry' WHERE id='$vendor_id'");

                // Log subscription
                mysqli_query($connection_server, "INSERT INTO sas_vendor_subscriptions (vendor_id, package_id, purchase_date, expiry_date, amount_paid) VALUES ('$vendor_id', '$plan_id', NOW(), '$new_expiry', '$amount')");

                $_SESSION["product_purchase_response"] = "Subscription renewed successfully until " . date('F j, Y', strtotime($new_expiry));
                header("Location: Dashboard.php");
                exit();
            } else {
                $_SESSION["product_purchase_response"] = "Insufficient balance to renew subscription.";
            }
        } else {
            $_SESSION["product_purchase_response"] = "Insufficient balance. Please fund your API wallet.";
        }
    }
    header("Location: RenewSubscription.php");
    exit();
}
?>
<?php
$renewal_only = !empty($GLOBALS['bc_vendor_is_expired']);
if ($renewal_only) {
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Renew your website</title><link rel="stylesheet" href="/assets-2/vendor/bootstrap/css/bootstrap.min.css"></head><body style="background:#edf3f8"><main class="container py-5" style="max-width:1100px"><div class="alert alert-warning"><h1 class="h4">Your website subscription has expired</h1><p class="mb-0">Only renewal is available. Choose a plan below to restore your website automatically. If your wallet balance is insufficient, contact the platform administrator to arrange funding.</p></div><p class="text-end"><a href="/admin-logout.php">Sign out</a></p>';
    if (!empty($_SESSION['product_purchase_response'])) {
        echo '<div class="alert alert-info">' . htmlspecialchars(strip_tags($_SESSION['product_purchase_response']), ENT_QUOTES, 'UTF-8') . '</div>';
        unset($_SESSION['product_purchase_response']);
    }
} else {
    include_once(__DIR__ . "/../func/bc-plate-admin.php");
    bc_adm_head("Renew Subscription | Admin Portal", array("area" => "admin"));
    bc_adm_sidebar(array("area" => "admin"));
    bc_adm_topbar(array("area" => "admin", "title" => "SUBSCRIPTION", "crumb" => "Home / Renew Subscription"));
}
?>
    <section class="section dashboard">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4">
                    <div class="card-header bg-primary text-white py-4 text-center">
                        <h2 class="fw-bold mb-0">Choose a Renewal Plan</h2>
                        <p class="mb-0 opacity-75">Select the best package to keep your platform running</p>
                    </div>
                    <div class="card-body p-4 p-md-5">
                        <div class="row g-4">
                            <?php
                            $plans_q = mysqli_query($connection_server, "SELECT * FROM sas_billing_packages ORDER BY price ASC");
                            if (mysqli_num_rows($plans_q) > 0) {
                                while ($plan = mysqli_fetch_assoc($plans_q)) {
                                    ?>
                                    <div class="col-md-4">
                                        <div class="card h-100 border shadow-sm rounded-4 text-center p-4 transition-hover">
                                            <div class="mb-3">
                                                <span class="badge bg-primary bg-opacity-10 text-dark-primary px-3 py-2 rounded-pill fw-bold text-uppercase"><?php echo htmlspecialchars($plan['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            </div>
                                            <h1 class="fw-bold mb-0">₦<?php echo number_format($plan['price'], 0); ?></h1>
                                            <p class="text-muted small mb-4">for <?php echo $plan['duration_days']; ?> days</p>

                                            <ul class="list-unstyled text-start mb-4 small">
                                                <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i> All Services Enabled</li>
                                                <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i> Technical Support</li>
                                                <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i> API Access</li>
                                                <li><i class="bi bi-check-circle-fill text-success me-2"></i> Unlimited Users</li>
                                            </ul>

                                            <form method="post">
                                                <?php echo bc_csrf_field(); ?>
                                                <button name="buy" value="<?php echo (int)$plan['id']; ?>" class="btn btn-primary w-100 rounded-pill py-2 fw-bold">RENEW NOW</button>
                                            </form>
                                        </div>
                                    </div>
                                    <?php
                                }
                            } else {
                                echo '<div class="col-12 text-center py-5"><p class="text-muted">No billing packages available. Please contact system administrator.</p></div>';
                            }
                            ?>
                        </div>
                    </div>
                </div>

                <div class="text-center">
                    <p class="text-muted small">Current Balance: <b class="text-dark">₦<?php echo number_format($get_logged_admin_details['balance'], 2); ?></b></p>
                    <?php if (!$renewal_only) { ?><a href="Fund.php" class="btn btn-outline-primary btn-sm rounded-pill px-4">Fund My Wallet</a><?php } ?>
                </div>
            </div>
        </div>
    </section>

    
    <style>
        .transition-hover { transition: all 0.3s ease; }
        .transition-hover:hover { transform: translateY(-10px); box-shadow: 0 15px 30px rgba(0,0,0,0.1) !important; }
    </style>



<?php if ($renewal_only) { echo "</main></body></html>"; } else { bc_adm_foot(array("area" => "admin")); } ?>

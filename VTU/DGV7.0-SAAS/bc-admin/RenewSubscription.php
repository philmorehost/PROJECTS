<?php session_start();
include("../func/bc-admin-config.php");

// If not logged in or doesn't exist, redirect to login
if (!isset($get_logged_admin_details)) {
    header("Location: Login.php");
    exit();
}

$vendor_id = $get_logged_admin_details['id'];

// Handle Plan Purchase
if (isset($_GET['buy'])) {
    $plan_id = mysqli_real_escape_string($connection_server, $_GET['buy']);
    $plan_q = mysqli_query($connection_server, "SELECT * FROM sas_billing_packages WHERE id='$plan_id' LIMIT 1");

    if ($plan_row = mysqli_fetch_assoc($plan_q)) {
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
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Renew Subscription | Admin Portal</title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
    <link rel="stylesheet" href="/cssfile/bc-style.css">
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
</head>
<body class="bg-light">
    <?php include("../func/bc-admin-header.php"); ?>

    <div class="pagetitle">
      <h1>SUBSCRIPTION</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li>
          <li class="breadcrumb-item active">Renew Subscription</li>
        </ol>
      </nav>
    </div>

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
                                                <span class="badge bg-primary bg-opacity-10 text-dark-primary px-3 py-2 rounded-pill fw-bold text-uppercase"><?php echo $plan['name']; ?></span>
                                            </div>
                                            <h1 class="fw-bold mb-0">₦<?php echo number_format($plan['price'], 0); ?></h1>
                                            <p class="text-muted small mb-4">for <?php echo $plan['duration_days']; ?> days</p>

                                            <ul class="list-unstyled text-start mb-4 small">
                                                <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i> All Services Enabled</li>
                                                <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i> Technical Support</li>
                                                <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i> API Access</li>
                                                <li><i class="bi bi-check-circle-fill text-success me-2"></i> Unlimited Users</li>
                                            </ul>

                                            <a href="?buy=<?php echo $plan['id']; ?>" class="btn btn-primary w-100 rounded-pill py-2 fw-bold" onclick="return confirm('Confirm purchase of <?php echo $plan['name']; ?> plan?')">
                                                RENEW NOW
                                            </a>
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
                    <a href="Fund.php" class="btn btn-outline-primary btn-sm rounded-pill px-4">Fund My Wallet</a>
                </div>
            </div>
        </div>
    </section>

    <?php include("../func/bc-admin-footer.php"); ?>
    <style>
        .transition-hover { transition: all 0.3s ease; }
        .transition-hover:hover { transform: translateY(-10px); box-shadow: 0 15px 30px rgba(0,0,0,0.1) !important; }
    </style>
</body>
</html>

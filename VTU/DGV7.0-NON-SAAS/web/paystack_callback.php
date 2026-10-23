<?php session_start();
    // Use basic configs
    include("../func/bc-connect.php");
	include("../func/bc-tables.php");

    $page_title = "Payment Verification";
    $error_message = "Transaction could not be verified. Please contact support.";

    if(isset($_GET['reference'])) {
        $reference = mysqli_real_escape_string($connection_server, $_GET['reference']);

        // Fetch Paystack secret key
        $gateway_res = mysqli_query($connection_server, "SELECT secret_key FROM sas_super_admin_payment_gateways WHERE gateway_name='paystack'");
        $gateway = mysqli_fetch_assoc($gateway_res);
        $secret_key = $gateway['secret_key'];

        if($secret_key) {
            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => "https://api.paystack.co/transaction/verify/" . rawurlencode($reference),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    "Authorization: Bearer " . $secret_key,
                    "Cache-Control: no-cache",
                ],
            ));

            $response = curl_exec($curl);
            $err = curl_error($curl);
            curl_close($curl);

            if ($err) {
                $error_message = "Payment gateway error. Please contact support.";
            } else {
                $result = json_decode($response, true);
                if ($result['status'] == true && $result['data']['status'] == 'success') {
                    $metadata = $result['data']['metadata'];
                    $payment_ref = $result['data']['reference'];
                    $type = $metadata['type'] ?? '';

                    if ($type == 'vendor_subscription') {
                        // Handle new registration
                        $pending_vendor_id = $metadata['pending_vendor_id'];
                        if($pending_vendor_id) {
                            $update_sql = "UPDATE sas_pending_vendors SET payment_status='paid', paystack_reference='$payment_ref' WHERE id='$pending_vendor_id'";
                            if(mysqli_query($connection_server, $update_sql)) {
                                $page_title = "Payment Successful";
                                $success_message = "Your payment was successful! Your registration is now pending final approval from our administrators. You will be notified by email once your account is activated.";
                            } else {
                                 $error_message = "Could not update your registration status. Please contact support with reference: $payment_ref";
                            }
                        } else {
                            $error_message = "Transaction metadata is missing. Please contact support.";
                        }
                    } elseif ($type == 'vendor_renewal') {
                        // Handle subscription renewal
                        $vendor_id = $metadata['vendor_id'];
                        $billing_package_id = $metadata['billing_package_id'];

                        if($vendor_id && $billing_package_id) {
                            // Get package duration
                            $package_res = mysqli_query($connection_server, "SELECT duration_days FROM sas_billing_packages WHERE id='$billing_package_id'");
                            $package = mysqli_fetch_assoc($package_res);
                            $duration_days = $package['duration_days'];

                            // Get current expiry date
                            $vendor_res = mysqli_query($connection_server, "SELECT expiry_date FROM sas_vendors WHERE id='$vendor_id'");
                            $vendor = mysqli_fetch_assoc($vendor_res);
                            $current_expiry = $vendor['expiry_date'];

                            $today = date("Y-m-d");
                            $start_date = ($current_expiry && $current_expiry > $today) ? $current_expiry : $today;
                            $new_expiry_date = date('Y-m-d', strtotime($start_date . " +$duration_days days"));

                            // Update vendor record
                            $stmt_update = mysqli_prepare($connection_server, "UPDATE sas_vendors SET expiry_date=?, status=1 WHERE id=?");
                            mysqli_stmt_bind_param($stmt_update, "si", $new_expiry_date, $vendor_id);

                            if (mysqli_stmt_execute($stmt_update)) {
                                // Log the subscription
                                $package_res = mysqli_query($connection_server, "SELECT price FROM sas_billing_packages WHERE id='$billing_package_id'");
                                $package = mysqli_fetch_assoc($package_res);
                                $price = $package['price'];
                                $purchase_date = date('Y-m-d H:i:s');

                                $stmt_log = mysqli_prepare($connection_server, "INSERT INTO sas_vendor_subscriptions (vendor_id, package_id, purchase_date, expiry_date, amount_paid) VALUES (?, ?, ?, ?, ?)");
                                mysqli_stmt_bind_param($stmt_log, "iissd", $vendor_id, $billing_package_id, $purchase_date, $new_expiry_date, $price);
                                mysqli_stmt_execute($stmt_log);

                                $page_title = "Renewal Successful";
                                $success_message = "Your subscription has been successfully renewed. Your new expiry date is " . date('F j, Y', strtotime($new_expiry_date)) . ".";
                            } else {
                                $error_message = "Could not renew your subscription. Please contact support with reference: $payment_ref";
                            }
                        } else {
                            $error_message = "Renewal transaction metadata is missing. Please contact support.";
                        }
                    } else {
                        $error_message = "Invalid transaction type. Please contact support.";
                    }
                } else {
                     $error_message = "Transaction was not successful or could not be verified.";
                }
            }
        } else {
            $error_message = "Payment gateway is not configured. Please contact support.";
        }
    }
?>
<!DOCTYPE html>
<head>
    <title><?php echo $page_title; ?></title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
</head>
<body>
    <main>
        <div class="container">
            <section class="section min-vh-100 d-flex flex-column align-items-center justify-content-center py-4">
                <div class="container">
                    <div class="row justify-content-center">
                        <div class="col-lg-6 col-md-8">
                            <div class="card">
                                <div class="card-body text-center p-5">
                                    <?php if(isset($success_message)): ?>
                                        <i class="bi bi-check-circle-fill text-success" style="font-size: 4rem;"></i>
                                        <h2 class="mt-3">Payment Successful!</h2>
                                        <p class="lead"><?php echo $success_message; ?></p>
                                    <?php else: ?>
                                         <i class="bi bi-x-circle-fill text-danger" style="font-size: 4rem;"></i>
                                        <h2 class="mt-3">Payment Failed</h2>
                                        <p class="lead"><?php echo $error_message; ?></p>
                                    <?php endif; ?>
                                    <a href="/" class="btn btn-primary mt-3">Go to Homepage</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </main>
</body>
</html>
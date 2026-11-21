<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
 bc_secure_session_start();
    // Use basic configs
    include("../func/bc-connect.php");
	include("../func/bc-tables.php");
	include_once("../func/whmp-func.php");
    require_once(__DIR__ . "/../func/bc-plate-auth.php");

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
                                // ─── WHMP Domain Registration ────────────────────────────────────────
                                $domain_registration_msg = '';
                                $pending_q = mysqli_query($connection_server, "SELECT app_base_url, domain_option, domain_registration_fee FROM sas_pending_vendors WHERE id='$pending_vendor_id'");
                                $pending_row = mysqli_fetch_assoc($pending_q);

                                if ($pending_row && ($pending_row['domain_option'] ?? '') == 'register' && !empty($pending_row['app_base_url'])) {
                                    if (whmp_is_configured()) {
                                        $reg_result = whmp_register_domain($pending_row['app_base_url']);
                                        if ($reg_result['status'] === 'success') {
                                            $domain_registration_msg = " Domain " . htmlspecialchars($pending_row['app_base_url']) . " has been registered successfully.";
                                            $whmp_ref = isset($reg_result['data']['domain_id']) ? $reg_result['data']['domain_id'] : '';
                                            if ($whmp_ref) {
                                                mysqli_query($connection_server, "UPDATE sas_pending_vendors SET whmp_domain_id='" . mysqli_real_escape_string($connection_server, $whmp_ref) . "' WHERE id='$pending_vendor_id'");
                                            }
                                        } else {
                                            $domain_registration_msg = " Note: Domain " . htmlspecialchars($pending_row['app_base_url']) . " registration will be completed manually.";
                                            error_log('[WHMP] Domain registration failed for ' . $pending_row['app_base_url'] . ': ' . ($reg_result['message'] ?? 'Unknown error'));
                                        }
                                    } else {
                                        $domain_registration_msg = " Note: Domain " . htmlspecialchars($pending_row['app_base_url']) . " will be registered manually once your account is approved.";
                                    }
                                }

                                $page_title = "Payment Successful";
                                $success_message = "Your payment was successful! Your registration is now pending final approval from our administrators. You will be notified by email once your account is activated." . $domain_registration_msg;
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

?>
<?php
bc_plate_auth_head($page_title . ' | ' . $get_all_site_details["site_title"]);
bc_plate_auth_top(array(
    'icon'  => isset($success_message) ? 'check' : 'warn',
    'title' => isset($success_message) ? 'Payment successful' : 'Payment failed',
    'sub'   => isset($success_message)
        ? 'Your subscription has been renewed.'
        : 'We could not confirm this payment.',
));
?>
        <p class="pk-auth__note" style="text-align:left;margin-bottom:18px;">
            <?php echo htmlspecialchars(isset($success_message) ? $success_message : $error_message); ?>
        </p>
        <a class="pk-auth__btn" href="/"><?php echo bc_plate_auth_icon('home', 18); ?> Go to homepage</a>
<?php
bc_plate_auth_end(array(
    'note' => 'If this payment was deducted but not confirmed, contact support with your reference.',
));
?>

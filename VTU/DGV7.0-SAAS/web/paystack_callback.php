<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
 bc_secure_session_start();
    // Use basic configs
    include("../func/bc-connect.php");
    include("../func/bc-tables.php");
    include_once("../func/whmp-func.php");
    require_once(__DIR__ . "/../func/bc-plate-auth.php");

    require_once __DIR__.'/../func/bc-vendor-registration-commerce.php';
    require_once __DIR__.'/../func/bc-vendor-registration.php';
    header('Cache-Control: no-store, private');
    $page_title = "Payment Verification";
    $error_message = "Transaction could not be verified. Please contact support.";

    if(isset($_GET['reference'])) {
        if(!is_string($_GET['reference'])||!preg_match('/\A[A-Za-z0-9_-]{8,120}\z/D',$_GET['reference'])){http_response_code(400);exit('Invalid reference.');}
        $reference = mysqli_real_escape_string($connection_server, $_GET['reference']);

        try{
            bc_vreg_limit('payment_verify_ip',(string)($_SERVER['REMOTE_ADDR']??'unknown'),30,900);
            bc_vreg_limit('payment_verify_ref',$reference,10,900);
            if(strpos($reference,'vendor_reg_')===0){
                $expected=bc_q_row('SELECT * FROM sas_pending_vendors WHERE paystack_reference=?',[$reference]);
                if(!$expected||$expected['payment_method']!=='paystack')throw new RuntimeException('Unknown invoice.');
                bc_vreg_invoice($expected);
            }
        }catch(Throwable $e){http_response_code(429);exit('Payment verification is unavailable or requires support review. Do not pay again.');}

        // Fetch Paystack secret key
        $gateway_res = mysqli_query($connection_server, "SELECT secret_key FROM sas_super_admin_payment_gateways WHERE gateway_name='paystack'");
        $gateway = mysqli_fetch_assoc($gateway_res);
        $secret_key = $gateway['secret_key'];

        if($secret_key) {
            $curl = curl_init();
// cURL waits forever by default - bound the connect and the whole call so one slow provider cannot
// hold this PHP worker open indefinitely.
curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
curl_setopt($curl, CURLOPT_TIMEOUT, 30);
            curl_setopt_array($curl, array(
                CURLOPT_URL => "https://api.paystack.co/transaction/verify/" . rawurlencode($reference),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    "Authorization: Bearer " . $secret_key,
                    "Cache-Control: no-cache",
                ],
            ));

            $response = curl_exec($curl);
            $verify_http=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);
            $err = curl_error($curl);
            curl_close($curl);

            if ($err) {
                $error_message = "Payment gateway error. Please contact support.";
            } else {
                $result = json_decode($response, true);
                if (($result['status']??false) === true && is_array($result['data']??null) && ($result['data']['status']??'') === 'success' && is_array($result['data']['metadata']??null)) {
                    $metadata = $result['data']['metadata'];
                    $payment_ref = $result['data']['reference'];
                    $type = $metadata['type'] ?? '';

                    if ($type == 'vendor_subscription') {
                        if($verify_http===200&&bc_vreg_online_receipt($result['data'],$reference)){
                            $page_title='Payment verified';$success_message='Your payment matches the registration invoice. Activation still requires platform approval and applicable KYC/KYB.';
                        }else{$error_message='Payment does not match a trusted registration invoice. No account has been activated; contact support.';}
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

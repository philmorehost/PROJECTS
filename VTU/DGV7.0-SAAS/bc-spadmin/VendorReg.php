<?php session_start();
    // Use basic configs to allow optional login
    include("../func/bc-connect.php");
	include("../func/bc-tables.php");
    include("../func/bc-email-templates.php");
    include_once("../func/bc-func.php");
    include("../func/whmcs-func.php");
    // The password-hashing helpers (bc_hash_password / bc_verify_password / bc_password_is_legacy)
    // live in bc-security.php, which only the full configs load (bc-config, bc-admin-config,
    // bc-spadmin-config). This page deliberately bootstraps with the "basic configs" so it can run
    // before a super admin signs in, so nothing above defines them -> load it explicitly here.
    // Same pattern as web/api/kyc.php and api/app-backend/ai-handler.php. Without this, placing an
    // order died with "Call to undefined function bc_hash_password()" on line ~86.
    include_once("../func/bc-security.php");

    // Fetch domain settings
    $nameservers = '';
    $nameservers = '';
    $ip_address = '';
    $registrar_url = '';
    $sql_fetch = "SELECT * FROM sas_super_admin_options WHERE option_name IN ('domain_nameservers', 'domain_ip_address', 'domain_registrar_url')";
    $result = mysqli_query($connection_server, $sql_fetch);
    if ($result) {
        while($row = mysqli_fetch_assoc($result)) {
            if($row['option_name'] == 'domain_nameservers') $nameservers = $row['option_value'];
            if($row['option_name'] == 'domain_ip_address') $ip_address = $row['option_value'];
            if($row['option_name'] == 'domain_registrar_url') $registrar_url = $row['option_value'];
        }
    }

    if(isset($_POST["create-profile"])){
        $first = mysqli_real_escape_string($connection_server, trim(strip_tags(ucwords($_POST["first"]))));
        $last = mysqli_real_escape_string($connection_server, trim(strip_tags(ucwords($_POST["last"]))));
        $address = mysqli_real_escape_string($connection_server, trim(strip_tags(ucwords($_POST["address"]))));
        $email = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["email"]))));
        $pass = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["pass"])));
        $phone = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["phone"]))));
        $billing_package_id = mysqli_real_escape_string($connection_server, $_POST['billing_package_id']);
        $payment_method = mysqli_real_escape_string($connection_server, $_POST['payment_method']);
        
        $app_base_url = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST['app_base_url'] ?? '')));
        $order_apk = isset($_POST['order_apk']) ? 1 : 0;
        $order_ios = isset($_POST['order_ios']) ? 1 : 0;
        $order_playstore = isset($_POST['order_playstore']) ? 1 : 0;
        $order_sms_bridge = isset($_POST['order_sms_bridge']) ? 1 : 0;

        // Dynamic Addons
        $selected_addons_ids = $_POST['addons'] ?? [];
        $selected_addons_str = mysqli_real_escape_string($connection_server, implode(',', $selected_addons_ids));
        $domain_fee = (float)($_POST['domain_fee'] ?? 0);
        $total_amount = (float)($_POST['total_amount'] ?? 0);
        $domain_option = $_POST['domain_option'] ?? 'register';

        $unrefined_website_url = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["website-url"]))));
        $refined_website_url = trim(str_replace(["https","http",":/","/","www."," "],"",$unrefined_website_url));
        $website_url = !empty($app_base_url) ? $app_base_url : $refined_website_url;

        if (bc_is_temporary_vendor_host($website_url)) {
            $_SESSION["product_purchase_response"] = "This domain is reserved for temporary vendor access. Please choose a different website domain.";
            header("Location: ".$_SERVER["REQUEST_URI"]);
            exit();
        }

        // Server-side Domain extension validation
        $ext_q = mysqli_query($connection_server, "SELECT extension FROM sas_domain_extensions ORDER BY extension ASC");
        $valid_exts = [];
        while ($row = mysqli_fetch_assoc($ext_q)) {
            $valid_exts[] = strtolower(trim($row['extension']));
        }
        if (empty($valid_exts)) {
            $valid_exts = ['.com', '.ng', '.com.ng', '.org', '.net'];
        }

        $has_extension = false;
        foreach ($valid_exts as $ext) {
            if (substr($website_url, -strlen($ext)) === $ext) {
                $has_extension = true;
                break;
            }
        }

        if (!$has_extension) {
            $ext_examples = implode(', ', $valid_exts);
            $_SESSION["product_purchase_response"] = "Please enter the domain name along with its extension (e.g., website" . $valid_exts[0] . "). Available extensions: " . $ext_examples;
            header("Location: ".$_SERVER["REQUEST_URI"]);
            exit();
        }
        
        if($domain_option == 'existing') $domain_fee = 0;

        if(!empty($first) && !empty($last) && !empty($address) && !empty($email) && !empty($pass) && !empty($phone) && !empty($website_url) && !empty($billing_package_id) && !empty($payment_method)){
            if(filter_var($email, FILTER_VALIDATE_EMAIL)){
                $check_vendor_details_with_email = mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE email='$email'");
                $check_pending_vendor_details_with_email = mysqli_query($connection_server, "SELECT * FROM sas_pending_vendors WHERE email='$email'");
                if(mysqli_num_rows($check_vendor_details_with_email) == 0 && mysqli_num_rows($check_pending_vendor_details_with_email) == 0){
                    $check_vendor_details_with_url = mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE website_url='$website_url'");
                    $check_pending_vendor_details_with_url = mysqli_query($connection_server, "SELECT * FROM sas_pending_vendors WHERE website_url='$website_url'");
                    if(mysqli_num_rows($check_vendor_details_with_url) == 0 && mysqli_num_rows($check_pending_vendor_details_with_url) == 0){
                        $md5_pass = bc_hash_password($pass); // stored as password_hash(); the login path also accepts legacy md5
                        
                        $def_min = getSuperAdminOption('default_min_withdrawal', '1000');
                        $def_max = getSuperAdminOption('default_max_withdrawal', '50000');
                        $def_limit = getSuperAdminOption('default_daily_payout_limit', '10');

                        $sql = "INSERT INTO sas_pending_vendors (website_url, email, password, firstname, lastname, phone_number, home_address, billing_package_id, payment_method, status, min_withdrawal_amount, max_withdrawal_amount, daily_payout_limit, app_base_url, order_apk, order_ios, order_playstore, order_sms_bridge, selected_addons, domain_registration_fee, total_amount) VALUES ('$website_url', '$email', '$md5_pass', '$first', '$last', '$phone', '$address', '$billing_package_id', '$payment_method', '0', '$def_min', '$def_max', '$def_limit', '$app_base_url', '$order_apk', '$order_ios', '$order_playstore', '$order_sms_bridge', '$selected_addons_str', '$domain_fee', '$total_amount')";
                        if(mysqli_query($connection_server, $sql)) {
                            $pending_id = mysqli_insert_id($connection_server);

                            // Send admin notification email
                            $get_super_admin = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_super_admin LIMIT 1"));
                            if($get_super_admin) {
                                $admin_email = $get_super_admin['email'];
                                $email_placeholders = array(
                                    "{firstname}" => $first,
                                    "{lastname}" => $last,
                                    "{email}" => $email,
                                    "{website}" => $website_url
                                );
                                $email_subject = getSuperAdminEmailTemplate('new-vendor-pending-admin-alert', 'subject');
                                $email_body = getSuperAdminEmailTemplate('new-vendor-pending-admin-alert', 'body');
                                foreach($email_placeholders as $key => $val) {
                                    $email_subject = str_replace($key, $val, $email_subject);
                                    $email_body = str_replace($key, $val, $email_body);
                                }
                                sendVendorEmail($admin_email, $email_subject, $email_body);
                            }

                            if ($payment_method == 'paystack') {
                                // Server-side Total Verification
                                $v_package_id = mysqli_real_escape_string($connection_server, $billing_package_id);
                                $v_pkg_res = mysqli_query($connection_server, "SELECT price FROM sas_billing_packages WHERE id='$v_package_id'");
                                $v_pkg = mysqli_fetch_assoc($v_pkg_res);

                                $calculated_total = (float)($v_pkg['price'] ?? 0);
                                
                                // Dynamic Addon Price Verification
                                if(!empty($selected_addons_ids)) {
                                    $addon_ids_clean = array_map(function($id) use ($connection_server) { return mysqli_real_escape_string($connection_server, $id); }, $selected_addons_ids);
                                    $addon_ids_str = implode(',', $addon_ids_clean);
                                    $addon_price_res = mysqli_query($connection_server, "SELECT SUM(price) as total_addon_price FROM sas_billing_addons WHERE id IN ($addon_ids_str)");
                                    $addon_price_row = mysqli_fetch_assoc($addon_price_res);
                                    $calculated_total += (float)($addon_price_row['total_addon_price'] ?? 0);
                                }

                                if ($domain_option == 'register') {
                                    $actual_domain_fee = 0;
                                    if (!empty($app_base_url)) {
                                        $first_dot = strpos($app_base_url, '.');
                                        $ext = ($first_dot !== false) ? substr($app_base_url, $first_dot) : '';
                                        $ext_esc = mysqli_real_escape_string($connection_server, $ext);
                                        $q_ext = mysqli_query($connection_server, "SELECT price, promo_price FROM sas_domain_extensions WHERE extension='$ext_esc' LIMIT 1");
                                        if($r_ext = mysqli_fetch_assoc($q_ext)) {
                                            $actual_domain_fee = ($r_ext['promo_price'] > 0) ? (float)$r_ext['promo_price'] : (float)$r_ext['price'];
                                        }
                                    }
                                    $calculated_total += $actual_domain_fee;
                                }

                                $amount_in_kobo = $calculated_total * 100;

                                if(abs($calculated_total - $total_amount) > 0.01) {
                                    mysqli_query($connection_server, "UPDATE sas_pending_vendors SET total_amount='$calculated_total' WHERE id='$pending_id'");
                                }

                                $gateway_res = mysqli_query($connection_server, "SELECT secret_key FROM sas_super_admin_payment_gateways WHERE gateway_name='paystack'");
                                $gateway = mysqli_fetch_assoc($gateway_res);
                                $secret_key = $gateway['secret_key'];

                                if ($secret_key) {
                                    $callback_url = $web_http_host . '/web/paystack_callback.php';
                                    $reference = 'vendor_reg_' . $pending_id . '_' . time();

                                    $post_data = [
                                        'email' => $email,
                                        'amount' => $amount_in_kobo,
                                        'callback_url' => $callback_url,
                                        'reference' => $reference,
                                        'metadata' => ['pending_vendor_id' => $pending_id, 'type' => 'vendor_subscription']
                                    ];

                                    $curl = curl_init();
// cURL waits forever by default - bound the connect and the whole call so one slow provider cannot
// hold this PHP worker open indefinitely.
curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
curl_setopt($curl, CURLOPT_TIMEOUT, 30);
                                    curl_setopt_array($curl, array(
                                        CURLOPT_URL => "https://api.paystack.co/transaction/initialize",
                                        CURLOPT_RETURNTRANSFER => true,
                                        CURLOPT_CUSTOMREQUEST => "POST",
                                        CURLOPT_POSTFIELDS => json_encode($post_data),
                                        CURLOPT_HTTPHEADER => ["Authorization: Bearer " . $secret_key, "Content-Type: application/json"],
                                    ));

                                    $response = curl_exec($curl);
                                    $err = curl_error($curl);
                                    curl_close($curl);

                                    if ($err) {
                                        $_SESSION["product_purchase_response"] = "Payment gateway error. Please try again or contact support.";
                                    } else {
                                        $result = json_decode($response, true);
                                        if ($result['status'] == true) {
                                            mysqli_query($connection_server, "UPDATE sas_pending_vendors SET paystack_reference='$reference' WHERE id='$pending_id'");
                                            header('Location: ' . $result['data']['authorization_url']);
                                            exit();
                                        } else {
                                            $_SESSION["product_purchase_response"] = "Could not initialize payment: " . $result['message'];
                                        }
                                    }
                                } else {
                                     $_SESSION["product_purchase_response"] = "Paystack payment gateway is not configured. Please contact support.";
                                }
                            } else {
                                header("Location: /web/manual_payment.php");
                                exit();
                            }
                        } else {
                            $_SESSION["product_purchase_response"] = "Could not save your registration. Please try again.";
                        }
                        header("Location: ".$_SERVER["REQUEST_URI"]);
                        exit();
                    } else {
                        $_SESSION["product_purchase_response"] = "A vendor with the same Website URL already exists.";
                    }
                } else {
                     $_SESSION["product_purchase_response"] = "A vendor with the same Email already exists.";
                }
            } else {
                $_SESSION["product_purchase_response"] = "Invalid Email format.";
            }
        } else {
            $_SESSION["product_purchase_response"] = "Please fill all required fields.";
        }
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit();
    }

    $is_admin_session = isset($_SESSION["spadmin_session"]);
    $css_style_template_location = "/cssfile/template/bc-style-template-1.css";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Vendor Onboarding | Platform Registration</title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
    <link rel="stylesheet" href="/cssfile/bc-style.css">
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
    <style>
        :root {
            --bs-primary: #0d6efd;
            --bs-primary-rgb: 13, 110, 253;
        }
        body { background-color: #f6f9ff; font-family: 'Inter', sans-serif; }
        .form-label { font-weight: 600; font-size: 0.85rem; color: #444; margin-bottom: 8px; }
        .card { border: none; border-radius: 15px; box-shadow: 0 5px 20px rgba(0,0,0,0.05); overflow: hidden; }
        .card-header { border-bottom: 1px solid #f0f0f0; background: #fff; padding: 1.25rem 1.5rem; }
        .input-group-text { background: #f8f9fa; border-right: none; color: var(--bs-primary); }
        .form-control, .form-select { border-radius: 10px; padding: 0.75rem 1rem; border-color: #e0e0e0; }
        .form-control:focus, .form-select:focus { box-shadow: 0 0 0 0.25rem rgba(var(--bs-primary-rgb), 0.1); border-color: var(--bs-primary); }

        .addon-card {
            border: 2px solid #f0f0f0;
            border-radius: 12px;
            padding: 1.25rem;
            height: 100%;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            background: #fff;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
        }
        .addon-card:hover { border-color: var(--bs-primary); transform: translateY(-3px); }
        .addon-trigger:checked + .addon-card {
            border-color: var(--bs-primary);
            background-color: rgba(var(--bs-primary-rgb), 0.02);
            box-shadow: 0 10px 25px rgba(var(--bs-primary-rgb), 0.1);
        }
        .addon-icon {
            width: 45px;
            height: 45px;
            background: #f0f7ff;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: var(--bs-primary);
            margin-bottom: 0.75rem;
        }
        .addon-price {
            font-size: 1.05rem;
            font-weight: 800;
            color: var(--bs-primary);
            margin-top: 0.25rem;
            white-space: nowrap;
        }
        @media (max-width: 576px) {
            .addon-price { font-size: 0.85rem; }
            .addon-card { padding: 1rem 0.5rem; }
            .addon-icon { width: 35px; height: 35px; font-size: 1.2rem; }
        }
        .addon-check {
            position: absolute;
            top: 12px;
            right: 12px;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            border: 2px solid #ddd;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .addon-trigger:checked + .addon-card .addon-check {
            background: var(--bs-primary);
            border-color: var(--bs-primary);
        }
        .addon-trigger:checked + .addon-card .addon-check i {
            color: #fff;
            font-size: 12px;
        }

        .domain-option-card {
            border: 2px solid #f0f0f0;
            border-radius: 12px;
            padding: 1.25rem;
            cursor: pointer;
            transition: all 0.2s;
            background: #fff;
            text-align: center;
            height: 100%;
            display: block;
        }
        .domain-option-trigger:checked + .domain-option-card {
            border-color: var(--bs-primary);
            background: rgba(var(--bs-primary-rgb), 0.03);
        }
        .domain-option-card i { font-size: 2rem; color: #666; margin-bottom: 0.5rem; display: block; }
        .domain-option-trigger:checked + .domain-option-card i { color: var(--bs-primary); }

        .checkout-summary {
            background: #fff;
            border-radius: 20px;
            padding: 2rem;
            border: 1px solid #eee;
            position: sticky;
            top: 20px;
        }
        .summary-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px dashed #eee;
        }
        .summary-total {
            font-size: 1.75rem;
            font-weight: 900;
            color: var(--bs-primary);
        }

        @media (max-width: 768px) {
            .summary-total { font-size: 1.5rem; }
            .checkout-summary { margin-top: 2rem; position: static; }
        }

        /* Explicit Button Styles for Visibility */
        .btn-primary, #search-btn {
            background-color: #0d6efd !important;
            border-color: #0d6efd !important;
            color: #ffffff !important;
        }
        .btn-primary:hover, #search-btn:hover {
            background-color: #0b5ed7 !important;
            border-color: #0a58ca !important;
            color: #ffffff !important;
        }

        /* Payment Channel Buttons Explicit Fix */
        .btn-outline-primary {
            color: #0d6efd !important;
            border-color: #0d6efd !important;
            background-color: transparent !important;
        }
        .btn-check:checked + .btn-outline-primary {
            background-color: #0d6efd !important;
            color: #ffffff !important;
            border-color: #0d6efd !important;
        }
        /* Fix Hover Visibility for Outline Buttons */
        .btn-outline-primary:hover {
            background-color: #0b5ed7 !important;
            color: #ffffff !important;
            border-color: #0b5ed7 !important;
        }

        #btn-submit {
            background: linear-gradient(45deg, #0d6efd, #004dc7) !important;
            border: none;
            padding: 1.1rem 2rem;
            font-weight: 800;
            letter-spacing: 0.5px;
            transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            color: #ffffff !important;
            display: flex !important;
            align-items: center;
            justify-content: center;
            box-shadow: 0 10px 20px rgba(13, 110, 253, 0.3) !important;
            opacity: 1 !important;
            visibility: visible !important;
            text-transform: uppercase;
        }
        #btn-submit:hover:not(:disabled) {
            transform: translateY(-3px) scale(1.01);
            box-shadow: 0 15px 30px rgba(13, 110, 253, 0.4) !important;
            filter: brightness(1.1);
            color: #ffffff !important;
        }
        #btn-submit:active {
            transform: translateY(-1px);
        }

        .suggestion-btn {
            font-size: 0.75rem;
            padding: 0.5rem 1.25rem;
            border: 1px solid #dee2e6;
            background: #fff;
            border-radius: 50px;
            transition: all 0.2s;
            word-break: break-all;
            font-weight: 600;
            color: #444;
        }
        .suggestion-btn:hover {
            border-color: var(--bs-primary);
            color: var(--bs-primary) !important;
            background: rgba(var(--bs-primary-rgb), 0.05);
            transform: scale(1.05);
        }

        .setup-instructions {
            background: #f0f7ff;
            border: 1px solid #cce3ff;
            border-left: 5px solid #0d6efd;
            border-radius: 12px;
            padding: 1.5rem;
        }

        code.text-break {
            word-wrap: break-word !important;
            white-space: pre-wrap !important;
        }
    </style>
</head>
<body>
    <?php if($is_admin_session) include("../func/bc-spadmin-header.php"); ?>

    <div class="container py-4 py-lg-5">
        <?php if($is_admin_session): ?>
        <div class="pagetitle mb-4">
            <h1 class="h3 fw-900 text-dark">Onboard New Vendor</h1>
            <nav>
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li>
                    <li class="breadcrumb-item active">Add Vendor</li>
                </ol>
            </nav>
        </div>
        <?php endif; ?>

        <form method="post" action="" id="reg-form">
            <div class="row g-4">
                <div class="col-lg-8">
                    <!-- Basic Info -->
                    <div class="card mb-4 shadow-sm">
                        <div class="card-header">
                            <h5 class="mb-0 fw-bold"><i class="bi bi-person-badge me-2 text-primary"></i>1. Vendor Contact Information</h5>
                        </div>
                        <div class="card-body p-4">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label text-uppercase small fw-bold">First Name</label>
                                    <input type="text" name="first" class="form-control" placeholder="John" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label text-uppercase small fw-bold">Last Name</label>
                                    <input type="text" name="last" class="form-control" placeholder="Doe" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label text-uppercase small fw-bold">Email Address</label>
                                    <input type="email" name="email" class="form-control" placeholder="vendor@example.com" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label text-uppercase small fw-bold">Phone Number</label>
                                    <input type="text" name="phone" class="form-control" placeholder="081XXXXXXXX" pattern="[0-9]{11}" required>
                                </div>
                                <div class="col-12">
                                    <label class="form-label text-uppercase small fw-bold">Address</label>
                                    <textarea name="address" class="form-control" rows="2" placeholder="Full residential or business address" required></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Domain Config -->
                    <div class="card mb-4 shadow-sm">
                        <div class="card-header">
                            <h5 class="mb-0 fw-bold"><i class="bi bi-globe me-2 text-primary"></i>2. Domain & URL Setup</h5>
                        </div>
                        <div class="card-body p-4">
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <input type="radio" class="d-none domain-option-trigger" name="domain_option" id="opt_reg" value="register" checked onchange="toggleDomainView()">
                                    <label class="domain-option-card" for="opt_reg">
                                        <i class="bi bi-plus-circle-fill"></i>
                                        <div class="fw-bold">Register New Domain</div>
                                        <div class="x-small text-muted">Auto-registration by system</div>
                                    </label>
                                </div>
                                <div class="col-md-6">
                                    <input type="radio" class="d-none domain-option-trigger" name="domain_option" id="opt_exist" value="existing" onchange="toggleDomainView()">
                                    <label class="domain-option-card" for="opt_exist">
                                        <i class="bi bi-hdd-network-fill"></i>
                                        <div class="fw-bold">Use Existing Domain</div>
                                        <div class="x-small text-muted">Already registered elsewhere</div>
                                    </label>
                                </div>
                            </div>

                            <div id="register_view">
                                <label class="form-label text-uppercase small fw-bold">Find Availability</label>
                                <div class="row g-2">
                                    <div class="col-sm-8">
                                        <div class="input-group input-group-lg">
                                            <span class="input-group-text bg-white">www.</span>
                                            <input type="text" id="target_domain" name="website-url" class="form-control border-start-0" placeholder="mybrandname">
                                        </div>
                                    </div>
                                    <div class="col-sm-4">
                                        <div class="input-group input-group-lg h-100">
                                            <select id="domain_extension" class="form-select fw-bold" onchange="updateCheckoutTotal()" style="border-top-right-radius: 0; border-bottom-right-radius: 0;">
                                                <?php
                                                $ext_res = mysqli_query($connection_server, "SELECT extension FROM sas_domain_extensions ORDER BY extension ASC");
                                                while($ext = mysqli_fetch_assoc($ext_res)) {
                                                    echo "<option value='{$ext['extension']}'>{$ext['extension']}</option>";
                                                }
                                                ?>
                                            </select>
                                            <button class="btn btn-primary px-4 fw-bold" type="button" onclick="lookupDomain()" id="search-btn" style="border-top-left-radius: 0; border-bottom-left-radius: 0;">
                                                <span id="btn-text">SEARCH</span>
                                                <span id="btn-spinner" class="spinner-border spinner-border-sm d-none"></span>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <div id="domain_feedback" class="mt-3"></div>
                            </div>

                            <div id="existing_view" class="d-none">
                                <label class="form-label text-uppercase small fw-bold">Specify Domain</label>
                                <div class="input-group input-group-lg mb-4">
                                    <span class="input-group-text bg-white">https://</span>
                                    <input type="text" id="existing_domain" name="existing_url" class="form-control" placeholder="mywebsite.com" oninput="syncExistingDomain(this.value)">
                                </div>

                                <div id="setup-instructions" class="setup-instructions shadow-sm">
                                    <div class="d-flex align-items-start">
                                        <i class="bi bi-info-square-fill text-primary fs-3 me-3"></i>
                                        <div class="w-100">
                                            <h6 class="fw-bold mb-3 text-dark">Point Your Domain (Instructions)</h6>
                                            <div class="row g-3">
                                                <div class="col-sm-6">
                                                    <div class="x-small fw-bold text-muted mb-1">PRIMARY NAMESERVERS</div>
                                                    <div class="bg-white p-2 border rounded">
                                                        <code class="text-dark small text-break"><?php echo nl2br(htmlspecialchars($nameservers)); ?></code>
                                                    </div>
                                                </div>
                                                <div class="col-sm-6">
                                                    <div class="x-small fw-bold text-muted mb-1">A-RECORD (IPv4)</div>
                                                    <div class="bg-white p-2 border rounded">
                                                        <code class="text-primary small text-break"><?php echo htmlspecialchars($ip_address); ?></code>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <input type="hidden" name="domain_fee" id="domain_fee_input" value="0">
                            <input type="hidden" name="app_base_url" id="app_base_url_input">
                        </div>
                    </div>

                    <div class="card mb-4 shadow-sm">
                        <div class="card-header">
                            <h5 class="mb-0 fw-bold"><i class="bi bi-app-indicator me-2 text-primary"></i>3. Service Add-ons (One-Off)</h5>
                        </div>
                        <div class="card-body p-4">
                            <div class="row g-3">
                                <?php
                                $addons_q = mysqli_query($connection_server, "SELECT * FROM sas_billing_addons ORDER BY id ASC");
                                if(mysqli_num_rows($addons_q) > 0):
                                    while($addon = mysqli_fetch_assoc($addons_q)):
                                ?>
                                <div class="col-6 col-md-3">
                                    <input class="d-none addon-trigger" type="checkbox" name="addons[]" id="addon_<?php echo $addon['id'] ?>" value="<?php echo $addon['id'] ?>" data-price="<?php echo $addon['price'] ?>" onchange="updateCheckoutTotal()">
                                    <label class="addon-card shadow-sm" for="addon_<?php echo $addon['id'] ?>">
                                        <div class="addon-check"><i class="bi bi-check"></i></div>
                                        <div class="addon-icon shadow-sm"><i class="bi <?php echo htmlspecialchars($addon['icon']) ?>"></i></div>
                                        <div class="fw-bold small text-dark"><?php echo htmlspecialchars($addon['name']) ?></div>
                                        <div class="addon-price">₦<?php echo number_format($addon['price'], 0) ?></div>
                                    </label>
                                </div>
                                <?php endwhile; else: ?>
                                <div class="col-12 text-center text-muted py-3">No additional services available at the moment.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="checkout-summary shadow-sm">
                        <h5 class="fw-black mb-4"><i class="bi bi-cart-check me-2 text-primary"></i>Checkout</h5>

                        <div class="mb-4">
                            <label class="form-label text-uppercase small fw-bold">Subscription Plan</label>
                            <select name="billing_package_id" id="billing_package_id" class="form-select bg-light border-0 py-3" required onchange="updateCheckoutTotal()">
                                <option value="" data-price="0" hidden selected>Select Plan</option>
                                <?php
                                    $packages_result = mysqli_query($connection_server, "SELECT * FROM sas_billing_packages ORDER BY price ASC");
                                    while($package = mysqli_fetch_assoc($packages_result)) {
                                        echo "<option value='{$package['id']}' data-price='{$package['price']}'>".htmlspecialchars($package['name'])." (₦".number_format($package['price'], 0).")</option>";
                                    }
                                ?>
                            </select>
                        </div>

                        <div id="summary-details">
                            <div class="summary-item">
                                <span class="text-muted">Plan Base</span>
                                <span class="fw-bold" id="sum-pkg">₦0.00</span>
                            </div>
                            <div class="summary-item" id="domain-row">
                                <span class="text-muted">Domain Name</span>
                                <span class="fw-bold" id="sum-domain">₦0.00</span>
                            </div>
                            <div class="summary-item">
                                <span class="text-muted">App Add-ons</span>
                                <span class="fw-bold" id="sum-addons">₦0.00</span>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between align-items-center mt-4 mb-4 pt-3 border-top">
                            <span class="fw-900 text-dark fs-5">TOTAL PAYABLE</span>
                            <span class="summary-total" id="display_total">₦0.00</span>
                        </div>

                        <div class="mb-4">
                            <label class="form-label text-uppercase small fw-bold">Payment Channel</label>
                            <div class="row g-2">
                                <div class="col-6">
                                    <input type="radio" class="btn-check" name="payment_method" id="paystack" value="paystack" required>
                                    <label class="btn btn-outline-primary w-100 fw-bold py-2 shadow-sm" for="paystack">ONLINE</label>
                                </div>
                                <div class="col-6">
                                    <input type="radio" class="btn-check" name="payment_method" id="bank_deposit" value="bank_deposit">
                                    <label class="btn btn-outline-primary w-100 fw-bold py-2 shadow-sm" for="bank_deposit">MANUAL</label>
                                </div>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label text-uppercase small fw-bold">Account Password</label>
                            <input type="password" name="pass" class="form-control bg-light border-0 py-3" placeholder="Set password" required>
                        </div>

                        <input type="hidden" name="total_amount" id="total_amount_input" value="0">
                        <button type="submit" name="create-profile" id="btn-submit" class="btn btn-primary w-100 rounded-pill py-3">
                            COMPLETE ORDER & PAY <i class="bi bi-arrow-right-circle ms-2"></i>
                        </button>

                        <?php if(!$is_admin_session): ?>
                        <div class="text-center mt-4">
                            <a href="/" class="text-decoration-none text-muted small fw-bold"><i class="bi bi-house-door me-1"></i> Back to Home</a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <?php if($is_admin_session) include("../func/bc-spadmin-footer.php"); ?>

    <script src="../assets-2/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script>
    let domainFee = 0;

    function toggleDomainView() {
        const option = document.querySelector('input[name="domain_option"]:checked').value;
        const regView = document.getElementById('register_view');
        const existView = document.getElementById('existing_view');
        const domainRow = document.getElementById('domain-row');

        if (option === 'register') {
            regView.classList.remove('d-none');
            existView.classList.add('d-none');
            domainRow.classList.remove('d-none');
            document.getElementById('target_domain').required = true;
            document.getElementById('existing_domain').required = false;
        } else {
            regView.classList.add('d-none');
            existView.classList.remove('d-none');
            domainRow.classList.add('d-none');
            document.getElementById('target_domain').required = false;
            document.getElementById('existing_domain').required = true;
            domainFee = 0;
            updateCheckoutTotal();
        }
    }

    function syncExistingDomain(val) {
        document.getElementById('app_base_url_input').value = val.trim();
    }

    function useSuggestedDomain(domain) {
        const parts = domain.split('.');
        document.getElementById('target_domain').value = parts[0];
        document.getElementById('domain_extension').value = "." + parts.slice(1).join('.');
        lookupDomain();
    }

    function lookupDomain() {
        let domain = document.getElementById('target_domain').value.trim();
        const extension = document.getElementById('domain_extension').value;
        const feedback = document.getElementById('domain_feedback');
        const spinner = document.getElementById('btn-spinner');
        const btnText = document.getElementById('btn-text');
        const submitBtn = document.getElementById('btn-submit');

        if (domain === '') {
            feedback.innerHTML = '<div class="alert alert-danger py-2 rounded-3 small fw-bold">Error: Enter a name first</div>';
            return;
        }

        if (domain.includes('.')) {
            domain = domain.split('.')[0];
            document.getElementById('target_domain').value = domain;
        }

        const fullDomain = domain + extension;
        spinner.classList.remove('d-none');
        btnText.classList.add('d-none');
        feedback.innerHTML = '<div class="text-primary small animate-pulse fw-bold"><i class="bi bi-cpu me-1"></i> Checking Availability...</div>';
        submitBtn.disabled = true;

        fetch('ajax-domain-check.php?domain=' + encodeURIComponent(fullDomain))
            .then(response => response.json())
            .then(data => {
                spinner.classList.add('d-none');
                btnText.classList.remove('d-none');

                if (data.status === 'available') {
                    domainFee = parseFloat(data.price || 0);
                    document.getElementById('domain_fee_input').value = domainFee;
                    document.getElementById('app_base_url_input').value = fullDomain;
                    feedback.innerHTML = `<div class="alert alert-success border-0 shadow-sm rounded-4 p-3">
                        <div class="d-flex align-items-center justify-content-between">
                            <div><i class="bi bi-check-circle-fill me-2 fs-4" style="color:#198754;"></i> <strong class="fs-5" style="color:#198754;" class="text-success">${fullDomain.toUpperCase()}</strong> IS AVAILABLE!</div>
                            <div class="fw-900 fs-5 text-dark">₦${domainFee.toLocaleString()}</div>
                        </div>
                    </div>`;
                    submitBtn.disabled = false;
                } else {
                    domainFee = 0;
                    document.getElementById('domain_fee_input').value = 0;
                    document.getElementById('app_base_url_input').value = "";
                    let html = `<div class="alert alert-danger border-0 rounded-4 p-3 shadow-sm mb-3">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-x-circle-fill me-2 fs-4" style="color:#dc3545;"></i>
                            <strong class="fs-5" style="color:#dc3545;" class="text-danger">${fullDomain.toUpperCase()}</strong> IS TAKEN.
                        </div>
                    </div>`;

                    if(data.suggestions && data.suggestions.length > 0) {
                        html += `<div class="p-3 bg-white border rounded-4 shadow-sm"><div class="x-small fw-black text-muted mb-3 text-uppercase letter-spacing-1">Try available alternatives:</div><div class="d-flex flex-wrap gap-2">`;
                        data.suggestions.forEach(s => {
                            html += `<button type="button" class="suggestion-btn" onclick="useSuggestedDomain('${s}')"><i class="bi bi-plus me-1"></i>${s}</button>`;
                        });
                        html += `</div></div>`;
                    }
                    feedback.innerHTML = html;
                    submitBtn.disabled = true;
                }
                updateCheckoutTotal();
            })
            .catch(err => {
                spinner.classList.add('d-none');
                btnText.classList.remove('d-none');
                feedback.innerHTML = '<div class="alert alert-danger py-2 small fw-bold">System Error: Could not connect to domain registry.</div>';
            });
    }

    function updateCheckoutTotal() {
        const packageSelect = document.getElementById('billing_package_id');
        const basePrice = parseFloat(packageSelect.options[packageSelect.selectedIndex].getAttribute('data-price') || 0);

        let addOnTotal = 0;
        document.querySelectorAll('.addon-trigger:checked').forEach(cb => {
            addOnTotal += parseFloat(cb.getAttribute('data-price') || 0);
        });

        const grandTotal = basePrice + domainFee + addOnTotal;

        document.getElementById('sum-pkg').innerText = "₦" + basePrice.toLocaleString();
        document.getElementById('sum-domain').innerText = "₦" + domainFee.toLocaleString();
        document.getElementById('sum-addons').innerText = "₦" + addOnTotal.toLocaleString();
        document.getElementById('display_total').innerText = "₦" + grandTotal.toLocaleString(undefined, {minimumFractionDigits: 2});
        document.getElementById('total_amount_input').value = grandTotal;
    }

    // Submit validation for domain extension
    document.getElementById('reg-form').addEventListener('submit', function(e) {
        const option = document.querySelector('input[name="domain_option"]:checked').value;
        let domainVal = '';
        
        // Fetch active extensions array from PHP
        const availableExtensions = <?php
            $ext_res = mysqli_query($connection_server, "SELECT extension FROM sas_domain_extensions ORDER BY extension ASC");
            $exts = [];
            while($ext = mysqli_fetch_assoc($ext_res)) {
                $exts[] = strtolower(trim($ext['extension']));
            }
            if (empty($exts)) {
                $exts = ['.com', '.ng', '.com.ng', '.org', '.net'];
            }
            echo json_encode($exts);
        ?>;

        if (option === 'register') {
            const domainInput = document.getElementById('target_domain').value.trim();
            const extension = document.getElementById('domain_extension').value.trim();
            if (domainInput === '') {
                e.preventDefault();
                alert('Please enter a domain name.');
                return;
            }
            domainVal = (domainInput + extension).toLowerCase();
        } else {
            domainVal = document.getElementById('existing_domain').value.trim().toLowerCase();
            if (domainVal === '') {
                e.preventDefault();
                alert('Please enter your existing domain name.');
                return;
            }
            // Remove common scheme and www prefix
            domainVal = domainVal.replace(/^(https?:\/\/)?(www\.)?/, '');
        }

        let hasExt = false;
        for (let ext of availableExtensions) {
            if (domainVal.endsWith(ext)) {
                hasExt = true;
                break;
            }
        }

        if (!hasExt) {
            e.preventDefault();
            const friendlyExample = domainVal.split('.')[0] || "website";
            alert('Please enter your domain name along with its extension (e.g., ' + friendlyExample + availableExtensions[0] + ').\n\nAvailable extensions:\n' + availableExtensions.join(', '));
        }
    });
    </script>
</body>
</html>

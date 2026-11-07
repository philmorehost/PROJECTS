<?php session_start();
    include("../func/bc-spadmin-config.php");
    
    $vendor_id_number = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9]+/", "", trim(strip_tags($_GET["vendorID"]))));
    $select_vendor = mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id_number'");
    if(mysqli_num_rows($select_vendor) > 0){
        $get_vendor_details = mysqli_fetch_array($select_vendor);
    }

    if(isset($_POST["update-profile"])){
        $first = mysqli_real_escape_string($connection_server, trim(strip_tags(ucwords($_POST["first"]))));
        $last = mysqli_real_escape_string($connection_server, trim(strip_tags(ucwords($_POST["last"]))));
        $address = mysqli_real_escape_string($connection_server, trim(strip_tags(ucwords($_POST["address"]))));
        $email = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["email"]))));
        $phone = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["phone"]))));
        $bank_code = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["bank-code"])));
        $account_number = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["account-number"])));
        $bvn = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["bvn"]))));
        $nin = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["nin"]))));
        $force_pin = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["force-pin"])));
        $force_2fa = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["force-2fa"])));
        $force_google = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["force-google"])));
        $google_client_id = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["google-client-id"])));
        $daily_payout_limit = mysqli_real_escape_string($connection_server, (int)$_POST["daily-payout-limit"]);
        $min_with = mysqli_real_escape_string($connection_server, (float)$_POST["min-withdrawal"]);
        $max_with = mysqli_real_escape_string($connection_server, (float)$_POST["max-withdrawal"]);
        $trans_email = isset($_POST["trans-email"]) ? 1 : 0;
        $crypto_approval = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["crypto-approval"])));
        $approve_withdrawal = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["approve-withdrawal"])));
        $withdrawal_fee = (float)$_POST["withdrawal-fee"];
        $crypto_swap_fee = (float)$_POST["crypto-swap-fee"];
        $unrefined_website_url = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["website-url"]))));
        $refined_website_url = trim(str_replace(["https","http",":/","/","www."," "],"",$unrefined_website_url));
        $website_url = $refined_website_url;
        if (bc_is_temporary_vendor_host($website_url)) {
            $_SESSION["product_purchase_response"] = "This domain is reserved for temporary vendor access. Please choose a different website domain.";
            header("Location: ".$_SERVER["REQUEST_URI"]);
            exit();
        }
        
        if(!empty($first) && !empty($last) && !empty($address) && !empty($email) && !empty($phone) && !empty($website_url)){
            if(filter_var($email, FILTER_VALIDATE_EMAIL)){
                $check_vendor_details = mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='".$vendor_id_number."'");
                if(mysqli_num_rows($check_vendor_details) == 1){
                    $get_vendor_details = mysqli_fetch_array($check_vendor_details);
                    $check_vendor_new_email = mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE email='$email'");
                    $check_vendor_new_website = mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE website_url='$website_url'");
                    $proceed_to_email_check = false;

                    if((mysqli_num_rows($check_vendor_new_website) == 1) || (mysqli_num_rows($check_vendor_new_website) < 1)){
                        if(mysqli_num_rows($check_vendor_new_website) == 1){
                            $get_new_vendor_details = mysqli_fetch_array($check_vendor_new_website);
                            if($get_new_vendor_details["id"] == $get_vendor_details["id"]){
                                mysqli_query($connection_server, "UPDATE sas_vendors SET firstname='$first', lastname='$last', home_address='$address', email='$email', phone_number='$phone' WHERE id='".$vendor_id_number."'");
                                $proceed_to_email_check = true;
                            }else{
                                //Website Address Taken By Another Vendor
                                $json_response_array = array("desc" => "Website Address Taken By Another Vendor");
                                $json_response_encode = json_encode($json_response_array,true);
                            }
                        }else{
                            if(mysqli_num_rows($check_vendor_new_website) < 1){
                                $proceed_to_email_check = true;
                            }
                        }
                    }else{
                        if(mysqli_num_rows($check_vendor_new_website) > 1){
                            //Duplicated Vendor Website Address, Contact Developer
                            $json_response_array = array("desc" => "Duplicated Vendor Website Address, Contact Developer");
                            $json_response_encode = json_encode($json_response_array,true);
                        }
                    }
                    if($proceed_to_email_check == true){
                        $email_check_verified = false;
                        if((mysqli_num_rows($check_vendor_new_email) == 1) || (mysqli_num_rows($check_vendor_new_email) < 1)){
                            if(mysqli_num_rows($check_vendor_new_email) == 1){
                                $get_new_vendor_details = mysqli_fetch_array($check_vendor_new_email);
                                if($get_new_vendor_details["id"] == $get_vendor_details["id"]){
                                    $email_check_verified = true;
                                }else{
                                    //Email Taken By Another Vendor
                                    $json_response_array = array("desc" => "Email Taken By Another Vendor");
                                    $json_response_encode = json_encode($json_response_array,true);
                                }
                            }else{
                                if(mysqli_num_rows($check_vendor_new_email) < 1){
                                    $email_check_verified = true;
                                }
                            }
                        }else{
                            if(mysqli_num_rows($check_vendor_new_email) > 1){
                                //Duplicated Vendor Email, Contact Developer
                                $json_response_array = array("desc" => "Duplicated Vendor Email, Contact Developer");
                                $json_response_encode = json_encode($json_response_array,true);
                            }
                        }
                    }

                    if($email_check_verified == true){
                    	if(!empty($bank_code) && is_numeric($bank_code) && (strlen($bank_code) >= 1)){
                    		$refined_bank_code = $bank_code;
                    	}else{
                    		$refined_bank_code = "";
                    	}
                    	
                    	if(!empty($account_number) && is_numeric($account_number) && (strlen($account_number) == 10)){
                    		$refined_account_number = $account_number;
                    	}else{
                    		$refined_account_number = "";
                    	}

                        if(!empty($bvn) && is_numeric($bvn) && (strlen($bvn) == 11)){
                    		$refined_bvn = $bvn;
                    	}else{
                    		$refined_bvn = "";
                    	}

                        if(!empty($nin) && is_numeric($nin) && (strlen($nin) == 11)){
                    		$refined_nin = $nin;
                    	}else{
                    		$refined_nin = "";
                    	}

                        // cPanel Domain Update Logic
                        $old_website_url = $get_vendor_details['website_url'];
                        if ($old_website_url != $website_url) {
                            include_once(__DIR__ . "/../func/cpanel-func.php");
                            cpanel_remove_addon_domain($old_website_url);
                            cpanel_add_addon_domain($website_url);
                        }

                        mysqli_query($connection_server, "UPDATE sas_vendors SET firstname='$first', lastname='$last', home_address='$address', bank_code='$refined_bank_code', account_number='$refined_account_number', bvn='$refined_bvn', nin='$refined_nin', email='$email', phone_number='$phone', website_url='$website_url', force_security_pin='$force_pin', force_2fa='$force_2fa', force_google_sso='$force_google', google_client_id='$google_client_id', crypto_withdrawal_approval='$crypto_approval', approve_withdrawal='$approve_withdrawal', withdrawal_fee='$withdrawal_fee', crypto_swap_fee='$crypto_swap_fee', daily_payout_limit='$daily_payout_limit', min_withdrawal_amount='$min_with', max_withdrawal_amount='$max_with', trans_email_enabled='$trans_email' WHERE id='".$vendor_id_number."'");
                        // Email Beginning
                        $log_template_encoded_text_array = array("{firstname}" => $first, "{lastname}" => $last, "{email}" => $email, "{phone}" => $phone, "{address}" => $address, "{website}" => $website_url);
                        $raw_log_template_subject = getSuperAdminEmailTemplate('vendor-account-update','subject');
                        $raw_log_template_body = getSuperAdminEmailTemplate('vendor-account-update','body');
                        foreach($log_template_encoded_text_array as $array_key => $array_val){
                        	$raw_log_template_subject = str_replace($array_key, $array_val, $raw_log_template_subject);
                        	$raw_log_template_body = str_replace($array_key, $array_val, $raw_log_template_body);
                        }
                        sendSuperAdminEmail($get_vendor_details["email"], $raw_log_template_subject, $raw_log_template_body);
                        // Email End
                        //Profile Information Updated Successfully
                        $json_response_array = array("desc" => "Profile Information Updated Successfully");
                        $json_response_encode = json_encode($json_response_array,true);
                    }
                }else{
                    if(mysqli_num_rows($check_vendor_details) == 0){
                        //Vendor Not Exists
                        $json_response_array = array("desc" => "Vendor Not Exists");
                        $json_response_encode = json_encode($json_response_array,true);
                    }else{
                        if(mysqli_num_rows($check_vendor_details) > 1){
                            //Duplicated Details, Contact Admin
                            $json_response_array = array("desc" => "Duplicated Details, Contact Admin");
                            $json_response_encode = json_encode($json_response_array,true);
                        }
                    }
                }
            }else{
                //Invalid Email
                $json_response_array = array("desc" => "Invalid Email");
                $json_response_encode = json_encode($json_response_array,true);
            }
        }else{
            if(empty($first)){
                //Firstname Field Empty
                $json_response_array = array("desc" => "Firstname Field Empty");
                $json_response_encode = json_encode($json_response_array,true);
            }else{
                if(empty($last)){
                    //Lastname Field Empty
                    $json_response_array = array("desc" => "Lastname Field Empty");
                    $json_response_encode = json_encode($json_response_array,true);
                }else{
                    if(empty($address)){
                        //Home Address Field Empty
                        $json_response_array = array("desc" => "Home Address Field Empty");
                        $json_response_encode = json_encode($json_response_array,true);
                    }else{
                        if(empty($email)){
                            //Email Field Empty
                            $json_response_array = array("desc" => "Email Field Empty");
                            $json_response_encode = json_encode($json_response_array,true);
                        }else{
                            if(empty($phone)){
                                //Phone Number Field Empty
                                $json_response_array = array("desc" => "Phone Number Field Empty");
                                $json_response_encode = json_encode($json_response_array,true);
                            }else{
                                if(empty($website_url)){
                                    //Website Url Field Empty
                                    $json_response_array = array("desc" => "Website Url Field Empty");
                                    $json_response_encode = json_encode($json_response_array,true);
                                }
                            }
                        }
                    }
                }
            }
        }

        $json_response_decode = json_decode($json_response_encode,true);
        $_SESSION["product_purchase_response"] = $json_response_decode["desc"];
        header("Location: ".$_SERVER["REQUEST_URI"]);
    }
    
    if(isset($_POST["update-subscription"])){
        $new_expiry = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["expiry-date"])));
        if(!empty($new_expiry)){
            mysqli_query($connection_server, "UPDATE sas_vendors SET expiry_date='$new_expiry', status=1 WHERE id='$vendor_id_number'");
            $_SESSION["product_purchase_response"] = "Subscription updated successfully";
        }else{
            $_SESSION["product_purchase_response"] = "Please select an expiry date";
        }
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit();
    }

    if(isset($_POST["update-app-orders"])){
        $apk = isset($_POST["apk_ordered"]) ? 1 : 0;
        $ios = isset($_POST["ios_ordered"]) ? 1 : 0;
        $ps = isset($_POST["playstore_ordered"]) ? 1 : 0;
        $sms = isset($_POST["sms_bridge_ordered"]) ? 1 : 0;

        mysqli_query($connection_server, "UPDATE sas_vendors SET apk_ordered='$apk', ios_ordered='$ios', playstore_ordered='$ps', sms_bridge_ordered='$sms' WHERE id='$vendor_id_number'");
        $_SESSION["product_purchase_response"] = "App service order status updated successfully";
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit();
    }

    if(isset($_POST["toggle-nin-card"])){
        $nin_enabled = isset($_POST["nin_card_enabled"]) ? 1 : 0;
        mysqli_query($connection_server, "UPDATE sas_vendors SET nin_card_enabled='$nin_enabled' WHERE id='$vendor_id_number'");
        $_SESSION["product_purchase_response"] = $nin_enabled ? "NIN Card Service enabled for this vendor." : "NIN Card Service disabled for this vendor.";
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit();
    }

    if(isset($_POST["update-ai-settings"])){
        $ai_status = isset($_POST["ai_status"]) ? 1 : 0;
        $tokens = (int)$_POST["ai_token_balance"];
        $price_1k = (float)$_POST["ai_price_per_1k"];
        $model = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["ai_model"])));
        $req_status = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["ai_request_status"])));

        mysqli_query($connection_server, "UPDATE sas_vendors SET ai_status='$ai_status', ai_token_balance='$tokens', ai_price_per_1k_tokens='$price_1k', ai_model_assigned='$model', ai_request_status='$req_status' WHERE id='$vendor_id_number'");
        $_SESSION["product_purchase_response"] = "AI settings updated for this vendor";
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit();
    }

    if(isset($_POST["change-password"])){
        $new_pass = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["new-pass"])));
        $con_new_pass = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["con-new-pass"])));
        
        if(!empty($new_pass) && !empty($con_new_pass)){
            $check_vendor_details = mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='".$vendor_id_number."'");
            if(mysqli_num_rows($check_vendor_details) == 1){
                $new_vendor_pass_hash = mysqli_real_escape_string($connection_server, bc_hash_password($new_pass));
                $new_pass_matches_confirm = ($new_pass === $con_new_pass);
                
                if($new_pass_matches_confirm){
                    mysqli_query($connection_server, "UPDATE sas_vendors SET password='$new_vendor_pass_hash' WHERE id='".$vendor_id_number."'");
                    //Account Password Updated Successfully
                    $json_response_array = array("desc" => "Account Password Updated Successfully");
                    $json_response_encode = json_encode($json_response_array,true);
                }else{
                    //New & Confirm Password Not Match
                    $json_response_array = array("desc" => "New & Confirm Password Not Match");
                    $json_response_encode = json_encode($json_response_array,true);
                }
            }else{
                if(mysqli_num_rows($check_vendor_details) == 0){
                    //Vendor Not Exists
                    $json_response_array = array("desc" => "Vendor Not Exists");
                    $json_response_encode = json_encode($json_response_array,true);
                }else{
                    if(mysqli_num_rows($check_vendor_details) > 1){
                    //Duplicated Details, Contact Admin
                        $json_response_array = array("desc" => "Duplicated Details, Contact Admin");
                        $json_response_encode = json_encode($json_response_array,true);
                    }
                }
            }
        }else{
            if(empty($new_pass)){
                //New Password Field Empty
                $json_response_array = array("desc" => "New Password Field Empty");
                $json_response_encode = json_encode($json_response_array,true);
            }else{
                if(empty($con_new_pass)){
                    //Confirm New Password Field Empty
                    $json_response_array = array("desc" => "Confirm New Password Field Empty");
                    $json_response_encode = json_encode($json_response_array,true);
                }
            }
        }
    
        $json_response_decode = json_decode($json_response_encode,true);
        $_SESSION["product_purchase_response"] = $json_response_decode["desc"];
        header("Location: ".$_SERVER["REQUEST_URI"]);
    }    
?>
<!DOCTYPE html>
<head>
    <title></title>
    <meta charset="UTF-8" />
    <meta name="description" content="" />
    <meta http-equiv="Content-Type" content="text/html; " />
    <meta name="theme-color" content="black" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
    <link rel="stylesheet" href="/cssfile/bc-style.css">
    <meta name="author" content="Philmore Codes">
    <meta name="dc.creator" content="Philmore Codes">
    
  <!-- Vendor CSS Files -->
  <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="../assets-2/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
  <link href="../assets-2/vendor/quill/quill.snow.css" rel="stylesheet">
  <link href="../assets-2/vendor/quill/quill.bubble.css" rel="stylesheet">
  <link href="../assets-2/vendor/remixicon/remixicon.css" rel="stylesheet">
  <link href="../assets-2/vendor/simple-datatables/style.css" rel="stylesheet">

  <!-- Template Main CSS File -->
  <link href="../assets-2/css/style.css" rel="stylesheet">

</head>
<body>
    <?php include("../func/bc-spadmin-header.php"); ?>    
    
    <div class="pagetitle">
      <h1>EDIT VENDOR</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="#">Home</a></li>
          <li class="breadcrumb-item active">Edit Vendor</li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
      <div class="row justify-content-center">
        <div class="col-lg-10">
    
    <?php if(!empty($get_vendor_details['id'])){ ?>
        <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4">
            <div class="card-header bg-white py-4 border-0 text-center">
                <div class="bg-primary bg-opacity-10 rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 80px; height: 80px;">
                    <i class="bi bi-pencil-square text-dark-primary fs-1"></i>
                </div>
                <h4 class="fw-bold mb-0">Vendor Profile Editor</h4>
                <p class="text-muted small">Modify vendor's account, contact and settlement information</p>
            </div>
            <div class="card-body p-4 p-md-5">
                <form method="post">
                    <div class="row g-4 mb-5">
                        <div class="col-12"><h6 class="fw-bold text-uppercase small text-primary border-bottom pb-2">Personal & Business Info</h6></div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">FIRST NAME</label>
                            <input name="first" type="text" value="<?php echo $get_vendor_details['firstname']; ?>" class="form-control rounded-3" required/>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">LAST NAME</label>
                            <input name="last" type="text" value="<?php echo $get_vendor_details['lastname']; ?>" class="form-control rounded-3" required/>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-bold">RESIDENTIAL ADDRESS</label>
                            <input name="address" type="text" value="<?php echo $get_vendor_details['home_address']; ?>" class="form-control rounded-3" required/>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">EMAIL ADDRESS</label>
                            <input name="email" type="email" value="<?php echo $get_vendor_details['email']; ?>" class="form-control rounded-3" required/>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">PHONE NUMBER</label>
                            <input name="phone" type="text" value="<?php echo $get_vendor_details['phone_number']; ?>" class="form-control rounded-3" pattern="[0-9]{11}" required/>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-bold">WEBSITE URL</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0 rounded-start-3">https://</span>
                                <input name="website-url" type="text" value="<?php echo $get_vendor_details['website_url']; ?>" class="form-control rounded-end-3" required/>
                            </div>
                        </div>
                    </div>

                    <div class="row g-4 mb-5">
                        <div class="col-12"><h6 class="fw-bold text-uppercase small text-warning border-bottom pb-2">Platform Security Settings</h6></div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">FORCE SECURITY PIN</label>
                            <select name="force-pin" class="form-select rounded-3">
                                <option value="0" <?php echo ($get_vendor_details['force_security_pin'] == 0) ? 'selected' : ''; ?>>No</option>
                                <option value="1" <?php echo ($get_vendor_details['force_security_pin'] == 1) ? 'selected' : ''; ?>>Yes</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">FORCE 2FA (EMAIL)</label>
                            <select name="force-2fa" class="form-select rounded-3">
                                <option value="0" <?php echo ($get_vendor_details['force_2fa'] == 0) ? 'selected' : ''; ?>>No</option>
                                <option value="1" <?php echo ($get_vendor_details['force_2fa'] == 1) ? 'selected' : ''; ?>>Yes</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">FORCE GOOGLE SSO</label>
                            <select name="force-google" class="form-select rounded-3">
                                <option value="0" <?php echo ($get_vendor_details['force_google_sso'] == 0) ? 'selected' : ''; ?>>No</option>
                                <option value="1" <?php echo ($get_vendor_details['force_google_sso'] == 1) ? 'selected' : ''; ?>>Yes</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">CRYPTO WITHDRAWAL APPROVAL</label>
                            <select name="crypto-approval" class="form-select rounded-3">
                                <option value="0" <?php echo ($get_vendor_details['crypto_withdrawal_approval'] == 0) ? 'selected' : ''; ?>>Instant</option>
                                <option value="1" <?php echo ($get_vendor_details['crypto_withdrawal_approval'] == 1) ? 'selected' : ''; ?>>Requires Admin Approval</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">NGN WITHDRAWAL APPROVAL</label>
                            <select name="approve-withdrawal" class="form-select rounded-3">
                                <option value="0" <?php echo ($get_vendor_details['approve_withdrawal'] == 0) ? 'selected' : ''; ?>>Instant</option>
                                <option value="1" <?php echo ($get_vendor_details['approve_withdrawal'] == 1) ? 'selected' : ''; ?>>Requires Admin Approval</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">TRANSACTION EMAIL</label>
                            <select name="trans-email" class="form-select rounded-3">
                                <option value="1" <?php echo ($get_vendor_details['trans_email_enabled'] ?? 1) == 1 ? 'selected' : ''; ?>>Enabled</option>
                                <option value="0" <?php echo ($get_vendor_details['trans_email_enabled'] ?? 1) == 0 ? 'selected' : ''; ?>>Disabled</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">WITHDRAWAL FEE (NGN)</label>
                            <input name="withdrawal-fee" type="number" step="0.01" value="<?php echo $get_vendor_details['withdrawal_fee']; ?>" class="form-control rounded-3" placeholder="0.00" />
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">CRYPTO SWAP FEE (%)</label>
                            <input name="crypto-swap-fee" type="number" step="0.01" value="<?php echo $get_vendor_details['crypto_swap_fee'] ?? 0; ?>" class="form-control rounded-3" placeholder="0.00" />
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">DAILY PAYOUT LIMIT (REQ COUNT)</label>
                            <input name="daily-payout-limit" type="number" value="<?php echo $get_vendor_details['daily_payout_limit'] ?? 10; ?>" class="form-control rounded-3" required />
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">MIN WITHDRAWAL (₦)</label>
                            <input name="min-withdrawal" type="number" min="100" value="<?php echo $get_vendor_details['min_withdrawal_amount'] ?? 1000; ?>" class="form-control rounded-3" required />
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">MAX WITHDRAWAL (₦)</label>
                            <input name="max-withdrawal" type="number" value="<?php echo $get_vendor_details['max_withdrawal_amount'] ?? 50000; ?>" class="form-control rounded-3" required />
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-bold">GOOGLE CLIENT ID</label>
                            <input name="google-client-id" type="text" value="<?php echo $get_vendor_details['google_client_id']; ?>" class="form-control rounded-3" placeholder="Paste Google Client ID here"/>
                        </div>
                    </div>

                    <div class="row g-4 mb-5">
                        <div class="col-12"><h6 class="fw-bold text-uppercase small text-success border-bottom pb-2">Settlement & Verification</h6></div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">SETTLEMENT BANK</label>
                            <select name="bank-code" class="form-select rounded-3">
                                <option value="">Choose Bank</option>
                                <?php
                                    $get_monnify_access_token_2 = json_decode(getSuperAdminMonnifyAccessToken(), true);
                                    if($get_monnify_access_token_2["status"] == "success"){
                                        $get_monnify_bank_lists = json_decode(getMonnifyBanks($get_monnify_access_token_2["token"]), true);
                                        if($get_monnify_bank_lists["status"] == "success"){
                                            foreach($get_monnify_bank_lists["banks"] as $bank){
                                                $selected = ($bank["code"] == $get_vendor_details["bank_code"]) ? "selected" : "";
                                                echo '<option value="'.$bank["code"].'" '.$selected.'>'.$bank["name"].'</option>';
                                            }
                                        }
                                    }
                                ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">ACCOUNT NUMBER</label>
                            <input name="account-number" type="text" value="<?php echo $get_vendor_details['account_number']; ?>" class="form-control rounded-3" pattern="[0-9]{10}"/>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">BVN (11 DIGITS)</label>
                            <input name="bvn" type="text" value="<?php echo $get_vendor_details['bvn']; ?>" class="form-control rounded-3" pattern="[0-9]{11}"/>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">NIN (11 DIGITS)</label>
                            <input name="nin" type="text" value="<?php echo $get_vendor_details['nin']; ?>" class="form-control rounded-3" pattern="[0-9]{11}"/>
                        </div>
                    </div>

                    <button name="update-profile" type="submit" class="btn btn-primary btn-lg w-100 rounded-pill fw-bold shadow-sm py-3">
                        <i class="bi bi-check2-circle me-2"></i>SAVE CHANGES
                    </button>
                </form>
            </div>
        </div>

        <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4">
            <div class="card-header bg-white py-3 border-0"><h5 class="fw-bold mb-0 text-primary"><i class="bi bi-phone me-2"></i>Mobile App Add-ons</h5></div>
            <div class="card-body p-4">
                <form method="post">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="apk_ordered" value="1" id="apk_o" <?php echo ($get_vendor_details['apk_ordered'] ?? 0) ? 'checked' : ''; ?>>
                                <label class="form-check-label small fw-bold" for="apk_o">Android APK</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="ios_ordered" value="1" id="ios_o" <?php echo ($get_vendor_details['ios_ordered'] ?? 0) ? 'checked' : ''; ?>>
                                <label class="form-check-label small fw-bold" for="ios_o">iOS App</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="playstore_ordered" value="1" id="ps_o" <?php echo ($get_vendor_details['playstore_ordered'] ?? 0) ? 'checked' : ''; ?>>
                                <label class="form-check-label small fw-bold" for="ps_o">Play Store Listing</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="sms_bridge_ordered" value="1" id="sms_o" <?php echo ($get_vendor_details['sms_bridge_ordered'] ?? 0) ? 'checked' : ''; ?>>
                                <label class="form-check-label small fw-bold" for="sms_o">PrintHub APP APK</label>
                            </div>
                        </div>
                    </div>
                    <button name="update-app-orders" type="submit" class="btn btn-primary mt-3 rounded-pill px-4 fw-bold">Update App Orders</button>
                </form>
            </div>
        </div>

        <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4">
            <div class="card-header bg-white py-3 border-0"><h5 class="fw-bold mb-0 text-success"><i class="bi bi-calendar-check me-2"></i>Manual Renewal</h5></div>
            <div class="card-body p-4">
                <form method="post">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-8">
                            <label class="form-label small fw-bold">SUBSCRIPTION EXPIRY DATE</label>
                            <input name="expiry-date" type="date" value="<?php echo $get_vendor_details['expiry_date']; ?>" class="form-control rounded-3" required/>
                        </div>
                        <div class="col-md-4">
                            <button name="update-subscription" type="submit" class="btn btn-success w-100 rounded-pill fw-bold">Update Expiry</button>
                        </div>
                    </div>
                    <div class="mt-2 small text-muted">Updating this will automatically set the vendor status to <b>Active</b>.</div>
                </form>
            </div>
        </div>

        <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4">
            <div class="card-header bg-white py-3 border-0">
                <h5 class="fw-bold mb-0 text-info"><i class="bi bi-person-badge me-2"></i>NIN Card Service</h5>
            </div>
            <div class="card-body p-4">
                <form method="post" class="d-flex align-items-center gap-3 flex-wrap">
                    <div class="flex-grow-1">
                        <h6 class="fw-bold mb-1">Enable NIN Card Service</h6>
                        <p class="text-muted small mb-0">Toggle whether this vendor's users can generate Digital NIN Slips. Normally activated by the vendor via the wallet payment, but you can grant or revoke it manually here.</p>
                    </div>
                    <div class="form-check form-switch fs-3">
                        <input class="form-check-input" type="checkbox" role="switch" name="nin_card_enabled" value="1" id="nin-card-toggle"
                            <?php echo ($get_vendor_details['nin_card_enabled'] ?? 0) ? 'checked' : ''; ?>>
                    </div>
                    <button name="toggle-nin-card" type="submit" class="btn btn-info rounded-pill px-4 fw-bold text-white">
                        <i class="bi bi-save2 me-1"></i> Save
                    </button>
                </form>
            </div>
        </div>
        
        <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4">
            <div class="card-header bg-white py-3 border-0">
                <h5 class="fw-bold mb-0" style="color:#7c3aed;"><i class="bi bi-cpu-fill me-2"></i>AI Suite Control</h5>
            </div>
            <div class="card-body p-4">
                <form method="post">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">AI FEATURE STATUS</label>
                            <div class="form-check form-switch fs-4">
                                <input class="form-check-input" type="checkbox" role="switch" name="ai_status" value="1" <?php echo ($get_vendor_details['ai_status'] ?? 0) ? 'checked' : ''; ?>>
                                <label class="form-check-label small" for="ai_status"><?php echo ($get_vendor_details['ai_status'] ?? 0) ? 'Active' : 'Inactive'; ?></label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">TOKEN BALANCE</label>
                            <input name="ai_token_balance" type="number" value="<?php echo $get_vendor_details['ai_token_balance'] ?? 0; ?>" class="form-control rounded-3" required />
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">PRICE PER 1K TOKENS (₦)</label>
                            <input name="ai_price_per_1k" type="number" step="0.01" value="<?php echo $get_vendor_details['ai_price_per_1k_tokens'] ?? 100.00; ?>" class="form-control rounded-3" required />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">ASSIGNED AI MODEL</label>
                            <select name="ai_model" class="form-select rounded-3">
                                <option value="gemini-1.5-flash" <?php echo ($get_vendor_details['ai_model_assigned'] ?? '') == 'gemini-1.5-flash' ? 'selected' : ''; ?>>Gemini 1.5 Flash (Default)</option>
                                <option value="gemini-1.5-pro" <?php echo ($get_vendor_details['ai_model_assigned'] ?? '') == 'gemini-1.5-pro' ? 'selected' : ''; ?>>Gemini 1.5 Pro (Intelligent)</option>
                                <option value="deepseek-chat" <?php echo ($get_vendor_details['ai_model_assigned'] ?? '') == 'deepseek-chat' ? 'selected' : ''; ?>>DeepSeek Chat (Balanced)</option>
                                <option value="llama3-70b-8192" <?php echo ($get_vendor_details['ai_model_assigned'] ?? '') == 'llama3-70b-8192' ? 'selected' : ''; ?>>Llama 3 70B (Powerful)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">ACTIVATION REQUEST STATUS</label>
                            <select name="ai_request_status" class="form-select rounded-3">
                                <option value="" <?php echo empty($get_vendor_details['ai_request_status']) ? 'selected' : ''; ?>>None (No Request)</option>
                                <option value="pending" <?php echo ($get_vendor_details['ai_request_status'] ?? '') == 'pending' ? 'selected' : ''; ?>>Pending Approval</option>
                                <option value="approved" <?php echo ($get_vendor_details['ai_request_status'] ?? '') == 'approved' ? 'selected' : ''; ?>>Approved</option>
                                <option value="rejected" <?php echo ($get_vendor_details['ai_request_status'] ?? '') == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                            </select>
                        </div>
                    </div>
                    <button name="update-ai-settings" type="submit" class="btn mt-4 rounded-pill px-5 fw-bold text-white" style="background:#7c3aed;">
                        <i class="bi bi-lightning-charge me-1"></i> Update AI Configuration
                    </button>
                </form>
            </div>
        </div>
        
        <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
            <div class="card-header bg-white py-3 border-0"><h5 class="fw-bold mb-0 text-danger">Reset Password</h5></div>
            <div class="card-body p-4">
                <form method="post">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">NEW PASSWORD</label>
                            <input name="new-pass" type="password" class="form-control rounded-3" required/>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">CONFIRM PASSWORD</label>
                            <input name="con-new-pass" type="password" class="form-control rounded-3" required/>
                        </div>
                    </div>
                    <button name="change-password" type="submit" class="btn btn-outline-danger mt-4 rounded-pill px-5 fw-bold">Update Password</button>
                </form>
            </div>
        </div>

    <?php }else{ ?>
        <div class="card shadow-sm border-0 rounded-4 overflow-hidden py-5">
            <div class="card-body text-center py-5">
                <img src="<?php echo $web_http_host; ?>/asset/ooops.gif" class="img-fluid mb-4" style="max-height: 200px;"/>
                <h3 class="fw-bold text-primary">Ooops!</h3>
                <p class="text-muted">Vendor account not found in our records.</p>
                <a href="Vendors.php" class="btn btn-primary px-5 rounded-pill fw-bold mt-3">Back to Vendors</a>
            </div>
        </div>
    <?php } ?>
        </div>
    </div>
  </section>
    <?php include("../func/bc-spadmin-footer.php"); ?>
    
</body>
</html>
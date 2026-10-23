<?php session_start();
    include("../func/bc-spadmin-config.php");

    $kyc_verification_array = array("bvn", "nin", "liveliness_video", "liveliness_picture", "govt_id", "proof_of_address");
    $payment_gateway_array = array("monnify", "flutterwave", "paystack", "payvessel", "payhub", "plisio");
	$payment_gateway_webhook_array = array("monnify" => $web_http_host . "/vendors-monnify.php", "flutterwave" => $web_http_host . "/vendors-flutterwave.php", "paystack" => $web_http_host . "/vendors-paystack.php", "payvessel" => $web_http_host . "/vendors-payvessel.php", "payhub" => $web_http_host . "/vendors-payhub.php", "plisio" => $web_http_host . "/users-plisio.php");
	
	if(isset($_POST["update-kyc-details"])){
        $force_kyc = isset($_POST["force_kyc"]) ? 1 : 0;
        $plisio_fee = mysqli_real_escape_string($connection_server, (float)$_POST["plisio_activation_fee"]);
        $nin_card_fee = mysqli_real_escape_string($connection_server, (float)$_POST["nin_card_activation_fee"]);
        $bvn_verify_fee = mysqli_real_escape_string($connection_server, (float)($_POST["bvn_verify_activation_fee"] ?? 5000));
        $identity_api_fee = mysqli_real_escape_string($connection_server, (float)($_POST["identity_api_activation_fee"] ?? 7000));
        $local_identity_fee = mysqli_real_escape_string($connection_server, (float)($_POST["local_identity_fee"] ?? 50));
        $local_identity_min_balance = mysqli_real_escape_string($connection_server, (float)($_POST["local_identity_min_balance"] ?? 1000));

        mysqli_query($connection_server, "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('force_kyc', '$force_kyc') ON DUPLICATE KEY UPDATE option_value='$force_kyc'");
        mysqli_query($connection_server, "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('plisio_activation_fee', '$plisio_fee') ON DUPLICATE KEY UPDATE option_value='$plisio_fee'");
        mysqli_query($connection_server, "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('nin_card_activation_fee', '$nin_card_fee') ON DUPLICATE KEY UPDATE option_value='$nin_card_fee'");
        mysqli_query($connection_server, "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('bvn_verify_activation_fee', '$bvn_verify_fee') ON DUPLICATE KEY UPDATE option_value='$bvn_verify_fee'");
        mysqli_query($connection_server, "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('identity_api_activation_fee', '$identity_api_fee') ON DUPLICATE KEY UPDATE option_value='$identity_api_fee'");
        mysqli_query($connection_server, "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('local_identity_fee', '$local_identity_fee') ON DUPLICATE KEY UPDATE option_value='$local_identity_fee'");
        mysqli_query($connection_server, "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('local_identity_min_balance', '$local_identity_min_balance') ON DUPLICATE KEY UPDATE option_value='$local_identity_min_balance'");

	// getSuperAdminOption() caches options per session; the writes above bypass the setter,
	// so clear the cache or the new KYC enforcement/rule values are not seen until re-login.
	if (isset($_SESSION['super_admin_options_cache'])) unset($_SESSION['super_admin_options_cache']);

	$verification_name = $_POST["verification-name"] ?? array();
	$verification_array_list = $kyc_verification_array;
	$ki_active_rules = 0;
	
	$json_response_encode = json_encode(["status" => "failed", "desc" => "No changes made"]);
	if(count($verification_name) > 0){
	foreach($verification_name as $index => $name){
		$each_verification_name = mysqli_real_escape_string($connection_server, trim(strip_tags($verification_name[$index])));

		if(isset($_POST["verification-status-".$each_verification_name])){
		    $each_verification_status = "1";
		    $ki_active_rules++;
		}else{
		    $each_verification_status = "2";
		}

		if(in_array($each_verification_name, $verification_array_list)){
		$get_kyc_verification_details = mysqli_query($connection_server, "SELECT * FROM sas_super_admin_kyc_verifications WHERE verification_name='$each_verification_name'");
		if(mysqli_num_rows($get_kyc_verification_details) == 1){
			mysqli_query($connection_server, "UPDATE sas_super_admin_kyc_verifications SET status='$each_verification_status' WHERE verification_name='$each_verification_name'");
			//kycverification Information Updated Successfully
			$json_response_array = array("desc" => "KYC Verification Information Updated Successfully");
			$json_response_encode = json_encode($json_response_array,true);
		}else{
			if(mysqli_num_rows($get_kyc_verification_details) == 0){
			mysqli_query($connection_server, "INSERT INTO sas_super_admin_kyc_verifications (verification_name, status) VALUES ('$each_verification_name', '$each_verification_status')");
			//kycverification Information Created Successfully
			$json_response_array = array("desc" => "KYC Verification Information Created Successfully");
			$json_response_encode = json_encode($json_response_array,true);
			}else{
			if(mysqli_num_rows($get_kyc_verification_details) > 1){
			//Duplicated Details, Contact Admin
			$json_response_array = array("desc" => "Duplicated Details, Contact Admin");
			$json_response_encode = json_encode($json_response_array,true);
			}
			}
		}
		}else{
		//cannot show the error once
		}
	}
	}else{
	if((count($verification_name) < 1)){
	//verification Field Not Available
	$json_response_array = array("desc" => "Verification Field Not Available");
	$json_response_encode = json_encode($json_response_array,true);
	}
	}
	
	$ki_msg = "KYC rules saved. Enforcement is " . ($force_kyc == 1 ? "ENABLED" : "DISABLED") . " with " . $ki_active_rules . " required verification step(s).";
	if ($force_kyc == 1 && $ki_active_rules === 0) {
		$ki_msg = "Saved - but KYC enforcement is ON while no verification step is enabled, so nothing is enforced yet. Turn on at least one step below and save again.";
	}
	$_SESSION["product_purchase_response"] = $ki_msg;
	header("Location: ".$_SERVER["REQUEST_URI"]);
	exit();
	}
	
    if(isset($_POST["update-gateway-details"])){
        $gateway_name = $_POST["gateway-name"];
        $public_key = $_POST["public-key"];
        $secret_key = $_POST["secret-key"];
        $encrypt_key = $_POST["encrypt-key"];
        $payment_percent = $_POST["payment-percent"];
        $gateway_array_list = $payment_gateway_array;
        $gateway_key_warnings = array();

        if((count($gateway_name) > 0) && (count($public_key) > 0) && (count($secret_key) > 0) && (count($public_key) == count($secret_key))){
            foreach($gateway_name as $index => $name){
                $each_gateway_name = mysqli_real_escape_string($connection_server, trim(strip_tags($gateway_name[$index])));
                $each_public_key = mysqli_real_escape_string($connection_server, trim(strip_tags($public_key[$index])));
                $each_secret_key = mysqli_real_escape_string($connection_server, trim(strip_tags($secret_key[$index])));
                $each_encrypt_key = mysqli_real_escape_string($connection_server, trim(strip_tags($encrypt_key[$index])));
                $each_payment_percent = mysqli_real_escape_string($connection_server, trim(strip_tags($payment_percent[$index])));
                
                //$each_gateway_status_unrefined = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9]+/","",trim(strip_tags( $_POST["gateway-status-".$each_gateway_name] ))));
                if(isset($_POST["gateway-status-".$each_gateway_name])){
                    $each_gateway_status = "1";
                }else{
                	$each_gateway_status = "2";
                }

                if(in_array($each_gateway_name, $gateway_array_list)){
                    $get_payment_gateway_details = mysqli_query($connection_server, "SELECT * FROM sas_super_admin_payment_gateways WHERE gateway_name='$each_gateway_name'");
                    if(mysqli_num_rows($get_payment_gateway_details) == 1){
                        $existing_sa_gateway_row = mysqli_fetch_assoc($get_payment_gateway_details);

                        // Never let a blank field erase a working key. This page is where the platform's
                        // OWN PayHub/Paystack keys live, and one save with an empty SECRET KEY box used to
                        // wipe them - every wallet funding then answered 401 "Invalid Secret Key" with
                        // nothing to explain it. Blank now means "keep what is already stored".
                        if ($each_public_key === '')  $each_public_key  = $existing_sa_gateway_row['public_key'] ?? '';
                        if ($each_secret_key === '')  $each_secret_key  = $existing_sa_gateway_row['secret_key'] ?? '';
                        if ($each_encrypt_key === '') $each_encrypt_key = $existing_sa_gateway_row['encrypt_key'] ?? '';

                        // A gateway with no secret key cannot take a single payment, so never let a save
                        // switch it ON. Keep the previous status and say what is missing.
                        if ($each_gateway_status == "1" && $each_secret_key === '') {
                            $each_gateway_status = $existing_sa_gateway_row['status'];
                            $gateway_key_warnings[] = strtoupper($each_gateway_name) . " was NOT enabled because it has no Secret Key - paste the gateway Secret Key and save again.";
                        }

                        mysqli_query($connection_server, "UPDATE sas_super_admin_payment_gateways SET public_key='$each_public_key', secret_key='$each_secret_key', encrypt_key='$each_encrypt_key', percentage='$each_payment_percent', status='$each_gateway_status' WHERE gateway_name='$each_gateway_name'");
                        //Payment Gateway Information Updated Successfully
                        $json_response_array = array("desc" => "Payment Gateway Information Updated Successfully");
                        $json_response_encode = json_encode($json_response_array,true);
                    }else{
                        if(mysqli_num_rows($get_payment_gateway_details) == 0){
                            // Same rule on create: never create a row that is enabled but has no key.
                            if ($each_gateway_status == "1" && $each_secret_key === '') {
                                $each_gateway_status = "2";
                                $gateway_key_warnings[] = strtoupper($each_gateway_name) . " was created but left DISABLED: no Secret Key was provided.";
                            }
                            mysqli_query($connection_server, "INSERT INTO sas_super_admin_payment_gateways (gateway_name, public_key, secret_key, encrypt_key, percentage, status) VALUES ('$each_gateway_name', '$each_public_key', '$each_secret_key', '$each_encrypt_key', '$each_payment_percent', '$each_gateway_status')");
                            //Payment Gateway Information Created Successfully
                            $json_response_array = array("desc" => "Payment Gateway Information Created Successfully");
                            $json_response_encode = json_encode($json_response_array,true);
                        }else{
                            if(mysqli_num_rows($get_payment_gateway_details) > 1){
                                //Duplicated Details, Contact Admin
                                $json_response_array = array("desc" => "Duplicated Details, Contact Admin");
                                $json_response_encode = json_encode($json_response_array,true);
                            }
                        }
                    }
                }else{
                    //cannot show the error once
                }
            }
        }else{
            if((count($gateway_name) < 1)){
                //Gateway Field Not Available
                $json_response_array = array("desc" => "Gateway Field Not Available");
                $json_response_encode = json_encode($json_response_array,true);
            }else{
                if((count($public_key) < 1)){
                    //Public Key Field Not Available
                    $json_response_array = array("desc" => "Public Key Field Not Available");
                    $json_response_encode = json_encode($json_response_array,true);
                }else{
                    if((count($secret_key) < 1)){
                        //Secret Key Field Not Available
                        $json_response_array = array("desc" => "Secret Key Field Not Available");
                        $json_response_encode = json_encode($json_response_array,true);
                    }else{
                    	if((count($encrypt_key) < 1)){
                    		//Encrypt Key Field Not Available
                    		$json_response_array = array("desc" => "Encrypt Key Field Not Available");
                    		$json_response_encode = json_encode($json_response_array,true);
                    	}else{
                    		if((count($payment_percent) < 1)){
                    			//Payment Percentage Field Not Available
                    			$json_response_array = array("desc" => "Payment Percentage Field Not Available");
                	    		$json_response_encode = json_encode($json_response_array,true);
                    		}else{
                    			if((count($public_key) !== count($secret_key)) || (count($secret_key) !== count($encrypt_key)) || (count($encrypt_key) !== count($payment_percent))){
                        			//Incomplete Field
                            		$json_response_array = array("desc" => "Incomplete Field");
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
        // Surface any key-safety warning from the loop above; otherwise the admin sees a plain
        // "Updated Successfully" and never learns that a gateway was left disabled for want of a key.
        if (!empty($gateway_key_warnings)) {
            $_SESSION["product_purchase_response"] = trim($json_response_decode["desc"] . " " . implode(" ", $gateway_key_warnings));
        }
        header("Location: ".$_SERVER["REQUEST_URI"]);
    }

    if(isset($_POST["update-withdrawal-gateway-details"])){
        $gateway_name = $_POST["gateway-name"];
        $public_key = $_POST["public-key"];
        $secret_key = $_POST["secret-key"];
        $encrypt_key = $_POST["encrypt-key"];
        $withdrawal_gateway_array = array("payhub", "paystack");

        if(count($gateway_name) > 0){
            foreach($gateway_name as $index => $name){
                $each_gateway_name = mysqli_real_escape_string($connection_server, trim(strip_tags($gateway_name[$index])));
                $each_public_key = mysqli_real_escape_string($connection_server, trim(strip_tags($public_key[$index])));
                $each_secret_key = mysqli_real_escape_string($connection_server, trim(strip_tags($secret_key[$index])));
                $each_encrypt_key = mysqli_real_escape_string($connection_server, trim(strip_tags($encrypt_key[$index])));
                $each_gateway_status = isset($_POST["withdrawal-status-".$each_gateway_name]) ? "1" : "2";

                if(in_array($each_gateway_name, $withdrawal_gateway_array)){
                    if (!empty($each_secret_key)) {
                        mysqli_query($connection_server, "INSERT INTO sas_bank_transfer_gateways (vendor_id, gateway_name, public_key, secret_key, encrypt_key, transfer_fee, status)
                            VALUES ('0', '$each_gateway_name', '$each_public_key', '$each_secret_key', '$each_encrypt_key', '0', '$each_gateway_status')
                            ON DUPLICATE KEY UPDATE public_key='$each_public_key', secret_key='$each_secret_key', encrypt_key='$each_encrypt_key', status='$each_gateway_status'");
                    } else {
                        mysqli_query($connection_server, "UPDATE sas_bank_transfer_gateways SET status='$each_gateway_status' WHERE vendor_id='0' AND gateway_name='$each_gateway_name'");
                    }
                }
            }
            $_SESSION["product_purchase_response"] = "Platform Withdrawal Gateway Information Updated Successfully";
        }
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit();
    }

    if(isset($_POST["update-global-identity-provider"])){
        $allowed_providers = ["monnify", "dojah", "qoreid", "smileid", "prembly"];
        $identity_provider_gateways = ["prembly", "dojah", "qoreid", "smileid"];

        $selected_provider = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["identity_provider"] ?? "monnify")));
        if (!in_array($selected_provider, $allowed_providers)) $selected_provider = "monnify";
        mysqli_query($connection_server, "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('identity_provider', '$selected_provider') ON DUPLICATE KEY UPDATE option_value='$selected_provider'");

        // Save global API keys for identity verification providers.
        // No UNIQUE key on this table, so replace each provider's rows with exactly one row.
        // A blank field keeps the value already stored.
        foreach ($identity_provider_gateways as $gw_name) {
            $pub_in = trim(strip_tags($_POST["idp_public_".$gw_name] ?? ""));
            $sec_in = trim(strip_tags($_POST["idp_secret_".$gw_name] ?? ""));

            $existing = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT * FROM sas_super_admin_payment_gateways WHERE gateway_name='$gw_name' ORDER BY date DESC LIMIT 1"));
            if ($pub_in === '' && $existing) $pub_in = (string)($existing['public_key'] ?? '');
            if ($sec_in === '' && $existing) $sec_in = (string)($existing['secret_key'] ?? '');
            if ($pub_in === '' && $sec_in === '') continue;

            $pub = mysqli_real_escape_string($connection_server, $pub_in);
            $sec = mysqli_real_escape_string($connection_server, $sec_in);

            mysqli_query($connection_server, "DELETE FROM sas_super_admin_payment_gateways WHERE gateway_name='$gw_name'");
            mysqli_query($connection_server, "INSERT INTO sas_super_admin_payment_gateways (gateway_name, public_key, secret_key, encrypt_key, percentage, status) VALUES ('$gw_name', '$pub', '$sec', '', '0', '1')");
        }
        $_SESSION["product_purchase_response"] = "Global Identity Verification Provider Updated Successfully";
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit();
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
      <h1>PAYMENT GATEWAYS</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="#">Home</a></li>
          <li class="breadcrumb-item active">Payment Gateways</li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
      <div class="row g-4 justify-content-center">
        <div class="col-lg-10">

            <!-- Vendor KYC Card -->
            <div class="card shadow-sm border-0 rounded-4 mb-4">
                <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold mb-0 text-primary">Vendor KYC Enforcement</h5>
                    <i class="bi bi-shield-check text-muted fs-4"></i>
                </div>
                <div class="card-body p-4">
                    <form method="post">
                        <div class="row g-4 mb-4">
                            <div class="col-md-12">
                                <?php
                                    $q_opt = mysqli_query($connection_server, "SELECT option_value FROM sas_super_admin_options WHERE option_name='force_kyc'");
                                    $force_kyc_val = ($q_opt && mysqli_num_rows($q_opt) > 0) ? mysqli_fetch_assoc($q_opt)['option_value'] : '0';

                                    $q_pfee = mysqli_query($connection_server, "SELECT option_value FROM sas_super_admin_options WHERE option_name='plisio_activation_fee'");
                                    $plisio_fee_val = ($q_pfee && mysqli_num_rows($q_pfee) > 0) ? mysqli_fetch_assoc($q_pfee)['option_value'] : '10000';

                                    $ki_rules_q = mysqli_query($connection_server, "SELECT COUNT(*) c FROM sas_super_admin_kyc_verifications WHERE status='1'");
                                    $ki_active_rules = (int)(($ki_rules_q ? mysqli_fetch_assoc($ki_rules_q)['c'] : 0));
                                    $ki_effective = ($force_kyc_val == 1 && $ki_active_rules > 0);
                                ?>
                                <div class="p-3 border border-primary rounded-4 bg-primary bg-opacity-10">
                                    <div class="d-flex align-items-center justify-content-between gap-3">
                                        <div>
                                            <div class="d-flex align-items-center gap-2 mb-1">
                                                <h6 class="fw-bold mb-0 text-dark-primary"><i class="bi bi-shield-lock me-1"></i>Super Admin KYC Enforcement</h6>
                                                <?php if ($ki_effective): ?>
                                                    <span class="badge bg-success">ACTIVE</span>
                                                <?php elseif ($force_kyc_val == 1): ?>
                                                    <span class="badge bg-warning text-dark">ON - NO STEPS</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">OFF</span>
                                                <?php endif; ?>
                                            </div>
                                            <p class="small text-dark-primary mb-0" style="opacity: 0.85;">Force all users and vendors (bc-admins) to verify their identity platform-wide. <strong>Requires at least one verification step enabled below</strong> to take effect.</p>
                                        </div>
                                        <div class="form-check form-switch flex-shrink-0">
                                            <input class="form-check-input fs-3" type="checkbox" role="switch" name="force_kyc" value="1" id="kiForceKyc" <?php echo ($force_kyc_val == 1) ? 'checked' : ''; ?>>
                                        </div>
                                    </div>
                                </div>
                                <?php if ($force_kyc_val == 1 && $ki_active_rules === 0): ?>
                                <div class="alert alert-warning border-0 rounded-3 mt-2 small mb-0">
                                    <i class="bi bi-exclamation-triangle-fill me-2"></i><strong>Enforcement is ON but nothing is enforced yet.</strong> Enable at least one verification step below, then save.
                                </div>
                                <?php elseif ($force_kyc_val == 1): ?>
                                <div class="alert alert-success border-0 rounded-3 mt-2 small mb-0">
                                    <i class="bi bi-check-circle-fill me-2"></i><strong>KYC enforcement is active</strong> with <?php echo $ki_active_rules; ?> required step(s). Vendors must complete them under <em>Account Settings &rarr; KYC</em> before accessing the management website.
                                </div>
                                <?php endif; ?>
                            </div>

                            <div class="col-12">
                                <h6 class="fw-bold text-dark mb-0 border-bottom pb-2"><i class="bi bi-cash-coin me-2"></i>Service Activation Fees</h6>
                            </div>

                            <div class="col-md-12">
                                <div class="p-4 border rounded-4 bg-light shadow-sm">
                                    <h6 class="fw-bold text-dark mb-2">Plisio Activation Fee (NGN)</h6>
                                    <div class="input-group">
                                        <span class="input-group-text bg-white border-0">₦</span>
                                        <input name="plisio_activation_fee" type="number" step="0.01" class="form-control form-control-lg border-0 bg-white" value="<?php echo $plisio_fee_val; ?>" placeholder="10000.00">
                                    </div>
                                    <p class="small text-muted mt-2 mb-0">The one-time fee vendors must pay to unlock the Plisio (Crypto) payment gateway in their admin panel.</p>
                                </div>
                            </div>

                            <div class="col-md-12">
                                <?php
                                    $q_nin_act_fee = mysqli_query($connection_server, "SELECT option_value FROM sas_super_admin_options WHERE option_name='nin_card_activation_fee'");
                                    $nin_card_fee_val = ($q_nin_act_fee && mysqli_num_rows($q_nin_act_fee) > 0) ? mysqli_fetch_assoc($q_nin_act_fee)['option_value'] : '5000';
                                ?>
                                <div class="p-4 border rounded-4 bg-light shadow-sm">
                                    <h6 class="fw-bold text-dark mb-2">NIN Card Service Activation Fee (NGN)</h6>
                                    <div class="input-group">
                                        <span class="input-group-text bg-white border-0">₦</span>
                                        <input name="nin_card_activation_fee" type="number" step="0.01" class="form-control form-control-lg border-0 bg-white" value="<?php echo $nin_card_fee_val; ?>" placeholder="5000.00">
                                    </div>
                                    <p class="small text-muted mt-2 mb-0">The one-time fee vendors (bc-admins) must pay to unlock the NIN Card / Digital NIN Slip service for their users.</p>
                                </div>
                            </div>

                            <div class="col-md-12">
                                <?php
                                    $q_bvn_act_fee = mysqli_query($connection_server, "SELECT option_value FROM sas_super_admin_options WHERE option_name='bvn_verify_activation_fee'");
                                    $bvn_verify_fee_val = ($q_bvn_act_fee && mysqli_num_rows($q_bvn_act_fee) > 0) ? mysqli_fetch_assoc($q_bvn_act_fee)['option_value'] : '5000';
                                ?>
                                <div class="p-4 border rounded-4 bg-light shadow-sm">
                                    <h6 class="fw-bold text-dark mb-2">BVN Verification Service Activation Fee (NGN)</h6>
                                    <div class="input-group">
                                        <span class="input-group-text bg-white border-0">₦</span>
                                        <input name="bvn_verify_activation_fee" type="number" step="0.01" class="form-control form-control-lg border-0 bg-white" value="<?php echo $bvn_verify_fee_val; ?>" placeholder="5000.00">
                                    </div>
                                    <p class="small text-muted mt-2 mb-0">The one-time fee vendors (bc-admins) must pay to unlock the BVN Verification service for their users.</p>
                                </div>
                            </div>

                            <div class="col-md-12">
                                <?php
                                    $q_idp_act_fee = mysqli_query($connection_server, "SELECT option_value FROM sas_super_admin_options WHERE option_name='identity_api_activation_fee'");
                                    $identity_api_fee_val = ($q_idp_act_fee && mysqli_num_rows($q_idp_act_fee) > 0) ? mysqli_fetch_assoc($q_idp_act_fee)['option_value'] : '7000';
                                ?>
                                <div class="p-4 border rounded-4 bg-light shadow-sm">
                                    <h6 class="fw-bold text-dark mb-2">Identity API Access Fee (NGN)</h6>
                                    <div class="input-group">
                                        <span class="input-group-text bg-white border-0">₦</span>
                                        <input name="identity_api_activation_fee" type="number" step="0.01" class="form-control form-control-lg border-0 bg-white" value="<?php echo $identity_api_fee_val; ?>" placeholder="7000.00">
                                    </div>
                                    <p class="small text-muted mt-2 mb-0">The one-time fee vendors (bc-admins) must pay from their wallet to unlock the <strong>premium</strong> identity providers (Monnify, Dojah, QoreID, Smile Identity). The Local Marketplace (vendor-to-vendor) API is free.</p>
                                </div>
                            </div>

                            <div class="col-md-12">
                                <?php
                                    $q_local_fee = mysqli_query($connection_server, "SELECT option_value FROM sas_super_admin_options WHERE option_name='local_identity_fee'");
                                    $local_fee_val = ($q_local_fee && mysqli_num_rows($q_local_fee) > 0) ? mysqli_fetch_assoc($q_local_fee)['option_value'] : '50';
                                ?>
                                <div class="p-4 border rounded-4 bg-light shadow-sm">
                                    <h6 class="fw-bold text-dark mb-2">Local Marketplace Verification Fee (NGN)</h6>
                                    <div class="input-group">
                                        <span class="input-group-text bg-white border-0">₦</span>
                                        <input name="local_identity_fee" type="number" step="0.01" min="0" class="form-control form-control-lg border-0 bg-white" value="<?php echo $local_fee_val; ?>" placeholder="50.00">
                                    </div>
                                    <p class="small text-muted mt-2 mb-0">Charged to the vendor's wallet for <strong>each successful verification</strong> done through the free Local Marketplace (vendor-to-vendor) API. Set 0 to make it free.</p>
                                </div>
                            </div>

                            <div class="col-md-12">
                                <?php
                                    $q_local_min = mysqli_query($connection_server, "SELECT option_value FROM sas_super_admin_options WHERE option_name='local_identity_min_balance'");
                                    $local_min_val = ($q_local_min && mysqli_num_rows($q_local_min) > 0) ? mysqli_fetch_assoc($q_local_min)['option_value'] : '1000';
                                ?>
                                <div class="p-4 border rounded-4 bg-light shadow-sm">
                                    <h6 class="fw-bold text-dark mb-2">Local Marketplace Minimum Vendor Balance (NGN)</h6>
                                    <div class="input-group">
                                        <span class="input-group-text bg-white border-0">₦</span>
                                        <input name="local_identity_min_balance" type="number" step="0.01" min="0" class="form-control form-control-lg border-0 bg-white" value="<?php echo $local_min_val; ?>" placeholder="1000.00">
                                    </div>
                                    <p class="small text-muted mt-2 mb-0">A vendor must hold at least this wallet balance to use the Local Marketplace (vendor-to-vendor) identity API.</p>
                                </div>
                            </div>

                            <div class="col-12 mt-2">
                                <h6 class="fw-bold text-dark mb-0 border-bottom pb-2"><i class="bi bi-list-check me-2"></i>Required Verification Steps</h6>
                            </div>

                            <?php foreach($kyc_verification_array as $verification_name):
                                $get_verification_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_super_admin_kyc_verifications WHERE verification_name='$verification_name'"));
                                $is_active = ($get_verification_details && $get_verification_details["status"] == 1);

                                $kyc_names_map = [
                                    'govt_id' => 'Government ID Card',
                                    'bvn' => 'BVN Number',
                                    'nin' => 'NIN Number',
                                    'proof_of_address' => 'Proof of Address Document'
                                ];
                                $friendly_name = isset($kyc_names_map[$verification_name]) ? $kyc_names_map[$verification_name] : ucwords(str_replace("_", " ", $verification_name));
                            ?>
                            <div class="col-md-6">
                                <div class="p-3 border rounded-4 bg-light d-flex align-items-center justify-content-between">
                                    <div>
                                        <h6 class="fw-bold mb-1 text-uppercase"><?php echo $friendly_name; ?></h6>
                                        <p class="small text-muted mb-0"><?php echo $is_active ? 'Currently Forced' : 'Currently Optional'; ?></p>
                                    </div>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input fs-4" type="checkbox" role="switch" name="verification-status-<?php echo $verification_name; ?>" value="1" <?php echo $is_active ? 'checked' : ''; ?>>
                                        <input name="verification-name[]" value="<?php echo $verification_name; ?>" hidden />
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 pt-3 border-top">
                            <span class="small text-muted"><i class="bi bi-info-circle me-1"></i>Changes apply immediately after saving.</span>
                            <button name="update-kyc-details" type="submit" class="btn btn-primary px-5 rounded-pill fw-bold"><i class="bi bi-save2 me-2"></i>Save KYC Rules</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Global Identity Verification Provider Card -->
            <div class="card shadow-sm border-0 rounded-4 mb-4">
                <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold mb-0 text-primary">Global Identity Verification Provider</h5>
                    <i class="bi bi-person-badge text-muted fs-4"></i>
                </div>
                <div class="card-body p-4">
                    <div class="alert alert-info border-0 rounded-4 small mb-4">
                        Configure which identity verification provider is used globally for BVN/NIN verification. Individual vendors may override this with their own provider in their Payment Gateway settings.
                    </div>
                    <form method="post">
                        <?php
                        $q_gidp = mysqli_query($connection_server, "SELECT option_value FROM sas_super_admin_options WHERE option_name='identity_provider'");
                        $global_idp = ($q_gidp && mysqli_num_rows($q_gidp) > 0) ? mysqli_fetch_assoc($q_gidp)['option_value'] : 'monnify';
                        $idp_providers_g = [
                            "monnify" => "Monnify (uses Monnify BVN/NIN endpoints)",
                            "prembly" => "Prembly (IdentityPass)",
                            "dojah"   => "Dojah",
                            "qoreid"  => "QoreID (VerifyMe)",
                            "smileid" => "Smile Identity",
                        ];
                        $idp_gateways_g = ["prembly", "dojah", "qoreid", "smileid"];
                        $idp_key_labels_g = [
                            "prembly"=> ["public_key" => "App ID (optional)", "secret_key" => "Secret Key"],
                            "dojah"  => ["public_key" => "App ID", "secret_key" => "Private Key"],
                            "qoreid" => ["public_key" => "Client ID", "secret_key" => "Client Secret"],
                            "smileid"=> ["public_key" => "Partner ID", "secret_key" => "API Key"],
                        ];
                        ?>
                        <div class="mb-4">
                            <label class="form-label fw-bold small">Default Provider</label>
                            <select name="identity_provider" class="form-select" id="g-idp-select">
                                <?php foreach ($idp_providers_g as $val => $label): ?>
                                <option value="<?php echo $val; ?>" <?php echo ($global_idp === $val) ? 'selected' : ''; ?>><?php echo $label; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php foreach ($idp_gateways_g as $gw): ?>
                        <?php
                            $gw_row_g = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT * FROM sas_super_admin_payment_gateways WHERE gateway_name='$gw' LIMIT 1"));
                            $gw_pub_g = $gw_row_g['public_key'] ?? '';
                            $gw_sec_g = $gw_row_g['secret_key'] ?? '';
                            $pub_label_g = $idp_key_labels_g[$gw]['public_key'];
                            $sec_label_g = $idp_key_labels_g[$gw]['secret_key'];
                            $gw_display_g = ($gw === 'smileid') ? 'Smile Identity' : ($gw === 'prembly' ? 'Prembly (IdentityPass)' : ucwords($gw));
                        ?>
                        <div class="p-3 border rounded-4 mb-3 g-idp-keys-block" id="g-idp-keys-<?php echo $gw; ?>" style="display:<?php echo ($global_idp === $gw) ? '' : 'none'; ?>">
                            <h6 class="fw-bold mb-3"><?php echo $gw_display_g; ?> API Keys</h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold"><?php echo $pub_label_g; ?></label>
                                    <input type="text" name="idp_public_<?php echo $gw; ?>" class="form-control" value="<?php echo htmlspecialchars($gw_pub_g); ?>" placeholder="Enter <?php echo $pub_label_g; ?>" />
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold"><?php echo $sec_label_g; ?></label>
                                    <input type="password" name="idp_secret_<?php echo $gw; ?>" class="form-control" value="<?php echo htmlspecialchars($gw_sec_g); ?>" placeholder="Enter <?php echo $sec_label_g; ?>" />
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <button name="update-global-identity-provider" type="submit" class="btn btn-primary px-5 rounded-pill fw-bold">Save Global Provider</button>
                    </form>
                    <script>
                    (function(){
                        var sel = document.getElementById('g-idp-select');
                        var blocks = document.querySelectorAll('.g-idp-keys-block');
                        function toggleBlocks(){
                            blocks.forEach(function(b){
                                b.style.display = b.id === 'g-idp-keys-'+sel.value ? '' : 'none';
                            });
                        }
                        sel.addEventListener('change', toggleBlocks);
                    })();
                    </script>
                </div>
            </div>

            <!-- Platform Withdrawal Gateways Card -->
            <div class="card shadow-sm border-0 rounded-4 mb-4">
                <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold mb-0 text-primary">Platform Withdrawal Gateways</h5>
                    <i class="bi bi-bank text-muted fs-4"></i>
                </div>
                <div class="card-body p-4">
                    <h6 class="fw-bold text-dark mb-4"><i class="bi bi-key me-2 text-primary"></i>Platform Isolated Payout Credentials</h6>
                    <p class="small text-muted mb-4">Set independent credentials for the platform's withdrawal provider. Vendors will fallback to these keys if they do not configure their own isolated payout credentials.</p>

                    <form method="post">
                        <div class="row g-4">
                            <?php
                            $withdrawal_gateways = ["payhub", "paystack"];
                            foreach($withdrawal_gateways as $wg_name):
                                $q_wg = mysqli_query($connection_server, "SELECT * FROM sas_bank_transfer_gateways WHERE vendor_id='0' && gateway_name='$wg_name'");
                                $wg_data = mysqli_fetch_assoc($q_wg);
                                $wg_active = ($wg_data && $wg_data['status'] == 1);
                            ?>
                            <div class="col-md-6">
                                <div class="p-4 border rounded-4 bg-light bg-opacity-50 shadow-none">
                                    <div class="d-flex justify-content-between align-items-center mb-4">
                                        <h6 class="fw-bold mb-0 text-uppercase"><?php echo $wg_name; ?> PAYOUT API</h6>
                                        <div class="form-check form-switch">
                                            <input class="form-check-input fs-5" type="checkbox" role="switch" name="withdrawal-status-<?php echo $wg_name; ?>" value="1" <?php echo $wg_active ? 'checked' : ''; ?>>
                                            <input name="gateway-name[]" value="<?php echo $wg_name; ?>" hidden />
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label small fw-bold text-muted">PUBLIC KEY</label>
                                        <input name="public-key[]" type="text" value="<?php echo $wg_data['public_key'] ?? ''; ?>" class="form-control rounded-3" placeholder="Enter Public Key" />
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label small fw-bold text-muted">SECRET KEY</label>
                                        <input name="secret-key[]" type="password" value="<?php echo $wg_data['secret_key'] ?? ''; ?>" class="form-control rounded-3" placeholder="Enter Secret Key" />
                                    </div>
                                    <div class="mb-0">
                                        <label class="form-label small fw-bold text-muted"><?php echo ($wg_name == 'paystack') ? 'NOT REQUIRED' : 'ENCRYPT KEY / APP ID'; ?></label>
                                        <input name="encrypt-key[]" type="text" value="<?php echo $wg_data['encrypt_key'] ?? ''; ?>" class="form-control rounded-3" placeholder="Optional" />
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="mt-4">
                            <button name="update-withdrawal-gateway-details" type="submit" class="btn btn-dark rounded-pill px-5 fw-bold shadow-sm">
                                <i class="bi bi-save2 me-2"></i>Save Platform Payout Keys
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Payment Gateways Grid -->
            <div class="card shadow-sm border-0 rounded-4">
                <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold mb-0 text-primary">Super Admin Gateways</h5>
                    <div class="badge bg-primary bg-opacity-10 text-dark-primary border border-primary border-opacity-25 rounded-pill px-3">System Auto-Funding</div>
                </div>
                <div class="card-body p-4">
                    <form method="post">
                        <div class="row g-4">
                            <?php foreach($payment_gateway_array as $gateway_name):
                                $get_gateway_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_super_admin_payment_gateways WHERE gateway_name='$gateway_name'"));
                                $is_enabled = ($get_gateway_details && $get_gateway_details["status"] == 1);
                            ?>
                            <div class="col-md-6">
                                <div class="card border rounded-4 h-100 mb-0 shadow-none bg-light bg-opacity-50">
                                    <div class="card-body p-4">
                                        <div class="d-flex justify-content-between align-items-center mb-4">
                                            <div class="d-flex align-items-center">
                                                <div class="bg-white rounded-3 p-2 me-3 shadow-sm">
                                                    <i class="bi bi-wallet2 text-primary fs-5"></i>
                                                </div>
                                                <h6 class="fw-bold mb-0 text-uppercase"><?php echo $gateway_name; ?></h6>
                                            </div>
                                            <div class="form-check form-switch">
                                                <input class="form-check-input fs-5" type="checkbox" role="switch" name="gateway-status-<?php echo $gateway_name; ?>" value="1" <?php echo $is_enabled ? 'checked' : ''; ?>>
                                                <input name="gateway-name[]" value="<?php echo $gateway_name; ?>" hidden />
                                            </div>
                                        </div>

                                        <div class="mb-3" <?php if($gateway_name == 'plisio') echo 'style="display:none;"'; ?>>
                                            <label class="form-label small fw-bold text-muted">PUBLIC KEY</label>
                                            <input name="public-key[]" type="text" value="<?php echo $get_gateway_details["public_key"]; ?>" class="form-control rounded-3" placeholder="<?php echo ($gateway_name == 'plisio') ? 'Not Required' : 'Enter Public Key'; ?>" />
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label small fw-bold text-muted">SECRET KEY</label>
                                            <input name="secret-key[]" type="password" value="<?php echo $get_gateway_details["secret_key"]; ?>" class="form-control rounded-3" placeholder="Enter Secret Key" />
                                        </div>
                                        <div class="row g-3 mb-3">
                                            <div class="col-6" <?php if($gateway_name == 'plisio') echo 'style="display:none;"'; ?>>
                                                <label class="form-label small fw-bold text-muted"><?php echo ($gateway_name === 'monnify') ? 'CONTRACT CODE' : 'ENCRYPT KEY'; ?></label>
                                                <input name="encrypt-key[]" type="text" value="<?php echo $get_gateway_details["encrypt_key"]; ?>" class="form-control rounded-3" placeholder="<?php echo ($gateway_name === 'monnify') ? 'Enter Contract Code' : ($gateway_name == 'plisio' ? 'Not Required' : 'Optional'); ?>" />
                                            </div>
                                            <div class="<?php echo ($gateway_name == 'plisio') ? 'col-12' : 'col-6'; ?>">
                                                <label class="form-label small fw-bold text-muted">DISCOUNT (%)</label>
                                                <input name="payment-percent[]" type="number" step="0.001" value="<?php echo $get_gateway_details["percentage"]; ?>" class="form-control rounded-3" placeholder="0.000" />
                                            </div>
                                        </div>
                                        <div>
                                            <label class="form-label small fw-bold text-muted">WEBHOOK URL</label>
                                            <div class="input-group input-group-sm">
                                                <input type="text" value="<?php echo $payment_gateway_webhook_array[$gateway_name]; ?>" class="form-control bg-white border-0 shadow-sm" readonly />
                                                <button class="btn btn-outline-secondary border-0" type="button" onclick="navigator.clipboard.writeText('<?php echo $payment_gateway_webhook_array[$gateway_name]; ?>')"><i class="bi bi-clipboard"></i></button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="mt-5 text-center">
                            <button name="update-gateway-details" type="submit" class="btn btn-primary btn-lg px-5 rounded-pill fw-bold shadow-sm">
                                <i class="bi bi-save2 me-2"></i>Save Gateway Configurations
                            </button>
                        </div>
                    </form>
                </div>
            </div>

        </div>
      </div>
    </section>
    
    <?php include("../func/bc-spadmin-footer.php"); ?>
    
</body>
</html>
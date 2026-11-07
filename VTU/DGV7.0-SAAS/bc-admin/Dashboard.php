<?php session_start();
    include("../func/bc-admin-config.php");
    
    $select_vendor_super_admin_status_message = mysqli_query($connection_server, "SELECT * FROM sas_super_admin_status_messages");
    if(mysqli_num_rows($select_vendor_super_admin_status_message) == 1){
    	$get_vendor_super_admin_status_message = mysqli_fetch_array($select_vendor_super_admin_status_message);
    	if(!isset($_SESSION["product_purchase_response"]) && isset($_SESSION["admin_session"])){
    		$vendor_super_admin_status_message_template_encoded_text_array = array("{firstname}" => $get_logged_admin_details["firstname"]);
    		foreach($vendor_super_admin_status_message_template_encoded_text_array as $array_key => $array_val){
    			$vendor_super_admin_status_message_template_text = str_replace($array_key, $array_val, $get_vendor_super_admin_status_message["message"]);
    		}
    		$_SESSION["product_purchase_response"] = str_replace("\n","<br/>",$vendor_super_admin_status_message_template_text);
    	}
    }
    
    if(isset($_POST["buy-addon"])){
        $addon_key = mysqli_real_escape_string($connection_server, trim($_POST["addon-key"]));
        $addon_config = [
            'apk' => ['price_opt' => 'apk_development_price', 'col' => 'apk_ordered', 'label' => 'Android APK'],
            'ios' => ['price_opt' => 'ios_development_price', 'col' => 'ios_ordered', 'label' => 'iOS App'],
            'playstore' => ['price_opt' => 'playstore_listing_price', 'col' => 'playstore_ordered', 'label' => 'Play Store Listing'],
            'sms_bridge' => ['price_opt' => 'sms_bridge_price', 'col' => 'sms_bridge_ordered', 'label' => 'PrintHub APP APK']
        ];

        if(isset($addon_config[$addon_key])){
            $conf = $addon_config[$addon_key];
            if($get_logged_admin_details[$conf['col']] == 0){
                $price = (float)getSuperAdminOption($conf['price_opt'], '0');
                if($get_logged_admin_details['balance'] >= $price){
                    $ref = "ADDON_".time().rand(10,99);
                    $desc = "Order for " . $conf['label'] . " (One-Off)";
                    $charge = chargeVendor("debit", $addon_key."_order", $conf['label'], $ref, $price, $price, $desc, $_SERVER["HTTP_HOST"], 1);
                    if($charge === "success"){
                        mysqli_query($connection_server, "UPDATE sas_vendors SET ".$conf['col']." = 1 WHERE id='".$get_logged_admin_details['id']."'");

                        // Optimization: Clear Super Admin dashboard cache so they see the new revenue
                        mysqli_query($connection_server, "DELETE FROM sas_dashboard_cache WHERE vendor_id=0 AND cache_key='platform_stats'");

                        // Notify Super Admin
                        $get_spadmin = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT email FROM sas_super_admin LIMIT 1"));
                        $sa_email = $get_spadmin['email'] ?? 'admin@philmorecodes.com';
                        sendVendorEmail($sa_email, "URGENT: New Addon Order - ".$conf['label'], "Dear Super Admin,<br><br>Vendor <b>".$get_logged_admin_details['email']."</b> has ordered the <b>".$conf['label']."</b> add-on.<br><br>Please check the Vendor Edit page to process this order.");

                        $_SESSION["product_purchase_response"] = $conf['label']." ordered successfully!";
                    } else {
                        $_SESSION["product_purchase_response"] = "Failed to process payment. Please try again.";
                    }
                } else {
                    $_SESSION["product_purchase_response"] = "Insufficient balance to order this addon.";
                }
            } else {
                $_SESSION["product_purchase_response"] = "You have already ordered this addon.";
            }
        }
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit();
    }

    if(isset($_POST["pay-bill"])){
        $purchase_method = "web";
        $purchase_method = strtoupper($purchase_method);
        $purchase_method_array = array("WEB");
        
        if(in_array($purchase_method, $purchase_method_array)){
            if($purchase_method === "WEB"){
                $bill_id = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["bill-id"]))));
            }
            
            if(!empty($bill_id)){
                if(is_numeric($bill_id)){
                    $get_bill_details = mysqli_query($connection_server, "SELECT * FROM sas_vendor_billings WHERE id='".$bill_id."'");
                    if(mysqli_num_rows($get_bill_details) == 1){
                    	$check_if_bill_is_paid = mysqli_query($connection_server, "SELECT * FROM sas_vendor_paid_bills WHERE vendor_id='".$get_logged_admin_details["id"]."' && bill_id='".$bill_id."'");
                    	if(mysqli_num_rows($check_if_bill_is_paid) == 0){
                        	$bill_amount = mysqli_fetch_array($get_bill_details);
                        	if(!empty($bill_amount["amount"]) && is_numeric($bill_amount["amount"]) && ($bill_amount["amount"] > 0)){
                            	if(!empty(vendorBalance(1)) && is_numeric(vendorBalance(1)) && (vendorBalance(1) > 0)){
                                	$amount = $bill_amount["amount"];
                                	$discounted_amount = $amount;
                                	$type_alternative = ucwords($bill_amount["bill_type"]);
                                	$reference = substr(str_shuffle("12345678901234567890"), 0, 15);
                                	$description = ucwords(checkTextEmpty($bill_amount["description"])." - Bill charges");
                                	$status = 1;
                                
                                	$debit_vendor = chargeVendor("debit", $bill_amount["bill_type"], $type_alternative, $reference, $amount, $discounted_amount, $description, $_SERVER["HTTP_HOST"], $status);
                                	if($debit_vendor === "success"){
                                    	$add_vendor_paid_bill_details = mysqli_query($connection_server, "INSERT INTO sas_vendor_paid_bills (vendor_id, bill_id, bill_type, description, amount, starting_date, ending_date) VALUES ('".$get_logged_admin_details["id"]."', '".$bill_amount["id"]."', '".$bill_amount["bill_type"]."', '".$bill_amount["description"]."', '$amount', '".$bill_amount["starting_date"]."','".$bill_amount["ending_date"]."')");
                                    	if($add_vendor_paid_bill_details == true){
                                        	//Account ... Bill Successfully
                                        	$json_response_array = array("desc" => "Account ".ucwords($bill_amount["bill_type"])." Bill Successfully");
                                        	$json_response_encode = json_encode($json_response_array,true);
                                    	}else{
                                        	$reference_2 = substr(str_shuffle("12345678901234567890"), 0, 15);
                                        	chargeVendor("credit", $bill_amount["bill_type"], "Refund", $reference_2, $amount, $discounted_amount, "Refund for Ref:<i>'$reference'</i>", $_SERVER["HTTP_HOST"], "1");
                                        	//Bill Failed, Contact Admin
                                        	$json_response_array = array("desc" => "Bill Failed, Contact Admin");
                                        	$json_response_encode = json_encode($json_response_array,true);
                                    	}
                                	}else{
                                    	//Insufficient Fund
                                    	$json_response_array = array("desc" => "Insufficient Fund");
                                    	$json_response_encode = json_encode($json_response_array,true);
                                	}
                            	}else{
                                	//Balance is LOW
                                	$json_response_array = array("desc" => "Balance is LOW");
                                	$json_response_encode = json_encode($json_response_array,true);
                            	}
                        	}else{
                            	//Pricing Error, Contact Admin
                            	$json_response_array = array("desc" => "Pricing Error, Contact Admin");
                            	$json_response_encode = json_encode($json_response_array,true);
                        	}
                        }else{
                        	//Bill Has Already Been Paid
                        	$json_response_array = array("desc" => "Bill Has Already Been Paid");
                        	$json_response_encode = json_encode($json_response_array,true);
                        }
                    }else{
                        //Error: Billing Details Not Exists, Contact Admin
                        $json_response_array = array("desc" => "Error: Billing Details Not Exists, Contact Admin");
                        $json_response_encode = json_encode($json_response_array,true);
                    }
                }else{
                    //Non-numeric Bill ID
                    $json_response_array = array("desc" => "Non-numeric Bill ID");
                    $json_response_encode = json_encode($json_response_array,true);
                }
            }else{
                //Bill Field Empty
                $json_response_array = array("desc" => "Bill Field Empty");
                $json_response_encode = json_encode($json_response_array,true);
            }
        }
        $json_response_decode = json_decode($json_response_encode,true);
        $_SESSION["product_purchase_response"] = $json_response_decode["desc"];
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit;
    }
	

	if((!empty($get_logged_admin_details["bank_code"]) && is_numeric($get_logged_admin_details["bank_code"]) && !empty($get_logged_admin_details["bvn"]) && is_numeric($get_logged_admin_details["bvn"]) && strlen($get_logged_admin_details["bvn"]) == 11) || (!empty($get_logged_admin_details["bank_code"]) && is_numeric($get_logged_admin_details["bank_code"]) && !empty($get_logged_admin_details["nin"]) && is_numeric($get_logged_admin_details["nin"]) && strlen($get_logged_admin_details["nin"]) == 11)){
		$virtual_account_vaccount_err = "";
		$bvn_nin_monnify_account_creation = [];
		$bvn_nin_payvessel_account_creation = [];
		if((!empty($get_logged_admin_details["bvn"]) && is_numeric($get_logged_admin_details["bvn"]) && strlen($get_logged_admin_details["bvn"]) == 11) && (!empty($get_logged_admin_details["nin"]) && is_numeric($get_logged_admin_details["nin"]) && strlen($get_logged_admin_details["nin"]) == 11)){
			$verification_type = 1;
			$bvn_nin_monnify_account_creation = ["bvn" => $get_logged_admin_details["bvn"], "nin" => $get_logged_admin_details["nin"]];
			$bvn_nin_payvessel_account_creation = ["bvn" => $get_logged_admin_details["bvn"]];
		}else{
			if((!empty($get_logged_admin_details["bvn"]) && is_numeric($get_logged_admin_details["bvn"]) && strlen($get_logged_admin_details["bvn"]) == 11)){
				$verification_type = 1;
				$bvn_nin_monnify_account_creation = ["bvn" => $get_logged_admin_details["bvn"]];
				$bvn_nin_payvessel_account_creation = ["bvn" => $get_logged_admin_details["bvn"]];
			}else{
				if((!empty($get_logged_admin_details["nin"]) && is_numeric($get_logged_admin_details["nin"]) && strlen($get_logged_admin_details["nin"]) == 11)){
					$verification_type = 2;
					$bvn_nin_monnify_account_creation = ["nin" => $get_logged_admin_details["nin"]];
				}
			}
		}
		
		$registered_virtual_bank_arr = array();
		$virtual_bank_code_arr = array("232", "035", "50515", "120001", "PayHub");
		if(is_array(getVendorVirtualBank()) == true){
			foreach(getVendorVirtualBank() as $bank_json){
				$bank_json = json_decode($bank_json, true);
				array_push($registered_virtual_bank_arr, $bank_json["bank_code"]);
			}
		}

        // PayHub Sync for Vendors
        // Branch DG6.7 Optimization: Throttled sync to prevent slow dashboard loads
        if (!in_array("PayHub", $registered_virtual_bank_arr)) {
            $last_payhub_sync = $_SESSION['last_payhub_sync_admin'] ?? 0;
            if (time() - $last_payhub_sync > 3600) {
                syncPayhubVirtualAccounts($get_logged_admin_details['id'], $get_logged_admin_details['email'], true);
                $_SESSION['last_payhub_sync_admin'] = time();
            }
        }
		if((getVendorVirtualBank() == false) || ((is_array(getVendorVirtualBank()) == true) && (!empty(array_diff($virtual_bank_code_arr, $registered_virtual_bank_arr))))){
		//Monnify
		$get_monnify_access_token = json_decode(getVendorMonnifyAccessToken(), true);
		if($get_monnify_access_token["status"] == "success"){

			//Check If Monnify Virtual Account Exists
			$admin_monnify_account_reference = md5($_SERVER["HTTP_HOST"]."-".$get_logged_admin_details["id"]."-".$get_logged_admin_details["email"]);
			$get_monnify_reserve_account = json_decode(makeMonnifyRequest("get", $get_monnify_access_token["token"], "api/v2/bank-transfer/reserved-accounts/".$admin_monnify_account_reference, ""), true);
			if($get_monnify_reserve_account["status"] == "success"){
				$monnify_reserve_account_response = json_decode($get_monnify_reserve_account["json_result"], true);
				foreach($monnify_reserve_account_response["responseBody"]["accounts"] as $monnify_accounts_json){
					if(in_array($monnify_accounts_json["bankCode"], array("232", "035", "50515"))){
						
                        addVendorVirtualBank($admin_monnify_account_reference, $monnify_accounts_json["bankCode"], $monnify_accounts_json["bankName"], $monnify_accounts_json["accountNumber"], $monnify_reserve_account_response["responseBody"]["accountName"], $get_logged_admin_details["id"], 'monnify');
					}
				}
			}else{
				$select_monnify_gateway_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_super_admin_payment_gateways WHERE gateway_name='monnify' LIMIT 1"));
				$monnify_create_reserve_account_array = array("accountReference" => $admin_monnify_account_reference, "accountName" => $get_logged_admin_details["firstname"]." ".$get_logged_admin_details["lastname"]." ".$get_logged_admin_details["othername"], "currencyCode" => "NGN", "contractCode" => $select_monnify_gateway_details["encrypt_key"], "customerEmail" => $get_logged_admin_details["email"], "getAllAvailableBanks" => false, "preferredBanks" => ["232", "035", "50515", "058"]);
				$monnify_create_reserve_account_array = array_merge($monnify_create_reserve_account_array, $bvn_nin_monnify_account_creation);
				makeMonnifyRequest("post", $get_monnify_access_token["token"], "api/v2/bank-transfer/reserved-accounts", $monnify_create_reserve_account_array);
				//$virtual_account_vaccount_err .= '<span class="color-4">Virtual Account Created Successfully</span>';
			}
		}else{
			if($get_monnify_access_token["status"] == "failed"){
				//$virtual_account_vaccount_err .= '<span class="color-4">'.$get_monnify_access_token["message"].'</span>';
			}
		}
		
		//Payvessel
		if((!empty($get_logged_admin_details["bvn"]) && is_numeric($get_logged_admin_details["bvn"]) && strlen($get_logged_admin_details["bvn"]) == 11)){
		$get_payvessel_access_token = json_decode(getVendorPayvesselAccessToken(), true);
		if($get_payvessel_access_token["status"] == "success"){
			$select_payvessel_gateway_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_super_admin_payment_gateways WHERE gateway_name='payvessel' LIMIT 1"));
			$admin_payvessel_account_reference = str_replace([".","-",":"], "", $_SERVER["HTTP_HOST"])."-".$get_logged_admin_details["id"]."-".$get_logged_admin_details["email"];
			$payvessel_create_reserve_account_array = array("email" => $admin_payvessel_account_reference, "name" => $get_logged_admin_details["firstname"]." ".$get_logged_admin_details["lastname"], "phoneNumber" => $get_logged_admin_details["phone_number"], "businessid" => $select_payvessel_gateway_details["encrypt_key"], "bankcode" => ["101", "120001"], "account_type" => "STATIC");
			$payvessel_create_reserve_account_array = array_merge($payvessel_create_reserve_account_array, $bvn_nin_payvessel_account_creation);
			$get_payvessel_reserve_account = json_decode(makePayvesselRequest("post", $get_payvessel_access_token["token"], "api/external/request/customerReservedAccount/", $payvessel_create_reserve_account_array), true);
			
			if(isset($get_payvessel_reserve_account["status"]) && $get_payvessel_reserve_account["status"] == "success"){
				$payvessel_reserve_account_response = json_decode($get_payvessel_reserve_account["json_result"], true);
				
				if (isset($payvessel_reserve_account_response["banks"])) {
					foreach($payvessel_reserve_account_response["banks"] as $payvessel_accounts_json){
						addVendorVirtualBank($payvessel_accounts_json["trackingReference"], $payvessel_accounts_json["bankCode"], $payvessel_accounts_json["bankName"], $payvessel_accounts_json["accountNumber"], $payvessel_accounts_json["accountName"], $get_logged_admin_details["id"], 'payvessel');
					}
				}
				//$virtual_account_vaccount_err .= '<span class="color-4">Virtual Account Created Successfully</span>';
			}
			
			if(isset($get_payvessel_reserve_account["status"]) && $get_payvessel_reserve_account["status"] == "failed"){
				//$virtual_account_vaccount_err .= '<span class="color-4">'.$get_payvessel_access_token["message"].'</span>';
			}
		}else{
			if($get_payvessel_access_token["status"] == "failed"){
				//$virtual_account_vaccount_err .= '<span class="color-4">'.$get_payvessel_access_token["message"].'</span>';
			}
		}
		}
		}else{
			foreach(getVendorVirtualBank() as $monnify_accounts_json){
				$monnify_accounts_json = json_decode($monnify_accounts_json, true);
				if(in_array($monnify_accounts_json["bank_code"], array("232", "035", "50515", "058", "101", "120001"))){
					
				}
			}
		}
	}else{
		if(empty($get_logged_admin_details["bank_code"])){
			//$virtual_account_vaccount_err .= '<span class="color-4">Incomplete Bank Details, Update Your Bank Details In Account Settings</span><br/>';
		}else{
			if(!is_numeric($get_logged_admin_details["bank_code"])){
				//$virtual_account_vaccount_err .= '<span class="color-4">Non-numeric Bank Code</span><br/>';
			}else{
				if(empty($get_logged_admin_details["bvn"])){
					//$virtual_account_vaccount_err .= '<span class="color-4">Update BVN if neccessary</span><br/>';
				}else{
					if(!is_numeric($get_logged_admin_details["bvn"])){
						//$virtual_account_vaccount_err .= '<span class="color-4">Non-numeric BVN</span><br/>';
					}else{
						if(strlen($get_logged_admin_details["bvn"]) !== 11){
							//$virtual_account_vaccount_err .= '<span class="color-4">BVN must be 11 digit long</span><br/>';
						}else{
							if(empty($get_logged_admin_details["nin"])){
								//$virtual_account_vaccount_err .= '<span class="color-4">Update NIN if neccessary</span><br/>';
							}else{
								if(!is_numeric($get_logged_admin_details["nin"])){
									//$virtual_account_vaccount_err .= '<span class="color-4">Non-numeric NIN</span><br/>';
								}else{
									if(strlen($get_logged_admin_details["nin"]) !== 11){
										//$virtual_account_vaccount_err .= '<span class="color-4">NIN must be 11 digit long</span>';
									}
								}
							}
						}
					}
				}
			}
		}
		
	}
?>
<!DOCTYPE html>
<head>
    <title>Admin Dashboard | <?php echo $get_all_super_admin_site_details["site_title"] ?? 'Vendor Dashboard'; ?></title>
    <meta charset="UTF-8" />
    <meta name="description" content="<?php echo substr($get_all_super_admin_site_details["site_desc"] ?? '', 0, 160); ?>" />
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
  <link href="../assets-2/vendor/remixicon/remixicon.css" rel="stylesheet">

  <!-- Template Main CSS File -->
  <link href="../assets-2/css/style.css" rel="stylesheet">
    <style>
    .balance-hero {
        background: linear-gradient(135deg, <?php echo $vendor_primary_color; ?> 0%, <?php echo $vendor_primary_color; ?>cc 100%);
        border-radius: 1.25rem;
        color: white;
        padding: 2rem;
        position: relative;
        overflow: hidden;
        margin-bottom: 1.5rem;
        border: none;
    }
    .balance-hero::after {
        content: '';
        position: absolute;
        top: -20%;
        right: -10%;
        width: 300px;
        height: 300px;
        background: rgba(255,255,255,0.1);
        border-radius: 50%;
    }
    .balance-amount { font-size: 2.5rem; font-weight: 800; letter-spacing: -1px; }

    .metric-box {
        background: rgba(255,255,255,0.1);
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255,255,255,0.2);
        border-radius: 1rem;
        padding: 1rem;
        transition: all 0.3s ease;
    }
    .metric-box:hover { background: rgba(255,255,255,0.15); }
    .metric-label { font-size: 0.75rem; font-weight: 700; text-uppercase: uppercase; opacity: 0.8; margin-bottom: 0.25rem; }
    .metric-value { font-size: 1.25rem; font-weight: 800; word-break: break-all; }

    @media (max-width: 767px) {
        .balance-amount { font-size: 1.75rem; }
        .balance-hero { padding: 1.25rem; }
        .metric-value { font-size: 1rem; }
    }

    .subscription-icon {
        width: 52px; height: 52px; border-radius: 1rem;
        background: linear-gradient(135deg, #f59e0b, #d97706);
        color: #fff; display: flex; align-items: center; justify-content: center; flex-shrink: 0;
    }
    .business-growth-icon {
        width: 52px; height: 52px; border-radius: 1rem;
        background: linear-gradient(135deg, #ef4444, #b91c1c);
        color: #fff; display: flex; align-items: center; justify-content: center; flex-shrink: 0;
    }
    .growth-tile { transition: transform 0.15s ease, box-shadow 0.15s ease; }
    .growth-tile:hover { transform: translateY(-2px); box-shadow: 0 10px 20px -8px rgba(0,0,0,0.15) !important; }
    @keyframes pulse-new {
        0%   { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.5); }
        70%  { box-shadow: 0 0 0 6px rgba(220, 53, 69, 0); }
        100% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0); }
    }

    .quick-link-tile {
        position: relative;
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        gap: 0.35rem;
        text-decoration: none;
        background: #f8f9fc;
        border: 1px solid #eef0f5;
        border-radius: 1rem;
        padding: 0.9rem 0.5rem;
        height: 100%;
        min-height: 84px;
        transition: transform 0.15s ease, box-shadow 0.15s ease, background 0.15s ease, border-color 0.15s ease;
    }
    .quick-link-tile i { font-size: 1.4rem; }
    .quick-link-tile span { font-size: 0.7rem; font-weight: 700; color: #334155; text-align: center; }
    .quick-link-tile:hover { transform: translateY(-3px); box-shadow: 0 10px 20px -8px rgba(0,0,0,0.15); }
    .quick-link-badge {
        position: absolute; top: 6px; right: 10px;
        background: #dc3545; color: #fff; font-size: 0.62rem; font-weight: 800;
        border-radius: 999px; min-width: 18px; height: 18px;
        display: flex; align-items: center; justify-content: center; padding: 0 4px;
    }
    .ql-primary i { color: #0d6efd; } .ql-primary:hover { background: #eaf2ff; border-color: #b6d4fe; }
    .ql-success i { color: #198754; } .ql-success:hover { background: #e9f7ef; border-color: #a3d9b8; }
    .ql-info    i { color: #0dcaf0; } .ql-info:hover    { background: #e6f9fd; border-color: #9eebfa; }
    .ql-purple  i { color: #6f42c1; } .ql-purple:hover  { background: #f1eafb; border-color: #d2b8f0; }
    .ql-warning i { color: #ffc107; } .ql-warning:hover { background: #fff9e6; border-color: #ffe69c; }
    .ql-danger  i { color: #dc3545; } .ql-danger:hover  { background: #fdecee; border-color: #f3b8c0; }
    .ql-teal    i { color: #20c997; } .ql-teal:hover    { background: #e7f9f3; border-color: #a8e6cf; }
    .ql-orange  i { color: #fd7e14; } .ql-orange:hover  { background: #fff1e6; border-color: #ffcda3; }
  </style>

</head>
<body>
    <?php include("../func/bc-admin-header.php"); ?>
    
  	<div class="pagetitle">
      <h1>DASHBOARD</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="#">Home</a></li>
          <li class="breadcrumb-item active">Dashboard</li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
    <?php
    // Quick Actions / Urgent Notifications
    $pending_unblocks = mysqli_query($connection_server, "SELECT COUNT(*) as count FROM sas_unblock_requests WHERE vendor_id='".$get_logged_admin_details["id"]."' AND status='pending'");
    $unblock_count = mysqli_fetch_assoc($pending_unblocks)['count'] ?? 0;

    $blocked_users = mysqli_query($connection_server, "SELECT COUNT(*) as count FROM sas_users WHERE vendor_id='".$get_logged_admin_details["id"]."' AND is_blocked=1");
    $blocked_count = mysqli_fetch_assoc($blocked_users)['count'] ?? 0;

    $pending_kyc = mysqli_query($connection_server, "SELECT COUNT(*) as count FROM sas_users WHERE vendor_id='".$get_logged_admin_details["id"]."' AND kyc_status=1");
    $kyc_count = mysqli_fetch_assoc($pending_kyc)['count'] ?? 0;

    if ($unblock_count > 0 || $blocked_count > 0 || $kyc_count > 0):
    ?>
    <div class="row mb-4 g-3">
        <?php if ($unblock_count > 0 || $blocked_count > 0): ?>
        <div class="col-md-6">
            <div class="card bg-warning bg-opacity-10 border-warning border-start border-4 rounded-4 shadow-sm h-100 mb-0">
                <div class="card-body py-3 d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center">
                        <i class="bi bi-shield-lock-fill text-warning fs-2 me-3"></i>
                        <div>
                            <h6 class="mb-0 fw-bold text-dark-primary">Security Action Required</h6>
                            <p class="mb-0 small text-dark-primary" style="opacity: 0.8;">
                                <?php if($unblock_count > 0) echo "<b>$unblock_count</b> pending unblock. "; ?>
                                <?php if($blocked_count > 0) echo "<b>$blocked_count</b> locked accounts."; ?>
                            </p>
                        </div>
                    </div>
                    <a href="BruteForceSecurity.php" class="btn btn-warning btn-sm fw-bold px-3 rounded-pill">Manage</a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($kyc_count > 0): ?>
        <div class="col-md-6">
            <div class="card bg-primary bg-opacity-10 border-primary border-start border-4 rounded-4 shadow-sm h-100 mb-0">
                <div class="card-body py-3 d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center">
                        <i class="bi bi-person-badge-fill text-primary fs-2 me-3"></i>
                        <div>
                            <h6 class="mb-0 fw-bold text-dark-primary">KYC Action Required</h6>
                            <p class="mb-0 small text-dark-primary" style="opacity: 0.8;"><b><?php echo $kyc_count; ?></b> pending identity verifications.</p>
                        </div>
                    </div>
                    <a href="KYCManagement.php" class="btn btn-primary btn-sm fw-bold px-3 rounded-pill">Review</a>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="balance-hero shadow-sm">
        <div class="row align-items-center">
            <div class="col-lg-5 mb-4 mb-lg-0 text-center text-lg-start">
                <div class="small fw-bold text-uppercase opacity-75 mb-1">Total API Balance</div>
                <div class="balance-amount mb-3" style="word-break: break-all;">₦<?php echo number_format($get_logged_admin_details["balance"], 2); ?></div>
                <div class="d-flex gap-2">
                    <a href="Fund.php" class="btn btn-light btn-sm rounded-pill px-3 fw-bold text-primary">Add Funds</a>
                    <a href="Transactions.php" class="btn btn-outline-light btn-sm rounded-pill px-3 fw-bold">History</a>
                </div>
            </div>
            <div class="col-lg-7">
                <div class="row g-3">
                    <div class="col-6 col-md-4">
                        <div class="metric-box">
                            <div class="metric-label">Total Users</div>
                            <div class="metric-value"><?php
                                $get_total_users = mysqli_query($connection_server, "SELECT COUNT(*) as count FROM sas_users WHERE vendor_id='".$get_logged_admin_details["id"]."'");
                                echo number_format(mysqli_fetch_assoc($get_total_users)['count'] ?? 0);
                            ?></div>
                        </div>
                    </div>
                    <?php
                        // Optimization DG6.7: 10-minute persistent cache using sas_dashboard_cache
                        $vid_stats = $get_logged_admin_details["id"];

                        // v2: the previous predicate counted every wallet funding as a sale and
                        // every "credit" word as a deposit. Cache under a new key so installs that
                        // still hold the old, wrong JSON stop serving it.
                        $stats_cache_key = 'admin_stats_v2';
                        $q_cache = mysqli_query($connection_server, "SELECT cache_value FROM sas_dashboard_cache WHERE vendor_id='$vid_stats' AND username='ADMIN' AND cache_key='$stats_cache_key' AND expiry > NOW() ORDER BY expiry DESC LIMIT 1");

                        if ($q_cache && mysqli_num_rows($q_cache) > 0) {
                            $stats_cache = json_decode(mysqli_fetch_assoc($q_cache)['cache_value'], true) ?: ['deposit' => 0, 'spent' => 0];
                        } else {
                            $total_dep = 0;
                            $total_spent = 0;

                            $stmt_dep = mysqli_prepare($connection_server, "SELECT SUM(amount) as total FROM sas_transactions WHERE vendor_id=? AND " . bc_sql_successful_deposit());
                            if ($stmt_dep) {
                                mysqli_stmt_bind_param($stmt_dep, "i", $vid_stats);
                                mysqli_stmt_execute($stmt_dep);
                                $res_dep = mysqli_stmt_get_result($stmt_dep);
                                $total_dep = ($res_dep && $r_dep = mysqli_fetch_assoc($res_dep)) ? (float)($r_dep['total'] ?? 0) : 0;
                                mysqli_stmt_close($stmt_dep);
                            }

                            $stmt_spent = mysqli_prepare($connection_server, "SELECT SUM(discounted_amount) as total FROM sas_transactions WHERE vendor_id=? AND " . bc_sql_successful_sale());
                            if ($stmt_spent) {
                                mysqli_stmt_bind_param($stmt_spent, "i", $vid_stats);
                                mysqli_stmt_execute($stmt_spent);
                                $res_spent = mysqli_stmt_get_result($stmt_spent);
                                $total_spent = ($res_spent && $r_sp = mysqli_fetch_assoc($res_spent)) ? (float)($r_sp['total'] ?? 0) : 0;
                                mysqli_stmt_close($stmt_spent);
                            }

                            $stats_cache = ['deposit' => $total_dep, 'spent' => $total_spent];
                            $cache_val = mysqli_real_escape_string($connection_server, json_encode($stats_cache));
                            // sas_dashboard_cache has no unique key, so ON DUPLICATE KEY UPDATE never
                            // fires and rows pile up. Replace the rows for this key explicitly.
                            mysqli_query($connection_server, "DELETE FROM sas_dashboard_cache WHERE vendor_id='$vid_stats' AND username='ADMIN' AND cache_key IN ('$stats_cache_key','admin_stats')");
                            mysqli_query($connection_server, "INSERT INTO sas_dashboard_cache (vendor_id, username, cache_key, cache_value, expiry) VALUES ('$vid_stats', 'ADMIN', '$stats_cache_key', '$cache_val', DATE_ADD(NOW(), INTERVAL 10 MINUTE))");
                        }
                    ?>
                    <div class="col-6 col-md-4">
                        <div class="metric-box">
                            <div class="metric-label">Total Deposits</div>
                            <div class="metric-value">₦<?php echo number_format($stats_cache['deposit'], 2); ?></div>
                        </div>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="metric-box">
                            <div class="metric-label">Total Sales</div>
                            <div class="metric-value">₦<?php echo number_format($stats_cache['spent'], 2); ?></div>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="metric-box border-warning border-opacity-50">
                            <div class="metric-label text-warning">24h Payout Limit</div>
                            <div class="metric-value">
                                <?php
                                    $vid = $get_logged_admin_details["id"];
                                    $daily_limit = (int)($get_logged_admin_details['daily_payout_limit'] ?? 10);
                                    $check_daily = mysqli_query($connection_server, "SELECT COUNT(*) as total FROM sas_bank_transfer_history WHERE vendor_id='$vid' AND DATE(date) = CURDATE()");
                                    $rd = mysqli_fetch_assoc($check_daily);
                                    $current_payouts = $rd['total'] ?? 0;
                                    echo "$current_payouts / $daily_limit";
                                ?>
                                <small class="fw-normal opacity-75 ms-1" style="font-size: 0.7rem;">Requests</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
        
        <!-- Row -->
        <div class="row">
          <!-- Subscription Card -->
          <div class="col-12 col-lg-4">
            <?php
              $expiry_date = $get_logged_admin_details['expiry_date'];
              $status_badge = 'bg-secondary';
              $status_text = 'No Subscription';
              $expiry_text = 'N/A';
              $days_remaining = null;
              $progress_pct = 0;
              $progress_color = 'bg-success';
              $plan_name = null;
              $purchase_date = null;

              // Most recently purchased plan — gives the card a real plan name instead of just a bare date.
              $vid_sub = (int)$get_logged_admin_details['id'];
              $plan_q = mysqli_query($connection_server, "SELECT bp.name, vs.purchase_date FROM sas_vendor_subscriptions vs INNER JOIN sas_billing_packages bp ON bp.id = vs.package_id WHERE vs.vendor_id='$vid_sub' ORDER BY vs.purchase_date DESC LIMIT 1");
              $plan_row = $plan_q ? mysqli_fetch_assoc($plan_q) : null;
              if ($plan_row) {
                  $plan_name = $plan_row['name'];
                  $purchase_date = $plan_row['purchase_date'];
              }

              if ($expiry_date) {
                  $today = new DateTime();
                  $expiry = new DateTime($expiry_date);
                  $days_remaining = (int)$today->diff($expiry)->format('%r%a');

                  if ($expiry < $today) {
                      $status_badge = 'bg-danger';
                      $status_text = 'Expired';
                      $progress_pct = 100;
                      $progress_color = 'bg-danger';
                  } else {
                      $status_badge = 'bg-primary';
                      $status_text = 'Active';
                      if ($days_remaining <= 7) { $progress_color = 'bg-danger'; }
                      elseif ($days_remaining <= 30) { $progress_color = 'bg-warning'; }
                      else { $progress_color = 'bg-success'; }

                      if (!empty($purchase_date)) {
                          $total_days = max(1, (new DateTime($purchase_date))->diff($expiry)->days);
                          $elapsed_days = max(0, $total_days - $days_remaining);
                          $progress_pct = min(100, round(($elapsed_days / $total_days) * 100));
                      }
                  }
                  $expiry_text = date('F j, Y', strtotime($expiry_date));
              }
            ?>
            <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4 h-100">
              <div class="card-body p-4 d-flex flex-column">

                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="subscription-icon">
                        <i class="bi bi-gem fs-4"></i>
                    </div>
                    <div>
                        <h5 class="mb-0 fw-bold text-dark"><?php echo $plan_name ? htmlspecialchars($plan_name) . ' Plan' : 'Subscription'; ?></h5>
                        <span class="badge <?php echo $status_badge; ?> rounded-pill px-2 py-1" style="font-size: 0.65rem;"><?php echo $status_text; ?></span>
                    </div>
                </div>

                <?php if ($days_remaining !== null): ?>
                <div class="mb-3">
                    <div class="d-flex justify-content-between small text-muted mb-1">
                        <span>Expires <?php echo htmlspecialchars($expiry_text); ?></span>
                        <span class="fw-bold <?php echo $days_remaining <= 7 ? 'text-danger' : 'text-dark'; ?>">
                            <?php echo $days_remaining >= 0 ? $days_remaining . ' days left' : abs($days_remaining) . ' days overdue'; ?>
                        </span>
                    </div>
                    <div class="progress" style="height: 6px;">
                        <div class="progress-bar <?php echo $progress_color; ?>" style="width: <?php echo $progress_pct; ?>%;"></div>
                    </div>
                </div>
                <?php else: ?>
                <p class="small text-muted mb-3">You don't have an active plan yet. Choose one to unlock full platform features.</p>
                <?php endif; ?>

                <a href="RenewSubscription.php" class="btn btn-primary rounded-pill fw-bold py-2 mt-auto">
                  <i class="bi bi-arrow-repeat me-2"></i><?php echo ($status_text === 'Active' || $status_text === 'Expired') ? 'Renew / Upgrade Plan' : 'Choose a Plan'; ?>
                </a>
              </div>
            </div>
          </div>

          <!-- Business Growth Card -->
          <?php if(isServiceEnabled('ai_suite')): ?>
          <div class="col-12 col-lg-4">
            <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4 h-100" style="background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);">
              <div class="card-body p-4 d-flex flex-column">

                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="business-growth-icon">
                        <i class="bi bi-rocket-takeoff-fill fs-4"></i>
                    </div>
                    <div>
                        <h5 class="mb-0 fw-bold" style="color:#0f172a;">Business Growth</h5>
                        <span class="small text-muted">AI tools to grow faster</span>
                    </div>
                </div>

                <div class="d-flex flex-column gap-2 flex-grow-1">
                  <a href="AISettings.php" class="text-decoration-none p-3 rounded-4 bg-white shadow-sm d-flex align-items-center growth-tile" style="border: 1px solid rgba(0,0,0,0.05);">
                      <div class="bg-primary bg-opacity-10 text-primary p-3 rounded-circle me-3">
                          <i class="bi bi-cpu-fill fs-4"></i>
                      </div>
                      <div class="flex-grow-1">
                          <h6 class="mb-0 fw-bold text-dark d-flex align-items-center">AI Business Suite <span class="badge bg-danger rounded-pill ms-2" style="font-size: 0.6rem; animation: pulse-new 2s infinite;">NEW</span></h6>
                          <span class="small text-muted" style="font-size: 0.75rem;">Automate support &amp; sales</span>
                      </div>
                      <i class="bi bi-chevron-right text-muted"></i>
                  </a>

                  <a href="AIMarketing.php" class="text-decoration-none p-3 rounded-4 bg-white shadow-sm d-flex align-items-center growth-tile" style="border: 1px solid rgba(0,0,0,0.05);">
                      <div class="bg-danger bg-opacity-10 text-danger p-3 rounded-circle me-3">
                          <i class="bi bi-megaphone-fill fs-4"></i>
                      </div>
                      <div class="flex-grow-1">
                          <h6 class="mb-0 fw-bold text-dark">AI Marketing Studio</h6>
                          <span class="small text-muted" style="font-size: 0.75rem;">Generate promo content instantly</span>
                      </div>
                      <i class="bi bi-chevron-right text-muted"></i>
                  </a>
                </div>

              </div>
            </div>
          </div>
          <?php endif; ?>

          <!-- Quick Links Card -->
          <div class="col-12 col-lg-4">
            <?php
                $ql_vid = (int)$get_logged_admin_details["id"];
                $ql_pending_orders = 0;
                $ql_pending_q = mysqli_query($connection_server, "SELECT COUNT(*) as c FROM sas_submitted_payments WHERE vendor_id='$ql_vid' && status='2'");
                if ($ql_pending_q) $ql_pending_orders = (int)(mysqli_fetch_assoc($ql_pending_q)['c'] ?? 0);
            ?>
            <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4 h-100">
              <div class="card-header bg-white py-3 border-0">
                  <h5 class="card-title mb-0 text-center"><i class="bi bi-grid-fill me-2 text-primary"></i>Quick Links</h5>
              </div>
              <div class="card-body p-4">
                <div class="row g-2">
                  <div class="col-6">
                    <a href="PaymentOrders.php" class="quick-link-tile ql-primary">
                      <?php if ($ql_pending_orders > 0): ?><span class="quick-link-badge"><?php echo $ql_pending_orders > 99 ? '99+' : $ql_pending_orders; ?></span><?php endif; ?>
                      <i class="bi bi-card-checklist"></i>
                      <span>Orders</span>
                    </a>
                  </div>
                  <div class="col-6">
                    <a href="ShareFund.php" class="quick-link-tile ql-success">
                      <i class="bi bi-send"></i>
                      <span>Transfer</span>
                    </a>
                  </div>
                  <div class="col-6">
                    <a href="Transactions.php" class="quick-link-tile ql-info">
                      <i class="bi bi-receipt"></i>
                      <span>Transactions</span>
                    </a>
                  </div>
                  <div class="col-6">
                    <a href="Users.php" class="quick-link-tile ql-purple">
                      <i class="bi bi-people"></i>
                      <span>Users</span>
                    </a>
                  </div>
                  <div class="col-6">
                    <a href="CoinConversions.php" class="quick-link-tile ql-warning">
                      <i class="bi bi-coin"></i>
                      <span>Coins</span>
                    </a>
                  </div>
                  <div class="col-6">
                    <a href="BruteForceSecurity.php" class="quick-link-tile ql-danger">
                      <i class="bi bi-shield-lock"></i>
                      <span>Security</span>
                    </a>
                  </div>
                  <div class="col-6">
                    <a href="PaymentGateway.php" class="quick-link-tile ql-teal">
                      <i class="bi bi-credit-card"></i>
                      <span>Gateways</span>
                    </a>
                  </div>
                  <div class="col-6">
                    <a href="ServiceControl.php" class="quick-link-tile ql-orange">
                      <i class="bi bi-toggles"></i>
                      <span>Services</span>
                    </a>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
        <!-- Row End -->
    
    <!-- Row -->
    <div class="row g-4">
        <div class="col-12 col-lg-6">
            <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4 h-100">
                <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0"><i class="bi bi-bank me-2 text-primary"></i>Auto-Funding</h5>
                    <button id="syncBtnAdmin" class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-bold" onclick="triggerAdminManualSync()">
                        <i class="bi bi-arrow-repeat me-1"></i> RE-SYNC
                    </button>
                </div>
                <div class="card-body p-4">
                    <div class="d-flex gap-3 overflow-auto pb-2 no-scrollbar">
                        <?php
                          $v_banks = getVendorVirtualBank();
                          if($v_banks){
                            foreach ($v_banks as $bank_accounts_json) {
                                $bank_accounts_json = json_decode($bank_accounts_json, true);
                                ?>
                                <div class="bg-light p-4 rounded-4 border-start border-primary border-4 flex-shrink-0" style="min-width: 320px;">
                                    <div class="small text-muted fw-bold text-uppercase mb-2"><?php echo $bank_accounts_json["bank_name"]; ?></div>
                                    <div class="h4 fw-bold text-dark mb-2 d-flex justify-content-between align-items-center">
                                        <?php echo $bank_accounts_json["account_number"]; ?>
                                        <button class="btn btn-sm btn-primary py-1 px-2 rounded-3" onclick="copyText('Account number copied', '<?php echo $bank_accounts_json['account_number']; ?>')">
                                            <i class="bi bi-clipboard me-1"></i>Copy
                                        </button>
                                    </div>
                                    <div class="small fw-bold text-primary text-truncate mb-3"><?php echo strtoupper($bank_accounts_json["account_name"]); ?></div>
                                    <div class="pt-2 border-top d-flex justify-content-between">
                                        <span class="small text-muted">Service Fee</span>
                                        <span class="small fw-bold text-danger">₦50.00</span>
                                    </div>
                                </div>
                                <?php
                            }
                          } else {
                              echo '<div class="alert alert-info w-100">Ensure your API context is correctly set to generate auto-funding accounts.</div>';
                          }
                        ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-6">
            <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4 h-100">
                <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0"><i class="bi bi-box-seam me-2 text-primary"></i>Mobile App Addons</h5>
                    <span class="badge bg-primary bg-opacity-10 text-primary rounded-pill">Premium Features</span>
                </div>
                <div class="card-body p-4">
                    <div class="list-group list-group-flush">
                        <?php
                            $addons = [
                                'apk' => ['label' => 'Android APK', 'price' => getSuperAdminOption('apk_development_price', '0'), 'ordered' => $get_logged_admin_details['apk_ordered'], 'icon' => 'bi-android2'],
                                'ios' => ['label' => 'iOS App (Apple)', 'price' => getSuperAdminOption('ios_development_price', '0'), 'ordered' => $get_logged_admin_details['ios_ordered'], 'icon' => 'bi-apple'],
                                'playstore' => ['label' => 'Play Store Listing', 'price' => getSuperAdminOption('playstore_listing_price', '0'), 'ordered' => $get_logged_admin_details['playstore_ordered'], 'icon' => 'bi-google-play'],
                                'sms_bridge' => ['label' => 'PrintHub APP APK', 'price' => getSuperAdminOption('sms_bridge_price', '0'), 'ordered' => $get_logged_admin_details['sms_bridge_ordered'], 'icon' => 'bi-chat-left-dots']
                            ];

                            foreach($addons as $key => $addon):
                                $is_ordered = ($addon['ordered'] == 1);
                        ?>
                            <div class="list-group-item px-0 py-3 border-light d-flex justify-content-between align-items-center">
                                <div class="d-flex align-items-center">
                                    <div class="bg-primary bg-opacity-10 text-primary rounded-3 p-2 me-3">
                                        <i class="bi <?php echo $addon['icon']; ?> fs-4"></i>
                                    </div>
                                    <div>
                                        <h6 class="mb-0 fw-bold"><?php echo $addon['label']; ?></h6>
                                        <span class="small text-muted">₦<?php echo number_format((float)$addon['price'], 2); ?></span>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <?php if($is_ordered): ?>
                                        <div class="form-check form-switch m-0 me-1" title="Service Activated">
                                            <input class="form-check-input" type="checkbox" role="switch" checked disabled>
                                        </div>
                                        <span class="badge bg-success bg-opacity-10 text-success rounded-pill px-2 py-1 small fw-bold">Active</span>
                                    <?php else: ?>
                                        <div class="form-check form-switch m-0 me-1" title="Toggle to view details and order">
                                            <input class="form-check-input" type="checkbox" role="switch" style="cursor: pointer;" onclick="bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-addon-<?php echo $key; ?>')).show(); this.checked = false;">
                                        </div>
                                        <button class="btn btn-link p-0 text-primary small fw-bold text-decoration-none" data-bs-toggle="modal" data-bs-target="#modal-addon-<?php echo $key; ?>">
                                            Details
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Row End -->
      </div>
    </section>
        <?php include("../func/admin-short-trans.php"); ?>
    <?php include("../func/bc-admin-footer.php"); ?>
    
    <script>
        function triggerAdminManualSync() {
            const btn = document.getElementById('syncBtnAdmin');
            const originalText = btn.innerHTML;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>SYNCING...';
            btn.disabled = true;

            fetch('ajax-sync-vendor-accounts.php?manual=1')
                .then(response => response.json())
                .then(data => {
                    console.log('Admin sync result:', data);
                    let messages = [];
                    if(data.payhub) messages.push("PayHub: " + data.payhub.message);
                    if(data.monnify) messages.push("Monnify: " + data.monnify.message);
                    if(data.payvessel) messages.push("Payvessel: " + data.payvessel.message);

                    Swal.fire({
                        title: 'Vendor Sync Result',
                        html: '<div class="text-start small">' + messages.join('<br>') + '</div>',
                        icon: 'info'
                    }).then(() => {
                        window.location.reload();
                    });
                })
                .catch(error => {
                    console.error('Sync error:', error);
                    Swal.fire('Error', 'Failed to communicate with sync server.', 'error');
                })
                .finally(() => {
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                });
        }

        // Background sync for admin with source param
        fetch('ajax-sync-vendor-accounts.php?source=dashboard')
            .then(response => response.json())
            .then(data => {
                const hasNew = ['payhub', 'monnify', 'payvessel'].some(gw => data[gw] && data[gw].status === 'success');
                if (hasNew) {
                    setTimeout(() => { window.location.reload(); }, 2000);
                }
            });
    </script>
    <!-- Addon Modals -->
    <?php
    $addon_details = [
        'apk' => [
            'title' => 'Android APK Development',
            'article' => 'The Android APK addon is a transformative tool for VTU vendors looking to dominate the mobile market. By providing a native application, you bypass the friction of mobile browsers, offering users a faster, more reliable way to access your services. This app is built on a high-performance framework ensuring zero lag even on entry-level devices.',
            'features' => [
                'One-Click Access: Users can open your store directly from their home screen.',
                'Push Notifications: Send instant alerts for successful wallet funding or promotional offers.',
                'Offline Viewing: Users can view their transaction history even with limited connectivity.',
                'Biometric Security: Secure transactions with Fingerprint and Face Unlock technology.',
                'Dynamic Branding: Automatic sync with your dashboard logo and color scheme.'
            ],
            'functions' => 'Functionally, the APK acts as a direct wrapper for your VTU services but enhanced with native capabilities. It manages secure session tokens, handles complex payment redirects internally, and provides high-fidelity receipt generation for sharing across social channels.'
        ],
        'ios' => [
            'title' => 'iOS App Development',
            'article' => 'Expanding into the iOS ecosystem is a mark of a premium VTU brand. Our iOS addon provides a native experience for iPhone and iPad users, leveraging Apple\'s fluid design system. This app ensures your business reaches a high-value demographic that prefers native security and performance.',
            'features' => [
                'Retina Optimized UI: Crystal clear icons and typography optimized for Apple displays.',
                'Keychain Integration: Securely store user credentials using iOS industry standards.',
                'Apple Ecosystem Trust: A native app significantly increases user trust and brand loyalty.',
                'Haptic Feedback: A premium feel for buttons and transaction confirmations.',
                'Universal Support: One app that works beautifully across all modern iPhone and iPad models.'
            ],
            'functions' => 'The iOS app functions as your platform\'s gateway on Apple hardware. It handles heavy encryption for API calls, supports native iOS gestures for navigation, and integrates with the system share sheet for rapid delivery of transaction details.'
        ],
        'playstore' => [
            'title' => 'Play Store Listing',
            'article' => 'Professionalism starts with availability. Listing your app on the Google Play Store removes the "Unknown Sources" security warning that often deters new users. Our team manages the entire submission process, ensuring your brand adheres to Google\'s stringent developer policies.',
            'features' => [
                'Verified Security: Your app is scanned by Play Protect, giving users peace of mind.',
                'ASO Optimization: We optimize your description and tags for better search ranking.',
                'Automatic Updates: When we release new features, your users get them automatically.',
                'User Analytics: Track installs, uninstalls, and active users via our developer console report.',
                'Global Availability: Reach potential users not just in Nigeria, but globally.'
            ],
            'functions' => 'This service functions as your technical liaison with Google. We handle content ratings, privacy policy requirements, and periodic updates needed to maintain compatibility with new Android versions, ensuring your app never goes obsolete.'
        ],
        'sms_bridge' => [
            'title' => 'PrintHub APP APK',
            'article' => 'The PrintHub APP APK is a powerful automation engine designed specifically for the **Print Hub Service**. It eliminates the need for manual transaction processing by acting as a real-time gateway between your incoming SMS notifications and the platform\'s vending engine, ensuring your printing business runs 24/7.',
            'features' => [
                'Full Automation: Automatically processes Print Hub transactions without any manual intervention.',
                'Multi-Service Support: Supports up to 6 major VTU services (Airtime, Data, Cable, etc.) depending on your active Print Hub configurations.',
                'SMS-Driven Logic: Instantly detects incoming SMS notifications on your designated phone number to trigger immediate fulfillment.',
                'High Reliability: Built to handle high volumes of transaction requests with minimal latency and high accuracy.',
                'Seamless Integration: Works directly with the phone number set in your Print Hub settings for effortless setup.'
            ],
            'functions' => 'When a customer request triggers an SMS to your designated Print Hub number, this APK captures the message and communicates directly with the platform to verify and fulfill the order. It supports 6 major services including Airtime and Data printing, ensuring your users receive their pins or top-ups instantly without requiring you to manually click "Process" for each transaction.'
        ]
    ];

    foreach($addon_details as $key => $detail):
        $price_key = ($key == 'playstore') ? 'playstore_listing_price' : (($key == 'sms_bridge') ? 'sms_bridge_price' : $key.'_development_price');
        $price = (float)getSuperAdminOption($price_key, '0');
    ?>
    <div class="modal fade" id="modal-addon-<?php echo $key; ?>" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 rounded-4">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold"><?php echo $detail['title']; ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4" style="max-height: 70vh; overflow-y: auto;">
                    <div class="mb-4">
                        <h6 class="fw-bold text-primary mb-2 border-bottom pb-1"><i class="bi bi-journal-text me-2"></i>About this Addon</h6>
                        <p class="text-muted small" style="line-height: 1.6; text-align: justify;"><?php echo $detail['article']; ?></p>
                    </div>

                    <div class="mb-4">
                        <h6 class="fw-bold text-primary mb-2 border-bottom pb-1"><i class="bi bi-stars me-2"></i>Key Features</h6>
                        <ul class="list-group list-group-flush">
                            <?php foreach($detail['features'] as $f): ?>
                            <li class="list-group-item px-0 py-2 border-0 d-flex align-items-start bg-transparent">
                                <i class="bi bi-check2-circle text-success me-2 mt-1"></i>
                                <span class="small text-muted"><?php echo $f; ?></span>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>

                    <div class="mb-4">
                        <h6 class="fw-bold text-primary mb-2 border-bottom pb-1"><i class="bi bi-gear-wide-connected me-2"></i>Functions & Mechanics</h6>
                        <p class="text-muted small" style="line-height: 1.6;"><?php echo $detail['functions']; ?></p>
                    </div>

                    <div class="bg-primary bg-opacity-10 p-3 rounded-4 border border-primary border-opacity-10 d-flex justify-content-between align-items-center mt-4">
                        <div>
                            <span class="d-block small text-primary text-uppercase fw-bold" style="letter-spacing: 0.5px;">One-Off Fee</span>
                            <h4 class="fw-bold mb-0 text-primary">₦<?php echo number_format($price, 2); ?></h4>
                        </div>
                        <form method="post">
                            <input type="hidden" name="addon-key" value="<?php echo $key; ?>">
                            <button type="button" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm" onclick="askPermissionSubBtn(this, 'Proceed to order <?php echo $detail['title']; ?> for ₦<?php echo number_format($price, 2); ?>? This will be debited from your wallet.');" name="buy-addon">Order Now</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</body>
</html>
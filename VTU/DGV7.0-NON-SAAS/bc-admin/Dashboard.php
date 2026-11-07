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
		if((!empty($get_logged_admin_details["bvn"]) && is_numeric($get_logged_admin_details["bvn"]) && strlen($get_logged_admin_details["bvn"]) == 11) && (!empty($get_logged_admin_details["nin"]) && is_numeric($get_logged_admin_details["nin"]) && strlen($get_logged_admin_details["nin"]) == 11)){
			$verification_type = 1;
			$bvn_nin_monnify_account_creation = '"bvn" => $get_logged_admin_details["bvn"], "nin" => $get_logged_admin_details["nin"]';
			$bvn_nin_payvessel_account_creation = '"bvn" => $get_logged_admin_details["bvn"]';
		}else{
			if((!empty($get_logged_admin_details["bvn"]) && is_numeric($get_logged_admin_details["bvn"]) && strlen($get_logged_admin_details["bvn"]) == 11)){
				$verification_type = 1;
				$bvn_nin_monnify_account_creation = '"bvn" => $get_logged_admin_details["bvn"]';
				$bvn_nin_payvessel_account_creation = '"bvn" => $get_logged_admin_details["bvn"]';
			}else{
				if((!empty($get_logged_admin_details["nin"]) && is_numeric($get_logged_admin_details["nin"]) && strlen($get_logged_admin_details["nin"]) == 11)){
					$verification_type = 2;
					$bvn_nin_monnify_account_creation = '"nin" => $get_logged_admin_details["nin"]';
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
					$monnify_gateway_details = getGatewayDetails('monnify', $get_logged_admin_details["id"]);

				$monnify_create_reserve_account_array = array("accountReference" => $admin_monnify_account_reference, "accountName" => $get_logged_admin_details["firstname"]." ".$get_logged_admin_details["lastname"]." ".$get_logged_admin_details["othername"], "currencyCode" => "NGN", "contractCode" => $monnify_gateway_details["encrypt_key"], "customerEmail" => $get_logged_admin_details["email"], $bvn_nin_monnify_account_creation, "getAllAvailableBanks" => false, "preferredBanks" => ["232", "035", "50515", "058"]);
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
			$payvessel_create_reserve_account_array = array("email" => $admin_payvessel_account_reference, "name" => $get_logged_admin_details["firstname"]." ".$get_logged_admin_details["lastname"], "phoneNumber" => $get_logged_admin_details["phone_number"], $bvn_nin_payvessel_account_creation, "businessid" => $select_payvessel_gateway_details["encrypt_key"], "bankcode" => ["101", "120001"], "account_type" => "STATIC");
			$get_payvessel_reserve_account = json_decode(makePayvesselRequest("post", $get_payvessel_access_token["token"], "api/external/request/customerReservedAccount/", $payvessel_create_reserve_account_array), true);
			
			if($get_payvessel_reserve_account["status"] == "success"){
				$payvessel_reserve_account_response = json_decode($get_payvessel_reserve_account["json_result"], true);
				
				foreach($payvessel_reserve_account_response["banks"] as $payvessel_accounts_json){
						
					addVendorVirtualBank($payvessel_accounts_json["trackingReference"], $payvessel_accounts_json["bankCode"], $payvessel_accounts_json["bankName"], $payvessel_accounts_json["accountNumber"], $payvessel_accounts_json["accountName"], $get_logged_admin_details["id"], 'payvessel');
				}
				//$virtual_account_vaccount_err .= '<span class="color-4">Virtual Account Created Successfully</span>';
			}
			
			if($payvessel_reserve_account_response["status"] == "failed"){
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
    <title>Admin Dashboard | <?php echo $get_all_super_admin_site_details["site_title"]; ?></title>
    <meta charset="UTF-8" />
    <meta name="description" content="<?php echo substr($get_all_super_admin_site_details["site_desc"], 0, 160); ?>" />
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

    .ai-suite-card {
        background: linear-gradient(135deg, #1e1b4b 0%, #4338ca 55%, #6d28d9 100%);
        border-radius: 1.25rem;
        color: #fff;
        position: relative;
        overflow: hidden;
    }
    .ai-suite-card::after {
        content: '';
        position: absolute;
        top: -30%;
        right: -15%;
        width: 260px;
        height: 260px;
        background: radial-gradient(circle, rgba(255,255,255,0.16) 0%, rgba(255,255,255,0) 70%);
        pointer-events: none;
    }
    .ai-suite-icon {
        width: 52px;
        height: 52px;
        border-radius: 1rem;
        background: rgba(255,255,255,0.12);
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    .ai-status-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        display: inline-block;
        margin-right: 6px;
    }
    .ai-status-dot.is-on { background: #4ade80; animation: ai-pulse-dot 1.8s infinite; }
    .ai-status-dot.is-off { background: #94a3b8; }
    @keyframes ai-pulse-dot {
        0%   { box-shadow: 0 0 0 0 rgba(74, 222, 128, 0.55); }
        70%  { box-shadow: 0 0 0 6px rgba(74, 222, 128, 0); }
        100% { box-shadow: 0 0 0 0 rgba(74, 222, 128, 0); }
    }
    .ai-stat-chip {
        background: rgba(255,255,255,0.1);
        backdrop-filter: blur(6px);
        border: 1px solid rgba(255,255,255,0.15);
        border-radius: 0.9rem;
        padding: 0.65rem 0.85rem;
    }
    .ai-suite-cta {
        background: rgba(255,255,255,0.95);
        color: #4338ca;
        font-weight: 700;
        border: none;
        transition: transform 0.15s ease, background 0.15s ease;
    }
    .ai-suite-cta:hover { background: #fff; color: #3730a3; transform: translateY(-1px); }

    .quick-links-icon {
        width: 52px; height: 52px; border-radius: 1rem;
        background: linear-gradient(135deg, #0d6efd, #0a58ca);
        color: #fff; display: flex; align-items: center; justify-content: center; flex-shrink: 0;
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
    $unblock_count = $pending_unblocks ? (mysqli_fetch_assoc($pending_unblocks)['count'] ?? 0) : 0;

    $blocked_users = mysqli_query($connection_server, "SELECT COUNT(*) as count FROM sas_users WHERE vendor_id='".$get_logged_admin_details["id"]."' AND is_blocked=1");
    $blocked_count = $blocked_users ? (mysqli_fetch_assoc($blocked_users)['count'] ?? 0) : 0;

    $pending_kyc = mysqli_query($connection_server, "SELECT COUNT(*) as count FROM sas_users WHERE vendor_id='".$get_logged_admin_details["id"]."' AND kyc_status=1");
    $kyc_count = $pending_kyc ? (mysqli_fetch_assoc($pending_kyc)['count'] ?? 0) : 0;

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
                <div class="small fw-bold text-uppercase opacity-75 mb-1">Today's Revenue</div>
                <?php
                    $vid = $get_logged_admin_details["id"];
                    $stmt_today = mysqli_prepare($connection_server, "SELECT SUM(discounted_amount) as total FROM sas_transactions WHERE vendor_id=? AND status=1 AND DATE(date) = CURDATE() AND (type_alternative NOT LIKE '%credit%' AND type_alternative NOT LIKE '%refund%' AND type_alternative NOT LIKE '%received%' AND type_alternative NOT LIKE '%commission%')");
                    mysqli_stmt_bind_param($stmt_today, "i", $vid);
                    mysqli_stmt_execute($stmt_today);
                    $today_revenue = (float)(mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_today))['total'] ?? 0);
                ?>
                <div class="balance-amount mb-3" style="word-break: break-all;">₦<?php echo number_format($today_revenue, 2); ?></div>
                <div class="d-flex gap-2">
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

                        $q_cache = mysqli_query($connection_server, "SELECT cache_value FROM sas_dashboard_cache WHERE vendor_id='$vid_stats' AND username='ADMIN' AND cache_key='admin_stats' AND expiry > NOW() LIMIT 1");

                        if ($q_cache && mysqli_num_rows($q_cache) > 0) {
                            $stats_cache = json_decode(mysqli_fetch_assoc($q_cache)['cache_value'], true);
                        } else {
                            $stmt_dep = mysqli_prepare($connection_server, "SELECT SUM(discounted_amount) as total FROM sas_transactions WHERE vendor_id=? AND status=1 AND (type_alternative LIKE '%credit%' OR type_alternative LIKE '%received%' OR type_alternative LIKE '%commission%')");
                            mysqli_stmt_bind_param($stmt_dep, "i", $vid_stats);
                            mysqli_stmt_execute($stmt_dep);
                            $total_dep = (float)(mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_dep))['total'] ?? 0);

                            $stmt_spent = mysqli_prepare($connection_server, "SELECT SUM(discounted_amount) as total FROM sas_transactions WHERE vendor_id=? AND status=1 AND (type_alternative NOT LIKE '%credit%' AND type_alternative NOT LIKE '%refund%' AND type_alternative NOT LIKE '%received%' AND type_alternative NOT LIKE '%commission%')");
                            mysqli_stmt_bind_param($stmt_spent, "i", $vid_stats);
                            mysqli_stmt_execute($stmt_spent);
                            $total_spent = (float)(mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_spent))['total'] ?? 0);

                            $stats_cache = ['deposit' => $total_dep, 'spent' => $total_spent];
                            $cache_val = mysqli_real_escape_string($connection_server, json_encode($stats_cache));
                            mysqli_query($connection_server, "INSERT INTO sas_dashboard_cache (vendor_id, username, cache_key, cache_value, expiry) VALUES ('$vid_stats', 'ADMIN', 'admin_stats', '$cache_val', DATE_ADD(NOW(), INTERVAL 10 MINUTE)) ON DUPLICATE KEY UPDATE cache_value=VALUES(cache_value), expiry=VALUES(expiry)");
                        }
                    ?>
                    <div class="col-6 col-md-4">
                        <div class="metric-box">
                            <div class="metric-label">Total Deposits</div>
                            <div class="metric-value">₦<?php echo number_format($stats_cache['deposit'], 0); ?></div>
                        </div>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="metric-box">
                            <div class="metric-label">Total Sales</div>
                            <div class="metric-value">₦<?php echo number_format($stats_cache['spent'], 0); ?></div>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="metric-box border-info border-opacity-50">
                            <div class="metric-label text-info">Total Users Balance</div>
                            <div class="metric-value">
                                <?php
                                    $vid = $get_logged_admin_details["id"];
                                    $check_users_balance = mysqli_query($connection_server, "SELECT SUM(balance) as total FROM sas_users WHERE vendor_id='$vid'");
                                    $rd = mysqli_fetch_assoc($check_users_balance);
                                    $total_users_balance = $rd['total'] ?? 0;
                                    echo "₦" . number_format($total_users_balance, 2);
                                ?>
                                <small class="fw-normal opacity-75 ms-1" style="font-size: 0.7rem;">Combined Liability</small>
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
          <!-- AI Business Suite Card -->
          <div class="col-12 col-lg-6">
            <?php
                $ai_dash_status = (int)($get_logged_admin_details["ai_status"] ?? 0);
                $ai_dash_tokens = (int)($get_logged_admin_details["ai_token_balance"] ?? 0);
                $ai_dash_vid = (int)$get_logged_admin_details["id"];
                $ai_dash_flags = 0;
                $ai_dash_flags_q = mysqli_query($connection_server, "SELECT COUNT(*) as c FROM sas_ai_audit_log WHERE actor LIKE '$ai_dash_vid:%' AND event_type='SENTINEL_FLAGGED' AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)");
                if ($ai_dash_flags_q) $ai_dash_flags = (int)(mysqli_fetch_assoc($ai_dash_flags_q)['c'] ?? 0);
            ?>
            <div class="card ai-suite-card shadow-sm border-0 mb-4 h-100">
              <div class="card-body p-4 d-flex flex-column">

                <div class="d-flex align-items-start justify-content-between mb-3">
                    <div class="d-flex align-items-center gap-3">
                        <div class="ai-suite-icon">
                            <i class="bi bi-cpu-fill fs-4"></i>
                        </div>
                        <div>
                            <h5 class="mb-0 fw-bold">AI Business Suite</h5>
                            <span class="small" style="opacity: 0.75;">Automate support, sales & security</span>
                        </div>
                    </div>
                    <span class="badge rounded-pill d-none d-sm-inline-flex align-items-center" style="background: rgba(255,255,255,0.12); font-size: 0.7rem;">
                        <span class="ai-status-dot <?php echo $ai_dash_status ? 'is-on' : 'is-off'; ?>"></span>
                        <?php echo $ai_dash_status ? 'Engine Active' : 'Engine Paused'; ?>
                    </span>
                </div>

                <div class="row g-2 mb-4">
                    <div class="col-4">
                        <div class="ai-stat-chip text-center h-100">
                            <div class="fw-bold fs-6"><?php echo number_format($ai_dash_tokens); ?></div>
                            <div class="x-small" style="font-size: 0.68rem; opacity: 0.75;">Tokens Left</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="ai-stat-chip text-center h-100">
                            <div class="fw-bold fs-6"><i class="bi bi-headset"></i></div>
                            <div class="x-small" style="font-size: 0.68rem; opacity: 0.75;">Auto Support</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="ai-stat-chip text-center h-100">
                            <div class="fw-bold fs-6 <?php echo $ai_dash_flags > 0 ? 'text-warning' : ''; ?>"><?php echo $ai_dash_flags > 0 ? number_format($ai_dash_flags) : '<i class="bi bi-shield-check"></i>'; ?></div>
                            <div class="x-small" style="font-size: 0.68rem; opacity: 0.75;"><?php echo $ai_dash_flags > 0 ? 'Flags (7d)' : 'Sentinel Clean'; ?></div>
                        </div>
                    </div>
                </div>

                <a href="AISettings.php" class="ai-suite-cta btn rounded-pill py-2 mt-auto d-flex align-items-center justify-content-center gap-2">
                    Open AI Control Center <i class="bi bi-arrow-right"></i>
                </a>

              </div>
            </div>
          </div>

          <!-- Quick Links Card -->
          <div class="col-12 col-lg-6">
            <?php
                $ql_vid = (int)$get_logged_admin_details["id"];
                $ql_pending_orders = 0;
                $ql_pending_q = mysqli_query($connection_server, "SELECT COUNT(*) as c FROM sas_submitted_payments WHERE vendor_id='$ql_vid' && status='2'");
                if ($ql_pending_q) $ql_pending_orders = (int)(mysqli_fetch_assoc($ql_pending_q)['c'] ?? 0);
            ?>
            <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4 h-100">
              <div class="card-body p-4 d-flex flex-column">

                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="quick-links-icon">
                        <i class="bi bi-grid-fill fs-4"></i>
                    </div>
                    <div>
                        <h5 class="mb-0 fw-bold text-dark">Quick Links</h5>
                        <span class="small text-muted">Jump straight to what you need</span>
                    </div>
                </div>

                <div class="row g-2 flex-grow-1">
                  <div class="col-6 col-md-3">
                    <a href="PaymentOrders.php" class="quick-link-tile ql-primary">
                      <?php if ($ql_pending_orders > 0): ?><span class="quick-link-badge"><?php echo $ql_pending_orders > 99 ? '99+' : $ql_pending_orders; ?></span><?php endif; ?>
                      <i class="bi bi-card-checklist"></i>
                      <span>Orders</span>
                    </a>
                  </div>
                  <div class="col-6 col-md-3">
                    <a href="ShareFund.php" class="quick-link-tile ql-success">
                      <i class="bi bi-send"></i>
                      <span>Transfer</span>
                    </a>
                  </div>
                  <div class="col-6 col-md-3">
                    <a href="Transactions.php" class="quick-link-tile ql-info">
                      <i class="bi bi-receipt"></i>
                      <span>Transactions</span>
                    </a>
                  </div>
                  <div class="col-6 col-md-3">
                    <a href="Users.php" class="quick-link-tile ql-purple">
                      <i class="bi bi-people"></i>
                      <span>Users</span>
                    </a>
                  </div>
                  <div class="col-6 col-md-3">
                    <a href="CoinConversions.php" class="quick-link-tile ql-warning">
                      <i class="bi bi-coin"></i>
                      <span>Coins</span>
                    </a>
                  </div>
                  <div class="col-6 col-md-3">
                    <a href="BruteForceSecurity.php" class="quick-link-tile ql-danger">
                      <i class="bi bi-shield-lock"></i>
                      <span>Security</span>
                    </a>
                  </div>
                  <div class="col-6 col-md-3">
                    <a href="PaymentGateway.php" class="quick-link-tile ql-teal">
                      <i class="bi bi-credit-card"></i>
                      <span>Gateways</span>
                    </a>
                  </div>
                  <div class="col-6 col-md-3">
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

</body>
</html>

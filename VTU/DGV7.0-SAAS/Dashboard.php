<?php session_start();
include("../func/bc-config.php");

$select_user_vendor_status_message = mysqli_query($connection_server, "SELECT * FROM sas_vendor_status_messages WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "'");
if (mysqli_num_rows($select_user_vendor_status_message) == 1) {
	$get_user_vendor_status_message = mysqli_fetch_array($select_user_vendor_status_message);
	if (!isset($_SESSION["product_purchase_response"]) && isset($_SESSION["user_session"])) {
		$user_vendor_status_message_template_encoded_text_array = array("{username}" => $get_logged_user_details["username"]);
		foreach ($user_vendor_status_message_template_encoded_text_array as $array_key => $array_val) {
			$user_vendor_status_message_template_text = str_replace($array_key, $array_val, $get_user_vendor_status_message["message"]);
		}
		$_SESSION["product_purchase_response"] = str_replace("\n", "<br/>", $user_vendor_status_message_template_text);
	}
}
if (isset($_POST["upgrade-user"])) {
	$account_level_upgrade_array = array("smart" => 1, "agent" => 2);
	$purchase_method = "web";
	$purchase_method = strtoupper($purchase_method);
	$purchase_method_array = array("WEB");

	if (in_array($purchase_method, $purchase_method_array)) {
		if ($purchase_method === "WEB") {
			$upgrade_type = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["upgrade-type"]))));
		}

		if ($account_level_upgrade_array[$upgrade_type] == true) {
			if ($account_level_upgrade_array[$upgrade_type] > $get_logged_user_details["account_level"]) {
				$get_upgrade_price = mysqli_query($connection_server, "SELECT * FROM sas_user_upgrade_price WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && account_type='" . $account_level_upgrade_array[$upgrade_type] . "'");
				if (mysqli_num_rows($get_upgrade_price) == 1) {
					$upgrade_price = mysqli_fetch_array($get_upgrade_price);
					if (!empty($upgrade_price["price"]) && is_numeric($upgrade_price["price"]) && ($upgrade_price["price"] > 0)) {
						if (!empty(userBalance(1)) && is_numeric(userBalance(1)) && (userBalance(1) > 0)) {
							$amount = $upgrade_price["price"];
							$discounted_amount = $amount;
							$type_alternative = ucwords("Account Upgrade");
							$reference = substr(str_shuffle("12345678901234567890"), 0, 15);
							$description = ucwords(accountLevel($account_level_upgrade_array[$upgrade_type]) . " Upgrade charges");
							$status = 1;

							$debit_user = chargeUser("debit", accountLevel($account_level_upgrade_array[$upgrade_type]), $type_alternative, $reference, "", $amount, $discounted_amount, $description, $purchase_method, $_SERVER["HTTP_HOST"], $status);
							if ($debit_user === "success") {
								$user_logged_name = $get_logged_user_details["username"];
								$account_upgrade_id = $account_level_upgrade_array[$upgrade_type];
								$alter_user_details = alterUser($user_logged_name, "account_level", $account_upgrade_id);
								if ($alter_user_details == "success") {
									// Email Beginning
									$upgrade_template_encoded_text_array = array("{firstname}" => $get_logged_user_details["firstname"], "{lastname}" => $get_logged_user_details["lastname"], "{account_level}" => $upgrade_type . " level");
									$raw_upgrade_template_subject = getUserEmailTemplate('user-upgrade', 'subject');
									$raw_upgrade_template_body = getUserEmailTemplate('user-upgrade', 'body');
									foreach ($upgrade_template_encoded_text_array as $array_key => $array_val) {
										$raw_upgrade_template_subject = str_replace($array_key, $array_val, $raw_upgrade_template_subject);
										$raw_upgrade_template_body = str_replace($array_key, $array_val, $raw_upgrade_template_body);
									}
									sendVendorEmail($get_logged_user_details["email"], $raw_upgrade_template_subject, $raw_upgrade_template_body);
									// Email End

									//Referral Function
									if (!empty($get_logged_user_details["referral_id"])) {
										$check_user_referral_details = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && id='" . $get_logged_user_details["referral_id"] . "'");
										if (mysqli_num_rows($check_user_referral_details) == 1) {
											$get_referral_details = mysqli_fetch_array($check_user_referral_details);
											$select_referral_percentage_details = mysqli_query($connection_server, "SELECT * FROM sas_referral_percents WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && account_level='" . $account_level_upgrade_array[$upgrade_type] . "'");
											if (mysqli_num_rows($select_referral_percentage_details) == 1) {
												$reference_3 = substr(str_shuffle("12345678901234567890"), 0, 15);
												$get_referral_percentage = mysqli_fetch_array($select_referral_percentage_details);
												$referral_commission = ($discounted_amount * ($get_referral_percentage["percentage"] / 100));
												$discounted_referral_commission = $referral_commission;
												chargeOtherUser($get_referral_details["username"], "credit", accountLevel($account_level_upgrade_array[$upgrade_type]) . " Referral Commission", "Referral Commission", $reference_3, "", $referral_commission, $discounted_referral_commission, "Referral Upgrade Commission from " . accountLevel($get_logged_user_details["account_level"]) . " to " . accountLevel($account_level_upgrade_array[$upgrade_type]) . " for user: " . $get_logged_user_details["username"], $purchase_method, $_SERVER["HTTP_HOST"], "1");
												// Email Beginning
												$referral_template_encoded_text_array = array("{firstname}" => $get_referral_details["firstname"], "{lastname}" => $get_referral_details["lastname"], "{referral_commission}" => toDecimal($discounted_referral_commission, 2), "{referree}" => $get_logged_user_details["username"], "{account_level}" => $upgrade_type . " level");
												$raw_referral_template_subject = getUserEmailTemplate('user-referral-commission', 'subject');
												$raw_referral_template_body = getUserEmailTemplate('user-referral-commission', 'body');
												foreach ($referral_template_encoded_text_array as $array_key => $array_val) {
													$raw_referral_template_subject = str_replace($array_key, $array_val, $raw_referral_template_subject);
													$raw_referral_template_body = str_replace($array_key, $array_val, $raw_referral_template_body);
												}
												sendVendorEmail($get_referral_details["email"], $raw_referral_template_subject, $raw_referral_template_body);
												// Email End
											}
										}
									}

									//Account Upgraded Successfully
									$json_response_array = array("desc" => "Account Upgraded Successfully");
									$json_response_encode = json_encode($json_response_array, true);
								} else {
									$reference_2 = substr(str_shuffle("12345678901234567890"), 0, 15);
									chargeUser("credit", accountLevel($account_level_upgrade_array[$upgrade_type]), "Refund", $reference_2, "", $amount, $discounted_amount, "Refund for Ref:<i>'$reference'</i>", $purchase_method, $_SERVER["HTTP_HOST"], "1");
									//Upgrade Failed, Contact Admin
									$json_response_array = array("desc" => "Upgrade Failed, Contact Admin");
									$json_response_encode = json_encode($json_response_array, true);
								}
							} else {
								//Insufficient Fund
								$json_response_array = array("desc" => "Insufficient Fund");
								$json_response_encode = json_encode($json_response_array, true);
							}
						} else {
							//Balance is LOW
							$json_response_array = array("desc" => "Balance is LOW");
							$json_response_encode = json_encode($json_response_array, true);
						}
					} else {
						//Pricing Error, Contact Admin
						$json_response_array = array("desc" => "Pricing Error, Contact Admin");
						$json_response_encode = json_encode($json_response_array, true);
					}
				} else {
					//Error: Pricing Not Available, Contact Admin
					$json_response_array = array("desc" => "Error: Pricing Not Available, Contact Admin");
					$json_response_encode = json_encode($json_response_array, true);
				}
			} else {
				//Error: Account Cannot Be Downgraded, Contact Admin
				$json_response_array = array("desc" => "Error: Account Cannot Be Downgraded, Contact Admin");
				$json_response_encode = json_encode($json_response_array, true);
			}
		} else {
			//Invalid Upgrade Type
			$json_response_array = array("desc" => "Invalid Upgrade Type");
			$json_response_encode = json_encode($json_response_array, true);
		}
	}
	$json_response_decode = json_decode($json_response_encode, true);
	$_SESSION["product_purchase_response"] = $json_response_decode["desc"];
	header("Location: " . $_SERVER["REQUEST_URI"]);
}

$virtual_account_vaccount_err = "";

// 1. Mandatory API-based generation (Requires BVN/NIN)
$registered_virtual_bank_arr = array();
$virtual_bank_code_arr = array("232", "035", "50515", "120001", "100039", "110072", "566", "090110");
$user_banks = getUserVirtualBank();
if (is_array($user_banks)) {
    foreach ($user_banks as $bank_json) {
        $bank_json = json_decode($bank_json, true);
        array_push($registered_virtual_bank_arr, $bank_json["bank_code"]);
    }
}


if ((!empty($select_vendor_table["bank_code"]) && is_numeric($select_vendor_table["bank_code"]) && !empty($select_vendor_table["bvn"]) && is_numeric($select_vendor_table["bvn"]) && strlen($select_vendor_table["bvn"]) == 11) || (!empty($get_logged_user_details["bank_code"]) && is_numeric($get_logged_user_details["bank_code"]) && !empty($get_logged_user_details["nin"]) && is_numeric($get_logged_user_details["nin"]) && strlen($get_logged_user_details["nin"]) == 11)) {
	//Admin BVN/NIN
	if ((!empty($select_vendor_table["bvn"]) && is_numeric($select_vendor_table["bvn"]) && strlen($select_vendor_table["bvn"]) == 11) && (!empty($select_vendor_table["nin"]) && is_numeric($select_vendor_table["nin"]) && strlen($select_vendor_table["nin"]) == 11)) {
		$verification_type = 1;
		$select_vendor_table_bvn = $select_vendor_table["bvn"];
		$select_vendor_table_nin = $select_vendor_table["nin"];
	} else {
		if ((!empty($select_vendor_table["bvn"]) && is_numeric($select_vendor_table["bvn"]) && strlen($select_vendor_table["bvn"]) == 11)) {
			$verification_type = 1;
			$select_vendor_table_bvn = $select_vendor_table["bvn"];
		} else {
			if ((!empty($select_vendor_table["nin"]) && is_numeric($select_vendor_table["nin"]) && strlen($select_vendor_table["nin"]) == 11)) {
				$verification_type = 2;
				$select_vendor_table_nin = $select_vendor_table["nin"];
			}
		}
	}
	
	if ((getUserVirtualBank() == false) || (!empty(array_diff(array("232", "035", "50515", "120001"), $registered_virtual_bank_arr)))) {
		//Monnify
		$get_monnify_access_token = json_decode(getUserMonnifyAccessToken(), true);
		if ($get_monnify_access_token["status"] == "success") {

			//Check If Monnify Virtual Account Exists
			$user_monnify_account_reference = md5($_SERVER["HTTP_HOST"] . "-" . $get_logged_user_details["vendor_id"] . "-" . $get_logged_user_details["username"]);
			$get_monnify_reserve_account = json_decode(makeMonnifyRequest("get", $get_monnify_access_token["token"], "api/v2/bank-transfer/reserved-accounts/" . $user_monnify_account_reference, ""), true);
			if ($get_monnify_reserve_account["status"] == "success") {
				$monnify_reserve_account_response = json_decode($get_monnify_reserve_account["json_result"], true);
				foreach ($monnify_reserve_account_response["responseBody"]["accounts"] as $monnify_accounts_json) {
					if (in_array($monnify_accounts_json["bankCode"], array("232", "035", "50515", "058"))) {
						
						addUserVirtualBank($user_monnify_account_reference, $monnify_accounts_json["bankCode"], $monnify_accounts_json["bankName"], $monnify_accounts_json["accountNumber"], $monnify_reserve_account_response["responseBody"]["accountName"], $get_logged_user_details["vendor_id"], $get_logged_user_details["username"], 'monnify');
					}
				}
			} else {
				$select_monnify_gateway_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_payment_gateways WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && gateway_name='monnify' LIMIT 1"));
				$monnify_create_reserve_account_array = array("accountReference" => $user_monnify_account_reference, "accountName" => $get_logged_user_details["firstname"] . " " . $get_logged_user_details["lastname"] . " " . $get_logged_user_details["othername"], "currencyCode" => "NGN", "contractCode" => $select_monnify_gateway_details["encrypt_key"], "customerEmail" => $get_logged_user_details["email"], "getAllAvailableBanks" => false, "preferredBanks" => ["232", "035", "50515", "058"]);
				if (strlen($select_vendor_table_bvn) === 11) {
					$monnify_create_reserve_account_array["bvn"] = $select_vendor_table_bvn;
				}
				if (strlen($select_vendor_table_nin) === 11) {
					$monnify_create_reserve_account_array["nin"] = $select_vendor_table_nin;
				}
				makeMonnifyRequest("post", $get_monnify_access_token["token"], "api/v2/bank-transfer/reserved-accounts", $monnify_create_reserve_account_array);
			}
		}


		//Payvessel Admin/User BVN Virtual Account Generation
		if ((!empty($select_vendor_table["bvn"]) && is_numeric($select_vendor_table["bvn"]) && strlen($select_vendor_table["bvn"]) == 11) || (!empty($get_logged_user_details["bvn"]) && is_numeric($get_logged_user_details["bvn"]) && strlen($get_logged_user_details["bvn"]) == 11)) {
			$get_payvessel_access_token = json_decode(getUserPayvesselAccessToken(), true);

			if ($get_payvessel_access_token["status"] == "success") {
				$select_payvessel_gateway_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_payment_gateways WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && gateway_name='payvessel' LIMIT 1"));
				$user_payvessel_account_reference = str_replace([".", "-", ":"], "", $_SERVER["HTTP_HOST"]) . "-" . $get_logged_user_details["username"] . "-" . $get_logged_user_details["email"];
				$payvessel_create_reserve_account_array = array("email" => $user_payvessel_account_reference, "name" => trim($get_logged_user_details["firstname"] . " " . $get_logged_user_details["lastname"] . " " . $get_logged_user_details["othername"]), "phoneNumber" => $get_logged_user_details["phone_number"], "businessid" => $select_payvessel_gateway_details["encrypt_key"], "bankcode" => ["101", "120001"], "account_type" => "STATIC");
				if (strlen($select_vendor_table_bvn) === 11) {
					$payvessel_create_reserve_account_array["bvn"] = $select_vendor_table_bvn;
				}
				if (strlen($select_vendor_table_nin) === 11) {
					$payvessel_create_reserve_account_array["nin"] = $select_vendor_table_nin;
				}
				$get_payvessel_reserve_account = json_decode(makePayvesselRequest("post", $get_payvessel_access_token["token"], "api/external/request/customerReservedAccount/", $payvessel_create_reserve_account_array), true);

				if ($get_payvessel_reserve_account["status"] == "success") {
					$payvessel_reserve_account_response = json_decode($get_payvessel_reserve_account["json_result"], true);

					foreach ($payvessel_reserve_account_response["banks"] as $payvessel_accounts_json) {
						
						addUserVirtualBank($payvessel_accounts_json["trackingReference"], $payvessel_accounts_json["bankCode"], $payvessel_accounts_json["bankName"], $payvessel_accounts_json["accountNumber"], $payvessel_accounts_json["accountName"], $get_logged_user_details["vendor_id"], $get_logged_user_details["username"], 'payvessel');
						
					}
				}
			}
		}

		//Beewave Admin/User BVN Virtual Account Generation
		if ((!empty($select_vendor_table["nin"]) && is_numeric($select_vendor_table["nin"]) && strlen($select_vendor_table["nin"]) == 11) || (!empty($get_logged_user_details["nin"]) && is_numeric($get_logged_user_details["nin"]) && strlen($get_logged_user_details["nin"]) == 11) || (!empty($select_vendor_table["bvn"]) && is_numeric($select_vendor_table["bvn"]) && strlen($select_vendor_table["bvn"]) == 11) || (!empty($get_logged_user_details["bvn"]) && is_numeric($get_logged_user_details["bvn"]) && strlen($get_logged_user_details["bvn"]) == 11)) {
			$select_beewave_gateway_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_payment_gateways WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && gateway_name='beewave' LIMIT 1"));
			$user_beewave_account_reference = str_replace([".", "-", ":"], "", $_SERVER["HTTP_HOST"]) . "-" . $get_logged_user_details["username"] . "-" . $get_logged_user_details["email"];
			$beewave_create_reserve_account_array = array("email" => $user_beewave_account_reference, "name" => trim($get_logged_user_details["firstname"] . " " . $get_logged_user_details["lastname"] . " " . $get_logged_user_details["othername"]), "phone" => $get_logged_user_details["phone_number"], "access_key" => $select_beewave_gateway_details["public_key"], "bank_code" => ["110072"]);
			if (strlen($select_vendor_table_bvn) === 11) {
				$beewave_create_reserve_account_array["bvn"] = $select_vendor_table_bvn;
			}else{
				$beewave_create_reserve_account_array["bvn"] = $get_logged_user_details["bvn"];
			}
			if (strlen($select_vendor_table_nin) === 11) {
				$beewave_create_reserve_account_array["nin"] = $select_vendor_table_nin;
			}else{
				$beewave_create_reserve_account_array["nin"] = $get_logged_user_details["nin"];
			}
			$get_beewave_reserve_account = json_decode(makeBeewaveRequest("post", "s", "api/v1/bank-transfer/virtual-account-numbers", $beewave_create_reserve_account_array), true);
			
			if ($get_beewave_reserve_account["status"] == "success") {
				$beewave_reserve_account_response = json_decode($get_beewave_reserve_account["json_result"], true);

				foreach ($beewave_reserve_account_response["virtual_accounts"] as $beewave_accounts_json) {
					
					addUserVirtualBank($beewave_accounts_json["tracking_ref"], $beewave_accounts_json["bank_code"], $beewave_accounts_json["bank_name"], $beewave_accounts_json["account_number"], $beewave_accounts_json["account_name"], $get_logged_user_details["vendor_id"], $get_logged_user_details["username"], 'beewave');
				}
			}
		}

	}
}
?>
<!DOCTYPE html>

<head>
	<title>Dashboard | <?php echo $get_all_site_details["site_title"]; ?></title>
	<meta charset="UTF-8" />
	<meta name="description" content="<?php echo substr($get_all_site_details["site_desc"], 0, 160); ?>" />
	<meta http-equiv="Content-Type" content="text/html; " />
	<meta name="theme-color" content="black" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
	<link rel="stylesheet" href="/cssfile/bc-style.css">
	<meta name="author" content="Philmore Codes">
	<meta name="dc.creator" content="Philmore Codes">
	
	<!-- Google Fonts -->
  <link href="https://fonts.gstatic.com" rel="preconnect">
  <link
    href="https://fonts.googleapis.com/css?family=Open+Sans:300,300i,400,400i,600,600i,700,700i|Nunito:300,300i,400,400i,600,600i,700,700i|Poppins:300,300i,400,400i,500,500i,600,600i,700,700i"
    rel="stylesheet">

    <script src="https://merchant.beewave.ng/checkout.min.js"></script> 
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
  <style>
    .info-card.card-blue { background-color: #eef7ff; }
    .info-card.card-red { background-color: #fceeed; }
    .info-card.card-green { background-color: #eefcef; }
    .info-card.card-yellow { background-color: #fff9e6; }
    .info-card {
        border-radius: 15px;
    }
    .shadow {
        box-shadow: 0 4px 8px rgba(0,0,0,0.1) !important;
    }
    .auto-funding-container {
        display: flex;
        overflow-x: auto;
        white-space: nowrap;
        -webkit-overflow-scrolling: touch; /* for smooth scrolling on iOS */
        padding-bottom: 15px; /* space for scrollbar */
    }
    .auto-funding-container .bank-card {
        flex: 0 0 85%; /* Adjust width as needed, using percentage for responsiveness */
        max-width: 400px; /* Max width for larger screens */
        min-width: 300px; /* Min width for smaller screens */
        margin-right: 15px;
        display: inline-block;
        vertical-align: top;
        white-space: normal; /* Reset white-space for content inside the card */
        border-radius: 10px;
        box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    }
    .auto-funding-container .bank-card:last-child {
        margin-right: 0;
    }
  </style>

	<style>
    /* Glossy Light Fintech Theme */
    body {
        background-color: #f8fafc;
        color: #1e293b;
        font-family: 'Inter', 'Poppins', sans-serif;
    }
    .pagetitle h1 {
        color: #0f172a;
        font-weight: 700;
        letter-spacing: -0.5px;
    }
    .breadcrumb-item a { color: #2563eb; }

    .card {
        background: #ffffff !important;
        border: 1px solid rgba(0, 0, 0, 0.05) !important;
        box-shadow: 0 10px 25px rgba(0,0,0,0.05) !important;
        border-radius: 1.25rem;
    }
    .card-title, h5, h6 { color: #0f172a !important; }

    /* Wallet Hero Section */
    .wallet-hero, .balance-hero {
        background: linear-gradient(135deg, #ffffff 0%, #f1f5f9 100%); border-left: 5px solid <?php echo $vendor_primary_color ?? "#2563eb"; ?>;
        border: 1px solid rgba(0, 0, 0, 0.05);
        border-radius: 20px;
        color: #0f172a;
        padding: 2rem;
        box-shadow: 0 15px 35px rgba(0,0,0,0.05), inset 0 1px 0 rgba(255, 255, 255, 1);
        position: relative;
        overflow: hidden;
        margin-bottom: 1.5rem;
    }
    .wallet-hero::before, .balance-hero::before {
        content: '';
        position: absolute;
        top: -50%; right: -50%;
        width: 100%; height: 100%;
        background: radial-gradient(circle, rgba(37,99,235,0.05) 0%, transparent 60%);
        pointer-events: none;
    }
    .balance-title {
        font-size: 0.9rem;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: #64748b;
    }
    .balance-amount {
        font-size: 3rem;
        font-weight: 800;
        letter-spacing: -1px;
        color: #0f172a;
        text-shadow: none;
    }
    
    .wallet-metrics {
        display: flex;
        justify-content: space-between;
        margin-top: 1.5rem;
        border-top: 1px solid rgba(0,0,0,0.05);
        padding-top: 1.5rem;
    }
    .metric-item {
        text-align: center;
        flex: 1;
    }
    .metric-item h6 {
        font-size: 0.8rem;
        color: #64748b;
        margin-bottom: 0.2rem;
        text-transform: uppercase;
    }
    .metric-item h5 {
        font-size: 1.1rem;
        font-weight: 600;
        color: #0f172a;
        margin-bottom: 0;
    }

    /* Quick Actions */
    .quick-actions {
        display: flex;
        justify-content: space-between;
        margin: 2rem 0;
        gap: 1rem;
    }
    .action-btn, .quick-action-btn {
        flex: 1;
        background: #ffffff;
        border: 1px solid rgba(0, 0, 0, 0.05);
        border-radius: 16px;
        padding: 1.5rem 0.5rem;
        text-align: center;
        color: #1e293b;
        text-decoration: none;
        box-shadow: 0 4px 10px rgba(0,0,0,0.03);
        transition: all 0.3s ease;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        min-height: 70px;
    }
    .action-btn:hover, .quick-action-btn:hover {
        background: #f8fafc;
        border-color: rgba(37, 99, 235, 0.2);
        transform: translateY(-5px);
        color: #2563eb;
        box-shadow: 0 10px 20px rgba(0,0,0,0.08);
    }
    .action-btn .icon-circle {
        width: 50px;
        height: 50px;
        border-radius: 50%;
        background: linear-gradient(135deg, #eff6ff, #dbeafe);
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 0.8rem;
        font-size: 1.2rem;
        color: #2563eb;
        box-shadow: 0 4px 10px rgba(37, 99, 235, 0.1);
    }
    .action-btn span {
        font-size: 0.85rem;
        font-weight: 600;
    }
    .quick-action-btn i { font-size: 1.25rem; margin-bottom: 5px; color: #2563eb; }

    /* Services Grid */
    .service-card {
        background: #ffffff;
        border: 1px solid rgba(0, 0, 0, 0.05);
        border-radius: 16px;
        padding: 1.5rem;
        display: flex;
        flex-direction: column;
        align-items: center;
        text-decoration: none;
        color: #1e293b;
        transition: all 0.3s ease;
        margin-bottom: 1.5rem;
        box-shadow: 0 4px 6px rgba(0,0,0,0.02);
    }
    .service-card:hover {
        background: #ffffff;
        border-color: rgba(37, 99, 235, 0.2);
        transform: translateY(-5px);
        box-shadow: 0 15px 30px rgba(0,0,0,0.08);
    }
    .service-card .service-icon {
        width: 45px;
        height: 45px;
        border-radius: 12px;
        background: #eff6ff;
        color: #2563eb;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.4rem;
        margin-right: 1rem;
    }
    .service-card h5 {
        margin: 0;
        font-size: 1.05rem;
        font-weight: 600;
    }

    .service-icon-box {
        width: 56px;
        height: 56px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 0.75rem;
        font-size: 1.5rem;
        background: #eff6ff;
        color: #2563eb;
    }
    
    /* Specific icons */
    .icon-data { color: #3b82f6; background: #eff6ff; }
    .icon-airtime { color: #ef4444; background: #fef2f2; }
    .icon-cable { color: #db2777; background: #fdf2f7; }
    .icon-electric { color: #f59e0b; background: #fffbeb; }
    .icon-transfer { color: #287bff; background: #eef2ff; }
    .icon-exam { color: #8b5cf6; background: #f5f3ff; }
    .icon-betting { color: #f97316; background: #fff7ed; }
    .icon-crypto { color: #f59e0b; background: #fff7ed; }
    .icon-vcard { color: #3b82f6; background: #eef2ff; }
    .icon-giftcard { color: #db2777; background: #fdf2f7; }
    .icon-nin { color: #8b5cf6; background: #f5f3ff; }
    .icon-bvn { color: #3b82f6; background: #eef2ff; }
    .icon-more { color: #64748b; background: #f8fafc; }

    /* Modern Glassmorphism Service Cards (NON-SAAS) */
    .service-quick-card {
        background: #ffffff;
        border: 1px solid rgba(0, 0, 0, 0.05);
        border-radius: 1.5rem;
        padding: 1.5rem;
        transition: all 0.3s ease;
        height: 100%;
        color: #1e293b;
        box-shadow: 0 4px 10px rgba(0,0,0,0.03);
    }
    .service-quick-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 15px 30px rgba(0,0,0,0.08);
        border-color: rgba(37, 99, 235, 0.2);
    }
    .service-quick-card h6 { color: #0f172a !important; }

    /* Auto Funding Cards */
    .auto-funding-container {
        display: flex;
        overflow-x: auto;
        white-space: nowrap;
        -webkit-overflow-scrolling: touch;
        padding: 1rem 0 2rem;
        gap: 1.5rem;
    }
    .auto-funding-container::-webkit-scrollbar {
        height: 6px;
    }
    .auto-funding-container::-webkit-scrollbar-track {
        background: rgba(0,0,0,0.05);
        border-radius: 4px;
    }
    .auto-funding-container::-webkit-scrollbar-thumb {
        background: rgba(0,0,0,0.15);
        border-radius: 4px;
    }
    .bank-card {
        flex: 0 0 320px;
        background: #ffffff;
        border: 1px solid rgba(0,0,0,0.05);
        border-radius: 16px;
        padding: 1.5rem;
        position: relative;
        overflow: hidden;
        white-space: normal;
        box-shadow: 0 10px 25px rgba(0,0,0,0.05);
    }
    .bank-card::after {
        content: '';
        position: absolute;
        top: 0; right: 0;
        width: 150px; height: 150px;
        background: radial-gradient(circle, rgba(37,99,235,0.05) 0%, transparent 60%);
        border-radius: 50%;
        transform: translate(30%, -30%);
    }
    .bank-card .bank-name {
        color: #64748b;
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 1px;
        margin-bottom: 0.5rem;
    }
    .bank-card .account-number {
        font-size: 1.5rem;
        font-weight: 700;
        color: #0f172a;
        letter-spacing: 2px;
        margin-bottom: 1rem;
        font-family: 'Courier New', Courier, monospace;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .bank-card .account-name {
        color: #1e293b;
        font-size: 0.95rem;
        font-weight: 600;
    }
    .bank-card .fee {
        position: absolute;
        bottom: 1.5rem;
        right: 1.5rem;
        font-size: 0.8rem;
        color: #64748b;
        text-align: right;
    }
    .copy-btn {
        cursor: pointer;
        color: #2563eb;
        background: #eff6ff;
        padding: 5px;
        border-radius: 6px;
        transition: background 0.2s;
    }
    .copy-btn:hover { background: #dbeafe; }

    .stats-label { font-size: 0.875rem; color: #64748b; font-weight: 500; }
    .stats-value { font-size: 1.25rem; font-weight: 700; color: #0f172a; word-break: break-all; }

    @media (max-width: 767px) {
        .balance-amount { font-size: 2rem; }
        .balance-hero, .wallet-hero { padding: 1.25rem; }
        .pagetitle { display: none; }
        .quick-action-btn, .action-btn { font-size: 0.65rem; padding: 0.4rem; }
    }
    
    /* Text overrides for light mode */
    .text-dark { color: #0f172a !important; }
    .text-muted { color: #64748b !important; }
	</style>

</head>

<body>
	<?php include("../func/bc-header.php"); ?>
	
		<div class="pagetitle">
      <h1>OVERVIEW</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="#">Home</a></li>
          <li class="breadcrumb-item active">Dashboard</li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
      <div class="row">
        
        <!-- Wallet Hero Section (Combining all 4 metrics into one premium card) -->
        <div class="col-12 mb-4">
            <div class="wallet-hero">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <span class="balance-title">Current Balance</span>
                    <span class="badge bg-primary px-3 py-2 rounded-pill"><?php echo accountLevel($get_logged_user_details["account_level"]); ?></span>
                </div>
                <div class="balance-amount">₦<?php echo toDecimal($get_logged_user_details["balance"], "2"); ?></div>
                

            </div>
        </div>

        <!-- Quick Actions Row -->
        <div class="col-12 mb-4">
            <h5 class="text-muted fw-bold mb-3" style="font-size: 0.9rem; letter-spacing: 1px;">QUICK ACTIONS</h5>
            <div class="quick-actions flex-wrap flex-md-nowrap">
                <a href="Fund.php" class="action-btn">
                    <div class="icon-circle" style="background: #eef2ff; color: #287bff; box-shadow: 0 4px 10px rgba(40,123,255,0.1);"><i class="bi bi-wallet2"></i></div>
                    <span>Fund Wallet</span>
                </a>
                <a href="ShareFund.php" class="action-btn">
                    <div class="icon-circle" style="background: #fdf2f7; color: #db2777; box-shadow: 0 4px 10px rgba(219,39,119,0.1);"><i class="bi bi-send"></i></div>
                    <span>Transfer</span>
                </a>
                <a href="SubmitPayment.php" class="action-btn">
                    <div class="icon-circle" style="background: #fffbeb; color: #f59e0b; box-shadow: 0 4px 10px rgba(245,158,11,0.1);"><i class="bi bi-receipt"></i></div>
                    <span>Pay Bill</span>
                </a>
                <a href="Transactions.php" class="action-btn">
                    <div class="icon-circle" style="background: #f5f3ff; color: #8b5cf6; box-shadow: 0 4px 10px rgba(139,92,246,0.1);"><i class="bi bi-clock-history"></i></div>
                    <span>History</span>
                </a>
            </div>
        </div>

        <!-- Services Grid -->
        <div class="col-12 mb-4">
            <h5 class="text-muted fw-bold mb-3" style="font-size: 0.9rem; letter-spacing: 1px;">SERVICES</h5>
            <div class="row">
                <div class="col-6 col-md-3">
                    <a href="Data.php" class="service-card flex-column text-center px-2 py-4">
                        <div class="service-icon mb-3 me-0"><i class="bi bi-wifi"></i></div>
                        <h5>Buy Data</h5>
                    </a>
                </div>
                <div class="col-6 col-md-3">
                    <a href="Airtime.php" class="service-card flex-column text-center px-2 py-4">
                        <div class="service-icon mb-3 me-0"><i class="bi bi-telephone"></i></div>
                        <h5>Buy Airtime</h5>
                    </a>
                </div>
                <div class="col-6 col-md-3">
                    <a href="Cable.php" class="service-card flex-column text-center px-2 py-4">
                        <div class="service-icon mb-3 me-0"><i class="bi bi-tv"></i></div>
                        <h5>Cable TV</h5>
                    </a>
                </div>
                <div class="col-6 col-md-3">
                    <a href="Electric.php" class="service-card flex-column text-center px-2 py-4">
                        <div class="service-icon mb-3 me-0"><i class="bi bi-lightbulb"></i></div>
                        <h5>Electricity</h5>
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Row for Auto Funding -->




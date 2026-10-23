<?php session_start();
	include(__DIR__."/func/bc-connect.php");
	
	$catch_incoming_request = json_decode(file_get_contents("php://input"),true);
	//Select Vendor Table
    $vendor_id = resolveVendorID();
	$select_vendor_table = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id' AND status=1 LIMIT 1"));
	// A webhook is a server-to-server call, so it arrives on whatever host the provider has been
	// configured with - which is not necessarily the vendor's website_url (a "www." prefix, another
	// subdomain, the portal domain). resolveVendorID() then answers 0, and this file credited
	// nothing and logged nothing. The checkout row for the reference being reported names the vendor
	// exactly, so fall back to it.
	$flw_posted_ref = trim((string)($catch_incoming_request["data"]["tx_ref"] ?? ($catch_incoming_request["data"]["reference"] ?? "")));
	if(!$select_vendor_table && $flw_posted_ref !== ""){
		$flw_ref_esc_lookup = mysqli_real_escape_string($connection_server, $flw_posted_ref);
		$q_flw_vendor = mysqli_query($connection_server, "SELECT vendor_id FROM sas_user_payment_checkouts WHERE reference='$flw_ref_esc_lookup' LIMIT 1");
		if($q_flw_vendor && ($r_flw_vendor = mysqli_fetch_assoc($q_flw_vendor))){
			$vendor_id = (int) $r_flw_vendor["vendor_id"];
			$select_vendor_table = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id' LIMIT 1"));
			error_log("Flutterwave webhook: host did not resolve, vendor recovered from the checkout row (vendor=$vendor_id ref=$flw_posted_ref)");
		}
	}
	if(!$select_vendor_table){
		error_log("Flutterwave webhook: could not resolve a vendor for ref='" . $flw_posted_ref . "' - nothing credited");
	}
	if($select_vendor_table){
		$flutterwave_keys = mysqli_fetch_assoc(mysqli_query($connection_server,"SELECT * FROM sas_payment_gateways WHERE vendor_id='".$select_vendor_table["id"]."' && gateway_name='flutterwave'"));
		
		$flutterwave_verify_transaction = json_decode(confirmPaymentDeposited("GET","https://api.flutterwave.com/v3/transactions/".$catch_incoming_request["data"]["id"]."/verify",["Authorization: Bearer ".$flutterwave_keys["secret_key"]],""),true);

		// ── Authenticate the callback, and take the amounts from Flutterwave - never from the body ──
		// Anyone can POST a "successful" payload here. The old code credited
		// $catch_incoming_request["data"]["charged_amount"], which the caller controls, while the verify
		// call only proved that *some* transaction id had succeeded - so a real 20-naira charge could be
		// replayed with a 200,000 amount, or one id paired with somebody else's tx_ref.
		// 1) verif-hash, when the merchant has configured one (stored as the gateway "encrypt key").
		$flw_secret_hash = trim((string)($flutterwave_keys['encrypt_key'] ?? ''));
		if ($flw_secret_hash !== '') {
			$flw_signature = $_SERVER['HTTP_VERIF_HASH'] ?? '';
			if (empty($flw_signature) || !hash_equals($flw_secret_hash, (string)$flw_signature)) {
				http_response_code(401);
				exit("Invalid signature");
			}
		}
		// 2) The verified transaction itself.
		$flw_verified = (isset($flutterwave_verify_transaction['data']) && is_array($flutterwave_verify_transaction['data'])) ? $flutterwave_verify_transaction['data'] : array();
		$flw_verified_ok = (!empty($flutterwave_verify_transaction['status'])
			&& in_array(strtolower((string)($flw_verified['status'] ?? '')), array('successful', 'success'), true)
			&& (float)($flw_verified['amount'] ?? 0) > 0);
		$flw_verified_ref = trim((string)($flw_verified['tx_ref'] ?? ''));
		
		$customer_name = $catch_incoming_request["data"]["customer"]["name"];
		$customer_phone_number = $catch_incoming_request["data"]["customer"]["phone_number"];
		$customer_email = $catch_incoming_request["data"]["customer"]["email"];
		$amount_paid = (float)($flw_verified["amount"] ?? 0);
		$amount_deposited = $amount_paid - (float)($flw_verified["app_fee"] ?? 0);
		if ($amount_deposited < 0) $amount_deposited = $amount_paid;
		$transaction_id = $catch_incoming_request["data"]["tx_ref"];
		// The verified transaction must be the one we are about to credit: a valid transaction id paired
		// with another reference used to be accepted, letting an attacker choose whose wallet is funded.
		if ($flw_verified_ref === '' || $flw_verified_ref !== (string)$transaction_id) {
			error_log("SECURITY: Flutterwave webhook ref mismatch (verified='$flw_verified_ref' posted='$transaction_id') vendor=$vendor_id");
			http_response_code(400);
			exit("Reference mismatch");
		}
		$payment_method = $catch_incoming_request["data"]["payment_type"];
		$vendor_id = trim($select_vendor_table["id"]);
		$check_if_pre_payment_exists = mysqli_query($connection_server, "SELECT * FROM sas_user_payment_checkouts WHERE vendor_id='$vendor_id' && reference='$transaction_id'");

		if(mysqli_num_rows($check_if_pre_payment_exists) == 1){
			$get_payment_details = mysqli_fetch_array($check_if_pre_payment_exists);
			$user_id = $get_payment_details["username"];
			$reference = substr(str_shuffle("12345678901234567890"), 0, 15);
			$check_vendor_user_exists = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' && username='$user_id'");
			if(mysqli_num_rows($check_vendor_user_exists) == 1){
				$get_logged_user_details = mysqli_fetch_array($check_vendor_user_exists);
				$_SESSION["user_session"] = $get_logged_user_details["username"];
			
				$select_transaction_history = mysqli_query($connection_server,"SELECT * FROM sas_transactions WHERE (api_reference='$transaction_id')");
			
				if($flw_verified_ok){
					if(mysqli_num_rows($select_transaction_history) == 0){
						chargeUser("credit", $_SESSION["user_session"], "Wallet Credit", $reference, $transaction_id, $amount_paid, $amount_deposited, "Flutterwave Wallet Credit - ".str_replace("_"," ",$payment_method), strtoupper("WEB"), $_SERVER["HTTP_HOST"], "1");
						unset($_SESSION["user_session"]);
					}
				}
			}
		}
	}

	function confirmPaymentDeposited($method,$url,$header,$json){
		$apiwalletBalance = curl_init($url);
		// cURL waits forever by default - bound the connect and the whole call so one slow
		// provider cannot hold this PHP worker open indefinitely.
		curl_setopt($apiwalletBalance, CURLOPT_CONNECTTIMEOUT, 10);
		curl_setopt($apiwalletBalance, CURLOPT_TIMEOUT, 30);
		$apiwalletBalanceUrl = $url;
		curl_setopt($apiwalletBalance,CURLOPT_URL,$apiwalletBalanceUrl);
		curl_setopt($apiwalletBalance,CURLOPT_RETURNTRANSFER,true);
		if($method == "POST"){
			curl_setopt($apiwalletBalance,CURLOPT_POST,true);
		}
		
		if($method == "GET"){
		curl_setopt($apiwalletBalance,CURLOPT_HTTPGET,true);
		}
		
		if($header == true){
			curl_setopt($apiwalletBalance,CURLOPT_HTTPHEADER,$header);
		}
		if($json == true){
			curl_setopt($apiwalletBalance,CURLOPT_POSTFIELDS,$json);
		}
		curl_setopt($apiwalletBalance, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($apiwalletBalance, CURLOPT_SSL_VERIFYPEER, false);
		
		$GetAPIBalanceJSON = curl_exec($apiwalletBalance);
		return $GetAPIBalanceJSON;
	}
?>
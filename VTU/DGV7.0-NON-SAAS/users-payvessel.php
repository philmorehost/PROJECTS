<?php session_start();
	include(__DIR__."/func/bc-connect.php");
	
	$catch_incoming_request = json_decode(file_get_contents("php://input"),true);
	//Select Vendor Table
	$vendor_id = resolveVendorID();
	$select_vendor_table = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id' AND status=1 LIMIT 1"));
	if ($select_vendor_table) {
		$payvessel_keys = mysqli_fetch_assoc(mysqli_query($connection_server,"SELECT * FROM sas_payment_gateways WHERE vendor_id='".$select_vendor_table["id"]."' && gateway_name='payvessel'"));
		
		//$payvessel_verify_transaction = json_decode(confirmPaymentDeposited("GET","https://api.payvessel.co/transaction/verify/".$catch_incoming_request["data"]["reference"],["Authorization: Bearer ".$payvessel_keys["secret_key"]],""),true);
		
		//Payvessel Line
		$payload = file_get_contents('php://input');
		$payvessel_signature = $_SERVER['HTTP_PAYVESSEL_HTTP_SIGNATURE'];
		//this line maybe be differ depends on your server
		//$ip_address = $_SERVER['HTTP_X_FORWARDED_FOR']; 
		$ip_address = $_SERVER['REMOTE_ADDR']; 
		$secret = $payvessel_keys["secret_key"];
		$hashkey = hash_hmac('sha512', $payload, $secret);
		//Payvessel End
		
		$webhook = "\n PAYLOAD:".$payload."\n IP: ".$ip_address."\n HASH:".$hashkey."\n SIGNATURE:".$payvessel_signature;
		fwrite(fopen("upayvessel-webhook.txt", "a++"), $webhook);
		//echo $webhook;
		
		$customer_name = "";
		$customer_phone_number = $catch_incoming_request["customer"]["phone"];
		$customer_email = $catch_incoming_request["customer"]["email"];
		$amount_paid = floatval($catch_incoming_request['order']['amount']);
		$amount_deposited = floatval($catch_incoming_request['order']['settlement_amount']);
		$transaction_id = $catch_incoming_request["transaction"]["reference"];
		$virtual_accountno = $catch_incoming_request["virtualAccount"]["virtualAccountNumber"];
		$virtual_bankcode = $catch_incoming_request["virtualAccount"]["virtualBank"];
		$payment_method = "BANK TRANSFER";
		// Limiting explode to 3 parts keeps index 1 (username) reliable and captures the real email
		// intact in index 2 even when that email itself contains a hyphen.
		$exp_customer_detail = explode("-", trim($customer_email), 3);
		$customer_username = isset($exp_customer_detail[1]) ? $exp_customer_detail[1] : '';
		$customer_mail = isset($exp_customer_detail[2]) ? $exp_customer_detail[2] : $customer_email;
		$vendor_id = trim($select_vendor_table["id"]);
		
		$check_if_banks_exists = mysqli_query($connection_server, "SELECT * FROM sas_user_banks WHERE vendor_id='$vendor_id' && username='$customer_username' && account_number='$virtual_accountno' && bank_code='$virtual_bankcode'");

		if(mysqli_num_rows($check_if_banks_exists) == 1){
			$get_payment_details = mysqli_fetch_array($check_if_banks_exists);
			$user_id = $get_payment_details["username"];
			$reference = substr(str_shuffle("12345678901234567890"), 0, 15);
			$check_vendor_user_exists = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' && username='$user_id'");
			if(mysqli_num_rows($check_vendor_user_exists) == 1){
				$get_logged_user_details = mysqli_fetch_array($check_vendor_user_exists);
				$_SESSION["user_session"] = $get_logged_user_details["username"];
			
				$select_transaction_history = mysqli_query($connection_server,"SELECT * FROM sas_transactions WHERE (reference='$transaction_id' OR api_reference='$transaction_id')");
				
				if(($payvessel_signature == $hashkey) && ($ip_address == "162.246.254.36") && ($catch_incoming_request["message"] == "Success")) {
					if(mysqli_num_rows($select_transaction_history) == 0){
						chargeUser("credit", $_SESSION["user_session"], "Wallet Credit", $reference, $transaction_id, $amount_paid, $amount_deposited, "Payvessel Wallet Credit - ".str_replace("_"," ",$payment_method), strtoupper("WEB"), $_SERVER["HTTP_HOST"], "1");
						unset($_SESSION["user_session"]);
					}
				}
			}
		}
	}
	
	function generateSignature($data, $secret_key) {
		$secret = $secret_key;
		$signature = hash_hmac('sha512', json_encode($data), $secret);
		return $signature;
	}
	
	function confirmPaymentDeposited($method,$url,$header,$json){
		$apiwalletBalance = curl_init($url);
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
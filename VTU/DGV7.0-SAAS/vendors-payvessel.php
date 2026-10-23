<?php session_start();
	include(__DIR__."/func/bc-connect.php");
	
	$catch_incoming_request = json_decode(file_get_contents("php://input"),true);
	$payvessel_keys = mysqli_fetch_assoc(mysqli_query($connection_server,"SELECT * FROM sas_super_admin_payment_gateways WHERE gateway_name='payvessel'"));
	
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
	// Limiting explode to 3 parts keeps index 1 (customer_id) reliable and captures the real email
	// intact in index 2 even when that email itself contains a hyphen — the previous array_filter()
	// split silently dropped the trailing segment whenever it was empty, causing "Undefined array key 2".
	$exp_customer_detail = explode("-", trim($customer_email), 3);
	$customer_id = $exp_customer_detail[1] ?? "";
	$customer_mail = $exp_customer_detail[2] ?? "";
	
	$check_if_banks_exists = mysqli_query($connection_server, "SELECT * FROM sas_vendor_banks WHERE vendor_id='$customer_id' && account_number='$virtual_accountno' && bank_code='$virtual_bankcode'");
	
	if(mysqli_num_rows($check_if_banks_exists) == 1){
		$get_payment_details = mysqli_fetch_array($check_if_banks_exists);
		$vendor_id = trim($get_payment_details["vendor_id"]);
		$reference = substr(str_shuffle("12345678901234567890"), 0, 15);
		$check_vendor_user_exists = mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id'");
		if(mysqli_num_rows($check_vendor_user_exists) == 1){
			$get_logged_admin_details = mysqli_fetch_array($check_vendor_user_exists);
			$_SESSION["admin_session"] = $get_logged_admin_details["email"];
			
			$select_transaction_history = mysqli_query($connection_server,"SELECT * FROM sas_vendor_transactions WHERE vendor_id='".$get_logged_admin_details["id"]."' && reference='$transaction_id'");
			
			if(($payvessel_signature == $hashkey) && ($ip_address == "162.246.254.36") && ($catch_incoming_request["message"] == "Success")) {
                $vtx = mysqli_fetch_assoc($select_transaction_history);
				if(!$vtx || $vtx['status'] != 1){
                    if ($vtx && $vtx['product_unique_id'] == 'plisio_activation') {
                        mysqli_query($connection_server, "UPDATE sas_vendors SET plisio_activated=1 WHERE id='$vendor_id'");
                        mysqli_query($connection_server, "UPDATE sas_vendor_transactions SET status=1, discounted_amount='$amount_deposited' WHERE id='".$vtx['id']."'");
                    } elseif ($vtx && $vtx['product_unique_id'] == 'payout_activation') {
                        mysqli_query($connection_server, "UPDATE sas_vendors SET payout_activated=1 WHERE id='$vendor_id'");
                        mysqli_query($connection_server, "UPDATE sas_vendor_transactions SET status=1, discounted_amount='$amount_deposited' WHERE id='".$vtx['id']."'");
                    } else {
					    chargeVendor("credit", $_SESSION["admin_session"], "Wallet Credit", $transaction_id, $amount_paid, $amount_deposited, "Payvessel Wallet Credit - ".str_replace("_"," ",$payment_method), $_SERVER["HTTP_HOST"], "1");
                    }
					unset($_SESSION["admin_session"]);
				}
			}
		}
	}
	
	function confirmPaymentDeposited($method,$url,$header,$json){
		$apiwalletBalance = curl_init($url);
// cURL waits forever by default - bound the connect and the whole call so one slow provider cannot
// hold this PHP worker open indefinitely.
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
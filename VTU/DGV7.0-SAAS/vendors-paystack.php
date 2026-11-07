<?php session_start();
	include(__DIR__."/func/bc-connect.php");
	
	$catch_incoming_request = json_decode(file_get_contents("php://input"),true);
	$paystack_keys = mysqli_fetch_assoc(mysqli_query($connection_server,"SELECT * FROM sas_super_admin_payment_gateways WHERE gateway_name='paystack'"));
	
	$paystack_verify_transaction = json_decode(confirmPaymentDeposited("GET","https://api.paystack.co/transaction/verify/".$catch_incoming_request["data"]["reference"],["Authorization: Bearer ".$paystack_keys["secret_key"]],""),true);
	
	$customer_name = $catch_incoming_request["data"]["customer"]["first_name"];
	$customer_phone_number = $catch_incoming_request["data"]["customer"]["phone"];
	$customer_email = $catch_incoming_request["data"]["customer"]["email"];
	$amount_paid = ($catch_incoming_request["data"]["amount"]/100);
	$amount_deposited = (($catch_incoming_request["data"]["amount"]/100)-($catch_incoming_request["data"]["fees"]/100));
	$transaction_id = $catch_incoming_request["data"]["reference"];
	$payment_method = $catch_incoming_request["data"]["channel"];
	
	$check_if_pre_payment_exists = mysqli_query($connection_server, "SELECT * FROM sas_vendor_payment_checkouts WHERE reference='$transaction_id'");

	if(mysqli_num_rows($check_if_pre_payment_exists) == 1){
		$get_payment_details = mysqli_fetch_array($check_if_pre_payment_exists);
		$vendor_id = trim($get_payment_details["vendor_id"]);
		$reference = substr(str_shuffle("12345678901234567890"), 0, 15);
		$check_vendor_user_exists = mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id'");
		if(mysqli_num_rows($check_vendor_user_exists) == 1){
			$get_logged_admin_details = mysqli_fetch_array($check_vendor_user_exists);
			$_SESSION["admin_session"] = $get_logged_admin_details["email"];
			
			$select_transaction_history = mysqli_query($connection_server,"SELECT * FROM sas_vendor_transactions WHERE vendor_id='".$get_logged_admin_details["id"]."' && reference='$transaction_id'");
			
			if(($paystack_verify_transaction["data"]["status"] == "success") && ($catch_incoming_request["data"]["status"] == "success")){
                $vtx = mysqli_fetch_assoc($select_transaction_history);
				if(!$vtx || $vtx['status'] != 1){

                    if ($vtx && $vtx['product_unique_id'] == 'plisio_activation') {
                        mysqli_query($connection_server, "UPDATE sas_vendors SET plisio_activated=1 WHERE id='$vendor_id'");
                        mysqli_query($connection_server, "UPDATE sas_vendor_transactions SET status=1, discounted_amount='$amount_deposited' WHERE id='".$vtx['id']."'");
                    } elseif ($vtx && $vtx['product_unique_id'] == 'payout_activation') {
                        mysqli_query($connection_server, "UPDATE sas_vendors SET payout_activated=1 WHERE id='$vendor_id'");
                        mysqli_query($connection_server, "UPDATE sas_vendor_transactions SET status=1, discounted_amount='$amount_deposited' WHERE id='".$vtx['id']."'");
                    } else {
					    chargeVendor("credit", $_SESSION["admin_session"], "Wallet Credit", $transaction_id, $amount_paid, $amount_deposited, "Paystack Wallet Credit - ".str_replace("_"," ",$payment_method), $_SERVER["HTTP_HOST"], "1");
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
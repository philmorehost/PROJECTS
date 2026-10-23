<?php session_start();
	include(__DIR__."/func/bc-connect.php");
	
	$body = file_get_contents("php://input");
	$catch_incoming_request = json_decode($body, true);

    if (!$catch_incoming_request) {
        http_response_code(400);
        die("Invalid payload");
    }

    $event_data = $catch_incoming_request["eventData"] ?? $catch_incoming_request;
    $transaction_ref = $event_data["transactionReference"] ?? $event_data["paymentReference"] ?? "";
    $product_type = $event_data["product"]["type"] ?? $catch_incoming_request["product"]["type"] ?? "";
    $product_reference = $event_data["product"]["reference"] ?? $catch_incoming_request["product"]["reference"] ?? "";

	$monnify_keys = mysqli_fetch_assoc(mysqli_query($connection_server,"SELECT * FROM sas_super_admin_payment_gateways WHERE gateway_name='monnify'"));

    // Verify Signature
    $client_sig = $_SERVER['HTTP_MONNIFY_SIGNATURE'] ?? '';
    $secret = $monnify_keys['secret_key'] ?? '';
    if (!empty($secret) && !empty($client_sig)) {
        $computed_sig = hash_hmac('sha512', $body, $secret);
        if ($client_sig !== $computed_sig) {
            error_log("Monnify Vendor Webhook Signature Mismatch. Ref: $transaction_ref");
        }
    }

	$monnifyApiUrl = "https://api.monnify.com/api/v1/auth/login";
	$monnifyAPILogin = curl_init($monnifyApiUrl);
	curl_setopt($monnifyAPILogin,CURLOPT_POST,true);
	curl_setopt($monnifyAPILogin,CURLOPT_RETURNTRANSFER,true);
	$monnifyLoginHeader = array("Authorization: Basic ".base64_encode($monnify_keys["public_key"].':'.$monnify_keys["secret_key"]),"Content-Type: application/json","Content-Length: 0");
	curl_setopt($monnifyAPILogin,CURLOPT_HTTPHEADER,$monnifyLoginHeader);
	curl_setopt($monnifyAPILogin, CURLOPT_SSL_VERIFYHOST, false);
	curl_setopt($monnifyAPILogin, CURLOPT_SSL_VERIFYPEER, false);
	
	$GetMonnifyJSON = curl_exec($monnifyAPILogin);
	$monnifyJSONObj = json_decode($GetMonnifyJSON,true);
	$access_token = $monnifyJSONObj["responseBody"]["accessToken"] ?? "";

    if ($access_token) {
        $verify_url = "https://api.monnify.com/api/v2/transactions/" . urlencode($transaction_ref);
        $monnify_verify_transaction = json_decode(confirmPaymentDeposited("GET", $verify_url, ["Authorization: Bearer ".$access_token], ""), true);

        $pay_status = $monnify_verify_transaction["responseBody"]["paymentStatus"] ?? $event_data["paymentStatus"] ?? "";

        if($pay_status == "PAID") {
            $amount_paid = $event_data["totalPayable"] ?? $event_data["amountPaid"] ?? 0;
            $amount_deposited = $event_data["settlementAmount"] ?? $amount_paid;
            $payment_method = $event_data["paymentMethod"] ?? "UNKNOWN";

            $vendor_id = 0;
            if ($product_type == "RESERVED_ACCOUNT") {
                $q_bank = mysqli_query($connection_server, "SELECT id FROM sas_vendor_banks WHERE reference='".mysqli_real_escape_string($connection_server, $product_reference)."' LIMIT 1");
                if ($r_bank = mysqli_fetch_assoc($q_bank)) $vendor_id = $r_bank['id'];
            } else {
                $q_checkout = mysqli_query($connection_server, "SELECT vendor_id FROM sas_vendor_payment_checkouts WHERE reference='".mysqli_real_escape_string($connection_server, $transaction_ref)."' LIMIT 1");
                if ($r_checkout = mysqli_fetch_assoc($q_checkout)) $vendor_id = $r_checkout['vendor_id'];
            }

            if ($vendor_id > 0) {
                $GLOBALS['vendor_id'] = $vendor_id;
                $check_v = mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id' LIMIT 1");
                if ($r_v = mysqli_fetch_assoc($check_v)) {
                    $admin_email = $r_v["email"];
                    $select_transaction_history = mysqli_query($connection_server,"SELECT * FROM sas_vendor_transactions WHERE vendor_id='$vendor_id' && reference='$transaction_ref'");

                    $vtx = mysqli_fetch_assoc($select_transaction_history);
                    if(!$vtx || $vtx['status'] != 1){
                        if ($vtx && $vtx['product_unique_id'] == 'plisio_activation') {
                             mysqli_query($connection_server, "UPDATE sas_vendors SET plisio_activated=1 WHERE id='$vendor_id'");
                             mysqli_query($connection_server, "UPDATE sas_vendor_transactions SET status=1, discounted_amount='$amount_deposited' WHERE id='".$vtx['id']."'");
                        } elseif ($vtx && $vtx['product_unique_id'] == 'payout_activation') {
                             mysqli_query($connection_server, "UPDATE sas_vendors SET payout_activated=1 WHERE id='$vendor_id'");
                             mysqli_query($connection_server, "UPDATE sas_vendor_transactions SET status=1, discounted_amount='$amount_deposited' WHERE id='".$vtx['id']."'");
                        } else {
                             chargeVendor("credit", $admin_email, "Wallet Credit", $transaction_ref, $amount_paid, $amount_deposited, "Monnify Wallet Credit - ".str_replace("_"," ",$payment_method), $_SERVER["HTTP_HOST"] ?? "CRON", "1");
                        }
                        echo "SUCCESS";
                    } else {
                        echo "ALREADY_PROCESSED";
                    }
                }
            } else {
                error_log("Monnify Vendor not found for ref $product_reference or trx $transaction_ref");
            }
        }
    }

	function confirmPaymentDeposited($method,$url,$header,$json){
		$ch = curl_init($url);
		curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
		if($method == "POST") curl_setopt($ch,CURLOPT_POST,true);
		if($method == "GET") curl_setopt($ch,CURLOPT_HTTPGET,true);
		if($header) curl_setopt($ch,CURLOPT_HTTPHEADER,$header);
		if($json) curl_setopt($ch,CURLOPT_POSTFIELDS,$json);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		$res = curl_exec($ch);
		curl_close($ch);
		return $res;
	}
?>
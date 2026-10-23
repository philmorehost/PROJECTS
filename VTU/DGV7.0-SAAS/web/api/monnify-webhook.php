<?php session_start();
	include_once("../../func/bc-connect.php");

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

	// Robust Vendor Identification
	$vendor_id = resolveVendorID();
	$select_vendor_table = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id' AND status=1 LIMIT 1"));

    if (!$select_vendor_table) {
        // Try to identify via product reference (accountReference)
        if (!empty($product_reference)) {
            $ref_esc = mysqli_real_escape_string($connection_server, $product_reference);
            $q = mysqli_query($connection_server, "SELECT vendor_id FROM sas_user_banks WHERE reference='$ref_esc' LIMIT 1");
            if ($r = mysqli_fetch_assoc($q)) {
                $vendor_id = $r['vendor_id'];
                $select_vendor_table = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id' LIMIT 1"));
            }
        }

        // Fallback for checkout transactions
        if (!$select_vendor_table && !empty($transaction_ref)) {
            $trx_esc = mysqli_real_escape_string($connection_server, $transaction_ref);
            $q_c = mysqli_query($connection_server, "SELECT vendor_id FROM sas_user_payment_checkouts WHERE reference='$trx_esc' LIMIT 1");
            if ($r_c = mysqli_fetch_assoc($q_c)) {
                $vendor_id = $r_c['vendor_id'];
                $select_vendor_table = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id' LIMIT 1"));
            }
        }
    }

	if($select_vendor_table && $select_vendor_table["status"] == 1){
        $vendor_id = $select_vendor_table["id"];
        $GLOBALS['vendor_id'] = $vendor_id;
		$monnify_keys = mysqli_fetch_assoc(mysqli_query($connection_server,"SELECT * FROM sas_payment_gateways WHERE vendor_id='$vendor_id' && gateway_name='monnify'"));

        // Verify Signature
        $client_sig = $_SERVER['HTTP_MONNIFY_SIGNATURE'] ?? '';
        $secret = !empty($monnify_keys['webhook_secret']) ? $monnify_keys['webhook_secret'] : ($monnify_keys['secret_key'] ?? '');
        if (!empty($secret)) {
            $computed_sig = hash_hmac('sha512', $body, $secret);
            if (empty($client_sig) || !hash_equals($computed_sig, $client_sig)) {
                error_log("SECURITY: Monnify webhook signature mismatch/missing for vendor $vendor_id. Ref: $transaction_ref");
                http_response_code(401);
                die("Invalid signature");
                // Mandatory: a callback that cannot be authenticated must never reach the credit path.
            }
        }

        // Verify via API
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

            // The payment status must come from Monnify's own API response: falling back to the POSTED
            // paymentStatus let a caller declare an unpaid reference PAID.
            $pay_status = $monnify_verify_transaction["responseBody"]["paymentStatus"] ?? "";

            if($pay_status == "PAID") {
                $monnify_verified_body = (isset($monnify_verify_transaction['responseBody']) && is_array($monnify_verify_transaction['responseBody'])) ? $monnify_verify_transaction['responseBody'] : array();
                $amount_paid = (float)($monnify_verified_body["amountPaid"] ?? $monnify_verified_body["totalPayable"] ?? $monnify_verified_body["settlementAmount"] ?? 0);
                if ($amount_paid <= 0) {
                    error_log("SECURITY: Monnify webhook verified without a usable amount. vendor=$vendor_id ref=$transaction_ref");
                    http_response_code(200);
                    exit("Ignored: no verified amount");
                }

                // Implement Charges correctly
                $charge_percent = (float)($monnify_keys['percentage'] ?? 0);
                $amount_deposited = $amount_paid * (1 - ($charge_percent / 100));

                $payment_method = $event_data["paymentMethod"] ?? "UNKNOWN";

                $username = "";
                if ($product_type == "RESERVED_ACCOUNT") {
                    $q_bank = mysqli_query($connection_server, "SELECT username FROM sas_user_banks WHERE vendor_id='$vendor_id' && reference='".mysqli_real_escape_string($connection_server, $product_reference)."' LIMIT 1");
                    if ($r_bank = mysqli_fetch_assoc($q_bank)) $username = $r_bank['username'];
                } else {
                    $q_checkout = mysqli_query($connection_server, "SELECT username FROM sas_user_payment_checkouts WHERE vendor_id='$vendor_id' && reference='".mysqli_real_escape_string($connection_server, $transaction_ref)."' LIMIT 1");
                    if ($r_checkout = mysqli_fetch_assoc($q_checkout)) $username = $r_checkout['username'];
                }

                if (!empty($username)) {
                    // Check if already processed
                    $check_tx = mysqli_query($connection_server, "SELECT id FROM sas_transactions WHERE vendor_id='$vendor_id' AND (api_reference='$transaction_ref' OR reference='$transaction_ref') AND status=1 LIMIT 1");
                    if (mysqli_num_rows($check_tx) == 0) {
                        $new_ref = substr(str_shuffle("12345678901234567890"), 0, 15);
                        $desc = "Monnify Wallet Credit - ".str_replace("_"," ",$payment_method);

                        // Use chargeOtherUser to credit
                        chargeOtherUser($username, "credit", "Monnify", "Wallet Credit", $new_ref, $transaction_ref, $amount_paid, $amount_deposited, $desc, "APP", $host, 1);

                        // Update checkout status
                        mysqli_query($connection_server, "UPDATE sas_user_payment_checkouts SET status=2 WHERE vendor_id='$vendor_id' AND reference='".mysqli_real_escape_string($connection_server, $transaction_ref)."'");

                        echo "SUCCESS";
                    } else {
                        echo "ALREADY_PROCESSED";
                    }
                } else {
                    error_log("Monnify User not found for ref $product_reference or trx $transaction_ref");
                }
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

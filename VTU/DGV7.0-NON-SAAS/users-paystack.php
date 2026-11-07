<?php session_start();
	include(__DIR__."/func/bc-connect.php");
	
	$body = file_get_contents("php://input");
	$catch_incoming_request = json_decode($body, true);

    if (!$catch_incoming_request) {
        http_response_code(400);
        die("Invalid payload");
    }

    $event_data = $catch_incoming_request["data"] ?? [];
    $transaction_ref = $event_data["reference"] ?? "";

	// Robust Vendor Identification
	$vendor_id = resolveVendorID();
	$select_vendor_table = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id' AND status=1 LIMIT 1"));

    if (!$select_vendor_table) {
        if (!empty($transaction_ref)) {
            $ref_esc = mysqli_real_escape_string($connection_server, $transaction_ref);
            $q = mysqli_query($connection_server, "SELECT vendor_id FROM sas_user_payment_checkouts WHERE reference='$ref_esc' LIMIT 1");
            if ($r = mysqli_fetch_assoc($q)) {
                $vendor_id = $r['vendor_id'];
                $select_vendor_table = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id' LIMIT 1"));
            }
        }
    }

	if($select_vendor_table && $select_vendor_table["status"] == 1){
        $vendor_id = $select_vendor_table["id"];
		$paystack_keys = mysqli_fetch_assoc(mysqli_query($connection_server,"SELECT * FROM sas_payment_gateways WHERE vendor_id='$vendor_id' && gateway_name='paystack'"));
		
        // Verify Signature (HMAC-SHA512) — reject forged webhooks. Enforcement only kicks in
        // when a real secret key (sk_) is stored; a misconfigured public key is left to the
        // API verification below so legitimate webhooks are never silently dropped.
        $client_sig = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';
        $secret = trim((string)($paystack_keys['secret_key'] ?? ''));
        if (!empty($secret) && strpos($secret, 'pk_') !== 0) {
            if (empty($client_sig)) {
                 error_log("Paystack MISSING signature for vendor $vendor_id. Ref: $transaction_ref");
                 http_response_code(401);
                 exit("Invalid signature");
            }
            $computed_sig = hash_hmac('sha512', $body, $secret);
            if (!hash_equals($computed_sig, $client_sig)) {
                 error_log("Paystack INVALID signature for vendor $vendor_id. Ref: $transaction_ref");
                 http_response_code(401);
                 exit("Invalid signature");
            }
        }

        // Verify via API
		$paystack_verify_transaction = json_decode(confirmPaymentDeposited("GET","https://api.paystack.co/transaction/verify/".urlencode($transaction_ref),["Authorization: Bearer ".$paystack_keys["secret_key"]],""),true);
		
		if(($paystack_verify_transaction["data"]["status"] ?? "") == "success") {
            $customer_email = $event_data["customer"]["email"];
            $amount_paid = ($event_data["amount"] / 100);
            $amount_deposited = (($event_data["amount"] / 100) - (($event_data["fees"] ?? 0) / 100));
            $payment_method = $event_data["channel"] ?? "UNKNOWN";

            // Find user
            $username = "";
            $q_checkout = mysqli_query($connection_server, "SELECT username FROM sas_user_payment_checkouts WHERE vendor_id='$vendor_id' && reference='".mysqli_real_escape_string($connection_server, $transaction_ref)."' LIMIT 1");
            if ($r_checkout = mysqli_fetch_assoc($q_checkout)) {
                $username = $r_checkout['username'];
            } else {
                $email_esc = mysqli_real_escape_string($connection_server, $customer_email);
                $q_user = mysqli_query($connection_server, "SELECT username FROM sas_users WHERE vendor_id='$vendor_id' AND email='$email_esc' LIMIT 1");
                if ($r_user = mysqli_fetch_assoc($q_user)) $username = $r_user['username'];
            }

            if (!empty($username)) {
                // Idempotency guard: only skip crediting if a COMPLETED (status=1) transaction
                // already exists. The pending row (status=2) created at checkout must NOT block
                // the credit — otherwise the webhook echoes ALREADY_PROCESSED forever and the
                // wallet stays pending.
                $check_tx = mysqli_query($connection_server, "SELECT id FROM sas_transactions WHERE vendor_id='$vendor_id' AND (api_reference='$transaction_ref' OR reference='$transaction_ref') AND status=1 LIMIT 1");
                if (mysqli_num_rows($check_tx) == 0) {
                    $new_ref = substr(str_shuffle("12345678901234567890"), 0, 15);
                    $desc = "Paystack Wallet Credit - ".str_replace("_"," ",$payment_method);
                    chargeOtherUser($username, "credit", "Paystack", "Wallet Credit", $new_ref, $transaction_ref, $amount_paid, $amount_deposited, $desc, "WEB", $host, 1);
                    echo "SUCCESS";
                } else {
                    echo "ALREADY_PROCESSED";
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
?>
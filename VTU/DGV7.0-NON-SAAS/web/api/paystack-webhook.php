<?php session_start();
	include_once("../../func/bc-connect.php");

	$body = file_get_contents("php://input");
	$catch_incoming_request = json_decode($body, true);

    if (!$catch_incoming_request) {
        http_response_code(400);
        die("Invalid payload");
    }

    $event = $catch_incoming_request["event"] ?? "";
    $event_data = $catch_incoming_request["data"] ?? [];
    $transaction_ref = $event_data["reference"] ?? "";

    if ($event !== "charge.success") {
        exit("Ignored event: " . $event);
    }

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
        $GLOBALS['vendor_id'] = $vendor_id;
		$paystack_keys = mysqli_fetch_assoc(mysqli_query($connection_server,"SELECT * FROM sas_payment_gateways WHERE vendor_id='$vendor_id' && gateway_name='paystack'"));

        // Security Fix: reject on signature mismatch (previously this only logged and continued
        // regardless — the API re-verify below was the sole real protection). Prefer the dedicated
        // webhook_secret; fall back to secret_key (which is what Paystack's HMAC is documented to
        // sign with) for installs that haven't set a separate webhook secret yet.
        $client_sig = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';
        $secret = !empty($paystack_keys['webhook_secret']) ? $paystack_keys['webhook_secret'] : ($paystack_keys['secret_key'] ?? '');
        if (!empty($secret)) {
            $computed_sig = hash_hmac('sha512', $body, $secret);
            if (empty($client_sig) || !hash_equals($computed_sig, $client_sig)) {
                error_log("SECURITY: Paystack webhook signature mismatch/missing for vendor $vendor_id. Ref: $transaction_ref");
                http_response_code(401);
                die("Invalid signature");
            }
        }

        // Verify via API
		$paystack_verify_transaction = json_decode(confirmPaymentDeposited("GET","https://api.paystack.co/transaction/verify/".urlencode($transaction_ref),["Authorization: Bearer ".$paystack_keys["secret_key"]],""),true);

		if(($paystack_verify_transaction["data"]["status"] ?? "") == "success") {
            // Security Fix: read the amount/email from Paystack's own verify response, not the
            // raw webhook body — a forged callback citing a real successful reference could
            // otherwise carry a manipulated amount/email even though the reference itself checks out.
            $verified_data = $paystack_verify_transaction["data"];
            $customer_email = $verified_data["customer"]["email"] ?? ($event_data["customer"]["email"] ?? "");
            $amount_paid = (float)(($verified_data["amount"] ?? $event_data["amount"] ?? 0) / 100);

            // Implement Charges correctly
            $charge_percent = (float)($paystack_keys['percentage'] ?? 0);
            $amount_deposited = $amount_paid * (1 - ($charge_percent / 100));

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
                $check_tx = mysqli_query($connection_server, "SELECT id FROM sas_transactions WHERE vendor_id='$vendor_id' AND (api_reference='$transaction_ref' OR reference='$transaction_ref') AND status=1 LIMIT 1");
                if (mysqli_num_rows($check_tx) == 0) {
                    $new_ref = substr(str_shuffle("12345678901234567890"), 0, 15);
                    $desc = "Paystack Wallet Credit - ".str_replace("_"," ",$payment_method);
                    chargeOtherUser($username, "credit", "Paystack", "Wallet Credit", $new_ref, $transaction_ref, $amount_paid, $amount_deposited, $desc, "APP", $host, 1);

                    // Mark original pending transaction as successful
                    mysqli_query($connection_server, "UPDATE sas_transactions SET status=1 WHERE vendor_id='$vendor_id' AND reference='".mysqli_real_escape_string($connection_server, $transaction_ref)."' AND status=2");

                    // Update checkout status
                    mysqli_query($connection_server, "UPDATE sas_user_payment_checkouts SET status=2 WHERE vendor_id='$vendor_id' AND reference='".mysqli_real_escape_string($connection_server, $transaction_ref)."'");

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

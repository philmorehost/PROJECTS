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

    // Webhook audit log — helps diagnose "money received but not credited" cases.
    function paystack_webhook_log($msg) {
        $dir = __DIR__ . "/logs";
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        @file_put_contents($dir . "/paystack_webhook.log", "[" . date('Y-m-d H:i:s') . "] " . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
    paystack_webhook_log("Webhook received: ref=" . $transaction_ref . " event=" . ($catch_incoming_request["event"] ?? '') . " bytes=" . strlen($body));

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
    paystack_webhook_log("resolveVendorID=" . $vendor_id . " vendor_found=" . ($select_vendor_table ? $select_vendor_table["id"] : "NO") . " ref=" . $transaction_ref);

	if($select_vendor_table && $select_vendor_table["status"] == 1){
        $vendor_id = $select_vendor_table["id"];
        // CRITICAL: push the resolved vendor into the globals so chargeOtherUser() ->
        // resolveVendorID() uses the SAME vendor (the webhook has no session, and the host-
        // based lookup alone resolves to the wrong/zero vendor on shared hosts). Without this,
        // chargeOtherUser() returns "failed" and the money is received but never credited.
        $GLOBALS['vendor_id'] = $vendor_id;
        $GLOBALS['select_vendor_table'] = $select_vendor_table;
        $host = $_SERVER['HTTP_HOST'] ?? 'WEB';
		$paystack_keys = mysqli_fetch_assoc(mysqli_query($connection_server,"SELECT * FROM sas_payment_gateways WHERE vendor_id='$vendor_id' && gateway_name='paystack'"));
        paystack_webhook_log("Vendor=" . $vendor_id . " paystack_keys=" . (($paystack_keys && !empty($paystack_keys['secret_key'])) ? "yes" : "MISSING") . " ref=" . $transaction_ref);
        if ($paystack_keys && !empty($paystack_keys['secret_key']) && strpos(trim($paystack_keys['secret_key']), 'pk_') === 0) {
            paystack_webhook_log("CRITICAL: vendor " . $vendor_id . " Paystack secret_key is a PUBLIC key (starts with pk_). Verification WILL fail and no credit will be applied. Fix sas_payment_gateways.secret_key for this vendor. ref=" . $transaction_ref);
        }
		
        // Verify Signature (HMAC-SHA512) — reject forged webhooks. Enforcement only kicks in
        // when a real secret key (sk_) is stored; a misconfigured public key is logged and left
        // to the API verification below so legitimate webhooks are never silently dropped.
        $client_sig = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';
        $secret = trim((string)($paystack_keys['secret_key'] ?? ''));
        if (!empty($secret) && strpos($secret, 'pk_') !== 0) {
            if (empty($client_sig)) {
                paystack_webhook_log("MISSING Paystack signature — rejected. ref=" . $transaction_ref);
                http_response_code(401);
                exit("Invalid signature");
            }
            $computed_sig = hash_hmac('sha512', $body, $secret);
            if (!hash_equals($computed_sig, $client_sig)) {
                paystack_webhook_log("INVALID Paystack signature — rejected. ref=" . $transaction_ref);
                http_response_code(401);
                exit("Invalid signature");
            }
        }

        // Verify via API — the authoritative check that Paystack actually received the money.
		$paystack_verify_transaction = json_decode(confirmPaymentDeposited("GET","https://api.paystack.co/transaction/verify/".urlencode($transaction_ref),["Authorization: Bearer ".$secret],""),true);
		
        paystack_webhook_log("Verify status=" . ($paystack_verify_transaction["data"]["status"] ?? 'N/A') . " msg=" . ($paystack_verify_transaction["message"] ?? '') . " ref=" . $transaction_ref);

		if(($paystack_verify_transaction["data"]["status"] ?? "") == "success") {
            $customer_email = $event_data["customer"]["email"];
            $amount_paid = (float)($event_data["amount"] / 100);
            // Apply the vendor's configured gateway percentage (same model as verify-funding.php
            // and web/api/paystack-webhook.php) so the credited amount is consistent everywhere.
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
            paystack_webhook_log("username_found=" . (!empty($username) ? $username : "NO") . " ref=" . $transaction_ref);

            if (!empty($username)) {
                // Idempotency guard: only skip crediting if a COMPLETED (status=1) transaction
                // already exists for this reference. The pending row created by create_checkout
                // (status=2) must NOT block the credit — previously this missing filter made the
                // webhook echo ALREADY_PROCESSED forever and the wallet stayed pending.
                $check_tx = mysqli_query($connection_server, "SELECT id FROM sas_transactions WHERE vendor_id='$vendor_id' AND (api_reference='$transaction_ref' OR reference='$transaction_ref') AND status=1 LIMIT 1");
                if (mysqli_num_rows($check_tx) == 0) {
                    $new_ref = substr(str_shuffle("12345678901234567890"), 0, 15);
                    $desc = "Paystack Wallet Credit - ".str_replace("_"," ",$payment_method);
                    $charge_result = chargeOtherUser($username, "credit", "Paystack", "Wallet Credit", $new_ref, $transaction_ref, $amount_paid, $amount_deposited, $desc, "WEB", $host, 1);
                    paystack_webhook_log("chargeOtherUser=" . $charge_result . " vendor=" . $vendor_id . " user=" . $username . " amount=" . $amount_deposited . " ref=" . $transaction_ref);

                    // Mark the original pending funding transaction as successful and close out
                    // the checkout record (mirrors web/api/paystack-webhook.php).
                    mysqli_query($connection_server, "UPDATE sas_transactions SET status=1 WHERE vendor_id='$vendor_id' AND reference='".mysqli_real_escape_string($connection_server, $transaction_ref)."' AND status=2");
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
// cURL waits forever by default - bound the connect and the whole call so one slow provider cannot
// hold this PHP worker open indefinitely.
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
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
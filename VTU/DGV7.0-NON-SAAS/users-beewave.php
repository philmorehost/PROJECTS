<?php session_start();
include(__DIR__ . "/func/bc-connect.php");

// This handler used to have no logging at all, so any payload it did not recognise resulted in
// money taken and nothing credited, with no trace anywhere. Every exit now says what arrived.
if (!function_exists('bc_log_beewave')) {
	function bc_log_beewave($msg)
	{
		$dir = __DIR__ . '/logs';
		if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
		@file_put_contents($dir . '/beewave_webhook.log', '[' . date('Y-m-d H:i:s') . '] root:' . basename(__FILE__) . ' ' . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
	}
}

$catch_incoming_request = json_decode(file_get_contents("php://input"), true);

//Select Vendor Table
$vendor_id = resolveVendorID();
$select_vendor_table = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id' AND status=1 LIMIT 1"));
if ($select_vendor_table) {
	$beewave_keys = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT * FROM sas_payment_gateways WHERE vendor_id='" . $select_vendor_table["id"] . "' && gateway_name='beewave'"));
	
	$customer_name = "";
	$customer_phone_number = $catch_incoming_request["data"]["customer"]["phone"];
	$customer_email = $catch_incoming_request["data"]["customer"]["email"];
	$amount_paid = floatval($catch_incoming_request['data']['amount_paid'] ?? ($catch_incoming_request['data']['amount'] ?? 0));
	$amount_deposited = floatval($catch_incoming_request['data']['settlement_amount'] ?? ($catch_incoming_request['data']['amount'] ?? 0));
	// Beewave has shipped both spellings of this field, and the web/api beewave-webhook.php handler
	// only read "reference" while this one only read "transaction_ref" - so one of the two URLs
	// discarded every callback it was given.
	$transaction_id = $catch_incoming_request["data"]["transaction_ref"] ?? ($catch_incoming_request["data"]["reference"] ?? "");
	$virtual_accountno = $catch_incoming_request["data"]["destination"]["account_number"] ?? "";
	$virtual_bankcode = $catch_incoming_request["data"]["destination"]["bank_code"] ?? "";
	$virtual_bankname = $catch_incoming_request["data"]["destination"]["bank_name"] ?? "";
	$virtual_accounttype = $catch_incoming_request["data"]["destination"]["account_type"] ?? "";
	bc_log_beewave("payload: ref=" . $transaction_id . " vatype='" . $virtual_accounttype . "' email=" . $customer_email . " paid=" . $amount_paid . " settled=" . $amount_deposited);
	$payment_method = "BANK TRANSFER";
	// Limiting explode to 3 parts keeps index 1 (username) reliable and captures the real email
	// intact in index 2 even when that email itself contains a hyphen — the previous array_filter()
	// split silently dropped the trailing segment whenever it was empty, causing "Undefined array key 2".
	$exp_customer_detail = explode("-", trim($customer_email), 3);
	$customer_username = $exp_customer_detail[1] ?? "";
	$customer_mail = $exp_customer_detail[2] ?? "";
	$vendor_id = trim($select_vendor_table["id"]);

	if ($virtual_accounttype == "static") {
		$check_if_banks_exists = mysqli_query($connection_server, "SELECT * FROM sas_user_banks WHERE vendor_id='$vendor_id' && username='$customer_username' && account_number='$virtual_accountno' && bank_code='$virtual_bankcode'");

		if (mysqli_num_rows($check_if_banks_exists) == 1) {
			$get_payment_details = mysqli_fetch_array($check_if_banks_exists);

			$beewave_verify_transaction = json_decode(confirmPaymentDeposited("GET", "https://merchant.beewave.ng/api/v1/collection/verify?access_key=" . $beewave_keys["public_key"] . "&&transaction_ref=" . $transaction_id, ["Accept: application/json", "Content-Type: application/json"], ""), true);

			$user_id = $get_payment_details["username"];
			$reference = substr(str_shuffle("12345678901234567890"), 0, 15);
			$check_vendor_user_exists = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' && username='$user_id'");
			if (mysqli_num_rows($check_vendor_user_exists) == 1) {
				$get_logged_user_details = mysqli_fetch_array($check_vendor_user_exists);
				$_SESSION["user_session"] = $get_logged_user_details["username"];

				$select_transaction_history = mysqli_query($connection_server, "SELECT * FROM sas_transactions WHERE (reference='$transaction_id' OR api_reference='$transaction_id')");

				if (($catch_incoming_request['data']['settlement_amount'] == $beewave_verify_transaction['data']['settlement_amount']) && ($beewave_verify_transaction['status'] == true && $beewave_verify_transaction['data']['status'] == "success") && ($catch_incoming_request['status'] == true && $catch_incoming_request['data']['status'] == "success")) {
					if (mysqli_num_rows($select_transaction_history) == 0) {
						chargeUser("credit", $_SESSION["user_session"], "Wallet Credit", $reference, $transaction_id, $amount_paid, $amount_deposited, "Beewave Wallet Credit - " . str_replace("_", " ", $payment_method), strtoupper("WEB"), $_SERVER["HTTP_HOST"], "1");
						unset($_SESSION["user_session"]);
					}
				}
			}


		}
	}

	if ($virtual_accounttype == "dynamic") {
		
		$beewave_verify_transaction = json_decode(confirmPaymentDeposited("GET", "https://merchant.beewave.ng/api/v1/collection/verify?access_key=" . $beewave_keys["public_key"] . "&transaction_ref=" . $transaction_id, ["Accept: application/json", "Content-Type: application/json"], ""), true);
		
		$user_id = $customer_username;
		$reference = substr(str_shuffle("12345678901234567890"), 0, 15);
		$check_vendor_user_exists = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' && username='$user_id'");
		if (mysqli_num_rows($check_vendor_user_exists) == 1) {
			$get_logged_user_details = mysqli_fetch_array($check_vendor_user_exists);
			$_SESSION["user_session"] = $get_logged_user_details["username"];

			$select_transaction_history = mysqli_query($connection_server, "SELECT * FROM sas_transactions WHERE (reference='$transaction_id' OR api_reference='$transaction_id')");
		
			if (($catch_incoming_request['data']['settlement_amount'] == $beewave_verify_transaction['data']['settlement_amount']) && ($beewave_verify_transaction['status'] == true && $beewave_verify_transaction['data']['status'] == "success") && ($catch_incoming_request['status'] == true && $catch_incoming_request['data']['status'] == "success")) {
				if (mysqli_num_rows($select_transaction_history) == 0) {
					chargeUser("credit", $_SESSION["user_session"], "Wallet Credit", $reference, $transaction_id, $amount_paid, $amount_deposited, "Beewave Wallet Credit - " . str_replace("_", " ", $payment_method) ." (".$virtual_bankname." (DYNAMIC ACCOUNT) - ".$virtual_accountno.")", strtoupper("WEB"), $_SERVER["HTTP_HOST"], "1");
					unset($_SESSION["user_session"]);
					bc_log_beewave("dynamic VA credit issued for ref=$transaction_id user=$user_id");
				}
			}else{
				bc_log_beewave("dynamic VA: verify/status check refused the credit for ref=$transaction_id (verified=" . json_encode($beewave_verify_transaction) . ")");
			}
		}else{
			bc_log_beewave("dynamic VA: no sas_users row for user=$user_id vendor=$vendor_id");
		}
	}

	// ── Fallback: a payment made through the Beewave CHECKOUT POPUP ───────────────────────────
	// That flow sends no virtual account - destination.account_type is neither "static" nor
	// "dynamic" - so neither branch above could ever credit it, and because this handler logged
	// nothing the money simply disappeared. Identify the customer from our own checkout row or from
	// the email the popup was given (host-username-user@email, built by web/Fund.php), have Beewave
	// confirm the transaction, and credit what the customer asked to fund.
	if ($virtual_accounttype != "static" && $virtual_accounttype != "dynamic") {
		$beewave_user = "";
		$bw_ref_esc = mysqli_real_escape_string($connection_server, $transaction_id);
		$q_bw_checkout = mysqli_query($connection_server, "SELECT username FROM sas_user_payment_checkouts WHERE vendor_id='$vendor_id' && reference='$bw_ref_esc' LIMIT 1");
		if ($q_bw_checkout && ($r_bw_checkout = mysqli_fetch_assoc($q_bw_checkout))) {
			$beewave_user = $r_bw_checkout['username'];
		}
		if ($beewave_user === "") {
			$bw_parts = explode("-", trim((string)$customer_email), 3);
			$bw_tries = array();
			if (!empty($bw_parts[1])) { $bw_tries[] = array("col" => "username", "val" => $bw_parts[1]); }
			if (!empty($bw_parts[2])) { $bw_tries[] = array("col" => "email", "val" => $bw_parts[2]); }
			foreach ($bw_tries as $bw_try) {
				$bw_val = mysqli_real_escape_string($connection_server, trim($bw_try["val"]));
				$q_bw_u = mysqli_query($connection_server, "SELECT username FROM sas_users WHERE vendor_id='$vendor_id' && " . $bw_try["col"] . "='$bw_val' LIMIT 1");
				if ($q_bw_u && ($r_bw_u = mysqli_fetch_assoc($q_bw_u))) { $beewave_user = $r_bw_u["username"]; break; }
			}
		}

		// The amount the customer asked to fund: by reference when we have it, otherwise their most
		// recent pending funding row (the popup never receives a reference from us). It doubles as
		// the cap, so a forged or replayed body can never inflate the credit.
		$bw_expected = 0.0;
		$bw_pending_ref = "";
		$q_bw_expected = mysqli_query($connection_server, "SELECT reference, amount FROM sas_transactions WHERE vendor_id='$vendor_id' && (reference='$bw_ref_esc' OR api_reference='$bw_ref_esc') ORDER BY id DESC LIMIT 1");
		if ($q_bw_expected && ($r_bw_expected = mysqli_fetch_assoc($q_bw_expected))) {
			$bw_expected = (float)$r_bw_expected["amount"];
			$bw_pending_ref = (string)$r_bw_expected["reference"];
		}
		if ($bw_expected <= 0 && $beewave_user !== "") {
			$q_bw_pend = mysqli_query($connection_server, "SELECT reference, amount FROM sas_transactions WHERE vendor_id='$vendor_id' && username='" . mysqli_real_escape_string($connection_server, $beewave_user) . "' && product_unique_id='wallet_funding' && status=2 ORDER BY id DESC LIMIT 1");
			if ($q_bw_pend && ($r_bw_pend = mysqli_fetch_assoc($q_bw_pend))) {
				$bw_expected = (float)$r_bw_pend["amount"];
				$bw_pending_ref = (string)$r_bw_pend["reference"];
			}
		}

		$bw_verify = json_decode(confirmPaymentDeposited("GET", "https://merchant.beewave.ng/api/v1/collection/verify?access_key=" . $beewave_keys["public_key"] . "&transaction_ref=" . $transaction_id, ["Accept: application/json", "Content-Type: application/json"], ""), true);
		$bw_verified = (is_array($bw_verify) && !empty($bw_verify["status"]) && (($bw_verify["data"]["status"] ?? "") === "success"));
		$bw_settled = (float)($bw_verify["data"]["amount"] ?? ($bw_verify["data"]["amount_paid"] ?? ($bw_verify["data"]["settlement_amount"] ?? 0)));

		if ($beewave_user === "") {
			bc_log_beewave("checkout fallback: no customer resolved for ref=$transaction_id email=$customer_email");
		} elseif (!$bw_verified) {
			bc_log_beewave("checkout fallback: beewave did not confirm ref=$transaction_id response=" . substr(json_encode($bw_verify), 0, 300));
		} elseif ($bw_expected <= 0) {
			bc_log_beewave("checkout fallback: no amount on record for user=$beewave_user ref=$transaction_id - not credited");
		} else {
			$bw_dupe = mysqli_query($connection_server, "SELECT id FROM sas_transactions WHERE vendor_id='$vendor_id' && (reference='$bw_ref_esc' OR api_reference='$bw_ref_esc') && status=1 LIMIT 1");
			if ($bw_dupe && mysqli_num_rows($bw_dupe) > 0) {
				bc_log_beewave("checkout fallback: already credited ref=$transaction_id");
			} else {
				$bw_paid = $bw_settled > 0 ? min($bw_settled, $bw_expected) : $bw_expected;
				$bw_credit = $bw_paid * (1 - ((float)($beewave_keys["percentage"] ?? 0) / 100));
				$bw_new_ref = substr(str_shuffle("12345678901234567890"), 0, 15);
				$bw_desc = "Beewave Wallet Credit - " . str_replace("_", " ", $payment_method);
				$bw_result = chargeOtherUser($beewave_user, "credit", "Beewave", "Wallet Credit", $bw_new_ref, $transaction_id, $bw_paid, $bw_credit, $bw_desc, "WEB", $_SERVER["HTTP_HOST"], 1);
				if ($bw_result === "success") {
					if ($bw_pending_ref !== "") {
						mysqli_query($connection_server, "UPDATE sas_transactions SET status=1 WHERE vendor_id='$vendor_id' && reference='" . mysqli_real_escape_string($connection_server, $bw_pending_ref) . "' && status=2");
					}
					mysqli_query($connection_server, "UPDATE sas_user_payment_checkouts SET status=2 WHERE vendor_id='$vendor_id' && reference='$bw_ref_esc'");
					bc_log_beewave("checkout fallback CREDITED user=$beewave_user paid=$bw_paid credited=$bw_credit ref=$transaction_id");
				} else {
					bc_log_beewave("checkout fallback: chargeOtherUser returned " . var_export($bw_result, true) . " for user=$beewave_user ref=$transaction_id");
				}
			}
		}
	}

}

function confirmPaymentDeposited($method, $url, $header, $json)
{
	$apiwalletBalance = curl_init($url);
// cURL waits forever by default - bound the connect and the whole call so one slow provider cannot
// hold this PHP worker open indefinitely.
curl_setopt($apiwalletBalance, CURLOPT_CONNECTTIMEOUT, 10);
curl_setopt($apiwalletBalance, CURLOPT_TIMEOUT, 30);
	$apiwalletBalanceUrl = $url;
	curl_setopt($apiwalletBalance, CURLOPT_URL, $apiwalletBalanceUrl);
	curl_setopt($apiwalletBalance, CURLOPT_RETURNTRANSFER, true);
	if ($method == "POST") {
		curl_setopt($apiwalletBalance, CURLOPT_POST, true);
	}

	if ($method == "GET") {
		curl_setopt($apiwalletBalance, CURLOPT_HTTPGET, true);
	}

	if ($header == true) {
		curl_setopt($apiwalletBalance, CURLOPT_HTTPHEADER, $header);
	}
	if ($json == true) {
		curl_setopt($apiwalletBalance, CURLOPT_POSTFIELDS, $json);
	}
	curl_setopt($apiwalletBalance, CURLOPT_SSL_VERIFYHOST, false);
	curl_setopt($apiwalletBalance, CURLOPT_SSL_VERIFYPEER, false);

	$GetAPIBalanceJSON = curl_exec($apiwalletBalance);
	return $GetAPIBalanceJSON;
}
?>
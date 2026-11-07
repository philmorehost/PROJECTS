<?php session_start();
	include_once("../../func/bc-connect.php");

// Helpers for this handler. They are declared here, before use: an unconditional top-level function is
// hoisted, but one inside an if() block is only defined when execution reaches it.
if (!function_exists('bc_log_beewave')) {
	function bc_log_beewave($msg) {
		$dir = __DIR__ . '/../../logs';
		if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
		@file_put_contents($dir . '/beewave_webhook.log', '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
	}
}
if (!function_exists('bc_beewave_verify_lookup')) {
	function bc_beewave_verify_lookup($url) {
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array("Accept: application/json", "Content-Type: application/json"));
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		$res = curl_exec($ch);
		curl_close($ch);
		return json_decode((string)$res, true);
	}
}

	$body = file_get_contents("php://input");
	$catch_incoming_request = json_decode($body, true);

    if (!$catch_incoming_request) {
        http_response_code(400);
        die("Invalid payload");
    }

    $event = $catch_incoming_request["event"] ?? "";
    $event_data = $catch_incoming_request["data"] ?? [];
    // Beewave has shipped both spellings of this field. This handler only read "reference" while
    // the root users-beewave.php only read "transaction_ref", so whichever URL Beewave actually
    // calls, one of the two silently discarded every callback it received.
    $transaction_ref = $event_data["reference"] ?? ($event_data["transaction_ref"] ?? "");

    // Two payload shapes are in circulation: the event style this handler was written for
    // ("event":"transaction.success") and the flat one the root handler expects (no event, with
    // data.status = "success"). Accept either, and log what actually arrived so an unrecognised
    // shape is diagnosable instead of silently ignored.
    $event_is_success = ($event === "transaction.success")
        || ($event === "" && strtolower((string)($event_data["status"] ?? "")) === "success");
    if (!$event_is_success) {
        bc_log_beewave("IGNORED event='" . $event . "' keys=" . implode(",", array_keys($event_data)) . " ref=$transaction_ref");
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
		$beewave_keys = mysqli_fetch_assoc(mysqli_query($connection_server,"SELECT * FROM sas_payment_gateways WHERE vendor_id='$vendor_id' && gateway_name='beewave'"));

        // ── Verify the callback before it may credit anything ──────────────────────────────
        // This URL is public: anyone can POST {"event":"transaction.success","data":{...}} at it. The
        // "signature verification would go here" placeholder meant a forged POST credited ANY amount to
        // ANY pending reference - free money for whoever found the endpoint.
        //
        // 1) The reference must be one of OUR funding attempts. That row is also where the amount the
        //    customer was asked to pay comes from, and the credit is capped by it below, so a forged or
        //    replayed body can never inflate the amount.
        $ref_esc_exp = mysqli_real_escape_string($connection_server, (string)$transaction_ref);
        $expected_amount = 0.0;
        $q_exp = mysqli_query($connection_server, "SELECT amount FROM sas_transactions WHERE vendor_id='$vendor_id' AND (reference='$ref_esc_exp' OR api_reference='$ref_esc_exp') ORDER BY id DESC LIMIT 1");
        if ($q_exp && ($r_exp = mysqli_fetch_assoc($q_exp))) {
            $expected_amount = (float)$r_exp['amount'];
        }

        // 2) Beewave itself must confirm the transaction, using the merchant's access key.
        $beewave_access_key = trim((string)($beewave_keys['public_key'] ?? ''));
        $beewave_verified_data = null;
        if ($beewave_access_key !== '' && (string)$transaction_ref !== '') {
            $beewave_verify = bc_beewave_verify_lookup("https://merchant.beewave.ng/api/v1/collection/verify?access_key=" . rawurlencode($beewave_access_key) . "&transaction_ref=" . rawurlencode((string)$transaction_ref));
            if (is_array($beewave_verify) && !empty($beewave_verify['status']) && (($beewave_verify['data']['status'] ?? '') === 'success')) {
                $beewave_verified_data = is_array($beewave_verify['data'] ?? null) ? $beewave_verify['data'] : array();
            }
        }

        // 3) If this vendor has a webhook secret configured, the caller must present it too.
        $configured_secret = trim((string)($beewave_keys['webhook_secret'] ?? ''));
        if ($configured_secret !== '') {
            $provided_secret = $_GET['secret'] ?? ($_SERVER['HTTP_X_BEEWAVE_SIGNATURE'] ?? '');
            if (empty($provided_secret) || !hash_equals($configured_secret, (string)$provided_secret)) {
                bc_log_beewave("REJECTED secret mismatch vendor=$vendor_id ref=$transaction_ref");
                http_response_code(401);
                die("Invalid signature");
            }
        }

        if ($beewave_verified_data === null) {
            // Not confirmed by the gateway. 502 so Beewave retries, and logged for reconciliation.
            bc_log_beewave("UNVERIFIED vendor=$vendor_id ref=$transaction_ref body_amount=" . (float)($event_data['amount'] ?? 0));
            http_response_code(502);
            die("Could not verify transaction with Beewave");
        }
        // NOTE: the "no matching pending row" check now happens after the customer has been
        // resolved below. A payment made through the Beewave CHECKOUT POPUP carries Beewave's own
        // transaction_ref instead of the reference we created, so the amount we asked for has to be
        // found by customer rather than by reference.

        $settled_amount = (float)($beewave_verified_data['amount'] ?? $beewave_verified_data['amount_paid'] ?? $beewave_verified_data['settlement_amount'] ?? 0);
        $payment_method = $event_data["channel"] ?? "UNKNOWN";

        // Find the customer. Three anchors, most reliable first:
        //   1. our own checkout row, keyed by the reference we created;
        //   2. the username embedded in the customer email - the Beewave checkout popup is never
        //      handed a reference by us, so this is the only anchor a payment through that popup has
        //      (web/Fund.php builds the email as host-username-user@email);
        //   3. the customer's email as stored on the account.
        $username = "";
        $q_checkout = mysqli_query($connection_server, "SELECT username FROM sas_user_payment_checkouts WHERE vendor_id='$vendor_id' && reference='" . mysqli_real_escape_string($connection_server, $transaction_ref) . "' LIMIT 1");
        if ($r_checkout = mysqli_fetch_assoc($q_checkout)) {
            $username = $r_checkout['username'];
        }
        if ($username === "") {
            $beewave_email_parts = explode("-", trim((string)($event_data['customer']['email'] ?? ($event_data['email'] ?? ''))), 3);
            $beewave_candidates = array();
            if (!empty($beewave_email_parts[1])) { $beewave_candidates[] = array('col' => 'username', 'val' => $beewave_email_parts[1]); }
            if (!empty($beewave_email_parts[2])) { $beewave_candidates[] = array('col' => 'email', 'val' => $beewave_email_parts[2]); }
            foreach ($beewave_candidates as $beewave_candidate) {
                $beewave_val = mysqli_real_escape_string($connection_server, trim($beewave_candidate['val']));
                $q_u = mysqli_query($connection_server, "SELECT username FROM sas_users WHERE vendor_id='$vendor_id' && " . $beewave_candidate['col'] . "='$beewave_val' LIMIT 1");
                if ($q_u && ($r_u = mysqli_fetch_assoc($q_u))) { $username = $r_u['username']; break; }
            }
        }
        bc_log_beewave("resolve: vendor=$vendor_id ref=$transaction_ref username=" . ($username !== "" ? $username : "NOT FOUND"));

        if ($expected_amount <= 0 && $username !== "") {
            // The popup flow gives us no reference to match on, so fall back to what this customer
            // most recently asked to fund - create_checkout leaves a pending wallet-funding row.
            // That figure is also the cap the credit is held to below.
            $q_pending = mysqli_query($connection_server, "SELECT reference, amount FROM sas_transactions WHERE vendor_id='$vendor_id' && username='" . mysqli_real_escape_string($connection_server, $username) . "' && product_unique_id='wallet_funding' && status=2 ORDER BY id DESC LIMIT 1");
            if ($q_pending && ($r_pending = mysqli_fetch_assoc($q_pending))) {
                $expected_amount = (float)$r_pending['amount'];
                // Remembered so the row can be closed out after the credit: leaving it pending would
                // keep showing the funding as unfinished on the customer's dashboard.
                $matched_pending_reference = (string)$r_pending['reference'];
                bc_log_beewave("expected amount taken from the customer's pending funding row: $expected_amount (ref=$matched_pending_reference)");
            }
        }

        if ($expected_amount <= 0) {
            bc_log_beewave("NO MATCHING PENDING ROW vendor=$vendor_id ref=$transaction_ref username=" . ($username !== "" ? $username : "NOT FOUND"));
            http_response_code(200);
            die("Ignored: unknown reference");
        }

        // Prefer the gateway's own figure, but never more than the customer was asked to fund.
        $amount_paid = $settled_amount > 0 ? min($settled_amount, $expected_amount) : $expected_amount;

        // Implement Charges correctly
        $charge_percent = (float)($beewave_keys['percentage'] ?? 0);
        $amount_deposited = $amount_paid * (1 - ($charge_percent / 100));

        if (!empty($username)) {
            $check_tx = mysqli_query($connection_server, "SELECT id FROM sas_transactions WHERE vendor_id='$vendor_id' AND (api_reference='$transaction_ref' OR reference='$transaction_ref') AND status=1 LIMIT 1");
            if (mysqli_num_rows($check_tx) == 0) {
                $new_ref = substr(str_shuffle("12345678901234567890"), 0, 15);
                $desc = "Beewave Wallet Credit - ".str_replace("_"," ",$payment_method);
                chargeOtherUser($username, "credit", "Beewave", "Wallet Credit", $new_ref, $transaction_ref, $amount_paid, $amount_deposited, $desc, "APP", $_SERVER['HTTP_HOST'], 1);

                // Update checkout status
                mysqli_query($connection_server, "UPDATE sas_user_payment_checkouts SET status=2 WHERE vendor_id='$vendor_id' AND reference='".mysqli_real_escape_string($connection_server, $transaction_ref)."'");
                if (!empty($matched_pending_reference)) {
                    mysqli_query($connection_server, "UPDATE sas_transactions SET status=1 WHERE vendor_id='$vendor_id' AND reference='" . mysqli_real_escape_string($connection_server, $matched_pending_reference) . "' AND status=2");
                }
                bc_log_beewave("CREDITED vendor=$vendor_id user=$username paid=$amount_paid credited=$amount_deposited ref=$transaction_ref");
                echo "SUCCESS";
            } else {
                echo "ALREADY_PROCESSED";
            }
        }
	}
?>
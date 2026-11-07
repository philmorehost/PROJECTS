<?php
$purchase_method = strtoupper($purchase_method);
$purchase_method_array = array("API", "WEB", "APP");
if (in_array($purchase_method, $purchase_method_array)) {
    if ($purchase_method === "WEB") {
        if (isset($_SESSION["transfer_enquiry_id"])) {
            $transfer_enquiry_id = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_SESSION["transfer_enquiry_id"]))));
            $bank_code = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_SESSION["bank_code"]))));
            $amount = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9.]+/", "", trim(strip_tags($_SESSION["amount"]))));
            $account_number = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9.]+/", "", trim(strip_tags($_SESSION["account_number"]))));
            $narration = mysqli_real_escape_string($connection_server, trim(strip_tags($_SESSION["narration"])));
        } else {
            $bank_code = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["bank-code"]))));
            $amount = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9.]+/", "", trim(strip_tags($_POST["amount"]))));
            $account_number = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9.]+/", "", trim(strip_tags($_POST["account-number"]))));
            $narration = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["narration"])));
        }

    }

    if (in_array($purchase_method, array("API", "APP"))) {
        $transfer_enquiry_id = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($get_api_post_info["enquiry_id"]))));
        $bank_code = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($get_api_post_info["bank_code"]))));
        $amount = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9.]+/", "", trim(strip_tags($get_api_post_info["amount"]))));
        $account_number = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9.]+/", "", trim(strip_tags($get_api_post_info["account_number"]))));
        $narration = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($get_api_post_info["narration"]))));
        //$requery_reference = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9.]+/","",trim(strip_tags($get_api_post_info["reference"]))));

    }
    //$discounted_amount = $amount;
    $log_dir = $_SERVER["DOCUMENT_ROOT"] . "/logs";
    if (!is_dir($log_dir)) @mkdir($log_dir, 0777, true);
    $log_file = $log_dir . "/bank_transfer.log";
    $bt_log = function($m) use ($log_file) { @file_put_contents($log_file, "[" . date('Y-m-d H:i:s') . "] " . $m . "\n", FILE_APPEND); };

    $bt_log("Request: Method=$purchase_method | Action=$action_function | Bank=$bank_code | Acc=$account_number | Amt=$amount | User=".$get_logged_user_details['username']);

    $bank_code_alternative = ucwords("Bank Transfer");
    $reference = substr(str_shuffle("12345678901234567890"), 0, 15);
    $description = "Bank Charges";
    $status = 3;


    $bank_code_array = array();
    $bank_code_name_array = array();
    $retrieve_bank_list = file_get_contents($_SERVER["DOCUMENT_ROOT"] . "/func/banks.json");
    $retrieve_bank_list = json_decode($retrieve_bank_list, true);
    if (is_array($retrieve_bank_list)) {
        foreach ($retrieve_bank_list as $each_bank) {
            $b_code = trim($each_bank["bankCode"]);
            array_push($bank_code_array, $b_code);

            // Also allow the standard mapped version of this code
            $std = getStandardBankCode($b_code);
            if (!empty($std)) array_push($bank_code_array, $std);

            $bank_code_name_array[$b_code] = $each_bank["bankName"];
            if (!empty($std)) $bank_code_name_array[$std] = $each_bank["bankName"];
        }
    }

    $bt_log("Validation: Checking code '$bank_code' against " . count($bank_code_array) . " codes");

    if (in_array($bank_code, $bank_code_array) || strlen($bank_code) == 3 || (strlen($bank_code) == 6 && is_numeric($bank_code))) {
        // Payout Toggle Enforcement
        if (!isServiceEnabled('payout')) {
            $json_response_encode = json_encode(["status" => "failed", "desc" => "Payout service is currently offline. Please contact support."]);
            return;
        }
        // Wallet to Bank Toggle Enforcement
        if (!isServiceEnabled('withdraw')) {
            $json_response_encode = json_encode(["status" => "failed", "desc" => "Wallet to Bank service is currently offline. Please contact support."]);
            return;
        }

        $vid = $get_logged_user_details["vendor_id"];
        $select_v = mysqli_query($connection_server, "SELECT withdrawal_fee, approve_withdrawal, payout_provider, payout_activated, min_withdrawal_amount, max_withdrawal_amount, daily_payout_limit FROM sas_vendors WHERE id='$vid' LIMIT 1");
        $rv = mysqli_fetch_assoc($select_v);
        $v_withdrawal_fee = (float)($rv['withdrawal_fee'] ?? 50);
        $needs_approval = ($rv['approve_withdrawal'] == 1);
        $payout_provider = $rv['payout_provider'] ?? '';
        $payout_activated = ($rv['payout_activated'] == 1);
        $min_with = (float)($rv['min_withdrawal_amount'] ?? getSuperAdminOption('default_min_withdrawal', 1000));
        $max_with = (float)($rv['max_withdrawal_amount'] ?? getSuperAdminOption('default_max_withdrawal', 50000));
        $daily_limit = (int)($rv['daily_payout_limit'] ?? getSuperAdminOption('default_daily_payout_limit', 10));

        if (!$payout_activated) {
            $json_response_encode = json_encode(["status" => "failed", "desc" => "Withdrawal service not activated for this account."]);
            return;
        }

        if (empty($payout_provider)) {
            $json_response_encode = json_encode(["status" => "failed", "desc" => "Withdrawal provider not configured by administrator."]);
            return;
        }

        if ($action_function == 1) { // Initiate Transfer
            // Enforce Amount Limits
            if ($amount < $min_with) {
                $json_response_encode = json_encode(["status" => "failed", "desc" => "Minimum withdrawal amount is ₦" . number_format($min_with, 2)]);
                return;
            }
            if ($amount > $max_with) {
                $json_response_encode = json_encode(["status" => "failed", "desc" => "Maximum withdrawal amount is ₦" . number_format($max_with, 2)]);
                return;
            }

            // Enforce Daily Frequency Limit
            $check_daily = mysqli_query($connection_server, "SELECT COUNT(*) as total FROM sas_bank_transfer_history WHERE vendor_id='$vid' AND DATE(date) = CURDATE()");
            $rd = mysqli_fetch_assoc($check_daily);
            if ($rd['total'] >= $daily_limit) {
                $json_response_encode = json_encode(["status" => "failed", "desc" => "Daily payout request limit ($daily_limit) reached for this vendor."]);
                return;
            }

            $masked_acc = "******" . substr($account_number, -4);
            $bank_name_display = $bank_code_name_array[$bank_code] ?? $bank_code;
            $withdrawal_desc = "Money sent to ($masked_acc - $bank_name_display)";

            if (userBalance(1) >= $amount && $amount > 0) {
                $transfer_fee = $v_withdrawal_fee;
                $total_debit = (float)$amount + (float)$transfer_fee;

                // Strictly enforce balance check including fees
                if (userBalance(1) >= $total_debit) {
                    // Idempotency guard: block duplicate withdrawals while one is still pending
                    $dup_w = mysqli_query($connection_server, "SELECT id FROM sas_transactions WHERE vendor_id='$vid' AND username='".$get_logged_user_details['username']."' AND status=2 AND type_alternative='Bank Transfer' AND description LIKE 'Money sent to%' LIMIT 1");
                    if (mysqli_num_rows($dup_w) > 0) {
                        $json_response_encode = json_encode(["status" => "failed", "desc" => "You already have a pending withdrawal request. Please wait for it to be processed."]);
                        return;
                    }

                    $initial_status = 2; // Keep as pending (2) during initiation
                    $debit_user = chargeUser("debit", $account_number, $bank_code_alternative, $reference, "", $amount, $total_debit, $withdrawal_desc, $purchase_method, strtoupper($payout_provider), $initial_status);

                    if ($debit_user === "success") {
                        $account_name = $_SESSION["account_name"] ?? $get_api_post_info["account_name"];
                        $bank_name_db = $bank_code_name_array[$bank_code] ?? $bank_code;
                        $narration_db = $narration;
                        $session_id_db = $transfer_enquiry_id;

                        mysqli_query($connection_server, "INSERT INTO sas_bank_transfer_history (vendor_id, reference, username, amount, amount_charged, bank_code, bank_name, account_name, account_number, narration, session_id) VALUES ('$vid', '$reference', '".$get_logged_user_details['username']."', '$amount', '$total_debit', '$bank_code', '$bank_name_db', '$account_name', '$account_number', '$narration_db', '$session_id_db')");

                        if ($needs_approval) {
                            alterTransaction($reference, "description", "$withdrawal_desc - Awaiting Admin Approval");

                            // Send Initiation Email
                            $u_email = $get_logged_user_details["email"];
                            $u_name = $get_logged_user_details["username"];
                            $subject = "Withdrawal Request Received - ₦" . number_format($amount, 2);
                            $body = "Dear $u_name,<br><br>Your withdrawal request of <b>₦" . number_format($amount, 2) . "</b> to <b>$bank_name_db ($account_number)</b> has been received and is awaiting administrative approval.<br><br>Transaction Reference: $reference<br><br>You will be notified once it is approved and processed.";
                            sendVendorEmail($u_email, $subject, $body, true); // Background

                            $json_response_encode = json_encode(["status" => "success", "desc" => "Withdrawal request of ₦".number_format($amount, 2)." submitted for approval. You will receive an email once processed.", "ref" => $reference]);
                        } else {
                            // Immediate Processing
                            $res = ['status' => 'failed', 'message' => 'Internal processing error'];

                            $gate_cfg = getWithdrawalGatewayDetails($payout_provider, $vid);
                            $using_platform_keys = ($gate_cfg && ($gate_cfg['vendor_id'] ?? -1) == 0);

                            $can_proceed = true;
                            if ($using_platform_keys) {
                                $check_v = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT balance FROM sas_vendors WHERE id='$vid' LIMIT 1"));
                                if (($check_v['balance'] ?? 0) < $amount) {
                                    $can_proceed = false;
                                    $res['message'] = "Merchant insufficient platform balance";
                                }
                            }

                            if ($can_proceed) {
                                if ($payout_provider == 'payhub') {
                                    $res = payhubInitiatePayout($amount, $bank_code, $account_number, $account_name, $narration, $vid);
                                } else if ($payout_provider == 'paystack') {
                                    // Paystack logic
                                    $recipient_code = $transfer_enquiry_id;
                                    $ps_res = paystackInitiateTransfer($amount * 100, $recipient_code, $narration, $vid);
                                    $ps_inner = json_decode($ps_res['json_result'] ?? '{}', true);
                                    if (($ps_inner['status'] ?? false) === true) {
                                        $res = ['status' => 'success', 'data' => ['reference' => $ps_inner['data']['reference'] ?? ($ps_inner['data']['transfer_code'] ?? 'N/A')]];
                                    } else {
                                        $res = ['status' => 'failed', 'message' => $ps_inner['message'] ?? ($ps_res['message'] ?? 'Paystack initiation failed')];
                                    }
                                }
                            }

                            $bt_log("Initiation Result for Ref $reference: " . json_encode($res));

                            if (($res['status'] ?? '') == 'success') {
                                $api_ref = $res['data']['reference'] ?? ($res['reference'] ?? ($res['data']['data']['reference'] ?? 'N/A'));
                                alterTransaction($reference, "status", 1);
                                alterTransaction($reference, "api_reference", $api_ref);
                                alterTransaction($reference, "description", $withdrawal_desc);

                                // Debit Vendor if using platform keys
                                if ($using_platform_keys) {
                                    chargeVendor("debit", "payout", "Bank Transfer", $reference, $amount, $amount, "Platform Payout for user withdrawal $reference", $_SERVER['HTTP_HOST'], 1);
                                }

                                // Send Success Email to User
                                $u_email = $get_logged_user_details["email"];
                                $u_name = $get_logged_user_details["username"];
                                $subject = "Withdrawal Successful - ₦" . number_format($amount, 2);
                                $body = "Dear $u_name,<br><br>Your withdrawal of <b>₦" . number_format($amount, 2) . "</b> to <b>$bank_name_db ($account_number)</b> has been processed successfully.<br><br>Transaction Reference: $reference<br>Narration: $narration_db<br><br>Thank you for choosing us!";
                                sendVendorEmail($u_email, $subject, $body, true); // Background

                                $json_response_encode = json_encode(["status" => "success", "desc" => "Transfer of ₦".number_format($amount, 2)." to $account_name was successful.", "ref" => $reference]);
                            } else {
                                // Detailed logging for failure
                                $log_dir = __DIR__ . "/../../logs";
                                if (!is_dir($log_dir)) @mkdir($log_dir, 0777, true);
                                $log_file = $log_dir . "/withdrawal_failures.log";
                                $log_msg = "[" . date('Y-m-d H:i:s') . "] Withdrawal Failed | Provider: $payout_provider | Ref: $reference | User: " . $get_logged_user_details['username'] . "\n";
                                $log_msg .= "Response: " . json_encode($res) . "\n------------------------------------------------\n";
                                @file_put_contents($log_file, $log_msg, FILE_APPEND);

                                // Refund
                                chargeUser("credit", $account_number, "Refund", "RFD".time(), "", $amount, $total_debit, "Refund for failed withdrawal $reference", $purchase_method, "SYSTEM", 1);
                                alterTransaction($reference, "status", 3);
                                alterTransaction($reference, "description", "Withdrawal Failed: " . ($res['message'] ?? 'API Error'));
                                $json_response_encode = json_encode(["status" => "failed", "desc" => $res['message'] ?? "Transfer Failed"]);
                            }
                        }
                    } else {
                        $json_response_encode = json_encode(["status" => "failed", "desc" => "Unable to process charges"]);
                    }
                } else {
                    $json_response_encode = json_encode(["status" => "failed", "desc" => "Insufficient Balance (including fees)"]);
                }
            } else {
                $json_response_encode = json_encode(["status" => "failed", "desc" => "Insufficient Funds"]);
            }
        }

        if ($action_function == 3) { // Verify Account
            if (!empty($account_number) && strlen($account_number) == 10 && !empty($bank_code)) {
                $res = ['status' => 'failed', 'message' => 'Verification failed'];
                $enquiry_id = "";

                $original_bank_code = $bank_code;
                if ($payout_provider == 'payhub') {
                    $raw = payhubResolveBank($account_number, $bank_code, $vid);
                    if (($raw['status'] ?? '') == 'success' && !empty($raw['account_name'])) {
                        $res = ['status' => 'success', 'account_name' => $raw['account_name']];
                        $enquiry_id = "PH_" . time();
                        $bank_code = $raw['mapped_bank_code'] ?? $bank_code;
                    } else {
                        $res['message'] = $raw['message'] ?? 'Account name not found';
                    }
                } else if ($payout_provider == 'paystack') {
                    $raw = paystackResolveAccount($account_number, $bank_code, $vid);
                    if (($raw['status'] ?? '') == 'success' && !empty($raw['account_name'])) {
                        $acc_name = $raw['account_name'];
                        $effective_code = $raw['mapped_bank_code'] ?? $bank_code;
                        // Create recipient
                        $rec_raw = paystackCreateTransferRecipient($acc_name, $account_number, $effective_code, $vid);
                        $rec_inner = json_decode($rec_raw['json_result'] ?? '{}', true);
                        if (($rec_inner['status'] ?? false) === true && !empty($rec_inner['data']['recipient_code'])) {
                            $res = ['status' => 'success', 'account_name' => $acc_name];
                            $enquiry_id = $rec_inner['data']['recipient_code'];
                            $bank_code = $effective_code;
                        } else {
                            $res['message'] = $rec_inner['message'] ?? ($rec_raw['message'] ?? 'Paystack recipient creation failed');
                        }
                    } else {
                        $res['message'] = $raw['message'] ?? 'Account name not found';
                    }
                }

                $bt_log("Verification Result: " . json_encode($res));

                if ($res['status'] == 'success' && !empty($res['account_name'])) {
                    $bank_name_to_use = $bank_code_name_array[$bank_code] ?? ($bank_code_name_array[$original_bank_code] ?? "Bank");
                    $json_response_encode = json_encode([
                        "status" => "success",
                        "customer_name" => $res['account_name'],
                        "bank_name" => $bank_name_to_use,
                        "bank_code" => $bank_code,
                        "account_name" => $res['account_name'],
                        "account_number" => $account_number,
                        "enquiry_id" => $enquiry_id,
                        "transfer_fee" => $v_withdrawal_fee,
                        "narration" => $narration
                    ]);
                } else {
                    $json_response_encode = json_encode(["status" => "failed", "desc" => $res['message']]);
                }
            } else {
                $json_response_encode = json_encode(["status" => "failed", "desc" => "Invalid account details"]);
            }
        }
    } else {
        //Invalid bank type
        $json_response_array = array("status" => "failed", "desc" => "Invalid bank type");
        $json_response_encode = json_encode($json_response_array, true);
    }
} else {
    //Purchase Method Not specified
    $json_response_array = array("status" => "failed", "desc" => "Purchase Method Not specified");
    $json_response_encode = json_encode($json_response_array, true);
}
?>
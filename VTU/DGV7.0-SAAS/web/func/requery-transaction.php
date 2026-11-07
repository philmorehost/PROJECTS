<?php
$purchase_method = strtoupper($purchase_method);
$purchase_method_array = array("API", "WEB", "CRON_JOB", "APP");
if (in_array($purchase_method, $purchase_method_array)) {
    if ($purchase_method === "WEB") {
        $action_function = 2;
        $requery_reference = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9.]+/", "", trim(strip_tags($_GET["requery"]))));
    }

    if (in_array($purchase_method, array("API", "APP"))) {
        $requery_reference = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9.]+/", "", trim(strip_tags($get_api_post_info["reference"]))));
    }

    if ($purchase_method === "CRON_JOB") {
        // CLI workers do not pass through the web/admin controller, so they
        // must explicitly select the requery action as well as the reference.
        // Without this, $action_function is unset and the worker silently
        // skips the entire requery block, leaving every transaction pending.
        $action_function = 2;
        $requery_reference = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9.]+/", "", trim(strip_tags($cron_job_requery_reference))));
    }

    //Requery Transaction
    if ($action_function == 2) {
        if (!empty($requery_reference)) {
            $transaction_query = mysqli_query($connection_server, "SELECT * FROM sas_transactions WHERE reference='$requery_reference' LIMIT 1");
            if (!$transaction_query) {
                if ($purchase_method === "CRON_JOB" && function_exists('bc_requery_queue_log')) {
                    bc_requery_queue_log('requery_transaction_lookup_failed', array(
                        'reference' => (string)$requery_reference,
                        'database_error' => bc_requery_report_safe_text(mysqli_error($connection_server))
                    ));
                }
                $get_transaction_data = null;
            } else {
                $get_transaction_data = mysqli_fetch_assoc($transaction_query);
            }
            if ($get_transaction_data) {
                if ($get_transaction_data["status"] != "3" || (isset($is_admin_requery) && $is_admin_requery)) {
                    $get_api_lists = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id='" . $get_transaction_data["vendor_id"] . "' && id='" . $get_transaction_data["api_id"] . "'");

                    if (!$get_api_lists) {
                        if ($purchase_method === "CRON_JOB" && function_exists('bc_requery_queue_log')) {
                            bc_requery_queue_log('requery_api_lookup_failed', array(
                                'vendor_id' => (int)$get_transaction_data["vendor_id"],
                                'transaction_id' => (int)$get_transaction_data["id"],
                                'reference' => (string)$requery_reference,
                                'api_id' => (int)$get_transaction_data["api_id"],
                                'database_error' => bc_requery_report_safe_text(mysqli_error($connection_server))
                            ));
                        }
                    } elseif (mysqli_num_rows($get_api_lists) > 0) {
                        while ($api_detail = mysqli_fetch_array($get_api_lists)) {
                            if (!empty($api_detail["api_key"])) {
                                $api_gateway_name_file_exists = $api_detail["api_type"] . "-" . str_replace(".", "-", $api_detail["api_base_url"]) . ".php";
                                $gateway_path = dirname(__DIR__, 2) . "/func/api-gateway/requery/";

                                if (file_exists($gateway_path . $api_gateway_name_file_exists)) {
                                    $api_gateway_name = $api_gateway_name_file_exists;
                                } else {
                                    $api_gateway_name = $api_detail["api_type"] . "-localserver.php";
                                }
                                $get_api_reference_id = $get_transaction_data["api_reference"];

                                // Reset variables at the start of each transaction
                                $api_response = null;
                                $api_response_description = null;
                                $api_response_reference = null;
                                $api_response_text = null;
                                $api_response_status = null;
                                $curl_result = null;
                                $curl_errno = null;
                                $curl_error = null;
                                $curl_http_code = null;

                                include($gateway_path . $api_gateway_name);
                                $gateway_response_before_settle = $api_response;
                                $api_response_text = strtolower((string)$api_response_text);
                                if (function_exists('bc_gateway_settle_purchase')) { bc_gateway_settle_purchase($api_response, $api_response_text, $api_response_description, $api_response_status, "", $curl_result ?? ""); }
                                if ($purchase_method === "CRON_JOB" && function_exists('bc_requery_queue_log')) {
                                    bc_requery_queue_log('provider_reply', array(
                                        'vendor_id' => (int)$get_transaction_data["vendor_id"],
                                        'transaction_id' => (int)$get_transaction_data["id"],
                                        'reference' => (string)$requery_reference,
                                        'api_id' => (int)$api_detail["id"],
                                        'api_type' => (string)$api_detail["api_type"],
                                        'api_base_url' => (string)$api_detail["api_base_url"],
                                        'gateway_file' => $api_gateway_name,
                                        'gateway_file_found' => file_exists($gateway_path . $api_gateway_name_file_exists),
                                        'gateway_outcome_before_settle' => $gateway_response_before_settle,
                                        'normalized_outcome' => $api_response,
                                        'provider_status_text' => bc_requery_report_safe_text($api_response_text, array($api_detail["api_key"])),
                                        'provider_description' => bc_requery_report_safe_text($api_response_description, array($api_detail["api_key"])),
                                        'curl_errno' => $curl_errno !== null ? (int)$curl_errno : null,
                                        'curl_error' => bc_requery_report_safe_text($curl_error, array($api_detail["api_key"])),
                                        'http_code' => $curl_http_code !== null ? (int)$curl_http_code : null,
                                        'response_excerpt' => bc_requery_report_safe_text($curl_result, array($api_detail["api_key"]))
                                    ));
                                }
                                // A refund needs a DEFINITIVE provider failure. A gateway that could not classify
                                // the reply (timeout, unreadable body, transport error, an auth/HTTP error page)
                                // must never trigger one: the provider may already have delivered the service and
                                // charged our wallet, so refunding loses that money. Downgrading to pending leaves
                                // the transaction for the next requery, which is what tells the truth.
                                if (function_exists('bc_gateway_refund_is_safe') && !bc_gateway_refund_is_safe($api_response, $api_response_text) && $api_response == "failed") { $api_response = "pending"; $api_response_text = ""; $api_response_description = "Transaction Pending | awaiting confirmation from the upstream provider"; $api_response_status = 2; }
                                
                                if (($api_response == "successful" || $api_response == "pending") && $get_transaction_data["status"] == "3") {
                                    $amount_to_charge = $get_transaction_data["discounted_amount"];
                                    $t_username = $get_transaction_data["username"];
                                    $reference_2 = substr(str_shuffle("12345678901234567890"), 0, 15);
                                    chargeOtherUser($t_username, "debit", $get_transaction_data["product_unique_id"], "Charge", $reference_2, "", $get_transaction_data["amount"], $amount_to_charge, "Charge for automatically updated Ref:<i>'$requery_reference'</i>", $get_transaction_data["mode"], $_SERVER["HTTP_HOST"] ?? "WEB", "1");
                                }

                                if ($api_response == "successful") {
                                    // GFTAL: a definitive success ends the consecutive-failure streak. Recorded
                                    // here (not at request time) because only here is 'successful' a fact.
                                    bc_tx_failure_record($get_transaction_data["vendor_id"], $get_transaction_data["username"], 'success', $get_transaction_data["type_alternative"] ?? '', '', (!empty($get_transaction_data["batch_number"]) ? 'bulk' : 'service'));
                                    alterTransaction($requery_reference, "status", $api_response_status);
                                    alterTransaction($requery_reference, "description", $api_response_description);
                                    $json_response_array = array("ref" => $requery_reference, "status" => "success", "desc" => "Transaction Successful", "response_desc" => $api_response_description);
                                    $json_response_encode = json_encode($json_response_array, true);
                                }

                                if ($api_response == "pending") {
                                    if ($get_transaction_data["status"] == "1") {
                                        // Never downgrade a delivered purchase: a requery that only reports
                                        // "pending" must not turn an already-successful order back into pending.
                                        // A later definitive "failed" reply still reverses it (failure branch).
                                        $json_response_array = array("ref" => $requery_reference, "status" => "success", "desc" => "Transaction Successful", "response_desc" => $get_transaction_data["description"]);
                                        $json_response_encode = json_encode($json_response_array, true);
                                    } else {
                                        alterTransaction($requery_reference, "status", $api_response_status);
                                        alterTransaction($requery_reference, "description", $api_response_description);
                                        $json_response_array = array("ref" => $requery_reference, "status" => "pending", "desc" => "Transaction Pending", "response_desc" => $api_response_description);
                                        $json_response_encode = json_encode($json_response_array, true);
                                    }
                                }

                                if ($api_response == "failed") {
                                    // ─── Idempotency fix: atomically claim the transaction so it can only be
                                    // refunded ONCE. status='2' is a stuck pending purchase; status='1' is a
                                    // purchase that was reported successful but has since been REVERSED by the
                                    // upstream (e.g. a reseller whose parent refunded its account after the
                                    // provider failed the transaction late). A duplicate or concurrent requery
                                    // run sees 0 affected rows and skips the credit - previously a
                                    // transaction that stayed pending was refunded again on every run.
                                    $claim_result = mysqli_query($connection_server, "UPDATE sas_transactions SET status='3' WHERE reference='$requery_reference' AND status IN ('2','1')");

                                    // GFTAL: this point is reached only for a DEFINITIVE provider failure (the
                                    // bc_gateway_refund_is_safe() gate above downgrades anything unclassifiable to
                                    // pending), so it is the one place where "the transaction failed" is a fact.
                                    // The recorder itself ignores provider-side reasons and batch sources.
                                    bc_tx_failure_record($get_transaction_data["vendor_id"], $get_transaction_data["username"], 'failed', $get_transaction_data["type_alternative"] ?? '', $api_response_description, (!empty($get_transaction_data["batch_number"]) ? 'bulk' : 'service'));
                                    $claimed = ($claim_result && mysqli_affected_rows($connection_server) > 0);

                                    // Recovery: a row already sitting at status 3 with NO refund of its own
                                    // is a failure whose refund never happened. The claim above cannot see
                                    // it (status 3 normally means "refunded"), so it would stay stuck for
                                    // ever. Claim it - the guard makes that happen exactly once.
                                    if (!$claimed && !bc_refund_already_credited($get_transaction_data["vendor_id"], $get_transaction_data["username"], "Refund", "Refund for Ref:'$requery_reference'")) {
                                        $stuck_q = mysqli_query($connection_server, "SELECT status FROM sas_transactions WHERE reference='$requery_reference' LIMIT 1");
                                        $stuck_r = $stuck_q ? mysqli_fetch_assoc($stuck_q) : null;
                                        if ($stuck_r && (string)$stuck_r["status"] === '3') {
                                            $claimed = true;
                                        }
                                    }

                                    if ($claimed) {
                                        removeProductPurchaseList($requery_reference);
                                        $reference_2 = substr(str_shuffle("12345678901234567890"), 0, 15);
                                        $phone_no = $get_transaction_data["product_unique_id"];
                                        $amount = $get_transaction_data["amount"];
                                        $discounted_amount = $get_transaction_data["discounted_amount"];
                                        $previous_purchase_method = $get_transaction_data["mode"];
                                        $t_username = $get_transaction_data["username"];

                                        // Pin the vendor to the TRANSACTION's own vendor_id: the queue
                                        // has no request host, and usernames repeat across vendors, so
                                        // resolveVendorID() inside chargeOtherUser() returned 0 and the
                                        // refund was refused - while the row had already been marked 3,
                                        // so nothing ever retried it and the money stayed stuck.
                                        // The queue has no request host, and a username is not unique across
                                        // vendors, so chargeOtherUser()'s resolveVendorID() answered 0 here:
                                        // it returned "failed" BEFORE crediting, the row had already been
                                        // marked 3, and nothing ever retried it - the refund was silently
                                        // lost. Pin resolution to the transaction's own vendor using
                                        // resolveVendorID()'s own override hook, and force a recompute
                                        // because a static/session cache may already hold the host answer.
                                        $_SESSION['tmp_vendor_override'] = (int)$get_transaction_data["vendor_id"];
                                        resolveVendorID(true);
                                        $refund_result = chargeOtherUser($t_username, "credit", $phone_no, "Refund", $reference_2, "", $amount, $discounted_amount, "Refund for Ref:<i>'$requery_reference'</i>", $previous_purchase_method, $_SERVER["HTTP_HOST"] ?? "CRON", "1");
                                        unset($_SESSION['tmp_vendor_override']);
                                        resolveVendorID(true); // drop the pin again for the rest of the run

                                        if ($refund_result !== "success") {
                                            @file_put_contents(__DIR__ . "/../../logs/requery_refund_failures.log", "[" . date('Y-m-d H:i:s') . "] Refund FAILED for ref $requery_reference user $t_username amount $discounted_amount\n", FILE_APPEND);
                                        }

                                        // Robust User Lookup for Email
                                        $get_user_info = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='".$get_transaction_data['vendor_id']."' AND username='$t_username' LIMIT 1"));

                                        // Email Beginning
                                        $log_template_encoded_text_array = array("{firstname}" => $get_user_info["firstname"] ?? $t_username, "{lastname}" => $get_user_info["lastname"] ?? "", "{amount}" => "N" . $discounted_amount, "{description}" => "Refund for Ref No: $requery_reference");
                                        $raw_log_template_subject = getUserEmailTemplate('user-refund', 'subject');
                                        $raw_log_template_body = getUserEmailTemplate('user-refund', 'body');
                                        foreach ($log_template_encoded_text_array as $array_key => $array_val) {
                                            $raw_log_template_subject = str_replace($array_key, $array_val, $raw_log_template_subject);
                                            $raw_log_template_body = str_replace($array_key, $array_val, $raw_log_template_body);
                                        }
                                        sendVendorEmail($get_user_info["email"] ?? "", $raw_log_template_subject, $raw_log_template_body);
                                        // Email End
                                    }

                                    alterTransaction($requery_reference, "description", $api_response_description);
                                    $json_response_array = array("status" => "failed", "desc" => "Transaction Failed");
                                    $json_response_encode = json_encode($json_response_array, true);
                                }
                            } else {
                                //Api Key Empty (Empty Gateway Key)
                                if ($purchase_method === "CRON_JOB" && function_exists('bc_requery_queue_log')) {
                                    bc_requery_queue_log('requery_skipped_empty_api_key', array(
                                        'vendor_id' => (int)$get_transaction_data["vendor_id"],
                                        'transaction_id' => (int)$get_transaction_data["id"],
                                        'reference' => (string)$requery_reference,
                                        'api_id' => (int)$api_detail["id"],
                                        'api_type' => (string)$api_detail["api_type"]
                                    ));
                                }
                                $json_response_array = array("status" => "failed", "desc" => "Empty Gateway Key");
                                $json_response_encode = json_encode($json_response_array, true);
                            }
                        }
                    } else {
                        //No API Installed
                        if ($purchase_method === "CRON_JOB" && function_exists('bc_requery_queue_log')) {
                            bc_requery_queue_log('requery_api_not_configured', array(
                                'vendor_id' => (int)$get_transaction_data["vendor_id"],
                                'transaction_id' => (int)$get_transaction_data["id"],
                                'reference' => (string)$requery_reference,
                                'api_id' => (int)$get_transaction_data["api_id"]
                            ));
                        }
                        $json_response_array = array("status" => "failed", "desc" => "Gateway Error");
                        $json_response_encode = json_encode($json_response_array, true);
                    }
                } else {
                    // Account Refunded Already - the transaction is FAILED and the account has been
                    // reversed. This must report "failed", never "success": a reseller asking about a
                    // purchase its parent already refunded has to learn it failed, otherwise the
                    // reseller keeps showing its own customer a successful order and never refunds.
                    $json_response_array = array("status" => "failed", "desc" => "Account Refunded Already");
                    $json_response_encode = json_encode($json_response_array, true);
                    if ($purchase_method === "CRON_JOB" && function_exists('bc_requery_queue_log')) {
                        bc_requery_queue_log('requery_skipped_already_refunded', array(
                            'vendor_id' => (int)$get_transaction_data["vendor_id"],
                            'transaction_id' => (int)$get_transaction_data["id"],
                            'reference' => (string)$requery_reference
                        ));
                    }
                }
            } else {
                //Transaction doesnt Exists
                $json_response_array = array("status" => "failed", "desc" => "Transaction doesnt Exists");
                $json_response_encode = json_encode($json_response_array, true);
                if ($purchase_method === "CRON_JOB" && function_exists('bc_requery_queue_log')) {
                    bc_requery_queue_log('requery_transaction_not_found', array('reference' => (string)$requery_reference));
                }
            }
        } else {
            //Incomplete Parameters
            $json_response_array = array("status" => "failed", "desc" => "Incomplete Parameters");
            $json_response_encode = json_encode($json_response_array, true);
        }
    }
} else {
    //Purchase Method Not specified
    $json_response_array = array("status" => "failed", "desc" => "Purchase Method Not specified");
    $json_response_encode = json_encode($json_response_array, true);
}
?>

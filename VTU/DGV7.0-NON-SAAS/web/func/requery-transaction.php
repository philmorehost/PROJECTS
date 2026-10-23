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
        $requery_reference = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9.]+/", "", trim(strip_tags($cron_job_requery_reference))));
    }

    //Requery Transaction
    if ($action_function == 2) {
        if (!empty($requery_reference)) {
            $get_transaction_data = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT * FROM sas_transactions WHERE reference='$requery_reference' LIMIT 1"));
            if ($get_transaction_data) {
                if ($get_transaction_data["status"] != "1") {
                    $get_api_lists = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id='" . $get_transaction_data["vendor_id"] . "' && id='" . $get_transaction_data["api_id"] . "'");

                    if (mysqli_num_rows($get_api_lists) > 0) {
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

                                include($gateway_path . $api_gateway_name);
                                $api_response_text = strtolower((string)$api_response_text);
                                if (function_exists('bc_gateway_settle_purchase')) { bc_gateway_settle_purchase($api_response, $api_response_text, $api_response_description, $api_response_status, "", $curl_result ?? ""); }
                                // A refund needs a DEFINITIVE provider failure. A gateway that could not classify
                                // the reply (timeout, unreadable body, transport error, an auth/HTTP error page)
                                // must never trigger one: the provider may already have delivered the service and
                                // charged our wallet, so refunding loses that money. Downgrading to pending leaves
                                // the transaction for the next requery, which is what tells the truth.
                                if (function_exists('bc_gateway_refund_is_safe') && !bc_gateway_refund_is_safe($api_response, $api_response_text) && $api_response == "failed") { $api_response = "pending"; $api_response_text = ""; $api_response_description = "Transaction Pending | awaiting confirmation from the upstream provider"; $api_response_status = 2; }
                                if ($api_response == "successful") {
                                    if ($get_transaction_data["status"] == "3") {
                                        chargeOtherUser($get_transaction_data["username"], "debit", $get_transaction_data["product_unique_id"], "Reversed Refund", substr(str_shuffle("12345678901234567890"), 0, 15), $requery_reference, $get_transaction_data["amount"], $get_transaction_data["discounted_amount"], "Reversed refund for Ref: $requery_reference", "WEB", $_SERVER["HTTP_HOST"] ?? "CRON", 1);
                                    }
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
                                    // Atomically claim the transaction so it can only be refunded ONCE:
                                    // status='2' is a stuck pending purchase, status='1' is a purchase the
                                    // upstream has since REVERSED (e.g. a reseller whose parent refunded its
                                    // account after the provider failed the transaction late). A duplicate or
                                    // concurrent requery run sees 0 affected rows and skips the credit.
                                    $claim_result = mysqli_query($connection_server, "UPDATE sas_transactions SET status='3' WHERE reference='$requery_reference' AND status IN ('2','1')");
                                    $claimed = ($claim_result && mysqli_affected_rows($connection_server) > 0);
                                    if ($claimed) {
                                        removeProductPurchaseList($requery_reference);
                                        $reference_2 = substr(str_shuffle("12345678901234567890"), 0, 15);
                                        $phone_no = $get_transaction_data["product_unique_id"];
                                        $amount = $get_transaction_data["amount"];
                                        $discounted_amount = $get_transaction_data["discounted_amount"];
                                        $previous_purchase_method = $get_transaction_data["mode"];
                                        $t_username = $get_transaction_data["username"];

                                        $refund_result = chargeOtherUser($t_username, "credit", $phone_no, "Refund", $reference_2, "", $amount, $discounted_amount, "Refund for Ref:<i>'$requery_reference'</i>", $previous_purchase_method, $_SERVER["HTTP_HOST"] ?? "CRON", "1");

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
                                    alterTransaction($requery_reference, "status", $api_response_status);
                                    alterTransaction($requery_reference, "description", $api_response_description);
                                    $json_response_array = array("status" => "failed", "desc" => "Transaction Failed");
                                    $json_response_encode = json_encode($json_response_array, true);
                                }
                            } else {
                                //Api Key Empty (Empty Gateway Key)
                                $json_response_array = array("status" => "failed", "desc" => "Empty Gateway Key");
                                $json_response_encode = json_encode($json_response_array, true);
                            }
                        }
                    } else {
                        //No API Installed
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
                }
            } else {
                //Transaction doesnt Exists
                $json_response_array = array("status" => "failed", "desc" => "Transaction doesnt Exists");
                $json_response_encode = json_encode($json_response_array, true);
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
<?php
$purchase_method = strtoupper($purchase_method);
$purchase_method_array = array("API", "WEB", "APP");
if (in_array($purchase_method, $purchase_method_array)) {
    if ($purchase_method === "WEB") {
        $epp = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["epp"]))));
        $quantity = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["quantity"])));

    }

    if (in_array($purchase_method, array("API", "APP"))) {
        $epp = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($get_api_post_info["type"]))));
        $quantity = mysqli_real_escape_string($connection_server, trim(strip_tags($get_api_post_info["quantity"])));
    }
    //$discounted_amount = $amount;
    $type_alternative = ucwords($epp . " exam");
    $reference = substr(str_shuffle("12345678901234567890"), 0, 15);
    $description = "Exam Charges";
    $status = 3;

    $exam_type_array = array("waec", "neco", "nabteb", "jamb");
    if (in_array($epp, $exam_type_array)) {
        // Transaction PIN enforcement - only for a PERSON buying: our own website ("WEB") or the
        // mobile app ("APP"). External API integrations ("API") are deliberately EXEMPT: one merchant
        // api_key serves that merchant's own customers, so there is no single PIN to ask for, and
        // requiring one made every API purchase fail with "Invalid transaction PIN.".
        // The AI assistant buys for the logged-in person on our own site, so it opts back in via
        // $GLOBALS['pin_required_for_api_purchase'] (and may pass a `pin` with the intent).
        $__pin_enforced = in_array($purchase_method, array("WEB", "APP")) || !empty($GLOBALS['pin_required_for_api_purchase']);
        $__pin_input = ($purchase_method === "WEB") ? $_POST : ($get_api_post_info ?? array());
        if ($__pin_enforced && !requireTransactionPin($get_vendor_details ?? array(), $get_logged_user_details, $__pin_input, $__pin_error)) {
            $json_response_array = array("status" => "failed", "desc" => $__pin_error);
            $json_response_encode = json_encode($json_response_array, true);
        } elseif ($get_logged_user_details["status"] != 1) {
            $json_response_array = array("status" => "failed", "desc" => "Your account is currently suspended. Please contact support.");
            $json_response_encode = json_encode($json_response_array, true);
        } elseif (!empty(userBalance(1)) && is_numeric(userBalance(1)) && (userBalance(1) > 0)) {
            if (!empty($epp) && !empty($quantity)) {

                $exam_type_table_name_arrays = array("waec" => "sas_exam_status", "neco" => "sas_exam_status", "nabteb" => "sas_exam_status", "jamb" => "sas_exam_status");
                $get_item_status_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM " . $exam_type_table_name_arrays[$epp] . " WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && product_name='$epp'"));
                $get_api_lists = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && id='" . $get_item_status_details["api_id"] . "' && api_type='exam'");
                $get_api_enabled_lists = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && id='" . $get_item_status_details["api_id"] . "' && api_type='exam' && status='1'");

                if (mysqli_num_rows($get_api_lists) > 0) {
                    if (mysqli_num_rows($get_api_enabled_lists) == 1) {
                        while ($api_detail = mysqli_fetch_array($get_api_lists)) {
                            if (!empty($api_detail["api_key"])) {
                                if ($api_detail["status"] == 1) {
                                    $account_level_table_name_arrays = array(1 => "sas_smart_parameter_values", 2 => "sas_agent_parameter_values", 3 => "sas_api_parameter_values");
                                    if ($account_level_table_name_arrays[$get_logged_user_details["account_level"]] == true) {
                                        $acc_level_table_name = $account_level_table_name_arrays[$get_logged_user_details["account_level"]];
                                        $exam_type_table_name = $exam_type_table_name_arrays[$epp];
                                        $product_name = strtolower($epp);
                                        $product_status_table = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM $exam_type_table_name WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && product_name='" . $product_name . "' LIMIT 1"));
                                        $product_table = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_products WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && product_name='" . $product_name . "' LIMIT 1"));
                                        $product_discount_table = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM $acc_level_table_name WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && api_id='" . $api_detail["id"] . "' && product_id='" . $product_table["id"] . "' && val_1='" . $quantity . "' LIMIT 1"));
                                        $amount = $product_discount_table["val_2"];
                                        $discounted_amount = $amount;
                                    }
                                    if (!empty(trim($product_discount_table["val_1"])) && !empty(trim($product_discount_table["val_2"])) && is_numeric($product_discount_table["val_2"])) {
                                        if ((userBalance(1) >= $amount) && !empty($amount) && is_numeric($amount)) {
                                            if (($product_table["status"] == 1) && ($product_status_table["status"] == 1)) {
                                                if (productIDPurchaseChecker($epp, "exam", $purchase_method) == "success") {
                                                    $debit_user = chargeUser("debit", $epp, $type_alternative, $reference, "", $amount, $discounted_amount, $description, $purchase_method, $_SERVER["HTTP_HOST"], $status);
                                                    if ($debit_user === "success") {
                                                    // Resolve the provider's gateway file from a NORMALISED host. The stored
                                                    // api_base_url is admin-typed and may carry a scheme, a "www." prefix or
                                                    // mixed case. The raw value used to be pasted straight into the filename, so
                                                    // "www.naijaresultpins.com" looked for "exam-www-naijaresultpins-com.php",
                                                    // silently missed, and fell through to exam-localserver.php - a completely
                                                    // different API. Mixed case missed too, since Linux filenames are
                                                    // case-sensitive.
                                                    $api_gateway_base_host = strtolower(trim($api_detail["api_base_url"]));
                                                    $api_gateway_base_host = preg_replace('#^https?://#i', '', $api_gateway_base_host);
                                                    $api_gateway_base_host = preg_replace('#^www\.#i', '', $api_gateway_base_host);
                                                    $api_gateway_base_host = rtrim($api_gateway_base_host, "/");
                                                    $api_gateway_name = "exam-" . str_replace(".", "-", $api_gateway_base_host) . ".php";
                                                    if (!file_exists($_SERVER['DOCUMENT_ROOT'] . "/func/api-gateway/" . $api_gateway_name)) {
                                                        // No gateway file for this provider. Log which one we wanted so a
                                                        // mis-typed domain is diagnosable instead of failing invisibly.
                                                        error_log("[DGV-EXAM-GATEWAY] No gateway file for api_base_url='" . $api_detail["api_base_url"] . "' (looked for " . $api_gateway_name . "); falling back to exam-localserver.php");
                                                        $api_gateway_name = "exam-localserver.php";
                                                    }

                                                    // Reset variables at the start of each transaction
                                                    $api_response = null;
                                                    $api_response_description = null;
                                                    $api_response_reference = null;
                                                    $api_response_text = null;
                                                    $api_response_status = null;

                                                    include_once($_SERVER['DOCUMENT_ROOT'] . "/func/api-gateway/" . $api_gateway_name);
                                                    if (function_exists('bc_gateway_settle_purchase')) { bc_gateway_settle_purchase($api_response, $api_response_text, $api_response_description, $api_response_status, "", $curl_result ?? ""); }
                                                    if (function_exists('bc_gateway_refund_is_safe') && !bc_gateway_refund_is_safe($api_response, $api_response_text) && $api_response == "failed") { $api_response = "pending"; $api_response_text = ""; $api_response_description = "Transaction Pending | awaiting confirmation from the upstream gateway"; $api_response_status = 2; }
                                                    $api_response_text = strtolower((string)$api_response_text);
                                                    if (in_array($api_response, array("successful"))) {
                                                            updateProductPurchaseList($reference, $epp, "exam");
                                                        alterTransaction($reference, "status", $api_response_status);
                                                        alterTransaction($reference, "api_id", $api_detail["id"]);
                                                        alterTransaction($reference, "product_id", $product_table["id"]);
                                                        alterTransaction($reference, "api_reference", $api_response_reference);
                                                        alterTransaction($reference, "description", $api_response_description);
                                                        alterTransaction($reference, "api_website", $api_detail["api_base_url"]);
                                                        $json_response_array = array("ref" => $reference, "status" => "success", "desc" => "Transaction Successful", "response_desc" => $api_response_description);
                                                        $json_response_encode = json_encode($json_response_array, true);
                                                    }

                                                    if (in_array($api_response, array("pending"))) {
                                                            updateProductPurchaseList($reference, $epp, "exam");
                                                        alterTransaction($reference, "status", $api_response_status);
                                                        alterTransaction($reference, "api_id", $api_detail["id"]);
                                                        alterTransaction($reference, "product_id", $product_table["id"]);
                                                        alterTransaction($reference, "api_reference", $api_response_reference);
                                                        alterTransaction($reference, "description", $api_response_description);
                                                        alterTransaction($reference, "api_website", $api_detail["api_base_url"]);
                                                        $json_response_array = array("ref" => $reference, "status" => "pending", "desc" => "Transaction Pending", "response_desc" => $api_response_description);
                                                        $json_response_encode = json_encode($json_response_array, true);
                                                    }

                                                    // Catches "failed" AND any unexpected value. A gateway could previously return
                                                    // neither "successful", "pending" nor "failed" (e.g. an unreadable response
                                                    // left $api_response null), in which case NONE of the branches ran: the
                                                    // customer was debited, got no PIN, received no message and was never
                                                    // refunded. Anything that is not a success or a pending must refund.
                                                    if (!in_array($api_response, array("successful", "pending"))) {
                                                        if (empty($api_response_description)) { $api_response_description = "Transaction Failed"; }
                                                        $reference_2 = substr(str_shuffle("12345678901234567890"), 0, 15);
                                                        alterTransaction($reference, "api_id", $api_detail["id"]);
                                                        alterTransaction($reference, "product_id", $product_table["id"]);
                                                        alterTransaction($reference, "api_reference", $api_response_reference);
                                                        alterTransaction($reference, "description", $api_response_description);
                                                        chargeUser("credit", $epp, "Refund", $reference_2, "", $amount, $discounted_amount, "Refund for Ref:<i>'$reference'</i>", $purchase_method, $_SERVER["HTTP_HOST"], "1");
                                                        // Email Beginning
                                                        $log_template_encoded_text_array = array("{firstname}" => $get_logged_user_details["firstname"], "{lastname}" => $get_logged_user_details["lastname"], "{amount}" => "N" . $discounted_amount, "{description}" => "Refund for Ref No: $reference");
                                                        $raw_log_template_subject = getUserEmailTemplate('user-refund', 'subject');
                                                        $raw_log_template_body = getUserEmailTemplate('user-refund', 'body');
                                                        foreach ($log_template_encoded_text_array as $array_key => $array_val) {
                                                            $raw_log_template_subject = str_replace($array_key, $array_val, $raw_log_template_subject);
                                                            $raw_log_template_body = str_replace($array_key, $array_val, $raw_log_template_body);
                                                        }
                                                        sendVendorEmail($get_logged_user_details["email"], $raw_log_template_subject, $raw_log_template_body);
                                                        // Email End
                                                        $json_response_array = array("status" => "failed", "desc" => "Transaction Failed");
                                                        $json_response_encode = json_encode($json_response_array, true);
                                                    }
                                                    } else {
                                                        //Unable to proceed with charges
                                                        $json_response_array = array("status" => "failed", "desc" => "Unable to proceed with charges");
                                                        $json_response_encode = json_encode($json_response_array, true);
                                                    }
                                                } else {
                                                    //Limit reached
                                                    $json_response_array = array("status" => "failed", "desc" => "ABUSE LIMIT: You have reached the maximum number of times you can buy exam pins today. To protect the platform, your account may be restricted if you continue. Please try again tomorrow.");
                                                    $json_response_encode = json_encode($json_response_array, true);
                                                }
                                            } else {
                                                //Product Locked
                                                $json_response_array = array("status" => "failed", "desc" => "Product Locked");
                                                $json_response_encode = json_encode($json_response_array, true);
                                            }
                                        } else {
                                            //Insufficient Wallet Balance
                                            $json_response_array = array("status" => "failed", "desc" => "Insufficient Wallet Balance");
                                            $json_response_encode = json_encode($json_response_array, true);
                                        }
                                    } else {
                                        //Data size not available
                                        $json_response_array = array("status" => "failed", "desc" => "Exam size not available");
                                        $json_response_encode = json_encode($json_response_array, true);
                                    }
                                } else {
                                    //System Is Busy
                                    $json_response_array = array("status" => "failed", "desc" => "System Is Busy");
                                    $json_response_encode = json_encode($json_response_array, true);
                                }
                            } else {
                                //Api Key Empty (Empty Gateway Key)
                                $json_response_array = array("status" => "failed", "desc" => "Empty Gateway Key");
                                $json_response_encode = json_encode($json_response_array, true);
                            }
                        }
                    } else {
                        if (mysqli_num_rows($get_api_enabled_lists) > 1) {
                            //More than 1 gateway enabled (System is unavailable, try again later)
                            $json_response_array = array("status" => "failed", "desc" => "System is unavailable, try again later");
                            $json_response_encode = json_encode($json_response_array, true);
                        } else {
                            if (mysqli_num_rows($get_api_enabled_lists) < 1) {
                                //Gateway Disabled (Product Not Available)
                                $json_response_array = array("status" => "failed", "desc" => "Product Not Available");
                                $json_response_encode = json_encode($json_response_array, true);
                            }
                        }
                    }
                } else {
                    //No API Installed
                    $json_response_array = array("status" => "failed", "desc" => "Gateway Error");
                    $json_response_encode = json_encode($json_response_array, true);
                }

            } else {
                //Incomplete Parameters
                $json_response_array = array("status" => "failed", "desc" => "Incomplete Parameters");
                $json_response_encode = json_encode($json_response_array, true);
            }
        } else {
            //Balance is LOW
            $json_response_array = array("status" => "failed", "desc" => "Balance is LOW");
            $json_response_encode = json_encode($json_response_array, true);
        }
    } else {
        //Invalid exam type
        $json_response_array = array("status" => "failed", "desc" => "Invalid exam type");
        $json_response_encode = json_encode($json_response_array, true);
    }
} else {
    //Purchase Method Not specified
    $json_response_array = array("status" => "failed", "desc" => "Purchase Method Not specified");
    $json_response_encode = json_encode($json_response_array, true);
}
?>
<?php
$purchase_method = strtoupper($purchase_method);
$purchase_method_array = array("API", "WEB", "APP");
if (in_array($purchase_method, $purchase_method_array)) {
    if ($purchase_method === "WEB") {
        if (isset($_SESSION["customer_name"])) {
            $epp = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_SESSION["customer_provider"]))));
            $amount = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9.]+/", "", trim(strip_tags($_SESSION["customer_amount"]))));
            $customer_id = sanitize_phone_number(trim(strip_tags($_SESSION["customer_id"])));
            $customer_id = mysqli_real_escape_string($connection_server, $customer_id);
        } else {
            $epp = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["epp"]))));
            $amount = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9.]+/", "", trim(strip_tags($_POST["amount"]))));
            $customer_id = sanitize_phone_number(trim(strip_tags($_POST["customer-id"])));
            $customer_id = mysqli_real_escape_string($connection_server, $customer_id);
        }

    }

    if (in_array($purchase_method, array("API", "APP"))) {
        $epp = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($get_api_post_info["provider"] ?? ""))));
        $amount = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9.]+/", "", trim(strip_tags($get_api_post_info["amount"] ?? ""))));
        $customer_id = sanitize_phone_number(trim(strip_tags($get_api_post_info["customer_id"] ?? "")));
        $customer_id = mysqli_real_escape_string($connection_server, $customer_id);
    }
    //$discounted_amount = $amount;
    $type_alternative = ucwords($epp . " betting");
    $reference = substr(str_shuffle("12345678901234567890"), 0, 15);
    $description = "Betting Charges";
    $status = 3;

    $betting_type_array = array("msport", "naijabet", "nairabet", "bet9ja-agent", "betland", "betlion", "supabet", "bet9ja", "bangbet", "betking", "1xbet", "betway", "merrybet", "mlotto", "western-lotto", "hallabet", "green-lotto");
    if (in_array($epp, $betting_type_array)) {
        //Purchase Service
        if ($action_function == 1) {
            // Transaction PIN enforcement - only for a PERSON buying: our own website ("WEB") or the
            // mobile app ("APP"). External API integrations ("API") are deliberately EXEMPT: one
            // merchant api_key serves that merchant's own customers, so there is no single PIN to ask
            // for, and requiring one made every API purchase fail with "Invalid transaction PIN.".
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
                if (userBalance(1) >= $amount && !empty($amount) && is_numeric($amount) && !empty($epp)) {

                    $betting_type_table_name_arrays = array("msport" => "sas_betting_status", "naijabet" => "sas_betting_status", "nairabet" => "sas_betting_status", "bet9ja-agent" => "sas_betting_status", "betland" => "sas_betting_status", "betlion" => "sas_betting_status", "supabet" => "sas_betting_status", "bet9ja" => "sas_betting_status", "bangbet" => "sas_betting_status", "betking" => "sas_betting_status", "1xbet" => "sas_betting_status", "betway" => "sas_betting_status", "merrybet" => "sas_betting_status", "mlotto" => "sas_betting_status", "western-lotto" => "sas_betting_status", "hallabet" => "sas_betting_status", "green-lotto" => "sas_betting_status");
                    $get_item_status_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM " . $betting_type_table_name_arrays[$epp] . " WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && product_name='$epp'"));
                    $get_api_lists = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && id='" . $get_item_status_details["api_id"] . "' && api_type='betting'");
                    $get_api_enabled_lists = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && id='" . $get_item_status_details["api_id"] . "' && api_type='betting' && status='1'");

                    if (mysqli_num_rows($get_api_lists) > 0) {
                        if (mysqli_num_rows($get_api_enabled_lists) == 1) {
                            while ($api_detail = mysqli_fetch_array($get_api_lists)) {
                                if (!empty($api_detail["api_key"])) {
                                    if ($api_detail["status"] == 1) {
                                        $account_level_table_name_arrays = array(1 => "sas_smart_parameter_values", 2 => "sas_agent_parameter_values", 3 => "sas_api_parameter_values");
                                        if ($account_level_table_name_arrays[$get_logged_user_details["account_level"]] == true) {
                                            $acc_level_table_name = $account_level_table_name_arrays[$get_logged_user_details["account_level"]];
                                            $betting_type_table_name = $betting_type_table_name_arrays[$epp];
                                            $product_name = strtolower($epp);
                                            $product_status_table = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM $betting_type_table_name WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && product_name='" . $product_name . "' LIMIT 1"));
                                            $product_table = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_products WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && product_name='" . $product_name . "' LIMIT 1"));
                                            $product_discount_table = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM $acc_level_table_name WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && api_id='" . $api_detail["id"] . "' && product_id='" . $product_table["id"] . "' LIMIT 1"));
                                            $discounted_amount = ($amount - ($amount * ($product_discount_table["val_1"] / 100)));
                                        }
                                        if (is_numeric($product_discount_table["val_1"])) {
                                            if ((userBalance(1) >= $amount) && !empty($amount) && is_numeric($amount)) {
                                                if (($product_table["status"] == 1) && ($product_status_table["status"] == 1)) {
                                                    if (productIDBlockChecker($customer_id) == "success") {
                                                        if (productIDPurchaseChecker($customer_id, "betting", $purchase_method) == "success") {
                                                            $debit_user = chargeUser("debit", $customer_id, $type_alternative, $reference, "", $amount, $discounted_amount, $description, $purchase_method, $_SERVER["HTTP_HOST"], $status);
                                                            if ($debit_user === "success") {
                                                                $api_gateway_name_file_exists = "betting-" . str_replace(".", "-", $api_detail["api_base_url"]) . ".php";
                                                                if (file_exists($_SERVER['DOCUMENT_ROOT'] . "/func/api-gateway/" . $api_gateway_name_file_exists)) {
                                                                    $api_gateway_name = "betting-" . str_replace(".", "-", $api_detail["api_base_url"]) . ".php";
                                                                } else {
                                                                    $api_gateway_name = "betting-localserver.php";
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
                                                                    updateProductPurchaseList($reference, $customer_id, "betting");
                                                                    alterTransaction($reference, "status", $api_response_status);
                                                                    alterTransaction($reference, "api_id", $api_detail["id"]);
                                                                    alterTransaction($reference, "product_id", $product_table["id"]);
                                                                    alterTransaction($reference, "api_reference", $api_response_reference);
                                                                    alterTransaction($reference, "description", $api_response_description);
                                                                    alterTransaction($reference, "api_website", $api_detail["api_base_url"]);
                                                                    $json_response_array = array("ref" => $reference, "status" => "success", "customer_id" => $api_response_customer_id, "token" => $api_response_token, "token_unit" => $api_response_token_unit, "desc" => "Transaction Successful", "response_desc" => $api_response_description);
                                                                    $json_response_encode = json_encode($json_response_array, true);
                                                                }

                                                                if (in_array($api_response, array("pending"))) {
                                                                    updateProductPurchaseList($reference, $customer_id, "betting");
                                                                    alterTransaction($reference, "status", $api_response_status);
                                                                    alterTransaction($reference, "api_id", $api_detail["id"]);
                                                                    alterTransaction($reference, "product_id", $product_table["id"]);
                                                                    alterTransaction($reference, "api_reference", $api_response_reference);
                                                                    alterTransaction($reference, "description", $api_response_description);
                                                                    alterTransaction($reference, "api_website", $api_detail["api_base_url"]);
                                                                    $json_response_array = array("ref" => $reference, "status" => "pending", "customer_id" => $api_response_customer_id, "token" => $api_response_token, "desc" => "Transaction Pending", "response_desc" => $api_response_description);
                                                                    $json_response_encode = json_encode($json_response_array, true);
                                                                }

                                                                if ($api_response == "failed") {
                                                                    $reference_2 = substr(str_shuffle("12345678901234567890"), 0, 15);
                                                                    alterTransaction($reference, "api_id", $api_detail["id"]);
                                                                    alterTransaction($reference, "product_id", $product_table["id"]);
                                                                    alterTransaction($reference, "api_reference", $api_response_reference);
                                                                    alterTransaction($reference, "description", $api_response_description);
                                                                    chargeUser("credit", $customer_id, "Refund", $reference_2, "", $amount, $discounted_amount, "Refund for Ref:<i>'$reference'</i>", $purchase_method, $_SERVER["HTTP_HOST"], "1");
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
                                                            //Error: Daily Limit Exceeded For This Customer Number 
                                                            $json_response_array = array("status" => "failed", "desc" => "ABUSE LIMIT: You have reached the maximum number of times you can fund betting wallet on this particular Betting ID (" . $customer_id . ") today. To protect the platform, your account may be restricted if you continue. Please try again tomorrow.");
                                                            $json_response_encode = json_encode($json_response_array, true);
                                                        }
                                                    } else {
                                                        //Error: Customer number has been blocked
                                                        $json_response_array = array("status" => "failed", "desc" => "Error: Customer number has been blocked");
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
                                            $json_response_array = array("status" => "failed", "desc" => "Betting size not available");
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
        }

        //Verify Service
        if ($action_function == 3) {
            if (!empty($customer_id) && is_numeric($customer_id) && !empty($epp)) {
                $betting_type_table_name_arrays = array("msport" => "sas_betting_status", "naijabet" => "sas_betting_status", "nairabet" => "sas_betting_status", "bet9ja-agent" => "sas_betting_status", "betland" => "sas_betting_status", "betlion" => "sas_betting_status", "supabet" => "sas_betting_status", "bet9ja" => "sas_betting_status", "bangbet" => "sas_betting_status", "betking" => "sas_betting_status", "1xbet" => "sas_betting_status", "betway" => "sas_betting_status", "merrybet" => "sas_betting_status", "mlotto" => "sas_betting_status", "western-lotto" => "sas_betting_status", "hallabet" => "sas_betting_status", "green-lotto" => "sas_betting_status");
                $get_item_status_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM " . $betting_type_table_name_arrays[$epp] . " WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && product_name='$epp'"));
                $get_api_lists = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && id='" . $get_item_status_details["api_id"] . "' && api_type='betting'");
                $get_api_enabled_lists = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && id='" . $get_item_status_details["api_id"] . "' && api_type='betting' && status='1'");

                if (mysqli_num_rows($get_api_lists) > 0) {
                    if (mysqli_num_rows($get_api_enabled_lists) == 1) {
                        while ($api_detail = mysqli_fetch_array($get_api_lists)) {
                            if (!empty($api_detail["api_key"])) {
                                if ($api_detail["status"] == 1) {
                                    $account_level_table_name_arrays = array(1 => "sas_smart_parameter_values", 2 => "sas_agent_parameter_values", 3 => "sas_api_parameter_values");
                                    if ($account_level_table_name_arrays[$get_logged_user_details["account_level"]] == true) {
                                        $betting_type_table_name = $betting_type_table_name_arrays[$epp];
                                        $product_name = strtolower($epp);
                                        $product_status_table = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM $betting_type_table_name WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && product_name='" . $product_name . "' LIMIT 1"));
                                        $product_table = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_products WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && product_name='" . $product_name . "' LIMIT 1"));
                                    }
                                    if (($product_table["status"] == 1) && ($product_status_table["status"] == 1)) {
                                        $api_gateway_name_file_exists = "betting-" . str_replace(".", "-", $api_detail["api_base_url"]) . ".php";
                                        if (file_exists($_SERVER['DOCUMENT_ROOT'] . "/func/api-gateway/verify/" . $api_gateway_name_file_exists)) {
                                            $api_gateway_name = "betting-" . str_replace(".", "-", $api_detail["api_base_url"]) . ".php";
                                        } else {
                                            $api_gateway_name = "betting-localserver.php";
                                        }

                                        // Reset variables at the start of each transaction
                                        $api_response = null;
                                        $api_response_description = null;
                                        $api_response_customer_name = null;
                                        $api_response_customer_address = null;
                                        $api_response_reference = null;
                                        $api_response_text = null;
                                        $api_response_status = null;

                                        include_once($_SERVER['DOCUMENT_ROOT'] . "/func/api-gateway/verify/" . $api_gateway_name);
                                        $api_response_text = strtolower($api_response_text);
                                        if (in_array($api_response, array("successful", "pending"))) {
                                            $json_response_array = array("status" => "success", "desc" => $api_response_description, "customer_name" => $api_response_description, "customer_address" => $api_response_customer_address);
                                            $json_response_encode = json_encode($json_response_array, true);
                                        } else {
                                            $json_response_array = array("status" => "failed", "desc" => "Error: Unable to verify customer" . ($api_response_description ? " - $api_response_description" : ""));
                                            $json_response_encode = json_encode($json_response_array, true);
                                        }
                                    } else {
                                        //Product Locked
                                        $json_response_array = array("status" => "failed", "desc" => "Product Locked");
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
        }

    } else {
        //Invalid betting type
        $json_response_array = array("status" => "failed", "desc" => "Invalid betting type");
        $json_response_encode = json_encode($json_response_array, true);
    }
} else {
    //Purchase Method Not specified
    $json_response_array = array("status" => "failed", "desc" => "Purchase Method Not specified");
    $json_response_encode = json_encode($json_response_array, true);
}
?>
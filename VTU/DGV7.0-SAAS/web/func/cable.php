<?php
$purchase_method = strtoupper($purchase_method);
$purchase_method_array = array("API", "WEB", "APP");
if (in_array($purchase_method, $purchase_method_array)) {
    if ($purchase_method === "WEB") {
        if (isset($_SESSION["cable_name"])) {
            $isp = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_SESSION["cable_provider"]))));
            $iuc_no = sanitize_phone_number(trim(strip_tags($_SESSION["iuc_number"])));
            $iuc_no = mysqli_real_escape_string($connection_server, $iuc_no);
            $quantity = mysqli_real_escape_string($connection_server, trim(strip_tags($_SESSION["cable_package"])));
        } else {
            $isp = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["isp"]))));
            $iuc_no = sanitize_phone_number(trim(strip_tags($_POST["iuc-number"])));
            $iuc_no = mysqli_real_escape_string($connection_server, $iuc_no);
            $quantity = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["quantity"])));
        }
    }

    if (in_array($purchase_method, array("API", "APP"))) {
        $isp = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($get_api_post_info["type"] ?? ""))));
        $iuc_no = sanitize_phone_number(trim(strip_tags($get_api_post_info["iuc_number"] ?? $get_api_post_info["iuc_no"] ?? "")));
        $iuc_no = mysqli_real_escape_string($connection_server, $iuc_no);
        $quantity = mysqli_real_escape_string($connection_server, trim(strip_tags($get_api_post_info["package"] ?? $get_api_post_info["plan_code"] ?? "")));
    }
    //$discounted_amount = $amount;
    $type_alternative = ucwords($isp . " cable");
    $reference = substr(str_shuffle("12345678901234567890"), 0, 15);
    $description = "Cable charges";
    $status = 3;

    $cable_type_array = array("startimes", "dstv", "gotv", "showmax");
    if (in_array($isp, $cable_type_array)) {
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
                if (!empty($isp) && !empty($iuc_no) && is_numeric($iuc_no) && !empty($quantity)) {

                    $cable_type_table_name_arrays = array("startimes" => "sas_cable_status", "dstv" => "sas_cable_status", "gotv" => "sas_cable_status", "showmax" => "sas_cable_status");
                    $get_item_status_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM " . $cable_type_table_name_arrays[$isp] . " WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && product_name='$isp'"));
                    $get_api_lists = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && id='" . $get_item_status_details["api_id"] . "' && api_type='cable'");
                    $get_api_enabled_lists = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && id='" . $get_item_status_details["api_id"] . "' && api_type='cable' && status='1'");

                    if (mysqli_num_rows($get_api_lists) > 0) {
                        if (mysqli_num_rows($get_api_enabled_lists) == 1) {
                            while ($api_detail = mysqli_fetch_array($get_api_lists)) {
                                if (!empty($api_detail["api_key"])) {
                                    if ($api_detail["status"] == 1) {
                                        $account_level_table_name_arrays = array(1 => "sas_smart_parameter_values", 2 => "sas_agent_parameter_values", 3 => "sas_api_parameter_values");
                                        if ($account_level_table_name_arrays[$get_logged_user_details["account_level"]] == true) {
                                            $acc_level_table_name = $account_level_table_name_arrays[$get_logged_user_details["account_level"]];
                                            $cable_type_table_name = $cable_type_table_name_arrays[$isp];
                                            $product_name = strtolower($isp);
                                            $product_status_table = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM $cable_type_table_name WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && product_name='" . $product_name . "' LIMIT 1"));
                                            $product_table = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_products WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && product_name='" . $product_name . "' LIMIT 1"));
                                            $product_discount_table = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM $acc_level_table_name WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && api_id='" . $api_detail["id"] . "' && product_id='" . $product_table["id"] . "' && val_1='" . $quantity . "' LIMIT 1"));
                                            $amount = $product_discount_table["val_2"];
                                            $discounted_amount = $amount;
                                        }
                                        if (!empty(trim($product_discount_table["val_1"])) && !empty(trim($product_discount_table["val_2"])) && is_numeric($product_discount_table["val_2"])) {
                                            if ((userBalance(1) >= $amount) && !empty($amount) && is_numeric($amount)) {
                                                if (($product_table["status"] == 1) && ($product_status_table["status"] == 1)) {
                                                    if (productIDBlockChecker($iuc_no) == "success") {
                                                        if (productIDPurchaseChecker($iuc_no, "cable", $purchase_method) == "success") {
                                                            $debit_user = chargeUser("debit", $iuc_no, $type_alternative, $reference, "", $amount, $discounted_amount, $description, $purchase_method, $_SERVER["HTTP_HOST"], $status);
                                                            if ($debit_user === "success") {
                                                                $api_gateway_name_file_exists = "cable-" . str_replace(".", "-", $api_detail["api_base_url"]) . ".php";
                                                                if (file_exists($_SERVER['DOCUMENT_ROOT'] . "/func/api-gateway/" . $api_gateway_name_file_exists)) {
                                                                    $api_gateway_name = "cable-" . str_replace(".", "-", $api_detail["api_base_url"]) . ".php";
                                                                } else {
                                                                    $api_gateway_name = "cable-localserver.php";
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
                                                                    updateProductPurchaseList($reference, $iuc_no, "cable");
                                                                    alterTransaction($reference, "status", $api_response_status);
                                                                    alterTransaction($reference, "api_id", $api_detail["id"]);
                                                                    alterTransaction($reference, "product_id", $product_table["id"]);
                                                                    alterTransaction($reference, "api_reference", $api_response_reference);
                                                                    alterTransaction($reference, "description", $api_response_description);
                                                                    alterTransaction($reference, "api_website", $api_detail["api_base_url"]);

                                                                    // Award daily bonus
                                                                    award_daily_bonus($get_logged_user_details["username"], time());
                                                                    $bonus_message = isset($_SESSION['bonus_message']) ? $_SESSION['bonus_message'] : null;
                                                                    unset($_SESSION['bonus_message']);

                                                                    $json_response_array = array("ref" => $reference, "status" => "success", "desc" => "Transaction Successful", "response_desc" => $api_response_description, "bonus_message" => $bonus_message);
                                                                    $json_response_encode = json_encode($json_response_array, true);
                                                                }

                                                                if (in_array($api_response, array("pending"))) {
                                                                    updateProductPurchaseList($reference, $iuc_no, "cable");
                                                                    alterTransaction($reference, "status", $api_response_status);
                                                                    alterTransaction($reference, "api_id", $api_detail["id"]);
                                                                    alterTransaction($reference, "product_id", $product_table["id"]);
                                                                    alterTransaction($reference, "api_reference", $api_response_reference);
                                                                    alterTransaction($reference, "description", $api_response_description);
                                                                    alterTransaction($reference, "api_website", $api_detail["api_base_url"]);
                                                                    $json_response_array = array("ref" => $reference, "status" => "pending", "desc" => "Transaction Pending", "response_desc" => $api_response_description);
                                                                    $json_response_encode = json_encode($json_response_array, true);
                                                                }

                                                                if ($api_response == "failed") {
                                                                    $reference_2 = substr(str_shuffle("12345678901234567890"), 0, 15);
                                                                    alterTransaction($reference, "api_id", $api_detail["id"]);
                                                                    alterTransaction($reference, "product_id", $product_table["id"]);
                                                                    alterTransaction($reference, "api_reference", $api_response_reference);
                                                                    alterTransaction($reference, "description", $api_response_description);
                                                                    chargeUser("credit", $iuc_no, "Refund", $reference_2, "", $amount, $discounted_amount, "Refund for Ref:<i>'$reference'</i>", $purchase_method, $_SERVER["HTTP_HOST"], "1");
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
                                                            //Error: Daily Limit Exceeded For This Cable IUC NO: 
                                                            $json_response_array = array("status" => "failed", "desc" => "ABUSE LIMIT: You have reached the maximum number of times you can buy Cable TV subscription on this particular IUC number (" . $iuc_no . ") today. To protect the platform, your account may be restricted if you continue. Please try again tomorrow.");
                                                            $json_response_encode = json_encode($json_response_array, true);
                                                        }
                                                    } else {
                                                        //Error: Cable IUC Number has been blocked
                                                        $json_response_array = array("status" => "failed", "desc" => "Error: Cable IUC Number has been blocked");
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
                                            $json_response_array = array("status" => "failed", "desc" => "Cable size not available");
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
            if (!empty($iuc_no) && is_numeric($iuc_no) && !empty($isp) && !empty($quantity)) {
                $cable_type_table_name_arrays = array("startimes" => "sas_cable_status", "dstv" => "sas_cable_status", "gotv" => "sas_cable_status", "showmax" => "sas_cable_status");
                $get_item_status_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM " . $cable_type_table_name_arrays[$isp] . " WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && product_name='$isp'"));
                $get_api_lists = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && id='" . $get_item_status_details["api_id"] . "' && api_type='cable'");
                $get_api_enabled_lists = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && id='" . $get_item_status_details["api_id"] . "' && api_type='cable' && status='1'");

                if (mysqli_num_rows($get_api_lists) > 0) {
                    if (mysqli_num_rows($get_api_enabled_lists) == 1) {
                        while ($api_detail = mysqli_fetch_array($get_api_lists)) {
                            if (!empty($api_detail["api_key"])) {
                                if ($api_detail["status"] == 1) {
                                    $account_level_table_name_arrays = array(1 => "sas_smart_parameter_values", 2 => "sas_agent_parameter_values", 3 => "sas_api_parameter_values");
                                    if ($account_level_table_name_arrays[$get_logged_user_details["account_level"]] == true) {
                                        $cable_type_table_name = $cable_type_table_name_arrays[$isp];
                                        $product_name = strtolower($isp);
                                        $product_status_table = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM $cable_type_table_name WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && product_name='" . $product_name . "' LIMIT 1"));
                                        $product_table = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_products WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && product_name='" . $product_name . "' LIMIT 1"));
                                    }
                                    if (($product_table["status"] == 1) && ($product_status_table["status"] == 1)) {
                                        $api_gateway_name_file_exists = "cable-" . str_replace(".", "-", $api_detail["api_base_url"]) . ".php";
                                        if (file_exists($_SERVER['DOCUMENT_ROOT'] . "/func/api-gateway/verify/" . $api_gateway_name_file_exists)) {
                                            $api_gateway_name = "cable-" . str_replace(".", "-", $api_detail["api_base_url"]) . ".php";
                                        } else {
                                            $api_gateway_name = "cable-localserver.php";
                                        }

                                        // Reset variables at the start of each transaction
                                        $api_response = null;
                                        $api_response_description = null;
                                        $api_response_reference = null;
                                        $api_response_text = null;
                                        $api_response_status = null;

                                        include_once($_SERVER['DOCUMENT_ROOT'] . "/func/api-gateway/verify/" . $api_gateway_name);
                                        if (in_array($api_response, array("successful", "pending"))) {
                                            $json_response_array = array("status" => "success", "desc" => $api_response_description);
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
        //Invalid cable type
        $json_response_array = array("status" => "failed", "desc" => "Invalid cable type");
        $json_response_encode = json_encode($json_response_array, true);
    }
} else {
    //Purchase Method Not specified
    $json_response_array = array("status" => "failed", "desc" => "Purchase Method Not specified");
    $json_response_encode = json_encode($json_response_array, true);
}
?>
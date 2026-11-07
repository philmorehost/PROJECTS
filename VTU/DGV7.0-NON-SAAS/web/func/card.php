<?php
$purchase_method = strtoupper($purchase_method);
$purchase_method_array = array("API", "WEB", "APP");
if (in_array($purchase_method, $purchase_method_array)) {
    if ($purchase_method === "WEB") {
        $isp = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["isp"]))));
        $qty_number = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9.]+/", "", trim(strip_tags($_POST["qty-number"]))));
        $type = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["type"]))));
        $quantity = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["quantity"]))));
        $business_name = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["card-name"])));
    }

    if (in_array($purchase_method, array("API", "APP"))) {
        $isp = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($get_api_post_info["network"]))));
        $qty_number = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9.]+/", "", trim(strip_tags($get_api_post_info["qty_number"]))));
        $type = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($get_api_post_info["type"]))));
        $quantity = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($get_api_post_info["quantity"]))));
        $business_name = mysqli_real_escape_string($connection_server, trim(strip_tags($get_api_post_info["card_name"])));
    }
    //$discounted_amount = $amount;
    $type_alternative = ucwords($isp . " card");
    $reference = substr(str_shuffle("12345678901234567890"), 0, 15);
    $description = ucwords($type . " charges");
    $status = 3;

    $data_type_array = array("datacard", "rechargecard");
    if (in_array($type, $data_type_array)) {
        if (!empty(userBalance(1)) && is_numeric(userBalance(1)) && (userBalance(1) > 0)) {
            if (!empty($isp) && !empty($qty_number) && is_numeric($qty_number) && ($qty_number >= 1) && !empty($type) && !empty($quantity)) {

                $data_type_table_name_arrays = array("datacard" => "sas_datacard_status", "rechargecard" => "sas_rechargecard_status");
                $get_item_status_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM " . $data_type_table_name_arrays[$type] . " WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && product_name='$isp'"));
                $get_api_lists = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && id='" . $get_item_status_details["api_id"] . "' && api_type='" . $type . "'");
                $get_api_enabled_lists = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && id='" . $get_item_status_details["api_id"] . "' && api_type='" . $type . "' && status='1'");

                if (mysqli_num_rows($get_api_lists) > 0) {
                    if (mysqli_num_rows($get_api_enabled_lists) == 1) {
                        while ($api_detail = mysqli_fetch_array($get_api_lists)) {
                            if (!empty($api_detail["api_key"])) {
                                if ($api_detail["status"] == 1) {
                                    $account_level_table_name_arrays = array(1 => "sas_smart_parameter_values", 2 => "sas_agent_parameter_values", 3 => "sas_api_parameter_values");
                                    $data_type_table_name_arrays = array("datacard" => "sas_datacard_status", "rechargecard" => "sas_rechargecard_status");
                                    if ($account_level_table_name_arrays[$get_logged_user_details["account_level"]] == true) {
                                        $acc_level_table_name = $account_level_table_name_arrays[$get_logged_user_details["account_level"]];
                                        $data_type_table_name = $data_type_table_name_arrays[$type];
                                        $product_name = strtolower($isp);
                                        $product_status_table = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM $data_type_table_name WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && product_name='" . $product_name . "' LIMIT 1"));
                                        $product_table = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_products WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && product_name='" . $product_name . "' LIMIT 1"));
                                        $product_discount_table = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM $acc_level_table_name WHERE vendor_id='" . $get_logged_user_details["vendor_id"] . "' && api_id='" . $api_detail["id"] . "' && product_id='" . $product_table["id"] . "' && val_1='" . $quantity . "' LIMIT 1"));
                                        $amount = ($product_discount_table["val_2"] * $qty_number);
                                        $discounted_amount = $amount;
                                    }
                                    if (!empty(trim($product_discount_table["val_1"])) && !empty(trim($product_discount_table["val_2"])) && is_numeric($product_discount_table["val_2"])) {
                                        if ((userBalance(1) >= $amount) && !empty($amount) && is_numeric($amount)) {
                                            if (($product_table["status"] == 1) && ($product_status_table["status"] == 1)) {
                                                $debit_user = chargeUser("debit", $qty_number, $type_alternative, $reference, "", $amount, $discounted_amount, $description, $purchase_method, $_SERVER["HTTP_HOST"], $status);
                                                if ($debit_user === "success") {
                                                    $api_gateway_name_file_exists = $type . "-" . str_replace(".", "-", $api_detail["api_base_url"]) . ".php";
                                                    if (file_exists($_SERVER['DOCUMENT_ROOT'] . "/func/api-gateway/" . $api_gateway_name_file_exists)) {
                                                        $api_gateway_name = $type . "-" . str_replace(".", "-", $api_detail["api_base_url"]) . ".php";
                                                    } else {
                                                        $api_gateway_name = $type . "-localserver.php";
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
                                                    $users_card_purchased = trim($users_card_purchased);
                                                    if (in_array($api_response, array("successful"))) {
                                                        alterTransaction($reference, "status", $api_response_status);
                                                        alterTransaction($reference, "api_id", $api_detail["id"]);
                                                        alterTransaction($reference, "product_id", $product_table["id"]);
                                                        alterTransaction($reference, "api_reference", $api_response_reference);
                                                        alterTransaction($reference, "description", $api_response_description);
                                                        alterTransaction($reference, "api_website", $api_detail["api_base_url"]);
                                                        $json_response_array = array("ref" => $reference, "status" => "success", "desc" => "Transaction Successful", "response_desc" => $api_response_description, "cards" => $users_card_purchased);
                                                        $json_response_encode = json_encode($json_response_array, true);
                                                    }

                                                    if (in_array($api_response, array("pending"))) {
                                                        alterTransaction($reference, "status", $api_response_status);
                                                        alterTransaction($reference, "api_id", $api_detail["id"]);
                                                        alterTransaction($reference, "product_id", $product_table["id"]);
                                                        alterTransaction($reference, "api_reference", $api_response_reference);
                                                        alterTransaction($reference, "description", $api_response_description);
                                                        alterTransaction($reference, "api_website", $api_detail["api_base_url"]);
                                                        $json_response_array = array("ref" => $reference, "status" => "pending", "desc" => "Transaction Pending", "response_desc" => $api_response_description, "cards" => $users_card_purchased);
                                                        $json_response_encode = json_encode($json_response_array, true);
                                                    }

                                                    if ($api_response == "failed") {
                                                        $reference_2 = substr(str_shuffle("12345678901234567890"), 0, 15);
                                                        alterTransaction($reference, "api_id", $api_detail["id"]);
                                                        alterTransaction($reference, "product_id", $product_table["id"]);
                                                        alterTransaction($reference, "api_reference", $api_response_reference);
                                                        alterTransaction($reference, "description", $api_response_description);
                                                        chargeUser("credit", $qty_number, "Refund", $reference_2, "", $amount, $discounted_amount, "Refund for Ref:<i>'$reference'</i>", $purchase_method, $_SERVER["HTTP_HOST"], "1");
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
                                        //Card size not available
                                        $json_response_array = array("status" => "failed", "desc" => "Card size not available");
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
                $json_response_array = array("status" => "failed", "desc" => "Incomplete Parameters ".$isp." ".$qty_number." ".$type." ".$business_name." ".$quantity);
                $json_response_encode = json_encode($json_response_array, true);
            }
        } else {
            //Balance is LOW
            $json_response_array = array("status" => "failed", "desc" => "Balance is LOW");
            $json_response_encode = json_encode($json_response_array, true);
        }
    } else {
        //Invalid Card type
        $json_response_array = array("status" => "failed", "desc" => "Invalid Card type");
        $json_response_encode = json_encode($json_response_array, true);
    }
} else {
    //Purchase Method Not specified
    $json_response_array = array("status" => "failed", "desc" => "Purchase Method Not specified");
    $json_response_encode = json_encode($json_response_array, true);
}
?>
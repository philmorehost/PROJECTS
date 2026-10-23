<?php error_reporting(0);
include_once("app-config.php");

header("Content-Type: application/json");
$incoming_post_request = file_get_contents("php://input");
fwrite(fopen("app-info.txt", "a"), $incoming_post_request . "\n\n");
$decode_post_request = json_decode($incoming_post_request, true);

if ((json_last_error() === JSON_ERROR_NONE) || (1 == 1)) {

    //Select Vendor Table
    $vendor_id = resolveVendorID();
    $select_vendor_table = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id' AND status=1 LIMIT 1"));
    if ($select_vendor_table) {
        //Dashboard Notice
        $get_dash_notice = "";
        $check_notice = mysqli_query($connection_server, "SELECT * FROM sas_vendor_status_messages WHERE vendor_id = '" . $select_vendor_table["id"] . "' LIMIT 1");
        if (mysqli_num_rows($check_notice) == 1) {
            while ($dash_notice = mysqli_fetch_assoc(result: $check_notice)) {
                $get_dash_notice .= $dash_notice["message"];
            }
        }


        // Admin Information
        $get_admin_email = "";
        $get_admin_contact_email = "";
        $get_admin_phone = "";
        $get_admin_address = "";
        $check_admin = mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id = '" . $select_vendor_table["id"] . "' LIMIT 1");
        if (mysqli_num_rows($check_admin) == 1) {
            while ($admin = mysqli_fetch_assoc($check_admin)) {
                $get_admin_email = $admin["email"];
                $get_admin_contact_email = $admin["email"];
                $get_admin_phone = $admin["phone_number"];
                $get_admin_address = $admin["home_address"];
            }
        }

        $admin_assoc_array = array(
            "dashboard-notification" => $get_dash_notice,
            "purchase-notification" => "",
            "push-notification" => "",
            "phone" => $get_admin_phone,
            "address" => $get_admin_address,
            "email" => $get_admin_email,
            "contact-email" => $get_admin_contact_email
        );

        //Update Banner Ads
        $banner_ads_assoc_array = array();
        $banner_ads_redirect_assoc_array = array();
        // $check_banner_ads = mysqli_query($connection_server, "SELECT * FROM banner_ads");
        // if (mysqli_num_rows($check_banner_ads) >= 1) {
        //     while ($banner_ads = mysqli_fetch_assoc($check_banner_ads)) {
        //         array_push($banner_ads_assoc_array, $banner_ads["banner_url"]);
        //         array_push($banner_ads_redirect_assoc_array, $banner_ads["banner_redirect"]);
        //     }
        // }

        //Update Banner Ads
        $payment_gateway_url_array = array("beewave" => "beewave.ng", "flutterwave" => "flutterwave.com", "paystack" => "paystack.com", "monnify" => "monnify.com", "payvessel" => "payvessel.com");
        $payment_gateway_assoc_array = array();
        $payment_gateway_public_key_assoc_array = array();
        $payment_gateway_secret_key_assoc_array = array();
        $payment_gateway_encrypt_key_assoc_array = array();
        $check_payment_gateway = mysqli_query($connection_server, "SELECT * FROM sas_payment_gateways WHERE vendor_id = '" . $select_vendor_table["id"] . "'");
        if (mysqli_num_rows($check_payment_gateway) >= 1) {
            while ($payment_gateway = mysqli_fetch_assoc($check_payment_gateway)) {
                array_push($payment_gateway_assoc_array, $payment_gateway_url_array[$payment_gateway["gateway_name"]]);
                array_push($payment_gateway_public_key_assoc_array, $payment_gateway["public_key"]);
                array_push($payment_gateway_secret_key_assoc_array, $payment_gateway["secret_key"]);
                array_push($payment_gateway_encrypt_key_assoc_array, $payment_gateway["encrypt_key"]);
            }
        }

        //User
        $user_level_array = array(1 => "Smart Earner", 2 => "Agent Vendor", 3 => "API Vendor");
        $user_account_type = $user_level_array["1"];
        $user_account_type_code = "1";

        $user_assoc_array = array();
        $checkuser = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND username = '" . $decode_post_request["username"] . "'");
        if (mysqli_num_rows($checkuser) == 1) {
            $checkuser_pass = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND username = '" . $decode_post_request["username"] . "' AND password = '" . $decode_post_request["encoded-passkey"] . "'");
            if (mysqli_num_rows($checkuser_pass) == 1) {

                //Transactions
                $user_trans_ref_array = array();
                $user_trans_type_array = array();
                $user_trans_desc_array = array();
                $user_trans_price_array = array();
                $user_trans_date_array = array();
                $user_trans_status_array = array();

                //Get Transactions
                $checkuser_trans = mysqli_query($connection_server, "SELECT * FROM sas_transactions WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND username = '" . $decode_post_request["username"] . "' ORDER BY date DESC");
                if (mysqli_num_rows($checkuser_trans) >= 1) {
                    while ($user_trans_det = mysqli_fetch_assoc($checkuser_trans)) {
                        array_push($user_trans_ref_array, $user_trans_det["reference"]);
                        $user_trans_type_array[$user_trans_det["reference"]] = $user_trans_det["type_alternative"];
                        $user_trans_desc_array[$user_trans_det["reference"]] = $user_trans_det["description"];
                        $user_trans_price_array[$user_trans_det["reference"]] = "₦ " . toDecimal($user_trans_det["amount"], 2);
                        $user_trans_date_array[$user_trans_det["reference"]] = $user_trans_det["date"];
                        $transaction_status_array = array(1 => "success", 2 => "pending", 3 => "failed");
                        $user_trans_status_array[$user_trans_det["reference"]] = $transaction_status_array[$user_trans_det["status"]];
                    }
                }

                $user_trans_assoc_array = array(
                    "tx-ref" => $user_trans_ref_array,
                    "tx-type" => $user_trans_type_array,
                    "tx-desc" => $user_trans_desc_array,
                    "tx-price" => $user_trans_price_array,
                    "tx-date" => $user_trans_date_array,
                    "tx-status" => $user_trans_status_array,
                );

                //Transaction End

                //Complaints
                $user_complaint_id_array = array();
                $user_complaint_title_array = array();
                $user_complaint_body_array = array();
                $user_complaint_desc_array = array();
                $user_complaint_date_array = array();
                $user_complaint_status_array = array();

                //Get Complaints
                // $checkuser_complaints = mysqli_query($connection_server, "SELECT * FROM complaints WHERE username = '" . $decode_post_request["username"] . "' ORDER BY date DESC");
                // if (mysqli_num_rows($checkuser_complaints) >= 1) {
                //     while ($user_complaints_det = mysqli_fetch_assoc($checkuser_complaints)) {
                //         array_push($user_complaint_id_array, $user_complaints_det["ref"]);
                //         $user_complaint_title_array[$user_complaints_det["ref"]] = $user_complaints_det["title"];
                //         $user_complaint_body_array[$user_complaints_det["ref"]] = $user_complaints_det["body"];
                //         $user_complaint_desc_array[$user_complaints_det["ref"]] = $user_complaints_det["notes"];
                //         $user_complaint_date_array[$user_complaints_det["ref"]] = $user_complaints_det["date"];
                //         $user_complaint_status_array[$user_complaints_det["ref"]] = $user_complaints_det["status"];
                //     }
                // }

                // $user_complaints_assoc_array = array(
                //     "id" => $user_complaint_id_array,
                //     "title" => $user_complaint_title_array,
                //     "body" => $user_complaint_body_array,
                //     "desc" => $user_complaint_desc_array,
                //     "date" => $user_complaint_date_array,
                //     "status" => $user_complaint_status_array
                // );

                //Complaint End

                //Virtual Accounts

                //VA (Virtual Acct)
                $user_va_bank_name_array = array();
                $user_va_bank_acct_name_array = array();
                $user_va_bank_acct_number_array = array();
                $user_va_bank_fee_array = array();
                //Get Virtual Accounts
                $checkuser_virtual_accounts = mysqli_query($connection_server, "SELECT * FROM sas_user_banks WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND username = '" . $decode_post_request["username"] . "'");
                if (mysqli_num_rows($checkuser_virtual_accounts) >= 1) {
                    while ($user_va_det = mysqli_fetch_assoc($checkuser_virtual_accounts)) {
                        array_push($user_va_bank_name_array, $user_va_det["bank_name"]);
                        $user_va_bank_acct_name_array[$user_va_det["bank_name"]] = $user_va_det["account_name"];
                        $user_va_bank_acct_number_array[$user_va_det["bank_name"]] = $user_va_det["account_number"];
                        $user_va_bank_fee_array[$user_va_det["bank_name"]] = $user_va_det["fee"] ?? "";
                    }
                }

                $user_va_assoc_array = array(
                    "banks" => $user_va_bank_name_array,
                    "account-names" => $user_va_bank_acct_name_array,
                    "account-numbers" => $user_va_bank_acct_number_array,
                    "fee" => $user_va_bank_fee_array
                );

                //Virtual Accounts End

                while ($user_det = mysqli_fetch_assoc($checkuser_pass)) {

                    $user_account_status_array = array(1 => "Active", 2 => "Disabled", 3 => "Deleted");
                    $user_assoc_array = array(
                        "balance" => toDecimal($user_det["balance"], 2),
                        "currency" => "NGN",
                        "username" => $user_det["username"],
                        "encoded-passkey" => $user_det["password"],
                        "fullname" => $user_det["firstname"] . " " . $user_det["lastname"],
                        "phone" => $user_det["phone_number"],
                        "address" => $user_det["home_address"],
                        "email" => $user_det["email"],
                        "api-key" => $user_det["api_key"],
                        "account-type" => $user_account_type,
                        "status" => $user_account_status_array[$user_det["status"]],
                        "ai_status" => (int)$user_det["ai_status"],
                        "ai_voice_status" => (int)$user_det["ai_voice_status"],
                        "ai_token_balance" => (float)$user_det["ai_token_balance"],
                        "trust_score" => (int)($user_det["trust_score"] ?? 50),
                        "referral-code" => base64_encode($user_det["username"]),
                        "virtual-accounts" => $user_va_assoc_array,
                        "transactions" => $user_trans_assoc_array,
                        "complaints" => $user_complaints_assoc_array,
                    );
                    $user_account_type = $user_level_array[$user_det["account_level"]];
                    $user_account_type_code = $user_det["account_level"];
                    $admin_assoc_array["dashboard-notification"] = str_replace("{username}", ucwords($user_det["username"]), $admin_assoc_array["dashboard-notification"]);
                }

            }
        }


        //End User
        $user_level_product_price_table_array = array(1 => "sas_smart_parameter_values", 2 => "sas_agent_parameter_values", 3 => "sas_api_parameter_values");

        $airtime_services_array = array();
        $vtu_api_id_array = array();
        $vtu_product_id_array = array();

        $select_vtu_apis_id = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND api_type = 'airtime'");
        if (mysqli_num_rows($select_vtu_apis_id) >= 1) {
            while ($each_api_id_detail = mysqli_fetch_assoc($select_vtu_apis_id)) {
                array_push($vtu_api_id_array, $each_api_id_detail["id"]);
            }
            if (count($vtu_api_id_array) >= 1) {

                $vtu_api_id_imploded_params = implode(",", $vtu_api_id_array);

                $select_vtu_product_id = mysqli_query($connection_server, "SELECT * FROM " . $user_level_product_price_table_array[$user_account_type_code] . " WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND api_id IN ($vtu_api_id_imploded_params)");
                if (mysqli_num_rows($select_vtu_product_id) >= 1) {
                    while ($each_product_id_detail = mysqli_fetch_assoc($select_vtu_product_id)) {
                        if (!in_array($each_product_id_detail["product_id"], array_keys($vtu_product_id_array))) {
                            $select_vtu_products = mysqli_query($connection_server, "SELECT * FROM sas_products WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND id = '" . $each_product_id_detail["product_id"] . "'");
                            if (mysqli_num_rows($select_vtu_products) == 1) {
                                $product_detail = mysqli_fetch_array($select_vtu_products);
                                $vtu_product_id_array[$each_product_id_detail["product_id"]] = $product_detail["product_name"];
                            }
                        }
                    }
                }
            }


            $select_vtu_product_price = mysqli_query($connection_server, "SELECT * FROM " . $user_level_product_price_table_array[$user_account_type_code] . " WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND api_id IN ($vtu_api_id_imploded_params)");
            if (mysqli_num_rows($select_vtu_product_price) >= 1) {
                while ($get_product_detail = mysqli_fetch_assoc($select_vtu_product_price)) {
                    if (in_array($get_product_detail["product_id"], array_keys($vtu_product_id_array))) {
                        $select_vtu_status = mysqli_query($connection_server, "SELECT * FROM sas_airtime_status WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND `product_name` = '" . $vtu_product_id_array[$get_product_detail["product_id"]] . "' AND `status` = '1'");
                        if (mysqli_num_rows($select_vtu_status) == 1) {
                            $airtime_services_array[$vtu_product_id_array[$get_product_detail["product_id"]]] = $get_product_detail["val_1"];
                        }
                    }
                }
            }
        }

        $education_services_array = array();

        $education_api_id_array = array();
        $education_product_id_array = array();

        $select_education_apis_id = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND api_type = 'exam'");
        if (mysqli_num_rows($select_education_apis_id) >= 1) {
            while ($each_api_id_detail = mysqli_fetch_assoc($select_education_apis_id)) {
                array_push($education_api_id_array, $each_api_id_detail["id"]);
            }
            if (count($education_api_id_array) >= 1) {

                $education_api_id_imploded_params = implode(",", $education_api_id_array);

                $select_education_product_id = mysqli_query($connection_server, "SELECT * FROM " . $user_level_product_price_table_array[$user_account_type_code] . " WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND api_id IN ($education_api_id_imploded_params)");
                if (mysqli_num_rows($select_education_product_id) >= 1) {
                    while ($each_product_id_detail = mysqli_fetch_assoc($select_education_product_id)) {
                        if (!in_array($each_product_id_detail["product_id"], array_keys($education_product_id_array))) {
                            $select_education_products = mysqli_query($connection_server, "SELECT * FROM sas_products WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND id = '" . $each_product_id_detail["product_id"] . "'");
                            if (mysqli_num_rows($select_education_products) == 1) {
                                $product_detail = mysqli_fetch_array($select_education_products);
                                $education_product_id_array[$each_product_id_detail["product_id"]] = $product_detail["product_name"];
                            }
                        }
                    }
                }
            }


            $select_education_product_price = mysqli_query($connection_server, "SELECT * FROM " . $user_level_product_price_table_array[$user_account_type_code] . " WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND api_id IN ($education_api_id_imploded_params)");
            if (mysqli_num_rows($select_education_product_price) >= 1) {
                while ($get_product_detail = mysqli_fetch_assoc($select_education_product_price)) {
                    if (in_array($get_product_detail["product_id"], array_keys($education_product_id_array))) {
                        $select_education_status = mysqli_query($connection_server, "SELECT * FROM sas_exam_status WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND `product_name` = '" . $education_product_id_array[$get_product_detail["product_id"]] . "' AND `status` = '1'");
                        if (mysqli_num_rows($select_education_status) == 1) {
                            $education_services_array[$education_product_id_array[$get_product_detail["product_id"]] . " | " . $get_product_detail["val_1"]] = $get_product_detail["val_2"];
                        }
                    }
                }
            }


        }

        $electric_services_array = array();


        $electric_api_id_array = array();
        $electric_product_id_array = array();

        $select_electric_apis_id = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND api_type = 'electric'");
        if (mysqli_num_rows($select_electric_apis_id) >= 1) {
            while ($each_api_id_detail = mysqli_fetch_assoc($select_electric_apis_id)) {
                array_push($electric_api_id_array, $each_api_id_detail["id"]);
            }
            if (count($electric_api_id_array) >= 1) {

                $electric_api_id_imploded_params = implode(",", $electric_api_id_array);

                $select_electric_product_id = mysqli_query($connection_server, "SELECT * FROM " . $user_level_product_price_table_array[$user_account_type_code] . " WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND api_id IN ($electric_api_id_imploded_params)");
                if (mysqli_num_rows($select_electric_product_id) >= 1) {
                    while ($each_product_id_detail = mysqli_fetch_assoc($select_electric_product_id)) {
                        if (!in_array($each_product_id_detail["product_id"], array_keys($electric_product_id_array))) {
                            $select_electric_products = mysqli_query($connection_server, "SELECT * FROM sas_products WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND id = '" . $each_product_id_detail["product_id"] . "'");
                            if (mysqli_num_rows($select_electric_products) == 1) {
                                $product_detail = mysqli_fetch_array($select_electric_products);
                                $electric_product_id_array[$each_product_id_detail["product_id"]] = $product_detail["product_name"];
                            }
                        }
                    }
                }
            }


            $select_electric_product_price = mysqli_query($connection_server, "SELECT * FROM " . $user_level_product_price_table_array[$user_account_type_code] . " WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND api_id IN ($electric_api_id_imploded_params)");
            if (mysqli_num_rows($select_electric_product_price) >= 1) {
                while ($get_product_detail = mysqli_fetch_assoc($select_electric_product_price)) {
                    if (in_array($get_product_detail["product_id"], array_keys($electric_product_id_array))) {
                        $select_electric_status = mysqli_query($connection_server, "SELECT * FROM sas_electric_status WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND `product_name` = '" . $electric_product_id_array[$get_product_detail["product_id"]] . "' AND `status` = '1'");
                        if (mysqli_num_rows($select_electric_status) == 1) {
                            $electric_services_array[$electric_product_id_array[$get_product_detail["product_id"]]] = $get_product_detail["val_1"];
                        }
                    }
                }
            }


        }

        $cable_services_array = array();

        $cable_api_id_array = array();
        $cable_product_id_array = array();

        $select_cable_apis_id = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND api_type = 'cable'");
        if (mysqli_num_rows($select_cable_apis_id) >= 1) {
            while ($each_api_id_detail = mysqli_fetch_assoc($select_cable_apis_id)) {
                array_push($cable_api_id_array, $each_api_id_detail["id"]);
            }
            if (count($cable_api_id_array) >= 1) {

                $cable_api_id_imploded_params = implode(",", $cable_api_id_array);

                $select_cable_product_id = mysqli_query($connection_server, "SELECT * FROM " . $user_level_product_price_table_array[$user_account_type_code] . " WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND api_id IN ($cable_api_id_imploded_params)");
                if (mysqli_num_rows($select_cable_product_id) >= 1) {
                    while ($each_product_id_detail = mysqli_fetch_assoc($select_cable_product_id)) {
                        if (!in_array($each_product_id_detail["product_id"], array_keys($cable_product_id_array))) {
                            $select_cable_products = mysqli_query($connection_server, "SELECT * FROM sas_products WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND id = '" . $each_product_id_detail["product_id"] . "'");
                            if (mysqli_num_rows($select_cable_products) == 1) {
                                $product_detail = mysqli_fetch_array($select_cable_products);
                                $cable_product_id_array[$each_product_id_detail["product_id"]] = $product_detail["product_name"];
                            }
                        }
                    }
                }
            }


            $select_cable_product_price = mysqli_query($connection_server, "SELECT * FROM " . $user_level_product_price_table_array[$user_account_type_code] . " WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND api_id IN ($cable_api_id_imploded_params)");
            if (mysqli_num_rows($select_cable_product_price) >= 1) {
                while ($get_product_detail = mysqli_fetch_assoc($select_cable_product_price)) {
                    if (in_array($get_product_detail["product_id"], array_keys($cable_product_id_array))) {
                        $select_cable_status = mysqli_query($connection_server, "SELECT * FROM sas_cable_status WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND `product_name` = '" . $cable_product_id_array[$get_product_detail["product_id"]] . "' AND `status` = '1'");
                        if (mysqli_num_rows($select_cable_status) == 1) {

                            //Add Cable Size
                            $select_cable_product_price_sizes = mysqli_query($connection_server, "SELECT * FROM " . $user_level_product_price_table_array[$user_account_type_code] . " WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND api_id = '" . $get_product_detail["api_id"] . "' AND product_id = '" . $get_product_detail["product_id"] . "'");
                            if (mysqli_num_rows($select_cable_product_price_sizes) >= 1) {
                                $cable_size = array();
                                while ($cable_type_size = mysqli_fetch_assoc($select_cable_product_price_sizes)) {
                                    $cable_size[$cable_type_size["val_1"]] = $cable_type_size["val_2"];

                                    // $cable_services_array[$provider] = $cable_size;
                                    $cable_services_array[$cable_product_id_array[$get_product_detail["product_id"]]] = $cable_size;

                                }
                            }
                        }
                    }
                }
            }
        }

        $data_services_array = array();
        $data_provider_array = array();

        $data_api_type_array = array();
        $data_status_table_array = array();

        $data_api_id_array = array();
        $data_api_product_name_array = array();
        $data_product_id_array = array();

        $select_data_apis_id = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND api_type LIKE '%-data%'");
        if (mysqli_num_rows($select_data_apis_id) >= 1) {
            while ($each_api_id_detail = mysqli_fetch_assoc($select_data_apis_id)) {
                array_push($data_api_id_array, $each_api_id_detail["id"]);
                $data_api_product_name_array[$each_api_id_detail["id"]] = $each_api_id_detail["api_type"];
                if (!in_array($each_api_id_detail["api_type"], $data_api_type_array)) {
                    array_push($data_api_type_array, $each_api_id_detail["api_type"]);
                    $data_status_table_array[$each_api_id_detail["api_type"]] = "sas_" . trim(str_replace("-", "_", $each_api_id_detail["api_type"])) . "_status";
                }
            }

            if (count($data_api_id_array) >= 1) {

                $data_api_id_imploded_params = implode(",", $data_api_id_array);

                $select_data_product_id = mysqli_query($connection_server, "SELECT * FROM " . $user_level_product_price_table_array[$user_account_type_code] . " WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND api_id IN ($data_api_id_imploded_params)");
                if (mysqli_num_rows($select_data_product_id) >= 1) {
                    while ($each_product_id_detail = mysqli_fetch_assoc($select_data_product_id)) {
                        if (!in_array($each_product_id_detail["product_id"], array_keys($data_product_id_array))) {
                            $select_data_products = mysqli_query($connection_server, "SELECT * FROM sas_products WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND id = '" . $each_product_id_detail["product_id"] . "'");
                            if (mysqli_num_rows($select_data_products) == 1) {
                                $product_detail = mysqli_fetch_array($select_data_products);
                                $data_provider_array[$product_detail["product_name"]] = array();
                                $data_product_id_array[$each_product_id_detail["product_id"]] = $product_detail["product_name"];
                            }
                        }
                    }
                }
            }

            $select_data_product_price = mysqli_query($connection_server, "SELECT * FROM " . $user_level_product_price_table_array[$user_account_type_code] . " WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND api_id IN ($data_api_id_imploded_params)");
            if (mysqli_num_rows($select_data_product_price) >= 1) {
                while ($get_product_detail = mysqli_fetch_assoc($select_data_product_price)) {
                    if (in_array($get_product_detail["product_id"], array_keys($data_product_id_array))) {

                        $select_data_apis_type = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND id = '" . $get_product_detail["api_id"] . "'");
                        if (mysqli_num_rows($select_data_apis_type) >= 1) {
                            $get_data_apis_type_detail = mysqli_fetch_assoc($select_data_apis_type);
                            $select_data_status = mysqli_query($connection_server, "SELECT * FROM " . $data_status_table_array[$get_data_apis_type_detail["api_type"]] . " WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND `product_name` = '" . $data_product_id_array[$get_product_detail["product_id"]] . "' AND `status` = '1'");
                            if (mysqli_num_rows($select_data_status) == 1) {

                                //Add Data Size
                                $select_data_product_price_sizes = mysqli_query($connection_server, "SELECT * FROM " . $user_level_product_price_table_array[$user_account_type_code] . " WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND api_id = '" . $get_product_detail["api_id"] . "' AND product_id = '" . $get_product_detail["product_id"] . "'");
                                if (mysqli_num_rows($select_data_product_price_sizes) >= 1) {

                                    while ($data_type_size = mysqli_fetch_assoc($select_data_product_price_sizes)) {
                                        if (in_array($data_type_size["product_id"], array_keys($data_product_id_array))) {
                                            if (in_array($data_type_size["api_id"], array_keys($data_api_product_name_array))) {
                                                $provider_name_str = $data_product_id_array[$data_type_size["product_id"]];
                                                $product_name_str = $data_api_product_name_array[$data_type_size["api_id"]];

                                                if (in_array($provider_name_str, array_keys($data_provider_array))) {
                                                    if (!in_array($product_name_str, array_keys($data_provider_array[$provider_name_str]))) {
                                                        $data_provider_array[$provider_name_str][$product_name_str] = array();
                                                    }

                                                    $current_provider_product_price_array = $data_provider_array[$provider_name_str][$product_name_str];
                                                    if (is_array($current_provider_product_price_array)) {
                                                        // echo $provider_name_str . " = " . $product_name_str . " = " . $data_type_size["val_1"]. " = " . $data_type_size["val_2"] . "\n";
                                                        // var_dump($current_provider_product_price_array)."\n";
                                                        if (!in_array($data_type_size["val_1"], $current_provider_product_price_array)) {
                                                            $data_size = [$data_type_size["val_1"] => $data_type_size["val_2"]];
                                                            // echo $provider_name_str . " = " . $product_name_str . " = " . $data_type_size["val_1"] . " = " . $data_type_size["val_2"] . "\n";

                                                            // $merged_provider_product_qty_price = array_merge($data_provider_array[$provider_name_str][$product_name_str], $data_size);
                                                            $data_provider_array[$provider_name_str][$product_name_str][$data_type_size["val_1"]] = $data_type_size["val_2"];

                                                        }
                                                    }

                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
                $data_services_array = $data_provider_array;
            }
        }

        $card_services_array = array();
        $card_provider_array = array();

        $card_api_type_array = array();
        $card_status_table_array = array();

        $card_api_id_array = array();
        $card_api_product_name_array = array();
        $card_product_id_array = array();

        $select_card_apis_id = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND (api_type = 'datacard' OR api_type = 'rechargecard')");
        if (mysqli_num_rows($select_card_apis_id) >= 1) {
            while ($each_api_id_detail = mysqli_fetch_assoc($select_card_apis_id)) {
                array_push($card_api_id_array, $each_api_id_detail["id"]);
                $card_api_product_name_array[$each_api_id_detail["id"]] = $each_api_id_detail["api_type"];
                if (!in_array($each_api_id_detail["api_type"], $card_api_type_array)) {
                    array_push($card_api_type_array, $each_api_id_detail["api_type"]);
                    $card_status_table_array[$each_api_id_detail["api_type"]] = "sas_" . trim(str_replace("-", "_", $each_api_id_detail["api_type"])) . "_status";
                }
            }

            if (count($card_api_id_array) >= 1) {

                $card_api_id_imploded_params = implode(",", $card_api_id_array);

                $select_card_product_id = mysqli_query($connection_server, "SELECT * FROM " . $user_level_product_price_table_array[$user_account_type_code] . " WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND api_id IN ($card_api_id_imploded_params)");
                if (mysqli_num_rows($select_card_product_id) >= 1) {
                    while ($each_product_id_detail = mysqli_fetch_assoc($select_card_product_id)) {
                        if (!in_array($each_product_id_detail["product_id"], array_keys($card_product_id_array))) {
                            $select_card_products = mysqli_query($connection_server, "SELECT * FROM sas_products WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND id = '" . $each_product_id_detail["product_id"] . "'");
                            if (mysqli_num_rows($select_card_products) == 1) {
                                $product_detail = mysqli_fetch_array($select_card_products);
                                $card_provider_array[$product_detail["product_name"]] = array();
                                $card_product_id_array[$each_product_id_detail["product_id"]] = $product_detail["product_name"];
                            }
                        }
                    }
                }
            }

            $select_card_product_price = mysqli_query($connection_server, "SELECT * FROM " . $user_level_product_price_table_array[$user_account_type_code] . " WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND api_id IN ($card_api_id_imploded_params)");
            if (mysqli_num_rows($select_card_product_price) >= 1) {
                while ($get_product_detail = mysqli_fetch_assoc($select_card_product_price)) {
                    if (in_array($get_product_detail["product_id"], array_keys($card_product_id_array))) {

                        $select_card_apis_type = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND id = '" . $get_product_detail["api_id"] . "'");
                        if (mysqli_num_rows($select_card_apis_type) >= 1) {
                            $get_card_apis_type_detail = mysqli_fetch_assoc($select_card_apis_type);
                            $select_card_status = mysqli_query($connection_server, "SELECT * FROM " . $card_status_table_array[$get_card_apis_type_detail["api_type"]] . " WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND `product_name` = '" . $card_product_id_array[$get_product_detail["product_id"]] . "' AND `status` = '1'");
                            if (mysqli_num_rows($select_card_status) == 1) {

                                //Add Data Size
                                $select_card_product_price_sizes = mysqli_query($connection_server, "SELECT * FROM " . $user_level_product_price_table_array[$user_account_type_code] . " WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND api_id = '" . $get_product_detail["api_id"] . "' AND product_id = '" . $get_product_detail["product_id"] . "'");
                                if (mysqli_num_rows($select_card_product_price_sizes) >= 1) {

                                    while ($card_type_size = mysqli_fetch_assoc($select_card_product_price_sizes)) {
                                        if (in_array($card_type_size["product_id"], array_keys($card_product_id_array))) {
                                            if (in_array($card_type_size["api_id"], array_keys($card_api_product_name_array))) {
                                                $provider_name_str = $card_product_id_array[$card_type_size["product_id"]];
                                                $product_name_str = $card_api_product_name_array[$card_type_size["api_id"]];

                                                if (in_array($provider_name_str, array_keys($card_provider_array))) {
                                                    if (!in_array($product_name_str, array_keys($card_provider_array[$provider_name_str]))) {
                                                        $card_provider_array[$provider_name_str][$product_name_str] = array();
                                                    }

                                                    $current_provider_product_price_array = $card_provider_array[$provider_name_str][$product_name_str];
                                                    if (is_array($current_provider_product_price_array)) {
                                                        // echo $provider_name_str . " = " . $product_name_str . " = " . $card_type_size["val_1"]. " = " . $card_type_size["val_2"] . "\n";
                                                        // var_dump($current_provider_product_price_array)."\n";
                                                        if (!in_array($card_type_size["val_1"], $current_provider_product_price_array)) {
                                                            $card_size = [$card_type_size["val_1"] => $card_type_size["val_2"]];
                                                            // echo $provider_name_str . " = " . $product_name_str . " = " . $card_type_size["val_1"] . " = " . $card_type_size["val_2"] . "\n";

                                                            // $merged_provider_product_qty_price = array_merge($card_provider_array[$provider_name_str][$product_name_str], $card_size);
                                                            $card_provider_array[$provider_name_str][$product_name_str][$card_type_size["val_1"]] = $card_type_size["val_2"];

                                                        }
                                                    }

                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
                $card_services_array = $card_provider_array;
            }
        }

        $virtual_card_services_array = array();
        $virtual_card_provider_array = array();

        $virtual_card_api_type_array = array();
        $virtual_card_status_table_array = array();

        $virtual_card_api_id_array = array();
        $virtual_card_api_product_name_array = array();
        $virtual_card_product_id_array = array();

        $select_virtual_card_apis_id = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND api_type = 'chimoney'");
        if (mysqli_num_rows($select_virtual_card_apis_id) >= 1) {
            while ($each_api_id_detail = mysqli_fetch_assoc($select_virtual_card_apis_id)) {
                array_push($virtual_card_api_id_array, $each_api_id_detail["id"]);
                $virtual_card_api_product_name_array[$each_api_id_detail["id"]] = $each_api_id_detail["api_type"];
                if (!in_array($each_api_id_detail["api_type"], $virtual_card_api_type_array)) {
                    array_push($virtual_card_api_type_array, $each_api_id_detail["api_type"]);
                    $virtual_card_status_table_array[$each_api_id_detail["api_type"]] = "sas_" . trim(str_replace("-", "_", $each_api_id_detail["api_type"])) . "_status";
                }
            }

            if (count($virtual_card_api_id_array) >= 1) {

                $virtual_card_api_id_imploded_params = implode(",", $virtual_card_api_id_array);

                $select_virtual_card_product_id = mysqli_query($connection_server, "SELECT * FROM " . $user_level_product_price_table_array[$user_account_type_code] . " WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND api_id IN ($virtual_card_api_id_imploded_params)");
                if (mysqli_num_rows($select_virtual_card_product_id) >= 1) {
                    while ($each_product_id_detail = mysqli_fetch_assoc($select_virtual_card_product_id)) {
                        if (!in_array($each_product_id_detail["product_id"], array_keys($virtual_card_product_id_array))) {
                            $select_virtual_card_products = mysqli_query($connection_server, "SELECT * FROM sas_products WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND id = '" . $each_product_id_detail["product_id"] . "'");
                            if (mysqli_num_rows($select_virtual_card_products) == 1) {
                                $product_detail = mysqli_fetch_array($select_virtual_card_products);
                                $virtual_card_provider_array[$product_detail["product_name"]] = array();
                                $virtual_card_product_id_array[$each_product_id_detail["product_id"]] = $product_detail["product_name"];
                            }
                        }
                    }
                }
            }

            $select_virtual_card_product_price = mysqli_query($connection_server, "SELECT * FROM " . $user_level_product_price_table_array[$user_account_type_code] . " WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND api_id IN ($virtual_card_api_id_imploded_params)");
            if (mysqli_num_rows($select_virtual_card_product_price) >= 1) {
                while ($get_product_detail = mysqli_fetch_assoc($select_virtual_card_product_price)) {
                    if (in_array($get_product_detail["product_id"], array_keys($virtual_card_product_id_array))) {

                        $select_virtual_card_apis_type = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND id = '" . $get_product_detail["api_id"] . "'");
                        if (mysqli_num_rows($select_virtual_card_apis_type) >= 1) {
                            $get_virtual_card_apis_type_detail = mysqli_fetch_assoc($select_virtual_card_apis_type);
                            $select_virtual_card_status = mysqli_query($connection_server, "SELECT * FROM " . $virtual_card_status_table_array[$get_virtual_card_apis_type_detail["api_type"]] . " WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND `product_name` = '" . $virtual_card_product_id_array[$get_product_detail["product_id"]] . "' AND `status` = '1'");
                            if (mysqli_num_rows($select_virtual_card_status) == 1) {

                                //Add Data Size
                                $select_virtual_card_product_price_sizes = mysqli_query($connection_server, "SELECT * FROM " . $user_level_product_price_table_array[$user_account_type_code] . " WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND api_id = '" . $get_product_detail["api_id"] . "' AND product_id = '" . $get_product_detail["product_id"] . "'");
                                if (mysqli_num_rows($select_virtual_card_product_price_sizes) >= 1) {

                                    while ($virtual_card_type_size = mysqli_fetch_assoc($select_virtual_card_product_price_sizes)) {
                                        if (in_array($virtual_card_type_size["product_id"], array_keys($virtual_card_product_id_array))) {
                                            if (in_array($virtual_card_type_size["api_id"], array_keys($virtual_card_api_product_name_array))) {
                                                $provider_name_str = $virtual_card_product_id_array[$virtual_card_type_size["product_id"]];
                                                $product_name_str = $virtual_card_api_product_name_array[$virtual_card_type_size["api_id"]];

                                                if (in_array($provider_name_str, array_keys($virtual_card_provider_array))) {
                                                    if (!in_array($product_name_str, array_keys($virtual_card_provider_array[$provider_name_str]))) {
                                                        $virtual_card_provider_array[$provider_name_str][$product_name_str] = array();
                                                    }

                                                    $current_provider_product_price_array = $virtual_card_provider_array[$provider_name_str][$product_name_str];
                                                    if (is_array($current_provider_product_price_array)) {
                                                        // echo $provider_name_str . " = " . $product_name_str . " = " . $virtual_card_type_size["val_1"]. " = " . $virtual_card_type_size["val_2"] . "\n";
                                                        // var_dump($current_provider_product_price_array)."\n";
                                                        if (!in_array($virtual_card_type_size["val_1"], $current_provider_product_price_array)) {
                                                            $virtual_card_size = [$virtual_card_type_size["val_1"] => $virtual_card_type_size["val_2"]];
                                                            // echo $provider_name_str . " = " . $product_name_str . " = " . $virtual_card_type_size["val_1"] . " = " . $virtual_card_type_size["val_2"] . "\n";

                                                            // $merged_provider_product_qty_price = array_merge($virtual_card_provider_array[$provider_name_str][$product_name_str], $virtual_card_size);
                                                            $virtual_card_provider_array[$provider_name_str][$product_name_str][$virtual_card_type_size["val_1"]] = $virtual_card_type_size["val_2"];

                                                        }
                                                    }

                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
                $virtual_card_services_array = $virtual_card_provider_array;
            }
        }

        $betting_services_array = array();


        $betting_api_id_array = array();
        $betting_product_id_array = array();

        $select_betting_apis_id = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND api_type = 'betting'");
        if (mysqli_num_rows($select_betting_apis_id) >= 1) {
            while ($each_api_id_detail = mysqli_fetch_assoc($select_betting_apis_id)) {
                array_push($betting_api_id_array, $each_api_id_detail["id"]);
            }
            if (count($betting_api_id_array) >= 1) {

                $betting_api_id_imploded_params = implode(",", $betting_api_id_array);

                $select_betting_product_id = mysqli_query($connection_server, "SELECT * FROM " . $user_level_product_price_table_array[$user_account_type_code] . " WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND api_id IN ($betting_api_id_imploded_params)");
                if (mysqli_num_rows($select_betting_product_id) >= 1) {
                    while ($each_product_id_detail = mysqli_fetch_assoc($select_betting_product_id)) {
                        if (!in_array($each_product_id_detail["product_id"], array_keys($betting_product_id_array))) {
                            $select_betting_products = mysqli_query($connection_server, "SELECT * FROM sas_products WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND id = '" . $each_product_id_detail["product_id"] . "'");
                            if (mysqli_num_rows($select_betting_products) == 1) {
                                $product_detail = mysqli_fetch_array($select_betting_products);
                                $betting_product_id_array[$each_product_id_detail["product_id"]] = $product_detail["product_name"];
                            }
                        }
                    }
                }
            }


            $select_betting_product_price = mysqli_query($connection_server, "SELECT * FROM " . $user_level_product_price_table_array[$user_account_type_code] . " WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND api_id IN ($betting_api_id_imploded_params)");
            if (mysqli_num_rows($select_betting_product_price) >= 1) {
                while ($get_product_detail = mysqli_fetch_assoc($select_betting_product_price)) {
                    if (in_array($get_product_detail["product_id"], array_keys($betting_product_id_array))) {
                        $select_betting_status = mysqli_query($connection_server, "SELECT * FROM sas_betting_status WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND `product_name` = '" . $betting_product_id_array[$get_product_detail["product_id"]] . "' AND `status` = '1'");
                        if (mysqli_num_rows($select_betting_status) == 1) {
                            $betting_services_array[$betting_product_id_array[$get_product_detail["product_id"]]] = $get_product_detail["val_1"];
                        }
                    }
                }
            }


        }

        $bank_transfer_services_array = array();

        $retrieve_bank_list = file_get_contents($_SERVER["DOCUMENT_ROOT"] . "/func/banks.json");
        $retrieve_bank_list = json_decode($retrieve_bank_list, true);
        if (is_array($retrieve_bank_list)) {
            foreach ($retrieve_bank_list as $each_bank) {
                $each_bank_json = $each_bank;
                $bank_transfer_services_array[$each_bank_json["bankCode"]] = $each_bank_json["bankName"];
            }
        }

        $services_assoc_array = array(
            "airtime" => $airtime_services_array,
            "education" => $education_services_array,
            "electric" => $electric_services_array,
            "cable" => $cable_services_array,
            "data" => $data_services_array,
            "card" => $card_services_array,
            "virtual-card" => $virtual_card_services_array,
            "betting" => $betting_services_array,
            "bank-transfer" => $bank_transfer_services_array,
        );


        //Main JSON
        $all_app_json = array(
            "json-status" => "success",
            "status" => "success",
            "status-msg" => "success",
            "admin-settings" => $admin_assoc_array,
            "banner-ads" => $banner_ads_assoc_array,
            "banner-ads-redirect" => $banner_ads_redirect_assoc_array,
            "payment-gateway" => $payment_gateway_assoc_array,
            "payment-gateway-public-key" => $payment_gateway_public_key_assoc_array,
            "payment-gateway-secret-key" => $payment_gateway_secret_key_assoc_array,
            "payment-gateway-encrypt-key" => $payment_gateway_encrypt_key_assoc_array,
            "user-info" => $user_assoc_array,
            "services" => $services_assoc_array
        );
        //End Main JSON

        $app_json = json_encode($all_app_json);
    } else {
        //Website not registered
        $app_json = json_encode(array("json-status" => "failed", "status" => "failed", "status-msg" => "Website not registered"), true);
    }
} else {
    $app_json = json_encode(array("json-status" => "success", "status" => "failed", "status-msg" => "Bad Request"), true);
}

fwrite(fopen("app-json.json", "a"), $app_json . "\n\n");

echo $app_json;

?>
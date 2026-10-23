<?php error_reporting(0);
include_once($_SERVER["DOCUMENT_ROOT"] . "/config.php");
header("Content-Type: application/json");
$incoming_post_request = file_get_contents("php://input");
// fwrite(fopen("app-json.txt", "a"), $incoming_post_request . "\n\n");
$decode_post_request = json_decode($incoming_post_request, true);
if (json_last_error() === JSON_ERROR_NONE || (1 == 1)) {

    //Dashboard Notice
    $get_dash_notice = "";
    $check_notice = mysqli_query($conn_db, "SELECT * FROM dash_notice LIMIT 1");
    if (mysqli_num_rows($check_notice) == 1) {
        while ($dash_notice = mysqli_fetch_assoc($check_notice)) {
            $get_dash_notice = $dash_notice["notice"];
        }
    }

    // Admin Information
    $get_admin_email = "";
    $get_admin_contact_email = "";
    $get_admin_phone = "";
    $get_admin_address = "";
    $check_admin = mysqli_query($conn_db, "SELECT * FROM admins LIMIT 1");
    if (mysqli_num_rows($check_admin) == 1) {
        while ($admin = mysqli_fetch_assoc($check_admin)) {
            $get_admin_email = $admin["email"];
            $get_admin_contact_email = $admin["contact_email"];
            $get_admin_phone = $admin["phone"];
            $get_admin_address = $admin["address"];
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
    $check_banner_ads = mysqli_query($conn_db, "SELECT * FROM banner_ads");
    if (mysqli_num_rows($check_banner_ads) >= 1) {
        while ($banner_ads = mysqli_fetch_assoc($check_banner_ads)) {
            array_push($banner_ads_assoc_array, $banner_ads["banner_url"]);
            array_push($banner_ads_redirect_assoc_array, $banner_ads["banner_redirect"]);
        }
    }

    //Update Banner Ads
    $payment_gateway_assoc_array = array();
    $payment_gateway_public_key_assoc_array = array();
    $payment_gateway_secret_key_assoc_array = array();
    $payment_gateway_encrypt_key_assoc_array = array();
    $check_payment_gateway = mysqli_query($conn_db, "SELECT * FROM installed_gateway_apis");
    if (mysqli_num_rows($check_payment_gateway) >= 1) {
        while ($payment_gateway = mysqli_fetch_assoc($check_payment_gateway)) {
            array_push($payment_gateway_assoc_array, $payment_gateway["api_website"]);
            array_push($payment_gateway_public_key_assoc_array, $payment_gateway["public_key"]);
            array_push($payment_gateway_secret_key_assoc_array, $payment_gateway["secret_key"]);
            array_push($payment_gateway_encrypt_key_assoc_array, $payment_gateway["encrypt_key"]);
        }
    }

    //User
    $user_account_type = "smart";
    $user_assoc_array = array();
    $checkuser = mysqli_query($conn_db, "SELECT * FROM users WHERE username = '" . $decode_post_request["username"] . "'");
    if (mysqli_num_rows($checkuser) == 1) {
        $checkuser_pass = mysqli_query($conn_db, "SELECT * FROM users WHERE username = '" . $decode_post_request["username"] . "' AND password = '" . $decode_post_request["encoded-passkey"] . "'");
        if (mysqli_num_rows($checkuser_pass) == 1) {

            //Transactions
            $user_trans_ref_array = array();
            $user_trans_type_array = array();
            $user_trans_desc_array = array();
            $user_trans_price_array = array();
            $user_trans_date_array = array();
            $user_trans_status_array = array();

            //Get Transactions
            $checkuser_trans = mysqli_query($conn_db, "SELECT * FROM transactions WHERE username = '" . $decode_post_request["username"] . "' ORDER BY date DESC");
            if (mysqli_num_rows($checkuser_trans) >= 1) {
                while ($user_trans_det = mysqli_fetch_assoc($checkuser_trans)) {
                    array_push($user_trans_ref_array, $user_trans_det["tx_ref"]);
                    $user_trans_type_array[$user_trans_det["tx_ref"]] = $user_trans_det["product_type"];
                    $user_trans_desc_array[$user_trans_det["tx_ref"]] = $user_trans_det["description"];
                    $user_trans_price_array[$user_trans_det["tx_ref"]] = "₦ " . $user_trans_det["amount"];
                    $user_trans_date_array[$user_trans_det["tx_ref"]] = $user_trans_det["date"];
                    $user_trans_status_array[$user_trans_det["tx_ref"]] = $user_trans_det["status"];
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
            $checkuser_complaints = mysqli_query($conn_db, "SELECT * FROM complaints WHERE username = '" . $decode_post_request["username"] . "' ORDER BY date DESC");
            if (mysqli_num_rows($checkuser_complaints) >= 1) {
                while ($user_complaints_det = mysqli_fetch_assoc($checkuser_complaints)) {
                    array_push($user_complaint_id_array, $user_complaints_det["ref"]);
                    $user_complaint_title_array[$user_complaints_det["ref"]] = $user_complaints_det["title"];
                    $user_complaint_body_array[$user_complaints_det["ref"]] = $user_complaints_det["body"];
                    $user_complaint_desc_array[$user_complaints_det["ref"]] = $user_complaints_det["notes"];
                    $user_complaint_date_array[$user_complaints_det["ref"]] = $user_complaints_det["date"];
                    $user_complaint_status_array[$user_complaints_det["ref"]] = $user_complaints_det["status"];
                }
            }

            $user_complaints_assoc_array = array(
                "id" => $user_complaint_id_array,
                "title" => $user_complaint_title_array,
                "body" => $user_complaint_body_array,
                "desc" => $user_complaint_desc_array,
                "date" => $user_complaint_date_array,
                "status" => $user_complaint_status_array
            );

            //Complaint End

            //Virtual Accounts

            //VA (Virtual Acct)
            $user_va_bank_name_array = array();
            $user_va_bank_acct_name_array = array();
            $user_va_bank_acct_number_array = array();
            $user_va_bank_fee_array = array();
            //Get Virtual Accounts
            $checkuser_virtual_accounts = mysqli_query($conn_db, "SELECT * FROM virtual_accounts WHERE username = '" . $decode_post_request["username"] . "' ORDER BY date DESC");
            if (mysqli_num_rows($checkuser_virtual_accounts) >= 1) {
                while ($user_va_det = mysqli_fetch_assoc($checkuser_virtual_accounts)) {
                    array_push($user_va_bank_name_array, $user_va_det["bank_name"]);
                    $user_va_bank_acct_name_array[$user_va_det["bank_name"]] = $user_va_det["account_name"];
                    $user_va_bank_acct_number_array[$user_va_det["bank_name"]] = $user_va_det["account_number"];
                    $user_va_bank_fee_array[$user_va_det["bank_name"]] = $user_va_det["fee"];
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

                
                if (strlen($user_bvn) == 11 || strlen($user_nin) == 11) {
                    //Get Virtual Accounts
                    $recheckuser_virtual_accounts = mysqli_query($conn_db, "SELECT * FROM virtual_accounts WHERE username = '" . $user_det["username"] . "' ORDER BY date DESC");
                    if (mysqli_num_rows($recheckuser_virtual_accounts) >= 1) {
                        while ($user_va_det = mysqli_fetch_assoc($recheckuser_virtual_accounts)) {

                        }
                    }

                }

                $user_assoc_array = array(
                    "balance" => $user_det["balance"],
                    "currency" => "NGN",
                    "username" => $user_det["username"],
                    "encoded-passkey" => $user_det["password"],
                    "fullname" => $user_det["firstname"] . " " . $user_det["lastname"],
                    "phone" => $user_det["phone"],
                    "address" => $user_det["address"],
                    "email" => $user_det["email"],
                    "api-key" => $user_det["token"],
                    "account-type" => $user_det["account_type"],
                    "status" => $user_det["status"],
                    "referral-code" => base64_encode($user_det["username"]),
                    "virtual-accounts" => $user_va_assoc_array,
                    "transactions" => $user_trans_assoc_array,
                    "complaints" => $user_complaints_assoc_array,
                );
                $user_account_type = $user_det["account_type"];
            }

        }
    }


    //End User

    $airtime_services_array = array();
    $select_vtu_product_price = mysqli_query($conn_db, "SELECT * FROM product_prices WHERE product_type = 'vtu'");
    if (mysqli_num_rows($select_vtu_product_price) >= 1) {
        while ($get_product_detail = mysqli_fetch_assoc($select_vtu_product_price)) {
            $select_vtu_status = mysqli_query($conn_db, "SELECT * FROM product_apis WHERE product_type = '" . $get_product_detail["product_type"] . "' AND `provider` = '" . $get_product_detail["provider"] . "' AND `status` = 'enabled'");
            if (mysqli_num_rows($select_vtu_status) == 1) {
                $airtime_services_array[$get_product_detail["provider"]] = ($get_product_detail[$user_account_type]);
            }
        }
    }

    $education_services_array = array();
    $select_education_product_price = mysqli_query($conn_db, "SELECT * FROM product_prices WHERE product_type = 'education'");
    if (mysqli_num_rows($select_education_product_price) >= 1) {
        while ($get_product_detail = mysqli_fetch_assoc($select_education_product_price)) {
            $select_education_status = mysqli_query($conn_db, "SELECT * FROM product_apis WHERE product_type = '" . $get_product_detail["product_type"] . "' AND `provider` = '" . $get_product_detail["provider"] . "' AND `status` = 'enabled'");
            if (mysqli_num_rows($select_education_status) == 1) {
                $education_services_array[$get_product_detail["provider"]] = ($get_product_detail["price"] + $get_product_detail[$user_account_type]);
            }
        }
    }

    $electric_services_array = array();
    $select_electric_product_price = mysqli_query($conn_db, "SELECT * FROM product_prices WHERE product_type = 'electric'");
    if (mysqli_num_rows($select_electric_product_price) >= 1) {
        while ($get_product_detail = mysqli_fetch_assoc($select_electric_product_price)) {
            $select_electric_status = mysqli_query($conn_db, "SELECT * FROM product_apis WHERE product_type = '" . $get_product_detail["product_type"] . "' AND `provider` = '" . $get_product_detail["provider"] . "' AND `status` = 'enabled'");
            if (mysqli_num_rows($select_electric_status) == 1) {
                $electric_services_array[$get_product_detail["provider"]] = ($get_product_detail["price"] + $get_product_detail[$user_account_type]);
            }
        }
    }

    $cable_services_array = array();

    //Add Cable Providers
    $select_cable_product_provider = mysqli_query($conn_db, "SELECT * FROM product_prices WHERE product_type LIKE '%cable%'");
    if (mysqli_num_rows($select_cable_product_provider) >= 1) {
        while ($get_product_provider_detail = mysqli_fetch_assoc($select_cable_product_provider)) {
            //Add Provider
            $provider = $get_product_provider_detail["provider"];

            if (!in_array($provider, $cable_services_array)) {
                $cable_services_array[$provider] = array();
            }

            $select_cable_type = mysqli_query($conn_db, "SELECT * FROM product_prices WHERE `provider` = '$provider' AND product_type LIKE '%cable%'");
            if (mysqli_num_rows($select_cable_type) >= 1) {
                while ($cable_type = mysqli_fetch_assoc($select_cable_type)) {
                    if (!in_array($cable_type["product_type"], $cable_services_array[$provider])) {


                        // //Add Cable Size
                        $select_cable_status = mysqli_query($conn_db, "SELECT * FROM product_apis WHERE `provider` = '$provider' AND product_type = '" . $cable_type["product_type"] . "' AND `status` = 'enabled'");
                        $select_cable_type_size = mysqli_query($conn_db, "SELECT * FROM product_prices WHERE `provider` = '$provider' AND product_type = '" . $cable_type["product_type"] . "'");
                        if ((mysqli_num_rows($select_cable_status) == 1) && mysqli_num_rows($select_cable_type_size) >= 1) {
                            $cable_size = array();
                            while ($cable_type_size = mysqli_fetch_assoc($select_cable_type_size)) {
                                $cable_size[$cable_type_size["qty"]] = ($cable_type_size["price"] + $cable_type_size[$user_account_type]);

                                $cable_services_array[$provider] = $cable_size;

                            }
                        }

                    }
                }
            }
        }
    }



    $data_services_array = array();

    //Add Data Providers
    $select_data_product_provider = mysqli_query($conn_db, "SELECT * FROM product_prices WHERE product_type LIKE '%data%'");
    if (mysqli_num_rows($select_data_product_provider) >= 1) {
        while ($get_product_provider_detail = mysqli_fetch_assoc($select_data_product_provider)) {
            //Add Provider
            $provider = $get_product_provider_detail["provider"];

            if (!in_array($provider, $data_services_array)) {
                $data_services_array[$provider] = array();
            }

            $select_data_type = mysqli_query($conn_db, "SELECT * FROM product_prices WHERE `provider` = '$provider' AND product_type LIKE '%data%'");
            if (mysqli_num_rows($select_data_type) >= 1) {
                while ($data_type = mysqli_fetch_assoc($select_data_type)) {
                    if (!in_array($data_type["product_type"], $data_services_array[$provider])) {


                        // //Add Data Size
                        $select_data_status = mysqli_query($conn_db, "SELECT * FROM product_apis WHERE `provider` = '$provider' AND product_type = '" . $data_type["product_type"] . "' AND `status` = 'enabled'");
                        $select_data_type_size = mysqli_query($conn_db, "SELECT * FROM product_prices WHERE `provider` = '$provider' AND product_type = '" . $data_type["product_type"] . "'");
                        if ((mysqli_num_rows($select_data_status) == 1) && mysqli_num_rows($select_data_type_size) >= 1) {
                            $data_size = array();
                            while ($data_type_size = mysqli_fetch_assoc($select_data_type_size)) {
                                $data_size[$data_type_size["qty"]] = ($data_type_size["price"] + $data_type_size[$user_account_type]);

                                $data_services_array[$provider][$data_type["product_type"]] = $data_size;

                            }
                        }

                    }
                }
            }
        }
    }

    $services_assoc_array = array(
        "airtime" => $airtime_services_array,
        "education" => $education_services_array,
        "electric" => $electric_services_array,
        "cable" => $cable_services_array,
        "data" => $data_services_array,
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
    $app_json = json_encode(array("json-status" => "success", "status" => "failed", "status-msg" => "Bad Request"), true);
}

// fwrite(fopen("app-json.txt", "a"), $app_json . "\n\n");

echo $app_json;

$app_json_2 =
    '{
    "json-status" : "success",
    "status" : "success",
    "status-msg" : "Successful Message",
    "admin-settings" : {
        "dashboard-notification" : "Welcome to SMART-BILL",
        "purchase-notification" : "Airtel is not available",
        "push-notification" : "Get MTN datashare @ N300 per GB",
        "whatsapp" : "08124232128",
        "address" : "Sabo-oke Ilorin, Kw St.",
        "email" : "hello@smartbill.com"
    },
    "banner-ads" : 
    [
        "https://th.bing.com/th?id=OIF.9yXPmRur1KO7Nez%2fHMXbuQ&r=0&rs=1&pid=ImgDetMain&o=7&rm=3",
        "https://tse1.mm.bing.net/th/id/OIP.iWIHqo2ZUud_KmH5oNL6kwHaHa?r=0&w=626&h=626&rs=1&pid=ImgDetMain&o=7&rm=3",
        "https://tse4.mm.bing.net/th/id/OIP.p-z0ePmbTGl2H2QbAsoxXAHaHa?r=0&w=700&h=700&rs=1&pid=ImgDetMain&o=7&rm=3"
    ],
    "user-info" : {
        "balance" : "50000.00",
        "currency" : "NGN",
        "username" : "realbeebay",
        "encoded-passkey" : "e10adc3949ba59abbe56e057f20f883e",
        "fullname" : "Habeebullahi Abdulrahaman",
        "phone" : "08124232128",
        "address" : "72 Edun Street, Ilorin, Kw St.",
        "email" : "beebayads@gmail.com",
        "status" : "1",
        "referral-code" : "GF4K9H",
        "virtual-accounts" : 
        {
            "banks" : ["Bank78", "Palmpay", "Wema"],
            "account-names" : {"Bank78" : "SmartBill - Habeebullahi", "Palmpay" : "SmartBill - Habeebullahi Abd", "Wema" : "SmartBill - Abdul"},
            "account-numbers" : {"Bank78" : "0127859532", "Palmpay" : "8124232128", "Wema" : "8287942705"},
            "fee" : {"Bank78" : "50", "Palmpay" : "35", "Wema" : "1%"}
        },
        "transactions" : 
        {
            "tx-ref" : ["376373736", "3836638368"],
            "tx-type" : {"376373736" : "sme-data", "3836638368" : "cg-data"},
            "tx-desc" : {"376373736" : "You have successfully shared 1gb MTN data to 08140985576", "3836638368" : "2gb GLO CG Data shared to 08058744518 successfully"},
            "tx-price" : {"376373736" : "560", "3836638368" : "340"},
            "tx-status" : {"376373736" : "success", "3836638368" : "pending"}
        }
    },
    "services" : {
        "airtime" : {"mtn" : "1", "airtel" : "2.5", "glo" : "2", "etisalat" : "1.5"},
        "education" : {"waec" : "2400", "neco" : "2100", "nabteb" : "1800", "jamb" : "4500"},
        "electric" : {"ibedc" : "1", "ikedc" : "2", "jedc" : "1.2", "phed" : "1.4"},
        "cable" : 
        {
            "startimes" : {
                "nova" : "N1800 (Monthly)",
                "basic" : "2500 (Weekly)",
                "rrr" : "3200 (Bi-Weekly)"
            },
            "dstv" : {
                "smallie" : "1800",
                "yanga" : "2500",
                "confam" : "3200"
            },
            "gotv" : {
                "jollie" : "1800"
            },
            "showmax" : {
                "jollie" : "1800"
            }
        },
        "data" : 
        {
            "mtn" : {
                "sme-data" : {
                    "1gb" : "400",
                    "2gb" : "5200",
                    "3gb" : "7800",
                    "5gb" : "99000"
                },
                "cg-data" : {
                    "1gb" : "600",
                    "2gb" : "1200",
                    "3gb" : "1800",
                    "5gb" : "3000"
                },
                "dd-data" : {
                    "1gb" : "600",
                    "2gb" : "4200",
                    "3gb" : "1800",
                    "5gb" : "5000"
                }
            },
            "airtel" : {
                "sme-data" : {
                    "1gb" : "600",
                    "2gb" : "1200",
                    "3gb" : "1800",
                    "5gb" : "3000"
                },
                "cg-data" : {
                    "1gb" : "600",
                    "2gb" : "5600",
                    "3gb" : "6700",
                    "5gb" : "77000"
                },
                "dd-data" : {
                    "1gb" : "400",
                    "2gb" : "800",
                    "3gb" : "1600",
                    "5gb" : "6000"
                }
            }
        }
    }
}';

// echo $app_json;
?>
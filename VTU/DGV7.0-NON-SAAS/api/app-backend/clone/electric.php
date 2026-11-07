<?php error_reporting(0);
include_once($_SERVER["DOCUMENT_ROOT"] . "/config.php");
header("Content-Type: application/json");
$incoming_post_request = file_get_contents("php://input");
// fwrite(fopen("electric.txt", "a"), $incoming_post_request . "\n\n");
$decode_post_request = json_decode($incoming_post_request, true);
$product_type = "";
if (json_last_error() === JSON_ERROR_NONE) {
    $username = mysqli_real_escape_string($conn_db, trim(strip_tags($decode_post_request["username"])));
    $password = mysqli_real_escape_string($conn_db, trim(strip_tags($decode_post_request["encoded-passkey"])));
    $network = mysqli_real_escape_string($conn_db, trim(strip_tags($decode_post_request["network"])));
    $electric_type = "electric";
    $meter_type = mysqli_real_escape_string($conn_db, trim(strip_tags($decode_post_request["meter_type"])));
    $meter_number = str_replace(" ", "", mysqli_real_escape_string($conn_db, trim(strip_tags($decode_post_request["meter_number"]))));
    $amount = mysqli_real_escape_string($conn_db, trim(strip_tags($decode_post_request["amount"])));
    $amount = str_replace(["-", "+", "*", "/"], "", $amount);

    $status_update = "failed";
    $status_msg = "Unknown Error";
    $electric_token = "";
    $electric_token_unit = "";

    if (
        !empty($username) &&
        !empty($password) &&
        !empty($network) &&
        !empty($electric_type) &&
        !empty($meter_type) &&
        !empty($meter_number) &&
        is_numeric($meter_number) &&
        (strlen($meter_number) == 11) &&
        !empty($amount) &&
        is_numeric($amount) &&
        ($amount >= 100)
    ) {

        $product_type = $electric_type;
        $checkuser = mysqli_query($conn_db, "SELECT * FROM users WHERE username = '" . $username . "' OR email='" . $username . "'");
        if (mysqli_num_rows($checkuser) == 1) {
            $checkuser_pass = mysqli_query($conn_db, "SELECT * FROM users WHERE (username = '" . $username . "' OR email='" . $username . "') AND password = '" . $password . "'");
            if (mysqli_num_rows($checkuser_pass) == 1) {
                $get_user_info = mysqli_fetch_array($checkuser_pass);
                $select_product_details = mysqli_query($conn_db, "SELECT * FROM product_prices WHERE product_type = '$product_type' AND `provider` = '$network' AND qty = '1'");
                if (mysqli_num_rows($select_product_details) == 1) {
                    $get_product_price_detail = mysqli_fetch_array($select_product_details);
                    $product_price = floatval($get_product_price_detail["price"]);
                    $user_account_type = get_user_info($username, "account_type");
                    $product_profit = floatval($get_product_price_detail[$user_account_type]);

                    $user_balance = get_user_info($username, "balance");

                    if (is_numeric($user_balance) && ($user_balance >= 0)) {
                        if (($user_balance >= $amount)) {
                            $user_service_permission = false;
                            $select_service_permission = mysqli_query($conn_db, "SELECT * FROM user_product_permission WHERE user_id = '" . $get_user_info["id"] . "' AND `provider` = '$network' AND product_type = '$product_type'");
                            if (mysqli_num_rows($select_service_permission) == 0) {
                                $user_service_permission = true;
                            } elseif (mysqli_num_rows($select_service_permission) == 1) {
                                $get_service_permission_info = mysqli_fetch_array($select_service_permission);
                                if ($get_service_permission_info["status"] == "enabled") {
                                    $user_service_permission = true;
                                }
                            }
                            if ($user_service_permission === true) {
                                $select_product_apis = mysqli_query($conn_db, "SELECT * FROM product_apis WHERE product_type = '$product_type' AND `provider` = '$network' AND `status` = 'enabled'");
                                if (mysqli_num_rows($select_product_apis) == 1) {
                                    $get_product_api_detail = mysqli_fetch_array($select_product_apis);
                                    $api_website = $get_product_api_detail["api_website"];
                                    $product_price = $product_price;
                                    $product_profit = $product_profit;
                                    $select_installed_product_apis = mysqli_query($conn_db, "SELECT * FROM installed_apis WHERE product_type = '$product_type' AND api_website = '$api_website'");
                                    if (mysqli_num_rows($select_installed_product_apis) == 1) {
                                        $get_installed_api_detail = mysqli_fetch_array($select_installed_product_apis);

                                        $api_website_url = $get_installed_api_detail["api_website"];
                                        $api_website_token = $get_installed_api_detail["token"];
                                        $api_website_file_path = $_SERVER["DOCUMENT_ROOT"] . "/func/api-gateway/" . strtolower($product_type . "-" . str_replace(".", "-", trim($api_website_url))) . ".php";
                                        $api_file_exists = file_exists($api_website_file_path);
                                        if ($api_file_exists) {
                                            $tx_ref = uniqid($product_type . "_", true);
                                            $product_type_1 = $product_type;
                                            $product_qty = "1";
                                            $product_id = $meter_number;
                                            $amount_1 = ($amount - (($product_profit / 100) * $amount));
                                            $profit = ((($product_price / 100) * $amount) - (($product_profit / 100) * $amount));
                                            $description = "Transaction Pending | " . str_replace(["_", "-"], " ", $meter_type) . " to Meter No: " . $meter_number . " was pending";
                                            $api_url = $_SERVER["HTTP_HOST"];
                                            $api_ref = "";
                                            $status = "pending";
                                            $debit_user = central_billing($username, "debit", $tx_ref, $product_type_1, $product_qty, $product_id, $amount_1, $profit, $description, $api_url, $api_ref, $status);
                                            if ($debit_user === true) {
                                                include_once($api_website_file_path);

                                                if ($api_response_status == 1) {
                                                    $status_update = "success";
                                                    $status_msg = $api_response_description;
                                                    $electric_token = $api_response_token;
                                                    $electric_token_unit = $api_response_token_unit;
                                                    update_transaction($username, $tx_ref, "status", "success");
                                                    update_transaction($username, $tx_ref, "api_ref", $api_response_reference);
                                                } elseif ($api_response_status == 2) {
                                                    $status_update = "pending";
                                                    $status_msg = $api_response_description;
                                                    $electric_token = $api_response_token;
                                                    $electric_token_unit = $api_response_token_unit;
                                                    update_transaction($username, $tx_ref, "status", "pending");
                                                    update_transaction($username, $tx_ref, "api_ref", $api_response_reference);
                                                } elseif ($api_response_status == 3) {
                                                    $status_update = "failed";
                                                    $status_msg = $api_response_description;
                                                    update_transaction($username, $tx_ref, "status", "failed");
                                                    $tx_ref_2 = uniqid("refund_", true);
                                                    $product_type_2 = $product_type;
                                                    $product_qty_2 = "1";
                                                    $product_id_2 = $meter_number . " " . $tx_ref;
                                                    $amount_2 = get_transaction($username, $tx_ref, "amount");
                                                    $profit_2 = "";
                                                    $description_2 = "Electric Refund";
                                                    $api_url_2 = $_SERVER["HTTP_HOST"];
                                                    $api_ref_2 = $tx_ref;
                                                    $status_2 = "success";
                                                    central_billing(
                                                        $username,
                                                        "credit",
                                                        $tx_ref_2,
                                                        $product_type_2,
                                                        $product_qty_2,
                                                        $product_id_2,
                                                        $amount_2,
                                                        $profit_2,
                                                        $description_2,
                                                        $api_url_2,
                                                        $api_ref_2,
                                                        $status_2
                                                    );
                                                }

                                                update_transaction($username, $tx_ref, "description", $api_response_description);

                                            } else {
                                                $status_msg = $debit_user;
                                            }
                                        } else {
                                            $status_msg = "Server unavailable";
                                        }
                                    } else {
                                        $status_msg = "No Server Installed";
                                    }
                                } else {
                                    $status_msg = "Service Locked";
                                }
                            } else {
                                $status_msg = "Service is currently not activated for this account, contact admin";
                            }
                        } else {
                            $status_msg = "Insufficient Balance";
                        }
                    } else {
                        $status_msg = "Invalid Balance";
                    }
                } else {
                    $status_msg = "Invalid Product Provider";
                }
            } else {
                $status_msg = "Invalid Password";
            }
        } else {
            $status_msg = "Invalid Username or email";
        }
    } else {
        if (empty($username)) {
            $status_msg = "Empty Username";
        } elseif (empty($password)) {
            $status_msg = "Empty Password";
        } elseif (empty($network)) {
            $status_msg = "Fullname is required";
        } elseif (empty($electric_type)) {
            $status_msg = "Electric Type is required";
        } elseif (empty($meter_type)) {
            $status_msg = "Electric Size is required";
        } elseif (empty($meter_number)) {
            $status_msg = "Phone number is required";
        } elseif (!is_numeric($meter_number)) {
            $status_msg = "Phone number must be a number";
        } elseif ((strlen($meter_number) < 11) || (strlen($meter_number) > 11)) {
            $status_msg = "Phone number must be 11 digits long";
        } elseif (empty($amount)) {
            $status_msg = "Amount is required";
        } elseif (!is_numeric($amount)) {
            $status_msg = "Amount must be a number";
        } elseif ($amount < 100) {
            $status_msg = "Amount must be 100 NGN and above";
        }
    }
    $app_json = json_encode(array("json-status" => "success", "status" => $status_update, "status-msg" => $status_msg, "token" => $electric_token, "units" => $electric_token_unit), true);
} else {
    $app_json = json_encode(array("json-status" => "success", "status" => "failed", "status-msg" => "Bad Request"), true);
}


echo $app_json;
?>
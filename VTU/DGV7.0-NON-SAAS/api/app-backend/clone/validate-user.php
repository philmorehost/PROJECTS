<?php error_reporting(0);
include_once($_SERVER["DOCUMENT_ROOT"] . "/config.php");
header("Content-Type: application/json");
$incoming_post_request = file_get_contents("php://input");
// fwrite(fopen("validate-user.txt", "a"), $incoming_post_request . "\n\n");
$decode_post_request = json_decode($incoming_post_request, true);
if (json_last_error() === JSON_ERROR_NONE) {
    $username = mysqli_real_escape_string($conn_db, trim(strip_tags($decode_post_request["username"])));
    $password = mysqli_real_escape_string($conn_db, trim(strip_tags($decode_post_request["encoded-passkey"])));
    $type = mysqli_real_escape_string($conn_db, trim(strip_tags($decode_post_request["type"])));
    $id_number = mysqli_real_escape_string($conn_db, trim(strip_tags($decode_post_request["id-number"])));

    $status_update = "failed";
    $status_msg = "Unknown Error";

    $validation_type_array = array("bvn", "nin");

    if (
        !empty($username) &&
        !empty($password) &&
        !empty($type) &&
        in_array(strtolower($type), $validation_type_array) &&
        !empty($id_number) &&
        is_numeric($id_number) &&
        (strlen($id_number) == 11)
    ) {
        $checkuser = mysqli_query($conn_db, "SELECT * FROM users WHERE username = '" . $username . "' OR email='" . $username . "'");
        if (mysqli_num_rows($checkuser) == 1) {
            $checkuser_pass = mysqli_query($conn_db, "SELECT * FROM users WHERE (username = '" . $username . "' OR email='" . $username . "') AND password = '" . $password . "'");
            if (mysqli_num_rows($checkuser_pass) == 1) {
                $get_user_detail = mysqli_fetch_array($checkuser_pass);
                $update_validation = update_user_info($get_user_detail["username"], strtolower(trim($type)), $id_number);
                
                if ($update_validation === true) {

                    $account_generated_array = array();
                    $account_generation_response = "failed";
                    array_push($account_generation_response, $account_generated_array);

                    //Virtual account Generation Beginning
                    $user_bvn = get_user_info($get_user_detail["username"], "bvn");
                    $user_nin = get_user_info($get_user_detail["username"], "nin");
                    $virtual_bank_code_arr = array("232" => "1%", "035" => "1%", "50515" => "1%", "120001" => "₦35", "110072" => "₦50");
                    $beewave_virtual_bank_code_arr = array("110072");
                    $payvessel_virtual_bank_code_arr = array("120001");
                    $monnify_virtual_bank_code_arr = array("232", "035", "50515");

                    $select_beewave_gateway = mysqli_query($conn_db, "SELECT * FROM installed_gateway_apis WHERE api_website='beewave.ng' AND `status`='enabled' LIMIT 1");
                    if (mysqli_num_rows($select_beewave_gateway) == 1) {
                        $get_beewave_gateway_details = mysqli_fetch_array($select_beewave_gateway);
                        $user_beewave_account_reference = str_replace([".", "-", ":"], "", $_SERVER["HTTP_HOST"]) . "-" . $get_user_detail["username"] . "-" . $get_user_detail["email"];
                        $beewave_create_reserve_account_array = array("email" => $user_beewave_account_reference, "name" => trim($get_user_detail["firstname"] . " " . $get_user_detail["lastname"]), "phone" => $get_user_detail["phone"], "access_key" => $get_beewave_gateway_details["secret_key"], "bank_code" => ["110072"]);
                        if (strlen($user_bvn) === 11) {
                            $beewave_create_reserve_account_array["bvn"] = $user_bvn;
                        }
                        if (strlen($user_nin) === 11) {
                            $beewave_create_reserve_account_array["nin"] = $user_nin;
                        }
                        $get_beewave_reserve_account = json_decode(makeBeewaveRequest("post", "s", "api/v1/bank-transfer/virtual-account-numbers", $beewave_create_reserve_account_array), true);
                        // fwrite(fopen("validate-user.txt", "a"), "Beewave: " . json_encode($get_beewave_reserve_account) . "\n\n");

                        if ($get_beewave_reserve_account["status"] == "success") {
                            $beewave_reserve_account_response = json_decode($get_beewave_reserve_account["json_result"], true);

                            foreach ($beewave_reserve_account_response["virtual_accounts"] as $beewave_accounts_json) {
                                addUserVirtualBank($get_user_detail["username"], $beewave_accounts_json["tracking_ref"], $beewave_accounts_json["bank_code"], $beewave_accounts_json["bank_name"], $beewave_accounts_json["account_number"], $beewave_accounts_json["account_name"], $virtual_bank_code_arr[$beewave_accounts_json["bank_code"]], "payvessel.com", "active");
                                $account_generation_response = "success";
                                array_push($account_generation_response, $account_generated_array);
                            }
                        }
                    }

                    //Payvessel
                    $select_payvessel_gateway = mysqli_query($conn_db, "SELECT * FROM installed_gateway_apis WHERE api_website='payvessel.com' AND `status`='enabled' LIMIT 1");
                    if (mysqli_num_rows($select_payvessel_gateway) == 1) {
                        $get_payvessel_gateway_details = mysqli_fetch_array($select_payvessel_gateway);

                        $get_payvessel_access_token = json_decode(getUserPayvesselAccessToken(), true);
                        if ($get_payvessel_access_token["status"] == "success") {
                            $user_payvessel_account_reference = str_replace([".", "-", ":"], "", $_SERVER["HTTP_HOST"]) . "-" . $get_user_detail["username"] . "-" . $get_user_detail["email"];
                            $payvessel_create_reserve_account_array = array("email" => $user_payvessel_account_reference, "name" => trim($get_user_detail["firstname"] . " " . $get_user_detail["lastname"]), "phoneNumber" => $get_user_detail["phone"], "businessid" => $get_payvessel_gateway_details["encrypt_key"], "bankcode" => ["101", "120001"], "account_type" => "STATIC");
                            if (strlen($user_bvn) === 11) {
                                $payvessel_create_reserve_account_array["bvn"] = $user_bvn;
                            }
                            if (strlen($user_nin) === 11) {
                                $payvessel_create_reserve_account_array["nin"] = $user_nin;
                            }
                            $get_payvessel_reserve_account = json_decode(makePayvesselRequest("post", $get_payvessel_access_token["token"], "api/external/request/customerReservedAccount/", $payvessel_create_reserve_account_array), true);
                            // fwrite(fopen("validate-user.txt", "a"), "Payvessel: " . json_encode($get_payvessel_reserve_account) . "\n\n");

                            if ($get_payvessel_reserve_account["status"] == "success") {
                                $payvessel_reserve_account_response = json_decode($get_payvessel_reserve_account["json_result"], true);

                                foreach ($payvessel_reserve_account_response["banks"] as $payvessel_accounts_json) {
                                    addUserVirtualBank($get_user_detail["username"], $payvessel_accounts_json["trackingReference"], $payvessel_accounts_json["bankCode"], $payvessel_accounts_json["bankName"], $payvessel_accounts_json["accountNumber"], $payvessel_accounts_json["accountName"], $virtual_bank_code_arr[$payvessel_accounts_json["bankCode"]], "payvessel.com", "active");
                                    $account_generation_response = "success";
                                    array_push($account_generation_response, $account_generated_array);
                                }
                            }
                        }
                    }

                    //Monnify
                    $select_monnify_gateway = mysqli_query($conn_db, "SELECT * FROM installed_gateway_apis WHERE api_website='monnify.com' AND `status`='enabled' LIMIT 1");
                    if (mysqli_num_rows($select_monnify_gateway) == 1) {
                        $get_monnify_gateway_details = mysqli_fetch_array($select_monnify_gateway);

                        $get_monnify_access_token = json_decode(getUserMonnifyAccessToken(), true);
                        if ($get_monnify_access_token["status"] == "success") {
                            $monnify_create_reserve_account_array = array("accountReference" => $user_monnify_account_reference, "accountName" => $get_user_detail["firstname"] . " " . $get_user_detail["lastname"], "currencyCode" => "NGN", "contractCode" => $get_monnify_gateway_details["encrypt_key"], "customerEmail" => $get_user_detail["email"], "getAllAvailableBanks" => false, "preferredBanks" => ["232", "035", "50515", "058"]);
                            if (strlen($user_bvn) === 11) {
                                $monnify_create_reserve_account_array["bvn"] = $user_bvn;
                            }
                            if (strlen($user_nin) === 11) {
                                $monnify_create_reserve_account_array["nin"] = $user_nin;
                            }
                            $get_monnify_reserve_account = json_decode(makeMonnifyRequest("post", $get_monnify_access_token["token"], "api/v2/bank-transfer/reserved-accounts", $monnify_create_reserve_account_array));
                            // fwrite(fopen("validate-user.txt", "a"), "PayMonnifyvessel: " . json_encode($get_monnify_reserve_account) . "\n\n");

                            if ($get_monnify_reserve_account["status"] == "success") {
                                $monnify_reserve_account_response = json_decode($get_monnify_reserve_account["json_result"], true);
                                foreach ($monnify_reserve_account_response["responseBody"]["accounts"] as $monnify_accounts_json) {
                                    if (in_array($monnify_accounts_json["bankCode"], array("232", "035", "50515", "058"))) {
                                        addUserVirtualBank($get_user_detail["username"], $user_monnify_account_reference, $monnify_accounts_json["bankCode"], $monnify_accounts_json["bankName"], $monnify_accounts_json["accountNumber"], $monnify_reserve_account_response["responseBody"]["accountName"], $virtual_bank_code_arr[$monnify_accounts_json["bankCode"]], "payvessel.com", "active");
                                        $account_generation_response = "success";
                                        array_push($account_generation_response, $account_generated_array);
                                    }
                                }
                            }
                        }
                    }

                    //Virtual account Generation End

                    if (in_array("success", $account_generated_array)) {
                        $status_update = "success";
                        $status_msg = strtoupper(trim($type)) . " Validation Successful, Virtual Account Generated";
                    } else {
                        $status_update = "failed";
                        $status_msg = strtoupper(trim($type)) . " Validation Failed, Server down";
                    }
                } else {
                    $status_msg = strtoupper(trim($type)) . " Validation Failed, Unable to update user details";
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
        } elseif (empty($type)) {
            $status_msg = "Validation Type Empty";
        } elseif (!in_array(strtolower($type), $validation_type_array)) {
            $status_msg = "Invalid Validation Type";
        } elseif (empty($id_number)) {
            $status_msg = "ID number Empty";
        } elseif (!is_numeric($id_number)) {
            $status_msg = "ID number must be a number";
        } elseif (strlen($id_number) < 11 || strlen($id_number) > 11) {
            $status_msg = "ID number must be 11 digit";
        }
    }
    $app_json = json_encode(array("json-status" => "success", "status" => $status_update, "status-msg" => $status_msg), true);
} else {
    $app_json = json_encode(array("json-status" => "success", "status" => "failed", "status-msg" => "Bad Request"), true);
}

// fwrite(fopen("validate-user.txt", "a"), $app_json . "\n\n");

echo $app_json;
?>
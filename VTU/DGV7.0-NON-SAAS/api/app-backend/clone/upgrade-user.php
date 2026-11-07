<?php error_reporting(0);
include_once($_SERVER["DOCUMENT_ROOT"] . "/config.php");
header("Content-Type: application/json");
$incoming_post_request = file_get_contents("php://input");
// fwrite(fopen("upgrade-user.txt", "a"), $incoming_post_request . "\n\n");
$decode_post_request = json_decode($incoming_post_request, true);
if (json_last_error() === JSON_ERROR_NONE) {
    $username = mysqli_real_escape_string($conn_db, trim(strip_tags($decode_post_request["username"])));
    $password = mysqli_real_escape_string($conn_db, trim(strip_tags($decode_post_request["encoded-passkey"])));
    $level = mysqli_real_escape_string($conn_db, trim(strip_tags(strtolower($decode_post_request["level"]))));

    $status_update = "failed";
    $status_msg = "Unknown Error";

    $upgrade_level_array = array("smart", "ambassador", "api");

    if (
        !empty($username) &&
        !empty($password) &&
        !empty($level) &&
        in_array(strtolower($level), $upgrade_level_array)
    ) {
        $checkuser = mysqli_query($conn_db, "SELECT * FROM users WHERE username = '" . $username . "' OR email='" . $username . "'");
        if (mysqli_num_rows($checkuser) == 1) {
            $checkuser_pass = mysqli_query($conn_db, "SELECT * FROM users WHERE (username = '" . $username . "' OR email='" . $username . "') AND password = '" . $password . "'");
            if (mysqli_num_rows($checkuser_pass) == 1) {
                $get_user_detail = mysqli_fetch_array($checkuser_pass);

                if ($get_user_detail["account_type"] != $level) {
                    if (array_search($level, $upgrade_level_array) > array_search($get_user_detail["account_type"], $upgrade_level_array)) {

                        $upgrade_price = 0;
                        $upgrade_referral_commission = 0;

                        $select_user_referral_setting = mysqli_query($conn_db, "SELECT * FROM user_referral_setting LIMIT 1");
                        if (mysqli_num_rows($select_user_referral_setting) >= 1) {
                            $get_user_referral_setting = mysqli_fetch_assoc($select_user_referral_setting);

                            if ($get_user_referral_setting["commission_mode"] == "flat") {
                                $upgrade_price = $get_user_referral_setting[strtolower($level)."_price"];
                                $upgrade_referral_commission = $get_user_referral_setting[strtolower($level)."_commission"];
                                
                                $upgrade_price = !empty($upgrade_price) && is_numeric($upgrade_price) ? $upgrade_price : 0;
                                $upgrade_referral_commission = !empty($upgrade_referral_commission) && is_numeric($upgrade_referral_commission) ? $upgrade_referral_commission : 0;
                            }elseif($get_user_referral_setting["commission_mode"] == "percent"){
                                $upgrade_price = $get_user_referral_setting[strtolower($level)."_price"];
                                $upgrade_referral_commission = ($upgrade_price * ($get_user_referral_setting[strtolower($level)."_commission"] / 100));
                                
                                $upgrade_price = !empty($upgrade_price) && is_numeric($upgrade_price) ? $upgrade_price : 0;
                                $upgrade_referral_commission = !empty($upgrade_referral_commission) && is_numeric($upgrade_referral_commission) ? $upgrade_referral_commission : 0;
                            }
                        }

                        $tx_ref = uniqid("upgrade_", true);
                        $product_type_1 = "Account Upgrade";
                        $product_qty = "1";
                        $product_id = $level;
                        $amount_1 = $upgrade_price;
                        $profit = "";
                        $description = "Account Upgrade from " . strtoupper(str_replace(["_", "-"], " ", $get_user_detail["account_type"])) . " to " . strtoupper(str_replace(["_", "-"], " ", $level));
                        $api_url = $_SERVER["HTTP_HOST"];
                        $api_ref = "";
                        $status = "success";
                        $debit_user = central_billing($username, "debit", $tx_ref, $product_type_1, $product_qty, $product_id, $amount_1, $profit, $description, $api_url, $api_ref, $status);
                        if ($debit_user === true) {
                            $update_upgrade = update_user_info($get_user_detail["username"], "account_type", $level);
                            if ($update_upgrade === true) {
                                $referral_username = $get_user_detail["referral"];
                                if (!empty($referral_username)) {
                                    $ref_tx_ref = uniqid("commission_", true);
                                    $ref_product_type_1 = "Referral Upgrade Commission";
                                    $ref_product_qty = "1";
                                    $ref_product_id = $username;
                                    $ref_amount_1 = $upgrade_referral_commission;
                                    $ref_profit = "";
                                    $ref_description = "Referral Commision from " . ucwords($username) . " Account Upgrade from " . strtoupper(str_replace(["_", "-"], " ", $get_user_detail["account_type"])) . " to " . strtoupper(str_replace(["_", "-"], " ", $level));
                                    $ref_api_url = $_SERVER["HTTP_HOST"];
                                    $ref_api_ref = "";
                                    $ref_status = "success";
                                    central_billing($referral_username, "credit", $ref_tx_ref, $ref_product_type_1, $ref_product_qty, $ref_product_id, $ref_amount_1, $ref_profit, $ref_description, $ref_api_url, $ref_api_ref, $ref_status);
                                }

                                $status_update = "success";
                                $status_msg = strtoupper(trim($level)) . " upgraded Successful";
                            } else {
                                $status_msg = strtoupper(trim($level)) . " upgrade Failed";
                            }
                        } else {
                            $status_msg = $debit_user;
                        }
                    } else {
                        $status_msg = "Err: cant downgrade account";
                    }
                } else {
                    $status_msg = "Err: cant upgrade account to same level";
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
        } elseif (empty($level)) {
            $status_msg = "Validation Type Empty";
        } elseif (!in_array(strtolower($level), $upgrade_level_array)) {
            $status_msg = "Invalid Validation Type";
        }
    }
    $app_json = json_encode(array("json-status" => "success", "status" => $status_update, "status-msg" => $status_msg), true);
} else {
    $app_json = json_encode(array("json-status" => "success", "status" => "failed", "status-msg" => "Bad Request"), true);
}

// fwrite(fopen("upgrade-user.txt", "a"), $app_json . "\n\n");

echo $app_json;
?>
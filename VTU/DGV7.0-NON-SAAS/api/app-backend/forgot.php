<?php error_reporting(0);
include_once("app-config.php");

header("Content-Type: application/json");
$incoming_post_request = file_get_contents("php://input");
$decode_post_request = json_decode($incoming_post_request, true);
if (json_last_error() === JSON_ERROR_NONE) {

    //Select Vendor Table
    $vendor_id = resolveVendorID();
    $select_vendor_table = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id' AND status=1 LIMIT 1"));
    if ($select_vendor_table) {

        $username = mysqli_real_escape_string($connection_server, trim(strip_tags((string)($decode_post_request["username"] ?? ''))));

        $status_update = "failed";
        $status_msg = "Unknown Error";

        $reset_ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $reset_gated = false;
        if (!isServiceEnabled('password_reset', $vendor_id)) {
            $status_msg = "Password reset is currently disabled. Please contact support.";
            $reset_gated = true;
        } elseif (function_exists('bc_is_password_reset_blocked') && ($msg = bc_is_password_reset_blocked($username, $reset_ip, $vendor_id))) {
            $status_msg = "Password reset is temporarily locked for this account: " . $msg . ". Please unlock with your Security PIN to continue.";
            $reset_gated = true;
        }

        if (!$reset_gated && function_exists('bc_handle_password_reset_attempt')) {
            bc_handle_password_reset_attempt($username, $reset_ip, 0, $vendor_id, 'request');
        }

        if (!empty($username) && !$reset_gated) {
            if (!function_exists('bc_issue_otp') || !function_exists('bc_verify_otp')) {
                $status_msg = "Please update your app or reset your password on the website.";
            } else {
                $checkuser = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND (username = '" . $username . "' OR email='" . $username . "')");
                if (mysqli_num_rows($checkuser) == 1) {
                    $get_user_detail = mysqli_fetch_array($checkuser);
                    $otp_input = preg_replace('/\D/', '', (string)($decode_post_request["otp"] ?? ''));

                    if (strlen($otp_input) !== 6) {
                        if (bc_is_rate_limited('app_forgot_otp', $vendor_id . ':' . strtolower($get_user_detail['username']), 5, 900)) {
                            $status_msg = "Too many reset requests. Please try again later.";
                        } else {
                            $otp = bc_issue_otp('app_password_reset', (string)$get_user_detail['username'], (int)$select_vendor_table["id"], array('ip' => $reset_ip));
                            $body = "Your password reset verification code is <b>" . $otp . "</b>. It expires in 10 minutes. If you did not request this, ignore this email.";
                            beeMailer($get_user_detail["email"], "Password Reset Verification Code", $body);
                            $status_update = "otp-required";
                            $status_msg = "A 6-digit verification code has been sent to your email. Resend the request with otp=<code>.";
                        }
                    } elseif (!bc_verify_otp('app_password_reset', (string)$get_user_detail['username'], (int)$select_vendor_table["id"], $otp_input)) {
                        $status_msg = "Invalid or expired verification code.";
                    } else {
                        $chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
                        $new_user_password = "";
                        for ($i = 0; $i < 12; $i++) {
                            $new_user_password .= $chars[random_int(0, strlen($chars) - 1)];
                        }
                        $new_user_password_hashed = md5($new_user_password);

                        $update_user_password = mysqli_query($connection_server, "UPDATE sas_users SET `password`='" . $new_user_password_hashed . "' WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND (username = '" . $username . "' OR email='" . $username . "')");
                        if ($update_user_password === true) {
                            $login_template_encoded_text_array = array("{firstname}" => $get_user_detail["firstname"], "{lastname}" => $get_user_detail["lastname"], "{password}" => $new_user_password);
                            $raw_login_template_subject = getUserEmailTemplate('user-auto-pass-generate', 'subject');
                            $raw_login_template_body = getUserEmailTemplate('user-auto-pass-generate', 'body');
                            foreach ($login_template_encoded_text_array as $array_key => $array_val) {
                                $raw_login_template_subject = str_replace($array_key, $array_val, $raw_login_template_subject);
                                $raw_login_template_body = str_replace($array_key, $array_val, $raw_login_template_body);
                            }

                            beeMailer($get_user_detail["email"], $raw_login_template_subject, $raw_login_template_body);

                            if (function_exists('bc_handle_password_reset_attempt')) {
                                bc_handle_password_reset_attempt($username, $reset_ip, 1, $vendor_id, 'verify');
                            }

                            $status_update = "success";
                            $status_msg = "New Password sent to " . $get_user_detail["email"];
                        } else {
                            $status_msg = "Err: Unable to reset password";
                        }
                    }
                } else {
                    $status_msg = "If that account exists, a verification code has been sent to its email.";
                }
            }
        } else {
            if (empty($username) && !$reset_gated) {
                $status_msg = "Empty Username";
            }
        }

        $app_json = json_encode(array("json-status" => "success", "status" => $status_update, "status-msg" => $status_msg), true);
    } else {
        $app_json = json_encode(array("json-status" => "failed", "status" => "failed", "status-msg" => "Website not registered"), true);
    }
} else {
    $app_json = json_encode(array("json-status" => "success", "status" => "failed", "status-msg" => "Bad Request"), true);
}


echo $app_json;
?>

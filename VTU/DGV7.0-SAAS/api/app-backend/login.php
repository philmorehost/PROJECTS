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
        $password = mysqli_real_escape_string($connection_server, trim(strip_tags((string)($decode_post_request["encoded-passkey"] ?? ''))));

        $status_update = "failed";
        $status_msg = "Unknown Error";

        $app_ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if (bc_is_rate_limited('app_login', (string)$app_ip, 10, 300)) {
            echo json_encode(array("json-status" => "success", "status" => "failed", "status-msg" => "Too many attempts. Try again later."));
            exit;
        }

        // The app sends md5(password) as the passkey. Anything that is not a 32-hex digest is
        // not a credential this endpoint can check, and accepting arbitrary text here is what
        // turned a leaked hash into a login (pass-the-hash).
        if (!preg_match('/^[a-f0-9]{32}$/i', $password)) {
            echo json_encode(array("json-status" => "success", "status" => "failed", "status-msg" => "Invalid username or password"));
            exit;
        }

        if (
            !empty($username) &&
            !empty($password)
        ) {
            $checkuser = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND (username = '" . $username . "' OR email='" . $username . "')");
            if (mysqli_num_rows($checkuser) == 1) {
                $checkuser_pass = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE  vendor_id = '" . $select_vendor_table["id"] . "' AND (username = '" . $username . "' OR email='" . $username . "') AND password = '" . $password . "'");
                if (mysqli_num_rows($checkuser_pass) == 1) {
                    $get_user_detail = mysqli_fetch_array($checkuser_pass);
                    bc_enrollment_api_guard($get_user_detail);
                    if ($get_user_detail["status"] == "1" && empty($get_user_detail["is_blocked"])) {
                        if (function_exists('bc_fintech_app_access')) {
                            $app_dec = bc_fintech_app_access($get_user_detail);
                            if (empty($app_dec['allowed'])) {
                                echo json_encode(array(
                                    "json-status" => "success",
                                    "status" => "failed",
                                    "status-msg" => $app_dec['message'] ?? "This account cannot use the mobile app."
                                ));
                                exit;
                            }
                        }

                        // This legacy endpoint has no way to carry a one-time code, so when the
                        // vendor requires user login OTP it must not mint a session at all -
                        // otherwise it would be a hole straight past the new protection.
                        if (bc_user_login_otp_required($select_vendor_table)) {
                            echo json_encode(array(
                                "json-status" => "success",
                                "status" => "failed",
                                "status-msg" => "Please update your app to the latest version to sign in. Email verification is now required."
                            ));
                            exit;
                        }
                        
                        // Email Beginning
                        $login_template_encoded_text_array = array("{firstname}" => $get_user_detail["firstname"], "{lastname}" => $get_user_detail["lastname"], "{username}" => $get_user_detail["username"], "{ip_address}" => $_SERVER["REMOTE_ADDR"]);
                        $raw_login_template_subject = getUserEmailTemplate('user-log', 'subject');
                        $raw_login_template_body = getUserEmailTemplate('user-log', 'body');
                        foreach ($login_template_encoded_text_array as $array_key => $array_val) {
                            $raw_login_template_subject = str_replace($array_key, $array_val, $raw_login_template_subject);
                            $raw_login_template_body = str_replace($array_key, $array_val, $raw_login_template_body);
                        }

                        beeMailer($get_user_detail["email"], $raw_login_template_subject, $raw_login_template_body);
                        // Email End

                        $status_update = "success";
                        $status_msg = "Login Successful";
                    } else {
                        $status_msg = "Account Locked, Contact the admin";
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
            }
        }
        // fwrite(fopen("login.txt", "a"), $status_msg . "\n\n");

        $app_json = json_encode(array("json-status" => "success", "status" => $status_update, "status-msg" => $status_msg), true);
        // fwrite(fopen("login.txt", "a"), $app_json . "\n\n");
    } else {
        //Website not registered
        $app_json = json_encode(array("json-status" => "failed", "status" => "failed", "status-msg" => "Website not registered"), true);
    }
} else {
    $app_json = json_encode(array("json-status" => "success", "status" => "failed", "status-msg" => "Bad Request"), true);
}


echo $app_json;
?>
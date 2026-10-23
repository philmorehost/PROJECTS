<?php error_reporting(0);
include_once($_SERVER["DOCUMENT_ROOT"] . "/config.php");
header("Content-Type: application/json");
$incoming_post_request = file_get_contents("php://input");
// fwrite(fopen("change-password.txt", "a"), $incoming_post_request . "\n\n");
$decode_post_request = json_decode($incoming_post_request, true);
if (json_last_error() === JSON_ERROR_NONE) {
    $username = mysqli_real_escape_string($conn_db, trim(strip_tags($decode_post_request["username"])));
    $password = mysqli_real_escape_string($conn_db, trim(strip_tags($decode_post_request["encoded-passkey"])));
    $new_password = mysqli_real_escape_string($conn_db, trim(strip_tags($decode_post_request["new-password"])));

    $status_update = "failed";
    $status_msg = "Unknown Error";

    if (
        !empty($username) &&
        !empty($password) &&
        !empty($new_password)
    ) {
        $checkuser = mysqli_query($conn_db, "SELECT * FROM users WHERE username = '" . $username . "' OR email='" . $username . "'");
        if (mysqli_num_rows($checkuser) == 1) {
            $checkuser_pass = mysqli_query($conn_db, "SELECT * FROM users WHERE (username = '" . $username . "' OR email='" . $username . "') AND password = '" . $password . "'");
            if (mysqli_num_rows($checkuser_pass) == 1) {
                $get_user_detail = mysqli_fetch_array($checkuser_pass);
                $update_user_password = update_user_info($get_user_detail["username"], "password", $new_password);
                if ($update_user_password === true) {
                    // Email Beginning
                    $change_password_template_encoded_text_array = array("{firstname}" => $get_user_detail["firstname"], "{lastname}" => $get_user_detail["lastname"]);
                    $raw_change_password_template_subject = getUserEmailTemplate('user-pass-update', 'subject');
                    $raw_change_password_template_body = getUserEmailTemplate('user-pass-update', 'body');
                    foreach ($change_password_template_encoded_text_array as $array_key => $array_val) {
                        $raw_change_password_template_subject = str_replace($array_key, $array_val, $raw_change_password_template_subject);
                        $raw_change_password_template_body = str_replace($array_key, $array_val, $raw_change_password_template_body);
                    }

                    beeMailer($get_user_detail["email"], $raw_change_password_template_subject, $raw_change_password_template_body);
                    // Email End

                    $status_update = "success";
                    $status_msg = "Password Updated Successful";
                } else {
                    $status_msg = "Err: Password not updated, contact admin";
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
        } elseif (!is_numeric($new_password)) {
            $status_msg = "New Password Empty";
        }
    }
    $app_json = json_encode(array("json-status" => "success", "status" => $status_update, "status-msg" => $status_msg), true);
} else {
    $app_json = json_encode(array("json-status" => "success", "status" => "failed", "status-msg" => "Bad Request"), true);
}


echo $app_json;
?>
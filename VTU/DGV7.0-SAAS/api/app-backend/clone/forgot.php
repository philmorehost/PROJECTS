<?php error_reporting(0);
include_once($_SERVER["DOCUMENT_ROOT"] . "/config.php");
header("Content-Type: application/json");
$incoming_post_request = file_get_contents("php://input");
// fwrite(fopen("login.txt", "a"), $incoming_post_request . "\n\n");
$decode_post_request = json_decode($incoming_post_request, true);
if (json_last_error() === JSON_ERROR_NONE) {
    $username = mysqli_real_escape_string($conn_db, trim(strip_tags($decode_post_request["username"])));
    // $password = mysqli_real_escape_string($conn_db, trim(strip_tags($decode_post_request["encoded-passkey"])));

    $status_update = "failed";
    $status_msg = "Unknown Error";

    if (
        !empty($username)
    ) {
        $checkuser = mysqli_query($conn_db, "SELECT * FROM users WHERE username = '" . $username . "' OR email='" . $username . "'");
        if (mysqli_num_rows($checkuser) == 1) {
            $get_user_detail = mysqli_fetch_array($checkuser);
            $new_user_password = substr(str_shuffle("1234567890abcdefghijklmnopqrstuvwxyz"), 0, 10);
            $new_user_password_hashed = md5($new_user_password);
            $update_user_password = update_user_info($get_user_detail["username"], "password", $new_user_password_hashed);
            if ($update_user_password === true) {
                // Email Beginning
                $forgot_password_template_encoded_text_array = array("{firstname}" => $get_user_detail["firstname"], "{lastname}" => $get_user_detail["lastname"], "{password}" => $new_user_password);
                $raw_forgot_password_template_subject = getUserEmailTemplate('user-auto-pass-generate', 'subject');
                $raw_forgot_password_template_body = getUserEmailTemplate('user-auto-pass-generate', 'body');
                foreach ($forgot_password_template_encoded_text_array as $array_key => $array_val) {
                    $raw_forgot_password_template_subject = str_replace($array_key, $array_val, $raw_forgot_password_template_subject);
                    $raw_forgot_password_template_body = str_replace($array_key, $array_val, $raw_forgot_password_template_body);
                }

                beeMailer($get_user_detail["email"], $raw_forgot_password_template_subject, $raw_forgot_password_template_body);
                // Email End

                $status_update = "success";
                $status_msg = "New Password sent to " . $get_user_detail["email"];
            } else {
                $status_msg = $update_user_password;
            }
        } else {
            $status_msg = "Invalid Username or email";
        }
    } else {
        if (empty($username)) {
            $status_msg = "Empty Username";
        }
    }
    $app_json = json_encode(array("json-status" => "success", "status" => $status_update, "status-msg" => $status_msg), true);
} else {
    $app_json = json_encode(array("json-status" => "success", "status" => "failed", "status-msg" => "Bad Request"), true);
}


echo $app_json;
?>
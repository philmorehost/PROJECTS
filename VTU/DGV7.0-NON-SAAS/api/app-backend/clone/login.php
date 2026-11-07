<?php error_reporting(0);
include_once($_SERVER["DOCUMENT_ROOT"] . "/config.php");
header("Content-Type: application/json");
$incoming_post_request = file_get_contents("php://input");
// fwrite(fopen("login.txt", "a"), $incoming_post_request . "\n\n");
$decode_post_request = json_decode($incoming_post_request, true);
if (json_last_error() === JSON_ERROR_NONE) {
    $username = mysqli_real_escape_string($conn_db, trim(strip_tags($decode_post_request["username"])));
    $password = mysqli_real_escape_string($conn_db, trim(strip_tags($decode_post_request["encoded-passkey"])));

    $status_update = "failed";
    $status_msg = "Unknown Error";

    if (
        !empty($username) &&
        !empty($password)
    ) {
        $checkuser = mysqli_query($conn_db, "SELECT * FROM users WHERE username = '" . $username . "' OR email='" . $username . "'");
        if (mysqli_num_rows($checkuser) == 1) {
            $checkuser_pass = mysqli_query($conn_db, "SELECT * FROM users WHERE (username = '" . $username . "' OR email='" . $username . "') AND password = '" . $password . "'");
            if (mysqli_num_rows($checkuser_pass) == 1) {
                $get_user_detail = mysqli_fetch_array($checkuser_pass);
                if ($get_user_detail["status"] == "enabled") {
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
    $app_json = json_encode(array("json-status" => "success", "status" => "failed", "status-msg" => "Bad Request"), true);
}


echo $app_json;
?>
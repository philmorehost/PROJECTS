<?php error_reporting(0);
include_once($_SERVER["DOCUMENT_ROOT"] . "/config.php");
header("Content-Type: application/json");
$incoming_post_request = file_get_contents("php://input");
// fwrite(fopen("update-user.txt", "a"), $incoming_post_request . "\n\n");
$decode_post_request = json_decode($incoming_post_request, true);
if (json_last_error() === JSON_ERROR_NONE) {
    $username = mysqli_real_escape_string($conn_db, trim(strip_tags($decode_post_request["username"])));
    $password = mysqli_real_escape_string($conn_db, trim(strip_tags($decode_post_request["encoded-passkey"])));
    $fullname = mysqli_real_escape_string($conn_db, trim(strip_tags($decode_post_request["fullname"])));
    $phone = mysqli_real_escape_string($conn_db, trim(strip_tags($decode_post_request["phone"])));
    $address = mysqli_real_escape_string($conn_db, trim(strip_tags($decode_post_request["address"])));


    $status_update = "failed";
    $status_msg = "Unknown Error";

    if (
        !empty($username) &&
        !empty($password) &&
        !empty($fullname) &&
        !empty($phone) &&
        is_numeric($phone) &&
        (strlen($phone) == 11) &&
        !empty($address)
    ) {
        $checkuser = mysqli_query($conn_db, "SELECT * FROM users WHERE username = '" . $username . "' OR email='" . $username . "'");
        if (mysqli_num_rows($checkuser) == 1) {
            $checkuser_pass = mysqli_query($conn_db, "SELECT * FROM users WHERE (username = '" . $username . "' OR email='" . $username . "') AND password = '" . $password . "'");
            if (mysqli_num_rows($checkuser_pass) == 1) {
                $get_user_detail = mysqli_fetch_array($checkuser_pass);
                $fullname_exp = array_filter(explode(" ", trim($fullname)));
                $lastname_concat = ltrim($fullname, $fullname_exp[0]);

                $check_existing_user = mysqli_query($conn_db, "SELECT * FROM users WHERE phone = '$phone' AND username != '" . $username . "'");
                if (mysqli_num_rows($check_existing_user) == 0) {
                    $update_user = mysqli_query($conn_db, "UPDATE users SET firstname = '" . $fullname_exp[0] . "', lastname = '" . $lastname_concat . "', phone = '$phone', `address` = '$address' WHERE username = '" . $username . "'");
                    if ($update_user == true) {
                        // Email Beginning
                        $account_update_template_encoded_text_array = array("{firstname}" => $fullname_exp[0], "{lastname}" => $lastname_concat, "{email}" => $get_user_detail["email"], "{phone}" => $phone, "{address}" => $address);
                        $raw_account_update_template_subject = getUserEmailTemplate('user-account-update', 'subject');
                        $raw_account_update_template_body = getUserEmailTemplate('user-account-update', 'body');
                        foreach ($account_update_template_encoded_text_array as $array_key => $array_val) {
                            $raw_account_update_template_subject = str_replace($array_key, $array_val, $raw_account_update_template_subject);
                            $raw_account_update_template_body = str_replace($array_key, $array_val, $raw_account_update_template_body);
                        }

                        beeMailer($get_user_detail["email"], $raw_account_update_template_subject, $raw_account_update_template_body);
                        // Email End
                        $status_update = "success";
                        $status_msg = "Information updated successfully";
                    } else {
                        $status_msg = "Error: Server Down, Details failed to update";
                    }
                } else {
                    $status_msg = "Error: Phone is taken by another user";
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
        } elseif (empty($fullname)) {
            $status_msg = "Fullname is required";
        } elseif (empty($phone)) {
            $status_msg = "Phone number is required";
        } elseif (!is_numeric($phone)) {
            $status_msg = "Phone number must be a number";
        } elseif ((strlen($phone) < 11) || (strlen($phone) > 11)) {
            $status_msg = "Phone number must be 11 digits long";
        } elseif (empty($address)) {
            $status_msg = "Address is required";
        }
    }
    $app_json = json_encode(array("json-status" => "success", "status" => $status_update, "status-msg" => $status_msg), true);
} else {
    $app_json = json_encode(array("json-status" => "success", "status" => "failed", "status-msg" => "Bad Request"), true);
}


echo $app_json;
?>
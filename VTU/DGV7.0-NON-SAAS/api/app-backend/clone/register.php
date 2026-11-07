<?php error_reporting(0);
include_once($_SERVER["DOCUMENT_ROOT"] . "/config.php");
header("Content-Type: application/json");
$incoming_post_request = file_get_contents("php://input");
// fwrite(fopen("register.txt", "a"), $incoming_post_request . "\n\n");
$decode_post_request = json_decode($incoming_post_request, true);
if (json_last_error() === JSON_ERROR_NONE) {
    $fullname_exp = array_filter(explode(" ", trim($decode_post_request['fullname'])));
    $firstname = mysqli_real_escape_string($conn_db, trim(strip_tags($fullname_exp[0])));
    $lastname = mysqli_real_escape_string($conn_db, trim(strip_tags($fullname_exp[1])));
    $username = mysqli_real_escape_string($conn_db, trim(strip_tags($decode_post_request["username"])));
    $phone = mysqli_real_escape_string($conn_db, trim(strip_tags($decode_post_request["phone"])));
    $email = mysqli_real_escape_string($conn_db, trim(strip_tags($decode_post_request["email"])));
    $address = mysqli_real_escape_string($conn_db, trim(strip_tags($decode_post_request["address"])));
    $password = mysqli_real_escape_string($conn_db, trim(strip_tags($decode_post_request["encoded-passkey"])));
    $referral = mysqli_real_escape_string($conn_db, trim(strip_tags($decode_post_request["referral"])));

    $status_update = "failed";
    $status_msg = "Unknown Error";

    if (
        !empty($firstname) &&
        !empty($lastname) &&
        !empty($username) &&
        !empty($phone) &&
        is_numeric($phone) &&
        (strlen($phone) == 11) &&
        !empty($email) &&
        filter_var($email, FILTER_VALIDATE_EMAIL) &&
        !empty($address) &&
        !empty($password)
    ) {
        $account_type = 'smart';
        $token = uniqid() . uniqid();
        $status = 'enabled';
        $balance = "0";
        $checkuser = mysqli_query($conn_db, "SELECT * FROM users WHERE username = '" . $username . "' OR email='" . $email . "'");
        if (mysqli_num_rows($checkuser) == 0) {
            $proceed_register = false;
            $refined_referral = "";
            if (!empty($referral)) {
                $check_referral_user = mysqli_query($conn_db, "SELECT * FROM users WHERE username = '" . base64_decode($referral) . "'");
                if (mysqli_num_rows($check_referral_user) == 1) {
                    $proceed_register = true;
                    $refined_referral = base64_decode($referral);
                } elseif (mysqli_num_rows($check_referral_user) == 0) {
                    $status_msg = "Invalid Referral Code";
                } elseif (mysqli_num_rows($check_referral_user) > 1) {
                    $status_msg = "Multiple Referral Records, Contact Admin";
                }
            } else {
                $proceed_register = true;
            }

            if ($proceed_register == true) {
                mysqli_query($conn_db, "INSERT INTO users 
                (username, `password`, phone, email, balance, firstname, lastname, account_type, token, `status`, `address`, `referral`, bvn, nin)
                VALUES ('$username', '$password', '$phone', '$email', '$balance', '$firstname', '$lastname', '$account_type', '$token', '$status' , '$address', '$refined_referral', '', '') ");

                // Email Beginning
                $registration_template_encoded_text_array = array("{firstname}" => $firstname, "{lastname}" => $lastname, "{username}" => $username, "{email}" => $email, "{phone}" => $phone, "{address}" => $address);
                $raw_registration_template_subject = getUserEmailTemplate('user-reg', 'subject');
                $raw_registration_template_body = getUserEmailTemplate('user-reg', 'body');
                foreach ($registration_template_encoded_text_array as $array_key => $array_val) {
                    $raw_registration_template_subject = str_replace($array_key, $array_val, $raw_registration_template_subject);
                    $raw_registration_template_body = str_replace($array_key, $array_val, $raw_registration_template_body);
                }

                beeMailer($email, $raw_registration_template_subject, $raw_registration_template_body);
                // Email End

                $status_update = "success";
                $status_msg = "Registration Successful";
            }
        } else {
            $status_msg = "Username or email taken";
        }
    } else {
        if (empty($firstname)) {
            $status_msg = "Empty Firstname";
        } elseif (empty($lastname)) {
            $status_msg = "Empty Lastname";
        } elseif (empty($username)) {
            $status_msg = "Empty Username";
        } elseif (empty($phone)) {
            $status_msg = "Empty Phone number";
        } elseif (!is_numeric($phone)) {
            $status_msg = "Phone number must be a number";
        } elseif (strlen($phone) < 11 || strlen($phone) > 11) {
            $status_msg = "Phone number must be 11 digit";
        } elseif (empty($email)) {
            $status_msg = "Empty Email";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $status_msg = "Invalid Email";
        } elseif (empty($address)) {
            $status_msg = "Empty Address";
        } elseif (empty($password)) {
            $status_msg = "Empty Password";
        }
    }
    $app_json = json_encode(array("json-status" => "success", "status" => $status_update, "status-msg" => $status_msg), true);
} else {
    $app_json = json_encode(array("json-status" => "success", "status" => "failed", "status-msg" => "Bad Request"), true);
}


echo $app_json;
?>
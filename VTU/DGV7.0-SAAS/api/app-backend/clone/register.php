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
    $phone_raw = trim(strip_tags((string)($decode_post_request["phone"] ?? '')));
    $phone_norm = function_exists('bc_normalize_world_phone')
        ? bc_normalize_world_phone($phone_raw, trim(strip_tags((string)($decode_post_request["country"] ?? ''))))
        : (preg_match('/^\+?[0-9][0-9\s().-]{6,19}$/', $phone_raw)
            ? (strpos($phone_raw, '+') === 0 ? '+' . preg_replace('/\D+/', '', $phone_raw) : preg_replace('/\D+/', '', $phone_raw))
            : '');
    $phone = mysqli_real_escape_string($conn_db, $phone_norm);
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
        !empty($phone_raw) &&
        $phone !== '' &&
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
        } elseif (empty($phone_raw)) {
            $status_msg = "Empty Phone number";
        } elseif ($phone === '') {
            $status_msg = "Invalid phone number. Enter a valid local or international number";
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
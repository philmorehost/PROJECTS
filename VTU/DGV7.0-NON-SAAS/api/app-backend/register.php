<?php error_reporting(0);
include_once("app-config.php");

header("Content-Type: application/json");
$incoming_post_request = file_get_contents("php://input");
// fwrite(fopen("register.txt", "a"), $incoming_post_request . "\n\n");
$decode_post_request = json_decode($incoming_post_request, true);
if (json_last_error() === JSON_ERROR_NONE) {
    
    //Select Vendor Table
    $vendor_id = resolveVendorID();
    $select_vendor_table = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id' AND status=1 LIMIT 1"));
    if ($select_vendor_table) {
        $fullname_exp = array_filter(explode(" ", trim($decode_post_request['fullname'])));
        $firstname = mysqli_real_escape_string($connection_server, trim(strip_tags($fullname_exp[0])));
        $lastname = mysqli_real_escape_string($connection_server, trim(strip_tags($fullname_exp[1] ?? "")));
        $othername = mysqli_real_escape_string($connection_server, trim(strip_tags($fullname_exp[2] ?? "")));
        $username = mysqli_real_escape_string($connection_server, trim(strip_tags($decode_post_request["username"])));
        $country_raw = trim(strip_tags((string)($decode_post_request["country"] ?? '')));
        $country_info = ($country_raw !== '' && function_exists('bc_world_country_lookup')) ? bc_world_country_lookup($country_raw) : null;
        $country = mysqli_real_escape_string($connection_server, $country_info ? $country_info['name'] : '');
        $phone_raw = trim(strip_tags((string)($decode_post_request["phone"] ?? '')));
        $phone_norm = function_exists('bc_normalize_world_phone')
            ? bc_normalize_world_phone($phone_raw, $country_info ? $country_info['code'] : $country_raw)
            : (is_numeric($phone_raw) && strlen($phone_raw) == 11 ? $phone_raw : '');
        $phone = mysqli_real_escape_string($connection_server, $phone_norm);
        $email = mysqli_real_escape_string($connection_server, trim(strip_tags($decode_post_request["email"])));
        $address = mysqli_real_escape_string($connection_server, trim(strip_tags($decode_post_request["address"])));
        $password = mysqli_real_escape_string($connection_server, trim(strip_tags($decode_post_request["encoded-passkey"])));
        $referral_raw = trim((string)($decode_post_request["referral"] ?? ''));
        $referral = mysqli_real_escape_string($connection_server, trim(strip_tags($referral_raw)));

        $status_update = "failed";
        $status_msg = "Unknown Error";

        if (function_exists('bc_registration_enabled') && !bc_registration_enabled($select_vendor_table["id"])) {
            echo json_encode(array(
                "json-status" => "success",
                "status" => "failed",
                "status-msg" => "Registration is currently disabled."
            ));
            exit;
        }

        if (
            !empty($firstname) &&
            !empty($lastname) &&
            !empty($othername) &&
            !empty($username) &&
            !empty($phone_raw) &&
            $phone !== '' &&
            ($country_raw === '' || $country_info !== null) &&
            !empty($email) &&
            filter_var($email, FILTER_VALIDATE_EMAIL) &&
            !empty($address) &&
            !empty($password) &&
            preg_match('/^[a-f0-9]{32}$/i', $password)
        ) {
            // Legacy endpoint: these old clients have no way to submit an emailed code, so when
            // email verification is on the only safe answer is to refuse and point at the update.
            if (($select_vendor_table["reg_otp_enabled"] ?? 1) == 1) {
                echo json_encode(array(
                    "json-status" => "success",
                    "status" => "failed",
                    "status-msg" => "Please update your app to the latest version to create an account. Email verification is now required."
                ));
                exit;
            }
            $account_type = '1';
            $token = bin2hex(random_bytes(25));
            $status = '1';
            $balance = "0";
            $checkuser = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND (username = '" . $username . "' OR email='" . $email . "')");
            if (mysqli_num_rows($checkuser) == 0) {
                $proceed_register = false;
                $refined_referral = "";
                if (!empty($referral_raw)) {
                    $decoded_ref = base64_decode($referral_raw, true);
                    $clean_ref = ($decoded_ref !== false) ? trim(strip_tags($decoded_ref)) : '';
                    if ($clean_ref === '') {
                        $status_msg = "Invalid Referral Code";
                    } else {
                        $ref_esc = mysqli_real_escape_string($connection_server, $clean_ref);
                        $check_referral_user = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id = '" . $select_vendor_table["id"] . "' AND username = '" . $ref_esc . "'");
                        if ($check_referral_user && mysqli_num_rows($check_referral_user) == 1) {
                            $proceed_register = true;
                            $get_referral_details = mysqli_fetch_array($check_referral_user);

                            $refined_referral = $get_referral_details["id"];
                        } elseif (!$check_referral_user || mysqli_num_rows($check_referral_user) == 0) {
                            $status_msg = "Invalid Referral Code";
                        } elseif (mysqli_num_rows($check_referral_user) > 1) {
                            $status_msg = "Multiple Referral Records, Contact Admin";
                        }
                    }
                } else {
                    $proceed_register = true;
                }

                if ($proceed_register == true) {
                    $last_login = date('Y-m-d H:i:s.u');
                    $has_country_col = function_exists('bc_ensure_user_country_column') && bc_ensure_user_country_column($connection_server);
                    $country_col_sql = $has_country_col ? ", `country`" : "";
                    $country_val_sql = $has_country_col ? ", '$country'" : "";
                    mysqli_query($connection_server, "INSERT INTO sas_users 
                (vendor_id, username, `password`, phone_number, email, balance, firstname, lastname, othername, account_level, api_key, last_login, api_status,`status`, `home_address`{$country_col_sql}, `referral_id`)
                VALUES ('" . $select_vendor_table["id"] . "', '$username', '$password', '$phone', '$email', '$balance', '$firstname', '$lastname', '$othername', '$account_type', '$token', '$last_login', '2', '$status' , '$address'{$country_val_sql}, '$refined_referral') ");

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
            } elseif ($country_raw !== '' && $country_info === null) {
                $status_msg = "Invalid Country";
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
        //Website not registered
        $app_json = json_encode(array("json-status" => "failed", "status" => "failed", "status-msg" => "Website not registered"), true);
    }
} else {
    $app_json = json_encode(array("json-status" => "success", "status" => "failed", "status-msg" => "Bad Request"), true);
}
mysqli_close($connection_server);

echo $app_json;


?>
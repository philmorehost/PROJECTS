<?php session_start();
    include("../func/bc-spadmin-config.php");

    // Demo-mode blocked feature notice
    if (isset($_GET['demo_blocked']) && bc_is_demo_mode($connection_server)) {
        $blocked_feature = htmlspecialchars($_GET['feature'] ?? 'This feature');
        echo '<div class="alert alert-danger text-center m-3" role="alert">
                <i class="bi bi-lock-fill me-2"></i>
                <strong>Demo Mode:</strong> ' . $blocked_feature . ' is locked.
                Contact your administrator to enable this feature.
              </div>';
    }

    if (isset($_GET['refresh_license'])) {
        header('Content-Type: application/json');
        $license_key = getSuperAdminOption('license_key', '');
        $license_domain = getSuperAdminOption('license_domain', $_SERVER['HTTP_HOST']);
        
        $api_url = "https://manager.pmhserver.name.ng/api.php";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'key' => $license_key,
            'domain' => $license_domain
        ]));
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $is_valid = false;
        $message = "No valid license key provided.";
        $expiry = "Lifetime License";
        $license_type = "Enterprise SaaS Edition";
        
        $data = null;
        if ($http_code === 200 && !empty($response)) {
            $data = json_decode($response, true);
        }
        
        $lock_file = __DIR__ . '/../func/.license.lock';
        if (is_array($data) && isset($data['status'])) {
            // "Limit exceeded" from the licensing server is a rate/request cap, not a revoked
            // license — treat it as a pass so a transient API limit never bricks the platform.
            $limit_exceeded = isset($data['message']) && stripos($data['message'], 'Limit exceeded') !== false;
            $is_valid = ($data['status'] === 'active' || $data['status'] === 'valid' || (int)$data['status'] === 1 || $limit_exceeded);
            $message = $data['message'] ?? ($is_valid ? "License is active and verified." : "License is invalid.");
            $expiry = $data['expiry'] ?? "Lifetime";
            $license_type = $data['type'] ?? "Enterprise SaaS Edition";

            // ONLY an explicit server-side 'suspended' status may activate the hard kill switch.
            // A generic invalid/error response must NOT brick the system — it falls back to the
            // 48-hour grace period so the admin can correct the key/domain via the UI.
            if ($data['status'] === 'suspended') {
                file_put_contents($lock_file, json_encode([
                    'suspended_at' => time(),
                    'reason' => $data['message'] ?? 'License suspended by administrator.'
                ]));
            } elseif ($is_valid) {
                if (file_exists($lock_file)) {
                    @unlink($lock_file);
                }
            }
        } else {
            // If the server returns HTML (e.g. DNS parking/default page) or is offline
            if (!empty($license_key) && strlen($license_key) >= 10) {
                $is_valid = true;
                $message = "Verified locally (Offline mode).";
                if (file_exists($lock_file)) {
                    @unlink($lock_file);
                }
            } else {
                $message = "No valid license key format provided.";
                // Do NOT write a hard lock here — the 48h grace period is the soft fallback.
            }
        }

        $status_str = $is_valid ? 'valid' : 'invalid';
        bc_store_license_option($connection_server, 'license_status', $status_str);
        bc_store_license_option($connection_server, 'license_last_check', date('Y-m-d H:i:s'));

        if ($is_valid) {
            mysqli_query($connection_server, "DELETE FROM sas_super_admin_options WHERE option_name='license_invalid_since'");
        } else {
            // Only set if not already set, to prevent reset on subsequent checks
            $invalid_check = mysqli_query($connection_server, "SELECT option_value FROM sas_super_admin_options WHERE option_name='license_invalid_since' LIMIT 1");
            if ($invalid_check && mysqli_num_rows($invalid_check) == 0) {
                bc_store_license_option($connection_server, 'license_invalid_since', time());
            }
        }
        
        echo json_encode([
            'status' => 'success',
            'is_valid' => $is_valid,
            'message' => $message,
            'expiry' => $expiry,
            'type' => $license_type,
            'last_check' => date('Y-m-d H:i:s')
        ]);
        exit();
    }

    if (isset($_POST['update-tmp-url-settings'])) {
        $temporary_domain_input = trim((string)($_POST['temporary_vendor_domain'] ?? ''));
        $expiry_days = filter_var($_POST['tmp_url_expiry_days'] ?? null, FILTER_VALIDATE_INT);
        $save_error = '';
        $temporary_domain = '';

        if ($expiry_days === false || $expiry_days < 1 || $expiry_days > 30) {
            $save_error = 'Temporary access duration must be between 1 and 30 days.';
        } elseif ($temporary_domain_input !== '') {
            $temporary_domain = bc_clean_vendor_domain($temporary_domain_input);
            if ($temporary_domain === '' || !filter_var($temporary_domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
                $save_error = 'Enter a valid domain name only, for example temporary.example.com.';
            } elseif (bc_normalize_vendor_host($temporary_domain) === bc_normalize_vendor_host($_SERVER['HTTP_HOST'] ?? '')) {
                $save_error = 'The temporary vendor domain must be different from the current primary website domain.';
            } else {
                $vendor_domains = mysqli_query($connection_server, "SELECT website_url FROM sas_vendors WHERE website_url IS NOT NULL AND website_url <> '' UNION ALL SELECT website_url FROM sas_pending_vendors WHERE website_url IS NOT NULL AND website_url <> ''");
                if (!$vendor_domains) {
                    $save_error = 'Could not verify that the temporary domain is separate from vendor and pending vendor domains: ' . mysqli_error($connection_server);
                } else {
                    while ($vendor_domain_row = mysqli_fetch_assoc($vendor_domains)) {
                        if (bc_normalize_vendor_host($temporary_domain) === bc_normalize_vendor_host($vendor_domain_row['website_url'] ?? '')) {
                            $save_error = 'The temporary vendor domain cannot also be assigned as an existing or pending vendor website domain.';
                            break;
                        }
                    }
                }
            }
        }

        if ($save_error === '') {
            if (!mysqli_begin_transaction($connection_server)) {
                $save_error = 'Could not start saving the temporary access settings: ' . mysqli_error($connection_server);
            } else {
                $domain_saved = setSuperAdminOption('temporary_vendor_domain', $temporary_domain);
                $expiry_saved = $domain_saved && setSuperAdminOption('tmp_url_expiry_days', (string)$expiry_days);
                if ($expiry_saved && mysqli_commit($connection_server)) {
                    $_SESSION['product_purchase_response'] = $temporary_domain === ''
                        ? 'Temporary vendor domain cleared. Secure vendor portal access is now disabled until a domain is configured.'
                        : 'Temporary vendor domain and access duration saved successfully.';
                } else {
                    mysqli_rollback($connection_server);
                    $save_error = 'Could not save the temporary access settings: ' . mysqli_error($connection_server);
                }
            }
        }

        if ($save_error !== '') {
            $_SESSION['product_purchase_response'] = $save_error;
        }
        header('Location: AccountSettings.php#tab-system');
        exit();
    }
    
    if(isset($_POST["change-logo"])){
        $logo_file = $_FILES["logo"] ?? null;
        $logo_name = $logo_file["name"] ?? "";
        $logo_tmp_name = $logo_file["tmp_name"] ?? "";
        $logo_size = (int)($logo_file["size"] ?? 0);
        $logo_error = (int)($logo_file["error"] ?? UPLOAD_ERR_NO_FILE);
        $logo_ext = strtolower(pathinfo($logo_name, PATHINFO_EXTENSION));
        $acceptable_ext_array = array("png", "jpg", "jpeg");

        $logo_problem = null;
        switch ($logo_error) {
            case UPLOAD_ERR_OK: break;
            case UPLOAD_ERR_NO_FILE: $logo_problem = "File Field Empty"; break;
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE: $logo_problem = "File Too Larger Than " . ini_get('upload_max_filesize') . " (server upload limit)"; break;
            case UPLOAD_ERR_PARTIAL: $logo_problem = "The logo upload was interrupted - please try again"; break;
            case UPLOAD_ERR_NO_TMP_DIR:
            case UPLOAD_ERR_CANT_WRITE: $logo_problem = "The server could not store the uploaded logo"; break;
            case UPLOAD_ERR_EXTENSION: $logo_problem = "A PHP extension blocked the logo upload"; break;
            default: $logo_problem = "The logo upload failed (code " . $logo_error . ")";
        }
        if ($logo_problem === null && ($logo_name === "" || $logo_size <= 0)) $logo_problem = "File Field Empty";
        if ($logo_problem === null && $logo_size > 2097152) $logo_problem = "File Too Larger Than 2MB";
        if ($logo_problem === null && !in_array($logo_ext, $acceptable_ext_array, true)) $logo_problem = "Error: Image Extension must be (" . implode(", ", $acceptable_ext_array) . ")";

        // Resolve from this file. Relative paths could be evaluated from the Apache/FPM working
        // directory, causing the upload to be saved outside the document root.
        $logo_dir = __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "uploaded-image";
        $logo_dir = realpath($logo_dir) ?: (__DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "uploaded-image");
        $logo_target = $logo_dir . DIRECTORY_SEPARATOR . "sp-logo.png";
        $had_logo = is_file($logo_target);

        if ($logo_problem === null && !is_dir($logo_dir) && !@mkdir($logo_dir, 0755, true)) $logo_problem = "The uploaded-image folder could not be created";
        if ($logo_problem === null && !is_dir($logo_dir)) $logo_problem = "The uploaded-image folder does not exist";
        if ($logo_problem === null && !is_writable($logo_dir)) $logo_problem = "The uploaded-image folder is not writable by PHP";

        $tmp_target = "";
        $stored = false;
        if ($logo_problem === null) {
            $tmp_target = $logo_dir . DIRECTORY_SEPARATOR . "sp-logo.uploading_" . bin2hex(random_bytes(6));
            if (in_array($logo_ext, array("jpg", "jpeg"), true)) {
                if (!function_exists("imagecreatefromjpeg") || !function_exists("imagepng")) {
                    $logo_problem = "JPEG uploads require the PHP GD extension. Upload a PNG or enable GD on the server.";
                } else {
                    $image = @imagecreatefromjpeg($logo_tmp_name);
                    if ($image !== false) { $stored = @imagepng($image, $tmp_target, 6); imagedestroy($image); }
                }
            } else {
                $stored = @move_uploaded_file($logo_tmp_name, $tmp_target);
            }
        }

        $renamed = false;
        if ($logo_problem === null && $stored) {
            $renamed = @rename($tmp_target, $logo_target);
            if (!$renamed && is_file($logo_target)) { @unlink($logo_target); $renamed = @rename($tmp_target, $logo_target); }
        }
        if ($logo_problem === null && $stored && $renamed) {
            $message = "Website Logo " . ($had_logo ? "Updated" : "Created") . " Successfully";
        } else {
            if ($tmp_target !== "" && is_file($tmp_target)) @unlink($tmp_target);
            $message = $logo_problem ?: "Website Logo could NOT be saved: PHP could not write the image to " . $logo_dir;
        }
        $_SESSION["product_purchase_response"] = $message;
        header("Location: " . $_SERVER["REQUEST_URI"]);
        exit;
    }

    $css_style_template_array = array("1" => "bc-style-template-1", "2" => "bc-style-template-2", "3" => "bc-style-template-3", "4" => "bc-style-template-4", "5" => "bc-style-template-5");
    if (isset($_POST["update-template"])) {
        $template_name = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["template-name"])));
        $primary_color = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["primary-color"])));
        $template_filename = pathinfo($template_name, PATHINFO_FILENAME);
        $css_style_template_name_array = array_values($css_style_template_array);

        if (!empty($template_name) && in_array($template_filename, $css_style_template_name_array)) {
            $select_spadmin_style_templates_details = mysqli_query($connection_server, "SELECT * FROM sas_spadmin_style_templates");
            if (mysqli_num_rows($select_spadmin_style_templates_details) == 0) {
                mysqli_query($connection_server, "INSERT INTO sas_spadmin_style_templates (template_name, primary_color) VALUES ('$template_name', '$primary_color')");
                //Template Created & Updated Successfully
                $json_response_array = array("desc" => "Template Created & Updated Successfully");
                $json_response_encode = json_encode($json_response_array, true);
            } else {
                if (mysqli_num_rows($select_spadmin_style_templates_details) == 1) {
                    mysqli_query($connection_server, "UPDATE sas_spadmin_style_templates SET template_name='$template_name', primary_color='$primary_color'");
                    //Template Updated Successfully
                    $json_response_array = array("desc" => "Template Updated Successfully");
                    $json_response_encode = json_encode($json_response_array, true);
                } else {
                    if (mysqli_num_rows($select_spadmin_style_templates_details) > 1) {
                        //Duplicated Details, Contact Admin
                        $json_response_array = array("desc" => "Duplicated Details, Contact Admin");
                        $json_response_encode = json_encode($json_response_array, true);
                    }
                }
            }
        } else {
            if (empty($template_name)) {
                //Template Field Empty
                $json_response_array = array("desc" => "Template Field Empty");
                $json_response_encode = json_encode($json_response_array, true);
            } else {
                if (!in_array($template_filename, $css_style_template_name_array)) {
                    //Invalid Template Type
                    $json_response_array = array("desc" => "Invalid Template Type");
                    $json_response_encode = json_encode($json_response_array, true);
                }
            }
        }

        $json_response_decode = json_decode($json_response_encode, true);
        $_SESSION["product_purchase_response"] = $json_response_decode["desc"];
        header("Location: " . $_SERVER["REQUEST_URI"]);
    }
    
    if(isset($_POST["update-profile"])){
        $first = mysqli_real_escape_string($connection_server, trim(strip_tags(ucwords($_POST["first"]))));
        $last = mysqli_real_escape_string($connection_server, trim(strip_tags(ucwords($_POST["last"]))));
        $address = mysqli_real_escape_string($connection_server, trim(strip_tags(ucwords($_POST["address"]))));
        $email = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["email"]))));
        $phone = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["phone"]))));
    	$pass = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["pass"])));
        
        if(!empty($first) && !empty($last) && !empty($address) && !empty($email) && !empty($phone) && !empty($pass)){
            $check_admin_details = mysqli_query($connection_server, "SELECT * FROM sas_super_admin WHERE id='".$get_logged_spadmin_details["id"]."'");
            if(mysqli_num_rows($check_admin_details) == 1){
                // Verify the current password in PHP so both password_hash() (bcrypt) and legacy raw-MD5
                // rows are accepted; a legacy hash is upgraded in place on success.
                $check_admin_with_pass_matches = ($get_logged_spadmin_details && bc_verify_password($pass, $get_logged_spadmin_details["password"])) ? 1 : 0;
                if ($check_admin_with_pass_matches === 1 && bc_password_is_legacy($get_logged_spadmin_details["password"])) {
                    $rehashed_spadmin_pass = mysqli_real_escape_string($connection_server, bc_hash_password($pass));
                    mysqli_query($connection_server, "UPDATE sas_super_admin SET password='$rehashed_spadmin_pass' WHERE id='".(int)$get_logged_spadmin_details["id"]."'");
                }
                $check_admin_with_email = mysqli_query($connection_server, "SELECT * FROM sas_super_admin WHERE email='$email'");
                $check_admin_with_phone = mysqli_query($connection_server, "SELECT * FROM sas_super_admin WHERE phone_number='$phone'");
                
                if($check_admin_with_pass_matches == 1){
                    $proceed_account_phone_verification = false;
                    if(mysqli_num_rows($check_admin_with_email) == 1){
                        $admin_email_fetch = mysqli_fetch_array($check_admin_with_email);
                        if($admin_email_fetch["id"] == $get_logged_spadmin_details["id"]){
                            $proceed_account_phone_verification = true;
                        }else{
                            //Email Taken By Another Admin
                            $json_response_array = array("desc" => "Email Taken By Another Admin");
                            $json_response_encode = json_encode($json_response_array,true);
                        }
                    }else{
                        if(mysqli_num_rows($check_admin_with_email) == 0){
                            $proceed_account_phone_verification = true;
                        }else{
                            //Duplicated Email, Contact Admin
                            $json_response_array = array("desc" => "Duplicated Email, Contact Admin");
                            $json_response_encode = json_encode($json_response_array,true);
                        }
                    }

                    $proceed_account_update = false;
                    if($proceed_account_phone_verification == true){
                        if(mysqli_num_rows($check_admin_with_phone) == 1){
                            $admin_phone_fetch = mysqli_fetch_array($check_admin_with_phone);
                            if($admin_phone_fetch["id"] == $get_logged_spadmin_details["id"]){
                                $proceed_account_update = true;
                            }else{
                                //Phone Number Taken By Another Admin
                                $json_response_array = array("desc" => "Phone Number Taken By Another Admin");
                                $json_response_encode = json_encode($json_response_array,true);
                            }
                        }else{
                            if(mysqli_num_rows($check_admin_with_phone) == 0){
                                $proceed_account_update = true;
                            }else{
                                //Duplicated Phone Number, Contact Admin
                                $json_response_array = array("desc" => "Duplicated Phone Number, Contact Admin");
                                $json_response_encode = json_encode($json_response_array,true);
                            }
                        }
                    }

                    if($proceed_account_update == true){
                        mysqli_query($connection_server, "UPDATE sas_super_admin SET firstname='$first', lastname='$last', home_address='$address', email='$email', phone_number='$phone' WHERE id='".$get_logged_spadmin_details["id"]."'");
                        //Profile Information Updated Successfully
                        $json_response_array = array("desc" => "Profile Information Updated Successfully");
                        $json_response_encode = json_encode($json_response_array,true);
                    }
                }else{
                    //Incorrect Password
                    $json_response_array = array("desc" => "Incorrect Password");
                    $json_response_encode = json_encode($json_response_array,true);
                }
            }else{
                if(mysqli_num_rows($check_admin_details) == 0){
                    //Admin Not Exists
                    $json_response_array = array("desc" => "Admin Not Exists");
                    $json_response_encode = json_encode($json_response_array,true);
                }else{
                    if(mysqli_num_rows($check_admin_details) > 1){
                        //Duplicated Details, Contact Admin
                        $json_response_array = array("desc" => "Duplicated Details, Contact Admin");
                        $json_response_encode = json_encode($json_response_array,true);
                    }
                }
            }
        }else{
            if(empty($first)){
                //Firstname Field Empty
                $json_response_array = array("desc" => "Firstname Field Empty");
                $json_response_encode = json_encode($json_response_array,true);
            }else{
                if(empty($last)){
                    //Lastname Field Empty
                    $json_response_array = array("desc" => "Lastname Field Empty");
                    $json_response_encode = json_encode($json_response_array,true);
                }else{
                    if(empty($address)){
                        //Home Address Field Empty
                        $json_response_array = array("desc" => "Home Address Field Empty");
                        $json_response_encode = json_encode($json_response_array,true);
                    }else{
                        if(empty($email)){
                            //Email Field Empty
                            $json_response_array = array("desc" => "Email Field Empty");
                            $json_response_encode = json_encode($json_response_array,true);
                        }else{
                            if(empty($phone)){
                                //Phone Number Field Empty
                                $json_response_array = array("desc" => "Phone Number Field Empty");
                                $json_response_encode = json_encode($json_response_array,true);
                            }else{
                                if(empty($pass)){
                                    //Password Field Empty
                                    $json_response_array = array("desc" => "Password Field Empty");
                                    $json_response_encode = json_encode($json_response_array,true);
                                }
                            }
                        }
                    }
                }
            }
        }

        $json_response_decode = json_decode($json_response_encode,true);
        $_SESSION["product_purchase_response"] = $json_response_decode["desc"];
        header("Location: ".$_SERVER["REQUEST_URI"]);
    }
    
    if (isset($_POST['set-demo-lock-key'])) {
        $new_demo_key = trim($_POST['demo_lock_key'] ?? '');
        $confirm_demo_key = trim($_POST['demo_lock_key_confirm'] ?? '');
        if (strlen($new_demo_key) < 10 || $new_demo_key !== $confirm_demo_key) {
            $_SESSION['product_purchase_response'] = 'Demo/Production lock key must be at least 10 characters and both entries must match.';
        } elseif (bc_demo_set_lock_key($connection_server, $new_demo_key)) {
            $_SESSION['product_purchase_response'] = 'Demo/Production security lock key saved successfully.';
        } else {
            $_SESSION['product_purchase_response'] = 'Unable to save the Demo/Production security lock key.';
        }
        header('Location: ' . $_SERVER['REQUEST_URI'] . '#tab-security');
        exit();
    }

    if (isset($_POST['change-demo-mode'])) {
        $demo_result = bc_demo_toggle($connection_server, $_POST['demo_mode'] ?? 'production', $_POST['demo_lock_key'] ?? '');
        $_SESSION['product_purchase_response'] = $demo_result['message'];
        header('Location: ' . $_SERVER['REQUEST_URI'] . '#tab-security');
        exit();
    }

    if(isset($_POST["update-security"])){ 
        $force_pin = isset($_POST["force_vendor_pin"]) ? '1' : '0';
        $force_email = isset($_POST["force_spadmin_trans_email"]) ? '1' : '0';
        mysqli_query($connection_server, "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('force_vendor_pin', '$force_pin') ON DUPLICATE KEY UPDATE option_value='$force_pin'");
        mysqli_query($connection_server, "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('spadmin_trans_email_enabled', '$force_email') ON DUPLICATE KEY UPDATE option_value='$force_email'");

        // Require an emailed one-time code at every vendor (bc-admin) login. A per-vendor preference
        // (sas_vendors.login_otp_enabled) overrides this default; vendors that never chose inherit it.
        $force_login_otp = isset($_POST["force_bcadmin_login_otp"]) ? '1' : '0';
        mysqli_query($connection_server, "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('force_bcadmin_login_otp', '$force_login_otp') ON DUPLICATE KEY UPDATE option_value='$force_login_otp'");

        // Daily password-reset limit (per account, default 1)
        $reset_daily_limit = max(1, (int)($_POST["password_reset_daily_limit"] ?? 1));
        mysqli_query($connection_server, "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('password_reset_daily_limit', '$reset_daily_limit') ON DUPLICATE KEY UPDATE option_value='$reset_daily_limit'");

        $smtp_host = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["smtp_host"])));
        $smtp_user = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["smtp_user"])));
        $smtp_pass = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["smtp_pass"])));
        $smtp_port = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["smtp_port"])));
        $smtp_sec = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["smtp_sec"])));

        mysqli_query($connection_server, "UPDATE sas_super_admin SET smtp_host='$smtp_host', smtp_user='$smtp_user', smtp_pass='$smtp_pass', smtp_port='$smtp_port', smtp_sec='$smtp_sec' WHERE id='".$get_logged_spadmin_details["id"]."'");

        if(!empty($_POST["my_pin"])){
            $new_pin = mysqli_real_escape_string($connection_server, $_POST["my_pin"]);
            if(preg_match("/^\d{4}$/", $new_pin)){
                $hashed_pin = password_hash($new_pin, PASSWORD_DEFAULT);
                mysqli_query($connection_server, "UPDATE sas_super_admin SET security_pin='$hashed_pin' WHERE id='".$get_logged_spadmin_details["id"]."'");
                $_SESSION["product_purchase_response"] = "Global policy and personal Security PIN updated successfully";
            } else {
                $_SESSION["product_purchase_response"] = "Error: PIN must be 4 digits.";
            }
        } else {
            $_SESSION["product_purchase_response"] = "Global security policy updated successfully";
        }
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit();
    }

    if(isset($_POST["change-password"])){
        $old_pass = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["old-pass"])));
        $new_pass = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["new-pass"])));
        $con_new_pass = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["con-new-pass"])));
        
        if(!empty($old_pass) && !empty($new_pass) && !empty($con_new_pass)){
            $check_admin_details = mysqli_query($connection_server, "SELECT * FROM sas_super_admin WHERE id='".$get_logged_spadmin_details["id"]."'");
            if(mysqli_num_rows($check_admin_details) == 1){
                // bc_verify_password() accepts both bcrypt and legacy raw-MD5 hashes, and isolates the
                // scheme from the surrounding logic (a bcrypt row can never be compared with a plain ==).
                $pass_matches_old = bc_verify_password($old_pass, $get_logged_spadmin_details["password"]);
                $pass_matches_new = bc_verify_password($new_pass, $get_logged_spadmin_details["password"]);
                $new_pass_matches_confirm = ($new_pass === $con_new_pass);
                
                if($pass_matches_old){
                    if(!$pass_matches_new){
                        if($new_pass_matches_confirm){
                            $new_spadmin_pass_hash = mysqli_real_escape_string($connection_server, bc_hash_password($new_pass));
                            mysqli_query($connection_server, "UPDATE sas_super_admin SET password='$new_spadmin_pass_hash' WHERE id='".(int)$get_logged_spadmin_details["id"]."'");
                            //Account Password Updated Successfully
                            $json_response_array = array("desc" => "Account Password Updated Successfully");
                            $json_response_encode = json_encode($json_response_array,true);
                        }else{
                            //New & Confirm Password Not Match
                            $json_response_array = array("desc" => "New & Confirm Password Not Match");
                            $json_response_encode = json_encode($json_response_array,true);
                        }
                    }else{
                        //New & Old Password Must Be Different
                        $json_response_array = array("desc" => "New & Old Password Must Be Different");
                        $json_response_encode = json_encode($json_response_array,true);
                    }
                }else{
                    //Incorrect Old Password
                    $json_response_array = array("desc" => "Incorrect Old Password");
                    $json_response_encode = json_encode($json_response_array,true);
                }
            }else{
                if(mysqli_num_rows($check_admin_details) == 0){
                    //Admin Not Exists
                    $json_response_array = array("desc" => "Admin Not Exists");
                    $json_response_encode = json_encode($json_response_array,true);
                }else{
                    if(mysqli_num_rows($check_admin_details) > 1){
                        //Duplicated Details, Contact Admin
                        $json_response_array = array("desc" => "Duplicated Details, Contact Admin");
                        $json_response_encode = json_encode($json_response_array,true);
                    }
                }
            }
        }else{
            if(empty($old_pass)){
                //Old Password Field Empty
                $json_response_array = array("desc" => "Old Password Field Empty");
                $json_response_encode = json_encode($json_response_array,true);
            }else{
                if(empty($new_pass)){
                    //New Password Field Empty
                    $json_response_array = array("desc" => "New Password Field Empty");
                    $json_response_encode = json_encode($json_response_array,true);
                }else{
                    if(empty($con_new_pass)){
                        //Confirm New Password Field Empty
                        $json_response_array = array("desc" => "Confirm New Password Field Empty");
                        $json_response_encode = json_encode($json_response_array,true);
                    }
                }
            }
        }
    
        $json_response_decode = json_decode($json_response_encode,true);
        $_SESSION["product_purchase_response"] = $json_response_decode["desc"];
        header("Location: ".$_SERVER["REQUEST_URI"]);
    }

    if(isset($_POST["update-bank-details"])){
        $fullname = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["name"])));
        $bank_name = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["bank"])));
        $account_number = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9]+/","",trim(strip_tags($_POST["number"]))));
        $phone_number = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9]+/","",strip_tags($_POST["phone"])));
        $amount_charged = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9.]+/","",trim(strip_tags($_POST["charges"]))));

        if(!empty($fullname) && !empty($bank_name) && !empty($account_number) && is_numeric($account_number) && !empty($phone_number) && is_numeric($phone_number) && !empty($amount_charged) && is_numeric($amount_charged) && ($amount_charged > 0)){
            $get_admin_payment_details = mysqli_query($connection_server, "SELECT * FROM sas_super_admin_payments LIMIT 1");
	
            if(mysqli_num_rows($get_admin_payment_details) == 1){
                mysqli_query($connection_server, "UPDATE sas_super_admin_payments SET bank_name='$bank_name', account_name='$fullname', account_number='$account_number', phone_number='$phone_number', amount_charged='$amount_charged'");
                //Bank Information Updated Successfully
                $json_response_array = array("desc" => "Bank Information Updated Successfully");
                $json_response_encode = json_encode($json_response_array,true);
            }else{
                if(mysqli_num_rows($get_admin_payment_details) == 0){
		            mysqli_query($connection_server, "INSERT INTO sas_super_admin_payments (bank_name, account_name, account_number, phone_number, amount_charged) VALUES ('$bank_name', '$fullname', '$account_number', '$phone_number', '$amount_charged')");
                    //Admin Bank Info Exists
                    $json_response_array = array("desc" => "Bank Information Created Successfully");
                    $json_response_encode = json_encode($json_response_array,true);
                }else{
                    if(mysqli_num_rows($get_admin_payment_details) > 1){
                        //Duplicated Details, Contact Admin
                        $json_response_array = array("desc" => "Duplicated Details, Contact Admin");
                        $json_response_encode = json_encode($json_response_array,true);
                    }
                }
            }
        }else{
            if(empty($fullname)){
                //Fullname Field Empty
                $json_response_array = array("desc" => "Fullname Field Empty");
                $json_response_encode = json_encode($json_response_array,true);
            }else{
                if(empty($bank_name)){
                    //Bank Name Field Empty
                    $json_response_array = array("desc" => "Bank Name Field Empty");
                    $json_response_encode = json_encode($json_response_array,true);
                }else{
                    if(empty($account_number)){
                        //Account Number Field Empty
                        $json_response_array = array("desc" => "Account Number Field Empty");
                        $json_response_encode = json_encode($json_response_array,true);
                    }else{
                        if(!is_numeric($account_number)){
                            //Non-numeric Account Number
                            $json_response_array = array("desc" => "Non-numeric Account Number");
                            $json_response_encode = json_encode($json_response_array,true);
                        }else{
                            if(empty($phone_number)){
                                //Phone Number Field Empty
                                $json_response_array = array("desc" => "Phone Number Field Empty");
                                $json_response_encode = json_encode($json_response_array,true);
                            }else{
                                if(!is_numeric($phone_number)){
                                    //Non-numeric Phone Number
                                    $json_response_array = array("desc" => "Non-numeric Account Number");
                                    $json_response_encode = json_encode($json_response_array,true);
                                }else{
                                    if(empty($amount_charged)){
                                        //Amount Field Empty
                                        $json_response_array = array("desc" => "Amount Number Field Empty");
                                        $json_response_encode = json_encode($json_response_array,true);
                                    }else{
                                        if(!is_numeric($amount_charged)){
                                            //Non-numeric Amount
                                            $json_response_array = array("desc" => "Non-numeric Account");
                                            $json_response_encode = json_encode($json_response_array,true);
                                        }else{
                                            if($amount_charged > 0){
                                                //Amount Must Be Greater Than 0
                                                $json_response_array = array("desc" => "Amount Must Be Greater Than 0");
                                                $json_response_encode = json_encode($json_response_array,true);
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        $json_response_decode = json_decode($json_response_encode,true);
        $_SESSION["product_purchase_response"] = $json_response_decode["desc"];
        header("Location: ".$_SERVER["REQUEST_URI"]);
    }


    if(isset($_POST["update-global-withdrawal-settings"])){
        $def_min = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9.]+/","",trim(strip_tags($_POST["def_min"]))));
        $def_max = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9.]+/","",trim(strip_tags($_POST["def_max"]))));
        $def_limit = (int)$_POST["def_limit"];

        mysqli_query($connection_server, "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('default_min_withdrawal', '$def_min') ON DUPLICATE KEY UPDATE option_value='$def_min'");
        mysqli_query($connection_server, "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('default_max_withdrawal', '$def_max') ON DUPLICATE KEY UPDATE option_value='$def_max'");
        mysqli_query($connection_server, "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('default_daily_payout_limit', '$def_limit') ON DUPLICATE KEY UPDATE option_value='$def_limit'");

        if (isset($_POST['apply_to_all'])) {
            mysqli_query($connection_server, "UPDATE sas_vendors SET min_withdrawal_amount='$def_min', max_withdrawal_amount='$def_max', daily_payout_limit='$def_limit'");
            $_SESSION["product_purchase_response"] = "Global withdrawal defaults updated and applied to all vendors successfully";
        } else {
            $_SESSION["product_purchase_response"] = "Global withdrawal defaults updated successfully (New vendors only)";
        }
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit();
    }

    if(isset($_POST["update-payment-order-details"])){
        $min_amount = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9.]+/","",trim(strip_tags($_POST["min"]))));
        $max_amount = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9.]+/","",trim(strip_tags($_POST["max"]))));

        if(!empty($min_amount) && is_numeric($min_amount) && ($min_amount > 0) && !empty($max_amount) && is_numeric($max_amount) && ($max_amount > 0) && ($max_amount > $min_amount)){
            $get_admin_payment_order_details = mysqli_query($connection_server, "SELECT * FROM sas_super_admin_payment_orders LIMIT 1");
	
            if(mysqli_num_rows($get_admin_payment_order_details) == 1){
                mysqli_query($connection_server, "UPDATE sas_super_admin_payment_orders SET min_amount='$min_amount', max_amount='$max_amount'");
                //Payment Order Limits Information Updated Successfully
                $json_response_array = array("desc" => "Payment Order Limits Information Updated Successfully");
                $json_response_encode = json_encode($json_response_array,true);
            }else{
                if(mysqli_num_rows($get_admin_payment_order_details) == 0){
		            mysqli_query($connection_server, "INSERT INTO sas_super_admin_payment_orders (min_amount, max_amount) VALUES ('$min_amount', '$max_amount')");
                    //Payment Order Limits Information Created Successfully
                    $json_response_array = array("desc" => "Payment Order Limits Information Created Successfully");
                    $json_response_encode = json_encode($json_response_array,true);
                }else{
                    if(mysqli_num_rows($get_admin_payment_order_details) > 1){
                        //Duplicated Details, Contact Admin
                        $json_response_array = array("desc" => "Duplicated Details, Contact Admin");
                        $json_response_encode = json_encode($json_response_array,true);
                    }
                }
            }
        }else{
            if(empty($min_amount)){
                //Minimum Amount Field Empty
                $json_response_array = array("desc" => "Minimum Amount Field Empty");
                $json_response_encode = json_encode($json_response_array,true);
            }else{
                if(!is_numeric($min_amount)){
                    //Non-numeric Minimum Amount
                    $json_response_array = array("desc" => "Non-numeric Minimum Amount");
                    $json_response_encode = json_encode($json_response_array,true);
                }else{
                    if(($min_amount < 0)){
                        //Minimum Amount MUst Be Greater Than Zero (0)
                        $json_response_array = array("desc" => "Minimum Amount MUst Be Greater Than Zero (0)");
                        $json_response_encode = json_encode($json_response_array,true);
                    }else{
                        if(empty($max_amount)){
                            //Maximum Amount Field Empty
                            $json_response_array = array("desc" => "Maximum Amount Field Empty");
                            $json_response_encode = json_encode($json_response_array,true);
                        }else{
                            if(!is_numeric($max_amount)){
                                //Non-numeric Maximum Amount
                                $json_response_array = array("desc" => "Non-numeric Maximum Amount");
                                $json_response_encode = json_encode($json_response_array,true);
                            }else{
                                if(($max_amount < 0)){
                                    //Maximum Amount MUst Be Greater Than Zero (0)
                                    $json_response_array = array("desc" => "Maximum Amount MUst Be Greater Than Zero (0)");
                                    $json_response_encode = json_encode($json_response_array,true);
                                }else{
                                    if(($min_amount > $max_amount)){
                                        //Minimum Amount Must Not Be Greater Than Maximum Amount
                                        $json_response_array = array("desc" => "Minimum Amount Must Not Be Greater Than Maximum Amount");
                                        $json_response_encode = json_encode($json_response_array,true);
                                    }else{
                                        if(($min_amount == $max_amount)){
                                            //Minimum Amount Must Not Equal Maximum Amount
                                            $json_response_array = array("desc" => "Minimum Amount Must Not Equal Maximum Amount");
                                            $json_response_encode = json_encode($json_response_array,true);
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        $json_response_decode = json_decode($json_response_encode,true);
        $_SESSION["product_purchase_response"] = $json_response_decode["desc"];
        header("Location: ".$_SERVER["REQUEST_URI"]);
    }

	
    if(isset($_POST["update-app-services"])){
        $apk_price = mysqli_real_escape_string($connection_server, $_POST['apk_price']);
        $ios_price = mysqli_real_escape_string($connection_server, $_POST['ios_price']);
        $playstore_price = mysqli_real_escape_string($connection_server, $_POST['playstore_price']);
        $sms_bridge_price = mysqli_real_escape_string($connection_server, $_POST['sms_bridge_price']);

        mysqli_query($connection_server, "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('apk_development_price', '$apk_price') ON DUPLICATE KEY UPDATE option_value='$apk_price'");
        mysqli_query($connection_server, "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('ios_development_price', '$ios_price') ON DUPLICATE KEY UPDATE option_value='$ios_price'");
        mysqli_query($connection_server, "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('playstore_listing_price', '$playstore_price') ON DUPLICATE KEY UPDATE option_value='$playstore_price'");
        mysqli_query($connection_server, "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('sms_bridge_price', '$sms_bridge_price') ON DUPLICATE KEY UPDATE option_value='$sms_bridge_price'");

        $_SESSION["product_purchase_response"] = "App service prices updated successfully";
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit();
    }

    if(isset($_POST["update-whmcs-settings"])){
        $api_url = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["whmcs_api_url"])));
        $api_ident = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["whmcs_api_ident"])));
        $api_secret = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["whmcs_api_secret"])));
        $dom_price = mysqli_real_escape_string($connection_server, $_POST['whmcs_domain_price']);
        $client_id = mysqli_real_escape_string($connection_server, $_POST['whmcs_client_id']);

        mysqli_query($connection_server, "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('whmcs_api_url', '$api_url') ON DUPLICATE KEY UPDATE option_value='$api_url'");
        mysqli_query($connection_server, "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('whmcs_api_ident', '$api_ident') ON DUPLICATE KEY UPDATE option_value='$api_ident'");
        mysqli_query($connection_server, "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('whmcs_api_secret', '$api_secret') ON DUPLICATE KEY UPDATE option_value='$api_secret'");
        mysqli_query($connection_server, "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('default_domain_registration_price', '$dom_price') ON DUPLICATE KEY UPDATE option_value='$dom_price'");
        mysqli_query($connection_server, "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('whmcs_default_client_id', '$client_id') ON DUPLICATE KEY UPDATE option_value='$client_id'");

        $_SESSION["product_purchase_response"] = "WHMCS API settings updated successfully";
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit();
    }


    if(isset($_POST["update-cpanel-settings"])){
        $cp_host = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["cpanel_host"])));
        $cp_user = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["cpanel_username"])));
        $cp_token = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["cpanel_api_token"])));

        mysqli_query($connection_server, "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('cpanel_host', '$cp_host') ON DUPLICATE KEY UPDATE option_value='$cp_host'");
        mysqli_query($connection_server, "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('cpanel_username', '$cp_user') ON DUPLICATE KEY UPDATE option_value='$cp_user'");
        mysqli_query($connection_server, "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('cpanel_api_token', '$cp_token') ON DUPLICATE KEY UPDATE option_value='$cp_token'");

        $_SESSION["product_purchase_response"] = "cPanel API settings updated successfully";
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit();
    }


    if(isset($_POST["update-printhub-settings"])){
        $new_secret = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["ph_secret"])));
        if(!empty($new_secret)){
            mysqli_query($connection_server, "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('print_hub_secret', '$new_secret') ON DUPLICATE KEY UPDATE option_value='$new_secret'");
            $_SESSION["product_purchase_response"] = "Print Hub security token updated successfully";
        }
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit();
    }

    if(isset($_POST["update-license"])){
        $license_key = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["license_key"])));
        $license_domain = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["license_domain"])));

        // Use the existence-checked upsert so the value actually sticks on both schema
        // variants of sas_super_admin_options (plain INSERT ... ON DUPLICATE KEY UPDATE
        // silently appends duplicates on the older non-unique-key layout).
        bc_store_license_option($connection_server, 'license_key', $license_key);
        bc_store_license_option($connection_server, 'license_domain', $license_domain);

        // Persist to activation file as well
        bc_write_activation($license_key);
        // Clear integrity cache file to force fresh check on next load
        $cache_file = __DIR__ . '/../func/cache/bc-core.cache';
        if (file_exists($cache_file)) {
            @unlink($cache_file);
        }

        // Validate immediately
        $api_url = "https://manager.pmhserver.name.ng/api.php";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'key' => $license_key,
            'domain' => $license_domain
        ]));
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $is_valid = false;
        $data = null;
        if ($http_code === 200 && !empty($response)) {
            $data = json_decode($response, true);
        }
        $lock_file = __DIR__ . '/../func/.license.lock';
        if (is_array($data) && isset($data['status'])) {
            $limit_exceeded = isset($data['message']) && stripos($data['message'], 'Limit exceeded') !== false;
            $is_valid = ($data['status'] === 'active' || $data['status'] === 'valid' || (int)$data['status'] === 1 || $limit_exceeded);

            // Hard lock ONLY on an explicit server-side suspension. Any other invalid status
            // falls back to the 48h grace period so the admin can still correct the key.
            if ($data['status'] === 'suspended') {
                file_put_contents($lock_file, json_encode([
                    'suspended_at' => time(),
                    'reason' => $data['message'] ?? 'License suspended by administrator.'
                ]));
            } elseif ($is_valid && file_exists($lock_file)) {
                @unlink($lock_file);
            }
        } else {
            if (!empty($license_key) && strlen($license_key) >= 10) {
                $is_valid = true;
                if (file_exists($lock_file)) {
                    @unlink($lock_file);
                }
            }
        }
        
        $status_str = $is_valid ? 'valid' : 'invalid';
        bc_store_license_option($connection_server, 'license_status', $status_str);
        bc_store_license_option($connection_server, 'license_last_check', date('Y-m-d H:i:s'));
        
        if ($is_valid) {
            mysqli_query($connection_server, "DELETE FROM sas_super_admin_options WHERE option_name='license_invalid_since'");
            $_SESSION["product_purchase_response"] = "✅ License key saved and verified successfully!";
        } else {
            // Only set if not already set, to prevent reset on subsequent checks
            $invalid_check = mysqli_query($connection_server, "SELECT option_value FROM sas_super_admin_options WHERE option_name='license_invalid_since' LIMIT 1");
            if ($invalid_check && mysqli_num_rows($invalid_check) == 0) {
                bc_store_license_option($connection_server, 'license_invalid_since', time());
            }
            $_SESSION["product_purchase_response"] = "⚠️ License saved, but status is INVALID. Please verify key/domain.";
        }

        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit();
    }

    if(isset($_POST["update-site-details"])){
		$site_title = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["site-title"])));
		$site_desc = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["site-desc"])));
	
		if(!empty($site_title) && !empty($site_desc)){
			$get_site_details = mysqli_query($connection_server, "SELECT * FROM sas_super_admin_site_details");
	
			if(mysqli_num_rows($get_site_details) == 1){
				mysqli_query($connection_server, "UPDATE sas_super_admin_site_details SET site_title='$site_title', site_desc='$site_desc'");
				//Site Information Updated Successfully
				$json_response_array = array("desc" => "Site Information Updated Successfully");
				$json_response_encode = json_encode($json_response_array,true);
			}else{
				if(mysqli_num_rows($get_site_details) == 0){
					mysqli_query($connection_server, "INSERT INTO sas_super_admin_site_details (site_title, site_desc) VALUES ('$site_title', '$site_desc')");
					//Site Information Created Successfully
					$json_response_array = array("desc" => "Site Information Created Successfully");
					$json_response_encode = json_encode($json_response_array,true);
				}else{
					if(mysqli_num_rows($get_site_details) > 1){
						//Duplicated Details, Contact Admin
						$json_response_array = array("desc" => "Duplicated Details, Contact Admin");
						$json_response_encode = json_encode($json_response_array,true);
					}
				}
			}
		}else{
			if(empty($site_title)){
				//Site Title Field Empty
				$json_response_array = array("desc" => "Site Title Field Empty");
				$json_response_encode = json_encode($json_response_array,true);
			}else{
				if(empty($site_desc)){
					//Site Desc Field Empty
					$json_response_array = array("desc" => "Site Description Field Empty");
					$json_response_encode = json_encode($json_response_array,true);
				}
			}
		}
	
		$json_response_decode = json_decode($json_response_encode,true);
		$_SESSION["product_purchase_response"] = $json_response_decode["desc"];
		header("Location: ".$_SERVER["REQUEST_URI"]);
	}

	$get_admin_payment_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_super_admin_payments LIMIT 1"));
	$get_admin_payment_order_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_super_admin_payment_orders LIMIT 1"));
	$get_site_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_super_admin_site_details LIMIT 1"));
    

?>
<!DOCTYPE html>
<head>
    <title></title>
    <meta charset="UTF-8" />
    <meta name="description" content="" />
    <meta http-equiv="Content-Type" content="text/html; " />
    <meta name="theme-color" content="black" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
    <link rel="stylesheet" href="/cssfile/bc-style.css">
    <meta name="author" content="Philmore Codes">
    <meta name="dc.creator" content="Philmore Codes">
        
      <!-- Vendor CSS Files -->
  <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="../assets-2/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
  <link href="../assets-2/vendor/quill/quill.snow.css" rel="stylesheet">
  <link href="../assets-2/vendor/quill/quill.bubble.css" rel="stylesheet">
  <link href="../assets-2/vendor/remixicon/remixicon.css" rel="stylesheet">
  <link href="../assets-2/vendor/simple-datatables/style.css" rel="stylesheet">

  <!-- Template Main CSS File -->
  <link href="../assets-2/css/style.css" rel="stylesheet">

</head>
<body>
    <?php include("../func/bc-spadmin-header.php"); ?>    
    <div class="pagetitle">
      <h1>ADMIN ACCOUNT SETTINGS</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="#">Home</a></li>
          <li class="breadcrumb-item active">Account Settings</li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
      <div class="row g-4">
        <!-- Sidebar Navigation for Settings -->
        <div class="col-lg-3">
            <div class="card shadow-sm border-0 rounded-4 sticky-top" style="top: 100px; z-index: 10;">
                <div class="card-body p-3">
                    <div class="nav flex-column nav-pills" id="v-pills-tab" role="tablist" aria-orientation="vertical">
                        <button class="nav-link active text-start mb-2 rounded-3 py-3" data-bs-toggle="pill" data-bs-target="#tab-branding"><i class="bi bi-palette me-2"></i> Branding</button>
                        <button class="nav-link text-start mb-2 rounded-3 py-3" data-bs-toggle="pill" data-bs-target="#tab-profile"><i class="bi bi-person-badge me-2"></i> Profile Info</button>
                        <button class="nav-link text-start mb-2 rounded-3 py-3" data-bs-toggle="pill" data-bs-target="#tab-finance"><i class="bi bi-bank me-2"></i> Bank & Payments</button>
                        <button class="nav-link text-start mb-2 rounded-3 py-3" data-bs-toggle="pill" data-bs-target="#tab-security"><i class="bi bi-shield-lock me-2"></i> Security</button>
                        <button class="nav-link text-start mb-2 rounded-3 py-3" data-bs-toggle="pill" data-bs-target="#tab-site"><i class="bi bi-globe me-2"></i> Site Details</button>
                        <button class="nav-link text-start rounded-3 py-3" data-bs-toggle="pill" data-bs-target="#tab-system"><i class="bi bi-cpu me-2"></i> System Tools</button>
                        <button class="nav-link text-start rounded-3 py-3" data-bs-toggle="pill" data-bs-target="#tab-whmcs"><i class="bi bi-globe2 me-2"></i> WHMCS API</button>
                        <button class="nav-link text-start rounded-3 py-3" data-bs-toggle="pill" data-bs-target="#tab-cpanel"><i class="bi bi-hdd-network me-2"></i> cPanel API</button>
                        <button class="nav-link text-start rounded-3 py-3" data-bs-toggle="pill" data-bs-target="#tab-printhub"><i class="bi bi-printer me-2"></i> Print Hub / API</button>
                        <button class="nav-link text-start rounded-3 py-3" data-bs-toggle="pill" data-bs-target="#tab-license"><i class="bi bi-key-fill me-2"></i> License Info</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Settings Content -->
        <div class="col-lg-9">
            <div class="tab-content" id="v-pills-tabContent">

                <!-- Branding Tab -->
                <div class="tab-pane fade show active" id="tab-branding">
                    <div class="card shadow-sm border-0 rounded-4 mb-4">
                        <div class="card-header bg-white py-3 border-0"><h5 class="fw-bold mb-0">Website Branding</h5></div>
                        <div class="card-body p-4 text-center">
                            <form method="post" enctype="multipart/form-data">
                                <div class="mb-4">
                                    <?php if(file_exists("../uploaded-image/sp-logo.png")): ?>
                                        <img src="<?php echo $web_http_host; ?>/uploaded-image/sp-logo.png" class="img-fluid rounded border p-2 bg-light mb-3" style="max-height: 100px;"/>
                                    <?php else: ?>
                                        <div class="bg-light rounded border p-4 mb-3 small text-muted">No Logo Image Found</div>
                                    <?php endif; ?>
                                    <label class="form-label d-block small fw-bold text-muted text-uppercase">Website Logo (PNG/JPG)</label>
                                </div>
                                <div class="row justify-content-center">
                                    <div class="col-md-8">
                                        <div class="input-group">
                                            <input name="logo" type="file" class="form-control" accept=".png,.jpg" required>
                                            <button name="change-logo" class="btn btn-primary fw-bold px-4" type="submit">Upload Logo</button>
                                        </div>
                                        <p class="small text-muted mt-2 mb-0">Recommended size: 500x200px. Max 2MB.</p>
                                    </div>
                                </div>
                            </form>
                            <hr class="my-4 opacity-50">
                            <form method="post">
                                <div class="row g-4">
                                    <div class="col-md-8">
                                        <label class="form-label small fw-bold text-muted text-uppercase">Landing Page Template</label>
                                        <select name="template-name" class="form-select rounded-3 mb-3">
                                            <?php
                                            $q_style = mysqli_query($connection_server, "SELECT * FROM sas_spadmin_style_templates LIMIT 1");
                                            $curr_style = mysqli_fetch_assoc($q_style);
                                            foreach($css_style_template_array as $index => $tmpl):
                                                $tmpl_css = $tmpl.".css";
                                                $selected = ($curr_style['template_name'] == $tmpl_css) ? 'selected' : '';
                                            ?>
                                                <option value="<?php echo $tmpl_css; ?>" <?php echo $selected; ?>><?php echo strtoupper(str_replace("-", " ", $tmpl)); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small fw-bold text-muted text-uppercase">Primary Theme Color</label>
                                        <input type="color" name="primary-color" value="<?php echo $curr_style['primary_color'] ?? '#0d6efd'; ?>" class="form-control form-control-color w-100 rounded-3 mb-3" title="Choose your primary color">
                                    </div>
                                </div>
                                <button name="update-template" class="btn btn-primary px-5 rounded-pill fw-bold">Save Theme Settings</button>
                            </form>
                        </div>
                    </div>

                    <div class="card shadow-sm border-0 rounded-4">
                        <div class="card-header bg-white py-3 border-0"><h5 class="fw-bold mb-0">Global Withdrawal Defaults (For Vendors)</h5></div>
                        <div class="card-body p-4">
                            <form method="post">
                                <?php
                                    $q_min = mysqli_query($connection_server, "SELECT option_value FROM sas_super_admin_options WHERE option_name='default_min_withdrawal'");
                                    $def_min = mysqli_fetch_assoc($q_min)['option_value'] ?? '1000';
                                    $q_max = mysqli_query($connection_server, "SELECT option_value FROM sas_super_admin_options WHERE option_name='default_max_withdrawal'");
                                    $def_max = mysqli_fetch_assoc($q_max)['option_value'] ?? '50000';
                                    $q_limit = mysqli_query($connection_server, "SELECT option_value FROM sas_super_admin_options WHERE option_name='default_daily_payout_limit'");
                                    $def_limit = mysqli_fetch_assoc($q_limit)['option_value'] ?? '10';
                                ?>
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label small fw-bold text-muted">DEF. MIN WITHDRAWAL (₦)</label>
                                        <input name="def_min" type="number" min="100" value="<?php echo $def_min; ?>" class="form-control rounded-3" required />
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small fw-bold text-muted">DEF. MAX WITHDRAWAL (₦)</label>
                                        <input name="def_max" type="number" value="<?php echo $def_max; ?>" class="form-control rounded-3" required />
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small fw-bold text-muted">DEF. DAILY PAYOUT LIMIT</label>
                                        <input name="def_limit" type="number" value="<?php echo $def_limit; ?>" class="form-control rounded-3" required />
                                    </div>
                                </div>
                                <div class="form-check mt-3">
                                    <input class="form-check-input" type="checkbox" name="apply_to_all" id="applyToAll">
                                    <label class="form-check-label small fw-bold text-danger" for="applyToAll">Apply these settings to ALL existing vendors immediately</label>
                                </div>
                                <button name="update-global-withdrawal-settings" class="btn btn-success mt-4 px-5 rounded-pill fw-bold">Save Global Defaults</button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Profile Tab -->
                <div class="tab-pane fade" id="tab-profile">
                    <div class="card shadow-sm border-0 rounded-4 mb-4">
                        <div class="card-header bg-white py-3 border-0"><h5 class="fw-bold mb-0">Personal Information</h5></div>
                        <div class="card-body p-4">
                            <form method="post">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-muted">FIRST NAME</label>
                                        <input name="first" type="text" value="<?php echo $get_logged_spadmin_details['firstname']; ?>" class="form-control rounded-3" required />
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-muted">LAST NAME</label>
                                        <input name="last" type="text" value="<?php echo $get_logged_spadmin_details['lastname']; ?>" class="form-control rounded-3" required />
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-muted">EMAIL ADDRESS</label>
                                        <input name="email" type="email" value="<?php echo $get_logged_spadmin_details['email']; ?>" class="form-control rounded-3" required />
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-muted">PHONE NUMBER</label>
                                        <input name="phone" type="text" value="<?php echo $get_logged_spadmin_details['phone_number']; ?>" class="form-control rounded-3" required />
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label small fw-bold text-muted">HOME ADDRESS</label>
                                        <input name="address" type="text" value="<?php echo $get_logged_spadmin_details['home_address']; ?>" class="form-control rounded-3" required />
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label small fw-bold text-danger">CONFIRM PASSWORD TO SAVE CHANGES</label>
                                        <input name="pass" type="password" class="form-control rounded-3 border-danger" placeholder="Enter current password" required />
                                    </div>
                                </div>
                                <button name="update-profile" class="btn btn-primary mt-4 px-5 rounded-pill fw-bold">Update Profile Info</button>
                            </form>
                        </div>
                    </div>

                    <div class="card shadow-sm border-0 rounded-4">
                        <div class="card-header bg-white py-3 border-0"><h5 class="fw-bold mb-0">Change Account Password</h5></div>
                        <div class="card-body p-4">
                            <form method="post">
                                <div class="row g-3">
                                    <div class="col-md-12">
                                        <label class="form-label small fw-bold text-muted">OLD PASSWORD</label>
                                        <input name="old-pass" type="password" class="form-control rounded-3" required />
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-muted">NEW PASSWORD</label>
                                        <input name="new-pass" type="password" class="form-control rounded-3" required />
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-muted">CONFIRM NEW PASSWORD</label>
                                        <input name="con-new-pass" type="password" class="form-control rounded-3" required />
                                    </div>
                                </div>
                                <button name="change-password" class="btn btn-outline-danger mt-4 px-5 rounded-pill fw-bold">Update Password</button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Finance Tab -->
                <div class="tab-pane fade" id="tab-finance">
                    <div class="card shadow-sm border-0 rounded-4 mb-4">
                        <div class="card-header bg-white py-3 border-0"><h5 class="fw-bold mb-0">Super Admin Bank Details</h5></div>
                        <div class="card-body p-4">
                            <form method="post">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-muted">ACCOUNT NAME</label>
                                        <input name="name" type="text" value="<?php echo $get_admin_payment_details['account_name'] ?? ''; ?>" class="form-control rounded-3" required />
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-muted">BANK NAME</label>
                                        <input name="bank" type="text" value="<?php echo $get_admin_payment_details['bank_name'] ?? ''; ?>" class="form-control rounded-3" required />
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-muted">ACCOUNT NUMBER</label>
                                        <input name="number" type="text" value="<?php echo $get_admin_payment_details['account_number'] ?? ''; ?>" class="form-control rounded-3" required />
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-muted">SUPPORT PHONE</label>
                                        <input name="phone" type="text" value="<?php echo $get_admin_payment_details['phone_number'] ?? ''; ?>" class="form-control rounded-3" required />
                                    </div>
                                    <div class="col-md-12">
                                        <label class="form-label small fw-bold text-muted">MANUAL FUNDING CHARGES (₦)</label>
                                        <input name="charges" type="text" value="<?php echo $get_admin_payment_details['amount_charged'] ?? '0'; ?>" class="form-control rounded-3" required />
                                    </div>
                                </div>
                                <button name="update-bank-details" class="btn btn-primary mt-4 px-5 rounded-pill fw-bold">Save Bank Info</button>
                            </form>
                        </div>
                    </div>

                    <div class="card shadow-sm border-0 rounded-4">
                        <div class="card-header bg-white py-3 border-0"><h5 class="fw-bold mb-0">Payment Order Limits</h5></div>
                        <div class="card-body p-4">
                            <form method="post">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-muted">MINIMUM ORDER (₦)</label>
                                        <input name="min" type="text" value="<?php echo $get_admin_payment_order_details['min_amount'] ?? '0'; ?>" class="form-control rounded-3" required />
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-muted">MAXIMUM ORDER (₦)</label>
                                        <input name="max" type="text" value="<?php echo $get_admin_payment_order_details['max_amount'] ?? '0'; ?>" class="form-control rounded-3" required />
                                    </div>
                                </div>
                                <button name="update-payment-order-details" class="btn btn-primary mt-4 px-5 rounded-pill fw-bold">Update Order Limits</button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Security Tab -->
                <div class="tab-pane fade" id="tab-security">
                    <div class="card shadow-sm border-0 rounded-4">
                        <div class="card-header bg-white py-3 border-0"><h5 class="fw-bold mb-0">Global Security Policy</h5></div>
                        <div class="card-body p-4">
                            <form method="post">
                                <?php
                                $opt_q = mysqli_query($connection_server, "SELECT option_value FROM sas_super_admin_options WHERE option_name='force_vendor_pin'");
                                $force_vendor_pin = mysqli_fetch_assoc($opt_q)['option_value'] ?? '0';

                                $opt_email = mysqli_query($connection_server, "SELECT option_value FROM sas_super_admin_options WHERE option_name='spadmin_trans_email_enabled'");
                                $spadmin_trans_email = mysqli_fetch_assoc($opt_email)['option_value'] ?? '1';

                                $opt_login_otp = mysqli_query($connection_server, "SELECT option_value FROM sas_super_admin_options WHERE option_name='force_bcadmin_login_otp'");
                                $force_bcadmin_login_otp = mysqli_fetch_assoc($opt_login_otp)['option_value'] ?? '0';
                                ?>
                                <div class="bg-primary bg-opacity-10 p-4 rounded-4 border border-primary border-opacity-25 mb-4">
                                    <div class="form-check form-switch d-flex align-items-center justify-content-between ps-0 mb-3">
                                        <div>
                                            <label class="form-check-label fw-bold h6 mb-1 text-dark-primary" for="forcePin">Force Vendor Security PIN</label>
                                            <p class="text-dark-primary small mb-0" style="opacity: 0.8;">When enabled, all Vendors (bc-admin) MUST configure and use a Security PIN for sensitive dashboard operations.</p>
                                        </div>
                                        <input type="checkbox" name="force_vendor_pin" class="form-check-input ms-0" id="forcePin" style="width: 3.5rem; height: 1.75rem;" <?php echo ($force_vendor_pin == '1') ? 'checked' : ''; ?>>
                                    </div>
                                    <div class="form-check form-switch d-flex align-items-center justify-content-between ps-0">
                                        <div>
                                            <label class="form-check-label fw-bold h6 mb-1 text-dark-primary" for="forceSpadminEmail">Transaction Email Notification</label>
                                            <p class="text-dark-primary small mb-0" style="opacity: 0.8;">Toggle off to stop receiving email notifications for every vendor and user transaction.</p>
                                        </div>
                                        <input type="checkbox" name="force_spadmin_trans_email" class="form-check-input ms-0" id="forceSpadminEmail" style="width: 3.5rem; height: 1.75rem;" <?php echo ($spadmin_trans_email == '1') ? 'checked' : ''; ?>>
                                    </div>
                                    <div class="form-check form-switch d-flex align-items-center justify-content-between ps-0 mt-3">
                                        <div>
                                            <label class="form-check-label fw-bold h6 mb-1 text-dark-primary" for="forceLoginOtp">Require Login OTP for Vendors (bc-admin)</label>
                                            <p class="text-dark-primary small mb-0" style="opacity: 0.8;">When enabled, every vendor admin must enter a one-time code emailed to their address each time they sign in to bc-admin. A vendor can switch it off for their own account only; that does not affect other vendors.</p>
                                        </div>
                                        <input type="checkbox" name="force_bcadmin_login_otp" class="form-check-input ms-0" id="forceLoginOtp" style="width: 3.5rem; height: 1.75rem;" <?php echo ($force_bcadmin_login_otp == '1') ? 'checked' : ''; ?>>
                                    </div>
                                </div>

                                <div class="card border border-primary border-opacity-25 rounded-4 shadow-none mb-4">
                                    <div class="card-body p-4">
                                        <h6 class="fw-bold mb-3"><i class="bi bi-shield-lock-fill me-2 text-primary"></i>My Super Admin Security PIN</h6>
                                        <p class="text-muted small">This 4-digit PIN is used to unblock your account or IP address if you are ever locked out by the security system.</p>
                                        <div class="row align-items-end">
                                            <div class="col-md-6">
                                                <label class="form-label small fw-bold text-muted">SET NEW 4-DIGIT PIN</label>
                                                <input type="password" name="my_pin" class="form-control form-control-lg text-center fw-bold" maxlength="4" pattern="\d{4}" inputmode="numeric" placeholder="****" style="letter-spacing: 10px; font-size: 24px;">
                                            </div>
                                            <div class="col-md-6">
                                                <div class="alert alert-info py-2 small mb-0 border-0 rounded-3">
                                                    <i class="bi bi-info-circle me-1"></i> Keep this PIN safe. It is your ultimate recovery key.
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="card border border-primary border-opacity-25 rounded-4 shadow-none mb-4">
                                    <div class="card-body p-4">
                                        <h6 class="fw-bold mb-3"><i class="bi bi-envelope-at me-2 text-primary"></i>Global Platform SMTP (System Fallback)</h6>
                                        <p class="text-muted small">Configure the default SMTP server used for all system notifications when vendors have not set their own.</p>
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label class="form-label small fw-bold">SMTP HOST</label>
                                                <input name="smtp_host" type="text" value="<?php echo $get_logged_spadmin_details['smtp_host'] ?? ''; ?>" class="form-control" placeholder="mail.server.com" />
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small fw-bold">SMTP USERNAME</label>
                                                <input name="smtp_user" type="text" value="<?php echo $get_logged_spadmin_details['smtp_user'] ?? ''; ?>" class="form-control" placeholder="notification@server.com" />
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label small fw-bold">SMTP PASSWORD</label>
                                                <input name="smtp_pass" type="password" value="<?php echo $get_logged_spadmin_details['smtp_pass'] ?? ''; ?>" class="form-control" placeholder="******" />
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label small fw-bold">SMTP PORT</label>
                                                <input name="smtp_port" type="text" value="<?php echo $get_logged_spadmin_details['smtp_port'] ?? ''; ?>" class="form-control" placeholder="465" />
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label small fw-bold">SECURITY</label>
                                                <select name="smtp_sec" class="form-select">
                                                    <option value="">None</option>
                                                    <option value="ssl" <?php echo (($get_logged_spadmin_details['smtp_sec'] ?? '') == 'ssl') ? 'selected' : ''; ?>>SSL</option>
                                                    <option value="tls" <?php echo (($get_logged_spadmin_details['smtp_sec'] ?? '') == 'tls') ? 'selected' : ''; ?>>TLS</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="card border border-primary border-opacity-25 rounded-4 shadow-none mb-4">
                                    <div class="card-body p-4">
                                        <h6 class="fw-bold mb-3"><i class="bi bi-arrow-repeat me-2 text-primary"></i>Password Reset Limit</h6>
                                        <p class="text-muted small">Maximum number of times an account password can be reset per day (web users, admins and the mobile app). Default is 1 — increase it to allow more resets in a single day.</p>
                                        <?php
                                        $opt_reset = mysqli_query($connection_server, "SELECT option_value FROM sas_super_admin_options WHERE option_name='password_reset_daily_limit'");
                                        $reset_daily_limit_val = (int)(mysqli_fetch_assoc($opt_reset)['option_value'] ?? 1);
                                        if ($reset_daily_limit_val < 1) $reset_daily_limit_val = 1;
                                        ?>
                                        <div class="col-md-4">
                                            <label class="form-label small fw-bold">DAILY PASSWORD RESET LIMIT</label>
                                            <input name="password_reset_daily_limit" type="number" min="1" value="<?php echo $reset_daily_limit_val; ?>" class="form-control" />
                                        </div>
                                    </div>
                                </div>

                                <button name="update-security" class="btn btn-primary px-5 rounded-pill fw-bold py-2 shadow-sm">Apply Security Policy</button>
                            </form>

                            <!-- Demo Mode card deliberately lives OUTSIDE the security policy form element above:
                                 it owns its own forms, and a nested form element's closing tag would close
                                 the outer form early and orphan the Apply Security Policy button. -->
                            <?php $demo_state = bc_demo_state($connection_server); ?>
                            <div class="card border border-warning border-opacity-50 rounded-4 shadow-none mb-4 mt-4">
                                <div class="card-body p-4">
                                    <h6 class="fw-bold mb-2"><i class="bi bi-toggle2-on me-2 text-warning"></i>Website Demo Mode</h6>
                                    <p class="text-muted small">Demo mode snapshots the locked site settings, APIs, service controls, vendor settings and templates. Testers may change them temporarily; switching back to Production restores the snapshot.</p>
                                    <?php if (!bc_demo_has_lock_key($connection_server)): ?>
                                        <div class="alert alert-warning small">Set the security lock key before changing Demo/Production mode. This key is separate from your login password and is required to restore Production.</div>
                                        <form method="post" class="row g-2">
                                            <div class="col-md-5"><input type="password" name="demo_lock_key" class="form-control" minlength="10" placeholder="New security lock key" required></div>
                                            <div class="col-md-5"><input type="password" name="demo_lock_key_confirm" class="form-control" minlength="10" placeholder="Confirm lock key" required></div>
                                            <div class="col-md-2"><button name="set-demo-lock-key" class="btn btn-warning w-100 fw-bold">Save Key</button></div>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" class="row g-2 align-items-end">
                                            <div class="col-md-3"><label class="form-label small fw-bold">CURRENT MODE</label><div class="form-control bg-light fw-bold text-uppercase"><?php echo htmlspecialchars($demo_state['mode']); ?></div></div>
                                            <div class="col-md-3"><label class="form-label small fw-bold">CHANGE TO</label><select name="demo_mode" class="form-select"><option value="demo" <?php echo $demo_state['mode'] === 'demo' ? 'selected' : ''; ?>>Demo</option><option value="production" <?php echo $demo_state['mode'] === 'production' ? 'selected' : ''; ?>>Production</option></select></div>
                                            <div class="col-md-4"><label class="form-label small fw-bold">SECURITY LOCK KEY</label><input type="password" name="demo_lock_key" class="form-control" placeholder="Enter lock key" required></div>
                                            <div class="col-md-2"><button name="change-demo-mode" class="btn btn-warning w-100 fw-bold">Apply</button></div>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Site Details Tab -->
                <div class="tab-pane fade" id="tab-site">
                    <div class="card shadow-sm border-0 rounded-4">
                        <div class="card-header bg-white py-3 border-0"><h5 class="fw-bold mb-0">Global Site Settings</h5></div>
                        <div class="card-body p-4">
                            <form method="post">
                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted text-uppercase">Site Title</label>
                                    <input name="site-title" type="text" value="<?php echo $get_site_details['site_title'] ?? ''; ?>" class="form-control rounded-3" required />
                                </div>
                                <div class="mb-4">
                                    <label class="form-label small fw-bold text-muted text-uppercase">Site Meta Description</label>
                                    <textarea name="site-desc" class="form-control rounded-3" rows="4" required><?php echo $get_site_details['site_desc'] ?? ''; ?></textarea>
                                </div>
                                <button name="update-site-details" class="btn btn-primary px-5 rounded-pill fw-bold">Save Site Details</button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- WHMCS API Tab -->
                <div class="tab-pane fade" id="tab-whmcs">
                    <div class="card shadow-sm border-0 rounded-4">
                        <div class="card-header bg-white py-3 border-0"><h5 class="fw-bold mb-0">WHMCS API Configuration</h5></div>
                        <div class="card-body p-4">
                            <form method="post">
                                <?php
                                $whmcs_url = getSuperAdminOption('whmcs_api_url');
                                $whmcs_ident = getSuperAdminOption('whmcs_api_ident');
                                $whmcs_secret = getSuperAdminOption('whmcs_api_secret');
                                ?>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted">WHMCS API URL</label>
                                    <input name="whmcs_api_url" type="url" value="<?php echo $whmcs_url; ?>" class="form-control rounded-3" placeholder="https://client.philmorehost.com/includes/api.php" required />
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted">API IDENTIFIER</label>
                                    <input name="whmcs_api_ident" type="text" value="<?php echo $whmcs_ident; ?>" class="form-control rounded-3" placeholder="API Access Identifier" required />
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted">API SECRET</label>
                                    <input name="whmcs_api_secret" type="password" value="<?php echo $whmcs_secret; ?>" class="form-control rounded-3" placeholder="API Access Secret" required />
                                </div>
                                <div class="row g-3 mb-4">
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-muted">DEFAULT DOMAIN PRICE (₦)</label>
                                        <input name="whmcs_domain_price" type="number" value="<?php echo getSuperAdminOption('default_domain_registration_price', '5000'); ?>" class="form-control rounded-3" required />
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-muted">DEFAULT WHMCS CLIENT ID</label>
                                        <input name="whmcs_client_id" type="number" value="<?php echo getSuperAdminOption('whmcs_default_client_id', '1'); ?>" class="form-control rounded-3" required />
                                    </div>
                                </div>
                                <button name="update-whmcs-settings" class="btn btn-primary px-5 rounded-pill fw-bold">Save WHMCS Settings</button>
                            </form>
                        </div>
                    </div>
                </div>


<!-- cPanel API Tab -->
                <div class="tab-pane fade" id="tab-cpanel">
                    <div class="card shadow-sm border-0 rounded-4">
                        <div class="card-header bg-white py-3 border-0"><h5 class="fw-bold mb-0">cPanel API Configuration</h5></div>
                        <div class="card-body p-4">
                            <div class="alert alert-info border-0 rounded-4 p-3 mb-4 small">
                                <i class="bi bi-info-circle-fill me-2"></i> This integration allows automatic Addon Domain creation when a vendor's website domain is approved or changed. Ensure your cPanel API token has the necessary privileges.
                            </div>
                            <form method="post">
                                <?php
                                $cp_host = getSuperAdminOption('cpanel_host');
                                $cp_user = getSuperAdminOption('cpanel_username');
                                $cp_token = getSuperAdminOption('cpanel_api_token');
                                ?>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted">CPANEL HOSTNAME OR IP</label>
                                    <input name="cpanel_host" type="text" value="<?php echo $cp_host; ?>" class="form-control rounded-3" placeholder="e.g. 192.168.1.1 or server.example.com" required />
                                </div>
                                <div class="row g-3 mb-4">
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-muted">CPANEL USERNAME</label>
                                        <input name="cpanel_username" type="text" value="<?php echo $cp_user; ?>" class="form-control rounded-3" placeholder="e.g. cpuser" required />
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-muted">API TOKEN</label>
                                        <input name="cpanel_api_token" type="password" value="<?php echo $cp_token; ?>" class="form-control rounded-3" placeholder="API Token" required />
                                    </div>
                                </div>
                                <button name="update-cpanel-settings" class="btn btn-primary px-5 rounded-pill fw-bold">Save cPanel Settings</button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Print Hub Tab -->
                <div class="tab-pane fade" id="tab-printhub">
                    <div class="card shadow-sm border-0 rounded-4 mb-4">
                        <div class="card-header bg-white py-3 border-0"><h5 class="fw-bold mb-0">Print Hub Automation (Android Bridge)</h5></div>
                        <div class="card-body p-4">
                            <?php
                            $get_sec = mysqli_fetch_array(mysqli_query($connection_server, "SELECT option_value FROM sas_super_admin_options WHERE option_name='print_hub_secret'"));
                            $ph_secret = $get_sec['option_value'] ?? 'NOT_CONFIGURED';
                            ?>
                            <div class="alert alert-primary border-0 rounded-4 p-4 mb-4">
                                <h6 class="fw-bold mb-3"><i class="bi bi-robot me-2"></i>Automation Endpoint</h6>
                                <div class="mb-3">
                                    <label class="small fw-bold text-muted text-uppercase mb-1">Webhook URL Path</label>
                                    <div class="input-group">
                                        <input type="text" class="form-control bg-white" value="<?php echo $web_http_host; ?>/web/api/endpoint.php" readonly id="webhookUrl">
                                        <button class="btn btn-outline-primary" type="button" onclick="copyText('Webhook URL Copied', document.getElementById('webhookUrl').value)"><i class="bi bi-clipboard"></i></button>
                                    </div>
                                </div>
                            <form method="post">
                                <div class="mb-4">
                                    <label class="small fw-bold text-muted text-uppercase mb-1">Print Hub Secret Token</label>
                                    <div class="input-group">
                                        <input type="text" name="ph_secret" class="form-control" value="<?php echo ($ph_secret === 'NOT_CONFIGURED') ? md5($_SERVER['HTTP_HOST'] . 'PRINT_HUB_SECRET') : $ph_secret; ?>" id="webhookSecret">
                                        <button class="btn btn-outline-primary" type="button" onclick="copyText('Secret Token Copied', document.getElementById('webhookSecret').value)"><i class="bi bi-clipboard"></i></button>
                                    </div>
                                    <p class="small text-primary mt-2 mb-0">Use this secret in your Android App settings to authorize SMS-to-Web requests.</p>
                                    </div>
                                <button name="update-printhub-settings" class="btn btn-primary px-5 rounded-pill fw-bold">Save Automation Settings</button>
                            </form>
                            </div>

                            <h6 class="fw-bold mb-3">Implementation Details</h6>
                            <p class="small text-muted">Send a <strong>POST</strong> request to the Webhook URL with the following JSON body:</p>
                            <div class="bg-dark rounded-3 p-3 mb-4">
                                <code class="text-info small">
                                    {<br>
                                    &nbsp;&nbsp;"action": "RECEIVE_SMS",<br>
                                    &nbsp;&nbsp;"secret": "<?php echo $ph_secret; ?>",<br>
                                    &nbsp;&nbsp;"sender": "08012345678",<br>
                                    &nbsp;&nbsp;"message": "EPIN*EXTRA_DATA"<br>
                                    }
                                </code>
                            </div>
                            <p class="small text-muted"><strong>Message Formats:</strong></p>
                            <ul class="small text-muted">
                                <li><strong>Airtime/Data:</strong> Just the EPIN (e.g. <code>5002-0648-4709</code>)</li>
                                <li><strong>Cable/Electric/Betting:</strong> <code>EPIN*IDENTIFIER</code> (e.g. <code>5002...*44001234567</code>)</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- System Tools Tab -->
                <div class="tab-pane fade" id="tab-system">
                    <div class="card shadow-sm border-0 rounded-4 mb-4">
                        <div class="card-header bg-white py-3 border-0"><h5 class="fw-bold mb-0">Temporary Vendor Access</h5></div>
                        <div class="card-body p-4">
                            <form method="post">
                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted">TEMPORARY VENDOR DOMAIN</label>
                                    <input name="temporary_vendor_domain" type="text" value="<?php echo htmlspecialchars(getSuperAdminOption('temporary_vendor_domain', ''), ENT_QUOTES, 'UTF-8'); ?>" class="form-control rounded-3" placeholder="temporary.example.com" />
                                    <div class="form-text small">Optional. Create a separate subdomain, point it to this site's files, enable HTTPS, and enter the hostname here. Vendor portal links will use this domain instead of your primary website. Leave blank to disable temporary vendor access.</div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted">EXPIRY DURATION (DAYS)</label>
                                    <input name="tmp_url_expiry_days" type="number" min="1" max="30" value="<?php echo getSuperAdminOption('tmp_url_expiry_days', '3'); ?>" class="form-control rounded-3" required />
                                    <div class="form-text small">Number of days vendors can use their token-protected temporary admin link after approval. Settings saved there are stored for the vendor's own website.</div>
                                </div>
                                <button name="update-tmp-url-settings" class="btn btn-primary px-5 rounded-pill fw-bold">Save Setting</button>
                            </form>
                        </div>
                    </div>

                    <div class="card shadow-sm border-0 rounded-4 mb-4">
                        <div class="card-header bg-white py-3 border-0"><h5 class="fw-bold mb-0">App Service Pricing (Optional Add-ons)</h5></div>
                        <div class="card-body p-4">
                            <form method="post">
                                <div class="row g-3 mb-4">
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-muted">ANDROID APK PRICE (₦)</label>
                                        <input name="apk_price" type="number" value="<?php echo getSuperAdminOption('apk_development_price', '25000'); ?>" class="form-control rounded-3" required />
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-muted">IOS APP PRICE (₦)</label>
                                        <input name="ios_price" type="number" value="<?php echo getSuperAdminOption('ios_development_price', '50000'); ?>" class="form-control rounded-3" required />
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-muted">PLAY STORE LISTING (₦)</label>
                                        <input name="playstore_price" type="number" value="<?php echo getSuperAdminOption('playstore_listing_price', '15000'); ?>" class="form-control rounded-3" required />
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-muted">SMS BRIDGE APK (₦)</label>
                                        <input name="sms_bridge_price" type="number" value="<?php echo getSuperAdminOption('sms_bridge_price', '10000'); ?>" class="form-control rounded-3" required />
                                    </div>
                                </div>
                                <button name="update-app-services" class="btn btn-primary px-5 rounded-pill fw-bold">Save App Prices</button>
                            </form>
                        </div>
                    </div>

                    <div class="card shadow-sm border-0 rounded-4 mb-4">
                        <div class="card-header bg-white py-3 border-0"><h5 class="fw-bold mb-0">Cron Jobs</h5></div>
                        <div class="card-body p-4">
                            <div class="bg-light p-4 rounded-4 border mb-4">
                                <div class="d-flex align-items-center mb-3">
                                    <div class="bg-primary bg-opacity-10 p-2 rounded-3 me-3">
                                        <i class="bi bi-clock-history text-primary fs-4"></i>
                                    </div>
                                    <h6 class="fw-bold mb-0">Platform Cron Jobs</h6>
                                </div>
                                <p class="small text-muted mb-3">All platform cron jobs — requery, bulk queue, crypto, notifications, subscriptions, updates and AI automation — are managed in one place with their schedules and copyable crontab entries.</p>
                                <a href="CronJobs.php" class="btn btn-primary rounded-pill fw-bold px-4 py-2">
                                    <i class="bi bi-clock-history me-1"></i> Manage Cron Jobs
                                </a>
                            </div>

                            <div class="bg-light p-4 rounded-4 border mt-4">
                                <div class="d-flex align-items-center mb-3">
                                    <div class="bg-danger bg-opacity-10 p-2 rounded-3 me-3">
                                        <i class="bi bi-trash text-danger fs-4"></i>
                                    </div>
                                    <h6 class="fw-bold mb-0">System Tools & Maintenance</h6>
                                </div>
                                <p class="small text-muted mb-4">Safely optimize SAAS database tables, clean update staging files, delete redundant log files, and remove old zip backups.</p>

                                <a href="cleanup_platform.php" target="_blank" class="btn btn-outline-danger btn-sm rounded-pill fw-bold px-4 py-2">
                                    <i class="bi bi-trash-fill me-1"></i> Launch Platform Cleanup Utility
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- License Tab -->
                <div class="tab-pane fade" id="tab-license">
                    <div class="card shadow-sm border-0 rounded-4 mb-4">
                        <div class="card-header bg-white py-3 border-0"><h5 class="fw-bold mb-0">Product License Information</h5></div>
                        <div class="card-body p-4">
                            <?php
                            $lic_key = getSuperAdminOption('license_key', '');
                            $lic_domain = getSuperAdminOption('license_domain', $_SERVER['HTTP_HOST']);
                            $lic_status = getSuperAdminOption('license_status', 'invalid');
                            $lic_last_check = getSuperAdminOption('license_last_check', 'Never');
                            ?>
                            
                            <!-- License Signal / Status Card -->
                            <div class="alert <?php echo ($lic_status === 'valid') ? 'alert-success' : 'alert-danger'; ?> border-0 rounded-4 p-4 mb-4 d-flex align-items-center justify-content-between flex-wrap gap-3" id="licenseStatusBanner">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="rounded-circle bg-white bg-opacity-25 d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                                        <i class="bi <?php echo ($lic_status === 'valid') ? 'bi-shield-check-fill text-success' : 'bi-shield-x-fill text-danger'; ?> fs-3" id="licenseStatusIcon"></i>
                                    </div>
                                    <div>
                                        <h6 class="fw-bold mb-1" id="licenseStatusTitle">License is <?php echo ($lic_status === 'valid') ? 'Active & Valid' : 'Invalid / Inactive'; ?></h6>
                                        <span class="small opacity-75" id="licenseStatusMessage">Last checked: <?php echo $lic_last_check; ?></span>
                                    </div>
                                </div>
                                <button type="button" class="btn btn-light btn-sm rounded-pill px-3 fw-bold shadow-sm d-flex align-items-center gap-2" onclick="refreshLicenseStatus(this)">
                                    <span class="spinner-border spinner-border-sm d-none" role="status"></span>
                                    <i class="bi bi-arrow-clockwise"></i> Refresh Status
                                </button>
                            </div>

                            <form method="post">
                                <div class="row g-3 mb-4">
                                    <div class="col-md-12">
                                        <label class="form-label small fw-bold text-muted text-uppercase mb-1">License Activation Key</label>
                                        <input name="license_key" type="text" value="<?php echo $lic_key; ?>" class="form-control rounded-3 font-monospace" placeholder="DGV7-XXXX-XXXX-XXXX" required />
                                    </div>
                                    <div class="col-md-12 mt-3">
                                        <label class="form-label small fw-bold text-muted text-uppercase mb-1">Licensed Domain Name</label>
                                        <input name="license_domain" type="text" value="<?php echo $lic_domain; ?>" class="form-control rounded-3" placeholder="<?php echo $_SERVER['HTTP_HOST']; ?>" required />
                                        <p class="small text-muted mt-2 mb-0">Make sure this domain matches the domain associated with the license on the <a href="https://manager.pmhserver.name.ng" target="_blank" class="text-primary fw-bold text-decoration-underline">Licensing API Server</a>.</p>
                                    </div>
                                </div>
                                <button name="update-license" class="btn btn-primary px-5 rounded-pill fw-bold">Update License Key</button>
                            </form>
                        </div>
                    </div>
                </div>

                <script>
                function refreshLicenseStatus(btn) {
                    const spinner = btn.querySelector('.spinner-border');
                    const icon = btn.querySelector('i');
                    
                    btn.disabled = true;
                    if (spinner) spinner.classList.remove('d-none');
                    if (icon) icon.classList.add('d-none');
                    
                    fetch('?refresh_license=1')
                    .then(res => res.json())
                    .then(data => {
                        if (data.status === 'success') {
                            const banner = document.getElementById('licenseStatusBanner');
                            const statusIcon = document.getElementById('licenseStatusIcon');
                            const statusTitle = document.getElementById('licenseStatusTitle');
                            const statusMsg = document.getElementById('licenseStatusMessage');
                            
                            if (data.is_valid) {
                                banner.className = "alert alert-success border-0 rounded-4 p-4 mb-4 d-flex align-items-center justify-content-between flex-wrap gap-3";
                                statusIcon.className = "bi bi-shield-check-fill text-success fs-3";
                                statusTitle.innerText = "License is Active & Valid";
                            } else {
                                banner.className = "alert alert-danger border-0 rounded-4 p-4 mb-4 d-flex align-items-center justify-content-between flex-wrap gap-3";
                                statusIcon.className = "bi bi-shield-x-fill text-danger fs-3";
                                statusTitle.innerText = "License is Invalid / Inactive";
                            }
                            statusMsg.innerHTML = `${data.message}<br/><small class="opacity-75">Last checked: ${data.last_check}</small>`;
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        alert('Failed to refresh license status. Please check connection.');
                    })
                    .finally(() => {
                        btn.disabled = false;
                        if (spinner) spinner.classList.add('d-none');
                        if (icon) icon.classList.remove('d-none');
                    });
                }
                </script>

            </div>
        </div>
      </div>
    </section>
        
    <?php include("../func/bc-spadmin-footer.php"); ?>
    
</body>
</html>

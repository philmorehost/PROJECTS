<?php session_start();
include ("../func/bc-admin-config.php");
include_once(__DIR__ . "/../func/bc-integrity.php");

// Demo-mode blocked feature notice
if (isset($_GET['demo_blocked']) && bc_is_demo_mode($connection_server)) {
    $blocked_feature = htmlspecialchars($_GET['feature'] ?? 'This feature');
    echo '<div class="alert alert-danger text-center m-3" role="alert">
            <i class="bi bi-lock-fill me-2"></i>
            <strong>Demo Mode:</strong> ' . $blocked_feature . ' is locked.
            Contact your administrator to enable this feature.
          </div>';
}

// Used by the Developer tab's mail-queue cron entry below.
$mail_queue_batch_size = (int)getSuperAdminOption('mail_queue_batch_size', 5);
$mail_queue_cron_secret = bc_get_or_create_cron_secret($connection_server);

// Demo Mode: unlock / relock settings editing for this session with the Super Admin
// Demo security lock key (see func/bc-demo-mode.php). In Demo Mode the page stays
// fully viewable but is read-only until the key is entered, letting the site owner
// edit while prospects remain view-only.
if (isset($_POST['demo-unlock-session'])) {
    $_SESSION["product_purchase_response"] = bc_demo_grant_edit_unlock($connection_server, trim($_POST['demo_unlock_key'] ?? ''))
        ? 'Demo editing unlocked for this session — you can now save changes.'
        : 'Incorrect Demo security lock key. Website settings remain read-only.';
    header('Location: ' . ($_SERVER['REQUEST_URI'] ?? 'AccountSettings.php'));
    exit;
}
if (isset($_POST['demo-relock-session'])) {
    bc_demo_revoke_edit_unlock();
    $_SESSION["product_purchase_response"] = 'Demo editing locked again for this session.';
    header('Location: ' . ($_SERVER['REQUEST_URI'] ?? 'AccountSettings.php'));
    exit;
}

// AJAX: quick PIN-only save for the forced-setup modal (func/bc-admin-header.php).
// Deliberately touches ONLY security_pin — the full "update-security-settings"
// handler further down also writes SMTP/2FA/SSO fields together in one UPDATE,
// which would blank those out if fired from a PIN-only modal submission.
if (isset($_POST['action']) && $_POST['action'] === 'quick_set_pin') {
    header('Content-Type: application/json');
    $new_pin = trim($_POST['admin_pin'] ?? '');
    $con_pin = trim($_POST['admin_pin_con'] ?? '');
    if (!preg_match('/^\d{4}$/', $new_pin)) {
        echo json_encode(['ok' => false, 'msg' => 'PIN must be exactly 4 digits.']);
        exit();
    }
    if ($new_pin !== $con_pin) {
        echo json_encode(['ok' => false, 'msg' => 'PIN and Confirm PIN do not match.']);
        exit();
    }
    $hashed_pin_esc = mysqli_real_escape_string($connection_server, password_hash($new_pin, PASSWORD_DEFAULT));
    mysqli_query($connection_server, "UPDATE sas_vendors SET security_pin='$hashed_pin_esc' WHERE id='" . $get_logged_admin_details["id"] . "'");
    echo json_encode(['ok' => true, 'msg' => 'Security PIN set successfully!']);
    exit();
}

if (isset($_POST['set-mail-batch-size'])) {
    // Existence-checked UPDATE/INSERT rather than INSERT ... ON DUPLICATE KEY UPDATE:
    // sas_super_admin_options has an older schema variant in the wild without a unique
    // constraint on option_name (see the license_key/license_domain fix in
    // DGV7.0-SAAS/func/bc-levelup.php — that file doesn't exist in this edition, so this
    // stays inlined here rather than depending on a helper only the other edition has).
    $new_batch_size = max(1, min(100, (int)($_POST['mail_queue_batch_size'] ?? 5)));
    $existing = mysqli_query($connection_server, "SELECT option_name FROM sas_super_admin_options WHERE option_name='mail_queue_batch_size' LIMIT 1");
    if ($existing && mysqli_num_rows($existing) > 0) {
        mysqli_query($connection_server, "UPDATE sas_super_admin_options SET option_value='$new_batch_size' WHERE option_name='mail_queue_batch_size'");
    } else {
        mysqli_query($connection_server, "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('mail_queue_batch_size', '$new_batch_size')");
    }
    $_SESSION["product_purchase_response"] = "Mail queue batch size updated to $new_batch_size per run.";
    header("Location: " . $_SERVER["REQUEST_URI"] . "#tab-developer");
    exit;
}

$license_code = bc_read_activation();
$license_domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
$license_valid = bc_verify_integrity();
$license_status_label = $license_valid ? 'Valid' : 'Invalid';
$license_status_class = $license_valid ? 'success' : 'danger';

if (isset($_POST["save-license-key"])) {
    $new_license_key = trim($_POST["license_key"] ?? '');
    if (empty($new_license_key)) {
        $_SESSION["product_purchase_response"] = "Please enter a license key to save.";
    } else {
        bc_clear_integrity_cache();
        if (bc_validate_activation_code($new_license_key, $license_domain)) {
            if (bc_write_activation($new_license_key)) {
                bc_write_integrity_cache(true);
                $_SESSION["product_purchase_response"] = "License key updated and validated successfully.";
                $license_code = $new_license_key;
                $license_valid = true;
                $license_status_label = 'Valid';
                $license_status_class = 'success';
            } else {
                $_SESSION["product_purchase_response"] = "Unable to save the license key. Check file permissions.";
            }
        } else {
            $_SESSION["product_purchase_response"] = "License validation failed for the provided key. Please verify the activation code and domain assignment.";
        }
    }
    header("Location: " . $_SERVER["REQUEST_URI"]);
    exit();
}

if (isset($_POST["refresh-license-status"])) {
    bc_clear_integrity_cache();
    $license_valid = bc_verify_integrity();
    $license_status_label = $license_valid ? 'Valid' : 'Invalid';
    $license_status_class = $license_valid ? 'success' : 'danger';
    $_SESSION["product_purchase_response"] = $license_valid ? "License status refreshed: valid." : "License status refreshed: invalid. Please verify your activation details.";
    header("Location: " . $_SERVER["REQUEST_URI"]);
    exit();
}

if (!function_exists('bc_upload_problem')) {
    /**
     * Returns null when an upload looks usable, otherwise a message saying why it is not.
     *
     * The PHP-level error code has to be checked as well as the field values. When a file exceeds
     * upload_max_filesize, PHP reports error 1 with size 0 - which the old field-only checks treated as
     * a valid 0-byte image, so the handler proceeded to move_uploaded_file() with an empty tmp_name,
     * ignored the failure, and still reported "Created Successfully". The logo then never appeared.
     */
    function bc_upload_problem($error, $name, $size, $ext, $allowed, $label) {
        switch ((int) $error) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_NO_FILE:
                return "File Field Empty";
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return "File Too Larger Than " . ini_get('upload_max_filesize') . " (server upload limit) - please upload a smaller image";
            case UPLOAD_ERR_PARTIAL:
                return "The " . $label . " upload was interrupted - please try again";
            case UPLOAD_ERR_NO_TMP_DIR:
            case UPLOAD_ERR_CANT_WRITE:
                return "The server could not store the uploaded " . $label . " (temp folder missing or not writable) - contact your host";
            case UPLOAD_ERR_EXTENSION:
                return "A PHP extension blocked the " . $label . " upload";
            default:
                return "The " . $label . " upload failed (code " . (int) $error . ")";
        }
        if (empty($name) || $size <= 0) {
            return "File Field Empty";
        }
        if ($size > 2097152) {
            return "File Too Larger Than 2MB";
        }
        if (!in_array($ext, $allowed)) {
            return "Error: Image Extension must be (" . implode(", ", $allowed) . ")";
        }
        return null;
    }
}

if (isset($_POST["change-logo"])) {
    bc_demo_guard_edit($connection_server);
    // $_FILES can be missing altogether (form without enctype, or a POST larger than post_max_size,
    // in which case PHP delivers empty $_POST and $_FILES). Never index into it unguarded.
    $logo_file     = $_FILES["logo"] ?? null;
    $logo_name     = $logo_file["name"] ?? "";
    $logo_tmp_name = $logo_file["tmp_name"] ?? "";
    $logo_size     = (int) ($logo_file["size"] ?? 0);
    $logo_error    = (int) ($logo_file["error"] ?? UPLOAD_ERR_NO_FILE);
    $logo_ext      = strtolower(pathinfo($logo_name)["extension"] ?? "");
    $acceptable_ext_array = array("png", "jpg");
    $website_edited_name = str_replace([".", ":"], "-", $_SERVER["HTTP_HOST"]);
    $logo_target = "../uploaded-image/" . $website_edited_name . "_logo.png";

    $logo_problem = bc_upload_problem($logo_error, $logo_name, $logo_size, $logo_ext, $acceptable_ext_array, "logo");

    if ($logo_problem === null) {
        // Create the folder on demand: if uploaded-image/ does not exist, move_uploaded_file() fails
        // and the old code still reported "Created Successfully", so the logo silently never appeared.
        if (!is_dir("../uploaded-image")) {
            @mkdir("../uploaded-image", 0755, true);
        }
        if (file_exists($logo_target)) {
            @unlink($logo_target);
        }
        if (move_uploaded_file($logo_tmp_name, $logo_target)) {
            clearstatcache(true, $logo_target);
            $json_response_array = array("desc" => "Website Logo Saved Successfully");
        } else {
            // Never claim success on a failed write - say what actually went wrong.
            $json_response_array = array("desc" => "Website Logo could NOT be saved: the file could not be moved into uploaded-image/. Check that the folder exists and is writable.");
        }
    } else {
        $json_response_array = array("desc" => $logo_problem);
    }

    $_SESSION["product_purchase_response"] = $json_response_array["desc"];
    header("Location: " . $_SERVER["REQUEST_URI"]);
    exit;
}

if (isset($_POST["change-pwa-icon"])) {
    bc_demo_guard_edit($connection_server);
    $icon_file     = $_FILES["pwa_icon"] ?? null;
    $icon_name     = $icon_file["name"] ?? "";
    $icon_tmp_name = $icon_file["tmp_name"] ?? "";
    $icon_size     = (int) ($icon_file["size"] ?? 0);
    $icon_error    = (int) ($icon_file["error"] ?? UPLOAD_ERR_NO_FILE);
    $icon_ext      = strtolower(pathinfo($icon_name)["extension"] ?? "");
    $acceptable_ext_array = array("png", "jpg");
    $website_edited_name = str_replace([".", ":"], "-", $_SERVER["HTTP_HOST"]);

    $icon_problem = bc_upload_problem($icon_error, $icon_name, $icon_size, $icon_ext, $acceptable_ext_array, "PWA icon");

    if ($icon_problem === null) {
        if (!is_dir("../uploaded-image")) {
            @mkdir("../uploaded-image", 0755, true);
        }
        $target_file = "../uploaded-image/" . $website_edited_name . "_pwa_icon.png";
        if (file_exists($target_file)) {
            @unlink($target_file);
        }
        // Result checked: a failed move used to be reported as "Updated Successfully".
        $json_response_array = array("desc" => move_uploaded_file($icon_tmp_name, $target_file)
            ? "PWA Icon Updated Successfully"
            : "PWA Icon could NOT be saved: the file could not be moved into uploaded-image/. Check that the folder exists and is writable.");
    } else {
        $json_response_array = array("desc" => $icon_problem);
    }
    $_SESSION["product_purchase_response"] = $json_response_array["desc"];
    header("Location: " . $_SERVER["REQUEST_URI"]);
    exit;
}

if (isset($_POST["change-pwa-splash"])) {
    bc_demo_guard_edit($connection_server);
    $splash_file     = $_FILES["pwa_splash"] ?? null;
    $splash_name     = $splash_file["name"] ?? "";
    $splash_tmp_name = $splash_file["tmp_name"] ?? "";
    $splash_size     = (int) ($splash_file["size"] ?? 0);
    $splash_error    = (int) ($splash_file["error"] ?? UPLOAD_ERR_NO_FILE);
    $splash_ext      = strtolower(pathinfo($splash_name)["extension"] ?? "");
    $acceptable_ext_array = array("png", "jpg");
    $website_edited_name = str_replace([".", ":"], "-", $_SERVER["HTTP_HOST"]);

    $splash_problem = bc_upload_problem($splash_error, $splash_name, $splash_size, $splash_ext, $acceptable_ext_array, "PWA splashscreen");

    if ($splash_problem === null) {
        if (!is_dir("../uploaded-image")) {
            @mkdir("../uploaded-image", 0755, true);
        }
        $target_file = "../uploaded-image/" . $website_edited_name . "_pwa_splash.png";
        if (file_exists($target_file)) {
            @unlink($target_file);
        }
        // Result checked: a failed move used to be reported as "Updated Successfully".
        $json_response_array = array("desc" => move_uploaded_file($splash_tmp_name, $target_file)
            ? "PWA Splashscreen Updated Successfully"
            : "PWA Splashscreen could NOT be saved: the file could not be moved into uploaded-image/. Check that the folder exists and is writable.");
    } else {
        $json_response_array = array("desc" => $splash_problem);
    }
    $_SESSION["product_purchase_response"] = $json_response_array["desc"];
    header("Location: " . $_SERVER["REQUEST_URI"]);
    exit;
}


// Migration: Ensure primary_color column exists
$check_col = mysqli_query($connection_server, "SHOW COLUMNS FROM `sas_vendor_style_templates` LIKE 'primary_color'");
if (mysqli_num_rows($check_col) == 0) {
    mysqli_query($connection_server, "ALTER TABLE `sas_vendor_style_templates` ADD `primary_color` VARCHAR(20) DEFAULT '#287bff'");
}

$style_templates = array("bc-style-template-1.css", "bc-style-template-2.css", "bc-style-template-3.css", "bc-style-template-4.css", "bc-style-template-5.css");

if (isset($_POST["update-theme"])) {
    bc_demo_guard_edit($connection_server);
    $template = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["template-name"])));
    $primary_color = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["primary-color"])));

    if (!empty($template) && in_array($template, $style_templates)) {
        $select_vendor_style_templates_details = mysqli_query($connection_server, "SELECT * FROM sas_vendor_style_templates WHERE vendor_id='" . $get_logged_admin_details["id"] . "'");
        if (mysqli_num_rows($select_vendor_style_templates_details) == 0) {
            // Both branches set only $json_response_array; $json_response_encode was NEVER assigned on
            // this path, so json_decode(undefined) returned null and the success message came back
            // empty with a "Trying to access array offset on null" warning. Encode here as well, and
            // check the write.
            $theme_saved = mysqli_query($connection_server, "INSERT INTO sas_vendor_style_templates (vendor_id, template_name, primary_color) VALUES ('" . $get_logged_admin_details["id"] . "', '$template', '$primary_color')");
            $json_response_array = array("desc" => $theme_saved ? "Theme & Color Created Successfully" : "Theme & Color Could Not Be Created: " . mysqli_error($connection_server));
        } else {
            $theme_saved = mysqli_query($connection_server, "UPDATE sas_vendor_style_templates SET template_name='$template', primary_color='$primary_color' WHERE vendor_id='" . $get_logged_admin_details["id"] . "'");
            $json_response_array = array("desc" => $theme_saved ? "Theme & Color Updated Successfully" : "Theme & Color Could Not Be Updated: " . mysqli_error($connection_server));
        }
        $json_response_encode = json_encode($json_response_array, true);
    } else {
        if (empty($color_scheme)) {
            //Theme Field Empty
            $json_response_array = array("desc" => "Theme Field Empty");
            $json_response_encode = json_encode($json_response_array, true);
        } else {
            if (!in_array($color_scheme, $color_schemes)) {
                //Invalid Theme Type
                $json_response_array = array("desc" => "Invalid Theme Type");
                $json_response_encode = json_encode($json_response_array, true);
            }
        }
    }

    $_SESSION["product_purchase_response"] = $json_response_array["desc"] ?? "Settings saved";
    header("Location: " . $_SERVER["REQUEST_URI"]);
    exit;
}

if (isset($_POST["update-profile"])) {
    bc_demo_guard_edit($connection_server);
    $first = mysqli_real_escape_string($connection_server, trim(strip_tags(ucwords($_POST["first"]))));
    $last = mysqli_real_escape_string($connection_server, trim(strip_tags(ucwords($_POST["last"]))));
    $address = mysqli_real_escape_string($connection_server, trim(strip_tags(ucwords($_POST["address"]))));
    $phone = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["phone"])));

    if (!empty($first) && !empty($last) && !empty($address) && !empty($phone)) {
        $check_admin_details = mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='" . $get_logged_admin_details["id"] . "'");
        if (mysqli_num_rows($check_admin_details) == 1) {
            mysqli_query($connection_server, "UPDATE sas_vendors SET firstname='$first', lastname='$last', home_address='$address', phone_number='$phone' WHERE id='" . $get_logged_admin_details["id"] . "'");
            // Email Beginning
            $log_template_encoded_text_array = array("{firstname}" => $first, "{lastname}" => $last, "{email}" => $get_logged_admin_details["email"], "{phone}" => $phone, "{address}" => $address, "{website}" => $get_logged_admin_details["website_url"]);
            $raw_log_template_subject = getSuperAdminEmailTemplate('vendor-account-update', 'subject');
            $raw_log_template_body = getSuperAdminEmailTemplate('vendor-account-update', 'body');
            foreach ($log_template_encoded_text_array as $array_key => $array_val) {
                $raw_log_template_subject = str_replace($array_key, $array_val, $raw_log_template_subject);
                $raw_log_template_body = str_replace($array_key, $array_val, $raw_log_template_body);
            }
            sendVendorEmail($get_logged_admin_details["email"], $raw_log_template_subject, $raw_log_template_body);
            // Email End
            //Profile Information Updated Successfully
            $json_response_array = array("desc" => "Profile Information Updated Successfully");
            $json_response_encode = json_encode($json_response_array, true);
        } else {
            if (mysqli_num_rows($check_admin_details) == 0) {
                //Admin Not Exists
                $json_response_array = array("desc" => "Admin Not Exists");
                $json_response_encode = json_encode($json_response_array, true);
            } else {
                if (mysqli_num_rows($check_admin_details) > 1) {
                    //Duplicated Details, Contact Admin
                    $json_response_array = array("desc" => "Duplicated Details, Contact Admin");
                    $json_response_encode = json_encode($json_response_array, true);
                }
            }
        }
    } else {
        if (empty($first)) {
            //Firstname Field Empty
            $json_response_array = array("desc" => "Firstname Field Empty");
            $json_response_encode = json_encode($json_response_array, true);
        } else {
            if (empty($last)) {
                //Lastname Field Empty
                $json_response_array = array("desc" => "Lastname Field Empty");
                $json_response_encode = json_encode($json_response_array, true);
            } else {
                if (empty($address)) {
                    //Home Address Field Empty
                    $json_response_array = array("desc" => "Home Address Field Empty");
                    $json_response_encode = json_encode($json_response_array, true);
                }
            }
        }
    }

    $_SESSION["product_purchase_response"] = $json_response_array["desc"] ?? "Settings saved";
    header("Location: " . $_SERVER["REQUEST_URI"]);
    exit;
}

if (isset($_POST["update-verification"])) {
    $bank_code = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["bank-code"] ?? '')));
    $account_number = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["account-number"] ?? '')));
    $bvn_nin = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["bvnnin"])));
    $verification_type = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["verification-type"])));
    $verification_type_array = array(1 => "bvn", 2 => "nin");
    $vid = $get_logged_admin_details["id"];
    $identity_provider = getIdentityProvider($vid);
    $needs_bank_fields = ($identity_provider === 'monnify' && $verification_type == 1);

    $input_valid = !empty($bvn_nin) && is_numeric($bvn_nin) && strlen($bvn_nin) == 11 && in_array($verification_type, array_keys($verification_type_array));
    if ($needs_bank_fields) {
        $input_valid = $input_valid && !empty($bank_code) && is_numeric($bank_code) && !empty($account_number) && is_numeric($account_number) && strlen($account_number) == 10;
    }

    if ($input_valid) {
        $check_user_details = mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vid'");
        if (mysqli_num_rows($check_user_details) == 1) {
            $amount = ($verification_type == 1) ? 30 : 100;
            $discounted_amount = $amount;
            $type_alternative = ucwords("bvn/nin verification");
            $reference = substr(str_shuffle("12345678901234567890"), 0, 15);
            $description = "BVN/NIN Verification charges";
            if (!empty(vendorBalance(1)) && is_numeric(vendorBalance(1)) && (vendorBalance(1) >= 10)) {
                $debit_user = chargeVendor("debit", "BVN/NIN Verification", $type_alternative, $reference, $amount, $discounted_amount, $description, $_SERVER["HTTP_HOST"], 3);
                if ($debit_user === "success") {
                    $type_str = $verification_type_array[$verification_type];
                    $verify_result = verifyBvnNin($bvn_nin, $type_str, $bank_code, $account_number, $get_logged_admin_details["firstname"], $get_logged_admin_details["lastname"], $vid, true);
                    if ($verify_result["status"] === "success") {
                        alterVendorTransaction($reference, "status", "1");
                        if ($type_str === 'bvn') {
                            $update_fields = "bank_code='$bank_code', account_number='$account_number', bvn='$bvn_nin'";
                            if ($needs_bank_fields) {
                                $update_fields = "bank_code='$bank_code', account_number='$account_number', bvn='$bvn_nin'";
                            } else {
                                $update_fields = "bvn='$bvn_nin'";
                            }
                            mysqli_query($connection_server, "UPDATE sas_vendors SET $update_fields WHERE id='$vid'");
                            $json_response_array = array("desc" => "Admin BVN Verification Information Updated Successfully");
                            $json_response_encode = json_encode($json_response_array, true);
                        } else {
                            // NIN: update phone if API returned one
                            $returned_phone = $verify_result["phone"] ?? '';
                            if (!empty($returned_phone)) {
                                $returned_phone_esc = mysqli_real_escape_string($connection_server, $returned_phone);
                                $ba_part = (!empty($bank_code) && !empty($account_number)) ? ", bank_code='$bank_code', account_number='$account_number'" : "";
                                mysqli_query($connection_server, "UPDATE sas_vendors SET phone_number='$returned_phone_esc'$ba_part, nin='$bvn_nin' WHERE id='$vid'");
                            } else {
                                $ba_part = (!empty($bank_code) && !empty($account_number)) ? ", bank_code='$bank_code', account_number='$account_number'" : "";
                                mysqli_query($connection_server, "UPDATE sas_vendors SET nin='$bvn_nin'$ba_part WHERE id='$vid'");
                            }
                            $json_response_array = array("desc" => "Admin NIN Verification Information Updated Successfully");
                            $json_response_encode = json_encode($json_response_array, true);
                        }
                    } else {
                        $reference_2 = substr(str_shuffle("12345678901234567890"), 0, 15);
                        chargeVendor("credit", "BVN/NIN Verification", "Refund", $reference_2, $amount, $discounted_amount, "Refund for Ref:<i>'$reference'</i>", $_SERVER["HTTP_HOST"], "1");
                        $json_response_array = array("desc" => $verify_result["message"]);
                        $json_response_encode = json_encode($json_response_array, true);
                    }
                } else {
                    $json_response_array = array("desc" => "Unable to proceed with charges");
                    $json_response_encode = json_encode($json_response_array, true);
                }
            } else {
                $json_response_array = array("desc" => "Error: Insufficient Funds");
                $json_response_encode = json_encode($json_response_array, true);
            }
        } else {
            $json_response_array = array("desc" => mysqli_num_rows($check_user_details) == 0 ? "User Not Exists" : "Duplicated Details, Contact Admin");
            $json_response_encode = json_encode($json_response_array, true);
        }
    } else {
        if (empty($bvn_nin)) {
            $label = isset($verification_type_array[$verification_type]) ? strtoupper($verification_type_array[$verification_type]) : "BVN/NIN";
            $json_response_array = array("desc" => "$label Field Empty");
        } elseif (!is_numeric($bvn_nin)) {
            $json_response_array = array("desc" => "BVN/NIN must be numeric");
        } elseif (strlen($bvn_nin) != 11) {
            $json_response_array = array("desc" => "BVN/NIN must be exactly 11 digits");
        } elseif (!in_array($verification_type, array_keys($verification_type_array))) {
            $json_response_array = array("desc" => "Unknown Verification Type");
        } elseif ($needs_bank_fields && empty($bank_code)) {
            $json_response_array = array("desc" => "Bank Code Field Empty");
        } elseif ($needs_bank_fields && (!is_numeric($account_number) || strlen($account_number) != 10)) {
            $json_response_array = array("desc" => "Account Number must be 10 digits");
        } else {
            $json_response_array = array("desc" => "Invalid submission data");
        }
        $json_response_encode = json_encode($json_response_array, true);
    }

    $_SESSION["product_purchase_response"] = $json_response_array["desc"] ?? "Settings saved";
    header("Location: " . $_SERVER["REQUEST_URI"]);
    exit();
}

if (isset($_POST["change-password"])) {
    $old_pass = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["old-pass"])));
    $new_pass = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["new-pass"])));
    $con_new_pass = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["con-new-pass"])));

    if (!empty($old_pass) && !empty($new_pass) && !empty($con_new_pass)) {
        $check_admin_details = mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='" . $get_logged_admin_details["id"] . "'");
        if (mysqli_num_rows($check_admin_details) == 1) {
            // bc_verify_password() accepts both bcrypt and legacy raw-MD5 hashes; only bcrypt is written.
            $pass_matches_old = bc_verify_password($old_pass, $get_logged_admin_details["password"]);
            $pass_matches_new = bc_verify_password($new_pass, $get_logged_admin_details["password"]);
            $new_pass_matches_confirm = ($new_pass === $con_new_pass);

            if ($pass_matches_old) {
                if (!$pass_matches_new) {
                    if ($new_pass_matches_confirm) {
                        $new_vendor_pass_hash = mysqli_real_escape_string($connection_server, bc_hash_password($new_pass));
                        mysqli_query($connection_server, "UPDATE sas_vendors SET password='$new_vendor_pass_hash' WHERE id='" . (int)$get_logged_admin_details["id"] . "'");
                        // Email Beginning
                        $log_template_encoded_text_array = array("{firstname}" => $get_logged_admin_details["firstname"], "{lastname}" => $get_logged_admin_details["lastname"]);
                        $raw_log_template_subject = getSuperAdminEmailTemplate('vendor-pass-update', 'subject');
                        $raw_log_template_body = getSuperAdminEmailTemplate('vendor-pass-update', 'body');
                        foreach ($log_template_encoded_text_array as $array_key => $array_val) {
                            $raw_log_template_subject = str_replace($array_key, $array_val, $raw_log_template_subject);
                            $raw_log_template_body = str_replace($array_key, $array_val, $raw_log_template_body);
                        }
                        sendVendorEmail($get_logged_admin_details["email"], $raw_log_template_subject, $raw_log_template_body);
                        // Email End
                        //Account Password Updated Successfully
                        $json_response_array = array("desc" => "Account Password Updated Successfully");
                        $json_response_encode = json_encode($json_response_array, true);
                    } else {
                        //New & Confirm Password Not Match
                        $json_response_array = array("desc" => "New & Confirm Password Not Match");
                        $json_response_encode = json_encode($json_response_array, true);
                    }
                } else {
                    //New & Old Password Must Be Different
                    $json_response_array = array("desc" => "New & Old Password Must Be Different");
                    $json_response_encode = json_encode($json_response_array, true);
                }
            } else {
                //Incorrect Old Password
                $json_response_array = array("desc" => "Incorrect Old Password");
                $json_response_encode = json_encode($json_response_array, true);
            }
        } else {
            if (mysqli_num_rows($check_admin_details) == 0) {
                //Admin Not Exists
                $json_response_array = array("desc" => "Admin Not Exists");
                $json_response_encode = json_encode($json_response_array, true);
            } else {
                if (mysqli_num_rows($check_admin_details) > 1) {
                    //Duplicated Details, Contact Admin
                    $json_response_array = array("desc" => "Duplicated Details, Contact Admin");
                    $json_response_encode = json_encode($json_response_array, true);
                }
            }
        }
    } else {
        if (empty($old_pass)) {
            //Old Password Field Empty
            $json_response_array = array("desc" => "Old Password Field Empty");
            $json_response_encode = json_encode($json_response_array, true);
        } else {
            if (empty($new_pass)) {
                //New Password Field Empty
                $json_response_array = array("desc" => "New Password Field Empty");
                $json_response_encode = json_encode($json_response_array, true);
            } else {
                if (empty($con_new_pass)) {
                    //Confirm New Password Field Empty
                    $json_response_array = array("desc" => "Confirm New Password Field Empty");
                    $json_response_encode = json_encode($json_response_array, true);
                }
            }
        }
    }

    $_SESSION["product_purchase_response"] = $json_response_array["desc"] ?? "Settings saved";
    header("Location: " . $_SERVER["REQUEST_URI"]);
    exit;
}

if (isset($_POST["update-bank-details"])) {
    bc_demo_guard_edit($connection_server);
    $fullname = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["name"])));
    $bank_name = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["bank"])));
    $account_number = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9]+/", "", trim(strip_tags($_POST["number"]))));
    $phone_number = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9]+/", "", strip_tags($_POST["phone"])));
    $amount_charged = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9.]+/", "", trim(strip_tags($_POST["charges"]))));

    if (!empty($fullname) && !empty($bank_name) && !empty($account_number) && is_numeric($account_number) && !empty($phone_number) && is_numeric($phone_number) && !empty($amount_charged) && is_numeric($amount_charged) && ($amount_charged > 0)) {
        $get_admin_payment_details = mysqli_query($connection_server, "SELECT * FROM sas_admin_payments WHERE vendor_id='" . $get_logged_admin_details["id"] . "'");

        if (mysqli_num_rows($get_admin_payment_details) == 1) {
            mysqli_query($connection_server, "UPDATE sas_admin_payments SET bank_name='$bank_name', account_name='$fullname', account_number='$account_number', phone_number='$phone_number', amount_charged='$amount_charged' WHERE vendor_id='" . $get_logged_admin_details["id"] . "'");
            //Bank Information Updated Successfully
            $json_response_array = array("desc" => "Bank Information Updated Successfully");
            $json_response_encode = json_encode($json_response_array, true);
        } else {
            if (mysqli_num_rows($get_admin_payment_details) == 0) {
                mysqli_query($connection_server, "INSERT INTO sas_admin_payments (vendor_id, bank_name, account_name, account_number, phone_number, amount_charged) VALUES ('" . $get_logged_admin_details["id"] . "', '$bank_name', '$fullname', '$account_number', '$phone_number', '$amount_charged')");
                //Admin Bank Info Exists
                $json_response_array = array("desc" => "Bank Information Created Successfully");
                $json_response_encode = json_encode($json_response_array, true);
            } else {
                if (mysqli_num_rows($get_admin_payment_details) > 1) {
                    //Duplicated Details, Contact Admin
                    $json_response_array = array("desc" => "Duplicated Details, Contact Admin");
                    $json_response_encode = json_encode($json_response_array, true);
                }
            }
        }
    } else {
        if (empty($fullname)) {
            //Fullname Field Empty
            $json_response_array = array("desc" => "Fullname Field Empty");
            $json_response_encode = json_encode($json_response_array, true);
        } else {
            if (empty($bank_name)) {
                //Bank Name Field Empty
                $json_response_array = array("desc" => "Bank Name Field Empty");
                $json_response_encode = json_encode($json_response_array, true);
            } else {
                if (empty($account_number)) {
                    //Account Number Field Empty
                    $json_response_array = array("desc" => "Account Number Field Empty");
                    $json_response_encode = json_encode($json_response_array, true);
                } else {
                    if (!is_numeric($account_number)) {
                        //Non-numeric Account Number
                        $json_response_array = array("desc" => "Non-numeric Account Number");
                        $json_response_encode = json_encode($json_response_array, true);
                    } else {
                        if (empty($phone_number)) {
                            //Phone Number Field Empty
                            $json_response_array = array("desc" => "Phone Number Field Empty");
                            $json_response_encode = json_encode($json_response_array, true);
                        } else {
                            if (!is_numeric($phone_number)) {
                                //Non-numeric Phone Number
                                $json_response_array = array("desc" => "Non-numeric Account Number");
                                $json_response_encode = json_encode($json_response_array, true);
                            } else {
                                if (empty($amount_charged)) {
                                    //Amount Field Empty
                                    $json_response_array = array("desc" => "Amount Number Field Empty");
                                    $json_response_encode = json_encode($json_response_array, true);
                                } else {
                                    if (!is_numeric($amount_charged)) {
                                        //Non-numeric Amount
                                        $json_response_array = array("desc" => "Non-numeric Account");
                                        $json_response_encode = json_encode($json_response_array, true);
                                    } else {
                                        if ($amount_charged > 0) {
                                            //Amount Must Be Greater Than 0
                                            $json_response_array = array("desc" => "Amount Must Be Greater Than 0");
                                            $json_response_encode = json_encode($json_response_array, true);
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

    $_SESSION["product_purchase_response"] = $json_response_array["desc"] ?? "Settings saved";
    header("Location: " . $_SERVER["REQUEST_URI"]);
    exit;
}


if (isset($_POST["update-daily-purchase-limit-details"])) {
    $limit_phone = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9]+/", "", trim(strip_tags($_POST["limit-phone"]))));
    $limit_cable = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9]+/", "", trim(strip_tags($_POST["limit-cable"]))));
    $limit_betting = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9]+/", "", trim(strip_tags($_POST["limit-betting"]))));
    $limit_electric = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9]+/", "", trim(strip_tags($_POST["limit-electric"]))));

    if (is_numeric($limit_phone) && is_numeric($limit_cable) && is_numeric($limit_betting) && is_numeric($limit_electric)) {
        $get_daily_purchase_limit_details = mysqli_query($connection_server, "SELECT * FROM sas_daily_purchase_limit WHERE vendor_id='" . $get_logged_admin_details["id"] . "'");

        if (mysqli_num_rows($get_daily_purchase_limit_details) == 1) {
            mysqli_query($connection_server, "UPDATE sas_daily_purchase_limit SET limit_phone='$limit_phone', limit_cable='$limit_cable', limit_betting='$limit_betting', limit_electric='$limit_electric', `limit`='$limit_phone' WHERE vendor_id='" . $get_logged_admin_details["id"] . "'");
            $json_response_array = array("desc" => "User Daily Limits Updated Successfully");
        } else {
            mysqli_query($connection_server, "INSERT INTO sas_daily_purchase_limit (vendor_id, limit_phone, limit_cable, limit_betting, limit_electric, `limit`) VALUES ('" . $get_logged_admin_details["id"] . "', '$limit_phone', '$limit_cable', '$limit_betting', '$limit_electric', '$limit_phone')");
            $json_response_array = array("desc" => "User Daily Limits Created Successfully");
        }
    } else {
        $json_response_array = array("desc" => "Invalid numeric limits provided");
    }

    $_SESSION["product_purchase_response"] = $json_response_array["desc"];
    header("Location: " . $_SERVER["REQUEST_URI"]);
    exit();
}

if (isset($_POST["refresh-purchase-id"])) {
    $purchase_id = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9]+/", "", trim(strip_tags($_POST["purchase-id"]))));

    if (!empty($purchase_id) && is_numeric($purchase_id) && (strlen($purchase_id) >= 1)) {
        $get_daily_purchase_tracker_details = mysqli_query($connection_server, "SELECT * FROM sas_daily_purchase_tracker WHERE vendor_id='" . $get_logged_admin_details["id"] . "' && product_id='$purchase_id' && date='" . date("Y-m-d") . "'");

        if (mysqli_num_rows($get_daily_purchase_tracker_details) >= 1) {
            mysqli_query($connection_server, "DELETE FROM sas_daily_purchase_tracker WHERE vendor_id='" . $get_logged_admin_details["id"] . "' && product_id='$purchase_id' && date='" . date("Y-m-d") . "'");
            //Purchase ID Limit History Cleared Successfully
            $json_response_array = array("desc" => "Purchase ID Limit History Cleared Successfully");
            $json_response_encode = json_encode($json_response_array, true);
        } else {
            if (mysqli_num_rows($get_daily_purchase_tracker_details) == 0) {
                //Purchase ID Daily Limits Unused
                $json_response_array = array("desc" => "Purchase ID Daily Limits Unused");
                $json_response_encode = json_encode($json_response_array, true);
            }
        }
    } else {
        if (empty($purchase_id)) {
            //Pruchase ID Field Empty
            $json_response_array = array("desc" => "Purchase ID Field Empty");
            $json_response_encode = json_encode($json_response_array, true);
        } else {
            if (!is_numeric($purchase_id)) {
                //Non-numeric Purchase ID
                $json_response_array = array("desc" => "Non-numeric Purchase ID");
                $json_response_encode = json_encode($json_response_array, true);
            } else {
                if ((strlen($purchase_id) < 1)) {
                    //Purchase ID Must Be Atleast 1 Numeric Value
                    $json_response_array = array("desc" => "Purchase ID Must Be Atleast 1 Numeric Value");
                    $json_response_encode = json_encode($json_response_array, true);
                }
            }
        }
    }

    $_SESSION["product_purchase_response"] = $json_response_array["desc"] ?? "Settings saved";
    header("Location: " . $_SERVER["REQUEST_URI"]);
    exit;
}

if (isset($_POST["whitelist-purchase-id"])) {
    $purchase_id = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9]+/", "", trim(strip_tags($_POST["purchase-id"]))));
    $type = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["type"])));
    $type_array = array("whitelist", "blacklist");
    if (!empty($purchase_id) && is_numeric($purchase_id) && (strlen($purchase_id) >= 1) && !empty($type) && in_array($type, $type_array)) {
        $get_validated_user_purchase_id_list_details = mysqli_query($connection_server, "SELECT * FROM sas_validated_user_purchase_id_list WHERE vendor_id='" . $get_logged_admin_details["id"] . "' && product_id='$purchase_id'");
        if ($type == "whitelist") {
            if (mysqli_num_rows($get_validated_user_purchase_id_list_details) == 0) {
                mysqli_query($connection_server, "INSERT INTO sas_validated_user_purchase_id_list (vendor_id, product_id) VALUES ('" . $get_logged_admin_details["id"] . "', '$purchase_id')");
                //Purchase ID Whitelisted Successfully
                $json_response_array = array("desc" => "Purchase ID Whitelisted Successfully");
                $json_response_encode = json_encode($json_response_array, true);
            } else {
                if (mysqli_num_rows($get_validated_user_purchase_id_list_details) == 1) {
                    //Purchase ID Already Whitelisted Successfully
                    $json_response_array = array("desc" => "Purchase ID Already Whitelisted Successfully");
                    $json_response_encode = json_encode($json_response_array, true);
                } else {
                    if (mysqli_num_rows($get_validated_user_purchase_id_list_details) > 1) {
                        //Duplicated Details, Contact Admin
                        $json_response_array = array("desc" => "Duplicated Details, Contact Admin");
                        $json_response_encode = json_encode($json_response_array, true);
                    }
                }
            }
        }

        if ($type == "blacklist") {
            if (mysqli_num_rows($get_validated_user_purchase_id_list_details) == 0) {
                //Purchase ID Not Exists in Whitelist Database
                $json_response_array = array("desc" => "Purchase ID Not Exists in Whitelist Database");
                $json_response_encode = json_encode($json_response_array, true);
            } else {
                if (mysqli_num_rows($get_validated_user_purchase_id_list_details) == 1) {
                    mysqli_query($connection_server, "DELETE FROM sas_validated_user_purchase_id_list WHERE vendor_id='" . $get_logged_admin_details["id"] . "' && product_id='$purchase_id'");
                    //Purchase ID Removed from Whitelist Successfully
                    $json_response_array = array("desc" => "Purchase ID Removed from Whitelist Successfully");
                    $json_response_encode = json_encode($json_response_array, true);
                } else {
                    if (mysqli_num_rows($get_validated_user_purchase_id_list_details) > 1) {
                        //Duplicated Details, Contact Admin
                        $json_response_array = array("desc" => "Duplicated Details, Contact Admin");
                        $json_response_encode = json_encode($json_response_array, true);
                    }
                }
            }
        }
    } else {
        if (empty($purchase_id)) {
            //Pruchase ID Field Empty
            $json_response_array = array("desc" => "Purchase ID Field Empty");
            $json_response_encode = json_encode($json_response_array, true);
        } else {
            if (!is_numeric($purchase_id)) {
                //Non-numeric Purchase ID
                $json_response_array = array("desc" => "Non-numeric Purchase ID");
                $json_response_encode = json_encode($json_response_array, true);
            } else {
                if ((strlen($purchase_id) < 1)) {
                    //Purchase ID Must Be Atleast 1 Numeric Value
                    $json_response_array = array("desc" => "Purchase ID Must Be Atleast 1 Numeric Value");
                    $json_response_encode = json_encode($json_response_array, true);
                } else {
                    if (empty($type)) {
                        //Type Field Empty
                        $json_response_array = array("desc" => "Type Field Empty");
                        $json_response_encode = json_encode($json_response_array, true);
                    } else {
                        if (!in_array($type, $type_array)) {
                            //Invalid Type
                            $json_response_array = array("desc" => "Invalid Type");
                            $json_response_encode = json_encode($json_response_array, true);
                        }
                    }
                }
            }
        }
    }

    $_SESSION["product_purchase_response"] = $json_response_array["desc"] ?? "Settings saved";
    header("Location: " . $_SERVER["REQUEST_URI"]);
    exit;
}

if (isset($_POST["update-user-minimum-funding-details"])) {
    $min_amount = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9.]+/", "", trim(strip_tags($_POST["min"]))));

    if (!empty($min_amount) && is_numeric($min_amount) && ($min_amount > 0)) {
        $get_user_minimum_funding_details = mysqli_query($connection_server, "SELECT * FROM sas_user_minimum_funding WHERE vendor_id='" . $get_logged_admin_details["id"] . "'");

        if (mysqli_num_rows($get_user_minimum_funding_details) == 1) {
            mysqli_query($connection_server, "UPDATE sas_user_minimum_funding SET min_amount='$min_amount' WHERE vendor_id='" . $get_logged_admin_details["id"] . "'");
            //User Minimum Fund Limits Information Updated Successfully
            $json_response_array = array("desc" => "User Minimum Fund Limits Information Updated Successfully");
            $json_response_encode = json_encode($json_response_array, true);
        } else {
            if (mysqli_num_rows($get_user_minimum_funding_details) == 0) {
                mysqli_query($connection_server, "INSERT INTO sas_user_minimum_funding (vendor_id, min_amount) VALUES ('" . $get_logged_admin_details["id"] . "', '$min_amount')");
                //User Minimum Fund Limits Information Created Successfully
                $json_response_array = array("desc" => "User Minimum Fund Limits Information Created Successfully");
                $json_response_encode = json_encode($json_response_array, true);
            } else {
                if (mysqli_num_rows($get_user_minimum_funding_details) > 1) {
                    //Duplicated Details, Contact Admin
                    $json_response_array = array("desc" => "Duplicated Details, Contact Admin");
                    $json_response_encode = json_encode($json_response_array, true);
                }
            }
        }
    } else {
        if (empty($min_amount)) {
            //Minimum Amount Field Empty
            $json_response_array = array("desc" => "Minimum Amount Field Empty");
            $json_response_encode = json_encode($json_response_array, true);
        } else {
            if (!is_numeric($min_amount)) {
                //Non-numeric Minimum Amount
                $json_response_array = array("desc" => "Non-numeric Minimum Amount");
                $json_response_encode = json_encode($json_response_array, true);
            } else {
                if (($min_amount < 0)) {
                    //Minimum Amount Must Be Greater Than Zero (0)
                    $json_response_array = array("desc" => "Minimum Amount MUst Be Greater Than Zero (0)");
                    $json_response_encode = json_encode($json_response_array, true);
                }
            }
        }
    }

    $_SESSION["product_purchase_response"] = $json_response_array["desc"] ?? "Settings saved";
    header("Location: " . $_SERVER["REQUEST_URI"]);
    exit;
}

if (isset($_POST["update-payment-order-details"])) {
    $min_amount = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9.]+/", "", trim(strip_tags($_POST["min"]))));
    $max_amount = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9.]+/", "", trim(strip_tags($_POST["max"]))));

    if (!empty($min_amount) && is_numeric($min_amount) && ($min_amount > 0) && !empty($max_amount) && is_numeric($max_amount) && ($max_amount > 0) && ($max_amount > $min_amount)) {
        $get_admin_payment_order_details = mysqli_query($connection_server, "SELECT * FROM sas_admin_payment_orders WHERE vendor_id='" . $get_logged_admin_details["id"] . "'");

        if (mysqli_num_rows($get_admin_payment_order_details) == 1) {
            mysqli_query($connection_server, "UPDATE sas_admin_payment_orders SET min_amount='$min_amount', max_amount='$max_amount' WHERE vendor_id='" . $get_logged_admin_details["id"] . "'");
            //Payment Order Limits Information Updated Successfully
            $json_response_array = array("desc" => "Payment Order Limits Information Updated Successfully");
            $json_response_encode = json_encode($json_response_array, true);
        } else {
            if (mysqli_num_rows($get_admin_payment_order_details) == 0) {
                mysqli_query($connection_server, "INSERT INTO sas_admin_payment_orders (vendor_id, min_amount, max_amount) VALUES ('" . $get_logged_admin_details["id"] . "', '$min_amount', '$max_amount')");
                //Payment Order Limits Information Created Successfully
                $json_response_array = array("desc" => "Payment Order Limits Information Created Successfully");
                $json_response_encode = json_encode($json_response_array, true);
            } else {
                if (mysqli_num_rows($get_admin_payment_order_details) > 1) {
                    //Duplicated Details, Contact Admin
                    $json_response_array = array("desc" => "Duplicated Details, Contact Admin");
                    $json_response_encode = json_encode($json_response_array, true);
                }
            }
        }
    } else {
        if (empty($min_amount)) {
            //Minimum Amount Field Empty
            $json_response_array = array("desc" => "Minimum Amount Field Empty");
            $json_response_encode = json_encode($json_response_array, true);
        } else {
            if (!is_numeric($min_amount)) {
                //Non-numeric Minimum Amount
                $json_response_array = array("desc" => "Non-numeric Minimum Amount");
                $json_response_encode = json_encode($json_response_array, true);
            } else {
                if (($min_amount < 0)) {
                    //Minimum Amount Must Be Greater Than Zero (0)
                    $json_response_array = array("desc" => "Minimum Amount MUst Be Greater Than Zero (0)");
                    $json_response_encode = json_encode($json_response_array, true);
                } else {
                    if (empty($max_amount)) {
                        //Maximum Amount Field Empty
                        $json_response_array = array("desc" => "Maximum Amount Field Empty");
                        $json_response_encode = json_encode($json_response_array, true);
                    } else {
                        if (!is_numeric($max_amount)) {
                            //Non-numeric Maximum Amount
                            $json_response_array = array("desc" => "Non-numeric Maximum Amount");
                            $json_response_encode = json_encode($json_response_array, true);
                        } else {
                            if (($max_amount < 0)) {
                                //Maximum Amount Must Be Greater Than Zero (0)
                                $json_response_array = array("desc" => "Maximum Amount MUst Be Greater Than Zero (0)");
                                $json_response_encode = json_encode($json_response_array, true);
                            } else {
                                if (($min_amount > $max_amount)) {
                                    //Minimum Amount Must Not Be Greater Than Maximum Amount
                                    $json_response_array = array("desc" => "Minimum Amount Must Not Be Greater Than Maximum Amount");
                                    $json_response_encode = json_encode($json_response_array, true);
                                } else {
                                    if (($min_amount == $max_amount)) {
                                        //Minimum Amount Must Not Equal Maximum Amount
                                        $json_response_array = array("desc" => "Minimum Amount Must Not Equal Maximum Amount");
                                        $json_response_encode = json_encode($json_response_array, true);
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    $_SESSION["product_purchase_response"] = $json_response_array["desc"] ?? "Settings saved";
    header("Location: " . $_SERVER["REQUEST_URI"]);
    exit;
}

if (isset($_POST["update-withdrawal-settings"])) {
    $min_with = (float)$_POST["min_with"];
    $max_with = (float)$_POST["max_with"];
    $daily_limit = (int)$_POST["daily_limit"];
    $global_min = (float)getSuperAdminOption('default_min_withdrawal', 1000);

    if ($max_with <= $min_with) {
        $_SESSION["product_purchase_response"] = "Maximum withdrawal must be greater than minimum";
    } elseif ($daily_limit < 1) {
        $_SESSION["product_purchase_response"] = "Daily payout limit must be at least 1";
    } else {
        mysqli_query($connection_server, "UPDATE sas_vendors SET min_withdrawal_amount='$min_with', max_withdrawal_amount='$max_with', daily_payout_limit='$daily_limit' WHERE id='" . $get_logged_admin_details["id"] . "'");
        $_SESSION["product_purchase_response"] = "Withdrawal limits updated successfully";
    }
    header("Location: " . $_SERVER["REQUEST_URI"]);
    exit();
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

if (isset($_POST["update-security-settings"])) {
    $force_pin = isset($_POST["force-pin"]) ? 1 : 0;
    $force_otp = isset($_POST["force-reg-otp"]) ? 1 : 0;
    $force_email = isset($_POST["force-trans-email"]) ? 1 : 0;
    $force_sso = isset($_POST["force-sso"]) ? 1 : 0;
    $google_id = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["google-client-id"])));
    $new_pin = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["admin_pin"])));
    $con_pin = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["admin_pin_con"])));

    $smtp_host = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["smtp_host"])));
    $smtp_user = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["smtp_user"])));
    $smtp_pass = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["smtp_pass"])));
    $smtp_port = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["smtp_port"])));
    $smtp_sec = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["smtp_sec"])));
    $support_whatsapp = mysqli_real_escape_string($connection_server, preg_replace('/[^0-9]/', '', $_POST["support-whatsapp"] ?? ''));

    // (VoveID Identity Verification is configured in IdentityAPI.php, with the other KYC providers.)

    mysqli_query($connection_server, "UPDATE sas_vendors SET force_security_pin='$force_pin', reg_otp_enabled='$force_otp', trans_email_enabled='$force_email', force_google_sso='$force_sso', google_client_id='$google_id', smtp_host='$smtp_host', smtp_user='$smtp_user', smtp_pass='$smtp_pass', smtp_port='$smtp_port', smtp_sec='$smtp_sec', support_whatsapp='$support_whatsapp' WHERE id='" . $get_logged_admin_details["id"] . "'");

    // Daily password-reset limit (per account, default 1)
    $reset_daily_limit_ns = max(1, (int)($_POST["password_reset_daily_limit"] ?? 1));
    mysqli_query($connection_server, "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('password_reset_daily_limit', '$reset_daily_limit_ns') ON DUPLICATE KEY UPDATE option_value='$reset_daily_limit_ns'");

    if (!empty($new_pin)) {
        if (is_numeric($new_pin) && strlen($new_pin) == 4) {
            if ($new_pin === $con_pin) {
                $hashed_pin = password_hash($new_pin, PASSWORD_DEFAULT);
                mysqli_query($connection_server, "UPDATE sas_vendors SET security_pin='$hashed_pin' WHERE id='" . $get_logged_admin_details["id"] . "'");
                $_SESSION["product_purchase_response"] = "Security settings and PIN updated successfully";
            } else {
                $_SESSION["product_purchase_response"] = "Admin PIN and Confirm PIN do not match";
            }
        } else {
            $_SESSION["product_purchase_response"] = "Admin PIN must be 4 digits";
        }
    } else {
        $_SESSION["product_purchase_response"] = "Security settings updated successfully";
    }

    header("Location: " . $_SERVER["REQUEST_URI"]);
    exit();
}

if (isset($_POST["update-upgrade-details"])) {
    $level_id = $_POST["level-id"];
    $level_percent = $_POST["level-price"];
    $referral_level_array = array(1, 2, 3);

    if ((count($level_id) > 0) && (count($level_percent) > 0) && (count($level_id) == count($level_percent))) {
        foreach ($level_id as $index => $id) {
            $each_level_id = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9.]+/", "", trim(strip_tags($level_id[$index]))));
            $each_level_percent = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9.]+/", "", trim(strip_tags($level_percent[$index]))));
            if (in_array($each_level_id, $referral_level_array)) {
                $get_referral_percent_details = mysqli_query($connection_server, "SELECT * FROM sas_user_upgrade_price WHERE vendor_id='" . $get_logged_admin_details["id"] . "' && account_type='$each_level_id'");
                if (mysqli_num_rows($get_referral_percent_details) == 1) {
                    mysqli_query($connection_server, "UPDATE sas_user_upgrade_price SET `price`='$each_level_percent' WHERE vendor_id='" . $get_logged_admin_details["id"] . "' && account_type='$each_level_id'");
                    //User Upgrade Price Information Updated Successfully
                    $json_response_array = array("desc" => "User Upgrade Price Information Updated Successfully");
                    $json_response_encode = json_encode($json_response_array, true);
                } else {
                    if (mysqli_num_rows($get_referral_percent_details) == 0) {
                        mysqli_query($connection_server, "INSERT INTO sas_user_upgrade_price (vendor_id, account_type, `price`) VALUES ('" . $get_logged_admin_details["id"] . "', '$each_level_id', '$each_level_percent')");
                        //User Upgrade Price Information Created Successfully
                        $json_response_array = array("desc" => "User Upgrade Price Information Created Successfully");
                        $json_response_encode = json_encode($json_response_array, true);
                    } else {
                        if (mysqli_num_rows($get_referral_percent_details) > 1) {
                            //Duplicated Details, Contact Admin
                            $json_response_array = array("desc" => "Duplicated Details, Contact Admin");
                            $json_response_encode = json_encode($json_response_array, true);
                        }
                    }
                }
            } else {
                //cannot show the error once
            }
        }
    } else {
        if ((count($level_id) < 1)) {
            //Level Field Not Available
            $json_response_array = array("desc" => "Level Field Not Available");
            $json_response_encode = json_encode($json_response_array, true);
        } else {
            if ((count($level_percent) < 1)) {
                //Level Price Field Not Available
                $json_response_array = array("desc" => "Level Price Field Not Available");
                $json_response_encode = json_encode($json_response_array, true);
            } else {
                if (count($level_id) !== count($level_percent)) {
                    //Incomplete Field
                    $json_response_array = array("desc" => "Incomplete Field");
                    $json_response_encode = json_encode($json_response_array, true);
                }
            }
        }
    }

    $_SESSION["product_purchase_response"] = $json_response_array["desc"] ?? "Settings saved";
    header("Location: " . $_SERVER["REQUEST_URI"]);
    exit;
}

if (isset($_POST["update-referral-details"])) {
    $level_id = $_POST["level-id"];
    $level_percent = $_POST["level-percent"];
    $referral_level_array = array(2, 3);

    if ((count($level_id) > 0) && (count($level_percent) > 0) && (count($level_id) == count($level_percent))) {
        foreach ($level_id as $index => $id) {
            $each_level_id = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9.]+/", "", trim(strip_tags($level_id[$index]))));
            $each_level_percent = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9.]+/", "", trim(strip_tags($level_percent[$index]))));
            if (in_array($each_level_id, $referral_level_array)) {
                $get_referral_percent_details = mysqli_query($connection_server, "SELECT * FROM sas_referral_percents WHERE vendor_id='" . $get_logged_admin_details["id"] . "' && account_level='$each_level_id'");
                if (mysqli_num_rows($get_referral_percent_details) == 1) {
                    mysqli_query($connection_server, "UPDATE sas_referral_percents SET `percentage`='$each_level_percent' WHERE vendor_id='" . $get_logged_admin_details["id"] . "' && account_level='$each_level_id'");
                    //Referral Percentage Information Updated Successfully
                    $json_response_array = array("desc" => "Referral Percentage Information Updated Successfully");
                    $json_response_encode = json_encode($json_response_array, true);
                } else {
                    if (mysqli_num_rows($get_referral_percent_details) == 0) {
                        mysqli_query($connection_server, "INSERT INTO sas_referral_percents (vendor_id, account_level, `percentage`) VALUES ('" . $get_logged_admin_details["id"] . "', '$each_level_id', '$each_level_percent')");
                        //Referral Percentage Information Created Successfully
                        $json_response_array = array("desc" => "Referral Percentage Information Created Successfully");
                        $json_response_encode = json_encode($json_response_array, true);
                    } else {
                        if (mysqli_num_rows($get_referral_percent_details) > 1) {
                            //Duplicated Details, Contact Admin
                            $json_response_array = array("desc" => "Duplicated Details, Contact Admin");
                            $json_response_encode = json_encode($json_response_array, true);
                        }
                    }
                }
            } else {
                //cannot show the error once
            }
        }
    } else {
        if ((count($level_id) < 1)) {
            //Level Field Not Available
            $json_response_array = array("desc" => "Level Field Not Available");
            $json_response_encode = json_encode($json_response_array, true);
        } else {
            if ((count($level_percent) < 1)) {
                //Level Percentage Field Not Available
                $json_response_array = array("desc" => "Level Percentage Field Not Available");
                $json_response_encode = json_encode($json_response_array, true);
            } else {
                if (count($level_id) !== count($level_percent)) {
                    //Incomplete Field
                    $json_response_array = array("desc" => "Incomplete Field");
                    $json_response_encode = json_encode($json_response_array, true);
                }
            }
        }
    }

    $_SESSION["product_purchase_response"] = $json_response_array["desc"] ?? "Settings saved";
    header("Location: " . $_SERVER["REQUEST_URI"]);
    exit;
}

if (isset($_POST["update-site-details"])) {
    bc_demo_guard_edit($connection_server);
    $site_title         = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["site-title"])));
    $site_desc          = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["site-desc"])));
    $apk_download_url   = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["apk-download-url"] ?? "")));
    // Accept "play.google.com/..." as well as a full URL. The field used to be type="url", which made
    // the BROWSER refuse to submit the form at all whenever the scheme was missing - the Save button
    // then appeared to do nothing. It is type="text" now and a missing scheme is added here instead.
    if ($apk_download_url !== '' && !preg_match('#^[a-z][a-z0-9+.-]*://#i', $apk_download_url)) {
        $apk_download_url = 'https://' . ltrim($apk_download_url, '/');
    }
    $app_redirect_mode = mysqli_real_escape_string($connection_server, strtolower(trim($_POST["app-redirect-mode"] ?? "off")));
    if (!in_array($app_redirect_mode, ["off", "prompt", "force"], true)) $app_redirect_mode = "off";
    $meta_keywords      = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["meta-keywords"] ?? "")));
    // The column is custom_head_code (see func/bc-tables.php and the live schema). It was written as
    // custom_header_code here, which made MySQL reject the WHOLE statement - so the APK download URL
    // and app redirect mode set in the same UPDATE never saved either, and the form came back empty.
    // SAAS has always used the correct name.
    $custom_head_code   = mysqli_real_escape_string($connection_server, $_POST["custom-header-code"] ?? "");
    $custom_footer_code = mysqli_real_escape_string($connection_server, $_POST["custom-footer-code"] ?? "");
    $robots_txt         = mysqli_real_escape_string($connection_server, $_POST["robots-txt"] ?? "");

    if (!empty($site_title) && !empty($site_desc)) {
        $get_site_details = mysqli_query($connection_server, "SELECT * FROM sas_site_details WHERE vendor_id='" . $get_logged_admin_details["id"] . "'");

        // sas_site_details is KEYLESS (no PRIMARY KEY, no UNIQUE KEY), so more than one row can exist
        // for the same vendor. This block used to require EXACTLY one row and did NOTHING on anything
        // else except report "Duplicated Details, Contact Admin" - so once a duplicate appeared, the
        // settings could never be saved again and the form just kept showing the old values. Updating
        // every matching row needs no index and repairs the duplicates in passing.
        if (mysqli_num_rows($get_site_details) > 0) {
            // Result checked on purpose: this used to report success no matter what, so a wrong column
            // name looked like a saving bug in the UI while nothing was actually written.
            $site_updated = mysqli_query($connection_server, "UPDATE sas_site_details SET site_title='$site_title', site_desc='$site_desc', apk_download_url='$apk_download_url', app_redirect_mode='$app_redirect_mode', meta_keywords='$meta_keywords', custom_head_code='$custom_head_code', custom_footer_code='$custom_footer_code', robots_txt='$robots_txt' WHERE vendor_id='" . $get_logged_admin_details["id"] . "'");
            if ($site_updated) {
                //Site Information Updated Successfully
                $json_response_array = array("desc" => "Site Information Updated Successfully");
            } else {
                $json_response_array = array("desc" => "Site Information Could Not Be Updated: " . mysqli_error($connection_server));
            }
        } else {
            $site_created = mysqli_query($connection_server, "INSERT INTO sas_site_details (vendor_id, site_title, site_desc, apk_download_url, app_redirect_mode, meta_keywords, custom_head_code, custom_footer_code, robots_txt) VALUES ('" . $get_logged_admin_details["id"] . "', '$site_title', '$site_desc', '$apk_download_url', '$app_redirect_mode', '$meta_keywords', '$custom_head_code', '$custom_footer_code', '$robots_txt')");
            if ($site_created) {
                //Site Information Created Successfully
                $json_response_array = array("desc" => "Site Information Created Successfully");
            } else {
                $json_response_array = array("desc" => "Site Information Could Not Be Created: " . mysqli_error($connection_server));
            }
        }
        $json_response_encode = json_encode($json_response_array, true);
        
        // Auto-compile sitemap and robots.txt physical files
        @include_once dirname(__DIR__) . '/func/seo-generator.php';
        if (function_exists('generateSitemap')) {
            generateSitemap($get_logged_admin_details["id"], $connection_server);
        }
        if (function_exists('generateRobotsTxt')) {
            generateRobotsTxt($get_logged_admin_details["id"], $connection_server);
        }
    } else {
        if (empty($site_title)) {
            //Site Title Field Empty
            $json_response_array = array("desc" => "Site Title Field Empty");
            $json_response_encode = json_encode($json_response_array, true);
        } else {
            if (empty($site_desc)) {
                //Site Desc Field Empty
                $json_response_array = array("desc" => "Site Description Field Empty");
                $json_response_encode = json_encode($json_response_array, true);
            }
        }
    }

    $_SESSION["product_purchase_response"] = $json_response_array["desc"] ?? "Settings saved";
    header("Location: " . $_SERVER["REQUEST_URI"]);
    exit;
}

$q_admin_payment = mysqli_query($connection_server, "SELECT * FROM sas_admin_payments WHERE vendor_id='" . $get_logged_admin_details["id"] . "' LIMIT 1");
$get_admin_payment_details = ($q_admin_payment && mysqli_num_rows($q_admin_payment) > 0) ? mysqli_fetch_array($q_admin_payment) : ['account_name' => '', 'bank_name' => '', 'account_number' => '', 'phone_number' => '', 'amount_charged' => 0];

$q_payment_order = mysqli_query($connection_server, "SELECT * FROM sas_admin_payment_orders WHERE vendor_id='" . $get_logged_admin_details["id"] . "' LIMIT 1");
$get_admin_payment_order_details = ($q_payment_order && mysqli_num_rows($q_payment_order) > 0) ? mysqli_fetch_array($q_payment_order) : ['min_amount' => 100, 'max_amount' => 10000];

$q_purchase_limit = mysqli_query($connection_server, "SELECT * FROM sas_daily_purchase_limit WHERE vendor_id='" . $get_logged_admin_details["id"] . "' LIMIT 1");
$get_user_daily_purchase_limit_details = ($q_purchase_limit && mysqli_num_rows($q_purchase_limit) > 0) ? mysqli_fetch_array($q_purchase_limit) : ['limit_phone' => 5, 'limit_cable' => 5, 'limit_betting' => 5, 'limit_electric' => 5];

$q_min_funding = mysqli_query($connection_server, "SELECT * FROM sas_user_minimum_funding WHERE vendor_id='" . $get_logged_admin_details["id"] . "' LIMIT 1");
$get_user_minimum_funding_details = ($q_min_funding && mysqli_num_rows($q_min_funding) > 0) ? mysqli_fetch_array($q_min_funding) : ['min_amount' => 0];

$q_site_details = mysqli_query($connection_server, "SELECT * FROM sas_site_details WHERE vendor_id='" . $get_logged_admin_details["id"] . "' LIMIT 1");
$get_site_details = ($q_site_details && mysqli_num_rows($q_site_details) > 0) ? mysqli_fetch_array($q_site_details) : ['site_title' => '', 'site_desc' => '', 'apk_download_url' => '', 'app_redirect_mode' => 'off', 'meta_keywords' => '', 'custom_head_code' => '', 'custom_footer_code' => '', 'robots_txt' => ''];


?>
<!DOCTYPE html>

<head>
    <title>Account Settings | <?php echo $get_all_super_admin_site_details["site_title"]; ?></title>
    <meta charset="UTF-8" />
    <meta name="description" content="<?php echo substr($get_all_super_admin_site_details["site_desc"], 0, 160); ?>" />
    <meta http-equiv="Content-Type" content="text/html; " />
    <meta name="theme-color" content="black" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
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
    <?php include ("../func/bc-admin-header.php"); ?>

    <?php if (bc_is_demo_mode($connection_server)): $demo_edit_open = bc_demo_edit_unlocked(); ?>
    <div class="px-3 pt-3">
      <div class="alert <?php echo $demo_edit_open ? 'alert-success' : 'alert-warning'; ?> border-0 rounded-4 d-flex flex-wrap align-items-center justify-content-between gap-2 mb-0">
        <div class="d-flex align-items-center gap-2">
          <i class="bi <?php echo $demo_edit_open ? 'bi-unlock-fill' : 'bi-lock-fill'; ?> fs-4"></i>
          <div>
            <strong>Demo Mode</strong> — website settings are
            <?php echo $demo_edit_open ? 'unlocked for this session.' : 'read-only: you can view everything, but saving is locked.'; ?>
            <?php if (!$demo_edit_open): ?><div class="small text-muted">Only the Super Admin Demo security lock key can unlock editing.</div><?php endif; ?>
          </div>
        </div>
        <?php if ($demo_edit_open): ?>
          <form method="post" class="m-0"><button name="demo-relock-session" class="btn btn-sm btn-outline-secondary fw-bold"><i class="bi bi-lock me-1"></i>Lock again</button></form>
        <?php else: ?>
          <form method="post" class="d-flex align-items-center gap-2 m-0">
            <input type="password" name="demo_unlock_key" class="form-control form-control-sm" minlength="10" style="width:220px" placeholder="Super Admin Demo lock key" required />
            <button name="demo-unlock-session" class="btn btn-sm btn-warning text-dark fw-bold"><i class="bi bi-unlock me-1"></i>Unlock editing</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

  <div class="pagetitle">
      <h1>VENDOR ACCOUNT SETTINGS</h1>
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
                    <div class="nav flex-row flex-lg-column nav-pills overflow-auto" id="v-pills-tab" role="tablist" aria-orientation="vertical" style="flex-wrap: nowrap;">
                        <button class="nav-link active text-start mb-lg-2 rounded-3 py-3 flex-fill text-nowrap" data-bs-toggle="pill" data-bs-target="#tab-branding"><i class="bi bi-palette me-2"></i> Branding & Assets</button>
                        <button class="nav-link text-start mb-lg-2 rounded-3 py-3 flex-fill text-nowrap" data-bs-toggle="pill" data-bs-target="#tab-profile"><i class="bi bi-person-badge me-2"></i> Profile Info</button>
                        <button class="nav-link text-start mb-lg-2 rounded-3 py-3 flex-fill text-nowrap" data-bs-toggle="pill" data-bs-target="#tab-kyc"><i class="bi bi-shield-check me-2"></i> Vendor KYC</button>
                        <button class="nav-link text-start mb-lg-2 rounded-3 py-3 flex-fill text-nowrap" data-bs-toggle="pill" data-bs-target="#tab-license"><i class="bi bi-award me-2"></i> License Info</button>
                        <button class="nav-link text-start mb-lg-2 rounded-3 py-3 flex-fill text-nowrap" data-bs-toggle="pill" data-bs-target="#tab-finance"><i class="bi bi-bank me-2"></i> Financial Settings</button>
                        <button class="nav-link text-start mb-lg-2 rounded-3 py-3 flex-fill text-nowrap" data-bs-toggle="pill" data-bs-target="#tab-security"><i class="bi bi-lock me-2"></i> Security & Keys</button>
                        <button class="nav-link text-start rounded-3 py-3 flex-fill text-nowrap" data-bs-toggle="pill" data-bs-target="#tab-developer"><i class="bi bi-code-slash me-2"></i> Developer Tools</button>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm border-0 rounded-4 mt-4">
                <div class="card-body p-4 text-center">
                    <div class="bg-primary bg-opacity-10 text-dark-primary rounded-circle d-flex align-items-center justify-content-center mx-auto mb-3" style="width: 60px; height: 60px;">
                        <i class="bi bi-gift-fill fs-3"></i>
                    </div>
                    <h6 class="fw-bold">Gift Card Store</h6>
                    <p class="small text-muted">Configure and install products for your gift card marketplace.</p>
                    <a href="GiftCardSetup.php" class="btn btn-primary w-100 rounded-pill btn-sm">Manage Store</a>
                </div>
            </div>
        </div>

        <!-- Settings Content -->
        <div class="col-lg-9">
            <div class="tab-content" id="v-pills-tabContent">

                <!-- Branding Tab -->
                <div class="tab-pane fade show active" id="tab-branding">
                    <div class="card shadow-sm border-0 rounded-4 mb-4">
                        <div class="card-header bg-white py-3 border-0"><h5 class="fw-bold mb-0">Branding & Theme</h5></div>
                        <div class="card-body p-4">
                            <form method="post" enctype="multipart/form-data">
                                <div class="row g-4 align-items-center mb-4">
                                    <div class="col-md-3 text-center">
                                        <?php if(file_exists("../uploaded-image/".str_replace([".",":"],"-",$_SERVER["HTTP_HOST"])."_logo.png")): ?>
                                            <img src="<?php echo $web_http_host; ?>/uploaded-image/<?php echo str_replace([".",":"],"-",$_SERVER["HTTP_HOST"]); ?>_logo.png" class="img-fluid rounded border p-2 bg-light mb-2" style="max-height: 100px;"/>
                                        <?php else: ?><div class="bg-light rounded border p-4 mb-2 small text-muted">No Logo</div><?php endif; ?>
                                    </div>
                                    <div class="col-md-9">
                                        <label class="form-label small fw-bold text-muted">WEBSITE LOGO (PNG/JPG)</label>
                                        <div class="input-group">
                                            <input name="logo" type="file" class="form-control" accept=".png,.jpg" required>
                                            <button name="change-logo" class="btn btn-primary fw-bold px-4" type="submit">Upload</button>
                                        </div>
                                    </div>
                                </div>
                            </form>
                            <hr class="my-4 opacity-50">
                            <form method="post">
                                <?php
                                    $q = mysqli_query($connection_server, "SELECT * FROM sas_vendor_style_templates WHERE vendor_id='".$get_logged_admin_details["id"]."'");
                                    $current_theme = mysqli_fetch_assoc($q);
                                ?>
                                <div class="row g-4">
                                    <div class="col-md-8">
                                        <label class="form-label small fw-bold text-muted">SELECT LANDING PAGE TEMPLATE</label>
                                        <select name="template-name" class="form-select rounded-3 mb-3">
                                            <?php foreach($style_templates as $tmpl):
                                                $selected = (($current_theme['template_name'] ?? '') == $tmpl) ? 'selected' : '';
                                            ?>
                                                <option value="<?php echo $tmpl; ?>" <?php echo $selected; ?>><?php echo str_replace(".css", "", strtoupper($tmpl)); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small fw-bold text-muted">PRIMARY THEME COLOR</label>
                                        <input type="color" name="primary-color" value="<?php echo $current_theme['primary_color'] ?? '#287bff'; ?>" class="form-control form-control-color w-100 rounded-3 mb-3" title="Choose your primary color">
                                    </div>
                                </div>
                                <button name="update-theme" class="btn btn-primary px-5 rounded-pill fw-bold">Save Custom Branding</button>
                            </form>
                        </div>
                    </div>

                    <div class="card shadow-sm border-0 rounded-4 mb-4">
                        <div class="card-header bg-white py-3 border-0"><h5 class="fw-bold mb-0">Max Daily Tx Per ID (Service Abuse Limits)</h5></div>
                        <div class="card-body p-4">
                            <form method="post">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">MAX DAILY PER PHONE (AIRTIME/DATA)</label>
                                        <input name="limit-phone" type="number" value="<?php echo $get_user_daily_purchase_limit_details['limit_phone'] ?? $get_user_daily_purchase_limit_details['limit'] ?? 5; ?>" class="form-control" required />
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">MAX DAILY PER CABLE IUC</label>
                                        <input name="limit-cable" type="number" value="<?php echo $get_user_daily_purchase_limit_details['limit_cable'] ?? 5; ?>" class="form-control" required />
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">MAX DAILY PER BETTING ID</label>
                                        <input name="limit-betting" type="number" value="<?php echo $get_user_daily_purchase_limit_details['limit_betting'] ?? 5; ?>" class="form-control" required />
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">MAX DAILY PER ELECTRIC METER</label>
                                        <input name="limit-electric" type="number" value="<?php echo $get_user_daily_purchase_limit_details['limit_electric'] ?? 5; ?>" class="form-control" required />
                                    </div>
                                </div>
                                <button name="update-daily-purchase-limit-details" class="btn btn-primary mt-4 px-5 rounded-pill fw-bold">Update Limits</button>
                            </form>
                        </div>
                    </div>

                    <div class="card shadow-sm border-0 rounded-4">
                        <div class="card-header bg-white py-3 border-0"><h5 class="fw-bold mb-0">Site Meta Information</h5></div>
                        <div class="card-body p-4">
                            <form method="post">
                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted">SITE TITLE</label>
                                    <input name="site-title" type="text" value="<?php echo htmlspecialchars($get_site_details['site_title']); ?>" class="form-control" required />
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted">SITE DESCRIPTION (SEO)</label>
                                    <textarea name="site-desc" class="form-control" rows="3" required><?php echo htmlspecialchars($get_site_details['site_desc']); ?></textarea>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted">META KEYWORDS (SEO)</label>
                                    <input name="meta-keywords" type="text" value="<?php echo htmlspecialchars($get_site_details['meta_keywords'] ?? ''); ?>" class="form-control" placeholder="vtu, airtime, data, bill payment, nigeria, cheap data" />
                                    <div class="form-text text-muted">Comma-separated keywords to help search engines catalog your site.</div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted">GOOGLE ANALYTICS / CUSTOM HEADER CODE</label>
                                    <textarea name="custom-header-code" class="form-control font-monospace" rows="4" placeholder="<!-- Place custom scripts or tracking pixels here to load before </head> -->"><?php echo htmlspecialchars($get_site_details['custom_head_code'] ?? ''); ?></textarea>
                                    <div class="form-text text-muted">Custom scripts (e.g. Google Analytics tracking code) injected in the landing page's head.</div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted">CUSTOM CHAT WIDGET / FOOTER CODE</label>
                                    <textarea name="custom-footer-code" class="form-control font-monospace" rows="4" placeholder="<!-- Place chat widgets or custom footer scripts here to load before </body> -->"><?php echo htmlspecialchars($get_site_details['custom_footer_code'] ?? ''); ?></textarea>
                                    <div class="form-text text-muted">Custom widgets (e.g. Smartsupp, Tawk.to live chat code) injected at bottom of the body.</div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted">CUSTOM ROBOTS.TXT CONTENT</label>
                                    <textarea name="robots-txt" class="form-control font-monospace" rows="4" placeholder="User-agent: *&#10;Allow: /&#10;Disallow: /bc-admin/"><?php echo htmlspecialchars($get_site_details['robots_txt'] ?? ''); ?></textarea>
                                    <div class="form-text text-muted">Custom crawl instructions for search engine bots. Leave blank to use optimized default settings.</div>
                                </div>
                                <div class="mb-4">
                                    <label class="form-label small fw-bold text-muted" for="apk-download-url-input">ANDROID APP DOWNLOAD URL</label>
                                    <input id="apk-download-url-input" name="apk-download-url" type="text" inputmode="url" value="<?php echo htmlspecialchars($get_site_details['apk_download_url'] ?? ''); ?>" class="form-control" placeholder="https://example.com/app.apk or play.google.com/store/apps/details?id=..." />
                                    <div class="form-text text-muted">Leave blank to hide the Download App button on the landing page. "https://" is added automatically if you leave it out.</div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted" for="app-redirect-mode-input">APP REDIRECT MODE</label>
                                    <select id="app-redirect-mode-input" name="app-redirect-mode" class="form-select">
                                        <option value="off" <?php echo (($get_site_details['app_redirect_mode'] ?? 'off') === 'off') ? 'selected' : ''; ?>>Off — No prompt or redirect</option>
                                        <option value="prompt" <?php echo (($get_site_details['app_redirect_mode'] ?? '') === 'prompt') ? 'selected' : ''; ?>>Prompt — Let users choose (recommended)</option>
                                        <option value="force" <?php echo (($get_site_details['app_redirect_mode'] ?? '') === 'force') ? 'selected' : ''; ?>>Force — Always redirect to app</option>
                                    </select>
                                    <div class="form-text text-muted">Choose how visitors are directed to your App Download URL above. "Prompt" lets users decide to install the app or keep using the website.</div>
                                </div>
                                <button name="update-site-details" class="btn btn-primary px-5 rounded-pill fw-bold">Update SEO & Settings</button>
                            </form>
                        </div>
                    </div>

                    <div class="card shadow-sm border-0 rounded-4 mb-4">
                        <div class="card-header bg-white py-3 border-0"><h5 class="fw-bold mb-0">User Account Upgrade Prices</h5></div>
                        <div class="card-body p-4">
                            <form method="post">
                                <div class="row g-3">
                                    <?php
                                    $account_type_array = array(1 => "Smart User", 2 => "Smart Reseller", 3 => "Smart API");
                                    foreach ($account_type_array as $type_id => $type_name) {
                                        $get_price = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT price FROM sas_user_upgrade_price WHERE vendor_id='" . $get_logged_admin_details["id"] . "' && account_type='$type_id'"));
                                        echo '
                                        <div class="col-md-4">
                                            <label class="form-label small fw-bold text-uppercase">' . $type_name . ' Price (₦)</label>
                                            <input type="hidden" name="level-id[]" value="' . $type_id . '">
                                            <input name="level-price[]" type="number" value="' . ($get_price['price'] ?? 0) . '" class="form-control" required />
                                        </div>';
                                    }
                                    ?>
                                </div>
                                <button name="update-upgrade-details" class="btn btn-primary mt-4 px-5 rounded-pill fw-bold">Update Upgrade Prices</button>
                            </form>
                        </div>
                    </div>

                </div>

                <!-- Profile Tab -->
                <div class="tab-pane fade" id="tab-profile">
                    <div class="card shadow-sm border-0 rounded-4">
                        <div class="card-header bg-white py-3 border-0"><h5 class="fw-bold mb-0">Personal Information</h5></div>
                        <div class="card-body p-4">
                            <form method="post">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">First Name</label>
                                        <input name="first" type="text" value="<?php echo $get_logged_admin_details['firstname']; ?>" class="form-control" required />
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">Last Name</label>
                                        <input name="last" type="text" value="<?php echo $get_logged_admin_details['lastname']; ?>" class="form-control" required />
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label small fw-bold">Home Address</label>
                                        <input name="address" type="text" value="<?php echo $get_logged_admin_details['home_address']; ?>" class="form-control" required />
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">Phone Number</label>
                                        <input name="phone" type="text" value="<?php echo $get_logged_admin_details['phone_number']; ?>" class="form-control" required />
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">Website Domain</label>
                                        <input type="text" value="<?php echo $get_logged_admin_details['website_url']; ?>" class="form-control bg-light" readonly />
                                    </div>
                                </div>
                                <button name="update-profile" class="btn btn-primary mt-4 px-5 rounded-pill fw-bold">Save Changes</button>
                            </form>
                            <hr class="my-5">
                            <h5 class="fw-bold mb-3">Change Account Password</h5>
                            <form method="post">
                                <div class="row g-3">
                                    <div class="col-md-4"><input name="old-pass" type="password" placeholder="Old Password" class="form-control" required /></div>
                                    <div class="col-md-4"><input name="new-pass" type="password" placeholder="New Password" class="form-control" required /></div>
                                    <div class="col-md-4"><input name="con-new-pass" type="password" placeholder="Confirm New" class="form-control" required /></div>
                                </div>
                                <button name="change-password" class="btn btn-outline-danger mt-3 px-5 rounded-pill fw-bold">Update Password</button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- KYC Tab -->
                <div class="tab-pane fade" id="tab-kyc">
                    <div class="card shadow-sm border-0 rounded-4">
                        <div class="card-header bg-white py-3 border-0"><h5 class="fw-bold mb-0">Monnify KYC</h5></div>
                        <div class="card-body p-4">
                            <?php
                            $admin_identity_provider = getIdentityProvider($get_logged_admin_details["id"]);
                            $provider_labels = ['monnify' => 'Monnify', 'dojah' => 'Dojah', 'qoreid' => 'QoreID (VerifyMe)', 'smileid' => 'Smile ID'];
                            $provider_label = $provider_labels[$admin_identity_provider] ?? ucwords($admin_identity_provider);
                            ?>
                            <div class="alert alert-info border-0 rounded-4 d-flex align-items-center mb-4">
                                <i class="bi bi-info-circle-fill fs-3 me-3"></i>
                                <div class="small">Charges: BVN Verification (₦30), NIN Verification (₦100). Uses your active Monnify payment gateway credentials when Monnify is selected. Name matching is applied on verification.</div>
                            </div>
                            <form method="post">
                                <div class="row g-3 mb-4">
                                    <?php if ($admin_identity_provider === 'monnify'): ?>
                                    <div class="col-md-6" id="bank-code-wrap">
                                        <label class="form-label small fw-bold">Settlement Bank <span class="text-muted small">(required for Monnify BVN)</span></label>
                                        <select name="bank-code" class="form-select" id="bank-code-select">
                                            <option value="">Choose Bank...</option>
                                            <?php
                                            $get_monnify_access_token_2 = json_decode(getVendorMonnifyAccessToken(), true);
                                            if ($get_monnify_access_token_2["status"] == "success") {
                                                $get_monnify_bank_lists = json_decode(getMonnifyBanks($get_monnify_access_token_2["token"]), true);
                                                if ($get_monnify_bank_lists["status"] == "success") {
                                                    foreach ($get_monnify_bank_lists["banks"] as $bank) {
                                                        $selected = ($bank["code"] == $get_logged_admin_details["bank_code"]) ? "selected" : "";
                                                        echo '<option value="' . $bank["code"] . '" '.$selected.'>' . $bank["name"] . '</option>';
                                                    }
                                                }
                                            }
                                            ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6" id="account-number-wrap">
                                        <label class="form-label small fw-bold">Account Number <span class="text-muted small">(required for Monnify BVN)</span></label>
                                        <input name="account-number" type="text" value="<?php echo $get_logged_admin_details['account_number']; ?>" class="form-control" id="account-number-input" />
                                    </div>
                                    <?php else: ?>
                                    <input type="hidden" name="bank-code" value="" />
                                    <input type="hidden" name="account-number" value="" />
                                    <?php endif; ?>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">ID Type</label>
                                        <select name="verification-type" class="form-select" id="verification-type-select" required>
                                            <option value="1">BVN (Bank Verification Number)</option>
                                            <option value="2">NIN (National Identity Number)</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">ID Number</label>
                                        <input name="bvnnin" type="text" class="form-control" placeholder="Enter 11 digit number" required />
                                    </div>
                                </div>
                                <button name="update-verification" class="btn btn-primary px-5 rounded-pill fw-bold">Submit KYC</button>
                            </form>
                            <?php if ($admin_identity_provider === 'monnify'): ?>
                            <script>
                            (function(){
                                var vtsel = document.getElementById('verification-type-select');
                                var bankWrap = document.getElementById('bank-code-wrap');
                                var accWrap  = document.getElementById('account-number-wrap');
                                var bankSel  = document.getElementById('bank-code-select');
                                var accInput = document.getElementById('account-number-input');
                                function toggle(){
                                    var isBvn = (vtsel.value == '1');
                                    bankWrap.style.display = isBvn ? '' : 'none';
                                    accWrap.style.display  = isBvn ? '' : 'none';
                                    if(bankSel)  bankSel.required  = isBvn;
                                    if(accInput) accInput.required = isBvn;
                                }
                                vtsel.addEventListener('change', toggle);
                                toggle();
                            })();
                            </script>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- License Tab -->
                <div class="tab-pane fade" id="tab-license">
                    <div class="card shadow-sm border-0 rounded-4 mb-4">
                        <div class="card-header bg-white py-3 border-0"><h5 class="fw-bold mb-0">License Information</h5></div>
                        <div class="card-body p-4">
                            <div class="d-flex align-items-center justify-content-between mb-4">
                                <div>
                                    <h6 class="mb-1">License Status</h6>
                                    <p class="small text-muted mb-0">This reflects the current activation state for your domain.</p>
                                </div>
                                <span class="badge bg-<?php echo $license_status_class; ?> py-2 px-3 fw-semibold"><?php echo $license_status_label; ?></span>
                            </div>
                            <form method="post">
                                <div class="row g-3 mb-4">
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">License Code</label>
                                        <input name="license_key" type="text" class="form-control" value="<?php echo htmlspecialchars($license_code); ?>" placeholder="Enter your activation code" required />
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">Licensed Domain</label>
                                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($license_domain); ?>" readonly />
                                    </div>
                                </div>
                                <div class="alert alert-<?php echo $license_valid ? 'success' : 'danger'; ?> border-0 rounded-4">
                                <?php if ($license_valid): ?>
                                    <div class="small">Your activation is valid for this domain. If you change your domain in the future, update the domain on the <a href="https://manager.pmhserver.name.ng/api.php" target="_blank" rel="noopener">license API server</a> using the current licensed domain above.</div>
                                <?php else: ?>
                                    <div class="small">License is not valid or could not be verified. Please confirm your activation code and domain assignment with the <a href="https://manager.pmhserver.name.ng/api.php" target="_blank" rel="noopener">license API server</a>.</div>
                                <?php endif; ?>
                            </div>
                                <div class="d-flex justify-content-between">
                                    <button type="submit" name="save-license-key" class="btn btn-primary px-4 rounded-pill">Save License Key</button>
                                    <button type="submit" name="refresh-license-status" class="btn btn-outline-primary px-4 rounded-pill">Refresh License Status</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Financial Tab -->
                <div class="tab-pane fade" id="tab-finance">
                    <div class="card shadow-sm border-0 rounded-4 mb-4">
                        <div class="card-header bg-white py-3 border-0"><h5 class="fw-bold mb-0">Payment Order Limits</h5></div>
                        <div class="card-body p-4">
                            <form method="post">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">MINIMUM FUNDING (₦)</label>
                                        <input name="min" type="number" value="<?php echo $get_admin_payment_order_details['min_amount']; ?>" class="form-control" required />
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">MAXIMUM FUNDING (₦)</label>
                                        <input name="max" type="number" value="<?php echo $get_admin_payment_order_details['max_amount']; ?>" class="form-control" required />
                                    </div>
                                </div>
                                <button name="update-payment-order-details" class="btn btn-primary mt-4 px-5 rounded-pill fw-bold">Save Limits</button>
                            </form>
                        </div>
                    </div>

                    <div class="card shadow-sm border-0 rounded-4 mb-4">
                        <div class="card-header bg-white py-3 border-0"><h5 class="fw-bold mb-0">Withdrawal Settings</h5></div>
                        <div class="card-body p-4">
                            <form method="post">
                                <?php $global_min = 100; // Minimal default ?>
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label small fw-bold">MIN WITHDRAWAL (₦)</label>
                                        <input name="min_with" type="number" min="<?php echo $global_min; ?>" value="<?php echo $get_logged_admin_details['min_withdrawal_amount'] ?? $global_min; ?>" class="form-control" />
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small fw-bold">MAX WITHDRAWAL (₦)</label>
                                        <input name="max_with" type="number" value="<?php echo $get_logged_admin_details['max_withdrawal_amount'] ?? 50000; ?>" class="form-control" />
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small fw-bold">DAILY PAYOUT LIMIT</label>
                                        <input name="daily_limit" type="number" min="1" value="<?php echo $get_logged_admin_details['daily_payout_limit'] ?? 10; ?>" class="form-control" />
                                    </div>
                                </div>
                                <div class="text-end mt-4">
                                    <button type="submit" name="update-withdrawal-settings" class="btn btn-primary px-4 fw-bold rounded-pill">Save Settings</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="card shadow-sm border-0 rounded-4">
                        <div class="card-header bg-white py-3 border-0"><h5 class="fw-bold mb-0">Manual Funding Bank Details</h5></div>
                        <div class="card-body p-4">
                            <form method="post">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">ACCOUNT NAME</label>
                                        <input name="name" type="text" value="<?php echo $get_admin_payment_details['account_name']; ?>" class="form-control" required />
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">BANK NAME</label>
                                        <input name="bank" type="text" value="<?php echo $get_admin_payment_details['bank_name']; ?>" class="form-control" required />
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">ACCOUNT NUMBER</label>
                                        <input name="number" type="text" value="<?php echo $get_admin_payment_details['account_number']; ?>" class="form-control" required />
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">CONTACT PHONE NUMBER</label>
                                        <input name="phone" type="text" value="<?php echo $get_admin_payment_details['phone_number']; ?>" class="form-control" required />
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">CHARGES (₦)</label>
                                        <input name="charges" type="number" value="<?php echo $get_admin_payment_details['amount_charged']; ?>" class="form-control" required />
                                    </div>
                                </div>
                                <button name="update-bank-details" class="btn btn-primary mt-4 px-5 rounded-pill fw-bold">Update Bank Info</button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Security Tab -->
                <div class="tab-pane fade" id="tab-security">
                    <div class="card shadow-sm border-0 rounded-4 mb-4">
                        <div class="card-header bg-white py-3 border-0"><h5 class="fw-bold mb-0 text-primary"><i class="bi bi-info-circle me-2"></i>Google Client ID Setup Guide</h5></div>
                        <div class="card-body p-4">
                            <div class="accordion" id="googleSetupGuide">
                                <div class="accordion-item border-0 mb-2 shadow-sm rounded-3 overflow-hidden">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button collapsed fw-bold small text-uppercase" type="button" data-bs-toggle="collapse" data-bs-target="#step1">
                                            Step 1: Create Google Cloud Project
                                        </button>
                                    </h2>
                                    <div id="step1" class="accordion-collapse collapse" data-bs-parent="#googleSetupGuide">
                                        <div class="accordion-body small text-muted">
                                            Go to the <a href="https://console.cloud.google.com/" target="_blank" class="fw-bold">Google Cloud Console</a>. Click on "Select a project" at the top and then "New Project". Give it a name (e.g., your website name) and click "Create".
                                        </div>
                                    </div>
                                </div>
                                <div class="accordion-item border-0 mb-2 shadow-sm rounded-3 overflow-hidden">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button collapsed fw-bold small text-uppercase" type="button" data-bs-toggle="collapse" data-bs-target="#step2">
                                            Step 2: Configure OAuth Consent Screen
                                        </button>
                                    </h2>
                                    <div id="step2" class="accordion-collapse collapse" data-bs-parent="#googleSetupGuide">
                                        <div class="accordion-body small text-muted">
                                            In the left sidebar, go to "APIs & Services" > "OAuth consent screen". Select "External" and click "Create". Fill in the App name, Support email, and Developer contact info. Click "Save and Continue" through the remaining steps.
                                        </div>
                                    </div>
                                </div>
                                <div class="accordion-item border-0 mb-2 shadow-sm rounded-3 overflow-hidden">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button collapsed fw-bold small text-uppercase" type="button" data-bs-toggle="collapse" data-bs-target="#step3">
                                            Step 3: Create OAuth 2.0 Client ID
                                        </h2>
                                    </h2>
                                    <div id="step3" class="accordion-collapse collapse" data-bs-parent="#googleSetupGuide">
                                        <div class="accordion-body small text-muted">
                                            Go to "Credentials" in the sidebar. Click "+ Create Credentials" > "OAuth client ID".
                                            <ul class="mt-2">
                                                <li><b>Application type:</b> Web application</li>
                                                <li><b>Name:</b> Enter your website name</li>
                                                <li><b>Authorized JavaScript origins:</b> Add <code>https://<?php echo $_SERVER['HTTP_HOST']; ?></code></li>
                                                <li><b>Authorized redirect URIs:</b> Add <code>https://<?php echo $_SERVER['HTTP_HOST']; ?>/web/Login.php</code></li>
                                            </ul>
                                            Click "Create". A popup will show your <b>Client ID</b>. Copy it and paste it into the "Google Client ID" field below.
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card shadow-sm border-0 rounded-4 mb-4">
                        <div class="card-header bg-white py-3 border-0"><h5 class="fw-bold mb-0">Platform Security Settings</h5></div>
                        <div class="card-body p-4">
                            <form method="post">
                                <div class="mb-4">
                                    <div class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox" name="force-pin" id="forcePin" <?php echo $get_logged_admin_details['force_security_pin'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label fw-bold" for="forcePin">Enforce Transaction PIN for all Users</label>
                                        <div class="small text-muted">Users will be forced to setup and use a 4-digit PIN for all transfers. Applies to the website, mobile apps, and API alike.</div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-bold" for="supportWhatsapp">Support WhatsApp Number</label>
                                        <input class="form-control" type="text" name="support-whatsapp" id="supportWhatsapp" placeholder="e.g. 2348012345678 or 14155552671" value="<?php echo htmlspecialchars($get_logged_admin_details['support_whatsapp'] ?? ''); ?>">
                                        <div class="small text-muted">Digits only, with country code, no leading 0 or +. Any country is fine — use whatever country your WhatsApp Business number is registered in. Powers the WhatsApp support button shown to users, including in the mobile apps.</div>
                                    </div>
                                    <div class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox" name="force-reg-otp" id="forceRegOTP" <?php echo ($get_logged_admin_details['reg_otp_enabled'] ?? 1) ? 'checked' : ''; ?>>
                                        <label class="form-check-label fw-bold" for="forceRegOTP">Registration Email OTP</label>
                                        <div class="small text-muted">Enable to send OTP verification email during user registration. Disable for instant onboarding.</div>
                                    </div>
                                    <div class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox" name="force-trans-email" id="forceTransEmail" <?php echo ($get_logged_admin_details['trans_email_enabled'] ?? 1) ? 'checked' : ''; ?>>
                                        <label class="form-check-label fw-bold" for="forceTransEmail">Transaction Email Notification</label>
                                        <div class="small text-muted">Toggle off to stop sending email notifications for every transaction.</div>
                                    </div>
                                    <div class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox" name="force-sso" id="forceSSO" <?php echo $get_logged_admin_details['force_google_sso'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label fw-bold" for="forceSSO">Enable Google One-Tap SSO</label>
                                        <div class="small text-muted">Allows users to sign in instantly using their Google account.</div>
                                    </div>
                                </div>
                                <div class="mb-4">
                                    <label class="form-label small fw-bold text-muted text-uppercase">Google Client ID (For SSO)</label>
                                    <input name="google-client-id" type="text" value="<?php echo $get_logged_admin_details['google_client_id']; ?>" class="form-control" placeholder="e.g. 12345678-abc.apps.googleusercontent.com" />
                                </div>

                                <hr class="my-4">
                                <h6 class="fw-bold mb-3"><i class="bi bi-shield-lock me-2 text-danger"></i>Admin Security PIN</h6>
                                <p class="small text-muted">Set a 4-digit PIN for sensitive admin actions like editing blog posts or crediting users.</p>
                                <div class="row g-3 mb-4">
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-muted">NEW ADMIN PIN</label>
                                        <input name="admin_pin" type="password" maxlength="4" pattern="[0-9]{4}" class="form-control" placeholder="Leave empty to keep current" inputmode="numeric"/>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-muted">CONFIRM ADMIN PIN</label>
                                        <input name="admin_pin_con" type="password" maxlength="4" pattern="[0-9]{4}" class="form-control" placeholder="Repeat new PIN" inputmode="numeric"/>
                                    </div>
                                </div>

                                <hr class="my-4">
                                <h6 class="fw-bold mb-3"><i class="bi bi-arrow-repeat me-2 text-warning"></i>Password Reset Limit</h6>
                                <p class="small text-muted">Maximum number of times an account password can be reset per day (web users, admins and the mobile app). Default is 1 — increase it to allow more resets in a single day.</p>
                                <div class="row g-3 mb-4">
                                    <div class="col-md-4">
                                        <label class="form-label small fw-bold">DAILY PASSWORD RESET LIMIT</label>
                                        <?php
                                        $opt_reset_ns = mysqli_query($connection_server, "SELECT option_value FROM sas_super_admin_options WHERE option_name='password_reset_daily_limit'");
                                        $reset_daily_limit_ns_val = (int)(mysqli_fetch_assoc($opt_reset_ns)['option_value'] ?? 1);
                                        if ($reset_daily_limit_ns_val < 1) $reset_daily_limit_ns_val = 1;
                                        ?>
                                        <input name="password_reset_daily_limit" type="number" min="1" value="<?php echo $reset_daily_limit_ns_val; ?>" class="form-control" />
                                    </div>
                                </div>

                                <hr class="my-4">
                                <h6 class="fw-bold mb-3"><i class="bi bi-envelope-at me-2 text-primary"></i>Custom SMTP Settings (Privacy & Branding)</h6>
                                <p class="small text-muted">Configure your own SMTP server to send emails from your own domain. Leave empty to use system default.</p>
                                <div class="row g-3 mb-4">
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">SMTP HOST</label>
                                        <input name="smtp_host" type="text" value="<?php echo $get_logged_admin_details['smtp_host'] ?? ''; ?>" class="form-control" placeholder="e.g. mail.yourdomain.com" />
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">SMTP USERNAME</label>
                                        <input name="smtp_user" type="text" value="<?php echo $get_logged_admin_details['smtp_user'] ?? ''; ?>" class="form-control" placeholder="e.g. info@yourdomain.com" />
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small fw-bold">SMTP PASSWORD</label>
                                        <input name="smtp_pass" type="password" value="<?php echo $get_logged_admin_details['smtp_pass'] ?? ''; ?>" class="form-control" placeholder="******" />
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small fw-bold">SMTP PORT</label>
                                        <input name="smtp_port" type="text" value="<?php echo $get_logged_admin_details['smtp_port'] ?? ''; ?>" class="form-control" placeholder="e.g. 465 or 587" />
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small fw-bold">SECURITY</label>
                                        <select name="smtp_sec" class="form-select">
                                            <option value="">None</option>
                                            <option value="ssl" <?php echo (($get_logged_admin_details['smtp_sec'] ?? '') == 'ssl') ? 'selected' : ''; ?>>SSL</option>
                                            <option value="tls" <?php echo (($get_logged_admin_details['smtp_sec'] ?? '') == 'tls') ? 'selected' : ''; ?>>TLS</option>
                                        </select>
                                    </div>
                                </div>

                                <button name="update-security-settings" class="btn btn-primary px-5 rounded-pill fw-bold">Update Security Policy</button>
                            </form>

                            <!--
                              Website Demo Mode sits OUTSIDE the security form on purpose.
                              It used to be nested inside it, and a form element inside an open form element is
                              invalid HTML: the browser ignores the inner start tag but still honours the inner
                              closing tag, which closed the security form early. Everything below that point - the
                              Admin PIN fields, the reset limit, the SMTP settings and the Update Security Policy
                              button - ended up outside every form, so clicking the button did nothing at all.
                              Keeping it separate also stops the lock-key inputs (which are required) from
                              blocking the security form's own submit with client-side validation.
                            -->
                            <?php $demo_state = bc_demo_state($connection_server); ?>
                            <div class="card border border-warning border-opacity-50 rounded-4 shadow-none mb-4">
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

                <!-- Developer Tab -->
                <div class="tab-pane fade" id="tab-developer">
                    <div class="card shadow-sm border-0 rounded-4 mb-4">
                        <div class="card-header bg-white py-3 border-0"><h5 class="fw-bold mb-0">System Cronjobs For Automation</h5></div>
                        <div class="card-body p-4">
                            <div class="mb-3">
                                <label class="form-label small fw-bold text-muted text-uppercase">Cron Requery URL</label>
                                <div class="input-group">
                                    <input type="text" value="<?php echo $web_http_host . "/automated-cron-requery.php"; ?>" class="form-control bg-light" readonly />
                                    <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.value)"><i class="bi bi-clipboard"></i></button>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-bold text-muted text-uppercase">Bulk Airtime/Data Queue Processor Cron Path</label>
                                <div class="input-group">
                                    <input type="text" value="wget -qO- <?php echo $web_http_host; ?>/cron/process_bulk_queue.php" class="form-control bg-light" readonly />
                                    <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.value)"><i class="bi bi-clipboard"></i></button>
                                </div>
                                <div class="small text-muted mt-1">Run this every 1 minute so bulk airtime/data batches keep processing in the background even if the customer closes their browser or their connection drops mid-submission.</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-bold text-muted text-uppercase">Crypto Automation Cron Path</label>
                                <div class="input-group">
                                    <input type="text" value="php <?php echo realpath(__DIR__ . '/../func/crypto-cron.php'); ?>" class="form-control bg-light" readonly />
                                    <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.value)"><i class="bi bi-clipboard"></i></button>
                                </div>
                                <div class="small text-muted mt-1">Run this every 2-5 minutes to automate crypto deposit verification.</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-bold text-muted text-uppercase">Sender ID Auto-Sync Cron Path</label>
                                <div class="input-group">
                                    <input type="text" value="php <?php echo realpath(__DIR__ . '/../cron/sync_sender_ids.php'); ?>" class="form-control bg-light" readonly />
                                    <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.value)"><i class="bi bi-clipboard"></i></button>
                                </div>
                                <div class="small text-muted mt-1">Run this every 1 hour to automatically update the status of pending Sender IDs from PhilmoreSMS.</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-bold text-muted text-uppercase">User Notifications Cron Path (Low Balance, Inactivity & Sales)</label>
                                <div class="input-group">
                                    <input type="text" value="php <?php echo realpath(__DIR__ . '/../cron/user_notifications.php'); ?>" class="form-control bg-light" readonly />
                                    <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.value)"><i class="bi bi-clipboard"></i></button>
                                </div>
                                <div class="small text-muted mt-1">Run this every 6-12 hours to send low balance alerts (weekly), inactivity reminders (7+ days), and weekly sales reports.</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-bold text-muted text-uppercase">System Automated Updates Cron Path</label>
                                <div class="input-group">
                                    <input type="text" value="php <?php echo realpath(__DIR__ . '/cron_update.php'); ?>" class="form-control bg-light" readonly />
                                    <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.value)"><i class="bi bi-clipboard"></i></button>
                                </div>
                                <div class="small text-muted mt-1">Run this once daily (e.g., at midnight) to automatically download and apply system updates with email report logs.</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-bold text-muted text-uppercase">Bulk Email Queue Processor Cron Path</label>
                                <div class="input-group">
                                    <input type="text" value="php <?php echo realpath(__DIR__ . '/../cron/process_mail_queue.php'); ?>" class="form-control bg-light" readonly />
                                    <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.value)"><i class="bi bi-clipboard"></i></button>
                                </div>
                                <div class="small text-muted mt-1">
                                    Run this every 1 minute so campaigns queued from AI Marketing Studio / Send Mail keep sending in the background at
                                    <?php echo (int)$mail_queue_batch_size; ?> email(s) per run, without the admin waiting on the page.
                                    Unlike CLI cron, hitting this over HTTP requires a secret key — use the URL below instead if your host only supports HTTP-triggered cron.
                                </div>
                                <div class="input-group mt-2">
                                    <input type="text" value="wget -qO- &quot;<?php echo $web_http_host; ?>/cron/process_mail_queue.php?key=<?php echo htmlspecialchars($mail_queue_cron_secret); ?>&quot;" class="form-control bg-light" readonly />
                                    <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.value)"><i class="bi bi-clipboard"></i></button>
                                </div>
                                <div class="small text-muted mt-1">This key is auto-generated and unique to your platform — keep it private; anyone with it could trigger your outbound mail queue.</div>

                                <form method="post" class="d-flex align-items-end gap-2 mt-3" style="max-width: 320px;">
                                    <div class="flex-grow-1">
                                        <label class="form-label small fw-bold text-muted text-uppercase mb-1">Emails Per Run</label>
                                        <input type="number" name="mail_queue_batch_size" min="1" max="100" value="<?php echo (int)$mail_queue_batch_size; ?>" class="form-control form-control-sm" />
                                    </div>
                                    <button type="submit" name="set-mail-batch-size" class="btn btn-primary btn-sm fw-bold">Save</button>
                                </form>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-bold text-muted text-uppercase">AI Weekly Health Scan &amp; Enhancement Plan Cron Path</label>
                                <div class="input-group">
                                    <input type="text" value="php <?php echo realpath(__DIR__ . '/../cron/ai_weekly_error_report.php'); ?>" class="form-control bg-light" readonly />
                                    <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.value)"><i class="bi bi-clipboard"></i></button>
                                </div>
                                <div class="small text-muted mt-1">Run once a week (e.g. every Monday) to scan PHP error logs, app logs, DB audit log, failed transactions and password-reset brute-force attempts — the AI emails an error report plus an enhancement plan to the admin.</div>
                            </div>

                            <div class="mb-1">
                                <label class="form-label small fw-bold text-muted text-uppercase d-block">System Tools & Maintenance</label>
                                <a href="cleanup_platform.php" target="_blank" class="btn btn-outline-primary btn-sm rounded-pill fw-bold px-4 py-2 mt-1">
                                    <i class="bi bi-trash-fill me-1"></i> Launch Platform Cleanup Utility
                                </a>
                                <div class="small text-muted mt-1">Safely optimize database storage logs, delete redundant cache folders, and clean outdated ZIP backups.</div>
                            </div>
                        </div>
                    </div>

                    <div class="card shadow-sm border-0 rounded-4">
                        <div class="card-header bg-white py-3 border-0"><h5 class="fw-bold mb-0">Service Webhooks</h5></div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="bg-light"><tr class="small text-uppercase"><th>Gateway Provider</th><th>Endpoint URL</th></tr></thead>
                                    <tbody>
                                        <?php
                                        $has_webhooks = false;
                                        foreach (scandir($_SERVER["DOCUMENT_ROOT"] . "/webhook") as $webhook) {
                                            if($webhook === '.' || $webhook === '..') continue;
                                            $api_website_address = str_replace("-", ".", str_replace(".php", "", $webhook));
                                            $select_api_if_exists = mysqli_query($connection_server, "SELECT * FROM sas_apis WHERE vendor_id='" . $get_logged_admin_details["id"] . "' && api_base_url='$api_website_address' LIMIT 1");
                                            if (mysqli_num_rows($select_api_if_exists) == 1) {
                                                $has_webhooks = true;
                                                echo '<tr><td class="ps-3 fw-bold">'.strtoupper($api_website_address).'</td><td class="small text-muted">'.$web_http_host.'/webhook/'.$webhook.'</td></tr>';
                                            }
                                        }
                                        if(!$has_webhooks) echo '<tr><td colspan="2" class="text-center py-4 text-muted">No active webhooks found for your configured APIs</td></tr>';
                                        ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
      </div>
    </section>

    <?php include ("../func/bc-admin-footer.php"); ?>

</body>

</html>

<?php session_start();
include ("../func/bc-admin-config.php");

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
    // constraint on option_name (see the license_key/license_domain fix in func/bc-levelup.php,
    // SAAS-only — NON-SAAS uses a different func/bc-integrity.php, so this stays inlined here
    // rather than depending on a helper that only exists in one edition).
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
    $acceptable_ext_array = array("png", "jpg", "jpeg");
    $website_edited_name = bc_vendor_asset_key();
    // Resolve storage from this file, not the PHP process working directory. The latter can
    // differ under Apache/FPM and cause the upload to be saved outside the document root.
    $logo_dir = __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "uploaded-image";
    $logo_dir = realpath($logo_dir) ?: (__DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "uploaded-image");
    $logo_target = $logo_dir . DIRECTORY_SEPARATOR . $website_edited_name . "_logo.png";

    $logo_problem = bc_upload_problem($logo_error, $logo_name, $logo_size, $logo_ext, $acceptable_ext_array, "logo");

    if ($logo_problem === null) {
        if (!is_dir($logo_dir) && !@mkdir($logo_dir, 0755, true)) {
            $logo_problem = "The uploaded-image folder could not be created. Check the parent folder permissions.";
        }
        if ($logo_problem === null && !is_dir($logo_dir)) {
            $logo_problem = "The uploaded-image folder does not exist.";
        } elseif ($logo_problem === null && !is_writable($logo_dir)) {
            $logo_problem = "The uploaded-image folder is not writable by PHP.";
        }

        if ($logo_problem === null) {
            // All templates expect _logo.png, so convert JPEG uploads to real PNG content.
            $logo_tmp_target = $logo_dir . DIRECTORY_SEPARATOR . $website_edited_name . "_logo.uploading_" . bin2hex(random_bytes(6));
            $stored = false;
            if (in_array($logo_ext, array("jpg", "jpeg"), true)) {
                if (!function_exists("imagecreatefromjpeg") || !function_exists("imagepng")) {
                    $logo_problem = "JPEG uploads require the PHP GD extension. Upload a PNG or enable GD on the server.";
                } else {
                    $image = @imagecreatefromjpeg($logo_tmp_name);
                    if ($image !== false) {
                        $stored = @imagepng($image, $logo_tmp_target, 6);
                        imagedestroy($image);
                    }
                }
            } else {
                $stored = @move_uploaded_file($logo_tmp_name, $logo_tmp_target);
            }

            $renamed = false;
            if ($logo_problem === null && $stored) {
                $renamed = @rename($logo_tmp_target, $logo_target);
                // Windows-backed development hosts may refuse to replace an existing file.
                if (!$renamed && is_file($logo_target)) {
                    @unlink($logo_target);
                    $renamed = @rename($logo_tmp_target, $logo_target);
                }
            }
            if ($logo_problem === null && $stored && $renamed) {
                clearstatcache(true, $logo_target);
                $json_response_array = array("desc" => "Website Logo Saved Successfully");
            } else {
                if (is_file($logo_tmp_target ?? "")) @unlink($logo_tmp_target);
                $json_response_array = array("desc" => $logo_problem ?: "Website Logo could NOT be saved: PHP could not write the image to " . $logo_dir . ". Check folder ownership and permissions.");
            }
        } else {
            $json_response_array = array("desc" => $logo_problem);
        }
    } else {
        $json_response_array = array("desc" => $logo_problem);
    }

    $_SESSION["product_purchase_response"] = $json_response_array["desc"] ?? "Settings saved";
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
    $website_edited_name = bc_vendor_asset_key();

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
    $_SESSION["product_purchase_response"] = $json_response_array["desc"] ?? "Settings saved";
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
    $website_edited_name = bc_vendor_asset_key();

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
    $_SESSION["product_purchase_response"] = $json_response_array["desc"] ?? "Settings saved";
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
            // The write is checked here: this branch sets only $json_response_array, and the handler
            // used to rely on $json_response_encode, which it never assigned - so the success message
            // came back empty with a "Trying to access array offset on null" warning.
            $theme_saved = mysqli_query($connection_server, "INSERT INTO sas_vendor_style_templates (vendor_id, template_name, primary_color) VALUES ('" . $get_logged_admin_details["id"] . "', '$template', '$primary_color')");
            $json_response_array = array("desc" => $theme_saved ? "Theme & Color Created Successfully" : "Theme & Color Could Not Be Created: " . mysqli_error($connection_server));
        } else {
            $theme_saved = mysqli_query($connection_server, "UPDATE sas_vendor_style_templates SET template_name='$template', primary_color='$primary_color' WHERE vendor_id='" . $get_logged_admin_details["id"] . "'");
            $json_response_array = array("desc" => $theme_saved ? "Theme & Color Updated Successfully" : "Theme & Color Could Not Be Updated: " . mysqli_error($connection_server));
        }
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
    $global_min = (float)getSuperAdminOption('default_min_withdrawal', 1000);

    if ($min_with < $global_min) {
        $_SESSION["product_purchase_response"] = "Minimum withdrawal amount must be at least ".number_format($global_min)." NGN";
    } else if ($max_with <= $min_with) {
        $_SESSION["product_purchase_response"] = "Maximum withdrawal must be greater than minimum";
    } else {
        mysqli_query($connection_server, "UPDATE sas_vendors SET min_withdrawal_amount='$min_with', max_withdrawal_amount='$max_with' WHERE id='" . $get_logged_admin_details["id"] . "'");
        $_SESSION["product_purchase_response"] = "Withdrawal limits updated successfully";
    }
    header("Location: " . $_SERVER["REQUEST_URI"]);
    exit();
}

if (isset($_POST["update-security-settings"])) {
    $force_pin = isset($_POST["force-pin"]) ? 1 : 0;
    $force_otp = isset($_POST["force-reg-otp"]) ? 1 : 0;
    $force_email = isset($_POST["force-trans-email"]) ? 1 : 0;
    $force_sso = isset($_POST["force-sso"]) ? 1 : 0;
    // Per-vendor login OTP. Stored explicitly so this vendor's choice overrides the
    // super-admin global default from here on, without touching any other vendor.
    $login_otp = isset($_POST["force-login-otp"]) ? 1 : 0;
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

    mysqli_query($connection_server, "UPDATE sas_vendors SET force_security_pin='$force_pin', reg_otp_enabled='$force_otp', trans_email_enabled='$force_email', login_otp_enabled='$login_otp', force_google_sso='$force_sso', google_client_id='$google_id', smtp_host='$smtp_host', smtp_user='$smtp_user', smtp_pass='$smtp_pass', smtp_port='$smtp_port', smtp_sec='$smtp_sec', support_whatsapp='$support_whatsapp' WHERE id='" . $get_logged_admin_details["id"] . "'");

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
    $site_title     = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["site-title"])));
    $site_desc      = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["site-desc"])));
    $apk_download_url = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["apk-download-url"] ?? "")));
    $app_redirect_mode = mysqli_real_escape_string($connection_server, strtolower(trim($_POST["app-redirect-mode"] ?? "off")));
    if (!in_array($app_redirect_mode, ["off", "prompt", "force"], true)) $app_redirect_mode = "off";
    
    // New SEO Fields
    $meta_keywords = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["meta-keywords"] ?? "")));
    $meta_author = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["meta-author"] ?? "")));
    $favicon_url = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["favicon-url"] ?? "")));
    $og_image = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["og-image"] ?? "")));
    
    // Integration & Analytics Fields
    $ga_tracking_id = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["ga-tracking-id"] ?? "")));
    $gtm_id = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["gtm-id"] ?? "")));
    $fb_pixel_id = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["fb-pixel-id"] ?? "")));
    
    // Custom injected code (NOT strip_tags to allow raw scripts, but escaped for DB safety)
    $custom_head_code = mysqli_real_escape_string($connection_server, trim($_POST["custom-head-code"] ?? ""));
    $custom_footer_code = mysqli_real_escape_string($connection_server, trim($_POST["custom-footer-code"] ?? ""));
    $robots_txt = mysqli_real_escape_string($connection_server, trim($_POST["robots-txt"] ?? ""));
    $sitemap_enabled = isset($_POST["sitemap-enabled"]) ? 1 : 0;
    
    // Social Fields
    $social_twitter = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["social-twitter"] ?? "")));
    $social_facebook = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["social-facebook"] ?? "")));
    $social_instagram = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["social-instagram"] ?? "")));
    $social_whatsapp = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["social-whatsapp"] ?? "")));
    
    // Schema.org Fields
    $schema_org_type = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["schema-org-type"] ?? "Organization")));
    $schema_org_phone = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["schema-org-phone"] ?? "")));
    $schema_org_address = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["schema-org-address"] ?? "")));

    if (!empty($site_title) && !empty($site_desc)) {
        $get_site_details_check = mysqli_query($connection_server, "SELECT * FROM sas_site_details WHERE vendor_id='" . $get_logged_admin_details["id"] . "'");

        if (mysqli_num_rows($get_site_details_check) == 1) {
            mysqli_query($connection_server, "UPDATE sas_site_details SET 
                site_title='$site_title', 
                site_desc='$site_desc', 
                apk_download_url='$apk_download_url',
                app_redirect_mode='$app_redirect_mode',
                meta_keywords='$meta_keywords',
                meta_author='$meta_author',
                favicon_url='$favicon_url',
                og_image='$og_image',
                ga_tracking_id='$ga_tracking_id',
                gtm_id='$gtm_id',
                fb_pixel_id='$fb_pixel_id',
                custom_head_code='$custom_head_code',
                custom_footer_code='$custom_footer_code',
                robots_txt='$robots_txt',
                sitemap_enabled='$sitemap_enabled',
                social_twitter='$social_twitter',
                social_facebook='$social_facebook',
                social_instagram='$social_instagram',
                social_whatsapp='$social_whatsapp',
                schema_org_type='$schema_org_type',
                schema_org_phone='$schema_org_phone',
                schema_org_address='$schema_org_address'
                WHERE vendor_id='" . $get_logged_admin_details["id"] . "'");
            
            // Site Information Updated Successfully
            $json_response_array = array("desc" => "Site Information & SEO Settings Updated Successfully");
            $json_response_encode = json_encode($json_response_array, true);
        } else {
            if (mysqli_num_rows($get_site_details_check) == 0) {
                mysqli_query($connection_server, "INSERT INTO sas_site_details (
                    vendor_id, site_title, site_desc, apk_download_url, app_redirect_mode,
                    meta_keywords, meta_author, favicon_url, og_image,
                    ga_tracking_id, gtm_id, fb_pixel_id, custom_head_code,
                    custom_footer_code, robots_txt, sitemap_enabled,
                    social_twitter, social_facebook, social_instagram, social_whatsapp,
                    schema_org_type, schema_org_phone, schema_org_address
                ) VALUES (
                    '" . $get_logged_admin_details["id"] . "', '$site_title', '$site_desc', '$apk_download_url', '$app_redirect_mode',
                    '$meta_keywords', '$meta_author', '$favicon_url', '$og_image',
                    '$ga_tracking_id', '$gtm_id', '$fb_pixel_id', '$custom_head_code',
                    '$custom_footer_code', '$robots_txt', '$sitemap_enabled',
                    '$social_twitter', '$social_facebook', '$social_instagram', '$social_whatsapp',
                    '$schema_org_type', '$schema_org_phone', '$schema_org_address'
                )");
                
                // Site Information Created Successfully
                $json_response_array = array("desc" => "Site Information & SEO Settings Created Successfully");
                $json_response_encode = json_encode($json_response_array, true);
            } else {
                if (mysqli_num_rows($get_site_details_check) > 1) {
                    // Duplicated Details, Contact Admin
                    $json_response_array = array("desc" => "Duplicated Details, Contact Admin");
                    $json_response_encode = json_encode($json_response_array, true);
                }
            }
        }
        
        // Cache Busting
        if (function_exists('bc_cache_delete')) {
            $host = strtolower(trim(explode(':', $_SERVER["HTTP_HOST"])[0] ?? ''));
            bc_cache_delete('vendor_details_' . md5($host));
            bc_cache_delete('vendor_site_style_' . md5((int)$get_logged_admin_details["id"]));
        }
    } else {
        if (empty($site_title)) {
            // Site Title Field Empty
            $json_response_array = array("desc" => "Site Title Field Empty");
            $json_response_encode = json_encode($json_response_array, true);
        } else {
            if (empty($site_desc)) {
                // Site Desc Field Empty
                $json_response_array = array("desc" => "Site Description Field Empty");
                $json_response_encode = json_encode($json_response_array, true);
            }
        }
    }

    $_SESSION["product_purchase_response"] = $json_response_array["desc"] ?? "Settings saved";
    header("Location: " . $_SERVER["REQUEST_URI"]);
    exit();
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
$seo_defaults = [
    'site_title' => '',
    'site_desc' => '',
    'apk_download_url' => '',
    'meta_keywords' => '',
    'meta_author' => '',
    'favicon_url' => '',
    'og_image' => '',
    'ga_tracking_id' => '',
    'gtm_id' => '',
    'fb_pixel_id' => '',
    'custom_head_code' => '',
    'custom_footer_code' => '',
    'robots_txt' => '',
    'sitemap_enabled' => 1,
    'social_twitter' => '',
    'social_facebook' => '',
    'social_instagram' => '',
    'social_whatsapp' => '',
    'schema_org_type' => 'Organization',
    'schema_org_phone' => '',
    'schema_org_address' => '',
    'app_redirect_mode' => 'off'
];
if ($q_site_details && mysqli_num_rows($q_site_details) > 0) {
    $get_site_details = array_merge($seo_defaults, mysqli_fetch_array($q_site_details, MYSQLI_ASSOC));
} else {
    $get_site_details = $seo_defaults;
}


?>
<!DOCTYPE html>

<head>
    <title>Account Settings | <?php echo $get_all_super_admin_site_details["site_title"] ?? ''; ?></title>
    <meta charset="UTF-8" />
    <meta name="description" content="<?php echo substr($get_all_super_admin_site_details["site_desc"] ?? '', 0, 160); ?>" />
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
                                        <?php if(file_exists(__DIR__ . "/../uploaded-image/" . bc_vendor_asset_key() . "_logo.png")): ?>
                                            <img src="<?php echo $web_http_host; ?>/uploaded-image/<?php echo htmlspecialchars(bc_vendor_asset_key(), ENT_QUOTES, 'UTF-8'); ?>_logo.png" class="img-fluid rounded border p-2 bg-light mb-2" style="max-height: 100px;"/>
                                        <?php else: ?><div class="bg-light rounded border p-4 mb-2 small text-muted">No Logo</div><?php endif; ?>
                                    </div>
                                    <div class="col-md-9">
                                        <label class="form-label small fw-bold text-muted">WEBSITE LOGO (PNG/JPG)</label>
                                        <div class="input-group">
                                            <input name="logo" type="file" class="form-control" accept=".png,.jpg,.jpeg" required>
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

                    <!-- Premium Per-Vendor SEO & Custom Code Suite -->
                    <div class="card shadow-sm border-0 rounded-4">
                        <div class="card-header bg-white py-3 border-0">
                            <h5 class="fw-bold mb-0 text-primary d-flex align-items-center">
                                <i class="bi bi-search me-2"></i> SEO, Analytics & Custom Code Control Suite
                            </h5>
                        </div>
                        <div class="card-body p-4">
                            <ul class="nav nav-tabs nav-tabs-buffered mb-4" id="seoTabs" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active fw-bold small" id="seo-basic-tab" data-bs-toggle="tab" data-bs-target="#seo-basic" type="button" role="tab"><i class="bi bi-globe me-1"></i> Basic SEO</button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link fw-bold small" id="seo-social-tab" data-bs-toggle="tab" data-bs-target="#seo-social" type="button" role="tab"><i class="bi bi-share me-1"></i> Social & OG</button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link fw-bold small" id="seo-analytics-tab" data-bs-toggle="tab" data-bs-target="#seo-analytics" type="button" role="tab"><i class="bi bi-graph-up me-1"></i> Analytics & Pixels</button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link fw-bold small" id="seo-code-tab" data-bs-toggle="tab" data-bs-target="#seo-code" type="button" role="tab"><i class="bi bi-code me-1"></i> Injected Code</button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link fw-bold small" id="seo-sitemap-tab" data-bs-toggle="tab" data-bs-target="#seo-sitemap" type="button" role="tab"><i class="bi bi-map me-1"></i> Sitemap & Robots</button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link fw-bold small" id="seo-schema-tab" data-bs-toggle="tab" data-bs-target="#seo-schema" type="button" role="tab"><i class="bi bi-diagram-3 me-1"></i> Schema.org</button>
                                </li>
                            </ul>
                            
                            <form method="post">
                                <div class="tab-content" id="seoTabsContent">
                                    
                                    <!-- 1. Basic SEO -->
                                    <div class="tab-pane fade show active" id="seo-basic" role="tabpanel">
                                        <div class="mb-3">
                                            <label class="form-label small fw-bold text-muted">SITE TITLE</label>
                                            <input name="site-title" type="text" value="<?php echo htmlspecialchars($get_site_details['site_title'] ?? ''); ?>" class="form-control rounded-3" placeholder="Enter your website business name" required />
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label small fw-bold text-muted">META DESCRIPTION (SEO)</label>
                                            <textarea name="site-desc" class="form-control rounded-3" rows="3" placeholder="Write a catchy 150-160 character description of your fintech..." required><?php echo htmlspecialchars($get_site_details['site_desc'] ?? ''); ?></textarea>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label small fw-bold text-muted">META KEYWORDS (COMMA-SEPARATED)</label>
                                            <input name="meta-keywords" type="text" value="<?php echo htmlspecialchars($get_site_details['meta_keywords'] ?? ''); ?>" class="form-control rounded-3" placeholder="vtu, bill payments, data recharge, airtime to cash" />
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label small fw-bold text-muted">META AUTHOR</label>
                                            <input name="meta-author" type="text" value="<?php echo htmlspecialchars($get_site_details['meta_author'] ?? ''); ?>" class="form-control rounded-3" placeholder="Philmore Codes" />
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label small fw-bold text-muted">CUSTOM FAVICON URL</label>
                                            <input name="favicon-url" type="url" value="<?php echo htmlspecialchars($get_site_details['favicon_url'] ?? ''); ?>" class="form-control rounded-3" placeholder="https://example.com/assets/favicon.ico" />
                                            <div class="form-text small text-muted">Favicon URL. If left empty, the uploaded site logo will be used as a favicon automatically.</div>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label small fw-bold text-muted" for="apk-download-url-input">ANDROID APP DOWNLOAD URL</label>
                                            <input id="apk-download-url-input" name="apk-download-url" type="url" value="<?php echo htmlspecialchars($get_site_details['apk_download_url'] ?? ''); ?>" class="form-control rounded-3" placeholder="https://example.com/app.apk or Google Play link" />
                                            <div class="form-text small text-muted">Leave blank to hide the Download App button on the landing page.</div>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label small fw-bold text-muted" for="app-redirect-mode-input">APP REDIRECT MODE</label>
                                            <select id="app-redirect-mode-input" name="app-redirect-mode" class="form-select rounded-3">
                                                <option value="off" <?php echo (($get_site_details['app_redirect_mode'] ?? 'off') === 'off') ? 'selected' : ''; ?>>Off — No prompt or redirect</option>
                                                <option value="prompt" <?php echo (($get_site_details['app_redirect_mode'] ?? '') === 'prompt') ? 'selected' : ''; ?>>Prompt — Let users choose (recommended)</option>
                                                <option value="force" <?php echo (($get_site_details['app_redirect_mode'] ?? '') === 'force') ? 'selected' : ''; ?>>Force — Always redirect to app</option>
                                            </select>
                                            <div class="form-text small text-muted">Choose how visitors are directed to your App Download URL above. "Prompt" lets users decide to install the app or keep using the website.</div>
                                        </div>
                                    </div>
                                    
                                    <!-- 2. Social & OG -->
                                    <div class="tab-pane fade" id="seo-social" role="tabpanel">
                                        <div class="mb-3">
                                            <label class="form-label small fw-bold text-muted">OPEN GRAPH SHARE IMAGE URL</label>
                                            <input name="og-image" type="url" value="<?php echo htmlspecialchars($get_site_details['og_image'] ?? ''); ?>" class="form-control rounded-3" placeholder="https://example.com/assets/banner.jpg" />
                                            <div class="form-text small text-muted">Recommended dimension: 1200x630px. If left empty, site logo will be used.</div>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label small fw-bold text-muted">TWITTER/X HANDLE</label>
                                            <div class="input-group">
                                                <span class="input-group-text">@</span>
                                                <input name="social-twitter" type="text" value="<?php echo htmlspecialchars(ltrim($get_site_details['social_twitter'] ?? '', '@')); ?>" class="form-control rounded-3" placeholder="MyBrand" />
                                            </div>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label small fw-bold text-muted">FACEBOOK PAGE URL</label>
                                            <input name="social-facebook" type="url" value="<?php echo htmlspecialchars($get_site_details['social_facebook'] ?? ''); ?>" class="form-control rounded-3" placeholder="https://facebook.com/MyBrand" />
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label small fw-bold text-muted">INSTAGRAM PROFILE URL</label>
                                            <input name="social-instagram" type="url" value="<?php echo htmlspecialchars($get_site_details['social_instagram'] ?? ''); ?>" class="form-control rounded-3" placeholder="https://instagram.com/MyBrand" />
                                        </div>
                                    </div>

                                    <!-- 3. Analytics & Pixels -->
                                    <div class="tab-pane fade" id="seo-analytics" role="tabpanel">
                                        <div class="mb-3">
                                            <label class="form-label small fw-bold text-muted">GOOGLE ANALYTICS (GA4) MEASUREMENT ID</label>
                                            <input name="ga-tracking-id" type="text" value="<?php echo htmlspecialchars($get_site_details['ga_tracking_id'] ?? ''); ?>" class="form-control rounded-3" placeholder="G-XXXXXXXXXX" />
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label small fw-bold text-muted">GOOGLE TAG MANAGER (GTM) CONTAINER ID</label>
                                            <input name="gtm-id" type="text" value="<?php echo htmlspecialchars($get_site_details['gtm_id'] ?? ''); ?>" class="form-control rounded-3" placeholder="GTM-XXXXXXX" />
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label small fw-bold text-muted">FACEBOOK/META PIXEL ID</label>
                                            <input name="fb-pixel-id" type="text" value="<?php echo htmlspecialchars($get_site_details['fb_pixel_id'] ?? ''); ?>" class="form-control rounded-3" placeholder="123456789012345" />
                                        </div>
                                    </div>
                                    
                                    <!-- 4. Injected Code -->
                                    <div class="tab-pane fade" id="seo-code" role="tabpanel">
                                        <div class="alert alert-warning border-0 rounded-4 d-flex align-items-center mb-3">
                                            <i class="bi bi-exclamation-triangle-fill fs-3 me-3"></i>
                                            <div class="small">
                                                <strong>Security Warning:</strong> Only paste code from trusted analytics, chat services, or pixel suppliers. Avoid scripts you do not understand.
                                            </div>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label small fw-bold text-muted">CUSTOM HEADER INJECTION CODE (INSIDE &lt;head&gt;)</label>
                                            <textarea name="custom-head-code" class="form-control rounded-3 font-monospace" rows="5" style="font-size:0.85rem;" placeholder="<!-- Custom head scripts, verification tags, CSS styles -->"><?php echo htmlspecialchars($get_site_details['custom_head_code'] ?? ''); ?></textarea>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label small fw-bold text-muted">CUSTOM FOOTER INJECTION CODE (BEFORE &lt;/body&gt;)</label>
                                            <textarea name="custom-footer-code" class="form-control rounded-3 font-monospace" rows="5" style="font-size:0.85rem;" placeholder="<!-- Live Chat widgets, popup widgets, custom tracking footer script -->"><?php echo htmlspecialchars($get_site_details['custom_footer_code'] ?? ''); ?></textarea>
                                        </div>
                                    </div>
                                    
                                    <!-- 5. Sitemap & Robots -->
                                    <div class="tab-pane fade" id="seo-sitemap" role="tabpanel">
                                        <?php
                                            $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://';
                                            $sitemap_url = $protocol . $_SERVER['HTTP_HOST'] . '/sitemap.xml';
                                            $robots_url = $protocol . $_SERVER['HTTP_HOST'] . '/robots.txt';
                                        ?>
                                        <div class="row g-4 mb-4">
                                            <div class="col-md-6">
                                                <div class="p-3 border rounded-3 bg-light">
                                                    <h6 class="fw-bold"><i class="bi bi-file-earmark-code me-1 text-primary"></i> XML Sitemap</h6>
                                                    <p class="small text-muted mb-2">Automated SEO sitemap that updates dynamically.</p>
                                                    <div class="input-group input-group-sm">
                                                        <input type="text" class="form-control" value="<?php echo $sitemap_url; ?>" readonly id="sitemap-url-box">
                                                        <button class="btn btn-primary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('sitemap-url-box').value); alert('Copied Sitemap URL!');">Copy</button>
                                                        <a href="/sitemap.xml" target="_blank" class="btn btn-outline-secondary"><i class="bi bi-box-arrow-up-right"></i></a>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="p-3 border rounded-3 bg-light">
                                                    <h6 class="fw-bold"><i class="bi bi-robot me-1 text-success"></i> robots.txt</h6>
                                                    <p class="small text-muted mb-2">Directives for SEO search engine crawlers.</p>
                                                    <div class="input-group input-group-sm">
                                                        <input type="text" class="form-control" value="<?php echo $robots_url; ?>" readonly id="robots-url-box">
                                                        <button class="btn btn-success" type="button" onclick="navigator.clipboard.writeText(document.getElementById('robots-url-box').value); alert('Copied robots.txt URL!');">Copy</button>
                                                        <a href="/robots.txt" target="_blank" class="btn btn-outline-secondary"><i class="bi bi-box-arrow-up-right"></i></a>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="form-check form-switch mb-3">
                                            <input class="form-check-input" type="checkbox" name="sitemap-enabled" id="sitemap-enabled-switch" <?php echo ($get_site_details['sitemap_enabled'] == 1) ? 'checked' : ''; ?>>
                                            <label class="form-check-label fw-bold small" for="sitemap-enabled-switch">ENABLE DYNAMIC XML SITEMAP</label>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label small fw-bold text-muted">CUSTOM ROBOTS.TXT CONTENT</label>
                                            <textarea name="robots-txt" class="form-control rounded-3 font-monospace" rows="5" style="font-size:0.85rem;" placeholder="User-agent: *&#10;Allow: /&#10;Disallow: /bc-admin/"><?php echo htmlspecialchars($get_site_details['robots_txt'] ?? ''); ?></textarea>
                                            <div class="form-text small text-muted">Leave empty to use recommended default robots directives. Use {sitemap_url} as placeholder to link sitemap dynamically.</div>
                                        </div>
                                    </div>
                                    
                                    <!-- 6. Schema.org -->
                                    <div class="tab-pane fade" id="seo-schema" role="tabpanel">
                                        <div class="mb-3">
                                            <label class="form-label small fw-bold text-muted">BUSINESS TYPE (SCHEMA.ORG)</label>
                                            <select name="schema-org-type" class="form-select rounded-3">
                                                <option value="Organization" <?php echo ($get_site_details['schema_org_type'] === 'Organization') ? 'selected' : ''; ?>>Organization</option>
                                                <option value="LocalBusiness" <?php echo ($get_site_details['schema_org_type'] === 'LocalBusiness') ? 'selected' : ''; ?>>Local Business</option>
                                                <option value="FinancialService" <?php echo ($get_site_details['schema_org_type'] === 'FinancialService') ? 'selected' : ''; ?>>Financial Service</option>
                                                <option value="Store" <?php echo ($get_site_details['schema_org_type'] === 'Store') ? 'selected' : ''; ?>>Store / Merchant</option>
                                            </select>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label small fw-bold text-muted">SUPPORT PHONE NUMBER</label>
                                            <input name="schema-org-phone" type="text" value="<?php echo htmlspecialchars($get_site_details['schema_org_phone'] ?? ''); ?>" class="form-control rounded-3" placeholder="+2348000000000" />
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label small fw-bold text-muted">BUSINESS PHYSICAL ADDRESS</label>
                                            <input name="schema-org-address" type="text" value="<?php echo htmlspecialchars($get_site_details['schema_org_address'] ?? ''); ?>" class="form-control rounded-3" placeholder="No 123 fintech avenue, Lagos, Nigeria" />
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mt-4 pt-3 border-top">
                                    <button name="update-site-details" class="btn btn-primary px-5 rounded-pill fw-bold" type="submit">
                                        <i class="bi bi-check-circle me-1"></i> Save SEO & Integration Settings
                                    </button>
                                </div>
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
                        <div class="card-header bg-white py-3 border-0"><h5 class="fw-bold mb-0">Vendor Verification</h5></div>
                        <div class="card-body p-4">
                            <?php
                            $admin_identity_provider = getIdentityProvider($get_logged_admin_details["id"]);
                            $provider_labels = ['monnify' => 'Monnify', 'prembly' => 'Prembly (IdentityPass)', 'dojah' => 'Dojah', 'qoreid' => 'QoreID (VerifyMe)', 'smileid' => 'Smile ID', 'localhost' => 'Local Marketplace'];
                            $provider_label = $provider_labels[$admin_identity_provider] ?? ucwords($admin_identity_provider);
                            ?>
                            <div class="alert alert-info border-0 rounded-4 d-flex align-items-center mb-4">
                                <i class="bi bi-info-circle-fill fs-3 me-3"></i>
                                <div class="small">Charges: BVN Verification (₦30), NIN Verification (₦100). Identity provider: <strong><?php echo htmlspecialchars($provider_label); ?></strong>. Name matching is applied on verification.</div>
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
                                <?php $global_min = (float)getSuperAdminOption('default_min_withdrawal', 1000); ?>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">MIN WITHDRAWAL (₦) - Min <?php echo number_format($global_min); ?></label>
                                        <input name="min_with" type="number" min="<?php echo $global_min; ?>" value="<?php echo $get_logged_admin_details['min_withdrawal_amount'] ?? $global_min; ?>" class="form-control" readonly />
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">MAX WITHDRAWAL (₦)</label>
                                        <input name="max_with" type="number" value="<?php echo $get_logged_admin_details['max_withdrawal_amount'] ?? 50000; ?>" class="form-control" readonly />
                                    </div>
                                </div>
                                <div class="alert alert-warning border-0 rounded-3 mt-4 small fw-bold">
                                    <i class="bi bi-info-circle me-2"></i> Withdrawal limits are managed by the Super Administrator.
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
                                        <input class="form-check-input" type="checkbox" name="force-login-otp" id="forceLoginOTP" <?php echo bc_admin_login_otp_required($get_logged_admin_details) ? 'checked' : ''; ?>>
                                        <label class="form-check-label fw-bold" for="forceLoginOTP">Require Login OTP for My Admin Account</label>
                                        <div class="small text-muted">When on, bc-admin requires a one-time code emailed to you each time you sign in. Turning it off affects your own account only — other vendors keep their own setting.</div>
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
                                            <option value="ssl" <?php echo ($get_logged_admin_details['smtp_sec'] == 'ssl') ? 'selected' : ''; ?>>SSL</option>
                                            <option value="tls" <?php echo ($get_logged_admin_details['smtp_sec'] == 'tls') ? 'selected' : ''; ?>>TLS</option>
                                        </select>
                                    </div>
                                </div>

                                <button name="update-security-settings" class="btn btn-primary px-5 rounded-pill fw-bold">Update Security Policy</button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Developer Tab -->
                <div class="tab-pane fade" id="tab-developer">
                    <div class="card shadow-sm border-0 rounded-4 mb-4">
                        <div class="card-header bg-white py-3 border-0"><h5 class="fw-bold mb-0">System Cronjobs For Automation</h5></div>
                        <div class="card-body p-4">
                            <div class="alert alert-info border-0 rounded-4 p-3 mb-4 small">
                                <i class="bi bi-info-circle-fill me-1"></i>
                                These cron jobs are configured centrally by the <strong>Super Admin</strong> on the platform server and run for all vendors automatically — you do not need to set them up individually. If background processing (auto-requery, bulk queue, notifications, etc.) is not working, please contact the Super Admin to confirm the platform cron jobs are active.
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-bold text-muted text-uppercase">Cron Requery Processor Cron Path</label>
                                <div class="input-group">
                                    <input type="text" value="php <?php echo realpath(__DIR__ . '/../cron/process_requery_queue.php'); ?>" class="form-control bg-light" readonly />
                                    <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.value)"><i class="bi bi-clipboard"></i></button>
                                </div>
                                <div class="small text-muted mt-1">Run every 1 minute to automatically re-check pending transactions and settle (charge/refund) them for all vendors.</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-bold text-muted text-uppercase">Bulk Airtime/Data Queue Processor Cron Path</label>
                                <div class="input-group">
                                    <input type="text" value="php <?php echo realpath(__DIR__ . '/../cron/process_bulk_queue.php'); ?>" class="form-control bg-light" readonly />
                                    <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.value)"><i class="bi bi-clipboard"></i></button>
                                </div>
                                <div class="small text-muted mt-1">Run this every 1 minute so bulk airtime/data batches keep processing in the background even if the customer closes their browser or their connection drops mid-submission.</div>
                                <div class="input-group mt-2">
                                    <input type="text" value="wget -qO- <?php echo $web_http_host; ?>/cron/process_bulk_queue.php" class="form-control bg-light" readonly />
                                    <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.value)"><i class="bi bi-clipboard"></i></button>
                                </div>
                                <div class="small text-muted mt-1">HTTP fallback (only if your host supports HTTP-triggered cron).</div>
                            <div class="mb-3">
                                <label class="form-label small fw-bold text-muted text-uppercase">Crypto Automation Cron Path</label>
                                <div class="input-group">
                                    <input type="text" value="php <?php echo realpath(__DIR__ . '/../func/crypto-cron.php'); ?>" class="form-control bg-light" readonly />
                                    <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.value)"><i class="bi bi-clipboard"></i></button>
                                </div>
                                <div class="small text-muted mt-1">Run this every 2-5 minutes to automate crypto deposit verification.</div>
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
                                <label class="form-label small fw-bold text-muted text-uppercase">Sender ID Auto-Sync Cron Path</label>
                                <div class="input-group">
                                    <input type="text" value="php <?php echo realpath(__DIR__ . '/../cron/sync_sender_ids.php'); ?>" class="form-control bg-light" readonly />
                                    <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.value)"><i class="bi bi-clipboard"></i></button>
                                </div>
                                <div class="small text-muted mt-1">Run this every 1 hour to automatically update the status of pending Sender IDs from PhilmoreSMS.</div>
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

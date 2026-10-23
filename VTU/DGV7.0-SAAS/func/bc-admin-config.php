<?php
if (isset($_SERVER["HTTPS"]) && ($_SERVER["HTTPS"] == "on")) {
    $web_http_host = "https://" . $_SERVER["HTTP_HOST"];
} else {
    $web_http_host = "http://" . $_SERVER["HTTP_HOST"];
}

// Standardize session_start across the platform
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include_once(__DIR__ . "/bc-connect.php");

if (isset($GLOBALS['bc_integrity_fail']) && $GLOBALS['bc_integrity_fail'] === true) {
    header("HTTP/1.1 503 Service Temporarily Unavailable");
    echo '<!DOCTYPE html><html><head><title>System Maintenance</title><meta name="viewport" content="width=device-width, initial-scale=1"><link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600&display=swap" rel="stylesheet"><style>body{background:#0b0f19;color:#f3f4f6;font-family:\'Outfit\',sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;padding:20px;text-align:center;box-sizing:border-box}.card{background:#111827;border:1px solid #1f2937;border-radius:12px;padding:40px;max-width:500px;box-shadow:0 10px 15px -3px rgba(0,0,0,0.5)}.icon{font-size:48px;color:#3b82f6;margin-bottom:20px}h1{font-size:24px;margin:0 0 10px 0;font-weight:600}p{color:#9ca3af;font-size:15px;line-height:1.6;margin:0 0 20px 0}</style></head><body><div class="card"><div class="icon">⚙️</div><h1>System Under Maintenance</h1><p>We are currently performing scheduled system updates to improve performance and stability. Please check back shortly.</p></div></body></html>';
    exit;
}

if (!$connection_server) {
    if (!in_array(explode("?", trim($_SERVER["REQUEST_URI"]))[0], array("/bc-admin/Error.php", "/index.php"))) {
        header("Location: /bc-admin/Error.php");
        exit();
    }
}
include_once(__DIR__ . "/bc-tables.php");
include_once(__DIR__ . "/bc-email-templates.php");
include_once(__DIR__ . "/email-design.php");
include_once(__DIR__ . "/bc-security.php"); // DGV6.90 AI Edition — Security utilities

if ($connection_server) {
    // Branch DG6.7 Optimization: Only run migrations if not already done in current session
    // This significantly improves site-wide page load speeds by skipping redundant DB structural checks.
    if (!isset($_SESSION['migrations_completed_version']) || $_SESSION['migrations_completed_version'] !== '6.9.1') {
        include_once(__DIR__ . "/bc-tables.php");
        include_once(__DIR__ . "/bc-email-templates.php");
        $_SESSION['migrations_completed_version'] = '6.9.1';
    }

    $get_all_super_admin_site_details_query = mysqli_query($connection_server, "SELECT * FROM sas_super_admin_site_details LIMIT 1");
    $get_all_super_admin_site_details = ($get_all_super_admin_site_details_query && mysqli_num_rows($get_all_super_admin_site_details_query) > 0) ? mysqli_fetch_array($get_all_super_admin_site_details_query) : null;

    $checkmate_super_admin_table_exists = mysqli_query($connection_server, "SELECT * FROM sas_super_admin");
    if ($checkmate_super_admin_table_exists && mysqli_num_rows($checkmate_super_admin_table_exists) >= 1) {
        //Admin To Vendor Login Method
        if (isset($_GET["logVendorAdmin"]) && !empty($_GET["logVendorAdmin"])) {
            $getLogVendorAdmin = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["logVendorAdmin"])));
            $decode_vendors_auth_login_text = base64_decode($getLogVendorAdmin);
            $exp_decode_vendors_auth_login_text = array_filter(explode(":", trim($decode_vendors_auth_login_text)));
            $vendors_auth_email = trim($exp_decode_vendors_auth_login_text[0]);
            $vendors_auth_pass = base64_decode(trim($exp_decode_vendors_auth_login_text[1]));
            if (!empty($vendors_auth_email) && !empty($vendors_auth_pass)) {
                $select_vendor_from_table = mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE email='" . $vendors_auth_email . "' && password='" . $vendors_auth_pass . "'");
                if (mysqli_num_rows($select_vendor_from_table) == 1) {
                    $_SESSION["admin_session"] = $vendors_auth_email;
                    $_SESSION["spadmin_vendor_auth"] = true;
                    $getRedirectUrl = isset($_GET["redirectAdminTo"]) ? mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["redirectAdminTo"]))) : "";
                    if (!empty($getRedirectUrl)) {
                        header("Location: /bc-admin/" . $getRedirectUrl);
                    } else {
                        header("Location: /bc-admin/Dashboard.php");
                    }
                    exit();
                }
            }
        }

        if (bc_is_temporary_vendor_host() && !bc_temporary_vendor_admin_session_is_valid()) {
            bc_render_temporary_vendor_block_page('This temporary website can only be opened through the secure vendor portal link.');
            exit;
        }

        //Select Vendor Table
        $vendor_id_cfg = resolveVendorID();
        $select_vendor_query = mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id_cfg' AND status=1 LIMIT 1");
        $select_vendor_table = $select_vendor_query ? mysqli_fetch_array($select_vendor_query) : null;
        if ($select_vendor_table || (isset($_SESSION["spadmin_vendor_auth"]))) {
            seedVendorBlog($select_vendor_table["id"]);
            if (isset($_SESSION["admin_session"])) {
                $vid = $select_vendor_table["id"];
                $email = $_SESSION["admin_session"];

                // Global Session Enforcement for Blocks
                if (!isset($_SESSION["spadmin_vendor_auth"]) && (isIPBlocked($_SERVER['REMOTE_ADDR'], $vid) || isAccountLocked($email, $vid))) {
                    if (!in_array(explode("?", trim($_SERVER["REQUEST_URI"]))[0], array("/bc-admin/ajax-unblock-request.php", "/web/LockoutResolution.php"))) {
                        session_destroy();
                        header("Location: /bc-admin/Login.php");
                        exit();
                    }
                }
                // Auto-release expired brute-force auto-blocks so blocked admins aren't stuck after the block window.
                bc_release_expired_blocks($email, $_SERVER['REMOTE_ADDR'] ?? '', $vid);
                $get_logged_admin_query = mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vid' && email='$email' LIMIT 1");
                if (mysqli_num_rows($get_logged_admin_query) == 1) {
                    $get_logged_admin_details = mysqli_fetch_array($get_logged_admin_query);
                    // DGV6.90: Ensure access_hash is never empty for the portal link
                    if (empty($get_logged_admin_details['access_hash'])) {
                        // A random 64-hex token, NOT md5(id.email.time()): the portal link is a bearer
                        // credential that can start a vendor session, and the MD5 form is guessable
                        // from a known id/email plus an approximate timestamp.
                        $new_hash = bin2hex(random_bytes(32));
                        mysqli_query($connection_server, "UPDATE sas_vendors SET access_hash='$new_hash' WHERE id='".$get_logged_admin_details['id']."'");
                        $get_logged_admin_details['access_hash'] = $new_hash;
                    }
                    if (($get_logged_admin_details["status"] == 1 && $get_logged_admin_details["is_blocked"] == 0) || (isset($_SESSION["spadmin_vendor_auth"]))) {
                        // Check for vendor expiry
                        $is_expired = false;
                        if ($get_logged_admin_details["expiry_date"] && strtotime($get_logged_admin_details["expiry_date"]) < time()) {
                            $is_expired = true;
                        }

                        if (in_array(explode("?", trim($_SERVER["REQUEST_URI"]))[0], array("/bc-admin/Login.php", "/bc-admin/PasswordRecovery.php", "/bc-admin/ajax-unblock-request.php", "/web/LockoutResolution.php"))) {
                            header("Location: /bc-admin/Dashboard.php");
                            exit();
                        } else {
                            if ($is_expired && !isset($_SESSION["spadmin_vendor_auth"])) {
                                if (!in_array(explode("?", trim($_SERVER["REQUEST_URI"]))[0], array("/bc-admin/RenewSubscription.php", "/admin-logout.php", "/web/LockoutResolution.php"))) {
                                    header("Location: /bc-admin/RenewSubscription.php");
                                    exit();
                                }
                            }

                            // Security PIN is required to self-unblock via the anti-brute-force lockout
                            // flow (web/LockoutResolution.php) — mandatory for every vendor admin
                            // regardless of the force_vendor_pin toggle, since an admin with no PIN
                            // set has no way to prove identity and unblock themselves if the
                            // brute-force system locks them out. Flag-only (no redirect): a blocking
                            // modal (func/bc-admin-header.php) prompts for it on whatever page the
                            // admin is already on. Exempt super-admin-as-vendor impersonation sessions.
                            $GLOBALS['bc_admin_needs_pin'] = empty($get_logged_admin_details["security_pin"]) && !isset($_SESSION["spadmin_vendor_auth"]);

                            $proceed_vendor_kyc_check = false;

                            // Branch DG6.7 Optimization: Throttle billing check to once per session per hour
                            if (!isset($_SESSION['last_billing_check']) || (time() - $_SESSION['last_billing_check'] > 3600)) {
                                $billing_end_date_array = array();
                                $config_get_active_billing_details = mysqli_query($connection_server, "SELECT b.id, b.ending_date FROM sas_vendor_billings b LEFT JOIN sas_vendor_paid_bills p ON b.id = p.bill_id AND p.vendor_id = '".$get_logged_admin_details["id"]."' WHERE b.date >= '" . $get_logged_admin_details["reg_date"] . "' AND p.id IS NULL");

                                if ($config_get_active_billing_details && mysqli_num_rows($config_get_active_billing_details) >= 1) {
                                    while ($config_active_billing = mysqli_fetch_assoc($config_get_active_billing_details)) {
                                        if (strtotime(date("Y-m-d")) > strtotime($config_active_billing["ending_date"])) {
                                            $billing_end_date_array[] = "1";
                                        } else {
                                            $billing_end_date_array[] = "2";
                                        }
                                    }
                                }
                                $_SESSION['billing_has_overdue'] = in_array("1", $billing_end_date_array);
                                $_SESSION['last_billing_check'] = time();
                            }

                            if (($_SESSION['billing_has_overdue'] ?? false) === true) {
                                $_SESSION["product_purchase_response"] = "Dear " . ucwords($get_logged_admin_details["firstname"]) . ", kindly note that there are outstanding bills on your account. Please settle them at your earliest convenience to avoid service disruptions. Thank you.";
                                if (!in_array(explode("?", trim($_SERVER["REQUEST_URI"]))[0], array("/bc-admin/Dashboard.php"))) {
                                    header("Location: /bc-admin/Dashboard.php");
                                    exit();
                                }
                                $proceed_vendor_kyc_check = false;
                            } else {
                                $proceed_vendor_kyc_check = true;
                            }

                            if ($proceed_vendor_kyc_check) {
                                $q_opt = mysqli_query($connection_server, "SELECT option_value FROM sas_super_admin_options WHERE option_name='force_kyc'");
                                $global_force_kyc = ($q_opt && mysqli_num_rows($q_opt) > 0) ? mysqli_fetch_assoc($q_opt)['option_value'] : '0';

                                $config_kyc_verification_status_array_value = array();
                                $forced_kyc_configs = ["bvn", "nin", "liveliness_video", "liveliness_picture", "govt_id", "proof_of_address"];
                                foreach ($forced_kyc_configs as $config_verification_name) {
                                    $config_get_verification_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_super_admin_kyc_verifications WHERE verification_name='$config_verification_name'"));
                                    if ($config_get_verification_details && $config_get_verification_details["status"] == 1) {
                                        $config_kyc_verification_status_array_value[] = ucwords(str_replace("_", " ", $config_verification_name));
                                    }
                                }

                                $admin_kyc_status = (int)($get_logged_admin_details["kyc_status"] ?? 0);
                                if ($global_force_kyc == 1 && count($config_kyc_verification_status_array_value) > 0 && $admin_kyc_status != 2) {
                                    $_SESSION["product_purchase_response"] = "Dear " . ucwords($get_logged_admin_details["firstname"]) . ", To comply with CBN regulations and unlock all platform features.<br>Please complete your identity verification (" . implode(", ", $config_kyc_verification_status_array_value) . ") securely.<br>Your information is treated confidentially.";
                                    if (!in_array(explode("?", trim($_SERVER["REQUEST_URI"]))[0], array("/bc-admin/AccountSettings.php", "/bc-admin/Fund.php", "/bc-admin/SelfSubmitPayment.php", "/bc-admin/SelfPaymentOrders.php"))) {
                                        header("Location: /bc-admin/AccountSettings.php");
                                        exit();
                                    }
                                }
                            }
                        }
                        alterVendor($get_logged_admin_details["id"], "last_login", date('Y-m-d H:i:s'));
                        $create_select_sas_daily_purchase_tracker = mysqli_query($connection_server, "SELECT * FROM sas_daily_purchase_limit WHERE vendor_id='" . $get_logged_admin_details["id"] . "'");
                        if (mysqli_num_rows($create_select_sas_daily_purchase_tracker) == 0) {
                            mysqli_query($connection_server, "INSERT INTO sas_daily_purchase_limit (vendor_id, `limit`) VALUES ('" . $get_logged_admin_details["id"] . "', '5')");
                        }
                    } else {
                        header("Location: /admin-logout.php");
                        exit();
                    }
                } else {
                    header("Location: /admin-logout.php");
                    exit();
                }
            } else {
                if (!in_array(explode("?", trim($_SERVER["REQUEST_URI"]))[0], array("/bc-admin/Login.php", "/bc-admin/PasswordRecovery.php", "/bc-admin/ajax-unblock-request.php", "/web/LockoutResolution.php"))) {
                    $redirecturl = trim($_SERVER["REQUEST_URI"]);
                    header("Location: /bc-admin/Login.php" . (!empty($redirecturl) ? "?redirecturl=" . urlencode($redirecturl) : ""));
                    exit();
                }
            }

        } else {
            header("Location: /bc-admin/Error.php");
            exit();
        }
    } else {
        if (!in_array(explode("?", trim($_SERVER["REQUEST_URI"]))[0], array("/saSetup.php"))) {
            header("Location: /saSetup.php");
            exit();
        }
    }
} else {
    if (!in_array(explode("?", trim($_SERVER["REQUEST_URI"]))[0], array("/index.php"))) {
        header("Location: /index.php");
        exit();
    }
}

//CSS Template Update
$css_style_template_location = "/cssfile/template/bc-style-template-1.css";
$vendor_primary_color = "#287bff";
if (isset($select_vendor_table["id"])) {
    $select_vendor_style_template = mysqli_query($connection_server, "SELECT * FROM sas_vendor_style_templates WHERE vendor_id='" . $select_vendor_table["id"] . "'");
    if (mysqli_num_rows($select_vendor_style_template) == 1) {
        $get_vendor_style_template = mysqli_fetch_array($select_vendor_style_template);
        $style_template_name = $get_vendor_style_template["template_name"];
        if (!empty($style_template_name)) {
            $style_template_location = "/cssfile/template/" . $style_template_name;
            if (file_exists($_SERVER['DOCUMENT_ROOT'] . $style_template_location)) {
                $css_style_template_location = $style_template_location;
            }
        }
        $vendor_primary_color = $get_vendor_style_template["primary_color"] ?? "#287bff";
    }
}
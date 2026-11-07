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

    // STANDALONE: Fallback to vendor site details for legacy variables
    $vendor_id_cfg = 1;
    $select_vendor_query = mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id_cfg' AND status=1 LIMIT 1");
    $select_vendor_table = $select_vendor_query ? mysqli_fetch_array($select_vendor_query) : null;
    
    $get_all_super_admin_site_details = null;
    if ($select_vendor_table) {
        $q_site_details = mysqli_query($connection_server, "SELECT * FROM sas_site_details WHERE vendor_id='".$select_vendor_table["id"]."' LIMIT 1");
        $get_all_super_admin_site_details = ($q_site_details && mysqli_num_rows($q_site_details) > 0) ? mysqli_fetch_array($q_site_details) : ['site_title' => 'Admin Panel', 'site_desc' => 'Admin Panel'];
        seedVendorBlog($select_vendor_table["id"]);
        if (isset($_SESSION["admin_session"])) {
            $vid = $select_vendor_table["id"];
            $email = $_SESSION["admin_session"];

            // Global Session Enforcement for Blocks
            if (isIPBlocked($_SERVER['REMOTE_ADDR'], $vid) || isAccountLocked($email, $vid)) {
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
                // Ensure access_hash is never empty for the portal link
                if (empty($get_logged_admin_details['access_hash'])) {
                    // A random 64-hex token, NOT md5(id.email.time()): the portal link is a bearer
                    // credential that can start a vendor session, and the MD5 form is guessable
                    // from a known id/email plus an approximate timestamp.
                    $new_hash = bin2hex(random_bytes(32));
                    mysqli_query($connection_server, "UPDATE sas_vendors SET access_hash='$new_hash' WHERE id='".$get_logged_admin_details['id']."'");
                    $get_logged_admin_details['access_hash'] = $new_hash;
                }

                // Self-heal default marketplace listing: v6.datagifting.com.ng runs the DGV7.0-SAAS
                // edition of this same platform, so it's a ready-made reseller API for every VTU
                // service via the "localserver.php" gateway fallback (func/api-gateway/{type}-localserver.php),
                // which talks to another DGV7 instance's own web/api/*.php endpoints. Seeded here
                // (not only on MarketPlace.php) so it self-heals on EVERY admin page load for both
                // brand-new and pre-existing installations, regardless of which page an admin visits
                // first or when this code was deployed relative to their last login. Session-gated
                // like the migrations check above so the 10 SELECT/INSERT checks below only run once
                // per session, not on every single request.
                if (!isset($_SESSION['default_marketplace_seeded_v1'])) {
                    $default_marketplace_url = "v6.datagifting.com.ng";
                    $default_marketplace_types = array("airtime", "shared-data", "sme-data", "cg-data", "dd-data", "cable", "electric", "exam", "betting", "bulk-sms");
                    foreach ($default_marketplace_types as $default_api_type) {
                        $check_default_api = mysqli_query($connection_server, "SELECT id FROM sas_apis WHERE vendor_id='" . $get_logged_admin_details['id'] . "' AND api_type='$default_api_type' AND api_base_url='$default_marketplace_url' LIMIT 1");
                        if ($check_default_api && mysqli_num_rows($check_default_api) == 0) {
                            mysqli_query($connection_server, "INSERT INTO sas_apis (vendor_id, api_type, api_base_url, api_key, status) VALUES ('" . $get_logged_admin_details['id'] . "', '$default_api_type', '$default_marketplace_url', '', '0')");
                        }
                    }
                    $_SESSION['default_marketplace_seeded_v1'] = true;
                }
                if ($get_logged_admin_details["status"] == 1 && $get_logged_admin_details["is_blocked"] == 0) {
                    if (in_array(explode("?", trim($_SERVER["REQUEST_URI"]))[0], array("/bc-admin/Login.php", "/bc-admin/PasswordRecovery.php", "/bc-admin/ajax-unblock-request.php", "/web/LockoutResolution.php"))) {
                        header("Location: /bc-admin/Dashboard.php");
                        exit();
                    } else {
                        // STANDALONE: Expiry check removed

                        // Security PIN is required to self-unblock via the anti-brute-force lockout
                        // flow (web/LockoutResolution.php) — mandatory for every admin regardless of
                        // the force_vendor_pin toggle, since an admin with no PIN set has no way to
                        // prove identity and unblock themselves if the brute-force system locks them
                        // out. Flag-only (no redirect): a blocking modal (func/bc-admin-header.php)
                        // prompts for it on whatever page the admin is already on, instead of bouncing
                        // them to a separate page (the previous redirect-based approach could loop
                        // under some server configurations).
                        $GLOBALS['bc_admin_needs_pin'] = empty($get_logged_admin_details["security_pin"]);

                        // STANDALONE: Billing check removed — always proceed
                        $proceed_vendor_kyc_check = true;

                        if ($proceed_vendor_kyc_check) {
                            $global_force_kyc = getSuperAdminOption('force_kyc', '0');

                            $config_kyc_verification_status_array_value = array();
                            $forced_kyc_configs = ["bvn", "nin", "liveliness_video", "liveliness_picture", "govt_id", "proof_of_address"];
                            foreach ($forced_kyc_configs as $config_verification_name) {
                                $config_get_verification_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_kyc_verifications WHERE vendor_id='$vid' AND verification_name='$config_verification_name'"));
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
                if (!in_array(explode("?", trim($_SERVER["REQUEST_URI"]))[0], array("/bc-admin/Login.php", "/bc-admin/PasswordRecovery.php", "/bc-admin/ajax-unblock-request.php", "/web/LockoutResolution.php"))) {
                    $redirecturl = trim($_SERVER["REQUEST_URI"]);
                    header("Location: /bc-admin/Login.php" . (!empty($redirecturl) ? "?redirecturl=" . urlencode($redirecturl) : ""));
                    exit();
                }
            }
        } else {
            if (!in_array(explode("?", trim($_SERVER["REQUEST_URI"]))[0], array("/bc-admin/Login.php", "/bc-admin/PasswordRecovery.php", "/bc-admin/ajax-unblock-request.php", "/web/LockoutResolution.php"))) {
                $redirecturl = trim($_SERVER["REQUEST_URI"]);
                header("Location: /bc-admin/Login.php" . (!empty($redirecturl) ? "?redirecturl=" . urlencode($redirecturl) : ""));
                exit();
            }
        }
    } else {
        if (!in_array(explode("?", trim($_SERVER["REQUEST_URI"]))[0], array("/bc-admin/Login.php", "/bc-admin/PasswordRecovery.php", "/bc-admin/ajax-unblock-request.php", "/web/LockoutResolution.php"))) {
            $redirecturl = trim($_SERVER["REQUEST_URI"]);
            header("Location: /bc-admin/Login.php" . (!empty($redirecturl) ? "?redirecturl=" . urlencode($redirecturl) : ""));
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

if (isset($GLOBALS['bc_integrity_fail']) && $GLOBALS['bc_integrity_fail'] === true) {
    if (isset($_SESSION["admin_session"])) {
        $_SESSION["product_purchase_response"] = "System integrity check failed. Please verify your system activation status.";
        if (!in_array(explode("?", trim($_SERVER["REQUEST_URI"]))[0], array("/bc-admin/AccountSettings.php", "/admin-logout.php", "/bc-admin/ajax-unblock-request.php"))) {
            header("Location: /bc-admin/AccountSettings.php");
            exit();
        }
    }
}

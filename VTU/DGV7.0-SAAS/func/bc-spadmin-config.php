<?php
require_once __DIR__ . '/bc-secure-session.php';

	if(isset($_SERVER["HTTPS"]) && ($_SERVER["HTTPS"] == "on")){
		$web_http_host = "https://".$_SERVER["HTTP_HOST"];
	}else{
		$web_http_host = "http://".$_SERVER["HTTP_HOST"];
	}

	// Standardize session_start across the platform
	if (session_status() === PHP_SESSION_NONE) {
		bc_secure_session_start();
	}

	include_once(__DIR__ . "/bc-connect.php");

	// The activation handler below calls the license helpers directly. bc-connect.php
	// loads bc-levelup.php, but a partially-updated deployment (this file replaced
	// without func/bc-levelup.php, exactly what a file-manager upload does) used to
	// fatal with "Call to undefined function bc_store_license_option()". Load the
	// helpers defensively instead of assuming the include chain completed.
	if (!function_exists('bc_store_license_option') || !function_exists('bc_verify_integrity')) {
	    include_once(__DIR__ . "/bc-levelup.php");
	}

if (isset($GLOBALS['bc_integrity_fail']) && $GLOBALS['bc_integrity_fail'] === true) {
    $act_error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_activate_code'])) {
        $code = trim($_POST['action_activate_code']);
        if (!empty($code)) {
            // Return value was previously discarded. A failed write here (func/ not writable by
            // PHP — very common right after re-uploading files via FTP/cPanel, which resets
            // ownership) silently left the system with no readable activation code, producing a
            // bare "invalid for this domain" with no explanation. Track it and report it below.
            $write_ok = bc_write_activation($code);
            // The key is persisted to the database AFTER it validates (below). Storing
            // it before the check meant a rejected attempt overwrote the previously
            // saved, working key with the bad one.
            // Force a fresh remote check instead of possibly reading a stale FAILED verdict cached
            // from an earlier attempt (e.g. from before the DB persistence fix above existed, or from
            // a transient DB-connection blip). Without this, a still-valid 6-hour-old negative cache
            // entry short-circuits bc_verify_integrity() before it ever re-contacts the license server
            // — this was the exact cause of "works on reload but not on the first try": the failure
            // branch below clears this same cache file, so only the *next* attempt got a fresh check.
            $cache_file = __DIR__ . '/cache/bc-core.cache';
            if (file_exists($cache_file)) {
                @unlink($cache_file);
            }
            // unset(), not = false: bc_verify_integrity() memoises on a truthy flag,
            // and assigning false used to leave the stale verdict in place.
            unset($GLOBALS['bc_integrity_checked']);
            foreach (array_keys($_SESSION ?? array()) as $bc_sk) { if (strpos((string)$bc_sk, 'bc_integrity_ok_') === 0) unset($_SESSION[$bc_sk]); }
            if (bc_verify_integrity()) {
                // Validated: now persist to the database. That copy (plus the file
                // bc_write_activation() just wrote) is what stops the site asking for
                // the key again after the next file update.
                if (isset($connection_server) && $connection_server) {
                    $current_domain = preg_replace('/^www\./i', '', str_replace(["https://", "http://"], "", $_SERVER['HTTP_HOST'] ?? 'localhost'));
                    if (!bc_store_license_option($connection_server, 'license_key', $code)) {
                        $db_write_err = mysqli_error($connection_server) ?: 'unknown database error';
                    } elseif (!bc_store_license_option($connection_server, 'license_domain', $current_domain)) {
                        $db_write_err = mysqli_error($connection_server) ?: 'unknown database error';
                    }
                } else {
                    $db_write_err = 'no database connection available';
                }
                if (!empty($db_write_err)) {
                    error_log('[DGV-LICENSE] validated but could not persist license_key: ' . $db_write_err);
                }
                header("Location: " . $_SERVER['REQUEST_URI']);
                exit;
            } else {
                $act_error = 'The activation code is invalid for this domain.';
                if (isset($_SESSION['bc_integrity_debug_err']) && !empty($_SESSION['bc_integrity_debug_err'])) {
                    $act_error .= '<br/><span style="font-size: 13px; opacity: 0.85;">Details: ' . htmlspecialchars($_SESSION['bc_integrity_debug_err']) . '</span>';
                    unset($_SESSION['bc_integrity_debug_err']);
                }
                if (empty($write_ok)) {
                    $act_error .= '<br/><span style="font-size: 13px; opacity: 0.85;">Note: could not write func/bc-activation.php — check that the func/ directory is writable by PHP.</span>';
                }
                if (!empty($db_write_err)) {
                    $act_error .= '<br/><span style="font-size: 13px; opacity: 0.85;">Note: could not save the license key to the database — ' . htmlspecialchars($db_write_err) . '</span>';
                }
                bc_clear_activation();
            }
        } else {
            $act_error = 'Please enter a valid activation code.';
        }
    }

    header("HTTP/1.1 403 Forbidden");
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>System Activation Required</title>
        <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css">
        <style>
            :root {
                --bg-color: #0b0f19;
                --card-bg: rgba(17, 24, 39, 0.7);
                --text-color: #f3f4f6;
                --text-muted: #9ca3af;
                --primary: #3b82f6;
                --primary-hover: #2563eb;
                --border-color: rgba(31, 41, 55, 0.8);
            }
            body {
                background: var(--bg-color);
                color: var(--text-color);
                font-family: 'Outfit', sans-serif;
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 100vh;
                margin: 0;
                padding: 20px;
                box-sizing: border-box;
                overflow-x: hidden;
                position: relative;
            }
            body::before {
                content: '';
                position: absolute;
                top: -50%;
                left: -50%;
                width: 200%;
                height: 200%;
                background: radial-gradient(circle, rgba(59, 130, 246, 0.08) 0%, transparent 50%);
                z-index: -1;
            }
            .activation-card {
                background: var(--card-bg);
                backdrop-filter: blur(16px);
                border: 1px solid var(--border-color);
                border-radius: 16px;
                padding: 40px;
                width: 100%;
                max-width: 500px;
                box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5), 0 10px 10px -5px rgba(0, 0, 0, 0.4);
                text-align: center;
                animation: fadeIn 0.6s cubic-bezier(0.16, 1, 0.3, 1);
            }
            @keyframes fadeIn {
                from { opacity: 0; transform: translateY(20px); }
                to { opacity: 1; transform: translateY(0); }
            }
            .logo-icon {
                font-size: 56px;
                background: linear-gradient(135deg, #60a5fa, #3b82f6);
                -webkit-background-clip: text;
                -webkit-text-fill-color: transparent;
                margin-bottom: 24px;
                display: inline-block;
            }
            h1 {
                font-size: 28px;
                margin: 0 0 12px 0;
                font-weight: 700;
                letter-spacing: -0.02em;
            }
            p {
                color: var(--text-muted);
                font-size: 15px;
                line-height: 1.6;
                margin: 0 0 32px 0;
            }
            .form-group {
                text-align: left;
                margin-bottom: 24px;
            }
            label {
                display: block;
                font-size: 13px;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.05em;
                margin-bottom: 8px;
                color: #e5e7eb;
            }
            input[type="text"] {
                width: 100%;
                padding: 14px 16px;
                background: rgba(10, 15, 30, 0.8);
                border: 1px solid var(--border-color);
                border-radius: 10px;
                color: #fff;
                font-family: inherit;
                font-size: 15px;
                transition: all 0.3s ease;
                box-sizing: border-box;
            }
            input[type="text"]:focus {
                outline: none;
                border-color: var(--primary);
                box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
            }
            .btn {
                width: 100%;
                padding: 14px;
                background: var(--primary);
                color: white;
                border: none;
                border-radius: 10px;
                font-size: 16px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.3s ease;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
            }
            .btn:hover {
                background: var(--primary-hover);
                transform: translateY(-1px);
            }
            .btn:active {
                transform: translateY(0);
            }
            .alert {
                padding: 12px 16px;
                border-radius: 8px;
                font-size: 14px;
                margin-bottom: 24px;
                text-align: left;
                display: flex;
                align-items: center;
                gap: 10px;
            }
            .alert-danger {
                background: rgba(239, 68, 68, 0.1);
                border: 1px solid rgba(239, 68, 68, 0.2);
                color: #f87171;
            }
            .info-box {
                margin-top: 32px;
                padding-top: 24px;
                border-top: 1px solid rgba(255, 255, 255, 0.05);
                font-size: 13px;
                color: var(--text-muted);
            }
            .info-box a {
                color: var(--primary);
                text-decoration: none;
            }
            .info-box a:hover {
                text-decoration: underline;
            }
        </style>
    </head>
    <body>
        <div class="activation-card">
            <div class="logo-icon"><i class="bi bi-shield-lock-fill"></i></div>
            <h1>System Activation</h1>
            <p>Please enter your system Activation Code to verify your installation and unlock administrative services.</p>
            
            <?php if (!empty($act_error)): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-octagon"></i>
                    <div><?= $act_error ?></div>
                </div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label>Activation Code</label>
                    <input type="text" name="action_activate_code" placeholder="Enter your Activation Code" required autocomplete="off">
                </div>
                <button type="submit" class="btn">
                    <span>Activate System</span>
                    <i class="bi bi-check2-circle"></i>
                </button>
            </form>

            <div class="info-box">
                Need support? Please contact our team or visit <a href="https://manager.pmhserver.name.ng" target="_blank">manager.pmhserver.name.ng</a>.
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

	if(!$connection_server){
		if(!in_array(explode("?",trim($_SERVER["REQUEST_URI"]))[0], array("/dbSetup.php", "/saSetup.php"))){
			header("Location: /dbSetup.php");
            exit();
		}
	}
	include_once(__DIR__ . "/bc-tables.php");
	include_once(__DIR__ . "/email-design.php");
	include_once(__DIR__ . "/bc-security.php"); // DGV6.90 AI Edition — Security utilities

	if($connection_server){
    // Branch DG6.7 Optimization: Only run migrations if not already done in current session
    // This significantly improves site-wide page load speeds by skipping redundant DB structural checks.
    // §45: one migration marker shared with the web + vendor-admin bootstraps
    // (bc_migrations_current in func/bc-security.php).
    if (!bc_migrations_current()) {
        include_once(__DIR__ . "/bc-tables.php");
        include_once(__DIR__ . "/bc-email-templates.php");
        bc_migrations_mark_done();
    }
	$checkmate_super_admin_table_exists = mysqli_query($connection_server, "SELECT id FROM sas_super_admin LIMIT 1");
	if($checkmate_super_admin_table_exists && mysqli_num_rows($checkmate_super_admin_table_exists) >= 1){
	//Select Super Admin Table
	$select_super_admin_table = mysqli_query($connection_server, "SELECT * FROM sas_super_admin");
	if(mysqli_num_rows($select_super_admin_table) > 0){
		if(isset($_SESSION["spadmin_session"])){
            $email = $_SESSION["spadmin_session"];

            // Global Session Enforcement for Blocks (vendor_id = 0 for super admin)
            if (isIPBlocked($_SERVER['REMOTE_ADDR'], 0) || isAccountLocked($email, 0)) {
                if (!in_array(explode("?", trim($_SERVER["REQUEST_URI"]))[0], array("/bc-spadmin/ajax-unblock-request.php", "/web/LockoutResolution.php"))) {
                    session_destroy();
                    header("Location: /bc-spadmin/Login.php");
                    exit();
                }
            }

			$get_logged_spadmin_query = mysqli_query($connection_server, "SELECT * FROM sas_super_admin WHERE email='$email'");
			if(mysqli_num_rows($get_logged_spadmin_query) == 1){
				$get_logged_spadmin_details = mysqli_fetch_array($get_logged_spadmin_query);
                bc_browser_binding_enforce('spadmin',$get_logged_spadmin_details);
				// §33: platform "logout all sessions" epoch check.
				if (function_exists('bc_auth_epoch_session_ok') && !bc_auth_epoch_session_ok()) {
					session_destroy();
					header("Location: /bc-spadmin/Login.php");
					exit();
				}
				if (function_exists('bc_web_session_touch_and_check') && !bc_web_session_touch_and_check('spadmin', $get_logged_spadmin_details)) {
					$_SESSION = array();
					if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
					header("Location: /bc-spadmin/Login.php");
					exit();
				}
				if($get_logged_spadmin_details["status"] == 1 && $get_logged_spadmin_details["is_blocked"] == 0){
					if(in_array(explode("?",trim($_SERVER["REQUEST_URI"]))[0], array("/bc-spadmin/Login.php", "/bc-spadmin/PasswordRecovery.php", "/web/LockoutResolution.php"))){
						header("Location: /bc-spadmin/Dashboard.php");
                        exit();
					}else{
						
					}
					mysqli_query($connection_server, "UPDATE sas_super_admin SET last_login='".date('Y-m-d H:i:s')."' WHERE id='".$get_logged_spadmin_details["id"]."'");
				}else{
					header("Location: /spadmin-logout.php");
                    exit();
				}
			}else{
				header("Location: /spadmin-logout.php");
                exit();
			}
		}else{
			if(!in_array(explode("?",trim($_SERVER["REQUEST_URI"]))[0], array("/bc-spadmin/Login.php", "/bc-spadmin/PasswordRecovery.php", "/web/LockoutResolution.php"))){
				$redirecturl = trim($_SERVER["REQUEST_URI"]);
				if(!empty(trim($redirecturl)) && file_exists("..".$redirecturl)){
					header("Location: /bc-spadmin/Login.php?redirecturl=".$redirecturl);
                    exit();
				}else{
					header("Location: /bc-spadmin/Login.php");
                    exit();
				}
			}
		}
	}else{
		header("Location: /bc-spadmin/Error.php");
        exit();
	}
	}else{
		if(!in_array(explode("?",trim($_SERVER["REQUEST_URI"]))[0], array("/saSetup.php"))){
			header("Location: /saSetup.php");
            exit();
		}
	}
	}else{
		//If Database Is Having Issue
		if(!in_array(explode("?",trim($_SERVER["REQUEST_URI"]))[0], array("/dbSetup.php"))){
			header("Location: /dbSetup.php");
            exit();
		}
	}

    // Migration: Ensure primary_color column exists for spadmin
    $check_sp_col = mysqli_query($connection_server, "SHOW COLUMNS FROM `sas_spadmin_style_templates` LIKE 'primary_color'");
    if ($check_sp_col && mysqli_num_rows($check_sp_col) == 0) {
        mysqli_query($connection_server, "ALTER TABLE `sas_spadmin_style_templates` ADD `primary_color` VARCHAR(20) DEFAULT '#0d6efd'");
    }

	//CSS Template Update
    $get_all_super_admin_site_details_query = mysqli_query($connection_server, "SELECT * FROM sas_super_admin_site_details LIMIT 1");
    $get_all_super_admin_site_details = $get_all_super_admin_site_details_query ? mysqli_fetch_array($get_all_super_admin_site_details_query) : null;

    // Every back-office page prints its <title> from this row. When the row has not been
    // seeded yet the value must still be an array, or each of those pages emits
    // "Trying to access array offset on null".
    if (!is_array($get_all_super_admin_site_details)) {
        $get_all_super_admin_site_details = array('site_title' => 'Admin Panel', 'site_desc' => 'Admin Panel');
    }

    $css_style_template_location = "/cssfile/template/bc-style-template-1.css";
    $spadmin_primary_color = "#0d6efd";
    $select_spadmin_style_template = mysqli_query($connection_server, "SELECT * FROM sas_spadmin_style_templates");
    if(mysqli_num_rows($select_spadmin_style_template) == 1){
        $get_spadmin_style_template = mysqli_fetch_array($select_spadmin_style_template);
        $style_template_name = $get_spadmin_style_template["template_name"];
        if(!empty($style_template_name)){
            $style_template_location = "/cssfile/template/".$style_template_name;
			if(file_exists("..".$style_template_location)){
				$css_style_template_location =  $style_template_location;
			}
        }
        $spadmin_primary_color = $get_spadmin_style_template["primary_color"] ?? "#0d6efd";
    }
    
	/*if(emailTemplateTableExist('student-reg','','verify') == false){
		mysqli_query($connection_server, "INSERT INTO sm_email_templates (school_id_number, template_name, template_title, template_message) VALUES ('".$get_logged_user_details["school_id_number"]."', 'student-reg', 'Student Registration', 'Hello {{student_name}} ,\n\nYour registration has been successful with {{school_name}}. You can now access your account. \n\nUser Name : {{user_name}}\nClass Name : {{class_name}}\nEmail : {{email}}\n\n\nRegards From {{school_name}}.')");
	}
	if(emailTemplateTableExist('add-user','','verify') == false){
		mysqli_query($connection_server, "INSERT INTO sm_email_templates (school_id_number, template_name, template_title, template_message) VALUES ('".$get_logged_user_details["school_id_number"]."', 'add-user', 'Your have been assigned role of {{role}} in {{school_name}}.', 'Dear {{user_name}},\n\n         You are Added by admin in {{school_name}} . Your have been assigned role of {{role}} in {{school_name}}.  You can sign in using this link. {{login_link}}\n\nUserName : {{username}}\nPassword : {{password}}\n\nRegards From {{school_name}}.')");
	}
	if(emailTemplateTableExist('fees-alert','','verify') == false){
		mysqli_query($connection_server, "INSERT INTO sm_email_templates (school_id_number, template_name, template_title, template_message) VALUES ('".$get_logged_user_details["school_id_number"]."', 'fees-alert', 'Fees Alert', 'Dear {{parent_name}},\n\n        You have a new invoice.  You can check the invoice on your portal.\n.')");
	}
	if(emailTemplateTableExist('student-assign-teacher','','verify') == false){
		mysqli_query($connection_server, "INSERT INTO sm_email_templates (school_id_number, template_name, template_title, template_message) VALUES ('".$get_logged_user_details["school_id_number"]."', 'student-assign-teacher', 'New Student has been assigned to you.', 'Dear {{teacher_name}},\n\n         New Student {{student_name}} has been assigned to you.\n \nRegards From {{school_name}}.')");
	}
	if(emailTemplateTableExist('student-assigned-teacher','','verify') == false){
		mysqli_query($connection_server, "INSERT INTO sm_email_templates (school_id_number, template_name, template_title, template_message) VALUES ('".$get_logged_user_details["school_id_number"]."', 'student-assigned-teacher', 'You have been Assigned {{teacher_name}} at {{school_name}}', 'Dear {{student_name}},\n\n         You are assigned to  {{teacher_name}}. {{teacher_name}} belongs to {{class_name}}.\n \nRegards From {{school_name}}.')");
	}
	if(emailTemplateTableExist('attendance-absent','','verify') == false){
		mysqli_query($connection_server, "INSERT INTO sm_email_templates (school_id_number, template_name, template_title, template_message) VALUES ('".$get_logged_user_details["school_id_number"]."', 'attendance-absent', 'Your Child {{child_name}} is absent today', 'Your Child {{child_name}} is absent today.\n\nRegards From {{school_name}}.')");
	}
	if(emailTemplateTableExist('payment-invoice','','verify') == false){
		mysqli_query($connection_server, "INSERT INTO sm_email_templates (school_id_number, template_name, template_title, template_message) VALUES ('".$get_logged_user_details["school_id_number"]."', 'payment-invoice', 'Payment Received against Invoice', 'Dear {{student_name}},\n\n        Your have successfully paid your invoice {{invoice_no}}. You can check the invoice receipt on your portal.\n \nRegards From {{school_name}}.')");
	}
	if(emailTemplateTableExist('notice','','verify') == false){
		mysqli_query($connection_server, "INSERT INTO sm_email_templates (school_id_number, template_name, template_title, template_message) VALUES ('".$get_logged_user_details["school_id_number"]."', 'notice', 'New Notice For You', 'New Notice For You.\n\nNotice Title : {{notice_title}}\n\nNotice Date  : {{notice_date}}\n\nNotice For  : {{notice_for}}\n\nNotice Comment :  {{notice_comment}}\n\nRegards From {{school_name}}\n')");
	}
	if(emailTemplateTableExist('holiday','','verify') == false){
		mysqli_query($connection_server, "INSERT INTO sm_email_templates (school_id_number, template_name, template_title, template_message) VALUES ('".$get_logged_user_details["school_id_number"]."', 'holiday', 'Holiday Announcement', 'Holiday Announcement\n\nHoliday Title : {{holiday_title}}\n\nHoliday Date : {{holiday_date}}\n\nRegards From {{school_name}}\n')");
	}
	if(emailTemplateTableExist('school-bus','','verify') == false){
		mysqli_query($connection_server, "INSERT INTO sm_email_templates (school_id_number, template_name, template_title, template_message) VALUES ('".$get_logged_user_details["school_id_number"]."', 'school-bus', 'School Bus Allocation', 'School Bus Allocation\n	\n	Route Name : {{route_name}}\n	\n	Vehicle Identifier : {{vehicle_identifier}}\n	\n	Vehicle Registration Number : {{vehicle_registration_number}}\n	\n	Driver Name : {{driver_name}}\n	\n	Driver Phone Number : {{driver_phone_number}}\n	\n	Driver Address : {{driver_address}}\n	\n	Route Fare  : {{route_fare}}\n	\n	Regards From {{school_name}}\n\n')");
	}
	if(emailTemplateTableExist('hostel-bed','','verify') == false){
		mysqli_query($connection_server, "INSERT INTO sm_email_templates (school_id_number, template_name, template_title, template_message) VALUES ('".$get_logged_user_details["school_id_number"]."', 'hostel-bed', 'Hostel Bed Assigned', 'Hello {{student_name}} ,\n\n		You have been assigned new hostel bed in {{school_name}}.\n\nHostel Name : {{hostel_name}}\nRoom Number : {{room_id}}\nBed Number : {{bed_id}}\n\nRegards From {{school_name}}.')");
	}
	if(emailTemplateTableExist('subject-assigned','','verify') == false){
		mysqli_query($connection_server, "INSERT INTO sm_email_templates (school_id_number, template_name, template_title, template_message) VALUES ('".$get_logged_user_details["school_id_number"]."', 'subject-assigned', 'New subject has been assigned to you', 'Dear {{teacher_name}},\n\nNew subject {{subject_name}} has been assigned to you.\n\nRegards From \n{{school_name}}')");
	}
	if(emailTemplateTableExist('issue-book','','verify') == false){
		mysqli_query($connection_server, "INSERT INTO sm_email_templates (school_id_number, template_name, template_title, template_message) VALUES ('".$get_logged_user_details["school_id_number"]."', 'issue-book', 'New book has been issue to you', 'Dear {{student_name}},\n\nNew book {{book_name}} has been issue to you.\n\nRegards From \n{{school_name}}')");
	}*/
	
	//include("./email-design.php");
	
?>
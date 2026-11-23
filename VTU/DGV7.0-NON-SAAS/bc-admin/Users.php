<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
 bc_secure_session_start();
header('Cache-Control: no-store, private');
require_once __DIR__ . '/../func/bc-user-action-confirm.php';
    include("../func/bc-admin-config.php");

    /**
     * Identify one of THIS vendor's users the way the Users table rows are identified: by
     * primary key when the browser sends it, otherwise by username.
     *
     * The account-status buttons used to lean on alterUser(), which re-derives the vendor from
     * the request host (resolveVendorID()) and then refuses to write unless
     * "SELECT ... WHERE vendor_id=<host vendor> && username=<name>" returns EXACTLY one row.
     * When the host does not resolve to the admin's vendor, or the browser had to re-encode a
     * username (whitespace, '+', a quote), that returns "failed" on every click - so Block and
     * Delete behaved like dead buttons while no error was ever shown.
     *
     * @return array|false  the sas_users row, or false when this vendor has no such user
     */
    function bc_admin_find_user($user_id, $raw_username)
    {
        global $connection_server, $get_logged_admin_details;

        $vendor_id = (int) ($get_logged_admin_details["id"] ?? 0);
        if ($vendor_id <= 0) {
            return false;
        }

        $user_id = (int) $user_id;
        if ($user_id > 0) {
            $match = "id='$user_id'";
        } else {
            $username = mysqli_real_escape_string($connection_server, trim(strip_tags((string) $raw_username)));
            if ($username === "") {
                return false;
            }
            $match = "username='$username'";
        }

        $query = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' && $match LIMIT 1");
        if (!$query || mysqli_num_rows($query) === 0) {
            return false;
        }
        return mysqli_fetch_array($query);
    }

    /**
     * Write one whitelisted column on one of this vendor's users, scoped by primary key.
     * Returns true on success; on failure $error carries mysqli_error() so the admin sees the
     * real reason instead of a generic "cannot be deleted".
     */
    function bc_admin_set_user_column($user_id, $column, $value, &$error)
    {
        global $connection_server, $get_logged_admin_details;

        $error = "";
        if (!in_array($column, array("status", "api_status"), true)) {
            $error = "Unsupported field";
            return false;
        }

        $vendor_id = (int) ($get_logged_admin_details["id"] ?? 0);
        $user_id = (int) $user_id;
        if ($vendor_id <= 0 || $user_id <= 0) {
            $error = "User not found for this vendor";
            return false;
        }

        if ($column === 'api_status') {
            require_once __DIR__ . '/../func/bc-user-api-status.php';
            return bc_user_api_status_save($connection_server,$vendor_id,$user_id,'',$value,$error);
        }
        $value = (int) $value;
        $update = mysqli_query($connection_server, "UPDATE sas_users SET $column='$value' WHERE vendor_id='$vendor_id' && id='$user_id'");
        if (!$update) {
            $error = mysqli_error($connection_server);
            return false;
        }
        return true;
    }

    if(isset($_POST["import-users"])){
        if(isset($_FILES['user-csv']) && $_FILES['user-csv']['error'] == 0){
            $file_tmp = $_FILES['user-csv']['tmp_name'];

            $file = fopen($file_tmp, "r");
            fgetcsv($file); // Skip header row

            while (($column = fgetcsv($file, 10000, ",")) !== FALSE) {
                $fullname = explode(" ", $column[1]);
                $firstname = $fullname[0] ?? '';
                $lastname = $fullname[1] ?? '';
                $othername = $fullname[2] ?? '';

                $username = $column[2];
                $level = array_search(strtolower($column[3]), array_map('strtolower', array(1 => "Smart Earner", 2 => "Agent Vendor", 3 => "API Vendor")));
                $balance = $column[4];
                $phone = $column[5];
                $address = $column[6];
                $referral_username = $column[7];
                $api_status = (strtolower($column[8]) == 'enabled') ? 1 : 0;
                $api_key = $column[9];
                $security_answer = $column[10];
                $reg_date = date("Y-m-d H:i:s", strtotime($column[11]));

                $referral_id = 0;
                if($referral_username != "Not Referred"){
                    $get_referral = mysqli_fetch_array(mysqli_query($connection_server, "SELECT id FROM sas_users WHERE username = '$referral_username' AND vendor_id = '".$get_logged_admin_details["id"]."'"));
                    if($get_referral){
                        $referral_id = $get_referral['id'];
                    }
                }

                // For simplicity, let's assume email is the same as username and set a default password
                $email = $username;
                $password = password_hash("password123", PASSWORD_DEFAULT);

                $firstname = mysqli_real_escape_string($connection_server, $firstname);
                $lastname = mysqli_real_escape_string($connection_server, $lastname);
                $othername = mysqli_real_escape_string($connection_server, $othername);
                $username = mysqli_real_escape_string($connection_server, $username);
                $email = mysqli_real_escape_string($connection_server, $email);
                $level = mysqli_real_escape_string($connection_server, $level);
                $balance = mysqli_real_escape_string($connection_server, $balance);
                $phone = mysqli_real_escape_string($connection_server, $phone);
                $address = mysqli_real_escape_string($connection_server, $address);
                $api_key = mysqli_real_escape_string($connection_server, $api_key);
                $security_answer = mysqli_real_escape_string($connection_server, $security_answer);

                $sql = "INSERT INTO sas_users (vendor_id, firstname, lastname, othername, username, email, password, account_level, balance, phone_number, home_address, referral_id, api_status, api_key, security_answer, reg_date, status) VALUES ('".$get_logged_admin_details["id"]."', '$firstname', '$lastname', '$othername', '$username', '$email', '$password', '$level', '$balance', '$phone', '$address', '$referral_id', '$api_status', '$api_key', '$security_answer', '$reg_date', '1')";
                mysqli_query($connection_server, $sql);
            }

            fclose($file);
            $_SESSION["product_purchase_response"] = "Users imported successfully.";
        }else{
            $_SESSION["product_purchase_response"] = "Error uploading file.";
        }
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit();
    }

    if(isset($_POST["export-users"])){
        $status = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["status"])));

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=users.csv');
        $output = fopen('php://output', 'w');
        fputcsv($output, array('S/N', 'Fullname', 'Username ID', 'Level', 'Balance', 'Phone number', 'Address', 'Referral', 'API Status', 'APIKey', 'Security Answer', 'Reg Date'));

        $sql = "SELECT * FROM sas_users WHERE vendor_id='".$get_logged_admin_details["id"]."'";
        if($status != 'all'){
            $sql .= " && status='$status'";
        }
        $result = mysqli_query($connection_server, $sql);
        $sn = 1;
        while($row = mysqli_fetch_assoc($result)){
            $fullname = $row['firstname'] . ' ' . $row['lastname'] . ' ' . $row['othername'];
            $referral_username = "Not Referred";
            if(!empty($row["referral_id"]) && is_numeric($row["referral_id"])){
                $get_user_referral_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='".$get_logged_admin_details["id"]."' && id='".$row["referral_id"]."'"));
                $referral_username = $get_user_referral_details["username"];
            }

            $api_status = ($row['api_status'] == 1) ? 'Enabled' : 'Disabled';

            fputcsv($output, array(
                $sn++,
                $fullname,
                $row['username'],
                accountLevel($row['account_level']),
                $row['balance'],
                $row['phone_number'],
                $row['home_address'],
                $referral_username,
                $api_status,
                $row['api_key'],
                $row['security_answer'],
                formDate($row['reg_date'])
            ));
        }
        fclose($output);
        exit();
    }

    // §33/§10: user account actions (activate / block / delete, API status,
    // permanent delete, "login as user") are POST + CSRF + step-up only.
    // Activations, API enablement, deletes and impersonation need Security
    // PIN + authenticator/email code for every action, including API disable.
    $sec33_gate_ok = false;
    if (isset($_POST['sec33_user_action']) || isset($_POST['sec33_send_code'])) {
        $mode = (string)($_POST['sec33_user_action'] ?? '');
        $status = (string)($_POST['sec33_status'] ?? '');
        if (!in_array($mode, ['status', 'api', 'log', 'permanent'], true)
            || ($mode === 'status' && !in_array($status, ['1', '2', '3'], true))
            || ($mode === 'api' && !in_array($status, ['1', '2'], true))) {
            http_response_code(400); exit('Invalid user action.');
        }
    }
    if (isset($_POST['sec33_send_code'])) {
        if (!function_exists('bc_validate_csrf')) { http_response_code(503); exit('Verification is unavailable.'); }
        bc_validate_csrf();
        $sec33_send_err = function_exists('bc_stepup_send_email_otp')
            ? bc_stepup_send_email_otp((int)$get_logged_admin_details['id'], (string)$get_logged_admin_details['email'], (string)$get_logged_admin_details['email'], 'user_account_action', 'User Account Action')
            : 'Verification is unavailable.';
        bc_user_action_otp_ajax($sec33_send_err);
        $_SESSION["product_purchase_response"] = $sec33_send_err === '' ? "Verification code sent to your email." : "Error: $sec33_send_err";
        header("Location: " . bc_user_action_confirmation_url($_POST));
        exit();
    }
    if (isset($_POST['sec33_user_action'])) {
        if (!function_exists('bc_validate_csrf')) { http_response_code(503); exit('Verification is unavailable.'); }
        bc_validate_csrf();
        $sec33_mode = (string)$_POST['sec33_user_action'];
        $sec33_status = (string)($_POST['sec33_status'] ?? '');
        $sec33_factor = (string)($_POST['sec_factor'] ?? '');
        $sec33_input = ['pin' => (string)($_POST['sec_pin'] ?? ''), 'otp_code' => $sec33_factor, 'totp_code' => $sec33_factor];
        $sec33_err = function_exists('bc_stepup_admin_verify')
            ? bc_stepup_admin_verify($get_logged_admin_details, $sec33_input, 'user_account_action')
            : 'Verification is unavailable.';
        if ($sec33_err !== '') {
            $_SESSION["product_purchase_response"] = "Error: " . $sec33_err;
            header("Location: " . bc_user_action_confirmation_url($_POST));
            exit();
        }
        unset($_GET['account-status'], $_GET['account-api-status'], $_GET['account-log'], $_GET['login-as']);
        unset($_POST['permanent-delete-user']); // Only the verified permanent mode may set this.
        $sec33_gate_ok = true;
        $GLOBALS['sec33_verified'] = true;
        if (function_exists('bc_sec_audit')) bc_sec_audit((int)$get_logged_admin_details['id'], 'admin', (string)$get_logged_admin_details['email'], 'user_' . $sec33_mode . '_action', 'user#' . (int)($_POST['account-user-id'] ?? 0), $sec33_mode . ' ' . $sec33_status);
        if ($sec33_mode === 'status') {
            $_GET['account-status'] = $sec33_status;
            $_GET['account-user-id'] = $_POST['account-user-id'] ?? '';
            $_GET['account-username'] = $_POST['account-username'] ?? '';
        } elseif ($sec33_mode === 'api') {
            $_GET['account-api-status'] = $sec33_status;
            $_GET['account-user-id'] = $_POST['account-user-id'] ?? '';
            $_GET['account-username'] = $_POST['account-username'] ?? '';
        } elseif ($sec33_mode === 'log') {
            $_GET['account-log'] = $_POST['account-user-id'] ?? '';
        } elseif ($sec33_mode === 'permanent') {
            $_POST['permanent-delete-user'] = '1';
        }
    }
    if (!$sec33_gate_ok && (isset($_GET["account-status"]) || isset($_GET["account-api-status"]) || isset($_GET["account-log"]))) {
        $_SESSION["product_purchase_response"] = "Error: that action now needs the secure confirmation form (PIN + authenticator code).";
        header("Location: /bc-admin/Users.php");
        exit();
    }

    if(isset($_GET["account-status"])){
        $status = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["account-status"])));
        $account_user = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["account-username"] ?? "")));
        $account_user_id = isset($_GET["account-user-id"]) ? (int) $_GET["account-user-id"] : 0;
        $statusArray = array(1, 2, 3);
        $statusVerbArray = array(1 => "activated", 2 => "deactivated", 3 => "deleted");
        $json_response_array = array("desc" => "Unexpected error");
        if(is_numeric($status)){
            if(in_array($status, $statusArray)){
                $send_mail_to_user = false;
                // Resolve the row the same way the table listed it - by id when the browser sends
                // it, otherwise by username - inside THIS vendor. Never via alterUser(), which
                // re-derives the vendor from the request host and needs exactly one matching row.
                $get_user_details = bc_admin_find_user($account_user_id, $_GET["account-username"] ?? "");
                if(!$get_user_details){
                    $json_response_array = array("desc" => "User not found for this vendor");
                }else{
                    $account_user = $get_user_details["username"];
                    $account_update_error = "";
                    if(bc_admin_set_user_column($get_user_details["id"], "status", $status, $account_update_error)){
                        $send_mail_to_user = true;
                        $json_response_array = array("desc" => ucwords($account_user." account ".$statusVerbArray[$status]." successfully"));
                    }else{
                        $json_response_array = array("desc" => ucwords($account_user." account cannot be ".$statusVerbArray[$status]).($account_update_error !== "" ? " - ".$account_update_error : ""));
                    }

                    if($send_mail_to_user == true){
                        // Email Beginning
                        $log_template_encoded_text_array = array("{firstname}" => $get_user_details["firstname"], "{lastname}" => $get_user_details["lastname"], "{account_status}" => accountStatus($status));
                        $raw_log_template_subject = getUserEmailTemplate('user-account-status','subject');
                        $raw_log_template_body = getUserEmailTemplate('user-account-status','body');
                        foreach($log_template_encoded_text_array as $array_key => $array_val){
                            $raw_log_template_subject = str_replace($array_key, $array_val, $raw_log_template_subject);
                            $raw_log_template_body = str_replace($array_key, $array_val, $raw_log_template_body);
                        }
                        sendVendorEmail($get_user_details["email"], $raw_log_template_subject, $raw_log_template_body);
                        // Email End
                    }
                }
            }else{
                //Invalid Status Code
                $json_response_array = array("desc" => "Invalid Status Code");
            }
        }else{
            //Non-numeric string
            $json_response_array = array("desc" => "Non-numeric string");
        }
        $_SESSION["product_purchase_response"] = $json_response_array["desc"] ?? "Unexpected error";
        header("Location: /bc-admin/Users.php");
        exit;
    }

    // §29: force one user to change password at next sign-in (CSRF-protected;
    // the module also revokes their app sessions and writes the audit event).
    if(isset($_POST["force-user-password-change"])){
        if (!function_exists('bc_validate_csrf')) { http_response_code(503); exit('Verification is unavailable.'); }
        bc_validate_csrf();
        $account_user = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["account-username"] ?? "")));
        $force_res = mysqli_query($connection_server, "SELECT id, username FROM sas_users WHERE vendor_id='".(int)$get_logged_admin_details["id"]."' && username='$account_user' LIMIT 1");
        $force_row = $force_res ? mysqli_fetch_array($force_res) : null;
        if ($force_row && function_exists('bc_force_password_change') && bc_force_password_change((int)$get_logged_admin_details["id"], (int)$force_row["id"])) {
            $_SESSION["product_purchase_response"] = ucwords($force_row["username"]." must change their password at next sign-in; their app sessions were revoked.");
        } else {
            $_SESSION["product_purchase_response"] = "Error: Could not force a password change for that account.";
        }
        header("Location: /bc-admin/Users.php");
        exit();
    }

    // §29: force EVERY user of this vendor to change password — high-impact,
    // so it needs CSRF + admin PIN + authenticator/email step-up.
    if(isset($_POST["force-all-password-change"])){
        if (!function_exists('bc_validate_csrf')) { http_response_code(503); exit('Verification is unavailable.'); }
        bc_validate_csrf();
        $sec29_err = function_exists('bc_stepup_admin_verify') ? bc_stepup_admin_verify($get_logged_admin_details, $_POST, 'force_password_change_all') : 'Verification is unavailable.';
        if ($sec29_err === '' && function_exists('bc_force_password_change') && bc_force_password_change((int)$get_logged_admin_details["id"], 0)) {
            $_SESSION["product_purchase_response"] = "All users must now change their password at next sign-in; app sessions were revoked.";
        } else {
            $_SESSION["product_purchase_response"] = "Error: " . ($sec29_err !== '' ? $sec29_err : "Could not force password changes.");
        }
        header("Location: /bc-admin/Users.php");
        exit();
    }

    // §29: emailed step-up code for the force-all action.
    if(isset($_POST["send_stepup_code"])){
        if (!function_exists('bc_validate_csrf')) { http_response_code(503); exit('Verification is unavailable.'); }
        bc_validate_csrf();
        $send_err = function_exists('bc_stepup_send_email_otp') ? bc_stepup_send_email_otp((int)$get_logged_admin_details['id'], (string)$get_logged_admin_details['email'], (string)$get_logged_admin_details['email'], 'force_password_change_all', 'Force Password Change') : 'Verification is unavailable.';
        $_SESSION["product_purchase_response"] = $send_err === '' ? "Verification code sent to your email." : "Error: $send_err";
        header("Location: /bc-admin/Users.php");
        exit();
    }

    if(isset($_POST["permanent-delete-user"])){
        // §33: permanent deletion only via the step-up gate above.
        if (empty($GLOBALS['sec33_verified'])) {
            $_SESSION["product_purchase_response"] = "Error: permanent deletion needs the secure confirmation form (PIN + authenticator code).";
            header("Location: /bc-admin/Users.php");
            exit();
        }
        $account_user_id = isset($_POST["account-user-id"]) ? (int) $_POST["account-user-id"] : 0;
        $account_user = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["account-username"] ?? "")));
        $get_user_details = bc_admin_find_user($account_user_id, $_POST["account-username"] ?? "");

        // Permanent delete is the SECOND step, so the row has to still be in the Deleted state.
        if($get_user_details && (int) $get_user_details["status"] === 3){
            $delete_user = mysqli_query($connection_server, "DELETE FROM sas_users WHERE vendor_id='".$get_logged_admin_details["id"]."' && id='".(int) $get_user_details["id"]."'");
            // mysqli_query() reports TRUE even when nothing matched, so the affected-row count is
            // the only thing that distinguishes a real delete from a silent no-op.
            if($delete_user && mysqli_affected_rows($connection_server) > 0){
                $_SESSION["product_purchase_response"] = ucwords($get_user_details["username"]." account permanently deleted successfully");
            } else {
                $_SESSION["product_purchase_response"] = "Error: Could not permanently delete ".$get_user_details["username"];
            }
        } else {
            $_SESSION["product_purchase_response"] = "Error: Could not permanently delete ".($account_user !== "" ? $account_user : "user");
        }
        header("Location: /bc-admin/Users.php");
        exit();
    }

    if(isset($_POST["bulk-user-action"])){
        $bulk_action = isset($_POST["bulk-user-action"]) ? trim(strip_tags($_POST["bulk-user-action"])) : "";
        // Closed set of actions; the request never names a column or a value directly.
        if (!function_exists('bc_validate_csrf')) { http_response_code(503); exit('Verification is unavailable.'); }
        bc_validate_csrf();
        $bulk_actions = array(
            "activate"         => array("column" => "status", "value" => 1, "label" => "activated"),
            "block"            => array("column" => "status", "value" => 2, "label" => "blocked"),
            "delete"           => array("column" => "status", "value" => 3, "label" => "deleted"),
            "permanent-delete" => array("column" => "", "value" => "", "label" => "permanently deleted"),
            "force-password-change" => array("column" => "", "value" => "", "label" => "flagged for a forced password change"),
        );

        // Only ids, deduplicated, and only this vendor's rows are ever touched.
        $bulk_ids = array();
        if (isset($_POST["bulk-user-ids"]) && is_array($_POST["bulk-user-ids"])) {
            foreach ($_POST["bulk-user-ids"] as $raw_bulk_id) {
                $clean_bulk_id = (int) $raw_bulk_id;
                if ($clean_bulk_id > 0) {
                    $bulk_ids[$clean_bulk_id] = $clean_bulk_id;
                }
            }
        }
        $bulk_ids = array_values($bulk_ids);

        if (!isset($bulk_actions[$bulk_action])) {
            $_SESSION["product_purchase_response"] = "Please choose an action to apply.";
        } elseif (count($bulk_ids) === 0) {
            $_SESSION["product_purchase_response"] = "No accounts were selected.";
        } elseif (count($bulk_ids) > 500) {
            $_SESSION["product_purchase_response"] = "Too many accounts selected at once (".count($bulk_ids)."). Please select 500 or fewer.";
        } else {
            $action_details = $bulk_actions[$bulk_action];
            $bulk_vendor_id = (int) $get_logged_admin_details["id"];
            $bulk_id_list = implode(",", $bulk_ids);
            $bulk_selection = mysqli_query($connection_server, "SELECT id, status FROM sas_users WHERE vendor_id='$bulk_vendor_id' && id IN ($bulk_id_list)");

            $bulk_applied = 0;
            $bulk_failed = 0;
            $bulk_skipped = 0;
            if ($bulk_selection) {
                while ($bulk_row = mysqli_fetch_assoc($bulk_selection)) {
                    $bulk_row_id = (int) $bulk_row["id"];
                    if ($bulk_action === "force-password-change") {
                        // §29: flag + app-token revocation + audit per account.
                        if (function_exists('bc_force_password_change') && bc_force_password_change($bulk_vendor_id, $bulk_row_id)) {
                            $bulk_applied++;
                        } else {
                            $bulk_failed++;
                        }
                        continue;
                    }
                    if ($bulk_action === "permanent-delete") {
                        // Same two-step rule as the single-row button: only an account already in
                        // the Deleted state can be removed for good.
                        if ((int) $bulk_row["status"] !== 3) {
                            $bulk_skipped++;
                            continue;
                        }
                        $bulk_delete = mysqli_query($connection_server, "DELETE FROM sas_users WHERE vendor_id='$bulk_vendor_id' && id='$bulk_row_id'");
                        if ($bulk_delete && mysqli_affected_rows($connection_server) > 0) {
                            $bulk_applied++;
                        } else {
                            $bulk_failed++;
                        }
                    } else {
                        $bulk_error = "";
                        if (bc_admin_set_user_column($bulk_row_id, $action_details["column"], $action_details["value"], $bulk_error)) {
                            $bulk_applied++;
                        } else {
                            $bulk_failed++;
                        }
                    }
                }
            }

            // Note: unlike the single-row status buttons this sends no email per user - one SMTP
            // round trip per account would make a large selection time out mid-request.
            $bulk_message = $bulk_applied . " of " . count($bulk_ids) . " selected account(s) " . $action_details["label"] . ".";
            if ($bulk_skipped > 0) {
                $bulk_message .= " " . $bulk_skipped . " skipped because they are not in the Deleted state.";
            }
            if ($bulk_failed > 0) {
                $bulk_message .= " " . $bulk_failed . " could not be updated.";
            }
            $_SESSION["product_purchase_response"] = $bulk_message;
        }
        header("Location: /bc-admin/Users.php");
        exit();
    }

    if(isset($_GET["account-api-status"])){
        $status = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["account-api-status"])));
        $account_user = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["account-username"] ?? "")));
        $account_user_id = isset($_GET["account-user-id"]) ? (int) $_GET["account-user-id"] : 0;
        $statusArray = array(1, 2);
        $statusArrayValue = array(1 => "Activated", 2 => "Deactivated");
        $json_response_array = array("desc" => "Unexpected error");

        if(is_numeric($status)){
            if(in_array($status, $statusArray)){
                $send_mail_to_user = false;
                $get_user_details = bc_admin_find_user($account_user_id, $_GET["account-username"] ?? "");
                if(!$get_user_details){
                    $json_response_array = array("desc" => "User not found for this vendor");
                }else{
                    $account_user = $get_user_details["username"];
                    $account_update_error = "";
                    if(bc_admin_set_user_column($get_user_details["id"], "api_status", $status, $account_update_error)){
                        $send_mail_to_user = true;
                        $json_response_array = array("desc" => ucwords($account_user." API access ".strtolower($statusArrayValue[$status])." and confirmed saved. Existing KYC, tenant service and API-key expiry policies still apply."));
                    }else{
                        $json_response_array = array("desc" => ucwords($account_user." API access cannot be ".strtolower($statusArrayValue[$status])).($account_update_error !== "" ? " - ".$account_update_error : ""));
                    }

                    if($send_mail_to_user == true){
                        // Email Beginning
                        $log_template_encoded_text_array = array("{firstname}" => $get_user_details["firstname"], "{lastname}" => $get_user_details["lastname"], "{api_status}" => $statusArrayValue[$status]);
                        $raw_log_template_subject = getUserEmailTemplate('user-api-status','subject');
                        $raw_log_template_body = getUserEmailTemplate('user-api-status','body');
                        foreach($log_template_encoded_text_array as $array_key => $array_val){
                            $raw_log_template_subject = str_replace($array_key, $array_val, $raw_log_template_subject);
                            $raw_log_template_body = str_replace($array_key, $array_val, $raw_log_template_body);
                        }
                        sendVendorEmail($get_user_details["email"], $raw_log_template_subject, $raw_log_template_body);
                        // Email End
                    }
                }
            }else{
                //Invalid Status Code
                $json_response_array = array("desc" => "Invalid Status Code");
            }
        }else{
            //Non-numeric string
            $json_response_array = array("desc" => "Non-numeric string");
        }
        $_SESSION["product_purchase_response"] = $json_response_array["desc"] ?? "Unexpected error";
        header("Location: /bc-admin/Users.php");
        exit;
    }

    if(isset($_GET["account-log"])){
        $account_log = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["account-log"])));
        if(is_numeric($account_log)){
            if($account_log >= 1){
			    $get_logged_user_query = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='".$get_logged_admin_details["id"]."' && id='$account_log'");
                if(mysqli_num_rows($get_logged_user_query) == 1){
                    $get_user_info = mysqli_fetch_array($get_logged_user_query);
                    $_SESSION["user_session"] = $get_user_info["username"];
                    $_SESSION["admin_to_user_redirect"] = true;
                }else{
                    if(mysqli_num_rows($get_logged_user_query) < 1){
                        $json_response_array = array("desc" => "Error: User not Exists");
                        $json_response_encode = json_encode($json_response_array,true);
                    }else{
                        if(mysqli_num_rows($get_logged_user_query) > 1){
                            $json_response_array = array("desc" => "Error: Duplicate User Accounts");
                            $json_response_encode = json_encode($json_response_array,true);
                        }
                    }
                }
            }else{
                //Invalid Account ID
                $json_response_array = array("desc" => "Invalid Account ID");
                $json_response_encode = json_encode($json_response_array,true);
            }
        }else{
            //Non-numeric string
            $json_response_array = array("desc" => "Non-numeric string");
            $json_response_encode = json_encode($json_response_array,true);
        }
        $json_response_decode = json_decode($json_response_encode,true);
        $_SESSION["product_purchase_response"] = $json_response_decode["desc"];
        header("Location: /bc-admin/Users.php");
        exit;
    }

?>
<?php include_once(__DIR__ . "/../func/bc-plate-admin.php");
bc_adm_head("Users | " . ($get_all_super_admin_site_details["site_title"] ?? 'DGV7 Platform'), array("area" => "admin"));
?>
<style data-pkx="page-redesign">
/* Modern fintech redesign layer (2026-11) */
.pk-app__wrap .card{border-radius:20px}
.pk-app__wrap .card-header{background:linear-gradient(135deg,color-mix(in srgb,var(--brand) 10%,var(--surface)),var(--surface));font-weight:800}
.pk-app__wrap .table thead th{letter-spacing:.04em;text-transform:uppercase;font-size:.72rem}
.pk-app__wrap .form-control,.pk-app__wrap .form-select{border-radius:12px}
.pk-app__wrap .form-label{letter-spacing:.05em}
.pk-app__wrap .btn{border-radius:12px;font-weight:700}
.pk-app__wrap .badge{border-radius:999px}
.pk-app__wrap .nav-pills .nav-link{border-radius:999px;font-weight:700}
.pk-app__wrap .modal-content{border-radius:20px;overflow:hidden}

.pk-app__wrap .avatar,.pk-app__wrap .user-avatar{box-shadow:inset 0 1px 0 rgba(255,255,255,.25)}
</style>
<?php
    	//Redirect To User Page
    	if(isset($_SESSION["admin_to_user_redirect"]) && ($_SESSION["admin_to_user_redirect"] == true)){
    		echo '<script>	window.onload = function(){	window.open("'.$web_http_host.'/web/Dashboard.php","_blank");	}	</script>';
    	}
    ?>
<?php bc_adm_sidebar(array("area" => "admin")); ?>
<?php bc_adm_topbar(array("area" => "admin", "title" => "USERS", "crumb" => "Home / Users")); ?><!-- End Page Title -->

    <section class="section dashboard">
      <div class="row">
        <!-- Stats Summary Cards -->
        <div class="col-xxl-3 col-md-6 mb-4">
          <div class="card info-card sales-card shadow-sm border-0 rounded-4">
            <div class="card-body">
              <h5 class="card-title">Total Users <span>| Platform</span></h5>
              <div class="d-flex align-items-center">
                <div class="card-icon rounded-circle d-flex align-items-center justify-content-center bg-primary bg-opacity-10 text-primary">
                  <i class="bi bi-people"></i>
                </div>
                <div class="ps-3">
                  <h6 id="stat-total-users">0</h6>
                  <span class="text-muted small pt-2">Registered</span>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="col-xxl-3 col-md-6 mb-4">
          <div class="card info-card revenue-card shadow-sm border-0 rounded-4">
            <div class="card-body">
              <h5 class="card-title">Active <span>| Accounts</span></h5>
              <div class="d-flex align-items-center">
                <div class="card-icon rounded-circle d-flex align-items-center justify-content-center bg-success bg-opacity-10 text-success">
                  <i class="bi bi-check-circle"></i>
                </div>
                <div class="ps-3">
                  <h6 id="stat-active-users">0</h6>
                  <span class="text-muted small pt-2">Enabled</span>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="col-xxl-3 col-md-6 mb-4">
          <div class="card info-card customers-card shadow-sm border-0 rounded-4">
            <div class="card-body">
              <h5 class="card-title">Blocked <span>| Restrictions</span></h5>
              <div class="d-flex align-items-center">
                <div class="card-icon rounded-circle d-flex align-items-center justify-content-center bg-warning bg-opacity-10 text-warning">
                  <i class="bi bi-exclamation-triangle"></i>
                </div>
                <div class="ps-3">
                  <h6 id="stat-blocked-users">0</h6>
                  <span class="text-muted small pt-2">Suspended</span>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="col-xxl-3 col-md-6 mb-4">
          <div class="card info-card customers-card shadow-sm border-0 rounded-4">
            <div class="card-body">
              <h5 class="card-title">Deleted <span>| Archived</span></h5>
              <div class="d-flex align-items-center">
                <div class="card-icon rounded-circle d-flex align-items-center justify-content-center bg-danger bg-opacity-10 text-danger">
                  <i class="bi bi-trash"></i>
                </div>
                <div class="ps-3">
                  <h6 id="stat-deleted-users">0</h6>
                  <span class="text-muted small pt-2">Removed</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="col-12">
        <div class="card shadow-sm border-0 rounded-4 mb-4">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="fw-bold mb-0">Admin Tools</h5>
                    <div class="d-flex gap-2">
                        <button class="btn btn-outline-primary btn-sm rounded-pill px-3" type="button" data-bs-toggle="collapse" data-bs-target="#importExportCollapse">
                            <i class="bi bi-tools me-1"></i> Import/Export
                        </button>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-7">
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                            <input id="user-search-input" type="text" placeholder="Search by Email, Username, Phone, Name..." class="form-control border-start-0" />
                        </div>
                    </div>
                    <div class="col-md-3">
                        <select id="user-status-filter" class="form-select">
                            <option value="1">Active Accounts</option>
                            <option value="2">Blocked Accounts</option>
                            <option value="3">Deleted Accounts</option>
                            <option value="all">All Accounts</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="button" onclick="fetchUsers(1)" class="btn btn-primary w-100">Apply Filter</button>
                    </div>
                </div>

                <div class="collapse" id="importExportCollapse">
                    <div class="row g-4 pt-3 border-top">
                        <div class="col-md-6 border-end">
                            <h6 class="fw-bold small text-muted text-uppercase mb-3">Import Users (CSV)</h6>
                            <form method="post" action="Users.php" enctype="multipart/form-data">
                                <div class="input-group">
                                    <input type="file" name="user-csv" class="form-control" accept=".csv" required>
                                    <button name="import-users" type="submit" class="btn btn-primary">Import</button>
                                </div>
                            </form>
                        </div>
                        <div class="col-md-6">
                            <h6 class="fw-bold small text-muted text-uppercase mb-3">Export Users</h6>
                            <form method="post" action="Users.php" class="d-flex gap-2">
                                <select name="status" class="form-select">
                                    <option value="all">All Status</option>
                                    <option value="1">Active Only</option>
                                    <option value="2">Blocked Only</option>
                                    <option value="3">Deleted Only</option>
                                </select>
                                <button name="export-users" type="submit" class="btn btn-primary text-nowrap">Export CSV</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4">
            <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0 text-primary" id="user-table-header">Users List</h6>
                <div id="pagination-top"></div>
            </div>
                        <div class="px-4 py-2 border-bottom bg-warning bg-opacity-10">
                <form method="post" class="d-flex align-items-center gap-2 flex-wrap m-0">
                    <?php if (function_exists('bc_csrf_field')) echo bc_csrf_field(); ?>
                    <span class="small fw-bold text-dark"><i class="bi bi-shield-lock me-1"></i>Security:</span>
                    <span class="small text-muted">Force every user to change password (kills app sessions):</span>
                    <input type="password" name="pin" maxlength="4" inputmode="numeric" placeholder="PIN" class="form-control form-control-sm" style="width:80px;">
                    <input type="text" name="otp_code" maxlength="6" inputmode="numeric" placeholder="2FA code" class="form-control form-control-sm" style="width:110px;">
                    <input type="hidden" name="totp_code" value="">
                    <button type="submit" name="send_stepup_code" value="1" formnovalidate class="btn btn-sm btn-outline-primary">Email me a code</button>
                    <button type="submit" name="force-all-password-change" value="1" class="btn btn-sm btn-warning" onclick="return confirm('Force ALL users to change their password at next sign-in?')">Force for all users</button>
                </form>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="font-size: 0.85rem;">
                    <thead class="bg-light">
                      <tr>
                          <th class="ps-4">S/N</th><th>User Info</th><th>Account Details</th><th>Financials</th><th>Security & API</th><th>Dates</th><th class="pe-4">Actions</th>
                      </tr>
                    </thead>
                    <tbody id="user-table-body">
                        <!-- Content loaded via AJAX -->
                        <tr><td colspan="7" class="text-center py-5"><div class="spinner-border text-primary"></div></td></tr>
                    </tbody>
                </table>
                </div>
            </div>
            <div class="card-footer bg-white py-3">
                <div id="pagination-bottom" class="d-flex justify-content-center gap-2"></div>
            </div>
        </div>

        </div>
      </div>
    </section>



    <script>
    document.addEventListener("DOMContentLoaded", function() {
        fetchUsers(1);

        // Live search with debounce
        let searchTimeout = null;
        document.getElementById("user-search-input").addEventListener("input", function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                fetchUsers(1);
            }, 500);
        });
    });

    function fetchUsers(page) {
        const search = document.getElementById("user-search-input").value;
        const status = document.getElementById("user-status-filter").value;
        const body = document.getElementById("user-table-body");

        // Show loading state
        body.innerHTML = `<tr><td colspan="7" class="text-center py-5"><div class="spinner-border text-primary"></div></td></tr>`;

        fetch(`ajax-users.php?page=${page}&searchq=${encodeURIComponent(search)}&status=${status}`)
            .then(r => r.json())
            .then(res => {
                if (res.status === 'success') {
                    renderTable(res.users, res.pagination.current_page);
                    renderPagination(res.pagination);
                    updateStats(res.stats);
                } else {
                    body.innerHTML = `<tr><td colspan="7" class="text-center py-5 text-danger">${res.message}</td></tr>`;
                }
            })
            .catch(e => {
                body.innerHTML = `<tr><td colspan="7" class="text-center py-5 text-danger">Network Error</td></tr>`;
            });
    }

    function renderTable(users, currentPage) {
        const body = document.getElementById("user-table-body");
        if (users.length === 0) {
            body.innerHTML = `<tr><td colspan="7" class="text-center py-5 text-muted">No users found matching criteria.</td></tr>`;
            return;
        }

        let html = '';
        users.forEach((u, i) => {
            const sn = ((currentPage - 1) * 10) + (i + 1);

            let statusActions = '';
            if (u.status == '1') {
                statusActions = `<button class="btn btn-sm btn-light border text-danger" onclick="updateUserAccountStatus('2','${u.username}')" title="Block User"><i class="bi bi-ban"></i></button>
                                 <button class="btn btn-sm btn-light border text-success" onclick="updateUserAccountStatus('3','${u.username}')" title="Delete User"><i class="bi bi-trash"></i></button>`;
            } else if (u.status == '2') {
                statusActions = `<button class="btn btn-sm btn-light border text-primary" onclick="updateUserAccountStatus('1','${u.username}')" title="Activate User"><i class="bi bi-check-circle"></i></button>
                                 <button class="btn btn-sm btn-light border text-success" onclick="updateUserAccountStatus('3','${u.username}')" title="Delete User"><i class="bi bi-trash"></i></button>`;
            } else {
                statusActions = `<button class="btn btn-sm btn-light border text-primary" onclick="updateUserAccountStatus('1','${u.username}')" title="Activate User"><i class="bi bi-check-circle"></i></button>
                                 <button class="btn btn-sm btn-light border text-danger" onclick="permanentlyDeleteUser('${u.username}')" title="Permanently Delete"><i class="bi bi-trash-fill"></i></button>`;
            }

            statusActions += ` <button class="btn btn-sm btn-light border text-warning" onclick="forceUserPasswordChange('${u.username}')" title="Force Password Change"><i class="bi bi-key-fill"></i></button>`;

            const apiBadge = (u.api_status == '1')
                ? `<button type="button" class="badge border-0 bg-success bg-opacity-10 text-success rounded-pill px-2 py-1" onclick="updateUserAccountAPIStatus('2','${u.username}')">API Enabled <i class="bi bi-toggle-on ms-1"></i></button>`
                : `<button type="button" class="badge border-0 bg-secondary bg-opacity-10 text-secondary rounded-pill px-2 py-1" onclick="updateUserAccountAPIStatus('1','${u.username}')">API Disabled <i class="bi bi-toggle-off ms-1"></i></button>`;

            html += `
                <tr>
                    <td class="ps-4 fw-bold">${sn}</td>
                    <td>
                        <div class="fw-bold text-dark">${u.fullname}</div>
                        <div class="small text-muted">@${u.username} <button class="btn p-0 text-primary small" onclick="customJsRedirect('/bc-admin/UserEdit.php?userID=${u.id}', 'Edit @${u.username}?')"><i class="bi bi-pencil-square ms-1"></i></button></div>
                        <div class="small text-muted text-break" style="max-width: 150px;">${u.email}</div>
                        <div class="small text-muted"><i class="bi bi-telephone me-1"></i>${u.phone_number}</div>
                    </td>
                    <td>
                        <div class="small fw-bold">${u.level_name} <button class="btn p-0 text-primary small" onclick="customJsRedirect('/bc-admin/UserUpgrade.php?userID=${u.id}', 'Upgrade @${u.username}?')"><i class="bi bi-arrow-down-up ms-1"></i></button></div>
                        <div class="small text-muted">Ref: ${u.referral_username}</div>
                        <div class="small text-muted text-truncate" style="max-width: 150px;" title="${u.home_address}"><i class="bi bi-geo-alt me-1"></i>${u.home_address}</div>
                    </td>
                    <td>
                        <div class="h6 fw-bold mb-1">₦${u.balance_formatted}</div>
                        <div class="small text-muted">Wallet Balance</div>
                    </td>
                    <td>
                        <div class="mb-2">${apiBadge}</div>
                        <div class="small text-muted">Ans: <span class="fw-bold text-dark">${u.security_answer || 'N/A'}</span></div>
                        <div class="small text-muted">Key: <span class="fw-bold text-dark">${u.api_key.substring(0,8)}...</span> <i class="bi bi-copy text-primary a-cursor" onclick="copyText('API Key copied','${u.api_key}')"></i></div>
                    </td>
                    <td>
                        <div class="small"><span class="text-muted">Reg:</span> ${u.reg_date_formatted}</div>
                        <div class="small"><span class="text-muted">Last:</span> ${u.last_login_formatted}</div>
                    </td>
                    <td>
                        <div class="d-flex gap-2 justify-content-end pe-3 mb-2">
                            ${statusActions}
                            <button class="btn btn-sm btn-light border text-primary" onclick="loginUserAccount('${u.id}', '${u.username}')" title="Login as User"><i class="bi bi-box-arrow-in-right"></i></button>
                        </div>
                        <div class="d-flex gap-2 justify-content-end pe-3">
                            <a href="Transactions.php?searchq=${u.username}" class="btn btn-sm btn-outline-info" title="View Transactions"><i class="bi bi-list-task"></i></a>
                            <a href="ShareFund.php?searchq=${u.username}" class="btn btn-sm btn-outline-success" title="Fund User"><i class="bi bi-plus-circle"></i></a>
                        </div>
                    </td>
                </tr>`;
        });
        body.innerHTML = html;
    }

    function renderPagination(p) {
        const container = document.getElementById("pagination-bottom");
        if (p.total_pages <= 1) {
            container.innerHTML = '';
            return;
        }

        let html = '';
        if (p.current_page > 1) {
            html += `<button class="btn btn-outline-primary btn-sm" onclick="fetchUsers(${p.current_page - 1})">Prev</button>`;
        }

        // Show max 5 page numbers
        let start = Math.max(1, p.current_page - 2);
        let end = Math.min(p.total_pages, start + 4);
        if (end - start < 4) start = Math.max(1, end - 4);

        for (let i = start; i <= end; i++) {
            html += `<button class="btn btn-sm ${i === p.current_page ? 'btn-primary' : 'btn-outline-primary'}" onclick="fetchUsers(${i})">${i}</button>`;
        }

        if (p.current_page < p.total_pages) {
            html += `<button class="btn btn-outline-primary btn-sm" onclick="fetchUsers(${p.current_page + 1})">Next</button>`;
        }
        container.innerHTML = html;
    }

    function updateStats(s) {
        document.getElementById("stat-total-users").textContent = s.total;
        document.getElementById("stat-active-users").textContent = s.active;
        document.getElementById("stat-blocked-users").textContent = s.blocked;
        document.getElementById("stat-deleted-users").textContent = s.deleted;
    }

    function updateUserAccountStatus(status, username) {
        const action = ({1: 'Activate', 2: 'Block', 3: 'Archive'})[status] || 'Update';
        sec33OpenUserModal('status', status, 0, username, action + ' Account?', `Are you sure you want to ${action.toLowerCase()} account for @${username}? Security PIN + verification code required. Audited.`, 'Yes, proceed');
    }

    function updateUserAccountAPIStatus(status, username) {
        const action = status == 1 ? 'Enable' : 'Disable';
        sec33OpenUserModal('api', status, 0, username, action + ' API?', `Are you sure you want to ${action.toLowerCase()} API access for @${username}? Security PIN + verification code required. Audited.`, 'Yes, proceed');
    }

    function loginUserAccount(id, username) {
        sec33OpenUserModal('log', '', id, username, 'Login as User?', `You are about to login to @${username}'s account. Security PIN + verification code required. Audited.`, 'Yes, login');
    }

    const BC_CSRF_TOKEN = '<?php echo function_exists("bc_generate_csrf_token") ? htmlspecialchars(bc_generate_csrf_token(), ENT_QUOTES, "UTF-8") : ""; ?>';
    function forceUserPasswordChange(username) {
        Swal.fire({
            title: 'Force password change?',
            text: "@" + username + " must set a new password at next sign-in and will be signed out of the app.",
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, force it'
        }).then((result) => {
            if (!result.isConfirmed) return;
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'Users.php';
            const fields = { '_csrf_token': BC_CSRF_TOKEN, 'force-user-password-change': '1', 'account-username': username };
            Object.keys(fields).forEach(function (k) {
                const i = document.createElement('input');
                i.type = 'hidden'; i.name = k; i.value = fields[k];
                form.appendChild(i);
            });
            document.body.appendChild(form);
            form.submit();
        });
    }

    function permanentlyDeleteUser(username) {
        sec33OpenUserModal('permanent', '', 0, username, 'Permanently delete?', "This will permanently delete the account for @" + username + ". This cannot be undone! Security PIN + verification code required. Audited.", 'Delete permanently');
    }
    </script>





    <script>
    function sec33OpenUserModal(mode, status, id, username, title, text, btn) {
        // A full document avoids modal clipping, unsupported dialog APIs and stale shell CSS.
        const query = new URLSearchParams({ mode: mode, status: status || '', id: id || '', username: username || '' });
        window.location.assign('UserAction.php?' + query.toString());
    }

    <?php $sec33_draft = $_SESSION['sec33_user_draft'] ?? null; unset($_SESSION['sec33_user_draft']); ?>
    const sec33Draft = <?php echo json_encode($sec33_draft, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    if (sec33Draft) {
        sec33OpenUserModal(sec33Draft.sec33_user_action, sec33Draft.sec33_status,
            sec33Draft['account-user-id'], sec33Draft['account-username'],
            'Verify user action', 'Confirm ' + sec33Draft.sec33_user_action + ' for @' + sec33Draft['account-username'] + '. Enter your Security PIN and verification code.', 'Confirm');
    }
    </script>

<?php bc_adm_foot(array("area" => "admin")); ?>

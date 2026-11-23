<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
 bc_secure_session_start();
    include("../func/bc-spadmin-config.php");
require_once __DIR__.'/../func/bc-admin-inline-otp.php';
bc_admin_inline_otp_dispatch($get_logged_spadmin_details,true,'vendor_registration_action');
    include_once("../func/whmp-func.php");
require_once __DIR__.'/../func/bc-vendor-registration.php';

    // §33/§9: vendor registration actions (verify payment / approve /
    // decline) are POST + CSRF + step-up only. Approving a new vendor and
    // confirming payment need Security PIN + authenticator/email code;
    // declining needs the PIN. Bare GET links no longer perform them.
    $sec33_gate_ok = false;
    if (isset($_POST['sec33_send_code'])) {
        if (function_exists('bc_validate_csrf')) bc_validate_csrf();
        $sec33_send_err = function_exists('bc_stepup_send_email_otp')
            ? bc_stepup_send_email_otp(0, (string)$get_logged_spadmin_details['email'], (string)$get_logged_spadmin_details['email'], 'vendor_registration_action', 'Vendor Registration Action')
            : 'Verification is unavailable.';
        $_SESSION["page_alert"] = $sec33_send_err === '' ? "Verification code sent to your email." : "Error: $sec33_send_err";
        header("Location: VendorRegistrations.php");
        exit();
    }
    if (isset($_POST['vr_action']) && isset($_POST['vr_id'])) {
        if (function_exists('bc_validate_csrf')) bc_validate_csrf();
        $sec33_action = (string)$_POST['vr_action'];
        $sec33_factor = (string)($_POST['sec_factor'] ?? '');
        if ($sec33_action === 'decline') {
            $sec33_input = ['factor_method'=>(string)($_POST['factor_method']??'auto'), 'pin' => (string)($_POST['sec_pin'] ?? ''), 'otp_code' => (string)($_POST['sec_factor'] ?? ''), 'totp_code' => (string)($_POST['sec_factor'] ?? '')];
        } else {
            $sec33_input = ['factor_method'=>(string)($_POST['factor_method']??'auto'), 'pin' => (string)($_POST['sec_pin'] ?? ''), 'otp_code' => $sec33_factor, 'totp_code' => $sec33_factor];
        }
        $sec33_err = function_exists('bc_stepup_spadmin_verify')
            ? bc_stepup_spadmin_verify($get_logged_spadmin_details, $sec33_input, 'vendor_registration_action')
            : 'Verification is unavailable.';
        if ($sec33_err !== '') {
            $_SESSION["page_alert"] = "Error: " . $sec33_err;
            header("Location: VendorRegistrations.php");
            exit();
        }
        $sec33_gate_ok = true;
        if (function_exists('bc_sec_audit')) bc_sec_audit(0, 'spadmin', (string)$get_logged_spadmin_details['email'], 'vendor_registration_' . $sec33_action, 'pending#' . (int)$_POST['vr_id'], '');
        $_GET['action'] = $sec33_action;
        $_GET['id'] = (string)(int)$_POST['vr_id'];
    }
    if (!$sec33_gate_ok && isset($_GET['action']) && isset($_GET['id'])) {
        $_SESSION["page_alert"] = "Error: that action now needs the secure confirmation form (PIN + authenticator code).";
        header("Location: VendorRegistrations.php");
        exit();
    }

    // Handle actions
    if(isset($_GET['action']) && isset($_GET['id'])) {
        $action = $_GET['action'];
        $pending_id = mysqli_real_escape_string($connection_server, $_GET['id']);
        if(!in_array($action,['approve','decline','mark_paid','review_compliance'],true)){http_response_code(400);exit('Invalid action.');}
        // Serialize decisions and policy changes before reading the applicant or compliance requirements.
        foreach(['bc_vendor_registration_settings','bc_vreg_pending_'.(int)$pending_id] as $decision_lock){
            $acquired=bc_q_row('SELECT GET_LOCK(?,2) AS acquired',[$decision_lock]);
            if((int)($acquired['acquired']??0)!==1){http_response_code(409);exit('Registration review is busy. Refresh before retrying.');}
            register_shutdown_function(function()use($decision_lock){bc_q_row('SELECT RELEASE_LOCK(?) AS released',[$decision_lock]);});
        }


        // Mark as Paid for manual deposit
        if($action == 'mark_paid') {
            if (!mysqli_query($connection_server, "UPDATE sas_pending_vendors SET payment_status='paid' WHERE id='$pending_id'")) {
                error_log("[VendorReg] Error marking as paid: " . mysqli_error($connection_server));
                $_SESSION["page_alert"] = "An error occurred. Please try again.";
                header("Location: VendorRegistrations.php"); exit;
            }
            $_SESSION['page_alert'] = "Vendor marked as paid.";
            header("Location: VendorRegistrations.php");
            exit();
        }

        $result = mysqli_query($connection_server, "SELECT * FROM sas_pending_vendors WHERE id='$pending_id'");
        if (!$result) {
            error_log("[VendorReg] Error fetching pending vendor: " . mysqli_error($connection_server));
            $_SESSION["page_alert"] = "An error occurred. Please try again.";
            header("Location: VendorRegistrations.php"); exit;
        }
        if(mysqli_num_rows($result) > 0) {
            $pending_vendor = mysqli_fetch_assoc($result);


            if($action === 'review_compliance'){
                try{
                    $policy=bc_vreg_policy();
                    if(empty($pending_vendor['email_verified_at']))throw new RuntimeException('Mailbox must be verified before compliance review. Legacy applications must be resubmitted.');
                    if(!is_string($_POST['review_snapshot']??null)||!hash_equals(bc_vreg_compliance_digest($pending_vendor,$policy),$_POST['review_snapshot']))throw new RuntimeException('Applicant details or policy changed. Refresh and review again.');
                    $proof=['digest'=>bc_vreg_compliance_digest($pending_vendor,$policy),'reviewer'=>(int)$get_logged_spadmin_details['id'],'at'=>gmdate('c')];
                    foreach(['kyc','kyb'] as $type){
                        $ref=$_POST[$type.'_case_reference']??'';
                        if(!is_string($ref)||strlen($ref)>190||($policy[$type]&&!preg_match('/\A[A-Za-z0-9][A-Za-z0-9 ._:\/-]{7,189}\z/D',$ref)))throw new RuntimeException('Supply the completed verification case reference for each required check.');
                        $proof[$type]=$ref;
                    }
                    if($policy['kyb']&&(empty($pending_vendor['business_name'])||empty($pending_vendor['business_registration_no'])))throw new RuntimeException('Applicant business details are missing. Request a complete application.');
                    if(empty($_POST['compliance_confirmed']))throw new RuntimeException('Confirm you reviewed the required evidence.');
                    // Bind review to the exact identity snapshot; any identity edit invalidates it.
                    $encoded=json_encode($proof,JSON_THROW_ON_ERROR);
                    bc_security_ensure_schema();$connection_server->begin_transaction();
                    if(bc_q('UPDATE sas_pending_vendors SET compliance_review=? WHERE id=?',[$encoded,(int)$pending_id])!==true)throw new RuntimeException('Review could not be saved.');
                    if(!bc_sec_audit(0,'spadmin',(string)$get_logged_spadmin_details['id'],'vendor_compliance_review','pending#'.(int)$pending_id,'snapshot='.$proof['digest'].';kyc='.$proof['kyc'].';kyb='.$proof['kyb']))throw new RuntimeException('Review audit unavailable.');
                    $connection_server->commit();
                    $_SESSION['page_alert']='Compliance review recorded. Approval still requires verified payment and the current policy checks.';
                }catch(Throwable $e){$connection_server->rollback();$_SESSION['page_alert']='Review not confirmed. Refresh, check the evidence references and retry.';}
                header('Location: VendorRegistrations.php');exit;
            }
            if($action == 'approve') {
                try{$approval_error=bc_vreg_approval_error($pending_vendor,bc_vreg_policy());}
                catch(Throwable $e){$approval_error='Registration compliance policy unavailable. Approval refused.';}
                if($approval_error!==''){$_SESSION['page_alert']=$approval_error;header('Location: VendorRegistrations.php');exit;}

                if(trim($pending_vendor['payment_status']) == 'paid') {
                    // 1. Get package details
                    $package_id = $pending_vendor['billing_package_id'];
                    $package_result = mysqli_query($connection_server, "SELECT * FROM sas_billing_packages WHERE id='$package_id'");
                    if (!$package_result) { error_log("[VendorReg] Error fetching package: " . mysqli_error($connection_server)); $_SESSION["page_alert"] = "An error occurred. Please try again."; header("Location: VendorRegistrations.php"); exit; }
                    $package = mysqli_fetch_assoc($package_result);
                    $duration_days = $package['duration_days'];

                    // 2. Insert into main vendors table
                    $email = $pending_vendor['email'];
                    $password = $pending_vendor['password'];
                    $firstname = $pending_vendor['firstname'];
                    $lastname = $pending_vendor['lastname'];
                    $phone_number = $pending_vendor['phone_number'];
                    $website_url = $pending_vendor['website_url'];
                    $home_address = $pending_vendor['home_address'];
                    $min_with = $pending_vendor['min_withdrawal_amount'];
                    $max_with = $pending_vendor['max_withdrawal_amount'];
                    $payout_limit = $pending_vendor['daily_payout_limit'];

                    $app_base_url = $pending_vendor['app_base_url'];
                    $apk_ordered = $pending_vendor['order_apk'];
                    $ios_ordered = $pending_vendor['order_ios'];
                    $playstore_ordered = $pending_vendor['order_playstore'];
                    $sms_bridge_ordered = $pending_vendor['order_sms_bridge'];
                    $selected_addons = $pending_vendor['selected_addons'];
                    
                    // Generate unique access hash for the order portal
                    $access_hash = bin2hex(random_bytes(32));

                    $insert_sql = "INSERT INTO sas_vendors (email_verified_at,onboarding_compliance_review,kyc_status,email, access_hash, password, firstname, lastname, phone_number, website_url, home_address, balance, status, min_withdrawal_amount, max_withdrawal_amount, daily_payout_limit, app_base_url, apk_ordered, ios_ordered, playstore_ordered, sms_bridge_ordered, selected_addons)
                                   VALUES (NOW(),?,?,?, ?, ?, ?, ?, ?, ?, ?, '0.00', '1', ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $onboarding_proof=json_encode(['review'=>json_decode($pending_vendor['compliance_review']??'',true),'business_name'=>$pending_vendor['business_name']??'','business_registration_no'=>$pending_vendor['business_registration_no']??'','pending_id'=>(int)$pending_id],JSON_THROW_ON_ERROR);
                    $onboarding_kyc=bc_vreg_policy()['kyc']?'2':'0';
                    $insert_stmt = mysqli_prepare($connection_server, $insert_sql);
                    if (!$insert_stmt) {
                        error_log("[VendorReg] Could not prepare vendor insert: " . mysqli_error($connection_server));
                        $_SESSION["page_alert"] = "An error occurred. Please try again.";
                        header("Location: VendorRegistrations.php");
                        exit;
                    }
                    mysqli_stmt_bind_param(
                        $insert_stmt,
                        "sssssssssssssssssss",
                        $onboarding_proof,$onboarding_kyc,$email, $access_hash, $password, $firstname, $lastname, $phone_number, $website_url, $home_address,
                        $min_with, $max_with, $payout_limit, $app_base_url, $apk_ordered, $ios_ordered, $playstore_ordered,
                        $sms_bridge_ordered, $selected_addons
                    );

                        if(mysqli_stmt_execute($insert_stmt)) {
                        mysqli_stmt_close($insert_stmt);
                        $new_vendor_id = mysqli_insert_id($connection_server);
                        $domain_registration_warning = '';
                        $addon_domain_warning = '';

                        // Vendor registration uses WHMP exclusively. A failed or unconfigured
                        // provider registration is reported for follow-up; no alternate registrar is called.
                        if ((float)$pending_vendor['domain_registration_fee'] > 0 && !empty($pending_vendor['app_base_url'])) {
                            if (whmp_is_configured()) {
                                $whmp_result = whmp_register_domain($pending_vendor['app_base_url']);
                                if (($whmp_result['status'] ?? '') === 'success') {
                                    error_log('[WHMP] Domain registered on approval: ' . $pending_vendor['app_base_url']);
                                } else {
                                    $domain_registration_warning = 'WHMP could not register ' . $pending_vendor['app_base_url'] . ': ' . ($whmp_result['message'] ?? 'Unknown error');
                                    error_log('[WHMP] ' . $domain_registration_warning);
                                }
                            } else {
                                $domain_registration_warning = 'WHMP is not configured, so ' . $pending_vendor['app_base_url'] . ' was not registered.';
                                error_log('[WHMP] ' . $domain_registration_warning);
                            }
                        }

                        // 3. Set subscription dates
                        $start_date = date("Y-m-d");
                        $expiry_date = date('Y-m-d', strtotime("+$duration_days days"));

                        // 4. Update vendor with subscription info
                        $update_sql = "UPDATE sas_vendors SET start_date='$start_date', expiry_date='$expiry_date', current_billing_id='$package_id' WHERE id='$new_vendor_id'";
                        if (!mysqli_query($connection_server, $update_sql)) { error_log("[VendorReg] Error updating subscription: " . mysqli_error($connection_server)); $_SESSION["page_alert"] = "An error occurred. Please try again."; header("Location: VendorRegistrations.php"); exit; }

                        // 5. Log this initial subscription to the history table
                        $price = $package['price'];
                        $purchase_date = date('Y-m-d H:i:s');
                        $log_sql = "INSERT INTO sas_vendor_subscriptions (vendor_id, package_id, purchase_date, expiry_date, amount_paid) VALUES ('$new_vendor_id', '$package_id', '$purchase_date', '$expiry_date', '$price')";
                        if (!mysqli_query($connection_server, $log_sql)) { error_log("[VendorReg] Error logging subscription: " . mysqli_error($connection_server)); }

                        // 6. Delete from pending vendors
                        if (!mysqli_query($connection_server, "DELETE FROM sas_pending_vendors WHERE id='$pending_id'")) { error_log("[VendorReg] Error deleting pending vendor: " . mysqli_error($connection_server)); }

                        // 6b. Add the platform's actual host (app_base_url), falling back to the
                        // legacy website_url only for older pending-vendor records.
                        include_once(__DIR__ . "/../func/cpanel-func.php");
                        $addon_domain = !empty($app_base_url) ? $app_base_url : $website_url;
                        $cpanel_add_result = cpanel_add_addon_domain($addon_domain);
                        if (empty($cpanel_add_result['success'])) {
                            $addon_domain_warning = 'cPanel could not add ' . $addon_domain . ': ' . ($cpanel_add_result['message'] ?? 'unknown cPanel error');
                            error_log('[VendorReg] ' . $addon_domain_warning);
                        }

                        // Fetch domain settings to include in the welcome email
                        $nameservers = '';
                        $ip_address = '';
                        $registrar_url = '';
                        $sql_fetch_settings = "SELECT * FROM sas_super_admin_options WHERE option_name IN ('domain_nameservers', 'domain_ip_address', 'domain_registrar_url')";
                        $settings_result = mysqli_query($connection_server, $sql_fetch_settings);
                        while($row = mysqli_fetch_assoc($settings_result)) {
                            if($row['option_name'] == 'domain_nameservers') {
                                $nameservers = nl2br(htmlspecialchars($row['option_value']));
                            }
                            if($row['option_name'] == 'domain_ip_address') {
                                $ip_address = htmlspecialchars($row['option_value']);
                            }
                            if($row['option_name'] == 'domain_registrar_url') {
                                $registrar_url = htmlspecialchars($row['option_value']);
                            }
                        }

                        // Portal Link
                        $portal_link = bc_temporary_vendor_portal_url($access_hash);

                        // Send welcome email
                        $template_includes_portal_link = strpos(getSuperAdminEmailTemplate('vendor-welcome-activated', 'body'), '{portal_link}') !== false;
                        $email_placeholders = array(
                            "{firstname}" => $firstname,
                            "{lastname}" => $lastname,
                            "{expiry_date}" => date('F j, Y', strtotime($expiry_date)),
                            "{domain_nameservers}" => $nameservers,
                            "{domain_ip_address}" => $ip_address,
                            "{domain_registrar_url}" => $registrar_url,
                            "{portal_link}" => $portal_link !== ''
                                ? '<a href="' . htmlspecialchars($portal_link, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($portal_link, ENT_QUOTES, 'UTF-8') . '</a>'
                                : 'Temporary vendor access is not configured yet. Please contact the super admin for assistance.'
                        );
                        $email_subject = getSuperAdminEmailTemplate('vendor-welcome-activated', 'subject');
                        $email_body = getSuperAdminEmailTemplate('vendor-welcome-activated', 'body');
                        foreach($email_placeholders as $key => $val) {
                            $email_subject = str_replace($key, $val, $email_subject);
                            $email_body = str_replace($key, $val, $email_body);
                        }

                        // Append instructions for the temporary URL
                        $expiry_days = max(1, min(30, (int)getSuperAdminOption('tmp_url_expiry_days', '3')));
                        $tmp_instructions = $portal_link !== '' ? "
                        <br><br>
                        <strong>Temporary Dashboard Access:</strong><br>
                        While your domain name (<strong>{$website_url}</strong>) is propagating, you can access your temporary admin dashboard through your Portal Link above. This temporary access will be valid for <strong>{$expiry_days} days</strong>.
                        " : "
                        <br><br><strong>Temporary Dashboard Access:</strong><br>
                        Temporary vendor access has not been configured yet. Please contact the super admin for help.
                        ";
                        if ($portal_link !== '' && !$template_includes_portal_link && strpos($email_body, 'Portal Link') === false) {
                           // if {portal_link} was missing from the template, ensure they get it
                           $email_body .= "<br><br><strong>Portal Link:</strong> <a href=\"{$portal_link}\">{$portal_link}</a>";
                        }
                        $email_body .= $tmp_instructions;
                        $domain_setup_warnings = array_filter([$domain_registration_warning, $addon_domain_warning]);
                        if (!empty($domain_setup_warnings)) {
                            $email_body .= '<br><br><strong>Domain setup needs attention:</strong> ' . htmlspecialchars(implode(' ', $domain_setup_warnings), ENT_QUOTES, 'UTF-8') . ' Please resolve this before relying on the requested custom domain.';
                        }

                        sendVendorEmail($email, $email_subject, $email_body);

                        if (!empty($domain_setup_warnings)) {
                            $_SESSION['page_alert'] = "Vendor account activated, but domain setup needs attention: " . implode(' ', $domain_setup_warnings);
                        } elseif ((float)$pending_vendor['domain_registration_fee'] > 0 && !empty($pending_vendor['app_base_url'])) {
                            $_SESSION['page_alert'] = "Vendor approved and account activated. WHMP registration and cPanel addon-domain setup completed successfully.";
                        } else {
                            $_SESSION['page_alert'] = "Vendor approved and account activated. cPanel addon-domain setup completed successfully.";
                        }
                    } else {
                        $_SESSION['page_alert'] = "Error approving vendor: " . mysqli_error($connection_server);
                    }
                } else {
                    $_SESSION['page_alert'] = "Cannot approve vendor. Payment has not been confirmed.";
                }

            } elseif($action == 'decline') {
                $email = $pending_vendor['email'];
                $firstname = $pending_vendor['firstname'];
                $lastname = $pending_vendor['lastname'];

                if (!mysqli_query($connection_server, "DELETE FROM sas_pending_vendors WHERE id='$pending_id'")) { error_log("[VendorReg] Error declining vendor: " . mysqli_error($connection_server)); $_SESSION["page_alert"] = "An error occurred. Please try again."; header("Location: VendorRegistrations.php"); exit; }

                // Send rejection email
                $email_placeholders = array(
                    "{firstname}" => $firstname,
                    "{lastname}" => $lastname
                );
                $email_subject = getSuperAdminEmailTemplate('vendor-rejection', 'subject');
                $email_body = getSuperAdminEmailTemplate('vendor-rejection', 'body');
                foreach($email_placeholders as $key => $val) {
                    $email_subject = str_replace($key, $val, $email_subject);
                    $email_body = str_replace($key, $val, $email_body);
                }
                sendVendorEmail($email, $email_subject, $email_body);

                $_SESSION['page_alert'] = "Vendor registration declined.";
            }
        } else {
            $_SESSION['page_alert'] = "Invalid registration ID.";
        }
        header("Location: VendorRegistrations.php");
        exit();
    }
?>
<?php include_once(__DIR__ . "/../func/bc-plate-admin.php");
bc_adm_head("Pending Vendor Registrations", array("area" => "spadmin"));
?>
<?php bc_adm_sidebar(array("area" => "spadmin")); ?>
<?php bc_adm_topbar(array("area" => "spadmin", "title" => "Pending Registrations", "crumb" => "Home / Pending Registrations")); ?>

    <section class="section dashboard">
        <div class="row g-4">
            <div class="col-12">
                <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
                    <div class="card-header bg-white py-4 border-0">
                        <h5 class="fw-bold mb-0 text-primary">Pending Vendor Approvals</h5>
                        <p class="text-muted small mb-0">Review and authorize new vendor registration requests</p><a href="VendorRegistrationSettings.php">Vendor registration ON/OFF &amp; security</a>
                    </div>
                    <div class="card-body p-0">
                        <?php if(isset($_SESSION['page_alert'])): ?>
                            <div class="px-4 pt-3">
                                <div class="alert alert-info alert-dismissible fade show rounded-3 border-0 shadow-sm" role="alert">
                                    <i class="bi bi-info-circle me-2"></i><?php echo htmlspecialchars($_SESSION['page_alert'], ENT_QUOTES, 'UTF-8'); unset($_SESSION['page_alert']); ?>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="bg-light">
                                    <tr class="small text-uppercase text-muted">
                                        <th class="ps-4">#</th>
                                        <th>Vendor Identity</th>
                                        <th>Subscription</th>
                                        <th>Order Summary</th>
                                        <th>Payment Verification</th>
                                        <th>Date</th>
                                        <th class="text-end pe-4">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                        $sql = "SELECT pv.*, bp.name as package_name
                                                FROM sas_pending_vendors pv
                                                JOIN sas_billing_packages bp ON pv.billing_package_id = bp.id
                                                ORDER BY pv.reg_date DESC";
                                        $result = mysqli_query($connection_server, $sql);
                                        if (!$result) { error_log("[VendorReg] Error fetching registrations: " . mysqli_error($connection_server)); }
                                        $count = 1;
                                        if(mysqli_num_rows($result) > 0):
                                            while($row = mysqli_fetch_assoc($result)):
                                    ?>
                                    <tr>
                                        <td class="ps-4 text-muted"><?php echo $count++; ?></td>
                                        <td>
                                            <div class="fw-bold text-dark"><?php echo htmlspecialchars($row['firstname'] . ' ' . $row['lastname']); ?></div>
                                            <div class="small text-primary"><i class="bi bi-link-45deg"></i> <?php echo htmlspecialchars($row['website_url']); ?></div>
                                            <div class="small text-muted mt-1"><?php echo htmlspecialchars($row['email']); ?> | <?php echo htmlspecialchars($row['phone_number']); ?></div>
                                        </td>
                                        <td>
                                            <span class="badge bg-primary bg-opacity-10 text-dark-primary rounded-pill px-3"><?php echo htmlspecialchars($row['package_name']); ?></span>
                                        </td>
                                        <td>
                                            <div class="small">
                                                <ul class="list-unstyled mb-0">
                                                    <li><i class="bi bi-check2-circle text-success me-1"></i> <?php echo htmlspecialchars($row['package_name']); ?></li>
                                                    <?php 
                                                        // Legacy checks
                                                        if($row['order_apk']): ?><li><i class="bi bi-plus-circle text-primary me-1"></i> Android APK</li><?php endif; 
                                                        if($row['order_ios']): ?><li><i class="bi bi-plus-circle text-primary me-1"></i> iOS App</li><?php endif; 
                                                        if($row['order_playstore']): ?><li><i class="bi bi-plus-circle text-primary me-1"></i> Playstore Listing</li><?php endif; 
                                                        if($row['order_sms_bridge']): ?><li><i class="bi bi-plus-circle text-primary me-1"></i> PrintHub APP</li><?php endif;
                                                        
                                                        // Dynamic Addons lookup
                                                        if(!empty($row['selected_addons'])) {
                                                            $addon_ids = explode(',', $row['selected_addons']);
                                                            $addon_ids_clean = array_map('intval', $addon_ids);
                                                            $addon_ids_str = implode(',', $addon_ids_clean);
                                                            $addons_res = mysqli_query($connection_server, "SELECT name FROM sas_billing_addons WHERE id IN ($addon_ids_str)");
                                                            if($addons_res) {
                                                                while($addon_row = mysqli_fetch_assoc($addons_res)) {
                                                                    echo "<li><i class='bi bi-plus-circle text-primary me-1'></i>".htmlspecialchars($addon_row['name'])."</li>";
                                                                }
                                                            }
                                                        }

                                                        if($row['domain_registration_fee'] > 0): ?><li><i class="bi bi-plus-circle text-primary me-1"></i> Domain (₦<?php echo number_format($row['domain_registration_fee'], 0); ?>)</li><?php endif; 
                                                    ?>
                                                </ul>
                                                <div class="mt-2 pt-2 border-top">
                                                    <strong class="text-primary">Total: ₦<?php echo number_format($row['total_amount'], 2); ?></strong>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="small"><strong>Method:</strong> <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)$row['payment_method'])), ENT_QUOTES, 'UTF-8'); ?></div>
                                            <div class="mt-1">
                                                <?php if(trim($row['payment_status']) == 'paid'): ?>
                                                    <span class="badge bg-success bg-opacity-10 text-success rounded-pill px-2">Verified Paid</span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning bg-opacity-10 text-warning rounded-pill px-2">Pending Confirmation</span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if(!empty($row['paystack_reference'])): ?>
                                                <div class="small text-muted mt-1"><i class="bi bi-hash"></i> <?php echo htmlspecialchars($row['paystack_reference']); ?></div>
                                            <?php endif; ?>
                                            <?php if(!empty($row['payment_proof_path'])): ?>
                                                <div class="mt-1"><a href="/<?php echo htmlspecialchars($row['payment_proof_path']); ?>" target="_blank" class="btn btn-link btn-sm p-0 fw-bold"><i class="bi bi-image me-1"></i>View Proof</a></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small text-muted"><?php echo date('M d, Y', strtotime($row['reg_date'])); ?></td>
                                        <td class="text-end pe-4">
                                            <div class="d-flex flex-column gap-1 align-items-end">
                                                <span class="small"><?php echo empty($row['email_verified_at'])?'Email: NOT VERIFIED':'Email ownership verified'; ?></span>
                                                <span class="small"><?php echo htmlspecialchars(($row['business_name']??'').' / '.($row['business_registration_no']??''),ENT_QUOTES,'UTF-8'); ?></span>
                                                <details class="text-start"><summary>KYC / KYB review</summary><form method="post" class="border p-3">
                                                <?php echo bc_csrf_field(); ?><input type="hidden" name="vr_action" value="review_compliance"><input type="hidden" name="vr_id" value="<?php echo (int)$row['id']; ?>">
                                                <input type="hidden" name="review_snapshot" value="<?php try{echo bc_vreg_compliance_digest($row,bc_vreg_policy());}catch(Throwable $e){echo 'unavailable';} ?>">
                                                <p>Complete the platform-required checks using the approved secure verification process first. Record case references only, not raw ID numbers or documents. A payment receipt is not compliance evidence.</p>
                                                <label>KYC verification case reference<input class="form-control" name="kyc_case_reference" maxlength="190"></label><label>KYB verification case reference<input class="form-control" name="kyb_case_reference" maxlength="190"></label>
                                                <label><input type="checkbox" name="compliance_confirmed" value="1" required> I reviewed the applicant identity and all currently required evidence and approved the required checks.</label>
                                                <label>Security PIN<input name="sec_pin" type="password" class="form-control" required autocomplete="off"></label><label>Authenticator / email code<input name="sec_factor" class="form-control" inputmode="numeric" pattern="[0-9]{6}"></label><button name="sec33_send_code" value="1" class="btn btn-link" type="submit" formnovalidate>Email me a code</button>
                                                <button class="btn btn-outline-primary" type="submit">Record completed compliance review</button></form></details>

                                                <?php if(($row['payment_method'] == 'bank_deposit' || $row['payment_method'] == 'paystack') && trim($row['payment_status']) != 'paid'): ?>
                                                    <button type="button" class="btn btn-info btn-sm rounded-pill px-3 fw-bold shadow-sm" onclick="sec33VrAction('mark_paid', <?php echo (int)$row['id']; ?>, 'Verify payment?', 'Confirm receipt of manual payment for this vendor. This needs your PIN + authenticator code and is audited.', 'Verify Payment')">Verify Payment</button>
                                                <?php endif; ?>
                                                
                                                <div class="btn-group btn-group-sm mt-1">
                                                    <button type="button" class="btn btn-outline-primary rounded-start-pill px-3 send-invoice" data-id="<?php echo $row['id']; ?>" title="Send Invoice Email">
                                                        <i class="bi bi-envelope-paper"></i> Invoice
                                                    </button>
                                                    <a href="EditVendorOrder.php?id=<?php echo $row['id']; ?>" class="btn btn-outline-secondary rounded-end-pill px-3" title="Edit Order Details">
                                                        <i class="bi bi-pencil-square"></i> Edit
                                                    </a>
                                                </div>

                                                <div class="btn-group btn-group-sm mt-1">
                                                    <button type="button" class="btn btn-primary <?php if(trim($row['payment_status']) != 'paid') echo 'disabled'; ?> rounded-start-pill px-3 fw-bold" onclick="sec33VrAction('approve', <?php echo (int)$row['id']; ?>, 'Approve new vendor?', 'Approve and activate this vendor account? This needs your PIN + authenticator code and is audited.', 'Approve Vendor')">Approve</button>
                                                    <button type="button" class="btn btn-outline-danger rounded-end-pill px-3" onclick="sec33VrAction('decline', <?php echo (int)$row['id']; ?>, 'Decline registration?', 'Reject and delete this registration? This needs your Security PIN and is audited.', 'Decline')">Decline</button>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endwhile; else: ?>
                                    <tr><td colspan="6" class="text-center py-5 text-muted">No pending registrations at the moment</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    

    <script>
        document.querySelectorAll('.send-invoice').forEach(button => {
            button.addEventListener('click', function() {
                const id = this.getAttribute('data-id');
                const btn = this;
                const originalText = btn.innerHTML;
                
                if(!confirm('Send professional invoice to this vendor?')) return;

                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Sending...';

                fetch('ajax-send-invoice.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'id=' + id + '&_csrf_token=<?php echo bc_generate_csrf_token(); ?>'
                })
                .then(response => response.json())
                .then(data => {
                    if(data.status === 'success') {
                        alert('Invoice successfully sent to vendor.');
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('A system error occurred while sending the invoice.');
                })
                .finally(() => {
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                });
            });
        });
    </script>



    <!-- §33: step-up confirmation for vendor registration actions -->
    <div class="modal fade" id="sec33VrModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <form method="post" class="modal-content rounded-4 border-0">
          <div class="modal-header border-0"><h5 class="modal-title fw-bold" id="sec33VrTitle">Confirm action</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
          <div class="modal-body">
            <?php echo function_exists('bc_csrf_field') ? bc_csrf_field() : ''; ?>
            <input type="hidden" name="vr_action" id="sec33VrAction" value="">
            <input type="hidden" name="vr_id" id="sec33VrId" value="">
            <p class="text-muted small" id="sec33VrText"></p>
            <div class="mb-3"><label class="form-label small fw-bold text-muted">SECURITY PIN</label><input type="password" name="sec_pin" class="form-control rounded-3" required autocomplete="off"></div>
            <div class="mb-1"><label class="form-label small fw-bold text-muted">AUTHENTICATOR / EMAIL CODE <span class="fw-normal">(approve &amp; verify-payment)</span></label><input name="sec_factor" class="form-control rounded-3" placeholder="6-digit code"></div>
            <button type="submit" name="sec33_send_code" class="btn btn-link btn-sm p-0" formnovalidate>Email me a code instead</button>
          </div>
          <div class="modal-footer border-0"><button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-danger rounded-pill px-4 fw-bold" id="sec33VrBtn">Confirm</button></div>
        </form>
      </div>
    </div>
    <script>
    function sec33VrAction(action, id, title, text, btn) {
        document.getElementById('sec33VrAction').value = action;
        document.getElementById('sec33VrId').value = id;
        document.getElementById('sec33VrTitle').textContent = title;
        document.getElementById('sec33VrText').textContent = text;
        document.getElementById('sec33VrBtn').textContent = btn;
        new bootstrap.Modal(document.getElementById('sec33VrModal')).show();
    }
    </script>

<?php bc_adm_foot(array("area" => "spadmin")); ?>

<script src="../asset/bc-admin-inline-otp.js" defer></script>

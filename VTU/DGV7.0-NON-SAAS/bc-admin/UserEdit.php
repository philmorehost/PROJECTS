<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
 bc_secure_session_start();
    include("../func/bc-admin-config.php");

    // Gate UserEdit behind the vendor-admin security unlock (Google Authenticator
    // or configured Action Verification Policy: Security PIN / Email OTP, 10-minute window).
    $user_edit_scope = 'bc_admin_user_edit';
    if (function_exists('bc_gauth_settings_gate')) {
        bc_gauth_settings_gate($user_edit_scope, 'sas_vendors', $get_logged_admin_details, array('gauth-unlock', 'gauth-lock', 'send_settings_code'));
    }
    $user_edit_unlocked = function_exists('bc_gauth_unlocked_until') && bc_gauth_unlocked_until($user_edit_scope) > 0;
    
    $user_id_number = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9]+/", "", trim(strip_tags((string)($_GET["userID"] ?? "")))));
    $select_user = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='".$get_logged_admin_details["id"]."' && id='$user_id_number'");
    if($select_user && mysqli_num_rows($select_user) > 0){
        $get_user_details = mysqli_fetch_array($select_user);
    }

    if($user_edit_unlocked && isset($_POST["update-profile"])){
        if (function_exists('bc_validate_csrf')) bc_validate_csrf();
        if (function_exists('bc_demo_guard_edit')) bc_demo_guard_edit($connection_server);
        $first = mysqli_real_escape_string($connection_server, trim(strip_tags(ucwords($_POST["first"]))));
    	$last = mysqli_real_escape_string($connection_server, trim(strip_tags(ucwords($_POST["last"]))));
        $other = mysqli_real_escape_string($connection_server, trim(strip_tags(ucwords($_POST["other"]))));
    	$quest = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["quest"])));
    	$answer = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["answer"]))));
    	$address = mysqli_real_escape_string($connection_server, trim(strip_tags(ucwords($_POST["address"]))));
        $email = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["email"]))));
        $country_raw = trim(strip_tags((string)($_POST["country"] ?? ($get_user_details['country'] ?? ''))));
        $country_info = ($country_raw !== '' && function_exists('bc_world_country_lookup')) ? bc_world_country_lookup($country_raw) : null;
        $country = mysqli_real_escape_string($connection_server, $country_info ? $country_info['name'] : '');
        $phone_raw = trim(strip_tags((string)($_POST["phone"] ?? '')));
        $phone_norm = function_exists('bc_normalize_world_phone')
            ? bc_normalize_world_phone($phone_raw, $country_info ? $country_info['code'] : $country_raw)
            : (is_numeric($phone_raw) && strlen($phone_raw) == 11 ? $phone_raw : '');
        $phone = mysqli_real_escape_string($connection_server, $phone_norm);
        $api_domain = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["api_domain"])));
    	//$bank_code = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["bank-code"])));
        //$account_number = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["account-number"])));
        //$bvn = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["bvn"]))));
    	//$nin = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["nin"]))));
    	
        if(!empty($first) && !empty($last) && !empty($quest) && is_numeric($quest) && !empty($answer) && !empty($address) && !empty($email) && !empty($phone_raw)){
            if(filter_var($email, FILTER_VALIDATE_EMAIL)){
                $check_user_details = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='".$get_logged_admin_details["id"]."' && id='".$user_id_number."'");
                if(mysqli_num_rows($check_user_details) == 1){
                    if((strlen($answer) >= 3) && (strlen($answer) <= 20)){
                        if($phone !== ''){
		            $has_country_col = function_exists('bc_ensure_user_country_column') && bc_ensure_user_country_column($connection_server);
		            $country_upd_sql = ($has_country_col && $country !== '') ? ", country='$country'" : "";
		            mysqli_query($connection_server, "UPDATE sas_users SET security_quest='$quest', security_answer='$answer', firstname='$first', lastname='$last', othername='$other', home_address='$address', email='$email', phone_number='$phone'{$country_upd_sql}, api_domain='$api_domain' WHERE vendor_id='".$get_logged_admin_details["id"]."' && id='".$user_id_number."'");
        	                //Profile Information Updated Successfully
    	                    $json_response_array = array("desc" => "Profile Information Updated Successfully");
	                        $json_response_encode = json_encode($json_response_array,true);
                    	}else{
                    		//Invalid phone number
                    		$json_response_array = array("desc" => "Enter a valid local or international phone number (7-15 digits)");
                    		$json_response_encode = json_encode($json_response_array,true);
                    	}
                    }else{
                        //Security Answer Must Be Between 3-20 Charaters Without Special Charaters
                        $json_response_array = array("desc" => "Security Answer Must Be Between 3-20 Charaters Without Special Charaters");
                        $json_response_encode = json_encode($json_response_array,true);
                    }
                }else{
                    if(mysqli_num_rows($check_user_details) == 0){
                        //User Not Exists
                        $json_response_array = array("desc" => "User Not Exists");
                        $json_response_encode = json_encode($json_response_array,true);
                    }else{
                        if(mysqli_num_rows($check_user_details) > 1){
                            //Duplicated Details, Contact Admin
                            $json_response_array = array("desc" => "Duplicated Details, Contact Admin");
                            $json_response_encode = json_encode($json_response_array,true);
                        }
                    }
                }
            }else{
                //Invalid Email
                $json_response_array = array("desc" => "Invalid Email");
                $json_response_encode = json_encode($json_response_array,true);
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
                    if(empty($quest)){
                        //Security Question Field Empty
                        $json_response_array = array("desc" => "Security Question Field Empty");
                        $json_response_encode = json_encode($json_response_array,true);
                    }else{
                        if(!is_numeric($quest)){
                            //Security Question Cannot Be String
                            $json_response_array = array("desc" => "Security Question Cannot Be String");
                            $json_response_encode = json_encode($json_response_array,true);
                        }else{
                            if(empty($answer)){
                                //Security Answer Field Empty
                                $json_response_array = array("desc" => "Security Answer Field Empty");
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
        exit;
    }
    
    if($user_edit_unlocked && isset($_POST["reset-security-pin"])){
        if (function_exists('bc_validate_csrf')) bc_validate_csrf();
        if (function_exists('bc_demo_guard_edit')) bc_demo_guard_edit($connection_server);
        $new_pin = trim((string)($_POST["new-pin"] ?? ""));
        $con_new_pin = trim((string)($_POST["con-new-pin"] ?? ""));

        if ($new_pin === "" || $con_new_pin === "") {
            $_SESSION["product_purchase_response"] = ($new_pin === "") ? "New Security PIN Field Empty" : "Confirm Security PIN Field Empty";
        } elseif (!preg_match('/\A[0-9]{4}\z/D', $new_pin)) {
            $_SESSION["product_purchase_response"] = "Security PIN must be exactly 4 digits";
        } elseif (in_array($new_pin, array('0000','1111','2222','3333','4444','5555','6666','7777','8888','9999','1234','4321'), true)) {
            $_SESSION["product_purchase_response"] = "That PIN is too easy to guess. Please choose another.";
        } elseif (!hash_equals($new_pin, $con_new_pin)) {
            $_SESSION["product_purchase_response"] = "New & Confirm Security PIN Not Match";
        } else {
            $vendor_id = (int)$get_logged_admin_details["id"];
            $check_user_details = mysqli_query($connection_server, "SELECT id, username, email FROM sas_users WHERE vendor_id='$vendor_id' && id='".$user_id_number."' LIMIT 1");
            if ($check_user_details && mysqli_num_rows($check_user_details) == 1) {
                $target_user = mysqli_fetch_assoc($check_user_details);
                $new_pin_hash = password_hash($new_pin, PASSWORD_DEFAULT);
                $new_pin_int = (int)$new_pin;
                $saved = function_exists('bc_q')
                    ? bc_q("UPDATE sas_users SET security_pin=?, transaction_pin=?, failed_pin_count=0, last_failed_pin=NULL WHERE vendor_id=? AND id=?", array($new_pin_hash, $new_pin_int, $vendor_id, (int)$user_id_number))
                    : mysqli_query($connection_server, "UPDATE sas_users SET security_pin='".mysqli_real_escape_string($connection_server, $new_pin_hash)."', transaction_pin='$new_pin_int', failed_pin_count=0, last_failed_pin=NULL WHERE vendor_id='$vendor_id' && id='".$user_id_number."'");
                if ($saved) {
                    if (function_exists('bc_web_session_revoke_actor')) {
                        bc_web_session_revoke_actor('user', $vendor_id, (int)$user_id_number, 'admin_pin_reset');
                    }
                    if (function_exists('bc_sec_audit')) {
                        bc_sec_audit($vendor_id, 'admin', (string)($get_logged_admin_details['email'] ?? ''), 'user_security_pin_reset', 'user#' . (int)$user_id_number, 'Vendor admin reset user security PIN for ' . (string)($target_user['username'] ?? $target_user['email'] ?? ''));
                    }
                    $_SESSION["product_purchase_response"] = "User Security PIN Updated Successfully";
                } else {
                    $_SESSION["product_purchase_response"] = "Could not update User Security PIN. Please try again.";
                }
            } else {
                $_SESSION["product_purchase_response"] = "User Not Exists";
            }
        }
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit;
    }

    if($user_edit_unlocked && isset($_POST["clear-security-pin"])){
        if (function_exists('bc_validate_csrf')) bc_validate_csrf();
        if (function_exists('bc_demo_guard_edit')) bc_demo_guard_edit($connection_server);
        $vendor_id = (int)$get_logged_admin_details["id"];
        $check_user_details = mysqli_query($connection_server, "SELECT id, username, email FROM sas_users WHERE vendor_id='$vendor_id' && id='".$user_id_number."' LIMIT 1");
        if ($check_user_details && mysqli_num_rows($check_user_details) == 1) {
            $target_user = mysqli_fetch_assoc($check_user_details);
            $cleared = function_exists('bc_q')
                ? bc_q("UPDATE sas_users SET security_pin=NULL, transaction_pin=NULL, failed_pin_count=0, last_failed_pin=NULL WHERE vendor_id=? AND id=?", array($vendor_id, (int)$user_id_number))
                : mysqli_query($connection_server, "UPDATE sas_users SET security_pin=NULL, transaction_pin=NULL, failed_pin_count=0, last_failed_pin=NULL WHERE vendor_id='$vendor_id' && id='".$user_id_number."'");
            if ($cleared) {
                if (function_exists('bc_web_session_revoke_actor')) {
                    bc_web_session_revoke_actor('user', $vendor_id, (int)$user_id_number, 'admin_pin_cleared');
                }
                if (function_exists('bc_sec_audit')) {
                    bc_sec_audit($vendor_id, 'admin', (string)($get_logged_admin_details['email'] ?? ''), 'user_security_pin_cleared', 'user#' . (int)$user_id_number, 'Vendor admin cleared user security PIN for ' . (string)($target_user['username'] ?? $target_user['email'] ?? ''));
                }
                $_SESSION["product_purchase_response"] = "User Security PIN Cleared. User will set a new PIN on next login.";
            } else {
                $_SESSION["product_purchase_response"] = "Could not clear User Security PIN. Please try again.";
            }
        } else {
            $_SESSION["product_purchase_response"] = "User Not Exists";
        }
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit;
    }

    if($user_edit_unlocked && isset($_POST["change-password"])){
        if (function_exists('bc_validate_csrf')) bc_validate_csrf();
        if (function_exists('bc_demo_guard_edit')) bc_demo_guard_edit($connection_server);
        $new_pass = trim(strip_tags((string)($_POST["new-pass"] ?? "")));
        $con_new_pass = trim(strip_tags((string)($_POST["con-new-pass"] ?? "")));
        
        if(!empty($new_pass) && !empty($con_new_pass)){
            $vendor_id = (int)$get_logged_admin_details["id"];
            $check_user_details = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' && id='".$user_id_number."'");
            if(mysqli_num_rows($check_user_details) == 1){
                $new_pass_hash = function_exists('bc_hash_password') ? bc_hash_password($new_pass) : md5($new_pass);
                $new_pass_hash_esc = mysqli_real_escape_string($connection_server, $new_pass_hash);

                if($new_pass === $con_new_pass){
                    mysqli_query($connection_server, "UPDATE sas_users SET password='$new_pass_hash_esc' WHERE vendor_id='$vendor_id' && id='".$user_id_number."'");
                    if (function_exists('bc_web_session_revoke_actor')) {
                        bc_web_session_revoke_actor('user', $vendor_id, (int)$user_id_number, 'admin_password_reset');
                    }
                    if (function_exists('bc_sec_audit')) {
                        bc_sec_audit($vendor_id, 'admin', (string)($get_logged_admin_details['email'] ?? ''), 'user_password_reset', 'user#' . (int)$user_id_number, 'Vendor admin reset user password');
                    }
                    //Account Password Updated Successfully
                    $json_response_array = array("desc" => "Account Password Updated Successfully");
                    $json_response_encode = json_encode($json_response_array,true);
                }else{
                    //New & Confirm Password Not Match
                    $json_response_array = array("desc" => "New & Confirm Password Not Match");
                    $json_response_encode = json_encode($json_response_array,true);
                }
            }else{
                if(mysqli_num_rows($check_user_details) == 0){
                    //User Not Exists
                    $json_response_array = array("desc" => "User Not Exists");
                    $json_response_encode = json_encode($json_response_array,true);
                }else{
                    if(mysqli_num_rows($check_user_details) > 1){
                    //Duplicated Details, Contact Admin
                        $json_response_array = array("desc" => "Duplicated Details, Contact Admin");
                        $json_response_encode = json_encode($json_response_array,true);
                    }
                }
            }
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
    
        $json_response_decode = json_decode($json_response_encode,true);
        $_SESSION["product_purchase_response"] = $json_response_decode["desc"];
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit;
    }    
?>
<?php include_once(__DIR__ . "/../func/bc-plate-admin.php");
bc_adm_head("Edit User | " . ($get_all_super_admin_site_details["site_title"]), array("area" => "admin"));
?>
<link href="../assets-2/vendor/quill/quill.snow.css" rel="stylesheet">
<link href="../assets-2/vendor/quill/quill.bubble.css" rel="stylesheet">
<?php bc_adm_sidebar(array("area" => "admin")); ?>
<?php bc_adm_topbar(array("area" => "admin", "title" => "EDIT USER", "crumb" => "Home / User Edit")); ?><!-- End Page Title -->

    <section class="section dashboard">
      <div class="row">
        <div class="col-12">
    <?php
        echo function_exists('bc_gauth_settings_banner') ? bc_gauth_settings_banner($user_edit_scope, $get_logged_admin_details) : '';
        $user_edit_channels = array('google' => true, 'email' => true);
        if (function_exists('bc_action_channels')) {
            try { $user_edit_channels = bc_action_channels('sas_vendors', $get_logged_admin_details); } catch (Throwable $e) { $user_edit_channels = array('google' => true, 'email' => true); }
        }
    ?>
        </div>
    <?php if(!$user_edit_unlocked){ ?>
        <div class="col-12">
            <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4">
                <div class="card-body text-center py-5 px-4">
                    <div class="bg-warning bg-opacity-10 rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 76px; height: 76px;">
                        <i class="bi bi-shield-lock-fill text-warning fs-1"></i>
                    </div>
                    <h4 class="fw-bold mb-2">User Editor Locked</h4>
                    <p class="text-muted mx-auto mb-4" style="max-width: 560px;">
                        Complete the security verification above to unlock user profile edits, password resets, and security PIN resets for 10 minutes.
                    </p>
                    <div class="d-flex flex-wrap justify-content-center gap-2">
                        <?php if(!empty($user_edit_channels['google']) && function_exists('bc_gauth_enrolled') && !bc_gauth_enrolled($get_logged_admin_details)): ?>
                            <a href="AccountSettings.php#tab-security" class="btn btn-primary rounded-pill px-4 fw-bold">
                                <i class="bi bi-shield-check me-2"></i>Enrol Google Authenticator in Account Settings
                            </a>
                        <?php else: ?>
                            <a href="<?php echo htmlspecialchars((string)($_SERVER['REQUEST_URI'] ?? 'Users.php'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-primary rounded-pill px-4 fw-bold">
                                <i class="bi bi-arrow-clockwise me-2"></i>Refresh After Unlocking
                            </a>
                        <?php endif; ?>
                        <a href="Users.php" class="btn btn-light rounded-pill px-4 fw-bold">Back to Users</a>
                    </div>
                </div>
            </div>
        </div>
    <?php }elseif(!empty($get_user_details['id'])){ ?>
        <?php if(empty($user_edit_channels['google']) && function_exists('bc_csrf_field')): ?>
            <div class="col-12 d-flex justify-content-end mb-3">
                <form method="post" class="m-0">
                    <?php echo bc_csrf_field(); ?>
                    <button type="submit" name="gauth-lock" value="1" class="btn btn-sm btn-outline-dark rounded-pill px-3">
                        <i class="bi bi-lock-fill me-1"></i>Lock User Editor Now
                    </button>
                </form>
            </div>
        <?php endif; ?>
        <div class="col-lg-8">
            <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4">
                <div class="card-header bg-white py-4 border-0">
                    <div class="d-flex align-items-center">
                        <div class="bg-primary bg-opacity-10 rounded-circle p-3 me-3">
                            <i class="bi bi-person-bounding-box text-dark-primary fs-3"></i>
                        </div>
                        <div>
                            <h5 class="fw-bold mb-0">Edit User Account</h5>
                            <p class="text-muted small mb-0">Managing <b>@<?php echo $get_user_details['username']; ?></b> (ID: <?php echo $get_user_details['id']; ?>)</p>
                        </div>
                    </div>
                </div>
                <div class="card-body p-4">
                    <form method="post">
                        <?php echo function_exists('bc_csrf_field') ? bc_csrf_field() : ''; ?>
                        <div class="row g-3 mb-4">
                            <div class="col-12">
                                <h6 class="fw-bold text-primary text-uppercase small border-bottom pb-2 mb-3">Personal Information</h6>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-bold">First Name</label>
                                <input name="first" type="text" value="<?php echo $get_user_details['firstname']; ?>" class="form-control rounded-3" pattern="[a-zA-Z ]{3,}" required/>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-bold">Last Name</label>
                                <input name="last" type="text" value="<?php echo $get_user_details['lastname']; ?>" class="form-control rounded-3" pattern="[a-zA-Z ]{3,}" required/>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-bold">Other Name</label>
                                <input name="other" type="text" value="<?php echo $get_user_details['othername']; ?>" class="form-control rounded-3" />
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-bold">Residential Address</label>
                                <input name="address" type="text" value="<?php echo $get_user_details['home_address']; ?>" class="form-control rounded-3" required/>
                            </div>
                        </div>

                        <div class="row g-3 mb-4">
                            <div class="col-12">
                                <h6 class="fw-bold text-primary text-uppercase small border-bottom pb-2 mb-3">Contact & Technical</h6>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Email Address</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0 rounded-start-3"><i class="bi bi-envelope"></i></span>
                                    <input name="email" type="email" value="<?php echo $get_user_details['email']; ?>" class="form-control rounded-end-3" required/>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Phone Number</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0 rounded-start-3"><i class="bi bi-phone"></i></span>
                                    <input name="phone" type="tel" value="<?php echo htmlspecialchars((string)$get_user_details['phone_number'], ENT_QUOTES, 'UTF-8'); ?>" class="form-control rounded-end-3" required/>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Country</label>
                                <select name="country" class="form-select rounded-3">
                                    <option value="">Select Country</option>
                                    <?php
                                        if (function_exists('bc_world_countries')) {
                                            $u_country_info = bc_world_country_lookup((string)($get_user_details['country'] ?? ''));
                                            $u_country_code = $u_country_info ? $u_country_info['code'] : '';
                                            foreach (bc_world_countries() as $c) {
                                                $sel = ($u_country_code !== '' && $c['code'] === $u_country_code) ? 'selected' : '';
                                                echo '<option value="'.htmlspecialchars($c['code'], ENT_QUOTES, 'UTF-8').'" '.$sel.'>'.htmlspecialchars($c['name'].' (+'.$c['dial'].')', ENT_QUOTES, 'UTF-8').'</option>';
                                            }
                                        }
                                    ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-bold">API Whitelist Domain</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0 rounded-start-3"><i class="bi bi-globe"></i></span>
                                    <input name="api_domain" type="text" value="<?php echo $get_user_details['api_domain']; ?>" class="form-control rounded-end-3" placeholder="e.g. mysite.com (no https://)"/>
                                </div>
                                <small class="text-muted">Used to authorize requests if this user uses your API.</small>
                            </div>
                        </div>

                        <div class="row g-3 mb-5">
                            <div class="col-12">
                                <h6 class="fw-bold text-primary text-uppercase small border-bottom pb-2 mb-3">Security Configuration</h6>
                            </div>
                            <div class="col-md-7">
                                <label class="form-label small fw-bold">Security Question</label>
                                <select name="quest" class="form-select rounded-3" required>
                                    <option value="">Choose Question...</option>
                                    <?php
                                        $get_security_quest_details = mysqli_query($connection_server, "SELECT * FROM sas_security_quests");
                                        while($security_details = mysqli_fetch_assoc($get_security_quest_details)){
                                            $selected = ($security_details["id"] == $get_user_details['security_quest']) ? 'selected' : '';
                                            echo '<option value="'.$security_details["id"].'" '.$selected.'>'.$security_details["quest"].'</option>';
                                        }
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label small fw-bold">Security Answer</label>
                                <input name="answer" type="text" value="<?php echo $get_user_details['security_answer']; ?>" class="form-control rounded-3" pattern="[0-9a-zA-Z ]{3,20}" required/>
                            </div>
                        </div>

                        <button name="update-profile" type="submit" class="btn btn-primary btn-lg w-100 rounded-pill fw-bold shadow-sm">
                            <i class="bi bi-save2 me-2"></i>SAVE USER CHANGES
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <?php
                $user_has_pin = !empty($get_user_details['security_pin']) || (!empty($get_user_details['transaction_pin']) && (int)$get_user_details['transaction_pin'] !== 0);
            ?>
            <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4">
                <div class="card-header bg-warning bg-opacity-10 py-3 border-0 d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <h6 class="fw-bold mb-0 text-warning"><i class="bi bi-key-fill me-2"></i>Reset User Security PIN</h6>
                    <?php if($user_has_pin): ?>
                        <span class="badge bg-success bg-opacity-10 text-success rounded-pill px-3 py-1">PIN Configured</span>
                    <?php else: ?>
                        <span class="badge bg-secondary bg-opacity-10 text-secondary rounded-pill px-3 py-1">Not Set</span>
                    <?php endif; ?>
                </div>
                <div class="card-body p-4">
                    <form method="post" class="mb-3">
                        <?php echo function_exists('bc_csrf_field') ? bc_csrf_field() : ''; ?>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">New 4-Digit PIN</label>
                            <input name="new-pin" type="password" maxlength="4" pattern="[0-9]{4}" inputmode="numeric" autocomplete="new-password" placeholder="4 digits" class="form-control rounded-3" required/>
                        </div>
                        <div class="mb-4">
                            <label class="form-label small fw-bold">Confirm Security PIN</label>
                            <input name="con-new-pin" type="password" maxlength="4" pattern="[0-9]{4}" inputmode="numeric" autocomplete="new-password" placeholder="4 digits" class="form-control rounded-3" required/>
                        </div>
                        <button name="reset-security-pin" type="submit" class="btn btn-warning text-dark w-100 rounded-pill fw-bold">
                            Update Security PIN
                        </button>
                    </form>
                    <hr class="my-3">
                    <form method="post" class="m-0">
                        <?php echo function_exists('bc_csrf_field') ? bc_csrf_field() : ''; ?>
                        <p class="small text-muted mb-2">Or clear the existing PIN so the user sets a fresh 4-digit PIN on next login.</p>
                        <button name="clear-security-pin" type="submit" class="btn btn-outline-warning text-dark w-100 rounded-pill fw-bold" onclick="return confirm('Clear this user\'s Security PIN? They will be prompted to set a new 4-digit PIN on next login.');">
                            Clear Security PIN
                        </button>
                    </form>
                </div>
            </div>

            <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4">
                <div class="card-header bg-danger bg-opacity-10 py-3 border-0">
                    <h6 class="fw-bold mb-0 text-danger"><i class="bi bi-shield-lock me-2"></i>Reset User Password</h6>
                </div>
                <div class="card-body p-4">
                    <form method="post">
                        <?php echo function_exists('bc_csrf_field') ? bc_csrf_field() : ''; ?>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">New Password</label>
                            <input name="new-pass" type="password" class="form-control rounded-3" required/>
                        </div>
                        <div class="mb-4">
                            <label class="form-label small fw-bold">Confirm Password</label>
                            <input name="con-new-pass" type="password" class="form-control rounded-3" required/>
                        </div>
                        <button name="change-password" type="submit" class="btn btn-outline-danger w-100 rounded-pill fw-bold">
                            Update Password
                        </button>
                    </form>
                </div>
            </div>

            <div class="card shadow-sm border-0 rounded-4 p-4 text-center">
                <div class="mb-3">
                    <img src="<?php echo $web_http_host; ?>/asset/user-icon.png" class="img-fluid rounded-circle bg-light p-3" style="width: 100px; height: 100px; object-fit: contain; filter: grayscale(1); opacity: 0.5;"/>
                </div>
                <h6 class="fw-bold"><?php echo $get_user_details['firstname'] . ' ' . $get_user_details['lastname']; ?></h6>
                <div class="badge bg-light text-dark border rounded-pill px-3 mb-3"><?php echo strtoupper(accountLevel($get_user_details['account_level'])); ?></div>
                <div class="text-muted small mb-1"><i class="bi bi-clock me-1"></i> Joined: <?php echo date('M d, Y', strtotime($get_user_details['reg_date'])); ?></div>
                <div class="text-muted small"><i class="bi bi-wallet2 me-1"></i> Balance: <b>₦<?php echo number_format($get_user_details['balance'], 2); ?></b></div>
            </div>
        </div>

    <?php }else{ ?>
      <div class="card info-card px-5 py-5">
    		<span style="user-select: auto;" class="h4">USER INFO</span><br>
    		<img src="<?php echo $web_http_host; ?>/asset/ooops.gif" class="h5 m-width-60 s-width-50" style="user-select: auto;"/><br/>
            <div style="text-align: center;" class="container">
                <span id="user-status-span" class="h5" style="user-select: auto;">Ooops</span><br/>
                <span id="user-status-span" class="h6" style="user-select: auto;">User Account Not Exists</span>
            </div><br/>
        </div>
    <?php } ?>
    </div>
  </section>
    
    



<?php bc_adm_foot(array("area" => "admin")); ?>

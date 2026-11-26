<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
 bc_secure_session_start();
    include("../func/bc-admin-config.php");
    
    if(isset($_POST["create-profile"])){
        $user = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["user"]))));
    	$first = mysqli_real_escape_string($connection_server, trim(strip_tags(ucwords($_POST["first"]))));
    	$last = mysqli_real_escape_string($connection_server, trim(strip_tags(ucwords($_POST["last"]))));
        $other = mysqli_real_escape_string($connection_server, trim(strip_tags(ucwords($_POST["other"]))));
    	$quest = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["quest"])));
    	$answer = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["answer"]))));
    	$address = mysqli_real_escape_string($connection_server, trim(strip_tags(ucwords($_POST["address"]))));
        $email = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["email"]))));
        $country_raw = trim(strip_tags((string)($_POST["country"] ?? '')));
        $country_info = ($country_raw !== '' && function_exists('bc_world_country_lookup')) ? bc_world_country_lookup($country_raw) : null;
        $country = mysqli_real_escape_string($connection_server, $country_info ? $country_info['name'] : '');
        $phone_raw = trim(strip_tags((string)($_POST["phone"] ?? '')));
        $phone_norm = function_exists('bc_normalize_world_phone')
            ? bc_normalize_world_phone($phone_raw, $country_info ? $country_info['code'] : $country_raw)
            : sanitize_phone_number($phone_raw);
        $phone = mysqli_real_escape_string($connection_server, $phone_norm);
        $pass = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["new-pass"])));
    	
        if(!empty($user) && !empty($first) && !empty($last) && !empty($quest) && is_numeric($quest) && !empty($answer) && !empty($address) && !empty($email) && !empty($phone_raw) && !empty($pass)){
            $check_user_details = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='".$get_logged_admin_details["id"]."' && username='$user'");
            
            if(mysqli_num_rows($check_user_details) == 0){
                if(!filter_var($user, FILTER_VALIDATE_EMAIL)){
                    if(filter_var($email, FILTER_VALIDATE_EMAIL)){
                        if($country_raw !== '' && $country_info === null){
                            $_SESSION["product_purchase_response"] = "Invalid country selected.";
                        }elseif($phone !== ''){
                            $md5_pass = md5($pass);
                            $api_key = bin2hex(random_bytes(25));
                            $last_login = date('Y-m-d H:i:s');
                            $has_country_col = function_exists('bc_ensure_user_country_column') && bc_ensure_user_country_column($connection_server);
                            $country_col_sql = $has_country_col ? ", country" : "";
                            $country_val_sql = $has_country_col ? ", '$country'" : "";
                            
                            $insert = mysqli_query($connection_server, "INSERT INTO sas_users (vendor_id, email, username, password, phone_number, balance, firstname, lastname, othername, security_quest, security_answer, home_address{$country_col_sql}, account_level, api_key, last_login, api_status, status) VALUES ('".$get_logged_admin_details["id"]."', '$email', '$user', '$md5_pass', '$phone', '0', '$first', '$last', '$other', '$quest', '$answer', '$address'{$country_val_sql}, '1', '$api_key', '$last_login', '2', '1')");
                            
                            if($insert){
                                // Email Beginning
                                $reg_template_encoded_text_array = array("{firstname}" => $first, "{lastname}" => $last, "{username}" => $user, "{address}" => $address, "{email}" => $email, "{phone}" => $phone);
                                $raw_reg_template_subject = getUserEmailTemplate('user-reg','subject');
                                $raw_reg_template_body = getUserEmailTemplate('user-reg','body');
                                foreach($reg_template_encoded_text_array as $array_key => $array_val){
                                    $raw_reg_template_subject = str_replace($array_key, $array_val, $raw_reg_template_subject);
                                    $raw_reg_template_body = str_replace($array_key, $array_val, $raw_reg_template_body);
                                }
                                sendVendorEmail($email, $raw_reg_template_subject, $raw_reg_template_body);

                                $_SESSION["product_purchase_response"] = "User account created successfully.";
                            } else {
                                $_SESSION["product_purchase_response"] = "Error creating account: ".mysqli_error($connection_server);
                            }
                        }else{
                            $_SESSION["product_purchase_response"] = "Enter a valid local or international phone number (7-15 digits, e.g. 08012345678 or +447911123456). Received: '" . $phone_raw . "'.";
                        }
                    }else{
                        $_SESSION["product_purchase_response"] = "Invalid email address: '" . $email . "' is not a valid email.";
                    }
                }else{
                    $_SESSION["product_purchase_response"] = "Username cannot be an email address. Use a short login name here (e.g. johndoe) and put the email in the Email Address field.";
                }
            }else{
                $_SESSION["product_purchase_response"] = "User Already Exists";
            }
        }else{
            // Name the fields that are actually missing. The previous single message - "Please fill
            // all required fields." - was shown for nine different failures (including a security
            // question that was not numeric), which made a mis-filled form look like it had simply
            // not saved at all.
            $missing_fields = array();
            if (empty($user))    $missing_fields[] = "username";
            if (empty($first))   $missing_fields[] = "first name";
            if (empty($last))    $missing_fields[] = "last name";
            if (empty($address)) $missing_fields[] = "home address";
            if (empty($email))   $missing_fields[] = "email address";
            if (empty($phone))   $missing_fields[] = "phone number";
            if (empty($pass))    $missing_fields[] = "login password";
            if (empty($answer))  $missing_fields[] = "security answer";
            if (empty($quest))   $missing_fields[] = "security question";
            if (empty($missing_fields)) {
                // Everything was filled, so the only remaining test here is is_numeric($quest).
                $missing_fields[] = "security question - choose one from the list";
            }
            $_SESSION["product_purchase_response"] = "Not saved - please check: " . implode(", ", $missing_fields) . ".";
        }
        header("Location: CreateUser.php");
        exit();
    }
        
?>
<?php include_once(__DIR__ . "/../func/bc-plate-admin.php");
bc_adm_head("Create User | " . ($get_all_super_admin_site_details["site_title"]), array("area" => "admin"));
?>
<?php bc_adm_sidebar(array("area" => "admin")); ?>
<?php bc_adm_topbar(array("area" => "admin", "title" => "CREATE NEW USER", "crumb" => "Home / Manage User / Create User")); ?>

    <section class="section dashboard">
      <div class="row justify-content-center">
        <div class="col-lg-10">
          <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
            <div class="row g-0">
                <div class="col-md-4 bg-primary p-5 text-white d-flex flex-column justify-content-center align-items-center text-center">
                    <div class="mb-4">
                        <i class="bi bi-person-plus display-1"></i>
                    </div>
                    <h3 class="fw-bold">Onboard User</h3>
                    <p class="opacity-75">Manually create a new user account and assign initial details.</p>
                    <div class="mt-4 pt-4 border-top border-white border-opacity-25 w-100">
                        <div class="small opacity-75">DEFAULT ACCOUNT LEVEL</div>
                        <div class="fw-bold">Smart User (Level 1)</div>
                    </div>
                </div>
                <div class="col-md-8 p-4 p-md-5 bg-white">
                    <form method="post" action="">
                        <h5 class="fw-bold text-dark mb-4 border-bottom pb-2">Personal Information</h5>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-muted">USERNAME</label>
                                <input name="user" type="text" placeholder="e.g. johndoe" class="form-control rounded-3" required/>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-muted">FIRST NAME</label>
                                <input name="first" type="text" placeholder="John" class="form-control rounded-3" required/>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-muted">LAST NAME</label>
                                <input name="last" type="text" placeholder="Doe" class="form-control rounded-3" required/>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-muted">OTHER NAME (Optional)</label>
                                <input name="other" type="text" placeholder="Middle Name" class="form-control rounded-3"/>
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-bold text-muted">HOME ADDRESS</label>
                                <textarea name="address" rows="2" class="form-control rounded-3" placeholder="Enter residential address" required></textarea>
                            </div>
                        </div>

                        <h5 class="fw-bold text-dark mb-4 border-bottom pb-2">Security & Credentials</h5>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-muted">SECURITY QUESTION</label>
                                <select name="quest" class="form-select rounded-3" required>
                                    <option value="" hidden>Choose Question</option>
                                    <?php
                                        $get_security_quest_details = mysqli_query($connection_server, "SELECT * FROM sas_security_quests");
                                        while($sq = mysqli_fetch_assoc($get_security_quest_details)) echo '<option value="'.$sq["id"].'">'.$sq["quest"].'</option>';
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-muted">SECURITY ANSWER</label>
                                <input name="answer" type="text" placeholder="Secret Answer" class="form-control rounded-3" required/>
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-bold text-muted">LOGIN PASSWORD</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0"><i class="bi bi-key"></i></span>
                                    <input name="new-pass" type="password" placeholder="Initial Password" class="form-control border-start-0 rounded-end-3" required/>
                                </div>
                            </div>
                        </div>

                        <h5 class="fw-bold text-dark mb-4 border-bottom pb-2">Contact Details</h5>
                        <div class="row g-3 mb-5">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-muted">EMAIL ADDRESS</label>
                                <input name="email" type="email" placeholder="user@example.com" class="form-control rounded-3" required/>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-muted">COUNTRY</label>
                                <select name="country" class="form-select rounded-3">
                                    <option value="">Select Country</option>
                                    <?php
                                        if (function_exists('bc_world_countries')) {
                                            foreach (bc_world_countries() as $c) {
                                                $sel = ($c['code'] === 'NG') ? 'selected' : '';
                                                echo '<option value="'.htmlspecialchars($c['code'], ENT_QUOTES, 'UTF-8').'" '.$sel.'>'.htmlspecialchars($c['name'].' (+'.$c['dial'].')', ENT_QUOTES, 'UTF-8').'</option>';
                                            }
                                        }
                                    ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-bold text-muted">PHONE NUMBER</label>
                                <input name="phone" type="tel" placeholder="e.g. 08012345678 or +447911123456" class="form-control rounded-3" required/>
                            </div>
                        </div>

                        <button name="create-profile" type="submit" class="btn btn-primary btn-lg w-100 rounded-4 fw-bold shadow-sm py-3">
                            <i class="bi bi-person-check me-2"></i>CREATE USER PROFILE
                        </button>
                    </form>
                </div>
            </div>
          </div>
        </div>
      </div>
    </section>
    



<?php bc_adm_foot(array("area" => "admin")); ?>

<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
 bc_secure_session_start();
    include("../func/bc-admin-config.php");
    
    $user_id_number = mysqli_real_escape_string($connection_server, preg_replace("/[^0-9]+/", "", trim(strip_tags($_GET["userID"] ?? ""))));
    $select_user = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='".$get_logged_admin_details["id"]."' && id='$user_id_number'");
    if(mysqli_num_rows($select_user) > 0){
        $get_user_details = mysqli_fetch_array($select_user);
    }

    require_once __DIR__ . '/../func/bc-admin-user-tier.php';
    if (isset($_POST['convert-personal']) || isset($_POST['send-personal-code'])) {
        if (!function_exists('bc_validate_csrf')) { http_response_code(503); exit('Verification unavailable.'); }
        bc_validate_csrf();
        $result = 'User not found for this vendor.';
        if (!empty($get_user_details['id'])) {
            if (isset($_POST['send-personal-code'])) {
                $result = bc_stepup_send_email_otp((int)$get_logged_admin_details['id'], (string)$get_logged_admin_details['email'], (string)$get_logged_admin_details['email'], 'user_personal_conversion', 'Convert Business account to Personal');
                $result = $result === '' ? 'Verification code sent to your email.' : $result;
            } else {
                $factor=(string)($_POST['conversion_factor'] ?? '');
                $error=bc_stepup_admin_verify($get_logged_admin_details, ['pin'=>(string)($_POST['conversion_pin'] ?? ''), 'totp_code'=>$factor, 'otp_code'=>$factor], 'user_personal_conversion');
                $result=$error === '' ? bc_admin_convert_user_to_personal((int)$get_logged_admin_details['id'], (int)$get_user_details['id'], (string)$get_logged_admin_details['email']) : $error;
                if ($result === true) $result='Account converted to Personal. Developer API disabled; pricing level and wallet unchanged. Personal app eligibility now applies; normal account and KYC checks still apply.';
            }
        }
        $_SESSION['product_purchase_response']=$result;
        header('Location: UserUpgrade.php?userID='.(int)$user_id_number);
        exit;
    }

    if(isset($_POST["upgrade-level"])){
        if (!function_exists('bc_validate_csrf')) { http_response_code(503); exit('Verification unavailable.'); }
        bc_validate_csrf();
    	$account_level_upgrade_array = array("smart" => 1, "agent" => 2, "api" => 3);
    	
        $upgrade_type = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["upgrade-type"])));
        
        if(!empty($upgrade_type) && in_array($upgrade_type, array_keys($account_level_upgrade_array))){
            $check_user_details = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='".$get_logged_admin_details["id"]."' && id='".$user_id_number."'");
            if(mysqli_num_rows($check_user_details) == 1){
                $upgrade_signal = false;
                $get_user_info = mysqli_fetch_array($check_user_details);
                $preserved_type = bc_fintech_account_type($get_user_info);
                if (!in_array($preserved_type, ['personal','business'], true)) {
                    $_SESSION['product_purchase_response']='Resolve the account type before changing its pricing level.';
                    header('Location: UserUpgrade.php?userID='.(int)$user_id_number); exit;
                }

                
                if($get_user_info["account_level"] == $account_level_upgrade_array[$upgrade_type]){
                    $upgrade_signal = true;
                }else{
                    if($get_user_info["account_level"] > $account_level_upgrade_array[$upgrade_type]){
                        $upgrade_signal = true;
                    }else{
                        if($get_user_info["account_level"] < $account_level_upgrade_array[$upgrade_type]){
                            $get_upgrade_price = mysqli_query($connection_server, "SELECT * FROM sas_user_upgrade_price WHERE vendor_id='".$get_logged_admin_details["id"]."' && account_type='".$account_level_upgrade_array[$upgrade_type]."'");
                            if(mysqli_num_rows($get_upgrade_price) == 1){
                                $upgrade_price = mysqli_fetch_array($get_upgrade_price);
                                if(!empty($upgrade_price["price"]) && is_numeric($upgrade_price["price"]) && ($upgrade_price["price"] > 0)){
                                    $transType = "debit";
                                    $userID = strtolower($get_user_info["username"]);
                                    $purchase_method = "WEB";
                                    $amount = $upgrade_price["price"];
                                    $discounted_amount = $amount;
                                    $type_alternative = ucwords("Account Upgrade");
                                    $reference = substr(str_shuffle("12345678901234567890"), 0, 15);
                                    $description = ucwords(accountLevel($account_level_upgrade_array[$upgrade_type])." Upgrade charges By Admin");
                                    $status = 1;
                                    
                                    $debit_other_user = chargeOtherUser($userID, $transType, ucwords(accountLevel($account_level_upgrade_array[$upgrade_type])), $type_alternative, $reference, "", $amount, $discounted_amount, $description, $purchase_method, $_SERVER["HTTP_HOST"], $status);
                                    if(in_array($debit_other_user, array("success"))){
                                        $upgrade_signal = true;
                                        $json_response_array = array("desc" => ucwords($get_user_info["username"])."`s Account Upgraded Successfully");
                                        $json_response_encode = json_encode($json_response_array,true);
                                    }
                                                                        
                                    if($debit_other_user == "failed"){
                                        $upgrade_signal = false;
                                        $json_response_array = array("desc" => "Upgrade Failed, Insufficient User Fund");
                                        $json_response_encode = json_encode($json_response_array,true);
                                    }		
                                }else{
                                    //Pricing Error, Contact Admin
                                    $json_response_array = array("desc" => "Pricing Error, Contact Admin");
                                    $json_response_encode = json_encode($json_response_array,true);
                                }
                            }else{
                                //Error: Pricing Not Available, Contact Admin
                                $json_response_array = array("desc" => "Error: Pricing Not Available, Contact Admin");
                                $json_response_encode = json_encode($json_response_array,true);
                            }
                        }
                    }
                }

                if($upgrade_signal == true){
                    mysqli_query($connection_server, "UPDATE sas_users SET account_level='".$account_level_upgrade_array[$upgrade_type]."', account_type='".$preserved_type."' WHERE vendor_id='".$get_logged_admin_details["id"]."' && id='".$user_id_number."'");
                    //Pricing Level Changed To ... Level Successfully
                    $json_response_array = array("desc" => "Pricing Level Changed To ".accountLevel($account_level_upgrade_array[$upgrade_type])." Successfully");
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
            if(empty($upgrade_type)){
                //Upgrade Field Empty
                $json_response_array = array("desc" => "Upgrade Field Empty");
                $json_response_encode = json_encode($json_response_array,true);
            }else{
                if(!in_array($upgrade_type, array_keys($account_level_upgrade_array))){
                    //Invalid Upgrade Level Code
                    $json_response_array = array("desc" => "Invalid Upgrade Level Code");
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
bc_adm_head("Upgrade User | " . ($get_all_super_admin_site_details["site_title"]), array("area" => "admin"));
?>
<?php bc_adm_sidebar(array("area" => "admin")); ?>
<?php bc_adm_topbar(array("area" => "admin", "title" => "USER UPGRADE", "crumb" => "Home / User Upgrade")); ?><!-- End Page Title -->

    <section class="section dashboard">
      <div class="row justify-content-center">
        <div class="col-lg-5 col-md-8">
            <?php if(!empty($get_user_details['id'])){ ?>
                <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
                    <div class="card-header bg-white py-4 border-0 text-center">
                        <div class="position-relative d-inline-block mb-3">
                            <div class="bg-primary bg-opacity-10 p-4 rounded-circle">
                                <i class="bi bi-arrow-up-circle text-dark-primary display-4"></i>
                            </div>
                            <span class="position-absolute bottom-0 end-0 badge rounded-pill bg-success border border-2 border-white">
                                <i class="bi bi-check"></i>
                            </span>
                        </div>
                        <h4 class="fw-bold mb-1"><?php echo strtoupper($get_user_details['username']); ?></h4>
                        <p class="small text-muted mb-0">Current Pricing Level: <span class="badge bg-light text-dark fw-bold border"><?php echo htmlspecialchars(([1=>'Smart Earner',2=>'Agent Vendor',3=>'API Vendor'][$get_user_details['account_level']] ?? 'Unknown').' (pricing level '.(int)$get_user_details['account_level'].')', ENT_QUOTES, 'UTF-8'); ?></span></p>
                    </div>
                    <div class="card-body p-4 p-md-5">
                        <form method="post" action="">
                            <?php echo bc_csrf_field(); ?>
                            <div class="mb-4">
                                <label class="form-label small fw-bold text-muted text-uppercase mb-2">Target Pricing Level</label>
                                <select name="upgrade-type" class="form-select form-select-lg bg-light border-0 py-3 rounded-3" required>
                                    <option value="" default hidden selected>Select new level...</option>
                                    <?php
                                        $account_level_upgrade_array = array(1 => "smart", 2 => "agent", 3 => "api");
                                        foreach($account_level_upgrade_array as $index => $account_levels){
                                            $get_upgrade_price = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_user_upgrade_price WHERE vendor_id='".$get_logged_admin_details["id"]."' && account_type='".$index."' LIMIT 1"));
                                            $price = toDecimal($get_upgrade_price["price"] ?? 0, 2);
                                            $selected = ($index == $get_user_details['account_level']) ? 'selected' : '';

                                            echo '<option value="'.$account_levels.'" '.$selected.'>'.([1=>'Smart Earner',2=>'Agent Vendor',3=>'API Vendor'][$index]).' (₦'.$price.')</option>';
                                        }
                                    ?>
                                </select>
                                <div class="mt-2 small text-muted text-center italic">
                                    <i class="bi bi-info-circle me-1"></i> Lower pricing levels are selectable. Pricing levels do not change Personal/Business account type.
                                </div>
                            </div>

                            <div class="mb-4">
                                 <div class="alert alert-info border-0 rounded-4 small py-3 px-4">
                                    <ul class="mb-0 ps-3">
                                        <li>Moving to a higher pricing level uses the existing upgrade fee. Downgrades have no charge or refund.</li>
                                        <li>This changes pricing only, not Personal/Business access.</li>
                                        <li>New pricing will apply immediately to user.</li>
                                    </ul>
                                 </div>
                            </div>

                            <button name="upgrade-level" type="submit" class="btn btn-primary btn-lg w-100 rounded-4 py-3 fw-bold shadow-sm">
                                <i class="bi bi-rocket-takeoff-fill me-2"></i>APPLY PRICING LEVEL
                            </button>
                        </form>
                        <hr>
                        <h5>Account type: <?php echo htmlspecialchars(ucfirst((string)bc_fintech_account_type($get_user_details)), ENT_QUOTES, 'UTF-8'); ?></h5>
                        <p class="small text-muted">Personal accounts use the app; Business accounts use the website. A pricing-level change above does not change this type. Business promotion still requires KYB approval.</p>
                        <?php if (bc_fintech_account_type($get_user_details) === 'business'): ?>
                        <form method="post">
                            <?php echo bc_csrf_field(); ?>
                            <h5>Convert Business to Personal</h5>
                            <p class="small">This disables developer API access and signs out existing app sessions. Wallet, pricing level, KYC and account blocks remain unchanged. Any pending KYB review is cancelled; historical documents are retained.</p>
                            <label for="conversion-pin" class="form-label">Security PIN</label>
                            <input id="conversion-pin" class="form-control mb-3" type="password" name="conversion_pin" required autocomplete="off">
                            <?php $conversion_totp = ($get_logged_admin_details['two_factor_type'] ?? '') === 'totp' && !empty($get_logged_admin_details['totp_secret']); ?>
                            <label for="conversion-factor" class="form-label"><?php echo $conversion_totp ? 'Google Authenticator code' : 'Email OTP'; ?></label>
                            <input id="conversion-factor" class="form-control mb-3" name="conversion_factor" required inputmode="numeric" pattern="[0-9]{6}" autocomplete="one-time-code">
                            <?php if (!$conversion_totp): ?><button type="submit" class="btn btn-outline-primary mb-3" name="send-personal-code" value="1" formnovalidate>Email me a code</button><?php endif; ?>
                            <button type="submit" class="btn btn-warning w-100" name="convert-personal" value="1">Confirm conversion to Personal</button>
                        </form>
                        <?php endif; ?>

                    </div>
                </div>
            <?php }else{ ?>
                <div class="card shadow-sm border-0 rounded-4 p-5 text-center">
                    <div class="bg-danger bg-opacity-10 p-4 rounded-circle d-inline-block mb-4 mx-auto">
                        <i class="bi bi-exclamation-octagon fs-1 text-danger"></i>
                    </div>
                    <h3 class="fw-bold">User Not Found</h3>
                    <p class="text-muted">The requested user account does not exist or has been removed from the system.</p>
                    <a href="Users.php" class="btn btn-outline-secondary rounded-pill px-4 mt-3">Back to Users</a>
                </div>
            <?php } ?>
        </div>
      </div>
    </section>
    
    



<?php bc_adm_foot(array("area" => "admin")); ?>

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
    require_once __DIR__.'/../func/bc-paid-entitlements.php';
    require_once __DIR__.'/../func/bc-paid-security.php';
    bc_paid_otp_dispatch($get_logged_admin_details,'admin',['paid_level_admin']);
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

    if(isset($_POST['upgrade-level'])){
        bc_validate_csrf();
        $error=bc_paid_verify($get_logged_admin_details,'admin','paid_level_admin',$_POST);
        $result=$error;
        if($error==='')$result=bc_paid_level_change($connection_server,(int)$get_logged_admin_details['id'],(int)$user_id_number,is_string($_POST['upgrade-type']??null)?$_POST['upgrade-type']:'',$_POST['paid_quote']??'',$_POST['expected_level']??-1);
        $_SESSION['product_purchase_response']=in_array($result,['success','already_active'],true)?'Pricing level confirmed. Personal/Business type, KYC and developer API eligibility are unchanged.':'Pricing level was not changed: '.$result.'. Refresh before retrying.';
        bc_paid_reply(in_array($result,['success','already_active'],true),$_SESSION['product_purchase_response']);
        header('Location: UserUpgrade.php?userID='.(int)$user_id_number);exit;
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
                        <form method="post" action="" data-paid-save>
                            <?php echo bc_csrf_field(); ?>
                            <input type="hidden" name="expected_level" value="<?php echo (int)$get_user_details['account_level']; ?>">
                            <input type="hidden" name="paid_quote" value="0.00">
                            <div class="mb-4">
                                <label class="form-label small fw-bold text-muted text-uppercase mb-2">Target Pricing Level</label>
                                <select onchange="this.form.elements.paid_quote.value=this.selectedOptions[0].dataset.quote" name="upgrade-type" class="form-select form-select-lg bg-light border-0 py-3 rounded-3" required>
                                    <option value="" default hidden selected>Select new level...</option>
                                    <?php
                                        $account_level_upgrade_array = array(1 => "smart", 2 => "agent", 3 => "api");
                                        foreach($account_level_upgrade_array as $index => $account_levels){
                                            $get_upgrade_price = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_user_upgrade_price WHERE vendor_id='".$get_logged_admin_details["id"]."' && account_type='".$index."' LIMIT 1"));
                                            $available=true;
                                            try{$price=($index===1 || $index<=(int)$get_user_details['account_level'])?'0.00':bc_paid_money((string)($get_upgrade_price['price']??''));}catch(Throwable $e){$price='0.00';$available=false;}
                                            $selected = ($index == $get_user_details['account_level']) ? 'selected' : '';

                                            echo '<option data-quote="'.$price.'" value="'.$account_levels.'" '.$selected.' '.(!$available?'disabled':'').'>'.([1=>'Smart Earner',2=>'Agent Vendor',3=>'API Vendor'][$index]).' (₦'.$price.')</option>';
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

                            <?php bc_paid_fields($get_logged_admin_details,'paid_level_admin'); ?>
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
    
    



<?php bc_paid_otp_script(); bc_adm_foot(array("area" => "admin")); ?>

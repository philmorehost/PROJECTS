<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
 bc_secure_session_start();
    include("../func/bc-config.php");
    // §33: registration disabled in Service Control -> sign-in page.
    if (function_exists('bc_registration_enabled') && !bc_registration_enabled((int)($select_vendor_table["id"] ?? 0))) {
        header("Location: /web/Login.php");
        exit();
    }
require_once(__DIR__ . "/../func/bc-plate-auth.php");
    if(isset($get_logged_user_details["username"]) && !empty($get_logged_user_details["username"]) && ($get_logged_user_details["status"] == 1)){
    	header("Location: /web/Dashboard.php");
    }
    
    // Registration is now handled via AJAX + email OTP (send-reg-otp.php / verify-reg-otp.php).
    // The block below is kept as a no-JS fallback; it will only fire if JavaScript is disabled.
    if(isset($_POST["register"])){
        bc_validate_csrf();
        if((int)($select_vendor_table['reg_otp_enabled']??1)===1){$_SESSION['product_purchase_response']='Email verification is required. Enable JavaScript to complete registration.';header('Location: Register.php');exit;}

    	$user = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["user"]))));
    	$pass = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["pass"])));
        $first = mysqli_real_escape_string($connection_server, trim(strip_tags(ucwords($_POST["first"]))));
    	$last = mysqli_real_escape_string($connection_server, trim(strip_tags(ucwords($_POST["last"]))));
        $other = mysqli_real_escape_string($connection_server, trim(strip_tags(ucwords($_POST["other"]))));
    	$email = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["email"]))));
        $country_raw = trim(strip_tags((string)($_POST["country"] ?? "NG")));
        $country_info = function_exists('bc_world_country_lookup') ? bc_world_country_lookup($country_raw) : null;
        $country = mysqli_real_escape_string($connection_server, $country_info ? $country_info['name'] : '');
        $phone_raw = trim(strip_tags((string)($_POST["phone"] ?? "")));
        $phone_norm = function_exists('bc_normalize_world_phone')
            ? bc_normalize_world_phone($phone_raw, $country_info ? $country_info['code'] : $country_raw)
            : (is_numeric($phone_raw) && strlen($phone_raw) == 11 ? $phone_raw : '');
        $phone = mysqli_real_escape_string($connection_server, $phone_norm);
    	$address = mysqli_real_escape_string($connection_server, trim(strip_tags(ucwords($_POST["address"]))));
        $referral = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_GET["referral"] ?? ""))));
        
        if(!empty($user) && !empty($pass) && !empty($first) && !empty($last) && !empty($email) && !empty($phone_raw) && !empty($address)){
                    // When email verification is on, this legacy no-JS path must NOT create the
                    // account: it used to insert straight into sas_users, which let a direct POST
                    // skip the emailed code entirely. Send the visitor through the real flow.
                    if (($select_vendor_table["reg_otp_enabled"] ?? 1) == 1) {
                        $_SESSION["product_purchase_response"] = "Please verify your email address to finish creating your account.";
                        header("Location: Register.php");
                        exit();
                    }
                    $vendor_id = $select_vendor_table["id"];
                    $check_user_details = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' && username='$user'");
                    
                    if(!empty($referral)){
                        $check_user_referral_details = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' && username='$referral'");
                        if(mysqli_num_rows($check_user_referral_details) == 1){
                            $get_referral_details = mysqli_fetch_array($check_user_referral_details);
                            $referral_edited = $get_referral_details["id"];
                        }else{
                            $referral_edited = "";
                        }
                    }else{
                        $referral_edited = "";
                    }
                    if(mysqli_num_rows($check_user_details) == 0){
                        if(!filter_var($user, FILTER_VALIDATE_EMAIL)){
                            if(filter_var($email, FILTER_VALIDATE_EMAIL)){
                                if($country_info !== null && $phone !== ''){
                                    // §29: bcrypt at rest (login still accepts legacy MD5).
                                    $md5_pass = function_exists('bc_hash_password') ? bc_hash_password($pass) : md5($pass);
                                    $api_key = bin2hex(random_bytes(25));
                                    $last_login = date('Y-m-d H:i:s.u');
                                    $has_country_col = function_exists('bc_ensure_user_country_column') && bc_ensure_user_country_column($connection_server);
                                    $country_col_sql = $has_country_col ? ", country" : "";
                                    $country_val_sql = $has_country_col ? ", '$country'" : "";
                                    mysqli_query($connection_server, "INSERT INTO sas_users (vendor_id, email, username, password, phone_number, balance, firstname, lastname, othername, home_address{$country_col_sql}, referral_id, account_level, api_key, api_key_created_at, api_key_expires_at, last_login, api_status, status) VALUES ('$vendor_id', '$email', '$user', '$md5_pass', '$phone', '0', '$first', '$last', '$other', '$address'{$country_val_sql}, '$referral_edited', '1', '$api_key', NOW(), DATE_ADD(NOW(), INTERVAL 60 DAY), '$last_login', '2', '1')");
                                    
                                    // Email Beginning
                                    $reg_template_encoded_text_array = array("{firstname}" => $first, "{lastname}" => $last, "{username}" => $user, "{address}" => $address, "{email}" => $email, "{phone}" => $phone);
                                    $raw_reg_template_subject = getUserEmailTemplate('user-reg','subject');
                                    $raw_reg_template_body = getUserEmailTemplate('user-reg','body');
                                    foreach($reg_template_encoded_text_array as $array_key => $array_val){
                                        $raw_reg_template_subject = str_replace($array_key, $array_val, $raw_reg_template_subject);
                                        $raw_reg_template_body = str_replace($array_key, $array_val, $raw_reg_template_body);
                                    }
                                    sendVendorEmail($email, $raw_reg_template_subject, $raw_reg_template_body);
                                    // Email End

                                    // Log the user in immediately
                                    $_SESSION["user_session"] = $user;
                                    bc_browser_binding_new_user($vendor_id,stripslashes($user));
                                    if (function_exists('bc_auth_epoch_stamp')) bc_auth_epoch_stamp(); // §33 session epoch
                                    //Congratulations, Account Has Been Created Successfully.
                                    $json_response_array = array("desc" => "Congratulations, Account Has Been Created Successfully.");
                                    $json_response_encode = json_encode($json_response_array,true);

                                    $new_u_q = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' AND username='$user' LIMIT 1");
                                    $new_u_row = ($new_u_q && mysqli_num_rows($new_u_q) === 1) ? mysqli_fetch_assoc($new_u_q) : ['vendor_id' => $vendor_id, 'username' => $user, 'kyc_status' => 0];
                                    $next_url = function_exists('bc_web_next_onboarding_url') ? bc_web_next_onboarding_url($connection_server, $new_u_row, $vendor_id) : '/web/SecurityPIN.php';
                                    header("Location: " . $next_url);
                                    exit();
                                }elseif($country_info === null){
                                    $json_response_array = array("desc" => "Invalid Country selected");
                                    $json_response_encode = json_encode($json_response_array,true);
                                }else{
                                    $json_response_array = array("desc" => "Invalid phone number. Enter a valid local or international number (e.g. +14155552671, +447911123456, or 08031234567)");
                                    $json_response_encode = json_encode($json_response_array,true);
                                }
                            }else{
                                //Invalid Email
                                $json_response_array = array("desc" => "Invalid Email");
                                $json_response_encode = json_encode($json_response_array,true);
                            }
                        }else{
                            //Username Cannot Be Email
                            $json_response_array = array("desc" => "Username Cannot Be Email");
                            $json_response_encode = json_encode($json_response_array,true);
                        }
                    }else{
                        if(mysqli_num_rows($check_user_details) == 1){
                            //User Already Exists
                            $json_response_array = array("desc" => "User Already Exists");
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
                    if(empty($user)){
                        //Username Field Empty
                        $json_response_array = array("desc" => "Username Field Empty");
                        $json_response_encode = json_encode($json_response_array,true);
                    }else{
                        if(empty($pass)){
                            //Password Field Empty
                            $json_response_array = array("desc" => "Password Field Empty");
                            $json_response_encode = json_encode($json_response_array,true);
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
                                    if(empty($email)){
                                        //Email Field Empty
                                        $json_response_array = array("desc" => "Email Field Empty");
                                        $json_response_encode = json_encode($json_response_array,true);
                                    }else{
                                    	if(empty($phone_raw)){
                                    		//Phone Number Field Empty
                                    		$json_response_array = array("desc" => "Phone Number Field Empty");
                                    		$json_response_encode = json_encode($json_response_array,true);
                                    	}else{
                                        	if(empty($address)){
                                            	//Home Address Field Empty
                                            	$json_response_array = array("desc" => "Home Address Field Empty");
                                            	$json_response_encode = json_encode($json_response_array,true);
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
    }
    
    $get_referral = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_GET["referral"]))));
    $vendor_id = $select_vendor_table["id"];
    $check_user_referral_details = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' && username='$get_referral'"));
    
?>
<?php
$bc_world_list = function_exists('bc_world_countries') ? bc_world_countries() : ['NG' => ['name' => 'Nigeria', 'dial' => '+234']];
$bc_default_country = 'NG';
if (function_exists('getCountryCodeFromIP') && !empty($_SERVER['REMOTE_ADDR'])) {
    $detected_cc = strtoupper((string)getCountryCodeFromIP($_SERVER['REMOTE_ADDR']));
    if (isset($bc_world_list[$detected_cc])) $bc_default_country = $detected_cc;
}
bc_plate_auth_head('Register | ' . $get_all_site_details["site_title"]);
bc_plate_auth_top(array(
    'icon'  => 'user',
    'title' => 'Get started',
    'sub'   => 'Fill in your details to create a secure account.',
    'wide'  => true,
));
?>
        <form method="post" action="">
            <?php echo bc_csrf_field(); ?>
            <div class="pk-auth__row">
                <div class="pk-auth__field">
                    <label class="pk-auth__label" for="user">Username</label>
                    <input id="user" name="user" type="text" class="pk-auth__input" placeholder="e.g. johndoe" pattern="[a-zA-Z]{6,}" required>
                </div>
                <div class="pk-auth__field">
                    <label class="pk-auth__label" for="country">Country</label>
                    <select id="country" name="country" class="pk-auth__input" required>
                        <?php foreach ($bc_world_list as $cc_code => $cc_info): ?>
                            <option value="<?php echo htmlspecialchars($cc_code, ENT_QUOTES, 'UTF-8'); ?>" data-dial="<?php echo htmlspecialchars($cc_info['dial'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo $cc_code === $bc_default_country ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cc_info['name'] . ' (' . $cc_info['dial'] . ')', ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="pk-auth__field">
                <label class="pk-auth__label" for="phone">Phone number <small id="phone-dial-hint" style="font-weight:500;color:var(--muted);">(include country code or local number, e.g. <?php echo htmlspecialchars($bc_world_list[$bc_default_country]['dial'] ?? '+234', ENT_QUOTES, 'UTF-8'); ?>...)</small></label>
                <input id="phone" name="phone" type="tel" class="pk-auth__input" placeholder="e.g. <?php echo htmlspecialchars(($bc_world_list[$bc_default_country]['dial'] ?? '+234') . '8012345678', ENT_QUOTES, 'UTF-8'); ?>" pattern="\+?[0-9\s\-\(\)]{7,20}" title="Enter a valid local or international phone number (7–15 digits)" required>
            </div>

            <div class="pk-auth__row">
                <div class="pk-auth__field">
                    <label class="pk-auth__label" for="first">First name</label>
                    <input id="first" name="first" type="text" class="pk-auth__input" placeholder="First name" required>
                </div>
                <div class="pk-auth__field">
                    <label class="pk-auth__label" for="last">Last name</label>
                    <input id="last" name="last" type="text" class="pk-auth__input" placeholder="Last name" required>
                </div>
                <div class="pk-auth__field">
                    <label class="pk-auth__label" for="other">Other name</label>
                    <input id="other" name="other" type="text" class="pk-auth__input" placeholder="Optional">
                </div>
            </div>

            <div class="pk-auth__field">
                <label class="pk-auth__label" for="email">Email address</label>
                <input id="email" name="email" type="email" class="pk-auth__input" placeholder="name@example.com" required>
            </div>

            <div class="pk-auth__field">
                <label class="pk-auth__label" for="address">Residential address</label>
                <input id="address" name="address" type="text" class="pk-auth__input" placeholder="Full address" required>
            </div>

            <div class="pk-auth__field">
                <label class="pk-auth__label" for="password-field">Security password</label>
                <div style="position: relative;">
                    <input id="password-field" name="pass" type="password" class="pk-auth__input"
                           placeholder="At least 8 characters" required style="padding-right: 62px;">
                    <button type="button" onclick="togglePasswordVisibility('password-field', this)"
                            aria-label="Show password"
                            style="position:absolute; right:8px; top:50%; transform:translateY(-50%);
                                   border:0; background:transparent; color:var(--muted); font:inherit;
                                   font-size:.8rem; font-weight:700; cursor:pointer;">Show</button>
                </div>
            </div>

            <?php if(!empty($check_user_referral_details["id"])){ ?>
                <div class="pk-auth__alert pk-auth__alert--info">
                    <span><?php echo bc_plate_auth_icon('check', 18); ?></span>
                    <span>Referred by <strong><?php echo htmlspecialchars(ucwords($check_user_referral_details["firstname"] . " " . $check_user_referral_details["lastname"])); ?></strong></span>
                </div>
            <?php } ?>

            <div class="pk-auth__field">
                <button id="btn-create-account" name="register" type="submit" class="pk-auth__btn" data-no-lock>
                    Create my account
                </button>
            </div>

            <p class="pk-auth__note">
                Already have an account? <a class="pk-auth__link" href="Login.php">Sign in</a>
            </p>
        </form>

  <script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.0.16/dist/sweetalert2.all.min.js"></script>

<?php if(isset($_SESSION["product_purchase_response"])){
    $reg_msg = (string)$_SESSION["product_purchase_response"];
    $reg_is_err = (stripos($reg_msg, 'congratulations') === false && stripos($reg_msg, 'success') === false);
?>

<script>
  Swal.fire (<?php echo json_encode($reg_is_err ? 'Notice' : 'Done!'); ?>, <?php echo json_encode($reg_msg); ?>, <?php echo json_encode($reg_is_err ? 'error' : 'success'); ?>) ;
            //Swal.fire({ position:'top-end',type:'',title:'Oops', text: 'kindly fill all form', showConfirmButton:1,timer:2500 });
          setTimeout(() => {
                fetch('/func/unset-product-response.php')
                    .then(response => response.text());
            }, 1000); // 3 seconds

</script>


	<!-- <div style="text-align: center;" id="customAlertDiv" class="bg-2 box-shadow m-z-index-2 s-z-index-2 m-block-dp s-block-dp m-position-fix s-position-fix m-top-20 s-top-40 br-radius-5px m-width-60 s-width-26 m-height-auto s-height-auto m-padding-lt-1 s-padding-lt-1 m-padding-rt-1 s-padding-rt-1 m-padding-tp-5 s-padding-tp-1 m-padding-bm-5 s-padding-bm-1 m-margin-lt-19 s-margin-lt-36 m-margin-bm-2 s-margin-bm-2">
	<span style="user-select: notne;" class="color-10 text-bold-500 m-font-size-20 s-font-size-25">
		<?php echo htmlspecialchars($_SESSION["product_purchase_response"], ENT_QUOTES, 'UTF-8'); ?>
	</span><br/>
	<button style="text-align: center; user-select: auto;" onclick="customDismissPop();" onkeypress="keyCustomDismissPop(event);" class="button-box onhover-bg-color-10 a-cursor color-2 bg-10 m-font-size-12 s-font-size-13 br-style-tp-0 m-inline-dp s-inline-block-dp m-position-rel s-position-rel m-width-30 s-width-30 m-height-auto s-height-auto m-margin-tp-1 s-margin-tp-1 m-margin-bm-2 s-margin-bm-2 m-margin-lt-0 s-margin-lt-0 m-margin-rt-0 s-margin-rt-0 m-padding-tp-5 s-padding-tp-5 m-padding-bm-5 s-padding-bm-5 m-padding-lt-5 s-padding-lt-5 m-padding-rt-5 s-padding-rt-5">
		DISMISS
	</button>
</div>
<script>
	function customDismissPop(){
		var customAlertDiv = document.getElementById("customAlertDiv");
		setTimeout(function(){
			customAlertDiv.style.display = "none";
		}, 300);
	}
	
	document.addEventListener("keydown", function(event){
		if(event.keyCode === 13){
			//prevent enter key default function
			event.preventDefault();
			var customAlertDiv = document.getElementById("customAlertDiv");
			setTimeout(function(){
				customAlertDiv.style.display = "none";
			}, 300);
		}
	});
	
	clearProductResponse();
	function clearProductResponse(){
		var productHttp = new XMLHttpRequest();
        productHttp.open("GET", "../unset-product.php");
        productHttp.setRequestHeader("Content-Type", "application/json");
        // productHttp.onload = function(){
        //     alert(productHttp.status);
        // }
        productHttp.send();
	}
</script>-->
<?php } ?>
<!-- ── Email OTP Verification Modal ─────────────────────────────────────── -->
<div id="otp-overlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.65);
     z-index:9999; align-items:center; justify-content:center;">
  <div class="card border-0 rounded-4 shadow-lg text-center p-4 p-md-5"
       style="max-width:420px; width:92%; margin:auto;">
    <div class="mb-3">
      <span style="font-size:2.5rem;">✉️</span>
    </div>
    <h5 class="fw-bold mb-1">Verify Your Email</h5>
    <p class="text-muted small mb-3" id="otp-email-hint">
      A 6-digit code has been sent to your email address.
    </p>

    <!-- 6 individual digit boxes -->
    <div id="otp-boxes" class="d-flex justify-content-center gap-2 mb-3">
      <input class="otp-digit" type="text" inputmode="numeric" pattern="[0-9]" maxlength="1"
             autocomplete="one-time-code"
             style="width:46px;height:56px;font-size:1.5rem;font-weight:700;text-align:center;
                    border:2px solid #dee2e6;border-radius:10px;outline:none;transition:border-color .15s;">
      <input class="otp-digit" type="text" inputmode="numeric" pattern="[0-9]" maxlength="1"
             style="width:46px;height:56px;font-size:1.5rem;font-weight:700;text-align:center;
                    border:2px solid #dee2e6;border-radius:10px;outline:none;transition:border-color .15s;">
      <input class="otp-digit" type="text" inputmode="numeric" pattern="[0-9]" maxlength="1"
             style="width:46px;height:56px;font-size:1.5rem;font-weight:700;text-align:center;
                    border:2px solid #dee2e6;border-radius:10px;outline:none;transition:border-color .15s;">
      <input class="otp-digit" type="text" inputmode="numeric" pattern="[0-9]" maxlength="1"
             style="width:46px;height:56px;font-size:1.5rem;font-weight:700;text-align:center;
                    border:2px solid #dee2e6;border-radius:10px;outline:none;transition:border-color .15s;">
      <input class="otp-digit" type="text" inputmode="numeric" pattern="[0-9]" maxlength="1"
             style="width:46px;height:56px;font-size:1.5rem;font-weight:700;text-align:center;
                    border:2px solid #dee2e6;border-radius:10px;outline:none;transition:border-color .15s;">
      <input class="otp-digit" type="text" inputmode="numeric" pattern="[0-9]" maxlength="1"
             style="width:46px;height:56px;font-size:1.5rem;font-weight:700;text-align:center;
                    border:2px solid #dee2e6;border-radius:10px;outline:none;transition:border-color .15s;">
    </div>

    <div id="otp-error" class="text-danger small mb-2" style="display:none;min-height:20px;"></div>
    <div id="otp-success" class="text-success small mb-2" style="display:none;min-height:20px;"></div>

    <div class="text-muted small mb-3">
      Code expires in <strong id="otp-countdown">10:00</strong>
    </div>

    <div id="otp-verifying" style="display:none;" class="mb-2">
      <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
      <span class="small text-muted">Verifying…</span>
    </div>

    <button id="otp-resend-btn" type="button" class="btn btn-link btn-sm text-decoration-none p-0 mb-2"
            onclick="sendOTP(true)">
      Didn't receive it? <strong>Resend OTP</strong>
    </button>
    <br>
    <button type="button" class="btn btn-outline-secondary btn-sm mt-1"
            onclick="closeOtpModal()">← Back to form</button>
  </div>
</div>
<!-- ──────────────────────────────────────────────────────────────────────── -->

<script src="../asset/bc-registration-otp.js" defer></script>

<script src="../jsfile/bc-custom-all.js"></script>
<script>
(function () {
    const countrySelect = document.getElementById('country');
    const phoneInput = document.getElementById('phone');
    const dialHint = document.getElementById('phone-dial-hint');
    if (countrySelect && phoneInput) {
        countrySelect.addEventListener('change', function () {
            const opt = countrySelect.options[countrySelect.selectedIndex];
            const dial = opt ? (opt.getAttribute('data-dial') || '') : '';
            if (dialHint && dial) dialHint.textContent = '(include country code or local number, e.g. ' + dial + '...)';
            if (dial) phoneInput.placeholder = 'e.g. ' + dial + '123456789';
        });
    }
})();
function togglePasswordVisibility(fieldId, toggleElement) {
    const field = document.getElementById(fieldId);
    const label = toggleElement.getAttribute('aria-label') === 'Show password' ? 'Hide password' : 'Show password';
    toggleElement.setAttribute('aria-label', label);
    toggleElement.textContent = field.type === 'password' ? 'Hide' : 'Show';
    field.type = field.type === 'password' ? 'text' : 'password';
    field.focus();
}
</script>

<?php
bc_plate_auth_end(array(
    'links' => array(
        array('href' => 'Login.php', 'label' => 'Sign in'),
        array('href' => 'PasswordRecovery.php', 'label' => 'Forgot password'),
    ),
));
?>

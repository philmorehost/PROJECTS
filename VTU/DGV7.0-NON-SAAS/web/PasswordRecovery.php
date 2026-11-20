<?php session_start();
    include("../func/bc-config.php");
require_once(__DIR__ . "/../func/bc-plate-auth.php");
    if(isset($get_logged_user_details["username"]) && !empty($get_logged_user_details["username"]) && ($get_logged_user_details["status"] == 1)){
    	header("Location: /web/Dashboard.php");
    }
    
    // Password Reset Control + Anti-BruteForce (security hardening)
    $reset_vendor_id = resolveVendorID();
    $reset_user_ip = $_SERVER['REMOTE_ADDR'];
    $reset_blocked_msg = null;
    if (!isServiceEnabled('password_reset', $reset_vendor_id)) {
        $reset_blocked_msg = "Password reset is currently disabled for this account. Please contact support.";
    } else {
        $reset_probe_user = (isset($_SESSION["user-recovery-username"]) && !empty($_SESSION["user-recovery-username"]))
            ? $_SESSION["user-recovery-username"]
            : (isset($_POST["user"]) ? trim(strip_tags($_POST["user"])) : '');
        if ($msg = bc_is_password_reset_blocked($reset_probe_user, $reset_user_ip, $reset_vendor_id)) {
            $reset_blocked_msg = "Password reset is temporarily locked for this account: " . $msg . ". Please unlock with your Security PIN to continue.";
        }
    }
    
    if(isset($_POST["send-code"])){
        if ($reset_blocked_msg) {
            $_SESSION["product_purchase_response"] = $reset_blocked_msg;
            header("Location: ".$_SERVER["REQUEST_URI"]);
            exit();
        }
    	$user = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["user"]))));
    	if(!empty($user)){
		$vendor_id = resolveVendorID();
		$get_user_details = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' && username='$user'");
    		if(mysqli_num_rows($get_user_details) == 1){
    			$get_user_personal_details = mysqli_fetch_array($get_user_details);
				$_SESSION["user-recovery-username"] = $get_user_personal_details["username"];
				$_SESSION["user-recovery-code"] = substr(str_shuffle("12345678901234567890"), 0, 6);
				// Email Beginning
				$log_template_encoded_text_array = array("{firstname}" => $get_user_personal_details["firstname"], "{lastname}" => $get_user_personal_details["lastname"], "{recovery_code}" => $_SESSION["user-recovery-code"]);
				$raw_log_template_subject = getUserEmailTemplate('user-account-recovery','subject');
				$raw_log_template_body = getUserEmailTemplate('user-account-recovery','body');
				foreach($log_template_encoded_text_array as $array_key => $array_val){
					$raw_log_template_subject = str_replace($array_key, $array_val, $raw_log_template_subject);
					$raw_log_template_body = str_replace($array_key, $array_val, $raw_log_template_body);
				}
				// §46: lead with the code so it can be copied from the inbox list without opening the mail.
				$raw_log_template_subject = (string)($_SESSION["user-recovery-code"]) . " is your password reset code" . ($raw_log_template_subject !== '' ? ' — ' . $raw_log_template_subject : '');
				sendVendorEmail($get_user_personal_details["email"], $raw_log_template_subject, $raw_log_template_body);
				// Email End
				// Record the reset request — repeated requests for the same account lock it out.
				bc_handle_password_reset_attempt($user, $reset_user_ip, 0, $vendor_id, 'request');
				//Recovery Code Emailed Successfully
				$json_response_array = array("desc" => "Recovery Code Emailed Successfully");
				$json_response_encode = json_encode($json_response_array,true);
    		}else{
    			if(mysqli_num_rows($get_user_details) > 1){
    				//Duplicated Details, Contact Admin
    				$json_response_array = array("desc" => "Duplicated Details, Contact Admin");
    				$json_response_encode = json_encode($json_response_array,true);
    			}else{
    				//User Not Exists
    				bc_handle_password_reset_attempt($user, $reset_user_ip, 0, $vendor_id, 'request');
    				$json_response_array = array("desc" => "User Not Exists");
    				$json_response_encode = json_encode($json_response_array,true);
    			}
    		}
    	}else{
    		if(empty($user)){
    			//Username Field Empty
    			$json_response_array = array("desc" => "Username Field Empty");
    			$json_response_encode = json_encode($json_response_array,true);
    		}
    	}
		
        $json_response_decode = json_decode($json_response_encode,true);
        $_SESSION["product_purchase_response"] = $json_response_decode["desc"];
        header("Location: ".$_SERVER["REQUEST_URI"]);
    }

	if(isset($_POST["verify-code"])){
        if ($reset_blocked_msg) {
            $_SESSION["product_purchase_response"] = $reset_blocked_msg;
            header("Location: ".$_SERVER["REQUEST_URI"]);
            exit();
        }
    	$pass = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["pass"])));
    	$confirm_pass = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["confirm-pass"])));
		$recovery_code = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["code"]))));
    	if(!empty($pass) && !empty($confirm_pass) && !empty($recovery_code) && is_numeric($recovery_code) && (strlen($recovery_code) == "6")){
		$vendor_id = resolveVendorID();
		$get_user_details = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' && username='".$_SESSION["user-recovery-username"]."'");
    		if(mysqli_num_rows($get_user_details) == 1){
				if($_SESSION["user-recovery-code"] == $recovery_code){
					if($pass == $confirm_pass){
						// §29: bcrypt at rest; a completed recovery also satisfies a forced change.
						$md5_pass = function_exists('bc_hash_password') ? bc_hash_password($pass) : md5($pass);
						$get_user_personal_details = mysqli_fetch_array($get_user_details);
						$get_user_details = mysqli_query($connection_server, "UPDATE sas_users SET password='$md5_pass', force_password_change=0 WHERE vendor_id='".$get_user_personal_details["vendor_id"]."' && username='".$get_user_personal_details["username"]."'");
						// Email Beginning
						$log_template_encoded_text_array = array("{firstname}" => $get_user_personal_details["firstname"], "{lastname}" => $get_user_personal_details["lastname"]);
						$raw_log_template_subject = getUserEmailTemplate('user-pass-update','subject');
						$raw_log_template_body = getUserEmailTemplate('user-pass-update','body');
						foreach($log_template_encoded_text_array as $array_key => $array_val){
							$raw_log_template_subject = str_replace($array_key, $array_val, $raw_log_template_subject);
							$raw_log_template_body = str_replace($array_key, $array_val, $raw_log_template_body);
						}
						sendVendorEmail($get_user_personal_details["email"], $raw_log_template_subject, $raw_log_template_body);
						// Email End
						// Successful reset clears the password-reset lockout counters.
						bc_handle_password_reset_attempt($_SESSION["user-recovery-username"] ?? '', $reset_user_ip, 1, $reset_vendor_id, 'verify');
						unset($_SESSION["user-recovery-username"]);
						unset($_SESSION["user-recovery-code"]);

						//Password Changed Successfully
						$json_response_array = array("desc" => "Password Changed Successfully");
						$json_response_encode = json_encode($json_response_array,true);
					}else{
						//Password Not Match
						bc_handle_password_reset_attempt($_SESSION["user-recovery-username"] ?? '', $reset_user_ip, 0, $reset_vendor_id, 'verify');
						$json_response_array = array("desc" => "Password Not Match");
						$json_response_encode = json_encode($json_response_array,true);
					}
				}else{
					//Invalid Recovery Code
					bc_handle_password_reset_attempt($_SESSION["user-recovery-username"] ?? '', $reset_user_ip, 0, $reset_vendor_id, 'verify');
					$json_response_array = array("desc" => "Invalid Recovery Code");
					$json_response_encode = json_encode($json_response_array,true);
				}
    		}else{
    			if(mysqli_num_rows($get_user_details) > 1){
    				//Duplicated Details, Contact Admin
    				$json_response_array = array("desc" => "Duplicated Details, Contact Admin");
    				$json_response_encode = json_encode($json_response_array,true);
    			}else{
    				//User Not Exists
    				$json_response_array = array("desc" => "User Not Exists");
    				$json_response_encode = json_encode($json_response_array,true);
    			}
    		}
    	}else{
			if(empty($pass)){
    			//Password Field Empty
    			$json_response_array = array("desc" => "Password Field Empty");
    			$json_response_encode = json_encode($json_response_array,true);
    		}else{
				if(empty($confirm_pass)){
					//Confirm Password Field Empty
					$json_response_array = array("desc" => "Confirm Password Field Empty");
					$json_response_encode = json_encode($json_response_array,true);
				}else{
					if(empty($recovery_code)){
						//Recovery Code Field Empty
						$json_response_array = array("desc" => "Recovery Code Field Empty");
						$json_response_encode = json_encode($json_response_array,true);
					}else{
						if(!is_numeric($recovery_code)){
							//Non-numeric String
							$json_response_array = array("desc" => "Non-numeric String");
							$json_response_encode = json_encode($json_response_array,true);
						}else{
							if(strlen($recovery_code) !== "6"){
								//Recovery Code Must Be 6 Digits
								$json_response_array = array("desc" => "Recovery Code Must Be 6 Digits");
								$json_response_encode = json_encode($json_response_array,true);
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

	if(isset($_POST["reset-recovery"])){
		unset($_SESSION["user-recovery-username"]);
		unset($_SESSION["user-recovery-code"]);
		header("Location: ".$_SERVER["REQUEST_URI"]);
	}
?>
<?php
bc_plate_auth_head('Password recovery | ' . $get_all_site_details["site_title"]);
bc_plate_auth_top(array(
    'icon'      => $reset_blocked_msg ? 'warn' : 'key',
    'icon_style'=> $reset_blocked_msg ? 'warn' : '',
    'title'     => 'Account recovery',
    'sub'       => $reset_blocked_msg
        ? htmlspecialchars($reset_blocked_msg)
        : 'Follow the steps below to reset your password.',
    'alert'     => $reset_blocked_msg
        ? array(array('type' => 'error', 'body' => htmlspecialchars($reset_blocked_msg)))
        : null,
));
?>
        <?php if ($reset_blocked_msg): ?>
            <div class="pk-auth__field">
                <a class="pk-auth__btn pk-auth__btn--danger" href="LockoutResolution.php">
                    <?php echo bc_plate_auth_icon('lock', 18); ?> Unlock with Security PIN
                </a>
            </div>
            <p class="pk-auth__note">
                <a class="pk-auth__link" href="Login.php">Back to login</a>
            </p>
        <?php else: ?>
            <form method="post" action="">
                <?php if(!isset($_SESSION["user-recovery-username"]) || empty($_SESSION["user-recovery-username"])){ ?>
                    <div class="pk-auth__field">
                        <label class="pk-auth__label" for="user">Enter username</label>
                        <input id="user" name="user" type="text" class="pk-auth__input" placeholder="e.g. johndoe"
                               style="text-transform: lowercase;" required>
                        <p class="pk-auth__note" style="margin-top:8px;">A verification code will be sent to your registered email.</p>
                    </div>
                    <div class="pk-auth__field">
                        <button name="send-code" type="submit" class="pk-auth__btn">Send recovery code</button>
                    </div>
                <?php } ?>

                <?php if(isset($_SESSION["user-recovery-username"]) && !empty($_SESSION["user-recovery-username"])){ ?>
                    <div class="pk-auth__alert pk-auth__alert--ok">
                        <span><?php echo bc_plate_auth_icon('check', 18); ?></span>
                        <span>Code sent to your email.</span>
                    </div>

                    <div class="pk-auth__field">
                        <label class="pk-auth__label" for="code">Recovery code</label>
                        <input id="code" name="code" type="text" class="pk-auth__input" placeholder="6-digit code" pattern="[0-9]{6}" required>
                    </div>

                    <div class="pk-auth__field">
                        <label class="pk-auth__label" for="new-password">New password</label>
                        <div style="position: relative;">
                            <input id="new-password" name="pass" type="password" class="pk-auth__input"
                                   placeholder="Min 8 characters" required style="padding-right: 62px;">
                            <button type="button" onclick="togglePasswordVisibility('new-password', this)"
                                    aria-label="Show password"
                                    style="position:absolute; right:8px; top:50%; transform:translateY(-50%);
                                           border:0; background:transparent; color:var(--muted); font:inherit;
                                           font-size:.8rem; font-weight:700; cursor:pointer;">Show</button>
                        </div>
                    </div>

                    <div class="pk-auth__field">
                        <label class="pk-auth__label" for="confirm-password">Confirm new password</label>
                        <div style="position: relative;">
                            <input id="confirm-password" name="confirm-pass" type="password" class="pk-auth__input"
                                   placeholder="Repeat password" required style="padding-right: 62px;">
                            <button type="button" onclick="togglePasswordVisibility('confirm-password', this)"
                                    aria-label="Show password"
                                    style="position:absolute; right:8px; top:50%; transform:translateY(-50%);
                                           border:0; background:transparent; color:var(--muted); font:inherit;
                                           font-size:.8rem; font-weight:700; cursor:pointer;">Show</button>
                        </div>
                    </div>

                    <div class="pk-auth__field">
                        <button name="verify-code" type="submit" class="pk-auth__btn">Reset password</button>
                    </div>
                    <div class="pk-auth__field">
                        <button name="reset-recovery" type="submit" class="pk-auth__btn pk-auth__btn--ghost">Restart recovery</button>
                    </div>
                <?php } ?>

                <div class="pk-auth__divider">or</div>
                <p class="pk-auth__note">
                    Remembered it? <a class="pk-auth__link" href="Login.php">Back to login</a>
                </p>
            </form>
        <?php endif; ?>

<?php if(isset($_SESSION["product_purchase_response"])){ ?>

  <script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.0.16/dist/sweetalert2.all.min.js"></script>

<script>
  Swal.fire ('Done!', '<?php echo htmlspecialchars($_SESSION["product_purchase_response"], ENT_QUOTES, 'UTF-8'); ?>', 'success') ;
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
<script src="/jsfile/bc-custom-all.js"></script>
<script>
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
bc_plate_auth_end();
?>

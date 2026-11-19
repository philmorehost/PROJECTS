<?php session_start();
    include("../func/bc-admin-config.php");
    
    // Password Reset Control + Anti-BruteForce (security hardening) — admin's own account
    $reset_vendor_id = resolveVendorID();
    $reset_user_ip = $_SERVER['REMOTE_ADDR'];
    $reset_blocked_msg = null;
    if (!isServiceEnabled('password_reset', $reset_vendor_id)) {
        $reset_blocked_msg = "Password reset is currently disabled for this admin account. Please contact the platform administrator.";
    } else {
        $reset_probe_user = (isset($_SESSION["admin-recovery-username"]) && !empty($_SESSION["admin-recovery-username"]))
            ? $_SESSION["admin-recovery-username"]
            : (isset($_POST["email"]) ? trim(strip_tags($_POST["email"])) : '');
        if ($msg = bc_is_password_reset_blocked($reset_probe_user, $reset_user_ip, $reset_vendor_id)) {
            $reset_blocked_msg = "Password reset is temporarily locked for this admin account: " . $msg . ". Please unlock with your Security PIN to continue.";
        }
    }
    
    if(isset($_POST["send-code"])){
        if ($reset_blocked_msg) {
            $_SESSION["product_purchase_response"] = $reset_blocked_msg;
            header("Location: ".$_SERVER["REQUEST_URI"]);
            exit();
        }
    	$email = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["email"]))));
    	if(!empty($email)){
            $vendor_id = resolveVendorID();
		$get_user_details = mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id' && email='$email'");
    		if(mysqli_num_rows($get_user_details) == 1){
    			$get_user_personal_details = mysqli_fetch_array($get_user_details);
				$_SESSION["admin-recovery-username"] = $get_user_personal_details["email"];
				$_SESSION["admin-recovery-code"] = substr(str_shuffle("12345678901234567890"), 0, 6);
				// Email Beginning
				$log_template_encoded_text_array = array("{firstname}" => $get_user_personal_details["firstname"], "{lastname}" => $get_user_personal_details["lastname"], "{recovery_code}" => $_SESSION["admin-recovery-code"]);
				$raw_log_template_subject = getSuperAdminEmailTemplate('vendor-account-recovery','subject');
				$raw_log_template_body = getSuperAdminEmailTemplate('vendor-account-recovery','body');
				foreach($log_template_encoded_text_array as $array_key => $array_val){
					$raw_log_template_subject = str_replace($array_key, $array_val, $raw_log_template_subject);
					$raw_log_template_body = str_replace($array_key, $array_val, $raw_log_template_body);
				}
				sendVendorEmail($get_user_personal_details["email"], $raw_log_template_subject, $raw_log_template_body);
				// Email End
				// Record the reset request — repeated requests for the same admin account lock it out.
				bc_handle_password_reset_attempt($email, $reset_user_ip, 0, $vendor_id, 'request');
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
    				bc_handle_password_reset_attempt($email, $reset_user_ip, 0, $vendor_id, 'request');
    				$json_response_array = array("desc" => "User Not Exists");
    				$json_response_encode = json_encode($json_response_array,true);
    			}
    		}
    	}else{
    		if(empty($email)){
    			//Email Field Empty
    			$json_response_array = array("desc" => "Email Field Empty");
    			$json_response_encode = json_encode($json_response_array,true);
    		}
    	}
		
        $json_response_decode = json_decode($json_response_encode,true);
        $_SESSION["product_purchase_response"] = $json_response_decode["desc"];
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit();
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
		$get_user_details = mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id' && email='".$_SESSION["admin-recovery-username"]."'");
    		if(mysqli_num_rows($get_user_details) == 1){
				if($_SESSION["admin-recovery-code"] == $recovery_code){
					if($pass == $confirm_pass){
						// Stored as password_hash() (bcrypt); the login path still accepts legacy raw-MD5 rows.
						$new_vendor_pass_hash = mysqli_real_escape_string($connection_server, bc_hash_password($pass));
						$get_user_personal_details = mysqli_fetch_array($get_user_details);
						$get_user_details = mysqli_query($connection_server, "UPDATE sas_vendors SET password='$new_vendor_pass_hash' WHERE id='".$get_user_personal_details["id"]."' && email='".$get_user_personal_details["email"]."'");
						// Email Beginning
						$log_template_encoded_text_array = array("{firstname}" => $get_user_personal_details["firstname"], "{lastname}" => $get_user_personal_details["lastname"]);
						$raw_log_template_subject = getSuperAdminEmailTemplate('vendor-pass-update','subject');
						$raw_log_template_body = getSuperAdminEmailTemplate('vendor-pass-update','body');
						foreach($log_template_encoded_text_array as $array_key => $array_val){
							$raw_log_template_subject = str_replace($array_key, $array_val, $raw_log_template_subject);
							$raw_log_template_body = str_replace($array_key, $array_val, $raw_log_template_body);
						}
						sendVendorEmail($get_user_personal_details["email"], $raw_log_template_subject, $raw_log_template_body);
						// Email End
						// Successful reset clears the password-reset lockout counters.
						bc_handle_password_reset_attempt($_SESSION["admin-recovery-username"] ?? '', $reset_user_ip, 1, $reset_vendor_id, 'verify');
						unset($_SESSION["admin-recovery-username"]);
						unset($_SESSION["admin-recovery-code"]);

						//Password Changed Successfully
						$json_response_array = array("desc" => "Password Changed Successfully");
						$json_response_encode = json_encode($json_response_array,true);
					}else{
						//Password Not Match
						bc_handle_password_reset_attempt($_SESSION["admin-recovery-username"] ?? '', $reset_user_ip, 0, $reset_vendor_id, 'verify');
						$json_response_array = array("desc" => "Password Not Match");
						$json_response_encode = json_encode($json_response_array,true);
					}
				}else{
					//Invalid Recovery Code
					bc_handle_password_reset_attempt($_SESSION["admin-recovery-username"] ?? '', $reset_user_ip, 0, $reset_vendor_id, 'verify');
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
        exit();
    }

	if(isset($_POST["reset-recovery"])){
		unset($_SESSION["admin-recovery-username"]);
		unset($_SESSION["admin-recovery-code"]);
		header("Location: ".$_SERVER["REQUEST_URI"]);
        exit();
	}
?>
<?php
$GLOBALS['get_all_site_details'] = $get_all_super_admin_site_details ?? ($GLOBALS['get_all_site_details'] ?? null);
include_once(__DIR__ . "/../func/bc-plate-auth.php");
bc_plate_auth_head("Admin Recovery | " . ($get_all_super_admin_site_details["site_title"] ?? "Admin"), $get_all_super_admin_site_details ?? null);
bc_plate_auth_top(array(
    'icon'  => 'key',
    'title' => 'Account Recovery',
    'sub'   => 'Reset your administrator password.',
));
?>
                    <?php if ($reset_blocked_msg): ?>
                    <div class="pk-auth__alert pk-auth__alert--error">
                        <span><?php echo bc_plate_auth_icon('warn', 18); ?></span>
                        <span><strong>Password reset unavailable</strong><br><?php echo htmlspecialchars($reset_blocked_msg); ?></span>
                    </div>
                    <a href="../web/LockoutResolution.php" class="pk-auth__btn pk-auth__btn--danger">Unlock with Security PIN</a>
                    <p class="pk-auth__note" style="margin-top:12px;">
                        <a href="Login.php" class="pk-auth__link">Back to Login</a>
                    </p>
                    <?php endif; ?>

                    <form method="post" action="" <?php echo $reset_blocked_msg ? 'style="display:none"' : ''; ?>>
                        <?php if(!isset($_SESSION["admin-recovery-username"]) || empty($_SESSION["admin-recovery-username"])){ ?>
                            <div class="pk-auth__field">
                                <label class="pk-auth__label" for="email">Admin Email</label>
                                <input id="email" name="email" type="email" class="pk-auth__input" placeholder="admin@example.com" style="text-transform: lowercase;" required>
                                <p class="pk-auth__note" style="margin-top:8px; text-align:left;">A verification code will be sent to this email if it exists in our system.</p>
                            </div>

                            <button name="send-code" type="submit" class="pk-auth__btn">
                                Request Access Code
                            </button>
                        <?php } ?>

                        <?php if(isset($_SESSION["admin-recovery-username"]) && !empty($_SESSION["admin-recovery-username"])){ ?>
                            <div class="pk-auth__alert pk-auth__alert--ok">
                                <span><?php echo bc_plate_auth_icon('check', 18); ?></span>
                                <span>Verification code sent.</span>
                            </div>

                            <div class="pk-auth__field">
                                <label class="pk-auth__label" for="code">Verification Code</label>
                                <input id="code" name="code" type="text" class="pk-auth__input" placeholder="6-digit code" pattern="[0-9]{6}" required>
                            </div>

                            <div class="pk-auth__field">
                                <label class="pk-auth__label" for="new-password">New Admin Password</label>
                                <div style="position: relative;">
                                    <input id="new-password" name="pass" type="password" class="pk-auth__input" placeholder="Min 8 characters" required style="padding-right: 62px;">
                                    <button type="button" onclick="togglePasswordVisibility('new-password', this)"
                                            aria-label="Show password"
                                            style="position:absolute; right:8px; top:50%; transform:translateY(-50%);
                                                   border:0; background:transparent; color:var(--muted); font:inherit;
                                                   font-size:.8rem; font-weight:700; cursor:pointer;">Show</button>
                                </div>
                            </div>

                            <div class="pk-auth__field">
                                <label class="pk-auth__label" for="confirm-password">Confirm New Password</label>
                                <div style="position: relative;">
                                    <input id="confirm-password" name="confirm-pass" type="password" class="pk-auth__input" placeholder="Repeat password" required style="padding-right: 62px;">
                                    <button type="button" onclick="togglePasswordVisibility('confirm-password', this)"
                                            aria-label="Show password"
                                            style="position:absolute; right:8px; top:50%; transform:translateY(-50%);
                                                   border:0; background:transparent; color:var(--muted); font:inherit;
                                                   font-size:.8rem; font-weight:700; cursor:pointer;">Show</button>
                                </div>
                            </div>

                            <button name="verify-code" type="submit" class="pk-auth__btn">
                                Update Password
                            </button>

                            <button name="reset-recovery" type="submit" class="pk-auth__btn pk-auth__btn--ghost" style="margin-top:10px;">
                                Cancel &amp; Restart
                            </button>
                        <?php } ?>

                        <p class="pk-auth__note" style="margin-top:16px;">
                            <a href="Login.php" class="pk-auth__link">Back to Admin Sign In</a>
                        </p>
                    </form>
<?php if(isset($_SESSION["product_purchase_response"])){ ?>

  <script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.0.16/dist/sweetalert2.all.min.js"></script>

<script>
  Swal.fire ('Notification', '<?php echo htmlspecialchars($_SESSION["product_purchase_response"], ENT_QUOTES, 'UTF-8'); ?>', 'info') ;
          setTimeout(() => {
                fetch('/func/unset-product-response.php')
                    .then(response => response.text());
            }, 1000);
</script>
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
<?php bc_plate_auth_end(array('links' => array(array('href' => 'Login.php', 'label' => 'Back to sign in')))); ?>

<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
 bc_secure_session_start();
    include("../func/bc-spadmin-config.php");
    
    // Password Reset General Control — the Super Admin controls the global kill switch.
    $reset_blocked_msg = null;
    if (!bc_is_global_service_enabled('password_reset')) {
        $reset_blocked_msg = "Password reset is currently disabled by the platform administrator.";
    }
    
    // Section 38: platform Google Client ID for Google-verified Super Admin reset.
    $bc_google_client_id = trim((string)getSuperAdminOption('spadmin_google_client_id', ''));

    require_once __DIR__.'/../func/bc-recovery-inline.php';
    bc_recovery_post_guard();
    if(isset($_POST["send-code"])){
        if(time()-(int)($_SESSION['recovery_send_at']??0)<60){bc_recovery_reply(false,'Please wait a minute before requesting another code.');$_SESSION['product_purchase_response']='Please wait a minute before requesting another code.';header('Location: PasswordRecovery.php');exit;}
        $_SESSION['recovery_send_at']=time();
        if ($reset_blocked_msg) {
            bc_recovery_reply(false,$reset_blocked_msg);
            $_SESSION["product_purchase_response"] = $reset_blocked_msg;
            header("Location: ".$_SERVER["REQUEST_URI"]);
            exit();
        }
    	$email = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["email"]))));
    	if(!empty($email)){
    		$get_user_details = mysqli_query($connection_server, "SELECT * FROM sas_super_admin WHERE email='$email'");
    		if(mysqli_num_rows($get_user_details) == 1){
    			$get_user_personal_details = mysqli_fetch_array($get_user_details);
				$_SESSION["spadmin-recovery-username"] = $get_user_personal_details["email"];
				$_SESSION["spadmin-recovery-code"] = (string)random_int(100000,999999);
				// Email Beginning
				$log_template_encoded_text_array = array("{firstname}" => $get_user_personal_details["firstname"], "{lastname}" => $get_user_personal_details["lastname"], "{recovery_code}" => $_SESSION["spadmin-recovery-code"]);
				$raw_log_template_subject = getSuperAdminEmailTemplate('spadmin-account-recovery','subject');
				$raw_log_template_body = getSuperAdminEmailTemplate('spadmin-account-recovery','body');
				foreach($log_template_encoded_text_array as $array_key => $array_val){
					$raw_log_template_subject = str_replace($array_key, $array_val, $raw_log_template_subject);
					$raw_log_template_body = str_replace($array_key, $array_val, $raw_log_template_body);
				}
				$recovery_mail_sent=sendVendorEmail($get_user_personal_details["email"], $raw_log_template_subject, $raw_log_template_body);
				bc_recovery_mail_state('spadmin',0,(bool)$recovery_mail_sent);
				// Email End
				//Recovery Code Emailed Successfully
				$json_response_array = array("desc" => !empty($recovery_mail_sent)?"Recovery Code Emailed Successfully":"Could not send recovery code. Try again later.");
				$json_response_encode = json_encode($json_response_array,true);
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
    		if(empty($email)){
    			//Email Field Empty
    			$json_response_array = array("desc" => "Email Field Empty");
    			$json_response_encode = json_encode($json_response_array,true);
    		}
    	}
		
        $json_response_decode = json_decode($json_response_encode,true);
        bc_recovery_reply($json_response_decode['desc']==='Recovery Code Emailed Successfully',$json_response_decode['desc']);
        $_SESSION["product_purchase_response"] = $json_response_decode["desc"];
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit();
    }


    // Section 38: Google-verified reset. Google proves control of the Super Admin
    // mailbox, so the emailed code is waived and a new master password can be set.
    if(isset($_POST["google_token"])){
        if ($reset_blocked_msg) {
            bc_recovery_reply(false,$reset_blocked_msg);
            $_SESSION["product_purchase_response"] = $reset_blocked_msg;
            header("Location: ".$_SERVER["REQUEST_URI"]);
            exit();
        }
        $bc_g_payload = ($bc_google_client_id !== "" && function_exists('verifyGoogleToken')) ? verifyGoogleToken(trim((string)$_POST["google_token"])) : null;
        if ($bc_google_client_id === "") {
            $_SESSION["product_purchase_response"] = "Google sign-in is not configured for the Super Admin.";
        } elseif (!bc_google_payload_valid($bc_g_payload, $bc_google_client_id)) {
            $_SESSION["product_purchase_response"] = "Google Authentication Failed.";
        } else {
            $bc_g_email = mysqli_real_escape_string($connection_server, strtolower($bc_g_payload['email']));
            $bc_g_sub = mysqli_real_escape_string($connection_server, (string)$bc_g_payload['sub']);
            $bc_g_q = mysqli_query($connection_server, "SELECT * FROM sas_super_admin WHERE email='$bc_g_email' OR google_id='$bc_g_sub' LIMIT 1");
            $bc_g_admin = $bc_g_q ? mysqli_fetch_assoc($bc_g_q) : null;
            if ($bc_g_admin) {
                if (empty($bc_g_admin['google_id'])) {
                    mysqli_query($connection_server, "UPDATE sas_super_admin SET google_id='$bc_g_sub' WHERE id='".(int)$bc_g_admin["id"]."'");
                }
                $_SESSION["spadmin-recovery-username"] = $bc_g_admin["email"];
                $_SESSION["spadmin-recovery-google"] = 1;
                $_SESSION["product_purchase_response"] = "Google identity verified. Set your new password below.";
            } else {
                $_SESSION["product_purchase_response"] = "No Super Admin account found with this Google email.";
            }
        }
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
		$recovery_code = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["code"] ?? ""))));
		$bc_google_verified = !empty($_SESSION["spadmin-recovery-google"]);
    	if(!empty($pass) && !empty($confirm_pass) && ($bc_google_verified || (!empty($recovery_code) && is_numeric($recovery_code) && (strlen($recovery_code) == "6")))){
    		$get_user_details = mysqli_query($connection_server, "SELECT * FROM sas_super_admin WHERE email='".$_SESSION["spadmin-recovery-username"]."'");
    		if(mysqli_num_rows($get_user_details) == 1){
				if($bc_google_verified || bc_recovery_code_valid('spadmin',0,$recovery_code)){
					if($pass == $confirm_pass){
                        bc_recovery_consume('spadmin');
						// Stored as password_hash() (bcrypt). The login path still accepts legacy
						// raw-MD5 rows, so nobody is locked out mid-migration.
						$new_spadmin_pass_hash = mysqli_real_escape_string($connection_server, bc_hash_password($pass));
						$get_user_personal_details = mysqli_fetch_array($get_user_details);
						$get_user_details = mysqli_query($connection_server, "UPDATE sas_super_admin SET password='$new_spadmin_pass_hash' WHERE id='".$get_user_personal_details["id"]."' && email='".$get_user_personal_details["email"]."'");
                        if($get_user_details!==true){$_SESSION['product_purchase_response']='Password change could not be confirmed. Restart recovery before retrying.';header('Location: PasswordRecovery.php');exit;}
						// Email Beginning
						$log_template_encoded_text_array = array("{firstname}" => $get_user_personal_details["firstname"], "{lastname}" => $get_user_personal_details["lastname"]);
						$raw_log_template_subject = getSuperAdminEmailTemplate('spadmin-pass-update','subject');
						$raw_log_template_body = getSuperAdminEmailTemplate('spadmin-pass-update','body');
						foreach($log_template_encoded_text_array as $array_key => $array_val){
							$raw_log_template_subject = str_replace($array_key, $array_val, $raw_log_template_subject);
							$raw_log_template_body = str_replace($array_key, $array_val, $raw_log_template_body);
						}
						sendVendorEmail($get_user_personal_details["email"], $raw_log_template_subject, $raw_log_template_body);
						// Email End
						unset($_SESSION["spadmin-recovery-username"]);
						unset($_SESSION["spadmin-recovery-code"]);
		unset($_SESSION["spadmin-recovery-google"]);

						//Password Changed Successfully
						$json_response_array = array("desc" => "Password Changed Successfully");
						$json_response_encode = json_encode($json_response_array,true);
					}else{
						//Password Not Match
						$json_response_array = array("desc" => "Password Not Match");
						$json_response_encode = json_encode($json_response_array,true);
					}
				}else{
					//Invalid Recovery Code
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
		unset($_SESSION["spadmin-recovery-username"]);
		unset($_SESSION["spadmin-recovery-code"]);
		unset($_SESSION["spadmin-recovery-google"]);
		header("Location: ".$_SERVER["REQUEST_URI"]);
        exit();
	}
?>
<?php
$GLOBALS['get_all_site_details'] = $get_all_super_admin_site_details ?? ($GLOBALS['get_all_site_details'] ?? null);
include_once(__DIR__ . "/../func/bc-plate-auth.php");
bc_plate_auth_head("Super Admin Recovery", $get_all_super_admin_site_details ?? null);
bc_plate_auth_top(array(
    'icon'  => 'key',
    'title' => 'Account Recovery',
    'sub'   => 'Master access restoration.',
));
?>
                    <?php if ($reset_blocked_msg): ?>
                    <div class="pk-auth__alert pk-auth__alert--error">
                        <span><?php echo bc_plate_auth_icon('warn', 18); ?></span>
                        <span><strong>Password reset unavailable</strong><br><?php echo htmlspecialchars($reset_blocked_msg); ?></span>
                    </div>
                    <a href="Login.php" class="pk-auth__btn">Back to Super Admin Login</a>
                    <?php endif; ?>

                    <?php if (!empty($bc_google_client_id) && empty($_SESSION["spadmin-recovery-username"])): ?>
                    <script src="https://accounts.google.com/gsi/client" async defer></script>
                    <div id="g_id_onload" data-client_id="<?php echo htmlspecialchars((string)$bc_google_client_id, ENT_QUOTES, 'UTF-8'); ?>" data-callback="bcGoogleLogin" data-auto_prompt="false"></div>
                    <div class="g_id_signin" data-type="standard" data-shape="rectangular" data-theme="outline" data-text="signin_with" data-size="large" data-logo_alignment="left" style="margin-bottom:14px;"></div>
                    <form id="google-login-form" method="post" action="" style="display:none;"><input type="hidden" name="google_token" id="google_token"></form>
                    <script>function bcGoogleLogin(r){document.getElementById('google_token').value=r.credential;document.getElementById('google-login-form').submit();}</script>
                    <p class="pk-auth__note" style="margin:0 0 16px;">&mdash; or continue with your password &mdash;</p>
                    <?php endif; ?>
                    <form data-recovery-form method="post" action="" <?php echo $reset_blocked_msg ? 'style="display:none"' : ''; ?>>
                        <?php echo bc_csrf_field(); ?>
                        <?php if(!isset($_SESSION["spadmin-recovery-username"]) || empty($_SESSION["spadmin-recovery-username"])){ ?>
                            <div class="pk-auth__field">
                                <label class="pk-auth__label" for="email">Super Email</label>
                                <input id="email" name="email" type="email" class="pk-auth__input" placeholder="super@system" style="text-transform: lowercase;" required>
                                <p class="pk-auth__note" style="margin-top:8px; text-align:left;">Authorized credentials required.</p>
                            </div>

                            <button name="send-code" type="submit" class="pk-auth__btn">
                                ISSUE RECOVERY CODE
                            </button>
                        <?php } ?>

                        <?php if(isset($_SESSION["spadmin-recovery-username"]) && !empty($_SESSION["spadmin-recovery-username"])){ ?>
                            <div class="pk-auth__alert pk-auth__alert--info">
                                <span><?php echo bc_plate_auth_icon('alert', 18); ?></span>
                                <span><?php echo !empty($_SESSION["spadmin-recovery-google"]) ? 'Google identity verified &mdash; set a new password below.' : 'Authorized code sent to email.'; ?></span>
                            </div>

                            <?php if (!empty($_SESSION["spadmin-recovery-google"])): ?>
                            <input type="hidden" name="code" value="">
                            <?php else: ?>
                            <div class="pk-auth__field">
                                <label class="pk-auth__label" for="code">Recovery Code</label>
                                <input id="code" name="code" type="text" class="pk-auth__input" placeholder="6-digit code" pattern="[0-9]{6}" required>
                            </div>
                            <?php endif; ?>

                            <div class="pk-auth__field">
                                <label class="pk-auth__label" for="new-password">New Master Password</label>
                                <div style="position: relative;">
                                    <input id="new-password" name="pass" type="password" class="pk-auth__input" placeholder="Required" required style="padding-right: 62px;">
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
                                    <input id="confirm-password" name="confirm-pass" type="password" class="pk-auth__input" placeholder="Repeat" required style="padding-right: 62px;">
                                    <button type="button" onclick="togglePasswordVisibility('confirm-password', this)"
                                            aria-label="Show password"
                                            style="position:absolute; right:8px; top:50%; transform:translateY(-50%);
                                                   border:0; background:transparent; color:var(--muted); font:inherit;
                                                   font-size:.8rem; font-weight:700; cursor:pointer;">Show</button>
                                </div>
                            </div>

                            <button name="verify-code" type="submit" class="pk-auth__btn">
                                OVERWRITE PASSWORD
                            </button>

                            <button name="reset-recovery" type="submit" formnovalidate class="pk-auth__btn pk-auth__btn--ghost" style="margin-top:10px;">
                                Cancel &amp; Restart
                            </button>
                        <?php } ?>

                        <p class="pk-auth__note" style="margin-top:16px;">
                            <a href="Login.php" class="pk-auth__link">Back to Super Admin Sign In</a>
                        </p>
                    </form>
<?php if(isset($_SESSION["product_purchase_response"])){ ?>

  <script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.0.16/dist/sweetalert2.all.min.js"></script>

<script>
  Swal.fire ('System Alert', '<?php echo $_SESSION["product_purchase_response"]; ?>', 'warning') ;
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

<script src="../asset/bc-recovery-inline.js" defer></script>

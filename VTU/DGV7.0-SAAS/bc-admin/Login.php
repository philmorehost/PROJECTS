<?php session_start();
	
    include("../func/bc-admin-config.php");
	if(isset($get_logged_admin_details["email"]) && !empty($get_logged_admin_details["email"]) && ($get_logged_admin_details["status"] == 1)){
    	$redirecturl = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["redirecturl"])));
		if(!empty(trim($redirecturl)) && file_exists("..".$redirecturl)){
			header("Location: ".$redirecturl);
			exit;
		}else{
			header("Location: /bc-admin/Dashboard.php");
			exit;
		}
        exit();
	}

    // Finish a two-step login: verify the emailed code, then start the admin session.
    if(isset($_POST["verify-login-otp"])){
        $pending = $_SESSION['bc_admin_login_otp'] ?? null;
        $code = preg_replace('/\D+/', '', (string)($_POST["otp_code"] ?? ''));
        $fail = '';
        if (!is_array($pending) || empty($pending['email']) || empty($pending['code_hash'])) {
            $fail = "Your sign-in attempt expired. Please enter your email and password again.";
        } elseif (($pending['expires'] ?? 0) < time()) {
            $fail = "That code has expired. Please sign in again.";
        } elseif (($pending['tries'] ?? 0) >= 5) {
            $fail = "Too many incorrect codes. Please sign in again.";
        } elseif (!password_verify($code, $pending['code_hash'])) {
            $_SESSION['bc_admin_login_otp']['tries'] = (int)($pending['tries'] ?? 0) + 1;
            $fail = "Incorrect code. Please check your email and try again.";
        }
        if ($fail !== '') {
            if (!is_array($pending) || ($pending['tries'] ?? 0) >= 5 || ($pending['expires'] ?? 0) < time()) {
                unset($_SESSION['bc_admin_login_otp']);
            }
            $_SESSION["product_purchase_response"] = $fail;
            header("Location: ".$_SERVER["REQUEST_URI"]);
            exit();
        }
        $pending_email = mysqli_real_escape_string($connection_server, $pending['email']);
        $q_admin = mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='".(int)$pending['vendor_id']."' && email='$pending_email' LIMIT 1");
        $admin_detail = $q_admin ? mysqli_fetch_assoc($q_admin) : null;
        if (!$admin_detail || $admin_detail["status"] != 1) {
            unset($_SESSION['bc_admin_login_otp']);
            $_SESSION["product_purchase_response"] = "Account Locked, Contact Admin";
            header("Location: ".$_SERVER["REQUEST_URI"]);
            exit();
        }
        unset($_SESSION['bc_admin_login_otp']);
        recordLoginAttempt($admin_detail["email"], $_SERVER['REMOTE_ADDR'], 1, (int)$admin_detail["id"]);
        $_SESSION["admin_session"] = strtolower($admin_detail["email"]);
        // Email Beginning
        $log_template_encoded_text_array = array("{firstname}" => $admin_detail["firstname"], "{lastname}" => $admin_detail["lastname"], "{email}" => $admin_detail["email"], "{ip_address}" => $_SERVER["REMOTE_ADDR"]);
        $raw_log_template_subject = getSuperAdminEmailTemplate('vendor-log','subject');
        $raw_log_template_body = getSuperAdminEmailTemplate('vendor-log','body');
        foreach($log_template_encoded_text_array as $array_key => $array_val){
            $raw_log_template_subject = str_replace($array_key, $array_val, $raw_log_template_subject);
            $raw_log_template_body = str_replace($array_key, $array_val, $raw_log_template_body);
        }
        sendVendorEmail($admin_detail["email"], $raw_log_template_subject, $raw_log_template_body);
        // Email End
        $_SESSION["product_purchase_response"] = "Welcome Back, ".ucwords($admin_detail["firstname"]);
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit();
    }

    if(isset($_POST["cancel-login-otp"])){
        unset($_SESSION['bc_admin_login_otp']);
        $_SESSION["product_purchase_response"] = "Sign-in cancelled.";
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit();
    }

    if(isset($_POST["login"])){
    	$email = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["email"]))));
    	$pass = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["pass"])));
    	if(!empty($email) && !empty($pass)){
            $vendor_id = resolveVendorID();
            $ip = $_SERVER['REMOTE_ADDR'];

            // Anti-BruteForce Check
            if ($msg = isIPBlocked($ip, $vendor_id)) {
                $_SESSION["product_purchase_response"] = "Access Denied: $msg";
                header("Location: ".$_SERVER["REQUEST_URI"]);
                exit();
            }
            if ($msg = isAccountLocked($email, $vendor_id)) {
                $_SESSION["product_purchase_response"] = "Account Locked: $msg";
                header("Location: ".$_SERVER["REQUEST_URI"]);
                exit();
            }

		$get_admin_details = mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id' && email='$email'");
			if(mysqli_num_rows($get_admin_details) == 1){
				// Verify in PHP so both password_hash() (bcrypt) and legacy raw-MD5 rows are accepted; a legacy
				// row is upgraded to bcrypt on the next successful login.
				$admin_stored_row = mysqli_fetch_assoc($get_admin_details);
				$vendor_pass_ok = ($admin_stored_row && bc_verify_password($pass, $admin_stored_row["password"]));
				if ($vendor_pass_ok && bc_password_is_legacy($admin_stored_row["password"])) {
					mysqli_query($connection_server, "UPDATE sas_vendors SET password='" . mysqli_real_escape_string($connection_server, bc_hash_password($pass)) . "' WHERE id='" . (int)$admin_stored_row["id"] . "'");
				}
				// Always a real result set, so the num_rows() gates below keep behaving exactly as before:
				// one row when the password matched, none when it did not.
				$check_admin_password_details = mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE email='$email' && " . ($vendor_pass_ok ? "id='" . (int)$admin_stored_row["id"] . "'" : "1=0"));
				if(mysqli_num_rows($check_admin_password_details) == 1){
					while($admin_detail = mysqli_fetch_assoc($check_admin_password_details)){
						if($admin_detail["status"] == 1){
                            recordLoginAttempt($email, $ip, 1, $vendor_id);

                            // Second factor: when login OTP is required for this vendor, do NOT start the
                            // session yet. Email a one-time code and hand over to the verification step.
                            // (A vendor may switch this off for their own account; see AccountSettings.)
                            if (bc_admin_login_otp_required($admin_detail)) {
                                $login_otp = generateOTP();
                                $_SESSION['bc_admin_login_otp'] = array(
                                    'email' => strtolower($admin_detail["email"]),
                                    'vendor_id' => (int)$admin_detail["id"],
                                    'code_hash' => password_hash($login_otp, PASSWORD_DEFAULT),
                                    'expires' => time() + 600,
                                    'tries' => 0,
                                    'sent' => time(),
                                );
                                sendVendorEmail(
                                    $admin_detail["email"],
                                    "Your login security code",
                                    "<p>Your one-time login code is <b style=\"font-size:20px;letter-spacing:3px;\">" . $login_otp . "</b></p><p>It expires in 10 minutes. If you did not try to sign in, change your password immediately.</p>"
                                );
                                $_SESSION["product_purchase_response"] = "Security code sent to " . $admin_detail["email"] . ". Enter the 6-digit code to finish signing in.";
                                header("Location: ".$_SERVER["REQUEST_URI"]);
                                exit();
                            }

							$_SESSION["admin_session"] = strtolower($admin_detail["email"]);
							// Email Beginning
							$log_template_encoded_text_array = array("{firstname}" => $admin_detail["firstname"], "{lastname}" => $admin_detail["lastname"], "{email}" => $admin_detail["email"], "{ip_address}" => $_SERVER["REMOTE_ADDR"]);
							$raw_log_template_subject = getSuperAdminEmailTemplate('vendor-log','subject');
							$raw_log_template_body = getSuperAdminEmailTemplate('vendor-log','body');
							foreach($log_template_encoded_text_array as $array_key => $array_val){
								$raw_log_template_subject = str_replace($array_key, $array_val, $raw_log_template_subject);
								$raw_log_template_body = str_replace($array_key, $array_val, $raw_log_template_body);
							}
							sendVendorEmail($admin_detail["email"], $raw_log_template_subject, $raw_log_template_body);
							// Email End
							//Welcome Back Message
							$json_response_array = array("desc" => "Welcome Back, ".ucwords($admin_detail["firstname"]));
							$json_response_encode = json_encode($json_response_array,true);
						}else{
                            recordLoginAttempt($email, $ip, 0, $vendor_id);
							//Account Locked, Contact Admin
							$json_response_array = array("desc" => "Account Locked, Contact Admin");
							$json_response_encode = json_encode($json_response_array,true);
						}
					}
				}else{
					if(mysqli_num_rows($check_admin_password_details) < 1){
                        recordLoginAttempt($email, $ip, 0, $vendor_id);
						//Incorrect Password
						$json_response_array = array("desc" => "Incorrect Password");
						$json_response_encode = json_encode($json_response_array,true);
					}
				}
			}else{
                recordLoginAttempt($email, $ip, 0, $vendor_id);
				if(mysqli_num_rows($get_admin_details) > 1){
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
    		}else{
    			if(empty($pass)){
    				//Password Field Empty
    				$json_response_array = array("desc" => "Password Field Empty");
    				$json_response_encode = json_encode($json_response_array,true);
    			}
    		}
    	}
		
        $json_response_decode = json_decode($json_response_encode,true);
        $_SESSION["product_purchase_response"] = $json_response_decode["desc"];
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit();
    }
?>
<?php
$GLOBALS['get_all_site_details'] = $get_all_super_admin_site_details ?? ($GLOBALS['get_all_site_details'] ?? null);
include_once(__DIR__ . "/../func/bc-plate-auth.php");
bc_plate_auth_head("Admin Login | " . ($get_all_super_admin_site_details["site_title"] ?? "System"), $get_all_super_admin_site_details ?? null);
bc_plate_auth_top(array(
    'icon'  => 'lock',
    'title' => !empty($_SESSION['bc_admin_login_otp']['email']) ? 'Two-Step Verification' : 'Admin Login',
    'sub'   => !empty($_SESSION['bc_admin_login_otp']['email'])
        ? 'Enter the 6-digit code we emailed to <b>' . htmlspecialchars($_SESSION['bc_admin_login_otp']['email']) . '</b>. It expires in 10 minutes.'
        : 'Enter your admin credentials to continue.',
));
?>
                    <?php if (!empty($_SESSION['bc_admin_login_otp']['email'])): ?>
                    <form method="post" action="">
                        <div class="pk-auth__field">
                            <label class="pk-auth__label" for="otp_code">Login Code</label>
                            <input id="otp_code" name="otp_code" type="text" inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="one-time-code" class="pk-auth__input pk-auth__otp" placeholder="******" required autofocus>
                        </div>

                        <button name="verify-login-otp" type="submit" class="pk-auth__btn">
                            VERIFY &amp; CONTINUE
                        </button>

                        <button name="cancel-login-otp" type="submit" formnovalidate class="pk-auth__btn pk-auth__btn--ghost" style="margin-top:10px;">Back to sign in</button>

                        <p class="pk-auth__note" style="margin-top:14px;">Didn't get it? Check your spam folder, or sign in again to send a new code.</p>
                    </form>
                    <?php else: ?>
                    <form method="post" action="">
                        <div class="pk-auth__field">
                            <label class="pk-auth__label" for="email">Email Address</label>
                            <input id="email" name="email" type="email" class="pk-auth__input" placeholder="admin@example.com" style="text-transform: lowercase;" required>
                        </div>

                        <div class="pk-auth__field">
                            <div style="display:flex; justify-content:space-between; align-items:baseline;">
                                <label class="pk-auth__label" for="password-field">Password</label>
                                <a href="PasswordRecovery.php" class="pk-auth__link">Forgot?</a>
                            </div>
                            <div style="position: relative;">
                                <input id="password-field" name="pass" type="password" class="pk-auth__input" placeholder="Enter password" required style="padding-right: 62px;">
                                <button type="button" onclick="togglePasswordVisibility('password-field', this)"
                                        aria-label="Show password"
                                        style="position:absolute; right:8px; top:50%; transform:translateY(-50%);
                                               border:0; background:transparent; color:var(--muted); font:inherit;
                                               font-size:.8rem; font-weight:700; cursor:pointer;">Show</button>
                            </div>
                        </div>

                        <button name="login" type="submit" class="pk-auth__btn">
                            ACCESS DASHBOARD
                        </button>

                        <p class="pk-auth__note" style="margin-top:16px;">
                            Need help? <a href="/" class="pk-auth__link">Back to Website</a>
                        </p>
                    </form>
                    <?php endif; ?>
<?php if(isset($_SESSION["product_purchase_response"])){ ?>

  <script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.0.16/dist/sweetalert2.all.min.js"></script>

<script>
  const msg = '<?php echo htmlspecialchars($_SESSION["product_purchase_response"], ENT_QUOTES, 'UTF-8'); ?>';
  const isBlocked = msg.includes('Access Denied') || msg.includes('Account Locked');

  if (isBlocked) {
    Swal.fire({
      title: 'Security Notification',
      text: msg,
      icon: 'warning',
      showDenyButton: true,
      showCancelButton: true,
      confirmButtonText: 'Use Security PIN',
      denyButtonText: 'Request Manual Unblock',
      cancelButtonText: 'Close',
      confirmButtonColor: '#0d6efd',
      denyButtonColor: '#6c757d'
    }).then((result) => {
      if (result.isConfirmed) {
        window.location.href = '../web/LockoutResolution.php';
      } else if (result.isDenied) {
        Swal.fire({
          title: 'Unblock Reason',
          input: 'text',
          inputPlaceholder: 'Briefly explain why you should be unblocked...',
          showCancelButton: true,
          confirmButtonText: 'Submit Request',
          showLoaderOnConfirm: true,
          preConfirm: (reason) => {
            return fetch('../web/ajax-unblock-request.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ username: <?php echo json_encode($_POST['email'] ?? '', JSON_HEX_QUOT|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_TAG); ?>, reason: reason })
            })
            .then(response => response.json())
            .then(data => {
              if (data.status === 'error') throw new Error(data.message);
              return data;
            })
            .catch(error => {
              Swal.showValidationMessage(`Request failed: ${error}`);
            });
          },
          allowOutsideClick: () => !Swal.isLoading()
        }).then((result) => {
          if (result.isConfirmed) {
            Swal.fire('Sent!', result.value.message, 'success');
          }
        });
      }
    });
  } else {
    Swal.fire('Notification', msg, 'info');
  }

  setTimeout(() => {
    fetch('/func/unset-product-response.php').then(response => response.text());
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
<?php bc_plate_auth_end(array('links' => array(array('href' => 'PasswordRecovery.php', 'label' => 'Forgot password'), array('href' => '/', 'label' => 'Back to Website')))); ?>

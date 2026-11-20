<?php session_start();
	
    include("../func/bc-spadmin-config.php");
    if(isset($get_logged_spadmin_details["email"]) && !empty($get_logged_spadmin_details["email"]) && ($get_logged_spadmin_details["status"] == 1)){
    	$redirecturl = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["redirecturl"])));
		if(!empty(trim($redirecturl)) && file_exists("..".$redirecturl)){
			header("Location: ".$redirecturl);
		}else{
			header("Location: /bc-spadmin/Dashboard.php");
		}
        exit();
	}


    // Section 38: Google sign-in for the Super Admin. Uses the platform Google Client
    // ID (Super Admin - Account Settings - Security). Google proves the mailbox, so
    // no master password is needed; brute-force lockouts still apply.
    if(isset($_POST["google_token"])){
        $bc_g_client_id = trim((string)getSuperAdminOption('spadmin_google_client_id', ''));
        $bc_g_payload = ($bc_g_client_id !== "" && function_exists('verifyGoogleToken')) ? verifyGoogleToken(trim((string)$_POST["google_token"])) : null;
        $bc_g_fail = "";
        if ($bc_g_client_id === "") {
            $bc_g_fail = "Google sign-in is not configured for the Super Admin.";
        } elseif (!bc_google_payload_valid($bc_g_payload, $bc_g_client_id)) {
            $bc_g_fail = "Google Authentication Failed.";
        } else {
            $bc_g_email = mysqli_real_escape_string($connection_server, strtolower($bc_g_payload['email']));
            $bc_g_sub = mysqli_real_escape_string($connection_server, (string)$bc_g_payload['sub']);
            $bc_g_ip = $_SERVER['REMOTE_ADDR'];
            if ($msg = isIPBlocked($bc_g_ip, 0)) {
                $bc_g_fail = "Access Denied: $msg";
            } elseif ($msg = isAccountLocked($bc_g_payload['email'], 0)) {
                $bc_g_fail = "Account Locked: $msg";
            } else {
                $bc_g_q = mysqli_query($connection_server, "SELECT * FROM sas_super_admin WHERE email='$bc_g_email' OR google_id='$bc_g_sub' LIMIT 1");
                $bc_g_admin = $bc_g_q ? mysqli_fetch_assoc($bc_g_q) : null;
                if (!$bc_g_admin) {
                    recordLoginAttempt($bc_g_payload['email'], $bc_g_ip, 0, 0);
                    $bc_g_fail = "No Super Admin account found with this Google email.";
                } elseif ($bc_g_admin["status"] != 1) {
                    recordLoginAttempt($bc_g_payload['email'], $bc_g_ip, 0, 0);
                    $bc_g_fail = "Account Locked, Contact Admin";
                } else {
                    if (empty($bc_g_admin['google_id'])) {
                        mysqli_query($connection_server, "UPDATE sas_super_admin SET google_id='$bc_g_sub' WHERE id='".(int)$bc_g_admin["id"]."'");
                    }
                    recordLoginAttempt($bc_g_admin["email"], $bc_g_ip, 1, 0);
                    $_SESSION["spadmin_session"] = strtolower($bc_g_admin["email"]);
                    if (function_exists('bc_auth_epoch_stamp')) bc_auth_epoch_stamp(); // session epoch
                    $_SESSION["product_purchase_response"] = "Welcome Back, ".ucwords($bc_g_admin["firstname"]);
                    header("Location: ".$_SERVER["REQUEST_URI"]);
                    exit();
                }
            }
        }
        $_SESSION["product_purchase_response"] = $bc_g_fail;
        header("Location: ".$_SERVER["REQUEST_URI"]);
        exit();
    }

    if(isset($_POST["login"])){
    	$email = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["email"]))));
    	$pass = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["pass"])));
    	if(!empty($email) && !empty($pass)){
            $ip = $_SERVER['REMOTE_ADDR'];

            // Anti-BruteForce Check
            if ($msg = isIPBlocked($ip, 0)) {
                $_SESSION["product_purchase_response"] = "Access Denied: $msg";
                header("Location: ".$_SERVER["REQUEST_URI"]);
                exit();
            }
            if ($msg = isAccountLocked($email, 0)) {
                $_SESSION["product_purchase_response"] = "Account Locked: $msg";
                header("Location: ".$_SERVER["REQUEST_URI"]);
                exit();
            }

    		$get_admin_details = mysqli_query($connection_server, "SELECT * FROM sas_super_admin WHERE email='$email'");
			if(mysqli_num_rows($get_admin_details) == 1){
				// Verify in PHP so both password_hash() (bcrypt) and legacy raw-MD5 rows are accepted; a legacy
				// row is upgraded to bcrypt on the next successful login.
				$admin_stored_row = mysqli_fetch_assoc($get_admin_details);
				$spadmin_pass_ok = ($admin_stored_row && bc_verify_password($pass, $admin_stored_row["password"]));
				if ($spadmin_pass_ok && bc_password_is_legacy($admin_stored_row["password"])) {
					mysqli_query($connection_server, "UPDATE sas_super_admin SET password='" . mysqli_real_escape_string($connection_server, bc_hash_password($pass)) . "' WHERE id='" . (int)$admin_stored_row["id"] . "'");
				}
				// Always a real result set, so the num_rows() gates below keep behaving exactly as before:
				// one row when the password matched, none when it did not.
				$check_admin_password_details = mysqli_query($connection_server, "SELECT * FROM sas_super_admin WHERE email='$email' && " . ($spadmin_pass_ok ? "id='" . (int)$admin_stored_row["id"] . "'" : "1=0"));
				if(mysqli_num_rows($check_admin_password_details) == 1){
					while($admin_detail = mysqli_fetch_assoc($check_admin_password_details)){
						if($admin_detail["status"] == 1){
                            recordLoginAttempt($email, $ip, 1, 0);

							$_SESSION["spadmin_session"] = strtolower($admin_detail["email"]);
							if (function_exists('bc_auth_epoch_stamp')) bc_auth_epoch_stamp(); // §33 session epoch
							//Welcome Back Message
							$json_response_array = array("desc" => "Welcome Back, ".ucwords($admin_detail["firstname"]));
							$json_response_encode = json_encode($json_response_array,true);
						}else{
                            recordLoginAttempt($email, $ip, 0, 0);
							//Account Locked, Contact Admin
							$json_response_array = array("desc" => "Account Locked, Contact Admin");
							$json_response_encode = json_encode($json_response_array,true);
						}
					}
				}else{
					if(mysqli_num_rows($check_admin_password_details) < 1){
                        recordLoginAttempt($email, $ip, 0, 0);
						//Incorrect Password
						$json_response_array = array("desc" => "Incorrect Password");
						$json_response_encode = json_encode($json_response_array,true);
					}
				}
			}else{
                recordLoginAttempt($email, $ip, 0, 0);
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
bc_plate_auth_head("Super Admin Login", $get_all_super_admin_site_details ?? null);
bc_plate_auth_top(array(
    'icon'  => 'shield',
    'title' => 'Super Admin',
    'sub'   => 'Authenticated access only. Master control panel for the entire platform.',
));
?>
                    <?php if (!empty(getSuperAdminOption('spadmin_google_client_id', ''))): ?>
                    <script src="https://accounts.google.com/gsi/client" async defer></script>
                    <div id="g_id_onload" data-client_id="<?php echo htmlspecialchars((string)getSuperAdminOption('spadmin_google_client_id', ''), ENT_QUOTES, 'UTF-8'); ?>" data-callback="bcGoogleLogin" data-auto_prompt="false"></div>
                    <div class="g_id_signin" data-type="standard" data-shape="rectangular" data-theme="outline" data-text="signin_with" data-size="large" data-logo_alignment="left" style="margin-bottom:14px;"></div>
                    <form id="google-login-form" method="post" action="" style="display:none;"><input type="hidden" name="google_token" id="google_token"></form>
                    <script>function bcGoogleLogin(r){document.getElementById('google_token').value=r.credential;document.getElementById('google-login-form').submit();}</script>
                    <p class="pk-auth__note" style="margin:0 0 16px;">&mdash; or continue with your password &mdash;</p>
                    <?php endif; ?>
                    <form method="post" action="">
                        <div class="pk-auth__field">
                            <label class="pk-auth__label" for="email">Super Email</label>
                            <input id="email" name="email" type="email" class="pk-auth__input" placeholder="super-admin@system" style="text-transform: lowercase;" required>
                        </div>

                        <div class="pk-auth__field">
                            <div style="display:flex; justify-content:space-between; align-items:baseline;">
                                <label class="pk-auth__label" for="password-field">Master Password</label>
                                <a href="PasswordRecovery.php" class="pk-auth__link">Forgot?</a>
                            </div>
                            <div style="position: relative;">
                                <input id="password-field" name="pass" type="password" class="pk-auth__input" placeholder="••••••••" required style="padding-right: 62px;">
                                <button type="button" onclick="togglePasswordVisibility('password-field', this)"
                                        aria-label="Show password"
                                        style="position:absolute; right:8px; top:50%; transform:translateY(-50%);
                                               border:0; background:transparent; color:var(--muted); font:inherit;
                                               font-size:.8rem; font-weight:700; cursor:pointer;">Show</button>
                            </div>
                        </div>

                        <button name="login" type="submit" class="pk-auth__btn">
                            AUTHORIZE SESSION
                        </button>

                        <p class="pk-auth__note" style="margin-top:16px;">
                            <a href="/" class="pk-auth__link">Return to Platform</a>
                        </p>
                    </form>
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
      confirmButtonColor: '#000',
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
<?php bc_plate_auth_end(array('links' => array(array('href' => 'PasswordRecovery.php', 'label' => 'Forgot password'), array('href' => '/', 'label' => 'Return to Platform')))); ?>

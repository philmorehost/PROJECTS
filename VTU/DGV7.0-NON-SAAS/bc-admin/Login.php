<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
 bc_secure_session_start();
	
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


    // Section 38: Google sign-in for the vendor admin. Uses THIS vendor's Google Client
    // ID (Account Settings - Security). Google proves the mailbox, so no password is needed.
    if(isset($_POST["google_token"])){
        $bc_g_vendor_id = resolveVendorID();
        $bc_g_vendor_q = mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='".(int)$bc_g_vendor_id."' LIMIT 1");
        $bc_g_vendor = $bc_g_vendor_q ? mysqli_fetch_assoc($bc_g_vendor_q) : null;
        $bc_g_client_id = trim((string)($bc_g_vendor["google_client_id"] ?? ""));
        $bc_g_payload = ($bc_g_client_id !== "" && function_exists('verifyGoogleToken')) ? verifyGoogleToken(trim((string)$_POST["google_token"])) : null;
        $bc_g_fail = "";
        $bc_g_nonce_ok=bc_google_signin_consume('vendor:'.(int)resolveVendorID(),$bc_g_payload);
        if ($bc_g_client_id === "") {
            $bc_g_fail = "Google sign-in is not configured for this admin account.";
        } elseif (!$bc_g_nonce_ok || !bc_google_payload_valid($bc_g_payload, $bc_g_client_id)) {
            $bc_g_fail = "Google Authentication Failed.";
        } else {
            $bc_g_email = mysqli_real_escape_string($connection_server, strtolower($bc_g_payload['email']));
            $bc_g_sub = mysqli_real_escape_string($connection_server, (string)$bc_g_payload['sub']);
            $bc_g_ip = $_SERVER['REMOTE_ADDR'];
            if ($msg = isIPBlocked($bc_g_ip, $bc_g_vendor_id)) {
                $bc_g_fail = "Access Denied: $msg";
            } elseif ($msg = isAccountLocked($bc_g_payload['email'], $bc_g_vendor_id)) {
                $bc_g_fail = "Account Locked: $msg";
            } else {
                $bc_g_q = mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='".(int)$bc_g_vendor_id."' && (email='$bc_g_email' OR google_id='$bc_g_sub') LIMIT 1");
                $bc_g_admin = $bc_g_q ? mysqli_fetch_assoc($bc_g_q) : null;
                if (!$bc_g_admin) {
                    recordLoginAttempt($bc_g_payload['email'], $bc_g_ip, 0, $bc_g_vendor_id);
                    $bc_g_fail = "No admin account found with this Google email.";
                } elseif ($bc_g_admin["status"] != 1) {
                    recordLoginAttempt($bc_g_payload['email'], $bc_g_ip, 0, $bc_g_vendor_id);
                    $bc_g_fail = "Account Locked, Contact Admin";
                } else {
                    if (empty($bc_g_admin['google_id'])) {
                        mysqli_query($connection_server, "UPDATE sas_vendors SET google_id='$bc_g_sub' WHERE id='".(int)$bc_g_admin["id"]."'");
                        $bc_g_admin = mysqli_fetch_assoc(mysqli_query($connection_server,"SELECT * FROM sas_vendors WHERE id='".(int)$bc_g_admin["id"]."' LIMIT 1"));
                    }
                    recordLoginAttempt($bc_g_admin["email"], $bc_g_ip, 1, $bc_g_vendor_id);
                    $_SESSION["admin_session"] = strtolower($bc_g_admin["email"]);
            bc_browser_binding_stamp('vendor',$bc_g_admin);
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


							$_SESSION["admin_session"] = strtolower($admin_detail["email"]);
            bc_browser_binding_stamp('vendor',$admin_detail);
							if (function_exists('bc_auth_epoch_stamp')) bc_auth_epoch_stamp(); // §33 session epoch
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
    'title' => 'Admin Login',
    'sub'   => 'Enter your admin credentials to continue.',
));
?>
                    <?php if (!empty($select_vendor_table['google_client_id'])): ?>
                    <script src="https://accounts.google.com/gsi/client" async defer></script>
                    <div id="g_id_onload" data-client_id="<?php echo htmlspecialchars((string)$select_vendor_table['google_client_id'], ENT_QUOTES, 'UTF-8'); ?>" data-nonce="<?php echo htmlspecialchars(bc_google_signin_nonce('vendor:'.(int)resolveVendorID()),ENT_QUOTES,'UTF-8'); ?>" data-callback="bcGoogleLogin" data-auto_prompt="false"></div>
                    <div class="g_id_signin" data-type="standard" data-shape="rectangular" data-theme="outline" data-text="signin_with" data-size="large" data-logo_alignment="left" style="margin-bottom:14px;"></div>
                    <form id="google-login-form" method="post" action="" style="display:none;"><input type="hidden" name="google_token" id="google_token"></form>
                    <script>function bcGoogleLogin(r){document.getElementById('google_token').value=r.credential;document.getElementById('google-login-form').submit();}</script>
                    <p class="pk-auth__note" style="margin:0 0 16px;">&mdash; or continue with your password &mdash;</p>
                    <?php endif; ?>
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
              body: JSON.stringify({ username: '<?php echo $_POST['email'] ?? ''; ?>', reason: reason })
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

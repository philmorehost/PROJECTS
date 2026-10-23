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
<!DOCTYPE html>
<head>
    <title>Admin Login | <?php echo $get_all_super_admin_site_details["site_title"] ?? "System"; ?></title>
    <meta charset="UTF-8" />
    <meta name="description" content="<?php echo substr($get_all_super_admin_site_details["site_desc"] ?? "Admin Portal", 0, 160); ?>" />
    <meta http-equiv="Content-Type" content="text/html; " />
    <meta name="theme-color" content="black" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
    <link rel="stylesheet" href="/cssfile/bc-style.css">
    <meta name="author" content="Philmore Codes">
    <meta name="dc.creator" content="Philmore Codes">

          <!-- Google Fonts -->
  <link href="https://fonts.gstatic.com" rel="preconnect">
  <link
    href="https://fonts.googleapis.com/css?family=Open+Sans:300,300i,400,400i,600,600i,700,700i|Nunito:300,300i,400,400i,600,600i,700,700i|Poppins:300,300i,400,400i,500,500i,600,600i,700,700i"
    rel="stylesheet">

  <!-- Vendor CSS Files -->
  <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="../assets-2/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
  <link href="../assets-2/vendor/remixicon/remixicon.css" rel="stylesheet">

  <!-- Template Main CSS File -->
  <link href="../assets-2/css/style.css" rel="stylesheet">
<style>
  body{
    background-image: url('../asset/web-bg-image.jpg');
    background-size: cover;
    background-position: center center;
    background-repeat: no-repeat;
    background-attachment: fixed;
  }
</style>
</head>
<body>	
    <div class="container d-flex justify-content-center align-items-center min-vh-100 py-5">
        <div class="card shadow-lg border-0 rounded-4 overflow-hidden" style="max-width: 900px; width: 100%;">
            <div class="row g-0">
                <div class="col-lg-5 d-none d-lg-flex flex-column justify-content-center align-items-center bg-primary p-5 text-white">
                    <img src="<?php echo $web_http_host; ?>/uploaded-image/<?php echo str_replace(['.',':'],'-',$_SERVER['HTTP_HOST']).'_'; ?>logo.png" style="width: 120px; height: 120px; object-fit: contain;" class="rounded-circle bg-white p-2 mb-4 shadow"/>
                    <h3 class="fw-bold">Admin Portal</h3>
                    <p class="text-center opacity-75">Securely manage your VTU business, users, and transactions from your dedicated control panel.</p>
                </div>
                <div class="col-lg-7 p-4 p-md-5 bg-white">
                    <div class="text-center mb-4 d-lg-none">
                        <img src="<?php echo $web_http_host; ?>/uploaded-image/<?php echo str_replace(['.',':'],'-',$_SERVER['HTTP_HOST']).'_'; ?>logo.png" style="width: 80px; height: 80px; object-fit: contain;" class="rounded-circle bg-light p-1 mb-3"/>
                        <h4 class="fw-bold text-dark">Admin Login</h4>
                    </div>

                    <?php if (!empty($_SESSION['bc_admin_login_otp']['email'])): ?>
                    <h2 class="fw-bold text-dark mb-1">Two-Step Verification</h2>
                    <p class="text-muted mb-4">Enter the 6-digit code we emailed to <b><?php echo htmlspecialchars($_SESSION['bc_admin_login_otp']['email']); ?></b>. It expires in 10 minutes.</p>

                    <form method="post" action="">
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-uppercase text-muted">Login Code</label>
                            <input name="otp_code" type="text" inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="one-time-code" class="form-control form-control-lg bg-light text-center fw-bold" style="letter-spacing: 8px; font-size: 22px;" placeholder="******" required autofocus>
                        </div>

                        <button name="verify-login-otp" type="submit" class="btn btn-primary btn-lg w-100 shadow-sm rounded-3 mb-3 py-3 fw-bold">
                            VERIFY &amp; CONTINUE
                        </button>

                        <button name="cancel-login-otp" type="submit" formnovalidate class="btn btn-link w-100 text-muted text-decoration-none">Back to sign in</button>

                        <div class="text-center small text-muted mt-2">Didn't get it? Check your spam folder, or sign in again to send a new code.</div>
                    </form>
                    <?php else: ?>
                    <h2 class="fw-bold text-dark mb-1 d-none d-lg-block">Welcome Back</h2>
                    <p class="text-muted mb-4 d-none d-lg-block">Enter your admin credentials to continue</p>

                    <form method="post" action="">
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-uppercase text-muted">Email Address</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0"><i class="bi bi-envelope text-primary"></i></span>
                                <input name="email" type="email" class="form-control form-control-lg bg-light border-start-0" placeholder="admin@example.com" style="text-transform: lowercase;" required>
                            </div>
                        </div>

                        <div class="mb-4">
                            <div class="d-flex justify-content-between">
                                <label class="form-label small fw-bold text-uppercase text-muted">Password</label>
                                <a href="PasswordRecovery.php" class="small fw-bold text-decoration-none">Forgot?</a>
                            </div>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0"><i class="bi bi-lock text-primary"></i></span>
                                <input id="password-field" name="pass" type="password" class="form-control form-control-lg bg-light border-start-0 border-end-0" placeholder="Enter password" required>
                                <span class="input-group-text bg-light border-start-0" style="cursor: pointer;" onclick="togglePasswordVisibility('password-field', this)">
                                    <i class="bi bi-eye text-muted"></i>
                                </span>
                            </div>
                        </div>

                        <button name="login" type="submit" class="btn btn-primary btn-lg w-100 shadow-sm rounded-3 mb-4 py-3 fw-bold">
                            ACCESS DASHBOARD
                        </button>

                        <div class="text-center">
                            <span class="text-muted">Need help? </span>
                            <a href="/" class="fw-bold text-decoration-none">Back to Website</a>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

<?php if(isset($_SESSION["product_purchase_response"])){ ?>

  <script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.0.16/dist/sweetalert2.all.min.js"></script>

<script>
  const msg = '<?php echo $_SESSION["product_purchase_response"]; ?>';
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
function togglePasswordVisibility(fieldId, iconElement) {
    const field = document.getElementById(fieldId);
    const icon = iconElement.querySelector('i');
    if (field.type === 'password') {
        field.type = 'text';
        icon.classList.remove('bi-eye');
        icon.classList.add('bi-eye-slash');
    } else {
        field.type = 'password';
        icon.classList.remove('bi-eye-slash');
        icon.classList.add('bi-eye');
    }
}
</script>
</body>
</html>

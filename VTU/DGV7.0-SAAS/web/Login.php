<?php session_start();
	
    include("../func/bc-config.php");
    if(isset($get_logged_user_details["username"]) && !empty($get_logged_user_details["username"]) && ($get_logged_user_details["status"] == 1)){
        // DGV7: a signed-in personal account follows the tenant's app destination (if set);
        // a business account always stays on the website. Runs before any redirecturl so a
        // crafted redirecturl cannot be used to walk around the policy.
        bc_fintech_web_login_flow($get_logged_user_details, (int)($select_vendor_table['id'] ?? 0));
    	$redirecturl = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["redirecturl"] ?? '')));
        $redirect_path = explode("?", $redirecturl)[0];
		if(!empty(trim($redirecturl)) && file_exists("..".$redirect_path)){
			header("Location: ".$redirecturl);
		}else{
			header("Location: /web/Dashboard.php");
		}
        exit();
    }

    // A failed schema upgrade must not silently disable a vendor's login OTP policy.
    if (!bc_ensure_user_login_otp_column()) {
        http_response_code(503);
        exit('Unable to verify login security settings. Please try again later.');
    }

    // Do not rely on the 60-second vendor session cache for an authentication policy.
    $login_vendor_id = (int)($select_vendor_table['id'] ?? 0);
    if ($login_vendor_id > 0) {
        $login_vendor_query = mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$login_vendor_id' AND status=1 LIMIT 1");
        $select_vendor_table = $login_vendor_query ? mysqli_fetch_array($login_vendor_query) : false;
        if (!$select_vendor_table) {
            http_response_code(503);
            exit('Unable to verify login security settings. Please try again later.');
        }
    }

    // Admin Impersonation Logic (Titanium Platform)
    // A one-time token minted by bc-spadmin/Users.php. It replaces the old
    // md5(username + YYYYMMDD + "SUPER_ADMIN_SECRET") scheme, which was a universal,
    // predictable password for every account: the "secret" was a literal in the source
    // and the hash was valid for the whole day. This version is random, expires in two
    // minutes and is burned on first use.
    if (isset($_GET['logAsToken'])) {
        $impersonated_user = bc_consume_login_token((string)$_GET['logAsToken'], 'impersonate');
        if ($impersonated_user) {
            session_regenerate_id(true);
            $_SESSION["user_session"] = strtolower($impersonated_user['username']);
            $_SESSION["admin_impersonation"] = true;
            $_SESSION["admin_impersonation_vendor"] = (int)$impersonated_user['vendor_id'];
            recordLoginAttempt($impersonated_user['username'], $_SERVER['REMOTE_ADDR'] ?? '', 1, (int)$impersonated_user['vendor_id']);
            header("Location: Dashboard.php");
            exit();
        }
        // Never reveal whether the token was wrong or expired.
        $_SESSION["product_purchase_response"] = "This login link is invalid or has expired. Please ask the administrator to generate a new one.";
        header("Location: Login.php");
        exit();
    }

    // ── Login verification code (step 2 of an OTP-protected sign-in) ─────────────
    // The password step only ADMITS the attempt; the session is created here, after the
    // emailed code is confirmed. Pending state is per-session and expires in 15 minutes.
    $login_otp_pending = $_SESSION['login_otp_pending'] ?? null;

    if (isset($_GET['cancel-login-otp'])) {
        unset($_SESSION['login_otp_pending']);
        header("Location: Login.php");
        exit();
    }

    if (isset($_POST['resend-login-otp']) && is_array($login_otp_pending)) {
        if (bc_is_rate_limited('web_login_otp', ((int)$login_otp_pending['vendor_id']) . ':' . strtolower((string)$login_otp_pending['username']), 6, 900)) {
            $_SESSION["product_purchase_response"] = "Please wait a few minutes before requesting another code.";
        } else {
            $resend_user = mysqli_real_escape_string($connection_server, (string)$login_otp_pending['username']);
            $resend_q = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='" . (int)$login_otp_pending['vendor_id'] . "' AND username='$resend_user' LIMIT 1");
            $resend_row = ($resend_q && mysqli_num_rows($resend_q) === 1) ? mysqli_fetch_assoc($resend_q) : null;
            if ($resend_row && bc_send_user_login_otp($resend_row, (int)$login_otp_pending['vendor_id']) !== '') {
                $_SESSION["product_purchase_response"] = "A new verification code has been sent to your email.";
            } else {
                $_SESSION["product_purchase_response"] = "Could not send a new code. Please sign in again.";
                unset($_SESSION['login_otp_pending']);
            }
        }
        header("Location: Login.php");
        exit();
    }

    if (isset($_POST['verify-login-otp']) && is_array($login_otp_pending)) {
        $entered_login_otp = preg_replace('/\D/', '', (string)($_POST['login_otp'] ?? ''));
        $pending_vendor_id = (int)$login_otp_pending['vendor_id'];
        $pending_username  = (string)$login_otp_pending['username'];

        if (time() > ((int)$login_otp_pending['created'] + 900)) {
            unset($_SESSION['login_otp_pending']);
            $_SESSION["product_purchase_response"] = "Your verification code expired. Please sign in again.";
        } elseif (!bc_verify_otp('user_login', $pending_username, $pending_vendor_id, $entered_login_otp)) {
            $_SESSION["product_purchase_response"] = "Incorrect or expired verification code.";
        } else {
            $pending_user_sql = mysqli_real_escape_string($connection_server, $pending_username);
            $pending_q = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$pending_vendor_id' AND username='$pending_user_sql' LIMIT 1");
            $pending_row = ($pending_q && mysqli_num_rows($pending_q) === 1) ? mysqli_fetch_assoc($pending_q) : null;
            if (!$pending_row || (int)$pending_row['status'] !== 1) {
                unset($_SESSION['login_otp_pending']);
                $_SESSION["product_purchase_response"] = "This account cannot be signed in right now. Please contact support.";
            } else {
                unset($_SESSION['login_otp_pending']);
                session_regenerate_id(true);
                $_SESSION["user_session"] = strtolower($pending_row["username"]);
                recordLoginAttempt($pending_row["username"], $_SERVER['REMOTE_ADDR'] ?? '', 1, $pending_vendor_id);

                // Same "new sign-in" notice the password-only path sends.
                $log_template_encoded_text_array = array("{firstname}" => $pending_row["firstname"], "{lastname}" => $pending_row["lastname"], "{username}" => $pending_row["username"], "{ip_address}" => $_SERVER["REMOTE_ADDR"]);
                $raw_log_template_subject = getUserEmailTemplate('user-log','subject');
                $raw_log_template_body = getUserEmailTemplate('user-log','body');
                foreach($log_template_encoded_text_array as $array_key => $array_val){
                    $raw_log_template_subject = str_replace($array_key, $array_val, $raw_log_template_subject);
                    $raw_log_template_body = str_replace($array_key, $array_val, $raw_log_template_body);
                }
                sendVendorEmail($pending_row["email"], $raw_log_template_subject, $raw_log_template_body);

                // DGV7: only now, with the session established and OTP passed, does the
                // personal-app redirect apply. It can never run before authentication.
                bc_fintech_web_login_flow($pending_row, $pending_vendor_id);

                header("Location: Dashboard.php");
                exit();
            }
        }
        header("Location: Login.php");
        exit();
    }

    if(isset($_POST['google_token'])){
        $id_token = $_POST['google_token'];
        $payload = verifyGoogleToken($id_token);

        if ($payload && isset($payload['email'])) {
            $email = mysqli_real_escape_string($connection_server, strtolower($payload['email']));
            $google_id = mysqli_real_escape_string($connection_server, $payload['sub']);

            $vendor_id = $select_vendor_table["id"];

            $check_user = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' AND (email='$email' OR google_id='$google_id') LIMIT 1");

            if ($check_user && mysqli_num_rows($check_user) == 1) {
                $user_detail = mysqli_fetch_assoc($check_user);
                if ($user_detail["status"] == 1) {
                    // Google proves the mailbox, but if this vendor requires a login code we still
                    // go through the same step so the audit trail and UX stay identical.
                    if (bc_user_login_otp_required($select_vendor_table)) {
                        if (bc_send_user_login_otp($user_detail, (int)$vendor_id) !== '') {
                            $_SESSION['login_otp_pending'] = array(
                                'username'  => strtolower($user_detail['username']),
                                'vendor_id' => (int)$vendor_id,
                                'created'   => time(),
                            );
                            $_SESSION["product_purchase_response"] = "A verification code has been sent to your email.";
                        } else {
                            $_SESSION["product_purchase_response"] = "Could not send the verification code. Please try again.";
                        }
                        header("Location: Login.php");
                        exit();
                    }
                    $_SESSION["user_session"] = strtolower($user_detail["username"]);
                    // Update google_id if not set
                    if (empty($user_detail['google_id'])) {
                        mysqli_query($connection_server, "UPDATE sas_users SET google_id='$google_id' WHERE id='".$user_detail['id']."'");
                    }
                    // DGV7: same post-authentication app routing as the password path.
                    bc_fintech_web_login_flow($user_detail, (int)$vendor_id);
                    header("Location: Dashboard.php");
                    exit();
                } else {
                    $_SESSION["product_purchase_response"] = "Account is not active.";
                }
            } else {
                // User not found, redirect to register or show error
                $_SESSION["product_purchase_response"] = "No account found with this Google email. Please register first.";
            }
        } else {
            $_SESSION["product_purchase_response"] = "Google Authentication Failed.";
        }
        header("Location: Login.php");
        exit();
    }

    if(isset($_POST["login"])){
    	$user = mysqli_real_escape_string($connection_server, trim(strip_tags(strtolower($_POST["user"]))));
    	$pass = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["pass"])));
    	if(!empty($user) && !empty($pass)){
            $vendor_id = $select_vendor_table["id"];
            $ip = $_SERVER['REMOTE_ADDR'];

            // Anti-BruteForce Check
            if ($msg = isIPBlocked($ip, $vendor_id)) {
                $_SESSION["product_purchase_response"] = "Access Denied: $msg";
                header("Location: ".$_SERVER["REQUEST_URI"]);
                exit();
            }
            if ($msg = isAccountLocked($user, $vendor_id)) {
                $_SESSION["product_purchase_response"] = "Account Locked: $msg";
                header("Location: ".$_SERVER["REQUEST_URI"]);
                exit();
            }

		$get_user_details = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' && username='$user'");
    		if($get_user_details && mysqli_num_rows($get_user_details) == 1){
    			$md5_pass = md5($pass);
			$check_user_password_details = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' && username='$user' && password='$md5_pass'");
    			if($check_user_password_details && mysqli_num_rows($check_user_password_details) == 1){
    				while($user_detail = mysqli_fetch_assoc($check_user_password_details)){
    					if($user_detail["status"] == 1){
                            // Password accepted — but when the vendor requires user login OTP,
                            // stop here and prove the mailbox before creating the session.
                            if (bc_user_login_otp_required($select_vendor_table)) {
                                if (bc_is_rate_limited('web_login_otp', $vendor_id . ':' . strtolower($user_detail['username']), 6, 900)) {
                                    $json_response_array = array("desc" => "Too many verification codes requested. Please try again later.");
                                    $json_response_encode = json_encode($json_response_array, true);
                                    continue;
                                }
                                if (!filter_var(trim((string)$user_detail['email']), FILTER_VALIDATE_EMAIL)) {
                                    $json_response_array = array("desc" => "This account has no valid email address on file. Please contact support.");
                                    $json_response_encode = json_encode($json_response_array, true);
                                    continue;
                                }
                                if (bc_send_user_login_otp($user_detail, $vendor_id) !== '') {
                                    $_SESSION['login_otp_pending'] = array(
                                        'username'  => strtolower($user_detail['username']),
                                        'vendor_id' => (int)$vendor_id,
                                        'created'   => time(),
                                    );
                                    $json_response_array = array("desc" => "A verification code has been sent to your email.");
                                } else {
                                    $json_response_array = array("desc" => "Could not send the verification code. Please try again.");
                                }
                                $json_response_encode = json_encode($json_response_array, true);
                                continue;
                            }

                            recordLoginAttempt($user, $ip, 1, $vendor_id);

                            // Stop session fixation: the pre-login session id must not carry over
                            // into an authenticated one.
                            if (session_status() === PHP_SESSION_ACTIVE) {
                                session_regenerate_id(true);
                            }

    						$_SESSION["user_session"] = strtolower($user_detail["username"]);

							// Email Beginning
							$log_template_encoded_text_array = array("{firstname}" => $user_detail["firstname"], "{lastname}" => $user_detail["lastname"], "{username}" => $user_detail["username"], "{ip_address}" => $_SERVER["REMOTE_ADDR"]);
							$raw_log_template_subject = getUserEmailTemplate('user-log','subject');
							$raw_log_template_body = getUserEmailTemplate('user-log','body');
							foreach($log_template_encoded_text_array as $array_key => $array_val){
								$raw_log_template_subject = str_replace($array_key, $array_val, $raw_log_template_subject);
								$raw_log_template_body = str_replace($array_key, $array_val, $raw_log_template_body);
							}
							sendVendorEmail($user_detail["email"], $raw_log_template_subject, $raw_log_template_body);
							// Email End

                            // DGV7: placed AFTER the sign-in notice so the security email still
                            // goes out, and after the session exists so the redirect can never
                            // run pre-authentication. Business accounts are never sent to the
                            // app; a malformed saved URL blocks instead of opening Dashboard.
                            bc_fintech_web_login_flow($user_detail, (int)$vendor_id);

    						//Welcome Back Message
    						$json_response_array = array("desc" => "Welcome Back, ".ucwords($user_detail["firstname"]));
    						$json_response_encode = json_encode($json_response_array,true);
    					}else{
                            recordLoginAttempt($user, $ip, 0, $vendor_id);
							if($user_detail["status"] == 2){
								//Account Locked, Contact Admin
								$json_response_array = array("desc" => "Account Locked, Contact Admin");
								$json_response_encode = json_encode($json_response_array,true);
							}else{
								if($user_detail["status"] == 3){
									//Account Deleted, Contact Admin
									$json_response_array = array("desc" => "Account Deleted, Contact Admin");
									$json_response_encode = json_encode($json_response_array,true);
								}else{
									//Invalid Account Status
									$json_response_array = array("desc" => "Invalid Account Status");
									$json_response_encode = json_encode($json_response_array,true);
								}
							}
    					}
    				}
    			}else{
    				if($check_user_password_details && mysqli_num_rows($check_user_password_details) < 1){
                        recordLoginAttempt($user, $ip, 0, $vendor_id);
    					//Incorrect Password
    					$json_response_array = array("desc" => "Incorrect Password");
    					$json_response_encode = json_encode($json_response_array,true);
    				}
    			}
    		}else{
                recordLoginAttempt($user, $ip, 0, $vendor_id);
    			if($get_user_details && mysqli_num_rows($get_user_details) > 1){
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
    		if(empty($user)){
    			//Username Field Empty
    			$json_response_array = array("desc" => "Username Field Empty");
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
	<title>Login | VTU BUSINESS WEBSITE</title>
    <meta charset="UTF-8" />
    <meta name="description" content="<?php echo substr($get_all_site_details["site_desc"], 0, 160); ?>" />
    <meta http-equiv="Content-Type" content="text/html; " />
    <meta name="theme-color" content="#1e3a8a" />
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
                    <h3 class="fw-bold">Welcome Back!</h3>
                    <p class="text-center opacity-75">Access your account to manage your services and transactions securely.</p>
                </div>
                <div class="col-lg-7 p-4 p-md-5 bg-white">
                    <div class="text-center mb-4 d-lg-none">
                        <img src="<?php echo $web_http_host; ?>/uploaded-image/<?php echo str_replace(['.',':'],'-',$_SERVER['HTTP_HOST']).'_'; ?>logo.png" style="width: 80px; height: 80px; object-fit: contain;" class="rounded-circle bg-light p-1 mb-3"/>
                        <h4 class="fw-bold text-dark">Sign In</h4>
                    </div>

                    <h2 class="fw-bold text-dark mb-1 d-none d-lg-block">Sign In</h2>
                    <p class="text-muted mb-4 d-none d-lg-block">Enter your credentials to access your dashboard</p>

                    <?php if(!empty($select_vendor_table['google_client_id']) && empty($_SESSION['login_otp_pending'])): ?>
                    <script src="https://accounts.google.com/gsi/client" async defer></script>
                    <div id="g_id_onload"
                        data-client_id="<?php echo $select_vendor_table['google_client_id']; ?>"
                        data-callback="handleCredentialResponse"
                        data-auto_prompt="false">
                    </div>
                    <div class="g_id_signin mb-4" data-type="standard" data-shape="rectangular" data-theme="outline" data-text="signin_with" data-size="large" data-logo_alignment="left" data-width="100%"></div>

                    <form id="google-login-form" method="post" action="" style="display:none;">
                        <input type="hidden" name="google_token" id="google_token">
                    </form>

                    <script>
                    function handleCredentialResponse(response) {
                        document.getElementById('google_token').value = response.credential;
                        document.getElementById('google-login-form').submit();
                    }
                    </script>

                    <div class="divider d-flex align-items-center my-4">
                        <p class="text-center fw-bold mx-3 mb-0 text-muted">OR</p>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($_SESSION['login_otp_pending'])): ?>
                    <div class="text-center mb-3">
                        <div class="bg-primary bg-opacity-10 p-3 rounded-circle d-inline-block">
                            <i class="bi bi-shield-lock text-primary fs-3"></i>
                        </div>
                    </div>
                    <h4 class="fw-bold text-dark text-center mb-1">Verify it's you</h4>
                    <p class="text-muted text-center small mb-4">
                        We emailed a 6-digit sign-in code to
                        <strong><?php echo htmlspecialchars(strtolower((string)$_SESSION['login_otp_pending']['username'])); ?></strong>'s address.
                    </p>
                    <form method="post" action="">
                        <div class="mb-4">
                            <label class="form-label small fw-bold text-uppercase text-muted">Verification Code</label>
                            <input name="login_otp" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                                   autocomplete="one-time-code" required
                                   class="form-control form-control-lg bg-light text-center fw-bold"
                                   style="letter-spacing: 10px; font-size: 1.5rem;" placeholder="——————">
                        </div>
                        <button name="verify-login-otp" type="submit" class="btn btn-primary btn-lg w-100 shadow-sm rounded-3 mb-3 py-3 fw-bold">
                            VERIFY &amp; SIGN IN
                        </button>
                        <button name="resend-login-otp" type="submit" class="btn btn-link w-100 text-decoration-none fw-bold">
                            Didn't receive it? Resend code
                        </button>
                        <div class="text-center mt-2">
                            <a href="Login.php?cancel-login-otp=1" class="small text-muted">Use a different account</a>
                        </div>
                    </form>
                    <?php else: ?>
                    <form method="post" action="">
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-uppercase text-muted">Username</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0"><i class="bi bi-person text-primary"></i></span>
                                <input name="user" type="text" class="form-control form-control-lg bg-light border-start-0" placeholder="Enter username" style="text-transform: lowercase;" required>
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

                        <button name="login" type="submit" class="btn btn-primary btn-lg w-100 shadow-sm rounded-3 mb-3 py-3 fw-bold">
                            CONTINUE
                        </button>

                        <button id="biometric-login-btn" type="button" class="btn btn-outline-primary btn-lg w-100 shadow-sm rounded-3 mb-4 py-3 fw-bold" style="display:none;">
                            <i class="bi bi-fingerprint"></i> LOGIN WITH BIOMETRIC
                        </button>

                        <div class="text-center">
                            <span class="text-muted">New here? </span>
                            <a href="Register.php" class="fw-bold text-decoration-none">Create an Account</a>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

  <script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.0.16/dist/sweetalert2.all.min.js"></script>

<?php if(isset($_SESSION["product_purchase_response"])){ ?>

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
        window.location.href = 'LockoutResolution.php';
      } else if (result.isDenied) {
        Swal.fire({
          title: 'Unblock Reason',
          input: 'text',
          inputPlaceholder: 'Briefly explain why you should be unblocked...',
          showCancelButton: true,
          confirmButtonText: 'Submit Request',
          showLoaderOnConfirm: true,
          preConfirm: (reason) => {
            return fetch('ajax-unblock-request.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ username: <?php echo json_encode($_POST['user'] ?? '', JSON_HEX_QUOT|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_TAG); ?>, reason: reason })
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
     Swal.fire('Message!', msg, msg.includes('Welcome') ? 'success' : 'info');
  }

  setTimeout(() => {
    fetch('/func/unset-product-response.php').then(response => response.text());
  }, 1000);
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
<div id="networkStatus" class="network-status"></div>
<style>
.network-status {
    position: fixed;
    top: 20px;
    left: 50%;
    transform: translateX(-50%) translateY(-100px);
    padding: 12px 24px;
    border-radius: 50px;
    font-weight: 600;
    z-index: 10001;
    transition: 0.5s;
    box-shadow: 0 10px 20px rgba(0,0,0,0.1);
    display: flex;
    align-items: center;
    gap: 10px;
}
.network-status.active { transform: translateX(-50%) translateY(0); }
.network-status.online { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
.network-status.offline { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
</style>
<script src="../jsfile/bc-custom-all.js"></script>
<script src="../asset/pwa-handler.js"></script>
<script src="../asset/biometric-handler.js"></script>
<script>
// Biometric Login Initialization
document.addEventListener('DOMContentLoaded', () => {
    const biometricBtn = document.getElementById('biometric-login-btn');
    if (window.PublicKeyCredential && biometricBtn) {
        window.PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable()
            .then(available => {
                if (available) {
                    biometricBtn.style.display = 'block';
                    biometricBtn.addEventListener('click', loginWithBiometric);
                }
            })
            .catch(err => console.error('Biometric check error:', err));
    }
});

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
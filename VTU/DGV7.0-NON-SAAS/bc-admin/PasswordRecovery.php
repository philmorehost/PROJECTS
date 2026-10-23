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
<!DOCTYPE html>
<head>
	<title>Admin Recovery | <?php echo $get_all_super_admin_site_details["site_title"]; ?></title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
    <link rel="stylesheet" href="/cssfile/bc-style.css">

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
                    <h3 class="fw-bold">Password Help</h3>
                    <p class="text-center opacity-75">Follow the instructions to safely regain access to your admin dashboard.</p>
                </div>
                <div class="col-lg-7 p-4 p-md-5 bg-white">
                    <?php if ($reset_blocked_msg): ?>
                    <div class="alert alert-danger border-0 rounded-3 mb-4">
                        <div class="d-flex align-items-center mb-2">
                            <i class="bi bi-shield-lock-fill fs-4 me-2 text-danger"></i>
                            <strong class="fw-bold">Password reset unavailable</strong>
                        </div>
                        <p class="mb-2 small"><?php echo htmlspecialchars($reset_blocked_msg); ?></p>
                        <a href="../web/LockoutResolution.php" class="btn btn-danger btn-lg w-100 fw-bold">Unlock with Security PIN</a>
                        <div class="text-center mt-2">
                            <a href="Login.php" class="text-decoration-none small fw-bold text-muted"><i class="bi bi-arrow-left me-1"></i>Back to Login</a>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="text-center mb-4 d-lg-none">
                        <img src="<?php echo $web_http_host; ?>/uploaded-image/<?php echo str_replace(['.',':'],'-',$_SERVER['HTTP_HOST']).'_'; ?>logo.png" style="width: 80px; height: 80px; object-fit: contain;" class="rounded-circle bg-light p-1 mb-3"/>
                        <h4 class="fw-bold text-dark">Recover Admin</h4>
                    </div>

                    <h2 class="fw-bold text-dark mb-1 d-none d-lg-block">Account Recovery</h2>
                    <p class="text-muted mb-4 d-none d-lg-block">Reset your administrator password</p>

                    <form method="post" action="" <?php echo $reset_blocked_msg ? 'style="display:none"' : ''; ?>>
                        <?php if(!isset($_SESSION["admin-recovery-username"]) || empty($_SESSION["admin-recovery-username"])){ ?>
                            <div class="mb-4">
                                <label class="form-label small fw-bold text-uppercase text-muted">Admin Email</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0"><i class="bi bi-envelope text-primary"></i></span>
                                    <input name="email" type="email" class="form-control form-control-lg bg-light border-start-0" placeholder="admin@example.com" style="text-transform: lowercase;" required>
                                </div>
                                <div class="form-text mt-2 small text-muted">A verification code will be sent to this email if it exists in our system.</div>
                            </div>

                            <button name="send-code" type="submit" class="btn btn-primary btn-lg w-100 shadow-sm rounded-3 mb-4 py-3 fw-bold text-uppercase">
                                Request Access Code
                            </button>
                        <?php } ?>

                        <?php if(isset($_SESSION["admin-recovery-username"]) && !empty($_SESSION["admin-recovery-username"])){ ?>
                            <div class="alert alert-success border-0 py-2 small mb-4">
                                <i class="bi bi-shield-check me-2"></i> Verification code sent.
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-bold text-uppercase text-muted">Verification Code</label>
                                <input name="code" type="text" class="form-control form-control-lg bg-light border-0" placeholder="6-digit code" pattern="[0-9]{6}" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-bold text-uppercase text-muted">New Admin Password</label>
                                <div class="input-group">
                                    <input id="new-password" name="pass" type="password" class="form-control form-control-lg bg-light border-0" placeholder="Min 8 characters" required>
                                    <span class="input-group-text bg-light border-0" style="cursor: pointer;" onclick="togglePasswordVisibility('new-password', this)">
                                        <i class="bi bi-eye text-muted"></i>
                                    </span>
                                </div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label small fw-bold text-uppercase text-muted">Confirm New Password</label>
                                <div class="input-group">
                                    <input id="confirm-password" name="confirm-pass" type="password" class="form-control form-control-lg bg-light border-0" placeholder="Repeat password" required>
                                    <span class="input-group-text bg-light border-0" style="cursor: pointer;" onclick="togglePasswordVisibility('confirm-password', this)">
                                        <i class="bi bi-eye text-muted"></i>
                                    </span>
                                </div>
                            </div>

                            <button name="verify-code" type="submit" class="btn btn-primary btn-lg w-100 shadow-sm rounded-3 mb-3 py-3 fw-bold text-uppercase">
                                Update Password
                            </button>

                            <button name="reset-recovery" type="submit" class="btn btn-link w-100 text-muted text-decoration-none small">
                                Cancel & Restart
                            </button>
                        <?php } ?>

                        <hr class="my-4 opacity-25">

                        <div class="text-center">
                            <a href="Login.php" class="fw-bold text-decoration-none small">Back to Admin Sign In</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

<?php if(isset($_SESSION["product_purchase_response"])){ ?>

  <script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.0.16/dist/sweetalert2.all.min.js"></script>

<script>
  Swal.fire ('Notification', '<?php echo $_SESSION["product_purchase_response"]; ?>', 'info') ;
          setTimeout(() => {
                fetch('/func/unset-product-response.php')
                    .then(response => response.text());
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

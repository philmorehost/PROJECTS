<?php session_start();
    include("../func/bc-spadmin-config.php");
    
    // Password Reset General Control — the Super Admin controls the global kill switch.
    $reset_blocked_msg = null;
    if (!bc_is_global_service_enabled('password_reset')) {
        $reset_blocked_msg = "Password reset is currently disabled by the platform administrator.";
    }
    
    if(isset($_POST["send-code"])){
        if ($reset_blocked_msg) {
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
				$_SESSION["spadmin-recovery-code"] = substr(str_shuffle("12345678901234567890"), 0, 6);
				// Email Beginning
				$log_template_encoded_text_array = array("{firstname}" => $get_user_personal_details["firstname"], "{lastname}" => $get_user_personal_details["lastname"], "{recovery_code}" => $_SESSION["spadmin-recovery-code"]);
				$raw_log_template_subject = getSuperAdminEmailTemplate('spadmin-account-recovery','subject');
				$raw_log_template_body = getSuperAdminEmailTemplate('spadmin-account-recovery','body');
				foreach($log_template_encoded_text_array as $array_key => $array_val){
					$raw_log_template_subject = str_replace($array_key, $array_val, $raw_log_template_subject);
					$raw_log_template_body = str_replace($array_key, $array_val, $raw_log_template_body);
				}
				sendVendorEmail($get_user_personal_details["email"], $raw_log_template_subject, $raw_log_template_body);
				// Email End
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
    		$get_user_details = mysqli_query($connection_server, "SELECT * FROM sas_super_admin WHERE email='".$_SESSION["spadmin-recovery-username"]."'");
    		if(mysqli_num_rows($get_user_details) == 1){
				if($_SESSION["spadmin-recovery-code"] == $recovery_code){
					if($pass == $confirm_pass){
						// Stored as password_hash() (bcrypt). The login path still accepts legacy
						// raw-MD5 rows, so nobody is locked out mid-migration.
						$new_spadmin_pass_hash = mysqli_real_escape_string($connection_server, bc_hash_password($pass));
						$get_user_personal_details = mysqli_fetch_array($get_user_details);
						$get_user_details = mysqli_query($connection_server, "UPDATE sas_super_admin SET password='$new_spadmin_pass_hash' WHERE id='".$get_user_personal_details["id"]."' && email='".$get_user_personal_details["email"]."'");
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
		header("Location: ".$_SERVER["REQUEST_URI"]);
        exit();
	}
?>
<!DOCTYPE html>
<head>
	<title>Super Admin Recovery</title>
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
  <link href="../assets-2/vendor/quill/quill.snow.css" rel="stylesheet">
  <link href="../assets-2/vendor/quill/quill.bubble.css" rel="stylesheet">
  <link href="../assets-2/vendor/remixicon/remixicon.css" rel="stylesheet">
  <link href="../assets-2/vendor/simple-datatables/style.css" rel="stylesheet">

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
                <div class="col-lg-5 d-none d-lg-flex flex-column justify-content-center align-items-center bg-dark p-5 text-white">
                    <img src="<?php echo $web_http_host; ?>/uploaded-image/sp-logo.png" style="width: 120px; height: 120px; object-fit: contain;" class="rounded-circle bg-white p-2 mb-4 shadow"/>
                    <h3 class="fw-bold">Super Recovery</h3>
                    <p class="text-center opacity-75">Secure session re-authorization for super administrative accounts.</p>
                </div>
                <div class="col-lg-7 p-4 p-md-5 bg-white">
                    <?php if ($reset_blocked_msg): ?>
                    <div class="alert alert-danger border-0 rounded-3 mb-4">
                        <div class="d-flex align-items-center mb-2">
                            <i class="bi bi-shield-lock-fill fs-4 me-2 text-danger"></i>
                            <strong class="fw-bold">Password reset unavailable</strong>
                        </div>
                        <p class="mb-2 small"><?php echo htmlspecialchars($reset_blocked_msg); ?></p>
                        <a href="Login.php" class="btn btn-dark btn-lg w-100 fw-bold">Back to Super Admin Login</a>
                    </div>
                    <?php endif; ?>
                    <div class="text-center mb-4 d-lg-none">
                        <img src="<?php echo $web_http_host; ?>/uploaded-image/sp-logo.png" style="width: 80px; height: 80px; object-fit: contain;" class="rounded-circle bg-light p-1 mb-3"/>
                        <h4 class="fw-bold text-dark">Super Recovery</h4>
                    </div>

                    <h2 class="fw-bold text-dark mb-1 d-none d-lg-block">Account Recovery</h2>
                    <p class="text-muted mb-4 d-none d-lg-block">Master access restoration</p>

                    <form method="post" action="" <?php echo $reset_blocked_msg ? 'style="display:none"' : ''; ?>>
                        <?php if(!isset($_SESSION["spadmin-recovery-username"]) || empty($_SESSION["spadmin-recovery-username"])){ ?>
                            <div class="mb-4">
                                <label class="form-label small fw-bold text-uppercase text-muted">Super Email</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0"><i class="bi bi-shield-lock text-dark"></i></span>
                                    <input name="email" type="email" class="form-control form-control-lg bg-light border-start-0" placeholder="super@system" style="text-transform: lowercase;" required>
                                </div>
                                <div class="form-text mt-2 small text-muted">Authorized credentials required.</div>
                            </div>

                            <button name="send-code" type="submit" class="btn btn-dark btn-lg w-100 shadow-sm rounded-3 mb-4 py-3 fw-bold text-uppercase">
                                ISSUE RECOVERY CODE
                            </button>
                        <?php } ?>

                        <?php if(isset($_SESSION["spadmin-recovery-username"]) && !empty($_SESSION["spadmin-recovery-username"])){ ?>
                            <div class="alert alert-warning border-0 py-2 small mb-4">
                                <i class="bi bi-exclamation-triangle-fill me-2"></i> Authorized code sent to email.
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-bold text-uppercase text-muted">Recovery Code</label>
                                <input name="code" type="text" class="form-control form-control-lg bg-light border-0" placeholder="6-digit code" pattern="[0-9]{6}" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-bold text-uppercase text-muted">New Master Password</label>
                                <div class="input-group">
                                    <input id="new-password" name="pass" type="password" class="form-control form-control-lg bg-light border-0" placeholder="Required" required>
                                    <span class="input-group-text bg-light border-0" style="cursor: pointer;" onclick="togglePasswordVisibility('new-password', this)">
                                        <i class="bi bi-eye text-muted"></i>
                                    </span>
                                </div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label small fw-bold text-uppercase text-muted">Confirm Password</label>
                                <div class="input-group">
                                    <input id="confirm-password" name="confirm-pass" type="password" class="form-control form-control-lg bg-light border-0" placeholder="Repeat" required>
                                    <span class="input-group-text bg-light border-0" style="cursor: pointer;" onclick="togglePasswordVisibility('confirm-password', this)">
                                        <i class="bi bi-eye text-muted"></i>
                                    </span>
                                </div>
                            </div>

                            <button name="verify-code" type="submit" class="btn btn-dark btn-lg w-100 shadow-sm rounded-3 mb-3 py-3 fw-bold text-uppercase">
                                OVERWRITE PASSWORD
                            </button>

                            <button name="reset-recovery" type="submit" class="btn btn-link w-100 text-muted text-decoration-none small">
                                ABORT SESSION
                            </button>
                        <?php } ?>

                        <hr class="my-4 opacity-25">

                        <div class="text-center">
                            <a href="Login.php" class="fw-bold text-decoration-none small text-dark">Return to System Access</a>
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
  Swal.fire ('System Alert', '<?php echo $_SESSION["product_purchase_response"]; ?>', 'warning') ;
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

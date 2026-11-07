<?php session_start();
	
    include("../func/bc-config.php");
    if(isset($get_logged_user_details["username"]) && !empty($get_logged_user_details["username"]) && ($get_logged_user_details["status"] == 1)){
    	$redirecturl = mysqli_real_escape_string($connection_server, trim(strip_tags($_GET["redirecturl"] ?? '')));
        $redirect_path = explode("?", $redirecturl)[0];
		if(!empty(trim($redirecturl)) && file_exists("..".$redirect_path)){
			header("Location: ".$redirecturl);
		}else{
			header("Location: /web/Dashboard.php");
		}
        exit();
    }

    // Admin Impersonation Logic (Titanium Platform)
    if(isset($_GET['logAsUser']) && isset($_GET['auth'])){
        $logAs = mysqli_real_escape_string($connection_server, trim($_GET['logAsUser']));
        $auth = $_GET['auth'];
        $expected_auth = md5($logAs . date('Ymd') . "SUPER_ADMIN_SECRET");
        
        if($auth === $expected_auth){
            $vendor_id = $select_vendor_table["id"];
            $check_u = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' AND username='$logAs' LIMIT 1");
            if($r = mysqli_fetch_assoc($check_u)){
                $_SESSION["user_session"] = $r['username'];
                $_SESSION["admin_impersonation"] = true; // Flag for later use if needed
                header("Location: Dashboard.php");
                exit();
            }
        }
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
                    $_SESSION["user_session"] = strtolower($user_detail["username"]);
                    // Update google_id if not set
                    if (empty($user_detail['google_id'])) {
                        mysqli_query($connection_server, "UPDATE sas_users SET google_id='$google_id' WHERE id='".$user_detail['id']."'");
                    }
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
                            recordLoginAttempt($user, $ip, 1, $vendor_id);


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

                    <?php if(!empty($select_vendor_table['google_client_id'])): ?>
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
                </div>
            </div>
        </div>
    </div>

  <script src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.0.16/dist/sweetalert2.all.min.js"></script>

<?php if(isset($_SESSION["product_purchase_response"])){ ?>

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
              body: JSON.stringify({ username: '<?php echo $_POST['user'] ?? ''; ?>', reason: reason })
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
		<?php echo $_SESSION["product_purchase_response"]; ?>
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
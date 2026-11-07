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
<!DOCTYPE html>
<head>
    <title>Super Admin Login</title>
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
                    <h3 class="fw-bold">Super Portal</h3>
                    <p class="text-center opacity-75">Master control panel for the entire multi-tenant VTU platform ecosystem.</p>
                </div>
                <div class="col-lg-7 p-4 p-md-5 bg-white">
                    <div class="text-center mb-4 d-lg-none">
                        <img src="<?php echo $web_http_host; ?>/uploaded-image/sp-logo.png" style="width: 80px; height: 80px; object-fit: contain;" class="rounded-circle bg-light p-1 mb-3"/>
                        <h4 class="fw-bold text-dark">Super Admin</h4>
                    </div>

                    <h2 class="fw-bold text-dark mb-1 d-none d-lg-block">System Access</h2>
                    <p class="text-muted mb-4 d-none d-lg-block">Authenticated access only</p>

                    <form method="post" action="">
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-uppercase text-muted">Super Email</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0"><i class="bi bi-shield-lock text-dark"></i></span>
                                <input name="email" type="email" class="form-control form-control-lg bg-light border-start-0" placeholder="super-admin@system" style="text-transform: lowercase;" required>
                            </div>
                        </div>

                        <div class="mb-4">
                            <div class="d-flex justify-content-between">
                                <label class="form-label small fw-bold text-uppercase text-muted">Master Password</label>
                                <a href="PasswordRecovery.php" class="small fw-bold text-decoration-none text-dark">Forgot?</a>
                            </div>
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0"><i class="bi bi-key text-dark"></i></span>
                                <input id="password-field" name="pass" type="password" class="form-control form-control-lg bg-light border-start-0 border-end-0" placeholder="••••••••" required>
                                <span class="input-group-text bg-light border-start-0" style="cursor: pointer;" onclick="togglePasswordVisibility('password-field', this)">
                                    <i class="bi bi-eye text-muted"></i>
                                </span>
                            </div>
                        </div>

                        <button name="login" type="submit" class="btn btn-dark btn-lg w-100 shadow-sm rounded-3 mb-4 py-3 fw-bold">
                            AUTHORIZE SESSION
                        </button>

                        <div class="text-center">
                            <a href="/" class="small fw-bold text-decoration-none text-muted">Return to Platform</a>
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

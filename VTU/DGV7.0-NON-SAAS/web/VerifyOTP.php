<?php session_start();
    include("../func/bc-config.php");

    if(!isset($_SESSION["temp_user_session"]) || !isset($_SESSION["auth_otp_time"])){
        header("Location: Login.php");
        exit();
    }

    $username = $_SESSION["temp_user_session"];
    $vendor_id = resolveVendorID();
    $user_detail = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT * FROM sas_users WHERE vendor_id='$vendor_id' AND username='$username' LIMIT 1"));

    if(isset($_POST["verify-otp"])){
        $otp = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["otp"])));

        if($_SESSION["2fa_type"] == "email"){
            if (time() - $_SESSION["auth_otp_time"] > 600) {
                $error = "Verification code has expired. Please request a new one.";
            } elseif($otp === $_SESSION["auth_otp"]){
                $_SESSION["user_session"] = $username;
                unset($_SESSION["temp_user_session"]);
                unset($_SESSION["auth_otp"]);
                unset($_SESSION["auth_otp_time"]);
                header("Location: Dashboard.php");
                exit();
            } else {
                $error = "Invalid verification code.";
            }
        }
        // TOTP logic can be added here
    }

    if(isset($_GET["resend"])){
        if($_SESSION["2fa_type"] == "email"){
            $otp = generateOTP();
            $_SESSION["auth_otp"] = $otp;
            $_SESSION["auth_otp_time"] = time();
            $subject = "Login Verification Code";
            $body = "Your verification code is: <b>$otp</b>";
            sendVendorEmail($user_detail["email"], $subject, $body);
            $_SESSION["product_purchase_response"] = "Verification code resent to your email.";
            header("Location: VerifyOTP.php");
            exit();
        }
    }
?>
<!DOCTYPE html>
<head>
    <title>Verify Login | <?php echo $get_all_site_details["site_title"]; ?></title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
    <link rel="stylesheet" href="/cssfile/bc-style.css">
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container d-flex justify-content-center align-items-center min-vh-100">
        <div class="card shadow border-0 rounded-4 p-4 p-md-5" style="max-width: 450px; width: 100%;">
            <div class="text-center mb-4">
                <div class="bg-primary bg-opacity-10 p-3 rounded-circle d-inline-block mb-3">
                    <i class="bi bi-shield-check text-primary fs-1"></i>
                </div>
                <h3 class="fw-bold">Two-Factor Authentication</h3>
                <p class="text-muted">A verification code has been sent to <b><?php echo substr($user_detail['email'], 0, 3) . '...' . substr($user_detail['email'], strpos($user_detail['email'], '@')); ?></b></p>
            </div>

            <?php if(isset($error)): ?>
                <div class="alert alert-danger py-2 small mb-3 text-center"><?php echo $error; ?></div>
            <?php endif; ?>

            <form method="post" action="">
                <div class="mb-4">
                    <label class="form-label small fw-bold text-uppercase text-muted text-center d-block">Enter 6-Digit Code</label>
                    <input name="otp" type="text" maxlength="6" pattern="[0-9]{6}" class="form-control form-control-lg text-center fw-bold letter-spacing-lg" placeholder="000000" inputmode="numeric" required autofocus>
                    <p class="text-center small text-muted mt-2">Code expires in: <span id="timer" class="fw-bold text-danger">10:00</span></p>
                </div>
                <button name="verify-otp" type="submit" class="btn btn-primary btn-lg w-100 shadow-sm rounded-3 py-3 fw-bold mb-3">
                    VERIFY & CONTINUE
                </button>
                <div class="text-center">
                    <a href="?resend=1" class="text-decoration-none small fw-bold">Didn't get the code? Resend</a>
                </div>
            </form>
        </div>
    </div>
    <style>
        .letter-spacing-lg { letter-spacing: 0.5rem; }
    </style>
    <script>
        var timeLeft = <?php echo max(0, 600 - (time() - ($_SESSION["auth_otp_time"] ?? time()))); ?>;
        var timerElement = document.getElementById('timer');

        function updateTimer() {
            if (timeLeft <= 0) {
                timerElement.innerHTML = "Expired";
                return;
            }
            var minutes = Math.floor(timeLeft / 60);
            var seconds = timeLeft % 60;
            timerElement.innerHTML = minutes + ":" + (seconds < 10 ? '0' : '') + seconds;
            timeLeft--;
            setTimeout(updateTimer, 1000);
        }
        updateTimer();
    </script>
</body>
</html>
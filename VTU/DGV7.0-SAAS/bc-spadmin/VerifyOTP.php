<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
 bc_secure_session_start();
    include("../func/bc-spadmin-config.php");

    if(!isset($_SESSION["temp_spadmin_session"]) || !isset($_SESSION["auth_otp_time"])){
        header("Location: Login.php");
        exit();
    }

    $email = $_SESSION["temp_spadmin_session"];
    $admin_detail = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT * FROM sas_super_admin WHERE email='$email' LIMIT 1"));

    // Retired pending states without an authentication-time binding cannot create sessions.
    if(!is_string($_SESSION['temp_auth_credential_binding']['spadmin']??null)||!hash_equals($_SESSION['temp_auth_credential_binding']['spadmin'],bc_browser_binding_fingerprint('spadmin',$admin_detail)??'')){header('Location: Login.php');exit;}

    if(isset($_POST["verify-otp"])){
        $otp = mysqli_real_escape_string($connection_server, trim(strip_tags($_POST["otp"])));

        if($_SESSION["2fa_type"] == "email"){
            if (time() - $_SESSION["auth_otp_time"] > 600) {
                $error = "Verification code has expired. Please request a new one.";
            } elseif($otp === $_SESSION["auth_otp"]){
                $_SESSION["spadmin_session"] = $email;
                session_regenerate_id(true);bc_browser_binding_stamp('spadmin',$admin_detail);bc_auth_epoch_stamp();
                unset($_SESSION["temp_spadmin_session"]);
                unset($_SESSION["auth_otp"]);
                unset($_SESSION["auth_otp_time"]);
                header("Location: Dashboard.php");
                exit();
            } else {
                $error = "Invalid master code.";
            }
        }
    }

    if(isset($_GET["resend"])){
        if($_SESSION["2fa_type"] == "email"){
            $otp = generateOTP();
            $_SESSION["auth_otp"] = $otp;
            $_SESSION["auth_otp_time"] = time();
            $subject = "Super Admin Verification Code";
            $body = "Your master verification code is: <b>$otp</b>";
            sendSuperAdminEmail($admin_detail["email"], $subject, $body);
            $_SESSION["product_purchase_response"] = "Master code resent to your email.";
            header("Location: VerifyOTP.php");
            exit();
        }
    }
?>
<?php
$GLOBALS['get_all_site_details'] = $get_all_super_admin_site_details ?? ($GLOBALS['get_all_site_details'] ?? null);
include_once(__DIR__ . "/../func/bc-plate-auth.php");
bc_plate_auth_head("Master Verification | Super Portal", $get_all_super_admin_site_details ?? null);
bc_plate_auth_top(array(
    'icon'  => 'shield',
    'title' => 'Master Verification',
    'sub'   => 'Enter the master code sent to your email: <b>' . substr($admin_detail['email'], 0, 3) . '...' . substr($admin_detail['email'], strpos($admin_detail['email'], '@')) . '</b>',
    'alert' => isset($error) ? array(array('type' => 'error', 'body' => $error)) : null,
));
?>
            <form method="post" action="">
                <div class="pk-auth__field">
                    <label class="pk-auth__label" for="otp">6-Digit Master Code</label>
                    <input id="otp" name="otp" type="text" maxlength="6" pattern="[0-9]{6}" class="pk-auth__input pk-auth__otp" placeholder="000000" inputmode="numeric" required autofocus>
                    <p class="pk-auth__note" style="margin-top:10px;">Code expires in: <span id="timer" style="color: var(--danger); font-weight: 700;">10:00</span></p>
                </div>
                <button name="verify-otp" type="submit" class="pk-auth__btn">
                    AUTHORIZE MASTER SESSION
                </button>
                <p class="pk-auth__note" style="margin-top:14px;">
                    <a href="?resend=1" class="pk-auth__link">Resend Code</a>
                </p>
            </form>
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
<?php bc_plate_auth_end(array('links' => array(array('href' => 'Login.php', 'label' => 'Back to sign in')))); ?>

<?php session_start();
    include("../func/bc-config.php");
require_once(__DIR__ . "/../func/bc-plate-auth.php");

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

<?php
$otp_email_masked = htmlspecialchars(substr($user_detail['email'], 0, 3) . '...' . substr($user_detail['email'], (int)strpos($user_detail['email'], '@')));
bc_plate_auth_head('Verify login | ' . $get_all_site_details["site_title"]);
bc_plate_auth_top(array(
    'icon'  => 'shield',
    'title' => 'Two-factor authentication',
    'sub'   => 'A verification code has been sent to <b>' . $otp_email_masked . '</b>.',
    'alert' => isset($error) ? array(array('type' => 'error', 'body' => $error)) : null,
));
?>
        <form method="post" action="">
            <div class="pk-auth__field">
                <label class="pk-auth__label" for="otp">Enter 6-digit code</label>
                <input id="otp" name="otp" type="text" maxlength="6" pattern="[0-9]{6}" inputmode="numeric"
                       required autofocus class="pk-auth__input pk-auth__otp" placeholder="000000">
                <p class="pk-auth__note" style="margin-top:10px;">
                    Code expires in <span id="timer" style="color: var(--danger); font-weight: 700;">10:00</span>
                </p>
            </div>
            <div class="pk-auth__field">
                <button name="verify-otp" type="submit" class="pk-auth__btn">Verify &amp; continue</button>
            </div>
            <p class="pk-auth__note">
                Did not get the code? <a class="pk-auth__link" href="?resend=1">Resend</a>
            </p>
        </form>
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
<?php
bc_plate_auth_end();
?>

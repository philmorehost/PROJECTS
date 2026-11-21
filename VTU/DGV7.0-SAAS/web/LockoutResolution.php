<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
 bc_secure_session_start();
include("../func/bc-config.php");
require_once(__DIR__ . "/../func/bc-plate-auth.php");

$ip = $_SERVER['REMOTE_ADDR'];
$vendor_id = resolveVendorID();
$error = "";
$success = "";

if (isset($_POST['resolve'])) {
    $username = mysqli_real_escape_string($connection_server, trim($_POST['username']));
    $pin = mysqli_real_escape_string($connection_server, $_POST['pin']);

    if (empty($username) || empty($pin)) {
        $error = "Please provide both username and Security PIN.";
    } else {
        // Find user in any table
        $user_found = null;
        $table = "";
        $id_col = "id";
        $user_col = "username";

        $q1 = mysqli_query($connection_server, "SELECT * FROM sas_users WHERE username='$username' AND vendor_id='$vendor_id'");
        if (mysqli_num_rows($q1) > 0) {
            $user_found = mysqli_fetch_assoc($q1);
            $table = "sas_users";
        } else {
            $q2 = mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE email='$username' AND id='$vendor_id'");
            if (mysqli_num_rows($q2) > 0) {
                $user_found = mysqli_fetch_assoc($q2);
                $table = "sas_vendors";
                $user_col = "email";
            } else {
                $q3 = mysqli_query($connection_server, "SELECT * FROM sas_super_admin WHERE email='$username'");
                if (mysqli_num_rows($q3) > 0) {
                    $user_found = mysqli_fetch_assoc($q3);
                    $table = "sas_super_admin";
                    $user_col = "email";
                }
            }
        }

        if (!$user_found) {
            $error = "User not found.";
        } else {
            $_SESSION["last_resolved_table"] = $table;
            if (empty($user_found['security_pin'])) {
                $error = "Security PIN has not been set for this account. Please contact support for manual unblocking.";
            } elseif ($user_found['failed_pin_count'] >= 3 && strtotime($user_found['last_failed_pin']) > (time() - 3600)) {
                // Check lockout duration for PIN attempts (1 hour after 3 failures)
                $error = "Too many failed PIN attempts. Please try again in 1 hour.";
            } else {
                $pin_valid = verifyUserPIN($pin, $user_found);

                if ($pin_valid) {
                    // If it was a legacy user PIN, auto-migrate to hashed security_pin
                    if ($table === 'sas_users' && !empty($user_found['transaction_pin']) && empty($user_found['security_pin'])) {
                        $h_pin = password_hash($pin, PASSWORD_DEFAULT);
                        mysqli_query($connection_server, "UPDATE sas_users SET security_pin='$h_pin' WHERE id='".$user_found['id']."'");
                    }
                    // Success! Reset everything
                    mysqli_query($connection_server, "UPDATE $table SET status = 1, is_blocked = 0, failed_login_count = 0, failed_pin_count = 0 WHERE $id_col = '".$user_found['id']."'");

                    // Unblock IP and Account if they exist in block tables
                    mysqli_query($connection_server, "DELETE FROM sas_blocked_ips WHERE ip_address = '$ip' AND vendor_id = '$vendor_id'");
                    mysqli_query($connection_server, "DELETE FROM sas_blocked_accounts WHERE username = '$username' AND vendor_id = '$vendor_id'");

                    $success = "Verification successful! Your account and IP have been unblocked. You can now log in.";
                } else {
                    mysqli_query($connection_server, "UPDATE $table SET failed_pin_count = failed_pin_count + 1, last_failed_pin = NOW() WHERE $id_col = '".$user_found['id']."'");
                    $error = "Incorrect Security PIN. Please check and try again.";
                }
            }
        }
    }
}

?>

<?php
$lockout_success = !empty($success);
$lockout_redirect = "Login.php";
if (isset($_SESSION["last_resolved_table"])) {
    if ($_SESSION["last_resolved_table"] === "sas_vendors") $lockout_redirect = "../bc-admin/Login.php";
    elseif ($_SESSION["last_resolved_table"] === "sas_super_admin") $lockout_redirect = "../bc-spadmin/Login.php";
    // Consumed once: the target is resolved for this render only, as before.
    unset($_SESSION["last_resolved_table"]);
}
bc_plate_auth_head('Account recovery');
bc_plate_auth_top(array(
    'icon'      => $lockout_success ? 'check' : 'key',
    'icon_style'=> $lockout_success ? '' : 'warn',
    'title'     => 'Security recovery',
    'sub'       => $lockout_success
        ? $success
        : 'Self-help unblocking using your Security PIN.',
    'alert'     => !empty($error) ? array(array('type' => 'error', 'body' => $error)) : null,
));
?>
        <?php if ($lockout_success): ?>
            <div class="pk-auth__field">
                <a class="pk-auth__btn" href="<?php echo htmlspecialchars($lockout_redirect); ?>">
                    <?php echo bc_plate_auth_icon('arrow', 18); ?> Go to login
                </a>
            </div>
        <?php else: ?>
            <form method="post">
                <div class="pk-auth__field">
                    <label class="pk-auth__label" for="username">Username or email</label>
                    <input id="username" type="text" name="username" class="pk-auth__input" required placeholder="Enter your username">
                </div>
                <div class="pk-auth__field">
                    <label class="pk-auth__label" for="pin">4-digit security PIN</label>
                    <input id="pin" type="password" name="pin" class="pk-auth__input pk-auth__otp" maxlength="4"
                           pattern="\d{4}" inputmode="numeric" required placeholder="****">
                </div>
                <div class="pk-auth__field">
                    <button type="submit" name="resolve" class="pk-auth__btn">Verify &amp; unblock</button>
                </div>
                <p class="pk-auth__note">
                    <a class="pk-auth__link" href="Login.php">Back to login</a>
                </p>
            </form>
        <?php endif; ?>
<?php
bc_plate_auth_end();
?>

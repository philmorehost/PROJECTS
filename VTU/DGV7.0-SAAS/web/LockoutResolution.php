<?php session_start();
include("../func/bc-config.php");

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
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Account Recovery | Lockout Resolution</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
    <style>
        body { background: #f6f9ff; display: flex; align-items: center; justify-content: center; min-vh-100; }
        .card { border-radius: 20px; border: none; box-shadow: 0 10px 30px rgba(0,0,0,0.05); }
    </style>
</head>
<body>
    <div class="container" style="max-width: 500px;">
        <div class="text-center mb-4">
            <h2 class="fw-bold">Security Recovery</h2>
            <p class="text-muted">Self-help unblocking using your Security PIN</p>
        </div>

        <div class="card p-4">
            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>

            <?php if ($success):
                $redirect_url = "Login.php";
                if (isset($_SESSION["last_resolved_table"])) {
                    if ($_SESSION["last_resolved_table"] === "sas_vendors") $redirect_url = "../bc-admin/Login.php";
                    elseif ($_SESSION["last_resolved_table"] === "sas_super_admin") $redirect_url = "../bc-spadmin/Login.php";
                    unset($_SESSION["last_resolved_table"]);
                }
            ?>
                <div class="alert alert-success">
                    <i class="bi bi-check-circle-fill me-2"></i> <?php echo $success; ?>
                    <div class="mt-3">
                        <a href="<?php echo $redirect_url; ?>" class="btn btn-primary w-100">Go to Login</a>
                    </div>
                </div>
            <?php else: ?>
                <form method="post">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Username / Email</label>
                        <input type="text" name="username" class="form-control form-control-lg" required placeholder="Enter your username">
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-bold">4-Digit Security PIN</label>
                        <input type="password" name="pin" class="form-control form-control-lg text-center fw-bold" maxlength="4" pattern="\d{4}" inputmode="numeric" required placeholder="****" style="letter-spacing: 10px; font-size: 24px;">
                    </div>
                    <button type="submit" name="resolve" class="btn btn-primary btn-lg w-100 fw-bold">VERIFY & UNBLOCK</button>
                    <div class="text-center mt-3">
                        <a href="Login.php" class="text-decoration-none small fw-bold"><i class="bi bi-arrow-left"></i> Back to Login</a>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>

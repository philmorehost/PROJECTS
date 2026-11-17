<?php
session_start();
include 'includes/db_connect.php';
include 'includes/header.php';

$message = '';
$error = '';
$code = isset($_GET['code']) ? $_GET['code'] : (isset($_POST['code']) ? $_POST['code'] : '');

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $code = $_POST['code'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    if (empty($code)) {
        $error = "The reset code is required.";
    } elseif ($new_password !== $confirm_password) {
        $error = "The new passwords do not match.";
    } elseif (strlen($new_password) < 8) {
        $error = "Password must be at least 8 characters long.";
    } else {
        // Find the OTP code in the database
        $stmt = $mysqli->prepare("SELECT * FROM otp_codes WHERE otp_code = ? AND purpose = 'password_reset' AND is_used = 0 AND expires_at > NOW()");
        $stmt->bind_param("s", $code);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $otp_data = $result->fetch_assoc();
            $user_id = $otp_data['user_id'];

            // Hash the new password
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

            // Update the user's password
            $stmt_update = $mysqli->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt_update->bind_param("si", $hashed_password, $user_id);

            if ($stmt_update->execute()) {
                // Mark the OTP as used
                $stmt_use_otp = $mysqli->prepare("UPDATE otp_codes SET is_used = 1 WHERE id = ?");
                $stmt_use_otp->bind_param("i", $otp_data['id']);
                $stmt_use_otp->execute();
                $stmt_use_otp->close();

                $message = "Your password has been reset successfully! You can now <a href='login.php'>log in</a> with your new password.";
            } else {
                $error = "An error occurred while updating your password. Please try again.";
            }
            $stmt_update->close();
        } else {
            $error = "Invalid or expired reset code. Please request a new one.";
        }
        $stmt->close();
    }
}
// NOTE: do NOT call $mysqli->close() here - includes/footer.php queries the
// modal_ads table after this point and a closed connection throws.
?>

<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h4>Reset Password</h4>
                </div>
                <div class="card-body">
                    <?php if ($message): ?>
                        <div class="alert alert-success"><?php echo $message; ?></div>
                    <?php else: ?>
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?php echo $error; ?></div>
                        <?php endif; ?>

                        <form action="reset_password.php" method="post">
                            <div class="form-group">
                                <label for="code">Reset Code</label>
                                <input type="text" name="code" id="code" class="form-control" value="<?php echo htmlspecialchars($code); ?>" required>
                            </div>
                            <br>
                            <div class="form-group">
                                <label for="new_password">New Password</label>
                                <input type="password" name="new_password" id="new_password" class="form-control" required>
                            </div>
                            <br>
                            <div class="form-group">
                                <label for="confirm_password">Confirm New Password</label>
                                <input type="password" name="confirm_password" id="confirm_password" class="form-control" required>
                            </div>
                            <br>
                            <button type="submit" class="btn btn-primary">Reset Password</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

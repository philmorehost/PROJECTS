<?php
session_start();
include 'includes/db_connect.php';
include 'includes/header.php';

$message = '';
$error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = $_POST['email'];

    // Check if the user exists
    $stmt = $mysqli->prepare("SELECT id, username FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        $user_id = $user['id'];

        // Generate a unique, random OTP code
        $otp_code = substr(bin2hex(random_bytes(4)), 0, 8); // 8-character hex code
        $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));

        // Store the OTP in the database
        $stmt_otp = $mysqli->prepare("INSERT INTO otp_codes (user_id, otp_code, expires_at, purpose) VALUES (?, ?, ?, 'password_reset')");
        $stmt_otp->bind_param("iss", $user_id, $otp_code, $expires_at);

        if ($stmt_otp->execute()) {
            // Send the OTP to the user's email
            $to = $email;
            $subject = "Your Password Reset Code";
            $reset_link = "http://" . $_SERVER['HTTP_HOST'] . "/reset_password.php?code=" . $otp_code;
            $email_message = "Hi " . $user['username'] . ",\n\n";
            $email_message .= "You requested a password reset. Click the link below or use the code to reset your password:\n";
            $email_message .= "Your code is: " . $otp_code . "\n";
            $email_message .= "Reset Link: " . $reset_link . "\n\n";
            $email_message .= "This code will expire in one hour.\n";
            $headers = "From: no-reply@yourdomain.com"; // Replace with your actual sending email

            if (mail($to, $subject, $email_message, $headers)) {
                $message = "A password reset link has been sent to your email.";
            } else {
                // For development: display the code if email fails
                $error = "Failed to send email. For development, your reset code is: <strong>$otp_code</strong> and the link is <a href='$reset_link'>$reset_link</a>";
            }
        } else {
            $error = "Error storing the reset code. Please try again.";
        }
        $stmt_otp->close();

    } else {
        $error = "No user found with that email address.";
    }
    $stmt->close();
}
// NOTE: do NOT call $mysqli->close() here - includes/footer.php queries the
// modal_ads table after this point and a closed connection throws.
?>

<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h4>Forgot Password</h4>
                </div>
                <div class="card-body">
                    <?php if ($message): ?>
                        <div class="alert alert-success"><?php echo $message; ?></div>
                    <?php endif; ?>
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?php echo $error; ?></div>
                    <?php endif; ?>

                    <p>Enter your email address and we will send you a link to reset your password.</p>

                    <form action="forgot_password.php" method="post">
                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <input type="email" name="email" id="email" class="form-control" required>
                        </div>
                        <br>
                        <button type="submit" class="btn btn-primary">Send Reset Link</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

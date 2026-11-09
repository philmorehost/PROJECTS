<?php
// Admin forgot password - uses the same DB and email logic as the storefront version
session_start();
require_once '../includes/db_connect.php';
require_once '../includes/send_email.php';

$message = '';
$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $stmt = $mysqli->prepare("SELECT id, username, email FROM users WHERE email = ? AND role_id IS NOT NULL");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            $user_id = $user['id'];
            $username = $user['username'];

            // Generate OTP
            $otp = rand(100000, 999999);
            $otp_expiry = date('Y-m-d H:i:s', strtotime('+10 minutes'));

            // Invalidate old OTPs
            $stmt_inv = $mysqli->prepare("UPDATE otp_codes SET is_used = 1 WHERE user_id = ?");
            $stmt_inv->bind_param("i", $user_id);
            $stmt_inv->execute();
            $stmt_inv->close();

            // Insert new OTP
            $stmt_otp = $mysqli->prepare("INSERT INTO otp_codes (user_id, otp_code, expires_at, purpose) VALUES (?, ?, ?, 'admin_reset')");
            $stmt_otp->bind_param("iss", $user_id, $otp, $otp_expiry);
            $stmt_otp->execute();
            $stmt_otp->close();

            // Send email
            $subject = "Admin Password Reset - Eflex";
            $body = "<h1>Admin Password Reset</h1>"
                  . "<p>Hello <strong>" . htmlspecialchars($username) . "</strong>,</p>"
                  . "<p>Your admin password reset OTP is: <strong>" . $otp . "</strong></p>"
                  . "<p>This code expires in 10 minutes.</p>"
                  . "<p><a href='http://" . $_SERVER['HTTP_HOST'] . "/admin/verify_otp.php?user_id=" . $user_id . "'>Click here to reset</a></p>";
            send_email($email, $subject, $body);

            $message = '<div class="alert alert-success">Reset instructions sent to your email.</div>';
        } else {
            $message = '<div class="alert alert-info">If an admin account exists with that email, instructions have been sent.</div>';
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Forgot Password</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link href="../css/custom_style.css" rel="stylesheet">
    <style>
        body { display: flex; align-items: center; justify-content: center; height: 100vh; background-color: #f8f9fa; }
        .login-form { width: 100%; max-width: 330px; padding: 15px; margin: auto; }
    </style>
</head>
<body>
<main class="login-form text-center">
    <h1 class="h3 mb-3 fw-normal">Admin Forgot Password</h1>
    <?php if ($message) echo $message; ?>
    <?php if ($error) echo '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>'; ?>
    <form method="post" action="forgot_password.php">
        <div class="form-floating mb-3">
            <input type="email" name="email" class="form-control" id="floatingEmail" placeholder="Admin email" value="<?php echo htmlspecialchars($email); ?>" required>
            <label for="floatingEmail">Admin Email</label>
        </div>
        <button class="w-100 btn btn-lg btn-primary" type="submit">Send Reset Link</button>
        <p class="mt-3"><a href="index.php">Back to login</a></p>
    </form>
</main>
</body>
</html>

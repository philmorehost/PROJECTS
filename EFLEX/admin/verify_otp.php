<?php
// Initialize the session
session_start();

// If the user is not in the OTP verification process, redirect to login
if (!isset($_SESSION["otp_user_id"])) {
    header("location: index.php");
    exit;
}

// Include database connection file
require_once "../includes/db_connect.php";

$otp_err = "";

// Processing form data when form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $submitted_otp = trim($_POST['otp']);
    $user_id = $_SESSION['otp_user_id'];

    if (empty($submitted_otp)) {
        $otp_err = "Please enter the OTP.";
    } else {
        // Find the latest, unused OTP for the user
        $sql = "SELECT otp_code, expires_at FROM otp_codes WHERE user_id = ? AND is_used = 0 ORDER BY id DESC LIMIT 1";

        if ($stmt = $mysqli->prepare($sql)) {
            $stmt->bind_param("i", $user_id);
            if ($stmt->execute()) {
                $stmt->store_result();
                if ($stmt->num_rows == 1) {
                    $stmt->bind_result($db_otp, $expires_at);
                    if ($stmt->fetch()) {
                        $current_time = date('Y-m-d H:i:s');
                        if ($expires_at < $current_time) {
                            $otp_err = "The OTP has expired. Please try logging in again.";
                        } elseif ($submitted_otp === $db_otp) {
                            // OTP is correct
                            // Mark OTP as used
                            $update_sql = "UPDATE otp_codes SET is_used = 1 WHERE user_id = ? AND otp_code = ?";
                            if($update_stmt = $mysqli->prepare($update_sql)){
                                $update_stmt->bind_param("is", $user_id, $db_otp);
                                $update_stmt->execute();
                                $update_stmt->close();
                            }

                            // Fetch user details to set session
                            $user_sql = "SELECT id, username, role_id FROM users WHERE id = ?";
                            if($user_stmt = $mysqli->prepare($user_sql)){
                                $user_stmt->bind_param("i", $user_id);
                                $user_stmt->execute();
                                $user_stmt->store_result();
                                if($user_stmt->num_rows == 1){
                                    $user_stmt->bind_result($id, $username, $role_id);
                                    if($user_stmt->fetch()){
                                        // Set session variables
                                        $_SESSION["loggedin"] = true;
                                        $_SESSION["id"] = $id;
                                        $_SESSION["username"] = $username;
                                        $_SESSION["role_id"] = $role_id;
                                        unset($_SESSION["otp_user_id"]);

                                        header("location: dashboard.php");
                                        exit;
                                    }
                                }
                            }
                        } else {
                            $otp_err = "The OTP you entered is incorrect.";
                        }
                    }
                } else {
                    $otp_err = "No pending OTP found. Please try logging in again.";
                }
            } else {
                $otp_err = "Oops! Something went wrong. Please try again.";
            }
            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify OTP</title>
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link href="../css/custom_style.css" rel="stylesheet">
    <style>
        body { display: flex; align-items: center; justify-content: center; height: 100vh; background-color: #f8f9fa; }
        .login-form { width: 100%; max-width: 330px; padding: 15px; margin: auto; }
    </style>
</head>
<body>

<main class="login-form text-center">
    <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
        <h1 class="h3 mb-3 fw-normal">Two-Factor Authentication</h1>
        <p class="mb-3">An OTP has been sent to your email address. Please enter it below.</p>

        <?php if(!empty($otp_err)){ echo '<div class="alert alert-danger">' . $otp_err . '</div>'; } ?>

        <div class="form-floating mb-3">
            <input type="text" name="otp" class="form-control" id="floatingOtp" placeholder="123456" required>
            <label for="floatingOtp">OTP Code</label>
        </div>

        <button class="w-100 btn btn-lg btn-primary" type="submit">Verify</button>
        <p class="mt-4">Didn't receive the code? <a href="index.php">Go back to login</a>.</p>
        <p class="mt-5 mb-3 text-muted">&copy; <?php echo date("Y"); ?> Eflex E-commerce</p>
    </form>
</main>

<script src="../js/bootstrap.bundle.min.js"></script>
</body>
</html>

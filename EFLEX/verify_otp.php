<?php
// Initialize the session
session_start();

// If the user is not in the OTP verification process, redirect to register
if (!isset($_SESSION["unverified_user_id"])) {
    header("location: register.php");
    exit;
}

// Include database connection
require_once "includes/db_connect.php";

$otp_err = "";
$user_id = $_SESSION['unverified_user_id'];

// Resend OTP logic
if ($_SERVER["REQUEST_METHOD"] == "GET" && isset($_GET['resend'])) {
    // Generate a new OTP
    $otp = rand(100000, 999999);
    $otp_expiry = date('Y-m-d H:i:s', strtotime('+10 minutes'));

    // Invalidate old OTPs for this user
    if ($stmt = $mysqli->prepare("UPDATE otp_codes SET is_used = 1 WHERE user_id = ?")) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();
    }

    // Insert new OTP
    $otp_sql = "INSERT INTO otp_codes (user_id, otp_code, expires_at) VALUES (?, ?, ?)";
    if($otp_stmt = $mysqli->prepare($otp_sql)){
        $otp_stmt->bind_param("iss", $user_id, $otp, $otp_expiry);
        $otp_stmt->execute();
        $otp_stmt->close();
    }

    // Fetch user's email to send the new OTP
    $stmt = $mysqli->prepare("SELECT email FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $email_res = $stmt->get_result();
    if($email_res->num_rows == 1) {
        $user_email = $email_res->fetch_assoc()['email'];
        require_once "includes/send_email.php";
        $subject = "Your New Verification Code";
        $body = "<p>Your new One-Time Password (OTP) is: <strong>$otp</strong></p>";
        send_email($user_email, $subject, $body);
        // Add a success message to display to the user
        $_SESSION['resend_success'] = "A new OTP has been sent to your email address.";
    }
    // Redirect back to the same page to prevent re-submission on refresh
    header("location: verify_otp.php");
    exit;
}


// Processing form data when form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $submitted_otp = trim($_POST['otp']);

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
                            $otp_err = "The OTP has expired. Please request a new one.";
                        } elseif ($submitted_otp === $db_otp) {
                            // OTP is correct, update user to be verified
                            $update_sql = "UPDATE users SET is_verified = 1 WHERE id = ?";
                            if($update_stmt = $mysqli->prepare($update_sql)){
                                $update_stmt->bind_param("i", $user_id);
                                $update_stmt->execute();
                                $update_stmt->close();
                            }

                            // Mark OTP as used
                            if ($update_stmt_used = $mysqli->prepare("UPDATE otp_codes SET is_used = 1 WHERE user_id = ?")) {
                                $update_stmt_used->bind_param("i", $user_id);
                                $update_stmt_used->execute();
                                $update_stmt_used->close();
                            }

                            // Log the user in
                            $user_sql = "SELECT id, username FROM users WHERE id = ?";
                             if($user_stmt = $mysqli->prepare($user_sql)){
                                $user_stmt->bind_param("i", $user_id);
                                $user_stmt->execute();
                                $user_stmt->store_result();
                                if($user_stmt->num_rows == 1){
                                    $user_stmt->bind_result($id, $username);
                                    if($user_stmt->fetch()){
                                        $_SESSION["loggedin"] = true;
                                        $_SESSION["id"] = $id;
                                        $_SESSION["username"] = $username;
                                        // Customers don't need a role_id in session for basic site access
                                        unset($_SESSION["unverified_user_id"]);
                                        // Redirect to cart page as requested
                                        header("location: cart.php");
                                        exit;
                                    }
                                }
                            }
                        } else {
                            $otp_err = "The OTP you entered is incorrect.";
                        }
                    }
                } else {
                    $otp_err = "No pending OTP found. Please try registering again or request a new one.";
                }
            } else {
                $otp_err = "Oops! Something went wrong. Please try again.";
            }
            $stmt->close();
        }
    }
}

// Include the header
include 'includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-6">
        <h2>Verify Your Account</h2>
        <p>An OTP has been sent to your email address. Please enter it below to verify your account.</p>

        <?php
        if(!empty($otp_err)){ echo '<div class="alert alert-danger">' . $otp_err . '</div>'; }
        if(isset($_SESSION['resend_success'])){
            echo '<div class="alert alert-success">' . $_SESSION['resend_success'] . '</div>';
            unset($_SESSION['resend_success']);
        }
        ?>

        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
            <div class="form-group mb-3">
                <label>OTP Code</label>
                <input type="text" name="otp" class="form-control" required>
            </div>
            <div class="form-group">
                <input type="submit" class="btn btn-primary" value="Verify">
            </div>
            <p class="mt-3">Didn't receive the code? <a href="verify_otp.php?resend=true">Resend OTP</a>.</p>
        </form>
    </div>
</div>

<?php
// Include the footer
include 'includes/footer.php';
?>

<?php
// Initialize the session
session_start();

// Check if the user is already logged in, if yes then redirect him to welcome page
if(isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true){
    header("location: index.php");
    exit;
}

// Include database connection and email function
require_once "includes/db_connect.php";
require_once "includes/send_email.php";
require_once "includes/send_notification.php";

// Fetch all settings
$settings_sql = "SELECT setting_key, setting_value FROM settings";
$result = $mysqli->query($settings_sql);
$settings = [];
while($row = $result->fetch_assoc()){
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Define variables and initialize with empty values
$username = $email = $password = $confirm_password = "";
$username_err = $email_err = $password_err = $confirm_password_err = "";

// Processing form data when form is submitted
if($_SERVER["REQUEST_METHOD"] == "POST"){

    // Validate username
    if(empty(trim($_POST["username"]))){
        $username_err = "Please enter a username.";
    } elseif(!preg_match('/^[a-zA-Z0-9_]+$/', trim($_POST["username"]))){
        $username_err = "Username can only contain letters, numbers, and underscores.";
    } else{
        // Prepare a select statement
        $sql = "SELECT id FROM users WHERE username = ?";

        if($stmt = $mysqli->prepare($sql)){
            $stmt->bind_param("s", $param_username);
            $param_username = trim($_POST["username"]);

            if($stmt->execute()){
                $stmt->store_result();

                if($stmt->num_rows == 1){
                    $username_err = "This username is already taken.";
                } else{
                    $username = trim($_POST["username"]);
                }
            } else{
                echo "Oops! Something went wrong. Please try again later.";
            }
            $stmt->close();
        }
    }

    // Validate email
    if(empty(trim($_POST["email"]))){
        $email_err = "Please enter an email.";
    } elseif(!filter_var(trim($_POST["email"]), FILTER_VALIDATE_EMAIL)) {
        $email_err = "Please enter a valid email.";
    } else {
         $sql = "SELECT id FROM users WHERE email = ?";

         if($stmt = $mysqli->prepare($sql)){
             $stmt->bind_param("s", $param_email);
             $param_email = trim($_POST["email"]);

             if($stmt->execute()){
                 $stmt->store_result();
                 if($stmt->num_rows == 1){
                     $email_err = "This email is already taken.";
                 } else{
                     $email = trim($_POST["email"]);
                 }
             } else{
                 echo "Oops! Something went wrong. Please try again later.";
             }
             $stmt->close();
         }
    }

    // Validate password
    if(empty(trim($_POST["password"]))){
        $password_err = "Please enter a password.";
    } elseif(strlen(trim($_POST["password"])) < 6){
        $password_err = "Password must have at least 6 characters.";
    } else{
        $password = trim($_POST["password"]);
    }

    // Validate confirm password
    if(empty(trim($_POST["confirm_password"]))){
        $confirm_password_err = "Please confirm password.";
    } else{
        $confirm_password = trim($_POST["confirm_password"]);
        if(empty($password_err) && ($password != $confirm_password)){
            $confirm_password_err = "Password did not match.";
        }
    }

    // Check input errors before inserting in database
    if(empty($username_err) && empty($email_err) && empty($password_err) && empty($confirm_password_err)){

        // Sanitize phone and address
        $phone = !empty($_POST['phone']) ? trim($_POST['phone']) : null;
        $address = !empty($_POST['address']) ? trim($_POST['address']) : null;

        // Get OTP setting from the pre-fetched settings array
        $otp_register_enabled = $settings['otp_register_enabled'] ?? '0';

        if ($otp_register_enabled == '1') {
            // OTP flow: Create user as unverified and send OTP
            $sql = "INSERT INTO users (username, email, password, phone, address, is_verified) VALUES (?, ?, ?, ?, ?, 0)";
            if($stmt = $mysqli->prepare($sql)){
                $stmt->bind_param("sssss", $param_username, $param_email, $param_password, $phone, $address);
                $param_username = $username;
                $param_email = $email;
                $param_password = password_hash($password, PASSWORD_DEFAULT);

                if($stmt->execute()){
                    $user_id = $stmt->insert_id;

                    // Generate and send OTP
                    $otp = rand(100000, 999999);
                    $otp_expiry = date('Y-m-d H:i:s', strtotime('+10 minutes'));
                    $otp_sql = "INSERT INTO otp_codes (user_id, otp_code, expires_at) VALUES (?, ?, ?)";
                    if($otp_stmt = $mysqli->prepare($otp_sql)){
                        $otp_stmt->bind_param("iss", $user_id, $otp, $otp_expiry);
                        $otp_stmt->execute();
                        $otp_stmt->close();
                    }

                    $subject = "Verify Your Email Address";
                    $body = "<h1>Welcome to Eflex!</h1>"
                          . "<p>Your One-Time Password (OTP) for account verification is: <strong>$otp</strong></p>"
                          . "<p>This code will expire in 10 minutes.</p>"
                          . "<p>Best regards,<br>The Eflex Team</p>";
                    send_email($email, $subject, $body);

                    // --- Start Admin Notification ---
                    $admin_subject = "New User Registration (Pending Verification)";
                    $admin_body = "A new user has registered on your website and is pending email verification.<br><br>"
                                . "<strong>Username:</strong> " . htmlspecialchars($username) . "<br>"
                                . "<strong>Email:</strong> " . htmlspecialchars($email);
                    send_admin_notification($admin_subject, $admin_body);
                    // --- End Admin Notification ---

                    $_SESSION['unverified_user_id'] = $user_id;
                    header("location: verify_otp.php");
                    exit();
                } else {
                    echo "Oops! Something went wrong. Please try again later.";
                }
                $stmt->close();
            }
        } else {
            // Standard flow: Create user as verified
            $sql = "INSERT INTO users (username, email, password, phone, address, is_verified) VALUES (?, ?, ?, ?, ?, 1)";
            if($stmt = $mysqli->prepare($sql)){
                $stmt->bind_param("sssss", $param_username, $param_email, $param_password, $phone, $address);
                $param_username = $username;
                $param_email = $email;
                $param_password = password_hash($password, PASSWORD_DEFAULT);

                if($stmt->execute()){
                    // Also log the user in directly
                    $_SESSION["loggedin"] = true;
                    $_SESSION["id"] = $stmt->insert_id;
                    $_SESSION["username"] = $username;
                    $_SESSION["email"] = $email; // Also save email to session

                    $subject = "Welcome to Eflex!";
                    $body = "<h1>Welcome, " . htmlspecialchars($username) . "!</h1><p>Thank you for registering. Your account is active.</p>";
                    send_email($email, $subject, $body);

                    // --- Start Admin Notification ---
                    $admin_subject = "New User Registration on Eflex";
                    $admin_body = "A new user has registered on your website.<br><br>"
                                . "<strong>Username:</strong> " . htmlspecialchars($username) . "<br>"
                                . "<strong>Email:</strong> " . htmlspecialchars($email);
                    send_admin_notification($admin_subject, $admin_body);
                    // --- End Admin Notification ---

                    // Check for a redirect URL
                    if(isset($_SESSION['redirect_to_url'])){
                        $redirect_url = $_SESSION['redirect_to_url'];
                        unset($_SESSION['redirect_to_url']);
                        header("location: " . $redirect_url);
                    } else {
                        // Redirect user to index page
                        header("location: index.php");
                    }
                    exit();
                } else {
                    echo "Oops! Something went wrong. Please try again later.";
                }
                $stmt->close();
            }
        }
    }
}

// Now, include the header which starts the HTML
include 'includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-6">
        <h2>Register</h2>
        <p>Please fill this form to create an account.</p>
        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
            <div class="form-group mb-3">
                <label>Username</label>
                <input type="text" name="username" class="form-control <?php echo (!empty($username_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($username); ?>">
                <span class="invalid-feedback"><?php echo $username_err; ?></span>
            </div>
            <div class="form-group mb-3">
                <label>Email</label>
                <input type="email" name="email" class="form-control <?php echo (!empty($email_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($email); ?>">
                <span class="invalid-feedback"><?php echo $email_err; ?></span>
            </div>
            <div class="form-group mb-3">
                <label>Phone</label>
                <input type="text" name="phone" class="form-control" value="">
            </div>
            <div class="form-group mb-3">
                <label>Address</label>
                <textarea name="address" class="form-control"></textarea>
            </div>
            <div class="form-group mb-3">
                <label>Password</label>
                <input type="password" name="password" class="form-control <?php echo (!empty($password_err)) ? 'is-invalid' : ''; ?>" value="<?php echo $password; ?>">
                <span class="invalid-feedback"><?php echo $password_err; ?></span>
            </div>
            <div class="form-group mb-3">
                <label>Confirm Password</label>
                <input type="password" name="confirm_password" class="form-control <?php echo (!empty($confirm_password_err)) ? 'is-invalid' : ''; ?>" value="<?php echo $confirm_password; ?>">
                <span class="invalid-feedback"><?php echo $confirm_password_err; ?></span>
            </div>
            <div class="form-group">
                <input type="submit" class="btn btn-primary" value="Submit">
                <input type="reset" class="btn btn-secondary ml-2" value="Reset">
            </div>
            <p>Already have an account? <a href="login.php">Login here</a>.</p>
        </form>
    </div>
</div>

<?php
// Include the footer
include 'includes/footer.php';
?>

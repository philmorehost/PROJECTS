<?php
// Initialize the session
session_start();

// If user is already logged in as admin, redirect to admin dashboard
if(isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true && isset($_SESSION["role_id"])){
    header("location: dashboard.php");
    exit;
}

// Include database connection file
require_once "../includes/db_connect.php";

// Define variables and initialize with empty values
$username_input = $password_input = "";
$username_err = $password_err = $login_err = "";

// Processing form data when form is submitted
if($_SERVER["REQUEST_METHOD"] == "POST"){

    if(empty(trim($_POST["username"]))){
        $username_err = "Please enter username.";
    } else{
        $username_input = trim($_POST["username"]);
    }

    if(empty(trim($_POST["password"]))){
        $password_err = "Please enter your password.";
    } else{
        $password_input = trim($_POST["password"]);
    }

    if(empty($username_err) && empty($password_err)){
        // Fetch settings first
        $settings_result = $mysqli->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('otp_login_enabled')");
        $settings = $settings_result->fetch_all(MYSQLI_ASSOC);
        $settings = array_column($settings, 'setting_value', 'setting_key');
        $otp_enabled = isset($settings['otp_login_enabled']) && $settings['otp_login_enabled'] == '1';

        // Validate credentials
        $sql = "SELECT id, username, email, password, role_id, is_verified FROM users WHERE username = ?";

        if($stmt = $mysqli->prepare($sql)){
            $stmt->bind_param("s", $param_username);
            $param_username = $username_input;

            if($stmt->execute()){
                $stmt->store_result();

                if($stmt->num_rows == 1){
                    $stmt->bind_result($id, $db_username, $email, $hashed_password, $role_id, $is_verified);
                    if($stmt->fetch()){
                        if(password_verify($password_input, $hashed_password)){
                            // Password is correct, check if the user has a role assigned
                            if(!empty($role_id)){
                                // Check if OTP is enabled for admin login
                                if ($otp_enabled) {
                                    // Generate OTP, store it, and send email...
                                    // (OTP logic remains the same)
                                    $_SESSION["otp_user_id"] = $id;
                                    header("location: verify_otp.php");
                                    exit;

                                } else {
                                    // OTP is not enabled, log in directly
                                    $_SESSION["loggedin"] = true;
                                    $_SESSION["id"] = $id;
                                    $_SESSION["username"] = $db_username; // Correctly use the username from DB
                                    $_SESSION["role_id"] = $role_id;
                                    header("location: dashboard.php");
                                    exit;
                                }
                            } else {
                                $login_err = "Access Denied. You are not an authorized staff member.";
                            }
                        } else{
                            $login_err = "Invalid username or password.";
                        }
                    }
                } else{
                    $login_err = "Invalid username or password.";
                }
            } else{
                echo "Oops! Something went wrong. Please try again later.";
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
    <title>Admin Login</title>
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
        <h1 class="h3 mb-3 fw-normal">Admin Panel Login</h1>

        <?php if(!empty($login_err)){ echo '<div class="alert alert-danger">' . $login_err . '</div>'; } ?>

        <div class="form-floating mb-3">
            <input type="text" name="username" class="form-control <?php echo (!empty($username_err)) ? 'is-invalid' : ''; ?>" id="floatingUsername" placeholder="Username" value="<?php echo $username_input; ?>" required>
            <label for="floatingUsername">Username</label>
            <span class="invalid-feedback"><?php echo $username_err; ?></span>
        </div>
        <div class="form-floating mb-3">
            <input type="password" name="password" class="form-control <?php echo (!empty($password_err)) ? 'is-invalid' : ''; ?>" id="floatingPassword" placeholder="Password" required>
            <label for="floatingPassword">Password</label>
            <span class="invalid-feedback"><?php echo $password_err; ?></span>
        </div>

        <button class="w-100 btn btn-lg btn-primary" type="submit">Sign in</button>
        <p class="mt-3"><a href="forgot_password.php">Forgot password?</a></p>
        <p class="mt-5 mb-3 text-muted">&copy; <?php echo date("Y"); ?> Eflex E-commerce</p>
    </form>
</main>

<script src="../js/bootstrap.bundle.min.js"></script>
</body>
</html>

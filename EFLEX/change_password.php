<?php
// We need to start the session on all pages to access session variables
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is logged in, if not then redirect to login page
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: login.php");
    exit;
}

// Include database connection
require_once 'includes/db_connect.php';

$current_password_err = $new_password_err = $confirm_new_password_err = "";
$success_message = "";

// Processing form data when form is submitted
if($_SERVER["REQUEST_METHOD"] == "POST"){
    // Validate current password
    if(empty(trim($_POST["current_password"]))){
        $current_password_err = "Please enter your current password.";
    } else {
        $current_password = trim($_POST["current_password"]);
    }

    // Validate new password
    if(empty(trim($_POST["new_password"]))){
        $new_password_err = "Please enter the new password.";
    } elseif(strlen(trim($_POST["new_password"])) < 6){
        $new_password_err = "Password must have at least 6 characters.";
    } else {
        $new_password = trim($_POST["new_password"]);
    }

    // Validate confirm password
    if(empty(trim($_POST["confirm_new_password"]))){
        $confirm_new_password_err = "Please confirm the new password.";
    } else {
        $confirm_new_password = trim($_POST["confirm_new_password"]);
        if(empty($new_password_err) && ($new_password != $confirm_new_password)){
            $confirm_new_password_err = "New password did not match.";
        }
    }

    // Check input errors before proceeding
    if(empty($current_password_err) && empty($new_password_err) && empty($confirm_new_password_err)){
        // Get the current user's hashed password from the database
        $sql = "SELECT password FROM users WHERE id = ?";
        if($stmt = $mysqli->prepare($sql)){
            $stmt->bind_param("i", $_SESSION["id"]);
            if($stmt->execute()){
                $stmt->store_result();
                if($stmt->num_rows == 1){
                    $stmt->bind_result($hashed_password);
                    $stmt->fetch();

                    if(password_verify($current_password, $hashed_password)){
                        // Current password is correct. Hash the new password.
                        $new_hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

                        // Update the password in the database
                        $sql_update = "UPDATE users SET password = ? WHERE id = ?";
                        if($stmt_update = $mysqli->prepare($sql_update)){
                            $stmt_update->bind_param("si", $new_hashed_password, $_SESSION["id"]);
                            if($stmt_update->execute()){
                                $success_message = "Your password has been updated successfully.";
                            } else {
                                $current_password_err = "Oops! Something went wrong. Please try again later.";
                            }
                            $stmt_update->close();
                        }
                    } else {
                        $current_password_err = "The current password you entered was not valid.";
                    }
                }
            }
            $stmt->close();
        }
    }
}

// Include the header
include 'includes/header.php';
?>

<div class="container mt-5">
    <div class="row">
        <div class="col-md-4">
            <div class="list-group">
                <a href="account.php" class="list-group-item list-group-item-action">My Account</a>
                <a href="my_orders.php" class="list-group-item list-group-item-action">My Orders</a>
                <a href="change_password.php" class="list-group-item list-group-item-action active" aria-current="true">Change Password</a>
                <a href="logout.php" class="list-group-item list-group-item-action">Logout</a>
            </div>
        </div>
        <div class="col-md-8">
            <h2>Change Password</h2>
            <p>Use the form below to change the password for your account.</p>
            <div class="card">
                <div class="card-body">
                    <?php
                    if(!empty($success_message)){
                        echo '<div class="alert alert-success">' . $success_message . '</div>';
                    }
                    ?>
                    <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                        <div class="mb-3">
                            <label for="current_password" class="form-label">Current Password</label>
                            <input type="password" name="current_password" class="form-control <?php echo (!empty($current_password_err)) ? 'is-invalid' : ''; ?>" id="current_password">
                            <span class="invalid-feedback"><?php echo $current_password_err; ?></span>
                        </div>
                        <div class="mb-3">
                            <label for="new_password" class="form-label">New Password</label>
                            <input type="password" name="new_password" class="form-control <?php echo (!empty($new_password_err)) ? 'is-invalid' : ''; ?>" id="new_password">
                            <span class="invalid-feedback"><?php echo $new_password_err; ?></span>
                        </div>
                        <div class="mb-3">
                            <label for="confirm_new_password" class="form-label">Confirm New Password</label>
                            <input type="password" name="confirm_new_password" class="form-control <?php echo (!empty($confirm_new_password_err)) ? 'is-invalid' : ''; ?>" id="confirm_new_password">
                            <span class="invalid-feedback"><?php echo $confirm_new_password_err; ?></span>
                        </div>
                        <button type="submit" class="btn btn-primary">Update Password</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
// Include the footer
include 'includes/footer.php';
?>

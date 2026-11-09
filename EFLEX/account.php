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

// Include the header
include 'includes/header.php';
?>

<div class="container mt-5">
    <div class="row">
        <div class="col-md-4">
            <div class="list-group">
                <a href="account.php" class="list-group-item list-group-item-action active" aria-current="true">
                    My Account
                </a>
                <a href="my_orders.php" class="list-group-item list-group-item-action">My Orders</a>
                <a href="change_password.php" class="list-group-item list-group-item-action">Change Password</a>
                <a href="logout.php" class="list-group-item list-group-item-action">Logout</a>
            </div>
        </div>
        <div class="col-md-8">
            <h2>My Account</h2>
            <p>Welcome, <strong><?php echo htmlspecialchars($_SESSION["username"]); ?></strong>!</p>
            <p>From your account dashboard you can view your recent orders and edit your password and account details.</p>
        </div>
    </div>
</div>

<?php
// Include the footer
include 'includes/footer.php';
?>

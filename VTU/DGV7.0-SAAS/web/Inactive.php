<?php session_start();
include("../func/bc-connect.php");

$vendor_id = resolveVendorID();
$select_vendor_table = mysqli_fetch_array(mysqli_query($connection_server, "SELECT * FROM sas_vendors WHERE id='$vendor_id' LIMIT 1"));

if (!$select_vendor_table) {
    header("Location: /index.php");
    exit();
}

$vendor_name = $select_vendor_table["firstname"] . " " . $select_vendor_table["lastname"];
$support_email = $select_vendor_table["email"];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Website Inactive | <?php echo $select_vendor_table["website_url"]; ?></title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
    <style>
        body { background: #f6f9ff; color: #444444; font-family: "Open Sans", sans-serif; }
        .inactive-card { max-width: 500px; width: 100%; padding: 40px; border-radius: 20px; background: #fff; box-shadow: 0 10px 30px rgba(0,0,0,0.05); text-align: center; }
        .icon-box { width: 80px; height: 80px; background: #fff3cd; color: #ffc107; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 40px; margin: 0 auto 20px; }
    </style>
</head>
<body class="d-flex align-items-center justify-content-center min-vh-100 p-3">
    <div class="inactive-card">
        <div class="icon-box">
            <i class="bi bi-exclamation-triangle-fill"></i>
        </div>
        <h2 class="fw-bold mb-3">Website Inactive</h2>
        <p class="text-muted mb-4">This website is currently inactive. If you are the owner, please login to your admin panel to renew your subscription. If you are a user, please contact the administrator for assistance.</p>

        <div class="d-grid gap-2">
            <a href="mailto:<?php echo htmlspecialchars($support_email); ?>" class="btn btn-primary btn-lg rounded-pill shadow-sm">
                <i class="bi bi-envelope-fill me-2"></i>Contact Admin
            </a>
            <a href="/bc-admin/Login.php" class="btn btn-outline-secondary btn-sm rounded-pill mt-3 border-0">Admin Login</a>
        </div>
    </div>
</body>
</html>

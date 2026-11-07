<?php session_start();
    include("../func/bc-spadmin-config.php");

    // Check for vendor ID
    if(!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        header("Location: Vendors.php");
        exit();
    }
    $vendor_id = mysqli_real_escape_string($connection_server, $_GET['id']);

    // Handle form submission
    if(isset($_POST['update_subscription'])) {
        $new_expiry_date = mysqli_real_escape_string($connection_server, $_POST['expiry_date']);
        $new_billing_id = mysqli_real_escape_string($connection_server, $_POST['billing_package_id']);
        
        $update_sql = "UPDATE sas_vendors SET expiry_date='$new_expiry_date', current_billing_id='$new_billing_id' WHERE id='$vendor_id'";
        if(mysqli_query($connection_server, $update_sql)) {
            $_SESSION['page_alert'] = "Subscription updated successfully!";
        } else {
            $_SESSION['page_alert'] = "Error updating subscription: " . mysqli_error($connection_server);
        }
        header("Location: ManageVendorSubscription.php?id=" . $vendor_id);
        exit();
    }


    // Fetch vendor and subscription details
    $sql = "SELECT v.id, v.email, v.firstname, v.lastname, v.expiry_date, v.current_billing_id, bp.name as package_name 
            FROM sas_vendors v 
            LEFT JOIN sas_billing_packages bp ON v.current_billing_id = bp.id 
            WHERE v.id = '$vendor_id'";
    $result = mysqli_query($connection_server, $sql);
    $vendor = mysqli_fetch_assoc($result);

    if(!$vendor) {
        header("Location: Vendors.php");
        exit();
    }

    // Fetch all packages for dropdown
    $all_packages_res = mysqli_query($connection_server, "SELECT * FROM sas_billing_packages ORDER BY name ASC");

?>
<!DOCTYPE html>
<head>
    <title>Manage Vendor Subscription</title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
</head>
<body>
    <?php include("../func/bc-spadmin-header.php"); ?>

    <div class="pagetitle">
        <h1>Manage Subscription</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li>
                <li class="breadcrumb-item"><a href="Vendors.php">Vendors</a></li>
                <li class="breadcrumb-item active">Manage Subscription</li>
            </ol>
        </nav>
    </div>

    <section class="section">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Editing Subscription for <?php echo htmlspecialchars($vendor['firstname'] . ' ' . $vendor['lastname']); ?></h5>
                        <p><strong>Email:</strong> <?php echo htmlspecialchars($vendor['email']); ?></p>

                        <?php if(isset($_SESSION['page_alert'])): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <?php echo $_SESSION['page_alert']; unset($_SESSION['page_alert']); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="">
                            <div class="mb-3">
                                <label for="billing_package_id" class="form-label">Subscription Plan</label>
                                <select class="form-select" id="billing_package_id" name="billing_package_id" required>
                                    <?php while($package = mysqli_fetch_assoc($all_packages_res)): ?>
                                        <option value="<?php echo $package['id']; ?>" <?php if($package['id'] == $vendor['current_billing_id']) echo 'selected'; ?>>
                                            <?php echo htmlspecialchars($package['name']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="expiry_date" class="form-label">Expiry Date</label>
                                <input type="date" class="form-control" id="expiry_date" name="expiry_date" value="<?php echo htmlspecialchars($vendor['expiry_date']); ?>" required>
                            </div>
                            <button type="submit" name="update_subscription" class="btn btn-primary">Update Subscription</button>
                            <a href="Vendors.php" class="btn btn-secondary">Back to Vendors</a>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <?php include("../func/bc-spadmin-footer.php"); ?>
</body>
</html>

<?php session_start();
include("../func/bc-spadmin-config.php");

// Fetch the pending vendor
if (!isset($_GET['id'])) {
    header("Location: VendorRegistrations.php");
    exit();
}

$id = mysqli_real_escape_string($connection_server, $_GET['id']);
$pv_query = mysqli_query($connection_server, "SELECT * FROM sas_pending_vendors WHERE id='$id'");
$pv = mysqli_fetch_assoc($pv_query);

if (!$pv) {
    die("Vendor not found.");
}

// Handle Save
if (isset($_POST['save_order'])) {
    bc_validate_csrf();

    $package_id = mysqli_real_escape_string($connection_server, $_POST['package_id']);
    $domain_fee = (float)$_POST['domain_fee'];
    $order_apk = isset($_POST['order_apk']) ? 1 : 0;
    $order_ios = isset($_POST['order_ios']) ? 1 : 0;
    $order_playstore = isset($_POST['order_playstore']) ? 1 : 0;
    $order_sms_bridge = isset($_POST['order_sms_bridge']) ? 1 : 0;
    
    $selected_addons = isset($_POST['addons']) ? implode(',', array_map('intval', $_POST['addons'])) : '';

    // Calculate Total
    $total = 0;
    $pkg_res = mysqli_query($connection_server, "SELECT price FROM sas_billing_packages WHERE id='$package_id'");
    $pkg = mysqli_fetch_assoc($pkg_res);
    $total += (float)$pkg['price'];
    $total += $domain_fee;

    if($order_apk) $total += (float)getSuperAdminOption('apk_development_price', '0');
    if($order_ios) $total += (float)getSuperAdminOption('ios_development_price', '0');
    if($order_playstore) $total += (float)getSuperAdminOption('playstore_listing_price', '0');
    if($order_sms_bridge) $total += (float)getSuperAdminOption('sms_bridge_price', '0');

    if(!empty($selected_addons)) {
        $addon_prices = mysqli_query($connection_server, "SELECT SUM(price) as total FROM sas_billing_addons WHERE id IN ($selected_addons)");
        $ap = mysqli_fetch_assoc($addon_prices);
        $total += (float)$ap['total'];
    }

    $update_sql = "UPDATE sas_pending_vendors SET 
                   billing_package_id='$package_id', 
                   domain_registration_fee='$domain_fee',
                   order_apk='$order_apk',
                   order_ios='$order_ios',
                   order_playstore='$order_playstore',
                   order_sms_bridge='$order_sms_bridge',
                   selected_addons='$selected_addons',
                   total_amount='$total'
                   WHERE id='$id'";

    if (mysqli_query($connection_server, $update_sql)) {
        $_SESSION['page_alert'] = "Order updated successfully. New total: ₦" . number_format($total, 2);
        header("Location: VendorRegistrations.php");
        exit();
    } else {
        $error = "Error updating order: " . mysqli_error($connection_server);
    }
}

// Fetch lists
$packages = mysqli_query($connection_server, "SELECT * FROM sas_billing_packages ORDER BY price ASC");
$addons = mysqli_query($connection_server, "SELECT * FROM sas_billing_addons ORDER BY name ASC");

$current_addons = explode(',', $pv['selected_addons']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Edit Vendor Order | Admin</title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
    <style>
        .addon-card {
            transition: all 0.3s ease;
            cursor: pointer;
            border: 2px solid transparent;
        }
        .addon-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .addon-check:checked + .addon-card {
            border-color: #0d6efd;
            background-color: #f8fbff;
        }
    </style>
</head>
<body>
    <?php include("../func/bc-spadmin-header.php"); ?>

    <div class="pagetitle">
        <h1>Edit Vendor Order</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li>
                <li class="breadcrumb-item"><a href="VendorRegistrations.php">Pending Registrations</a></li>
                <li class="breadcrumb-item active">Edit Order</li>
            </ol>
        </nav>
    </div>

    <section class="section">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="card shadow-sm border-0 rounded-4">
                    <div class="card-body p-4">
                        <div class="d-flex align-items-center mb-4">
                            <div class="bg-primary bg-opacity-10 p-3 rounded-4 me-3">
                                <i class="bi bi-pencil-square text-primary fs-3"></i>
                            </div>
                            <div>
                                <h5 class="card-title mb-0">Modify Order: <?php echo htmlspecialchars($pv['firstname'] . ' ' . $pv['lastname']); ?></h5>
                                <p class="text-muted small mb-0">Adjust plans and addons for <?php echo htmlspecialchars($pv['website_url']); ?></p>
                            </div>
                        </div>

                        <?php if(isset($error)): ?>
                            <div class="alert alert-danger rounded-3 border-0"><?php echo $error; ?></div>
                        <?php endif; ?>

                        <form method="POST" id="orderForm">
                            <?php echo bc_csrf_field(); ?>
                            
                            <div class="row g-4">
                                <!-- Package Selection -->
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Subscription Plan</label>
                                    <select name="package_id" class="form-select rounded-3 py-2" required>
                                        <?php while($pkg = mysqli_fetch_assoc($packages)): ?>
                                            <option value="<?php echo $pkg['id']; ?>" <?php if($pv['billing_package_id'] == $pkg['id']) echo 'selected'; ?> data-price="<?php echo $pkg['price']; ?>">
                                                <?php echo htmlspecialchars($pkg['name']); ?> (₦<?php echo number_format($pkg['price'], 0); ?>)
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>

                                <!-- Domain Fee -->
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Domain Registration Fee (₦)</label>
                                    <input type="number" name="domain_fee" class="form-control rounded-3 py-2" value="<?php echo (float)$pv['domain_registration_fee']; ?>" step="0.01">
                                </div>

                                <hr class="my-4 text-muted opacity-25">

                                <!-- Legacy Addons -->
                                <div class="col-12">
                                    <h6 class="fw-bold mb-3">Legacy App Services</h6>
                                    <div class="row g-3">
                                        <?php 
                                        $legacy = [
                                            ['order_apk', 'Android APK Development', getSuperAdminOption('apk_development_price', '0'), 'bi-android2'],
                                            ['order_ios', 'iOS App Development', getSuperAdminOption('ios_development_price', '0'), 'bi-apple'],
                                            ['order_playstore', 'PlayStore Listing', getSuperAdminOption('playstore_listing_price', '0'), 'bi-google-play'],
                                            ['order_sms_bridge', 'PrintHub APP Service', getSuperAdminOption('sms_bridge_price', '0'), 'bi-chat-dots']
                                        ];
                                        foreach($legacy as $item): 
                                        ?>
                                        <div class="col-md-3">
                                            <input type="checkbox" name="<?php echo $item[0]; ?>" id="<?php echo $item[0]; ?>" class="addon-check d-none" <?php if($pv[$item[0]]) echo 'checked'; ?> data-price="<?php echo $item[2]; ?>">
                                            <label for="<?php echo $item[0]; ?>" class="addon-card card h-100 p-3 rounded-4 mb-0">
                                                <div class="text-center">
                                                    <i class="bi <?php echo $item[3]; ?> fs-4 mb-2 d-block text-primary"></i>
                                                    <div class="small fw-bold"><?php echo $item[1]; ?></div>
                                                    <div class="text-muted extra-small">₦<?php echo number_format($item[2], 0); ?></div>
                                                </div>
                                            </label>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <!-- Dynamic Addons -->
                                <div class="col-12">
                                    <h6 class="fw-bold mb-3">Custom Addon Services</h6>
                                    <div class="row g-3">
                                        <?php while($addon = mysqli_fetch_assoc($addons)): ?>
                                        <div class="col-md-3">
                                            <input type="checkbox" name="addons[]" value="<?php echo $addon['id']; ?>" id="addon_<?php echo $addon['id']; ?>" class="addon-check d-none" <?php if(in_array($addon['id'], $current_addons)) echo 'checked'; ?> data-price="<?php echo $addon['price']; ?>">
                                            <label for="addon_<?php echo $addon['id']; ?>" class="addon-card card h-100 p-3 rounded-4 mb-0">
                                                <div class="text-center">
                                                    <i class="bi <?php echo htmlspecialchars($addon['icon']); ?> fs-4 mb-2 d-block text-primary"></i>
                                                    <div class="small fw-bold"><?php echo htmlspecialchars($addon['name']); ?></div>
                                                    <div class="text-muted extra-small">₦<?php echo number_format($addon['price'], 0); ?></div>
                                                </div>
                                            </label>
                                        </div>
                                        <?php endwhile; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Summary Footer -->
                            <div class="card bg-light border-0 rounded-4 mt-5 p-4 d-flex flex-md-row justify-content-between align-items-center">
                                <div>
                                    <h4 class="mb-0 fw-bold text-primary" id="totalDisplay">Total: ₦<?php echo number_format($pv['total_amount'], 2); ?></h4>
                                    <p class="text-muted small mb-0">Calculated based on current selections above</p>
                                </div>
                                <div class="mt-3 mt-md-0">
                                    <a href="VendorRegistrations.php" class="btn btn-link text-decoration-none me-3 text-muted">Cancel</a>
                                    <button type="submit" name="save_order" class="btn btn-primary px-5 py-2 rounded-pill fw-bold shadow-sm">Save Adjustments</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <?php include("../func/bc-spadmin-footer.php"); ?>

    <script>
        function calculateTotal() {
            let total = 0;
            
            // Plan
            const planSelect = document.querySelector('select[name="package_id"]');
            const selectedPlan = planSelect.options[planSelect.selectedIndex];
            total += parseFloat(selectedPlan.getAttribute('data-price') || 0);

            // Domain Fee
            const domainFee = parseFloat(document.querySelector('input[name="domain_fee"]').value || 0);
            total += domainFee;

            // Checkboxes
            document.querySelectorAll('.addon-check:checked').forEach(chk => {
                total += parseFloat(chk.getAttribute('data-price') || 0);
            });

            document.getElementById('totalDisplay').innerText = 'Total: ₦' + total.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        }

        document.querySelectorAll('select, input').forEach(el => {
            el.addEventListener('change', calculateTotal);
            el.addEventListener('input', calculateTotal);
        });
    </script>
</body>
</html>

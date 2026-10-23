<?php session_start();
    include("../func/bc-spadmin-config.php");

    // Handle Addon Actions
    if(isset($_POST['save_addon'])) {
        $addon_id = mysqli_real_escape_string($connection_server, $_POST['addon_id']);
        $a_name = mysqli_real_escape_string($connection_server, $_POST['addon_name'] ?? '');
        $a_price = mysqli_real_escape_string($connection_server, $_POST['addon_price'] ?? '0');
        $a_icon = mysqli_real_escape_string($connection_server, $_POST['addon_icon'] ?? 'bi-box-seam');
        $a_dl = mysqli_real_escape_string($connection_server, $_POST['download_url'] ?? '');

        if(empty($addon_id)) {
            mysqli_query($connection_server, "INSERT INTO sas_billing_addons (name, price, icon, download_url) VALUES ('$a_name', '$a_price', '$a_icon', '$a_dl')");
            $_SESSION['page_alert'] = "Addon added successfully!";
        } else {
            mysqli_query($connection_server, "UPDATE sas_billing_addons SET name='$a_name', price='$a_price', icon='$a_icon', download_url='$a_dl' WHERE id='$addon_id'");
            $_SESSION['page_alert'] = "Addon updated successfully!";
        }
        header("Location: BillingPackages.php"); exit();
    }

    if(isset($_GET['delete_addon'])) {
        $del_addon = mysqli_real_escape_string($connection_server, $_GET['delete_addon']);
        mysqli_query($connection_server, "DELETE FROM sas_billing_addons WHERE id='$del_addon'");
        $_SESSION['page_alert'] = "Addon deleted!";
        header("Location: BillingPackages.php"); exit();
    }

    // Handle Delete Request
    if(isset($_GET['delete_id'])) {
        $delete_id = mysqli_real_escape_string($connection_server, $_GET['delete_id']);
        mysqli_query($connection_server, "DELETE FROM sas_billing_packages WHERE id='$delete_id'");
        $_SESSION['page_alert'] = "Package deleted successfully!";
        header("Location: BillingPackages.php");
        exit();
    }

    // Handle Add/Edit Request
    if(isset($_POST['save_package'])) {
        $package_id = mysqli_real_escape_string($connection_server, $_POST['package_id']);
        $name = mysqli_real_escape_string($connection_server, $_POST['name']);
        $price = mysqli_real_escape_string($connection_server, $_POST['price']);
        $duration_days = mysqli_real_escape_string($connection_server, $_POST['duration_days']);
        $package_type = mysqli_real_escape_string($connection_server, $_POST['package_type']);
        $download_url = mysqli_real_escape_string($connection_server, $_POST['download_url']);

        if(empty($package_id)) {
            // Add New Package
            $sql = "INSERT INTO sas_billing_packages (name, package_type, price, duration_days, download_url) VALUES ('$name', '$package_type', '$price', '$duration_days', '$download_url')";
            $_SESSION['page_alert'] = "Package added successfully!";
        } else {
            // Update Existing Package
            $sql = "UPDATE sas_billing_packages SET name='$name', package_type='$package_type', price='$price', duration_days='$duration_days', download_url='$download_url' WHERE id='$package_id'";
            $_SESSION['page_alert'] = "Package updated successfully!";
        }

        if(!mysqli_query($connection_server, $sql)) {
            // If query fails, show the error instead of the generic success message
            $_SESSION['page_alert'] = "Error saving package: " . mysqli_error($connection_server);
        }

        header("Location: BillingPackages.php");
        exit();
    }

    // Handle App Service Prices
    if(isset($_POST["update-app-services"])){
        $apk_price = mysqli_real_escape_string($connection_server, $_POST['apk_price']);
        $ios_price = mysqli_real_escape_string($connection_server, $_POST['ios_price']);
        $playstore_price = mysqli_real_escape_string($connection_server, $_POST['playstore_price']);
        $sms_bridge_price = mysqli_real_escape_string($connection_server, $_POST['sms_bridge_price']);

        mysqli_query($connection_server, "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('apk_development_price', '$apk_price') ON DUPLICATE KEY UPDATE option_value='$apk_price'");
        mysqli_query($connection_server, "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('ios_development_price', '$ios_price') ON DUPLICATE KEY UPDATE option_value='$ios_price'");
        mysqli_query($connection_server, "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('playstore_listing_price', '$playstore_price') ON DUPLICATE KEY UPDATE option_value='$playstore_price'");
        mysqli_query($connection_server, "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('sms_bridge_price', '$sms_bridge_price') ON DUPLICATE KEY UPDATE option_value='$sms_bridge_price'");

        $_SESSION["page_alert"] = "App service prices updated successfully";
        header("Location: BillingPackages.php");
        exit();
    }

    // Fetch package for editing
    $edit_package = null;
    if(isset($_GET['edit_id'])) {
        $edit_id = mysqli_real_escape_string($connection_server, $_GET['edit_id']);
        $result = mysqli_query($connection_server, "SELECT * FROM sas_billing_packages WHERE id='$edit_id'");
        $edit_package = mysqli_fetch_assoc($result);
    }
?>
<!DOCTYPE html>
<head>
    <title>Billing Packages Management</title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
</head>
<body>
    <?php include("../func/bc-spadmin-header.php"); ?>

    <div class="pagetitle">
        <h1>Billing Packages</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li>
                <li class="breadcrumb-item active">Billing Packages</li>
            </ol>
        </nav>
    </div>

    <section class="section dashboard">
        <div class="row g-4">
            <div class="col-lg-4">
                <div class="card shadow-sm border-0 rounded-4">
                    <div class="card-header bg-white py-3 border-0">
                        <h5 class="fw-bold mb-0 text-primary"><?php echo isset($edit_package) ? 'Edit' : 'Add New'; ?> Package</h5>
                    </div>
                    <div class="card-body p-4">
                        <form method="POST" action="BillingPackages.php">
                            <input type="hidden" name="package_id" value="<?php echo $edit_package['id'] ?? ''; ?>">
                            <div class="mb-3">
                                <label for="name" class="form-label small fw-bold text-muted text-uppercase">Package Name</label>
                                <input type="text" class="form-control rounded-3" id="name" name="name" value="<?php echo $edit_package['name'] ?? ''; ?>" placeholder="e.g. Monthly Basic" required>
                            </div>
                            <div class="mb-3">
                                <label for="package_type" class="form-label small fw-bold text-muted text-uppercase">Package Type</label>
                                <select class="form-select rounded-3" id="package_type" name="package_type" required onchange="toggleDuration(this.value)">
                                    <option value="subscription" <?php echo (isset($edit_package) && $edit_package['package_type'] == 'subscription') ? 'selected' : ''; ?>>Recurring Subscription</option>
                                    <option value="one-off" <?php echo (isset($edit_package) && $edit_package['package_type'] == 'one-off') ? 'selected' : ''; ?>>ONE-OFF PAYMENT</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="price" class="form-label small fw-bold text-muted text-uppercase">Price (₦)</label>
                                <input type="number" step="0.01" class="form-control rounded-3" id="price" name="price" value="<?php echo $edit_package['price'] ?? ''; ?>" placeholder="0.00" required>
                            </div>
                            <div class="mb-4" id="duration_wrapper">
                                <label for="duration_days" class="form-label small fw-bold text-muted text-uppercase">Duration (Days)</label>
                                <input type="number" class="form-control rounded-3" id="duration_days" name="duration_days" value="<?php echo $edit_package['duration_days'] ?? ''; ?>" placeholder="30" required>
                            </div>
                            <div class="mb-4">
                                <label for="download_url" class="form-label small fw-bold text-muted text-uppercase">Download URL (For One-Off Scripts)</label>
                                <input type="text" class="form-control rounded-3" id="download_url" name="download_url" value="<?php echo $edit_package['download_url'] ?? ''; ?>" placeholder="https://cloud.com/script.zip">
                            </div>

                            <script>
                            function toggleDuration(type) {
                                const wrapper = document.getElementById('duration_wrapper');
                                const input = document.getElementById('duration_days');
                                if(type === 'one-off') {
                                    wrapper.style.opacity = '0.5';
                                    input.value = '9999';
                                    input.readOnly = true;
                                } else {
                                    wrapper.style.opacity = '1';
                                    input.readOnly = false;
                                    if(input.value == '9999') input.value = '30';
                                }
                            }
                            // Initial state
                            window.onload = function() {
                                toggleDuration(document.getElementById('package_type').value);
                            }
                            </script>
                            <button type="submit" name="save_package" class="btn btn-primary w-100 rounded-pill fw-bold shadow-sm">
                                <i class="bi bi-save me-1"></i> Save Package
                            </button>
                            <?php if(isset($edit_package)): ?>
                                <a href="BillingPackages.php" class="btn btn-light w-100 rounded-pill mt-2 border">Cancel</a>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>

                <div class="card shadow-sm border-0 rounded-4 mt-4">
                    <div class="card-header bg-dark py-3 border-0 text-white d-flex justify-content-between align-items-center">
                        <h6 class="mb-0"><i class="bi bi-phone-fill me-2"></i>Dynamic Billing Addons</h6>
                        <div class="d-flex gap-2">
                            <a href="VendorDownloadStats.php" class="btn btn-sm btn-info text-white rounded-pill">
                                <i class="bi bi-bar-chart-fill me-1"></i> Download Stats
                            </a>
                            <button type="button" class="btn btn-sm btn-outline-light rounded-pill" onclick="showAddonForm()">
                                <i class="bi bi-plus-circle me-1"></i> Add New
                            </button>
                        </div>
                    </div>
                    <div class="card-body p-4">
                        <!-- Addon Form (Hidden by default) -->
                        <div id="addon_form_wrapper" style="display: none;" class="mb-4 p-3 bg-light rounded-3 border">
                            <h6 class="fw-bold mb-3" id="addon_form_title">Add New Addon</h6>
                            <form method="POST" action="BillingPackages.php">
                                <input type="hidden" name="addon_id" id="addon_id">
                                <div class="mb-3">
                                    <label class="form-label small fw-bold">Addon Name</label>
                                    <input type="text" name="addon_name" id="addon_name" class="form-control rounded-3" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold">Price (₦)</label>
                                    <input type="number" step="0.01" name="addon_price" id="addon_price" class="form-control rounded-3" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold">Download Source URL (Cloud path/Zip link)</label>
                                    <input type="text" name="download_url" id="addon_dl" class="form-control rounded-3" placeholder="https://cloud.com/file.zip">
                                </div>
                                <div class="d-flex gap-2">
                                    <button type="submit" name="save_addon" class="btn btn-primary rounded-pill px-4">Save Addon</button>
                                    <button type="button" class="btn btn-light border rounded-pill px-4" onclick="hideAddonForm()">Cancel</button>
                                </div>
                            </form>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr class="small text-uppercase text-muted">
                                        <th>Icon</th>
                                        <th>Name</th>
                                        <th>Price</th>
                                        <th class="text-end">Edit</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $addons_q = mysqli_query($connection_server, "SELECT * FROM sas_billing_addons ORDER BY id ASC");
                                    if(mysqli_num_rows($addons_q) > 0):
                                        while($addon = mysqli_fetch_assoc($addons_q)):
                                    ?>
                                    <tr>
                                        <td><i class="bi <?php echo htmlspecialchars($addon['icon']); ?> text-primary"></i></td>
                                        <td><span class="small fw-bold"><?php echo htmlspecialchars($addon['name']); ?></span></td>
                                        <td><span class="small">₦<?php echo number_format($addon['price'], 0); ?></span></td>
                                        <td class="text-end">
                                            <div class="btn-group btn-group-sm">
                                                <button class="btn btn-outline-primary" onclick='editAddon(<?php echo json_encode($addon); ?>)'><i class="bi bi-pencil"></i></button>
                                                <a href="BillingPackages.php?delete_addon=<?php echo $addon['id']; ?>" class="btn btn-outline-danger" onclick="return confirm('Delete this addon?')"><i class="bi bi-trash"></i></a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endwhile; else: ?>
                                    <tr><td colspan="4" class="text-center py-3 text-muted x-small">No addons configured.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <script>
                function showAddonForm() {
                    document.getElementById('addon_form_wrapper').style.display = 'block';
                    document.getElementById('addon_form_title').innerText = 'Add New Addon';
                    document.getElementById('addon_id').value = '';
                    document.getElementById('addon_name').value = '';
                    document.getElementById('addon_price').value = '';
                    document.getElementById('addon_icon').value = 'bi-box-seam';
                    document.getElementById('addon_dl').value = '';
                }
                function hideAddonForm() {
                    document.getElementById('addon_form_wrapper').style.display = 'none';
                }
                function editAddon(data) {
                    document.getElementById('addon_form_wrapper').style.display = 'block';
                    document.getElementById('addon_form_title').innerText = 'Edit Addon';
                    document.getElementById('addon_id').value = data.id;
                    document.getElementById('addon_name').value = data.name;
                    document.getElementById('addon_price').value = data.price;
                    document.getElementById('addon_icon').value = data.icon;
                    document.getElementById('addon_dl').value = data.download_url || '';
                    document.getElementById('addon_form_wrapper').scrollIntoView({behavior: 'smooth'});
                }
                </script>
            </div>
            <div class="col-lg-8">
                <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
                    <div class="card-header bg-white py-4 border-0">
                        <h5 class="fw-bold mb-0 text-primary">Active Billing Packages</h5>
                        <p class="text-muted small mb-0">Configure subscription plans for your vendors</p>
                    </div>
                    <div class="card-body p-0">
                        <?php if(isset($_SESSION['page_alert'])): ?>
                            <div class="px-4 pt-3">
                                <div class="alert alert-success alert-dismissible fade show rounded-3 border-0 shadow-sm" role="alert">
                                    <i class="bi bi-check-circle me-2"></i><?php echo $_SESSION['page_alert']; unset($_SESSION['page_alert']); ?>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="bg-light">
                                    <tr class="small text-uppercase text-muted">
                                        <th class="ps-4">#</th>
                                        <th>Package Name</th>
                                        <th>Type</th>
                                        <th>Price</th>
                                        <th>Duration</th>
                                        <th class="text-end pe-4">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                        $result = mysqli_query($connection_server, "SELECT * FROM sas_billing_packages ORDER BY id DESC");
                                        $count = 1;
                                        while($row = mysqli_fetch_assoc($result)):
                                    ?>
                                    <tr>
                                        <td class="ps-4 text-muted"><?php echo $count++; ?></td>
                                        <td><div class="fw-bold text-dark"><?php echo htmlspecialchars($row['name']); ?></div></td>
                                        <td>
                                            <?php if(($row['package_type'] ?? '') == 'one-off'): ?>
                                                <span class="badge bg-success bg-opacity-10 text-success rounded-pill px-3">One-Off</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning bg-opacity-10 text-warning rounded-pill px-3">Subscription</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="fw-bold text-primary">₦<?php echo number_format($row['price'], 2); ?></span></td>
                                        <td>
                                            <?php if(($row['package_type'] ?? '') == 'one-off'): ?>
                                                <span class="badge bg-secondary bg-opacity-10 text-secondary rounded-pill px-3">Lifetime</span>
                                            <?php else: ?>
                                                <span class="badge bg-info bg-opacity-10 text-info rounded-pill px-3"><?php echo htmlspecialchars($row['duration_days']); ?> Days</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if(!empty($row['download_url'])): ?>
                                                <span class="badge bg-primary rounded-pill px-2" title="<?php echo htmlspecialchars($row['download_url']); ?>"><i class="bi bi-link-45deg"></i> Link Set</span>
                                            <?php else: ?>
                                                <span class="text-muted small">None</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end pe-4">
                                            <div class="btn-group btn-group-sm">
                                                <a href="BillingPackages.php?edit_id=<?php echo $row['id']; ?>" class="btn btn-outline-primary" title="Edit"><i class="bi bi-pencil"></i></a>
                                                <a href="BillingPackages.php?delete_id=<?php echo $row['id']; ?>" class="btn btn-outline-danger" onclick="return confirm('Are you sure you want to delete this package?');" title="Delete"><i class="bi bi-trash"></i></a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <?php include("../func/bc-spadmin-footer.php"); ?>
</body>
</html>
<?php session_start();
    include("../func/bc-spadmin-config.php");
    include("../func/bc-tables.php");
    include_once("../func/whmp-func.php");

    // Handle Domain Extension Management
    if(isset($_POST['add_extension'])) {
        $ext = mysqli_real_escape_string($connection_server, trim(strtolower($_POST['extension'])));
        if (!empty($ext)) {
            $ext = "." . ltrim($ext, "."); // Ensure it starts with a dot
        }
        $price = mysqli_real_escape_string($connection_server, $_POST['price']);
        $promo_price = trim($_POST['promo_price'] ?? '');
        $promo_price = ($promo_price === '') ? 0 : mysqli_real_escape_string($connection_server, $promo_price);

        if(!empty($ext) && is_numeric($price)) {
            $sql = "INSERT INTO sas_domain_extensions (extension, price, promo_price) VALUES ('$ext', '$price', '$promo_price') ON DUPLICATE KEY UPDATE price='$price', promo_price='$promo_price'";
            if(mysqli_query($connection_server, $sql)) {
                $_SESSION['page_alert'] = "Domain extension $ext added/updated successfully!";
            } else {
                $_SESSION['page_alert'] = "Error adding extension: " . mysqli_error($connection_server);
            }
        } else {
            $_SESSION['page_alert'] = "Invalid input. Please ensure extension and normal price are provided.";
        }
        header("Location: DomainSettings.php");
        exit();
    }

    if(isset($_GET['delete_ext'])) {
        $del_id = mysqli_real_escape_string($connection_server, $_GET['delete_ext']);
        mysqli_query($connection_server, "DELETE FROM sas_domain_extensions WHERE id='$del_id'");
        $_SESSION['page_alert'] = "Extension deleted successfully.";
        header("Location: DomainSettings.php");
        exit();
    }

    // Handle form submission
    if(isset($_POST['save_settings'])) {
        $nameservers = mysqli_real_escape_string($connection_server, $_POST['nameservers']);
        $ip_address = mysqli_real_escape_string($connection_server, $_POST['ip_address']);
        $registrar_url = mysqli_real_escape_string($connection_server, $_POST['registrar_url']);

        $sql_nameservers = "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('domain_nameservers', '$nameservers') ON DUPLICATE KEY UPDATE option_value = '$nameservers'";
        $sql_ip = "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('domain_ip_address', '$ip_address') ON DUPLICATE KEY UPDATE option_value = '$ip_address'";
        $sql_registrar = "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('domain_registrar_url', '$registrar_url') ON DUPLICATE KEY UPDATE option_value = '$registrar_url'";

        if(mysqli_query($connection_server, $sql_nameservers) && mysqli_query($connection_server, $sql_ip) && mysqli_query($connection_server, $sql_registrar)) {
            $_SESSION['page_alert'] = "Settings saved successfully!";
        } else {
            $_SESSION['page_alert'] = "Error saving settings: " . mysqli_error($connection_server);
        }
        header("Location: DomainSettings.php");
        exit();
    }

    // ─── WHMP API Configuration ───────────────────────────────────────────────────────────────────
    if(isset($_POST['save_whmp_config'])) {
        $whmp_api_url = mysqli_real_escape_string($connection_server, trim($_POST['whmp_api_url'] ?? 'https://client.philmorehost.com/api'));
        $whmp_api_key = mysqli_real_escape_string($connection_server, trim($_POST['whmp_api_key'] ?? ''));
        $whmp_api_secret = mysqli_real_escape_string($connection_server, trim($_POST['whmp_api_secret'] ?? ''));

        mysqli_query($connection_server, "REPLACE INTO sas_super_admin_options (option_name, option_value) VALUES ('whmp_api_url', '$whmp_api_url')");
        if ($whmp_api_key) {
            mysqli_query($connection_server, "REPLACE INTO sas_super_admin_options (option_name, option_value) VALUES ('whmp_api_key', '$whmp_api_key')");
        }
        if ($whmp_api_secret) {
            mysqli_query($connection_server, "REPLACE INTO sas_super_admin_options (option_name, option_value) VALUES ('whmp_api_secret', '$whmp_api_secret')");
        }
        $_SESSION['page_alert'] = "WHMP API configuration saved!";
        unset($_SESSION['super_admin_options_cache']);
        header("Location: DomainSettings.php");
        exit();
    }

    // Sync domain extensions from WHMP reseller pricing
    if(isset($_POST['sync_whmp_extensions'])) {
        if (!whmp_is_configured()) {
            $_SESSION['page_alert'] = "WHMP API is not configured. Please add your API key and secret first.";
        } else {
            $pricing = whmp_get_pricing();
            if ($pricing['status'] === 'success') {
                $synced = 0;
                foreach ($pricing['data']['domains'] ?? [] as $dom) {
                    $ext = $dom['tld'] ?? '';
                    if (empty($ext)) continue;
                    $reseller_price = (float)($dom['register']['reseller'] ?? 0);
                    $list_price = (float)($dom['register']['list'] ?? 0);
                    $promo = ($reseller_price > 0 && $reseller_price < $list_price) ? $reseller_price : 0;
                    mysqli_query($connection_server,
                        "INSERT INTO sas_domain_extensions (extension, price, promo_price) VALUES ('" . mysqli_real_escape_string($connection_server, $ext) . "', '$list_price', '$promo') ON DUPLICATE KEY UPDATE price='$list_price', promo_price='$promo'");
                    $synced++;
                }
                $_SESSION['page_alert'] = "WHMP sync complete! $synced domain extensions updated.";
            } else {
                $_SESSION['page_alert'] = "WHMP sync failed: " . ($pricing['message'] ?? 'Unknown error');
            }
        }
        header("Location: DomainSettings.php");
        exit();
    }

    // Test WHMP connection
    if(isset($_POST['test_whmp'])) {
        if (!whmp_is_configured()) {
            $_SESSION['page_alert'] = "WHMP API is not configured.";
        } else {
            $ping = whmp_request('GET', 'ping');
            if ($ping['status'] === 'success') {
                $_SESSION['page_alert'] = "WHMP connection successful! API is reachable.";
            } else {
                $_SESSION['page_alert'] = "WHMP connection failed: " . ($ping['message'] ?? 'Unknown error');
            }
        }
        header("Location: DomainSettings.php");
        exit();
    }

    // Fetch current settings
    $nameservers = '';
    $ip_address = '';
    $registrar_url = '';
    $sql_fetch = "SELECT * FROM sas_super_admin_options WHERE option_name IN ('domain_nameservers', 'domain_ip_address', 'domain_registrar_url')";
    $result = mysqli_query($connection_server, $sql_fetch);
    while($row = mysqli_fetch_assoc($result)) {
        if($row['option_name'] == 'domain_nameservers') { $nameservers = $row['option_value']; }
        if($row['option_name'] == 'domain_ip_address') { $ip_address = $row['option_value']; }
        if($row['option_name'] == 'domain_registrar_url') { $registrar_url = $row['option_value']; }
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Domain Management | Super Admin</title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
    <style>
        body { background-color: #f6f9ff; font-family: 'Inter', sans-serif; }
        .card { border: none; border-radius: 12px; box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075); }
        .form-label { font-weight: 600; font-size: 0.85rem; color: #444; margin-bottom: 5px; }
        .form-control, .form-select { border-radius: 8px; border: 1px solid #e0e0e0; padding: 0.6rem 1rem; }
        .form-control:focus { box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.05); }
        .table thead th { background: #f8f9fa; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.5px; border: none; padding: 1rem; }
        .table tbody td { padding: 1rem; vertical-align: middle; border-color: #f1f1f1; }
        .price-badge { background: rgba(13, 110, 253, 0.1); color: #0d6efd; padding: 4px 12px; border-radius: 6px; font-weight: 700; font-size: 0.9rem; }
        .promo-badge { background: rgba(25, 135, 84, 0.1); color: #198754; padding: 4px 12px; border-radius: 6px; font-weight: 700; font-size: 0.9rem; }
        .btn-primary { border-radius: 8px; padding: 0.6rem 1.5rem; font-weight: 600; }
        .action-icon { width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; border-radius: 8px; transition: all 0.2s; }
        .action-icon:hover { background: #fee2e2; color: #dc2626 !important; }
    </style>
</head>
<body>
    <?php include("../func/bc-spadmin-header.php"); ?>

    <div class="pagetitle">
        <h1>Domain Configuration</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li>
                <li class="breadcrumb-item active">Domain Settings</li>
            </ol>
        </nav>
    </div>

    <section class="section">
        <div class="row g-4">
            <!-- Left Column: Manage Extensions -->
            <div class="col-lg-7">
                <div class="card h-100">
                    <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                        <h5 class="fw-bold mb-0 text-primary">Domain Extensions & Pricing</h5>
                        <i class="bi bi-gear-fill text-muted"></i>
                    </div>
                    <div class="card-body p-4">
                        <form method="POST" action="" class="mb-4">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label">TLD Extension</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light border-end-0">.</span>
                                        <input type="text" name="extension" class="form-control border-start-0" placeholder="com" required>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Normal Price (₦)</label>
                                    <input type="number" step="0.01" name="price" class="form-control" placeholder="5000" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Promo Price (₦)</label>
                                    <input type="number" step="0.01" name="promo_price" class="form-control" placeholder="3500">
                                </div>
                                <div class="col-12 mt-3">
                                    <button type="submit" name="add_extension" class="btn btn-primary w-100 shadow-sm">
                                        <i class="bi bi-plus-circle me-2"></i>ADD NEW EXTENSION
                                    </button>
                                </div>
                            </div>
                        </form>

                        <div class="table-responsive mt-2">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Extension</th>
                                        <th>1st Year Promo</th>
                                        <th>Renewal Price</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $ext_res = mysqli_query($connection_server, "SELECT * FROM sas_domain_extensions ORDER BY extension ASC");
                                    if(mysqli_num_rows($ext_res) > 0):
                                        while($ext = mysqli_fetch_assoc($ext_res)):
                                    ?>
                                    <tr>
                                        <td class="fw-bold fs-6"><?php echo htmlspecialchars($ext['extension']); ?></td>
                                        <td>
                                            <?php if($ext['promo_price'] > 0): ?>
                                                <span class="promo-badge">₦<?php echo number_format($ext['promo_price'], 2); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted small">None</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="price-badge">₦<?php echo number_format($ext['price'], 2); ?></span></td>
                                        <td class="text-end">
                                            <a href="?delete_ext=<?php echo $ext['id']; ?>" class="action-icon text-muted" onclick="return confirm('Delete this extension?')">
                                                <i class="bi bi-trash3-fill"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endwhile; else: ?>
                                        <tr><td colspan="4" class="text-center py-5 text-muted">No extensions added yet.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: General Settings -->
            <div class="col-lg-5">
                <div class="card mb-4">
                    <div class="card-header bg-white py-3 border-0">
                        <h5 class="fw-bold mb-0 text-primary">Global Instructions</h5>
                    </div>
                    <div class="card-body p-4">
                        <?php if(isset($_SESSION['page_alert'])): ?>
                            <div class="alert alert-success alert-dismissible fade show border-0 rounded-3 small mb-4" role="alert">
                                <i class="bi bi-check-circle-fill me-2"></i><?php echo $_SESSION['page_alert']; unset($_SESSION['page_alert']); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="">
                            <div class="mb-3">
                                <label class="form-label">Nameservers</label>
                                <textarea class="form-control" name="nameservers" rows="3" placeholder="ns1.example.com&#10;ns2.example.com"><?php echo htmlspecialchars($nameservers); ?></textarea>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">A-Record IP Address</label>
                                <input type="text" class="form-control" name="ip_address" value="<?php echo htmlspecialchars($ip_address); ?>" placeholder="192.168.1.1">
                            </div>
                            <div class="mb-4">
                                <label class="form-label">Recommended Registrar URL</label>
                                <input type="url" class="form-control" name="registrar_url" value="<?php echo htmlspecialchars($registrar_url); ?>" placeholder="https://namecheap.com">
                            </div>
                            <button type="submit" name="save_settings" class="btn btn-primary w-100 shadow-sm py-2">
                                <i class="bi bi-save2 me-2"></i>SAVE GLOBAL SETTINGS
                            </button>
                        </form>
                    </div>
                </div>

                <!-- WHMP Billing Portal Configuration -->
                <div class="card mb-4 border-0 border-primary border-2">
                    <div class="card-header bg-primary bg-opacity-10 py-3 border-0">
                        <h5 class="fw-bold mb-0 text-primary"><i class="bi bi-boxes me-2"></i>WHMP Billing Portal</h5>
                        <span class="badge bg-success" id="whmp-status-badge" style="font-size:0.7rem;">Checking...</span>
                    </div>
                    <div class="card-body p-4">
                        <p class="small text-muted mb-3">
                            <i class="bi bi-info-circle me-1"></i>
                            WHMP (Philmore Host Unlimited) is our in-house billing platform for domain name registration.
                            <a href="https://client.philmorehost.com/client/reseller/docs" target="_blank" class="text-primary">API Docs</a>
                        </p>

                        <?php
                        $whmp_status = whmp_get_config_status();
                        $whmp_api_url = getSuperAdminOption('whmp_api_url', 'https://client.philmorehost.com/api');
                        $whmp_api_key = getSuperAdminOption('whmp_api_key', '');
                        $whmp_api_secret = getSuperAdminOption('whmp_api_secret', '');
                        ?>

                        <form method="POST" action="">
                            <div class="mb-3">
                                <label class="form-label">API Base URL</label>
                                <input type="text" class="form-control" name="whmp_api_url" value="<?php echo htmlspecialchars($whmp_api_url); ?>" placeholder="https://client.philmorehost.com/api">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">API Key</label>
                                <input type="password" class="form-control" name="whmp_api_key" value="<?php echo htmlspecialchars($whmp_api_key); ?>" placeholder="Your WHMP API key">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">API Secret</label>
                                <input type="password" class="form-control" name="whmp_api_secret" value="<?php echo htmlspecialchars($whmp_api_secret); ?>" placeholder="Your WHMP API secret">
                            </div>
                            <div class="d-flex gap-2">
                                <button type="submit" name="save_whmp_config" class="btn btn-primary btn-sm px-4">
                                    <i class="bi bi-save2 me-1"></i>Save
                                </button>
                                <button type="submit" name="test_whmp" class="btn btn-outline-primary btn-sm px-4">
                                    <i class="bi bi-broadcast me-1"></i>Test
                                </button>
                            </div>
                        </form>

                        <hr class="my-3">

                        <div class="mb-3">
                            <label class="form-label fw-bold">Sync Domain Extensions from WHMP</label>
                            <p class="small text-muted">Fetch the latest TLD prices from WHMP reseller pricing and update your extension list.</p>
                            <button type="submit" name="sync_whmp_extensions" form="whmp-sync-form" class="btn btn-info btn-sm px-4">
                                <i class="bi bi-cloud-arrow-down me-1"></i>Sync Extensions
                            </button>
                            <form id="whmp-sync-form" method="POST" action="" class="d-none"></form>
                        </div>

                        <div class="alert alert-light border small mb-0">
                            <i class="bi bi-shield-check me-1 text-success"></i>
                            <strong>Status:</strong>
                            <?php if ($whmp_status['configured']): ?>
                                <span class="text-success">Configured</span> — Domain availability checking and registration via WHMP is active.
                            <?php else: ?>
                                <span class="text-warning">Not configured</span> — Domain checking falls back to WHMCS. Domain registration will be manual.
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Info Box -->
                <div class="card border-0 bg-primary bg-opacity-10">
                    <div class="card-body p-4">
                        <div class="d-flex align-items-start gap-3">
                            <i class="bi bi-lightbulb-fill text-primary fs-3"></i>
                            <div>
                                <h6 class="fw-bold text-primary mb-1">Setup Tip</h6>
                                <p class="small mb-0 text-dark opacity-75">These details are automatically injected into registration emails. Ensure your IP address is static to prevent connection issues for vendors.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <?php include("../func/bc-spadmin-footer.php"); ?>
    <script src="../assets-2/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>

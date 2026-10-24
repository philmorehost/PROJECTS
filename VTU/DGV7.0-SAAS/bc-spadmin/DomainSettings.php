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

    // Save the administrator-controlled USD to NGN rate used for WHMP domain prices.
    if (isset($_POST['save_whmp_rate'])) {
        $rate_raw = trim($_POST['whmp_usd_to_ngn_rate'] ?? '');
        if (!is_numeric($rate_raw) || !is_finite((float)$rate_raw) || (float)$rate_raw <= 0 || (float)$rate_raw > 1000000) {
            $_SESSION['page_alert'] = "Enter a valid USD to NGN rate greater than 0 and no more than 1,000,000.";
        } else {
            $rate_value = number_format((float)$rate_raw, 4, '.', '');
            $save_rate = setSuperAdminOption('whmp_usd_to_ngn_rate', $rate_value);
            if ($save_rate) {
                $_SESSION['page_alert'] = "WHMP USD to NGN conversion rate saved: ₦$rate_value per $1 USD.";
            } else {
                $_SESSION['page_alert'] = "Could not save the conversion rate: " . mysqli_error($connection_server);
            }
        }
        header("Location: DomainSettings.php");
        exit();
    }

    // ─── WHMP API Configuration ───────────────────────────────────────────────────────────────────
    if(isset($_POST['save_whmp_config'])) {
        $whmp_api_url = trim($_POST['whmp_api_url'] ?? 'https://client.philmorehost.com/api');
        $whmp_api_key = trim($_POST['whmp_api_key'] ?? '');
        $whmp_api_secret = trim($_POST['whmp_api_secret'] ?? '');

        $config_saved = setSuperAdminOption('whmp_api_url', $whmp_api_url);
        if ($whmp_api_key !== '') {
            $config_saved = setSuperAdminOption('whmp_api_key', $whmp_api_key) && $config_saved;
        }
        if ($whmp_api_secret !== '') {
            $config_saved = setSuperAdminOption('whmp_api_secret', $whmp_api_secret) && $config_saved;
        }
        $_SESSION['page_alert'] = $config_saved
            ? "WHMP API settings saved. The connection status will be checked now."
            : "Could not save all WHMP API settings: " . mysqli_error($connection_server);
        header("Location: DomainSettings.php");
        exit();
    }

    // Sync WHMP USD domain prices into the site's NGN domain price table.
    if (isset($_POST['sync_whmp_extensions'])) {
        $usd_to_ngn_rate = whmp_get_usd_to_ngn_rate();
        if (!whmp_is_configured()) {
            $_SESSION['page_alert'] = "WHMP API is not configured. Please add your API key and secret first.";
        } elseif ($usd_to_ngn_rate <= 0) {
            $_SESSION['page_alert'] = "Set and save the USD to NGN conversion rate before syncing WHMP prices.";
        } else {
            $pricing = whmp_get_pricing();
            if (($pricing['status'] ?? '') === 'success') {
                $synced = 0;
                $failed = 0;
                $sync_stmt = mysqli_prepare($connection_server,
                    "INSERT INTO sas_domain_extensions (extension, price, promo_price) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE price=VALUES(price), promo_price=VALUES(promo_price)");

                if (!$sync_stmt) {
                    $_SESSION['page_alert'] = "WHMP pricing downloaded, but domain prices could not be prepared for saving: " . mysqli_error($connection_server);
                } else {
                    $domains = $pricing['data']['domains'] ?? [];
                    foreach ($domains as $dom) {
                        $ext = trim((string)($dom['tld'] ?? ''));
                        if ($ext === '') continue;

                        $usd_reseller = (float)($dom['register']['reseller'] ?? 0);
                        $usd_list = (float)($dom['register']['list'] ?? 0);
                        if ($usd_reseller <= 0 && $usd_list <= 0) continue;

                        // Save list price in NGN. If reseller price is lower, use the converted
                        // reseller price as the promotional price, preserving current semantics.
                        $ngn_price = whmp_convert_usd_to_ngn($usd_list > 0 ? $usd_list : $usd_reseller, $usd_to_ngn_rate);
                        $ngn_promo = ($usd_reseller > 0 && $usd_list > 0 && $usd_reseller < $usd_list)
                            ? whmp_convert_usd_to_ngn($usd_reseller, $usd_to_ngn_rate)
                            : 0.0;

                        mysqli_stmt_bind_param($sync_stmt, 'sdd', $ext, $ngn_price, $ngn_promo);
                        if (mysqli_stmt_execute($sync_stmt)) {
                            $synced++;
                        } else {
                            $failed++;
                        }
                    }
                    mysqli_stmt_close($sync_stmt);
                    if ($synced > 0 && $failed === 0) {
                        $_SESSION['page_alert'] = "WHMP sync complete! $synced extensions converted at ₦" . number_format($usd_to_ngn_rate, 4) . " per USD and updated.";
                    } elseif ($synced > 0) {
                        $_SESSION['page_alert'] = "WHMP sync partially complete: $synced updated, $failed failed.";
                    } elseif ($failed > 0) {
                        $_SESSION['page_alert'] = "WHMP sync failed: all $failed domain price updates failed.";
                    } else {
                        $_SESSION['page_alert'] = "WHMP returned no usable domain prices to sync.";
                    }
                }
            } else {
                $_SESSION['page_alert'] = "WHMP sync failed: " . ($pricing['message'] ?? 'Unknown error');
            }
        }
        header("Location: DomainSettings.php");
        exit();
    }

    // Fetch current settings
    $nameservers = '';
    $ip_address = '';
    $registrar_url = '';
    $whmp_usd_to_ngn_rate = whmp_get_usd_to_ngn_rate();
    $whmp_status = whmp_get_config_status();
    $whmp_api_url = getSuperAdminOption('whmp_api_url', 'https://client.philmorehost.com/api');
    $whmp_api_key = getSuperAdminOption('whmp_api_key', '');
    $whmp_api_secret = getSuperAdminOption('whmp_api_secret', '');
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
                        <?php if(isset($_SESSION['page_alert'])):
                            $alert_message = $_SESSION['page_alert'];
                            $alert_class = preg_match('/failed|error|could not|invalid|enter a valid|not configured|no usable|before syncing/i', $alert_message)
                                ? 'alert-danger'
                                : (preg_match('/partial/i', $alert_message) ? 'alert-warning' : 'alert-success');
                        ?>
                            <div class="alert <?php echo $alert_class; ?> alert-dismissible fade show border-0 rounded-3 small mb-4" role="alert">
                                <i class="bi <?php echo $alert_class === 'alert-success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'; ?> me-2"></i><?php echo htmlspecialchars($alert_message, ENT_QUOTES, 'UTF-8'); unset($_SESSION['page_alert']); ?>
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
                        <span class="badge <?php echo $whmp_status['configured'] ? 'bg-secondary' : 'bg-warning text-dark'; ?>" id="whmp-status-badge" style="font-size:0.7rem;"><?php echo $whmp_status['configured'] ? 'Checking WHMP...' : 'Not configured'; ?></span>
                    </div>
                    <div class="card-body p-4">
                        <p class="small text-muted mb-3">
                            <i class="bi bi-info-circle me-1"></i>
                            WHMP (Philmore Host Unlimited) is our in-house billing platform for domain name registration.
                            <a href="https://client.philmorehost.com/client/reseller/docs" target="_blank" class="text-primary">API Docs</a>
                        </p>

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
                                <button type="button" id="test-whmp-connection" class="btn btn-outline-primary btn-sm px-4" <?php echo $whmp_status['configured'] ? '' : 'disabled title="Save WHMP credentials first"'; ?>>
                                    <i class="bi bi-broadcast me-1"></i>Check connection
                                </button>
                            </div>
                        </form>

                        <div id="whmp-connection-message" class="small mt-2" role="status" aria-live="polite"></div>

                        <hr class="my-3">

                        <form method="POST" action="" class="border rounded-3 bg-light p-3 mb-3">
                            <label class="form-label fw-bold" for="whmp-usd-ngn-rate">USD to NGN conversion rate</label>
                            <div class="input-group">
                                <span class="input-group-text">$1 USD = ₦</span>
                                <input type="number" class="form-control" id="whmp-usd-ngn-rate" name="whmp_usd_to_ngn_rate"
                                       min="0.0001" max="1000000" step="0.0001" required
                                       value="<?php echo $whmp_usd_to_ngn_rate > 0 ? htmlspecialchars(rtrim(rtrim(number_format($whmp_usd_to_ngn_rate, 4, '.', ''), '0'), '.'), ENT_QUOTES, 'UTF-8') : ''; ?>"
                                       placeholder="e.g. 1600">
                                <button type="submit" name="save_whmp_rate" class="btn btn-outline-primary">
                                    <i class="bi bi-save2 me-1"></i>Save rate
                                </button>
                            </div>
                            <div class="form-text">WHMP prices are in USD. Saving a rate converts both list prices and reseller promo prices to NGN during sync.</div>
                        </form>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Sync Domain Extensions from WHMP</label>
                            <p class="small text-muted">Prices are saved in Naira using the conversion rate above. Existing extension prices and promo prices are updated.</p>
                            <button type="submit" name="sync_whmp_extensions" form="whmp-sync-form" class="btn btn-info btn-sm px-4" <?php echo $whmp_usd_to_ngn_rate > 0 ? '' : 'disabled title="Save a USD to NGN rate first"'; ?>>
                                <i class="bi bi-cloud-arrow-down me-1"></i>Sync Extensions
                            </button>
                            <form id="whmp-sync-form" method="POST" action="" class="d-none"></form>
                        </div>

                        <div class="alert alert-light border small mb-0">
                            <i class="bi bi-shield-check me-1 text-success"></i>
                            <strong>Status:</strong>
                            <?php if ($whmp_status['configured']): ?>
                                <span class="text-muted">Credentials saved.</span> <span id="whmp-status-detail">Checking API connectivity…</span>
                            <?php else: ?>
                                <span class="text-warning">Not configured</span> — Save the WHMP API key and secret to enable connection testing and syncing.
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
    <?php if ($whmp_status['configured']): ?>
    <script>
    (() => {
        const badge = document.getElementById('whmp-status-badge');
        const detail = document.getElementById('whmp-status-detail');
        const message = document.getElementById('whmp-connection-message');
        const checkButton = document.getElementById('test-whmp-connection');
        const checkConnection = async () => {
            badge.className = 'badge bg-secondary';
            badge.textContent = 'Checking WHMP...';
            detail.textContent = 'Contacting the WHMP API…';
            message.textContent = '';
            checkButton.disabled = true;
            try {
                const response = await fetch('ajax-whmp-status.php', {
                    headers: { 'Accept': 'application/json' },
                    cache: 'no-store',
                    credentials: 'same-origin'
                });
                if (!(response.headers.get('content-type') || '').includes('application/json')) {
                    throw new Error('Could not read the connection status. Your admin session may have expired; sign in again.');
                }
                const result = await response.json();
                if (!response.ok || !result.success) throw new Error(result.message || 'WHMP connection check failed.');
                badge.className = 'badge bg-success';
                badge.textContent = 'Connected';
                detail.textContent = result.message || 'WHMP API is reachable.';
                message.className = 'small mt-2 text-success';
                message.textContent = result.message || 'WHMP API connection successful.';
            } catch (error) {
                badge.className = 'badge bg-danger';
                badge.textContent = 'Connection failed';
                detail.textContent = error.message;
                message.className = 'small mt-2 text-danger';
                message.textContent = error.message;
            } finally {
                checkButton.disabled = false;
            }
        };
        checkButton.addEventListener('click', checkConnection);
        checkConnection();
    })();
    </script>
    <?php endif; ?>
</body>
</html>

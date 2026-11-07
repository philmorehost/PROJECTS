<?php session_start();
include("../func/bc-spadmin-config.php");

$is_super = isset($get_logged_spadmin_details['id']);
if (!$is_super) { header("Location: /bc-spadmin/Login.php"); exit(); }

// Manual Override: Grant / Revoke Access
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = mysqli_real_escape_string($connection_server, $_GET['action']);
    $vendor_id = (int)$_GET['id'];
    
    if ($action === 'grant') {
        mysqli_query($connection_server, "UPDATE sas_vendors SET ussd_access=1 WHERE id='$vendor_id'");
        $_SESSION['product_purchase_response'] = 'USSD Access granted successfully.';
    } elseif ($action === 'revoke') {
        mysqli_query($connection_server, "UPDATE sas_vendors SET ussd_access=0 WHERE id='$vendor_id'");
        $_SESSION['product_purchase_response'] = 'USSD Access revoked successfully.';
    }
    header("Location: USSDAccessFee.php"); exit();
}

// Save global settings
if (isset($_POST['save-settings'])) {
    $fee = mysqli_real_escape_string($connection_server, $_POST['ussd_access_fee'] ?? '0');
    $enabled = mysqli_real_escape_string($connection_server, $_POST['ussd_access_enabled'] ?? '0');
    $min_dep = mysqli_real_escape_string($connection_server, $_POST['ussd_min_deposit'] ?? '0');

    $fields = [
        'ussd_access_fee' => $fee,
        'ussd_access_enabled' => $enabled,
        'hollatags_username' => mysqli_real_escape_string($connection_server, $_POST['hollatags_username'] ?? ''),
        'hollatags_password' => mysqli_real_escape_string($connection_server, $_POST['hollatags_password'] ?? ''),
        'hollatags_ussd_code' => mysqli_real_escape_string($connection_server, $_POST['hollatags_ussd_code'] ?? ''),
        'ussd_per_call_charge' => mysqli_real_escape_string($connection_server, $_POST['ussd_per_call_charge'] ?? '0'),
        'ussd_min_deposit' => $min_dep
    ];

    foreach ($fields as $name => $val) {
        $check = mysqli_query($connection_server, "SELECT * FROM sas_super_admin_options WHERE option_name='$name'");
        if (mysqli_num_rows($check) > 0) {
            mysqli_query($connection_server, "UPDATE sas_super_admin_options SET option_value='$val' WHERE option_name='$name'");
        } else {
            mysqli_query($connection_server, "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('$name','$val')");
        }
    }

    $_SESSION['product_purchase_response'] = 'USSD settings successfully updated.';
    header("Location: USSDAccessFee.php"); exit();
}

$fee = getSuperAdminOption('ussd_access_fee', '0');
$enabled = getSuperAdminOption('ussd_access_enabled', '0');
$ht_user = getSuperAdminOption('hollatags_username', '');
$ht_pass = getSuperAdminOption('hollatags_password', '');
$ht_code = getSuperAdminOption('hollatags_ussd_code', '');
$per_call = getSuperAdminOption('ussd_per_call_charge', '0');
$min_deposit = getSuperAdminOption('ussd_min_deposit', '0');

// Fetch Vendors
$vendors_query = mysqli_query($connection_server, "SELECT id, firstname, lastname, email, balance, ussd_access, website_url FROM sas_vendors WHERE status=1 ORDER BY firstname ASC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>USSD Channel Access | Super Admin</title>
    <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
    <style>
        .premium-card { border: none; border-radius: 1.25rem; box-shadow: 0 5px 20px rgba(0,0,0,0.05); }
        .config-banner {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: white; border-radius: 1.25rem; padding: 2rem; margin-bottom: 1.5rem;
        }
        .input-box {
            background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 0.75rem;
            padding: 12px 16px; transition: all 0.2s;
        }
        .input-box:focus-within { background: white; border-color: #0d6efd; box-shadow: 0 0 0 4px rgba(13,110,253,0.1); }
        .input-box input { border: none; background: transparent; width: 100%; outline: none; font-size: 0.9rem; }
        .input-box i { color: #64748b; margin-right: 10px; }
        .btn-action {
            border-radius: 0.75rem; padding: 12px; font-weight: 700; font-size: 0.9rem;
            display: flex; align-items: center; justify-content: center; gap: 8px; border: none;
        }
    </style>
</head>
<body>
<?php include("../func/bc-spadmin-header.php"); ?>

    <div class="pagetitle mb-4">
        <h1 class="h3 fw-bold mb-1">USSD Channel Access</h1>
        <p class="text-muted small">Manage access fees and activations for vendor USSD channels.</p>
    </div>

    <section class="section">
        <div class="config-banner shadow-sm mb-4">
            <h4 class="fw-bold mb-1"><i class="bi bi-phone-fill me-2 text-primary"></i>USSD Channel Configuration</h4>
            <p class="mb-0 opacity-75 small">Set the fee required for vendors to activate USSD features on their platforms.</p>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-lg-6">
                <div class="card premium-card h-100">
                    <div class="card-body p-4">
                        <form method="post">
                            <div class="mb-3">
                                <label class="small fw-bold text-muted mb-2">USSD CHANNEL ACCESS FEE (NGN)</label>
                                <p class="small text-muted mb-2" style="font-size: 0.75rem;">One-time activation fee charged to vendor wallets.</p>
                                <div class="input-box d-flex align-items-center">
                                    <i class="bi bi-cash text-success"></i>
                                    <input name="ussd_access_fee" type="number" step="0.01" value="<?php echo htmlspecialchars($fee); ?>" required>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="small fw-bold text-muted mb-2">MINIMUM INITIAL DEPOSIT (NGN)</label>
                                <p class="small text-muted mb-2" style="font-size: 0.75rem;">Minimum wallet balance required for activation (remains in vendor's wallet).</p>
                                <div class="input-box d-flex align-items-center">
                                    <i class="bi bi-piggy-bank text-info"></i>
                                    <input name="ussd_min_deposit" type="number" step="0.01" value="<?php echo htmlspecialchars($min_deposit); ?>" required>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="small fw-bold text-muted mb-2">PER-USSD CALL CHARGE (NGN)</label>
                                <p class="small text-muted mb-2" style="font-size: 0.75rem;">Fee charged to vendor wallets per successful USSD redemption.</p>
                                <div class="input-box d-flex align-items-center">
                                    <i class="bi bi-wallet2 text-warning"></i>
                                    <input name="ussd_per_call_charge" type="number" step="0.01" value="<?php echo htmlspecialchars($per_call); ?>" required>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="small fw-bold text-muted mb-2">HOLLATAGS USERNAME</label>
                                <div class="input-box d-flex align-items-center">
                                    <i class="bi bi-person"></i>
                                    <input name="hollatags_username" type="text" value="<?php echo htmlspecialchars($ht_user); ?>" placeholder="Enter Hollatags username">
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="small fw-bold text-muted mb-2">HOLLATAGS PASSWORD</label>
                                <div class="input-box d-flex align-items-center">
                                    <i class="bi bi-key"></i>
                                    <input name="hollatags_password" type="password" value="<?php echo htmlspecialchars($ht_pass); ?>" placeholder="Enter Hollatags password">
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="small fw-bold text-muted mb-2">HOLLATAGS USSD CODE</label>
                                <div class="input-box d-flex align-items-center">
                                    <i class="bi bi-phone"></i>
                                    <input name="hollatags_ussd_code" type="text" value="<?php echo htmlspecialchars($ht_code); ?>" placeholder="e.g. *384*241#">
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <label class="small fw-bold text-muted mb-2">GLOBAL USSD SERVICE STATE</label>
                                <p class="small text-muted mb-2" style="font-size: 0.75rem;">Enable or disable the USSD activation tab for all vendors.</p>
                                <select name="ussd_access_enabled" class="form-select border-0 bg-light p-3 rounded-3" style="font-size: 0.9rem;">
                                    <option value="1" <?php echo $enabled == '1' ? 'selected' : ''; ?>>Active / Enabled</option>
                                    <option value="0" <?php echo $enabled == '0' ? 'selected' : ''; ?>>Disabled</option>
                                </select>
                            </div>

                            <button name="save-settings" type="submit" class="btn-action w-100 btn-primary text-white shadow-sm mt-2">
                                <i class="bi bi-save"></i> Save Settings
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-6">
                <div class="card premium-card bg-light h-100">
                    <div class="card-body p-4">
                        <h6 class="fw-bold mb-4"><i class="bi bi-info-circle me-2"></i>USSD Feature Rules</h6>
                        <ul class="small text-muted" style="line-height: 1.8;">
                            <li>Setting the fee to <strong>0</strong> makes the USSD channel activation free for all vendors.</li>
                            <li>Toggling the Global USSD Service State to <strong>Disabled</strong> will lock USSD functionality and hide the setup tab for all vendors, regardless of their payment status.</li>
                            <li>You can manually grant or revoke access using the vendor directory below.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-12">
                <div class="card premium-card">
                    <div class="card-header bg-white py-4 border-0">
                        <h5 class="fw-bold mb-0 text-primary">Vendor USSD Directory</h5>
                        <p class="text-muted small mb-0">View payment status and manually manage access override.</p>
                    </div>
                    <div class="card-body p-4">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle border rounded-3 overflow-hidden">
                                <thead class="bg-light">
                                    <tr class="small text-uppercase text-muted">
                                        <th class="ps-3">Vendor</th><th>URL</th><th>Wallet Balance</th><th>USSD Status</th><th class="text-end pe-3">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (mysqli_num_rows($vendors_query) > 0): ?>
                                        <?php while ($v = mysqli_fetch_assoc($vendors_query)): ?>
                                        <tr>
                                            <td class="ps-3">
                                                <div class="fw-bold text-dark mb-0"><?php echo htmlspecialchars($v['firstname'].' '.$v['lastname']); ?></div>
                                                <div class="small text-muted"><?php echo htmlspecialchars($v['email']); ?></div>
                                            </td>
                                            <td>
                                                <a href="//<?php echo htmlspecialchars($v['website_url']); ?>" target="_blank" class="small text-decoration-none"><i class="bi bi-link-45deg me-1"></i><?php echo htmlspecialchars($v['website_url']); ?></a>
                                            </td>
                                            <td>
                                                <div class="fw-bold">₦<?php echo number_format($v['balance'], 2); ?></div>
                                            </td>
                                            <td>
                                                <?php if ($v['ussd_access'] == 1): ?>
                                                    <span class="badge bg-success bg-opacity-10 text-success border"><i class="bi bi-check-circle-fill me-1"></i>Active</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger bg-opacity-10 text-danger border"><i class="bi bi-lock-fill me-1"></i>Locked</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end pe-3">
                                                <?php if ($v['ussd_access'] == 1): ?>
                                                    <a href="USSDAccessFee.php?action=revoke&id=<?php echo $v['id']; ?>" class="btn btn-outline-danger btn-sm rounded-pill fw-bold" onclick="return confirm('Are you sure you want to revoke USSD access for this vendor?');">Revoke Access</a>
                                                <?php else: ?>
                                                    <a href="USSDAccessFee.php?action=grant&id=<?php echo $v['id']; ?>" class="btn btn-outline-success btn-sm rounded-pill fw-bold" onclick="return confirm('Are you sure you want to grant USSD access to this vendor?');">Grant Access</a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr><td colspan="5" class="text-center py-4 text-muted small">No vendors found.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

<?php include("../func/bc-spadmin-footer.php"); ?>
<?php if (isset($_SESSION["product_purchase_response"])): ?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script>Swal.fire({ icon: 'success', title: 'Action Successful', text: '<?php echo addslashes($_SESSION["product_purchase_response"]); ?>', borderRadius: '1rem' });</script>
<?php unset($_SESSION["product_purchase_response"]); endif; ?>
</body>
</html>
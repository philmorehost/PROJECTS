<?php session_start();
include("../func/bc-spadmin-config.php");

$is_super = isset($get_logged_spadmin_details['id']);
if (!$is_super) { header("Location: /bc-spadmin/Login.php"); exit(); }

if (isset($_POST['save-pricing'])) {
    $marketing_usd = mysqli_real_escape_string($connection_server, $_POST['wa_marketing_base_usd'] ?? '0.022');
    $utility_usd = mysqli_real_escape_string($connection_server, $_POST['wa_utility_base_usd'] ?? '0.006');
    $profit_margin = mysqli_real_escape_string($connection_server, $_POST['wa_profit_margin_percent'] ?? '15');

    $fields = [
        'wa_marketing_base_usd' => $marketing_usd,
        'wa_utility_base_usd' => $utility_usd,
        'wa_profit_margin_percent' => $profit_margin
    ];

    foreach ($fields as $name => $val) {
        $check = mysqli_query($connection_server, "SELECT * FROM sas_super_admin_options WHERE option_name='$name'");
        if(mysqli_num_rows($check) > 0) {
            mysqli_query($connection_server, "UPDATE sas_super_admin_options SET option_value='$val' WHERE option_name='$name'");
        } else {
            mysqli_query($connection_server, "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('$name','$val')");
        }
    }

    $_SESSION['product_purchase_response'] = 'WhatsApp Billing Pricing successfully updated.';
    header("Location: WhatsAppPricing.php"); exit();
}

$marketing_usd = getSuperAdminOption('wa_marketing_base_usd', '0.022');
$utility_usd = getSuperAdminOption('wa_utility_base_usd', '0.006');
$profit_margin = getSuperAdminOption('wa_profit_margin_percent', '15');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>WhatsApp Pricing | Super Admin</title>
    <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
    <style>
        :root { --meta-blue: #0668E1; --wa-green: #25D366; }
        
        .premium-card { border: none; border-radius: 1.25rem; box-shadow: 0 5px 20px rgba(0,0,0,0.05); }
        .config-banner {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: white; border-radius: 1.25rem; padding: 2rem; margin-bottom: 1.5rem;
            display: flex; align-items: center; justify-content: space-between;
        }
        .input-box {
            background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 0.75rem;
            padding: 12px 16px; transition: all 0.2s;
        }
        .input-box:focus-within { background: white; border-color: var(--wa-green); box-shadow: 0 0 0 4px rgba(37,211,102,0.1); }
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
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1 class="h3 fw-bold mb-1">WhatsApp Billing Engine</h1>
                <p class="text-muted small">Manage live-conversion billing rates and profit margins.</p>
            </div>
            <a href="WhatsAppAIManager.php" class="btn btn-outline-dark rounded-pill px-4 btn-sm fw-bold"><i class="bi bi-arrow-left me-2"></i>Back to API</a>
        </div>
    </div>

    <section class="section">
        <div class="config-banner shadow-sm">
            <div>
                <h4 class="fw-bold mb-1"><i class="bi bi-cash-stack me-2 text-success"></i>Revenue Configuration</h4>
                <p class="mb-0 opacity-75 small">Set base USD costs. The system auto-converts to NGN and adds your markup.</p>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-6">
                <div class="card premium-card h-100">
                    <div class="card-body p-4">
                        <form method="post">
                            <div class="mb-4">
                                <label class="small fw-bold text-muted mb-2">MARKETING COST (USD)</label>
                                <p class="small text-muted mb-2" style="font-size: 0.75rem;">Used for promotional vendor broadcasts. Approx $0.022</p>
                                <div class="input-box d-flex align-items-center">
                                    <i class="bi bi-currency-dollar text-success"></i>
                                    <input name="wa_marketing_base_usd" type="text" value="<?php echo htmlspecialchars($marketing_usd); ?>" required>
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <label class="small fw-bold text-muted mb-2">UTILITY COST (USD)</label>
                                <p class="small text-muted mb-2" style="font-size: 0.75rem;">Used for transaction receipts and alerts. Approx $0.006</p>
                                <div class="input-box d-flex align-items-center">
                                    <i class="bi bi-currency-dollar text-primary"></i>
                                    <input name="wa_utility_base_usd" type="text" value="<?php echo htmlspecialchars($utility_usd); ?>" required>
                                </div>
                            </div>

                            <div class="mb-4">
                                <label class="small fw-bold text-muted mb-2">PLATFORM PROFIT MARGIN (%)</label>
                                <p class="small text-muted mb-2" style="font-size: 0.75rem;">This percentage is added to the converted NGN cost before charging vendors.</p>
                                <div class="input-box d-flex align-items-center">
                                    <i class="bi bi-percent text-warning"></i>
                                    <input name="wa_profit_margin_percent" type="text" value="<?php echo htmlspecialchars($profit_margin); ?>" required>
                                </div>
                            </div>

                            <button name="save-pricing" type="submit" class="btn-action w-100 bg-success text-white shadow-sm mt-2">
                                <i class="bi bi-save"></i> Save Billing Settings
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card premium-card bg-light">
                    <div class="card-body p-4">
                        <h6 class="fw-bold mb-4"><i class="bi bi-calculator me-2"></i>Live Pricing Example</h6>
                        <?php 
                        require_once("../func/bc-giftcard-func.php");
                        $live_rate = getLiveUSDToNGNRate(0); 
                        $markup_multiplier = 1 + ($profit_margin / 100);
                        
                        $marketing_ngn = $marketing_usd * $live_rate * $markup_multiplier;
                        $utility_ngn = $utility_usd * $live_rate * $markup_multiplier;
                        ?>
                        
                        <div class="p-3 bg-white rounded-3 shadow-sm mb-3">
                            <span class="small text-muted fw-bold d-block mb-1">CURRENT USD TO NGN RATE</span>
                            <h4 class="mb-0 text-dark fw-bold">₦<?php echo number_format($live_rate, 2); ?></h4>
                        </div>
                        
                        <div class="p-3 bg-white rounded-3 shadow-sm mb-3">
                            <span class="small text-muted fw-bold d-block mb-1">MARKETING CHARGE TO VENDOR</span>
                            <h5 class="mb-0 text-success fw-bold">₦<?php echo number_format($marketing_ngn, 2); ?> <span class="small text-muted fw-normal">/ message</span></h5>
                            <p class="mb-0 small text-muted mt-1" style="font-size:0.7rem;">(Base: $<?php echo $marketing_usd; ?>) + <?php echo $profit_margin; ?>% margin</p>
                        </div>
                        
                        <div class="p-3 bg-white rounded-3 shadow-sm">
                            <span class="small text-muted fw-bold d-block mb-1">UTILITY CHARGE TO VENDOR</span>
                            <h5 class="mb-0 text-primary fw-bold">₦<?php echo number_format($utility_ngn, 2); ?> <span class="small text-muted fw-normal">/ message</span></h5>
                            <p class="mb-0 small text-muted mt-1" style="font-size:0.7rem;">(Base: $<?php echo $utility_usd; ?>) + <?php echo $profit_margin; ?>% margin</p>
                        </div>
                        
                    </div>
                </div>
            </div>
        </div>
    </section>

<?php include("../func/bc-spadmin-footer.php"); ?>
<?php if(isset($_SESSION["product_purchase_response"])): ?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script>Swal.fire({ icon: 'success', title: 'Saved', text: '<?php echo addslashes($_SESSION["product_purchase_response"]); ?>', borderRadius: '1rem' });</script>
<?php unset($_SESSION["product_purchase_response"]); endif; ?>
</body>
</html>

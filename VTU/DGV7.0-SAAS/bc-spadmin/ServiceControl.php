<?php session_start();
include("../func/bc-spadmin-config.php");

// Migration: Create table if not exists (redundant but safe)
mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_global_service_control (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_name VARCHAR(50) NOT NULL UNIQUE,
    status TINYINT(1) DEFAULT 1
)");

// Migration: Add auto-incrementing id column as primary key to sas_user_banks if missing
$check_user_id = mysqli_query($connection_server, "SHOW COLUMNS FROM `sas_user_banks` LIKE 'id'");
if ($check_user_id && mysqli_num_rows($check_user_id) == 0) {
    mysqli_query($connection_server, "ALTER TABLE sas_user_banks ADD COLUMN id INT AUTO_INCREMENT PRIMARY KEY FIRST");
}

// Migration: Add auto-incrementing id column as primary key to sas_vendor_banks if missing
$check_vendor_id = mysqli_query($connection_server, "SHOW COLUMNS FROM `sas_vendor_banks` LIKE 'id'");
if ($check_vendor_id && mysqli_num_rows($check_vendor_id) == 0) {
    mysqli_query($connection_server, "ALTER TABLE sas_vendor_banks ADD COLUMN id INT AUTO_INCREMENT PRIMARY KEY FIRST");
}

// Migration: Re-classify existing bank accounts to correct gateways to prevent bulk override overlap
// 1. PayHub (Bank code is 'PayHub' or reference has PH_ prefix or contains 'payhub')
mysqli_query($connection_server, "UPDATE sas_user_banks SET gateway_name='payhub' WHERE bank_code='PayHub' OR reference LIKE 'PH_%' OR bank_name LIKE '%PAYHUB%'");
mysqli_query($connection_server, "UPDATE sas_vendor_banks SET gateway_name='payhub' WHERE bank_code='PayHub' OR reference LIKE 'PH_%' OR bank_name LIKE '%PAYHUB%'");

// 2. Beewave (reference has hyphen '-' and bank_code is 110072 or name contains BEEWAVE)
mysqli_query($connection_server, "UPDATE sas_user_banks SET gateway_name='beewave' WHERE (reference LIKE '%-%' AND bank_code='110072') OR bank_name LIKE '%BEEWAVE%'");
mysqli_query($connection_server, "UPDATE sas_vendor_banks SET gateway_name='beewave' WHERE (reference LIKE '%-%' AND bank_code='110072') OR bank_name LIKE '%BEEWAVE%'");

// 3. Payvessel (reference has hyphen '-' and bank_code in 101, 120001, or name contains GLOBUS/TITAN/PAYVESSEL)
mysqli_query($connection_server, "UPDATE sas_user_banks SET gateway_name='payvessel' WHERE (reference LIKE '%-%' AND bank_code IN ('101', '120001')) OR bank_name LIKE '%GLOBUS%' OR bank_name LIKE '%TITAN%' OR bank_name LIKE '%PAYVESSEL%'");
mysqli_query($connection_server, "UPDATE sas_vendor_banks SET gateway_name='payvessel' WHERE (reference LIKE '%-%' AND bank_code IN ('101', '120001')) OR bank_name LIKE '%GLOBUS%' OR bank_name LIKE '%TITAN%' OR bank_name LIKE '%PAYVESSEL%'");

// 4. Monnify (reference length is 32 - MD5 hash)
mysqli_query($connection_server, "UPDATE sas_user_banks SET gateway_name='monnify' WHERE LENGTH(reference) = 32 AND reference REGEXP '^[0-9a-fA-F]+$'");
mysqli_query($connection_server, "UPDATE sas_vendor_banks SET gateway_name='monnify' WHERE LENGTH(reference) = 32 AND reference REGEXP '^[0-9a-fA-F]+$'");

// 5. Paystack (fallback if it is not Monnify, Payvessel, Beewave, or PayHub)
mysqli_query($connection_server, "UPDATE sas_user_banks SET gateway_name='paystack' WHERE bank_code='Paystack' OR (gateway_name != 'monnify' AND gateway_name != 'payvessel' AND gateway_name != 'beewave' AND gateway_name != 'payhub' AND gateway_name != 'paystack') OR gateway_name IS NULL OR gateway_name = ''");
mysqli_query($connection_server, "UPDATE sas_vendor_banks SET gateway_name='paystack' WHERE bank_code='Paystack' OR (gateway_name != 'monnify' AND gateway_name != 'payvessel' AND gateway_name != 'beewave' AND gateway_name != 'payhub' AND gateway_name != 'paystack') OR gateway_name IS NULL OR gateway_name = ''");

mysqli_query($connection_server, "UPDATE sas_user_banks SET status=1 WHERE status IS NULL");
mysqli_query($connection_server, "UPDATE sas_vendor_banks SET status=1 WHERE status IS NULL");


$services = [
    'data' => 'Buy Data Bundle',
    'airtime' => 'Buy Airtime VTU',
    'cable' => 'Buy CableTv Sub',
    'electric' => 'Buy Electric Token',
    'betting' => 'Fund Betting',
    'exam' => 'Buy Exam PIN',
    'bulk_sms' => 'Bulk SMS',
    'data_card' => 'Print Hub (Master Switch)',
    'recharge_card' => 'Recharge Card Printing',
    'bank_transfer' => 'Bank Transfer Service',
    'payout' => 'Payout (API & Web)',
    'withdraw' => 'Wallet to Bank',
    'password_reset' => 'Password Reset',
    'virtual_card' => 'Virtual Card System',
    'gift_card' => 'Gift Cards',
    'crypto_hub' => 'Crypto Service',
    'nin_card' => 'Digital NIN Slip',
    'bvn_verify' => 'BVN Verification',
    'virtual_bank_display' => 'Virtual Bank Button (Off by Default)',
    'ai_suite' => 'AI Business Suite'
];

$gateways = [
    'paystack' => 'Paystack',
    'flutterwave' => 'Flutterwave',
    'monnify' => 'Monnify',
    'payvessel' => 'PayVessel',
    'beewave' => 'BeeWave',
    'payhub' => 'PayHub',
    'plisio' => 'Plisio (Crypto)',
    'manual_funding' => 'Manual Bank Funding'
];

if (isset($_POST['toggle_global_service'])) {
    $service_name = mysqli_real_escape_string($connection_server, $_POST['service_name']);
    $status = (int)$_POST['status'];

    mysqli_query($connection_server, "INSERT INTO sas_global_service_control (service_name, status)
        VALUES ('$service_name', $status)
        ON DUPLICATE KEY UPDATE status=$status");

    $_SESSION["product_purchase_response"] = "Global setting updated successfully.";
    header("Location: ServiceControl.php");
    exit();
}

if (isset($_POST['bulk_update_vendor_banks'])) {
    $bulk_gw = mysqli_real_escape_string($connection_server, $_POST['bulk_gateway']);
    $bulk_status = (int)$_POST['bulk_status'];
    
    mysqli_query($connection_server, "UPDATE sas_vendor_banks SET status = $bulk_status WHERE gateway_name='$bulk_gw'");
    
    $_SESSION["product_purchase_response"] = "Bulk update applied successfully to all vendors' " . strtoupper($bulk_gw) . " accounts.";
    header("Location: ServiceControl.php");
    exit();
}

// Fetch current global settings
$global_settings = [];
$q = mysqli_query($connection_server, "SELECT service_name, status FROM sas_global_service_control");
while($r = mysqli_fetch_assoc($q)) $global_settings[$r['service_name']] = $r['status'];

// Fetch vendor virtual accounts for management
$vendor_banks = [];
$qvb = mysqli_query($connection_server, "SELECT vb.*, v.website_url, v.email, v.firstname, v.lastname FROM sas_vendor_banks vb LEFT JOIN sas_vendors v ON vb.vendor_id = v.id ORDER BY v.website_url ASC");
if ($qvb) {
    while($rvb = mysqli_fetch_assoc($qvb)) $vendor_banks[] = $rvb;
}

$available_gateways = ['monnify', 'payvessel', 'payhub', 'paystack', 'beewave'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Global Service Control | Super Admin</title>
    <?php include("../func/bc-spadmin-header-link.php"); ?>
</head>
<body>
    <?php include("../func/bc-spadmin-header.php"); ?>

    <div class="pagetitle">
      <h1>GLOBAL SERVICE CONTROL</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li>
          <li class="breadcrumb-item active">Global Service Control</li>
        </ol>
      </nav>
    </div>

    <section class="section dashboard">
        <div class="row mb-4">
            <div class="col-12">
                <div class="card bg-primary bg-opacity-10 border-primary border-start border-4 rounded-4 shadow-sm">
                    <div class="card-body py-3 d-flex align-items-center">
                        <i class="bi bi-info-circle-fill text-primary fs-3 me-3"></i>
                        <div>
                            <h6 class="mb-0 fw-bold">Master Control Center</h6>
                            <p class="mb-0 small opacity-75">Disabling a service here will hide it for <b>ALL VENDORS</b> across the entire platform, regardless of their local settings.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Core Services -->
            <div class="col-lg-6">
                <div class="card shadow-sm border-0 rounded-4">
                    <div class="card-header bg-white py-3 border-0">
                        <h5 class="fw-bold mb-0 text-primary"><i class="bi bi-cpu me-2"></i>Global Service Visibility</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush">
                            <?php foreach($services as $key => $label):
                                $status = isset($global_settings[$key]) ? $global_settings[$key] : 1;
                            ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center py-3 px-4">
                                <div>
                                    <h6 class="mb-0 fw-bold"><?php echo $label; ?></h6>
                                    <span class="small text-muted">ID: <?php echo $key; ?></span>
                                </div>
                                <form method="post" action="">
                                    <input type="hidden" name="service_name" value="<?php echo $key; ?>">
                                    <input type="hidden" name="status" value="<?php echo $status ? 0 : 1; ?>">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input h4 mb-0 cursor-pointer" type="checkbox" role="switch"
                                            <?php echo $status ? 'checked' : ''; ?>
                                            onchange="this.form.submit()">
                                        <input type="hidden" name="toggle_global_service" value="1">
                                    </div>
                                </form>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Payment Gateways -->
            <div class="col-lg-6">
                <div class="card shadow-sm border-0 rounded-4">
                    <div class="card-header bg-white py-3 border-0">
                        <h5 class="fw-bold mb-0 text-primary"><i class="bi bi-credit-card-2-front me-2"></i>Global Payment Gateways</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush">
                            <?php foreach($gateways as $key => $label):
                                $status = isset($global_settings[$key]) ? $global_settings[$key] : 1;
                            ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center py-3 px-4">
                                <div>
                                    <h6 class="mb-0 fw-bold"><?php echo $label; ?></h6>
                                    <span class="small text-muted">ID: <?php echo $key; ?></span>
                                </div>
                                <form method="post" action="">
                                    <input type="hidden" name="service_name" value="<?php echo $key; ?>">
                                    <input type="hidden" name="status" value="<?php echo $status ? 0 : 1; ?>">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input h4 mb-0 cursor-pointer" type="checkbox" role="switch"
                                            <?php echo $status ? 'checked' : ''; ?>
                                            onchange="this.form.submit()">
                                        <input type="hidden" name="toggle_global_service" value="1">
                                    </div>
                                </form>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="row mt-4">
            <!-- Vendor Virtual Accounts Management -->
            <div class="col-12">
                <div class="card shadow-sm border-0 rounded-4">
                    <div class="card-header bg-white py-3 border-0">
                        <h5 class="fw-bold mb-0 text-primary"><i class="bi bi-bank me-2"></i>Manage Vendor Virtual Accounts</h5>
                        <p class="small text-muted mb-0">Toggle individual bank accounts for specific vendors across the platform.</p>
                    </div>
                    <div class="card-body">
                        <?php if(!empty($available_gateways)): ?>
                        <form method="post" class="mb-3 mt-2 p-3 bg-light rounded d-flex flex-wrap align-items-center gap-2">
                            <span class="small fw-bold text-muted me-2"><i class="bi bi-lightning-charge-fill text-warning me-1"></i>Bulk Action:</span>
                            <select name="bulk_gateway" class="form-select form-select-sm w-auto" required>
                                <option value="">Select Gateway</option>
                                <?php foreach($available_gateways as $ag): ?>
                                    <option value="<?php echo htmlspecialchars($ag); ?>"><?php echo strtoupper($ag); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="bulk_status" class="form-select form-select-sm w-auto" required>
                                <option value="0">Turn OFF for ALL Vendors</option>
                                <option value="1">Turn ON for ALL Vendors</option>
                            </select>
                            <button type="submit" name="bulk_update_vendor_banks" class="btn btn-sm btn-dark">Apply Platform-Wide</button>
                        </form>
                        <?php endif; ?>
                        <div style="max-height: 400px; overflow-y: auto;">
                            <?php if(empty($vendor_banks)): ?>
                                <p class="text-center text-muted py-4">No vendor virtual accounts found.</p>
                            <?php else: ?>
                                <?php foreach($vendor_banks as $bank): ?>
                                <div class="list-group-item d-flex justify-content-between align-items-center py-2 px-3 mb-2 bg-light rounded border-0">
                                    <div>
                                        <h6 class="mb-0 fw-bold"><?php echo htmlspecialchars($bank['bank_name'] ?? ''); ?> (<?php echo htmlspecialchars($bank['account_number'] ?? ''); ?>)</h6>
                                        <div class="small text-muted d-flex gap-3 mt-1">
                                            <span><i class="bi bi-shop me-1"></i><?php echo htmlspecialchars(!empty($bank['website_url']) ? $bank['website_url'] : (!empty($bank['email']) ? $bank['email'] : 'Vendor #'.$bank['vendor_id'])); ?></span>
                                            <span><i class="bi bi-person me-1"></i><?php echo htmlspecialchars($bank['account_name'] ?? ''); ?></span>
                                            <span><i class="bi bi-shield-lock me-1"></i><?php echo strtoupper($bank['gateway_name'] ?? ''); ?></span>
                                        </div>
                                    </div>
                                    <div class="form-check form-switch ms-3">
                                        <input class="form-check-input h4 mb-0 cursor-pointer toggle-bank" type="checkbox" role="switch"
                                            data-reference="<?php echo htmlspecialchars($bank['reference'] ?? ''); ?>"
                                            <?php echo (isset($bank['status']) && $bank['status'] == 1) ? 'checked' : ''; ?>>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.toggle-bank').forEach(toggle => {
            toggle.addEventListener('change', function() {
                const reference = this.getAttribute('data-reference');
                const status = this.checked ? 1 : 0;
                
                fetch('ajax-vendor-banks.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'toggle_bank_status=1&reference=' + encodeURIComponent(reference) + '&status=' + status
                })
                .then(response => response.json())
                .then(data => {
                    if(!data.success) {
                        alert('Failed to update bank status. Please try again.');
                        this.checked = !this.checked;
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert('Network error. Please try again.');
                    this.checked = !this.checked;
                });
            });
        });
    });
    </script>

    <?php include("../func/bc-spadmin-footer.php"); ?>
</body>
</html>

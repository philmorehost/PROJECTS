<?php session_start();
include("../func/bc-admin-config.php");

// Migration: Create table if not exists
mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_service_control (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vendor_id INT NOT NULL,
    service_name VARCHAR(50) NOT NULL,
    status TINYINT(1) DEFAULT 1,
    UNIQUE KEY vendor_service (vendor_id, service_name)
)");

// Migration: Add status column to banks if not exists
@mysqli_query($connection_server, "ALTER TABLE sas_user_banks ADD COLUMN status TINYINT(1) DEFAULT 1");
@mysqli_query($connection_server, "ALTER TABLE sas_vendor_banks ADD COLUMN status TINYINT(1) DEFAULT 1");

$services = [
    'data' => 'Buy Data Bundle',
    'airtime' => 'Buy Airtime VTU',
    'cable' => 'Buy CableTv Sub',
    'electric' => 'Buy Electric Token',
    'betting' => 'Fund Betting',
    'exam' => 'Buy Exam PIN',
    'bulk_sms' => 'Bulk SMS',
    'data_card' => 'Print Hub (Master Switch)',
    'print_data' => 'Print Hub — Data Cards',
    'print_airtime' => 'Print Hub — Airtime Cards',
    'print_cable' => 'Print Hub — Cable Cards',
    'print_electric' => 'Print Hub — Electric Cards',
    'print_exam' => 'Print Hub — Exam Pin Cards',
    'print_betting' => 'Print Hub — Betting Cards',
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
    'ai_suite' => 'AI Business Suite',
    'vtu_coins' => 'VTU Coins & Loyalty Program'
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

if (isset($_POST['toggle_service'])) {
    $service_name = mysqli_real_escape_string($connection_server, $_POST['service_name']);
    $status = (int)$_POST['status'];
    $vid = $get_logged_admin_details['id'];

    mysqli_query($connection_server, "INSERT INTO sas_service_control (vendor_id, service_name, status)
        VALUES ('$vid', '$service_name', $status)
        ON DUPLICATE KEY UPDATE status=$status");

    $_SESSION["product_purchase_response"] = "Setting updated successfully.";
    header("Location: ServiceControl.php");
    exit();
}

// Bulk update user virtual banks by gateway
if (isset($_POST['bulk_update_banks'])) {
    $bulk_gw = mysqli_real_escape_string($connection_server, $_POST['bulk_gateway']);
    $bulk_status = (int)$_POST['bulk_status'];
    $vid = $get_logged_admin_details['id'];
    
    mysqli_query($connection_server, "UPDATE sas_user_banks SET status = $bulk_status WHERE vendor_id='$vid' AND gateway_name='$bulk_gw'");
    
    $_SESSION["product_purchase_response"] = "Bulk update applied successfully to all " . strtoupper($bulk_gw) . " accounts.";
    header("Location: ServiceControl.php");
    exit();
}

// Fetch current settings
$settings = [];
$vid = $get_logged_admin_details['id'];
$q = mysqli_query($connection_server, "SELECT service_name, status FROM sas_service_control WHERE vendor_id='$vid'");
while($r = mysqli_fetch_assoc($q)) $settings[$r['service_name']] = $r['status'];

// Fetch global settings
$global_settings = [];
$qg = mysqli_query($connection_server, "SELECT service_name, status FROM sas_global_service_control");
while($rg = mysqli_fetch_assoc($qg)) $global_settings[$rg['service_name']] = $rg['status'];

// Fetch virtual accounts for management
$user_banks = [];
$qub = mysqli_query($connection_server, "SELECT * FROM sas_user_banks WHERE vendor_id='$vid' ORDER BY username ASC");
while($rub = mysqli_fetch_assoc($qub)) $user_banks[] = $rub;

$vendor_banks = [];
$qvb = mysqli_query($connection_server, "SELECT * FROM sas_vendor_banks WHERE vendor_id='$vid'");
while($rvb = mysqli_fetch_assoc($qvb)) $vendor_banks[] = $rvb;

$available_gateways = ['monnify', 'payvessel', 'payhub', 'paystack', 'beewave'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Service Control Center | <?php echo $get_all_super_admin_site_details["site_title"]; ?></title>
    <?php include("../func/bc-admin-header-link.php"); ?>
    <style>
        .restricted-badge {
            background-color: #f8d7da;
            color: #721c24;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: bold;
        }
        .bank-item {
            transition: all 0.3s ease;
        }
        .bank-item:hover {
            background-color: #f8f9fa;
        }
    </style>
</head>
<body>
    <?php include("../func/bc-admin-header.php"); ?>

    <div class="pagetitle">
      <h1>SERVICE CONTROL CENTER</h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="#">Home</a></li>
          <li class="breadcrumb-item active">Service Control</li>
        </ol>
      </nav>
    </div>

    <section class="section dashboard">
        <div class="row">
            <div class="col-lg-6">
                <div class="card shadow-sm border-0 rounded-4">
                    <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="fw-bold mb-0 text-primary"><i class="bi bi-cpu me-2"></i>Service Visibility</h5>
                            <p class="small text-muted mb-0">Enable or disable services across the platform.</p>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush">
                            <?php foreach($services as $key => $label):
                                $status = isset($settings[$key]) ? $settings[$key] : 1;
                                $globally_enabled = !isset($global_settings[$key]) || $global_settings[$key] == 1;
                            ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center py-3 px-4">
                                <div>
                                    <h6 class="mb-0 fw-bold"><?php echo $label; ?> 
                                        <?php if(!$globally_enabled): ?><span class="badge bg-secondary bg-opacity-10 text-secondary ms-2" style="font-size: 0.65rem; border: 1px solid rgba(108,117,125,0.2);">Default: OFF</span><?php endif; ?>
                                    </h6>
                                    <span class="small text-muted">ID: <?php echo $key; ?></span>
                                </div>
                                <form method="post" action="">
                                    <input type="hidden" name="service_name" value="<?php echo $key; ?>">
                                    <input type="hidden" name="status" value="<?php echo $status ? 0 : 1; ?>">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input h4 mb-0 cursor-pointer" type="checkbox" role="switch"
                                            <?php echo $status ? 'checked' : ''; ?>
                                            onchange="this.form.submit()">
                                        <input type="hidden" name="toggle_service" value="1">
                                    </div>
                                </form>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card shadow-sm border-0 rounded-4">
                    <div class="card-header bg-white py-3 border-0">
                        <h5 class="fw-bold mb-0 text-primary"><i class="bi bi-credit-card-2-front me-2"></i>Payment Gateways</h5>
                        <p class="small text-muted mb-0">Control which payment methods are available to users.</p>
                    </div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush">
                            <?php foreach($gateways as $key => $label):
                                $status = isset($settings[$key]) ? $settings[$key] : 1;
                                $globally_enabled = !isset($global_settings[$key]) || $global_settings[$key] == 1;
                            ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center py-3 px-4">
                                <div>
                                    <h6 class="mb-0 fw-bold"><?php echo $label; ?> 
                                        <?php if(!$globally_enabled): ?><span class="badge bg-secondary bg-opacity-10 text-secondary ms-2" style="font-size: 0.65rem; border: 1px solid rgba(108,117,125,0.2);">Default: OFF</span><?php endif; ?>
                                    </h6>
                                    <span class="small text-muted">ID: <?php echo $key; ?></span>
                                </div>
                                <form method="post" action="">
                                    <input type="hidden" name="service_name" value="<?php echo $key; ?>">
                                    <input type="hidden" name="status" value="<?php echo $status ? 0 : 1; ?>">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input h4 mb-0 cursor-pointer" type="checkbox" role="switch"
                                            <?php echo $status ? 'checked' : ''; ?>
                                            onchange="this.form.submit()">
                                        <input type="hidden" name="toggle_service" value="1">
                                    </div>
                                </form>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Virtual Bank Accounts Management -->
                <div class="card shadow-sm border-0 rounded-4 mt-4">
                    <div class="card-header bg-white py-3 border-0">
                        <h5 class="fw-bold mb-0 text-primary"><i class="bi bi-bank me-2"></i>Manage Virtual Accounts</h5>
                        <p class="small text-muted mb-0">Toggle individual bank accounts for yourself and users.</p>
                    </div>
                    <div class="card-body">
                        <ul class="nav nav-tabs nav-tabs-bordered" id="bankTab" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="user-banks-tab" data-bs-toggle="tab" data-bs-target="#user-banks" type="button" role="tab">User Accounts</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="vendor-banks-tab" data-bs-toggle="tab" data-bs-target="#vendor-banks" type="button" role="tab">My Admin Accounts</button>
                            </li>
                        </ul>
                        <div class="tab-content pt-3" id="bankTabContent">
                            <div class="tab-pane fade show active" id="user-banks" role="tabpanel">
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
                                        <option value="0">Turn OFF</option>
                                        <option value="1">Turn ON</option>
                                    </select>
                                    <button type="submit" name="bulk_update_banks" class="btn btn-sm btn-dark">Apply to All Users</button>
                                </form>
                                <?php endif; ?>
                                <div style="max-height: 400px; overflow-y: auto;">
                                    <?php if(empty($user_banks)): ?>
                                        <p class="text-center text-muted py-4">No user virtual accounts found.</p>
                                    <?php else: ?>
                                        <?php foreach($user_banks as $bank): ?>
                                            <div class="bank-item d-flex justify-content-between align-items-center p-3 border-bottom">
                                                <div>
                                                    <h6 class="mb-0 fw-bold small text-uppercase"><?php echo $bank['bank_name']; ?> - <?php echo $bank['account_number']; ?></h6>
                                                    <p class="mb-0 x-small text-muted">User: <b><?php echo $bank['username']; ?></b> | Gateway: <span class="badge bg-light text-dark"><?php echo $bank['gateway_name'] ?: 'Unknown'; ?></span></p>
                                                </div>
                                                <div class="form-check form-switch">
                                                     <input class="form-check-input h5 mb-0 cursor-pointer" type="checkbox" 
                                                         <?php echo $bank['status'] ? 'checked' : ''; ?>
                                                         onchange="toggleBank('<?php echo htmlspecialchars($bank['reference']); ?>', 'user', this.checked ? 1 : 0)">
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="tab-pane fade" id="vendor-banks" role="tabpanel">
                                <?php if(empty($vendor_banks)): ?>
                                    <p class="text-center text-muted py-4">No admin virtual accounts found.</p>
                                <?php else: ?>
                                    <?php foreach($vendor_banks as $bank): ?>
                                        <div class="bank-item d-flex justify-content-between align-items-center p-3 border-bottom">
                                            <div>
                                                <h6 class="mb-0 fw-bold small text-uppercase"><?php echo $bank['bank_name']; ?> - <?php echo $bank['account_number']; ?></h6>
                                                <p class="mb-0 x-small text-muted">Gateway: <span class="badge bg-light text-dark"><?php echo $bank['gateway_name'] ?: 'Unknown'; ?></span></p>
                                            </div>
                                            <div class="form-check form-switch">
                                                 <input class="form-check-input h5 mb-0 cursor-pointer" type="checkbox" 
                                                     <?php echo $bank['status'] ? 'checked' : ''; ?>
                                                     onchange="toggleBank('<?php echo htmlspecialchars($bank['reference']); ?>', 'vendor', this.checked ? 1 : 0)">
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <script>
    function toggleBank(id, type, status) {
        const formData = new FormData();
        formData.append('toggle_bank_status', '1');
        formData.append('bank_id', id);
        formData.append('type', type);
        formData.append('status', status);

        fetch('ajax-virtual-banks.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                alert('Error updating bank status: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Failed to update bank status. Please try again.');
        });
    }
    </script>

    <?php include("../func/bc-admin-footer.php"); ?>
</body>
</html>

<?php session_start();
include("../func/bc-spadmin-config.php");
include("../func/bc-whatsapp.php");

$is_super = isset($get_logged_spadmin_details['id']);
if (!$is_super) { header("Location: /bc-spadmin/Login.php"); exit(); }

if (isset($_POST['save-config'])) {
    $provider = mysqli_real_escape_string($connection_server, $_POST['wa_provider'] ?? 'official');
    $official_token = mysqli_real_escape_string($connection_server, $_POST['wa_official_token'] ?? '');
    $official_phone_id = mysqli_real_escape_string($connection_server, $_POST['wa_official_phone_id'] ?? '');
    $official_biz_id = mysqli_real_escape_string($connection_server, $_POST['wa_official_biz_id'] ?? '');
    $sc_key = mysqli_real_escape_string($connection_server, $_POST['wa_sendchamp_key'] ?? '');
    $sc_sender = mysqli_real_escape_string($connection_server, $_POST['wa_sendchamp_sender'] ?? '');

    $fields = [
        'wa_provider' => $provider,
        'wa_official_token' => $official_token,
        'wa_official_phone_id' => $official_phone_id,
        'wa_official_biz_id' => $official_biz_id,
        'wa_sendchamp_key' => $sc_key,
        'wa_sendchamp_sender' => $sc_sender
    ];

    foreach ($fields as $name => $val) {
        $q = mysqli_query($connection_server, "SELECT id FROM sas_super_admin_options WHERE option_name='$name' LIMIT 1");
        $ex = ($q) ? mysqli_fetch_assoc($q) : null;
        if ($ex) mysqli_query($connection_server, "UPDATE sas_super_admin_options SET option_value='$val' WHERE option_name='$name'");
        else mysqli_query($connection_server, "INSERT INTO sas_super_admin_options (option_name, option_value) VALUES ('$name','$val')");
    }

    $_SESSION['product_purchase_response'] = 'Configuration successfully synchronized with Meta Cloud.';
    header("Location: WhatsAppAIManager.php"); exit();
}

if (isset($_POST['send-test-message'])) {
    $ph  = preg_replace('/[^0-9]/', '', trim($_POST['test-phone'] ?? ''));
    $msg = trim($_POST['test-message'] ?? '');
    $success = sendOfficialWhatsAppAlert($ph, $msg);
    $_SESSION['product_purchase_response'] = $success ? '✅ Test Message Dispatched Successfully!' : '❌ Meta API Error: Check credentials.';
    header("Location: WhatsAppAIManager.php"); exit();
}

$wa_provider = getSuperAdminOption('wa_provider', 'official');
$off_token = getSuperAdminOption('wa_official_token', '');
$off_phone_id = getSuperAdminOption('wa_official_phone_id', '');
$off_biz_id = getSuperAdminOption('wa_official_biz_id', '');
$sc_key = getSuperAdminOption('wa_sendchamp_key', '');
$sc_sender = getSuperAdminOption('wa_sendchamp_sender', '');

$is_configured = ($wa_provider === 'official') ? (!empty($off_token) && !empty($off_phone_id)) : (!empty($sc_key) && !empty($sc_sender));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>WhatsApp Messaging Center | Super Admin</title>
    <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
    <style>
        :root { --meta-blue: #0668E1; --wa-green: #25D366; }
        .premium-card { border: none; border-radius: 1.25rem; box-shadow: 0 5px 20px rgba(0,0,0,0.05); }
        .config-banner {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            color: white; border-radius: 1.25rem; padding: 2rem; margin-bottom: 1.5rem;
            display: flex; align-items: center; justify-content: space-between;
        }
        .status-pill {
            background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2);
            padding: 8px 16px; border-radius: 50px; font-weight: 700; font-size: 0.7rem;
        }
        .input-box {
            background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 0.75rem;
            padding: 12px 16px; transition: all 0.2s;
        }
        .input-box:focus-within { background: white; border-color: var(--meta-blue); box-shadow: 0 0 0 4px rgba(6,104,225,0.1); }
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
                <h1 class="h3 fw-bold mb-1">WhatsApp Messaging Center</h1>
                <p class="text-muted small">Configure and monitor your Official Meta Cloud API infrastructure.</p>
            </div>
            <a href="WhatsAppPricing.php" class="btn btn-outline-success rounded-pill px-4 btn-sm fw-bold"><i class="bi bi-tags-fill me-2"></i>Pricing Settings</a>
        </div>
    </div>

<section class="section">
    <div class="config-banner shadow-sm">
        <div>
            <h4 class="fw-bold mb-1">WhatsApp Cloud Gateway</h4>
            <p class="mb-0 opacity-75 small">API Version: v18.0 | Status: <?php echo $is_configured ? 'Operational' : 'Awaiting Config'; ?></p>
        </div>
        <div class="status-pill <?php echo $is_configured ? 'text-success' : 'text-warning'; ?>">
            <i class="bi bi-circle-fill me-2" style="font-size: 8px;"></i>
            <?php echo $is_configured ? 'API CONNECTED' : 'NOT CONFIGURED'; ?>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card premium-card h-100">
                <div class="card-body p-4">
                    <form method="post">
                        <h6 class="fw-bold mb-4 text-dark"><i class="bi bi-gear-fill me-2 text-primary"></i>Gateway Settings</h6>

                        <div class="mb-4">
                            <label class="small fw-bold text-muted mb-2">WHATSAPP PROVIDER</label>
                            <select name="wa_provider" class="form-select input-box" onchange="toggleProvider(this.value)">
                                <option value="official" <?php echo $wa_provider == 'official' ? 'selected' : ''; ?>>Meta Cloud API (Official)</option>
                                <option value="sendchamp" <?php echo $wa_provider == 'sendchamp' ? 'selected' : ''; ?>>Sendchamp API (Unofficial)</option>
                            </select>
                        </div>

                        <!-- Meta Official Fields -->
                        <div id="meta-fields" style="<?php echo $wa_provider == 'official' ? '' : 'display:none'; ?>">
                            <h6 class="small fw-bold text-primary mb-3 mt-4">META CLOUD CREDENTIALS</h6>
                            <div class="mb-4">
                                <label class="small fw-bold text-muted mb-2">PERMANENT ACCESS TOKEN</label>
                                <div class="input-box d-flex align-items-center">
                                    <i class="bi bi-key-fill"></i>
                                    <input name="wa_official_token" type="password" value="<?php echo htmlspecialchars($off_token); ?>" placeholder="Enter Token...">
                                </div>
                            </div>
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label class="small fw-bold text-muted mb-2">PHONE NUMBER ID</label>
                                    <div class="input-box d-flex align-items-center">
                                        <i class="bi bi-phone-vibrate-fill"></i>
                                        <input name="wa_official_phone_id" type="text" value="<?php echo htmlspecialchars($off_phone_id); ?>" placeholder="10293...">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="small fw-bold text-muted mb-2">BUSINESS ID</label>
                                    <div class="input-box d-flex align-items-center">
                                        <i class="bi bi-briefcase-fill"></i>
                                        <input name="wa_official_biz_id" type="text" value="<?php echo htmlspecialchars($off_biz_id); ?>" placeholder="7823...">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Sendchamp Fields -->
                        <div id="sendchamp-fields" style="<?php echo $wa_provider == 'sendchamp' ? '' : 'display:none'; ?>">
                            <h6 class="small fw-bold text-success mb-3 mt-4">SENDCHAMP API CREDENTIALS</h6>
                            <div class="mb-4">
                                <label class="small fw-bold text-muted mb-2">SENDCHAMP ACCESS KEY</label>
                                <div class="input-box d-flex align-items-center">
                                    <i class="bi bi-shield-lock-fill"></i>
                                    <input name="wa_sendchamp_key" type="password" value="<?php echo htmlspecialchars($sc_key); ?>" placeholder="send_secret_...">
                                </div>
                            </div>
                            <div class="mb-4">
                                <label class="small fw-bold text-muted mb-2">WHATSAPP SENDER ID / NUMBER</label>
                                <div class="input-box d-flex align-items-center">
                                    <i class="bi bi-whatsapp"></i>
                                    <input name="wa_sendchamp_sender" type="text" value="<?php echo htmlspecialchars($sc_sender); ?>" placeholder="e.g. 2348120678278">
                                </div>
                            </div>
                        </div>

                        <button name="save-config" type="submit" class="btn-action w-100 bg-primary text-white shadow-sm mt-4">
                            <i class="bi bi-cloud-check-fill"></i> Save Configuration
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card premium-card">
                <div class="card-body p-4 text-center">
                    <div class="bg-success bg-opacity-10 p-3 rounded-circle d-inline-block mb-3">
                        <i class="bi bi-send-check-fill text-success h4 mb-0"></i>
                    </div>
                    <h6 class="fw-bold text-dark">Gateway Connectivity Test</h6>
                    <p class="text-muted small mb-4">Verify your credentials by sending a live API request.</p>
                    <form method="post">
                        <div class="input-box d-flex align-items-center mb-3">
                            <i class="bi bi-phone-fill"></i>
                            <input name="test-phone" type="text" placeholder="Recipient: 23480..." required>
                        </div>
                        <div class="input-box mb-4">
                            <textarea name="test-message" class="w-100 border-0 bg-transparent small" rows="2" style="outline:none; resize:none;">Meta Official API Connection Test Successful.</textarea>
                        </div>
                        <button name="send-test-message" type="submit" class="btn-action w-100 bg-dark text-white shadow-sm" <?php echo !$is_configured?'disabled':''; ?>>
                            <i class="bi bi-whatsapp"></i> Dispatch Test
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>

<?php include("../func/bc-spadmin-footer.php"); ?>
<script>
function toggleProvider(val) {
    document.getElementById('meta-fields').style.display = val === 'official' ? 'block' : 'none';
    document.getElementById('sendchamp-fields').style.display = val === 'sendchamp' ? 'block' : 'none';
}
</script>
<?php if(isset($_SESSION["product_purchase_response"])): ?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script>Swal.fire({ icon: 'info', title: 'Meta Gateway', text: '<?php echo addslashes($_SESSION["product_purchase_response"]); ?>', borderRadius: '1rem' });</script>
<?php unset($_SESSION["product_purchase_response"]); endif; ?>
</body>
</html>

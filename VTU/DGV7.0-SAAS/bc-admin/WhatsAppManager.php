<?php session_start();
include("../func/bc-admin-config.php");
include("../func/bc-whatsapp.php");
require_once("../func/bc-giftcard-func.php");

$vid = $get_logged_admin_details['id'];
$site_q = mysqli_query($connection_server, "SELECT site_title FROM sas_site_details WHERE vendor_id='$vid' LIMIT 1");
$site_data = mysqli_fetch_assoc($site_q);
$biz_name = $get_logged_admin_details['company_name'] ?? ($site_data['site_title'] ?? $_SERVER['HTTP_HOST']);

$wa_enabled = getVendorOption($vid, 'whatsapp_notifications', '0');
$is_configured = isWhatsAppGatewayOnline();

// --- Handle Toggle WhatsApp Alerts ---
if (isset($_POST['toggle-wa'])) {
    setVendorOption($vid, 'whatsapp_notifications', $_POST['toggle-wa'] == '1' ? '1' : '0');
    header("Location: WhatsAppManager.php"); exit();
}

// --- Ensure Schema Support for Media ---
@mysqli_query($connection_server, "ALTER TABLE sas_wa_templates ADD COLUMN header_type VARCHAR(20) DEFAULT 'TEXT'");
@mysqli_query($connection_server, "ALTER TABLE sas_wa_templates ADD COLUMN media_url TEXT NULL");

// --- Handle New Template Submission ---
if (isset($_POST['submit-template'])) {
    $t_name = "vendor_{$vid}_" . preg_replace('/[^a-z0-9]/', '', strtolower($_POST['template_name']));
    $t_cat = $_POST['template_category'];
    $t_body = $_POST['template_body'];
    $h_type = $_POST['header_type'] ?? 'TEXT';
    
    $media_id = null;
    $media_url = null;

    if ($h_type === 'IMAGE' && isset($_FILES['template_image']) && $_FILES['template_image']['error'] == 0) {
        $ext = strtolower(pathinfo($_FILES['template_image']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png'])) {
            if ($_FILES['template_image']['size'] <= 5 * 1024 * 1024) { // 5MB limit
                $upload_dir = '../uploaded-image/vendor_' . $vid . '/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                
                $filename = 'wa_tmpl_' . time() . '.' . $ext;
                $target = $upload_dir . $filename;
                
                if (move_uploaded_file($_FILES['template_image']['tmp_name'], $target)) {
                    $media_url = "https://" . $_SERVER['HTTP_HOST'] . "/uploaded-image/vendor_{$vid}/{$filename}";
                    $media_id = uploadMediaToMetaWhatsApp($target);
                    if (!$media_id) {
                        $_SESSION['error_msg'] = "Meta API Error: Failed to upload image to WhatsApp servers.";
                        header("Location: WhatsAppManager.php"); exit();
                    }
                }
            } else {
                $_SESSION['error_msg'] = "Error: Image must be under 5MB.";
                header("Location: WhatsAppManager.php"); exit();
            }
        } else {
            $_SESSION['error_msg'] = "Error: Only JPG/PNG allowed.";
            header("Location: WhatsAppManager.php"); exit();
        }
    }

    // Send to Meta API
    $api_res = createOfficialWhatsAppTemplate($t_name, $t_cat, $t_body, 'en_US', $media_id);
    
    if ($api_res['status']) {
        $meta_id = $api_res['id'];
        $clean_body = mysqli_real_escape_string($connection_server, $t_body);
        $clean_url = $media_url ? "'" . mysqli_real_escape_string($connection_server, $media_url) . "'" : "NULL";
        mysqli_query($connection_server, "INSERT INTO sas_wa_templates (vendor_id, template_name, category, body_text, status, meta_template_id, header_type, media_url) 
            VALUES ('$vid', '$t_name', '$t_cat', '$clean_body', 'PENDING', '$meta_id', '$h_type', $clean_url)");
        $_SESSION['success_msg'] = "Template submitted successfully to Meta! It usually takes 2-5 minutes to be approved.";
    } else {
        $_SESSION['error_msg'] = "Meta API Error: " . $api_res['message'];
    }
    header("Location: WhatsAppManager.php"); exit();
}

// --- Calculate Live Pricing ---
$live_rate = getLiveUSDToNGNRate(0); 
$marketing_usd = getSuperAdminOption('wa_marketing_base_usd', '0.022');
$profit_margin = getSuperAdminOption('wa_profit_margin_percent', '15');
$markup_multiplier = 1 + ($profit_margin / 100);
$marketing_ngn = round($marketing_usd * $live_rate * $markup_multiplier, 2);

// --- Fetch Vendor's Templates ---
$templates_q = mysqli_query($connection_server, "SELECT * FROM sas_wa_templates WHERE vendor_id='$vid' ORDER BY id DESC");
$approved_templates = [];
$pending_templates = [];
while ($t = mysqli_fetch_assoc($templates_q)) {
    // Optional: Auto-sync PENDING status
    if ($t['status'] === 'PENDING') {
        $status_check = checkTemplateStatus($t['template_name']);
        if ($status_check && $status_check !== 'PENDING') {
            mysqli_query($connection_server, "UPDATE sas_wa_templates SET status='$status_check' WHERE id='{$t['id']}'");
            $t['status'] = $status_check;
        }
    }
    if ($t['status'] === 'APPROVED') $approved_templates[] = $t;
    else $pending_templates[] = $t;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>WhatsApp Marketing | Vendor Panel</title>
    <meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
    <style>
        .select-scroll { height: 250px; overflow-y: auto; background: #fff; border: 1px solid #dee2e6; border-radius: 0.5rem; padding: 10px; }
        .user-checkbox-item { padding: 8px; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; gap: 10px; transition: 0.2s; }
        .user-checkbox-item:hover { background: #f8fafc; }
        .user-checkbox-item label { flex-grow: 1; cursor: pointer; margin: 0; font-size: 0.85rem; }
        .badge-wa { background: linear-gradient(135deg, #25d366 0%, #128c7e 100%); color: white; }
    </style>
</head>
<body>
<?php include("../func/bc-admin-header.php"); ?>
<div class="pagetitle d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 fw-bold mb-1">WhatsApp Official Notifications</h1>
        <p class="text-muted small">Send highly converted alerts via Official Cloud API</p>
    </div>
    <div class="text-end">
        <span class="badge bg-light text-dark shadow-sm px-3 py-2 border"><i class="bi bi-wallet2 text-success me-2"></i>Wallet: ₦<?php echo number_format($get_logged_admin_details['balance'], 2); ?></span>
    </div>
</div>

<?php if(isset($_SESSION['success_msg'])): ?>
    <div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i><?php echo $_SESSION['success_msg']; unset($_SESSION['success_msg']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if(isset($_SESSION['error_msg'])): ?>
    <div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-octagon me-2"></i><?php echo $_SESSION['error_msg']; unset($_SESSION['error_msg']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<section class="section">
    <div class="row g-4">
        <!-- LEFT COLUMN: PRICING & TEMPLATE SUBMISSION -->
        <div class="col-lg-4">
            <!-- Pricing Panel -->
            <div class="card border-0 rounded-4 shadow-sm mb-4">
                <div class="card-body p-4 bg-light rounded-4">
                    <h6 class="fw-bold mb-3"><i class="bi bi-tag text-primary me-2"></i>Live Broadcasting Cost</h6>
                    <div class="d-flex justify-content-between align-items-center bg-white p-3 rounded-3 shadow-sm border-0">
                        <div>
                            <span class="d-block small text-muted">Marketing Rate (Live)</span>
                            <h5 class="fw-bold text-success mb-0">₦<?php echo number_format($marketing_ngn, 2); ?> <span class="small fw-normal text-muted">/ message</span></h5>
                        </div>
                        <i class="bi bi-broadcast fs-3 text-success opacity-50"></i>
                    </div>
                    <p class="small text-muted mt-3 mb-0"><i class="bi bi-info-circle me-1"></i>Cost is automatically deducted from your vendor wallet upon dispatch.</p>
                </div>
            </div>

            <!-- Submit Template -->
            <div class="card border-0 rounded-4 shadow-sm mb-4">
                <div class="card-header bg-white border-0 py-3">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-plus-square text-primary me-2"></i>New Message Template</h6>
                </div>
                <div class="card-body p-4 pt-0">
                    <form method="post" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Template Name</label>
                            <input type="text" name="template_name" class="form-control" placeholder="e.g. weekend_promo" required pattern="[a-z0-9_]+" title="Only lowercase letters, numbers, and underscores allowed">
                            <small class="text-muted" style="font-size:11px;">No spaces, lowercase only.</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Category</label>
                            <select name="template_category" class="form-select" required>
                                <option value="MARKETING">Marketing (Promotions/Updates)</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Header Type</label>
                            <select name="header_type" id="header_type" class="form-select" onchange="toggleImageUpload()">
                                <option value="TEXT">Text Only</option>
                                <option value="IMAGE">Image Header (Marketing)</option>
                            </select>
                        </div>
                        <div class="mb-3 d-none" id="image_upload_container">
                            <label class="form-label small fw-bold"><i class="bi bi-image text-success me-1"></i>Template Image (Max 5MB)</label>
                            <input type="file" name="template_image" class="form-control" accept=".jpg,.jpeg,.png">
                            <small class="text-muted" style="font-size:11px;">An image banner to grab users' attention.</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Message Body</label>
                            <textarea name="template_body" class="form-control" rows="4" placeholder="Hello {{1}}! We have a special discount for you..." required></textarea>
                            <small class="text-muted" style="font-size:11px;">To insert names later, use {{1}}, {{2}} as variables.</small>
                        </div>
                        <button type="submit" name="submit-template" class="btn btn-dark w-100 rounded-pill"><i class="bi bi-cloud-upload me-2"></i>Submit for Approval</button>
                    </form>
                </div>
            </div>
            
            <script>
                function toggleImageUpload() {
                    const hType = document.getElementById('header_type').value;
                    const container = document.getElementById('image_upload_container');
                    if (hType === 'IMAGE') {
                        container.classList.remove('d-none');
                        container.querySelector('input').required = true;
                    } else {
                        container.classList.add('d-none');
                        container.querySelector('input').required = false;
                    }
                }
            </script>

            <!-- Template Status -->
            <div class="card border-0 rounded-4 shadow-sm">
                <div class="card-header bg-white border-0 py-3">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-list-check text-primary me-2"></i>My Templates</h6>
                </div>
                <div class="card-body p-0">
                    <ul class="list-group list-group-flush rounded-bottom-4">
                        <?php if (empty($approved_templates) && empty($pending_templates)) echo "<li class='list-group-item small text-muted text-center py-3'>No templates found</li>"; ?>
                        <?php foreach($pending_templates as $t): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span class="small text-truncate" style="max-width: 150px;" title="<?php echo htmlspecialchars($t['template_name']); ?>"><?php echo htmlspecialchars($t['template_name']); ?></span>
                                <span class="badge bg-warning text-dark px-2 py-1 rounded-pill" style="font-size: 10px;">PENDING</span>
                            </li>
                        <?php endforeach; ?>
                        <?php foreach($approved_templates as $t): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span class="small text-truncate" style="max-width: 150px;" title="<?php echo htmlspecialchars($t['template_name']); ?>">
                                    <?php if(isset($t['header_type']) && $t['header_type'] === 'IMAGE'): ?><i class="bi bi-image text-success me-1" title="Image Template"></i><?php endif; ?>
                                    <?php echo htmlspecialchars($t['template_name']); ?>
                                </span>
                                <span class="badge bg-success px-2 py-1 rounded-pill" style="font-size: 10px;">APPROVED</span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>

        <!-- RIGHT COLUMN: BROADCAST ENGINE -->
        <div class="col-lg-8">
            <div class="card border-0 rounded-4 shadow-sm">
                <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-megaphone me-2 text-primary"></i>Broadcast Dispatcher</h6>
                    <span class="badge bg-primary-subtle text-primary rounded-pill px-3 small">Limit: 200/batch</span>
                </div>
                <div class="card-body p-4 pt-0">
                    <form id="broadcast-form" onsubmit="sendBroadcast(event)">
                        <!-- Checkbox User List -->
                        <div class="mb-4">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="form-label small fw-bold text-dark mb-0">1. Select Recipients</label>
                                <div>
                                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" onclick="selectAll(false)">Clear</button>
                                    <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-bold" onclick="selectAllMax200()">Select Max 200</button>
                                </div>
                            </div>
                            <div class="select-scroll" id="checkbox-container">
                                <?php
                                $uq = mysqli_query($connection_server, "SELECT id, firstname, lastname, phone_number, username FROM sas_users WHERE vendor_id='$vid' OR vendor_id=".intval($vid)." ORDER BY id DESC LIMIT 500");
                                if (mysqli_num_rows($uq) == 0) {
                                    echo '<div class="text-center p-3 small text-muted">No users found.</div>';
                                }
                                $user_count = 0;
                                while ($u = mysqli_fetch_assoc($uq)) {
                                    $p = preg_replace('/[^0-9]/', '', $u['phone_number']);
                                    if (strlen($p) === 11 && $p[0] === '0') $p = '234' . substr($p, 1);
                                    
                                    $fn = htmlspecialchars(trim($u['firstname'] . ' ' . $u['lastname']));
                                    if ($p) {
                                        $user_count++;
                                        echo "<div class='user-checkbox-item'>
                                                <input class='form-check-input user-check' type='checkbox' value='$p' id='u_$user_count'>
                                                <label for='u_$user_count' class='text-truncate'>{$fn} (@".htmlspecialchars($u['username']).") <span class='text-muted ms-1'>+$p</span></label>
                                              </div>";
                                    }
                                }
                                ?>
                            </div>
                            <div class="form-text small mt-1 d-flex justify-content-between">
                                <span id="selected-count">0 selected</span>
                                <span>Total users: <?php echo $user_count; ?></span>
                            </div>
                        </div>
                        
                        <!-- Template Selection -->
                        <div class="mb-4">
                            <label class="form-label small fw-bold text-dark">2. Select Approved Template</label>
                            <select id="broadcast-template" class="form-select rounded-3 p-3 bg-light border-0">
                                <option value="">-- Choose a template --</option>
                                <?php foreach($approved_templates as $t): ?>
                                    <option value="<?php echo htmlspecialchars($t['template_name']); ?>">
                                        <?php if(isset($t['header_type']) && $t['header_type'] === 'IMAGE') echo "🖼️ "; ?>
                                        <?php echo htmlspecialchars($t['template_name']); ?> (<?php echo htmlspecialchars(substr($t['body_text'], 0, 40)); ?>...)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text small text-warning mt-1"><i class="bi bi-exclamation-triangle"></i> Proactive messages MUST use pre-approved templates per Meta policy.</div>
                        </div>
                        
                        <!-- Estimated Cost -->
                        <div class="bg-light p-3 rounded-4 mb-4 d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="fw-bold mb-0 text-dark">Estimated Cost</h6>
                                <span class="small text-muted" id="calc-breakdown">0 users × ₦<?php echo number_format($marketing_ngn, 2); ?></span>
                            </div>
                            <h4 class="fw-bold text-success mb-0" id="total-cost">₦0.00</h4>
                        </div>
                        
                        <div class="d-flex justify-content-between align-items-center">
                            <div id="broadcast-status" class="small fw-bold"></div>
                            <button type="submit" id="btn-broadcast" class="btn btn-dark rounded-pill px-5 fw-bold shadow-sm" <?php echo !$is_configured?'disabled':''; ?>>
                                <i class="bi bi-send-fill me-2"></i>Dispatch Campaign
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>

<?php include("../func/bc-admin-footer.php"); ?>

<script>
    const ratePerMessage = <?php echo $marketing_ngn; ?>;
    
    // Update counter and estimated cost on checkbox change
    document.getElementById('checkbox-container').addEventListener('change', updateCalculator);

    function updateCalculator() {
        const checkedBoxes = document.querySelectorAll('.user-check:checked');
        const count = checkedBoxes.length;
        document.getElementById('selected-count').innerText = `${count} selected`;
        
        const total = count * ratePerMessage;
        document.getElementById('calc-breakdown').innerText = `${count} users × ₦${ratePerMessage.toFixed(2)}`;
        document.getElementById('total-cost').innerText = `₦${total.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
    }

    function selectAll(forceCheck = false) {
        const boxes = document.querySelectorAll('.user-check');
        boxes.forEach(box => box.checked = forceCheck);
        updateCalculator();
    }

    function selectAllMax200() {
        selectAll(false); // clear first
        const boxes = document.querySelectorAll('.user-check');
        let count = 0;
        boxes.forEach(box => {
            if (count < 200) {
                box.checked = true;
                count++;
            }
        });
        updateCalculator();
        if(count > 0) {
            Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: `${count} users selected`, showConfirmButton: false, timer: 2000 });
        }
    }

    function sendBroadcast(e) {
        e.preventDefault();
        const checkedBoxes = Array.from(document.querySelectorAll('.user-check:checked'));
        const templateName = document.getElementById('broadcast-template').value;
        const status = document.getElementById('broadcast-status');
        const btn = document.getElementById('btn-broadcast');

        if (checkedBoxes.length === 0) {
            Swal.fire('Error', 'Please select at least one recipient.', 'error');
            return;
        }
        if (checkedBoxes.length > 200) {
            Swal.fire('Limit Reached', 'Maximum 200 recipients allowed per batch.', 'warning');
            return;
        }
        if (!templateName) {
            Swal.fire('Error', 'Please select an approved template.', 'error');
            return;
        }

        const phones = checkedBoxes.map(box => box.value);
        
        Swal.fire({
            title: 'Confirm Dispatch',
            text: `You are about to send a campaign to ${phones.length} users. Cost: ₦${(phones.length * ratePerMessage).toFixed(2)}. This will be deducted from your wallet.`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, Send it!',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Dispatching...';
                status.innerHTML = '<span class="text-primary"><i class="bi bi-hourglass-split me-1"></i>Starting...</span>';

                fetch('ajax-wa-broadcast.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ phones, template_name: templateName })
                })
                .then(res => res.json())
                .then(data => {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-send-fill me-2"></i>Dispatch Campaign';
                    if (data.success) {
                        Swal.fire('Success', `Broadcast complete! ${data.sent} sent, ${data.failed} failed. Cost deducted: ₦${data.cost}`, 'success')
                        .then(() => window.location.reload());
                    } else {
                        Swal.fire('Failed', data.error || 'Broadcast failed', 'error');
                        status.innerHTML = `<span class="text-danger">❌ Error</span>`;
                    }
                })
                .catch(err => {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-send-fill me-2"></i>Dispatch Campaign';
                    status.innerHTML = `<span class="text-danger">❌ Connection error: ${err.message}</span>`;
                });

            }
        });
    }
</script>
</body>
</html>

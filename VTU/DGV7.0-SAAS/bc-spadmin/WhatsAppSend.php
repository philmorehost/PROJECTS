<?php session_start();
include("../func/bc-admin-config.php");
include("../func/bc-whatsapp.php");

$is_super = (isset($get_logged_admin_details['id']) && $get_logged_admin_details['id'] == 1);
if (!$is_super) { header("Location: /bc-spadmin/Login.php"); exit(); }

if (isset($_POST['send-message'])) {
    $ph_input  = $_POST['phone'] ?? '';
    $phones = [];
    
    // Check if it's a broadcast or single
    if (!empty($_POST['recipients'])) {
        $phones = $_POST['recipients'];
    } elseif (!empty($ph_input)) {
        $phones = [preg_replace('/[^0-9]/', '', $ph_input)];
    }
    
    $msg = trim($_POST['message'] ?? '');
    $mode = $_POST['mode'] ?? 'unofficial';
    
    if (empty($phones)) {
        $_SESSION['product_purchase_response'] = '❌ No recipients selected.';
    } else {
        // Enforce 200 limit for Super Admin too
        $phones = array_slice($phones, 0, 200);
        $results = sendWhatsAppBulk($phones, $msg);
        $_SESSION['product_purchase_response'] = "✅ Broadcast Finished: {$results['sent']} sent, {$results['failed']} failed.";
    }
    
    header("Location: WhatsAppSend.php"); exit();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Send WhatsApp | Super Admin</title>
    <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
    <style>
        .select-scroll { height: 180px; border-radius: 12px; }
    </style>
</head>
<body>
<?php include("../func/bc-spadmin-header.php"); ?>

<div class="pagetitle">
    <h1>WhatsApp Messaging Center</h1>
    <nav><ol class="breadcrumb"><li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li><li class="breadcrumb-item active">Send WhatsApp</li></ol></nav>
</div>

<section class="section">
    <div class="row justify-content-center">
        <div class="col-lg-7">
            <div class="card shadow-sm border-0 rounded-4">
                <div class="card-header bg-white border-0 py-3 text-center">
                    <h5 class="mb-0 fw-bold"><i class="bi bi-send me-2 text-primary"></i>Compose Broadcast</h5>
                </div>
                <div class="card-body p-4">
                    <form method="post">
                        <div class="row g-4 mb-4">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">1. Select Recipients (Max 200)</label>
                                <select name="recipients[]" class="form-select select-scroll" multiple>
                                    <optgroup label="Recent Platform Users">
                                        <?php
                                        $uq = mysqli_query($connection_server, "SELECT full_name, phone FROM sas_users ORDER BY id DESC LIMIT 500");
                                        while ($u = mysqli_fetch_assoc($uq)) {
                                            $p = preg_replace('/[^0-9]/', '', $u['phone']);
                                            if ($p) echo "<option value='$p'>".htmlspecialchars($u['full_name'])." (+$p)</option>";
                                        }
                                        ?>
                                    </optgroup>
                                </select>
                                <div class="form-text small mt-2">Hold Ctrl/Cmd to select multiple.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">OR Manual Number</label>
                                <input name="phone" type="text" class="form-control rounded-3" placeholder="e.g. 08012345678">
                                
                                <div class="mt-4">
                                    <label class="form-label small fw-bold">2. Gateway Strategy</label>
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="radio" name="mode" id="mode-unoff" value="unofficial" checked>
                                        <label class="form-check-label small" for="mode-unoff">Unofficial Bridge (Small batches)</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="mode" id="mode-off" value="official">
                                        <label class="form-check-label small" for="mode-off">Official API (High Volume)</label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label small fw-bold">3. Message Content</label>
                            <textarea name="message" rows="5" class="form-control rounded-4 p-3" placeholder="Type your message here..." required></textarea>
                        </div>

                        <button name="send-message" type="submit" class="btn btn-primary w-100 rounded-pill py-2 fw-bold shadow-sm">
                            <i class="bi bi-whatsapp me-2"></i>Dispatch Broadcast
                        </button>
                    </form>
                </div>
            </div>
            
            <div class="text-center mt-4">
                <a href="WhatsAppAIManager.php" class="text-decoration-none small text-muted"><i class="bi bi-gear me-1"></i>Configure Gateway Settings</a>
            </div>
        </div>
    </div>
</section>

<?php include("../func/bc-spadmin-footer.php"); ?>
<?php if(isset($_SESSION["product_purchase_response"])): ?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script>Swal.fire('Notification','<?php echo addslashes($_SESSION["product_purchase_response"]); ?>','info');</script>
<?php unset($_SESSION["product_purchase_response"]); endif; ?>

</body>
</html>

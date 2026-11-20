<?php session_start();
include("../func/bc-config.php");
include_once(__DIR__ . "/../func/bc-fintech-services.php");

if (!isset($_SESSION["user_session"]) || !isset($get_logged_user_details)) {
    header("Location: Login.php");
    exit();
}
$vid = (int)$get_logged_user_details['vendor_id'];
$svc_user = $get_logged_user_details;

if(!isServiceEnabled('pos_request')){
    header("Location: Dashboard.php");
    exit();
}
$notice = ''; $notice_type = 'info';
if (isset($_POST['request_pos'])) {
    $payload = [
        'business_name' => trim(strip_tags($_POST['business_name'] ?? '')),
        'contact_phone' => trim(strip_tags($_POST['contact_phone'] ?? '')),
        'quantity' => max(1, (int)($_POST['quantity'] ?? 1)),
        'delivery_address' => trim(strip_tags($_POST['delivery_address'] ?? '')),
        'device_type' => trim(strip_tags($_POST['device_type'] ?? 'android')),
    ];
    if ($payload['business_name'] === '' || $payload['delivery_address'] === '') {
        $notice = 'Business name and delivery address are required.';
        $notice_type = 'danger';
    } else {
        $ref = bc_svc_request_create($vid, $svc_user, 'pos', 'flutterwave', $payload);
        $notice = $ref !== '' ? "POS request received ($ref). Our team will verify your business and arrange delivery." : 'We could not record your request. Please try again.';
        $notice_type = $ref !== '' ? 'success' : 'danger';
    }
}
$my_requests = bc_svc_requests_for_user($vid, (int)$svc_user['id'], 'pos');
include_once(__DIR__ . "/../func/bc-plate-app.php");
bc_app_head("Request POS Device | " . ($get_all_site_details["site_title"] ?? 'Platform'));
bc_app_sidebar();
bc_app_topbar(array("title" => "REQUEST POS DEVICE", "crumb" => "Home / POS"));
?>
<section class="section dashboard">
    <div class="card border-0 shadow-sm rounded-4 mb-4"><div class="card-body p-4">
        <span class="badge bg-primary mb-2">POS Terminals</span>
        <h4 class="fw-bold mb-1">Point of Sale (POS) device requests</h4>
        <p class="text-muted small mb-0">Accept card and transfer payments in-store. Tell us about your business and we will provision a POS terminal for you.</p>
    </div></div>
    <div class="card border-0 shadow-sm rounded-4"><div class="card-body p-4">
        <h6 class="fw-bold mb-3">Request a POS device</h6>
        <?php if ($notice !== ''): ?><div class="alert alert-<?php echo $notice_type; ?> rounded-3 small"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>
        <form method="post">
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label small fw-bold">Business name</label><input type="text" name="business_name" class="form-control" required></div>
                <div class="col-md-6"><label class="form-label small fw-bold">Contact phone</label><input type="text" name="contact_phone" class="form-control"></div>
                <div class="col-md-4"><label class="form-label small fw-bold">Device type</label>
                    <select name="device_type" class="form-select"><option value="android">Android POS</option><option value="classic">Classic POS</option><option value="mini">Mini POS</option></select></div>
                <div class="col-md-4"><label class="form-label small fw-bold">Quantity</label><input type="number" name="quantity" class="form-control" min="1" value="1"></div>
                <div class="col-md-12"><label class="form-label small fw-bold">Delivery address</label><textarea name="delivery_address" class="form-control" rows="2" required></textarea></div>
            </div>
            <button type="submit" name="request_pos" class="btn btn-primary rounded-pill px-4 fw-bold mt-4">Submit POS Request</button>
        </form>
        <?php if ($my_requests): ?><h6 class="fw-bold mt-4 mb-2">My POS requests</h6>
        <?php foreach ($my_requests as $r): ?><div class="d-flex justify-content-between border-bottom py-2"><span><strong><?php echo htmlspecialchars($r['reference']); ?></strong></span><span class="badge bg-secondary"><?php echo htmlspecialchars($r['status']); ?></span></div><?php endforeach; ?><?php endif; ?>
    </div></div>
</section>
<?php bc_app_foot(); ?>

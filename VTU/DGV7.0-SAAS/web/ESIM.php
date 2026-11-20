<?php session_start();
include("../func/bc-config.php");
include_once(__DIR__ . "/../func/bc-fintech-services.php");

if (!isset($_SESSION["user_session"]) || !isset($get_logged_user_details)) {
    header("Location: Login.php");
    exit();
}
$vid = (int)$get_logged_user_details['vendor_id'];
$svc_user = $get_logged_user_details;

if(!isServiceEnabled('esim')){
    header("Location: Dashboard.php");
    exit();
}
$plans = [
    ['name' => 'Global Starter', 'data' => '3 GB', 'days' => '15 days', 'price' => 7.50],
    ['name' => 'Global Traveller', 'data' => '10 GB', 'days' => '30 days', 'price' => 18.00],
    ['name' => 'Global Pro', 'data' => '25 GB', 'days' => '30 days', 'price' => 39.00],
];
$notice = ''; $notice_type = 'info';
if (isset($_POST['order_esim'])) {
    $payload = [
        'plan' => trim(strip_tags($_POST['plan'] ?? '')),
        'email' => trim(strip_tags($_POST['email'] ?? '')),
    ];
    if ($payload['plan'] === '' || !filter_var($payload['email'], FILTER_VALIDATE_EMAIL)) {
        $notice = 'Choose a plan and enter a valid email for QR delivery.';
        $notice_type = 'danger';
    } else {
        $ref = bc_svc_request_create($vid, $svc_user, 'esim', '1global', $payload);
        $notice = $ref !== '' ? "eSIM order received ($ref). Your QR code and SM-DP+ activation details will be delivered to your email." : 'We could not record your order. Please try again.';
        $notice_type = $ref !== '' ? 'success' : 'danger';
    }
}
$my_requests = bc_svc_requests_for_user($vid, (int)$svc_user['id'], 'esim');
include_once(__DIR__ . "/../func/bc-plate-app.php");
bc_app_head("eSIM | " . ($get_all_site_details["site_title"] ?? 'Platform'));
bc_app_sidebar();
bc_app_topbar(array("title" => "ESIM", "crumb" => "Home / eSIM"));
?>
<section class="section dashboard">
    <div class="card border-0 shadow-sm rounded-4 mb-4"><div class="card-body p-4">
        <span class="badge bg-primary mb-2">Powered by 1GLOBAL</span>
        <h4 class="fw-bold mb-1">Travel eSIM — data in 190+ countries</h4>
        <p class="text-muted small mb-0">Buy a plan, scan the QR code, and land connected. One-tap activation on iOS &amp; Android, exactly like the LemFi eSIM flow.</p>
    </div></div>
    <div class="row g-3 mb-4 text-center">
        <div class="col-md-4"><div class="p-3 bg-light rounded-4 h-100"><div class="fw-bold text-primary">STEP 01</div><strong>Choose a plan</strong><p class="small text-muted mb-0">Pick data and validity for your destination.</p></div></div>
        <div class="col-md-4"><div class="p-3 bg-light rounded-4 h-100"><div class="fw-bold text-primary">STEP 02</div><strong>Scan QR / SM-DP+</strong><p class="small text-muted mb-0">We email your QR code and SM-DP+ address instantly.</p></div></div>
        <div class="col-md-4"><div class="p-3 bg-light rounded-4 h-100"><div class="fw-bold text-primary">STEP 03</div><strong>Activate in one tap</strong><p class="small text-muted mb-0">Install the eSIM profile and switch data on when you land.</p></div></div>
    </div>
    <div class="card border-0 shadow-sm rounded-4"><div class="card-body p-4">
        <h6 class="fw-bold mb-3">Order an eSIM</h6>
        <?php if ($notice !== ''): ?><div class="alert alert-<?php echo $notice_type; ?> rounded-3 small"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>
        <form method="post">
            <div class="row g-3">
                <?php foreach ($plans as $i => $plan): ?>
                <div class="col-md-4"><label class="border rounded-4 p-3 d-block h-100">
                    <input type="radio" name="plan" value="<?php echo htmlspecialchars($plan['name']); ?>" <?php echo $i === 0 ? 'checked' : ''; ?>>
                    <strong class="d-block mt-2"><?php echo htmlspecialchars($plan['name']); ?></strong>
                    <span class="small text-muted"><?php echo htmlspecialchars($plan['data']); ?> · <?php echo htmlspecialchars($plan['days']); ?></span>
                    <strong class="d-block mt-1">$<?php echo number_format($plan['price'], 2); ?></strong>
                </label></div>
                <?php endforeach; ?>
                <div class="col-md-6"><label class="form-label small fw-bold">Email for QR delivery</label><input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($svc_user['email'] ?? ''); ?>" required></div>
            </div>
            <button type="submit" name="order_esim" class="btn btn-primary rounded-pill px-4 fw-bold mt-4">Order eSIM</button>
        </form>
        <?php if ($my_requests): ?><h6 class="fw-bold mt-4 mb-2">My eSIM orders</h6>
        <?php foreach ($my_requests as $r): ?><div class="d-flex justify-content-between border-bottom py-2"><span><strong><?php echo htmlspecialchars($r['reference']); ?></strong></span><span class="badge bg-secondary"><?php echo htmlspecialchars($r['status']); ?></span></div><?php endforeach; ?><?php endif; ?>
    </div></div>
</section>
<?php bc_app_foot(); ?>

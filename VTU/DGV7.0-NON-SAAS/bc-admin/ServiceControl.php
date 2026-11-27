<?php
require_once __DIR__ . '/../func/bc-secure-session.php';
 bc_secure_session_start();
include("../func/bc-admin-config.php");
include_once(__DIR__ . "/../func/bc-fintech-api-access.php");
require_once __DIR__.'/../func/bc-service-provider-routing.php';
require_once __DIR__.'/../func/bc-paid-security.php';
bc_paid_otp_dispatch($get_logged_admin_details,'admin',['service_provider_route']);
if(isset($_POST['save-service-route'])){
    $error=bc_service_route_save($get_logged_admin_details,$_POST);
    $message=$error===''?'Request route saved. Existing orders and financial holds are unchanged.':$error;
    bc_paid_reply($error==='',$message);
    $_SESSION['product_purchase_response']=$message;header('Location: ServiceControl.php');exit;
}


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
    'sme-data' => 'Data Type — SME DATA',
    'cg-data' => 'Data Type — CORPORATE DATA',
    'shared-data' => 'Data Type — SHARED DATA',
    'dd-data' => 'Data Type — DIRECT DATA',
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
    'share_fund' => 'Share Funds (User-to-User Transfer)',
    'password_reset' => 'Password Reset',
    'registration' => 'User Registration (Website & Mobile App)',
    'virtual_card' => 'Virtual Card System',
    'gift_card' => 'Gift Cards',
    'crypto_hub' => 'Crypto Service',
    'intl_transfer' => 'Send Money Abroad (International Transfer)',
    'tuition' => 'Pay Tuition Worldwide',
    'esim' => 'Travel eSIM',
    'pos_request' => 'POS Device Requests',
    'currency_conversion' => 'Currency Conversion (Live FX Rates)',
    'nin_card' => 'Digital NIN Slip',
    'bvn_verify' => 'BVN Verification',
    'virtual_bank_display' => 'Virtual Bank Button (Off by Default)',
    'ai_suite' => 'AI Business Suite',
    'vtu_coins' => 'VTU Coins & Loyalty Program',
    'referral' => 'Refer & Earn (Referral Program)'
];

$gateways = [
    'paystack' => 'Paystack',
    'flutterwave' => 'Flutterwave',
    'monnify' => 'Monnify',
    'payvessel' => 'PayVessel',
    'beewave' => 'BeeWave',
    'payhub' => 'PayHub',
    // 'plisio' => 'Plisio (Crypto)', // DGV7: retired, provider defunct
    'manual_funding' => 'Manual Bank Funding'
];

// Live FX (currency conversion) API configuration. The public landing-page
// converter is offered only while the Currency Conversion service above is ON
// and a key is saved here; getLiveExchangeRate() prefers this keyed provider
// over the shared free endpoint, so rates are the vendor's own live rates.
if (isset($_POST['save-fx-api'])) {
    $vid = (int)$get_logged_admin_details['id'];
    $fx_key = trim(strip_tags($_POST['fx_api_key'] ?? ''));
    setVendorOption($vid, 'fx_api_provider', 'exchangerate-api');
    setVendorOption($vid, 'fx_api_key', $fx_key);
    $_SESSION["product_purchase_response"] = $fx_key !== ''
        ? "Currency conversion API saved. Live rates are now used wherever currency conversion applies."
        : "Currency conversion API key removed. Live currency conversion stays off until a key is saved.";
    header("Location: ServiceControl.php");
    exit();
}

// §25: active fintech provider. The user frontend (Crypto, Send Money Abroad,
// tuition, eSIM, POS, virtual card) renders EXACTLY this provider's services;
// switching here is the single source of truth, so frontend and backend cannot drift.
if (isset($_POST['save-active-provider'])) {
    $vid = (int)$get_logged_admin_details['id'];
    $provider = strtolower(trim(strip_tags($_POST['fintech_active_provider'] ?? '')));
    // §28: Plisio is retired. Only APIs the vendor may use (included on
    // NON-SAAS, purchased on SAAS) can become the active provider.
    $allowed = function_exists('bc_fintech_api_accessible_providers') ? bc_fintech_api_accessible_providers($vid) : ['flutterwave'];
    if (!in_array($provider, $allowed, true)) $provider = $allowed[0] ?? 'flutterwave';
    if (function_exists('setVendorOption')) setVendorOption($vid, 'fintech_active_provider', $provider);
    $_SESSION["product_purchase_response"] = "Active provider switched. Legacy default saved. Explicit independent service routes are unchanged.";
    header("Location: ServiceControl.php");
    exit();
}

// §26: live provider credentials for the newly documented APIs. Klasha and
// Flutterwave keys keep their existing homes (PaymentGateway.php); Platnova's
// X-API-Key and 1GLOBAL's OAuth access token live in sas_payment_gateways
// under their provider names, which is where func/bc-fintech-providers.php
// reads them. A blank key field keeps the stored key (never wipes it).
if (isset($_POST['save-provider-credentials'])) {
    $vid = (int)$get_logged_admin_details['id'];
    $upsert_key = function ($gateway, $secret) use ($connection_server, $vid) {
        $gateway_e = mysqli_real_escape_string($connection_server, $gateway);
        $existing = mysqli_query($connection_server, "SELECT id, secret_key FROM sas_payment_gateways WHERE vendor_id='$vid' && gateway_name='$gateway_e' LIMIT 1");
        $row = $existing ? mysqli_fetch_assoc($existing) : null;
        $secret = trim((string)$secret);
        if ($secret === '' && $row) $secret = (string)$row['secret_key'];
        $secret_e = mysqli_real_escape_string($connection_server, $secret);
        $status = $secret !== '' ? 1 : 2;
        if ($row) {
            mysqli_query($connection_server, "UPDATE sas_payment_gateways SET secret_key='$secret_e', status='$status' WHERE vendor_id='$vid' && gateway_name='$gateway_e'");
        } else {
            mysqli_query($connection_server, "INSERT INTO sas_payment_gateways (vendor_id, gateway_name, public_key, secret_key, encrypt_key, percentage, status) VALUES ('$vid', '$gateway_e', '', '$secret_e', '', '0', '$status')");
        }
    };
    if (function_exists('bc_fintech_api_access') && !bc_fintech_api_access($vid, 'platnova')) {
        $_SESSION["api_access_notice"] = "Platnova credentials were NOT saved: Platnova API access has not been purchased. Buy it under Payment Gateways → Fintech API Access.";
    } else {
        $upsert_key('platnova', $_POST['platnova_api_key'] ?? '');
    }
    $upsert_key('oneglobal', $_POST['oneglobal_access_token'] ?? '');
    if (function_exists('setVendorOption')) {
        setVendorOption($vid, 'klasha_login_email', trim(strip_tags($_POST['klasha_login_email'] ?? '')));
        setVendorOption($vid, 'klasha_business_id', trim(strip_tags($_POST['klasha_business_id'] ?? '')));
    }
    $_SESSION["product_purchase_response"] = "Provider API credentials saved." . (!empty($_SESSION["api_access_notice"]) ? " " . $_SESSION["api_access_notice"] : "");
    unset($_SESSION["api_access_notice"]);
    header("Location: ServiceControl.php");
    exit();
}

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
// DGV7: per-tier service matrix (personal/business/provider readiness). Shared partial so the
// vendor console and the platform console cannot drift apart.
$matrix_vendor_id = (int)$get_logged_admin_details['id'];
$matrix_return_url = 'ServiceControl.php';
include(__DIR__ . "/../func/bc-service-matrix.php");
include(__DIR__ . "/../func/bc-account-backfill.php");

?>
<?php include_once(__DIR__ . "/../func/bc-plate-admin.php");
bc_adm_head("Service Control Center | " . ($get_all_super_admin_site_details["site_title"]), array("area" => "admin"));
?>
<style data-pkx="page-redesign">
/* Modern fintech redesign layer (2026-11) */
.pk-app__wrap .card{border-radius:20px}
.pk-app__wrap .card-header{background:linear-gradient(135deg,color-mix(in srgb,var(--brand) 10%,var(--surface)),var(--surface));font-weight:800}
.pk-app__wrap .table thead th{letter-spacing:.04em;text-transform:uppercase;font-size:.72rem}
.pk-app__wrap .form-control,.pk-app__wrap .form-select{border-radius:12px}
.pk-app__wrap .form-label{letter-spacing:.05em}
.pk-app__wrap .btn{border-radius:12px;font-weight:700}
.pk-app__wrap .badge{border-radius:999px}
.pk-app__wrap .nav-pills .nav-link{border-radius:999px;font-weight:700}
.pk-app__wrap .modal-content{border-radius:20px;overflow:hidden}

.pk-app__wrap .form-check-input{width:2.6rem;height:1.4rem;border-radius:999px}
.pk-app__wrap .form-switch .form-check-input:checked{background-color:var(--brand);border-color:var(--brand)}
</style>
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
<?php bc_adm_sidebar(array("area" => "admin")); ?>
<?php bc_adm_topbar(array("area" => "admin", "title" => "SERVICE CONTROL CENTER", "crumb" => "Home / Service Control")); ?>

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

            <?php include(__DIR__ . "/../func/bc-service-matrix-render.php"); ?>
            <?php include(__DIR__ . "/../func/bc-account-backfill-render.php"); ?>

            <div class="col-12 mb-4"><div class="card p-4"><h5>Independent service request routes</h5><p>Choose each request destination independently. Access and catalogue support are rechecked on every request. This does not enable settlement, crypto custody, cards or withdrawals, and never reroutes an existing order. Disabled routes do not fall back.</p>
<?php foreach(bc_service_route_catalogue() as $route_service=>$definition): $route_vid=(int)$get_logged_admin_details['id'];$route_current=bc_service_route_provider($route_vid,$route_service); ?>
<form method="post" data-paid-save class="border rounded p-3 mb-3"><h6><?php echo htmlspecialchars($definition['label'],ENT_QUOTES,'UTF-8'); ?></h6><input type="hidden" name="route_service" value="<?php echo $route_service; ?>"><select name="route_provider" class="form-select"><option value="">Disabled / unavailable</option><?php foreach(bc_service_route_choices($route_vid,$route_service) as $slug=>$label): ?><option value="<?php echo $slug; ?>" <?php echo $route_current===$slug?'selected':''; ?>><?php echo htmlspecialchars($label,ENT_QUOTES,'UTF-8'); ?></option><?php endforeach; ?></select><?php bc_paid_fields($get_logged_admin_details,'service_provider_route'); ?><button name="save-service-route" value="1" class="btn btn-primary">Save request route</button></form>
<?php endforeach; ?>
</div></div>
            <div class="col-lg-6 mb-4"><div class="card shadow-sm border-0 rounded-4 mb-4"><div class="card-header bg-white py-3 border-0"><h5 class="fw-bold mb-0 text-primary"><i class="bi bi-diagram-3 me-2"></i>Active Fintech Provider</h5></div>                    <div class="card-body border-top">
                        <?php if (function_exists('bc_adm_render_api_info_box')) bc_adm_render_api_info_box('fintech-routing'); ?>
                        <h6 class="fw-bold mb-1"><i class="bi bi-diagram-3 me-2"></i>Active Fintech Provider</h6>
                        <p class="small text-muted">Legacy default for pages without an independent route. Saved per-service request routes above take precedence. BTC swap is not offered by the new payment APIs, so BTC pairs hide automatically while one of them is active.</p>
                        <form method="post" action="" class="d-flex flex-wrap gap-2 align-items-center">
                            <select name="fintech_active_provider" class="form-select" style="max-width:280px;">
                                <?php $active_provider = function_exists('bc_svc_active_provider') ? bc_svc_active_provider((int)$get_logged_admin_details['id']) : (function_exists('getVendorOption') ? getVendorOption((int)$get_logged_admin_details['id'], 'fintech_active_provider', 'flutterwave') : 'flutterwave'); $provider_options = []; foreach (bc_fintech_api_accessible_providers((int)$get_logged_admin_details['id']) as $pv) { $provider_options[$pv] = bc_fintech_api_providers()[$pv]['label']; } if (!$provider_options) $provider_options = ['flutterwave' => 'Flutterwave']; foreach ($provider_options as $pv => $pl): ?>
                                <option value="<?php echo $pv; ?>" <?php echo $active_provider === $pv ? 'selected' : ''; ?>><?php echo $pl; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" name="save-active-provider" class="btn btn-primary fw-bold">Switch Provider</button>
                        </form>
                        <hr class="my-4">
                        <h6 class="fw-bold mb-1"><i class="bi bi-key me-2"></i>Provider API Credentials <span class="badge bg-success">§26 live</span></h6>
                        <p class="small text-muted">Endpoints follow the official provider documentation. Only APIs with active access (see Payment Gateways → Fintech API Access) can be switched on. Klasha &amp; Flutterwave keys are saved under <a href="PaymentGateway.php">Payment Gateways</a> (Klasha: Public Key = merchant public key, Secret Key = account password or Bearer token, Encrypt Key = encryption key). Save the Platnova / 1GLOBAL credentials and the Klasha login helpers here. Blank key fields keep the stored key.</p>
                        <?php
                            $cred_vid = (int)$get_logged_admin_details['id'];
                            $platnova_row = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT secret_key FROM sas_payment_gateways WHERE vendor_id='$cred_vid' && gateway_name='platnova' LIMIT 1"));
                            $oneglobal_row = mysqli_fetch_assoc(mysqli_query($connection_server, "SELECT secret_key FROM sas_payment_gateways WHERE vendor_id='$cred_vid' && gateway_name='oneglobal' LIMIT 1"));
                            $klasha_email = function_exists('getVendorOption') ? (string)getVendorOption($cred_vid, 'klasha_login_email', '') : '';
                            $klasha_biz = function_exists('getVendorOption') ? (string)getVendorOption($cred_vid, 'klasha_business_id', '') : '';
                        ?>
                        <form method="post" action="">
                            <div class="mb-3">
                                <label class="form-label small fw-bold">Platnova API Key (X-API-Key) <?php echo !empty($platnova_row['secret_key']) ? '<span class="badge bg-success">saved</span>' : '<span class="badge bg-secondary">not set</span>'; ?></label>
                                <input type="password" name="platnova_api_key" class="form-control" autocomplete="new-password" placeholder="Paste Platnova API key">
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-bold">1GLOBAL Connect Access Token (OAuth Bearer) <?php echo !empty($oneglobal_row['secret_key']) ? '<span class="badge bg-success">saved</span>' : '<span class="badge bg-secondary">not set</span>'; ?></label>
                                <input type="password" name="oneglobal_access_token" class="form-control" autocomplete="new-password" placeholder="Paste 1GLOBAL access token">
                            </div>
                            <div class="row g-2">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label small fw-bold">Klasha Login Email</label>
                                    <input type="email" name="klasha_login_email" class="form-control" value="<?php echo htmlspecialchars($klasha_email); ?>" placeholder="Klasha account email">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label small fw-bold">Klasha Business ID (China payouts)</label>
                                    <input type="text" name="klasha_business_id" class="form-control" value="<?php echo htmlspecialchars($klasha_biz); ?>" placeholder="e.g. 999">
                                </div>
                            </div>
                            <button type="submit" name="save-provider-credentials" class="btn btn-outline-primary fw-bold">Save API Credentials</button>
                        </form>
                    </div>
</div></div>
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

                <!-- Currency Conversion API -->
                <div class="card shadow-sm border-0 rounded-4 mt-4">
                    <div class="card-header bg-white py-3 border-0">
                        <h5 class="fw-bold mb-0 text-primary"><i class="bi bi-currency-exchange me-2"></i>Currency Conversion API</h5>
                        <p class="small text-muted mb-0">Live exchange rates for the public landing-page converter and currency-priced services.</p>
                    </div>
                    <div class="card-body">
                        <?php if (function_exists('bc_adm_render_api_info_box')) bc_adm_render_api_info_box('fx-api'); ?>
                        <?php $fx_api_key_saved = trim((string) getVendorOption((int)$get_logged_admin_details['id'], 'fx_api_key', '')); ?>
                        <div class="alert <?php echo $fx_api_key_saved !== '' ? 'alert-success' : 'alert-warning'; ?> border-0 rounded-3 small">
                            <?php if ($fx_api_key_saved !== ''): ?>
                                <i class="bi bi-check-circle me-1"></i><strong>API configured.</strong> Live conversion is available wherever the Currency Conversion service is switched on.
                            <?php else: ?>
                                <i class="bi bi-exclamation-triangle me-1"></i><strong>No API key saved.</strong> The landing-page converter stays hidden until a key is saved here and the Currency Conversion service is switched on.
                            <?php endif; ?>
                        </div>
                        <form method="post" action="">
                            <div class="mb-3">
                                <label class="form-label small fw-bold">Provider</label>
                                <select name="fx_api_provider" class="form-select">
                                    <option value="exchangerate-api" selected>ExchangeRate-API (v6.exchangerate-api.com)</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-bold">API Key</label>
                                <input type="text" name="fx_api_key" class="form-control" value="<?php echo htmlspecialchars($fx_api_key_saved, ENT_QUOTES); ?>" placeholder="Paste your ExchangeRate-API key" autocomplete="off">
                                <div class="form-text">Get a key at exchangerate-api.com. Leave the field blank and save to remove the key.</div>
                            </div>
                            <button type="submit" name="save-fx-api" class="btn btn-primary rounded-pill px-4 fw-bold"><i class="bi bi-save2 me-1"></i>Save API Configuration</button>
                        </form>
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

    



<?php bc_paid_otp_script();bc_adm_foot(array("area" => "admin")); ?>
                        

<?php session_start();
include("func/bc-connect.php");
include_once("func/bc-func.php");
include_once("func/whmcs-func.php");

$temporary_domain = bc_temporary_vendor_domain();
if ($temporary_domain === '') {
    bc_render_temporary_vendor_block_page('Temporary vendor access has not been configured yet.');
    exit;
}
if (!bc_is_temporary_vendor_host()) {
    bc_render_temporary_vendor_block_page('This secure vendor portal is only available on the temporary domain configured by the super admin.');
    exit;
}

$hash = mysqli_real_escape_string($connection_server, $_GET['hash'] ?? '');
if (empty($hash)) {
    die("Access denied. Invalid or missing secure key.");
}

// This link is a bearer credential that can start a vendor session, so only a full-length random
// token is accepted. The older MD5(id.email.time()) values were guessable and a short token could
// be brute-forced; bc-admin-config.php / bc-tables.php now mint 64-hex (random_bytes) tokens.
if (!bc_is_valid_vendor_access_hash($hash)) {
    die("Access denied. Invalid or missing secure key.");
}

$v_q = mysqli_query($connection_server, "SELECT v.*, bp.name as package_name, bp.price as package_price, bp.duration_days, bp.download_url as package_dl FROM sas_vendors v LEFT JOIN sas_billing_packages bp ON v.current_billing_id = bp.id WHERE v.access_hash='$hash'");
if (!$v_q) {
    die("System error accessing vendor data. Please contact support.");
}
$vendor = mysqli_fetch_assoc($v_q);

if (!$vendor) {
    die("Unauthorized access. This link may have been revoked.");
}

$platform_url = !empty($vendor['app_base_url']) ? $vendor['app_base_url'] : $vendor['website_url'];

// Handle Renewal & Domain Actions
$renewal_response = null;

if (isset($_POST['tmp_login'])) {
    $expiry_days = max(1, min(30, (int)getSuperAdminOption('tmp_url_expiry_days', '3')));
    $reg_time = strtotime($vendor['reg_date']);
    $current_time = time();
    if (($current_time - $reg_time) > ($expiry_days * 86400)) {
        $renewal_response = "<div class='alert alert-danger fw-bold'>Temporary Access has expired.</div>";
    } else {
        session_regenerate_id(true);
        $_SESSION['tmp_vendor_override'] = $vendor['id'];
        $_SESSION['tmp_vendor_host'] = bc_normalize_vendor_host($temporary_domain);
        $_SESSION['admin_session'] = $vendor['email'];
        header("Location: /bc-admin/Dashboard.php");
        die();
    }
}

if (isset($_POST['domain_action'])) {
    $action = $_POST['domain_action'];
    $v_id = $vendor['id'];
    $current_balance = (float)$vendor['balance'];
    
    if ($action == 'register_new') {
        $domain_name = mysqli_real_escape_string($connection_server, $_POST['target_domain']);
        $extension = mysqli_real_escape_string($connection_server, $_POST['domain_extension']);
        $full_domain = strtolower($domain_name . $extension);
        if (bc_is_temporary_vendor_host($full_domain)) {
            $renewal_response = ['status' => 'error', 'message' => 'This domain is reserved for temporary vendor access. Please choose a different website domain.'];
        } else {
            $q_ext = mysqli_query($connection_server, "SELECT price, promo_price FROM sas_domain_extensions WHERE extension='$extension' LIMIT 1");
            $price_row = mysqli_fetch_assoc($q_ext);
            $domain_price = ($price_row['promo_price'] > 0) ? (float)$price_row['promo_price'] : (float)$price_row['price'];

            if ($current_balance >= $domain_price) {
                $whmcs_client_id = getSuperAdminOption('whmcs_default_client_id', '1');
                $whmcs_res = whmcsCreateOrder($whmcs_client_id, $full_domain);

                if ($whmcs_res['result'] == 'success') {
                    $expiry_date = date('Y-m-d', strtotime("+1 year"));
                    mysqli_query($connection_server, "UPDATE sas_vendors SET balance = balance - $domain_price, app_base_url = '$full_domain', website_url = '$full_domain', domain_expiry_date = '$expiry_date' WHERE id = '$v_id'");
                    $renewal_response = ['status' => 'success', 'message' => "Domain $full_domain successfully registered and assigned!"];
                    // Refresh
                    $v_q = mysqli_query($connection_server, "SELECT v.*, bp.name as package_name, bp.price as package_price, bp.duration_days, bp.download_url as package_dl FROM sas_vendors v LEFT JOIN sas_billing_packages bp ON v.current_billing_id = bp.id WHERE v.id='$v_id'");
                    $vendor = mysqli_fetch_assoc($v_q);
                    $platform_url = !empty($vendor['app_base_url']) ? $vendor['app_base_url'] : $vendor['website_url'];
                } else {
                    $renewal_response = ['status' => 'error', 'message' => "WHMCS API Error: " . ($whmcs_res['message'] ?? 'Registration failed.')];
                }
            } else {
                $renewal_response = ['status' => 'error', 'message' => "Insufficient balance to register domain (₦".number_format($domain_price, 2).")."];
            }
        }
    } elseif ($action == 'update_existing') {
        $domain = mysqli_real_escape_string($connection_server, trim(strtolower($_POST['existing_domain'])));
        if (bc_is_temporary_vendor_host($domain)) {
            $renewal_response = ['status' => 'error', 'message' => 'This domain is reserved for temporary vendor access. Please choose a different website domain.'];
        } else {
            mysqli_query($connection_server, "UPDATE sas_vendors SET app_base_url = '$domain', website_url = '$domain' WHERE id = '$v_id'");
            $renewal_response = ['status' => 'success', 'message' => "Platform URL updated to $domain!"];
            // Refresh
            $v_q = mysqli_query($connection_server, "SELECT v.*, bp.name as package_name, bp.price as package_price, bp.duration_days, bp.download_url as package_dl FROM sas_vendors v LEFT JOIN sas_billing_packages bp ON v.current_billing_id = bp.id WHERE v.id='$v_id'");
            $vendor = mysqli_fetch_assoc($v_q);
            $platform_url = !empty($vendor['app_base_url']) ? $vendor['app_base_url'] : $vendor['website_url'];
        }
    }
}

if (isset($_POST['renew_action'])) {
    $action = $_POST['renew_action'];
    $v_id = $vendor['id'];
    $current_balance = (float)$vendor['balance'];
    $pkg_id = $vendor['current_billing_id'];
    
    if ($action == 'renew_site') {
        $price = (float)$vendor['package_price'];
        $days = (int)$vendor['duration_days'];
        if ($current_balance >= $price) {
            $new_expiry = date('Y-m-d', strtotime($vendor['expiry_date'] . " + $days days"));
            mysqli_query($connection_server, "UPDATE sas_vendors SET balance = balance - $price, expiry_date = '$new_expiry' WHERE id = '$v_id'");
            mysqli_query($connection_server, "INSERT INTO sas_vendor_subscriptions (vendor_id, package_id, purchase_date, expiry_date, amount_paid) VALUES ('$v_id', '$pkg_id', NOW(), '$new_expiry', '$price')");
            $renewal_response = ['status' => 'success', 'message' => "Site successfully renewed until " . date('M d, Y', strtotime($new_expiry))];
            // Refresh vendor data
            $v_q = mysqli_query($connection_server, "SELECT v.*, bp.name as package_name, bp.price as package_price, bp.duration_days, bp.download_url as package_dl FROM sas_vendors v JOIN sas_billing_packages bp ON v.current_billing_id = bp.id WHERE v.id='$v_id'");
            $vendor = mysqli_fetch_assoc($v_q);
        } else {
            $renewal_response = ['status' => 'error', 'message' => "Insufficient wallet balance. Please fund your vendor account."];
        }
    } elseif ($action == 'renew_domain') {
        // Find domain price
        $ext = "." . pathinfo($vendor['app_base_url'], PATHINFO_EXTENSION);
        $q_ext = mysqli_query($connection_server, "SELECT price FROM sas_domain_extensions WHERE extension='$ext' LIMIT 1");
        $domain_price = ($r_ext = mysqli_fetch_assoc($q_ext)) ? (float)$r_ext['price'] : 0;
        
        if ($domain_price <= 0) $domain_price = 10000; // Fallback default

        if ($current_balance >= $domain_price) {
            $base_date = (!empty($vendor['domain_expiry_date']) && $vendor['domain_expiry_date'] != '0000-00-00') ? $vendor['domain_expiry_date'] : date('Y-m-d');
            $new_domain_expiry = date('Y-m-d', strtotime($base_date . " + 365 days"));
            mysqli_query($connection_server, "UPDATE sas_vendors SET balance = balance - $domain_price, domain_expiry_date = '$new_domain_expiry' WHERE id = '$v_id'");
            $renewal_response = ['status' => 'success', 'message' => "Domain successfully renewed until " . date('M d, Y', strtotime($new_domain_expiry))];
            // Refresh
            $v_q = mysqli_query($connection_server, "SELECT v.*, bp.name as package_name, bp.price as package_price, bp.duration_days, bp.download_url as package_dl FROM sas_vendors v JOIN sas_billing_packages bp ON v.current_billing_id = bp.id WHERE v.id='$v_id'");
            $vendor = mysqli_fetch_assoc($v_q);
        } else {
            $renewal_response = ['status' => 'error', 'message' => "Insufficient balance to renew domain (₦".number_format($domain_price, 2).")."];
        }
    }
}
if (!empty($platform_url) && !preg_match("~^(?:f|ht)tps?://~i", $platform_url)) {
    $platform_url = "https://" . $platform_url;
}

// Fetch domain extensions for search
$ext_res = mysqli_query($connection_server, "SELECT extension FROM sas_domain_extensions ORDER BY extension ASC");
$domain_extensions = [];
while($ext = mysqli_fetch_assoc($ext_res)) $domain_extensions[] = $ext['extension'];

// Fetch domain settings for instructions
$nameservers = ''; $ip_address = '';
$sql_fetch_settings = "SELECT * FROM sas_super_admin_options WHERE option_name IN ('domain_nameservers', 'domain_ip_address')";
$settings_result = mysqli_query($connection_server, $sql_fetch_settings);
while($row = mysqli_fetch_assoc($settings_result)) {
    if($row['option_name'] == 'domain_nameservers') $nameservers = $row['option_value'];
    if($row['option_name'] == 'domain_ip_address') $ip_address = $row['option_value'];
}

$addon_ids = $vendor['selected_addons'];
$has_addons = !empty($addon_ids);
$has_package_dl = !empty($vendor['package_dl']);
$has_apps = ($vendor['apk_ordered'] || $vendor['ios_ordered'] || $vendor['playstore_ordered'] || $vendor['sms_bridge_ordered']);

// Fetch active addons with download URLs
$addons = [];
$addon_ids_list = [];
if ($has_addons) {
    $addon_ids_list = array_map('intval', explode(',', $addon_ids));
}

// Explicitly add PrintHub APP to addons if ordered
if ($vendor['sms_bridge_ordered']) {
    $ph_q = mysqli_query($connection_server, "SELECT id FROM sas_billing_addons WHERE name LIKE '%PrintHub%' LIMIT 1");
    if ($ph_r = mysqli_fetch_assoc($ph_q)) {
        if (!in_array($ph_r['id'], $addon_ids_list)) {
            $addon_ids_list[] = $ph_r['id'];
        }
    }
}

if (!empty($addon_ids_list)) {
    $safe_ids = implode(',', $addon_ids_list);
    $ar = mysqli_query($connection_server, "SELECT * FROM sas_billing_addons WHERE id IN ($safe_ids)");
    while ($row = mysqli_fetch_assoc($ar)) {
        if (!empty($row['download_url'])) $addons[] = $row;
    }
}

// Pre-generate download links for performance and reliability
function getOrCreateDownloadToken($vendor_id, $asset_id, $type = 'addon') {
    global $connection_server;

    // Ensure table exists with all columns
    mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_vendor_downloads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        vendor_id INT NOT NULL,
        addon_id INT DEFAULT NULL,
        package_id INT DEFAULT NULL,
        token VARCHAR(255) NOT NULL,
        expiry DATETIME NOT NULL,
        download_count INT DEFAULT 0,
        ip_address VARCHAR(50) DEFAULT NULL,
        last_download_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX (token),
        INDEX (vendor_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $res = mysqli_query($connection_server, "SHOW COLUMNS FROM sas_vendor_downloads");
    $cols = []; while($r = mysqli_fetch_assoc($res)) $cols[] = $r['Field'];
    if (!in_array('addon_id', $cols)) mysqli_query($connection_server, "ALTER TABLE sas_vendor_downloads ADD COLUMN addon_id INT DEFAULT NULL AFTER vendor_id");
    if (!in_array('package_id', $cols)) mysqli_query($connection_server, "ALTER TABLE sas_vendor_downloads ADD COLUMN package_id INT DEFAULT NULL AFTER addon_id");

    $col = ($type == 'addon') ? 'addon_id' : 'package_id';

    // Check for existing valid token
    $sql = "SELECT token FROM sas_vendor_downloads WHERE vendor_id='$vendor_id' AND $col='$asset_id' AND expiry > NOW() ORDER BY id DESC LIMIT 1";
    $q = mysqli_query($connection_server, $sql);

    if ($q && $row = mysqli_fetch_assoc($q)) {
        return "https://" . $_SERVER['HTTP_HOST'] . "/DownloadService.php?token=" . $row['token'];
    }

    // Generate new token
    $token = bin2hex(random_bytes(16));
    $expiry = date('Y-m-d H:i:s', strtotime('+12 hours'));
    mysqli_query($connection_server, "INSERT INTO sas_vendor_downloads (vendor_id, $col, token, expiry) VALUES ('$vendor_id', '$asset_id', '$token', '$expiry')");
    return "https://" . $_SERVER['HTTP_HOST'] . "/DownloadService.php?token=" . $token;
}

$package_download_link = $has_package_dl ? getOrCreateDownloadToken($vendor['id'], $vendor['current_billing_id'], 'package') : '#';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Vendor Portal | <?php echo htmlspecialchars($vendor['firstname']); ?></title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link href="assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css"/>
    <style>
        :root {
            --primary: #6366f1;
            --primary-light: #818cf8;
            --primary-dark: #4f46e5;
            --secondary: #64748b;
            --success: #10b981;
            --bg: #0f172a;
            --card-bg: rgba(30, 41, 59, 0.7);
            --border: rgba(255, 255, 255, 0.1);
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
        }

        body { 
            background-color: var(--bg); 
            color: var(--text-main);
            font-family: 'Outfit', sans-serif;
            background-image: radial-gradient(circle at 0% 0%, rgba(99, 102, 241, 0.15) 0%, transparent 50%),
                              radial-gradient(circle at 100% 100%, rgba(168, 85, 247, 0.1) 0%, transparent 50%);
            background-attachment: fixed;
            min-height: 100vh;
        }

        .glass-card {
            background: var(--card-bg);
            backdrop-filter: blur(12px);
            border: 1px solid var(--border);
            border-radius: 24px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.2);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .glass-card:hover {
            border-color: rgba(99, 102, 241, 0.3);
        }

        .balance-badge {
            background: linear-gradient(135deg, var(--primary), #a855f7);
            padding: 2rem;
            border-radius: 24px;
            box-shadow: 0 10px 30px rgba(99, 102, 241, 0.3);
            position: relative;
            overflow: hidden;
        }

        .balance-badge::after {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            pointer-events: none;
        }

        .nav-pill-custom {
            background: rgba(255, 255, 255, 0.05);
            padding: 4px;
            border-radius: 12px;
            border: 1px solid var(--border);
        }

        .download-item {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 1.5rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .download-item:hover {
            background: rgba(255, 255, 255, 0.06);
            transform: translateY(-5px);
            border-color: var(--primary-light);
        }

        .btn-premium {
            background: linear-gradient(to right, var(--primary), var(--primary-dark));
            border: none;
            color: white;
            padding: 12px 24px;
            border-radius: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(99, 102, 241, 0.2);
        }

        .btn-premium:hover {
            transform: scale(1.02);
            box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4);
            color: white;
        }

        .btn-outline-premium {
            background: transparent;
            border: 2px solid var(--border);
            color: var(--text-main);
            padding: 8px 20px;
            border-radius: 12px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .btn-outline-premium:hover {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }

        .status-pulse {
            width: 10px;
            height: 10px;
            background: var(--success);
            border-radius: 50%;
            display: inline-block;
            margin-right: 8px;
            box-shadow: 0 0 0 rgba(16, 185, 129, 0.4);
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 10px rgba(16, 185, 129, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }

        .text-gradient {
            background: linear-gradient(to right, #fff, #94a3b8);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .label-modern {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            margin-bottom: 0.25rem;
            display: block;
        }

        .icon-box {
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 14px;
            background: rgba(99, 102, 241, 0.1);
            color: var(--primary-light);
        }

        /* Modal Overrides */
        .modal-content {
            background: #1e293b;
            border: 1px solid var(--border);
            border-radius: 28px;
        }
    </style>
</head>
<body>
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-xl-10">
                
                <!-- Header Section -->
                <div class="row align-items-center mb-5 animate__animated animate__fadeInDown">
                    <div class="col-md-7 mb-4 mb-md-0">
                        <h1 class="display-5 fw-800 text-gradient mb-2">Order Portal</h1>
                        <p class="text-muted fs-5">Welcome back, <span class="text-white fw-semibold"><?php echo htmlspecialchars($vendor['firstname']); ?></span>. Review your digital ecosystem below.</p>
                    </div>
                    <div class="col-md-5">
                        <div class="balance-badge">
                            <div class="small text-white text-opacity-75 text-uppercase fw-bold mb-1">Available Balance</div>
                            <div class="h2 fw-bold text-white mb-0">₦<?php echo number_format($vendor['balance'], 2); ?></div>
                            <i class="bi bi-wallet2 position-absolute end-0 bottom-0 m-3 fs-1 text-white text-opacity-10"></i>
                        </div>
                    </div>
                </div>

                <?php if ($renewal_response): ?>
                <div class="alert glass-card border-0 p-4 mb-4 animate__animated animate__zoomIn <?php echo $renewal_response['status'] == 'success' ? 'text-success' : 'text-danger'; ?>">
                    <div class="d-flex align-items-center">
                        <i class="bi <?php echo $renewal_response['status'] == 'success' ? 'bi-check-circle' : 'bi-x-circle'; ?> fs-3 me-3"></i>
                        <div class="fw-medium"><?php echo $renewal_response['message']; ?></div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="row g-4">
            <!-- Temporary Access Card -->
            <div class="col-12 mb-2">
                <div class="card border-0 shadow-sm rounded-4" style="background: linear-gradient(135deg, #0d6efd, #0dcaf0); color: white;">
                    <div class="card-body p-4 d-flex align-items-center justify-content-between flex-wrap gap-3">
                        <div>
                            <h4 class="fw-bold mb-1"><i class="bi bi-rocket-takeoff me-2"></i> Temporary Admin Access</h4>
                            <p class="mb-0 opacity-75 small">This is a temporary website for <strong><?php echo htmlspecialchars($vendor['website_url'], ENT_QUOTES, 'UTF-8'); ?></strong>. Changes you save here are saved for your website and will appear as soon as your domain is accessible.</p>
                        </div>
                        <?php
                            $expiry_days = max(1, min(30, (int)getSuperAdminOption('tmp_url_expiry_days', '3')));
                            $reg_time = strtotime($vendor['reg_date']);
                            $current_time = time();
                            $days_left = ceil((($reg_time + ($expiry_days * 86400)) - $current_time) / 86400);
                        ?>
                        <?php if($days_left > 0): ?>
                        <form method="post" class="m-0">
                            <button name="tmp_login" type="submit" class="btn btn-light text-primary fw-bold px-4 py-2 rounded-pill shadow-sm">
                                Open Dashboard <i class="bi bi-arrow-right-short ms-1"></i>
                            </button>
                            <div class="text-white-50 small mt-1 text-end">Expires in <?php echo $days_left; ?> days</div>
                        </form>
                        <?php else: ?>
                        <div class="bg-white bg-opacity-25 px-4 py-2 rounded-pill fw-bold border border-light">
                            <i class="bi bi-clock-history me-2"></i> Temporary Access Expired
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

                    <!-- Vendor Profile Section -->
                    <div class="col-12">
                        <div class="glass-card p-4 animate__animated animate__fadeInUp" style="animation-delay: 0.1s;">
                            <div class="row g-4">

                                <div class="col-md-4">
                                    <span class="label-modern">Business Identity</span>
                                    <div class="d-flex align-items-center">
                                        <div class="icon-box me-3"><i class="bi bi-person-badge fs-4"></i></div>
                                        <div>
                                            <div class="fw-bold"><?php echo htmlspecialchars($vendor['firstname'] . ' ' . $vendor['lastname']); ?></div>
                                            <div class="small text-muted"><?php echo htmlspecialchars($vendor['email']); ?></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <span class="label-modern">Communication</span>
                                    <div class="d-flex align-items-center">
                                        <div class="icon-box me-3"><i class="bi bi-telephone-outbound fs-4"></i></div>
                                        <div>
                                            <div class="fw-bold"><?php echo htmlspecialchars($vendor['phone_number']); ?></div>
                                            <div class="small text-muted">Primary Contact</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <span class="label-modern">Operational Base</span>
                                    <div class="d-flex align-items-center">
                                        <div class="icon-box me-3"><i class="bi bi-geo-alt fs-4"></i></div>
                                        <div class="small fw-medium text-truncate" title="<?php echo htmlspecialchars($vendor['home_address']); ?>">
                                            <?php echo htmlspecialchars($vendor['home_address']); ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Subscription Summary -->
                    <div class="col-lg-12">
                        <div class="glass-card p-4 h-100 animate__animated animate__fadeInUp" style="animation-delay: 0.2s;">
                            <div class="d-flex justify-content-between align-items-center mb-4 pb-3 border-bottom border-white border-opacity-5">
                                <h5 class="fw-bold mb-0"><i class="bi bi-shield-check me-2 text-primary-light"></i>Subscription Status</h5>
                                <div class="badge bg-success bg-opacity-10 text-success rounded-pill px-3 py-2"><span class="status-pulse"></span>Active System</div>
                            </div>
                            
                            <div class="row g-4">

                                <div class="col-md-3 col-6">
                                    <span class="label-modern">Active Tier</span>
                                    <div class="fw-bold fs-5"><?php echo htmlspecialchars($vendor['package_name'] ?? 'N/A'); ?></div>
                                </div>
                                <div class="col-md-4 col-6">
                                    <span class="label-modern">Platform URL</span>
                                    <div class="fw-bold text-primary-light text-truncate">
                                        <a href="<?php echo $platform_url; ?>" target="_blank" class="text-decoration-none text-primary-light">
                                            <i class="bi bi-link-45deg"></i> <?php echo htmlspecialchars($platform_url ?: 'Not Configured'); ?>
                                        </a>
                                    </div>
                                </div>
                                <div class="col-md-5">
                                    <div class="row g-3">
                                        <div class="col-sm-6">
                                            <span class="label-modern">System Expiry</span>
                                            <div class="fw-bold"><?php echo date('M d, Y', strtotime($vendor['expiry_date'])); ?></div>
                                            <form method="post" onsubmit="return confirm('Renew for ₦<?php echo number_format($vendor['package_price'], 2); ?>?');">
                                                <input type="hidden" name="renew_action" value="renew_site">
                                                <button type="submit" class="btn btn-outline-premium btn-sm mt-2 w-100">Renew (₦<?php echo number_format($vendor['package_price'], 0); ?>)</button>
                                            </form>
                                        </div>
                                        <div class="col-sm-6">
                                            <span class="label-modern">Domain Expiry</span>
                                            <div class="fw-bold">
                                                <?php echo (!empty($vendor['domain_expiry_date']) && $vendor['domain_expiry_date'] != '0000-00-00') ? date('M d, Y', strtotime($vendor['domain_expiry_date'])) : '<span class="text-warning">Pending Setup</span>'; ?>
                                            </div>
                                            <button type="button" class="btn btn-outline-premium btn-sm mt-2 w-100" data-bs-toggle="modal" data-bs-target="#domainModal">
                                                <i class="bi bi-gear-wide-connected me-1"></i> Manage Domain
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-12 pt-3">
                                    <span class="label-modern mb-3">Deployed Modules</span>
                                    <div class="d-flex flex-wrap gap-2">
                                        <?php if($vendor['apk_ordered']): ?><span class="badge glass-card bg-white bg-opacity-5 rounded-pill px-3 py-2 border-0"><i class="bi bi-android2 me-2"></i>Android Build</span><?php endif; ?>
                                        <?php if($vendor['ios_ordered']): ?><span class="badge glass-card bg-white bg-opacity-5 rounded-pill px-3 py-2 border-0"><i class="bi bi-apple me-2"></i>iOS Build</span><?php endif; ?>
                                        <?php if($vendor['playstore_ordered']): ?><span class="badge glass-card bg-white bg-opacity-5 rounded-pill px-3 py-2 border-0"><i class="bi bi-shop me-2"></i>Playstore</span><?php endif; ?>
                                        <?php if($vendor['sms_bridge_ordered']): ?><span class="badge glass-card bg-primary text-white rounded-pill px-3 py-2 border-0"><i class="bi bi-broadcast me-2"></i>PrintHub APP</span><?php endif; ?>
                                        
                                        <?php 
                                            if($has_addons) {
                                                $safe_ids = implode(',', array_map('intval', explode(',', $addon_ids)));
                                                $addons_res = mysqli_query($connection_server, "SELECT name FROM sas_billing_addons WHERE id IN ($safe_ids)");
                                                while($arow = mysqli_fetch_assoc($addons_res)) {
                                                    echo '<span class="badge glass-card bg-primary bg-opacity-10 text-primary-light rounded-pill px-3 py-2 border-0"><i class="bi bi-plus-circle me-2"></i>'.htmlspecialchars($arow['name']).'</span>';
                                                }
                                            }
                                        ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Digital Assets Grid -->
                    <div class="col-12">
                        <div class="glass-card p-4 animate__animated animate__fadeInUp" style="animation-delay: 0.3s;">
                            <h5 class="fw-bold mb-4"><i class="bi bi-layers-half me-2 text-primary-light"></i>Digital Assets & Downloads</h5>
                            
                            <div class="alert bg-primary bg-opacity-5 border border-primary border-opacity-10 rounded-4 small mb-4 text-primary-light">
                                <i class="bi bi-info-circle-fill me-2"></i> Your download links are automatically generated and ready for use.
                            </div>

                            <div class="row g-4">

                                <?php if ($has_package_dl): ?>
                                <div class="col-12">
                                    <div class="download-item p-4 border-primary border-opacity-20 bg-primary bg-opacity-5 shadow-sm">
                                        <div class="row align-items-center">
                                            <div class="col-md-8 mb-3 mb-md-0 text-center text-md-start">
                                                <div class="d-flex align-items-center justify-content-center justify-content-md-start">
                                                    <div class="icon-box bg-primary p-3 rounded-4 me-3 text-white">
                                                        <i class="bi bi-terminal fs-3"></i>
                                                    </div>
                                                    <div>
                                                        <div class="fw-bold fs-5"><?php echo htmlspecialchars($vendor['package_name']); ?> Source Core</div>
                                                        <div class="small text-primary-light fw-medium">Complete Server-Side VTU Script Bundle</div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <a href="<?php echo $package_download_link; ?>" target="_blank" class="btn btn-premium w-100">
                                                    <i class="bi bi-cloud-download-fill me-2"></i> Download Package
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <?php if (empty($addons) && !$has_package_dl): ?>
                                    <div class="col-12 text-center py-5 text-muted">
                                        <i class="bi bi-stack fs-1 d-block mb-3 opacity-25"></i>
                                        <div class="fs-5">No assets available for this tier yet.</div>
                                        <p class="small">Contact administration if you believe this is an error.</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($addons as $addon):
                                        $dl_link = getOrCreateDownloadToken($vendor['id'], $addon['id'], 'addon');
                                    ?>
                                    <div class="col-md-6 col-xl-4">
                                        <div class="download-item">
                                            <div class="d-flex align-items-center mb-4">
                                                <div class="icon-box me-3">
                                                    <i class="bi <?php echo htmlspecialchars($addon['icon'] ?: 'bi-box-seam'); ?> fs-4"></i>
                                                </div>
                                                <div class="overflow-hidden">
                                                    <div class="fw-bold text-truncate"><?php echo htmlspecialchars($addon['name']); ?></div>
                                                    <div class="small text-muted">Production Build</div>
                                                </div>
                                            </div>
                                            <a href="<?php echo $dl_link; ?>" target="_blank" class="btn btn-outline-premium w-100 btn-sm">
                                                <i class="bi bi-cloud-download me-2"></i> Download Asset
                                            </a>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <footer class="text-center mt-5 pt-5 opacity-50 small">
                    <p>&copy; <?php echo date('Y'); ?> Digital Assets Cloud • Powered by DGV6.90 Architecture</p>
                </footer>
            </div>
        </div>
    </div>

    <!-- Modal for Domain Management -->
    <div class="modal fade" id="domainModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content text-white p-2">
                <div class="modal-body p-4">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h4 class="fw-bold mb-0">Domain Management</h4>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <ul class="nav nav-pills nav-pill-custom mb-4" id="domainTab" role="tablist">
                        <li class="nav-item flex-fill" role="presentation">
                            <button class="nav-link active w-100 text-white border-0" id="register-tab" data-bs-toggle="pill" data-bs-target="#register-view" type="button" role="tab">Register New Domain</button>
                        </li>
                        <li class="nav-item flex-fill" role="presentation">
                            <button class="nav-link w-100 text-white border-0" id="existing-tab" data-bs-toggle="pill" data-bs-target="#existing-view" type="button" role="tab">Use Existing Domain</button>
                        </li>
                    </ul>

                    <div class="tab-content" id="domainTabContent">
                        <!-- Register New Domain -->
                        <div class="tab-pane fade show active" id="register-view" role="tabpanel">
                            <p class="text-muted small mb-4">Search for a new domain to register for your platform. Registration fees will be deducted from your wallet balance.</p>
                            
                            <div class="input-group input-group-lg mb-3">
                                <span class="input-group-text bg-black bg-opacity-20 border-white border-opacity-10 text-muted">www.</span>
                                <input type="text" id="target_domain" class="form-control bg-black bg-opacity-20 border-white border-opacity-10 text-white" placeholder="mybrandname">
                                <select id="domain_extension" class="form-select bg-black bg-opacity-30 border-white border-opacity-10 text-white" style="max-width: 130px;">
                                    <?php foreach($domain_extensions as $ext): ?>
                                        <option value="<?php echo $ext; ?>"><?php echo $ext; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="btn btn-premium px-4" type="button" id="search-btn" onclick="lookupDomain()">
                                    <span id="btn-text">Search</span>
                                    <span id="btn-spinner" class="spinner-border spinner-border-sm d-none"></span>
                                </button>
                            </div>
                            
                            <div id="domain_feedback" class="mb-4"></div>

                            <form method="post" id="reg-domain-form" class="d-none">
                                <input type="hidden" name="domain_action" value="register_new">
                                <input type="hidden" name="target_domain" id="final_target_domain">
                                <input type="hidden" name="domain_extension" id="final_domain_extension">
                                <div class="bg-primary bg-opacity-10 p-4 rounded-4 border border-primary border-opacity-20 text-center">
                                    <div class="small text-muted text-uppercase mb-2">Registration Cost</div>
                                    <div class="h3 fw-bold text-primary-light mb-4" id="domain_price_display">₦0.00</div>
                                    <button type="submit" class="btn btn-premium w-100 py-3" onclick="return confirm('Register this domain? The price will be deducted from your wallet balance.');">
                                        <i class="bi bi-credit-card me-2"></i> Register & Pay Now
                                    </button>
                                </div>
                            </form>
                        </div>

                        <!-- Use Existing Domain -->
                        <div class="tab-pane fade" id="existing-view" role="tabpanel">
                            <p class="text-muted small mb-4">Update your platform URL to a domain you already own. You'll need to point your domain to our servers.</p>
                            
                            <form method="post">
                                <input type="hidden" name="domain_action" value="update_existing">
                                <div class="mb-4">
                                    <label class="label-modern">Enter Domain Name</label>
                                    <div class="input-group input-group-lg">
                                        <span class="input-group-text bg-black bg-opacity-20 border-white border-opacity-10 text-muted">https://</span>
                                        <input type="text" name="existing_domain" class="form-control bg-black bg-opacity-20 border-white border-opacity-10 text-white" placeholder="mywebsite.com" required>
                                    </div>
                                </div>

                                <div class="bg-black bg-opacity-20 p-4 rounded-4 border border-white border-opacity-5 mb-4">
                                    <h6 class="fw-bold mb-3"><i class="bi bi-info-circle me-2 text-primary-light"></i>Setup Instructions</h6>
                                    <div class="row g-3">
                                        <div class="col-sm-6">
                                            <span class="label-modern">Nameservers</span>
                                            <div class="bg-black bg-opacity-30 p-2 rounded border border-white border-opacity-5 small font-monospace">
                                                <?php echo nl2br(htmlspecialchars($nameservers)); ?>
                                            </div>
                                        </div>
                                        <div class="col-sm-6">
                                            <span class="label-modern">A-Record (IP)</span>
                                            <div class="bg-black bg-opacity-30 p-2 rounded border border-white border-opacity-5 small font-monospace">
                                                <?php echo htmlspecialchars($ip_address); ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-premium w-100 py-3">
                                    <i class="bi bi-arrow-repeat me-2"></i> Update Platform URL
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="assets-2/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script>
        function lookupDomain() {
            const domain = document.getElementById('target_domain').value.trim();
            const extension = document.getElementById('domain_extension').value;
            const feedback = document.getElementById('domain_feedback');
            const spinner = document.getElementById('btn-spinner');
            const btnText = document.getElementById('btn-text');
            const regForm = document.getElementById('reg-domain-form');

            if (!domain) {
                alert('Please enter a domain name');
                return;
            }

            spinner.classList.remove('d-none');
            btnText.classList.add('d-none');
            feedback.innerHTML = '<div class="text-center py-3"><div class="spinner-border text-primary"></div><div class="mt-2 small text-muted">Checking Availability...</div></div>';
            regForm.classList.add('d-none');

            fetch('bc-spadmin/ajax-domain-check.php?domain=' + encodeURIComponent(domain + extension))
                .then(r => r.json())
                .then(data => {
                    spinner.classList.add('d-none');
                    btnText.classList.remove('d-none');

                    if (data.status === 'available') {
                        feedback.innerHTML = `<div class="alert bg-success bg-opacity-10 border-success border-opacity-20 text-success rounded-4 p-3 mb-0">
                            <i class="bi bi-check-circle-fill me-2"></i> <strong>${domain}${extension}</strong> is available for registration!
                        </div>`;
                        document.getElementById('domain_price_display').innerText = '₦' + parseFloat(data.price).toLocaleString();
                        document.getElementById('final_target_domain').value = domain;
                        document.getElementById('final_domain_extension').value = extension;
                        regForm.classList.remove('d-none');
                    } else if (data.status === 'registered' || data.status === 'unavailable') {
                        let html = `<div class="alert bg-danger bg-opacity-10 border-danger border-opacity-20 text-danger rounded-4 p-3 mb-3">
                            <i class="bi bi-x-circle-fill me-2"></i> <strong>${domain}${extension}</strong> is already taken.
                        </div>`;
                        if (data.suggestions && data.suggestions.length > 0) {
                            html += `<div class="small text-muted mb-2 text-uppercase fw-bold">Try these alternatives:</div><div class="d-flex flex-wrap gap-2">`;
                            data.suggestions.forEach(s => {
                                html += `<button type="button" class="btn btn-outline-premium btn-sm rounded-pill py-1 px-3" onclick="useSuggested('${s}')">${s}</button>`;
                            });
                            html += `</div>`;
                        }
                        feedback.innerHTML = html;
                    } else {
                        feedback.innerHTML = `<div class="alert bg-warning bg-opacity-10 border-warning border-opacity-20 text-warning rounded-4 p-3">${data.message}</div>`;
                    }
                })
                .catch(err => {
                    console.error(err);
                    spinner.classList.add('d-none');
                    btnText.classList.remove('d-none');
                    feedback.innerHTML = '<div class="alert bg-danger bg-opacity-10 text-danger p-3 rounded-4">Lookup failed. Check your connection.</div>';
                });
        }

        function useSuggested(fullDomain) {
            const parts = fullDomain.split('.');
            document.getElementById('target_domain').value = parts[0];
            const ext = '.' + parts.slice(1).join('.');
            const select = document.getElementById('domain_extension');
            for(let i=0; i<select.options.length; i++){
                if(select.options[i].value === ext){
                    select.selectedIndex = i;
                    break;
                }
            }
            lookupDomain();
        }
    </script>
</body>
</html>

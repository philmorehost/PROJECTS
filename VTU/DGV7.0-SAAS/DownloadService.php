<?php
include("func/bc-connect.php");

// Logging function
function logDownloadError($message) {
    $logFile = dirname(__FILE__) . '/logs/download_link_errors.log';
    if (!is_dir(dirname($logFile))) {
        mkdir(dirname($logFile), 0777, true);
    }
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] {$message}\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

if (!$connection_server) {
    $err = "Database connection failed: " . mysqli_connect_error();
    logDownloadError($err);
    die("Error: $err. Please contact support.");
}

// ─── DEFENSIVE SCHEMA SETUP ───
mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_billing_addons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    price DECIMAL(10, 2) NOT NULL,
    icon VARCHAR(50) DEFAULT 'bi-box-seam',
    download_url TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_billing_packages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    price DECIMAL(10, 2) NOT NULL,
    duration_days INT NOT NULL,
    download_url TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

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

// Ensure columns exist in sas_vendor_downloads
$res = mysqli_query($connection_server, "SHOW COLUMNS FROM sas_vendor_downloads");
$cols = []; while($r = mysqli_fetch_assoc($res)) $cols[] = $r['Field'];
if (!in_array('addon_id', $cols)) mysqli_query($connection_server, "ALTER TABLE sas_vendor_downloads ADD COLUMN addon_id INT DEFAULT NULL AFTER vendor_id");
if (!in_array('package_id', $cols)) mysqli_query($connection_server, "ALTER TABLE sas_vendor_downloads ADD COLUMN package_id INT DEFAULT NULL AFTER addon_id");

// Ensure columns exist in addons and packages
$check_addons = mysqli_query($connection_server, "SHOW COLUMNS FROM sas_billing_addons LIKE 'download_url'");
if (mysqli_num_rows($check_addons) == 0) mysqli_query($connection_server, "ALTER TABLE sas_billing_addons ADD COLUMN download_url TEXT DEFAULT NULL");

$check_pkgs = mysqli_query($connection_server, "SHOW COLUMNS FROM sas_billing_packages LIKE 'download_url'");
if (mysqli_num_rows($check_pkgs) == 0) mysqli_query($connection_server, "ALTER TABLE sas_billing_packages ADD COLUMN download_url TEXT DEFAULT NULL");


$token = mysqli_real_escape_string($connection_server, $_GET['token'] ?? '');

if (empty($token)) {
    die("Error: No token provided.");
}

// Fetch the tracking record
$sql = "SELECT vd.*, a.download_url as addon_url, p.download_url as package_url
        FROM sas_vendor_downloads vd
        LEFT JOIN sas_billing_addons a ON vd.addon_id = a.id
        LEFT JOIN sas_billing_packages p ON vd.package_id = p.id
        WHERE vd.token='$token' LIMIT 1";
$res = mysqli_query($connection_server, $sql);

if (!$res) {
    $err = "SQL Error: " . mysqli_error($connection_server);
    logDownloadError($err . " | SQL: $sql");
    die("Error: $err. Please contact support.");
}

$dl = mysqli_fetch_assoc($res);

if (!$dl) {
    die("Error: Download link not found or has been revoked.");
}

// Check expiry (12 hours)
if (strtotime($dl['expiry']) < time()) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <title>Download Link Expired</title>
        <link href="assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
        <link href="assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    </head>
    <body class="bg-light d-flex align-items-center justify-content-center vh-100">
        <div class="text-center p-5 bg-white rounded-4 shadow-sm" style="max-width: 400px;">
            <div class="bg-warning bg-opacity-10 p-4 rounded-circle d-inline-block mb-4">
                <i class="bi bi-exclamation-triangle text-warning fs-1"></i>
            </div>
            <h4 class="fw-bold text-dark">Link Expired</h4>
            <p class="text-muted mb-4 small">For security reasons, this download address expired after 12 hours. Please return to your Order Portal to generate a fresh link.</p>
            <a href="javascript:window.history.back()" class="btn btn-primary rounded-pill px-4 fw-bold">Return to Portal</a>
        </div>
    </body>
    </html>
    <?php
    exit();
}

// Track download
$ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
mysqli_query($connection_server, "UPDATE sas_vendor_downloads SET download_count = download_count + 1, ip_address='$ip' WHERE id='".$dl['id']."'");

// Redirect to actual file
$final_url = !empty($dl['addon_url']) ? $dl['addon_url'] : $dl['package_url'];
if (empty($final_url)) {
    die("Error: The file source (URL) is empty in the database for this asset.");
}

header("Location: $final_url");
exit();

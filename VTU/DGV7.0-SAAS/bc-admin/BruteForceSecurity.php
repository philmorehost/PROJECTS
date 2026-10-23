<?php session_start();
include("../func/bc-admin-config.php");

if (isset($_POST["update-settings"])) {
    $vendor_id = $get_logged_admin_details["id"];
    $is_enabled = isset($_POST["is_enabled"]) ? 1 : 0;
    $period = (int)$_POST["period_mins"];
    $max_acc = (int)$_POST["max_failures_account"];
    $max_ip = (int)$_POST["max_failures_ip"];
    $block_dur = mysqli_real_escape_string($connection_server, $_POST["block_duration"]);
    $lock_admin = isset($_POST["lock_admin"]) ? 1 : 0;
    $notify_admin = isset($_POST["notify_admin"]) ? 1 : 0;

    // Check if settings already exist
    $check_exist = mysqli_query($connection_server, "SELECT vendor_id FROM sas_bruteforce_settings WHERE vendor_id='$vendor_id'");
    if (mysqli_num_rows($check_exist) > 0) {
        $stmt = mysqli_prepare($connection_server, "UPDATE sas_bruteforce_settings SET is_enabled=?, period_mins=?, max_failures_account=?, max_failures_ip=?, block_duration=?, lock_admin=?, notify_admin=? WHERE vendor_id=?");
        mysqli_stmt_bind_param($stmt, "iiiisiii", $is_enabled, $period, $max_acc, $max_ip, $block_dur, $lock_admin, $notify_admin, $vendor_id);
    } else {
        $stmt = mysqli_prepare($connection_server, "INSERT INTO sas_bruteforce_settings (vendor_id, is_enabled, period_mins, max_failures_account, max_failures_ip, block_duration, lock_admin, notify_admin) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, "iiiiisii", $vendor_id, $is_enabled, $period, $max_acc, $max_ip, $block_dur, $lock_admin, $notify_admin);
    }

    if (mysqli_stmt_execute($stmt)) {
        $_SESSION["product_purchase_response"] = "Security settings updated successfully.";
    } else {
        $_SESSION["product_purchase_response"] = "Error updating settings: " . mysqli_error($connection_server);
    }

    // ─── Failed-transaction guard settings ───────────────────────────────────────
    // Saved with a separate statement so the prepared brute-force statement above keeps its exact
    // shape, and guarded by a column check so this cannot break on an install that has not yet run
    // the migration that adds these columns.
    $tx_enabled = isset($_POST["tx_guard_enabled"]) ? 1 : 0;
    $tx_notify  = isset($_POST["tx_guard_notify_admin"]) ? 1 : 0;
    $tx_auto    = isset($_POST["tx_guard_auto_unlock"]) ? 1 : 0;
    $tx_max     = max(1, (int)($_POST["tx_guard_max_failures"] ?? 5));
    $tx_window  = max(1, (int)($_POST["tx_guard_window_hours"] ?? 24));
    $tx_scope   = (($_POST["tx_guard_scope"] ?? 'all') === 'per-service') ? 'per-service' : 'all';
    $tx_cols_ok = mysqli_query($connection_server, "SHOW COLUMNS FROM sas_bruteforce_settings LIKE 'tx_guard_enabled'");
    if ($tx_cols_ok && mysqli_num_rows($tx_cols_ok) > 0) {
        mysqli_query($connection_server, "UPDATE sas_bruteforce_settings SET tx_guard_enabled='$tx_enabled', tx_guard_max_failures='$tx_max', tx_guard_window_hours='$tx_window', tx_guard_scope='$tx_scope', tx_guard_notify_admin='$tx_notify', tx_guard_auto_unlock='$tx_auto' WHERE vendor_id='$vendor_id'");
    }

    header("Location: BruteForceSecurity.php");
    exit();
}

if (isset($_POST["unblock-ip"])) {
    $ip = mysqli_real_escape_string($connection_server, $_POST["ip_to_unblock"]);
    mysqli_query($connection_server, "DELETE FROM sas_blocked_ips WHERE ip_address='$ip' AND vendor_id='".$get_logged_admin_details["id"]."'");
    // logic to automatically whitelist an IP address upon unblocking
    mysqli_query($connection_server, "INSERT INTO sas_ip_whitelist (ip_address, vendor_id, success_count) VALUES ('$ip', '".$get_logged_admin_details["id"]."', 5) ON DUPLICATE KEY UPDATE success_count = GREATEST(success_count, 5)");
    $_SESSION["product_purchase_response"] = "IP Unblocked and Trusted.";
    header("Location: BruteForceSecurity.php");
    exit();
}

if (isset($_POST["unblock-account"])) {
    $user = mysqli_real_escape_string($connection_server, $_POST["acc_to_unblock"]);
    mysqli_query($connection_server, "DELETE FROM sas_blocked_ips WHERE ip_address IN (SELECT ip_address FROM sas_login_attempts WHERE username='$user' AND vendor_id='".$get_logged_admin_details["id"]."') AND vendor_id='".$get_logged_admin_details["id"]."'");
    mysqli_query($connection_server, "DELETE FROM sas_blocked_accounts WHERE username='$user' AND vendor_id='".$get_logged_admin_details["id"]."'");
    // Also restore user status if it was locked (status 2)
    mysqli_query($connection_server, "UPDATE sas_users SET status=1, is_blocked=0, failed_login_count=0, failed_pin_count=0 WHERE username='$user' AND vendor_id='".$get_logged_admin_details["id"]."'");
    // API status remains 2 (Disabled) until manual admin action

    // Mark unblock requests as approved
    mysqli_query($connection_server, "UPDATE sas_unblock_requests SET status='approved' WHERE username='$user' AND vendor_id='".$get_logged_admin_details["id"]."' AND status='pending'");

    // GFTAL: also lift any failed-transaction lock. Pass the RAW posted username - $user is already
    // mysqli-escaped above, and escaping it a second time would corrupt names containing a quote.
    if (function_exists('bc_tx_unlock_user')) {
        bc_tx_unlock_user((int)$get_logged_admin_details["id"], trim((string)($_POST["acc_to_unblock"] ?? '')), (int)$get_logged_admin_details["id"], 'admin-unblock');
    }

    // Check if there was an IP associated with this account block
    $ip_q = mysqli_query($connection_server, "SELECT ip_address FROM sas_login_attempts WHERE username='$user' AND vendor_id='".$get_logged_admin_details["id"]."' ORDER BY timestamp DESC LIMIT 1");
    if($ip_row = mysqli_fetch_assoc($ip_q)){
        $ip = $ip_row['ip_address'];
        mysqli_query($connection_server, "INSERT INTO sas_ip_whitelist (ip_address, vendor_id, success_count) VALUES ('$ip', '".$get_logged_admin_details["id"]."', 5) ON DUPLICATE KEY UPDATE success_count = GREATEST(success_count, 5)");
    }

    $_SESSION["product_purchase_response"] = "Account Restored and Identity Trusted.";
    header("Location: BruteForceSecurity.php");
    exit();
}

if (isset($_POST["blacklist-country"])) {
    $code = mysqli_real_escape_string($connection_server, $_POST["country_code"]);
    $status = mysqli_real_escape_string($connection_server, $_POST["country_status"]);
    mysqli_query($connection_server, "INSERT INTO sas_country_security (country_code, vendor_id, status) VALUES ('$code', '".$get_logged_admin_details["id"]."', '$status') ON DUPLICATE KEY UPDATE status=VALUES(status)");
    $_SESSION["product_purchase_response"] = "Country policy updated.";
    header("Location: BruteForceSecurity.php");
    exit();
}

// Ensure table exists
mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_unblock_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vendor_id INT,
    username VARCHAR(255),
    ip_address VARCHAR(255),
    reason TEXT,
    status VARCHAR(50) DEFAULT 'pending',
    date TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$settings = getBruteForceSettings($get_logged_admin_details["id"]);
// Users currently locked out of transacting by the failed-transaction guard.
$tx_locked_users = function_exists('bc_tx_locked_users') ? bc_tx_locked_users((int)$get_logged_admin_details["id"]) : [];
$countries = [
    "AF" => "Afghanistan", "AL" => "Albania", "DZ" => "Algeria", "AS" => "American Samoa", "AD" => "Andorra", "AO" => "Angola", "AI" => "Anguilla", "AQ" => "Antarctica", "AG" => "Antigua and Barbuda", "AR" => "Argentina", "AM" => "Armenia", "AW" => "Aruba", "AU" => "Australia", "AT" => "Austria", "AZ" => "Azerbaijan",
    "BS" => "Bahamas", "BH" => "Bahrain", "BD" => "Bangladesh", "BB" => "Barbados", "BY" => "Belarus", "BE" => "Belgium", "BZ" => "Belize", "BJ" => "Benin", "BM" => "Bermuda", "BT" => "Bhutan", "BO" => "Bolivia", "BA" => "Bosnia and Herzegovina", "BW" => "Botswana", "BR" => "Brazil", "IO" => "British Indian Ocean Territory", "BN" => "Brunei Darussalam", "BG" => "Bulgaria", "BF" => "Burkina Faso", "BI" => "Burundi",
    "KH" => "Cambodia", "CM" => "Cameroon", "CA" => "Canada", "CV" => "Cape Verde", "KY" => "Cayman Islands", "CF" => "Central African Republic", "TD" => "Chad", "CL" => "Chile", "CN" => "China", "CX" => "Christmas Island", "CC" => "Cocos (Keeling) Islands", "CO" => "Colombia", "KM" => "Comoros", "CG" => "Congo", "CD" => "Congo, The Democratic Republic of the", "CK" => "Cook Islands", "CR" => "Costa Rica", "CI" => "Cote D'Ivoire", "HR" => "Croatia", "CU" => "Cuba", "CY" => "Cyprus", "CZ" => "Czech Republic",
    "DK" => "Denmark", "DJ" => "Djibouti", "DM" => "Dominica", "DO" => "Dominican Republic", "EC" => "Ecuador", "EG" => "Egypt", "SV" => "El Salvador", "GQ" => "Equatorial Guinea", "ER" => "Eritrea", "EE" => "Estonia", "ET" => "Ethiopia", "FK" => "Falkland Islands (Malvinas)", "FO" => "Faroe Islands", "FJ" => "Fiji", "FI" => "Finland", "FR" => "France", "GF" => "French Guiana", "PF" => "French Polynesia", "TF" => "French Southern Territories",
    "GA" => "Gabon", "GM" => "Gambia", "GE" => "Georgia", "DE" => "Germany", "GH" => "Ghana", "GI" => "Gibraltar", "GR" => "Greece", "GL" => "Greenland", "GD" => "Grenada", "GP" => "Guadeloupe", "GU" => "Guam", "GT" => "Guatemala", "GN" => "Guinea", "GW" => "Guinea-Bissau", "GY" => "Guyana", "HT" => "Haiti", "HM" => "Heard Island and Mcdonald Islands", "VA" => "Holy See (Vatican City State)", "HN" => "Honduras", "HK" => "Hong Kong", "HU" => "Hungary",
    "IS" => "Iceland", "IN" => "India", "ID" => "Indonesia", "IR" => "Iran, Islamic Republic of", "IQ" => "Iraq", "IE" => "Ireland", "IL" => "Israel", "IT" => "Italy", "JM" => "Jamaica", "JP" => "Japan", "JO" => "Jordan", "KZ" => "Kazakhstan", "KE" => "Kenya", "KI" => "Kiribati", "KP" => "Korea, Democratic People's Republic of", "KR" => "Korea, Republic of", "KW" => "Kuwait", "KG" => "Kyrgyzstan", "LA" => "Lao People's Democratic Republic", "LV" => "Latvia", "LB" => "Lebanon", "LS" => "Lesotho", "LR" => "Liberia", "LY" => "Libyan Arab Jamahiriya", "LI" => "Liechtenstein", "LT" => "Lithuania", "LU" => "Luxembourg",
    "MO" => "Macao", "MK" => "Macedonia, The Former Yugoslav Republic of", "MG" => "Madagascar", "MW" => "Malawi", "MY" => "Malaysia", "MV" => "Maldives", "ML" => "Mali", "MT" => "Malta", "MH" => "Marshall Islands", "MQ" => "Martinique", "MR" => "Mauritania", "MU" => "Mauritius", "YT" => "Mayotte", "MX" => "Mexico", "FM" => "Micronesia, Federated States of", "MD" => "Moldova, Republic of", "MC" => "Monaco", "MN" => "Mongolia", "MS" => "Montserrat", "MA" => "Morocco", "MZ" => "Mozambique", "MM" => "Myanmar",
    "NA" => "Namibia", "NR" => "Nauru", "NP" => "Nepal", "NL" => "Netherlands", "AN" => "Netherlands Antilles", "NC" => "New Caledonia", "NZ" => "New Zealand", "NI" => "Nicaragua", "NE" => "Niger", "NG" => "Nigeria", "NU" => "Niue", "NF" => "Norfolk Island", "MP" => "Northern Mariana Islands", "NO" => "Norway", "OM" => "Oman", "PK" => "Pakistan", "PW" => "Palau", "PS" => "Palestinian Territory, Occupied", "PA" => "Panama", "PG" => "Papua New Guinea", "PY" => "Paraguay", "PE" => "Peru", "PH" => "Philippines", "PN" => "Pitcairn", "PL" => "Poland", "PT" => "Portugal", "PR" => "Puerto Rico", "QA" => "Qatar",
    "RE" => "Reunion", "RO" => "Romania", "RU" => "Russian Federation", "RW" => "Rwanda", "SH" => "Saint Helena", "KN" => "Saint Kitts and Nevis", "LC" => "Saint Lucia", "PM" => "Saint Pierre and Miquelon", "VC" => "Saint Vincent and the Grenadines", "WS" => "Samoa", "SM" => "San Marino", "ST" => "Sao Tome and Principe", "SA" => "Saudi Arabia", "SN" => "Senegal", "CS" => "Serbia and Montenegro", "SC" => "Seychelles", "SL" => "Sierra Leone", "SG" => "Singapore", "SK" => "Slovakia", "SI" => "Slovenia", "SB" => "Solomon Islands", "SO" => "Somalia", "ZA" => "South Africa", "GS" => "South Georgia and the South Sandwich Islands", "ES" => "Spain", "LK" => "Sri Lanka", "SD" => "Sudan", "SR" => "Suriname", "SJ" => "Svalbard and Jan Mayen", "SZ" => "Swaziland", "SE" => "Sweden", "CH" => "Switzerland", "SY" => "Syrian Arab Republic",
    "TW" => "Taiwan, Province of China", "TJ" => "Tajikistan", "TZ" => "Tanzania, United Republic of", "TH" => "Thailand", "TL" => "Timor-Leste", "TG" => "Togo", "TK" => "Tokelau", "TO" => "Tonga", "TT" => "Trinidad and Barbuda", "TN" => "Tunisia", "TR" => "Turkey", "TM" => "Turkmenistan", "TC" => "Turks and Caicos Islands", "TV" => "Tuvalu", "UG" => "Uganda", "UA" => "Ukraine", "AE" => "United Arab Emirates", "GB" => "United Kingdom", "US" => "United States", "UM" => "United States Minor Outlying Islands", "UY" => "Uruguay", "UZ" => "Uzbekistan",
    "VU" => "Vanuatu", "VE" => "Venezuela", "VN" => "Viet Nam", "VG" => "Virgin Islands, British", "VI" => "Virgin Islands, U.S.", "WF" => "Wallis and Futuna", "EH" => "Western Sahara", "YE" => "Yemen", "ZM" => "Zambia", "ZW" => "Zimbabwe"
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Brute Force Security | <?php echo $get_all_super_admin_site_details["site_title"]; ?></title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
    <link rel="stylesheet" href="/cssfile/bc-style.css">
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/css/style.css" rel="stylesheet">
</head>
<body>
    <?php include("../func/bc-admin-header.php"); ?>
    <div class="pagetitle">
        <h1>BRUTE FORCE SECURITY</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="Dashboard.php">Home</a></li>
                <li class="breadcrumb-item active">Brute Force</li>
            </ol>
        </nav>
    </div>

    <section class="section dashboard">
        <div class="row g-4">
            <div class="col-lg-6">
                <div class="card shadow-sm border-0 rounded-4 h-100">
                    <div class="card-header bg-white py-3 border-0 d-flex align-items-center">
                        <div class="bg-primary bg-opacity-10 p-2 rounded-3 me-3 shadow-sm"><i class="bi bi-shield-lock text-dark-primary"></i></div>
                        <h5 class="fw-bold mb-0">Security Thresholds</h5>
                    </div>
                    <div class="card-body p-4">
                        <form method="post">
                            <div class="bg-primary bg-opacity-10 p-3 rounded-4 mb-4 border border-primary border-opacity-25">
                                <div class="form-check form-switch d-flex align-items-center justify-content-between ps-0">
                                    <div>
                                        <label class="form-check-label fw-bold h6 mb-0 text-dark-primary" for="isEnabled">Brute Force Protection</label>
                                        <p class="text-dark-primary small mb-0" style="opacity: 0.8;">Enable or disable global brute force security monitoring.</p>
                                    </div>
                                    <input type="checkbox" name="is_enabled" class="form-check-input ms-0" id="isEnabled" style="width: 3rem; height: 1.5rem;" <?php echo $settings['is_enabled'] ? 'checked' : ''; ?>>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-bold text-muted text-uppercase">Protection Period (Minutes)</label>
                                <input type="number" name="period_mins" class="form-control rounded-3" value="<?php echo $settings['period_mins']; ?>" required>
                            </div>
                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold text-muted text-uppercase">Max Failures / Account</label>
                                    <input type="number" name="max_failures_account" class="form-control rounded-3" value="<?php echo $settings['max_failures_account']; ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold text-muted text-uppercase">Max Failures / IP</label>
                                    <input type="number" name="max_failures_ip" class="form-control rounded-3" value="<?php echo $settings['max_failures_ip']; ?>" required>
                                </div>
                            </div>
                            <div class="mb-4">
                                <label class="form-label small fw-bold text-muted text-uppercase">Block Duration</label>
                                <select name="block_duration" class="form-select rounded-3">
                                    <option value="one-day" <?php echo $settings['block_duration'] == 'one-day' ? 'selected' : ''; ?>>One Day</option>
                                    <option value="one-week" <?php echo $settings['block_duration'] == 'one-week' ? 'selected' : ''; ?>>One Week</option>
                                    <option value="one-month" <?php echo $settings['block_duration'] == 'one-month' ? 'selected' : ''; ?>>One Month</option>
                                    <option value="one-year" <?php echo $settings['block_duration'] == 'one-year' ? 'selected' : ''; ?>>One Year</option>
                                </select>
                            </div>
                            <div class="bg-light p-3 rounded-4 mb-4">
                                <div class="form-check form-switch mb-2">
                                    <input type="checkbox" name="lock_admin" class="form-check-input" id="lockAdmin" <?php echo $settings['lock_admin'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label fw-bold small" for="lockAdmin">Allow locking admin/administrator</label>
                                </div>
                                <div class="form-check form-switch">
                                    <input type="checkbox" name="notify_admin" class="form-check-input" id="notifyAdmin" <?php echo $settings['notify_admin'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label fw-bold small" for="notifyAdmin">Send brute force notifications</label>
                                </div>
                            </div>
                            <hr class="my-4">
                            <h6 class="fw-bold mb-1"><i class="bi bi-shield-exclamation me-2 text-danger"></i>Failed Transaction Guard</h6>
                            <p class="small text-muted">Locks a user out of <b>new transactions only</b> after repeated failures they caused themselves. Provider-side failures (out of stock, upstream down) are never counted, the count resets on any success, and signing in is never blocked.</p>
                            <div class="bg-light p-3 rounded-4 mb-4">
                                <div class="form-check form-switch mb-2">
                                    <input type="checkbox" name="tx_guard_enabled" class="form-check-input" id="txGuardEnabled" <?php echo !empty($settings['tx_guard_enabled']) ? 'checked' : ''; ?>>
                                    <label class="form-check-label fw-bold small" for="txGuardEnabled">Enable failed transaction guard</label>
                                </div>
                                <div class="form-check form-switch mb-2">
                                    <input type="checkbox" name="tx_guard_notify_admin" class="form-check-input" id="txGuardNotify" <?php echo !empty($settings['tx_guard_notify_admin']) ? 'checked' : ''; ?>>
                                    <label class="form-check-label fw-bold small" for="txGuardNotify">Email me when a user is locked</label>
                                </div>
                                <div class="form-check form-switch">
                                    <input type="checkbox" name="tx_guard_auto_unlock" class="form-check-input" id="txGuardAuto" <?php echo !empty($settings['tx_guard_auto_unlock']) ? 'checked' : ''; ?>>
                                    <label class="form-check-label fw-bold small" for="txGuardAuto">Unlock automatically when the window expires (recommended)</label>
                                </div>
                            </div>
                            <div class="row g-3 mb-4">
                                <div class="col-md-4">
                                    <label class="form-label small fw-bold text-muted text-uppercase">Consecutive Failures</label>
                                    <input type="number" min="1" name="tx_guard_max_failures" class="form-control rounded-3" value="<?php echo (int)($settings['tx_guard_max_failures'] ?? 5); ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small fw-bold text-muted text-uppercase">Window (Hours)</label>
                                    <input type="number" min="1" name="tx_guard_window_hours" class="form-control rounded-3" value="<?php echo (int)($settings['tx_guard_window_hours'] ?? 24); ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small fw-bold text-muted text-uppercase">Lock Scope</label>
                                    <select name="tx_guard_scope" class="form-select rounded-3">
                                        <option value="all" <?php echo ($settings['tx_guard_scope'] ?? 'all') === 'all' ? 'selected' : ''; ?>>All Services</option>
                                        <option value="per-service" <?php echo ($settings['tx_guard_scope'] ?? 'all') === 'per-service' ? 'selected' : ''; ?>>Only The Failing Service</option>
                                    </select>
                                </div>
                            </div>
                            <button type="submit" name="update-settings" class="btn btn-primary w-100 rounded-pill fw-bold shadow-sm py-2">Update Security Policy</button>
                        </form>

                        <hr class="my-4">
                        <h6 class="fw-bold mb-3"><i class="bi bi-lock-fill me-2 text-danger"></i>Locked Transaction Access (<?php echo count($tx_locked_users); ?>)</h6>
                        <?php if (empty($tx_locked_users)): ?>
                            <p class="small text-muted mb-0">No account is currently locked out of transacting.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead class="bg-light">
                                        <tr class="small text-uppercase"><th>User</th><th>Failures</th><th>Scope</th><th>Until</th><th class="text-end">Action</th></tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($tx_locked_users as $lu): ?>
                                        <tr>
                                            <td class="small">
                                                <b><?php echo htmlspecialchars($lu['username']); ?></b><br>
                                                <span class="text-muted"><?php echo htmlspecialchars(trim(($lu['firstname'] ?? '') . ' ' . ($lu['lastname'] ?? ''))); ?></span>
                                            </td>
                                            <td class="small"><?php echo (int)$lu['tx_fail_streak']; ?></td>
                                            <td class="small"><?php echo htmlspecialchars($lu['tx_lock_service'] ?: 'All services'); ?></td>
                                            <td class="small"><?php echo !empty($lu['tx_lock_until']) ? htmlspecialchars(date('d M Y H:i', strtotime($lu['tx_lock_until']))) : 'Manual'; ?></td>
                                            <td class="text-end">
                                                <form method="post" class="d-inline">
                                                    <input type="hidden" name="acc_to_unblock" value="<?php echo htmlspecialchars($lu['username']); ?>">
                                                    <button type="submit" name="unblock-account" class="btn btn-sm btn-outline-danger rounded-pill fw-bold" onclick="return confirm('Restore transaction access for this account?');">Unlock</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card shadow-sm border-0 rounded-4 h-100">
                    <div class="card-header bg-white py-3 border-0 d-flex align-items-center">
                        <div class="bg-info bg-opacity-10 p-2 rounded-3 me-3"><i class="bi bi-globe text-info"></i></div>
                        <h5 class="fw-bold mb-0">Geo-IP Security</h5>
                    </div>
                    <div class="card-body p-4">
                        <div class="mb-3">
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0"><i class="bi bi-search"></i></span>
                                <input type="text" id="countrySearch" class="form-control border-start-0 ps-0 rounded-end-3" placeholder="Search countries...">
                            </div>
                        </div>
                        <div class="table-responsive" style="max-height: 420px; border-radius: 12px; border: 1px solid #eee;">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="bg-light sticky-top">
                                    <tr class="small text-uppercase"><th>Country</th><th>Status</th><th class="text-end">Action</th></tr>
                                </thead>
                                <tbody id="countryTableBody">
                                    <?php
                                    $get_policies = mysqli_query($connection_server, "SELECT * FROM sas_country_security WHERE vendor_id='".$get_logged_admin_details["id"]."'");
                                    $policies = [];
                                    while($p = mysqli_fetch_assoc($get_policies)) $policies[$p['country_code']] = $p['status'];

                                    foreach ($countries as $code => $name) {
                                        $current = $policies[$code] ?? 'Not Specified';
                                        $badge = 'secondary';
                                        if($current == 'Whitelisted') $badge = 'success';
                                        if($current == 'Blacklisted') $badge = 'danger';
                                        echo "<tr>
                                            <td class='fw-bold small'>$name</td>
                                            <td><span class='badge bg-$badge bg-opacity-10 text-$badge rounded-pill'>$current</span></td>
                                            <td class='text-end'>
                                                <form method='post' class='d-flex justify-content-end'>
                                                    <input type='hidden' name='country_code' value='$code'>
                                                    <select name='country_status' class='form-select form-select-sm rounded-pill w-auto' style='font-size: 10px;' onchange='this.form.submit()'>
                                                        <option value='Not Specified' ".($current == 'Not Specified' ? 'selected' : '').">Default</option>
                                                        <option value='Whitelisted' ".($current == 'Whitelisted' ? 'selected' : '').">Whitelist</option>
                                                        <option value='Blacklisted' ".($current == 'Blacklisted' ? 'selected' : '').">Blacklist</option>
                                                    </select>
                                                    <input type='hidden' name='blacklist-country' value='1'>
                                                </form>
                                            </td>
                                        </tr>";
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mt-2">
            <div class="col-lg-12">
                <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
                    <div class="card-header bg-white py-3 border-0">
                        <h5 class="fw-bold mb-0">Active Restrictions</h5>
                    </div>
                    <div class="card-body p-0">
                        <ul class="nav nav-tabs nav-tabs-bordered d-flex" id="securityTabs" role="tablist">
                            <li class="nav-item flex-fill" role="presentation"><button class="nav-link w-100 active fw-bold" data-bs-toggle="tab" data-bs-target="#blockedIPs">Blocked IPs</button></li>
                            <li class="nav-item flex-fill" role="presentation"><button class="nav-link w-100 fw-bold" data-bs-toggle="tab" data-bs-target="#lockedAccounts">Locked Accounts</button></li>
                            <li class="nav-item flex-fill" role="presentation"><button class="nav-link w-100 fw-bold" data-bs-toggle="tab" data-bs-target="#unblockRequests">Unblock Requests</button></li>
                            <li class="nav-item flex-fill" role="presentation"><button class="nav-link w-100 fw-bold" data-bs-toggle="tab" data-bs-target="#whitelistedIPs">Trust-List (IP)</button></li>
                        </ul>
                        <div class="tab-content p-4">
                            <div class="tab-pane fade show active" id="blockedIPs">
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle">
                                        <thead class="bg-light"><tr class="small text-uppercase"><th>IP Address</th><th>Expiry</th><th>Reason</th><th class="text-end">Action</th></tr></thead>
                                        <tbody>
                                            <?php
                                            $get_blocked = mysqli_query($connection_server, "SELECT * FROM sas_blocked_ips WHERE vendor_id='".$get_logged_admin_details["id"]."'");
                                            if(mysqli_num_rows($get_blocked) > 0){
                                                while($row = mysqli_fetch_assoc($get_blocked)){
                                                    echo "<tr><td class='fw-bold'>{$row['ip_address']}</td><td class='small text-muted'>{$row['block_until']}</td><td class='small'>{$row['reason']}</td>
                                                    <td class='text-end'><form method='post'><input type='hidden' name='ip_to_unblock' value='{$row['ip_address']}'><button type='submit' name='unblock-ip' class='btn btn-outline-danger btn-sm rounded-pill px-3'>Unblock</button></form></td></tr>";
                                                }
                                            } else { echo '<tr><td colspan="4" class="text-center py-4 text-muted small">No IPs currently blocked</td></tr>'; }
                                            ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <div class="tab-pane fade" id="lockedAccounts">
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle">
                                        <thead class="bg-light"><tr class="small text-uppercase"><th>Username</th><th>Expiry</th><th>Reason</th><th class="text-end">Action</th></tr></thead>
                                        <tbody>
                                            <?php
                                            $get_locked = mysqli_query($connection_server, "SELECT * FROM sas_blocked_accounts WHERE vendor_id='".$get_logged_admin_details["id"]."'");
                                            if(mysqli_num_rows($get_locked) > 0){
                                                while($row = mysqli_fetch_assoc($get_locked)){
                                                    echo "<tr><td class='fw-bold'>@{$row['username']}</td><td class='small text-muted'>{$row['block_until']}</td><td class='small'>{$row['reason']}</td>
                                                    <td class='text-end'><form method='post'><input type='hidden' name='acc_to_unblock' value='{$row['username']}'><button type='submit' name='unblock-account' class='btn btn-outline-danger btn-sm rounded-pill px-3'>Unlock Account</button></form></td></tr>";
                                                }
                                            } else { echo '<tr><td colspan="4" class="text-center py-4 text-muted small">No accounts currently locked</td></tr>'; }
                                            ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <div class="tab-pane fade" id="unblockRequests">
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle">
                                        <thead class="bg-light"><tr class="small text-uppercase"><th>Identity</th><th>Reason</th><th>Date</th><th class="text-end">Action</th></tr></thead>
                                        <tbody>
                                            <?php
                                            $get_reqs = mysqli_query($connection_server, "SELECT * FROM sas_unblock_requests WHERE vendor_id='".$get_logged_admin_details["id"]."' AND status='pending' AND username NOT LIKE '%@%'");
                                            if(mysqli_num_rows($get_reqs) > 0){
                                                while($row = mysqli_fetch_assoc($get_reqs)){
                                                    echo "<tr><td class='fw-bold'>@{$row['username']} ({$row['ip_address']})</td><td class='small'>{$row['reason']}</td><td class='small text-muted'>{$row['date']}</td>
                                                    <td class='text-end'><form method='post'><input type='hidden' name='acc_to_unblock' value='{$row['username']}'><button type='submit' name='unblock-account' class='btn btn-success btn-sm rounded-pill px-3'>Approve & Unblock</button></form></td></tr>";
                                                }
                                            } else { echo '<tr><td colspan="4" class="text-center py-4 text-muted small">No pending user unblock requests</td></tr>'; }
                                            ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <div class="tab-pane fade" id="whitelistedIPs">
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle">
                                        <thead class="bg-light"><tr class="small text-uppercase"><th>IP Address</th><th>Successful Logins</th><th>Level</th></tr></thead>
                                        <tbody>
                                            <?php
                                            $get_white = mysqli_query($connection_server, "SELECT * FROM sas_ip_whitelist WHERE vendor_id='".$get_logged_admin_details["id"]."'");
                                            if(mysqli_num_rows($get_white) > 0){
                                                while($row = mysqli_fetch_assoc($get_white)){
                                                    $lvl = $row['success_count'] >= 5 ? '<span class="badge bg-success bg-opacity-10 text-success rounded-pill px-3">Trusted Device</span>' : '<span class="badge bg-warning bg-opacity-10 text-warning rounded-pill px-3">Learning...</span>';
                                                    echo "<tr><td class='fw-bold'>{$row['ip_address']}</td><td>{$row['success_count']}</td><td>$lvl</td></tr>";
                                                }
                                            } else { echo '<tr><td colspan="3" class="text-center py-4 text-muted small">Whitelist learning database empty</td></tr>'; }
                                            ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mt-2">
            <div class="col-12">
                <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
                    <div class="card-header bg-white py-4 border-0">
                        <h5 class="fw-bold mb-0 text-primary">Live Access Logs</h5>
                        <p class="text-muted small mb-0">Real-time monitoring of system login attempts</p>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="bg-light">
                                    <tr class="small text-uppercase text-muted"><th>User Identity</th><th>Source IP</th><th>Outcome</th><th class="text-end pe-4">Timestamp</th></tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $get_logs = mysqli_query($connection_server, "SELECT l.*, w.success_count FROM sas_login_attempts l LEFT JOIN sas_ip_whitelist w ON l.ip_address = w.ip_address AND w.vendor_id='".$get_logged_admin_details["id"]."' WHERE l.vendor_id='".$get_logged_admin_details["id"]."' ORDER BY timestamp DESC LIMIT 30");
                                    while($row = mysqli_fetch_assoc($get_logs)){
                                        $res = $row['success'] ? '<span class="badge bg-success bg-opacity-10 text-success rounded-pill px-3">Authorized</span>' : '<span class="badge bg-danger bg-opacity-10 text-danger rounded-pill px-3">Failed Attempt</span>';
                                        $ip_icon = $row['success_count'] >= 5 ? '<i class="bi bi-shield-fill-check text-success me-1" title="Trusted Source"></i> ' : '<i class="bi bi-question-circle text-muted me-1"></i> ';
                                        echo "<tr>
                                            <td class='fw-bold'>@{$row['username']}</td>
                                            <td>$ip_icon{$row['ip_address']}</td>
                                            <td>$res</td>
                                            <td class='text-end pe-4 small text-muted'>".date('M d, H:i:s', strtotime($row['timestamp']))."</td>
                                        </tr>";
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <script>
        document.getElementById('countrySearch').addEventListener('input', function(e) {
            let term = e.target.value.toLowerCase();
            let rows = document.querySelectorAll('#countryTableBody tr');
            rows.forEach(row => {
                let name = row.cells[0].textContent.toLowerCase();
                row.style.display = name.includes(term) ? '' : 'none';
            });
        });
    </script>
    <?php include("../func/bc-admin-footer.php"); ?>
</body>
</html>

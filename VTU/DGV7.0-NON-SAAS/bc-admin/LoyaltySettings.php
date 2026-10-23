<?php
session_start();
include("../func/bc-admin-config.php");

// Migration: Create table if not exists
mysqli_query($connection_server, "CREATE TABLE IF NOT EXISTS sas_loyalty_bonus_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vendor_id INT NOT NULL UNIQUE,
    day_1_bonus INT DEFAULT 0,
    day_2_bonus INT DEFAULT 0,
    day_3_bonus INT DEFAULT 0,
    day_4_bonus INT DEFAULT 0,
    day_5_bonus INT DEFAULT 0,
    day_6_bonus INT DEFAULT 0,
    day_7_bonus INT DEFAULT 0,
    first_purchase_bonus INT DEFAULT 0
)");

// Migration: Add first_purchase_bonus column if missing
$check_col = mysqli_query($connection_server, "SHOW COLUMNS FROM `sas_loyalty_bonus_settings` LIKE 'first_purchase_bonus'");
if ($check_col && mysqli_num_rows($check_col) == 0) {
    mysqli_query($connection_server, "ALTER TABLE sas_loyalty_bonus_settings ADD COLUMN first_purchase_bonus INT DEFAULT 0");
}

// Ensure row exists first
$vendor_id = $get_logged_admin_details["id"];
$check_exists = mysqli_query($connection_server, "SELECT id FROM sas_loyalty_bonus_settings WHERE vendor_id = '$vendor_id'");
if ($check_exists && mysqli_num_rows($check_exists) == 0) {
    mysqli_query($connection_server, "INSERT INTO sas_loyalty_bonus_settings (vendor_id) VALUES ('$vendor_id')");
}

// Handle form submission to update loyalty settings
if (isset($_POST["update-loyalty-settings"])) {
    // Update daily bonus amounts in the wide table format
    $day1 = (int)$_POST["bonus_day_1"];
    $day2 = (int)$_POST["bonus_day_2"];
    $day3 = (int)$_POST["bonus_day_3"];
    $day4 = (int)$_POST["bonus_day_4"];
    $day5 = (int)$_POST["bonus_day_5"];
    $day6 = (int)$_POST["bonus_day_6"];
    $day7 = (int)$_POST["bonus_day_7"];
    $first_purchase_bonus = (int)$_POST["first_purchase_bonus"];

    $query_bonus = "INSERT INTO sas_loyalty_bonus_settings (vendor_id, day_1_bonus, day_2_bonus, day_3_bonus, day_4_bonus, day_5_bonus, day_6_bonus, day_7_bonus, first_purchase_bonus)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                    day_1_bonus = VALUES(day_1_bonus),
                    day_2_bonus = VALUES(day_2_bonus),
                    day_3_bonus = VALUES(day_3_bonus),
                    day_4_bonus = VALUES(day_4_bonus),
                    day_5_bonus = VALUES(day_5_bonus),
                    day_6_bonus = VALUES(day_6_bonus),
                    day_7_bonus = VALUES(day_7_bonus),
                    first_purchase_bonus = VALUES(first_purchase_bonus)";

    $stmt_bonus = mysqli_prepare($connection_server, $query_bonus);
    mysqli_stmt_bind_param($stmt_bonus, "iiiiiiiii", $vendor_id, $day1, $day2, $day3, $day4, $day5, $day6, $day7, $first_purchase_bonus);
    mysqli_stmt_execute($stmt_bonus);

    // Update conversion rate and minimum threshold in the key-value settings table
    $conversion_rate = mysqli_real_escape_string($connection_server, $_POST["conversion_rate"]);
    $min_conversion_threshold = mysqli_real_escape_string($connection_server, $_POST["min_conversion_threshold"]);
    $coins_email_threshold = (int)mysqli_real_escape_string($connection_server, $_POST["coins_email_threshold"] ?? 0);

    $settings_to_update = [
        'points_conversion_rate' => $conversion_rate,
        'min_points_conversion' => $min_conversion_threshold,
        'coins_email_threshold' => $coins_email_threshold,
    ];

    foreach ($settings_to_update as $name => $value) {
        $query_settings = "INSERT INTO sas_settings (vendor_id, setting_name, setting_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = ?";
        $stmt_settings = mysqli_prepare($connection_server, $query_settings);
        mysqli_stmt_bind_param($stmt_settings, "isss", $vendor_id, $name, $value, $value);
        mysqli_stmt_execute($stmt_settings);
    }

    $_SESSION["product_purchase_response"] = "Loyalty settings updated successfully!";
    header("Location: " . $_SERVER["REQUEST_URI"]);
    exit();
}

// Fetch current loyalty settings
$stmt = mysqli_prepare($connection_server, "SELECT * FROM sas_loyalty_bonus_settings WHERE vendor_id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, "i", $vendor_id);
mysqli_stmt_execute($stmt);
$loyalty_settings_row = mysqli_stmt_get_result($stmt);
$loyalty_settings = mysqli_fetch_assoc($loyalty_settings_row);

// Fetch conversion rate and minimum threshold from sas_settings
$stmt = mysqli_prepare($connection_server, "SELECT * FROM sas_settings WHERE vendor_id = ? AND setting_name IN ('points_conversion_rate', 'min_points_conversion', 'coins_email_threshold')");
mysqli_stmt_bind_param($stmt, "i", $vendor_id);
mysqli_stmt_execute($stmt);
$settings_query = mysqli_stmt_get_result($stmt);
$settings = [];
while($row = mysqli_fetch_assoc($settings_query)){
    $settings[$row['setting_name']] = $row['setting_value'];
}
$points_conversion_rate = $settings['points_conversion_rate'] ?? 500;
$min_points_conversion = $settings['min_points_conversion'] ?? 100;
$coins_email_threshold = $settings['coins_email_threshold'] ?? 0;

?>
<!DOCTYPE html>
<html>
<head>
    <title>Loyalty Settings | <?php echo $get_all_super_admin_site_details["site_title"]; ?></title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <link rel="stylesheet" href="<?php echo $css_style_template_location; ?>">
    <link rel="stylesheet" href="/cssfile/bc-style.css">
    <!-- Vendor CSS Files -->
    <link href="../assets-2/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets-2/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
    <link href="../assets-2/vendor/remixicon/remixicon.css" rel="stylesheet">
    <!-- Template Main CSS File -->
    <link href="../assets-2/css/style.css" rel="stylesheet">
</head>
<body>
    <?php include("../func/bc-admin-header.php"); ?>

    <div class="pagetitle">
        <h1>Loyalty Settings</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="#">Home</a></li>
                <li class="breadcrumb-item active">Loyalty Settings</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
        <div class="row g-4">
            <div class="col-lg-8">
                <div class="card shadow-sm border-0 rounded-4 mb-4">
                    <div class="card-header bg-white py-3 border-0 d-flex align-items-center">
                        <div class="bg-primary bg-opacity-10 p-2 rounded-3 me-3"><i class="bi bi-gift text-dark-primary"></i></div>
                        <h5 class="fw-bold mb-0">Daily Streak Bonuses</h5>
                    </div>
                    <div class="card-body p-4">
                        <form method="post">
                            <div class="row g-3">
                                <?php for ($day = 1; $day <= 7; $day++) : ?>
                                    <div class="col-md-4 col-6">
                                        <div class="p-3 border rounded-4 text-center bg-light bg-opacity-50">
                                            <label for="bonus_day_<?php echo $day; ?>" class="form-label small fw-bold text-muted mb-2 text-uppercase">Day <?php echo $day; ?></label>
                                            <div class="input-group">
                                                <input type="number" class="form-control text-center fw-bold rounded-3" id="bonus_day_<?php echo $day; ?>" name="bonus_day_<?php echo $day; ?>" value="<?php echo $loyalty_settings['day_' . $day . '_bonus'] ?? 0; ?>" required>
                                                <span class="input-group-text bg-white border-start-0 small"><i class="bi bi-gem text-warning"></i></span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endfor; ?>
                            </div>
                    </div>
                </div>

                <div class="card shadow-sm border-0 rounded-4">
                    <div class="card-header bg-white py-3 border-0 d-flex align-items-center">
                        <div class="bg-info bg-opacity-10 p-2 rounded-3 me-3"><i class="bi bi-arrow-repeat text-info"></i></div>
                        <h5 class="fw-bold mb-0">Coin Conversion & Referrals</h5>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-4">
                            <div class="col-md-6">
                                <label for="conversion_rate" class="form-label small fw-bold text-muted text-uppercase">Conversion Rate</label>
                                <div class="input-group input-group-lg">
                                    <input type="number" step="any" class="form-control rounded-3" id="conversion_rate" name="conversion_rate" value="<?php echo $points_conversion_rate; ?>" required>
                                    <span class="input-group-text bg-light small">Pts/₦</span>
                                </div>
                                <p class="small text-muted mt-2">Number of points required for ₦1.00</p>
                            </div>
                            <div class="col-md-6">
                                <label for="min_conversion_threshold" class="form-label small fw-bold text-muted text-uppercase">Min. Threshold</label>
                                <input type="number" class="form-control form-control-lg rounded-3" id="min_conversion_threshold" name="min_conversion_threshold" value="<?php echo $min_points_conversion; ?>" required>
                                <p class="small text-muted mt-2">Minimum points allowed for withdrawal</p>
                            </div>
                            <div class="col-md-6">
                                <label for="coins_email_threshold" class="form-label small fw-bold text-muted text-uppercase">Email Alert Threshold</label>
                                <input type="number" class="form-control form-control-lg rounded-3" id="coins_email_threshold" name="coins_email_threshold" value="<?php echo $coins_email_threshold; ?>" min="0">
                                <p class="small text-muted mt-2">When a user's coins reach this amount, they automatically receive an email guide on converting to wallet funds. Set 0 to disable.</p>
                            </div>
                            <div class="col-12">
                                <hr class="my-2 opacity-25">
                            </div>
                            <div class="col-12">
                                <label for="first_purchase_bonus" class="form-label small fw-bold text-muted text-uppercase">Referral Sign-up Bonus</label>
                                <div class="input-group input-group-lg">
                                    <input type="number" class="form-control rounded-3" id="first_purchase_bonus" name="first_purchase_bonus" value="<?php echo $loyalty_settings['first_purchase_bonus'] ?? 100; ?>" required>
                                    <span class="input-group-text bg-light small"><i class="bi bi-gem text-warning me-1"></i> Coins</span>
                                </div>
                                <p class="small text-muted mt-2">Bonus awarded to referrer upon first successful purchase by referee</p>
                            </div>
                        </div>
                        <div class="mt-4">
                            <button name="update-loyalty-settings" type="submit" class="btn btn-primary btn-lg px-5 rounded-pill fw-bold shadow-sm">
                                <i class="bi bi-check2-circle me-2"></i> Save Reward Settings
                            </button>
                        </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card shadow-sm border-0 rounded-4 bg-dark text-white p-4">
                    <h6 class="fw-bold mb-3"><i class="bi bi-info-circle me-2"></i> Loyalty System Info</h6>
                    <p class="small opacity-75 mb-4">Loyalty points (VTU Coins) incentivize daily engagement and word-of-mouth marketing. Users earn daily bonuses for consecutive logins and special rewards for successful referrals.</p>
                    <div class="bg-white bg-opacity-10 p-3 rounded-4 border border-white border-opacity-10 mb-3">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="small opacity-75">Current Rate</span>
                            <span class="fw-bold small"><?php echo $points_conversion_rate; ?> Pts = ₦1.00</span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="small opacity-75">Min. Withdrawal</span>
                            <span class="fw-bold small"><?php echo $min_points_conversion; ?> Coins</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <?php include("../func/bc-admin-footer.php"); ?>
</body>
</html>
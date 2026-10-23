<?php
// PHP 8.1+ Compatibility Fix: Disable strict exception mode for MySQLi
mysqli_report(MYSQLI_REPORT_OFF);

session_start();

$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$error = '';
$success = '';

// If already installed, don't allow re-installation
if (file_exists('../func/db-json.php') && $step < 4) {
    // Verify connection to see if it's a valid installation
    include('../func/db-dtl.php');
    if (isset($mySqlServer)) { // Variables are exported by db-dtl.php
        $conn = @mysqli_connect($mySqlServer, $mySqlUser, $mySqlPass, $mySqlDBName);
        if ($conn) {
            $check_admin = @mysqli_query($conn, "SELECT id FROM sas_super_admin LIMIT 1");
            if ($check_admin && mysqli_num_rows($check_admin) > 0) {
                header("Location: /");
                exit;
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($step === 1) {
        $code = trim($_POST['activation_code'] ?? '');
        if (empty($code)) {
            $error = "Please enter your Activation Code.";
        } else {
            $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
            if (strpos($domain, ':') !== false) {
                $domain = explode(':', $domain)[0];
            }
            $ch = curl_init('https://manager.pmhserver.name.ng/api.php');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                'key' => $code,
                'domain' => $domain
            ]));
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response !== false && $http_code === 200) {
                $res = @json_decode($response, true);
                $res_status = $res['status'] ?? '';
                $is_valid = ($res_status === 'active' || $res_status === 'valid' || (int)$res_status === 1);
                if (isset($res['status']) && $is_valid) {
                    include_once('../func/bc-levelup.php');
                    if (bc_write_activation($code)) {
                        // Keep the validated license key/domain in the session so step 3 can
                        // persist it into sas_super_admin_options (the DB doesn't exist yet here).
                        $_SESSION['install_license_key'] = $code;
                        $_SESSION['install_license_domain'] = $domain;
                        header("Location: ?step=2");
                        exit;
                    } else {
                        $error = "Failed to write activation file. Check folder permissions.";
                    }
                } else {
                    $error = "Invalid activation code for this domain.";
                }
            } else {
                $error = "Unable to connect to the activation server. Please try again.";
            }
        }
    } elseif ($step === 2) {
        $host = $_POST['db_host'] ?? '';
        $name = $_POST['db_name'] ?? '';
        $user = $_POST['db_user'] ?? '';
        $pass = $_POST['db_pass'] ?? '';

        $conn = @mysqli_connect($host, $user, $pass, $name);
        if (!$conn) {
            $error = "Database connection failed: " . mysqli_connect_error();
        } else {
            $db_config = [
                "server" => $host,
                "user" => $user,
                "pass" => $pass,
                "dbname" => $name
            ];
            $json = "<?php\n\$db_json_decode = json_decode('" . json_encode($db_config) . "', true);\n?>";
            if (file_put_contents("../func/db-json.php", $json) === false) {
                $error = "Failed to write to func/db-json.php. Check folder permissions.";
            } else {
                // Initialize database schema
                $connection_server = $conn;
                try {
                    include("../func/bc-tables.php");
                } catch (\Throwable $t) {
                    error_log("Schema setup notice: " . $t->getMessage());
                }
                
                $_SESSION['install_db_host'] = $host;
                $_SESSION['install_db_name'] = $name;
                $_SESSION['install_db_user'] = $user;
                $_SESSION['install_db_pass'] = $pass;

                header("Location: ?step=3");
                exit;
            }
        }
    } elseif ($step === 3) {
        $fname = trim($_POST['firstname'] ?? '');
        $lname = trim($_POST['lastname'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $gender = trim($_POST['gender'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (empty($fname) || empty($lname) || empty($email) || empty($phone) || empty($password)) {
            $error = "Please fill in all required fields.";
        } elseif ($password !== $confirm) {
            $error = "Passwords do not match.";
        } else {
            // Connect to DB using database configuration file
            include_once("../func/db-dtl.php");
            $conn = @mysqli_connect($mySqlServer, $mySqlUser, $mySqlPass, $mySqlDBName);
            if (!$conn) {
                $error = "Database connection lost. Please restart installation.";
            } else {
                // password_hash() (bcrypt), not md5(): the super-admin row is verified in PHP by
                // bc_verify_password(), which also still accepts legacy raw-MD5 rows.
                $hashed_pw = password_hash($password, PASSWORD_DEFAULT);
                $status = 1;

                $stmt = mysqli_prepare($conn, "INSERT INTO sas_super_admin (email, password, firstname, lastname, phone_number, gender, home_address, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                mysqli_stmt_bind_param($stmt, "sssssssi", $email, $hashed_pw, $fname, $lname, $phone, $gender, $address, $status);
                
                if (mysqli_stmt_execute($stmt)) {
                    // Pre-fill required settings for super admin
                    mysqli_query($conn, "INSERT IGNORE INTO sas_super_admin_options (option_name, option_value) VALUES ('system_migration_version', '6.9.11-ai')");

                    // Persist the license key validated in step 1 into sas_super_admin_options.
                    // The activation file alone is not enough: AccountSettings' license checks and
                    // the 48h grace-period lock both read license_key/license_status from the DB.
                    // A missing DB row made a fresh, valid install look unlicensed and get
                    // "suspended" the moment the license status was refreshed.
                    include_once('../func/bc-levelup.php');
                    $install_license_key = isset($_SESSION['install_license_key']) ? trim($_SESSION['install_license_key']) : '';
                    $install_license_domain = isset($_SESSION['install_license_domain']) ? trim($_SESSION['install_license_domain']) : ($_SERVER['HTTP_HOST'] ?? 'localhost');
                    if (!empty($install_license_key)) {
                        bc_store_license_option($conn, 'license_key', $install_license_key);
                        bc_store_license_option($conn, 'license_domain', $install_license_domain);
                        bc_store_license_option($conn, 'license_status', 'valid');
                        bc_store_license_option($conn, 'license_last_check', date('Y-m-d H:i:s'));
                    }
                    unset($_SESSION['install_license_key'], $_SESSION['install_license_domain']);

                    header("Location: ?step=4");
                    exit;
                } else {
                    $error = "Failed to create Super Admin: " . mysqli_error($conn);
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installation Wizard - DGV7.0 SAAS</title>
    <link rel="stylesheet" href="install.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.10.5/font/bootstrap-icons.min.css">
</head>
<body>

<div class="installer-container">
    <div class="installer-header">
        <h1>DGV7.0 SAAS Installer</h1>
        <p>Complete the setup process to get started.</p>
    </div>

    <div class="stepper">
        <div class="step <?= $step >= 1 ? 'active' : '' ?>">1</div>
        <div class="line <?= $step >= 2 ? 'active' : '' ?>"></div>
        <div class="step <?= $step >= 2 ? 'active' : '' ?>">2</div>
        <div class="line <?= $step >= 3 ? 'active' : '' ?>"></div>
        <div class="step <?= $step >= 3 ? 'active' : '' ?>">3</div>
        <div class="line <?= $step >= 4 ? 'active' : '' ?>"></div>
        <div class="step <?= $step >= 4 ? 'active' : '' ?>">4</div>
    </div>

    <div class="installer-body">
        <?php if (!empty($error)): ?>
            <div class="alert error"><i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($step === 1): ?>
            <h2>System Requirements</h2>
            <?php
            $php_ok = version_compare(PHP_VERSION, '7.4.0', '>=');
            $mysqli_ok = extension_loaded('mysqli');
            $curl_ok = extension_loaded('curl');
            $json_ok = extension_loaded('json');
            $mbstring_ok = extension_loaded('mbstring');
            $all_ok = $php_ok && $mysqli_ok && $curl_ok && $json_ok && $mbstring_ok;
            ?>
            <ul class="requirements-list">
                <li>PHP Version >= 7.4 <span class="badge <?= $php_ok ? 'success' : 'fail' ?>"><?= $php_ok ? 'Yes' : 'No' ?></span></li>
                <li>MySQLi Extension <span class="badge <?= $mysqli_ok ? 'success' : 'fail' ?>"><?= $mysqli_ok ? 'Yes' : 'No' ?></span></li>
                <li>cURL Extension <span class="badge <?= $curl_ok ? 'success' : 'fail' ?>"><?= $curl_ok ? 'Yes' : 'No' ?></span></li>
                <li>JSON Extension <span class="badge <?= $json_ok ? 'success' : 'fail' ?>"><?= $json_ok ? 'Yes' : 'No' ?></span></li>
                <li>Mbstring Extension <span class="badge <?= $mbstring_ok ? 'success' : 'fail' ?>"><?= $mbstring_ok ? 'Yes' : 'No' ?></span></li>
                <li>func/ Folder Writable <span class="badge <?= is_writable('../func') ? 'success' : 'fail' ?>"><?= is_writable('../func') ? 'Yes' : 'No' ?></span></li>
            </ul>
            
            <form method="POST">
                <div class="form-group mt-4" style="text-align: left;">
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Activation Code</label>
                    <input type="text" name="activation_code" placeholder="Enter your Activation Code" required autocomplete="off">
                </div>
                
                <?php if ($all_ok && is_writable('../func')): ?>
                    <button type="submit" class="btn btn-primary w-100 mt-4">Verify & Next Step <i class="bi bi-arrow-right"></i></button>
                <?php else: ?>
                    <button type="button" class="btn btn-disabled w-100 mt-4" disabled>Please fix requirements</button>
                <?php endif; ?>
            </form>

        <?php elseif ($step === 2): ?>
            <h2>Database Setup</h2>
            <p>Enter your database connection details below.</p>
            <form method="POST">
                <div class="form-group">
                    <label>Database Host</label>
                    <input type="text" name="db_host" value="localhost" required>
                </div>
                <div class="form-group">
                    <label>Database Name</label>
                    <input type="text" name="db_name" required placeholder="e.g. dgv7_saas">
                </div>
                <div class="form-group">
                    <label>Database Username</label>
                    <input type="text" name="db_user" required placeholder="e.g. root">
                </div>
                <div class="form-group">
                    <label>Database Password</label>
                    <input type="password" name="db_pass" placeholder="Leave empty if none">
                </div>
                <button type="submit" class="btn btn-primary w-100 mt-4">Install Database <i class="bi bi-database-check"></i></button>
            </form>

        <?php elseif ($step === 3): ?>
            <h2>Super Admin Profile</h2>
            <p>Create the main administrator account.</p>
            <form method="POST">
                <div class="row">
                    <div class="form-group col-half">
                        <label>First Name</label>
                        <input type="text" name="firstname" required>
                    </div>
                    <div class="form-group col-half">
                        <label>Last Name</label>
                        <input type="text" name="lastname" required>
                    </div>
                </div>
                <div class="row">
                    <div class="form-group col-half">
                        <label>Email Address</label>
                        <input type="email" name="email" required>
                    </div>
                    <div class="form-group col-half">
                        <label>Phone Number</label>
                        <input type="text" name="phone" required>
                    </div>
                </div>
                <div class="form-group">
                    <label>Gender</label>
                    <select name="gender" required>
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Home Address</label>
                    <input type="text" name="address" required>
                </div>
                <div class="row">
                    <div class="form-group col-half">
                        <label>Password</label>
                        <input type="password" name="password" required>
                    </div>
                    <div class="form-group col-half">
                        <label>Confirm Password</label>
                        <input type="password" name="confirm_password" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-100 mt-4">Complete Setup <i class="bi bi-check-circle"></i></button>
            </form>

        <?php elseif ($step === 4): ?>
            <div class="success-screen">
                <div class="success-icon"><i class="bi bi-check-circle-fill"></i></div>
                <h2>Installation Complete!</h2>
                <p>DGV7.0 SAAS has been successfully installed on your server.</p>
                <div class="alert warning mt-4">
                    <i class="bi bi-shield-exclamation"></i> 
                    <strong>Important Security Notice:</strong> 
                    Please delete the <code>/install</code> directory immediately to prevent unauthorized re-installation.
                </div>
                <a href="/bc-spadmin/" class="btn btn-primary mt-4">Go to Super Admin Panel <i class="bi bi-box-arrow-in-right"></i></a>
            </div>
        <?php endif; ?>
    </div>
</div>

</body>
</html>

<?php
// PHP 8.1+ Compatibility Fix: Disable strict exception mode for MySQLi
mysqli_report(MYSQLI_REPORT_OFF);

session_start();

// Shared installer layout (byte-identical across editions).
require_once __DIR__ . '/../func/bc-installer-shell.php';

$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$error = '';
$success = '';
$activationCodeInput = '';

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
        $code = trim((string)($_POST['activation_code'] ?? ''));
        $activationCodeInput = $code;
        if ($code === '') {
            $error = 'Please enter your Activation Code.';
        } else {
            $hostHeader = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
            $domain = parse_url('http://' . $hostHeader, PHP_URL_HOST);
            $domain = strtolower(rtrim((string)($domain ?: preg_replace('/:\d+$/', '', $hostHeader)), '.'));
            if ($domain === '' || $domain === 'localhost') {
                $error = 'Could not determine this installation domain. Open the installer using the site domain, then try again.';
            } else {
                try {
                    $endpoint = 'https://manager.pmhserver.name.ng/api.php';
                    $ch = curl_init($endpoint);
                    if ($ch === false) {
                        throw new RuntimeException('The server could not initialize cURL. Confirm the cURL extension is enabled.');
                    }
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => http_build_query(['key' => $code, 'domain' => $domain]),
                        CURLOPT_HTTPHEADER => ['Accept: application/json'],
                        CURLOPT_CONNECTTIMEOUT => 5,
                        CURLOPT_TIMEOUT => 15,
                        CURLOPT_SSL_VERIFYPEER => true,
                        CURLOPT_SSL_VERIFYHOST => 2,
                        CURLOPT_USERAGENT => 'DGV7-SaaS-Installer/' . PHP_VERSION,
                    ]);
                    $response = curl_exec($ch);
                    $curl_errno = curl_errno($ch);
                    $curl_error = curl_error($ch);
                    $http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);

                    if ($response === false) {
                        error_log('[DGV7 installer] License verification transport error ' . $curl_errno . ': ' . $curl_error);
                        $error = 'Could not contact the activation server (connection error ' . $curl_errno . '). ' . ($curl_error ?: 'Check outbound HTTPS/DNS and try again.');
                    } elseif ($http_code < 200 || $http_code >= 300) {
                        error_log('[DGV7 installer] License server returned HTTP ' . $http_code . ' for domain ' . $domain);
                        $error = 'The activation server returned HTTP ' . $http_code . '. Please check the activation service and try again.';
                    } else {
                        $res = json_decode((string)$response, true);
                        if (!is_array($res)) {
                            error_log('[DGV7 installer] License server returned invalid JSON (HTTP ' . $http_code . ').');
                            $error = 'The activation server returned an unreadable response. Please try again later or contact support.';
                        } else {
                            $statusValue = $res['status'] ?? ($res['data']['status'] ?? null);
                            $validFlag = $res['valid'] ?? ($res['data']['valid'] ?? false);
                            $normalizedStatus = strtolower(trim((string)$statusValue));
                            $is_valid = in_array($normalizedStatus, ['active', 'valid', 'success', 'ok', '1', 'true'], true)
                                || filter_var($validFlag, FILTER_VALIDATE_BOOLEAN);

                            if ($is_valid) {
                                try {
                                    include_once('../func/bc-levelup.php');
                                    if (!function_exists('bc_write_activation')) {
                                        throw new RuntimeException('Activation support could not be loaded.');
                                    }
                                    if (!bc_write_activation($code)) {
                                        throw new RuntimeException('Could not write the activation file. Check write permissions for the func/ folder.');
                                    }
                                    $_SESSION['install_license_key'] = $code;
                                    $_SESSION['install_license_domain'] = $domain;
                                    header('Location: ?step=2');
                                    exit;
                                } catch (Throwable $activationError) {
                                    error_log('[DGV7 installer] Valid key could not be persisted: ' . $activationError->getMessage());
                                    $error = 'The key was verified, but could not be saved on this server. Check that func/ is writable and that OpenSSL is enabled. Details: ' . $activationError->getMessage();
                                }
                            } else {
                                $apiMessage = $res['message'] ?? ($res['data']['message'] ?? 'The activation service did not approve this key for the submitted domain.');
                                $apiMessage = trim(substr(strip_tags((string)$apiMessage), 0, 300));
                                $error = $apiMessage !== '' ? $apiMessage : 'The activation key was not accepted for ' . $domain . '.';
                                error_log('[DGV7 installer] License verification rejected for domain ' . $domain . ': ' . $error);
                            }
                        }
                    }
                } catch (Throwable $verificationError) {
                    error_log('[DGV7 installer] License verification failed: ' . $verificationError->getMessage());
                    $error = 'License verification could not be completed. ' . $verificationError->getMessage();
                }
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
<?php bc_installer_head("Installation Wizard - DGV7.0 SAAS"); ?>
<div class="bc-installer">
    <div class="bc-installer__brand">
        <div class="bc-installer__mark">D7</div>
        <div class="bc-installer__brandtxt">
            <strong>DGV7.0 SAAS</strong>
            <span>Installation wizard</span>
        </div>
    </div>

    <div class="bc-installer__card">
        <?php bc_installer_stepper($step); ?>

        <?php if (!empty($error)): ?>
            <div class="bc-installer__alert bc-installer__alert--error" role="alert" aria-live="assertive"><i class="bi bi-exclamation-triangle-fill"></i><span><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></span></div>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
            <div class="bc-installer__alert bc-installer__alert--ok" role="alert" aria-live="polite"><i class="bi bi-check-circle-fill"></i><span><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></span></div>
        <?php endif; ?>

        <?php if ($step === 1): ?>
            <h2 class="bc-installer__title">System Requirements</h2>
            <p class="bc-installer__sub">Confirm your server meets the minimum requirements before continuing.</p>
            <?php
            $php_ok = version_compare(PHP_VERSION, '7.4.0', '>=');
            $mysqli_ok = extension_loaded('mysqli');
            $curl_ok = extension_loaded('curl');
            $json_ok = extension_loaded('json');
            $mbstring_ok = extension_loaded('mbstring');
            $openssl_ok = extension_loaded('openssl');
            $func_writable = is_writable('../func');
            $all_ok = $php_ok && $mysqli_ok && $curl_ok && $json_ok && $mbstring_ok && $openssl_ok && $func_writable;
            $requirements = array(
                array('PHP Version >= 7.4', $php_ok),
                array('MySQLi Extension', $mysqli_ok),
                array('cURL Extension', $curl_ok),
                array('JSON Extension', $json_ok),
                array('Mbstring Extension', $mbstring_ok),
                array('OpenSSL Extension', $openssl_ok),
                array('func/ Folder Writable', $func_writable),
            );
            ?>
            <ul class="bc-installer__req">
                <?php foreach ($requirements as $req): ?>
                    <li><?php echo htmlspecialchars($req[0], ENT_QUOTES, 'UTF-8'); ?><span class="bc-installer__pill <?php echo $req[1] ? 'bc-installer__pill--ok' : 'bc-installer__pill--bad'; ?>"><?php echo $req[1] ? 'Yes' : 'No'; ?></span></li>
                <?php endforeach; ?>
            </ul>

            <form method="POST">
                <div class="bc-installer__field">
                    <label class="bc-installer__label" for="activation_code">Activation Code</label>
                    <input type="text" id="activation_code" class="bc-installer__input" name="activation_code" value="<?php echo htmlspecialchars($activationCodeInput, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Enter your Activation Code" required autocomplete="off" aria-describedby="activationFeedback">
                </div>
                <?php if ($all_ok): ?>
                    <button type="submit" class="bc-installer__btn">Verify &amp; Next Step <i class="bi bi-arrow-right"></i></button>
                <?php else: ?>
                    <button type="button" class="bc-installer__btn" disabled>Please fix requirements</button>
                <?php endif; ?>
            </form>

        <?php elseif ($step === 2): ?>
            <h2 class="bc-installer__title">Database Setup</h2>
            <p class="bc-installer__sub">Enter your database connection details below.</p>
            <form method="POST">
                <div class="bc-installer__grid bc-installer__grid--2">
                    <div class="bc-installer__field">
                        <label class="bc-installer__label" for="db_host">Database Host</label>
                        <input type="text" id="db_host" class="bc-installer__input" name="db_host" value="localhost" required>
                    </div>
                    <div class="bc-installer__field">
                        <label class="bc-installer__label" for="db_name">Database Name</label>
                        <input type="text" id="db_name" class="bc-installer__input" name="db_name" required placeholder="e.g. dgv7_saas">
                    </div>
                    <div class="bc-installer__field">
                        <label class="bc-installer__label" for="db_user">Database Username</label>
                        <input type="text" id="db_user" class="bc-installer__input" name="db_user" required placeholder="e.g. root">
                    </div>
                    <div class="bc-installer__field">
                        <label class="bc-installer__label" for="db_pass">Database Password</label>
                        <input type="password" id="db_pass" class="bc-installer__input" name="db_pass" placeholder="Leave empty if none">
                    </div>
                </div>
                <button type="submit" class="bc-installer__btn mt-4">Install Database <i class="bi bi-database-check"></i></button>
            </form>

        <?php elseif ($step === 3): ?>
            <h2 class="bc-installer__title">Super Admin Profile</h2>
            <p class="bc-installer__sub">Create the main administrator account.</p>
            <form method="POST">
                <div class="bc-installer__grid bc-installer__grid--2">
                    <div class="bc-installer__field">
                        <label class="bc-installer__label" for="firstname">First Name</label>
                        <input type="text" id="firstname" class="bc-installer__input" name="firstname" required>
                    </div>
                    <div class="bc-installer__field">
                        <label class="bc-installer__label" for="lastname">Last Name</label>
                        <input type="text" id="lastname" class="bc-installer__input" name="lastname" required>
                    </div>
                    <div class="bc-installer__field">
                        <label class="bc-installer__label" for="email">Email Address</label>
                        <input type="email" id="email" class="bc-installer__input" name="email" required>
                    </div>
                    <div class="bc-installer__field">
                        <label class="bc-installer__label" for="phone">Phone Number</label>
                        <input type="text" id="phone" class="bc-installer__input" name="phone" required>
                    </div>
                    <div class="bc-installer__field">
                        <label class="bc-installer__label" for="gender">Gender</label>
                        <select id="gender" class="bc-installer__select" name="gender" required>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                        </select>
                    </div>
                    <div class="bc-installer__field">
                        <label class="bc-installer__label" for="address">Home Address</label>
                        <input type="text" id="address" class="bc-installer__input" name="address" required>
                    </div>
                    <div class="bc-installer__field">
                        <label class="bc-installer__label" for="password">Password</label>
                        <input type="password" id="password" class="bc-installer__input" name="password" required>
                    </div>
                    <div class="bc-installer__field">
                        <label class="bc-installer__label" for="confirm_password">Confirm Password</label>
                        <input type="password" id="confirm_password" class="bc-installer__input" name="confirm_password" required>
                    </div>
                </div>
                <button type="submit" class="bc-installer__btn mt-4">Complete Setup <i class="bi bi-check-circle"></i></button>
            </form>

        <?php elseif ($step === 4): ?>
            <div class="bc-installer__success">
                <div class="bc-installer__successicon"><i class="bi bi-check-lg" style="font-size:1.8rem;"></i></div>
                <h2 class="bc-installer__title">Installation Complete!</h2>
                <p class="bc-installer__sub" style="text-align:center;">DGV7.0 SAAS has been successfully installed on your server.</p>
                <div class="bc-installer__alert bc-installer__alert--warn mt-4">
                    <i class="bi bi-shield-exclamation"></i>
                    <span><strong>Important Security Notice:</strong> Please delete the <code>/install</code> directory immediately to prevent unauthorized re-installation.</span>
                </div>
                <a href="/bc-spadmin/" class="bc-installer__btn mt-4">Go to Super Admin Panel <i class="bi bi-box-arrow-in-right"></i></a>
            </div>
        <?php endif; ?>
    </div>
<?php
bc_installer_foot();

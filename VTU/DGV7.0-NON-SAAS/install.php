<?php
/**
 * DGV7 Standalone VTU — Installation Wizard
 * Single-page installer for the standalone version
 * 
 * NOTE: DB credentials are written to func/db-json.php
 * which is included by func/db-dtl.php → func/bc-connect.php
 */

// Prevent running if already installed
if (file_exists(__DIR__ . '/installed.lock')) {
    header('Location: /bc-admin/Dashboard.php');
    exit();
}

// PHP 8.1+ made mysqli throw mysqli_sql_exception on connection failure instead of
// returning false — the @ operator only suppresses warnings, not exceptions, so every
// failed test_db/install attempt below was crashing with an uncaught fatal error (HTML
// output) instead of the JSON response the installer's JS expects. Restore the classic
// "return false on failure" behavior for this script.
mysqli_report(MYSQLI_REPORT_OFF);

define('__INSTALLER_SCRIPTS__', <<<'JS'

function goStep(n) {
    document.querySelectorAll('.bc-installer__panel').forEach(p => p.classList.remove('active'));
    document.getElementById('step-' + n).classList.add('active');
    for (let i = 1; i <= 4; i++) {
        const dot = document.querySelector('.bc-installer__step[data-step="' + i + '"]');
        if (!dot) continue;
        dot.classList.toggle('bc-installer__step--done', i < n);
        dot.classList.toggle('bc-installer__step--now', i == n);
    }
    document.querySelectorAll('.bc-installer__bar').forEach(b => {
        b.classList.toggle('bc-installer__bar--done', parseInt(b.getAttribute('data-after'), 10) < n);
    });
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

async function testDB() {
    const btn = document.getElementById('btn-test-db');
    const result = document.getElementById('db-test-result');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Testing...';
    result.style.display = 'none';

    const body = new URLSearchParams({
        action:  'test_db',
        db_host: document.getElementById('db_host').value,
        db_name: document.getElementById('db_name').value,
        db_user: document.getElementById('db_user').value,
        db_pass: document.getElementById('db_pass').value,
    });

    try {
        const r    = await fetch('install.php', { method: 'POST', body });
        const data = await r.json();
        result.style.display = 'block';
        if (data.ok) {
            result.innerHTML = '<div class="bc-installer__alert bc-installer__alert--ok"><i class="bi bi-check-circle-fill me-2"></i>' + data.msg + '</div>';
            document.getElementById('btn-next-2').disabled = false;
            dbConnected = true;
        } else {
            result.innerHTML = '<div class="bc-installer__alert bc-installer__alert--error"><i class="bi bi-x-circle-fill me-2"></i>' + data.msg + '</div>';
            document.getElementById('btn-next-2').disabled = true;
            dbConnected = false;
        }
    } catch(e) {
        result.innerHTML = '<div class="bc-installer__alert bc-installer__alert--error"><i class="bi bi-x-circle-fill me-2"></i>Request failed: ' + e.message + '</div>';
        result.style.display = 'block';
    }
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-plug me-2"></i>Test Connection';
}

function checkPassStrength() {
    const p     = document.getElementById('admin_pass').value;
    const bar   = document.getElementById('pass-strength');
    const label = document.getElementById('pass-strength-label');
    let strength = 0;
    if (p.length >= 8)          strength++;
    if (p.length >= 12)         strength++;
    if (/[A-Z]/.test(p))        strength++;
    if (/[0-9]/.test(p))        strength++;
    if (/[^A-Za-z0-9]/.test(p)) strength++;
    const colors = ['#dc2626','#ea580c','#ca8a04','#16a34a','#15803d'];
    const labels = ['Very Weak','Weak','Fair','Strong','Very Strong'];
    bar.style.width = (strength * 20) + '%';
    bar.style.background = colors[Math.max(0, strength - 1)];
    label.textContent = p.length ? labels[Math.max(0, strength - 1)] : '';
    label.style.color = colors[Math.max(0, strength - 1)];
}

function validateStep3() {
    const errDiv = document.getElementById('step3-error');
    const first  = document.getElementById('admin_first').value.trim();
    const email  = document.getElementById('admin_email').value.trim();
    const pass   = document.getElementById('admin_pass').value;
    const pass2  = document.getElementById('admin_pass2').value;
    const pin    = document.getElementById('admin_pin').value;
    const pin2   = document.getElementById('admin_pin2').value;

    errDiv.style.display = 'none';
    if (!first)              { showStep3Err('First name is required.');                   return; }
    if (!email)              { showStep3Err('Email address is required.');                return; }
    if (!email.includes('@')){ showStep3Err('Please enter a valid email address.');       return; }
    if (pass.length < 8)     { showStep3Err('Password must be at least 8 characters.');  return; }
    if (pass !== pass2)      { showStep3Err('Passwords do not match.');                   return; }
    if (!/^\d{4}$/.test(pin)){ showStep3Err('Security PIN must be exactly 4 digits.');   return; }
    if (pin !== pin2)        { showStep3Err('Security PIN and Confirm PIN do not match.'); return; }

    runInstall();
}
function showStep3Err(msg) {
    const e = document.getElementById('step3-error');
    e.style.display = 'block';
    e.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-2"></i>' + msg;
}

async function runInstall() {
    goStep(4);
    const prog   = document.getElementById('install-progress');
    const status = document.getElementById('install-status');
    const log    = document.getElementById('install-log');

    const logLines = [
        'Connecting to database...',
        'Writing configuration file...',
        'Creating database tables...',
        'Seeding vendor record (id=1)...',
        'Installing API providers (airtime, data, cable, electric)...',
        'Creating installed.lock...',
    ];
    let i = 0;
    log.textContent = logLines[0];
    const interval = setInterval(() => {
        i++;
        if (i < logLines.length) {
            log.textContent += '\n' + logLines[i];
            prog.style.width = Math.round((i / logLines.length) * 90) + '%';
            log.scrollTop = log.scrollHeight;
        }
    }, 700);

    const body = new URLSearchParams({
        action:      'install',
        db_host:     document.getElementById('db_host').value,
        db_name:     document.getElementById('db_name').value,
        db_user:     document.getElementById('db_user').value,
        db_pass:     document.getElementById('db_pass').value,
        site_name:   document.getElementById('site_name').value,
        admin_first: document.getElementById('admin_first').value,
        admin_last:  document.getElementById('admin_last').value,
        admin_email: document.getElementById('admin_email').value,
        admin_phone: document.getElementById('admin_phone').value,
        admin_pass:  document.getElementById('admin_pass').value,
        admin_pin:   document.getElementById('admin_pin').value,
    });

    try {
        const r    = await fetch('install.php', { method: 'POST', body });
        const data = await r.json();
        clearInterval(interval);
        prog.style.width = '100%';

        if (data.ok) {
            document.getElementById('done-email').textContent = document.getElementById('admin_email').value;
            setTimeout(() => {
                document.getElementById('installing-view').style.display = 'none';
                document.getElementById('done-view').style.display = 'block';
            }, 600);
        } else {
            document.getElementById('error-msg').textContent = data.msg;
            setTimeout(() => {
                document.getElementById('installing-view').style.display = 'none';
                document.getElementById('error-view').style.display = 'block';
            }, 400);
        }
    } catch(e) {
        clearInterval(interval);
        document.getElementById('error-msg').textContent = 'Network error: ' + e.message;
        document.getElementById('installing-view').style.display = 'none';
        document.getElementById('error-view').style.display = 'block';
    }
}

async function verifyActivation() {
    const code = document.getElementById('activation_code').value.trim();
    const result = document.getElementById('activation-result');
    const btn = document.querySelector('#step-1 .bc-installer__btn');

    if (!code) {
        result.style.display = 'block';
        result.innerHTML = '<div class="bc-installer__alert bc-installer__alert--error"><i class="bi bi-x-circle-fill me-2"></i>Activation code is required.</div>';
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Activating...';
    result.style.display = 'none';

    const body = new URLSearchParams({
        action: 'check_activation',
        activation_code: code
    });

    try {
        const r = await fetch('install.php', { method: 'POST', body });
        const data = await r.json();
        result.style.display = 'block';
        if (data.ok) {
            result.innerHTML = '<div class="bc-installer__alert bc-installer__alert--ok"><i class="bi bi-check-circle-fill me-2"></i>' + data.msg + '</div>';
            setTimeout(() => { goStep(2); }, 800);
        } else {
            result.innerHTML = '<div class="bc-installer__alert bc-installer__alert--error"><i class="bi bi-x-circle-fill me-2"></i>' + data.msg + '</div>';
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-arrow-right me-2"></i>Continue to Database Setup';
        }
    } catch (e) {
        result.style.display = 'block';
        result.innerHTML = '<div class="bc-installer__alert bc-installer__alert--error"><i class="bi bi-x-circle-fill me-2"></i>Connection error: ' + e.message + '</div>';
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-arrow-right me-2"></i>Continue to Database Setup';
    }
}

function resetInstall() {
    document.getElementById('installing-view').style.display = 'block';
    document.getElementById('done-view').style.display = 'none';
    document.getElementById('error-view').style.display = 'none';
    document.getElementById('install-progress').style.width = '0%';
    document.getElementById('install-log').textContent = 'Initializing...';
    goStep(1);
}

JS);

// Shared installer layout (byte-identical across editions).
require_once __DIR__ . '/func/bc-installer-shell.php';

$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;

// ─── AJAX: Test DB Connection ─────────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'test_db') {
    header('Content-Type: application/json');
    $h = trim($_POST['db_host'] ?? '');
    $n = trim($_POST['db_name'] ?? '');
    $u = trim($_POST['db_user'] ?? '');
    $p = trim($_POST['db_pass'] ?? '');
    $conn = @mysqli_connect($h, $u, $p, $n);
    if ($conn) {
        mysqli_close($conn);
        echo json_encode(['ok' => true, 'msg' => 'Connection successful!']);
    } else {
        echo json_encode(['ok' => false, 'msg' => mysqli_connect_error()]);
    }
    exit();
}

// ─── AJAX: Test Activation Code ───────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'check_activation') {
    header('Content-Type: application/json');
    $activation_code = trim($_POST['activation_code'] ?? '');
    if (empty($activation_code)) {
        echo json_encode(['ok' => false, 'msg' => 'Please enter a valid activation code.']);
        exit();
    }
    
    $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $api_url = 'https://manager.pmhserver.name.ng/api.php';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'key' => $activation_code,
        'domain' => $domain
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code === 200 && !empty($response)) {
        $res_data = json_decode($response, true);
        $status = isset($res_data['status']) ? (int)$res_data['status'] : 0;
        if ($status === 1) {
            // Write activation code and seed the 48-hour integrity cache immediately
            include_once(__DIR__ . '/func/bc-integrity.php');
            if (bc_write_activation($activation_code)) {
                bc_write_integrity_cache(true);
                echo json_encode(['ok' => true, 'msg' => 'Activation successful!']);
            } else {
                echo json_encode(['ok' => false, 'msg' => 'Could not write activation state file. Check directory permissions.']);
            }
        } else {
            echo json_encode(['ok' => false, 'msg' => 'Invalid activation code for this domain.']);
        }
    } else {
        echo json_encode(['ok' => false, 'msg' => 'Integrity validation server unreachable. Please try again later.']);
    }
    exit();
}

// ─── AJAX: Run Full Installation ──────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'install') {
    header('Content-Type: application/json');

    $db_host    = trim($_POST['db_host']    ?? '');
    $db_name    = trim($_POST['db_name']    ?? '');
    $db_user    = trim($_POST['db_user']    ?? '');
    $db_pass    = trim($_POST['db_pass']    ?? '');
    $site_name  = trim($_POST['site_name']  ?? 'My VTU Platform');
    $admin_first = trim($_POST['admin_first'] ?? '');
    $admin_last  = trim($_POST['admin_last']  ?? '');
    $admin_email = trim($_POST['admin_email'] ?? '');
    $admin_phone = trim($_POST['admin_phone'] ?? '');
    $admin_pass  = trim($_POST['admin_pass']  ?? '');
    $admin_pin   = trim($_POST['admin_pin']   ?? '');

    // Validate required fields
    $errors = [];
    if (empty($db_host))    $errors[] = 'Database host is required';
    if (empty($db_name))    $errors[] = 'Database name is required';
    if (empty($db_user))    $errors[] = 'Database user is required';
    if (empty($admin_first)) $errors[] = 'Admin first name is required';
    if (empty($admin_email)) $errors[] = 'Admin email is required';
    if (empty($admin_pass))  $errors[] = 'Admin password is required';
    if (strlen($admin_pass) < 8) $errors[] = 'Password must be at least 8 characters';
    // A security PIN set at install time means the admin never hits the
    // force_vendor_pin enforcement redirect (func/bc-admin-config.php) on first
    // login — that redirect only exempts /bc-admin/AccountSettings.php itself,
    // so an admin whose PIN is still empty depends on that one exemption holding
    // up across every other page; setting it here up front removes the dependency.
    if (!preg_match('/^\d{4}$/', $admin_pin)) $errors[] = 'Security PIN must be exactly 4 digits';

    if (!empty($errors)) {
        echo json_encode(['ok' => false, 'msg' => implode(', ', $errors)]);
        exit();
    }

    // Test DB connection
    $conn = @mysqli_connect($db_host, $db_user, $db_pass, $db_name);
    if (!$conn) {
        echo json_encode(['ok' => false, 'msg' => 'Database connection failed: ' . mysqli_connect_error()]);
        exit();
    }
    mysqli_set_charset($conn, 'utf8mb4');

    // ── Step 1: Write func/db-json.php with credentials ───────────────────────
    // bc-connect.php → db-dtl.php → db-json.php is the credential chain
    $db_json_content = '<?php' . "\n" .
        '/**' . "\n" .
        ' * DGV7 Standalone — Database Credentials' . "\n" .
        ' * Written by install wizard on ' . date('Y-m-d H:i:s') . "\n" .
        ' */' . "\n\n" .
        'function _bc_db_config_safe(): array {' . "\n" .
        '    return [' . "\n" .
        '        \'server\'  => getenv(\'DB_HOST\') ?: ' . var_export($db_host, true) . ',' . "\n" .
        '        \'user\'    => getenv(\'DB_USER\') ?: ' . var_export($db_user, true) . ',' . "\n" .
        '        \'pass\'    => getenv(\'DB_PASS\') ?: ' . var_export($db_pass, true) . ',' . "\n" .
        '        \'dbname\'  => getenv(\'DB_NAME\') ?: ' . var_export($db_name, true) . ',' . "\n" .
        '        \'app_env\' => getenv(\'APP_ENV\') ?: \'production\',' . "\n" .
        '    ];' . "\n" .
        '}' . "\n\n" .
        '$db_json_decode = _bc_db_config_safe();' . "\n\n" .
        '// Legacy variables kept for strict backward compatibility' . "\n" .
        '$db_json_dtls   = $db_json_decode;' . "\n" .
        '$db_json_encode = json_encode($db_json_decode, JSON_THROW_ON_ERROR);' . "\n";

    $db_json_file = __DIR__ . '/func/db-json.php';
    if (!file_put_contents($db_json_file, $db_json_content)) {
        echo json_encode(['ok' => false, 'msg' => 'Could not write func/db-json.php — check directory permissions.']);
        exit();
    }

    // ── Step 2: Run bc-tables.php to create all tables ────────────────────────
    $tables_file = __DIR__ . '/func/bc-tables.php';
    if (file_exists($tables_file)) {
        // bc-tables.php expects $connection_server to be set
        $connection_server = $conn;
        // Suppress any getSuperAdminOption calls inside tables seeding
        if (!function_exists('getSuperAdminOption')) {
            function getSuperAdminOption($key, $default = '') { return $default; }
        }
        @include($tables_file);
    } else {
        echo json_encode(['ok' => false, 'msg' => 'func/bc-tables.php not found — cannot create tables.']);
        exit();
    }

    // ── Step 3: Seed vendor record (id=1) with all features unlocked ──────────
    $md5_pass     = password_hash($admin_pass, PASSWORD_DEFAULT); // bcrypt; bc-admin/Login.php also accepts legacy md5
    $reg_date     = date('Y-m-d H:i:s');
    $access_hash  = bin2hex(random_bytes(32)); // opaque bearer token, not md5('1'.email.time())
    $website_url  = $_SERVER['HTTP_HOST'] ?? 'localhost';

    $site_esc    = mysqli_real_escape_string($conn, $site_name);
    $first_esc   = mysqli_real_escape_string($conn, $admin_first);
    $last_esc    = mysqli_real_escape_string($conn, $admin_last);
    $email_esc   = mysqli_real_escape_string($conn, $admin_email);
    $phone_esc   = mysqli_real_escape_string($conn, $admin_phone);
    $pass_esc    = mysqli_real_escape_string($conn, $md5_pass);
    $hash_esc    = mysqli_real_escape_string($conn, $access_hash);
    $website_esc = mysqli_real_escape_string($conn, $website_url);
    $pin_esc     = mysqli_real_escape_string($conn, password_hash($admin_pin, PASSWORD_DEFAULT));

    $check_vendor = mysqli_query($conn, "SELECT id FROM sas_vendors WHERE id=1");
    if ($check_vendor && mysqli_num_rows($check_vendor) == 0) {
        mysqli_query($conn, "INSERT INTO sas_vendors
            (id, firstname, lastname, email, phone_number, password, website_url,
             home_address, balance, status, is_blocked,
             plisio_activated, payout_activated, ai_status, access_hash, security_pin, reg_date)
            VALUES
            (1, '$first_esc', '$last_esc', '$email_esc', '$phone_esc', '$pass_esc', '$website_esc',
             '', 0, 1, 0,
             1, 1, 1, '$hash_esc', '$pin_esc', '$reg_date')");
    } else {
        mysqli_query($conn, "UPDATE sas_vendors SET
            firstname='$first_esc', lastname='$last_esc', email='$email_esc',
            phone_number='$phone_esc', password='$pass_esc', website_url='$website_esc',
            status=1, is_blocked=0, plisio_activated=1, payout_activated=1, ai_status=1,
            expiry_date=NULL, access_hash='$hash_esc', security_pin='$pin_esc'
            WHERE id=1");
    }

    // ── Step 4: Insert default site details ───────────────────────────────────
    $check_site = mysqli_query($conn, "SELECT vendor_id FROM sas_site_details WHERE vendor_id=1 LIMIT 1");
    if (!$check_site || mysqli_num_rows($check_site) == 0) {
        mysqli_query($conn, "INSERT INTO sas_site_details (vendor_id, site_title, site_desc)
            VALUES (1, '$site_esc', 'Your trusted VTU platform')");
    } else {
        mysqli_query($conn, "UPDATE sas_site_details SET
            site_title='$site_esc'
            WHERE vendor_id=1");
    }

    // Save activation code in sas_super_admin_options
    include_once(__DIR__ . '/func/bc-integrity.php');
    $auth_token = bc_read_activation();
    if (!empty($auth_token)) {
        $auth_token_esc = mysqli_real_escape_string($conn, $auth_token);
        mysqli_query($conn, "UPDATE sas_super_admin_options SET option_value='$auth_token_esc' WHERE option_name='system_auth_token'");
        mysqli_query($conn, "UPDATE sas_super_admin_options SET option_value='1' WHERE option_name='integrity_status'");
    }

    // ── Step 5: Seed all API providers into sas_apis for vendor_id=1 ──────────
    $api_seeds = [
        'airtime'      => ['vtpass.com','clubkonnect.com','husmodataapi.com','kvdata.net','mobileone.ng','paygold.ng','smartrecharge.ng','smartrechargeapi.com'],
        'sme-data'     => ['vtpass.com','clubkonnect.com','abumpay.com','benzoni.ng','bilalsadasub.com','datastationapi.com','gladtidingsapihub.com','grecians.ng','hdkdata.com','husmodataapi.com','kvdata.net','legitdataway.com','mobileone.ng','rpidatang.com','smartrecharge.ng','smartrechargeapi.com'],
        'cg-data'      => ['vtpass.com','clubkonnect.com','abumpay.com','benzoni.ng','bilalsadasub.com','datastationapi.com','gladtidingsapihub.com','grecians.ng','hdkdata.com','husmodataapi.com','kvdata.net','legitdataway.com','mobileone.ng','rpidatang.com','smartrecharge.ng','smartrechargeapi.com'],
        'dd-data'      => ['benzoni.ng','clubkonnect.com','grecians.ng','mobileone.ng','smartrecharge.ng','smartrechargeapi.com','vtpass.com'],
        'shared-data'  => ['datastationapi.com','gladtidingsapihub.com','hdkdata.com','husmodataapi.com','kvdata.net'],
        'cable'        => ['vtpass.com','clubkonnect.com','mobileone.ng','smartrecharge.ng','smartrechargeapi.com'],
        'electric'     => ['vtpass.com','clubkonnect.com','smartrecharge.ng','smartrechargeapi.com'],
        'exam'         => ['clubkonnect.com','abumpay.com','naijaresultpins.com'],
        'rechargecard' => ['alrahuzdata.com','bilalsadasub.com','clubkonnect.com','legitdataway.com'],
        'betting'      => ['clubkonnect.com'],
        'sms'          => ['app.philmoresms.com','kudisms.net','termii.com'],
    ];

    foreach ($api_seeds as $api_type => $providers) {
        foreach ($providers as $provider_url) {
            $type_esc = mysqli_real_escape_string($conn, $api_type);
            $url_esc  = mysqli_real_escape_string($conn, $provider_url);
            $check_api = mysqli_query($conn, "SELECT id FROM sas_apis WHERE vendor_id=1 AND api_type='$type_esc' AND api_base_url='$url_esc' LIMIT 1");
            if (!$check_api || mysqli_num_rows($check_api) == 0) {
                mysqli_query($conn, "INSERT INTO sas_apis (vendor_id, api_type, api_base_url, api_key, status)
                    VALUES (1, '$type_esc', '$url_esc', '', 0)");
            }
        }
    }

    // ── Step 6: Create installed.lock ─────────────────────────────────────────
    file_put_contents(__DIR__ . '/installed.lock', 'Installed on ' . date('Y-m-d H:i:s') . ' by ' . $admin_email);

    mysqli_close($conn);
    echo json_encode(['ok' => true, 'msg' => 'Installation complete!']);
    exit();
}

// ─── System Requirements Check (for Step 1 UI) ───────────────────────────────
$req_php        = version_compare(PHP_VERSION, '7.4', '>=');
$req_mysqli     = extension_loaded('mysqli');
$req_mbstring   = extension_loaded('mbstring');
$req_gd         = extension_loaded('gd');
$req_writable_func = is_writable(__DIR__ . '/func/');
$req_writable_root = is_writable(__DIR__);
$all_req_met    = $req_php && $req_mysqli && $req_mbstring && $req_writable_func && $req_writable_root;
?>
<?php bc_installer_head("Install — DGV7 Standalone VTU"); ?>
<div class="bc-installer">
    <div class="bc-installer__brand">
        <div class="bc-installer__mark">D7</div>
        <div class="bc-installer__brandtxt">
            <strong>DGV7 Standalone VTU</strong>
            <span>Installation wizard</span>
        </div>
    </div>

    <div class="bc-installer__card">
        <?php bc_installer_stepper($step); ?>

        <!-- STEP 1: System Requirements -->
        <div class="bc-installer__panel<?php echo $step == 1 ? ' active' : ''; ?>" id="step-1">
            <h2 class="bc-installer__title">System Requirements</h2>
            <p class="bc-installer__sub">Verifying your server meets the minimum requirements.</p>

            <?php
            $requirements = [
                ['label' => 'PHP Version &ge; 7.4 &nbsp;<span class="text-muted">(Current: ' . PHP_VERSION . ')</span>', 'ok' => $req_php,           'required' => true],
                ['label' => 'MySQLi Extension',                                                                         'ok' => $req_mysqli,        'required' => true],
                ['label' => 'Mbstring Extension',                                                                       'ok' => $req_mbstring,      'required' => true],
                ['label' => 'GD Image Library &nbsp;<span class="text-muted">(recommended)</span>',                    'ok' => $req_gd,            'required' => false],
                ['label' => '<code>func/</code> Directory Writable',                                                    'ok' => $req_writable_func, 'required' => true],
                ['label' => 'Root Directory Writable',                                                                  'ok' => $req_writable_root, 'required' => true],
            ];
            ?>
            <ul class="bc-installer__req">
                <?php foreach ($requirements as $req):
                    $icon_class = $req['ok'] ? 'ok' : ($req['required'] ? 'fail' : 'warn');
                    $icon_bi    = $req['ok'] ? 'check-lg' : ($req['required'] ? 'x-lg' : 'exclamation');
                ?>
                <li>
                    <span class="bc-installer__reqname <?php echo (!$req['ok'] && $req['required']) ? 'text-danger fw-bold' : ''; ?>"><?php echo $req['label']; ?></span>
                    <span class="bc-installer__pill <?php echo $req['ok'] ? 'bc-installer__pill--ok' : 'bc-installer__pill--bad'; ?>"><i class="bi bi-<?php echo $icon_bi; ?>"></i> <?php echo $req['ok'] ? 'OK' : 'Missing'; ?></span>
                </li>
                <?php endforeach; ?>
            </ul>

            <div class="bg-light rounded-3 p-3 border mt-4">
                <label class="bc-installer__label" for="activation_code">System Activation</label>
                <div class="input-group mb-1">
                    <input type="text" id="activation_code" class="form-control" placeholder="Enter Activation Code (e.g. XXXX-XXXX-XXXX-XXXX)" value="" required>
                </div>
                <div class="form-text text-muted">Activation is domain-bound. "License Key" is not required.</div>
                <div id="activation-result" class="mt-2" style="display:none;"></div>
            </div>

            <div class="mt-4">
                <?php if ($all_req_met): ?>
                <button class="bc-installer__btn" onclick="verifyActivation()">
                    <i class="bi bi-arrow-right me-2"></i>Continue to Database Setup
                </button>
                <?php else: ?>
                <div class="bc-installer__alert bc-installer__alert--error">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <span>Please fix the failed requirements before continuing.</span>
                </div>
                <button class="bc-installer__btn bc-installer__btn--ghost" onclick="window.location.reload()">
                    <i class="bi bi-arrow-clockwise me-2"></i>Re-check Requirements
                </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- STEP 2: Database -->
        <div class="bc-installer__panel<?php echo $step == 2 ? ' active' : ''; ?>" id="step-2">
            <h2 class="bc-installer__title">Database Connection</h2>
            <p class="bc-installer__sub">Enter your MySQL database credentials.</p>

            <div class="bc-installer__field">
                <label class="bc-installer__label" for="db_host">Database Host</label>
                <input type="text" id="db_host" class="bc-installer__input" value="localhost" placeholder="localhost">
            </div>
            <div class="bc-installer__field">
                <label class="bc-installer__label" for="db_name">Database Name</label>
                <input type="text" id="db_name" class="bc-installer__input" placeholder="vtu_db">
            </div>
            <div class="bc-installer__field">
                <label class="bc-installer__label" for="db_user">Database Username</label>
                <input type="text" id="db_user" class="bc-installer__input" placeholder="root">
            </div>
            <div class="bc-installer__field">
                <label class="bc-installer__label" for="db_pass">Database Password</label>
                <div class="input-group">
                    <input type="password" id="db_pass" class="form-control" placeholder="Leave blank if none">
                    <button class="btn btn-outline-secondary" type="button" onclick="toggleVis('db_pass',this)"><i class="bi bi-eye"></i></button>
                </div>
            </div>

            <div id="db-test-result" class="mt-2" style="display:none;"></div>

            <div class="bc-installer__actions mt-4">
                <button class="bc-installer__btn bc-installer__btn--ghost" onclick="goStep(1)">
                    <i class="bi bi-arrow-left me-1"></i>Back
                </button>
                <button class="bc-installer__btn" onclick="testDB()" id="btn-test-db">
                    <i class="bi bi-plug me-2"></i>Test Connection
                </button>
                <button class="bc-installer__btn" onclick="goStep(3)" id="btn-next-2" disabled>
                    <i class="bi bi-arrow-right"></i>
                </button>
            </div>
        </div>

        <!-- STEP 3: Admin Account -->
        <div class="bc-installer__panel<?php echo $step == 3 ? ' active' : ''; ?>" id="step-3">
            <h2 class="bc-installer__title">Admin Account Setup</h2>
            <p class="bc-installer__sub">Configure your administrator account and site details.</p>

            <div class="section-label">Site Information</div>
            <div class="bc-installer__field">
                <label class="bc-installer__label" for="site_name">Site Name <span class="text-danger">*</span></label>
                <input type="text" id="site_name" class="bc-installer__input" placeholder="My VTU Platform" value="My VTU Platform">
            </div>

            <div class="section-label">Administrator Details</div>
            <div class="bc-installer__grid bc-installer__grid--2">
                <div class="bc-installer__field">
                    <label class="bc-installer__label" for="admin_first">First Name <span class="text-danger">*</span></label>
                    <input type="text" id="admin_first" class="bc-installer__input" placeholder="John">
                </div>
                <div class="bc-installer__field">
                    <label class="bc-installer__label" for="admin_last">Last Name</label>
                    <input type="text" id="admin_last" class="bc-installer__input" placeholder="Doe">
                </div>
                <div class="bc-installer__field">
                    <label class="bc-installer__label" for="admin_email">Email Address <span class="text-danger">*</span></label>
                    <input type="email" id="admin_email" class="bc-installer__input" placeholder="admin@yoursite.com">
                </div>
                <div class="bc-installer__field">
                    <label class="bc-installer__label" for="admin_phone">Phone Number</label>
                    <input type="text" id="admin_phone" class="bc-installer__input" placeholder="08012345678">
                </div>
                <div class="bc-installer__field">
                    <label class="bc-installer__label" for="admin_pass">Password <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <input type="password" id="admin_pass" class="form-control" placeholder="Min. 8 characters" oninput="checkPassStrength()">
                        <button class="btn btn-outline-secondary" type="button" onclick="toggleVis('admin_pass',this)"><i class="bi bi-eye"></i></button>
                    </div>
                    <div id="pass-strength" class="bc-installer__strength mt-1" style="width:0%; background:#dc2626;"></div>
                    <div id="pass-strength-label" class="small text-muted mt-1"></div>
                </div>
                <div class="bc-installer__field">
                    <label class="bc-installer__label" for="admin_pass2">Confirm Password <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <input type="password" id="admin_pass2" class="form-control" placeholder="Repeat password">
                        <button class="btn btn-outline-secondary" type="button" onclick="toggleVis('admin_pass2',this)"><i class="bi bi-eye"></i></button>
                    </div>
                </div>
            </div>

            <div class="section-label">Admin Security PIN</div>
            <p class="text-muted small mb-3">Used to confirm sensitive admin actions (crediting users, editing blog posts, etc). Set it now so you're never forced into a setup loop on first login.</p>
            <div class="bc-installer__grid bc-installer__grid--2">
                <div class="bc-installer__field">
                    <label class="bc-installer__label" for="admin_pin">4-Digit PIN <span class="text-danger">*</span></label>
                    <input type="password" id="admin_pin" class="bc-installer__input" maxlength="4" pattern="[0-9]{4}" inputmode="numeric" placeholder="****" style="letter-spacing:6px;">
                </div>
                <div class="bc-installer__field">
                    <label class="bc-installer__label" for="admin_pin2">Confirm PIN <span class="text-danger">*</span></label>
                    <input type="password" id="admin_pin2" class="bc-installer__input" maxlength="4" pattern="[0-9]{4}" inputmode="numeric" placeholder="****" style="letter-spacing:6px;">
                </div>
            </div>

            <div id="step3-error" class="bc-installer__alert bc-installer__alert--error mt-3" style="display:none;"></div>

            <div class="bc-installer__actions mt-4">
                <button class="bc-installer__btn bc-installer__btn--ghost" onclick="goStep(2)">
                    <i class="bi bi-arrow-left me-1"></i>Back
                </button>
                <button class="bc-installer__btn" onclick="validateStep3()">
                    <i class="bi bi-rocket-takeoff me-2"></i>Install Now
                </button>
            </div>
        </div>

        <!-- STEP 4: Installing / Complete / Error -->
        <div class="bc-installer__panel<?php echo $step == 4 ? ' active' : ''; ?>" id="step-4">
            <div id="installing-view" class="text-center py-4">
                <div class="bc-installer__spinner" role="status"></div>
                <h2 class="bc-installer__title">Installing...</h2>
                <p class="text-muted">Please wait while we set up your platform.</p>
                <div class="bc-installer__log" id="install-log">Initializing...</div>
                <div class="bc-installer__progress">
                    <div class="bc-installer__progressbar" id="install-progress"></div>
                </div>
                <div id="install-status" class="small text-muted mt-2">&nbsp;</div>
            </div>

            <div id="done-view" style="display:none;" class="bc-installer__success">
                <div class="bc-installer__successicon"><i class="bi bi-check-lg" style="font-size:1.8rem;"></i></div>
                <h2 class="bc-installer__title" style="color:var(--ok);">Installation Complete!</h2>
                <p class="text-muted mb-4">Your standalone VTU platform is ready. All APIs are seeded and all features are unlocked.</p>
                <div class="bg-light rounded-3 p-4 text-start mb-4">
                    <div class="small fw-bold text-muted text-uppercase mb-2">Your Login Credentials</div>
                    <div class="d-flex gap-2 align-items-center mb-2">
                        <i class="bi bi-envelope-fill" style="color:var(--brand);"></i>
                        <span id="done-email" class="fw-bold"></span>
                    </div>
                    <div class="d-flex gap-2 align-items-center">
                        <i class="bi bi-lock-fill" style="color:var(--brand);"></i>
                        <span>Your chosen password</span>
                    </div>
                </div>
                <a href="/bc-admin/Login.php" class="bc-installer__btn w-100">
                    <i class="bi bi-box-arrow-in-right me-2"></i>Go to Admin Login
                </a>
            </div>

            <div id="error-view" style="display:none;" class="text-center py-4">
                <div style="width:80px;height:80px;background:#fee2e2;border-radius:50%;display:grid;place-items:center;margin:0 auto 20px;">
                    <i class="bi bi-x-lg text-danger" style="font-size:2rem;"></i>
                </div>
                <h2 class="bc-installer__title text-danger">Installation Failed</h2>
                <p id="error-msg" class="text-muted"></p>
                <button class="bc-installer__btn bc-installer__btn--ghost" onclick="resetInstall()">
                    <i class="bi bi-arrow-clockwise me-2"></i>Start Over
                </button>
            </div>
        </div>
    </div>
<?php
bc_installer_foot('', __INSTALLER_SCRIPTS__);


<?php
// Public School Registration Page
define('PORTAL_PATH', __DIR__);

if (!file_exists(PORTAL_PATH . '/config/config.php')) {
    die('App not installed. Please run the installer first.');
}

require_once PORTAL_PATH . '/config/config.php';
require_once PORTAL_PATH . '/include/db.php';
require_once PORTAL_PATH . '/include/favicon_helper.php';


session_start();

// ── AJAX: Slug availability check ────────────────────────────
if (isset($_GET['check_slug'])) {
    header('Content-Type: application/json');
    $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($_GET['check_slug'])));
    $stmt = $pdo->prepare("SELECT id FROM sm_schools WHERE slug = ?");
    $stmt->execute([$slug]);
    echo json_encode(['available' => !$stmt->fetch(), 'slug' => $slug]);
    exit;
}

$error   = '';
$success = '';

// Fetch platform global settings
$platform_settings = [];
$stmtCon = $pdo->query("SELECT * FROM sm_global_settings");
foreach ($stmtCon->fetchAll() as $row) {
    $platform_settings[$row['setting_key']] = $row['setting_value'];
}

// Fetch all subscription packages
$packages = $pdo->query("SELECT * FROM sm_subscription_packages ORDER BY price ASC")->fetchAll();

// Fetch all domain TLDs
$tlds = $pdo->query("SELECT * FROM sm_domain_tlds ORDER BY id ASC")->fetchAll();

// Fetch addon prices and descriptions
$addons_info = [
    'CBT' => [
        'price' => (float)($platform_settings['price_addon_cbt'] ?? 15000.00),
        'desc' => $platform_settings['desc_addon_cbt'] ?? 'Computer Based Testing with automatic grading and analysis'
    ],
    'Bulk Report Card' => [
        'price' => (float)($platform_settings['price_addon_bulk_report_card'] ?? 25000.00),
        'desc' => $platform_settings['desc_addon_bulk_report_card'] ?? 'Process and print student terminal report cards in bulk'
    ],
    'Data Cleanup' => [
        'price' => (float)($platform_settings['price_addon_data_cleanup'] ?? 10000.00),
        'desc' => $platform_settings['desc_addon_data_cleanup'] ?? 'Wipe records or reset session databases cleanly'
    ],
    'ID Card' => [
        'price' => (float)($platform_settings['price_addon_id_card'] ?? 12000.00),
        'desc' => $platform_settings['desc_addon_id_card'] ?? 'Generate beautiful barcode-supported printable ID Cards'
    ],
    'Live Classes' => [
        'price' => (float)($platform_settings['price_addon_live_classes'] ?? 30000.00),
        'desc' => $platform_settings['desc_addon_live_classes'] ?? 'Host online audio/video classes with interactive screenshare'
    ],
    'SMS' => [
        'price' => (float)($platform_settings['price_addon_sms'] ?? 20000.00),
        'desc' => $platform_settings['desc_addon_sms'] ?? 'Send SMS notifications to parents and staff'
    ]
];

// ── Handle registration form ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_school'])) {
    try {
        $school_name  = trim($_POST['school_name']);
        $motto        = trim($_POST['motto']);
        $slug         = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($_POST['slug'])));
        $admin_name   = trim($_POST['admin_name']);
        $admin_email  = strtolower(trim($_POST['admin_email']));
        $admin_pass   = $_POST['admin_password'];
        $confirm_pass = $_POST['confirm_password'];
        $package_id   = isset($_POST['package_id']) ? (int)$_POST['package_id'] : 0;
        
        $domain_option = $_POST['domain_option'] ?? 'subdomain';
        $custom_domain = trim($_POST['custom_domain'] ?? '');
        $tld_id = isset($_POST['tld_id']) ? (int)$_POST['tld_id'] : 0;
        $selected_addons = $_POST['addons'] ?? [];

        // Validation
        if (empty($school_name) || empty($slug) || empty($admin_email) || empty($admin_pass) || empty($package_id)) {
            $error = 'All fields including plan selection are required.';
        } elseif (!filter_var($admin_email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (strlen($admin_pass) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($admin_pass !== $confirm_pass) {
            $error = 'Passwords do not match.';
        } elseif (strlen($slug) < 3) {
            $error = 'School slug must be at least 3 characters.';
        } else {
            // Find selected package details
            $selectedPkg = null;
            foreach ($packages as $pkg) {
                if ($pkg['id'] == $package_id) {
                    $selectedPkg = $pkg;
                    break;
                }
            }

            if (!$selectedPkg) {
                $error = 'Please select a valid subscription package plan.';
            }

            // Domain Processing & Price calculation
            $domain_yearly_price = 0.00;
            $resolved_domain = null;
            if ($domain_option === 'existing') {
                if (empty($custom_domain)) {
                    $error = 'Please enter your existing domain address.';
                } else {
                    $resolved_domain = strtolower($custom_domain);
                }
            } elseif ($domain_option === 'register') {
                if (empty($custom_domain)) {
                    $error = 'Please enter domain name to register.';
                } else {
                    $stmtTld = $pdo->prepare("SELECT * FROM sm_domain_tlds WHERE id = ?");
                    $stmtTld->execute([$tld_id]);
                    $tld_row = $stmtTld->fetch();
                    if (!$tld_row) {
                        $error = 'Please select a valid domain extension.';
                    } else {
                        $resolved_domain = strtolower($custom_domain . $tld_row['tld']);
                        $domain_yearly_price = (float)$tld_row['normal_price'];
                    }
                }
            }

            // Check slug uniqueness
            if (!$error) {
                $slugCheck = $pdo->prepare("SELECT id FROM sm_schools WHERE slug = ?");
                $slugCheck->execute([$slug]);
                if ($slugCheck->fetch()) {
                    $error = 'That school slug is already taken. Please choose a different one.';
                }
            }

            // Check custom domain uniqueness
            if (!$error && $resolved_domain) {
                $domainCheck = $pdo->prepare("SELECT id FROM sm_schools WHERE custom_domain = ?");
                $domainCheck->execute([$resolved_domain]);
                if ($domainCheck->fetch()) {
                    $error = 'That custom domain is already linked to another school.';
                }
            }

            // Check email uniqueness across all users
            if (!$error) {
                $emailCheck = $pdo->prepare("SELECT email FROM sm_moderators WHERE email = ?");
                $emailCheck->execute([$admin_email]);
                if ($emailCheck->fetch()) {
                    $error = 'An account with that email already exists.';
                }
            }

            if (!$error) {
                $pdo->beginTransaction();

                // Generate unique school ID
                $school_id = strtoupper(preg_replace('/[^A-Z0-9]/', '', strtoupper($slug))) . '_' . strtoupper(bin2hex(random_bytes(4)));

                // Calculate subscription expiration
                $expires_at = null;
                if ($selectedPkg['billing_cycle'] === 'monthly') {
                    $expires_at = date('Y-m-d H:i:s', strtotime('+1 month'));
                } elseif ($selectedPkg['billing_cycle'] === 'yearly') {
                    $expires_at = date('Y-m-d H:i:s', strtotime('+1 year'));
                } elseif ($selectedPkg['billing_cycle'] === 'lifetime') {
                    $expires_at = date('Y-m-d H:i:s', strtotime('+50 years'));
                }
                $subscription_status = 'active';

                // Insert school
                $stmtSch = $pdo->prepare("INSERT INTO sm_schools (id, slug, name, motto, status, package_id, subscription_status, subscription_expires_at, custom_domain, domain_type, domain_yearly_price, created_at) VALUES (?, ?, ?, ?, 'active', ?, ?, ?, ?, ?, ?, NOW())");
                $stmtSch->execute([$school_id, $slug, $school_name, $motto, $package_id, $subscription_status, $expires_at, $resolved_domain, $domain_option, $domain_yearly_price]);

                // Create admin moderator account
                $hashed = password_hash($admin_pass, PASSWORD_DEFAULT);
                $stmtMod = $pdo->prepare("INSERT INTO sm_moderators (school_id_number, fullname, email, password) VALUES (?, ?, ?, ?)");
                $stmtMod->execute([$school_id, $admin_name, $admin_email, $hashed]);

                // Provision package-default addons
                $addons_to_enable = [];
                if (!empty($selectedPkg['included_addons'])) {
                    $addons_to_enable = array_map('trim', explode(',', $selectedPkg['included_addons']));
                }
                // Merge with user chosen addons
                foreach ($selected_addons as $addon_name) {
                    if (isset($addons_info[$addon_name]) && !in_array($addon_name, $addons_to_enable)) {
                        $addons_to_enable[] = $addon_name;
                    }
                }

                if (!empty($addons_to_enable)) {
                    $stmtAddon = $pdo->prepare("INSERT INTO sm_school_addons (school_id_number, addon_name, status, activated_at) VALUES (?, ?, 'active', NOW())");
                    foreach ($addons_to_enable as $addon) {
                        if (!empty($addon)) {
                            $stmtAddon->execute([$school_id, $addon]);
                        }
                    }
                }

                $pdo->commit();
                $success = $school_id;
            }
        }
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = 'Registration failed: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register Your School — SchoolApp</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo getFaviconDataUri($pdo, $_SESSION['school_id'] ?? null, null); ?>" />

    <meta name="description" content="Create your school's management portal on SchoolApp. Get instant access to student management, CBT exams, fee billing, and more.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --bg:       #080d18;
            --surface:  rgba(255,255,255,0.04);
            --border:   rgba(255,255,255,0.09);
            --accent:   #4f7fff;
            --accent2:  #7c5cfc;
            --success:  #22c55e;
            --danger:   #ef4444;
            --text:     #e8ecf4;
            --muted:    #cbd5e1;
            --radius:   14px;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            background-image:
                radial-gradient(ellipse 80% 50% at 20% -10%, rgba(79,127,255,.18) 0%, transparent 60%),
                radial-gradient(ellipse 60% 40% at 80% 110%, rgba(124,92,252,.14) 0%, transparent 55%);
        }

        /* Two-column layout */
        .page-wrap {
            display: grid;
            grid-template-columns: 300px 1fr;
            min-height: 100vh;
        }

        /* Left Hero */
        .hero {
            padding: 50px 24px;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            border-right: 1px solid var(--border);
            position: sticky;
            top: 0;
            height: 100vh;
            overflow: hidden;
        }
        .hero::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(79,127,255,.06) 0%, transparent 60%);
            pointer-events: none;
        }
        .badge {
            display: inline-flex; align-items: center; gap: 8px;
            background: rgba(79,127,255,.12); border: 1px solid rgba(79,127,255,.3);
            color: #93b4ff; font-size: 11px; font-weight: 600; letter-spacing: .08em;
            padding: 4px 14px; border-radius: 99px; margin-bottom: 28px;
            text-transform: uppercase; width: fit-content;
        }
        .hero h1 {
            font-size: clamp(1.8rem, 2.2vw, 2.4rem);
            font-weight: 900; line-height: 1.1; letter-spacing: -.03em;
            background: linear-gradient(135deg, #93b4ff 0%, #c4b5fd 100%);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            margin-bottom: 20px;
        }
        .hero p { color: var(--muted); font-size: 14px; line-height: 1.6; max-width: 100%; }

        .feature-list { list-style: none; margin-top: 40px; display: flex; flex-direction: column; gap: 14px; }
        .feature-list li {
            display: flex; align-items: center; gap: 12px;
            font-size: 13px; color: #a0a8c0; font-weight: 500;
        }
        .feature-list li span.icon {
            width: 32px; height: 32px; border-radius: 8px; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center; font-size: 14px;
            background: rgba(79,127,255,.12); border: 1px solid rgba(79,127,255,.2);
        }

        .already-link { margin-top: 48px; font-size: 13px; color: var(--muted); }
        .already-link a { color: var(--accent); text-decoration: none; font-weight: 600; }
        .already-link a:hover { text-decoration: underline; }

        /* Right Form */
        .form-side {
            padding: 40px;
        }
        .form-title { font-size: 22px; font-weight: 800; margin-bottom: 6px; }
        .form-sub   { font-size: 13px; color: var(--muted); margin-bottom: 32px; }

        .form-section {
            border: 1px solid var(--border); border-radius: var(--radius);
            padding: 20px 20px 16px; margin-bottom: 20px; background: var(--surface);
        }
        .form-section-title {
            font-size: 11px; font-weight: 700; color: var(--muted);
            letter-spacing: .08em; text-transform: uppercase; margin-bottom: 16px;
        }
        .field { margin-bottom: 14px; }
        label  { display: block; font-size: 12px; font-weight: 600; color: var(--muted); margin-bottom: 5px; letter-spacing: .04em; text-transform: uppercase; }

        input[type=text], input[type=email], input[type=password] {
            width: 100%; background: rgba(0,0,0,.25); border: 1px solid var(--border);
            border-radius: 10px; color: var(--text); font-size: 14px; font-family: inherit;
            padding: 11px 14px; outline: none;
            transition: border-color .2s, box-shadow .2s;
        }
        input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(79,127,255,.18); }
        input::placeholder { color: var(--muted); }

        /* Slug field */
        .slug-wrap { position: relative; }
        .slug-wrap input { padding-right: 100px; font-family: 'Courier New', monospace; }
        .slug-status {
            position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
            font-size: 11px; font-weight: 700; letter-spacing: .04em;
        }
        .slug-status.ok   { color: var(--success); }
        .slug-status.bad  { color: var(--danger); }
        .slug-status.idle { color: var(--muted); }
        .slug-preview { font-size: 11px; color: var(--muted); margin-top: 5px; }
        .slug-preview span { color: #93b4ff; font-family: monospace; }

        .btn-submit {
            width: 100%; padding: 14px; font-size: 15px; font-weight: 800;
            font-family: inherit; border: none; border-radius: 12px; cursor: pointer;
            background: linear-gradient(135deg, var(--accent), var(--accent2));
            color: #fff; box-shadow: 0 4px 24px rgba(79,127,255,.35);
            transition: all .2s; letter-spacing: .02em; margin-top: 8px;
        }
        .btn-submit:hover { transform: translateY(-1px); box-shadow: 0 8px 32px rgba(79,127,255,.45); }
        .btn-submit:active { transform: none; }
        .btn-submit:disabled { opacity: .5; cursor: not-allowed; transform: none; }

        .alert {
            padding: 14px 16px; border-radius: 12px; font-size: 13px; margin-bottom: 20px;
            border: 1px solid transparent; display: flex; gap: 10px; align-items: flex-start;
        }
        .alert.err  { background: rgba(239,68,68,.08); border-color: rgba(239,68,68,.25); color: #fca5a5; }
        .alert.ok   { background: rgba(34,197,94,.08);  border-color: rgba(34,197,94,.25);  color: #86efac; }

        /* Success screen */
        .success-screen { text-align: center; padding: 40px 20px; }
        .success-icon   { font-size: 4rem; margin-bottom: 20px; animation: pop .5s ease; }
        .success-screen h2 { font-size: 1.7rem; font-weight: 800; color: #86efac; margin-bottom: 10px; }
        .success-screen p  { color: var(--muted); font-size: 14px; line-height: 1.7; }
        .cred-box {
            margin: 24px auto; max-width: 380px; text-align: left;
            background: rgba(0,0,0,.3); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 18px 20px;
        }
        .cred-box .cred-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; font-size: 13px; }
        .cred-box .cred-label { color: var(--muted); }
        .cred-box .cred-val   { color: var(--text); font-weight: 600; font-family: monospace; }
        .btn-login {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 13px 32px; background: linear-gradient(135deg, var(--success), #16a34a);
            color: #fff; border-radius: 12px; font-weight: 800; text-decoration: none;
            font-size: 14px; margin-top: 20px; transition: all .2s;
        }
        .btn-login:hover { transform: translateY(-1px); box-shadow: 0 8px 24px rgba(34,197,94,.35); }

        @keyframes pop { 0%{transform:scale(0)} 70%{transform:scale(1.2)} 100%{transform:scale(1)} }

        @media (max-width: 1024px) {
            .page-wrap { grid-template-columns: 1fr; }
            .hero { display: none; }
            .form-side { padding: 40px 24px; }
        }
        @media (max-width: 480px) {
            .form-side { padding: 24px 16px; }
        }

        /* Responsive Grid & Checkout styles */
        .plans-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 16px;
            margin-top: 10px;
        }
        @media (min-width: 480px) {
            .plans-grid {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            }
        }
        .plan-card {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 18px;
            cursor: pointer;
            position: relative;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .plan-card:hover {
            border-color: rgba(79, 127, 255, 0.35);
            transform: translateY(-2px);
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.4);
            background: rgba(255, 255, 255, 0.04);
        }
        .plan-card.selected {
            border-color: var(--accent);
            background: rgba(79, 127, 255, 0.08);
            box-shadow: 0 0 15px rgba(79, 127, 255, 0.2), inset 0 0 10px rgba(79, 127, 255, 0.1);
        }
        .plan-card input[type="radio"] {
            position: absolute;
            top: 16px;
            right: 16px;
            accent-color: var(--accent);
            cursor: pointer;
            width: 18px;
            height: 18px;
        }
        .plan-badge {
            font-size: 9px;
            font-weight: 800;
            background: linear-gradient(135deg, var(--accent2), #9333ea);
            color: #fff;
            padding: 3px 10px;
            border-radius: 99px;
            text-transform: uppercase;
            width: fit-content;
            margin-bottom: 10px;
            letter-spacing: 0.06em;
            box-shadow: 0 2px 8px rgba(124, 92, 252, 0.4);
        }
        .plan-name {
            font-size: 16px;
            font-weight: 800;
            color: #fff;
            margin-bottom: 6px;
        }
        .plan-desc {
            font-size: 11px;
            color: var(--muted);
            line-height: 1.5;
            margin-bottom: 16px;
            flex-grow: 1;
        }
        .plan-price-box {
            border-top: 1px solid var(--border);
            padding-top: 10px;
            margin-top: 10px;
            display: flex;
            justify-content: space-between;
            align-items: baseline;
        }
        .plan-price {
            font-size: 18px;
            font-weight: 950;
            color: var(--success);
        }
        .plan-cycle {
            font-size: 10px;
            color: var(--muted);
        }

        /* Domain Setup Options style */
        .domain-options-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 12px;
            margin-top: 12px;
        }
        @media (min-width: 480px) and (max-width: 767px) {
            .domain-options-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }
        @media (min-width: 768px) and (max-width: 1099px) {
            .domain-options-grid {
                grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            }
        }
        @media (min-width: 1100px) {
            .domain-options-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }
        .domain-opt-card {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 12px 8px;
            cursor: pointer;
            text-align: center;
            transition: all 0.25s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
        }
        .domain-opt-card:hover {
            border-color: rgba(79, 127, 255, 0.3);
            background: rgba(255, 255, 255, 0.04);
        }
        .domain-opt-card.selected {
            border-color: var(--accent);
            background: rgba(79, 127, 255, 0.06);
            box-shadow: 0 0 12px rgba(79, 127, 255, 0.15);
        }
        .domain-opt-icon {
            font-size: 20px;
        }
        .domain-opt-title {
            font-size: 12px;
            font-weight: 700;
            color: #fff;
        }
        .domain-opt-desc {
            font-size: 10px;
            color: var(--muted);
            line-height: 1.3;
        }

        .domain-input-box {
            background: rgba(0, 0, 0, 0.15);
            border: 1px dashed var(--border);
            border-radius: 12px;
            padding: 16px;
            margin-top: 14px;
        }

        /* Addons checklist */
        .addon-item-row {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 12px 16px;
            margin-bottom: 10px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .addon-item-row:hover {
            background: rgba(255, 255, 255, 0.04);
            border-color: rgba(79,127,255,0.25);
        }
        .addon-item-row.selected {
            border-color: var(--accent);
            background: rgba(79, 127, 255, 0.04);
        }
        .addon-checkbox {
            margin-top: 4px;
            accent-color: var(--accent);
            width: 16px;
            height: 16px;
            cursor: pointer;
        }
        .addon-text {
            flex-grow: 1;
        }
        .addon-row-title {
            font-size: 13px;
            font-weight: 700;
            color: #fff;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .addon-row-price {
            color: var(--success);
            font-weight: 800;
        }
        .addon-row-desc {
            font-size: 11px;
            color: var(--muted);
            margin-top: 2px;
            line-height: 1.4;
        }

        /* Checkout Summary panel */
        .checkout-side {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.03) 0%, rgba(255, 255, 255, 0.01) 100%);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 24px;
            position: sticky;
            top: 24px;
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            z-index: 10;
        }
        .checkout-header {
            font-size: 16px;
            font-weight: 800;
            color: #fff;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .checkout-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            font-size: 13px;
        }
        .checkout-label {
            color: var(--muted);
        }
        .checkout-val {
            color: #fff;
            font-weight: 600;
        }
        .checkout-total-box {
            margin-top: 20px;
            padding-top: 16px;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .checkout-total-label {
            font-size: 14px;
            font-weight: 800;
            color: #fff;
        }
        .checkout-total-val {
            font-size: 24px;
            font-weight: 900;
            color: var(--accent);
            text-shadow: 0 0 10px rgba(79, 127, 255, 0.25);
        }
        
        .three-column-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 24px;
        }
        @media (min-width: 768px) {
            .three-column-grid {
                grid-template-columns: 1.6fr 1fr;
            }
        }

        .checkout-wrap {
            position: relative;
            height: 100%;
        }

        /* Standard CSS Utility Classes */
        .flex { display: flex !important; }
        .flex-col { flex-direction: column !important; }
        .items-center { align-items: center !important; }
        .justify-between { justify-content: space-between !important; }
        .flex-grow { flex-grow: 1 !important; }
        .gap-2 { gap: 8px !important; }
        .gap-4 { gap: 16px !important; }
        .w-full { width: 100% !important; }
        .mt-2 { margin-top: 8px !important; }
        .mt-3 { margin-top: 12px !important; }
        .mt-6 { margin-top: 24px !important; }
        .mb-3 { margin-bottom: 12px !important; }
        .p-4 { padding: 16px !important; }
        .rounded-xl { border-radius: 12px !important; }
        .text-xs { font-size: 12px !important; }
        .text-sm { font-size: 13px !important; }
        .text-gray-400 { color: #94a3b8 !important; }
        .bg-black { background-color: #000 !important; }
        .bg-black\/30 { background-color: rgba(0, 0, 0, 0.3) !important; }
        .bg-blue-500\/10 { background-color: rgba(59, 130, 246, 0.1) !important; }
        .border-blue-500\/20 { border-color: rgba(59, 130, 246, 0.2) !important; }
        .text-blue-400 { color: #60a5fa !important; }
        .font-mono { font-family: monospace !important; }
        .text-white { color: #fff !important; }
        .grid { display: grid !important; }
        .grid-cols-2 { grid-template-columns: repeat(2, 1fr) !important; }
        .space-y-2 > * + * { margin-top: 8px !important; }
        .space-y-6 > * + * { margin-top: 24px !important; }

        /* Enhanced Domain Search Bar */
        .domain-search-bar {
            display: flex;
            align-items: center;
            background: rgba(0, 0, 0, 0.25);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 4px;
            gap: 4px;
            transition: border-color 0.25s, box-shadow 0.25s;
            margin-top: 8px;
        }
        .domain-search-bar:focus-within {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(79, 127, 255, 0.18);
        }
        .domain-search-prefix {
            font-size: 13px;
            color: var(--muted);
            padding-left: 12px;
            font-family: monospace;
            user-select: none;
        }
        .domain-search-bar input[type="text"] {
            flex-grow: 1;
            background: transparent;
            border: none;
            border-radius: 0;
            color: var(--text);
            font-size: 14px;
            padding: 10px 8px;
            outline: none;
            width: 100%;
        }
        .domain-search-bar input[type="text"]:focus {
            box-shadow: none;
            border-color: transparent;
        }
        .domain-select-wrapper {
            position: relative;
            border-left: 1px solid var(--border);
            padding-left: 8px;
            margin-right: 4px;
            display: flex;
            align-items: center;
        }
        .domain-search-bar select {
            background: rgba(0, 0, 0, 0.4);
            color: #fff;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            padding: 8px 28px 8px 12px;
            outline: none;
            cursor: pointer;
            appearance: none;
            -webkit-appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%23cbd5e1'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 10px center;
            background-size: 10px;
        }
        .domain-search-bar select:focus {
            border-color: var(--accent);
        }
        .domain-search-btn {
            background: linear-gradient(135deg, var(--accent), var(--accent2));
            color: #fff;
            border: none;
            border-radius: 10px;
            padding: 10px 20px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }
        .domain-search-btn:hover {
            opacity: 0.95;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(79, 127, 255, 0.25);
        }
        .domain-search-btn:active {
            transform: none;
        }
        .domain-search-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }
        #domain-search-result {
            margin-top: 12px;
            transition: all 0.3s ease;
        }
        .domain-search-result-box {
            padding: 12px 14px;
            border-radius: 10px;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideDown 0.2s ease-out;
        }
        .domain-search-result-box.available {
            background: rgba(34, 197, 94, 0.08);
            border: 1px solid rgba(34, 197, 94, 0.25);
            color: #86efac;
        }
        .domain-search-result-box.taken {
            background: rgba(239, 68, 68, 0.08);
            border: 1px solid rgba(239, 68, 68, 0.25);
            color: #fca5a5;
        }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-4px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>
<div class="page-wrap">

    <!-- ── LEFT HERO ─────────────────────────────────── -->
    <div class="hero">
        <div class="badge">🚀 SchoolApp Platform</div>
        <h1>Launch Your School's Digital Portal</h1>
        <p>Everything your school needs — student management, CBT examinations, fee billing, attendance, and more. Set up in minutes.</p>

        <ul class="feature-list">
            <li><span class="icon">🎓</span> Full student admission & registry management</li>
            <li><span class="icon">📝</span> CBT exams, grading & report card generation</li>
            <li><span class="icon">💰</span> Fee billing, invoicing & payment tracking</li>
            <li><span class="icon">📱</span> SMS broadcast to parents & students</li>
            <li><span class="icon">📚</span> LMS curriculum & homework management</li>
            <li><span class="icon">🤖</span> AI Copilot for teachers & educators</li>
        </ul>

        <div class="already-link">
            Already registered? <a href="/login.php">Log in to your school portal →</a>
        </div>
    </div>

    <!-- ── RIGHT FORM ─────────────────────────────────── -->
    <div class="form-side">

        <?php if ($success): ?>
        <!-- Success Screen -->
        <div class="success-screen">
            <div class="success-icon">🎉</div>
            <h2>School Registered!</h2>
            <p>Your school portal has been created and is ready to use.<br>Log in below using the credentials you just set.</p>
            <div class="cred-box">
                <div class="cred-row">
                    <span class="cred-label">School ID</span>
                    <span class="cred-val"><?php echo htmlspecialchars($success); ?></span>
                </div>
                <div class="cred-row">
                    <span class="cred-label">Email</span>
                    <span class="cred-val"><?php echo htmlspecialchars($_POST['admin_email']); ?></span>
                </div>
                <div class="cred-row">
                    <span class="cred-label">Role</span>
                    <span class="cred-val">School Admin</span>
                </div>
            </div>
            <a href="/login.php" class="btn-login">→ Go to Login</a>
        </div>

        <?php else: ?>

        <div class="form-title">Register Your School</div>
        <div class="form-sub">Fill in the details below to create your school's platform.</div>

        <?php if ($error): ?>
            <div class="alert err">⚠️ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="post" id="regForm" novalidate>
            <div class="three-column-grid">
                
                <!-- Main Form Inputs -->
                <div class="space-y-6">
                    <!-- Subscription Package Select -->
                    <div class="form-section">
                        <div class="form-section-title">💳 1. Select Subscription Plan</div>
                        <div class="plans-grid">
                            <?php 
                            $first = true;
                            foreach ($packages as $pkg): 
                                $isSelected = (isset($_POST['package_id']) && $_POST['package_id'] == $pkg['id']) || ($first && !isset($_POST['package_id']));
                                $first = false;
                            ?>
                                <div class="plan-card <?php echo $isSelected ? 'selected' : ''; ?>" onclick="selectPlan(this, <?php echo $pkg['id']; ?>, <?php echo (float)$pkg['price']; ?>)" data-included-addons="<?php echo htmlspecialchars($pkg['included_addons'] ?? ''); ?>">
                                    <input type="radio" name="package_id" value="<?php echo $pkg['id']; ?>" <?php echo $isSelected ? 'checked' : ''; ?> style="opacity: 0;">
                                    <div>
                                        <?php if ($pkg['billing_cycle'] === 'lifetime'): ?>
                                            <div class="plan-badge">LIFETIME</div>
                                        <?php endif; ?>
                                        <div class="plan-name"><?php echo htmlspecialchars($pkg['name']); ?></div>
                                        <div class="plan-desc"><?php echo htmlspecialchars($pkg['description']); ?></div>
                                    </div>
                                    <div class="plan-price-box">
                                        <div class="plan-price">₦<?php echo number_format($pkg['price'], 2); ?></div>
                                        <div class="plan-cycle"><?php echo $pkg['billing_cycle'] === 'lifetime' ? 'one-time payment' : 'per year'; ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Domain & URL Configuration -->
                    <div class="form-section">
                        <div class="form-section-title">🌐 2. Domain & URL Setup</div>
                        <p class="text-xs text-gray-400 mb-3">Notice: Domain name registration is not free. Choose how you want to configure your portal's URL:</p>
                        
                        <div class="domain-options-grid">
                            <div class="domain-opt-card selected" onclick="selectDomainOption('subdomain')">
                                <span class="domain-opt-icon">⚡</span>
                                <span class="domain-opt-title">Free Subdomain</span>
                                <span class="domain-opt-desc">yourschool.schoolapp.ng</span>
                            </div>
                            <div class="domain-opt-card" onclick="selectDomainOption('existing')">
                                <span class="domain-opt-icon">📦</span>
                                <span class="domain-opt-title">Existing Domain</span>
                                <span class="domain-opt-desc">Point nameservers/DNS</span>
                            </div>
                            <div class="domain-opt-card" onclick="selectDomainOption('register')">
                                <span class="domain-opt-icon">🌎</span>
                                <span class="domain-opt-title">Register New</span>
                                <span class="domain-opt-desc">Lookup & Register TLD</span>
                            </div>
                        </div>

                        <input type="hidden" name="domain_option" id="domain_option" value="subdomain">

                        <!-- Subdomain Input wrapper -->
                        <div id="subdomain-wrapper" class="domain-input-box">
                            <div class="field">
                                <label>Portal Slug Prefix</label>
                                <div class="slug-wrap">
                                    <input type="text" name="slug" id="slug"
                                           value="<?php echo htmlspecialchars($_POST['slug'] ?? ''); ?>"
                                           placeholder="e.g. greenfield-academy" required>
                                    <span class="slug-status idle" id="slugStatus">...</span>
                                </div>
                                <div class="slug-preview">Default link: <span id="slugPreview">your-school-slug</span>.schoolapp.ng</div>
                            </div>
                        </div>

                        <!-- Existing Custom Domain Pointing wrapper -->
                        <div id="existing-wrapper" class="domain-input-box" style="display: none;">
                            <div class="field">
                                <label>Your Existing Domain / Subdomain</label>
                                <input type="text" name="custom_domain" id="custom_domain" placeholder="e.g. portal.greenfieldacademy.com">
                            </div>
                            <div class="p-4 bg-blue-500/10 border border-blue-500/20 text-blue-400 rounded-xl text-xs space-y-2 mt-2">
                                <strong>Instructions:</strong> Point your domain's A-Record or Nameservers to link it:
                                <div class="grid grid-cols-2 gap-2 mt-2 bg-black/30 p-2 rounded">
                                    <div>Nameservers:</div>
                                    <div class="font-mono text-white">
                                        <?php 
                                        $ns_list = explode(',', $platform_settings['nameservers'] ?? 'ns1.pmhserver.name.ng,ns2.pmhserver.name.ng');
                                        foreach ($ns_list as $ns) {
                                            if (!empty(trim($ns))) {
                                                echo htmlspecialchars(trim($ns)) . '<br>';
                                            }
                                        }
                                        ?>
                                    </div>
                                    <div>A-Record IP:</div>
                                    <div class="font-mono text-white"><?php echo htmlspecialchars($platform_settings['ip_address'] ?? '173.208.213.194'); ?></div>
                                </div>
                            </div>
                        </div>

                        <!-- Register New Domain via WHMCS -->
                        <div id="register-wrapper" class="domain-input-box" style="display: none;">
                            <label>Search for Domain Name</label>
                            <div class="domain-search-bar">
                                <span class="domain-search-prefix">www.</span>
                                <input type="text" id="register_domain_input" placeholder="e.g. greenfieldacademy">
                                <div class="domain-select-wrapper">
                                    <select id="tld_id" name="tld_id">
                                        <?php foreach ($tlds as $tld_row): ?>
                                            <option value="<?php echo $tld_row['id']; ?>" data-promo="<?php echo (float)$tld_row['promo_price']; ?>" data-normal="<?php echo (float)$tld_row['normal_price']; ?>"><?php echo htmlspecialchars($tld_row['tld']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <button type="button" onclick="checkCustomDomain()" class="domain-search-btn" id="domainSearchBtn">
                                    <span>🔍 Search</span>
                                </button>
                            </div>
                            <div id="domain-search-result"></div>
                        </div>
                    </div>

                    <!-- Service Addons selection checklist -->
                    <div class="form-section">
                        <div class="form-section-title">📦 3. Choose Service Add-ons</div>
                        <p class="text-xs text-gray-400 mb-3">Optional addons can be integrated instantly into your school portal:</p>
                        
                        <?php foreach ($addons_info as $addon_name => $info): ?>
                            <div class="addon-item-row" data-addon-name="<?php echo htmlspecialchars($addon_name); ?>" data-addon-price="<?php echo $info['price']; ?>" onclick="toggleAddonRow(this, '<?php echo htmlspecialchars($addon_name); ?>', <?php echo $info['price']; ?>)">
                                <input type="checkbox" name="addons[]" value="<?php echo htmlspecialchars($addon_name); ?>" class="addon-checkbox" onclick="event.stopPropagation(); updateCheckoutPrices();">
                                <div class="addon-text">
                                    <div class="addon-row-title">
                                        <span><?php echo htmlspecialchars($addon_name); ?></span>
                                        <span class="addon-row-price">₦<?php echo number_format($info['price'], 0); ?> / Year</span>
                                    </div>
                                    <div class="addon-row-desc"><?php echo htmlspecialchars($info['desc']); ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- School details & Admin setup -->
                    <div class="form-section">
                        <div class="form-section-title">🏫 4. School Information</div>
                        <div class="field">
                            <label>School Name</label>
                            <input type="text" name="school_name" id="school_name"
                                   value="<?php echo htmlspecialchars($_POST['school_name'] ?? ''); ?>"
                                   placeholder="e.g. Greenfield Academy" required>
                        </div>
                        <div class="field">
                            <label>School Motto <span style="color:var(--muted);font-weight:400">(optional)</span></label>
                            <input type="text" name="motto"
                                   value="<?php echo htmlspecialchars($_POST['motto'] ?? ''); ?>"
                                   placeholder="e.g. Excellence in Learning">
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-title">👤 5. Admin Account</div>
                        <div class="field">
                            <label>Full Name</label>
                            <input type="text" name="admin_name"
                                   value="<?php echo htmlspecialchars($_POST['admin_name'] ?? ''); ?>"
                                   placeholder="Your full name" required>
                        </div>
                        <div class="field">
                            <label>Email Address</label>
                            <input type="email" name="admin_email"
                                   value="<?php echo htmlspecialchars($_POST['admin_email'] ?? ''); ?>"
                                   placeholder="admin@yourschool.com" required>
                        </div>
                        <div class="field">
                            <label>Password <span style="color:var(--muted);font-weight:400">(min 8 characters)</span></label>
                            <input type="password" name="admin_password" id="admin_password" placeholder="••••••••••" required>
                        </div>
                        <div class="field" style="margin-bottom:0">
                            <label>Confirm Password</label>
                            <input type="password" name="confirm_password" id="confirm_password" placeholder="••••••••••" required>
                        </div>
                    </div>
                </div>

                <!-- Checkout Summary Side Pane -->
                <div class="checkout-wrap">
                    <div class="checkout-side">
                        <div class="checkout-header">
                            <span>🛒 Checkout</span>
                            <span class="text-xs text-blue-400">Order Summary</span>
                        </div>
                        
                        <div class="checkout-row">
                            <span class="checkout-label">Plan Base Price</span>
                            <span class="checkout-val" id="checkout-plan-price">₦0.00</span>
                        </div>
                        
                        <div class="checkout-row">
                            <span class="checkout-label">Domain Name Fee</span>
                            <span class="checkout-val" id="checkout-domain-price">₦0.00</span>
                        </div>

                        <div class="checkout-row">
                            <span class="checkout-label">App Add-ons</span>
                            <span class="checkout-val" id="checkout-addons-price">₦0.00</span>
                        </div>

                        <div class="checkout-total-box">
                            <span class="checkout-total-label">TOTAL PAYABLE</span>
                            <span class="checkout-total-val" id="checkout-total-price">₦0.00</span>
                        </div>

                        <button type="submit" name="register_school" class="btn-submit w-full mt-6" id="submitBtn">
                            🚀 Create & Pay Order
                        </button>
                        
                        <p style="text-align:center;font-size:12px;color:var(--muted);margin-top:14px;">
                            Already have a school? <a href="/login.php" style="color:var(--accent);font-weight:600;">Log in here</a>
                        </p>
                    </div>
                </div>

            </div>
        </form>
        <?php endif; ?>

    </div>
</div>

<script>
// ── State variables for price calculation ────────────────────
let basePlanPrice = 0;
let domainRegFee = 0;
let selectedAddonsTotal = 0;

// ── Auto-generate slug from school name ──────────────────────
const nameInput  = document.getElementById('school_name');
const slugInput  = document.getElementById('slug');
const slugStatus = document.getElementById('slugStatus');
const slugPreview= document.getElementById('slugPreview');
let slugTimer;

function slugify(str) {
    return str.toLowerCase().trim()
              .replace(/[^a-z0-9\s\-]/g, '')
              .replace(/[\s]+/g, '-')
              .replace(/\-+/g, '-')
              .replace(/^\-|\-$/g, '');
}

nameInput?.addEventListener('input', () => {
    if (!slugInput.dataset.manuallyEdited) {
        slugInput.value = slugify(nameInput.value);
        checkSlug(slugInput.value);
    }
});

slugInput?.addEventListener('input', () => {
    slugInput.dataset.manuallyEdited = 'true';
    slugInput.value = slugify(slugInput.value);
    checkSlug(slugInput.value);
});

function checkSlug(val) {
    slugPreview.textContent = val || 'your-school-slug';
    clearTimeout(slugTimer);
    if (!val || val.length < 3) {
        slugStatus.textContent = val.length > 0 ? 'Too short' : '...';
        slugStatus.className = 'slug-status idle';
        return;
    }
    slugStatus.textContent = 'Checking…';
    slugStatus.className = 'slug-status idle';
    slugTimer = setTimeout(async () => {
        try {
            const r = await fetch(`?check_slug=${encodeURIComponent(val)}`);
            const d = await r.json();
            slugStatus.textContent = d.available ? '✓ Available' : '✗ Taken';
            slugStatus.className = 'slug-status ' + (d.available ? 'ok' : 'bad');
        } catch(e) {
            slugStatus.textContent = '?';
        }
    }, 500);
}

// ── Password match check ─────────────────────────────────────
document.getElementById('confirm_password')?.addEventListener('input', function() {
    const pw = document.getElementById('admin_password').value;
    this.style.borderColor = this.value === pw ? 'var(--success)' : 'var(--danger)';
});

// ── Pricing calculation & checkout view updates ──────────────
function selectPlan(card, id, price) {
    document.querySelectorAll('.plan-card').forEach(c => {
        c.classList.remove('selected');
        c.querySelector('input[type="radio"]').checked = false;
    });
    card.classList.add('selected');
    const radio = card.querySelector('input[type="radio"]');
    radio.checked = true;
    
    basePlanPrice = parseFloat(price);

    // Dynamic package default addons selection
    const includedStr = card.dataset.includedAddons || '';
    const includedAddons = includedStr.split(',').map(s => s.trim()).filter(Boolean);
    applyPackageAddons(includedAddons);

    updateCheckoutPrices();
}

function applyPackageAddons(includedAddons) {
    document.querySelectorAll('.addon-item-row').forEach(row => {
        const name = row.dataset.addonName;
        const basePrice = parseFloat(row.dataset.addonPrice);
        const chk = row.querySelector('.addon-checkbox');
        const priceDisplay = row.querySelector('.addon-row-price');

        if (includedAddons.includes(name)) {
            chk.checked = true;
            chk.disabled = true;
            row.classList.add('selected', 'package-included');
            priceDisplay.innerHTML = `<s>₦${basePrice.toLocaleString()}</s> <span style="color:var(--success); font-weight:800; font-size:10px; margin-left:6px; background:rgba(34,197,94,0.1); padding:2px 6px; border-radius:4px; text-transform:uppercase;">Included - Free</span>`;
        } else {
            chk.disabled = false;
            row.classList.remove('package-included');
            priceDisplay.innerHTML = `₦${basePrice.toLocaleString()} / Year`;
            
            // Restore visual status
            if (chk.checked) {
                row.classList.add('selected');
            } else {
                row.classList.remove('selected');
            }
        }
    });
}

function selectDomainOption(option) {
    document.getElementById('domain_option').value = option;
    document.querySelectorAll('.domain-opt-card').forEach(c => c.classList.remove('selected'));
    
    // Select styling
    const cards = document.querySelectorAll('.domain-opt-card');
    if (option === 'subdomain') cards[0].classList.add('selected');
    else if (option === 'existing') cards[1].classList.add('selected');
    else if (option === 'register') cards[2].classList.add('selected');

    // Toggle forms
    document.getElementById('subdomain-wrapper').style.display = (option === 'subdomain') ? 'block' : 'none';
    document.getElementById('existing-wrapper').style.display = (option === 'existing') ? 'block' : 'none';
    document.getElementById('register-wrapper').style.display = (option === 'register') ? 'block' : 'none';

    if (option !== 'register') {
        domainRegFee = 0;
        document.getElementById('domain-search-result').textContent = '';
    } else {
        // Trigger TLD default normal price as domain price
        const tldSelect = document.getElementById('tld_id');
        const selectedOpt = tldSelect.options[tldSelect.selectedIndex];
        domainRegFee = selectedOpt ? parseFloat(selectedOpt.dataset.normal) : 0;
    }
    updateCheckoutPrices();
}

async function checkCustomDomain() {
    const searchResult = document.getElementById('domain-search-result');
    const domainInput = document.getElementById('register_domain_input').value.trim();
    const tldSelect = document.getElementById('tld_id');
    const tldId = tldSelect.value;
    const selectedOpt = tldSelect.options[tldSelect.selectedIndex];
    const searchBtn = document.getElementById('domainSearchBtn');

    if (!domainInput) {
        searchResult.innerHTML = `<div class="domain-search-result-box taken">⚠️ Please enter a domain name.</div>`;
        return;
    }

    // Toggle loading state
    searchBtn.disabled = true;
    searchBtn.innerHTML = `<span>⏳ Searching...</span>`;
    searchResult.innerHTML = `<div class="domain-search-result-box" style="background:rgba(255,255,255,0.05); color:#fff; border:1px solid var(--border)">🔍 Checking WHOIS records for ${domainInput}${selectedOpt.text}...</div>`;

    try {
        const response = await fetch(`api/v1/tenants/domain-search.php?domain=${encodeURIComponent(domainInput)}&tld_id=${tldId}`);
        const data = await response.json();
        
        if (data.available) {
            searchResult.innerHTML = `
                <div class="domain-search-result-box available">
                    <span>✅ <strong>${domainInput}${selectedOpt.text}</strong> is available!</span>
                    <span style="margin-left:auto; font-size:11px; opacity:0.9;">Promo: ₦${parseFloat(data.promo_price).toLocaleString()} / Renewal: ₦${parseFloat(data.normal_price).toLocaleString()}</span>
                </div>
            `;
            domainRegFee = parseFloat(data.normal_price);
            
            // Populate hidden custom domain input field
            document.getElementById('custom_domain').value = domainInput + selectedOpt.text;
        } else {
            searchResult.innerHTML = `
                <div class="domain-search-result-box taken">
                    <span>❌ <strong>${domainInput}${selectedOpt.text}</strong> is already taken.</span>
                </div>
            `;
            domainRegFee = 0;
            document.getElementById('custom_domain').value = '';
        }
        updateCheckoutPrices();
    } catch(e) {
        searchResult.innerHTML = `<div class="domain-search-result-box taken">⚠️ Error checking domain availability. Please try again.</div>`;
    } finally {
        searchBtn.disabled = false;
        searchBtn.innerHTML = `<span>🔍 Search</span>`;
    }
}

function toggleAddonRow(row, name, price) {
    if (row.classList.contains('package-included')) return;
    const chk = row.querySelector('.addon-checkbox');
    chk.checked = !chk.checked;
    row.classList.toggle('selected', chk.checked);
    updateCheckoutPrices();
}

function updateCheckoutPrices() {
    // Sum selected addons
    selectedAddonsTotal = 0;
    document.querySelectorAll('.addon-checkbox').forEach(chk => {
        if (chk.checked) {
            const row = chk.closest('.addon-item-row');
            if (!row.classList.contains('package-included')) {
                const price = parseFloat(row.dataset.addonPrice);
                selectedAddonsTotal += price;
            }
        }
    });

    document.getElementById('checkout-plan-price').textContent = '₦' + basePlanPrice.toLocaleString(undefined, {minimumFractionDigits: 2});
    document.getElementById('checkout-domain-price').textContent = '₦' + domainRegFee.toLocaleString(undefined, {minimumFractionDigits: 2});
    document.getElementById('checkout-addons-price').textContent = '₦' + selectedAddonsTotal.toLocaleString(undefined, {minimumFractionDigits: 2});
    
    const grandTotal = basePlanPrice + domainRegFee + selectedAddonsTotal;
    document.getElementById('checkout-total-price').textContent = '₦' + grandTotal.toLocaleString(undefined, {minimumFractionDigits: 2});
}

// Trigger initial setup
window.addEventListener('DOMContentLoaded', () => {
    const checkedPlan = document.querySelector('.plan-card.selected');
    if (checkedPlan) {
        checkedPlan.click();
    }
    if (slugInput?.value) checkSlug(slugInput.value);
});
</script>
</body>
</html>

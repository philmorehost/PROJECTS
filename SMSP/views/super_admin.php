<?php
// Super Admin — School Management Panel
if (!defined('PORTAL_PATH')) exit;
if ($user_role !== 'super_admin') {
    echo '<div class="p-6 text-red-400 font-semibold">⛔ Access Denied.</div>';
    return;
}

$action = $_GET['action'] ?? 'list';
$msg    = '';
$error  = '';

// ── Handle POST Actions ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['toggle_addon'])) {
        $t_school  = trim($_POST['school_id']);
        $t_addon   = trim($_POST['addon_name']);
        $t_status  = $_POST['current_status'] === 'active' ? 'inactive' : 'active';
        $stmt = $pdo->prepare("INSERT INTO sm_school_addons (school_id_number, addon_name, status)
                               VALUES (?, ?, ?)
                               ON DUPLICATE KEY UPDATE status = ?");
        $stmt->execute([$t_school, $t_addon, $t_status, $t_status]);
        $msg = "Addon '{$t_addon}' set to <strong>{$t_status}</strong> for school {$t_school}.";
    }

    if (isset($_POST['toggle_status'])) {
        $t_school  = trim($_POST['school_id']);
        $t_status  = $_POST['current_status'] === 'active' ? 'inactive' : 'active';
        $stmt = $pdo->prepare("UPDATE sm_schools SET status = ? WHERE id = ?");
        $stmt->execute([$t_status, $t_school]);
        $msg = "School status updated to <strong>{$t_status}</strong>.";
    }

    if (isset($_POST['delete_school'])) {
        $t_school = trim($_POST['school_id']);
        $stmt = $pdo->prepare("DELETE FROM sm_schools WHERE id = ?");
        $stmt->execute([$t_school]);
        $msg = "School <strong>{$t_school}</strong> and all related data deleted.";
    }

    // Impersonate school owner/moderator
    if (isset($_POST['impersonate_school'])) {
        $t_school = trim($_POST['school_id']);
        $stmt = $pdo->prepare("SELECT * FROM sm_moderators WHERE school_id_number = ? LIMIT 1");
        $stmt->execute([$t_school]);
        $mod = $stmt->fetch();

        $stmtSchool = $pdo->prepare("SELECT name FROM sm_schools WHERE id = ? LIMIT 1");
        $stmtSchool->execute([$t_school]);
        $sch_name = $stmtSchool->fetchColumn() ?: 'School';

        if ($mod) {
            $_SESSION['super_admin_session'] = [
                'user_session' => $_SESSION['user_session'],
                'user_role'    => $_SESSION['user_role'],
                'user_name'    => $_SESSION['user_name'],
                'school_id'    => $_SESSION['school_id']
            ];
            $_SESSION['impersonated_school_name'] = $sch_name;
            $_SESSION['user_session'] = $mod['email'];
            $_SESSION['user_role']    = 'school_admin';
            $_SESSION['user_name']    = $mod['fullname'] . ' (Impersonated)';
            $_SESSION['school_id']    = $mod['school_id_number'];

            echo '<script>window.location.href="dashboard.php";</script>';
            exit;
        } else {
            $msg = "⚠️ Error: No admin/owner exists for this school. Please click Edit to set up their credentials.";
        }
    }

    // Save edited school details & password
    if (isset($_POST['save_school_edit'])) {
        $school_id = trim($_POST['school_id']);
        $name = trim($_POST['school_name']);
        $slug = trim($_POST['school_slug']);
        $mod_fullname = trim($_POST['mod_fullname']);
        $mod_email = trim($_POST['mod_email']);
        $mod_password = $_POST['mod_password'];
        $price_per_sms = (float)$_POST['price_per_sms'];
        $package_id = !empty($_POST['package_id']) ? (int)$_POST['package_id'] : null;
        $subscription_status = $_POST['subscription_status'];
        $subscription_expires_at = !empty($_POST['subscription_expires_at']) ? $_POST['subscription_expires_at'] : null;

        $stmt = $pdo->prepare("UPDATE sm_schools SET name = ?, slug = ?, package_id = ?, subscription_status = ?, subscription_expires_at = ? WHERE id = ?");
        $stmt->execute([$name, $slug, $package_id, $subscription_status, $subscription_expires_at, $school_id]);

        // Get alphanumeric school_id_number
        $school_id_number = $school_id;

        $stmtSmsCheck = $pdo->prepare("SELECT COUNT(*) FROM sm_sms_settings WHERE school_id_number = ?");
        $stmtSmsCheck->execute([$school_id_number]);
        if ($stmtSmsCheck->fetchColumn() == 0) {
            $stmtSms = $pdo->prepare("INSERT INTO sm_sms_settings (school_id_number, account_name, api_key, price_per_sms) VALUES (?, ?, '', ?)");
            $stmtSms->execute([$school_id_number, $name . ' SMS', $price_per_sms]);
        } else {
            $stmtSms = $pdo->prepare("UPDATE sm_sms_settings SET price_per_sms = ? WHERE school_id_number = ?");
            $stmtSms->execute([$price_per_sms, $school_id_number]);
        }

        $stmtCheckMod = $pdo->prepare("SELECT id FROM sm_moderators WHERE school_id_number = ? LIMIT 1");
        $stmtCheckMod->execute([$school_id]);
        $mod_id = $stmtCheckMod->fetchColumn();

        if ($mod_id) {
            if (!empty($mod_password)) {
                $hashed_pw = password_hash($mod_password, PASSWORD_DEFAULT);
                $stmtMod = $pdo->prepare("UPDATE sm_moderators SET fullname = ?, email = ?, password = ? WHERE id = ?");
                $stmtMod->execute([$mod_fullname, $mod_email, $hashed_pw, $mod_id]);
            } else {
                $stmtMod = $pdo->prepare("UPDATE sm_moderators SET fullname = ?, email = ? WHERE id = ?");
                $stmtMod->execute([$mod_fullname, $mod_email, $mod_id]);
            }
        } else {
            $hashed_pw = !empty($mod_password) ? password_hash($mod_password, PASSWORD_DEFAULT) : password_hash('password123', PASSWORD_DEFAULT);
            $stmtMod = $pdo->prepare("INSERT INTO sm_moderators (school_id_number, fullname, email, password) VALUES (?, ?, ?, ?)");
            $stmtMod->execute([$school_id, $mod_fullname, $mod_email, $hashed_pw]);
        }

        $msg = "School '<strong>" . htmlspecialchars($name) . "</strong>' updated successfully!";
        $action = 'list';
    }

    if (isset($_POST['approve_sender'])) {
        $req_id = (int)$_POST['request_id'];
        $stmt = $pdo->prepare("UPDATE sm_sms_sender_ids SET status = 'approved' WHERE id = ?");
        $stmt->execute([$req_id]);
        $msg = "Sender ID approved successfully!";
    }

    if (isset($_POST['reject_sender'])) {
        $req_id = (int)$_POST['request_id'];
        $stmt = $pdo->prepare("UPDATE sm_sms_sender_ids SET status = 'rejected' WHERE id = ?");
        $stmt->execute([$req_id]);
        $msg = "Sender ID request rejected.";
    }

    if (isset($_POST['approve_purchase'])) {
        $pur_id = (int)$_POST['purchase_id'];
        $stmtP = $pdo->prepare("SELECT * FROM sm_sms_purchases WHERE id = ? AND status = 'pending'");
        $stmtP->execute([$pur_id]);
        $pur = $stmtP->fetch();
        if ($pur) {
            $stmtApp = $pdo->prepare("UPDATE sm_sms_purchases SET status = 'approved' WHERE id = ?");
            $stmtApp->execute([$pur_id]);

            $stmtCredit = $pdo->prepare("UPDATE sm_schools SET wallet_balance = wallet_balance + ? WHERE id = ?");
            $stmtCredit->execute([$pur['credits_purchased'], $pur['school_id_number']]);

            $msg = "SMS credit purchase approved! School wallet credited.";
        } else {
            $msg = "⚠️ Error: Purchase request not found or already processed.";
        }
    }

    if (isset($_POST['reject_purchase'])) {
        $pur_id = (int)$_POST['purchase_id'];
        $stmt = $pdo->prepare("UPDATE sm_sms_purchases SET status = 'failed' WHERE id = ?");
        $stmt->execute([$pur_id]);
        $msg = "SMS credit purchase rejected.";
    }

    if (isset($_POST['approve_addon_purchase'])) {
        $pur_id = (int)$_POST['purchase_id'];
        $stmtP = $pdo->prepare("SELECT * FROM sm_addon_purchases WHERE id = ? AND status = 'pending'");
        $stmtP->execute([$pur_id]);
        $pur = $stmtP->fetch();
        if ($pur) {
            $stmtApp = $pdo->prepare("UPDATE sm_addon_purchases SET status = 'approved' WHERE id = ?");
            $stmtApp->execute([$pur_id]);

            if (str_starts_with($pur['addon_name'], 'Subscription: ')) {
                $pkgName = substr($pur['addon_name'], 14);
                $stmtPkg = $pdo->prepare("SELECT * FROM sm_subscription_packages WHERE name = ? LIMIT 1");
                $stmtPkg->execute([$pkgName]);
                $pkg = $stmtPkg->fetch();
                if ($pkg) {
                    $cycle = $pkg['billing_cycle'];
                    if ($cycle === 'lifetime') {
                        $new_expiry = date('Y-m-d H:i:s', strtotime('+50 years'));
                    } else {
                        $new_expiry = date('Y-m-d H:i:s', strtotime('+1 year'));
                    }
                    $stmtSch = $pdo->prepare("UPDATE sm_schools SET package_id = ?, subscription_status = 'active', status = 'active', subscription_expires_at = ?, subscription_expiry_warned = 0, subscription_suspended_notified = 0 WHERE id = ?");
                    $stmtSch->execute([$pkg['id'], $new_expiry, $pur['school_id_number']]);
                }
                $msg = "Subscription renewal approved! School portal is now active.";
            } else {
                // Activate the addon for the school
                AddonHelper::toggleAddon($pdo, $pur['school_id_number'], $pur['addon_name'], true);
                $msg = "Addon purchase approved! Module '{$pur['addon_name']}' is now active.";
            }
        } else {
            $msg = "⚠️ Error: Purchase request not found or already processed.";
        }
    }

    if (isset($_POST['reject_addon_purchase'])) {
        $pur_id = (int)$_POST['purchase_id'];
        $stmt = $pdo->prepare("UPDATE sm_addon_purchases SET status = 'rejected' WHERE id = ?");
        $stmt->execute([$pur_id]);
        $msg = "Addon purchase request rejected.";
    }

    if (isset($_POST['save_package'])) {
        $pkg_id = isset($_POST['package_id']) ? (int)$_POST['package_id'] : 0;
        $pkg_name = trim($_POST['package_name']);
        $desc = trim($_POST['description']);
        $price = (float)$_POST['price'];
        $cycle = in_array($_POST['billing_cycle'], ['monthly', 'yearly', 'lifetime']) ? $_POST['billing_cycle'] : 'yearly';
        $max_stud = (int)$_POST['max_students'];
        $auto_renew = isset($_POST['auto_renew']) ? 1 : 0;

        $selected_addons = isset($_POST['package_addons']) ? $_POST['package_addons'] : [];
        $included_addons = implode(',', $selected_addons);

        if ($pkg_id > 0) {
            $stmt = $pdo->prepare("UPDATE sm_subscription_packages SET name = ?, description = ?, price = ?, billing_cycle = ?, included_addons = ?, max_students = ?, auto_renew = ? WHERE id = ?");
            $stmt->execute([$pkg_name, $desc, $price, $cycle, $included_addons, $max_stud, $auto_renew, $pkg_id]);
            $msg = "Package '<strong>" . htmlspecialchars($pkg_name) . "</strong>' updated successfully!";
        } else {
            $stmt = $pdo->prepare("INSERT INTO sm_subscription_packages (name, description, price, billing_cycle, included_addons, max_students, auto_renew) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$pkg_name, $desc, $price, $cycle, $included_addons, $max_stud, $auto_renew]);
            $msg = "Subscription package '<strong>" . htmlspecialchars($pkg_name) . "</strong>' created successfully!";
        }
    }

    if (isset($_POST['delete_package'])) {
        $pkg_id = (int)$_POST['package_id'];
        $stmt = $pdo->prepare("DELETE FROM sm_subscription_packages WHERE id = ?");
        $stmt->execute([$pkg_id]);
        $msg = "Subscription package deleted successfully.";
    }

    // Save platform global settings
    if (isset($_POST['save_global_settings'])) {
        $fields = [
            'platform_name', 'platform_tagline', 'platform_logo_url', 'platform_support_email', 'platform_support_phone',
            'allow_school_registration', 'allow_parent_registration', 'require_email_verification', 'default_package_id',
            'trial_period_days', 'maintenance_mode', 'maintenance_message', 'maintenance_bypass_ips', 'min_password_length',
            'session_timeout_minutes', 'max_login_attempts', 'lockout_duration_minutes', 'addon_cbt_enabled',
            'addon_bulk_report_card_enabled', 'addon_data_cleanup_enabled', 'addon_id_card_enabled', 'addon_live_classes_enabled',
            'addon_sms_enabled', 'cpanel_host', 'cpanel_username', 'cpanel_api_token', 'cpanel_doc_root'
        ];
        $stmt = $pdo->prepare("INSERT INTO sm_global_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        foreach ($fields as $field) {
            $val = isset($_POST[$field]) ? trim($_POST[$field]) : '';
            if ($field === 'maintenance_mode' || $field === 'allow_school_registration' || $field === 'allow_parent_registration' || $field === 'require_email_verification') {
                $val = isset($_POST[$field]) ? '1' : '0';
            }
            if (str_starts_with($field, 'addon_')) {
                $val = isset($_POST[$field]) ? '1' : '0';
            }
            $stmt->execute([$field, $val, $val]);
        }
        $msg = "Global platform configurations updated successfully!";
    }

    // Save platform billing settings
    if (isset($_POST['save_billing_settings'])) {
        $fields = [
            'platform_currency_symbol', 'platform_currency_code', 'enable_tax', 'tax_percentage', 'tax_label',
            'platform_paystack_public', 'platform_paystack_secret', 'platform_flutterwave_public', 'platform_flutterwave_secret',
            'platform_bank_name', 'platform_account_number', 'platform_account_name', 'platform_sms_api_key', 'platform_sms_account_name',
            'price_addon_cbt', 'price_addon_bulk_report_card', 'price_addon_data_cleanup', 'price_addon_id_card', 'price_addon_live_classes', 'price_addon_sms', 'price_addon_mobile_app',
            'desc_addon_cbt', 'desc_addon_bulk_report_card', 'desc_addon_data_cleanup', 'desc_addon_id_card', 'desc_addon_live_classes', 'desc_addon_sms', 'desc_addon_mobile_app',
            'mobile_app_apk_url', 'mobile_app_ios_url',
            'subscription_warn_days_1', 'subscription_warn_days_2', 'subscription_grace_days', 'auto_suspend_expired', 'invoice_prefix',
            'receipt_prefix', 'terms_url'
        ];
        $stmt = $pdo->prepare("INSERT INTO sm_global_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        foreach ($fields as $field) {
            $val = isset($_POST[$field]) ? trim($_POST[$field]) : '';
            if ($field === 'enable_tax' || $field === 'auto_suspend_expired') {
                $val = isset($_POST[$field]) ? '1' : '0';
            }
            $stmt->execute([$field, $val, $val]);
        }
        $msg = "Global billing configurations updated successfully!";
    }

    // Save WHMCS and Domain settings
    if (isset($_POST['save_domain_settings'])) {
        $fields = [
            'whmcs_api_url', 'whmcs_api_identifier', 'whmcs_api_secret', 'nameservers', 'ip_address', 'recommended_registrar_url'
        ];
        $stmt = $pdo->prepare("INSERT INTO sm_global_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        foreach ($fields as $field) {
            $val = isset($_POST[$field]) ? trim($_POST[$field]) : '';
            $stmt->execute([$field, $val, $val]);
        }
        $msg = "Domain and WHMCS configuration settings saved successfully!";
    }

    // Save platform license settings
    if (isset($_POST['save_license_settings'])) {
        $license_key = trim($_POST['license_key'] ?? '');
        $domain = $_SERVER['HTTP_HOST'];
        $api_url = 'https://manager.pmhserver.name.ng/api.php';
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'key' => $license_key,
            'domain' => $domain
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $isValid = false;
        $error_msg = 'Could not contact the license verification server.';
        
        if ($http_code === 200 && $response) {
            $result = json_decode($response, true);
            if (isset($result['status']) && $result['status'] == 1) {
                $isValid = true;
            } else {
                $error_msg = isset($result['message']) ? $result['message'] : 'Invalid license key or domain mismatch.';
            }
        }
        
        if ($isValid) {
            $stmt = $pdo->prepare("INSERT INTO sm_global_settings (setting_key, setting_value) VALUES ('license_key', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$license_key, $license_key]);
            
            // Clear local cache file so that bootstrap picks up new key instantly
            $cache_file = dirname(__DIR__) . '/config/.sys_cache';
            if (file_exists($cache_file)) {
                @unlink($cache_file);
            }
            $msg = "License key updated and validated successfully!";
        } else {
            $error = "Failed to update license key: " . $error_msg;
        }
    }

    // Add Domain TLD
    if (isset($_POST['add_domain_tld'])) {
        $tld = '.' . ltrim(trim($_POST['tld']), '.');
        $promo = (float)$_POST['promo_price'];
        $normal = (float)$_POST['normal_price'];
        if (!empty($tld)) {
            $stmt = $pdo->prepare("INSERT INTO sm_domain_tlds (tld, promo_price, normal_price) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE promo_price = ?, normal_price = ?");
            $stmt->execute([$tld, $promo, $normal, $promo, $normal]);
            $msg = "Domain extension {$tld} pricing updated successfully!";
        }
    }

    // Delete Domain TLD
    if (isset($_POST['delete_domain_tld'])) {
        $id = (int)$_POST['tld_id'];
        $stmt = $pdo->prepare("DELETE FROM sm_domain_tlds WHERE id = ?");
        $stmt->execute([$id]);
        $msg = "Domain extension removed successfully.";
    }

    // Send Super Admin Email Broadcast to Schools
    if (isset($_POST['send_super_email'])) {
        $recipient_scope = $_POST['recipient_scope'] ?? 'individual';
        $school_email = trim($_POST['school_email'] ?? '');
        $selected_schools = $_POST['selected_schools'] ?? [];
        $subject = trim($_POST['subject'] ?? '');
        $body = trim($_POST['body'] ?? '');
        
        $recipients = [];
        if ($recipient_scope === 'individual') {
            if (filter_var($school_email, FILTER_VALIDATE_EMAIL)) {
                $recipients[] = $school_email;
            }
        } elseif ($recipient_scope === 'some') {
            foreach ($selected_schools as $sch_id) {
                // Fetch the school moderator/admin email
                $stmtM = $pdo->prepare("SELECT email FROM sm_moderators WHERE school_id_number = ? LIMIT 1");
                $stmtM->execute([$sch_id]);
                $email = $stmtM->fetchColumn();
                if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $recipients[] = $email;
                }
            }
        } elseif ($recipient_scope === 'all') {
            // Fetch all moderator emails
            $recipients = $pdo->query("SELECT email FROM sm_moderators")->fetchAll(PDO::FETCH_COLUMN);
        }
        
        $recipients = array_filter(array_unique($recipients));
        
        if (empty($recipients)) {
            $msg = "⚠️ Error: No valid school admin emails found.";
        } elseif (empty($subject) || empty($body)) {
            $msg = "⚠️ Error: Subject and body are required.";
        } else {
            require_once PORTAL_PATH . '/include/mail_helper.php';
            $success_count = 0;
            foreach ($recipients as $to_email) {
                if (MailHelper::sendHTML($to_email, $subject, $body)) {
                    $success_count++;
                }
            }
            
            // Log outbox message inside sm_email_logs
            $recipient_list = implode(', ', $recipients);
            $stmtLog = $pdo->prepare("INSERT INTO sm_email_logs (school_id_number, sender_role, recipient_type, recipient_email, subject, body, status) VALUES (NULL, 'super_admin', ?, ?, ?, ?, 'sent')");
            $stmtLog->execute([$recipient_scope, $recipient_list, $subject, $body]);
            
            $msg = "Super admin email broadcast sent successfully to {$success_count} schools!";
        }
    }
}

// Fetch domain settings data if active action
$tld_list = [];
if ($action === 'domain_settings') {
    $tld_list = $pdo->query("SELECT * FROM sm_domain_tlds ORDER BY id ASC")->fetchAll();
}

// Fetch approvals data if active action
$senderRequests = [];
$creditRequests = [];
$addonRequests = [];
if ($action === 'sms_requests') {
    $senderRequests = $pdo->query("SELECT r.*, s.name AS school_name FROM sm_sms_sender_ids r JOIN sm_schools s ON r.school_id_number = s.id ORDER BY r.id DESC")->fetchAll();
    $creditRequests = $pdo->query("SELECT p.*, s.name AS school_name FROM sm_sms_purchases p JOIN sm_schools s ON p.school_id_number = s.id ORDER BY p.id DESC")->fetchAll();
    $addonRequests = $pdo->query("SELECT p.*, s.name AS school_name FROM sm_addon_purchases p JOIN sm_schools s ON p.school_id_number = s.id ORDER BY p.id DESC")->fetchAll();
}

$packages = $pdo->query("SELECT * FROM sm_subscription_packages ORDER BY price ASC")->fetchAll();

// ── Global Stats ─────────────────────────────────────────────
$totalSchools  = (int)$pdo->query("SELECT COUNT(*) FROM sm_schools")->fetchColumn();
$totalStudents = (int)$pdo->query("SELECT COUNT(*) FROM sm_students")->fetchColumn();
$totalTeachers = (int)$pdo->query("SELECT COUNT(*) FROM sm_teachers")->fetchColumn();
$activeSchools = (int)$pdo->query("SELECT COUNT(*) FROM sm_schools WHERE status='active'")->fetchColumn();

// ── All Schools ───────────────────────────────────────────────
$schools = $pdo->query("SELECT s.*,
    (SELECT COUNT(*) FROM sm_students st WHERE st.school_id_number = s.id) AS student_count,
    (SELECT COUNT(*) FROM sm_teachers te WHERE te.school_id_number = s.id) AS teacher_count,
    (SELECT GROUP_CONCAT(addon_name ORDER BY addon_name SEPARATOR ', ')
     FROM sm_school_addons sa WHERE sa.school_id_number = s.id AND sa.status='active') AS active_addons,
    (SELECT email FROM sm_moderators m WHERE m.school_id_number = s.id LIMIT 1) AS admin_email
FROM sm_schools s ORDER BY s.created_at DESC")->fetchAll();

$allAddons = ['CBT', 'Bulk Report Card', 'Data Cleanup', 'ID Card', 'Live Classes', 'SMS'];
?>

<div class="space-y-8">

    <!-- Header -->
    <div class="flex flex-wrap justify-between items-center gap-4">
        <div>
            <h2 class="text-2xl font-bold bg-gradient-to-r from-blue-400 to-indigo-400 bg-clip-text text-transparent">🏫 School Management</h2>
            <p class="text-sm text-gray-400 mt-1">Manage all registered schools, activate addons, and control access.</p>
        </div>
        <a href="/register.php" target="_blank"
           class="mt-2 sm:mt-0 px-5 py-2.5 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white rounded-xl font-bold text-sm shadow-lg transition-all">
            + Register New School
        </a>
    </div>

    <!-- Navigation Tabs -->
    <div class="flex overflow-x-auto gap-2 pb-4 border-b border-white/10 w-full scrollbar-thin">
        <a href="?page=super_admin&action=list" class="flex-shrink-0 whitespace-nowrap px-4 py-2 rounded-xl text-xs sm:text-sm font-semibold transition-all <?php echo ($action === 'list' || $action === 'edit') ? 'bg-blue-600 text-white shadow-md shadow-blue-600/25' : 'bg-white/5 border border-white/10 text-gray-400 hover:text-white hover:bg-white/10'; ?>">Schools Directory</a>
        <a href="?page=super_admin&action=sms_requests" class="flex-shrink-0 whitespace-nowrap px-4 py-2 rounded-xl text-xs sm:text-sm font-semibold transition-all <?php echo $action === 'sms_requests' ? 'bg-blue-600 text-white shadow-md shadow-blue-600/25' : 'bg-white/5 border border-white/10 text-gray-400 hover:text-white hover:bg-white/10'; ?>">Approvals</a>
        <a href="?page=super_admin&action=packages" class="flex-shrink-0 whitespace-nowrap px-4 py-2 rounded-xl text-xs sm:text-sm font-semibold transition-all <?php echo $action === 'packages' ? 'bg-blue-600 text-white shadow-md shadow-blue-600/25' : 'bg-white/5 border border-white/10 text-gray-400 hover:text-white hover:bg-white/10'; ?>">Subscription Packages</a>
        <a href="?page=super_admin&action=settings" class="flex-shrink-0 whitespace-nowrap px-4 py-2 rounded-xl text-xs sm:text-sm font-semibold transition-all <?php echo $action === 'settings' ? 'bg-blue-600 text-white shadow-md shadow-blue-600/25' : 'bg-white/5 border border-white/10 text-gray-400 hover:text-white hover:bg-white/10'; ?>">Global Settings</a>
        <a href="?page=super_admin&action=billing_settings" class="flex-shrink-0 whitespace-nowrap px-4 py-2 rounded-xl text-xs sm:text-sm font-semibold transition-all <?php echo $action === 'billing_settings' ? 'bg-blue-600 text-white shadow-md shadow-blue-600/25' : 'bg-white/5 border border-white/10 text-gray-400 hover:text-white hover:bg-white/10'; ?>">Billing Settings</a>
        <a href="?page=super_admin&action=email" class="flex-shrink-0 whitespace-nowrap px-4 py-2 rounded-xl text-xs sm:text-sm font-semibold transition-all <?php echo $action === 'email' ? 'bg-blue-600 text-white shadow-md shadow-blue-600/25' : 'bg-white/5 border border-white/10 text-gray-400 hover:text-white hover:bg-white/10'; ?>">📧 Email Broadcast</a>
        <a href="?page=super_admin&action=domain_settings" class="flex-shrink-0 whitespace-nowrap px-4 py-2 rounded-xl text-xs sm:text-sm font-semibold transition-all <?php echo $action === 'domain_settings' ? 'bg-blue-600 text-white shadow-md shadow-blue-600/25' : 'bg-white/5 border border-white/10 text-gray-400 hover:text-white hover:bg-white/10'; ?>">🌐 Domain & WHMCS</a>
        <a href="?page=super_admin&action=image_converter" class="flex-shrink-0 whitespace-nowrap px-4 py-2 rounded-xl text-xs sm:text-sm font-semibold transition-all <?php echo $action === 'image_converter' ? 'bg-blue-600 text-white shadow-md shadow-blue-600/25' : 'bg-white/5 border border-white/10 text-gray-400 hover:text-white hover:bg-white/10'; ?>">📸 Image Converter</a>
        <a href="?page=super_admin&action=license_settings" class="flex-shrink-0 whitespace-nowrap px-4 py-2 rounded-xl text-xs sm:text-sm font-semibold transition-all <?php echo $action === 'license_settings' ? 'bg-blue-600 text-white shadow-md shadow-blue-600/25' : 'bg-white/5 border border-white/10 text-gray-400 hover:text-white hover:bg-white/10'; ?>">🔑 Script License</a>
    </div>

    <?php if ($msg): ?>
        <div class="p-4 bg-green-500/10 border border-green-500/25 text-green-400 rounded-xl text-sm mb-4">
            <?php echo $msg; ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="p-4 bg-red-500/10 border border-red-500/25 text-red-400 rounded-xl text-sm mb-4">
            <?php echo $error; ?>
        </div>
    <?php endif; ?>

    <?php if ($action === 'list'): ?>
    <!-- Global Stats -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <?php $stats = [
            ['Total Schools',   $totalSchools,  'blue',   '🏫'],
            ['Active Schools',  $activeSchools, 'green',  '✅'],
            ['Total Students',  $totalStudents, 'indigo', '🎓'],
            ['Total Teachers',  $totalTeachers, 'purple', '👨‍🏫'],
        ]; foreach ($stats as [$label, $val, $color, $icon]): ?>
        <div class="bg-white/5 border border-white/10 rounded-2xl p-5 flex items-center gap-4">
            <span class="text-3xl"><?php echo $icon; ?></span>
            <div>
                <p class="text-gray-400 text-xs font-semibold uppercase tracking-wider"><?php echo $label; ?></p>
                <p class="text-2xl font-extrabold text-<?php echo $color; ?>-400"><?php echo number_format($val); ?></p>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Schools Table -->
    <div class="bg-white/5 border border-white/10 rounded-2xl shadow-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-white/10 flex justify-between items-center">
            <h3 class="font-semibold text-white">All Registered Schools</h3>
            <span class="text-xs text-gray-400"><?php echo $totalSchools; ?> school<?php echo $totalSchools !== 1 ? 's' : ''; ?></span>
        </div>

        <?php if (empty($schools)): ?>
            <div class="p-12 text-center text-gray-500">
                <span class="text-5xl block mb-4">🏫</span>
                <p class="font-semibold">No schools registered yet.</p>
                <a href="/register.php" class="mt-4 inline-block px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-semibold transition">Register First School</a>
            </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[800px] text-sm">
                <thead>
                    <tr class="text-gray-400 text-xs uppercase tracking-wider border-b border-white/10">
                        <th class="px-5 py-3 text-left">School</th>
                        <th class="px-5 py-3 text-left">Admin Email</th>
                        <th class="px-5 py-3 text-center">Students</th>
                        <th class="px-5 py-3 text-center">Status</th>
                        <th class="px-5 py-3 text-left">Active Addons</th>
                        <th class="px-5 py-3 text-left">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    <?php foreach ($schools as $sch): ?>
                    <tr class="hover:bg-white/5 transition" id="row-<?php echo htmlspecialchars($sch['id']); ?>">

                        <!-- School Info -->
                        <td class="px-5 py-4">
                            <div class="font-semibold text-white"><?php echo htmlspecialchars($sch['name']); ?></div>
                            <div class="text-xs text-gray-550 font-mono mt-0.5"><?php echo htmlspecialchars($sch['slug']); ?></div>
                            <div class="text-xs text-gray-650 mt-0.5"><?php echo htmlspecialchars($sch['id']); ?></div>
                            <?php 
                            $pkgName = 'No Package';
                            if ($sch['package_id']) {
                                foreach ($packages as $pkg) {
                                    if ($pkg['id'] == $sch['package_id']) {
                                        $pkgName = $pkg['name'];
                                        break;
                                    }
                                }
                            }
                            ?>
                            <div class="mt-1.5 flex flex-wrap gap-1 items-center">
                                <span class="text-[10px] px-1.5 py-0.5 rounded font-bold uppercase <?php 
                                    echo $sch['subscription_status'] === 'active' ? 'bg-green-500/10 text-green-400 border border-green-500/10' : 'bg-red-500/10 text-red-400 border border-red-500/10'; ?>">
                                    <?php echo htmlspecialchars($pkgName); ?>: <?php echo htmlspecialchars(ucfirst($sch['subscription_status'] ?? 'pending')); ?>
                                </span>
                                <?php if ($sch['subscription_expires_at']): ?>
                                    <span class="text-[10px] text-gray-500">Expires: <?php echo date('Y-m-d', strtotime($sch['subscription_expires_at'])); ?></span>
                                <?php endif; ?>
                            </div>
                        </td>

                        <!-- Admin Email -->
                        <td class="px-5 py-4 text-gray-300 text-xs">
                            <?php echo $sch['admin_email'] ? htmlspecialchars($sch['admin_email']) : '<span class="text-gray-600 italic">No admin yet</span>'; ?>
                        </td>

                        <!-- Student Count -->
                        <td class="px-5 py-4 text-center">
                            <span class="bg-blue-500/15 text-blue-400 px-2.5 py-0.5 rounded-full font-bold text-xs">
                                <?php echo number_format($sch['student_count']); ?>
                            </span>
                        </td>

                        <!-- Status -->
                        <td class="px-5 py-4 text-center">
                            <span class="px-2.5 py-0.5 rounded-full text-xs font-bold
                                <?php echo $sch['status'] === 'active' ? 'bg-green-500/15 text-green-400' : 'bg-red-500/15 text-red-400'; ?>">
                                <?php echo ucfirst($sch['status']); ?>
                            </span>
                        </td>

                        <!-- Active Addons -->
                        <td class="px-5 py-4">
                            <?php if ($sch['active_addons']): ?>
                                <div class="flex flex-wrap gap-1">
                                    <?php foreach (explode(', ', $sch['active_addons']) as $an): ?>
                                        <span class="bg-indigo-500/15 text-indigo-300 px-2 py-0.5 rounded-full text-xs font-medium">
                                            <?php echo htmlspecialchars(trim($an)); ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <span class="text-gray-600 italic text-xs">None</span>
                            <?php endif; ?>
                        </td>

                        <!-- Actions -->
                        <td class="px-5 py-4">
                            <div class="flex flex-wrap gap-2">
                                <!-- Impersonate / Login As -->
                                <form method="post" style="display: inline;">
                                    <input type="hidden" name="school_id" value="<?php echo htmlspecialchars($sch['id']); ?>">
                                    <button type="submit" name="impersonate_school"
                                        class="px-3 py-1 bg-green-500/20 hover:bg-green-500/30 text-green-400 rounded-lg text-xs font-semibold transition flex items-center gap-1">
                                        🔑 Login As Owner
                                    </button>
                                </form>

                                <!-- Edit School Details -->
                                <a href="?page=super_admin&action=edit&school_id=<?php echo htmlspecialchars($sch['id']); ?>"
                                    class="px-3 py-1 bg-blue-500/20 hover:bg-blue-500/30 text-blue-300 rounded-lg text-xs font-semibold transition">
                                    ✏️ Edit
                                </a>

                                <!-- Toggle Status -->
                                <form method="post" onsubmit="return confirm('Toggle school status?');" style="display: inline;">
                                    <input type="hidden" name="school_id" value="<?php echo htmlspecialchars($sch['id']); ?>">
                                    <input type="hidden" name="current_status" value="<?php echo $sch['status']; ?>">
                                    <button type="submit" name="toggle_status"
                                        class="px-3 py-1 rounded-lg text-xs font-semibold transition
                                        <?php echo $sch['status'] === 'active' ? 'bg-orange-500/20 text-orange-400 hover:bg-orange-500/30' : 'bg-green-500/20 text-green-400 hover:bg-green-500/30'; ?>">
                                        <?php echo $sch['status'] === 'active' ? '⏸ Suspend' : '▶ Activate'; ?>
                                    </button>
                                </form>

                                <!-- Manage Addons Button -->
                                <button onclick="toggleAddonPanel('<?php echo htmlspecialchars($sch['id']); ?>')"
                                    class="px-3 py-1 bg-indigo-500/20 hover:bg-indigo-500/30 text-indigo-300 rounded-lg text-xs font-semibold transition">
                                    🧩 Addons
                                </button>

                                <!-- Delete -->
                                <form method="post" onsubmit="return confirm('⚠️ PERMANENTLY delete this school and ALL its data? This cannot be undone.');" style="display: inline;">
                                    <input type="hidden" name="school_id" value="<?php echo htmlspecialchars($sch['id']); ?>">
                                    <button type="submit" name="delete_school"
                                        class="px-3 py-1 bg-red-500/20 hover:bg-red-500/30 text-red-400 rounded-lg text-xs font-semibold transition">
                                        🗑 Delete
                                    </button>
                                </form>
                            </div>

                            <!-- Addon Panel (hidden by default) -->
                            <div id="addon-panel-<?php echo htmlspecialchars($sch['id']); ?>"
                                 class="hidden mt-3 p-3 bg-white/5 border border-white/10 rounded-xl space-y-2">
                                <p class="text-xs font-semibold text-gray-400 mb-2">Toggle Addons:</p>
                                <?php
                                // Get per-school addon status
                                $addonStmt = $pdo->prepare("SELECT addon_name, status FROM sm_school_addons WHERE school_id_number = ?");
                                $addonStmt->execute([$sch['id']]);
                                $schoolAddons = [];
                                foreach ($addonStmt->fetchAll() as $row) {
                                    $schoolAddons[$row['addon_name']] = $row['status'];
                                }
                                foreach ($allAddons as $addon):
                                    $addonStatus = $schoolAddons[$addon] ?? 'inactive';
                                    $isActive = $addonStatus === 'active';
                                ?>
                                <form method="post" class="flex items-center justify-between gap-3">
                                    <input type="hidden" name="school_id" value="<?php echo htmlspecialchars($sch['id']); ?>">
                                    <input type="hidden" name="addon_name" value="<?php echo htmlspecialchars($addon); ?>">
                                    <input type="hidden" name="current_status" value="<?php echo $addonStatus; ?>">
                                    <span class="text-xs text-gray-300"><?php echo htmlspecialchars($addon); ?></span>
                                    <button type="submit" name="toggle_addon"
                                        class="px-3 py-0.5 rounded-full text-xs font-bold transition
                                        <?php echo $isActive ? 'bg-green-500/20 text-green-400 hover:bg-green-500/30' : 'bg-gray-500/20 text-gray-400 hover:bg-gray-500/30'; ?>">
                                        <?php echo $isActive ? '● ON' : '○ OFF'; ?>
                                    </button>
                                </form>
                                <?php endforeach; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <?php elseif ($action === 'edit'): 
        $school_id = $_GET['school_id'] ?? '';
        $stmt = $pdo->prepare("SELECT s.*, m.fullname, m.email, ss.price_per_sms FROM sm_schools s LEFT JOIN sm_moderators m ON s.id = m.school_id_number LEFT JOIN sm_sms_settings ss ON s.id = ss.school_id_number WHERE s.id = ?");
        $stmt->execute([$school_id]);
        $sch = $stmt->fetch();
        
        if ($sch):
    ?>
    <div class="max-w-xl bg-white/5 border border-white/10 rounded-2xl p-8 shadow-2xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <h3 class="text-lg font-semibold text-blue-300">✏️ Edit School Details</h3>
            <a href="?page=super_admin&action=list" class="text-xs text-gray-400 hover:text-white font-medium">← Back to List</a>
        </div>
        
        <form method="post" class="space-y-4">
            <input type="hidden" name="school_id" value="<?php echo htmlspecialchars($sch['id']); ?>">
            
            <div>
                <label class="block text-sm font-medium text-gray-400 mb-1">School Name</label>
                <input type="text" name="school_name" required value="<?php echo htmlspecialchars($sch['name']); ?>" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-400 mb-1">School Slug (URL identifier)</label>
                <input type="text" name="school_slug" required value="<?php echo htmlspecialchars($sch['slug']); ?>" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-400 mb-1">Price Per SMS Credit Override (₦)</label>
                <input type="number" step="0.01" name="price_per_sms" required value="<?php echo htmlspecialchars($sch['price_per_sms'] ?? (defined('SMS_PRICE_PER_CREDIT') ? SMS_PRICE_PER_CREDIT : '5.00')); ?>" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-400 mb-1">Subscription Package</label>
                <select name="package_id" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500">
                    <option value="">No Package (Free/Trial)</option>
                    <?php foreach ($packages as $pkg): ?>
                        <option value="<?php echo $pkg['id']; ?>" <?php echo ($sch['package_id'] == $pkg['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($pkg['name']); ?> (₦<?php echo number_format($pkg['price'], 2); ?> - <?php echo ucfirst($pkg['billing_cycle']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-400 mb-1">Subscription Status</label>
                <select name="subscription_status" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500">
                    <option value="pending" <?php echo ($sch['subscription_status'] === 'pending') ? 'selected' : ''; ?>>Pending</option>
                    <option value="active" <?php echo ($sch['subscription_status'] === 'active') ? 'selected' : ''; ?>>Active</option>
                    <option value="suspended" <?php echo ($sch['subscription_status'] === 'suspended') ? 'selected' : ''; ?>>Suspended</option>
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-400 mb-1">Subscription Expires At</label>
                <input type="date" name="subscription_expires_at" value="<?php echo $sch['subscription_expires_at'] ? date('Y-m-d', strtotime($sch['subscription_expires_at'])) : ''; ?>" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                <p class="text-[10px] text-gray-550 mt-1">Leave empty for Lifetime (one-off) packages.</p>
            </div>
            
            <hr class="border-white/10 my-6">
            <h4 class="text-sm font-bold text-indigo-300 uppercase tracking-wide">Administrator Credentials</h4>
            
            <div>
                <label class="block text-sm font-medium text-gray-400 mb-1">Admin Full Name</label>
                <input type="text" name="mod_fullname" required value="<?php echo htmlspecialchars($sch['fullname'] ?? ''); ?>" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-400 mb-1">Admin Email Address</label>
                <input type="email" name="mod_email" required value="<?php echo htmlspecialchars($sch['email'] ?? ''); ?>" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-400 mb-1">New Password (leave blank to keep unchanged)</label>
                <input type="password" name="mod_password" placeholder="••••••••" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
            </div>
            
            <button type="submit" name="save_school_edit" class="w-full py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-semibold transition">
                Save Changes
            </button>
        </form>
    </div>
    <?php endif; ?>

    <?php elseif ($action === 'sms_requests'): ?>
    <div class="space-y-8">
        <!-- Sender ID Requests -->
        <div class="bg-white/5 border border-white/10 rounded-2xl shadow-xl overflow-hidden">
            <div class="px-6 py-4 border-b border-white/10">
                <h3 class="font-semibold text-white">Sender ID & Template Approval Requests</h3>
                <p class="text-xs text-gray-400 mt-1">Review and approve custom Sender IDs and message templates requested by school administrators.</p>
            </div>
            
            <?php if (empty($senderRequests)): ?>
                <div class="p-8 text-center text-gray-500 text-sm">
                    No Sender ID requests recorded on the platform.
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[850px] text-sm">
                        <thead>
                            <tr class="text-gray-400 text-xs uppercase tracking-wider border-b border-white/10">
                                <th class="px-5 py-3 text-left">School</th>
                                <th class="px-5 py-3 text-left">Sender ID</th>
                                <th class="px-5 py-3 text-left">Sample Template</th>
                                <th class="px-5 py-3 text-center">Status</th>
                                <th class="px-5 py-3 text-left">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            <?php foreach ($senderRequests as $req): ?>
                                <tr class="hover:bg-white/5 transition">
                                    <td class="px-5 py-4">
                                        <div class="font-semibold text-white"><?php echo htmlspecialchars($req['school_name']); ?></div>
                                        <div class="text-[10px] text-gray-550"><?php echo htmlspecialchars($req['school_id_number']); ?></div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <strong class="text-white text-xs font-mono uppercase bg-white/5 px-2 py-0.5 rounded border border-white/10"><?php echo htmlspecialchars($req['sender_id']); ?></strong>
                                    </td>
                                    <td class="px-5 py-4 max-w-xs">
                                        <p class="text-xs text-gray-300 italic truncate" title="<?php echo htmlspecialchars($req['sample_sms']); ?>">"<?php echo htmlspecialchars($req['sample_sms']); ?>"</p>
                                    </td>
                                    <td class="px-5 py-4 text-center">
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase <?php 
                                            if ($req['status'] === 'approved') echo 'bg-green-500/10 text-green-400 border border-green-500/10';
                                            elseif ($req['status'] === 'pending') echo 'bg-yellow-500/10 text-yellow-400 border border-yellow-500/10';
                                            else echo 'bg-red-500/10 text-red-400 border border-red-500/10';
                                        ?>">
                                            <?php echo htmlspecialchars($req['status']); ?>
                                        </span>
                                    </td>
                                    <td class="px-5 py-4">
                                        <?php if ($req['status'] === 'pending'): ?>
                                            <div class="flex gap-2">
                                                <form method="post" class="inline">
                                                    <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>" />
                                                    <button type="submit" name="approve_sender" class="px-3 py-1 bg-green-500 hover:bg-green-600 text-white rounded-lg text-xs font-semibold transition">Approve</button>
                                                </form>
                                                <form method="post" class="inline">
                                                    <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>" />
                                                    <button type="submit" name="reject_sender" class="px-3 py-1 bg-red-500 hover:bg-red-600 text-white rounded-lg text-xs font-semibold transition">Reject</button>
                                                </form>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-gray-500 text-xs italic">Processed</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- SMS Credit Purchases -->
        <div class="bg-white/5 border border-white/10 rounded-2xl shadow-xl overflow-hidden">
            <div class="px-6 py-4 border-b border-white/10">
                <h3 class="font-semibold text-white">Manual SMS Credit Recharge Approvals</h3>
                <p class="text-xs text-gray-400 mt-1">Approve pending manual bank transfers/deposits to instantly credit tenant wallets.</p>
            </div>
            
            <?php if (empty($creditRequests)): ?>
                <div class="p-8 text-center text-gray-500 text-sm">
                    No credit recharge transactions recorded.
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[850px] text-sm">
                        <thead>
                            <tr class="text-gray-400 text-xs uppercase tracking-wider border-b border-white/10">
                                <th class="px-5 py-3 text-left">School</th>
                                <th class="px-5 py-3 text-left">Ref / Date</th>
                                <th class="px-5 py-3 text-center">Credits</th>
                                <th class="px-5 py-3 text-left">Amount</th>
                                <th class="px-5 py-3 text-center">Status</th>
                                <th class="px-5 py-3 text-left">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            <?php foreach ($creditRequests as $cr): ?>
                                <tr class="hover:bg-white/5 transition">
                                    <td class="px-5 py-4">
                                        <div class="font-semibold text-white"><?php echo htmlspecialchars($cr['school_name']); ?></div>
                                        <div class="text-[10px] text-gray-500"><?php echo htmlspecialchars($cr['school_id_number']); ?></div>
                                    </td>
                                    <td class="px-5 py-4 font-mono text-xs text-left">
                                        <div class="text-white"><?php echo htmlspecialchars($cr['transaction_ref']); ?></div>
                                        <div class="text-[10px] text-gray-500"><?php echo htmlspecialchars($cr['purchase_date']); ?></div>
                                    </td>
                                    <td class="px-5 py-4 text-center font-bold text-blue-400">
                                        <?php echo number_format($cr['credits_purchased']); ?>
                                    </td>
                                    <td class="px-5 py-4 font-semibold text-green-400 text-left">
                                        ₦<?php echo number_format($cr['amount_paid'], 2); ?>
                                    </td>
                                    <td class="px-5 py-4 text-center">
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase <?php 
                                            if ($cr['status'] === 'approved') echo 'bg-green-500/10 text-green-400 border border-green-500/10';
                                            elseif ($cr['status'] === 'pending') echo 'bg-yellow-500/10 text-yellow-400 border border-yellow-500/10';
                                            else echo 'bg-red-500/10 text-red-400 border border-red-500/10';
                                        ?>">
                                            <?php echo htmlspecialchars($cr['status']); ?>
                                        </span>
                                    </td>
                                    <td class="px-5 py-4 text-left">
                                        <?php if ($cr['status'] === 'pending'): ?>
                                            <div class="flex gap-2">
                                                <form method="post" class="inline" onsubmit="return confirm('Approve payment and credit school wallet?');">
                                                    <input type="hidden" name="purchase_id" value="<?php echo $cr['id']; ?>" />
                                                    <button type="submit" name="approve_purchase" class="px-3 py-1 bg-green-500 hover:bg-green-600 text-white rounded-lg text-xs font-semibold transition">Approve</button>
                                                </form>
                                                <form method="post" class="inline" onsubmit="return confirm('Reject this purchase?');">
                                                    <input type="hidden" name="purchase_id" value="<?php echo $cr['id']; ?>" />
                                                    <button type="submit" name="reject_purchase" class="px-3 py-1 bg-red-500 hover:bg-red-600 text-white rounded-lg text-xs font-semibold transition">Reject</button>
                                                </form>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-gray-500 text-xs italic">Processed</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Portal Addon Activation Requests -->
        <div class="bg-white/5 border border-white/10 rounded-2xl shadow-xl overflow-hidden mt-8">
            <div class="px-6 py-4 border-b border-white/10">
                <h3 class="font-semibold text-white">Manual Addon Activation Approvals</h3>
                <p class="text-xs text-gray-400 mt-1">Approve pending manual bank transfers/deposits to activate commercialized addons for school tenants.</p>
            </div>
            
            <?php if (empty($addonRequests)): ?>
                <div class="p-8 text-center text-gray-500 text-sm">
                    No addon activation requests recorded.
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[850px] text-sm">
                        <thead>
                            <tr class="text-gray-400 text-xs uppercase tracking-wider border-b border-white/10">
                                <th class="px-5 py-3 text-left">School</th>
                                <th class="px-5 py-3 text-left">Addon Module</th>
                                <th class="px-5 py-3 text-left">Ref / Date / Method</th>
                                <th class="px-5 py-3 text-left">Amount</th>
                                <th class="px-5 py-3 text-center">Status</th>
                                <th class="px-5 py-3 text-left">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            <?php foreach ($addonRequests as $ar): ?>
                                <tr class="hover:bg-white/5 transition">
                                    <td class="px-5 py-4">
                                        <div class="font-semibold text-white"><?php echo htmlspecialchars($ar['school_name']); ?></div>
                                        <div class="text-[10px] text-gray-500"><?php echo htmlspecialchars($ar['school_id_number']); ?></div>
                                    </td>
                                    <td class="px-5 py-4 text-left">
                                        <span class="px-2.5 py-1 bg-purple-500/10 text-purple-400 border border-purple-500/20 rounded-lg text-xs font-bold"><?php echo htmlspecialchars($ar['addon_name']); ?></span>
                                    </td>
                                    <td class="px-5 py-4 font-mono text-xs text-left">
                                        <div class="text-white"><?php echo htmlspecialchars($ar['transaction_ref']); ?></div>
                                        <div class="text-[10px] text-gray-400"><?php echo htmlspecialchars($ar['payment_method']); ?></div>
                                        <div class="text-[10px] text-gray-500"><?php echo htmlspecialchars($ar['purchase_date']); ?></div>
                                    </td>
                                    <td class="px-5 py-4 font-semibold text-green-400 text-left">
                                        ₦<?php echo number_format($ar['amount_paid'], 2); ?>
                                    </td>
                                    <td class="px-5 py-4 text-center">
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase <?php 
                                            if ($ar['status'] === 'approved') echo 'bg-green-500/10 text-green-400 border border-green-500/10';
                                            elseif ($ar['status'] === 'pending') echo 'bg-yellow-500/10 text-yellow-400 border border-yellow-500/10';
                                            else echo 'bg-red-500/10 text-red-400 border border-red-500/10';
                                        ?>">
                                            <?php echo htmlspecialchars($ar['status']); ?>
                                        </span>
                                    </td>
                                    <td class="px-5 py-4 text-left">
                                        <?php if ($ar['status'] === 'pending'): ?>
                                            <div class="flex gap-2">
                                                <form method="post" class="inline" onsubmit="return confirm('Approve payment and activate addon?');">
                                                    <input type="hidden" name="purchase_id" value="<?php echo $ar['id']; ?>" />
                                                    <button type="submit" name="approve_addon_purchase" class="px-3 py-1 bg-green-500 hover:bg-green-600 text-white rounded-lg text-xs font-semibold transition">Approve</button>
                                                </form>
                                                <form method="post" class="inline" onsubmit="return confirm('Reject this request?');">
                                                    <input type="hidden" name="purchase_id" value="<?php echo $ar['id']; ?>" />
                                                    <button type="submit" name="reject_addon_purchase" class="px-3 py-1 bg-red-500 hover:bg-red-600 text-white rounded-lg text-xs font-semibold transition">Reject</button>
                                                </form>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-gray-500 text-xs italic">Processed</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php elseif ($action === 'packages'): 
        $editPkg = null;
        if (isset($_GET['edit_id'])) {
            $edit_id = (int)$_GET['edit_id'];
            foreach ($packages as $pkg) {
                if ($pkg['id'] == $edit_id) {
                    $editPkg = $pkg;
                    break;
                }
            }
        }
    ?>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <!-- Package Form (Create/Edit) -->
        <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl h-fit">
            <h3 class="font-semibold text-white mb-4">
                <?php echo $editPkg ? '✏️ Edit Subscription Package' : '✨ Create Subscription Package'; ?>
            </h3>
            
            <form method="post" class="space-y-4">
                <?php if ($editPkg): ?>
                    <input type="hidden" name="package_id" value="<?php echo $editPkg['id']; ?>">
                <?php endif; ?>
                
                <div>
                    <label class="block text-xs font-semibold text-gray-400 uppercase mb-1">Package Name</label>
                    <input type="text" name="package_name" required value="<?php echo htmlspecialchars($editPkg['name'] ?? ''); ?>" 
                           placeholder="e.g. Starter, Premium, Lifetime"
                           class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-white focus:outline-none focus:border-blue-500 transition">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-400 uppercase mb-1">Description</label>
                    <textarea name="description" rows="3" required placeholder="What features/addons are included?"
                              class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-white focus:outline-none focus:border-blue-500 transition"><?php echo htmlspecialchars($editPkg['description'] ?? ''); ?></textarea>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-400 uppercase mb-1">Price (₦)</label>
                        <input type="number" name="price" step="0.01" required value="<?php echo htmlspecialchars($editPkg['price'] ?? '0.00'); ?>" 
                               class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-white focus:outline-none focus:border-blue-500 transition">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-400 uppercase mb-1">Billing Cycle</label>
                        <select name="billing_cycle" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-white focus:outline-none focus:border-blue-500 transition">
                            <option value="monthly" <?php echo ($editPkg && $editPkg['billing_cycle'] === 'monthly') ? 'selected' : ''; ?>>Monthly</option>
                            <option value="yearly" <?php echo ($editPkg && $editPkg['billing_cycle'] === 'yearly') ? 'selected' : ''; ?>>Yearly</option>
                            <option value="lifetime" <?php echo ($editPkg && $editPkg['billing_cycle'] === 'lifetime') ? 'selected' : ''; ?>>Lifetime (One-off)</option>
                        </select>
                    </div>
                </div>

                <div class="flex items-center gap-2 pt-1">
                    <input type="checkbox" name="auto_renew" id="auto_renew" value="1" <?php echo ($editPkg && isset($editPkg['auto_renew']) && $editPkg['auto_renew'] == 1) ? 'checked' : ''; ?> class="rounded bg-white/5 border-white/10 text-blue-500 focus:ring-0 focus:ring-offset-0" />
                    <label for="auto_renew" class="text-xs font-semibold text-gray-300">Enable Automatic Renewal (Optional / Free Trial packages)</label>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-400 uppercase mb-1">Max Students limit</label>
                    <input type="number" name="max_students" required value="<?php echo htmlspecialchars($editPkg['max_students'] ?? '1000'); ?>" 
                           placeholder="e.g. 500 (0 for unlimited)"
                           class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-sm text-white focus:outline-none focus:border-blue-500 transition">
                    <p class="text-[10px] text-gray-400 mt-1">Set to 0 or very large for unlimited.</p>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-400 uppercase mb-2">Included Addon Modules</label>
                    <div class="space-y-2 max-h-48 overflow-y-auto pr-2">
                        <?php 
                        $pkgAddons = $editPkg ? explode(',', $editPkg['included_addons']) : [];
                        foreach ($allAddons as $addon): 
                        ?>
                            <label class="flex items-center gap-2.5 text-xs text-gray-300 hover:text-white cursor-pointer select-none">
                                <input type="checkbox" name="package_addons[]" value="<?php echo htmlspecialchars($addon); ?>"
                                       <?php echo in_array($addon, $pkgAddons) ? 'checked' : ''; ?>
                                       class="rounded bg-white/5 border border-white/10 text-blue-600 focus:ring-0 focus:ring-offset-0">
                                <span><?php echo htmlspecialchars($addon); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="pt-4 flex gap-2">
                    <button type="submit" name="save_package" class="flex-1 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-semibold transition">
                        <?php echo $editPkg ? 'Save Changes' : 'Create Package'; ?>
                    </button>
                    <?php if ($editPkg): ?>
                        <a href="?page=super_admin&action=packages" class="px-3 py-2 bg-white/5 hover:bg-white/10 text-gray-300 rounded-lg text-sm font-semibold transition flex items-center justify-center">
                            Cancel
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Packages List -->
        <div class="lg:col-span-2 bg-white/5 border border-white/10 rounded-2xl shadow-xl overflow-hidden h-fit">
            <div class="px-6 py-4 border-b border-white/10">
                <h3 class="font-semibold text-white">Active Subscription Packages</h3>
                <p class="text-xs text-gray-400 mt-1">Review the plans visible to schools during registration.</p>
            </div>
            
            <?php if (empty($packages)): ?>
                <div class="p-8 text-center text-gray-500 text-sm">
                    No subscription packages created yet.
                </div>
            <?php else: ?>
                <div class="divide-y divide-white/5">
                    <?php foreach ($packages as $pkg): ?>
                        <div class="p-6 flex flex-col md:flex-row md:items-center justify-between gap-4 hover:bg-white/5 transition">
                            <div class="space-y-1">
                                <div class="flex items-center gap-2">
                                    <h4 class="font-bold text-white"><?php echo htmlspecialchars($pkg['name']); ?></h4>
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider <?php echo $pkg['billing_cycle'] === 'lifetime' ? 'bg-purple-500/10 text-purple-400 border border-purple-500/10' : 'bg-blue-500/10 text-blue-400 border border-blue-500/10'; ?>">
                                        <?php echo htmlspecialchars($pkg['billing_cycle']); ?>
                                    </span>
                                </div>
                                <p class="text-xs text-gray-300"><?php echo htmlspecialchars($pkg['description']); ?></p>
                                <div class="flex flex-wrap gap-1.5 mt-2">
                                    <span class="text-[10px] bg-white/5 text-gray-400 px-2 py-0.5 rounded">Students Limit: <?php echo $pkg['max_students'] > 0 ? number_format($pkg['max_students']) : 'Unlimited'; ?></span>
                                    <?php 
                                    if (!empty($pkg['included_addons'])) {
                                        foreach (explode(',', $pkg['included_addons']) as $add) {
                                            echo '<span class="text-[10px] bg-indigo-500/10 text-indigo-300 px-2 py-0.5 rounded border border-indigo-500/10">' . htmlspecialchars(trim($add)) . '</span>';
                                        }
                                    } else {
                                        echo '<span class="text-[10px] bg-white/5 text-gray-500 px-2 py-0.5 rounded italic">No addons included</span>';
                                    }
                                    ?>
                                </div>
                            </div>
                            <div class="flex items-center gap-4 justify-between md:justify-end border-t md:border-t-0 pt-4 md:pt-0 border-white/5">
                                <div class="text-right">
                                    <div class="text-lg font-extrabold text-green-400">₦<?php echo number_format($pkg['price'], 2); ?></div>
                                    <div class="text-[10px] text-gray-500"><?php echo $pkg['billing_cycle'] === 'lifetime' ? 'one-time payment' : 'per year'; ?></div>
                                </div>
                                <div class="flex gap-2">
                                    <a href="?page=super_admin&action=packages&edit_id=<?php echo $pkg['id']; ?>" 
                                       class="px-2.5 py-1.5 bg-blue-500 hover:bg-blue-600 text-white rounded text-xs font-semibold transition">
                                        Edit
                                    </a>
                                    <form method="post" onsubmit="return confirm('Are you sure you want to delete this subscription package?');" class="inline">
                                        <input type="hidden" name="package_id" value="<?php echo $pkg['id']; ?>">
                                        <button type="submit" name="delete_package" 
                                                class="px-2.5 py-1.5 bg-red-500 hover:bg-red-600 text-white rounded text-xs font-semibold transition">
                                            Delete
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($action === 'settings'): 
        // Fetch current global configs
        $configs = [];
        $stmtCon = $pdo->query("SELECT * FROM sm_global_settings");
        foreach ($stmtCon->fetchAll() as $row) {
            $configs[$row['setting_key']] = $row['setting_value'];
        }
    ?>
    <!-- Global Settings Tab View -->
    <div class="space-y-6">
        <div>
            <h3 class="text-xl font-extrabold text-white">⚙️ Global Platform Settings</h3>
            <p class="text-xs text-gray-400 mt-1">Configure general platform identity details, registration limits, maintenance properties, and security criteria.</p>
        </div>

        <form method="post" class="space-y-6">
            <!-- Platform Identity -->
            <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl backdrop-blur-md">
                <h4 class="text-md font-semibold text-blue-300 mb-4">🏫 Platform Identity & Contact</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-400 mb-1">Platform Name</label>
                        <input type="text" name="platform_name" value="<?php echo htmlspecialchars($configs['platform_name'] ?? 'SchoolApp'); ?>" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-400 mb-1">Platform Tagline / Slogan</label>
                        <input type="text" name="platform_tagline" value="<?php echo htmlspecialchars($configs['platform_tagline'] ?? ''); ?>" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-400 mb-1">Support Email Address</label>
                        <input type="email" name="platform_support_email" value="<?php echo htmlspecialchars($configs['platform_support_email'] ?? 'support@schoolapp.ng'); ?>" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-400 mb-1">Support Phone Line</label>
                        <input type="text" name="platform_support_phone" value="<?php echo htmlspecialchars($configs['platform_support_phone'] ?? ''); ?>" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-xs font-semibold text-gray-400 mb-1">Branding Logo Web URL</label>
                        <input type="text" name="platform_logo_url" value="<?php echo htmlspecialchars($configs['platform_logo_url'] ?? ''); ?>" placeholder="https://example.com/logo.png" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                    </div>
                </div>
            </div>

            <!-- Access Control & Registration Policies -->
            <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl backdrop-blur-md">
                <h4 class="text-md font-semibold text-indigo-300 mb-4">🚀 Registration Controls & Trial System</h4>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="flex items-center gap-3 bg-white/5 border border-white/5 p-4 rounded-xl">
                        <input type="checkbox" name="allow_school_registration" id="allow_school_registration" value="1" <?php echo ($configs['allow_school_registration'] ?? '1') === '1' ? 'checked' : ''; ?> class="w-4 h-4 text-blue-600 border-white/10 rounded focus:ring-0 bg-transparent" />
                        <label for="allow_school_registration" class="text-xs font-semibold text-gray-300 cursor-pointer">Allow School Self-Registration</label>
                    </div>
                    <div class="flex items-center gap-3 bg-white/5 border border-white/5 p-4 rounded-xl">
                        <input type="checkbox" name="allow_parent_registration" id="allow_parent_registration" value="1" <?php echo ($configs['allow_parent_registration'] ?? '1') === '1' ? 'checked' : ''; ?> class="w-4 h-4 text-blue-600 border-white/10 rounded focus:ring-0 bg-transparent" />
                        <label for="allow_parent_registration" class="text-xs font-semibold text-gray-300 cursor-pointer">Allow Parent Self-Registration</label>
                    </div>
                    <div class="flex items-center gap-3 bg-white/5 border border-white/5 p-4 rounded-xl">
                        <input type="checkbox" name="require_email_verification" id="require_email_verification" value="1" <?php echo ($configs['require_email_verification'] ?? '0') === '1' ? 'checked' : ''; ?> class="w-4 h-4 text-blue-600 border-white/10 rounded focus:ring-0 bg-transparent" />
                        <label for="require_email_verification" class="text-xs font-semibold text-gray-300 cursor-pointer">Require Account Email Verification</label>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-400 mb-1">Default Trial Subscription Package</label>
                        <select name="default_package_id" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm">
                            <?php foreach ($packages as $pkg): ?>
                                <option value="<?php echo $pkg['id']; ?>" <?php echo ($configs['default_package_id'] ?? '1') == $pkg['id'] ? 'selected' : ''; ?> class="bg-gray-900 text-white"><?php echo htmlspecialchars($pkg['name']); ?> (₦<?php echo number_format($pkg['price'], 2); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-400 mb-1">Trial Period Duration (Days)</label>
                        <input type="number" name="trial_period_days" value="<?php echo htmlspecialchars($configs['trial_period_days'] ?? '14'); ?>" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                    </div>
                </div>
            </div>

            <!-- Maintenance Mode Panel -->
            <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl backdrop-blur-md">
                <h4 class="text-md font-semibold text-yellow-300 mb-4">⚠️ Maintenance Mode Controls</h4>
                <div class="space-y-4">
                    <div class="flex items-center gap-3 bg-white/5 border border-white/5 p-4 rounded-xl">
                        <input type="checkbox" name="maintenance_mode" id="maintenance_mode" value="1" <?php echo ($configs['maintenance_mode'] ?? '0') === '1' ? 'checked' : ''; ?> class="w-4 h-4 text-blue-600 border-white/10 rounded focus:ring-0 bg-transparent" />
                        <label for="maintenance_mode" class="text-xs font-semibold text-gray-300 cursor-pointer">Activate Platform Maintenance Screen (Bypasses Non-Admins)</label>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-gray-400 mb-1">Maintenance Lock Screen Message</label>
                            <textarea name="maintenance_message" rows="3" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm"><?php echo htmlspecialchars($configs['maintenance_message'] ?? 'The system is undergoing routine maintenance. Please check back shortly.'); ?></textarea>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-400 mb-1">IP Addresses Allowed to Bypass (Comma separated)</label>
                            <textarea name="maintenance_bypass_ips" rows="3" placeholder="127.0.0.1, 192.168.1.10" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm font-mono"><?php echo htmlspecialchars($configs['maintenance_bypass_ips'] ?? '127.0.0.1'); ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Security Standards & Policies -->
            <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl backdrop-blur-md">
                <h4 class="text-md font-semibold text-red-300 mb-4">🛡️ Security Standards & Policies</h4>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-400 mb-1">Min Password Length</label>
                        <input type="number" min="6" max="30" name="min_password_length" value="<?php echo htmlspecialchars($configs['min_password_length'] ?? '8'); ?>" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-400 mb-1">Session Inactive Timeout (Min)</label>
                        <input type="number" min="5" name="session_timeout_minutes" value="<?php echo htmlspecialchars($configs['session_timeout_minutes'] ?? '60'); ?>" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-400 mb-1">Max Login Fail Attempts</label>
                        <input type="number" min="3" name="max_login_attempts" value="<?php echo htmlspecialchars($configs['max_login_attempts'] ?? '5'); ?>" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-400 mb-1">Account Lock Duration (Min)</label>
                        <input type="number" min="1" name="lockout_duration_minutes" value="<?php echo htmlspecialchars($configs['lockout_duration_minutes'] ?? '15'); ?>" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                    </div>
                </div>
            </div>

            <!-- cPanel Automation Settings -->
            <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl backdrop-blur-md">
                <h4 class="text-md font-semibold text-indigo-300 mb-4">🌐 cPanel Domain Automation API</h4>
                <p class="text-xs text-gray-400 mb-4">Configure the server UAPI credentials. This allows the system to automatically register school domains/subdomains and map them to the codebase folder instantly.</p>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-400 mb-1">cPanel Server Host IP/Hostname</label>
                        <input type="text" name="cpanel_host" value="<?php echo htmlspecialchars($configs['cpanel_host'] ?? 'localhost'); ?>" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm font-mono" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-400 mb-1">cPanel Login Username</label>
                        <input type="text" name="cpanel_username" value="<?php echo htmlspecialchars($configs['cpanel_username'] ?? 'sms'); ?>" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-400 mb-1">cPanel API Token</label>
                        <input type="password" name="cpanel_api_token" value="<?php echo htmlspecialchars($configs['cpanel_api_token'] ?? ''); ?>" placeholder="Enter API Token..." class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm font-mono" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-400 mb-1">Website File Path (Doc Root Folder)</label>
                        <input type="text" name="cpanel_doc_root" value="<?php echo htmlspecialchars($configs['cpanel_doc_root'] ?? 'portal.schoolapp.ng'); ?>" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm font-mono" />
                    </div>
                </div>
            </div>

            <!-- Global Addon Toggles -->
            <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl backdrop-blur-md">
                <h4 class="text-md font-semibold text-purple-300 mb-2">🔌 Global Modules & Addons Master Switch</h4>
                <p class="text-xs text-gray-400 mb-4">Enable or disable features system-wide. Disabling a module here overrides all tenant configuration.</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                    <?php 
                    $addonKeys = [
                        'addon_cbt_enabled' => 'Computer Based Testing (CBT) Builder',
                        'addon_bulk_report_card_enabled' => 'Bulk Report Card Compiler',
                        'addon_data_cleanup_enabled' => 'School Database Cleanup Tool',
                        'addon_id_card_enabled' => 'Digital Student & Teacher ID Cards',
                        'addon_live_classes_enabled' => 'Live Streaming Classes (LMS Extra)',
                        'addon_sms_enabled' => 'Bulk SMS Notice Gateway Broadcasts'
                    ];
                    foreach ($addonKeys as $key => $title):
                    ?>
                    <div class="flex items-center gap-3 bg-white/5 border border-white/5 p-4 rounded-xl">
                        <input type="checkbox" name="<?php echo $key; ?>" id="<?php echo $key; ?>" value="1" <?php echo ($configs[$key] ?? '1') === '1' ? 'checked' : ''; ?> class="w-4 h-4 text-purple-600 border-white/10 rounded focus:ring-0 bg-transparent" />
                        <label for="<?php echo $key; ?>" class="text-xs font-semibold text-gray-300 cursor-pointer"><?php echo $title; ?></label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <button type="submit" name="save_global_settings" class="w-full py-3 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white rounded-xl font-bold text-sm shadow-lg transition-all">
                💾 Save Platform Configurations
            </button>
        </form>
    </div>
    <?php endif; ?>

    <?php if ($action === 'billing_settings'): 
        // Fetch current global configs
        $configs = [];
        $stmtCon = $pdo->query("SELECT * FROM sm_global_settings");
        foreach ($stmtCon->fetchAll() as $row) {
            $configs[$row['setting_key']] = $row['setting_value'];
        }

        // Calculations for Projections & Overview stats
        $totalSchoolSubs = (int)$pdo->query("SELECT COUNT(*) FROM sm_schools WHERE package_id IS NOT NULL AND status='active'")->fetchColumn();
        $totalSMSCreditsAll = (int)$pdo->query("SELECT SUM(credits_purchased) FROM sm_sms_purchases WHERE status='approved'")->fetchColumn() ?: 0;
        $totalSMSRevenueAll = (float)$pdo->query("SELECT SUM(amount_paid) FROM sm_sms_purchases WHERE status='approved'")->fetchColumn() ?: 0.00;
        $pendingApprovalsCount = (int)$pdo->query("SELECT COUNT(*) FROM sm_sms_purchases WHERE status='pending'")->fetchColumn();
        
        // Calculate subscription potential revenue projection
        $projection = 0.00;
        $activeSchoolsWithPkg = $pdo->query("SELECT s.package_id, p.price, p.billing_cycle FROM sm_schools s JOIN sm_subscription_packages p ON s.package_id = p.id WHERE s.status='active'")->fetchAll();
        foreach ($activeSchoolsWithPkg as $item) {
            if ($item['billing_cycle'] === 'yearly') {
                $projection += (float)$item['price'];
            }
        }
    ?>
    <!-- Billing Settings Tab View -->
    <div class="space-y-6">
        <div>
            <h3 class="text-xl font-extrabold text-white">💰 Billing Settings & Revenue Control</h3>
            <p class="text-xs text-gray-400 mt-1">Manage platform billing parameters, setup centralized payment gateways, configure pricing schemas, and view global transaction forecasts.</p>
        </div>

        <!-- Revenue Dashboard Overview (Curated design) -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div class="bg-gradient-to-br from-emerald-500/10 to-teal-500/10 border border-emerald-500/25 rounded-2xl p-5 shadow-sm">
                <span class="text-2xl">📈</span>
                <p class="text-gray-400 text-xs font-semibold mt-2 uppercase">Sub Projected Annual Revenue</p>
                <p class="text-2xl font-black text-emerald-400 mt-1">₦<?php echo number_format($projection, 2); ?></p>
                <span class="text-[9px] text-gray-500">Based on <?php echo $totalSchoolSubs; ?> active schools subscription packages.</span>
            </div>
            <div class="bg-gradient-to-br from-indigo-500/10 to-blue-500/10 border border-indigo-500/25 rounded-2xl p-5 shadow-sm">
                <span class="text-2xl">📱</span>
                <p class="text-gray-400 text-xs font-semibold mt-2 uppercase">Approved SMS Volume</p>
                <p class="text-2xl font-black text-indigo-400 mt-1"><?php echo number_format($totalSMSCreditsAll); ?> units</p>
                <span class="text-[9px] text-gray-500">Total credits approved across all tenants.</span>
            </div>
            <div class="bg-gradient-to-br from-purple-500/10 to-pink-500/10 border border-purple-500/25 rounded-2xl p-5 shadow-sm">
                <span class="text-2xl">💸</span>
                <p class="text-gray-400 text-xs font-semibold mt-2 uppercase">Approved SMS Revenue</p>
                <p class="text-2xl font-black text-purple-400 mt-1">₦<?php echo number_format($totalSMSRevenueAll, 2); ?></p>
                <span class="text-[9px] text-gray-500">Gross revenue verified from SMS recharges.</span>
            </div>
            <div class="bg-gradient-to-br from-amber-500/10 to-orange-500/10 border border-amber-500/25 rounded-2xl p-5 shadow-sm">
                <span class="text-2xl">⏳</span>
                <p class="text-gray-400 text-xs font-semibold mt-2 uppercase">Pending Approvals</p>
                <p class="text-2xl font-black text-amber-400 mt-1"><?php echo $pendingApprovalsCount; ?> requests</p>
                <span class="text-[9px] text-gray-500">Awaiting offline bank verification.</span>
            </div>
        </div>

        <form method="post" class="space-y-6">
            <!-- Currency & Tax Settings -->
            <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl backdrop-blur-md">
                <h4 class="text-md font-semibold text-green-300 mb-4">🪙 Currency & Tax Configuration</h4>
                <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-400 mb-1">Currency Symbol</label>
                        <input type="text" name="platform_currency_symbol" value="<?php echo htmlspecialchars($configs['platform_currency_symbol'] ?? '₦'); ?>" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm font-mono" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-400 mb-1">Currency Code</label>
                        <input type="text" name="platform_currency_code" value="<?php echo htmlspecialchars($configs['platform_currency_code'] ?? 'NGN'); ?>" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm font-mono" />
                    </div>
                    <div class="flex items-center gap-3 bg-white/5 border border-white/5 px-4 py-2 rounded-xl h-10 mt-5">
                        <input type="checkbox" name="enable_tax" id="enable_tax" value="1" <?php echo ($configs['enable_tax'] ?? '0') === '1' ? 'checked' : ''; ?> class="w-4 h-4 text-green-600 border-white/10 rounded focus:ring-0 bg-transparent" />
                        <label for="enable_tax" class="text-xs font-semibold text-gray-300 cursor-pointer">Enable Tax Extra</label>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-400 mb-1">Tax Percentage (%)</label>
                        <input type="number" step="0.1" name="tax_percentage" value="<?php echo htmlspecialchars($configs['tax_percentage'] ?? '7.5'); ?>" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-400 mb-1">Tax Label Text</label>
                        <input type="text" name="tax_label" value="<?php echo htmlspecialchars($configs['tax_label'] ?? 'VAT'); ?>" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                    </div>
                </div>
            </div>

            <!-- Platform Payment Gateways (Collecting School Subscriptions) -->
            <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl backdrop-blur-md">
                <h4 class="text-md font-semibold text-blue-300 mb-4">💳 Centrally Owned Gateways (For Receiving School Subscription Payments)</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-400 mb-1">Paystack Central Public Key</label>
                        <input type="text" name="platform_paystack_public" value="<?php echo htmlspecialchars($configs['platform_paystack_public'] ?? ''); ?>" placeholder="pk_live_..." class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm font-mono" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-400 mb-1">Paystack Central Secret Key</label>
                        <input type="password" name="platform_paystack_secret" value="<?php echo htmlspecialchars($configs['platform_paystack_secret'] ?? ''); ?>" placeholder="sk_live_..." class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm font-mono" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-400 mb-1">Flutterwave Central Public Key</label>
                        <input type="text" name="platform_flutterwave_public" value="<?php echo htmlspecialchars($configs['platform_flutterwave_public'] ?? ''); ?>" placeholder="FLWPUBK-..." class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm font-mono" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-400 mb-1">Flutterwave Central Secret Key</label>
                        <input type="password" name="platform_flutterwave_secret" value="<?php echo htmlspecialchars($configs['platform_flutterwave_secret'] ?? ''); ?>" placeholder="FLWSECK-..." class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm font-mono" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-400 mb-1">PhilmoreSMS Central API Token / Key</label>
                        <input type="password" name="platform_sms_api_key" value="<?php echo htmlspecialchars($configs['platform_sms_api_key'] ?? ''); ?>" placeholder="Enter central philmoresms token..." class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm font-mono" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-400 mb-1">PhilmoreSMS Central Account Name</label>
                        <input type="text" name="platform_sms_account_name" value="<?php echo htmlspecialchars($configs['platform_sms_account_name'] ?? ''); ?>" placeholder="Central SMS Account Name" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                    </div>
                </div>
                <!-- Platform Offline Bank Details -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4 pt-4 border-t border-white/5">
                    <div>
                        <label class="block text-xs font-semibold text-gray-400 mb-1">Central Platform Bank Name</label>
                        <input type="text" name="platform_bank_name" value="<?php echo htmlspecialchars($configs['platform_bank_name'] ?? ''); ?>" placeholder="e.g. Zenith Bank" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-400 mb-1">Central Platform Bank Account Number</label>
                        <input type="text" name="platform_account_number" value="<?php echo htmlspecialchars($configs['platform_account_number'] ?? ''); ?>" placeholder="10 digits number" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm font-mono" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-400 mb-1">Central Platform Bank Account Name</label>
                        <input type="text" name="platform_account_name" value="<?php echo htmlspecialchars($configs['platform_account_name'] ?? ''); ?>" placeholder="Central Company Name" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                    </div>
                </div>
            </div>

            <!-- Commercial Addon Module Pricing & Descriptions -->
            <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl backdrop-blur-md">
                <h4 class="text-md font-semibold text-purple-300 mb-4">🧩 Commercial Addon Module Pricing & Descriptions</h4>
                <div class="space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 border-b border-white/5 pb-3">
                        <div>
                            <label class="block text-xs font-semibold text-gray-400 mb-1">CBT Exam Module Price (₦)</label>
                            <input type="number" step="0.01" name="price_addon_cbt" value="<?php echo htmlspecialchars($configs['price_addon_cbt'] ?? '15000.00'); ?>" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm font-mono" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-400 mb-1">CBT Exam Module Description</label>
                            <input type="text" name="desc_addon_cbt" value="<?php echo htmlspecialchars($configs['desc_addon_cbt'] ?? 'Computer Based Testing with automatic grading and analysis'); ?>" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 border-b border-white/5 pb-3">
                        <div>
                            <label class="block text-xs font-semibold text-gray-400 mb-1">Bulk Report Card Compiler Price (₦)</label>
                            <input type="number" step="0.01" name="price_addon_bulk_report_card" value="<?php echo htmlspecialchars($configs['price_addon_bulk_report_card'] ?? '25000.00'); ?>" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm font-mono" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-400 mb-1">Bulk Report Card Compiler Description</label>
                            <input type="text" name="desc_addon_bulk_report_card" value="<?php echo htmlspecialchars($configs['desc_addon_bulk_report_card'] ?? 'Process and print student terminal report cards in bulk'); ?>" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 border-b border-white/5 pb-3">
                        <div>
                            <label class="block text-xs font-semibold text-gray-400 mb-1">Data Cleanup Module Price (₦)</label>
                            <input type="number" step="0.01" name="price_addon_data_cleanup" value="<?php echo htmlspecialchars($configs['price_addon_data_cleanup'] ?? '10000.00'); ?>" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm font-mono" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-400 mb-1">Data Cleanup Module Description</label>
                            <input type="text" name="desc_addon_data_cleanup" value="<?php echo htmlspecialchars($configs['desc_addon_data_cleanup'] ?? 'Wipe records or reset session databases cleanly'); ?>" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 border-b border-white/5 pb-3">
                        <div>
                            <label class="block text-xs font-semibold text-gray-400 mb-1">ID Card Generation Price (₦)</label>
                            <input type="number" step="0.01" name="price_addon_id_card" value="<?php echo htmlspecialchars($configs['price_addon_id_card'] ?? '12000.00'); ?>" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm font-mono" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-400 mb-1">ID Card Generation Description</label>
                            <input type="text" name="desc_addon_id_card" value="<?php echo htmlspecialchars($configs['desc_addon_id_card'] ?? 'Generate beautiful barcode-supported printable ID Cards'); ?>" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 border-b border-white/5 pb-3">
                        <div>
                            <label class="block text-xs font-semibold text-gray-400 mb-1">Live Classes Module Price (₦)</label>
                            <input type="number" step="0.01" name="price_addon_live_classes" value="<?php echo htmlspecialchars($configs['price_addon_live_classes'] ?? '30000.00'); ?>" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm font-mono" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-400 mb-1">Live Classes Module Description</label>
                            <input type="text" name="desc_addon_live_classes" value="<?php echo htmlspecialchars($configs['desc_addon_live_classes'] ?? 'Host online audio/video classes with interactive screenshare'); ?>" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 border-b border-white/5 pb-3">
                        <div>
                            <label class="block text-xs font-semibold text-gray-400 mb-1">SMS Broadcast Module Price (₦)</label>
                            <input type="number" step="0.01" name="price_addon_sms" value="<?php echo htmlspecialchars($configs['price_addon_sms'] ?? '20000.00'); ?>" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm font-mono" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-400 mb-1">SMS Broadcast Module Description</label>
                            <input type="text" name="desc_addon_sms" value="<?php echo htmlspecialchars($configs['desc_addon_sms'] ?? 'Send SMS notifications to parents and staff'); ?>" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 border-b border-white/5 pb-3">
                        <div>
                            <label class="block text-xs font-semibold text-gray-400 mb-1">Mobile Application Package Price (₦)</label>
                            <input type="number" step="0.01" name="price_addon_mobile_app" value="<?php echo htmlspecialchars($configs['price_addon_mobile_app'] ?? '50000.00'); ?>" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm font-mono" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-400 mb-1">Mobile Application Package Description</label>
                            <input type="text" name="desc_addon_mobile_app" value="<?php echo htmlspecialchars($configs['desc_addon_mobile_app'] ?? 'Generate native Android APK and iOS app builds synced to portal'); ?>" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pb-2">
                        <div>
                            <label class="block text-xs font-semibold text-gray-400 mb-1">Android APK Download URL</label>
                            <input type="text" name="mobile_app_apk_url" value="<?php echo htmlspecialchars($configs['mobile_app_apk_url'] ?? ''); ?>" placeholder="https://..." class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-400 mb-1">iOS App Store URL</label>
                            <input type="text" name="mobile_app_ios_url" value="<?php echo htmlspecialchars($configs['mobile_app_ios_url'] ?? ''); ?>" placeholder="https://apps.apple.com/..." class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                        </div>
                    </div>
                </div>
            </div>

            <!-- Auto-Expiry Warnings & Expired Suspension Policies -->
            <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl backdrop-blur-md">
                <h4 class="text-md font-semibold text-amber-300 mb-4">⏰ Subscription Warnings & Expiry Rules</h4>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-400 mb-1">First Alert Prior to Expiry (Days)</label>
                        <input type="number" name="subscription_warn_days_1" value="<?php echo htmlspecialchars($configs['subscription_warn_days_1'] ?? '30'); ?>" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-400 mb-1">Final Alert Prior to Expiry (Days)</label>
                        <input type="number" name="subscription_warn_days_2" value="<?php echo htmlspecialchars($configs['subscription_warn_days_2'] ?? '7'); ?>" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-400 mb-1">Grace Period Overdue (Days)</label>
                        <input type="number" name="subscription_grace_days" value="<?php echo htmlspecialchars($configs['subscription_grace_days'] ?? '3'); ?>" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                    </div>
                    <div class="flex items-center gap-3 bg-white/5 border border-white/5 px-4 py-2 rounded-xl h-10 mt-5">
                        <input type="checkbox" name="auto_suspend_expired" id="auto_suspend_expired" value="1" <?php echo ($configs['auto_suspend_expired'] ?? '1') === '1' ? 'checked' : ''; ?> class="w-4 h-4 text-amber-600 border-white/10 rounded focus:ring-0 bg-transparent" />
                        <label for="auto_suspend_expired" class="text-xs font-semibold text-gray-300 cursor-pointer">Auto Suspend School</label>
                    </div>
                </div>
            </div>

            <!-- Invoices and Legal configs -->
            <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl backdrop-blur-md">
                <h4 class="text-md font-semibold text-indigo-300 mb-4">📄 Invoices & Legal Agreements</h4>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-400 mb-1">Invoice Reference Prefix</label>
                        <input type="text" name="invoice_prefix" value="<?php echo htmlspecialchars($configs['invoice_prefix'] ?? 'INV-'); ?>" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-400 mb-1">Receipt Reference Prefix</label>
                        <input type="text" name="receipt_prefix" value="<?php echo htmlspecialchars($configs['receipt_prefix'] ?? 'RCT-'); ?>" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-400 mb-1">Platform Terms of Service URL</label>
                        <input type="text" name="terms_url" value="<?php echo htmlspecialchars($configs['terms_url'] ?? ''); ?>" placeholder="https://example.com/terms" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm" />
                    </div>
                </div>
            </div>

            <button type="submit" name="save_billing_settings" class="w-full py-3 bg-gradient-to-r from-green-600 to-emerald-600 hover:from-green-700 hover:to-emerald-700 text-white rounded-xl font-bold text-sm shadow-lg transition-all">
                💾 Save Billing Configurations
            </button>
        </form>
    </div>
    <?php endif; ?>

    <?php if ($action === 'email'): ?>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 bg-white/5 border border-white/10 rounded-2xl p-8 shadow-2xl space-y-6">
            <h3 class="text-lg font-bold text-blue-300">📧 Send Email Broadcast to School Admins</h3>
            
            <!-- AI Compose prompt -->
            <div class="p-4 bg-white/5 border border-white/10 rounded-xl space-y-3">
                <label class="block text-xs font-bold text-gray-400 uppercase tracking-wider">✨ Gmail-like AI Smart Compose</label>
                <div class="flex gap-2">
                    <input type="text" id="super-ai-prompt" placeholder="e.g. Write a notification informing school admins of platform system maintenance this Saturday" class="flex-grow bg-black/40 border border-white/10 rounded-lg px-4 py-2 text-white text-sm focus:outline-none focus:border-blue-500">
                    <button type="button" onclick="composeSuperEmailViaAI()" id="super-ai-btn" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-bold transition-all shrink-0">✨ Compose</button>
                </div>
            </div>

            <form method="post" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-400 mb-1">Recipients Scope</label>
                    <select name="recipient_scope" id="recipient_scope" onchange="toggleSuperRecipientScope(this.value)" class="w-full bg-slate-900 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500">
                        <option value="individual">Individual School Admin Email</option>
                        <option value="some">Select Some Schools (Checklist)</option>
                        <option value="all">Send to All Schools</option>
                    </select>
                </div>

                <!-- Individual Input -->
                <div id="wrapper-super-individual">
                    <label class="block text-sm font-medium text-gray-400 mb-1">School Admin Email</label>
                    <input type="email" name="school_email" placeholder="e.g. admin@school.com" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                </div>

                <!-- Some/Select list of schools -->
                <div id="wrapper-super-some" style="display: none;" class="space-y-2">
                    <div class="flex justify-between items-center bg-white/5 p-2 rounded">
                        <span class="text-xs font-bold text-blue-300">Registered Schools</span>
                        <label class="text-xs text-gray-400 cursor-pointer"><input type="checkbox" onclick="toggleAllSchools(this.checked)"> Select All</label>
                    </div>
                    <div class="border border-white/10 rounded-xl p-4 max-h-60 overflow-y-auto grid grid-cols-1 sm:grid-cols-2 gap-2">
                        <?php foreach ($schools as $sch): ?>
                            <label class="flex items-center gap-2 text-xs text-gray-300 cursor-pointer">
                                <input type="checkbox" name="selected_schools[]" value="<?php echo htmlspecialchars($sch['id']); ?>" class="chk-school">
                                <span><?php echo htmlspecialchars($sch['name'] . ' (' . ($sch['admin_email'] ?? 'No admin email') . ')'); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-400 mb-1">Subject</label>
                    <input type="text" name="subject" id="super-email-subject" required placeholder="Broadcast subject line" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-400 mb-1">Message Body</label>
                    <textarea name="body" id="super-email-body" rows="8" required placeholder="Write email body content..." class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 text-sm"></textarea>
                </div>

                <button type="submit" name="send_super_email" class="w-full py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-bold transition">
                    🚀 Dispatch Broadcast to Schools
                </button>
            </form>
        </div>
        
        <!-- Sidebar Info -->
        <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl space-y-4 h-fit text-sm">
            <h4 class="font-bold text-white">Super Admin Broadcasts</h4>
            <p class="text-xs text-gray-400 leading-relaxed">Broadcast announcements, billing notices, or policy changes to all school managers across the SaaS platform.</p>
        </div>
    </div>

    <script>
    function toggleSuperRecipientScope(val) {
        document.getElementById('wrapper-super-individual').style.display = (val === 'individual') ? 'block' : 'none';
        document.getElementById('wrapper-super-some').style.display = (val === 'some') ? 'block' : 'none';
    }
    function toggleAllSchools(checked) {
        document.querySelectorAll('.chk-school').forEach(chk => chk.checked = checked);
    }
    async function composeSuperEmailViaAI() {
        const promptInput = document.getElementById('super-ai-prompt');
        const promptVal = promptInput.value.trim();
        const btn = document.getElementById('super-ai-btn');
        if (!promptVal) {
            alert('Please enter a description for the email.');
            return;
        }
        btn.disabled = true;
        btn.textContent = 'Composing...';
        try {
            const formData = new FormData();
            formData.append('ajax_ai_compose', promptVal);
            const response = await fetch('dashboard.php', {
                method: 'POST',
                body: formData
            });
            const data = await response.json();
            if (data.subject && data.body) {
                document.getElementById('super-email-subject').value = data.subject;
                document.getElementById('super-email-body').value = data.body;
            } else {
                alert('Failed to compose email templates.');
            }
        } catch(e) {
            alert('AI Helper server timed out.');
        } finally {
            btn.disabled = false;
            btn.textContent = '✨ Compose';
        }
    }
    </script>
    <?php endif; ?>

    <?php if ($action === 'domain_settings'): 
        // Fetch current global configs
        $configs = [];
        $stmtCon = $pdo->query("SELECT * FROM sm_global_settings");
        foreach ($stmtCon->fetchAll() as $row) {
            $configs[$row['setting_key']] = $row['setting_value'];
        }
    ?>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        <!-- Left: Domain Extension prices & Global Instruction settings -->
        <div class="lg:col-span-2 space-y-6">
            
            <!-- TLD Price Table & Form -->
            <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl backdrop-blur-md">
                <h3 class="text-lg font-semibold text-white mb-4">🌐 Domain Extensions & Pricing</h3>
                
                <!-- Add TLD form -->
                <form method="post" class="grid grid-cols-1 sm:grid-cols-3 gap-4 bg-black/20 p-4 rounded-xl border border-white/5 mb-6">
                    <div>
                        <label class="block text-xs font-bold text-gray-400 mb-1">TLD Extension (e.g. com.ng)</label>
                        <input type="text" name="tld" required placeholder="com.ng" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-1.5 text-white text-xs focus:outline-none focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-400 mb-1">1st Year Promo Price (₦)</label>
                        <input type="number" name="promo_price" step="0.01" required placeholder="1500" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-1.5 text-white text-xs focus:outline-none focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-400 mb-1">Renewal Price (₦)</label>
                        <input type="number" name="normal_price" step="0.01" required placeholder="3500" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-1.5 text-white text-xs focus:outline-none focus:border-blue-500">
                    </div>
                    <button type="submit" name="add_domain_tld" class="sm:col-span-3 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-xs font-bold transition-all">
                        ➕ ADD NEW EXTENSION
                    </button>
                </form>

                <!-- List TLD extensions -->
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[500px] text-xs text-left">
                        <thead>
                            <tr class="border-b border-white/10 text-gray-400">
                                <th class="py-2">Extension</th>
                                <th class="py-2">1st Year Promo</th>
                                <th class="py-2">Renewal Price</th>
                                <th class="py-2 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            <?php foreach ($tld_list as $item): ?>
                                <tr>
                                    <td class="py-2.5 font-bold text-white"><?php echo htmlspecialchars($item['tld']); ?></td>
                                    <td class="py-2.5 text-gray-300">₦<?php echo number_format($item['promo_price'], 2); ?></td>
                                    <td class="py-2.5 text-blue-400 font-semibold">₦<?php echo number_format($item['normal_price'], 2); ?></td>
                                    <td class="py-2.5 text-right">
                                        <form method="post" class="inline" onsubmit="return confirm('Remove TLD extension pricing?');">
                                            <input type="hidden" name="tld_id" value="<?php echo $item['id']; ?>">
                                            <button type="submit" name="delete_domain_tld" class="text-red-400 hover:text-red-300 font-bold">🗑️</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- WHMCS settings -->
            <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl backdrop-blur-md">
                <h3 class="text-lg font-semibold text-white mb-4">⚙️ WHMCS API Configuration</h3>
                <form method="post" class="space-y-4">
                    <div>
                        <label class="block text-xs font-bold text-gray-400 mb-1">WHMCS API URL</label>
                        <input type="text" name="whmcs_api_url" value="<?php echo htmlspecialchars($configs['whmcs_api_url'] ?? ''); ?>" placeholder="https://clients.philmorehost.com/includes/api.php" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white text-xs focus:outline-none focus:border-blue-500">
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-400 mb-1">API Identifier</label>
                            <input type="text" name="whmcs_api_identifier" value="<?php echo htmlspecialchars($configs['whmcs_api_identifier'] ?? ''); ?>" placeholder="Identifier String" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white text-xs focus:outline-none focus:border-blue-500">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-400 mb-1">API Secret</label>
                            <input type="password" name="whmcs_api_secret" value="<?php echo htmlspecialchars($configs['whmcs_api_secret'] ?? ''); ?>" placeholder="••••••••••••••••" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white text-xs focus:outline-none focus:border-blue-500">
                        </div>
                    </div>
                    
                    <div class="border-t border-white/10 pt-4 mt-4 grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-400 mb-1">Nameservers (comma-separated)</label>
                            <textarea name="nameservers" rows="2" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-1.5 text-white text-xs focus:outline-none focus:border-blue-500"><?php echo htmlspecialchars($configs['nameservers'] ?? 'ns1.pmhserver.name.ng,ns2.pmhserver.name.ng'); ?></textarea>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-400 mb-1">A-Record IP Address</label>
                            <input type="text" name="ip_address" value="<?php echo htmlspecialchars($configs['ip_address'] ?? '173.208.213.194'); ?>" placeholder="173.208.213.194" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-1.5 text-white text-xs focus:outline-none focus:border-blue-500">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-400 mb-1">Recommended Registrar URL</label>
                            <input type="text" name="recommended_registrar_url" value="<?php echo htmlspecialchars($configs['recommended_registrar_url'] ?? 'https://clients.philmorehost.com'); ?>" placeholder="https://clients.philmorehost.com" class="w-full bg-white/5 border border-white/10 rounded-lg px-3 py-1.5 text-white text-xs focus:outline-none focus:border-blue-500">
                        </div>
                    </div>
                    
                    <button type="submit" name="save_domain_settings" class="w-full py-2.5 bg-green-600 hover:bg-green-700 text-white rounded-lg text-xs font-bold transition-all">
                        💾 Save Domain & WHMCS Configs
                    </button>
                </form>
            </div>

        </div>

        <!-- Right: Guide / Instructions -->
        <div class="space-y-6">
            <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl text-xs space-y-4">
                <h4 class="font-bold text-white text-sm">🌐 Domain Configurations Guide</h4>
                <p class="text-gray-400 leading-relaxed">Here you define the Nameservers, DNS settings, and TLD pricing that will be injected into school registration flow. Schools choosing "Register New Domain" will checkout with prices queried here and integrated with your WHMCS client area automation.</p>
                
                <div class="border-t border-white/10 pt-4 mt-4 space-y-3">
                    <h5 class="font-bold text-blue-300">🔑 WHMCS API Authentication Help</h5>
                    <p class="text-gray-400 leading-relaxed">WHMCS secures API endpoints using generated key pairs. To configure connection credentials:</p>
                    <ol class="list-decimal list-inside text-gray-400 space-y-2 leading-relaxed">
                        <li>Log in to your <strong>WHMCS Admin Panel</strong>.</li>
                        <li>Navigate to <strong>Configuration (wrench icon) > System Settings > API Credentials</strong> (or <em>Setup > Staff Management > API Credentials</em> on older setups).</li>
                        <li>Click <strong>Generate New API Credential</strong>.</li>
                        <li>Define an API Role name and grant permissions for WHOIS / Domain lookups and ordering.</li>
                        <li>Copy the generated <strong>API Identifier</strong> (which behaves like a username role key) and <strong>API Secret</strong> (the private access code) and paste them here.</li>
                    </ol>
                    <div class="p-3 bg-white/5 border border-white/5 rounded-lg text-gray-500 mt-2 leading-normal">
                        <strong>Note:</strong> If you previously used a single API Access Key (defined in <code>configuration.php</code>), please upgrade to generated API credentials under the admin panel for modern compatibility.
                    </div>
                </div>
            </div>
        </div>

    </div>
    <?php endif; ?>

    <?php if ($action === 'image_converter'): ?>
    <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl backdrop-blur-md space-y-6">
        <div>
            <h3 class="text-xl font-extrabold text-white">📸 Automated User Image Converter</h3>
            <p class="text-xs text-gray-400 mt-1">Scan, compress, and optimize all student passports and school logos inside the <code>dataimg/</code> folder to modern space-saving WebP formats.</p>
        </div>

        <div id="converter_setup" class="space-y-4">
            <div class="flex gap-4">
                <button onclick="scanImageDirectory()" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-bold text-sm transition">
                    🔍 Scan dataimg/ Directory
                </button>
            </div>
            
            <div id="scan_results" class="hidden p-4 bg-white/5 border border-white/10 rounded-xl space-y-2 text-sm">
                <p>Files Detected: <strong id="detected_count" class="text-blue-400">0</strong> images</p>
                <p>Total Directory Size: <strong id="detected_size" class="text-indigo-400">0.00</strong> MB</p>
                <p class="text-xs text-gray-400">WebP conversions typically reduce image sizes by 80% to 90% without visible loss in quality.</p>
                
                <button id="start_btn" onclick="startImageConversion()" class="mt-4 px-6 py-3 bg-gradient-to-r from-green-600 to-emerald-600 hover:from-green-700 hover:to-emerald-700 text-white rounded-lg font-extrabold text-sm transition shadow-lg w-full md:w-auto">
                    ⚡ Start Image Conversion & Optimization
                </button>
            </div>
        </div>

        <div id="converter_progress_panel" class="hidden space-y-4">
            <div class="flex justify-between text-sm">
                <span class="text-gray-300">Processing: <strong id="current_file" class="text-blue-400">...</strong></span>
                <span class="text-gray-300"><strong id="progress_ratio">0 / 0</strong> (<strong id="progress_percent">0%</strong>)</span>
            </div>
            
            <div class="w-full bg-white/10 rounded-full h-4 overflow-hidden border border-white/5">
                <div id="progress_bar" class="bg-gradient-to-r from-blue-500 to-indigo-500 h-full rounded-full transition-all duration-300" style="width: 0%;"></div>
            </div>
            
            <div class="p-3 bg-white/5 rounded-xl border border-white/5 text-xs font-mono max-h-40 overflow-y-auto space-y-1 text-gray-400" id="conversion_logs">
                <p class="text-blue-400">[System] Converter initialized...</p>
            </div>
        </div>

        <div id="converter_complete_panel" class="hidden p-6 bg-green-500/10 border border-green-500/25 rounded-2xl text-center space-y-4">
            <span class="text-4xl">🎉</span>
            <h4 class="text-lg font-bold text-green-400">Image Conversion Completed Successfully!</h4>
            <p class="text-sm text-gray-300">All scanned images have been converted to high-compression formats, and the large original uncompressed files have been deleted.</p>
            <div class="inline-block px-4 py-2 bg-green-500/10 border border-green-500/10 rounded-xl text-sm font-semibold">
                Total Disk Space Saved: <span id="total_savings" class="text-green-400 font-bold">0.00 MB</span>
            </div>
            <div>
                <a href="?page=super_admin&action=image_converter" class="inline-block px-4 py-2 bg-white/10 hover:bg-white/15 text-white rounded-lg text-xs font-semibold transition">
                    Reset Converter
                </a>
            </div>
        </div>
    </div>

    <script>
        let filesToConvert = [];
        let totalCount = 0;
        let processedCount = 0;
        let totalSavingsBytes = 0;

        function scanImageDirectory() {
            const scanResults = document.getElementById('scan_results');
            scanResults.classList.add('hidden');
            
            const formData = new FormData();
            formData.append('ajax_scan_images', '1');

            fetch('dashboard.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    filesToConvert = data.files || [];
                    totalCount = filesToConvert.length;
                    
                    document.getElementById('detected_count').textContent = totalCount;
                    document.getElementById('detected_size').textContent = data.total_size_mb;
                    
                    if (totalCount > 0) {
                        scanResults.classList.remove('hidden');
                    } else {
                        alert("No uncompressed images found in dataimg/ directory! All files are already optimized.");
                    }
                } else {
                    alert("Scan Error: " + data.message);
                }
            })
            .catch(err => {
                console.error(err);
                alert("Connection failed to server scanner.");
            });
        }

        const batchSize = 5;

        function startImageConversion() {
            if (filesToConvert.length === 0) return;
            
            document.getElementById('converter_setup').classList.add('hidden');
            document.getElementById('converter_progress_panel').classList.remove('hidden');
            
            processedCount = 0;
            totalSavingsBytes = 0;
            
            processNextBatch();
        }

        function processNextBatch() {
            if (processedCount >= totalCount) {
                // Done!
                document.getElementById('converter_progress_panel').classList.add('hidden');
                document.getElementById('converter_complete_panel').classList.remove('hidden');
                
                const savingsMB = (totalSavingsBytes / (1024 * 1024)).toFixed(2);
                document.getElementById('total_savings').textContent = savingsMB + ' MB';
                
                // Trigger notification sound or alert
                alert("🎉 Image migration and optimization successfully completed! " + savingsMB + " MB of disk space freed.");
                return;
            }

            const batch = filesToConvert.slice(processedCount, processedCount + batchSize);
            document.getElementById('current_file').textContent = `Batch of ${batch.length} files...`;
            
            // Update counts
            const displayCount = Math.min(processedCount + batch.length, totalCount);
            document.getElementById('progress_ratio').textContent = `${displayCount} / ${totalCount}`;
            const pct = Math.round((displayCount / totalCount) * 100);
            document.getElementById('progress_percent').textContent = pct + '%';
            document.getElementById('progress_bar').style.width = pct + '%';

            const logBox = document.getElementById('conversion_logs');
            
            const formData = new FormData();
            formData.append('ajax_convert_image', '1');
            batch.forEach(file => {
                formData.append('filenames[]', file);
            });

            fetch('dashboard.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success' && data.results) {
                    data.results.forEach(res => {
                        const log = document.createElement('p');
                        if (res.status === 'success') {
                            totalSavingsBytes += res.savings;
                            const savingsKB = (res.savings / 1024).toFixed(1);
                            log.className = 'text-green-400';
                            log.textContent = `✓ [Success] Converted ${res.filename} to .${res.format} (Saved ${savingsKB} KB)`;
                        } else if (res.status === 'skipped') {
                            log.className = 'text-amber-400';
                            log.textContent = `⚠ [Skipped] ${res.filename}: ${res.message}`;
                        } else {
                            log.className = 'text-red-400';
                            log.textContent = `✗ [Failed] ${res.filename}: ${res.message}`;
                        }
                        logBox.appendChild(log);
                    });
                } else {
                    const log = document.createElement('p');
                    log.className = 'text-red-400';
                    log.textContent = `✗ [Error] Batch failed: ${data.message || 'Unknown error'}`;
                    logBox.appendChild(log);
                }
                logBox.scrollTop = logBox.scrollHeight;
                
                processedCount += batch.length;
                // 250ms cooldown pause between requests to let the server breathe
                setTimeout(processNextBatch, 250);
            })
            .catch(err => {
                const log = document.createElement('p');
                log.className = 'text-red-400';
                log.textContent = `✗ [Error] Network failure processing batch`;
                logBox.appendChild(log);
                logBox.scrollTop = logBox.scrollHeight;
                
                processedCount += batch.length;
                setTimeout(processNextBatch, 500);
            });
        }
    </script>
    <?php endif; ?>

    <?php if ($action === 'license_settings'): 
        $stmtKey = $pdo->prepare("SELECT setting_value FROM sm_global_settings WHERE setting_key = 'license_key' LIMIT 1");
        $stmtKey->execute();
        $db_license_key = $stmtKey->fetchColumn() ?: '';
    ?>
    <div class="space-y-6">
        <div>
            <h3 class="text-xl font-extrabold text-white">🔑 Script License Management</h3>
            <p class="text-xs text-gray-400 mt-1">Configure, activate, and manage your script license key. Validates directly against the official License Manager API.</p>
        </div>

        <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl backdrop-blur-md">
            <h4 class="text-md font-semibold text-blue-300 mb-4">Update Product License</h4>
            
            <form method="post" class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-gray-400 mb-1">Active License Key</label>
                    <input type="text" name="license_key" value="<?php echo htmlspecialchars($db_license_key); ?>" placeholder="XXXX-XXXX-XXXX-XXXX" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2.5 text-white focus:outline-none focus:border-blue-500 text-sm font-mono" />
                    <p class="text-xs text-gray-500 mt-1">The license is validated for the current host domain: <strong><?php echo htmlspecialchars($_SERVER['HTTP_HOST']); ?></strong></p>
                </div>
                
                <div class="flex justify-end pt-2">
                    <button type="submit" name="save_license_settings" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-bold text-xs shadow-md transition-all">
                        Validate & Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
function toggleAddonPanel(schoolId) {
    const panel = document.getElementById('addon-panel-' + schoolId);
    if (panel) panel.classList.toggle('hidden');
}
</script>

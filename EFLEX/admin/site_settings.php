<?php
// Include the new admin header
include 'includes/admin_header.php';
require_permission('manage_site_settings');

$message = "";

// One-time cleanup for obsolete PWA and OneSignal settings
$cleanup_check_sql = "SELECT setting_key FROM settings WHERE setting_key = 'pwa_enabled'";
$cleanup_result = $mysqli->query($cleanup_check_sql);
if ($cleanup_result && $cleanup_result->num_rows > 0) {
    $obsolete_keys = [
        'pwa_enabled',
        'pwa_app_name',
        'pwa_app_short_name',
        'pwa_theme_color',
        'pwa_bg_color',
        'onesignal_app_id'
    ];
    $placeholders = implode(',', array_fill(0, count($obsolete_keys), '?'));
    $cleanup_sql = "DELETE FROM settings WHERE setting_key IN ($placeholders)";
    if ($stmt_cleanup = $mysqli->prepare($cleanup_sql)) {
        $stmt_cleanup->bind_param(str_repeat('s', count($obsolete_keys)), ...$obsolete_keys);
        $stmt_cleanup->execute();
        $stmt_cleanup->close();
        $message .= '<div class="alert alert-info">Obsolete PWA and OneSignal settings have been removed from the database.</div>';
    }
}

// Handle form submission
if($_SERVER["REQUEST_METHOD"] == "POST"){
    // We are saving key-value pairs, so we can just loop through the POST data
    $sql = "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)";
    $stmt = $mysqli->prepare($sql);

    // Handle checkboxes that might not be in POST if unchecked
    $checkboxes = [
        'bank_transfer_enabled', 'paystack_enabled', 'stripe_enabled', 'flutterwave_enabled',
        'social_links_enabled', 'ga_enabled', 'whatsapp_enabled',
        'otp_login_enabled', 'otp_register_enabled'
    ];
    foreach($checkboxes as $cb){
        if(!isset($_POST[$cb])){
            $_POST[$cb] = '0';
        }
    }

    foreach($_POST as $key => $value){
        // Skip file inputs and the submit button
        if(strpos($key, '_icon_') !== false || $key === 'save_settings') continue;

        $value = is_array($value) ? json_encode($value) : $value;
        $stmt->bind_param("ss", $key, $value);
        $stmt->execute();
    }

    // Handle File Uploads for PWA icons
    // (This part is complex and might need a dedicated handler, simplified for now)
    // ...

    $stmt->close();
    $message = '<div class="alert alert-success">Settings saved successfully.</div>';
}


// Fetch current settings to display in the form
$settings_sql = "SELECT setting_key, setting_value FROM settings";
$result = $mysqli->query($settings_sql);
$settings = [];
while($row = $result->fetch_assoc()){
    $settings[$row['setting_key']] = $row['setting_value'];
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Site Settings</h2>
</div>

<?php echo $message; ?>

<form action="site_settings.php" method="post" enctype="multipart/form-data">
    <div class="row">
        <div class="col-lg-8">
            <!-- General Settings -->
            <div class="card shadow mb-4">
                <div class="card-header">General Settings</div>
                <div class="card-body">
                    <div class="mb-3"><label for="site_name" class="form-label">Site Name</label><input type="text" name="site_name" class="form-control" id="site_name" value="<?php echo htmlspecialchars($settings['site_name'] ?? 'Eflex'); ?>"></div>
                    <div class="mb-3"><label for="admin_notification_email" class="form-label">Admin Notification Email</label><input type="email" name="admin_notification_email" class="form-control" id="admin_notification_email" value="<?php echo htmlspecialchars($settings['admin_notification_email'] ?? ''); ?>" placeholder="admin@example.com"><div class="form-text">The email address to receive notifications for new orders, logins, etc.</div></div>
                    <div class="row"><div class="col-md-6"><label for="currency_code" class="form-label">Currency Code</label><input type="text" name="currency_code" class="form-control" id="currency_code" value="<?php echo htmlspecialchars($settings['currency_code'] ?? 'USD'); ?>"></div><div class="col-md-6"><label for="currency_symbol" class="form-label">Currency Symbol</label><input type="text" name="currency_symbol" class="form-control" id="currency_symbol" value="<?php echo htmlspecialchars($settings['currency_symbol'] ?? '$'); ?>"></div></div>
                    <div class="row mt-3"><div class="col-md-6"><label for="language" class="form-label">Site Language</label><select name="language" id="language" class="form-select"><option value="en" <?php if(($settings['language'] ?? 'en') == 'en') echo 'selected'; ?>>English</option><option value="es" <?php if(($settings['language'] ?? '') == 'es') echo 'selected'; ?>>Español</option><option value="fr" <?php if(($settings['language'] ?? '') == 'fr') echo 'selected'; ?>>Français</option></select></div></div>
                    <div class="mt-3"><label for="copyright_text" class="form-label">Copyright Text (HTML allowed)</label><textarea name="copyright_text" class="form-control" id="copyright_text" rows="3"><?php echo htmlspecialchars($settings['copyright_text'] ?? '© ' . date('Y') . ' Eflex E-commerce. All Rights Reserved.'); ?></textarea></div>
                     <div class="mt-3"><label for="footer_about_us" class="form-label">Footer About Us Text</label><textarea name="footer_about_us" class="form-control" id="footer_about_us" rows="4"><?php echo htmlspecialchars($settings['footer_about_us'] ?? 'Your one-stop shop for the best products at the best prices. We are committed to providing quality and value.'); ?></textarea></div>
                </div>
            </div>
            <!-- Company Details -->
            <div class="card shadow mb-4"><div class="card-header">Company Details (for Invoices)</div><div class="card-body"><div class="mb-3"><label class="form-label">Company Name</label><input type="text" name="company_name" class="form-control" value="<?php echo htmlspecialchars($settings['company_name'] ?? ''); ?>"></div><div class="mb-3"><label class="form-label">Company Address</label><textarea name="company_address" class="form-control" rows="3"><?php echo htmlspecialchars($settings['company_address'] ?? ''); ?></textarea></div><div class="mb-3"><label class="form-label">Company Phone</label><input type="text" name="company_phone" class="form-control" value="<?php echo htmlspecialchars($settings['company_phone'] ?? ''); ?>"></div></div></div>
            <!-- SEO Settings -->
            <div class="card shadow mb-4"><div class="card-header">SEO Settings</div><div class="card-body"><div class="mb-3"><label for="meta_title" class="form-label">Meta Title</label><input type="text" name="meta_title" class="form-control" id="meta_title" value="<?php echo htmlspecialchars($settings['meta_title'] ?? ''); ?>"></div><div class="mb-3"><label for="meta_description" class="form-label">Meta Description</label><textarea name="meta_description" class="form-control" id="meta_description" rows="3"><?php echo htmlspecialchars($settings['meta_description'] ?? ''); ?></textarea></div><div class="mb-3"><label for="meta_keywords" class="form-label">Meta Keywords</label><input type="text" name="meta_keywords" class="form-control" id="meta_keywords" value="<?php echo htmlspecialchars($settings['meta_keywords'] ?? ''); ?>"><div class="form-text">Comma-separated values.</div></div></div></div>
            <!-- Security Settings -->
            <div class="card shadow mb-4">
                <div class="card-header">Security Settings</div>
                <div class="card-body">
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="otp_login_enabled" value="1" <?php echo !empty($settings['otp_login_enabled']) && $settings['otp_login_enabled'] == '1' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="otp_login_enabled">Enable OTP for Admin Login</label>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="otp_register_enabled" value="1" <?php echo !empty($settings['otp_register_enabled']) && $settings['otp_register_enabled'] == '1' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="otp_register_enabled">Enable OTP for User Registration</label>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <!-- Theme & Color Settings -->
            <div class="card shadow mb-4"><div class="card-header">Theme & Color Settings</div><div class="card-body"><div class="mb-3"><label class="form-label">Primary Color</label><input type="color" name="theme_primary_color" class="form-control form-control-color" value="<?php echo htmlspecialchars($settings['theme_primary_color'] ?? '#ae8e6a'); ?>"></div><div class="mb-3"><label class="form-label">Secondary Color</label><input type="color" name="theme_secondary_color" class="form-control form-control-color" value="<?php echo htmlspecialchars($settings['theme_secondary_color'] ?? '#f2f2f2'); ?>"></div></div></div>

            <!-- SMTP Settings -->
            <div class="card shadow mb-4">
                <div class="card-header">SMTP Settings</div>
                <div class="card-body">
                    <div class="mb-3"><label for="from_name" class="form-label">From Name</label><input type="text" name="from_name" class="form-control" id="from_name" value="<?php echo htmlspecialchars($settings['from_name'] ?? 'Eflex'); ?>"></div>
                    <div class="mb-3"><label for="from_email" class="form-label">From Email</label><input type="email" name="from_email" class="form-control" id="from_email" value="<?php echo htmlspecialchars($settings['from_email'] ?? ''); ?>"></div>
                    <hr>
                    <div class="mb-3"><label for="smtp_host" class="form-label">SMTP Host</label><input type="text" name="smtp_host" class="form-control" id="smtp_host" value="<?php echo htmlspecialchars($settings['smtp_host'] ?? ''); ?>"></div>
                    <div class="row">
                        <div class="col-md-6"><label for="smtp_port" class="form-label">SMTP Port</label><input type="number" name="smtp_port" class="form-control" id="smtp_port" value="<?php echo htmlspecialchars($settings['smtp_port'] ?? '587'); ?>"></div>
                        <div class="col-md-6">
                            <label for="smtp_encryption" class="form-label">Encryption</label>
                            <?php $smtp_encryption = $settings['smtp_encryption'] ?? 'tls'; ?>
                            <select name="smtp_encryption" id="smtp_encryption" class="form-select">
                                <option value="none" <?php if($smtp_encryption == 'none') echo 'selected'; ?>>None</option>
                                <option value="ssl" <?php if($smtp_encryption == 'ssl') echo 'selected'; ?>>SSL</option>
                                <option value="tls" <?php if($smtp_encryption == 'tls') echo 'selected'; ?>>TLS</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3 mt-3"><label for="smtp_user" class="form-label">SMTP Username</label><input type="text" name="smtp_user" class="form-control" id="smtp_user" value="<?php echo htmlspecialchars($settings['smtp_user'] ?? ''); ?>"></div>
                    <div class="mb-3"><label for="smtp_pass" class="form-label">SMTP Password</label><input type="password" name="smtp_pass" class="form-control" id="smtp_pass" value="<?php echo htmlspecialchars($settings['smtp_pass'] ?? ''); ?>"></div>
                </div>
            </div>

            <!-- Social Media Links -->
            <div class="card shadow mb-4"><div class="card-header">Social Media Links</div><div class="card-body"><div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="social_links_enabled" value="1" <?php echo !empty($settings['social_links_enabled']) && $settings['social_links_enabled'] == '1' ? 'checked' : ''; ?>><label class="form-check-label">Enable Social Links</label></div><hr><div class="mb-3"><label class="form-label">Facebook URL</label><input type="url" name="social_facebook" class="form-control" value="<?php echo htmlspecialchars($settings['social_facebook'] ?? ''); ?>"></div><div class="mb-3"><label class="form-label">Twitter (X) URL</label><input type="url" name="social_twitter" class="form-control" value="<?php echo htmlspecialchars($settings['social_twitter'] ?? ''); ?>"></div><div class="mb-3"><label class="form-label">Instagram URL</label><input type="url" name="social_instagram" class="form-control" value="<?php echo htmlspecialchars($settings['social_instagram'] ?? ''); ?>"></div><div class="mb-3"><label class="form-label">YouTube URL</label><input type="url" name="social_youtube" class="form-control" value="<?php echo htmlspecialchars($settings['social_youtube'] ?? ''); ?>"></div><div class="mb-3"><label class="form-label">Threads URL</label><input type="url" name="social_threads" class="form-control" value="<?php echo htmlspecialchars($settings['social_threads'] ?? ''); ?>"></div></div></div>
            <!-- Integrations -->
            <div class="card shadow mb-4"><div class="card-header">Integrations</div><div class="card-body"><h6>Google Analytics</h6><div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="ga_enabled" value="1" <?php echo !empty($settings['ga_enabled']) && $settings['ga_enabled'] == '1' ? 'checked' : ''; ?>><label class="form-check-label">Enable Google Analytics</label></div><div class="mb-3"><label class="form-label">GA Tracking ID</label><input type="text" name="ga_tracking_id" class="form-control" value="<?php echo htmlspecialchars($settings['ga_tracking_id'] ?? ''); ?>" placeholder="G-XXXXXXXXXX"></div><hr><h6>WhatsApp Chat</h6><div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="whatsapp_enabled" value="1" <?php echo !empty($settings['whatsapp_enabled']) && $settings['whatsapp_enabled'] == '1' ? 'checked' : ''; ?>><label class="form-check-label">Enable WhatsApp Chat</label></div><div class="mb-3"><label class="form-label">WhatsApp Number</label><input type="text" name="whatsapp_number" class="form-control" value="<?php echo htmlspecialchars($settings['whatsapp_number'] ?? ''); ?>" placeholder="+1234567890"></div></div></div>
        </div>
    </div>
    <div class="row"><div class="col-12"><div class="card shadow mb-4"><div class="card-header">Payment Gateway Settings</div><div class="card-body"><div class="row"><div class="col-md-6 border-end"><h6 class="text-center">Bank Transfer</h6><div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="bank_transfer_enabled" value="1" <?php echo !empty($settings['bank_transfer_enabled']) && $settings['bank_transfer_enabled'] == '1' ? 'checked' : ''; ?>><label class="form-check-label">Enable</label></div><div class="mb-3"><label class="form-label">Account Name</label><input type="text" name="bank_account_name" class="form-control" value="<?php echo htmlspecialchars($settings['bank_account_name'] ?? ''); ?>"></div><div class="mb-3"><label class="form-label">Account Number</label><input type="text" name="bank_account_number" class="form-control" value="<?php echo htmlspecialchars($settings['bank_account_number'] ?? ''); ?>"></div><div class="mb-3"><label class="form-label">Bank Name</label><input type="text" name="bank_name" class="form-control" value="<?php echo htmlspecialchars($settings['bank_name'] ?? ''); ?>"></div><div class="mb-3"><label class="form-label">Instructions</label><textarea name="bank_payment_instructions" class="form-control" rows="4"><?php echo htmlspecialchars($settings['bank_payment_instructions'] ?? ''); ?></textarea></div></div><div class="col-md-6"><h6 class="text-center">Paystack</h6><div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="paystack_enabled" value="1" <?php echo !empty($settings['paystack_enabled']) && $settings['paystack_enabled'] == '1' ? 'checked' : ''; ?>><label class="form-check-label">Enable</label></div><div class="mb-3"><label class="form-label">Public Key</label><input type="text" name="paystack_public_key" class="form-control" value="<?php echo htmlspecialchars($settings['paystack_public_key'] ?? ''); ?>"></div><div class="mb-3"><label class="form-label">Secret Key</label><input type="password" name="paystack_secret_key" class="form-control" value="<?php echo htmlspecialchars($settings['paystack_secret_key'] ?? ''); ?>"></div></div></div><hr><div class="row mt-3"><div class="col-md-6 border-end"><h6 class="text-center">Stripe</h6><div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="stripe_enabled" value="1" <?php echo !empty($settings['stripe_enabled']) && $settings['stripe_enabled'] == '1' ? 'checked' : ''; ?>><label class="form-check-label">Enable</label></div><div class="mb-3"><label class="form-label">Publishable Key</label><input type="text" name="stripe_public_key" class="form-control" value="<?php echo htmlspecialchars($settings['stripe_public_key'] ?? ''); ?>"></div><div class="mb-3"><label class="form-label">Secret Key</label><input type="password" name="stripe_secret_key" class="form-control" value="<?php echo htmlspecialchars($settings['stripe_secret_key'] ?? ''); ?>"></div></div><div class="col-md-6"><h6 class="text-center">Flutterwave</h6><div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="flutterwave_enabled" value="1" <?php echo !empty($settings['flutterwave_enabled']) && $settings['flutterwave_enabled'] == '1' ? 'checked' : ''; ?>><label class="form-check-label">Enable</label></div><div class="mb-3"><label class="form-label">Public Key</label><input type="text" name="flutterwave_public_key" class="form-control" value="<?php echo htmlspecialchars($settings['flutterwave_public_key'] ?? ''); ?>"></div><div class="mb-3"><label class="form-label">Secret Key</label><input type="password" name="flutterwave_secret_key" class="form-control" value="<?php echo htmlspecialchars($settings['flutterwave_secret_key'] ?? ''); ?>"></div></div></div></div></div></div></div>
    <button type="submit" name="save_settings" class="btn btn-primary mt-3 mb-4">Save All Settings</button>
</form>

<?php
// Include the new admin footer
include 'includes/admin_footer.php';
?>

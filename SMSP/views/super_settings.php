<?php
// Super Admin — API & Gateway Settings View
if (!defined('PORTAL_PATH')) exit;
if ($user_role !== 'super_admin') {
    echo '<div class="p-6 text-red-400 font-semibold">⛔ Access Denied.</div>';
    return;
}

$msg = '';

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $settings_to_save = [
        'deepseek_api_key'      => trim($_POST['deepseek_api_key']),
        'deepseek_api_base_url' => trim($_POST['deepseek_api_base_url']),
        'bulk_sms_api_key'    => trim($_POST['bulk_sms_api_key']),
        'bulk_sms_sender_id'  => trim($_POST['bulk_sms_sender_id']),
        'sms_price_per_credit'=> trim($_POST['sms_price_per_credit']),
        'platform_ip'         => trim($_POST['platform_ip']),
        'platform_ns1'        => trim($_POST['platform_ns1']),
        'platform_ns2'        => trim($_POST['platform_ns2']),
        'smtp_host'           => trim($_POST['smtp_host']),
        'smtp_port'           => trim($_POST['smtp_port']),
        'smtp_username'       => trim($_POST['smtp_username']),
        'smtp_password'       => $_POST['smtp_password'],
        'smtp_encryption'     => trim($_POST['smtp_encryption'])
    ];

    try {
        $stmt = $pdo->prepare("INSERT INTO sm_global_settings (setting_key, setting_value) VALUES (?, ?) 
                               ON DUPLICATE KEY UPDATE setting_value = ?");
        foreach ($settings_to_save as $key => $val) {
            $stmt->execute([$key, $val, $val]);
        }
        $msg = "Global platform settings updated successfully! Changes will take effect immediately.";
        
        // Reload constants for this execution
        foreach ($settings_to_save as $key => $val) {
            $const_name = strtoupper($key);
            if (!defined($const_name)) {
                define($const_name, $val);
            }
        }
    } catch (PDOException $e) {
        $msg = "Error updating settings: " . $e->getMessage();
    }
}

// Fetch current values
$current = [];
try {
    $stmt = $pdo->query("SELECT * FROM sm_global_settings");
    foreach ($stmt->fetchAll() as $row) {
        $current[$row['setting_key']] = $row['setting_value'];
    }
} catch (PDOException $e) {
    // Ignore
}
?>

<div class="space-y-6">
    <div>
        <h2 class="text-2xl font-bold bg-gradient-to-r from-blue-400 to-indigo-400 bg-clip-text text-transparent">🔑 API & Gateway Settings</h2>
        <p class="text-sm text-gray-400 mt-1">Configure global service keys, SMS gateways, and mail parameters.</p>
    </div>

    <?php if ($msg) : ?>
        <div class="p-4 bg-blue-500/10 border border-blue-500/20 text-blue-400 rounded-xl text-sm">
            <?php echo $msg; ?>
        </div>
    <?php endif; ?>

    <form method="post" class="space-y-6">
        <!-- AI configs -->
        <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl backdrop-blur-md">
            <h3 class="text-lg font-semibold mb-2 text-blue-300 flex items-center gap-2">🤖 AI Engines & Copilot Gateways</h3>
            <p class="text-xs text-gray-400 mb-6">Configure the API parameters for DeepSeek AI. The platform uses DeepSeek to generate weekly lesson plans, student report card comments, and power the interactive Study Buddy chatbot.</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-400 mb-1">DeepSeek API Key</label>
                    <input type="password" name="deepseek_api_key" value="<?php echo htmlspecialchars($current['deepseek_api_key'] ?? ''); ?>" placeholder="sk-..." class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 font-mono text-sm" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-400 mb-1">DeepSeek API Base URL</label>
                    <input type="text" name="deepseek_api_base_url" value="<?php echo htmlspecialchars($current['deepseek_api_base_url'] ?? 'https://api.deepseek.com/v1'); ?>" placeholder="https://api.deepseek.com/v1" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 font-mono text-sm" />
                </div>
            </div>
        </div>

        <!-- Bulk SMS Gateway -->
        <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl backdrop-blur-md">
            <h3 class="text-lg font-semibold mb-4 text-indigo-300 flex items-center gap-2">📱 Bulk SMS Gateway Configuration</h3>
            <p class="text-xs text-gray-400 mb-4">Set up your SMS gateway provider key to broadcast emergency alerts, fees reminders, and event notices to parents and teachers.</p>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-400 mb-1">SMS API Gateway Secret Key</label>
                    <input type="password" name="bulk_sms_api_key" value="<?php echo htmlspecialchars($current['bulk_sms_api_key'] ?? ''); ?>" placeholder="Enter API secret / auth token" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 font-mono" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-400 mb-1">Default Sender ID (Name)</label>
                    <input type="text" name="bulk_sms_sender_id" value="<?php echo htmlspecialchars($current['bulk_sms_sender_id'] ?? ''); ?>" placeholder="e.g. SCHOOLAPP" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-400 mb-1">Default SMS Price Per Credit (₦)</label>
                    <input type="number" step="0.01" name="sms_price_per_credit" value="<?php echo htmlspecialchars($current['sms_price_per_credit'] ?? '5.00'); ?>" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                </div>
            </div>
        </div>

        <!-- Domain Routing Settings -->
        <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl backdrop-blur-md">
            <h3 class="text-lg font-semibold mb-4 text-indigo-300 flex items-center gap-2">🔗 Domain Routing & Platform DNS</h3>
            <p class="text-xs text-gray-400 mb-4">Set up the server IP and Nameserver records that school admins will use to map their custom domains/subdomains.</p>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-400 mb-1">Server Public IP Address</label>
                    <input type="text" name="platform_ip" value="<?php echo htmlspecialchars($current['platform_ip'] ?? '123.45.67.89'); ?>" placeholder="e.g. 123.45.67.89" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 font-mono text-sm" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-400 mb-1">Primary Nameserver (NS1)</label>
                    <input type="text" name="platform_ns1" value="<?php echo htmlspecialchars($current['platform_ns1'] ?? 'ns1.schoolapp.ng'); ?>" placeholder="e.g. ns1.schoolapp.ng" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 font-mono text-sm" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-400 mb-1">Secondary Nameserver (NS2)</label>
                    <input type="text" name="platform_ns2" value="<?php echo htmlspecialchars($current['platform_ns2'] ?? 'ns2.schoolapp.ng'); ?>" placeholder="e.g. ns2.schoolapp.ng" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500 font-mono text-sm" />
                </div>
            </div>
        </div>

        <!-- Global SMTP Settings -->
        <div class="bg-white/5 border border-white/10 rounded-2xl p-6 shadow-xl backdrop-blur-md">
            <h3 class="text-lg font-semibold mb-4 text-green-300 flex items-center gap-2">✉️ Global SMTP Mailer</h3>
            <p class="text-xs text-gray-400 mb-4">Configure the default SMTP mail server settings to send verification emails, invoices, and password recovery codes to users across all school tenants.</p>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-medium text-gray-400 mb-1">SMTP Host</label>
                    <input type="text" name="smtp_host" value="<?php echo htmlspecialchars($current['smtp_host'] ?? ''); ?>" placeholder="smtp.mailgun.org" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-400 mb-1">SMTP Port</label>
                    <input type="text" name="smtp_port" value="<?php echo htmlspecialchars($current['smtp_port'] ?? ''); ?>" placeholder="587" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-400 mb-1">SMTP Encryption</label>
                    <select name="smtp_encryption" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500">
                        <option value="tls" <?php echo (($current['smtp_encryption'] ?? '') === 'tls') ? 'selected' : ''; ?>>TLS</option>
                        <option value="ssl" <?php echo (($current['smtp_encryption'] ?? '') === 'ssl') ? 'selected' : ''; ?>>SSL</option>
                        <option value="none" <?php echo (($current['smtp_encryption'] ?? '') === 'none') ? 'selected' : ''; ?>>None</option>
                    </select>
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-400 mb-1">SMTP Username</label>
                    <input type="text" name="smtp_username" value="<?php echo htmlspecialchars($current['smtp_username'] ?? ''); ?>" placeholder="postmaster@yourdomain.com" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-400 mb-1">SMTP Password</label>
                    <input type="password" name="smtp_password" value="<?php echo htmlspecialchars($current['smtp_password'] ?? ''); ?>" placeholder="••••••••••••" class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-blue-500" />
                </div>
            </div>
        </div>

        <button type="submit" name="save_settings" class="w-full py-3 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white rounded-xl font-bold text-sm shadow-lg transition-all transform hover:scale-[1.01]">
            💾 Save Global Settings & API Keys
        </button>
    </form>
</div>

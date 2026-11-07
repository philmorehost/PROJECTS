<?php
$php_version = phpversion();
$php_ok = version_compare($php_version, '8.1.0', '>=');

$required_extensions = [
    'pdo_mysql' => extension_loaded('pdo_mysql'),
    'mbstring' => extension_loaded('mbstring'),
    'openssl' => extension_loaded('openssl'),
    'curl' => extension_loaded('curl'),
    'gd' => extension_loaded('gd'),
    'zip' => extension_loaded('zip')
];

$system_ok = $php_ok && !in_array(false, $required_extensions, true);

$error = '';
$success = false;
$license_key = $_SESSION['license_key'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_license'])) {
    $license_key = trim($_POST['license_key']);
    
    if (empty($license_key)) {
        $error = 'Please enter a valid license key.';
    } else {
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
        
        if ($http_code === 200 && $response) {
            $result = json_decode($response, true);
            if (isset($result['status']) && $result['status'] == 1) {
                $_SESSION['license_key'] = $license_key;
                $success = true;
            } else {
                $error = isset($result['message']) ? $result['message'] : 'Invalid license key or domain mismatch.';
            }
        } else {
            $error = 'Could not contact the license verification server. Please verify your internet connection and try again.';
        }
    }
}
?>
<div>
    <h2 class="text-xl font-semibold mb-4 text-blue-300">System Compatibility & License Check</h2>
    <p class="text-gray-300 mb-6">Verifying that your environment matches standard requirements and validating your license key to activate the script.</p>

    <?php if ($error) : ?>
        <div class="mb-6 p-4 bg-red-500/10 border border-red-500/20 text-red-400 rounded-lg text-sm">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <div class="space-y-4 mb-8">
        <div class="flex items-center justify-between p-3 rounded-lg <?php echo $php_ok ? 'bg-green-500/10 border border-green-500/20' : 'bg-red-500/10 border border-red-500/20'; ?>">
            <span>PHP Version (Current: <?php echo $php_version; ?>, Required: >= 8.1.0)</span>
            <span class="font-bold <?php echo $php_ok ? 'text-green-400' : 'text-red-400'; ?>">
                <?php echo $php_ok ? 'OK' : 'Fail'; ?>
            </span>
        </div>

        <?php foreach ($required_extensions as $ext => $loaded) : ?>
            <div class="flex items-center justify-between p-3 rounded-lg <?php echo $loaded ? 'bg-green-500/10 border border-green-500/20' : 'bg-red-500/10 border border-red-500/20'; ?>">
                <span>Extension: <?php echo $ext; ?></span>
                <span class="font-bold <?php echo $loaded ? 'text-green-400' : 'text-red-400'; ?>">
                    <?php echo $loaded ? 'Available' : 'Missing'; ?>
                </span>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($system_ok) : ?>
        <?php if ($success || !empty($_SESSION['license_key'])) : ?>
            <div class="mb-6 p-4 bg-green-500/10 border border-green-500/20 text-green-400 rounded-lg text-sm flex flex-col items-center">
                <span class="font-bold mb-2">✓ License Key Validated Successfully!</span>
                <a href="?step=2" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-semibold transition-all">
                    Proceed to Database Configuration
                </a>
            </div>
        <?php else : ?>
            <form method="post" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-400 mb-1">Product License Key</label>
                    <input type="text" name="license_key" placeholder="XXXX-XXXX-XXXX-XXXX" value="<?php echo htmlspecialchars($license_key); ?>" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2.5 text-white focus:outline-none focus:border-blue-500 placeholder-gray-600 font-mono" />
                </div>
                
                <div class="flex justify-end pt-4">
                    <button type="submit" name="verify_license" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-semibold transition-all">
                        Verify License Key
                    </button>
                </div>
            </form>
        <?php endif; ?>
    <?php else : ?>
        <div class="flex justify-end">
            <button disabled class="px-6 py-2.5 bg-gray-600 text-gray-400 rounded-lg cursor-not-allowed font-semibold">
                Fix Compatibility Issues to Continue
            </button>
        </div>
    <?php endif; ?>
</div>

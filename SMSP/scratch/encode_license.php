<?php
$code = <<<'CODE'
function display_license_error($message, $setup_error = '') {
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>License Verification Required</title>
        <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;800&display=swap" rel="stylesheet">
        <script src="https://cdn.tailwindcss.com"></script>
        <style>
            body {
                font-family: 'Outfit', sans-serif;
                background: #080d18;
                background-image: radial-gradient(ellipse 80% 50% at 50% -10%, rgba(59, 130, 246, 0.1) 0%, transparent 60%);
            }
        </style>
    </head>
    <body class="text-white min-h-screen flex items-center justify-center p-4">
        <div class="w-full max-w-md bg-white/5 backdrop-blur-md border border-white/10 rounded-2xl p-8 shadow-2xl text-center">
            <div class="w-16 h-16 bg-blue-500/10 border border-blue-500/20 rounded-2xl flex items-center justify-center text-3xl mx-auto mb-6">🔑</div>
            <h1 class="text-2xl font-bold tracking-tight text-white mb-2">License Key Required</h1>
            <p class="text-gray-300 text-sm mb-6"><?php echo htmlspecialchars($message); ?></p>
            
            <?php if (!empty($setup_error)) : ?>
                <div class="mb-6 p-4 bg-red-500/10 border border-red-500/20 text-red-400 rounded-lg text-xs text-left">
                    <strong>Error:</strong> <?php echo htmlspecialchars($setup_error); ?>
                </div>
            <?php endif; ?>

            <form method="post" class="space-y-4 text-left">
                <div>
                    <label class="block text-xs font-semibold text-gray-400 mb-1">Enter License Key</label>
                    <input type="text" name="license_key" placeholder="XXXX-XXXX-XXXX-XXXX" required class="w-full bg-white/5 border border-white/10 rounded-lg px-4 py-2.5 text-white focus:outline-none focus:border-blue-500 placeholder-gray-600 font-mono text-sm" />
                </div>
                <button type="submit" name="submit_license_setup" class="w-full py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-bold text-sm transition shadow-lg">
                    Activate Product License
                </button>
            </form>
            
            <div class="text-[10px] text-gray-500 mt-6">Domain: <?php echo htmlspecialchars($_SERVER['HTTP_HOST']); ?></div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

global $pdo;
$license_key = '';
$main_domain = '';

if (isset($pdo)) {
    try {
        $stmtKey = $pdo->prepare("SELECT setting_key, setting_value FROM sm_global_settings WHERE setting_key IN ('license_key', 'main_domain')");
        $stmtKey->execute();
        $settings = $stmtKey->fetchAll(PDO::FETCH_KEY_PAIR);
        $license_key = $settings['license_key'] ?? '';
        $main_domain = $settings['main_domain'] ?? '';
    } catch (PDOException $e) {
    }
}

if (empty($license_key) && defined('LICENSE_KEY')) {
    $license_key = LICENSE_KEY;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_license_setup'])) {
    $license_key = trim($_POST['license_key'] ?? '');
    if (!empty($license_key)) {
        $domain = $_SERVER['HTTP_HOST'];
        // Override check: If main_domain is already known and this is a custom tenant domain, validate using main_domain
        if (!empty($main_domain)) {
            $clean_host = str_replace(['www.', ':80', ':443'], '', strtolower($domain));
            $clean_main = str_replace(['www.', ':80', ':443'], '', strtolower($main_domain));
            if ($clean_host !== $clean_main && !str_ends_with($clean_host, '.' . $clean_main) && $clean_host !== 'localhost' && $clean_host !== '127.0.0.1') {
                $domain = $main_domain;
            }
        }
        
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
                if (isset($pdo)) {
                    $stmtSave = $pdo->prepare("INSERT INTO sm_global_settings (setting_key, setting_value) VALUES ('license_key', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                    $stmtSave->execute([$license_key, $license_key]);
                    
                    // Save the domain used for activation as the main domain
                    $stmtSaveDomain = $pdo->prepare("INSERT INTO sm_global_settings (setting_key, setting_value) VALUES ('main_domain', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                    $stmtSaveDomain->execute([$domain, $domain]);
                }
                
                $cache_file = dirname(__DIR__) . '/config/.sys_cache';
                if (file_exists($cache_file)) {
                    @unlink($cache_file);
                }
                
                header("Location: " . $_SERVER['REQUEST_URI']);
                exit;
            } else {
                $setup_error = isset($result['message']) ? $result['message'] : 'Invalid license key or domain mismatch.';
            }
        } else {
            $setup_error = 'Could not contact the license verification server.';
        }
    } else {
        $setup_error = 'Please enter a valid license key.';
    }
}

if (empty($license_key)) {
    display_license_error("No license key configured. Please enter your license key to activate the script.", $setup_error ?? '');
}

$domain = $_SERVER['HTTP_HOST'];
if (!empty($main_domain)) {
    $clean_host = str_replace(['www.', ':80', ':443'], '', strtolower($domain));
    $clean_main = str_replace(['www.', ':80', ':443'], '', strtolower($main_domain));
    if ($clean_host !== $clean_main && !str_ends_with($clean_host, '.' . $clean_main) && $clean_host !== 'localhost' && $clean_host !== '127.0.0.1') {
        $domain = $main_domain;
    }
}

$cache_file = dirname(__DIR__) . '/config/.sys_cache';
$now = time();

if (file_exists($cache_file)) {
    $cache_data = json_decode(file_get_contents($cache_file), true);
    if (is_array($cache_data) && isset($cache_data['expiry']) && $cache_data['expiry'] > $now) {
        if ($cache_data['status'] === 'active' && $cache_data['key'] === $license_key && $cache_data['domain'] === $domain) {
            return true;
        }
    }
}

$api_url = 'https://manager.pmhserver.name.ng/api.php';

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $api_url);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'key' => $license_key,
    'domain' => $domain
]));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 8);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code === 200 && $response) {
    $result = json_decode($response, true);
    if (isset($result['status']) && $result['status'] == 1) {
        $cache_data = [
            'key' => $license_key,
            'domain' => $domain,
            'status' => 'active',
            'expiry' => $now + 86400
        ];
        file_put_contents($cache_file, json_encode($cache_data));
        
        // Auto-initialize main_domain in DB if missing and this is a successful validation run on the primary host
        if (empty($main_domain) && isset($pdo) && $domain === $_SERVER['HTTP_HOST']) {
            try {
                $stmtSaveDomain = $pdo->prepare("INSERT INTO sm_global_settings (setting_key, setting_value) VALUES ('main_domain', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmtSaveDomain->execute([$domain, $domain]);
            } catch (PDOException $e) {}
        }
        return true;
    } else {
        $msg = isset($result['message']) ? $result['message'] : 'Invalid license key or domain.';
        display_license_error($msg, $setup_error ?? '');
    }
} else {
    if (file_exists($cache_file)) {
        $cache_data = json_decode(file_get_contents($cache_file), true);
        if (is_array($cache_data)) {
            $cache_data['expiry'] = $now + 7200;
            file_put_contents($cache_file, json_encode($cache_data));
            return true;
        }
    }
    $cache_data = [
        'key' => $license_key,
        'domain' => $domain,
        'status' => 'active',
        'expiry' => $now + 3600
    ];
    file_put_contents($cache_file, json_encode($cache_data));
    return true;
}
CODE;

echo base64_encode($code);
?>

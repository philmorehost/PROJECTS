<?php
// check-update.php
// API endpoint for Over-The-Air (OTA) updates validation on central License Manager

header('Content-Type: application/json');

require_once('db.php');

// Helper to log actions
function check_update_log($message) {
    file_put_contents('api_check_update.log', date('[Y-m-d H:i:s] ') . $message . "\n", FILE_APPEND);
}

// 1. IP Whitelisting Check (for unlicensed internal platform)
$requester_ip = $_SERVER['REMOTE_ADDR'];
if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
    $requester_ip = trim($ips[0]);
}

$settings_file = 'settings.json';
$settings = [];
if (file_exists($settings_file)) {
    $settings = json_decode(file_get_contents($settings_file), true);
}

$whitelisted_ips = $settings['whitelisted_ips'] ?? [];
if (!is_array($whitelisted_ips)) {
    if (!empty($whitelisted_ips)) {
        $whitelisted_ips = array_map('trim', explode(',', $whitelisted_ips));
    } else {
        $whitelisted_ips = [];
    }
}

// Check if IP is in the whitelist
if (in_array($requester_ip, $whitelisted_ips)) {
    check_update_log("Bypassing validation: request from whitelisted internal IP: {$requester_ip}");
    
    // Serve the SAAS unlicensed update payload directly
    try {
        $force_version = trim($_REQUEST['force_version'] ?? '');
        if (!empty($force_version)) {
            // Filter by SAAS tier to prevent cross-tier contamination
            $stmt = $pdo->prepare("SELECT u.* FROM script_updates u JOIN script_tiers t ON u.tier_id = t.id WHERE u.version_number = ? AND t.tier_code = 'SAAS' LIMIT 1");
            $stmt->execute([$force_version]);
        } else {
            $stmt = $pdo->prepare("SELECT u.* FROM script_updates u JOIN script_tiers t ON u.tier_id = t.id WHERE t.tier_code = 'SAAS' AND u.is_released = 1 ORDER BY u.release_date DESC, u.id DESC LIMIT 1");
            $stmt->execute();
        }
        $latest_update = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$latest_update) {
            echo json_encode(['status' => 'error', 'message' => 'No matching update found.']);
            exit;
        }

        // Generate temporary secure download token for whitelisted bypass (license_id is NULL)
        $token = bin2hex(random_bytes(32));
        $expires_at = date('Y-m-d H:i:s', time() + 1800); // 30 mins
        
        $token_stmt = $pdo->prepare("INSERT INTO download_tokens (license_id, update_id, token, expires_at) VALUES (NULL, ?, ?, ?)");
        $token_stmt->execute([$latest_update['id'], $token, $expires_at]);

        // Build the download url
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $download_url = $protocol . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . '/download.php?token=' . $token;

        echo json_encode([
            'status' => 'success',
            'latest_version' => $latest_update['version_number'],
            'changelog' => $latest_update['changelog'],
            'download_url' => $download_url,
            'checksum' => $latest_update['checksum'],
            'tier_detected' => 'SAAS (Whitelisted Bypass)'
        ]);
        exit;
    } catch (PDOException $e) {
        check_update_log("Database error during whitelisted update fetch: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Database error occurred.']);
        exit;
    }
}

// 2. Vendor Request Process (Standard License flow)
// Support both GET and POST requests
$license_key = trim($_REQUEST['license_key'] ?? $_REQUEST['key'] ?? '');
$requesting_domain = trim($_REQUEST['domain'] ?? '');

if (empty($license_key) || empty($requesting_domain)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing license_key or domain parameter.']);
    exit;
}

// Remove any protocol prefixes from domain
$requesting_domain = preg_replace('#^https?://#', '', $requesting_domain);
$requesting_domain = rtrim($requesting_domain, '/');

check_update_log("Licensing Request: Key: {$license_key}, Domain: {$requesting_domain}");

try {
    // Query license details with tier
    $stmt = $pdo->prepare("SELECT l.id, l.status, l.max_domains, l.license_type, l.tier_id, t.tier_code FROM licenses l LEFT JOIN script_tiers t ON l.tier_id = t.id WHERE l.license_key = ?");
    $stmt->execute([$license_key]);
    $license = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$license) {
        check_update_log("Authentication failed: License key '{$license_key}' not found.");
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Invalid license key.']);
        exit;
    }

    if ($license['status'] !== 'active') {
        check_update_log("Authentication failed: License '{$license_key}' is status: '{$license['status']}'.");
        http_response_code(403);
        echo json_encode(['status' => 'suspended', 'message' => 'Your license is suspended or expired.']);
        exit;
    }

    // Determine script tier (fallback if tier_id is null)
    $tier_code = $license['tier_code'];
    $tier_id = $license['tier_id'];
    if (empty($tier_code)) {
        // Fallback mapping
        if ($license['license_type'] === 'extended') {
            $tier_code = 'SAAS';
            $tier_id = 1;
        } else {
            $tier_code = 'NON-SAAS';
            $tier_id = 2;
        }
        check_update_log("WARNING: License '{$license_key}' has no tier_id set. Fell back to tier '{$tier_code}' (tier_id={$tier_id}) based on license_type='{$license['license_type']}'.");
    } else {
        check_update_log("INFO: License '{$license_key}' resolved to tier '{$tier_code}' (tier_id={$tier_id}) from DB.");
    }

    // Check if domain is registered
    $domain_stmt = $pdo->prepare("SELECT id FROM licensed_domains WHERE license_id = ? AND domain_name = ?");
    $domain_stmt->execute([$license['id'], $requesting_domain]);
    $domain_record = $domain_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$domain_record) {
        // Domain not registered yet, check limits
        $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM licensed_domains WHERE license_id = ?");
        $count_stmt->execute([$license['id']]);
        $domain_count = $count_stmt->fetchColumn();

        $max_domains = intval($license['max_domains'] ?? 1);
        if ($domain_count < $max_domains) {
            // Register domain
            $register_stmt = $pdo->prepare("INSERT INTO licensed_domains (license_id, domain_name, server_ip) VALUES (?, ?, ?)");
            $register_stmt->execute([$license['id'], $requesting_domain, $requester_ip]);
            check_update_log("Registered domain '{$requesting_domain}' for license '{$license_key}'");
        } else {
            check_update_log("Limit exceeded: Domain '{$requesting_domain}' attempted register but limit is {$max_domains}.");
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'This license has reached the maximum number of registered domains.']);
            exit;
        }
    } else {
        // Update server IP and timestamp
        $update_ip_stmt = $pdo->prepare("UPDATE licensed_domains SET server_ip = ?, last_update_check = CURRENT_TIMESTAMP WHERE license_id = ? AND domain_name = ?");
        $update_ip_stmt->execute([$requester_ip, $license['id'], $requesting_domain]);
    }

    // Fetch the update package details (either specific version or latest released)
    $force_version = trim($_REQUEST['force_version'] ?? '');
    if (!empty($force_version)) {
        // Filter by this license's tier to prevent cross-tier version contamination
        $update_stmt = $pdo->prepare("SELECT * FROM script_updates WHERE version_number = ? AND tier_id = ? LIMIT 1");
        $update_stmt->execute([$force_version, $tier_id]);
    } else {
        $update_stmt = $pdo->prepare("SELECT * FROM script_updates WHERE tier_id = ? AND is_released = 1 ORDER BY release_date DESC, id DESC LIMIT 1");
        $update_stmt->execute([$tier_id]);
    }
    $latest_update = $update_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$latest_update) {
        echo json_encode(['status' => 'success', 'message' => 'No updates released for this tier.', 'latest_version' => '0.00']);
        exit;
    }

    // Generate secure download token (one-time use, 30 min expiry)
    $token = bin2hex(random_bytes(32));
    $expires_at = date('Y-m-d H:i:s', time() + 1800); // 30 mins
    
    $token_stmt = $pdo->prepare("INSERT INTO download_tokens (license_id, update_id, token, expires_at) VALUES (?, ?, ?, ?)");
    $token_stmt->execute([$license['id'], $latest_update['id'], $token, $expires_at]);

    // Build secure download URL
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $download_url = $protocol . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . '/download.php?token=' . $token;

    check_update_log("Issued update info for domain '{$requesting_domain}' (Version: {$latest_update['version_number']})");

    echo json_encode([
        'status' => 'success',
        'latest_version' => $latest_update['version_number'],
        'checksum' => $latest_update['checksum'],
        'changelog' => $latest_update['changelog'],
        'download_url' => $download_url,
        'tier_detected' => $tier_code
    ]);
    exit;

} catch (PDOException $e) {
    check_update_log("Database error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Internal database error.']);
    exit;
}

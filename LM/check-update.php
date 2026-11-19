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

// Optional project/tier scoping parameters (omitted by legacy DGV7.0 clients; sent by multi-project clients like SMSP)
$expected_tier = strtoupper(trim($_REQUEST['expected_tier'] ?? ''));
$project_family = strtoupper(trim($_REQUEST['project_family'] ?? ''));

// Edition declared by the client itself. DGV7.0 clients send `edition` (SAAS /
// NON-SAAS) with every check: the running code knows which edition it is, and a
// SAAS install must never be handed the NON-SAAS package because its license
// record was created without a tier. Feeding it into $expected_tier makes the
// existing mismatch guard (and the whitelist tier pick) honour it.
if (!function_exists('lm_check_edition_tier')) {
    function lm_check_edition_tier($raw) {
        $e = strtoupper(trim((string) $raw));
        if ($e === '') return '';
        $e = str_replace(array('_', ' '), '-', $e);
        if (strpos($e, 'NON') !== false || strpos($e, 'STANDALONE') !== false || strpos($e, 'STANDARD') !== false) return 'NON-SAAS';
        if (strpos($e, 'SAAS') !== false) return 'SAAS';
        return '';
    }
}
$client_edition = lm_check_edition_tier($_REQUEST['edition'] ?? '');
if ($expected_tier === '' && $client_edition !== '') {
    $expected_tier = $client_edition;
}

// Check if IP is in the whitelist
if (in_array($requester_ip, $whitelisted_ips)) {
    $whitelist_tier = !empty($expected_tier) ? $expected_tier : (!empty($project_family) ? ($project_family . '-SAAS') : 'SAAS');
    check_update_log("Bypassing validation: request from whitelisted internal IP: {$requester_ip} (target tier: {$whitelist_tier})");
    
    // Serve the target tier's unlicensed update payload directly (defaults to SAAS for DGV7.0 backward compatibility)
    try {
        $force_version = trim($_REQUEST['force_version'] ?? '');
        if (!empty($force_version)) {
            // Filter by target tier to prevent cross-tier contamination
            $stmt = $pdo->prepare("SELECT u.*, t.tier_code FROM script_updates u JOIN script_tiers t ON u.tier_id = t.id WHERE u.version_number = ? AND UPPER(t.tier_code) = ? LIMIT 1");
            $stmt->execute([$force_version, $whitelist_tier]);
        } else {
            $stmt = $pdo->prepare("SELECT u.*, t.tier_code FROM script_updates u JOIN script_tiers t ON u.tier_id = t.id WHERE UPPER(t.tier_code) = ? AND u.is_released = 1 ORDER BY u.release_date DESC, u.id DESC LIMIT 1");
            $stmt->execute([$whitelist_tier]);
        }
        $latest_update = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$latest_update) {
            echo json_encode(['status' => 'success', 'message' => "No updates released yet for tier {$whitelist_tier}.", 'latest_version' => '0.00', 'tier_detected' => $whitelist_tier]);
            exit;
        }

        // Generate temporary secure download token for whitelisted bypass (license_id is NULL)
        $token = bin2hex(random_bytes(32));
        $expires_at = date('Y-m-d H:i:s', time() + 1800); // 30 mins
        
        $token_stmt = $pdo->prepare("INSERT INTO download_tokens (license_id, update_id, token, expires_at) VALUES (NULL, ?, ?, ?)");
        $token_stmt->execute([$latest_update['id'], $token, $expires_at]);

        // Build the download url
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $base_dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
        $download_url = $protocol . $_SERVER['HTTP_HOST'] . $base_dir . '/download.php?token=' . $token;

        $client_ver = trim($_REQUEST['current_version'] ?? '');
        $is_newer = ($client_ver !== '' && $latest_update['version_number'] !== '0.00')
            ? version_compare(ltrim($latest_update['version_number'], 'vV'), ltrim($client_ver, 'vV'), '>')
            : true;

        echo json_encode([
            'status' => 'success',
            'update_available' => $is_newer,
            'latest_version' => $latest_update['version_number'],
            'release_date' => $latest_update['release_date'] ?? '',
            'changelog' => $latest_update['changelog'],
            'download_url' => $download_url,
            'checksum' => $latest_update['checksum'],
            'tier_detected' => $latest_update['tier_code'],
            'edition' => $latest_update['tier_code']
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

check_update_log("Licensing Request: Key: {$license_key}, Domain: {$requesting_domain}" . ($client_edition !== '' ? ", Edition: {$client_edition}" : ''));

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
        // Fallback mapping for legacy DGV7.0 licenses with NULL tier_id. The
        // edition the client declares wins over the license_type guess, tier ids
        // are resolved by tier_code (never assumed to be 1/2), and the record is
        // healed so later checks resolve the tier straight from the database.
        // license_type is the pre-tier edition record ('extended' = SAAS,
        // 'standard' = NON-SAAS), so it wins on conflict - a standard license
        // must not pull SAAS packages just because the client asks. The
        // client-declared edition decides only when license_type carries no
        // edition (the usual NULL-tier case).
        $type_edition = ($license['license_type'] === 'extended') ? 'SAAS'
            : (($license['license_type'] === 'standard') ? 'NON-SAAS' : '');
        if ($client_edition !== '' && $type_edition !== '' && $client_edition !== $type_edition) {
            check_update_log("WARNING: License '{$license_key}' declares edition '{$client_edition}' but license_type '{$license['license_type']}' maps to '{$type_edition}'. Serving '{$type_edition}'; fix the license tier if the client is right.");
        }
        $tier_code = ($type_edition !== '') ? $type_edition
            : ($client_edition !== '' ? $client_edition : 'NON-SAAS');
        $tier_id = ($tier_code === 'SAAS') ? 1 : 2;
        try {
            $tier_stmt = $pdo->prepare("SELECT id FROM script_tiers WHERE UPPER(tier_code) = ? LIMIT 1");
            $tier_stmt->execute([$tier_code]);
            $found_tier_id = $tier_stmt->fetchColumn();
            if ($found_tier_id) {
                $tier_id = (int) $found_tier_id;
                $pdo->prepare("UPDATE licenses SET tier_id = ? WHERE id = ? AND tier_id IS NULL")
                    ->execute([$tier_id, $license['id']]);
            }
        } catch (PDOException $e) {
            check_update_log("WARNING: could not resolve/heal tier for license '{$license_key}': " . $e->getMessage());
        }
        check_update_log("WARNING: License '{$license_key}' has no tier_id set. Fell back to tier '{$tier_code}' (tier_id={$tier_id}) based on " . ($type_edition !== '' ? "license_type='{$license['license_type']}'" : 'client-declared edition') . ".");
    } else {
        check_update_log("INFO: License '{$license_key}' resolved to tier '{$tier_code}' (tier_id={$tier_id}) from DB.");
    }

    // Cross-project contamination guard (only enforced when client specifies project_family or expected_tier)
    $resolved_upper = strtoupper((string)$tier_code);
    if (!empty($project_family)) {
        $matches_family = ($resolved_upper === $project_family || str_starts_with($resolved_upper, $project_family . '-') || str_starts_with($resolved_upper, $project_family . '_'));
        if (!$matches_family) {
            check_update_log("REJECTED: License '{$license_key}' belongs to tier '{$tier_code}', but client requested project_family '{$project_family}'.");
            http_response_code(403);
            echo json_encode([
                'status' => 'error',
                'message' => "License project mismatch: this license belongs to '{$tier_code}' and cannot pull updates for '{$project_family}'."
            ]);
            exit;
        }
    } elseif (!empty($expected_tier) && $resolved_upper !== $expected_tier) {
        check_update_log("REJECTED: License '{$license_key}' belongs to tier '{$tier_code}', but client expected '{$expected_tier}'.");
        http_response_code(403);
        echo json_encode([
            'status' => 'error',
            'message' => "License tier mismatch: this license is assigned to '{$tier_code}', not '{$expected_tier}'."
        ]);
        exit;
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
    $base_dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    $download_url = $protocol . $_SERVER['HTTP_HOST'] . $base_dir . '/download.php?token=' . $token;

    check_update_log("Issued update info for domain '{$requesting_domain}' (Version: {$latest_update['version_number']})");

    $client_ver = trim($_REQUEST['current_version'] ?? '');
    $is_newer = ($client_ver !== '' && $latest_update['version_number'] !== '0.00')
        ? version_compare(ltrim($latest_update['version_number'], 'vV'), ltrim($client_ver, 'vV'), '>')
        : true;

    echo json_encode([
        'status' => 'success',
        'update_available' => $is_newer,
        'latest_version' => $latest_update['version_number'],
        'release_date' => $latest_update['release_date'] ?? '',
        'checksum' => $latest_update['checksum'],
        'changelog' => $latest_update['changelog'],
        'download_url' => $download_url,
        'tier_detected' => $tier_code,
        'edition' => $tier_code
    ]);
    exit;

} catch (PDOException $e) {
    check_update_log("Database error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Internal database error.']);
    exit;
}

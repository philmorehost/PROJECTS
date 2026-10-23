<?php
require_once __DIR__ . '/includes/security.php';
lm_security_headers();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once('db.php');

/**
 * Log without writing licence keys to disk.
 *
 * The old helper appended every key to api_debug.log inside the web root;
 * any misconfigured server that served .log files leaked the whole licence
 * table. Keys are now shown as a short prefix only.
 *
 * @param string $message
 * @return void
 */
function api_log($message) {
    lm_log('api', preg_replace('/\b([A-Z]{2,10}-)[A-Z0-9]{6,}\b/', '$1…', (string) $message));
}

/**
 * A licence key as it may appear in a log line.
 *
 * api_log() masks the generated VALID-… shape, but the key here comes from the
 * request body: an attacker can send anything, and older/imported keys are not
 * guaranteed to match the pattern. Masking the value itself is format-agnostic.
 *
 * @param mixed $key
 * @return string
 */
function api_key_label($key) {
    $key = (string) $key;
    if ($key === '') {
        return '(empty)';
    }
    if (strlen($key) <= 8) {
        return str_repeat('*', strlen($key));
    }
    return substr($key, 0, 4) . str_repeat('*', max(4, strlen($key) - 8)) . substr($key, -4);
}

api_log('--- New API Request ---');

// A licence check is cheap, but unlimited requests are not: cap per address.
$apiLimit = lm_rate_limit(lm_rate_limit_ip('api-check'), 120, 60, true);
if (!$apiLimit['allowed']) {
    http_response_code(429);
    header('Retry-After: ' . (int) $apiLimit['retry_after']);
    echo json_encode(array('status' => 0, 'message' => 'Too many requests. Try again shortly.'));
    exit;
}

$response = ['status' => 0, 'message' => 'Invalid license key or domain.'];

if (isset($_POST['key']) && isset($_POST['domain'])) {
    $key = $_POST['key'];
    $domain = $_POST['domain'];
    $key_label = api_key_label($key);
    api_log('Received key for domain ' . preg_replace('/[^a-z0-9.\-]/i', '', (string) $domain));

    try {
        $stmt = $pdo->prepare("SELECT * FROM licenses WHERE license_key = ?");
        $stmt->execute([$key]);
        $license = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($license) {
            if ($license['status'] === 'suspended') {
                $response['status'] = 'suspended';
                $response['message'] = 'This license has been suspended by the administrator.';
                api_log("FAILURE: License key '{$key_label}' is suspended.");
            } elseif ($license['status'] !== 'active') {
                $response['status'] = 0;
                $response['message'] = 'This license is inactive.';
                api_log("FAILURE: License key '{$key_label}' is inactive. Status: {$license['status']}");
            } else {
                $license_type = $license['license_type'] ?? 'standard';
                // Check domains in licensed_domains normalized table
                $domain_clean = preg_replace('/^www\./', '', $domain);
                $domain_stmt = $pdo->prepare("SELECT id FROM licensed_domains WHERE license_id = ? AND (domain_name = ? OR domain_name = ? OR domain_name = ?)");
                $domain_stmt->execute([$license['id'], $domain, $domain_clean, "www." . $domain_clean]);
                $domain_exists = $domain_stmt->fetch();
                
                if ($license_type === 'extended' || $domain_exists || ($license['domain'] === $domain || preg_replace('/^www\./', '', $license['domain']) === $domain_clean)) {
                    $response['status'] = 1;
                    $response['message'] = 'License is valid.';
                    api_log("SUCCESS: Found matching active license (Type: {$license_type}) for domain '{$domain}'.");
                } else {
                    // Check if we can auto-register under limit
                    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM licensed_domains WHERE license_id = ?");
                    $count_stmt->execute([$license['id']]);
                    $domain_count = $count_stmt->fetchColumn();
                    $max_domains = intval($license['max_domains'] ?? 1);
                    
                    if ($domain_count < $max_domains) {
                        $register_stmt = $pdo->prepare("INSERT INTO licensed_domains (license_id, domain_name) VALUES (?, ?)");
                        $register_stmt->execute([$license['id'], $domain]);
                        $response['status'] = 1;
                        $response['message'] = 'License is valid (Domain registered).';
                        api_log("SUCCESS: Registered and validated domain '{$domain}' for key '{$key_label}'.");
                    } else {
                        $response['status'] = 0;
                        $response['message'] = 'Domain not authorized. Limit exceeded.';
                        api_log("FAILURE: Domain '{$domain}' not authorized for key '{$key_label}'. Limit is {$max_domains}.");
                    }
                }
            }
        } else {
            api_log("FAILURE: License key '{$key_label}' does not exist.");
        }
    } catch (PDOException $e) {
        $response['message'] = 'A database error occurred.';
        api_log("DATABASE ERROR: " . $e->getMessage());
    }
} else {
    $response['message'] = 'Missing key or domain parameter.';
    api_log("ERROR: Missing key or domain in POST request. Fields present: "
        . implode(',', array_map('strval', array_keys($_POST))));
}

api_log("Responding with: " . json_encode($response));
echo json_encode($response);
?>


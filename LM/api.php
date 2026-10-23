<?php
header('Content-Type: application/json');

require_once('db.php');

// Function to log debug messages
function api_log($message) {
    file_put_contents('api_debug.log', date('[Y-m-d H:i:s] ') . $message . "\n", FILE_APPEND);
}

api_log("--- New API Request ---");

$response = ['status' => 0, 'message' => 'Invalid license key or domain.'];

if (isset($_POST['key']) && isset($_POST['domain'])) {
    $key = $_POST['key'];
    $domain = $_POST['domain'];
    api_log("Received key: {$key}, domain: {$domain}");

    try {
        $stmt = $pdo->prepare("SELECT * FROM licenses WHERE license_key = ?");
        $stmt->execute([$key]);
        $license = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($license) {
            if ($license['status'] === 'suspended') {
                $response['status'] = 'suspended';
                $response['message'] = 'This license has been suspended by the administrator.';
                api_log("FAILURE: License key '{$key}' is suspended.");
            } elseif ($license['status'] !== 'active') {
                $response['status'] = 0;
                $response['message'] = 'This license is inactive.';
                api_log("FAILURE: License key '{$key}' is inactive. Status: {$license['status']}");
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
                        api_log("SUCCESS: Registered and validated domain '{$domain}' for key '{$key}'.");
                    } else {
                        $response['status'] = 0;
                        $response['message'] = 'Domain not authorized. Limit exceeded.';
                        api_log("FAILURE: Domain '{$domain}' not authorized for key '{$key}'. Limit is {$max_domains}.");
                    }
                }
            }
        } else {
            api_log("FAILURE: License key '{$key}' does not exist.");
        }
    } catch (PDOException $e) {
        $response['message'] = 'A database error occurred.';
        api_log("DATABASE ERROR: " . $e->getMessage());
    }
} else {
    $response['message'] = 'Missing key or domain parameter.';
    api_log("ERROR: Missing key or domain in POST request. Data: " . json_encode($_POST));
}

api_log("Responding with: " . json_encode($response));
echo json_encode($response);
?>


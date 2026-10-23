<?php
/**
 * Verify payment directly with Paystack API
 * This endpoint checks Paystack to confirm payment and creates license if confirmed
 */
header('Content-Type: application/json');

require_once('db.php');

$ref = $_GET['ref'] ?? '';

if (empty($ref)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No transaction reference provided']);
    exit;
}

$settings_file = 'settings.json';
if (!file_exists($settings_file)) {
    echo json_encode(['success' => false, 'message' => 'Configuration error']);
    exit;
}

$settings = json_decode(file_get_contents($settings_file), true);
$paystack_secret_key = $settings['paystack_secret_key'] ?? '';

if (empty($paystack_secret_key)) {
    echo json_encode(['success' => false, 'message' => 'Paystack not configured']);
    exit;
}

// Helper for auto-login token generation and response building
function buildLicenseResponse(PDO $pdo, array $transaction): array {
    $licenseStmt = $pdo->prepare("SELECT license_key, customer_email FROM licenses WHERE id = ?");
    $licenseStmt->execute([$transaction['license_id']]);
    $license = $licenseStmt->fetch(PDO::FETCH_ASSOC);

    if (!$license) {
        return ['success' => false, 'message' => 'License record missing'];
    }

    $autoLoginToken = $transaction['auto_login_token'] ?? null;
    $tokenCreated = $transaction['token_created_at'] ?? null;
    if (!$autoLoginToken || !$tokenCreated || (time() - strtotime($tokenCreated) > 600)) {
        $autoLoginToken = bin2hex(random_bytes(16));
        $tokenCreated = date('Y-m-d H:i:s');
        $updateStmt = $pdo->prepare("UPDATE transactions SET auto_login_token = ?, token_created_at = ? WHERE id = ?");
        $updateStmt->execute([$autoLoginToken, $tokenCreated, $transaction['id']]);
    }

    return [
        'success' => true,
        'license_key' => $license['license_key'],
        'customer_email' => $license['customer_email'] ?? '',
        'auto_login_token' => $autoLoginToken,
        'message' => 'License found'
    ];
}

// First check if license already exists
try {
    $stmt = $pdo->prepare("SELECT * FROM transactions WHERE transaction_ref = ?");
    $stmt->execute([$ref]);
    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($transaction && !empty($transaction['license_id'])) {
        $response = buildLicenseResponse($pdo, $transaction);
        echo json_encode($response);
        exit;
    }
} catch (PDOException $e) {
    error_log("Error checking transaction: " . $e->getMessage());
}

// Verify with Paystack API
$curl = curl_init();

curl_setopt_array($curl, array(
    CURLOPT_URL => "https://api.paystack.co/transaction/verify/" . urlencode($ref),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_HTTPHEADER => array(
        "Authorization: Bearer " . $paystack_secret_key,
        "Content-Type: application/json",
    ),
));

$response = curl_exec($curl);
$curl_error = curl_error($curl);
$http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

error_log("Paystack API call for ref: {$ref}, HTTP Code: {$http_code}, cURL Error: {$curl_error}");

if ($curl_error) {
    error_log("cURL error details: " . $curl_error);
    echo json_encode(['success' => false, 'message' => 'Could not verify payment (connection error)']);
    exit;
}

if (empty($response)) {
    error_log("Empty response from Paystack API");
    echo json_encode(['success' => false, 'message' => 'Could not verify payment (no response)']);
    exit;
}

$response_data = json_decode($response, true);

if (!$response_data) {
    error_log("Invalid JSON response from Paystack: " . substr($response, 0, 200));
    echo json_encode(['success' => false, 'message' => 'Could not parse payment response']);
    exit;
}

if (!isset($response_data['status'])) {
    error_log("No status in Paystack response: " . json_encode($response_data));
    echo json_encode(['success' => false, 'message' => 'Invalid payment response']);
    exit;
}

if (!$response_data['status']) {
    // Request failed
    $error_message = $response_data['message'] ?? 'Payment verification failed';
    error_log("Paystack verification failed: " . $error_message);
    echo json_encode(['success' => false, 'message' => 'Payment not confirmed yet']);
    exit;
}

if ($response_data['data']['status'] !== 'success') {
    error_log("Payment status not success: " . $response_data['data']['status']);
    echo json_encode(['success' => false, 'message' => 'Payment not confirmed yet']);
    exit;
}

// Payment confirmed! Now create license if not already created
try {
    $payment_data = $response_data['data'];
    $customer_email = $payment_data['customer']['email'] ?? '';
    $domain = $payment_data['metadata']['domain'] ?? 'N/A';
    $amount = $payment_data['amount'] / 100;
    $currency = $payment_data['currency'];
    
    // Check if transaction already processed
    $stmt = $pdo->prepare("SELECT * FROM transactions WHERE transaction_ref = ?");
    $stmt->execute([$ref]);
    $trans = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($trans && !empty($trans['license_id'])) {
        echo json_encode(buildLicenseResponse($pdo, $trans));
        exit;
    }
    
    // Create new license
    $pdo->beginTransaction();
    
    $new_license_key = 'VALID-' . strtoupper(bin2hex(random_bytes(8)));
    $max_domain_changes = $settings['max_domain_changes'] ?? 3;

    $licenseInsert = getLicenseInsertDefinition($pdo);
    $licenseParams = $licenseInsert['params']($new_license_key, $domain, $customer_email, 'active', $max_domain_changes);
    $stmt = $pdo->prepare($licenseInsert['sql']);
    $stmt->execute($licenseParams);
    $license_id = $pdo->lastInsertId();
    
    // Create or update transaction record
    // Generate a one-time auto-login token (expires shortly)
    $auto_login_token = bin2hex(random_bytes(16));
    $token_created_at = date('Y-m-d H:i:s');

    if ($trans) {
        $stmt = $pdo->prepare("UPDATE transactions SET license_id = ?, amount = ?, currency = ?, status = ?, auto_login_token = ?, token_created_at = ? WHERE id = ?");
        $stmt->execute([$license_id, $amount, $currency, 'success', $auto_login_token, $token_created_at, $trans['id']]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO transactions (license_id, transaction_ref, amount, currency, status, auto_login_token, token_created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$license_id, $ref, $amount, $currency, 'success', $auto_login_token, $token_created_at]);
    }
    
    $pdo->commit();
    error_log("License created successfully: {$new_license_key} for {$customer_email}");
    
    // Send email notification
    send_license_email($customer_email, $new_license_key, $domain, $settings);
    
    echo json_encode([
        'success' => true,
        'license_key' => $new_license_key,
        'customer_email' => $customer_email,
        'auto_login_token' => $auto_login_token,
        'message' => 'License created and email sent'
    ]);
    
} catch (Exception $e) {
    if (isset($pdo)) {
        $pdo->rollBack();
    }
    error_log("Error creating license: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    echo json_encode(['success' => false, 'message' => 'Error processing payment: ' . $e->getMessage()]);
}

function send_license_email($email, $license_key, $domain, $settings) {
    try {
        require 'includes/src/Exception.php';
        require 'includes/src/PHPMailer.php';
        require 'includes/src/SMTP.php';
        
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        $mail->isSMTP();
        $mail->Host = $settings['smtp_host'] ?? 'smtp.example.com';
        $mail->SMTPAuth = true;
        $mail->Username = $settings['smtp_user'] ?? 'user@example.com';
        $mail->Password = $settings['smtp_pass'] ?? 'password';
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $settings['smtp_port'] ?? 587;
        
        $mail->setFrom($settings['admin_email'] ?? 'from@example.com', $settings['site_name'] ?? 'License Manager');
        $mail->addAddress($email);
        $mail->addBCC($settings['admin_email'] ?? 'admin@example.com');
        
        $mail->isHTML(true);
        $mail->Subject = 'Your License Key - ' . ($settings['site_name'] ?? 'License Manager');
        
        $template = file_get_contents('email_template.html');
        if (!$template) {
            $template = get_default_email_template();
        }
        
        $body = str_replace(
            ['{license_key}', '{domain}', '{site_name}'],
            [$license_key, $domain, $settings['site_name'] ?? 'License Manager'],
            $template
        );
        
        $mail->Body = $body;
        $mail->AltBody = "Thank you for your purchase!\n\nYour License Key: {$license_key}\nDomain: {$domain}";
        
        $mail->send();
        error_log("License email sent to: " . $email);
        
    } catch (Exception $e) {
        error_log("Failed to send license email: " . $e->getMessage());
    }
}

function get_default_email_template() {
    return <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 8px 8px 0 0; text-align: center; }
        .content { background: #f9fafb; padding: 20px; border-radius: 0 0 8px 8px; border: 1px solid #e5e7eb; }
        .license-box { background: white; border: 2px dashed #d1d5db; padding: 15px; border-radius: 8px; margin: 20px 0; text-align: center; }
        .license-key { font-size: 18px; font-weight: bold; font-family: monospace; color: #3b82f6; }
        .footer { margin-top: 20px; padding-top: 20px; border-top: 1px solid #e5e7eb; font-size: 12px; color: #94a3b8; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>{site_name}</h1>
            <p>Thank You for Your Purchase!</p>
        </div>
        <div class="content">
            <p>Hello,</p>
            <p>Your license has been successfully created and is ready to use.</p>
            
            <div class="license-box">
                <p style="margin: 0; color: #666; font-size: 12px;">YOUR LICENSE KEY</p>
                <div class="license-key">{license_key}</div>
                <p style="margin: 5px 0 0 0; color: #666; font-size: 12px;">Domain: {domain}</p>
            </div>
            
            <h3>Installation Instructions:</h3>
            <ol>
                <li>Download the main script and upload it to your server</li>
                <li>Navigate to setup.php in your browser</li>
                <li>Enter your license key and domain when prompted</li>
                <li>Follow the on-screen instructions to complete setup</li>
            </ol>
            
            <p><strong>Keep your license key safe!</strong> You'll need it for installation and support requests.</p>
            
            <p>If you have any questions, please don't hesitate to contact us.</p>
            
            <div class="footer">
                <p>Thank you for choosing {site_name}!</p>
                <p>© 2026 {site_name}. All rights reserved.</p>
            </div>
        </div>
    </div>
</body>
</html>
HTML;
}

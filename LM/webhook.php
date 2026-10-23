<?php
// Paystack webhook handler

// Get the POST data.
$event = file_get_contents('php://input');

// Load settings to get the secret key
$settings_file = 'settings.json';
$settings = [];
if (file_exists($settings_file)) {
    $settings = json_decode(file_get_contents($settings_file), true);
}

// Log the webhook initiation
$log_file = 'webhook.log';
$error_log_file = 'error.log';

function log_message($message) {
    global $log_file;
    file_put_contents($log_file, date('[Y-m-d H:i:s] ') . $message . "\n", FILE_APPEND);
}

log_message("=== Webhook received ===");
log_message("Raw event data: " . substr($event, 0, 200));

// For security, you should verify the webhook signature.
    $paystack_secret_key = $settings['paystack_secret_key'] ?? '';
    $signature = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';

log_message("Signature present: " . ($signature ? 'yes' : 'no'));
log_message("Secret key set: " . ($paystack_secret_key ? 'yes' : 'no'));

if (!$signature || !$paystack_secret_key) {
    log_message("ERROR: Webhook missing signature or secret key. Rejecting request.");
    http_response_code(401);
    echo 'Unauthorized';
    exit();
}

if (!hash_equals(hash_hmac('sha512', $event, $paystack_secret_key), $signature)) {
    log_message("ERROR: Webhook signature verification failed");
    http_response_code(401);
    echo 'Unauthorized';
    exit();
}

log_message("Webhook signature verified successfully");

$event_data = json_decode($event, true);

log_message("Event type: " . ($event_data['event'] ?? 'unknown'));

if ($event_data['event'] === 'charge.success') {
    log_message("Charge success event received.");
    $customer_email = $event_data['data']['customer']['email'];
    $domain = $event_data['data']['metadata']['domain'] ?? 'N/A';

    // Generate a new license key
    $new_license_key = 'VALID-' . strtoupper(bin2hex(random_bytes(8)));

    // --- Database interaction ---
    log_message("Attempting database interaction.");
    require_once('db.php');

    try {
        $pdo->beginTransaction();

        // Insert license
        log_message("Inserting license...");
        $max_domain_changes = $settings['max_domain_changes'] ?? 3;
        $licenseInsert = getLicenseInsertDefinition($pdo);
        $licenseParams = $licenseInsert['params']($new_license_key, $domain, $customer_email, 'active', $max_domain_changes);
        $stmt = $pdo->prepare($licenseInsert['sql']);
        $stmt->execute($licenseParams);
        $license_id = $pdo->lastInsertId();
        log_message("License inserted with ID: {$license_id}");

        // Insert transaction with license_id and a one-time auto-login token
        log_message("Inserting transaction for license_id {$license_id}...");
        $auto_login_token = bin2hex(random_bytes(16));
        $token_created_at = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare("INSERT INTO transactions (license_id, transaction_ref, amount, currency, status, auto_login_token, token_created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $result = $stmt->execute([$license_id, $event_data['data']['reference'], $event_data['data']['amount'] / 100, $event_data['data']['currency'], 'success', $auto_login_token, $token_created_at]);
        
        if (!$result) {
            throw new PDOException("Failed to insert transaction record");
        }
        $transaction_id = $pdo->lastInsertId();
        log_message("Transaction inserted with ID: {$transaction_id}");

        $pdo->commit();
        log_message("Database transaction committed successfully.");
        
        // Send email notification
        send_webhook_license_email($customer_email, $new_license_key, $domain, $settings);
        log_message("Auto-login token generated: {$auto_login_token}");
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error_msg = "Database error: " . $e->getMessage();
        log_message($error_msg);
        file_put_contents($error_log_file, $error_msg . "\n", FILE_APPEND);
        http_response_code(500);
        exit();
    }
}

function send_webhook_license_email($email, $license_key, $domain, $settings) {
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
        
        $template_file = 'email_template.html';
        if (file_exists($template_file)) {
            $template = file_get_contents($template_file);
        } else {
            $template = get_webhook_default_email_template();
        }
        
        $body = str_replace(
            ['{license_key}', '{domain}', '{site_name}'],
            [$license_key, $domain, $settings['site_name'] ?? 'License Manager'],
            $template
        );
        
        $mail->Body = $body;
        $mail->AltBody = "Thank you for your purchase!\n\nYour License Key: {$license_key}\nDomain: {$domain}";
        
        $mail->send();
        log_message("License email sent to: " . $email);
        
    } catch (Exception $e) {
        log_message("Failed to send license email: " . $e->getMessage());
    }
}

function get_webhook_default_email_template() {
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

http_response_code(200);
?>

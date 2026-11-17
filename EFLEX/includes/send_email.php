<?php
// Include PHPMailer classes
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/phpmailer/src/Exception.php';
require_once __DIR__ . '/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/phpmailer/src/SMTP.php';

require_once __DIR__ . '/email_template.php';

function send_email($to, $subject, $body_content) {
    // We need the database connection to fetch settings
    global $mysqli;

    // Fetch all site settings from the database for branding and SMTP
    $settings_sql = "SELECT setting_key, setting_value FROM settings";
    $result = $mysqli->query($settings_sql);
    $settings = [];
    while($row = $result->fetch_assoc()){
        $settings[$row['setting_key']] = $row['setting_value'];
    }

    // Check if essential SMTP settings are present
    $smtp_configured = !empty($settings['smtp_host']) && !empty($settings['from_email']);

    // Generate the full HTML email using the modern template
    $html_body = get_modern_email_html($subject, $body_content, $settings);

    if ($smtp_configured) {
        $mail = new PHPMailer(true);

        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host       = $settings['smtp_host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $settings['smtp_user'] ?? '';
            $mail->Password   = $settings['smtp_pass'] ?? '';
            $mail->SMTPSecure = ($settings['smtp_encryption'] ?? 'tls') === 'none' ? '' : ($settings['smtp_encryption'] ?? 'tls');
            $mail->Port       = $settings['smtp_port'] ?? 587;

            // Recipients
            $mail->setFrom($settings['from_email'], $settings['from_name'] ?? 'Eflex');
            $mail->addAddress($to);

            // Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $html_body;
            $mail->AltBody = strip_tags($body_content);

            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("SMTP Message could not be sent. Mailer Error: {$mail->ErrorInfo}");
            // Fall back to PHP mail() if SMTP fails
        }
    }

    // Fallback to PHP mail() when SMTP is not configured or SMTP failed
    $headers = "From: " . ($settings['from_name'] ?? 'Eflex') . " <" . ($settings['from_email'] ?? 'noreply@eflex.com') . ">\r\n";
    $headers .= "Reply-To: " . ($settings['from_email'] ?? 'noreply@eflex.com') . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";

    $sent = mail($to, $subject, $html_body, $headers);
    if ($sent) {
        return true;
    }

    error_log("PHP mail() fallback also failed for $to");
    return false;
}
?>

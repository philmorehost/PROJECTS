<?php
require_once('../db.php');

// Security check (prevent abuse)
// This script is called via AJAX, we don't strictly require session for public processing of queue,
// but let's restrict it slightly or just let it run quickly and exit if nothing to do.

try {
    $stmt = $pdo->prepare("SELECT * FROM email_queue WHERE status = 'pending' ORDER BY id ASC LIMIT 10");
    $stmt->execute();
    $emails = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($emails)) {
        echo json_encode(['status' => 'success', 'message' => 'No pending emails']);
        exit();
    }

    $settings = json_decode(file_get_contents('../settings.json'), true);
    require '../includes/src/Exception.php';
    require '../includes/src/PHPMailer.php';
    require '../includes/src/SMTP.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $settings['smtp_host'] ?? 'smtp.example.com';
    $mail->SMTPAuth = true;
    $mail->Username = $settings['smtp_user'] ?? 'user@example.com';
    $mail->Password = $settings['smtp_pass'] ?? 'password';
    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = $settings['smtp_port'] ?? 587;
    $mail->setFrom($settings['admin_email'] ?? 'from@example.com', $settings['site_name'] ?? 'License Manager');
    $mail->isHTML(true);

    foreach ($emails as $email_job) {
        try {
            $mail->clearAddresses();
            $mail->addAddress($email_job['recipient_email']);
            $mail->Subject = $email_job['subject'];
            $mail->Body = $email_job['body'];
            
            $mail->send();

            $u = $pdo->prepare("UPDATE email_queue SET status = 'sent' WHERE id = ?");
            $u->execute([$email_job['id']]);
        } catch (Exception $e) {
            $u = $pdo->prepare("UPDATE email_queue SET status = 'failed' WHERE id = ?");
            $u->execute([$email_job['id']]);
            error_log('Failed to send queued email ID ' . $email_job['id'] . ': ' . $e->getMessage());
        }
    }

    echo json_encode(['status' => 'success', 'message' => 'Processed ' . count($emails) . ' emails']);
} catch (Exception $e) {
    error_log('Error processing email queue: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

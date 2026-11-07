<?php
// This script should be run daily via a cron job.

if (PHP_SAPI !== 'cli') {
    die("Direct access forbidden. This script must be run from the command line.");
}

include_once(__DIR__ . "/../func/bc-connect.php");
include_once(__DIR__ . "/../func/bc-tables.php");

// Set timezone to avoid date mismatches
date_default_timezone_set('Africa/Lagos');

// --- Main Logic ---
$today = date('Y-m-d');
$reminder_date = date('Y-m-d', strtotime('+7 days'));

// Find vendors whose subscription expires in 7 days and who haven't been notified
$sql = "
    SELECT id, email, firstname, lastname, expiry_date
    FROM sas_vendors 
    WHERE expiry_date = ? 
    AND expiry_notified = 0 
    AND status = 1
";

$stmt = mysqli_prepare($connection_server, $sql);
mysqli_stmt_bind_param($stmt, "s", $reminder_date);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if ($result && mysqli_num_rows($result) > 0) {
    echo "Found " . mysqli_num_rows($result) . " vendors to notify.\n";

    while ($vendor = mysqli_fetch_assoc($result)) {
        $vendor_id = $vendor['id'];
        $email = $vendor['email'];
        $firstname = $vendor['firstname'];
        $lastname = $vendor['lastname'];
        $expiry_date_formatted = date('F j, Y', strtotime($vendor['expiry_date']));

        // --- Prepare and Send Email ---
        $email_subject = "Your Subscription is Expiring Soon!";
        
        $email_body = "
            <p>Dear " . htmlspecialchars($firstname) . " " . htmlspecialchars($lastname) . ",</p>
            <p>This is a friendly reminder that your subscription is scheduled to expire in 7 days, on <strong>" . $expiry_date_formatted . "</strong>.</p>
            <p>To ensure uninterrupted access to our services, please log in to your account and renew your subscription at your earliest convenience.</p>
            <p>If you have any questions, please don't hesitate to contact our support team.</p>
            <p>Thank you for being a valued vendor!</p>
        ";

        // Placeholders for the email template function
        $email_placeholders = array(
            "{firstname}" => $firstname,
            "{lastname}" => $lastname,
            "{expiry_date}" => $expiry_date_formatted
        );

        // Use existing email functions if they are suitable
        // Trying to find a generic template or using a direct send
        $template_subject = getSuperAdminEmailTemplate('vendor-subscription-reminder', 'subject');
        $template_body = getSuperAdminEmailTemplate('vendor-subscription-reminder', 'body');

        if ($template_body) {
            $email_subject = $template_subject;
            $email_body = $template_body;
            foreach ($email_placeholders as $key => $val) {
                $email_subject = str_replace($key, $val, $email_subject);
                $email_body = str_replace($key, $val, $email_body);
            }
        }
        
        // The sendVendorEmail function seems to require a logged-in user context
        // which is not available in a cron job. We might need a more generic mailer.
        // Let's use the basic customBCMailSender from bc-mailer.php
        
        global $mail_sender_name, $mail_sender_email, $mail_headers;
        $details_array = array();
        $mail_html_body = mailDesignTemplate($email_subject, $email_body, $details_array, false);
        
        if (customBCMailSender($mail_sender_name, $email, $email_subject, $mail_html_body, $mail_headers)) {
            echo "Reminder email sent to " . htmlspecialchars($email) . "\n";

            // --- Update Notification Status ---
            $update_sql = "UPDATE sas_vendors SET expiry_notified = 1 WHERE id = ?";
            $update_stmt = mysqli_prepare($connection_server, $update_sql);
            mysqli_stmt_bind_param($update_stmt, "i", $vendor_id);
            if(mysqli_stmt_execute($update_stmt)) {
                echo "Updated expiry_notified status for vendor ID " . $vendor_id . "\n";
            } else {
                echo "Error updating status for vendor ID " . $vendor_id . ": " . mysqli_error($connection_server) . "\n";
            }
        } else {
            echo "Failed to send email to " . htmlspecialchars($email) . "\n";
        }
    }
} else {
    echo "No vendors to notify today.\n";
}

echo "Cron job finished.\n";
?>

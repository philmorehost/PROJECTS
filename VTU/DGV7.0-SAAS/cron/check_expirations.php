<?php
// This script is intended to be run as a cron job.
// It checks for expired vendor subscriptions and deactivates their accounts.

if (PHP_SAPI !== 'cli') {
    die("Direct access forbidden. This script must be run from the command line.");
}

// Set the correct path to the config files.
// The cron job will be run from the root of the project.
include(__DIR__ . "/../func/bc-connect.php");
include(__DIR__ . "/../func/bc-tables.php");

// --- Brute-force block auto-release ---
$block_release = bc_release_all_expired_bruteforce_blocks($connection_server);
if (empty($block_release['ok'])) {
    $message = $block_release['error'] ?? 'Unknown block-release error.';
    error_log('[DGV-BRUTEFORCE-CRON] ' . $message);
    echo "Brute-force auto-release failed: $message\n";
} else {
    echo 'Brute-force auto-release: '
        . (int)$block_release['accounts_removed'] . ' account block(s), '
        . (int)$block_release['ips_removed'] . ' IP block(s) removed; '
        . (int)$block_release['users_unblocked'] . ' user(s), '
        . (int)$block_release['vendors_unblocked'] . " vendor(s) unlocked.\n";
}

// --- Subscription Expiry Reminders ---
$reminder_date = date('Y-m-d', strtotime('+7 days'));

$stmt_reminder = mysqli_prepare($connection_server, "SELECT * FROM sas_vendors WHERE status = 1 AND expiry_date = ?");
mysqli_stmt_bind_param($stmt_reminder, "s", $reminder_date);
mysqli_stmt_execute($stmt_reminder);
$reminder_result = mysqli_stmt_get_result($stmt_reminder);

if ($reminder_result && mysqli_num_rows($reminder_result) > 0) {
    echo "Found " . mysqli_num_rows($reminder_result) . " vendor(s) with subscriptions expiring in 7 days.\n";
    while ($vendor = mysqli_fetch_assoc($reminder_result)) {
        $email_placeholders = array(
            "{firstname}" => $vendor['firstname'],
            "{lastname}" => $vendor['lastname'],
            "{expiry_date}" => date('F j, Y', strtotime($vendor['expiry_date']))
        );
        $email_subject = getSuperAdminEmailTemplate('vendor-subscription-reminder', 'subject');
        $email_body = getSuperAdminEmailTemplate('vendor-subscription-reminder', 'body');
        foreach($email_placeholders as $key => $val) {
            $email_subject = str_replace($key, $val, $email_subject);
            $email_body = str_replace($key, $val, $email_body);
        }
        sendVendorEmail($vendor['email'], $email_subject, $email_body);
        echo "Sent expiry reminder to vendor ID: " . $vendor['id'] . " (" . $vendor['email'] . ")\n";
    }
} else {
    echo "No subscriptions expiring in 7 days.\n";
}

echo "----------------------------------------\n";

// --- Subscription Deactivation ---
// Suspends accounts with an expiry date on or before 2 days ago
$suspension_date = date('Y-m-d', strtotime('-2 days'));

$stmt_deactivate = mysqli_prepare($connection_server, "SELECT * FROM sas_vendors WHERE status = 1 AND expiry_date <= ?");
mysqli_stmt_bind_param($stmt_deactivate, "s", $suspension_date);
mysqli_stmt_execute($stmt_deactivate);
$result = mysqli_stmt_get_result($stmt_deactivate);

if ($result && mysqli_num_rows($result) > 0) {
    echo "Found " . mysqli_num_rows($result) . " vendor(s) with expired subscriptions.\n";
    
    // Status is no longer changed to 0. Redirection logic in bc-config and bc-admin-config handles the restriction.
    // $update_stmt = mysqli_prepare($connection_server, "UPDATE sas_vendors SET status = 0 WHERE id = ?");

    while ($vendor = mysqli_fetch_assoc($result)) {
        $vendor_id = $vendor['id'];
        
        /*mysqli_stmt_bind_param($update_stmt, "i", $vendor_id);
        if (mysqli_stmt_execute($update_stmt)) {
            echo "Deactivated vendor ID: $vendor_id (" . $vendor['email'] . ")\n";
        }*/
            
        // Send deactivation email
        $email_placeholders = array(
            "{firstname}" => $vendor['firstname'],
            "{lastname}" => $vendor['lastname']
        );
        $email_subject = getSuperAdminEmailTemplate('vendor-subscription-expired', 'subject');
        $email_body = getSuperAdminEmailTemplate('vendor-subscription-expired', 'body');
        foreach($email_placeholders as $key => $val) {
            $email_subject = str_replace($key, $val, $email_subject);
            $email_body = str_replace($key, $val, $email_body);
        }
        sendVendorEmail($vendor['email'], $email_subject, $email_body);
    }
} else {
    echo "No expired vendors found.\n";
}

echo "Cron job finished.\n";

?>

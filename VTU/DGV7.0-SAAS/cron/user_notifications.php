<?php
/**
 * Cron Job: User Notifications
 * - Weekly Sales Analysis for API Users
 * - Low Balance Notifications (every 2 days if balance < 1000)
 */

if (PHP_SAPI !== 'cli') {
    die("Direct access forbidden. This script must be run from the command line.");
}

include(__DIR__ . "/../func/bc-connect.php");
include(__DIR__ . "/../func/bc-tables.php");

echo "Starting User Notifications Cron: " . date('Y-m-d H:i:s') . "\n";

// 1. Low Balance Notifications (Weekly if balance < 1000)
echo "Processing Low Balance Notifications (Weekly)...\n";
$low_balance_threshold = 1000;
// Identify users with balance below threshold who haven't been notified in the last 7 days
$q_low = mysqli_query($connection_server, "SELECT id, vendor_id, username, email, firstname, balance, last_low_balance_email
    FROM sas_users
    WHERE balance < $low_balance_threshold
    AND status = 1
    AND (last_low_balance_email IS NULL OR last_low_balance_email < NOW() - INTERVAL 7 DAY)");

if ($q_low && mysqli_num_rows($q_low) > 0) {
    echo "Found " . mysqli_num_rows($q_low) . " users for low balance alert.\n";
    while ($user = mysqli_fetch_assoc($q_low)) {
        $vid = $user['vendor_id'];
        $GLOBALS['vendor_id'] = $vid;
        resolveVendorID(true); // Set context for email templates

        $subject = "Low Balance Alert - Action Required";
        $body = "Hello " . $user['firstname'] . ",<br><br>Your wallet balance is currently <b>N" . number_format($user['balance'], 2) . "</b>, which is below our recommended threshold of N" . number_format($low_balance_threshold, 2) . ".<br><br>To avoid service interruptions, please fund your wallet today.<br><br>Thank you for choosing us!";

        // Use custom template if available
        $tmpl_subject = getUserEmailTemplate('low-balance-alert', 'subject');
        $tmpl_body = getUserEmailTemplate('low-balance-alert', 'body');
        if (!empty($tmpl_subject)) {
            $subject = str_replace(['{firstname}', '{balance}'], [$user['firstname'], number_format($user['balance'], 2)], $tmpl_subject);
        }
        if (!empty($tmpl_body)) {
            $body = str_replace(['{firstname}', '{balance}'], [$user['firstname'], number_format($user['balance'], 2)], $tmpl_body);
        }

        sendVendorEmail($user['email'], $subject, $body);
        mysqli_query($connection_server, "UPDATE sas_users SET last_low_balance_email = NOW() WHERE id = " . $user['id']);
        echo "Sent low balance email to: " . $user['username'] . "\n";
    }
} else {
    echo "No users qualified for low balance notifications.\n";
}

echo "----------------------------------------\n";

// 2. Inactivity Reminders (7+ Days since last login or transaction)
echo "Processing Inactivity Reminders (7+ Days)...\n";
$q_inactive = mysqli_query($connection_server, "SELECT id, vendor_id, username, email, firstname, last_login, last_inactivity_email
    FROM sas_users
    WHERE status = 1
    AND (last_inactivity_email IS NULL OR last_inactivity_email < NOW() - INTERVAL 7 DAY)
    AND (last_login IS NULL OR last_login < NOW() - INTERVAL 7 DAY)");

if ($q_inactive && mysqli_num_rows($q_inactive) > 0) {
    echo "Analyzing " . mysqli_num_rows($q_inactive) . " potentially inactive users...\n";
    while ($user = mysqli_fetch_assoc($q_inactive)) {
        $vid = $user['vendor_id'];
        $uname = mysqli_real_escape_string($connection_server, $user['username']);

        // Check for ANY transaction in the last 7 days
        $q_tx_check = mysqli_query($connection_server, "SELECT id FROM sas_transactions WHERE vendor_id='$vid' AND username='$uname' AND date > NOW() - INTERVAL 7 DAY LIMIT 1");

        if (mysqli_num_rows($q_tx_check) == 0) {
            $GLOBALS['vendor_id'] = $vid;
            resolveVendorID(true);

            $subject = "We Miss You! - Check back on our latest offers";
            $body = "Hi " . $user['firstname'] . ",<br><br>It's been a while since we last saw you on our platform. We've updated our services and prices to offer you even better value!<br><br>Log in now to see what's new and perform your transactions instantly.<br><br>Best Regards!";

            // Try specific template if available
            $tmpl_subject = getUserEmailTemplate('user-inactivity', 'subject');
            $tmpl_body = getUserEmailTemplate('user-inactivity', 'body');
            if (!empty($tmpl_subject)) $subject = str_replace('{firstname}', $user['firstname'], $tmpl_subject);
            if (!empty($tmpl_body)) $body = str_replace('{firstname}', $user['firstname'], $tmpl_body);

            sendVendorEmail($user['email'], $subject, $body);
            mysqli_query($connection_server, "UPDATE sas_users SET last_inactivity_email = NOW() WHERE id = " . $user['id']);
            echo "Sent inactivity reminder to: " . $user['username'] . "\n";
        }
    }
} else {
    echo "No users qualified for inactivity reminders.\n";
}

echo "----------------------------------------\n";

// 2. Weekly Sales Analysis (For API Users)
echo "Processing Weekly Sales Analysis for API Users...\n";
// API Users are account_level 3
$q_api = mysqli_query($connection_server, "SELECT id, vendor_id, username, email, firstname, last_weekly_sales_email
    FROM sas_users
    WHERE account_level = 3
    AND status = 1
    AND (last_weekly_sales_email IS NULL OR last_weekly_sales_email < NOW() - INTERVAL 7 DAY)");

if ($q_api && mysqli_num_rows($q_api) > 0) {
    echo "Found " . mysqli_num_rows($q_api) . " API users for weekly analysis.\n";
    while ($user = mysqli_fetch_assoc($q_api)) {
        $vid = $user['vendor_id'];
        $uname = $user['username'];
        $GLOBALS['vendor_id'] = $vid;
        resolveVendorID(true);

        // Fetch sales summary for the last 7 days
        $start_date = date('Y-m-d 00:00:00', strtotime('-7 days'));
        $end_date = date('Y-m-d 23:59:59');

        $q_stats = mysqli_query($connection_server, "SELECT COUNT(*) as total_count, SUM(discounted_amount) as total_spent
            FROM sas_transactions
            WHERE vendor_id = '$vid'
            AND username = '$uname'
            AND status = 1
            AND type_alternative NOT LIKE '%Credit%'
            AND date BETWEEN '$start_date' AND '$end_date'");

        $stats = mysqli_fetch_assoc($q_stats);
        $total_count = $stats['total_count'] ?? 0;
        $total_spent = $stats['total_spent'] ?? 0;

        if ($total_count > 0) {
            $subject = "Your Weekly Sales Performance Report";
            $body = "Dear " . $user['firstname'] . ",<br><br>Here is your sales summary for the past week (API Usage):<br><br>
                - <b>Total Transactions:</b> $total_count<br>
                - <b>Total Volume:</b> N" . number_format($total_spent, 2) . "<br><br>
                Log in to your dashboard to view detailed reports.<br><br>Keep up the great work!";

            sendVendorEmail($user['email'], $subject, $body);
            echo "Sent weekly sales analysis to: " . $user['username'] . "\n";
        } else {
            echo "Skipping " . $user['username'] . " (No sales activity this week).\n";
        }

        mysqli_query($connection_server, "UPDATE sas_users SET last_weekly_sales_email = NOW() WHERE id = " . $user['id']);
    }
} else {
    echo "No API users qualified for weekly sales analysis.\n";
}

echo "Cron job finished at: " . date('Y-m-d H:i:s') . "\n";
?>

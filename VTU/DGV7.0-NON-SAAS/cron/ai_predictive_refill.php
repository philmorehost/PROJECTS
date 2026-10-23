<?php
/**
 * DGV7.0 AI Edition — Predictive Refill Agent
 * Scans transaction history for recurring patterns and sends refill reminder emails.
 *
 * Frequency: Once Daily
 * Command: php /path/to/cron/ai_predictive_refill.php
 */

include_once(__DIR__ . "/../func/bc-connect.php");

echo "AI Predictive Refill Agent started...\n";

// Get users who buy similar amounts/plans on regular intervals
$q = mysqli_query($connection_server, "SELECT 
    username, 
    description,
    COUNT(*) as buy_count,
    AVG(DATEDIFF(NOW(), created_at)) as avg_days_since_start,
    MAX(created_at) as last_buy
    FROM sas_transactions 
    WHERE status=1 AND type_alternative='debit'
    GROUP BY username, description
    HAVING buy_count >= 3");

while ($row = mysqli_fetch_assoc($q)) {
    $last_buy = strtotime($row['last_buy']);
    $days_since = (time() - $last_buy) / 86400;

    // We assume a 30-day cycle for VTU data/airtime
    // If it's day 28 or 29, send a reminder
    if ($days_since >= 28 && $days_since <= 29) {
        $username = $row['username'];

        // Get user contact + vendor context
        $u_q = mysqli_query($connection_server, "SELECT vendor_id, email FROM sas_users WHERE username='".mysqli_real_escape_string($connection_server, $username)."' LIMIT 1");
        $user = mysqli_fetch_assoc($u_q);

        if ($user && !empty($user['email'])) {
            $msg = "Hey " . ucfirst($username) . "!<br><br>"
                 . "Based on your history, your <b>" . htmlspecialchars($row['description']) . "</b> might be running out soon.<br><br>"
                 . "Want to refill now to stay connected? Just log in to top up.<br><br>"
                 . "&mdash; Powered by AI Sentinel";

            global $get_logged_user_details;
            $get_logged_user_details = ['vendor_id' => $user['vendor_id'], 'username' => $username];
            sendVendorEmail($user['email'], "Time to refill: " . $row['description'], $msg);
            bc_notify_user($connection_server, $user['vendor_id'], $username, "Refill Reminder", $row['description'] . " might be running out soon.", "");
            echo "Sent reminder to $username ($days_since days since last buy).\n";
        }
    }
}

echo "Predictive scan complete.\n";

<?php
/**
 * Dormant User Alert Cron — DGV7.0 AI Edition
 * Runs: Daily at 10:00 AM
 */

define('CRON_CLI', true);
require_once __DIR__ . '/../func/bc-connect.php';
require_once __DIR__ . '/../func/bc-ai-engine.php';

$start   = microtime(true);
$sent    = 0;
$engine  = ai_engine();
$ai_up   = $engine->isAiOnline();
$model   = getSuperAdminOption('ai_default_model', 'gemini-1.5-flash');

echo "[DORMANT-ALERT] " . date('Y-m-d H:i:s') . " — Sweep started\n";

$vendors_q = mysqli_query($connection_server, "SELECT id, firstname, site_name FROM sas_vendors WHERE status=1");

while ($vendor = mysqli_fetch_assoc($vendors_q)) {
    $vid   = (int)$vendor['id'];
    $vname = $vendor['site_name'] ?: $vendor['firstname'] ?: 'VTU Platform';

    $dormant_q = mysqli_query($connection_server, "SELECT u.id, u.firstname, u.username, u.email, MAX(t.date) as last_tx FROM sas_users u LEFT JOIN sas_transactions t ON t.vendor_id=u.vendor_id AND t.username=u.username AND t.status=1 WHERE u.vendor_id='$vid' AND u.status=1 AND u.email != '' GROUP BY u.id HAVING (last_tx < DATE_SUB(NOW(), INTERVAL 14 DAY)) LIMIT 50");

    while ($user = mysqli_fetch_assoc($dormant_q)) {
        $name = $user['firstname'] ?: 'Customer';

        if ($ai_up) {
            $prompt  = "Write a friendly re-engagement email message for $name on $vname. Max 2 sentences.";
            $result  = $engine->chat($model, $prompt);
            $message = $result['response'] ?? "Hi $name! We've missed you on $vname. Log in today for data and airtime!";
        } else {
            $message = "Hi $name! We've missed you on $vname. Log in today for data and airtime!";
        }

        global $get_logged_user_details;
        $get_logged_user_details = ['vendor_id' => $vid, 'username' => $user['username']];
        sendVendorEmail($user['email'], "We miss you at $vname", $message);
        bc_notify_user($connection_server, $vid, $user['username'], "We miss you!", $message, "");
        $sent++;
        echo "  ✅ Sent to $name\n";
    }
}

$elapsed = round(microtime(true) - $start, 2);
echo "[DORMANT-ALERT] Done. Sent: $sent | Time: {$elapsed}s\n";

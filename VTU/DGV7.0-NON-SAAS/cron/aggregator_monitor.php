<?php
/**
 * aggregator_monitor.php — DGV6.90 AI Edition
 * Cron: Runs every 5 minutes
 * Purpose: Monitors API provider success rates. If a provider drops below 85%
 *          success rate in the last hour, it flags it inactive and alerts Super Admin.
 *
 * cPanel Cron: Every 5 minutes /usr/local/bin/php /home/[user]/public_html/cron/aggregator_monitor.php
 */

define('RUNNING_AS_CRON', true);
require_once(__DIR__ . '/../func/bc-config.php');

if (!$connection_server) exit("No DB connection\n");

// Get all distinct providers from recent transactions
$providers_q = mysqli_query($connection_server,
    "SELECT DISTINCT api_website FROM sas_transactions WHERE date >= DATE_SUB(NOW(), INTERVAL 1 HOUR) AND api_website != ''"
);
if (!$providers_q) exit("No providers found\n");

$alerts = [];

while ($prow = mysqli_fetch_assoc($providers_q)) {
    $provider = $prow['api_website'];
    if (empty($provider)) continue;

    $esc_prov = mysqli_real_escape_string($connection_server, $provider);

    // Calculate success rate in last 1 hour
    $stats_q = mysqli_query($connection_server,
        "SELECT
            COUNT(*) as total,
            SUM(CASE WHEN status=1 THEN 1 ELSE 0 END) as success,
            AVG(TIMESTAMPDIFF(SECOND, date, NOW())) as avg_age
         FROM sas_transactions
         WHERE api_website='$esc_prov' AND date >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
    );
    $stats = $stats_q ? mysqli_fetch_assoc($stats_q) : null;
    if (!$stats || $stats['total'] < 5) continue; // Need at least 5 calls to make a judgement

    $success_rate = $stats['total'] > 0 ? round(($stats['success'] / $stats['total']) * 100, 2) : 100;
    $is_active    = $success_rate >= 85 ? 1 : 0;

    // Upsert health record
    mysqli_query($connection_server,
        "INSERT INTO sas_aggregator_health (provider_name, success_rate_1h, is_active)
         VALUES ('$esc_prov', '$success_rate', '$is_active')
         ON DUPLICATE KEY UPDATE success_rate_1h='$success_rate', is_active='$is_active', last_checked=NOW()"
    );

    echo date('Y-m-d H:i:s') . " Provider: $provider | Rate: {$success_rate}% | Active: $is_active\n";

    if (!$is_active) {
        $alerts[] = "• *$provider*: {$success_rate}% success rate (below 85%)";
    }
}

// Email alert if any providers are failing
if (!empty($alerts)) {
    $super_admin_q = mysqli_query($connection_server,
        "SELECT email FROM sas_super_admin LIMIT 1"
    );
    $sa = $super_admin_q ? mysqli_fetch_assoc($super_admin_q) : null;

    if ($sa && !empty($sa['email'])) {
        $msg = "<b>API Provider Alert</b><br><br>"
             . "The following providers are performing below 85% success rate in the last hour:<br><br>"
             . implode("<br>", array_map('htmlspecialchars', $alerts)) . "<br><br>"
             . "Please check your API configurations in the dashboard.";
        sendSuperAdminEmail($sa['email'], "API Provider Alert", $msg);
    }
    echo "Alert sent for " . count($alerts) . " failing providers.\n";
}

echo date('Y-m-d H:i:s') . " aggregator_monitor complete.\n";

<?php
/**
 * AI Daily Business Briefing Cron — DGV7.0 AI Edition
 * Runs: Daily at 7:00 AM
 */

define('CRON_CLI', true);
require_once __DIR__ . '/../func/bc-connect.php';
require_once __DIR__ . '/../func/bc-ai-engine.php';

$yesterday = date('Y-m-d', strtotime('-1 day'));
$engine    = ai_engine();
$ai_online = $engine->isAiOnline();
$model     = getSuperAdminOption('ai_default_model', 'gemini-1.5-flash');
$sent      = 0;

echo "[DAILY-BRIEFING] " . date('Y-m-d H:i:s') . " — Generating vendor briefings\n";

if (!$ai_online) {
    echo "[DAILY-BRIEFING] Cloud AI engine offline — skipping\n";
    exit(0);
}

// Query active vendors
$vendors_q = mysqli_query($connection_server, "SELECT id, firstname, lastname, email, site_name FROM sas_vendors WHERE status=1 AND email != ''");

while ($vendor = mysqli_fetch_assoc($vendors_q)) {
    $vid   = (int)$vendor['id'];
    $vname = $vendor['site_name'] ?: ($vendor['firstname'].' '.$vendor['lastname']);

    $GLOBALS['vendor_id'] = $vid;
    resolveVendorID(true);

    // Pull stats
    $stats_q = mysqli_query($connection_server, "SELECT COUNT(*) total_tx, SUM(CASE WHEN status=1 THEN 1 ELSE 0 END) successful, SUM(CASE WHEN status=1 THEN discounted_amount ELSE 0 END) revenue FROM sas_transactions WHERE vendor_id='$vid' AND DATE(date)='$yesterday'");
    $stats = mysqli_fetch_assoc($stats_q);
    if (!$stats || $stats['total_tx'] == 0) continue;

    $rev_fmt = '₦' . number_format($stats['revenue'], 2);
    $prompt  = "You are a VTU business analyst. Write a concise daily business briefing email for $vname based on: {$stats['total_tx']} total, {$stats['successful']} successful, revenue $rev_fmt. Start with 'Daily Business Report - $yesterday'.";

    $result  = $engine->chat($model, $prompt);
    $message = $result['response'] ?? "Daily Business Report - $yesterday\n\nHi $vname! Yesterday: {$stats['successful']} successful transactions, revenue $rev_fmt. Keep growing!";

    global $get_logged_user_details;
    $get_logged_user_details = ['vendor_id' => $vid, 'username' => ''];
    sendVendorEmail($vendor['email'], "Daily Business Report - $yesterday", nl2br($message));
    bc_notify_user($connection_server, $vid, '', "Daily Business Report", "Yesterday: {$stats['successful']} successful, revenue $rev_fmt", "");
    $sent++;
    echo "  ✅ Briefing sent to $vname\n";
}

echo "\n[DAILY-BRIEFING] Done. Sent: $sent\n";

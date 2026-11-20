<?php
/**
 * Global Crypto Transaction Sync Cron
 * §28: legacy provider removed — no-op (see body). Kept for cron compatibility.
 */
include_once(__DIR__ . "/bc-connect.php");
include_once(__DIR__ . "/bc-crypto-func.php");

// Set execution time limit to 5 minutes
set_time_limit(300);

function logCron($msg) {}

logCron("--- Crypto Cron Started ---");

if (!$connection_server) {
    logCron("CRITICAL: Database connection failed.");
    exit;
}

// §28: the legacy crypto provider was removed, so there is no external status
// feed to poll. Pending legacy deposits are settled by admin reconciliation
// (bc-admin → Crypto Invoices). This cron intentionally performs no provider
// calls and no balance changes; it stays in place so existing cPanel schedules
// keep succeeding instead of erroring.
$count = 0;

logCron("--- Crypto Cron Finished ($count processed) ---");
?>

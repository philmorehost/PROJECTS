<?php
/**
 * Global Crypto Transaction Sync Cron
 * This script checks all pending crypto deposits and updates their status.
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

// Get pending transactions (status 2) created in the last 48 hours to avoid indefinite polling of very old ones
$q = mysqli_query($connection_server, "SELECT * FROM `sas_crypto_transactions` WHERE `status`='2' AND `type`='deposit' AND `plisio_tx_id` != '' AND `created_at` > DATE_SUB(NOW(), INTERVAL 48 HOUR) ORDER BY `created_at` DESC");

if (!$q) {
    logCron("Error fetching transactions: " . mysqli_error($connection_server));
    exit;
}

$count = 0;
while ($tx = mysqli_fetch_assoc($q)) {
    $count++;
    $ref = $tx['reference'];
    $vid = $tx['vendor_id'];

    // Query Plisio for current status
    $res = getPlisioTransactionDetails($tx['plisio_tx_id'], $vid);

    if (($res['status'] ?? '') == 'success') {
        $data = $res['data'];
        $new_st = $data['status'] ?? '';

        if ($new_st == 'completed' || $new_st == 'mismatch') {
            $amount = $data['amount'] ?? $tx['amount'];
            if (updateUserCryptoBalance($vid, $tx['username'], $tx['currency_code'], $amount, 'credit')) {
                mysqli_query($connection_server, "UPDATE `sas_crypto_transactions` SET `status`='1', `amount`='$amount' WHERE `id`='".$tx['id']."'");
                logCron("SUCCESS: $ref credited with $amount " . $tx['currency_code']);
            } else {
                logCron("ERROR: Failed to update wallet for $ref");
            }
        } elseif ($new_st == 'expired' || $new_st == 'cancelled' || $new_st == 'error') {
            mysqli_query($connection_server, "UPDATE `sas_crypto_transactions` SET `status`='3' WHERE `id`='".$tx['id']."'");
            logCron("EXPIRED/FAILED: $ref marked as $new_st");
        } else {
            // Still pending (new, pending, etc.)
            logCron("INFO: $ref is still $new_st");
        }
    } else {
        logCron("WARNING: Could not fetch Plisio status for $ref: " . ($res['message'] ?? 'Unknown Error'));
    }

    // Small sleep to avoid hitting rate limits if many transactions exist
    usleep(100000); // 0.1s
}

logCron("--- Crypto Cron Finished ($count processed) ---");
?>

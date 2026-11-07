<?php
function logPlisio($msg) {
    $logFile = __DIR__ . '/logs/plisio_webhook.log';
    if(!is_dir(dirname($logFile))) mkdir(dirname($logFile), 0777, true);
    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] " . $msg . "\n", FILE_APPEND);
}

session_start();
include_once(__DIR__."/func/bc-connect.php");
include_once(__DIR__."/func/bc-crypto-func.php");

$body = file_get_contents("php://input");
logPlisio("Plisio Webhook Received: " . $body);

$data = $_POST;
if (empty($data)) {
    $data = json_decode($body, true);
}

if (!$data || !isset($data['status'])) {
    logPlisio("Invalid payload");
    exit;
}

$status = $data['status'];
$txn_id = $data['txn_id'] ?? '';
$order_id = $data['order_number'] ?? $data['order_id'] ?? ''; // Support both order_number and order_id

if (empty($order_id)) {
    logPlisio("No Order ID (Reference) found");
    exit;
}

// Check if transaction already processed (status 1 = Success)
$ref_esc = mysqli_real_escape_string($connection_server, $order_id);
$txn_id_esc = mysqli_real_escape_string($connection_server, $txn_id);

// Robust Reference extraction (e.g. DEP_1773978807_95 -> 1773978807)
$clean_ref = $order_id;
if (strpos($order_id, 'DEP_') === 0) {
    $parts = explode('_', $order_id);
    if (count($parts) >= 2) $clean_ref = $parts[1];
}
$clean_ref_esc = mysqli_real_escape_string($connection_server, $clean_ref);

$check = mysqli_query($connection_server, "SELECT * FROM sas_crypto_transactions WHERE (reference='$ref_esc' OR reference='$clean_ref_esc' OR (plisio_tx_id='$txn_id_esc' AND plisio_tx_id != '')) AND status='1'");
if (mysqli_num_rows($check) > 0) {
    logPlisio("Transaction $order_id already completed/credited");
    exit;
}

// Find the transaction record to get user/vendor context (with retry logic for race conditions)
$r = null;
for ($i = 0; $i < 3; $i++) {
    $q = mysqli_query($connection_server, "SELECT * FROM `sas_crypto_transactions` WHERE reference='$ref_esc' OR reference='$clean_ref_esc' OR (plisio_tx_id='$txn_id_esc' AND plisio_tx_id != '') LIMIT 1");
    if ($q && $r = mysqli_fetch_assoc($q)) {
        break;
    }
    if ($i < 2) usleep(500000); // Wait 0.5s before retry
}

if ($r) {
    $vendor_id = $r['vendor_id'];
    $username = $r['username'];
    $currency = $r['currency_code'];

    $GLOBALS['vendor_id'] = $vendor_id;
    resolveVendorID(true);

    // Verify signature
    $verify = verifyPlisioWebhook($data, $vendor_id);
    if (!$verify) {
        logPlisio("Signature verification failed for $order_id");
        exit;
    }

    // Status 31 = Completed in Plisio API
    $status_code = (int)($data['status_code'] ?? 0);

    if ($status == 'completed' || $status == 'mismatch' || $status_code === 31) {
        $amount = $data['amount'] ?? $r['amount'];
        $blockchain_txid = $data['tx_id'] ?? '';
        if (is_array($blockchain_txid)) $blockchain_txid = implode(',', $blockchain_txid);
        $blockchain_txid_esc = mysqli_real_escape_string($connection_server, $blockchain_txid);

        // Update wallet
        if (updateUserCryptoBalance($vendor_id, $username, $currency, $amount, 'credit')) {
            mysqli_query($connection_server, "UPDATE `sas_crypto_transactions` SET `status`='1', `amount`='$amount', `blockchain_txid`='$blockchain_txid_esc' WHERE `reference`='$ref_esc'");
            logPlisio("Successfully credited $amount $currency to $username (Vendor $vendor_id)");
        } else {
            $db_err = mysqli_error($connection_server);
            logPlisio("ERROR: Payment detected but failed to update wallet for $username. DB Error: $db_err");
        }
    } else if ($status == 'expired' || $status == 'cancelled') {
        mysqli_query($connection_server, "UPDATE `sas_crypto_transactions` SET `status`='3' WHERE `reference`='$ref_esc'");
        logPlisio("Transaction $order_id marked as $status");
    }
} else {
    logPlisio("Transaction record not found for reference: $order_id");
}
?>

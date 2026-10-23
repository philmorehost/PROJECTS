<?php
/**
 * PayHub Webhook Handler
 * Location: /users-payhub.php
 */

function logPayhub($msg) {
    $dir = __DIR__ . "/logs";
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    @file_put_contents($dir . "/payhub_webhook.log", "[" . date('Y-m-d H:i:s') . "] " . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
}

session_start();
include_once(__DIR__ . "/func/bc-connect.php");

$body = file_get_contents("php://input");
logPayhub("Incoming Webhook: " . substr($body, 0, 2000));

$catch = json_decode($body, true);
if (!$catch) {
    logPayhub("Invalid payload");
    http_response_code(400);
    exit("Invalid payload");
}

// Support both v2 (event object) and v1 (flat or direct data)
$event = $catch['event'] ?? '';
$data = $catch['data'] ?? $catch;
$reference = $data['reference'] ?? '';

if ($event == 'charge.success' || ($catch['status'] ?? '') == 'success' || ($catch['status'] ?? '') == 'successful') {
    logPayhub("Processing success event for reference: $reference");

    // 1. Context Resolution from metadata
    $meta = [];
    if (!empty($data['metadata'])) {
        $meta = is_array($data['metadata']) ? $data['metadata'] : json_decode($data['metadata'], true);
        if (!is_array($meta)) $meta = [];
    }

    $vid = (int)($meta['vendor_id'] ?? 0);
    $target = $meta['target'] ?? 'user';
    $username = $meta['username'] ?? '';

    logPayhub("Context: vid=$vid target=$target username='" . $username . "' ref=$reference");

    // Fallback: resolve vendor/user from the checkout record if metadata is missing/empty.
    if (($vid <= 0 || empty($username)) && !empty($reference)) {
        $ref_esc = mysqli_real_escape_string($connection_server, $reference);
        $q_c = mysqli_query($connection_server, "SELECT vendor_id, username FROM sas_user_payment_checkouts WHERE reference='$ref_esc' LIMIT 1");
        if ($r_c = mysqli_fetch_assoc($q_c)) {
            if ($vid <= 0) $vid = (int)$r_c['vendor_id'];
            if (empty($username)) $username = $r_c['username'];
            logPayhub("Resolved context from checkout: vid=$vid username='$username'");
        }
    }
    // Also try the metadata reference if PayHub generated its own transaction reference.
    if (($vid <= 0 || empty($username)) && !empty($meta['reference']) && $meta['reference'] !== $reference) {
        $ref_esc2 = mysqli_real_escape_string($connection_server, $meta['reference']);
        $q_c2 = mysqli_query($connection_server, "SELECT vendor_id, username FROM sas_user_payment_checkouts WHERE reference='$ref_esc2' LIMIT 1");
        if ($r_c2 = mysqli_fetch_assoc($q_c2)) {
            if ($vid <= 0) $vid = (int)$r_c2['vendor_id'];
            if (empty($username)) $username = $r_c2['username'];
            logPayhub("Resolved context from metadata reference: vid=$vid username='$username'");
        }
    }

    // If it's a vendor funding, they pay the platform, so we use Super Admin keys (vid=0)
    // If it's a user funding, they pay their vendor, so we use vendor keys
    $lookup_vid = ($target == 'vendor') ? 0 : $vid;
    $payhub_keys = getGatewayDetails('payhub', $lookup_vid);

    if (!$payhub_keys) {
        logPayhub("CRITICAL: PayHub keys not found for VID $lookup_vid. Aborting.");
        http_response_code(404);
        exit;
    }

    // 2. Verify the payment with PayHub BEFORE crediting. A webhook payload can be forged
    // (anyone can POST {"status":"success", ...}), so we must confirm PayHub actually received
    // the money. Only an explicit data.status of success/successful counts.
    $verify_res = makePayhubRequest("GET", "api/transaction/verify/" . urlencode($reference), "", $vid, ($target == 'vendor'));
    $v_data = json_decode($verify_res, true);
    $verified = false;
    if (($v_data['status'] ?? '') == 'success') {
        $v_tx_raw = json_decode($v_data['json_result'], true);
        $v_tx_data = (isset($v_tx_raw['data']) && is_array($v_tx_raw['data'])) ? $v_tx_raw['data'] : $v_tx_raw;
        $verified = in_array(strtolower($v_tx_data['status'] ?? ''), ['success', 'successful'], true);
    }
    if (!$verified) {
        logPayhub("CRITICAL: Verification FAILED for $reference — webhook ignored (possible forgery).");
        http_response_code(200);
        echo "Ignored";
        exit;
    }

    // 3. Process payment and credit wallet
    //
    // SECURITY: credit from the SERVER-VERIFIED PayHub response, never from the posted
    // webhook body. The body is unsigned, so anyone can POST
    // {"status":"success","amount":<anything>,"metadata":{...}}. The verification above
    // only proves the REFERENCE was paid - it says nothing about whether this body is
    // truthful. Passing $data here would let the caller dictate `amount`, and the
    // domain/gateway_amount guards inside processPayhubSuccess would then be reading
    // attacker-supplied values as well.
    $v_tx_meta = [];
    if (!empty($v_tx_data['metadata'])) {
        $v_tx_meta = is_array($v_tx_data['metadata']) ? $v_tx_data['metadata'] : json_decode($v_tx_data['metadata'], true);
        if (!is_array($v_tx_meta)) $v_tx_meta = [];
    }
    // Verified metadata is what PayHub recorded at initialize time, so it is the authority
    // on which account this payment belongs to. Fall back to the posted body only when
    // PayHub returned none (older transactions).
    if (!empty($v_tx_meta['vendor_id'])) $vid = (int)$v_tx_meta['vendor_id'];
    if (!empty($v_tx_meta['username']))  $username = $v_tx_meta['username'];
    if (!empty($v_tx_meta['target']))    $target = $v_tx_meta['target'];
    if (empty($v_tx_data['reference']))  $v_tx_data['reference'] = $reference;

    $result_ref = processPayhubSuccess($vid, $v_tx_data['reference'], $v_tx_data, $payhub_keys, $username);

    if ($result_ref) {
        logPayhub("Successfully processed $reference. Local Ref: $result_ref");
        http_response_code(200);
        echo "Success";
    } else {
        logPayhub("Failed to process $reference (vid=$vid username='" . $username . "' target=$target)");
        http_response_code(500);
        echo "Processing failed";
    }
} else {
    logPayhub("Ignored event type: $event");
    http_response_code(200);
    echo "Event ignored";
}
?>

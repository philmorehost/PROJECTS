<?php
/**
 * PayHub Webhook Handler
 * Location: /users-payhub.php
 */

function logPayhub($msg) {
}

session_start();
include_once(__DIR__ . "/func/bc-connect.php");

$body = file_get_contents("php://input");
logPayhub("Incoming Webhook: " . $body);

$catch = json_decode($body, true);
if (!$catch) {
    logPayhub("Invalid payload");
    http_response_code(400);
    exit("Invalid payload");
}

// Support both v2 (event object) and v1 (flat or direct data)
$event = $catch['event'] ?? 'charge.success';
$data = $catch['data'] ?? $catch;
$reference = $data['reference'] ?? '';

if ($event == 'charge.success' || ($catch['status'] ?? '') == 'success' || ($catch['status'] ?? '') == 'successful') {
    logPayhub("Processing success event for reference: $reference");

    // We need to determine the vendor_id to get the correct keys for signature verification if applicable
    // But processPayhubSuccess handles context resolution internally.

    // 1. Context Resolution from metadata
    $meta = [];
    if (!empty($data['metadata'])) {
        $meta = is_array($data['metadata']) ? $data['metadata'] : json_decode($data['metadata'], true);
    }

    $vid = (int)($meta['vendor_id'] ?? 0);
    $target = $meta['target'] ?? 'user';

    // If it's a vendor funding, they pay the platform, so we use Super Admin keys (vid=0)
    // If it's a user funding, they pay their vendor, so we use vendor keys
    $lookup_vid = ($target == 'vendor') ? 0 : $vid;
    $payhub_keys = getGatewayDetails('payhub', $lookup_vid);

    if (!$payhub_keys) {
        logPayhub("CRITICAL: PayHub keys not found for VID $lookup_vid. Aborting.");
        http_response_code(404);
        exit;
    }

    // Security Fix: this webhook body is unauthenticated input — a forged POST with
    // "status":"success" and an arbitrary reference/amount/metadata would previously be
    // credited without question. Before crediting anything, independently re-verify the
    // reference against PayHub's own transaction-verify API (same pattern already proven
    // in reconcileDeposit(), func/bc-func.php ~line 4998) and only proceed using the
    // VERIFIED transaction data PayHub's API returns — never the raw webhook body.
    if (empty($reference)) {
        logPayhub("SECURITY: Webhook payload missing reference. Rejecting.");
        http_response_code(400);
        exit("Missing reference");
    }

    $is_vendor_recon = ($target == 'vendor');
    $verify_res = makePayhubRequest("GET", "api/transaction/verify/" . urlencode($reference), "", $vid, $is_vendor_recon);
    $v_data = json_decode($verify_res, true);
    $verified_tx = null;
    if (($v_data['status'] ?? "") == "success") {
        $tx_raw = json_decode($v_data['json_result'], true);
        $tx_data = $tx_raw['data'] ?? $tx_raw;
        // SECURITY: PayHub's verify endpoint returns TOP-LEVEL `status:true` merely to mean
        // "transaction retrieved"; the REAL payment status is in `data.status`. Only treat
        // 'success'/'successful' (data.status) as paid — never the top-level boolean.
        $tx_status = strtolower($tx_data['status'] ?? "");
        if ($tx_status == "success" || $tx_status == "successful") {
            $verified_tx = $tx_data;
        }
    }

    if (!$verified_tx) {
        logPayhub("SECURITY: Could not independently verify reference $reference via PayHub's API. Refusing to credit.");
        http_response_code(400);
        exit("Unverified transaction");
    }

    // 2. Process payment and credit wallet — using the VERIFIED transaction data from
    // PayHub's own API, not the raw (attacker-controllable) webhook body.
    $username = $meta['username'] ?? '';
    $result_ref = processPayhubSuccess($vid, $verified_tx['reference'] ?? $reference, $verified_tx, $payhub_keys, $username);

    if ($result_ref) {
        logPayhub("Successfully processed $reference. Local Ref: $result_ref");
        http_response_code(200);
        echo "Success";
    } else {
        logPayhub("Failed to process $reference");
        http_response_code(500);
        echo "Processing failed";
    }
} else {
    logPayhub("Ignored event type: $event");
    http_response_code(200);
    echo "Event ignored";
}
?>

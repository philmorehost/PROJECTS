<?php
/**
 * PayHub Webhook Handler (Vendors/Platform)
 * Location: /vendors-payhub.php
 */

function logPayhubVendor($msg) {
}

session_start();
include_once(__DIR__ . "/func/bc-connect.php");

$body = file_get_contents("php://input");
logPayhubVendor("Incoming Vendor Webhook: " . $body);

$catch = json_decode($body, true);
if (!$catch) {
    logPayhubVendor("Invalid payload");
    http_response_code(400);
    exit("Invalid payload");
}

// Support both v2 (event object) and v1 (flat or direct data)
$event = $catch['event'] ?? '';
$data = $catch['data'] ?? $catch;
$reference = $data['reference'] ?? '';

if ($event == 'charge.success' || ($catch['status'] ?? '') == 'success' || ($catch['status'] ?? '') == 'successful') {
    logPayhubVendor("Processing success event for reference: $reference");

    // For vendor payments, they are paying the platform, so we use Super Admin keys (vid=0)
    $payhub_keys = getGatewayDetails('payhub', 0);

    if (!$payhub_keys) {
        logPayhubVendor("CRITICAL: PayHub keys not found for Super Admin. Aborting.");
        http_response_code(404);
        exit;
    }

    // Process payment and credit vendor wallet
    // metadata is expected to contain "target" => "vendor" and "vendor_id"
    $meta = [];
    if (!empty($data['metadata'])) {
        $meta = is_array($data['metadata']) ? $data['metadata'] : json_decode($data['metadata'], true);
    }

    // Verify the payment with PayHub BEFORE crediting — webhook payloads can be forged.
    $verify_res = makePayhubRequest("GET", "api/transaction/verify/" . urlencode($reference), "", 0, true);
    $v_data = json_decode($verify_res, true);
    $verified = false;
    if (($v_data['status'] ?? '') == 'success') {
        $v_tx_raw = json_decode($v_data['json_result'], true);
        $v_tx_data = (isset($v_tx_raw['data']) && is_array($v_tx_raw['data'])) ? $v_tx_raw['data'] : $v_tx_raw;
        $verified = in_array(strtolower($v_tx_data['status'] ?? ''), ['success', 'successful'], true);
    }
    if (!$verified) {
        logPayhubVendor("Verification FAILED for $reference — ignored (possible forgery).");
        http_response_code(200);
        echo "Ignored";
        exit;
    }

    // SECURITY: credit from the SERVER-VERIFIED PayHub response, never from the posted
    // webhook body. The body is unsigned - verification above only proves the REFERENCE
    // was paid, not that the body is truthful - so passing $data would let the caller
    // dictate `amount` and the account the credit lands in.
    $v_tx_meta = [];
    if (!empty($v_tx_data['metadata'])) {
        $v_tx_meta = is_array($v_tx_data['metadata']) ? $v_tx_data['metadata'] : json_decode($v_tx_data['metadata'], true);
        if (!is_array($v_tx_meta)) $v_tx_meta = [];
    }
    // Prefer the vendor PayHub recorded against the transaction; fall back to the body.
    $vid = (int)($v_tx_meta['vendor_id'] ?? 0);
    if ($vid <= 0) $vid = (int)($meta['vendor_id'] ?? 0);
    if (empty($v_tx_data['reference'])) $v_tx_data['reference'] = $reference;

    $result_ref = processPayhubSuccess($vid, $v_tx_data['reference'], $v_tx_data, $payhub_keys);

    if ($result_ref) {
        logPayhubVendor("Successfully processed $reference. Local Ref: $result_ref");
        http_response_code(200);
        echo "Success";
    } else {
        logPayhubVendor("Failed to process $reference");
        http_response_code(500);
        echo "Processing failed";
    }
} else {
    logPayhubVendor("Ignored event type: $event");
    http_response_code(200);
    echo "Event ignored";
}
?>

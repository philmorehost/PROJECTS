<?php
/**
 * Flutterwave v3 wallet-funding webhook.
 *
 * DGV7 phase 3. The verification approach is unchanged from the original handler —
 * and is the reference implementation for the new adapters:
 *   1. verif-hash compared with hash_equals against the gateway's stored key;
 *   2. the transaction is then CONFIRMED with Flutterwave's own v3 verify endpoint;
 *   3. the verified tx_ref must equal the reference we generated.
 * Only then is a wallet credited.
 *
 * What changed:
 *   - the credit now goes through bc_fintech_ledger_post(), the single atomic ledger
 *     primitive, instead of chargeUser() running its own UPDATE;
 *   - the delivery is claimed in sas_webhook_events first, so a retried webhook is
 *     skipped instead of credited twice;
 *   - every refusal is logged to logs/flutterwave_webhook.log.
 *
 * Contract obeyed here: read the RAW php://input body, answer fast with JSON.
 */
header("Content-Type: application/json");
include_once(__DIR__ . "/func/bc-connect.php");
if (file_exists(__DIR__ . "/func/bc-payment-gateway-settlement.php")) {
    require_once __DIR__ . "/func/bc-payment-gateway-settlement.php";
    bc_pg_ensure_tables($connection_server);
}

$provider = 'flutterwave';
$raw = bc_fintech_webhook_raw_input();
$headers = bc_fintech_webhook_headers();

$vendor_id = (int) resolveVendorID();
if ($vendor_id <= 0) {
    // A webhook arrives on whatever host the provider was configured with, which is not
    // necessarily the vendor's website_url (a "www." prefix, another subdomain, the
    // portal domain). The checkout row for the reference being reported names the vendor
    // exactly, so fall back to it - the same rule the original handler used.
    $payload = json_decode($raw, true);
    $ref_path = bc_fintech_provider_field($provider, 'reference');
    $posted_ref = ($ref_path !== '' && is_array($payload))
        ? trim((string)(bc_fintech_array_path($payload, $ref_path) ?? ''))
        : '';
    if ($posted_ref !== '') {
        $ref_esc = mysqli_real_escape_string($connection_server, $posted_ref);
        $q = mysqli_query($connection_server, "SELECT vendor_id FROM sas_secure_payment_intents WHERE reference='$ref_esc' LIMIT 1");
        if ($q && ($r = mysqli_fetch_assoc($q))) {
            $vendor_id = (int)$r['vendor_id'];
            error_log("Flutterwave webhook: host did not resolve, vendor recovered from the intent row (vendor=$vendor_id ref=$posted_ref)");
        } else {
            $q2 = mysqli_query($connection_server, "SELECT vendor_id FROM sas_user_payment_checkouts WHERE reference='$ref_esc' LIMIT 1");
            if ($q2 && ($r2 = mysqli_fetch_assoc($q2))) {
                $vendor_id = (int)$r2['vendor_id'];
                error_log("Flutterwave webhook: host did not resolve, vendor recovered from the checkout row (vendor=$vendor_id ref=$posted_ref)");
            }
        }
    }
}
if ($vendor_id <= 0) {
    bc_fintech_webhook_log($provider, '', 'could not resolve a vendor; nothing credited');
    http_response_code(200);
    echo json_encode(["status" => "ignored", "reason" => "vendor_not_resolved", "message" => "Vendor not resolved."]);
    exit;
}

$result = bc_flw_handle_webhook($vendor_id, $raw, $headers);

http_response_code((int)$result['status']);
echo json_encode([
    "status"  => $result['ok'] ? "success" : "ignored",
    "reason"  => $result['reason'],
    "message" => $result['message'],
]);

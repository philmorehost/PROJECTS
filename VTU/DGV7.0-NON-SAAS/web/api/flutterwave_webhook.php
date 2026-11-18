<?php
/**
 * Legacy Flutterwave webhook URL — RETIRED as a credit path.
 *
 * This endpoint used to credit wallets itself, and differently from the canonical handler:
 *   - it compared the verif-hash only when the merchant had configured one, and never
 *     confirmed the transaction with Flutterwave's own API;
 *   - it had no durable idempotency claim (a plain SELECT on sas_transactions), so a
 *     retried delivery could credit twice;
 *   - it applied the gateway FEE (%) while users-flutterwave.php credits net of the
 *     Flutterwave app fee only.
 * A merchant who configured this URL from the developer docs while the admin gateway screen
 * pointed at users-flutterwave.php therefore had TWO credit paths for one provider.
 *
 * It is kept as a URL alias so existing merchant configurations keep working, but every
 * delivery now goes through exactly the same pipeline as users-flutterwave.php:
 *   signature -> durable claim -> Flutterwave v3 verification -> reference match -> ledger.
 *
 * The ONE behaviour preserved is the fee: this endpoint always deducted the gateway FEE (%)
 * before crediting, so that rule is passed through to bc_flw_handle_webhook(). Everything
 * else about the credit is now identical to the canonical path.
 *
 * Contract obeyed here: read the RAW php://input body, answer fast with JSON.
 */
header("Content-Type: application/json");
include_once(__DIR__ . "/../../func/bc-connect.php");

$provider = 'flutterwave';
$raw = bc_fintech_webhook_raw_input();
$headers = bc_fintech_webhook_headers();

$vendor_id = (int) resolveVendorID();
if ($vendor_id <= 0) {
    // A webhook arrives on whatever host the provider was configured with, which is not
    // necessarily the vendor's website_url. The checkout row for the reference being
    // reported names the vendor exactly, so fall back to it.
    $payload = json_decode($raw, true);
    $ref_path = bc_fintech_provider_field($provider, 'reference');
    $posted_ref = ($ref_path !== '' && is_array($payload))
        ? trim((string)(bc_fintech_array_path($payload, $ref_path) ?? ''))
        : '';
    if ($posted_ref !== '') {
        $ref_esc = mysqli_real_escape_string($connection_server, $posted_ref);
        $q = mysqli_query($connection_server, "SELECT vendor_id FROM sas_user_payment_checkouts WHERE reference='$ref_esc' LIMIT 1");
        if ($q && ($r = mysqli_fetch_assoc($q))) {
            $vendor_id = (int)$r['vendor_id'];
            error_log("Flutterwave webhook (legacy URL): host did not resolve, vendor recovered from the checkout row (vendor=$vendor_id ref=$posted_ref)");
        }
    }
}
if ($vendor_id <= 0) {
    bc_fintech_webhook_log($provider, '', 'could not resolve a vendor; nothing credited');
    http_response_code(200);
    echo json_encode(["status" => "ignored", "reason" => "vendor_not_resolved", "message" => "Vendor not resolved."]);
    exit;
}

// Preserve this endpoint's historical fee rule (the gateway's FEE (%)).
$merchant_fee_percent = null;
$gw_q = mysqli_query($connection_server, "SELECT percentage FROM sas_payment_gateways WHERE vendor_id='$vendor_id' AND gateway_name='flutterwave' LIMIT 1");
if ($gw_q && ($gw_row = mysqli_fetch_assoc($gw_q))) {
    $merchant_fee_percent = $gw_row['percentage'];
}

$result = bc_flw_handle_webhook($vendor_id, $raw, $headers, $merchant_fee_percent);

http_response_code((int)$result['status']);
echo json_encode([
    "status"  => $result['ok'] ? "success" : "ignored",
    "reason"  => $result['reason'],
    "message" => $result['message'],
]);

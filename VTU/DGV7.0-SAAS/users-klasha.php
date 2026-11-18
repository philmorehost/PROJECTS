<?php
/**
 * Klasha collection/payout webhook.
 *
 * DGV7 phase 3. Klasha replaces the retired Plisio for USDC/USDT collection and
 * payout, but NOTHING is live until the provider specification is supplied:
 * bc_fintech_provider_registry() leaves Klasha's base URL, endpoint paths, field
 * mapping and signature scheme EMPTY, and the handler refuses while they are.
 *
 * Contract obeyed here:
 *   - read the RAW php://input body (never a re-encoded copy) so the signature is
 *     checked over the exact bytes the provider signed;
 *   - answer fast, and always with a JSON body;
 *   - log every refusal to logs/klasha_webhook.log.
 *
 * No credit can happen without: a valid signature, a durable idempotency claim, a
 * transaction that Klasha's own API confirms, and a reference that matches the one
 * we generated. See func/bc-fintech-webhook.php.
 */
header("Content-Type: application/json");
include_once(__DIR__ . "/func/bc-connect.php");

$result = null;

// A webhook is server-to-server, so it arrives on whatever host the provider was
// configured with. Klasha's payload field names are not yet mapped, so the checkout
// fallback the Flutterwave handler uses is unavailable here: resolve from the host
// and refuse loudly rather than guess a tenant.
$vendor_id = (int) resolveVendorID();
if ($vendor_id <= 0) {
    bc_fintech_webhook_log('klasha', '', 'could not resolve a vendor for this host; nothing credited');
    http_response_code(200);
    echo json_encode(["status" => "ignored", "reason" => "vendor_not_resolved", "message" => "Vendor not resolved."]);
    exit;
}

$result = bc_klasha_handle_webhook($vendor_id, bc_fintech_webhook_raw_input(), bc_fintech_webhook_headers());

http_response_code((int)$result['status']);
echo json_encode([
    "status"  => $result['ok'] ? "success" : "ignored",
    "reason"  => $result['reason'],
    "message" => $result['message'],
]);

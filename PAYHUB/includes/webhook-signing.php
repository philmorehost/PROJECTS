<?php
/** Merchant delivery authentication only; does not authorize or settle a payment. */
function payhub_merchant_webhook_signature($body, $merchant, $isTest) {
    // Database PDO may return 0/1 as strings. Reject unknown/ambiguous modes.
    if (!in_array($isTest, [0, 1, '0', '1', false, true], true) || !is_array($merchant) || !is_string($body)) return '';
    $test = ($isTest === 1 || $isTest === '1' || $isTest === true);
    $payload = json_decode($body, true);
    if (!is_array($payload) || ($payload['event'] ?? null) !== 'charge.success' || !is_array($payload['data'] ?? null)
        || ($payload['data']['domain'] ?? null) !== ($test ? 'test' : 'live')) return '';
    $secret = $merchant[$test ? 'test_secret_key' : 'secret_key'] ?? null;
    // Never fall back to the other mode, a global key or a default shared secret.
    if (!is_string($secret) || trim($secret) === '') return '';
    return hash_hmac('sha256', $body, $secret);
}

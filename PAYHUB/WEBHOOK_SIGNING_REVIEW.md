# Merchant webhook signing consistency follow-up

## Source finding and bounded change

A follow-up to the historical VTU PayHub outage review confirmed that the generic sender and admin retry already selected test/live keys correctly. Two other paths still differed:

- `verify.php` preferred the live merchant key even for test transactions and fell back to the test key if the live key was absent.
- `webhook-paystack.php` selected the appropriate mode key but fell back to a global `payhub_webhook_secret` with default `secret` when the merchant key was missing.
- All four paths could attempt unsigned delivery when no signature was available.

All four now use `includes/webhook-signing.php`. It accepts only known database boolean/0/1 mode representations, checks charge.success payload domain against the persisted mode, and computes HMAC-SHA256 over the exact raw body using only that merchant's corresponding key. No opposite-mode/global/default fallback. Missing/invalid key, unknown mode, invalid JSON/event or mismatched domain refuses signing. The sender then skips HTTP and records the existing failed-delivery outcome with HTTP 0 and a generic signing-refused diagnosis. HTTP 0 here is a local refusal, not a merchant HTTP response. Fix the exact mode key or investigate the stored payload; do not relabel a test transaction as live to retry it.

No wallet amounts, balances, ledger writes, payload amount units, historical payloads, transaction statuses, delivery-log schema or receiver activation switches are changed. In particular, the verify-page's legacy NAIRA payload versus other senders' KOBO payload remains an explicit separate compatibility issue. This signing change must not be described as an end-to-end funding repair.

## Regression evidence

`php PAYHUB/tests/webhook_signing_test.php` covers mode/key isolation, raw-byte signatures, invalid/missing keys and payloads, shared-secret refusal and the actual four sender refusal blocks with a network-init trap. 89 fixture-only checks pass locally in PHP-wasm; all five changed PHP implementation files parse there. A dedicated native PHP 8.2/8.3 CI workflow runs parser and regression checks. No test contacts a provider, database or merchant.

This is not full endpoint/MySQL/provider integration. Existing curl/TLS behaviour, sender transaction boundaries, delivery durability/retries, historical amount compatibility and receiver gates require separate validation. Missing-key and domain-mismatch deliveries previously sent unsigned or with a fallback key now fail deliberately rather than appearing delivered. Deploy the new helper with/before the updated shared functions and sender files, never omit it from update packages. No migration is needed for this change.

## Financial and evidence boundaries

No live deployment/provider activation, historical replay, cleanup or credit was performed. Preserve existing webhook/payment/ledger evidence. Keep existing fintech holds and the deposit rollout gate until paired-schema, independent verification and explicit operational acceptance are complete. Bybit staging remains cancelled and is not part of this work.

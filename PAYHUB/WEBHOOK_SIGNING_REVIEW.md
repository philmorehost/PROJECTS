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

## Next increment: additive amount-unit contract

Newly generated payloads from the generic sender, Paystack forwarding and browser verify-page now declare `money_contract_version: 1`, `amount_unit` (`major`/`minor`) and `amount_basis` (`requested`/`gateway`) inside `data`. Existing amount values and types are unchanged:

| Sender | Legacy `amount` | New declaration |
| --- | --- | --- |
| Generic | Integer kobo; gross paid when known, otherwise its existing requested fallback | `minor`, `gateway` when known; `requested` for fallback |
| Paystack forwarding | Gross paid integer kobo | `minor`, `gateway` |
| Browser verify | Requested naira, preserving the stored value/type | `major`, `requested` |

Browser verify now also includes the stored currency. Its `amount_kobo` requested-value alias remains. The common requested/gateway/fee/settled fields retain their existing kobo conversion and values. This does NOT repair or endorse generic historical rows whose gateway_amount is actually the existing requested fallback: the label explicitly identifies the lack of a known paid amount. Recipients must independently verify payment rather than treat the labels or the requested value as payment evidence. No currency conversion, rounding policy or wallet accounting is changed.

The API reference now explains sender differences, the fallback caveat, raw-byte mode-specific verification, common minor-unit fields and independent verification/idempotency requirements. Verification API semantics are unchanged. Original stored retry bodies are not relabelled or re-encoded. Old unlabelled payloads remain signable under the previous mode/domain rules; recipients must not infer units from magnitude or blindly replay historical events.

`webhook_money_contract_test.php` evaluates the actual three shipped payload-builder expressions (not hand-copied alternatives) with isolated fixtures and the shipped conversion function: tiny/fractional/large requested amounts, test/live modes, known/missing generic paid amount, upstream label override resistance, exact legacy value/type preservation, common kobo fields, currency, signature coverage and historical retry-byte preservation. 181 local PHP-wasm checks pass, with no database/network/provider. Native PHP 8.2/8.3 CI now runs both suites. This increment improves the migration contract; a breaking unification of legacy amount or replacement of delivery durability/transaction boundaries is still not implemented or authorized for activation.

## Delivery durability prerequisite: compatible log schema

The runtime `ensure_critical_tables()` fallback previously created the obsolete `(user_id, event_type, response_code)` log shape. Current senders/admin use `(transaction_id, status, attempt_count, last_attempt_at, payload, response)`. `CREATE TABLE IF NOT EXISTS` cannot repair an existing legacy table, and swallowed logging failures can leave a delivered webhook with no usable delivery record. This is a source-confirmed compatibility gap, not proof of any specific live installation's schema.

The runtime fallback now creates the current column contract for a missing table, including a transaction lookup index. Existing installations require the explicit operator migration `install/20261118_webhook_log_compat.sql`. It is additive and rerunnable: it creates a missing table, adds only absent columns and a lookup index, and does not rewrite existing definitions or records. It does not run from a web request, bump the general runtime schema marker or alter wallet/transaction tables. There is no automatically applied schema migration here.

Back up and review `SHOW CREATE TABLE webhook_logs` first; select the correct database and run outside application transactions in a maintenance window because MySQL DDL implicitly commits and acquires metadata locks. Rehearse on a sanitized clone. Unknown legacy transaction IDs, status, attempt counts, last-attempt timestamps and responses stay NULL rather than inventing a link or an outcome. Original payloads, response_code, user_id, event_type and timestamps are preserved. Existing conflicting column definitions/constraints or custom index names require operator review rather than a destructive ALTER. Rerunning is safe for supported shapes but concurrent migration runners are not supported. No rows are deleted and no foreign keys/engines are modified.

The existing admin inner join still intentionally excludes records without an authoritative transaction link; these preserved legacy rows must be examined with restricted read-only database tooling and reconciled independently. Do not infer links from metadata/email or replay old rows to manufacture delivery records. Existing installer foreign-key deletion behaviour is not changed by this patch.

`webhook_log_schema_mysql_test.php` runs only with a disposable-CI guard and creates/drops its own randomly named database. It exercises the actual shipped runtime and installer DDL plus the operator migration against legacy, current, runtime-created and missing schemas; reruns migration; preserves historical columns/bytes; and verifies current sender inserts and admin-style retry joins/updates. This is schema compatibility evidence, not provider/network/crash recovery validation. The separate native MySQL CI job supplies its own disposable database; the local PHP-wasm environment is used only for parsing this MySQL test, not claimed as MySQL execution.

Still remaining: persist-before-send/outbox semantics, serialized retry claims, ambiguous HTTP outcome handling and crash recovery. Current senders still log after HTTP and some perform outbound delivery inside accounting transactions. This schema fix does not make delivery exactly-once, durable across those crash windows, or safe for blind automatic retries. No automatic retry worker or financial activation is enabled.

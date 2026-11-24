# PayHub virtual-account deposits: LEGACY_SETTLEMENT_HOLD investigation

## Finding

The supplied PayHub retry response is an intentional DataGifting application hold, not an IP-blocking response:

```
HTTP 503
{"status":"pending","code":"LEGACY_SETTLEMENT_HOLD", ...}
```

Source-confirmed receivers:

- `DGV7.0-SAAS/users-payhub.php`
- `DGV7.0-SAAS/vendors-payhub.php`
- `DGV7.0-NON-SAAS/users-payhub.php`

Each immediately sets HTTP 503, emits the exact JSON message reported by the operator, and stops at end of file. There is no request authentication, payload parsing, database connection, durable webhook inbox, wallet credit or refund in these files. The response does not mean the payment was rejected by the bank or refunded. A pending JSON status here is not proof of a local pending-payment database record.

These stubs were introduced by commit `c12ed90336dc28b7bdb8c00cfe3cf7bbd954e6ec`, **Harden incident-affected financial paths and document remaining release gates**. See `SECURITY_REMEDIATION_STATUS.md`, Intentional financial restrictions. This is a containment restriction without a completed replacement PayHub webhook settlement workflow. It explains this funding outage; it is not merely a recommendation to change a firewall setting.

## Trace through PAYHUB

1. `PAYHUB/webhook-paystack.php` validates the upstream signature and optionally upstream IP. Its IP refusal is **403 / Unauthorized IP address**, unlike the reported response.
2. For a dedicated-account transfer, it resolves a `virtual_accounts` record, recovers its metadata, creates/fetches a transaction, applies upstream amount/mode checks, and attempts local PayHub settlement.
3. On successful local fulfillment, it commits PayHub's transaction before attempting the merchant webhook. Thus a merchant notification failure can coexist with successful PayHub-side receipt/accounting. This is consistent with the report, not independent confirmation of the individual payment.
4. The merchant payload is `charge.success`, with reference, amount fields in minor units (kobo), domain, and metadata. It is signed using `X-Payhub-Signature` (HMAC-SHA256 of raw JSON).
5. `PAYHUB/admin/webhooks.php` retries a stored payload only for a locally successful transaction with a matching reference. It POSTs to the merchant's configured URL and records delivery success only for HTTP 2xx. A 503 correctly remains failed.
6. The retry error banner keeps only the first **180 characters** of the response. This explains the cut-off sentence in the supplied log; it is not evidence of payload truncation in transit.

There are multiple PayHub delivery implementations. The dedicated-account forwarding path selects the signing key according to test/live mode, while the generic sender and admin retry prefer the live secret, falling back to the test secret. This is a separate compatibility/security review item before reopening; it is NOT the cause of this unconditional DataGifting 503.

## What is established, and what is not

- The supplied response matches the checked-in held receiver exactly. For that request, an application endpoint returned a response; an IP allowlist cannot fix that unconditional response.
- The repository cannot establish the deployed merchant URL, installed source revision, bank settlement, customer mapping or current wallet balance. No production database, authenticated provider account or live endpoint was accessed for this investigation.
- Other payments may have additional failures. The report does not prove all PayHub transfers settled successfully, or that there are no independent network issues.
- Repeated retries against these unchanged receivers cannot credit a wallet. Returning 200 instead would only hide failed fulfillment; it would not credit anything.

## Immediate operator actions

1. Preserve PayHub transactions, upstream bank/provider evidence, virtual-account ownership records, webhook payloads, delivery logs and DataGifting wallet/history/mutation records in access-controlled storage. Do not paste secrets, complete bank/customer records or raw production dumps into chat.
2. Temporarily prevent new use/presentation of affected PayHub virtual accounts or clearly mark automatic crediting unavailable through the approved operational controls. Existing standing-account transfers may still arrive; hiding a button alone is not sufficient containment. Do not disable evidence collection or imply the bank account itself has stopped accepting transfers.
3. For each affected payment, reconcile the exact PayHub reference, live/test mode, receiving merchant and virtual account, tenant and customer ownership, gross/fee/net amount and currency. Compare against all existing DataGifting credit, refund and mutation evidence to detect previous/manual credits or refunds.
4. Use an approved, audited reconciliation process for confirmed obligations, with an independent review and duplicate-credit protection. This investigation does not execute credits or supply a direct SQL balance-update workaround.
5. Keep the failed notifications and original evidence. Do not mass-retry, recreate payment intents retroactively, restore the old receiver, bypass with browser callbacks, or change HTTP 503 to 200 as a repair.

## Requirements for safe restoration

A scoped replacement must authenticate the raw notification with the correct tenant/mode key, independently verify the payment against the expected PayHub merchant, require explicit live success and exact reference/currency/amount semantics, and bind the beneficiary to trusted virtual-account provisioning records rather than posted metadata or email heuristics. Standing virtual accounts need a reviewed deposit-binding model; a one-off checkout intent cannot simply be fabricated after the payment.

Settlement must use exact integer minor units, documented gross/fee/net rules, globally unique provider payment claims, an atomic ledger/balance/history transaction, and replay/concurrency/conflict handling. Only acknowledge successful settlement (or a proven identical prior settlement), or durable acceptance into a reviewed inbox with explicit held status. Preserve uncertain outcomes for reconciliation rather than resending money operations.

Test valid live transfers, forged signatures, test/live mismatch, wrong merchant/tenant/beneficiary, missing ownership, changed payloads, duplicate delivery, alternate delivery IDs for one payment, concurrent workers and crash/rollback/retry behavior on native MySQL before controlled rollout. Withdrawal and unrelated financial holds must remain intact.

## Offline reproduction

```
node VTU/tests/harness/run-test.mjs tests/payhub_settlement_hold_test.php
```

This executes the three held receiver files without DB/network and confirms their deliberate 503/no-settlement behavior. The PayHub sender trace above was inspected separately in source. It is a diagnosis regression, not payment-provider or production acceptance. No runtime financial code, allowlist, response status, balance, provider configuration or deployment is changed.

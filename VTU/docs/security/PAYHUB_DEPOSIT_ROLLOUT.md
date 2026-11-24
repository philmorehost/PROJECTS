# PayHub dedicated-account crediting replacement

## Status and scope

Source implementation; **not deployed or enabled by this work**. Runtime entry points are the existing SAAS user/vendor PayHub receiver URLs and NON-SAAS user URL. All call a minimal POST-only bootstrap with no sessions, host-based tenant selection, legacy callback or request-time DataGifting migration.

The existing unconditional `LEGACY_SETTLEMENT_HOLD` is replaced with a narrowly scoped, rollout-gated dedicated-account workflow. Until `BC_PAYHUB_DEPOSITS_ENABLED=1` is set in the PHP process environment after acceptance, POST returns `503 PAYHUB_DEPOSITS_ROLLOUT_REQUIRED`. Returning 503 is intentional until dependencies are in place; deploying files alone does not restore production credits. No unrelated withdrawal, checkout, card or crypto hold is released. Bybit staging remains cancelled.

## Trust and accounting model

1. Parse a bounded `charge.success` notification. Account/reference values are routing hints, not payment or beneficiary evidence.
2. Require exactly one active PayHub bank record across `sas_user_banks` and `sas_vendor_banks`. Reject duplicates (including inactive duplicates), ambiguous ownership and absent records. Resolve the beneficiary from this stored binding, never posted usernames/emails/vendor IDs or request host.
3. Require exactly one enabled, exact-name PayHub gateway configuration in the correct scope. Customer deposits use that vendor's live key; vendor deposits use the platform live key. There is no platform fallback for customer deposits. Duplicated configurations must be reviewed rather than selected arbitrarily.
4. Authenticate raw bytes with `X-Payhub-Signature` HMAC-SHA256. Test keys, empty signatures and mismatches cannot authorize a credit.
5. Independently GET the PayHub verification API over certificate-verified HTTPS with no redirects, short timeouts and a bounded response. This increment supports only the audited `https://merchant.payhub.com.ng/` host. Custom/self-hosted PayHub domains require a separately reviewed allowlist/transport change, not weakening URL/TLS checks.
6. Require explicit paid/success/live/NGN evidence, identical reference and server-owned receiving account, and deposit evidence version 1. Amounts are bounded integer kobo; gross must equal fee + positive net. **Credit verified provider net**, not an amount claimed by the notification. No naira/kobo guessing or additional legacy configured percentage deduction is applied. Confirm this provider-net fee policy before activation; a different commercial policy requires a reviewed, exact-minor-unit implementation, not silently re-enabling old calculations.
7. Under one InnoDB transaction, claim the globally unique PayHub payment reference, re-lock/revalidate the bank binding, key configuration and active/unblocked tenant/recipient, check legacy receipts/mutation events, and write balance + history + wallet mutation event + credited claim atomically. No provider call occurs while DB locks are held. Existing outer transactions and non-InnoDB tables are refused.
8. Return 200 only for committed credit or an identical credited claim. Duplicate delivery IDs for the same provider payment cannot create another credit. Changed amount, binding or merchant credentials produce a conflict/review response, not another credit. Deadlock/storage/commit uncertainty remains non-2xx; retry can recover the same unique claim. Credential rotation may require reconciliation for historical acknowledgments; it does not permit a second credit.

The deprecated `processPayhubSuccess` returns false in both editions so browser callbacks and older code cannot bypass the new atomic receiver. Hosted checkout funding is NOT restored by this dedicated-account repair.

## PayHub changes and historical payments

`transactions.receiving_account_number` is a new nullable, server-owned field. Only a new dedicated-account transaction created from a matched Paystack-signed transfer and a unique stored PayHub virtual account gets this field. Checkout initialize metadata cannot populate it. Initial notification, generic notification and authenticated verification expose this field plus `deposit_evidence_version=1`. Verification now returns the actual stored currency rather than hardcoding NGN. Generic and retry senders select signing keys according to transaction test/live mode.

PayHub's existing `ensure_payment_schema()` includes the new column check outside settlement transactions, but an explicit reviewed schema migration BEFORE code rollout is preferred. Do not rely on live webhook DDL permissions as the rollout plan.

**Old received payments are not automatically backfilled or credited.** Existing references without versioned account evidence remain review-required, even on retry. Preserve bank/PayHub settlement exports, original payloads, virtual-account ownership, DataGifting histories and mutation evidence. Resolve historical obligations with independently reviewed, audited, duplicate-protected reconciliation. Do not set the new evidence column by guessing from email or merchant metadata, create synthetic intents, or issue direct SQL balance increments. The receiver does not persist untrusted notifications as financial evidence; preserve the PayHub delivery logs while held.

## Controlled rollout checklist (operator actions, not executed here)

1. Preserve backups/logs/evidence and identify unpaid customer obligations. Prevent new use of affected funding instructions or clearly mark automatic crediting unavailable, accounting for transfers that can still arrive to standing accounts.
2. Validate production schema parity on a sanitized copy. All tables named in `bc_ph_schema` must be InnoDB, and the new claims table must retain its provider-reference primary key. No runtime conversion/deletion of existing financial tables is performed.
3. Apply `PAYHUB/install/20261118_deposit_evidence.sql` once in PayHub after checking the column, then deploy matching PayHub capture/forward/verify changes. No historical backfill.
4. Apply `VTU/migrations/20261118_payhub_deposits.sql` to each relevant DataGifting database using the migration process. Review stored bank-account ownership and unique enabled live merchant configurations; review net-credit policy and actual provider host. Keep the environment flag off.
5. Run native MySQL tests for both editions and controlled end-to-end signed test fixtures on isolated staging. Test upstream DVA capture and verification too: the DataGifting MySQL tests intentionally mock provider verification and are NOT real-bank/provider acceptance. Test old unversioned references, signature failures, test/live isolation, non-NGN payments, wrong tenant, duplicates/concurrency, changed evidence, rollback and lost responses.
6. With operator approval and verified deployment artifacts, enable `BC_PAYHUB_DEPOSITS_ENABLED=1` for the scoped PHP installation, restart/reload the PHP worker as required, and use an authorized controlled live acceptance payment. Confirm matching PayHub bank receipt, DataGifting claim/history/event, exactly one balance delta and successful retry acknowledgment. Monitor logs and pending exceptions. Source CI success is not permission to move real money.
7. Reconcile already-paid users separately. Re-enable presentation of funding instructions only after the actual end-to-end acceptance passes. Leave unrelated financial holds untouched.

Rollback: disable the environment flag first; preserve the new claims/history/mutation evidence and PayHub receiving-account field. Never delete those records or restore the old browser/legacy writer. Pausing the receiver does not refund or reverse committed credits.

## Validation

```
node VTU/tests/harness/run-test.mjs tests/payhub_deposits_test.php
node VTU/tests/harness/run-test.mjs tests/payhub_settlement_hold_test.php
php PAYHUB/tests/payhub_dva_reconcile_contract_test.php
BC_PAYHUB_DEPOSITS_CI=1 BC_TEST_EDITION=SAAS php VTU/tests/payhub_deposits_mysql_test.php
BC_PAYHUB_DEPOSITS_CI=1 BC_TEST_EDITION=NON-SAAS php VTU/tests/payhub_deposits_mysql_test.php
PAYHUB_PAIRED_CI=1 BC_TEST_EDITION=SAAS php VTU/tests/payhub_paired_stack_mysql_test.php
PAYHUB_PAIRED_CI=1 BC_TEST_EDITION=NON-SAAS php VTU/tests/payhub_paired_stack_mysql_test.php
```

Native tests create/drop uniquely named disposable databases only and use mocked/local verification callbacks, including concurrent independent workers and injected receipt failure. Never run the CI fixture against production credentials/databases. Paired PayHub -> VTU fixtures verify outbox/signing/claim/verify contract alignment across both SAAS and NON-SAAS receivers, not real public HTTPS/TLS/DNS or live bank settlement.

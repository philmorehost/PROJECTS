# Controlled Bybit staging verification — no funds activation

## Scope and status

This is an operator checklist, not completed verification or permission to deploy,
create keys/accounts, send money or enable services. Local synthetic/native tests
do not prove provider eligibility, actual customer allocation or ingress security.
Production polling/delivery, customer address publication, credits, withdrawals
and trading remain off. Preserve existing financial holds and containment.

## Local preflight (implemented)

On the platform Custody page, use the existing protected evidence viewer to open
exact relationship evidence, bound-address evidence and the independently reviewed
candidate manifest. Run **Local reviewed-route preflight** with exact custody and
candidate revisions. The action requires POST, CSRF and fresh platform step-up,
with a sanitized audit recorded before the check. No provider request occurs.

The report rechecks reviewed/unexpired candidate and binding, independent actors,
attested address evidence, exact immutable customer mapping, current child/parent
registry/configuration/KYC/custody/tenant context, policies/catalogue and global
exact-route conflicts. Missing data or a failed gate refuses a passing report.
The report contains no address/memo, raw UID, key, IP or custody fingerprint.
`local_checks_passed` is a point-in-time result, NOT a persisted authorization,
atomic future-use guarantee or a live readiness verdict. Later uses must recheck
all gates. `live_provider_verified`, `operational_readiness_verified`,
`customer_funding_enabled`, `credit_posting_enabled` and `live_enabled` stay false.

## Operator prerequisites — verification outstanding

- Explicitly approve a dedicated staging environment and an eligible provider
  account model under applicable provider terms/region restrictions. Confirm that
  Bybit's sub-address endpoint supports the actual account type (custodial
  subaccounts are excluded by its documented contract).
- Keep funding/withdrawal/trading and automatic credits disabled. Use a designated
  eligible staging customer and server-configured personal IP-bound read-only
  master/subaccount keys; do not broaden permissions to bypass a failed check.
- Confirm the alert receiver is disabled when not explicitly under test. Runtime
  body/header/rate/slow-client enforcement and secret isolation remain independent
  outstanding operational gates; do not infer enforcement from installed config.
- Arrange private operator access, key isolation, backups, audit retention,
  on-call ownership and rollback/stop procedure. Do not paste secrets, addresses,
  raw UIDs or protected manifests into chat or public issue logs.

## Read-only staging sequence — not executed by this change

1. Record environment, registry revisions, selected configuration IDs and operator
   approval reference privately. Confirm source vendors/customer are active and
   current customer KYC and custody/tenant/policy reviews exist.
2. Run protected parent comparison and inspect the encrypted result. Independently
   verify actual parent/subaccount identity against the provider account console.
   A provider-reported relationship is not proof of legal/customer ownership.
3. Propose/renew the immutable customer binding, then have another authenticated
   reviewer open its exact evidence and independently review the customer mapping.
4. Run the protected bound-subaccount address inspection. Failure, unsupported
   model, stale evidence, changed identity/configuration or rate limiting must
   stop the attempt with no retry/fallback address and no successful artifact.
5. Compare the exact asset, canonical network, address and memo privately with
   provider-authenticated account information. A truncated token contract suffix,
   address checksum or master inventory is insufficient allocation proof.
6. Propose from bound-address evidence and independently open/review the resulting
   manifest. Verify global exact-route conflict rejection and expiry/revocation in
   disposable staging fixtures only—never mutate production financial records.
7. Run local preflight; record the result, timestamp, revision context and all
   independent provider/operational results separately. Do not label a local pass
   as provider verification. Revoke/stop staging review artifacts when complete.

No sending of funds is part of this checklist. A separately approved pilot still
requires customer allocation/exclusivity assurance, real ingestion/retry/crash and
reversal checks, risk/finality review, balanced idempotent credits, reconciliation,
operational ingress hardening and explicit authorization before live funding.

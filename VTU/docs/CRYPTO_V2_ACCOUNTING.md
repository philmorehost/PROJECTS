# Crypto V2 accounting implementation — production foundation, not activation

## Release status

Implemented in both editions as an isolated accounting foundation. NOT connected to legacy wallets, customer HTTP routes, Bybit trade/withdrawal submission or native clients. No historical balances imported; no holds released; no credentials configured or production deployment performed.

This is a subset of Phase 2 of `CRYPTOHUB_BYBIT_BLUEPRINT.md`, not completion of the entire custody/ledger design. Local asset-approval governance is now implemented separately; see `CRYPTO_ASSET_GOVERNANCE.md`. Production still requires verified MySQL behavior, reviewed migrations, customer/provider account attribution, balanced opening entries, transaction workflow integration, withdrawal state/outbox controls, provider reconciliation, permission-isolated finance database access, and release authorization.

## Added modules

- `func/bc-crypto-decimal.php`: exact non-negative decimal-string/integer-unit arithmetic.
- `func/bc-crypto-reservations.php`: transactional reserve/full-release API for already provisioned V2 customer bucket accounts.
- `func/bc-crypto-v2-schema.php`: four additive, default-closed InnoDB accounting tables (the CLI installer additionally includes three governance tables). Never included in web bootstrap.
- `func/bc-crypto-v2-reconcile.php`: tenant/environment-scoped, read-only, consistent-snapshot journal integrity checks.
- `func/crypto-v2-manage.php`: CLI-only schema preview, explicitly approved schema installation and internal integrity checks.

## Amount contract

All amounts are canonical strings. Floats, native-number monetary inputs, whitespace, signs, exponent notation, NaN, leading zeros and excess decimal places are rejected. No implicit truncation, rounding or locale conversion. Scale is an integer 0–30; units contain at most 60 decimal digits. These are deliberate supported bounds, below database DECIMAL(65,0) capacity, so arithmetic overflows are caught before database writes. Asset/network account scales must match and cannot be silently changed when provider display precision changes.

Examples:

```php
$units = bc_crypto_to_units('12.345678', 6); // '12345678'
$amount = bc_crypto_from_units('1', 18);    // '0.000000000000000001'
$remaining = bc_crypto_subtract('100', '70'); // '30'; never negative
$fee = bc_crypto_fee_bps('101', 100);       // '2': 1%, rounded UP to one unit
```

Fee helper supports integer basis points 0–10000, not arbitrary Bybit fee fractions. Do not round/truncate provider fee precision into basis points. A separate exact rational/decimal fee mapping is required before quote/execution integration. Provider fees and platform fees must remain separately disclosed.

## Data model

- **asset_policies**: tenant + environment + asset/network; immutable account scale expectation, explicit enable/withdrawal flags, expiry, reconciliation status and per-reservation cap. New rows default disabled/blocked. No automatic approvals or imported Bybit discovery settings.
- **accounts**: customer-owned available/reserved/pending/held buckets, scoped by tenant + user + environment + asset/network; exact unit projection. Fresh accounts must begin at zero.
- **operations**: reserve/release intent, units, stable reference, payload fingerprint and optional parent reservation; unique tenant/environment/reference and unique release parent.
- **entries**: one debit and one credit of the same units, committed with projections and operation. Unique operation/account entry and foreign keys.

There is deliberately no general-purpose deposit-credit/funding API. There are no treasury/system contra-accounts, opening-balance import, settlement postings or account-provisioning endpoint in this stage. Those must be designed and independently reviewed before real balances enter V2. Never seed production accounts directly with a nonzero balance to make this module appear ready; the integrity checker detects projections without journal backing. Test doubles use synthetic balances only to exercise reservation behavior.

Historical `sas_users.balance`, `sas_user_crypto_wallets`, old ledger tables and provider selection are untouched. Asset/network buckets remain separate; cross-network or cross-asset conversion is not a bucket transfer.

## Transaction invariants

Internal API (not an HTTP API):

```php
$scope = ['vendor_id'=>7, 'user_id'=>9,
          'environment'=>'testnet', 'asset_network'=>'USDT:TRX'];
// Assumes trusted upstream authentication, authorization, fresh step-up and provisioned V2 accounts.
bc_crypto_v2_reservation($scope, 'withdrawal-reservation-001', '1000000');
bc_crypto_v2_reservation($scope, 'withdrawal-cancel-001', '1000000', 'withdrawal-reservation-001');
```

- Refuses nested transactions/non-autocommit connections, preventing accidental commit of an outer transaction.
- Locks the actual tenant/user row before policy and bucket operations. Same-customer operations serialize; available then reserved account lock order is stable.
- New reservations require active, unblocked, KYC-approved user, no forced password change; explicit enabled/withdrawal-approved, unexpired, matched-reconciliation policy; correct scale and cap. Missing policy/accounts fail closed.
- Upstream must additionally enforce session/TOTP enrolment, replay-protected transaction-specific step-up, platform/vendor kill switches, all existing holds and regulatory/beneficiary rules. The internal accounting function is NOT a substitute for those checks. It is intentionally not wired into live routes until that orchestration exists.
- Identical reference + payload returns prior committed result without repeating writes. Same reference with changed user/asset/network/amount/kind/parent is rejected.
- Reservations move units available → reserved; releases reverse exactly the original full reservation. No partial refunds or arbitrary reserved/held releases. Unique parent release constraint and row locks prevent double refund with a different reference.
- Release is allowed after account/policy restriction solely to reverse an unsubmitted local reservation. Before any provider worker is added, this path MUST be extended with a locked submission-state guard so submitted/unknown/completed withdrawals can never be refunded by this API. Currently no provider submission path exists.
- Operation, both balance projections and both entries commit together. Any error rolls back. A commit/network ambiguity must be recovered using the SAME reference; never generate a new reference and assume the first failed.
- No external HTTP inside a database transaction. No schema creation on reservation or public page loads. Database errors do not authorize another route/provider.

Journal append-only behavior is enforced by the application API (no operation/entry update/delete functions). Database-level immutability/tamper resistance still requires separate restricted finance credentials and off-host audit exports. A privileged database compromise is not prevented by PHP functions alone.

## Internal integrity vs external reconciliation

`bc_crypto_v2_integrity(vendor, environment)` uses a read-only REPEATABLE READ snapshot and reports counts for:

1. Account projection differs from signed journal sum.
2. Operation lacks exactly two equal-sized opposing entries.
3. Paired accounts use inconsistent scales.
4. Entry crosses tenant/customer/environment/asset/network or uses a wrong reserve/release bucket.
5. Release does not match its original reservation.

Returns `provider_reserves_verified: false` even for an internally consistent or empty database. Does NOT mark policies matched or enable anything. It does not query Bybit, prove reserves, validate legal custody or replace per-asset liabilities vs provider holdings reconciliation. Full-table aggregation is an explicit operator task, not web-page work; monitor cost on large tenants and plan indexed incremental/offline reconciliation before scale.

## Operator migration workflow

Run with native PHP CLI and MySQL/MariaDB support. The application database must use InnoDB and support transactions, row locks, foreign keys, exact DECIMAL and REPEATABLE READ read-only snapshots. Live SQL compatibility was not verified in the coding environment.

1. Review schema, back up database and verify restore in staging.
2. Preview (does not load database/application bootstrap):
   `php func/crypto-v2-manage.php schema`
3. On reviewed staging deployment only, inject `BC_CRYPTO_V2_SCHEMA_APPROVED=1` and run:
   `php func/crypto-v2-manage.php install --apply`
4. Inspect actual SHOW CREATE TABLE output, indexes and foreign keys. CREATE TABLE IF NOT EXISTS does not repair an incompatible pre-existing table. Installation can partially complete if a later DDL fails; rerun only after inspecting the failed step. MySQL DDL is not transactionally rolled back.
5. Internal check:
   `php func/crypto-v2-manage.php check 7 testnet`
6. Do NOT populate policies as enabled, import balances or enable live submissions merely because empty tables pass checks. No migration approval flag enables trading.

No DROP/TRUNCATE/delete migration or destructive rollback supplied. Removing unused code does not reverse settled financial transactions; later releases require a forward-recovery plan. Preserve historical records.

## Validation completed

- 2,728 exact arithmetic checks: independent Python arbitrary-precision expected vectors, fees, limits, parse/format, dust and overflow.
- 54 transactional reservation checks using a rollback-capable storage double: exact movement, duplicate/conflicting reference, release-once, blocked/KYC/password restrictions, policy expiry/limits, scope mismatches, each write failure, commit failure, insufficient funds and nested transaction refusal.
- 37 schema/source checks: additive/default-closed tables, transactional engine and uniqueness, CLI gating, no web DDL/provider/legacy-balance side effects, edition parity and PHP parsing.
- 9 consistent-snapshot reconciliation checks using a query double: each mismatch, query failure and no false provider-reserve claim.

These do NOT establish native MySQL locking, isolation, deadlock handling, FK/DECIMAL semantics, throughput, live custody or provider behavior. Those checks are mandatory before activation. No PHP/MySQL native server toolchain was available here.

## Remaining production gates (not waived by general authorization)

- Real database concurrency, rollback/fault injection and restore tests; independent ledger review.
- Integrate the implemented asset-governance constraints into future deposit/quote/submission workflows; actual beneficiary cooldown/confirmation processing and provider submission-time stops remain required.
- Approved customer deposit attribution; explicit nonzero opening migration with balanced system accounts and signed reconciliation.
- Deposit records polling, idempotent external-event claims, confirmation/risk handling and exact liabilities/reserves matching.
- Transaction-specific authorization and cooldown/beneficiary checks; signed-state withdrawal outbox; stable provider request IDs and unknown-outcome reconciliation.
- Isolated restricted signing/runtime database credentials, production key verification, monitoring/incident drills and tiny-value controlled end-to-end pilot.
- Source/UI/native client rollout and explicit authorization to release relevant withdrawal holds only after verification.

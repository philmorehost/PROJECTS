# Crypto V2 deposit observation foundation

## Implemented boundary

Both editions now contain `func/bc-crypto-deposits.php` and three additive observation tables. This is an **internal, normalized record intake and exception-queue data foundation**, not a live deposit integration or a completed customer funding workflow.

There is no HTTP endpoint, webhook receiver, address allocator, poller, route-approval UI, customer balance display or automatic settlement worker. Normal page loads do not include this code or create tables. No provider credentials/permissions are changed. Nothing writes accounting accounts, operations, entries, historical wallets or existing holds. The return value always has `settlement_enabled=false`.

`review_ready` means only that the last normalized observation passed local checks and can be reviewed. It is **not** verified provider settlement, a liability, a spendable balance, a funding receipt or authorization for a later worker to credit funds. A stale label must never authorize money movement. Actual settlement must reverify the provider, ownership, account/asset policies, risk, chain finality and external reconciliation inside its own approved accounting workflow.

## Additive schema and migration

`crypto-v2-manage.php schema` now previews **fourteen** additive production tables: four accounting, three governance, three deposit-observation, two custody-registry and two protected-evidence tables (see `CRYPTO_CUSTODY_REGISTRY.md`):

- `sas_crypto_v2_deposit_routes`: exclusive environment/custody-account/asset-network/routing-hash attribution. Disabled and expired by default. Stores user/vendor, scale, evidence digest and explicit approval expiry. No route provisioning function is supplied in this stage.
- `sas_crypto_v2_deposits`: immutable original vendor, asset, units/scale and economic fingerprint; bound original route/customer where approved; latest confirmation high-water mark, state/reason, revision and observation fingerprint.
- `sas_crypto_v2_deposit_events`: append-only application-level history per revision, with normalized observation document, evidence/observation digests, before/after states and reason. Inserted in the same transaction as the observation change.

After backup/restore validation, schema review and operator authorization, the existing explicit CLI installer can be run again:

```text
php func/crypto-v2-manage.php schema
BC_CRYPTO_V2_SCHEMA_APPROVED=1 php func/crypto-v2-manage.php install --apply
```

CREATE TABLE IF NOT EXISTS does not repair incompatible definitions. There is no application/staging/production migration or deployment by the agent. Disposable CI creates and drops only random test databases. Forward recovery and retention planning are required; do not delete historical deposit evidence as a financial rollback.

## Routing and identity

The future trusted worker supplies server-side context: positive integer vendor ID, exact `testnet`/`mainnet`, and a canonical 64-lowercase-hex custody-account fingerprint. All integrations referring to the same physical Bybit account must use the **same** fingerprint across tenants; it must not be a tenant-chosen alias. An internal local-review registry is now implemented (see `CRYPTO_CUSTODY_REGISTRY.md`); intake rechecks its expiring account and tenant-binding reviews. The SAAS authenticated dual-review management/evidence workflow is documented in `CRYPTO_CUSTODY_MANAGEMENT.md`; provider-verified UID/custody model and standalone independent-operator provisioning remain unimplemented.

`bc_crypto_deposit_route_hash(asset_network, address, tag)` binds exact network, case-sensitive address and memo/tag. Empty tag and `0` are different; missing/null tag is rejected. No trimming, case-folding or chain-specific normalization is assumed. Any provider/network requiring other characters or normalization needs its own reviewed adapter first; the initial ASCII validation is intentionally narrow. Do not assume a shared master address proves customer ownership.

A route may belong to only one customer/tenant per environment, custody account and exact network/address/tag hash. An enabled, unexpired route and nonempty-format attribution evidence hash are required, but hashes are only references to evidence, **not proof that evidence is genuine**. No production routes should be manually seeded as a substitute for the missing audited approval workflow.

External deduplication uses `(environment, custody_account, provider_record_id)` across vendors. A cross-vendor replay is refused, not reassigned. The future adapter must demonstrate a stable unique provider record identity (including transfer/output identity where necessary); a transaction hash alone cannot generically identify every deposit. It must also canonicalize aliases and prevent a provider event from being re-imported under different IDs. That adapter is not implemented or verified here.

Original economics bind network, scale, exact integer units, routing hash and transaction-identity digest. Reusing a record with conflicting economics quarantines without overwriting the original amount, network, customer or route. Original ownership is never moved by later imports.

## Normalized observation contract

`bc_crypto_deposit_observe($context, $input)` is for internal callers only. Never expose it to browser data, unverified callbacks or client-supplied ownership context. It accepts:

- `record_id`: stable printable ASCII provider-record identifier, at most 128 bytes.
- `asset_network`: exact approved coin/network key.
- `scale`: integer 0–30; `amount`: canonical positive decimal **string**, never a float.
- `address`, `tag`, `txid`: bounded exact ASCII strings; tag must explicitly be a string, including empty when appropriate.
- `confirmations`: canonical nonnegative integer string, bounded at 1,000,000,000.
- `status`: normalized `pending`, `complete`, `failed` or `reorged`.
- `risk`: normalized `unknown`, `clear` or `blocked`.
- `evidence_hash`: SHA-256 digest referencing protected source evidence.

No mapping from actual Bybit deposit statuses, signatures, pagination, cursor checkpoints or risk results is claimed. A caller asserting `complete` or `clear` is not authenticated provider proof. Full source evidence needs protected storage, access controls and retention outside these tables. Only sanitized normalized documents and hashes are persisted here; never put secrets in record identifiers. SQL evidence and audit storage still need encryption/access controls and off-host tamper-resistant export.

## State and safety rules

- Invalid syntax/zero amount/excess precision is rejected before storage; storage/transaction errors roll back and must be retried by a future bounded worker. Missing or malformed schema is not repaired by intake.
- Valid pending observations with approved attribution, eligible KYC customer, current platform/vendor approvals and released stops remain `observed` while awaiting status/confirmations.
- Confirmation requirement is the effective platform/vendor bound, already no weaker than the provider catalogue's confirmation bounds. Deposit availability is checked independently of withdrawal availability. Existing reservation withdrawal gates are unchanged.
- `complete` + enough confirmations + locally asserted clear risk can reach `review_ready`, **never available funds**. Configured amount bounds are conservative review filters, not an enforced deposit product or fee schedule.
- Unknown/disabled/expired attribution, wrong memo/network/environment, changed attribution/scale, customer restrictions, risk unknown/blocked, suspended network, expired catalogue/approval, stop or amount-policy failure quarantine.
- Failed/reorged states, decreasing confirmations or a complete-to-pending regression quarantine. A reordered older report may be benign, but without authenticated ordering it is conservatively quarantined rather than silently credited. No automatic reorg compensation/debit exists because no credits exist.
- Quarantine is sticky. Later good reports, route activation, policy refresh or reorg recovery cannot silently unfreeze/reassign a record. A separately designed audited operator-resolution workflow is still required.
- Identical reports are idempotent only after current safety reevaluation. An identical report after a stop can change a review label to quarantine. No background sweeper invalidates old labels, so later settlement must never trust a stored label alone.
- Original confirmation count is kept as a high-water mark; full normalized update documents remain in event history, including lower/conflicting reports.

Row locks and the cross-tenant external identity constraint serialize competing imports. Observation changes and revisioned event history commit atomically; audit insert failure rolls everything back. Nested/autocommit-off transactions fail closed without committing caller work.

## Validation and next gates

`tests/crypto_deposits_test.php` covers normalization, immutable fingerprints, tags/case, pure state transitions, direction-specific governance and source/parity boundaries. The real MySQL runner includes `crypto_deposits_mysql_cases.php` in each isolated edition job for default-closed routing, wrong memo/network/environment, cross-tenant replay, exact-unit preservation, progress/duplicates/conflicts/reorgs, audit rollback, competing processes, stops and unchanged balances/holds/journal.

Before live use: authenticated management of the local custody/account registry and externally verified account identity/custody model; audited route provisioning with fresh step-up and dual review as appropriate; authenticated read-only provider adapter with exact record/status mapping, pagination/checkpoints/rate limits and protected evidence; operator exception queue and reconciliation; separately reviewed balanced deposit settlement accounting; real tiny-value testnet/pilot cases, reorg/freeze/outage recovery and operational signoff. Existing withdrawal disablement and all financial holds remain in force.

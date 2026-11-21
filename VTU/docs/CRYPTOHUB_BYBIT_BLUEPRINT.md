# CryptoHub + Bybit: implementation blueprint and security release gates

Status: Phase 1 and an isolated Phase 2 reservation/accounting foundation implemented in source; NOT a live custody, deposit, trading or withdrawal integration. Subsequent phases below are planned, not completed. Applies to SAAS and NON-SAAS, with shared web/mobile business rules.

## 1. Mandate and boundaries

The owner confirms regional support. Separately confirm the operating entity's permitted business/custody model, multi-tenant arrangement, customer jurisdictions, AML responsibilities, data handling and provider agreement before holding customer funds. Do not infer these permissions from regional availability alone.

Bybit's public API describes authentication, permissions, asset/network metadata and transaction operations; its complete internal security architecture is not public or independently verified here. We will adopt documented API controls and design our own defence-in-depth custody/accounting boundary. No claim of equivalent or superior overall security is made without independent assessment.

Preserve existing account blocks, financial holds, withdrawal switches, PINs, Google Authenticator anti-replay, forced-password/setup confinement, KYC, referral safeguards and session revocation. Connecting a provider must NEVER release held funds or silently re-enable services. Do not restore Plisio or send legacy instructions to a new provider.

## 2. Current architecture: findings and implications

- `web/CryptoHub.php` exposes wallet/history and trade interfaces, but explicitly refuses new provider-mode trades while provisioning is incomplete.
- `web/api/crypto.php` retains historical wallet reads and a fixed legacy coin list. New legacy deposit, swap and withdrawal operations are blocked by retirement gates.
- `func/bc-crypto-func.php` retains historical provider helpers and a separate legacy wallet writer. Do not use it as the writer for Bybit.
- `func/bc-fintech-services.php` chooses a general fintech provider and mostly stablecoin asset catalogues. A crypto provider must be selected independently of bank/fiat providers; Bybit is not a replacement Nigerian bank-transfer rail.
- `func/bc-fintech-ledger.php` supplies transactions, balance locks, settlement claims and audit records, but its supported asset list is limited and calculations still use floats. This is a useful base, not a complete decimal-safe double-entry exchange ledger.
- Existing administrative approval screens and credential configuration must not become a path around new custody controls.

Required refactor: one authoritative crypto domain layer used by web, Android, iOS and admin; historical balances remain visible, but new transactions use versioned accounting and provider references. Never switch historical records to Bybit by changing a provider dropdown.

## 3. Target architecture

Clients → authentication/enrolment gate → authorization/KYC/holds → crypto domain service → risk/limits → transactional ledger + outbox → restricted provider workers → Bybit.

Separate trust boundaries:
1. Client and public application tier: no provider secrets; cannot directly settle balances.
2. Domain tier: authoritative account ownership, asset/network policy, quote acceptance and reservations.
3. Ledger: decimal-safe, immutable balanced entries, unique external-event claims.
4. Provider signer/worker: explicit operations, fixed origins, least privilege, egress/IP restrictions; separate withdrawal signing service before withdrawal launch.
5. Operations: independent approver, audit review, reconciliation and emergency stop.

Provider operations do not run inside page-render transactions. Never hold a database row lock while waiting on external HTTP. Use atomic reservation/outbox creation, then submit asynchronously and reconcile.

## 4. Custody decision (must be signed off before Phase 3)

Choose and document either customer-owned connected exchange accounts, or an approved platform custody/business arrangement. For custody, establish customer deposit attribution, tenant segregation, liabilities vs actual provider holdings, insolvency/provider-freeze exposure and exit procedures. Ordinary master-account addresses are not automatically unique customer wallets. Do not allocate a shared address to multiple users without a provable attribution mechanism. Subaccount types and deposit-address eligibility must be confirmed with Bybit. No simulated address generation or guessed memo routing.

## 5. Crypto interface redesign

### Customer CryptoHub

- Overview: total indicative value with price timestamp, available/reserved/pending/held balances, clearly separated crypto and fiat balances. Never count pending/unconfirmed deposits as spendable.
- Assets: searchable approved asset catalogue; coin and network selection are distinct. Show network status, support limits and maintenance warnings. Hide unsupported actions rather than implying they work. Historical assets with balances remain visible after delisting.
- Receive: approved asset/network only; exact address and mandatory memo/tag, QR generated locally, minimum deposit and confirmation policy, explicit wrong-network warning. Provisioning/pending state must not display a placeholder address.
- Send: address/network validation, memo enforcement, approved beneficiary selection, destination risk review, gross amount, provider fee, platform fee and actual received amount. Final confirmation binds exact transaction fields to fresh TOTP/PIN challenge. New beneficiary/cooldown warnings cannot be dismissed to bypass restrictions.
- Convert: executable quote with quote ID, expiry, spread, fees, rounding policy and minimum output. Indicative prices are not executable quotes. Slippage/partial-fill policy explicit; no derivatives or margin in the initial release.
- Activity: pending/confirming/reserved/approved/submitted/processing/completed/rejected/failed/under-review states. Show provider-independent receipt reference, network TXID/explorer link where verified, fee breakdown, downloadable receipts and dispute/support flow.
- Security: account requirements, approved beneficiaries, active sessions, limits, recent changes, holds and recovery options. Never show internal keys or sensitive provider payloads.
- Accessibility: website brand colours and contrast, keyboard navigation, readable mobile tables/cards, no colour-only status indicators. Web/native clients consume the same server capabilities and state definitions.

### Vendor admin

- Independent crypto provider/environment readiness; read-only catalogue preview and explicit approval of individual coin/network pairs.
- Policy editor: limits, fees, confirmation minimums, beneficiary cooldowns, approval thresholds, KYC tier eligibility, supported jurisdictions, per-service kill switches.
- Queues: withdrawals needing approval, compliance holds, failed/unknown provider submissions, unattributed deposits and reconciliation discrepancies.
- Finance: customer liabilities vs provider assets per asset, reserved funds, settlement exports and historical opening-balance reconciliation.
- Keys: secret-manager references only, redacted metadata, key-role/IP verification, last successful check, expiry/rotation runbook; no browser-readable secrets.

### Super admin (SAAS)

- Platform ceilings override tenant settings, but do not erase stricter tenant rules.
- Independent approval for high-risk actions; a vendor cannot approve its own platform-level release.
- Tenant-level emergency isolation, global withdrawal stop, provider outage state and reconciliation exception overview.
- Policy edits require fresh Google Authenticator verification + CSRF, an audit trail and versioning. High-risk weakening changes require two-person approval/cooling-off in later phases.

## 6. Catalogue and capability model

Asset and network registry: stable internal IDs; provider coin/network IDs; verified full contract address where applicable; chain-native precision; deposit/withdrawal minimums/maximums; fixed and proportional fees; provider confirmations and safe-withdrawal confirmations; provider status; local approval; fetched-at/stale-at; source/environment and schema version.

Effective availability = local approval AND tenant entitlement/policy AND platform allowance AND fresh provider capability AND account eligibility AND healthy reconciliation. Never auto-enable all returned coins. Preserve network separation (e.g. USDT:TRX vs USDT:ETH); explicitly map historical USDT_TRX rather than silently rewriting balances. Provider minAccuracy is provider deposit/withdrawal precision, not necessarily a full description of token-native decimals.

Production catalogue refresh: scheduled worker, validated snapshot, atomic last-known-good replacement, schema-error alerts, single-flight refresh and backoff. Serving normal pages reads local snapshots; it must not fetch all coins on each visit. Stale data may be displayed with warnings, but sensitive actions must fail closed/revalidate. Phase 1 uses explicit admin-triggered point-in-time discovery only; no cache schema or asset-approval persistence is claimed implemented.

## 7. Money and ledger design

Before funding:
- Remove float casts/arithmetic from amount validation, fees, quotes and settlement; use canonical decimal strings plus a vetted arbitrary-precision decimal library, or exact integer units with an asset-specific scale. Bound supported precision explicitly.
- Immutable double-entry journal with balanced postings per asset, ownership-bound ledger accounts and atomic balance projections. Balances: available, reserved, pending and compliance-held. Fiat and crypto liabilities remain distinct.
- Unique constraints scope by tenant, provider account, environment, asset/network and provider event ID. Include transaction/event type and output index where needed; do not deduplicate solely on blockchain hash.
- Atomic balance reservation + order + outbox creation; insufficient funds and replay checks in the same database transaction. Concurrent requests cannot spend reserved funds.
- Historical opening entries require signed reconciliation; never mint opening balances from an unverified import.
- Administrative adjustments are balanced journal entries with independent approval, reason/evidence and audit—not UPDATE balance commands.
- Recovery/reconciliation can correct projections through auditable procedures, not silently rewrite history.

## 8. Transaction state machines

Deposit: address allocated → detected/unattributed → confirmations pending → provider confirmed → risk-cleared → ledger credited. Available for withdrawal only after the stricter applicable security-confirmation policy. Reorg/freeze handling moves funds into an explicit reviewed state; already-spent exposure is an incident, not a hidden negative correction.

Withdrawal: requested → risk checks → funds reserved → approval pending → approved → submission queued → submitted → processing → completed. Rejected/definitively failed releases reservation once. Timeout/unknown outcome goes to reconciliation; do NOT retry with a new reference or refund automatically. Use a stable provider idempotency/request ID and query status before resubmitting. Completion requires verified provider evidence, not merely accepted submission.

Conversion: quote requested → quote issued → accepted before expiry → funds reserved → execution submitted → fills reconciled → balanced settlement. Partial fills, expired quotes and cancellation are explicit states. No quote from client-supplied rates.

## 9. Defence-in-depth security plan

Threats include stolen sessions/API keys, compromised administrator, tenant cross-access, malicious beneficiary, replay/racing callbacks, precision abuse, provider outages/freezes, supply-chain compromise and insider collusion.

Controls:
- Existing forced-password/TOTP setup enforced on every authenticated transport. Fresh replay-protected TOTP plus PIN for money movement; challenges bind amount/asset/network/destination/fee/quote. Protect enrolment/recovery and weakening of policy with appropriate step-up and cooling-off.
- New destination and sensitive-account-change cooldowns; notifications out of band. Document account recovery without email-only bypass of enrolled TOTP.
- KYC tier, sanctions/address screening, source-of-funds and applicable Travel Rule checks. Do not claim Bybit's customer checks fulfil our own obligations.
- Hardware-backed/KMS-managed production keys, least privilege and short-lived access to signer. Separate discovery/trading/withdrawal credentials and roles where supported. Static egress IPs, provider IP bindings, key rotation/revocation, no secrets in logs/URLs/mobile bundles.
- No arbitrary provider URLs or redirects; TLS verification, bounded timeouts/body sizes, exact signing bytes and controlled time synchronisation. Requests use allowlisted operations only.
- Immutable/off-host audit export, tamper-evident journal checks, alerts on key/beneficiary/policy changes, repeated failures and velocity anomalies. Redact PII and provider secrets.
- Emergency withdrawal stop independent of web admin availability; it must also stop queued-but-not-submitted workers. Confirmed settlements still reconcile safely. Least-privilege DB identities, tested restore, incident isolation and forensic retention.
- Dependency pinning, secret scanning, CI security checks, independent penetration test, ledger review and incident tabletop exercises before unrestricted launch.

These controls reduce risk but cannot guarantee a platform cannot be hacked. Exchange custody introduces provider compromise, insolvency, account freeze and operational concentration risks. Retain explicit customer disclosures and an exit plan.

## 10. Bybit API mapping and documented constraints

Official references reviewed for Phase 1:
- Authentication/signing: https://bybit-exchange.github.io/docs/v5/guide
- API-key information: GET /v5/user/query-api — https://bybit-exchange.github.io/docs/v5/user/apikey-info
- Coin/network metadata: GET /v5/asset/coin/query-info — https://bybit-exchange.github.io/docs/v5/asset/coin-info

Planned operations require a fresh documentation/eligibility review before implementation:
- Master/subaccount deposit-address retrieval and verified deposit records (custodial subaccount constraints matter).
- Withdrawal creation/status, address-book requirements, stable unique requestId, master-key restrictions, rate limits and applicable compliance questionnaire.
- Conversion quote/execute/history or spot execution, based on approved account capabilities. Do not assume account API access equals permission to offer every product.

No generic deposit-webhook trust is assumed. Start with authenticated record polling/status verification and reconciliation; add any callback/event mechanism only after its authenticity, sequencing and coverage are documented and tested. Public market tickers never authorize customer settlement.

## 11. Implementation phases and acceptance gates

### Phase 1 — implemented now: isolated read-only discovery

Files in both editions: `func/bc-bybit.php`, `bc-admin/CryptoBybit.php`, entry link from admin Crypto Hub.
- Server-only, vendor-specific environment credentials; default testnet, fixed HTTPS origins, no tenant/platform key fallback.
- HMAC-SHA256 GET signing, two allowlisted read endpoints only, no redirects, TLS verification, 3s connect/8s per-call total timeout, 2MiB response cap, strict provider error handling. Two reads can take up to roughly 16s on an explicit inspection; normal page loads make no provider calls.
- Verify readOnly=1 and exact configured IP bindings before catalogue retrieval. Broad/read-write keys, wildcard/no IP bindings or mismatches are refused.
- Admin access requires active account, CSRF, throttling, fresh PIN+TOTP verification and audit. No emailed-code fallback. Browser receives only sanitized readiness and exact-string catalogue values, never raw key-info payloads.
- All discovered assets marked platform_enabled=false. No wallet writes, no schema migrations, no custody changes, no activation/withdrawal switch changes. Existing provider selection is untouched.

Acceptance: deterministic signing/validation tests, edition parity, invalid-provider responses fail closed, tenant config isolation, no money-moving operation available, live testnet check by operator pending.

### Phase 2 — domain/accounting foundation

Implementation progress: `CRYPTO_V2_ACCOUNTING.md` documents exact-decimal arithmetic, additive V2 tables, atomic reserve/full-release journaling and internal integrity tooling now in source. These are isolated from historical balances and provider submission; the full Phase 2 release gate below is NOT yet complete.

Implement exact-decimal module, versioned asset/network and approved capability tables, policy hierarchy, double-entry journal, reservations, outbox and state machines. Provide explicit reviewed migrations/rollback procedures; no DDL on public page loads. Wire read-only customer/native capability interfaces. Acceptance: property-based accounting/concurrency tests, migration/reconciliation dry run and independent review.

### Phase 3 — deposits, limited pilot

Approved attribution/custody model; address allocation, records polling, confirmation/risk gates, deduplication, reconciliation and operator exception queues. Begin with a limited stablecoin/network allowlist after actual approval—not automatically every Bybit coin. Acceptance: real tiny-value controlled deposits, duplicate/reordered events, shared/memo routing, wrong network, reorg/freeze and outage tests; liabilities reconcile exactly.

### Phase 4 — controlled withdrawals

Independent signer/worker, beneficiary controls, limits/cooldowns, multi-party approval and kill switches. Acceptance: insufficient funds races, idempotent submission, timeout with successful provider execution, rejection/refund-once, fee changes, provider maintenance, queued kill-switch tests and externally reviewed penetration test. Start with low transaction/aggregate limits and manual approval. Existing withdrawal disablement stays until explicitly authorized release.

### Phase 5 — conversions and full client rollout

Executable quotes/execution reconciliation, fee disclosure, slippage/partial-fill handling; consistent web and all six native clients. Local builds/device QA required. No margin/derivatives/earn/P2P/card expansion implied.

### Phase 6 — production scale/operations

Load/rate-limit tests, offsite audit/monitoring, daily asset/liability signoff, disaster recovery and key compromise drills, incident response on-call, periodic access review and external security review. Broader coins/networks enabled individually with separate approval.

## 12. Phase 1 operator setup

Use environment variables injected by the deployment secret manager; never commit or paste real keys into chat:
- `BC_BYBIT_VENDOR_<id>_ENV`: testnet (default) or mainnet (explicit read-only discovery only).
- `BC_BYBIT_VENDOR_<id>_KEY`, `BC_BYBIT_VENDOR_<id>_SECRET`: dedicated HMAC read-only key.
- `BC_BYBIT_VENDOR_<id>_EXPECTED_IPS`: comma-separated exact static egress IPs, matching provider key bindings. CIDR/wildcards unsupported deliberately in this phase.

Configure the provider key/IP bindings in the approved account, confirm server clock/NTP and outbound access to the correct fixed origin, then visit bc-admin → Crypto Hub → Bybit discovery. Enrol Google Authenticator and Security PIN first. Discovery results are a non-persistent snapshot; refresh requires another fresh verification. No automated retry on errors. Do not configure withdrawal-capable keys for this phase.

Environment variables are an initial server-only injection mechanism, not a finished managed signing service. Before transactional phases, complete KMS/signing isolation and secret lifecycle controls. Neither a green discovery screen nor passing mocked tests establishes production readiness.

## 13. Verification and rollback

Run `node VTU/tests/harness/run-test.mjs tests/bybit_discovery_test.php` plus existing policy/fintech/security suites. Test signed reads on a dedicated account separately; live Bybit HTTP, account permission acceptance, native UI and financial operations have not been verified here.

Phase 1 rollback: remove the admin entry link/page and adapter, revoke the dedicated read-only key and remove its environment injection. No new balances, provider switches or database schema need reversing. Do not delete historical crypto records. Future financial phases require forward-recovery accounting plans, not destructive rollback of settled transactions.

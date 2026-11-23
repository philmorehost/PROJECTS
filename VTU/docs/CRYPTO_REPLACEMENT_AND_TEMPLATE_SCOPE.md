# FINTECHFLOW DGV7.0: revised crypto and public-template scope

## New direction (supersedes earlier Bybit feature requirements)

The user requests complete removal of Bybit from the distributed application, vendor-neutral FINTECHFLOW DGV7.0 product branding, independent Klasha/Platnova/Flutterwave service routing, an admin-to-user/user-to-admin crypto Buy/Sell experience, the exact supplied home-page design with responsive navigation, and distinct BC-STYLE-TEMPLATE designs. Existing financial/crypto holds and the staging pause remain in effect. This request is not authorization to fund a hot wallet, execute sample trades, broadcast transactions or deploy.

## Inputs missing in this checkout

At intake, VTU/home-page.html, VTU/cryptocurrency-idea and widget-idea-1.jpeg/widget-idea-2.jpeg could not be found by filesystem search or tracked-file lookup. They must be uploaded/synced before source review or exact design reproduction. The quoted description and simulated-test claims are design input, not verified implementation or security evidence.

## First source fix

Both public index controllers ignored the saved template and unconditionally included the shared home design. They now resolve tenant-scoped saved CSS names through a fixed include map. Templates 1, 2, 3 and 6 (the choices currently offered in admin settings) select their corresponding existing PHP files. Template 1 currently wraps the shared home; 2, 3 and 6 retain distinct existing implementations. Missing/invalid selection uses the existing shared-home fallback; the public Freelancers route is retained. This does not implement the absent home-page.html reference or certify all-device visual fidelity. SAAS has no numbered template-4/5 PHP pages; inventing replacements or enabling unsupported selections is not part of this fix.

## Bybit removal: pending implementation, not yet claimed complete

Inventory found references across provider configuration, commercial entitlements/settings, custody/deposit discovery/readiness/polling and tests/docs. Remove the distributed runtime integration and visible cards/routes/options in dependency order; do not blindly delete a helper while live callers remain. Review cron/jobs, encrypted credentials, environment variables, database options and app/client references. Preserve historical transactions/audit evidence and backups; any destructive production credential/data cleanup needs a separate reviewed plan. Update tests/docs to represent the replacement architecture, not relabel old Bybit custody code as another provider. No Bybit runtime removal was implemented in the public-template fix.

## Node.js is optional; safe custody is not

PHP can implement the authenticated UI/API, tenant service-routing policy, fixed-point quotes, durable orders, Naira ledger reservations/settlement and reconciliation. Cron/CLI PHP or supervised PHP workers can run asynchronous jobs; shared hosting must be evaluated for its actual worker, extension and operational constraints. No requirement inherently mandates Node.js.

For automatic blockchain signing, prefer an isolated signer or an eligible managed custody provider with an explicit authenticated API, tight asset/network/amount limits and audit logs. Do not store a universal seed in the web application or make arbitrary signing available to PHP request handlers. A PHP-only signing implementation would still require maintained EVM/TRON/Bitcoin cryptography libraries, independent review, per-network nonce/UTXO management and secured key operations. A VPS or managed durable-worker infrastructure may still be needed even without Node.js.

Existing adapters do not prove that Klasha, Platnova or Flutterwave supports every proposed crypto asset/network/custody function. Build a capability matrix from verified provider contracts and actual account eligibility, then route each function independently. Unknown/unsupported routes stay disabled; no implicit cross-provider fallback after an uncertain financial request.

## Required Buy/Sell invariants before implementation can be enabled

- Clarify card labels: user buys crypto from administrator; user sells crypto to administrator. Confirm the desired A2P/P2A naming against the supplied screenshots.
- Per-tenant, per-asset/network policy, KYC/KYB, account restrictions, transaction limits, PIN and independently configured email/TOTP action checks.
- Two-minute quote with exact rate, margin, fees, expiry, asset/network, amount and destination binding; integer minor units/fixed-point arithmetic, never floating-point financial math.
- Durable idempotent order/ledger intents and transactional reservation/debit/credit; no send from an HTTP request without a recoverable durable outbox/worker claim.
- Payout states distinguish never-attempted, definitively rejected-before-broadcast, broadcast, confirmed, and unknown. Timeout is NOT proof of failure. Refund only when provably safe with an exactly-once ledger claim; otherwise review/reconcile.
- Sell deposit mapping and confirmation/finality rules per chain; token contract/network/decimals validation, reorg/dedup handling and immutable attribution. An address prefix alone is not full validation or proof of network correctness.
- Late/over/under/duplicate/unconfirmed/wrong-network deposits and provider uncertainty require explicit review rules. Sweeps/gas funding are separate tracked financial operations, not an unaccounted side effect.
- TRON resource costs and BTC confirmation/fee assumptions from the proposal are not guaranteed constants; validate current network/provider behavior before approved staging.
- Independent review/auditing, key backup/recovery, bounded hot-wallet liquidity, alerts, kill switch, tenant isolation and abuse protection.
- Disposable DB concurrent/fault tests, chain-adapter simulations and independently authorized network/device/provider testing. Simulations and PHP syntax checks are necessary but insufficient.
- Confirm regulatory/provider eligibility with qualified advisers before launch; no legal approval is implied.

## Next steps

1. Obtain the missing reference files and inspect the supplied implementation/dependencies/assets.
2. Complete Bybit retirement inventory and remove runtime/visible integration safely without releasing holds.
3. Review provider capability matrix and select custody/signing architecture before financial implementation.
4. Reproduce the reference homepage and verify responsive navigation; audit each selectable template and baked-in brand artwork separately. Keep tenant branding dynamic; do not globally replace legitimate configured vendor names or historical records.
5. Implement held Buy/Sell source and durable accounting tests; request separate authorization for staging/deployment/live trades.

# Crypto asset/network governance — implementation and operating guide

## Status and boundaries

Both editions now have local asset/network approval controls integrated with isolated Crypto V2 reservation admission. This does NOT enable Bybit deposits, trading, withdrawals, historical wallet migration or queued provider submission. Existing financial holds, legacy withdrawal switches, credentials, account balances and reconciliation readiness remain unchanged.

Configured fees, confirmation minimums and beneficiary cooldowns are returned as policy constraints for future transaction services. Actual fee charging, deposit-confirmation processing and beneficiary-age checks are not implemented by a policy editor; those workflows remain release gates. The new admission stop protects V2 reservations, not legacy financial services or provider operations already committed elsewhere. Do not present it as a universal exchange kill switch.

## Navigation

- Both editions: bc-admin → Crypto Hub → Crypto asset/network policies and emergency stop (`CryptoPolicies.php`). Also linked from Bybit discovery.
- SAAS: bc-spadmin → Account Settings → Security → Crypto platform asset ceilings and emergency stop.
- NON-SAAS: owner uses the vendor page's Platform ceilings / Vendor rules links. No new super-admin console is introduced in NON-SAAS.

Tenant scope comes from the authenticated vendor. A SAAS vendor cannot select another vendor or platform scope by changing request fields. Only the super-admin wrapper grants platform authority. A source-vendor selector on the super-admin page chooses a previously verified catalogue as reference metadata; it does not grant access to provider credentials and does not edit that vendor's rules.

Every mutation requires CSRF, actor-scoped rate limiting, a fresh active actor read, enrolled Google Authenticator, and fresh PIN+TOTP verification using existing anti-replay helpers. A prior settings unlock or emailed OTP is not sufficient. Vendor Demo Mode edit restrictions remain in force. The shared page cannot be executed directly without the trusted wrapper marker.

## Verified catalogue snapshots

Successful authenticated Bybit discovery now persists only its sanitized, server-produced network catalogue, scoped by vendor and testnet/mainnet environment. No raw key-info response or secret is stored. Browser-posted catalogues are never accepted by a route.

Snapshots expire after 900 seconds. Metadata is read locally when reviewing/approving networks; opening the policy page makes no provider calls. New approval requires the selected exact asset/network to exist in a fresh snapshot. Approval fingerprints bind the provider metadata; a fee, precision, confirmation or network-status change invalidates the prior matching approval. Refreshing identical metadata renews catalogue freshness without automatically approving any asset.

For current admission, both platform and vendor rules must match the tenant's current provider metadata. A platform approval made from a source vendor whose capabilities differ from the tenant does not authorize the mismatch. Mainnet and testnet never share snapshots, approvals or controls.

The current refresh is explicit, stepped-up discovery. There is no unattended production catalogue refresher in this release. Operators must refresh before the 15-minute snapshot expiry if testing admission. A scheduled least-privilege refresh worker is still required before continuous production operation.

## Approval fields

- Accounting scale: integer 0–30, separate from provider withdrawal precision. Existing account scales cannot be changed by the editor.
- Minimum/maximum reservation amount: exact decimal strings converted to canonical integer units. Maximum must be nonzero; minimum cannot exceed maximum. No float, exponent or silent rounding.
- Platform fee ceiling / vendor fee in integer basis points, 0–10000. These are the platform fee settings, not a replacement for Bybit's fixed/proportional network fees. Provider fees are not converted lossily into basis points.
- Confirmation minimum: cannot be below either of the provider's advertised deposit/security-unlock confirmation requirements.
- Beneficiary cooldown minimum: 0–2592000 seconds. It remains a configured bound for a future beneficiary service.
- Approval expiry: at most 86400 seconds, with catalogue freshness separately limited to 900 seconds. Expired approvals do not authorize new reservations.

Effective admission requires both scopes to approve the exact network, both admission stops released, fresh matching metadata, consistent accounting scale and provider withdrawal availability. A tenant cannot exceed the platform amount/fee ceiling or weaken its minimum amount, confirmation requirement or cooldown. Missing, revoked or malformed rules fail closed.

Local rules can be recorded before the other scope is configured. A stored approval is NOT proof that effective admission is open: the UI explicitly distinguishes stored rules from runtime authorization. A weaker stored tenant rule is rejected by the effective evaluator and grants no admission.

The existing `sas_crypto_v2_asset_policies` table remains an independent accounting/reconciliation release gate. This dashboard NEVER sets `reconciliation_status=matched`, enables that table's withdrawal flag, funds accounts or claims provider reserves are verified. `live_money_movement_enabled` is always false in the new policy evaluator's response.

## Stops, revocation, concurrency and audit

- Missing control rows mean STOPPED. Platform and tenant stops are independent; releasing one cannot release the other.
- Stop/release requires no provider request or fresh catalogue. Revocation also remains possible during a provider outage or expired snapshot.
- Stop and admission reads use transactional row locks. Once a stop commits, later new admissions see it; already committed/in-flight earlier work is not retroactively undone. Future withdrawal workers must separately enforce a submission-time stop.
- Stops do not automatically release reservations or compliance holds. Exact reversal of an unsubmitted V2 reservation remains possible through the existing internal release primitive.
- Policy revisions use compare-and-set semantics. A stale browser form is rejected and must be refreshed, not silently merged.
- A dedicated pre-migrated audit table stores actor role/id, target scope/environment/key, next revision and before/after documents in the SAME transaction as the change. Audit failure or commit failure cannot report a successful edit. No lazy security-audit DDL is run inside this transaction.
- Application code is append-only for policy audit records. Restricted finance DB credentials and off-host tamper-resistant export are still required before production; broad database administrators can otherwise alter data.

## Explicit migration (not executed by the agent)

New additive InnoDB tables:

1. `sas_crypto_v2_catalogues` — sanitized expiring metadata snapshots.
2. `sas_crypto_v2_governance` — revisioned controls and asset rules; vendor_id=0 denotes platform scope.
3. `sas_crypto_v2_policy_audit` — atomic before/after audit records, unique per policy revision.

The existing CLI management tool now previews/installs these three tables together with the four accounting tables. Existing installations must run the reviewed additive installer again after backup; CREATE TABLE IF NOT EXISTS does not repair incompatible pre-existing definitions.

```text
php func/crypto-v2-manage.php schema
# Only after schema review, staging backup/restore and operator authorization:
BC_CRYPTO_V2_SCHEMA_APPROVED=1 php func/crypto-v2-manage.php install --apply
```

No page request creates tables or bumps the global migration version. Missing schema fails closed and the dashboard explains that an operator migration is required. Discovery persistence now also needs this schema; migration must precede use of the updated discovery screen. No migration, policy population or production deployment was performed here.

## Suggested staging test sequence

1. Back up staging; review/install all seven additive tables and verify actual definitions/indexes/FKs.
2. Configure the dedicated read-only Bybit testnet key and exact IP binding from the discovery runbook; do not enable withdrawal privileges.
3. Enrol administrator PIN/TOTP; fetch verified catalogue in vendor discovery.
4. Create platform and vendor rules for one matching asset/network, with strict limits. Confirm approvals alone do not permit admissions while stops remain active.
5. Release each scope's admission stop independently. Confirm the separate accounting/reconciliation gate still blocks an unready wallet.
6. Test stale forms, wrong/replayed codes, CSRF failure, cross-tenant requests, changed provider fingerprints, snapshot expiry, provider suspension and stricter platform constraints.
7. Test real MySQL concurrent stop/edit/reservation operations, audit failure and rollback. Verify balances/legacy holds are untouched.
8. Do not populate fake balances or manually mark provider reconciliation complete. Opening entries, custody attribution and external reconciliation still require their own reviewed implementation.

## Automated validation

- `tests/crypto_governance_test.php`: 95 pure/source/schema checks for hierarchy, fail-closed controls, snapshots, exact limits, provider fingerprint changes, suspension, expiry and controller boundaries.
- `tests/crypto_governance_storage_test.php`: 25 transaction-double checks for scope authority, revision conflicts, approval, atomic audit rollback, immutable account scale, outage revocation and NON-SAAS owner scope.
- Updated reservation storage double: 57 checks including actual governance admission integration, platform stop/no mutation and exact unsubmitted release during stop.
- Existing arithmetic, schema, Bybit, authentication and fintech regressions retained.

See `CRYPTO_MYSQL_VALIDATION.md` and the dedicated MySQL CI workflow for disposable database integration checks. These do not replace staging/browser/provider validation.

Storage doubles and PHP parsing are NOT proof of live MySQL isolation, performance, deployment correctness or end-to-end Bybit transactions. Those tests remain mandatory release gates.

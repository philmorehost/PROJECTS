# CryptoHub custody/account registry — internal foundation

## Scope

This resumes the paused CryptoHub work after the observation-only deposit foundation. Both editions now have an **internal, local-review-only registry**. It does not connect to Bybit, authenticate ownership, prove custody eligibility or reserves, allocate addresses/routes, credit customer balances, release holds, submit withdrawals, execute conversions or change live activation switches. No database migration or deployment was performed.

The earlier requery issue is confirmed solved by the operator with `* * * * * /bin/sh /home/v8data/public_html/cron/run_requery_queue.sh`. Setup pages publish this full launcher schedule using the actual local installation directory; cPanel instructions distinguish the five timing fields from its Command field.

## Canonical external identity

`bc_crypto_custody_identity(environment, uid)` derives SHA-256 over exact JSON `['bybit', environment, uid]`. UID must be a provider-issued physical account/subaccount UID, supplied as an exact positive digit string with no leading zero, whitespace, exponent, float or tenant alias. The same physical account/environment has the same identity across all vendors. Mainnet/testnet are deliberately distinct. API-key changes do not change that identity.

No raw UID, API key, secret or address is stored in these registry tables. The digest is a pseudonymous identifier, not encryption or authentication. The future trusted provider adapter must establish the correct UID and master/subaccount relationship from authenticated evidence, detect aliases and prevent the same account being imported under different claimed UIDs. An operator typing a UID or an evidence hash is NOT proof that those assertions are true. No live account is registered by this implementation.

## Review workflow and trust boundary

`bc_crypto_custody_save(authority, environment, uid, expected_revision, action, input)` is an **internal library API**, not an HTTP endpoint, CLI registration command or security verifier. Like the existing internal governance library, its authority is a trusted controller assertion. It accepts platform role `spadmin` and a positive integer actor ID; vendor-role assertions are rejected. The authenticated SAAS Super Admin controller is now implemented; see `CRYPTO_CUSTODY_MANAGEMENT.md`. No vendor/standalone mutation path is exposed. Do not expose this API to browser JSON/callbacks.

The management interface must always load fresh active platform actors from the database, enforce forced-password/authenticator enrollment, CSRF, throttling, replay-protected PIN + TOTP (no emailed fallback), role/permission checks and an independent reviewer identity policy. Merely passing a role/actor array is not authentication. Standalone deployments also remain unprovisionable until an appropriate independent-operator workflow is implemented; no single-owner self-approval exception was added.

Actions:
- `propose`: select a reviewed proposed custody model (`dedicated_subaccount` or `omnibus_exclusive_routes`) and SHA-256 evidence reference. State is proposed, expiry zero, bindings empty. Reproposal resets **all** prior reviews/bindings, even if the model/evidence is unchanged.
- `review`: a different actor must review the exact evidence digest at the expected revision; max lifetime 24 hours. State becomes `review_only`, not enabled/live.
- `propose_binding` / `review_binding`: explicitly bind a vendor ID to the reviewed account, with separate evidence and independent review. Binding expiry cannot exceed account-review expiry. No tenant/platform fallback is inferred.
- `suspend`: immediately zero expiry and suspend account eligibility with a revisioned audit entry. No automatic resume; a new proposal and independent reviews are required.
- `revoke_binding`: remove that tenant's eligibility. No effect on other explicitly reviewed tenant bindings.

Model names are declarations for evidence review, not implemented segregation guarantees. Neither a subaccount UID nor a shared omnibus address demonstrates end-customer attribution. Exclusive network/address/tag route approval remains a separate, unimplemented workflow. Protected source evidence and regulator/provider/business approval remain external obligations; hashes only reference evidence.

## Storage and atomicity

Two additive InnoDB tables:
- `sas_crypto_v2_custody_accounts`: global canonical identity, provider/environment, current document and revision.
- `sas_crypto_v2_custody_audit`: before/after documents, actor, action and unique revision, committed with the registry update.

No DDL is called on web/worker intake. The existing explicit `crypto-v2-manage.php schema` preview/approved installer now includes **nineteen** additive production tables, including two protected-evidence and two route-candidate and three polling-stream tables. CREATE IF NOT EXISTS does not repair incompatible definitions; operator backup/restore review and separate migration authorization are still required. Registry mutations refuse non-InnoDB/missing registry tables, stale expected revisions and nested/autocommit-off transactions. Row locking and compare-and-update serialize concurrent changes; audit failure rolls back state and revision. Append-only audit is application-level, not protection against a privileged database administrator; off-host immutable audit export remains required.

## Deposit observation integration

Every observation locks and rechecks its current canonical registry row before deposit/route processing. Missing, expired, suspended, wrong-environment or unbound custody context produces quarantine `custody_unapproved` (or storage failure/rollback if the registry schema is unavailable), never review-ready. Existing route, customer/KYC, exact-unit, deduplication, network/governance, risk and confirmation checks still apply. Identical reports are reevaluated: suspension invalidates a stale review-ready label. Later approvals never unfreeze sticky quarantine. There is no background invalidation sweep; future settlement must revalidate everything rather than trusting stored review labels.

This gate is necessary but not sufficient: review-only is never a settlement authorization, and locally asserted provider status/risk/evidence remain unauthenticated until a trusted adapter is implemented.

## Validation / next gate

- Pure tests cover canonicalization, environment identity, authority rejection, independent/evidence-bound review, expiry, scope, suspension/revocation, reproposal and source/edition boundaries.
- Disposable MySQL tests cover additive migration, stale/self-review refusals, tenant/environment scope, concurrent expected revisions, nested-transaction refusal, atomic audit rollback, and unchanged journal/balances/holds. Deposit fixtures now use synthetic registry approvals created solely in random test databases; no production seeds.
- Native CI is required before merge. No production database or provider tests are claimed.

Management/evidence interface progress is now documented in `CRYPTO_CUSTODY_MANAGEMENT.md`. Next: verified provider UID/account capabilities and approved custody model; audited exclusive route provisioning; read-only deposit polling with exact status/record identity/pagination checkpoints and evidence retention. Settlement accounting and tiny-value controlled pilot remain separate review gates. Withdrawals stay disabled and existing financial holds remain intact.

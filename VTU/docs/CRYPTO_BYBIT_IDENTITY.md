# CryptoHub: read-only provider account identity comparison

> The separate master-address inventory action now reuses these identity checks around one new read endpoint. See `CRYPTO_BYBIT_ADDRESS_READ.md`; no customer allocation or activation is inferred. The identity-only action described below still makes only its existing single query.

## What this stage does

The platform custody-review page now has an explicit POST action to inspect the selected vendor's **server-configured** personal Bybit read-only credentials using the existing signed `GET /v5/user/query-api`. Normal page loads perform no provider calls. No new provider operation is allowlisted, and the existing fixed origins, TLS verification, no redirects, 3s connect/8s total timeout and 2MiB response cap remain in use. There is no retry or money-moving request.

The action requires the custody-management CSRF, fresh session-bound platform actor, active/unblocked/no-forced-password-change checks, throttling, PIN and enrolled replay-protected TOTP. Vendor/standalone self-service remains blocked. Source vendor existence/status is checked, and the environment must match that vendor's environment configuration exactly; there is no tenant/platform credential fallback or silent switch between mainnet/testnet.

`bc-crypto-bybit-identity.php` compares the provider-reported user ID with the selected registry account's deterministic fingerprint. It accepts a UID only as an exact positive string or a PHP integer that was decoded without loss; floats, exponents, whitespace, leading zeros and missing/ambiguous values are refused. Existing JSON_BIGINT_AS_STRING decoding preserves oversized JSON integer values rather than casting through floats. The existing read transport's millisecond timestamp now uses decimal formatting instead of an intermediate integer cast, avoiding 32-bit overflow.

Required response checks:
- `readOnly` is integer 1 and IP bindings exactly match the configured expected set.
- `type` is integer 1 (personal); third-party connected keys are not supported in this stage.
- Returned API key matches the exact key used for this query; a nonempty returned secret is refused.
- `isMaster` is a boolean. A reported master must have string parentUid `0`. A reported subaccount must have a positive canonical parent UID different from its own UID.
- UID/environment-derived identity matches the selected registry fingerprint. Unknown, conflicting or malformed responses fail closed.

The normalized evidence contains the account fingerprint, reported master/subaccount kind, fingerprinted parent account where applicable, source vendor ID, source-config fingerprint, actor, registry revision, server inspection time and a 15-minute freshness label. It explicitly marks custody eligibility/write capabilities/live activation **false**. Raw UID/parent UID, API key/secret/IP bindings, raw permission arrays, notes, KYC details and provider response bodies are not returned to the UI or stored in this evidence.

## Audit and evidence

A sanitized request audit must succeed before the provider read. The protected-evidence schema/encryption key is preflighted before the call. On a successful comparison, only the generated normalized JSON is encrypted in the existing evidence store, with a transactional evidence-storage event. Expected registry revision is checked again under row lock inside that storage transaction, so a proposal/suspension change while the query is in flight cannot silently attach evidence to a newer revision. Registry approval, bindings, expiry, revision and balances are not changed by the comparison.

The UI returns only the encrypted evidence digest and a point-in-time match notice. A reviewer can open it through the existing audited, freshly stepped-up evidence-view flow. The same independent-review and receipt rules apply if it is referenced in a local review. A stale or failed comparison never force-approves an account.

The label `identity_matches_registry` means exactly that an authenticated query response matched the expected UID at that moment. It is NOT proof of legal account ownership, approved custody/business model, end-customer attribution, solvency/reserves or ability/permission to deposit, withdraw, transfer or trade. Read-only permission labels cannot demonstrate those write capabilities. Reported parent UID is not a verification of the parent account's credentials or a subaccount/customer allocation policy.

Freshness is a label on review evidence, not an authorization cache: this stage adds no consumer that treats a stored comparison as permission to operate. Future route provisioning/settlement must query/revalidate identity, registry/policies/config fingerprints, permissions and custody approvals through their own reviewed gates. Manual local proposal/review remains possible but never activates live services.

## Provider documentation and verification limits

Reviewed official source on this turn:
- https://github.com/bybit-exchange/docs/blob/3c10aaca297bb1f12c069d9ebba4a27e2664f003/docs/v5/user/apikey-info.mdx
- Canonical published reference: https://bybit-exchange.github.io/docs/v5/user/apikey-info

The reference states that master and subaccount keys can query this endpoint, documents readOnly/type/userID/isMaster/parentUid and explicitly notes any API permission can access the endpoint. Consequently a successful call is not evidence that other operations are supported. `id` is the API key's internal ID, not the user UID; `inviterID` is not parentUid. Neither is used as custody identity.

No real credentials, provider account or production host were inspected. Tests use synthetic responses only. Live operator verification still needs separately configured read-only credentials/IP binding, clock/NTP, correct account/environment and fresh protected evidence keys. Existing crypto activation/withdrawal disablement and all holds are unchanged. No schema addition, migration, deployment or production mutation is introduced by this stage.

## Remaining major stages

1. Verify the intended master/subaccount account structure and **approved custody/business eligibility**, including independent evidence and permissions appropriate to each future operation. The current endpoint cannot establish these alone.
2. Audited exclusive network/address/memo route provisioning; authenticated deposit record polling with exact identity/status mappings, pagination/checkpoints, bounded recovery and evidence retention.
3. Separately reviewed balanced deposit settlement, current-policy/finality/risk revalidation, asset/liability reconciliation and controlled tiny-value pilot.
4. Independent withdrawal worker/signer, beneficiary cooling-off, approval/limits/kill switches, stable submission IDs, unknown-outcome recovery and security testing. Do not lift existing withdrawal stops to test.
5. Executable conversion quotes, execution/fill reconciliation, web/native integration and local device QA.
6. Operational readiness: backups/restore drills, protected key lifecycle, off-host audit, monitoring, incident response and independent financial/security review.

Foundations in source are not equivalent to production readiness. Do not represent this stage as a completed CryptoHub launch.

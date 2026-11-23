# Project completion checklist

## How to read this

Consolidated from the standing project requirements, remediation tracker, security/financial-hold documents and recent source review. This is the known remaining acceptance checklist, not a claim that every file/provider has been audited. Older batch documents describe historical states; a merged source fix is not deployment, device verification or production readiness. No defensible completion percentage or finish date is available until the inventory and release tests are closed.

## 1. Finish security and compliance audit — release blocker

- Complete the remaining authentication and authorization route inventory for both editions and every role: user, vendor, platform administrator, developer integration and first-party app.
- Check security-question authentication, temporary/vendor access URLs, configured lifetime/expiry/revocation, tenant scope and approval-email delivery. Recent credential-bound login-token fixes do not certify all temporary-access features.
- Finish Google account linking/identity-conflict and recovery review; verify actual Google/browser integration. Audience, expiry, nonce and transport hardening are source-implemented.
- Restore biometric sign-in only after full server-side WebAuthn assertion verification (challenge, signature, origin/RP ID, user/credential association and replay/counter policy) and browser/device tests. Credential-ID-only sign-in remains refused; do not remove enrollment or pretend it works.
- Run end-to-end forced password AND PIN → fresh login → authenticator enrollment → KYC/KYB → permitted access tests, including blocked/suspended users and recovery. Recent atomic/reset and policy-freshness fixes do not replace this test matrix.
- Finish KYB reviewer identity/independence and audit trail, privileged/alternate account-promotion routes, document access/upload controls, manual/AI/provider review decisions, stale decisions and concurrent writes. Current user/vendor-matching email/username self-review checks are conservative identity signals, not proof of beneficial ownership.
- Decide whether stronger monotonic per-account revocation is needed. Current credential fingerprints invalidate changed values but can become valid again if identical old values and live session/token state are restored.
- Finish explicit Business integration-key rotation/revocation and migration acceptance. Personal API-key fallback is refused; Business keys intentionally remain independent of password resets.

## 2. Inline OTP and security UX — unfinished site-wide acceptance

- Inventory every remaining Send Code/Resend, PIN/TOTP/recovery/setup form across user/vendor/platform screens and app handoffs.
- Verify no reload, no closed modal/card, preserved inputs, inline errors, SENT feedback and duplicate-in-flight protection everywhere requested.
- Confirm send requests contain only the necessary challenge metadata, not PIN/password/entered OTP/financial mutation payloads; document and review the existing registration resend exception.
- Preserve purpose, CSRF, replay, rate limits, fresh factors, tenant/account checks and independent platform/vendor Google/email action policy.
- Verify real mail delivery and failure/retry behavior separately from UI success. A SENT label is not delivery proof.

## 3. Web/app feature parity and navigation — inventory and test

- Test every home/service action for all DG7, JIKABIZ and MZEEVTU Android/iOS variants against both server editions and allowed account types.
- Validate Send (Nigeria/local/global), Receive (invoices), Topup (funding), More/services, and Crypto/Virtual Card/Gift Card/VTU tiles. Held or unconfigured services must remain clearly unavailable, not become dead links or fake successes.
- Verify browser handoff, token refresh/revocation, idle/biometric locks, security-question flow, required setup and error recovery without loops or dropped parameters.
- Reconcile real wallet/balance/currency contracts, wallet selection and live-FX handling; no fabricated balances or successful-empty history fallbacks.
- Confirm enabled-service floating shortcuts, tenant theming, support chat and enabled automation in regression tests.

## 4. History and receipts — site-wide acceptance outstanding

- Cover every transaction/history surface, not only the main Transactions page; verify tenant/user isolation, search, pagination and permission failures.
- Verify screen, PDF and image receipts, sharing/downloading and branding on web and all apps.
- Put credited destination numbers on their own lines; use actual stored utility customer name/address/meter/prepaid token; never fabricate missing data or display prepaid tokens for postpaid products.
- Reconcile provider outcomes with wallet/ledger and receipt state under retries, timeouts and duplicate requests.

## 5. Financial correctness and held services — do not release implicitly

- Gift-card settlement currently has containment/holds, not a completed durable replacement. Finish intent/idempotency, provider unknown-outcome reconciliation, atomic wallet/inventory/fees/receipts, P2P ownership/escrow and fault/concurrency tests before proposing release.
- Review remaining service/provider settlement and reconciliation paths for idempotency, tenant/account checks, durable unknown outcomes and transactional updates. Never auto-refund an unknown provider order or blindly retry it.
- Keep withdrawals, suspected-vendor restrictions and other financial holds until separately reviewed and explicitly authorized.
- Personal↔Business switching must remain free; pricing-level upgrades are separate. Verify zero/default Smart Earner prices and atomic/idempotent charging for any paid pricing-level changes.

## 6. Crypto — staging paused, not a release task to activate now

- Complete independent custody/account-model/region/product eligibility evidence and provider review.
- Complete approved staging/operator checks, customer allocation/address correctness, deposit dedup/finality/risk checks, durable accounting/reconciliation, polling/retry/overlap recovery and fault tests.
- Verify secured webhook/worker ingress, monitoring and operator recovery using the documented controlled-staging checklist.
- Customer addresses, credits, trading and withdrawals remain non-live. Source configuration/gateway cards or local preflight success do not authorize activation. Any staging resumption requires explicit approval.

## 7. Marketplace, gateways and platform administration — final acceptance

- Finish inventory/acceptance of all real bundled adapters and their category/listing controls, beyond the VTU/SMS discovery subset. SAAS new entries inactive/private; explicit free/zero price must not auto-activate services.
- Leave NON-SAAS marketplace/free behavior untouched.
- Verify SAAS System Update remains platform-admin only; update/clone/privilege routes cannot bypass permissions.
- Verify Bybit/Klasha gateway-card display, configurable SAAS commercial defaults and entitlement/storage rules without implying live crypto readiness.
- Re-test user API-enable persistence/effective policy notices and the enabled-service/account-pricing controls. Several source fixes have landed; full UI/device/provider acceptance remains distinct.
- Validate app-update broadcasts/worker delivery, duplicate prevention, failure notices and operator recovery.

## 8. Rewards/referrals and public pages — acceptance/regression

- Verify configurable referral reward (default 1,000 coins), $100 verified Crypto/International Transfer qualification, duplicate/abuse checks and no prequalification withdrawal.
- Preserve rewards/referrals and existing support/automation while testing account, transaction and reversal flows.
- Verify public /ReferAndEarn.php, /blog.php, /services/<slug>.php and /services/CoinConversion.php, including navigation/mobile rendering and intended public/auth boundaries.

## 9. Test-suite and quality debt — release blocker

- Investigate and correct known broad fintech CoinConversion body-baseline failure; do not silently rebaseline without proving expected behavior.
- Replace obsolete Google-recovery and authenticator/QR source assertions with tests of current secure behavior. Do not restore removed insecure mechanisms to make old tests pass.
- Run the complete suite, not only workflows triggered by changed paths, plus both-edition disposable MySQL, concurrent/fault-injection tests and browser no-reload/redirect tests.
- Add end-to-end tests for the remaining role/account/policy matrix; review security-sensitive logs and data leaks, loading performance and accessibility/error handling.
- Merged focused checks do not establish that the entire project is green.

## 10. Build, provider and operational verification — not done by merging

- User rebuilds/signs the native apps locally. Verify all six variants on physical devices and supported OS versions; confirm signing/upload-key/store state with current evidence (older repository notes are not current store verification).
- Coordinate app/server rollout: legacy browser sessions, unbound tokens and personal-key clients require fresh sign-in/updated builds. Already-open nonce/ticket forms need reload.
- Validate live mail, Google, KYC, payments/FX and service-provider credentials/callbacks in an authorized nonproduction environment; no production/provider success is claimed by repository tests.
- Prepare backup/restore, migrations, secrets, worker/cron configuration, monitoring, alerts, rollback, incident response and reconciliation runbooks. Do not perform destructive live cleanup without separate authorization.
- Obtain explicit deployment and held-service/staging approvals; merge is not deployment. Perform post-deployment smoke checks and reconciliation only under the approved release plan.

## Recommended order

1. Close security/compliance/alternate-route audit and obsolete-test debt.
2. Finish inline security UX and full web/app feature/history/receipt acceptance.
3. Complete held financial implementations and authorized provider/staging validation without releasing holds early.
4. Rebuild/device-test apps, approve release plan, then deploy in controlled stages.

A project-wide completion declaration requires evidence for every applicable item above, not just another merged security batch.

# VTU purchase failure and loading review

## Scope and live-verification limits

Reviewed the screenshot's **Unable to proceed with charges** path, shared wallet posting, all shipped VTU gateway cURL call sites, supplier/website-API dispatch and the login page in both editions. The current source fixes are not a deployment, a production database migration, a paid test purchase, or proof that every external provider/account/plan is currently working.

The public `https://v6.datagifting.com.ng/web/Login.php` content was retrieved through the page fetcher. A direct sandbox cURL timing attempt failed during connection/TLS before any HTTP response (`http=000`). Therefore there is **no reliable live TTFB, browser waterfall or before/after latency measurement** from this environment. The failed attempt is not evidence that the public website is down. Server load, worker exhaustion, DNS, SMTP, current database engine/version and production credentials were not available for inspection.

Crypto remains observation-only. This urgent VTU reliability slice takes priority over the next custody/account-registry implementation; no custody registry, attribution approval workflow, deposit settlement or live crypto activation is delivered by this change.

## Findings and fixes

### 1. Shared wallet rejection before a supplier is called

The VTU handlers call `chargeUser()` before including the provider adapter. The screenshot's generic charge error comes from that pre-provider failure branch; it does not by itself identify a ClubKonnect, VTPass or HDK rejection.

`bc-incident-wallet.php` used `@@session.in_transaction`, a MariaDB-specific variable absent on MySQL 8. This reproducibly refused charges on MySQL. It now checks autocommit and executes next-transaction `SET TRANSACTION ISOLATION LEVEL REPEATABLE READ`; an active transaction is refused without committing caller work. Real MySQL tests cover normal debit, duplicate rejection, linked refund-once, concurrent same-reference debits, autocommit-off and readless nested transactions, receipt failure rollback and insufficient funds.

The InnoDB requirement, wallet lock, atomic receipt/replay claim, insufficient-balance check, exact linked refund, security failure guard and existing financial holds remain intact. **Do not bypass those requirements to force a purchase through.** No production table was converted or enabled. A MariaDB deployment, missing event table or nontransactional schema can still have a different root cause; operator verification is required.

Wallet failures now log a safe reason and surface a `WALLET_<CODE>` support code in the VTU charge-failure branch. Database/SQL errors and credentials are not returned. Codes include `TRANSACTION_UNAVAILABLE`, `TRANSACTIONAL_SCHEMA_REQUIRED`, `STORAGE_ERROR` and specific atomic-write refusal codes. The same diagnostic is available across the shared service handlers, not just data.

### 2. Wrong adapter selection and malformed API URLs

Most handlers built adapter filenames directly from admin-entered API base URLs. Uppercase hosts, `www`, `https://`, paths or a trailing slash could select the generic website-API adapter instead of the intended supplier. The generic adapter could then construct `https://https://...` or duplicate `/web/api/` paths.

`bc-vtu-runtime.php` now centralizes:

- Case-insensitive host extraction for adapter selection, including `www` and the Nellobyte/ClubKonnect hostname alias.
- HTTPS origin construction for named provider adapters, independent of pasted endpoint paths. Existing provider payload/auth/status mappings remain unchanged.
- Website-API construction for all localserver service, verify and requery adapters, preserving an installation prefix while avoiding duplicate `/web/api/<service>.php` suffixes.
- Rejection of ambiguous credential-bearing/query/fragment/non-443 base URLs. These were not supported by the old hostname-only configuration contract. Put keys in the configured credential field, never in the base URL.

For the **Localhost/website API** profile, use the upstream website's real HTTPS domain (and installation prefix if any), not the literal machine name `localhost` or `127.0.0.1`. It is an authenticated supplier API integration, not permission to bypass upstream PIN/API approval, tenant policy, balance or license gates. TLS validation is never disabled.

A bounded `X-BC-VTU-Hop` header is forwarded by website API adapters. Updated service endpoints reject malformed or excessive hops before session/DB work, limiting cycles in reseller routing. The header grants no authentication or free-fulfillment privilege. It is a safety bound, not a substitute for checking that each vendor route ultimately terminates at an actual supplier. Older upstream installations/proxies that do not preserve this header need independent loop/worker-pool review.

HDK's static plan mappings still require checking against the actual purchased plan catalogue. Different editions have pre-existing different mappings; this fix does not invent or silently replace plan IDs. Unsupported plans must be configured/mapped after supplier verification, not forced through as success.

### 3. Long transport and notification delays

Every shipped gateway cURL call now uses one shared execution wrapper, including purchases, verification and requery. Old duplicate 60-second overrides no longer override the effective budget:

- Connect budget: 8 seconds.
- External call total: 30 seconds.
- Website API total: 45 seconds, providing margin over a downstream supplier call.
- No automatic retry or provider failover for an uncertain paid request.
- TLS host/peer checks remain enabled; redirects are not followed with credentials.

These are **per-call bounds, not a guaranteed end-to-end response time**. Multi-hop routes, DB waits, synchronous SMTP, bootstrap work and PHP/web-server limits can add latency. A provider may finish after our timeout: unknown delivery still follows existing pending/requery handling, never an invented success or an automatic blind refund. Do not repeat a pending paid request merely because the browser took too long.

`[VTU_TRANSPORT]` logs slow/error requests with adapter filename, HTTP status, errno and DNS/connect/total milliseconds. It does not include URL query strings, API keys, headers, phone numbers or response bodies. `[WALLET_TIMING]` separates mutation time from post-commit notification time when wallet posting takes at least one second. This is important because wallet notices still use synchronous SMTP after commit; **this change does not add a mail outbox or pretend SMTP is backgrounded**. Operators can now distinguish that delay from a supplier delay before choosing the next change.

### 4. Login script/render blockers

The auth shell used a render-blocking Google font stylesheet. Login used parser-blocking third-party jQuery and SweetAlert, plus a reference to `asset/pwa-handler.js`, which does not exist in the checkout.

- Optional auth font CSS is now non-render-blocking with the existing system-font fallback.
- Login no longer downloads unused jQuery.
- Login uses the already-shipped local SweetAlert library, deferred; flash alerts wait for DOMContentLoaded and have a text fallback if the optional library fails.
- Existing custom and biometric scripts are deferred. Biometric login, Google authentication, OTP, forced setup, lockout/PIN handling and password checks are retained.
- Missing PWA-handler requests were removed from Login/Register; no functioning PWA handler or service worker was deleted.

The async Google identity script remains optional for the Google sign-in button and does not gate ordinary password-form rendering. No login-security settings are cached less strictly or skipped to improve speed.

## Validation

- 428 no-network runtime/routing/loading checks, covering every shipped gateway call site, base URLs, TLS/timeouts, single submission, loop-header behavior and login dependencies.
- 130 no-network adapter contracts using the real ClubKonnect and VTPass airtime/direct-data adapters, HDK SME/corporate/shared-data adapters and website-API airtime/data adapters in both editions. Synthetic success and timeout/pending responses only; **not supplier certification**.
- 30 wallet storage-double checks plus real MySQL wallet tests in both edition jobs.
- Shared service-handler WEB/APP/API/AI PIN, suspension and balance-gate tests.
- Gateway verdict/refund safety, login OTP/bootstrap, crypto and existing security/performance regression suites.
- Native CI parses all shared service handlers and gateway files.

The broad fintech CI job has pre-existing failing tests. These were reproduced against unchanged base commit `32f7e11e47b7672228c644a7da730d12d1d9059c` using a source-only export (no branch switch):

- `plate_migration_test.php`: stale captured CoinConversion body contract.
- `backoffice_regression_test.php`: two old remote Bootstrap Icons expectations after local icons were introduced.
- `upgrade25_test.php`: eight old referral/default and virtual-card activation expectations.
- `provider_live_test.php`: old virtual-card refusal reason expects `endpoint_not_configured`, actual secure gate returns `security_review_required`.

Those unrelated production paths and activation/security gates are unchanged. Their failures are not hidden or resolved by relaxing security. Two related source assertions were updated legitimately: optional SweetAlert cache-busting in the performance test, and allowing the local alert library without reinstating the retired auth template. The VTU eval fixture now preserves the handler's original `__DIR__` for its real shared include.

## Operator rollout and remaining checks

1. Review the PR and back up application/database. Deploy both the shared runtime **and** modified gateway/handler files together; partial deployment will fail includes. Reload/clear OPcache as appropriate. No automatic production deployment is performed.
2. In protected server diagnostics, confirm database version and actual InnoDB engines for `sas_users`, `sas_transactions`, `sas_vendors`, `sas_vendor_transactions` and `sas_wallet_mutation_events`. Verify the reviewed wallet-event migration exists and its schema/indexes match. Never blindly convert/drop tables on a live financial system; use a reviewed backup/restore/maintenance plan if migration is needed.
3. Review provider assignment per network/data type, account level prices, supported plan IDs, API approval, credentials format and upstream wallet funding privately in the admin console. Do not send API keys, passwords, OTPs, cookies or raw customer payloads in chat.
4. Check PHP error logs around one failing reference for `WALLET_MUTATION_REFUSED`, `WALLET_TIMING` and `VTU_TRANSPORT`. An error before adapter execution means no call from that handler; a timeout after submission means reconcile, not resubmit. Check web-server/PHP slow logs for time outside these stages and for loopback worker starvation.
5. Measure Login with a real browser waterfall (disable cache; record document TTFB separately from scripts/fonts and DOMContentLoaded), before and after rollout. Inspect missing/blocked resources, redirect chains and service-worker/cache state. Public timing can also be taken without cookies: `curl -sS -o /dev/null -w 'http=%{http_code} ttfb=%{time_starttransfer} total=%{time_total}\n' https://v6.datagifting.com.ng/web/Login.php`.
6. Validate authenticated read-only supplier endpoints first. A real paid tiny-value purchase/requery/refund test requires separate operator authorization and reconciliation for each selected provider/service. Do not run automatic paid smoke tests across all services.
7. If SMTP dominates the new timing logs, the next VTU step is a durable transactional notification outbox and monitored worker, not dropping receipts/notifications or weakening charge checks. Provider-specific stock, current plan catalogue, KYC/API restrictions, production DNS/firewall and live service delivery remain unverified here.

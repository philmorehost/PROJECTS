# DGV7 financial-incident source security audit

**Review date:** 2026-11-18 (session date).  
**Reviewed base:** `7454158556ca9304a620af4656a8ad6ae79bb1a5`.  
**Scope:** VTU DGV7.0 SAAS and NON-SAAS PHP source; DG7, JIKABIZ and MZEEVTU Android/iOS source.  
**Disposition:** **Do not treat the current source as safe for unrestricted financial operations.** Critical weaknesses remain. This is a source-audit report, not an incident attribution or production penetration-test certificate.

## Subsequent remediation status

Working-source changes now exist. See [SECURITY_REMEDIATION_STATUS.md](SECURITY_REMEDIATION_STATUS.md) for finding-by-finding treatments, deliberate feature holds, current tests and outstanding release gates. The report below remains a historical account of the reviewed base, not a description of every current line. Remediation is incomplete and no production deployment has occurred.

## Executive conclusion

The reported loss is credible enough to require immediate containment. The current checkout contains multiple ways for authentication and financial state controls to fail. Particularly serious are an externally selectable PIN-only bypass in shared step-up helpers, authenticated SQL injection, untrusted payment-beneficiary records, and a simulated virtual-card liquidation that credits real wallet funds.

These findings do **not** prove which route was used, that the suspected vendor was responsible, or that the live installation matches this commit. A new frontend, application build, WAF or earlier passing unit tests do not establish financial-system integrity. Production logs, provider settlement records and database history must be reconciled.

**No application fixes or deployment were performed in this audit.** Only this report, an inventory and offline characterization probes were added. No production endpoint was attacked, no real payment was initiated and no credential value is reproduced here.

## Method and limitations

- Inventoried **788 SAAS PHP files** and **679 NON-SAAS PHP files**, then searched both trees for SQL sinks/input sources, money updates, admin/session entry points, callbacks, uploads, command execution, TLS disabling and CSRF patterns. Counts include tests and auxiliary scripts; they are **not counts of manually reviewed files or vulnerabilities**.
- Manually traced the principal findings through their caller, bootstrap and helper code. Reviewed mobile storage, networking, manifests and new portal code across all three brands. Duplicate app source was compared by relevant patterns, not independently built.
- Executed the actual shared step-up helper implementations offline with mocked PIN and second-factor boundaries. All three roles in each edition demonstrated the bypass described in F01. This is helper-level reproduction, not a live-account exploit.
- SQL injection, XSS and business-logic issues below are confirmed unsafe source flows, **not database/browser exploit demonstrations**. Race behavior is inferred from statement ordering and missing atomic claims; real concurrent MySQL/provider tests remain necessary.
- No production filesystem, access/error logs, database, reverse-proxy configuration, installed schema, provider dashboard or live secrets were available. No Android SDK/Xcode builds, runtime mobile tests, full dependency-CVE audit or exhaustive path-sensitive analysis was performed.
- File references below are relative to `VTU/`. Unless marked otherwise, both editions contain the relevant defect. SAAS line numbers are given first; the inventory records selected NON-SAAS equivalents. Line numbers apply to the reviewed base.

## Immediate incident containment — before remediation

1. Disable outgoing payouts at the **payment provider as well as the application**, including API-initiated transfers, automatic withdrawal approval and alternate admin approval paths. A database/application compromise can bypass a UI switch. Temporarily disable manual wallet credits and virtual-card liquidation/funding routes.
2. Preserve a timestamped, access-controlled database backup, webroot snapshot, web/PHP/reverse-proxy/WAF logs, hosting access logs, cron definitions and provider transaction exports. Record the server timezone. Do not delete the suspected account or overwrite evidence with an unrecorded redeploy.
3. Suspend the suspected vendor and affected accounts, revoke app/developer tokens and admin sessions, and use the existing global session invalidation where applicable. Verify revocation on legacy endpoints too.
4. From a clean administrative device, rotate exposed provider keys, hosting/cPanel/SSH credentials, database credentials and SMTP credentials; enforce MFA. Preserve evidence first where feasible. If the server itself may be compromised, rebuild on a clean host rather than trusting in-place file replacement.
5. Ask payment providers to freeze/review suspicious payouts and preserve beneficiary, IP, idempotency and settlement records. Do not mark pending transfers failed or refund them until provider status is known.
6. Reconcile balances from verified external money movements and an immutable transaction export, not merely the current `balance` column. Engage your host and an incident-response professional for active theft; assess applicable breach-notification obligations.

## Findings summary

| ID | Severity | Finding | Principal prerequisite |
|---|---|---|---|
| F01 | Critical | Client-influenced PIN-only flag bypasses second factor | Authenticated sensitive-action session + valid PIN; CSRF token where enforced |
| F02 | Critical | SQL injection in funding verification gateway selector | Valid user credential/app session and a reference that passes preliminary checks |
| F03 | High | Vendor-admin SQL injection in gift-card cache search | Vendor-admin session; fallback cache path |
| F04 | Critical | Virtual-card liquidation credits wallet without provider liquidation | User session and a local card record with positive recorded balance |
| F05 | Critical | Anonymous checkout creation accepts tenant and beneficiary from request | Public endpoint reachable |
| F06 | High | Funding verification does not bind payment owner to authenticated beneficiary | User credential + valid provider reference not already handled by that path |
| F07 | High | Withdrawal rejection is not atomically claimed or restricted to withdrawal transactions | Vendor-admin action permissions/PIN; F01 can weaken second factor |
| F08 | High | Ambiguous payout failure releases claim for another external payout attempt | Pending approval + ambiguous provider result/retry |
| F09 | High | Credit/approval paths use check-then-act instead of durable idempotency | Concurrent or repeated financial operations |
| F10 | High | SAAS debit helper can report success after zero-row debit | Concurrent balance movement / affected-row mismatch |
| F11 | High | NON-SAAS wallet helpers still write stale absolute balances | Concurrent wallet requests |
| F12 | High | Legacy card money actions lack transaction authorization/reservation; funding call has wrong arguments | User session; configured provider for issuance |
| F13 | High | Transaction mutations omit tenant scope | Colliding/attacker-controlled references and a reachable modifying caller |
| F14 | High | Vendor can poison shared gift-card catalogue; output is unescaped | Vendor-admin session and another admin viewing cached content |
| F15 | High | TLS verification disabled across legacy provider/hosting integrations | Network interception or malicious network endpoint |
| F16 | High | Public auxiliary purchase test embeds a provider credential | SAAS test script deployed and reachable; valid embedded key for financial impact |
| F17 | Medium | CSRF gaps and money-changing GET route | Logged-in victim browser; deployment cookie policy affects cross-site feasibility |
| F18 | Medium | Mobile bearer credentials stored in ordinary preferences | Device/backup compromise or privileged local access |
| F19 | High | Card security code is persisted in plaintext | Database/backup/read-path compromise |

Severity describes potential impact and prerequisites, not proof of successful exploitation or a CVSS calculation.

## Detailed evidence and remediation

### F01 — Critical: request-controlled second-factor bypass

**Evidence:** both editions `func/bc-stepup.php:383–480`, especially lines **398, 437, 476**. After PIN verification, user/admin/super-admin helpers return success when the input array contains a truthy `pin_check_only`. Intended server-generated PIN-only policies share the same input namespace as untrusted request fields. Financial callers pass **raw `$_POST`**, e.g. `bc-admin/Withdrawals.php:45`, `bc-admin/PaymentOrders.php:5–10`, `bc-spadmin/ShareFund.php:18–19`, and `bc-spadmin/PaymentOrders.php:35`.

**Impact:** a valid authenticated session and PIN can satisfy supposedly PIN-plus-OTP/TOTP financial approval without invoking the second-factor check. This does **not** bypass login, the PIN, or independently enforced CSRF. The user helper has the same unsafe interface, but an exposed user money route passing it raw input was not established during this review.

**Offline evidence:** `tests/security_audit_stepup_probe.php` and `tests/security_audit_stepup_nonsaas_probe.php` execute the real helpers with mocked factor boundaries. Each prints three `VULNERABLE` results: the flagged call returns success and never invokes second-factor verification. A baseline call without the flag invokes the denied second factor.

**Fix:** remove policy toggles from request data. Use separate explicitly PIN-only helpers or a server-only typed policy argument that raw POST cannot populate. Financial callers must always demand second factor. Bind approval challenges to tenant, actor, action, reference, amount and beneficiary. Add negative tests for extra client fields on every approval route.

### F02 — Critical: SQL injection in funding verification

**Evidence:** `web/api/verify-funding.php:15,66` (NON-SAAS **15,39**). `gateway` is only lowercased/trimmed/`strip_tags`-processed and is interpolated inside the quoted `gateway_name` predicate. `strip_tags` is not SQL escaping; no allowlist is applied before this query. The query executes before branching on known providers.

**Impact:** an authenticated ordinary account can alter SQL semantics within a database query. Tenant predicates in the same string do not contain an injection. Cross-tenant reads and credential disclosure are plausible given the query and application DB privileges; arbitrary writes or full takeover were **not demonstrated**. Multi-statement support is not required for a SELECT injection.

**Fix:** prepared parameters plus a strict provider allowlist before any SQL. Review every endpoint reading provider/type/search identifiers; apply least-privilege DB credentials. Test malformed gateway values without reaching providers.

### F03 — High: vendor-admin SQL injection

**Evidence:** both editions `bc-admin/GiftCardSetup.php:109–124`: `country` and `search` flow directly from GET into `SELECT ... country_code='$country' AND product_name LIKE '%$search%'`. This executes when the Reloadly token is absent or mock.

**Impact:** a vendor administrator is not a trusted platform administrator. This provides a SQL injection boundary accessible to the type of account implicated in the incident, subject to the fallback condition. The root `.htaccess` query-signature filters are not an adequate SQL defense and do not make this flow safe.

**Fix:** parameterize both values, validate country code, and escape LIKE wildcards if literal matching is intended. Verify tenant DB isolation and read permissions. Test the fallback path, not only live-provider success.

### F04 — Critical: simulated card liquidation mints withdrawable wallet credit

**Evidence:** both editions `web/virtual-card-ajax.php:100–119`. The code explicitly simulates returning funds: reads local `balance_usd`, marks the card terminated/zero locally, and calls `chargeOtherUser(..., 'credit', ..., 'Card Liquidation', ...)`. It does not obtain a successful provider liquidation/termination settlement. The row query includes username but **omits vendor_id**.

**Impact:** a user can receive NGN credit while the corresponding card/provider value has not actually been withdrawn. Stale local balances or overlapping calls worsen exposure; a card spent at the provider may still have a positive local value. Financial impact requires such a local card record; no live-provider theft was attempted.

**Fix:** disable the route until real provider liquidation and reconciliation exist. Lock/reserve the card and use a unique liquidation event. Credit only the verified recoverable/settled amount, scoped to tenant and owner. Do not refund on an unverified timeout.

### F05 — Critical: public creation of authoritative-looking payment records

**Evidence:** both editions `web/finance-ajax.php:15–74`. `create_checkout` executes **before** the session rejection at lines **75–79**. It accepts `username`, `vendor_id`, `reference`, `amount` and vendor/user target from POST, then inserts checkout/pending transaction rows for those identities without authenticating ownership. Reference existence is not claimed globally/atomically. Later verification/callback flows trust these rows to resolve a beneficiary.

**Impact:** unauthenticated clients can plant payment-beneficiary mappings and contaminate financial records across tenants. In combination with an unprocessed valid payment reference, subsequent verification can attribute real payment funds to an attacker-selected account. This is **not** proof that an arbitrary fake payment reference alone produces free money; provider verification is still required.

**Fix:** authenticate first, derive tenant and beneficiary from the server-authenticated actor, generate unguessable references server-side, bind provider/currency/amount to an immutable payment intent, and enforce a unique claim before exposing the reference. Ignore client-provided identity and vendor/user target switches.

### F06 — High: payment ownership and currency binding are incomplete

**Evidence:** `web/api/verify-funding.php:50–55,166–190` (NON-SAAS analogous authentication/credit tail). The authenticated user's username is the credit target, but the checkout's username/customer identity is not compared with that actor. Success verification and amount capping do not establish the rightful beneficiary. The code also lacks an explicit expected-currency comparison at this credit boundary. `web/finance-ajax.php:404–420` derives ownership from mutable, reference-only lookup records (see F05).

**Impact:** a not-yet-processed valid payment reference can potentially be claimed by a different user at the same merchant; a provider payment in an unexpected currency must not be interpreted as an NGN amount. Success depends on the provider/ref state and competing handlers. No arbitrary-reference payment forgery is asserted.

**Fix:** immutable payment intent binding merchant, gateway, canonical provider ID, tenant, user, currency and expected amount; verify all fields from the authenticated provider response. Resolve the beneficiary from that intent, never from whichever caller presents the reference.

### F07 — High: unsafe withdrawal rejection and action selection

**Evidence:** `bc-admin/Withdrawals.php:26–74,187–206` (NON-SAAS rejection **183–202**). Backend selection restricts vendor/reference but **not transaction type**. Approval claims pending status atomically; rejection only checks the previously loaded pending status and credits a refund **before** a terminal state update. Cancel/re-pending transitions also rely on stale status. The refund text `Refund for rejected withdrawal ...` does not match the `Ref: '...'` pattern in SAAS `func/bc-func.php:925–936`.

**Impact:** rejection can act on a pending non-withdrawal row without demonstrating an original debit; overlapping rejection requests or rejection racing approval can refund more than once or refund a transfer still being paid. OTP consumption reduces some concurrency cases but is not a transaction lock; F01 further weakens that barrier.

**Fix:** select the explicit withdrawal request entity and original held debit; atomically claim every transition, serialize approval/rejection/cancel, and issue an idempotent refund tied by a dedicated original-debit ID. Never use description text as the refund uniqueness key.

### F08 — High: ambiguous provider failure can lead to duplicate payouts

**Evidence:** `bc-admin/Withdrawals.php:137–150,224–229` (NON-SAAS reset **220–225**). Approval locks to processing, but remaining status 6 is reset to pending at request end without distinguishing definite rejection from timeout/unknown outcome. Payhub/Paystack initiation calls here are not passed a durable request-specific idempotency key by this caller.

**Impact:** provider may accept a payout while this request receives no usable success response. Releasing to pending enables another payout attempt. This is a distributed-state/reconciliation risk, not a demonstrated provider double payment.

**Fix:** durable idempotency reference passed to the provider, an `unknown/reconciling` state, and verification by original provider reference before retry/refund. Keep an outbox/audit record of the submitted intent before the network call.

### F09 — High: check-then-credit is not financial idempotency

**Evidence:** both editions `bc-admin/PaymentOrders.php:27–39` and `bc-admin/FundTransferRequests.php` approval/refund branches: read pending state, credit, then update state. SAAS `web/finance-ajax.php:456–480` and `web/api/beewave-webhook.php` use success-row existence checks followed by credits rather than an atomic provider-event claim. SAAS `func/bc-tables.php:701,729` defines a non-unique transaction reference/index. `chargeOtherUser` does not make every credit and its durable unique event a single atomic operation.

**Impact:** concurrent approvals, verification and webhook deliveries may post multiple financial credits. This must be fixed across entry points; adding a check in one webhook is insufficient. Do not assume every reference can be made globally unique without auditing legitimate ledger/refund relationships.

**Fix:** unique scoped provider-event/payment-intent IDs, explicit atomic state claims, SQL transactions covering ledger/event/wallet changes and an outbox for external work. Test simultaneous webhook/API verification and repeated/manual approvals against a real transactional database.

### F10 — High: zero-row SAAS debit can be treated as successful

**Evidence:** SAAS `func/bc-func.php:1183–1193` in `chargeOtherUser` debit. After conditional `UPDATE ... balance >= amount`, code checks whether a follow-up balance SELECT returned a user. An UPDATE that matched no sufficient-balance row still returns SQL success; the following SELECT still finds the account, so the affected-row failure branch is skipped. The function may return success without a debit. Transaction insertion precedes the conditional update.

**Impact:** concurrent spending can be recorded as paid despite no corresponding debit, depending on the caller. This specific implementation is SAAS-only; NON-SAAS has the distinct F11 defect.

**Fix:** capture affected rows immediately after the debit UPDATE and require exactly one, before any other query; use a transaction with the corresponding unique ledger entry, rolling back on any failure. Test two concurrent debits against funds covering only one.

### F11 — High: NON-SAAS lost-update wallet races

**Evidence:** NON-SAAS `func/bc-func.php:718–719,757–827` and vendor-charge branches around **903–938**. `chargeOtherUser` and credit branches compute balance from a read then write an absolute new balance without making the entire read/check/write/ledger operation transactional. This differs materially from the SAAS relative-write improvements.

**Impact:** overlapping requests can overwrite intervening debits/credits, resurrect spent balance, or lose legitimate credits. Existing hardening is not consistently ported between editions.

**Fix:** port a tested atomic ledger/wallet implementation, use conditional deltas and appropriate row locking, and make event/transaction and balance updates a single SQL transaction. Verify schema engine and run concurrency tests separately for both editions.

### F12 — High: unsafe and broken legacy virtual-card actions

**Evidence:** both editions `web/virtual-card-ajax.php:40–97`: issuance contacts provider after a non-locking balance read, then attempts a local debit without checking debit success; issuance/funding do not require PIN/step-up. The route has no CSRF validation and `bc-config.php` does not automatically validate every POST. At line **87**, funding passes **four arguments** `(vid, uname, card_ref, amount)` to the helper declared in `func/bc-virtualcard-flutterwave.php:82` as **three** `(vid, card_ref, amount)`: the helper receives shifted values. Card reveal/liquidation queries omit tenant scope. Reactivation does not establish user ownership before the helper call.

**Impact:** issuance can consume provider funds without a guaranteed wallet debit; normal top-up can fail or address wrong arguments. Card operations may cross user/tenant boundaries where identifiers/username collisions permit. The existence of a newer safer API does not remove this reachable legacy route.

**Fix:** retire the duplicate route or route all calls through one policy-enforced implementation with tenant+owner checks, bounded positive amounts, PIN/step-up, durable reservation before external work and checked debit/settlement. Correct signatures and add contract tests. Search all callers when changing helpers.

### F13 — High: reference-only transaction updates cross tenant boundaries

**Evidence:** SAAS `func/bc-func.php:1648–1675`, NON-SAAS **1208 onward**: `alterTransaction`/`alterVendorTransaction` update by reference alone. SAAS `bc-admin/Transactions.php:335` also changes rows by reference alone. F05 permits arbitrary reference creation; references are not globally unique in the reviewed SAAS table definition.

**Impact:** modifying one tenant's transaction can affect another tenant with the same reference. This is a confirmed missing-scope condition, not proof of unrestricted cross-tenant access on every caller. Dynamic column names also lack a fixed allowlist; **no untrusted-column caller was established**, so that is a helper-design risk, not a separate confirmed SQL injection.

**Fix:** update by immutable row/ledger ID plus vendor ID; use explicit column allowlists. Scope joins such as withdrawal/history joins by tenant too. Prove that a tenant cannot mutate a fixture with the same reference in another tenant.

### F14 — High: cross-tenant catalogue poisoning and stored XSS

**Evidence:** both editions `bc-admin/GiftCardSetup.php:8–45,62–98`: vendor-admin submitted JSON is written to the **shared** `sas_global_giftcard_products`, including product names, logo URLs and instructions. There is no trusted server-side catalogue lookup to establish these values. Lines **263–265** render the logo fallback in JavaScript/HTML and product name without context escaping. The cached fallback reads shared entries at **124–142**.

**Impact:** one vendor can alter shared product metadata and persist executable markup for another vendor administrator viewing the fallback catalogue. Actual browser execution was not tested. CSP permits inline script and therefore is not an adequate safety net. A vendor-admin session compromise can be more damaging than cookie theft alone: same-origin script can act through the browser.

**Fix:** only platform-trusted/server-fetched data may update the global catalogue. Store vendor overrides separately. Escape HTML text/attributes, avoid inline handlers and unsafe script interpolation, validate image schemes/hosts, sanitize any intentionally allowed rich HTML and adopt a restrictive nonce-based CSP after compatibility testing.

### F15 — High: provider and hosting calls disable TLS certificate verification

**Evidence:** source inventory finds this pattern in **188 SAAS PHP files** and **179 NON-SAAS PHP files** (includes auxiliary/test files, not necessarily all active routes). Examples: `func/cpanel-func.php:66`, SAAS `func/bc-func.php:6644`, multiple `func/api-gateway/*` purchase/verification handlers. Exact filenames are in `SOURCE_AUDIT_INVENTORY.json`.

**Impact:** an on-path attacker may impersonate provider/hosting endpoints, steal API credentials, modify service results or financial responses. Exploitation requires network positioning; it is not equivalent to unauthenticated Internet access. Arbitrary configured API hosts warrant a separate SSRF review; broad SSRF exploitation was not established here.

**Fix:** enable peer and hostname verification using a maintained CA bundle; remove insecure retries. Validate/allowlist outbound hosts, protocols, ports, redirects and resolved IP ranges where URLs are configurable. Correct certificate errors rather than suppressing them.

### F16 — High: publicly runnable purchase test and embedded credential

**Evidence:** SAAS `test_hdkdata.php:15–42` contains a hardcoded provider token and executes a provider purchase request without authentication. Root `.htaccess` does not explicitly deny this script or the entire test/scratch trees. The configured plan/quantity appears inconsistent, which may cause failure, but that is not an access-control boundary. The credential value is deliberately omitted from this report.

**Impact:** if deployed, reachable and the token remains valid, unauthenticated hits can invoke provider requests with the embedded credential. Financial spending is conditional on provider validation. Repository/history exposure is itself enough to warrant key rotation.

**Fix:** revoke/rotate the token, remove test/scratch tools from deployment artifacts, deny them at the web-server layer, scan repository history and deployed files for other embedded secrets, and use a secret manager/environment configuration. Do not assume deleting the file revokes the credential. NON-SAAS does not contain this exact file.

### F17 — Medium: CSRF coverage is not universal; financial mutation via GET

**Evidence:** both editions `bc-admin/GiftCardSetup.php` POST install actions have no CSRF validation. SAAS `bc-admin/Transactions.php:314–335` accepts `mark_success` by GET, debits the user's wallet and marks a transaction successful without action-specific CSRF/step-up. Bootstrap authentication does not enforce CSRF on every request. Settings TOTP unlock is not itself CSRF protection.

**Impact:** a logged-in admin may be induced to perform unwanted actions. Cookie SameSite behavior, path, browser and deployment affect exploitability. The money-changing GET is particularly unsafe because top-level cross-site navigation can carry Lax cookies. This does not assert every admin POST is vulnerable: several approval routes correctly call `bc_validate_csrf`.

**Fix:** POST-only mutations, strict token validation, action-specific authorization/step-up for money, explicit Secure/HttpOnly/SameSite session cookie configuration and origin validation as defense in depth. Review all state-changing GETs and form handlers, not just pages already labelled financial.

### F18 — Medium: mobile bearer credential storage is not hardened

**Evidence:** all three Android `app/src/main/java/<package>/util/PreferenceManager.kt:8–9,24–31` store the login credential in ordinary `SharedPreferences`; manifests set `android:allowBackup="true"`. All three iOS `<source>/Services/AppNetworkService.swift:18–20` persist the credential in `UserDefaults` under `user_api_key` (the name can hold a bearer app credential; it is still sensitive).

**Impact:** backups and device/privileged-local compromise may expose reusable session credentials. Actual Android backup availability depends on OS/transport policy. This does **not** provide a remote website attacker direct access to every phone and is not the leading explanation for the reported vendor intrusion.

**Fix:** iOS Keychain with appropriate accessibility/device binding; Android Keystore-backed protection and explicit backup/data-extraction exclusions for credentials. Use short-lived revocable app sessions, clear secrets on logout and account switching, and test biometric/lock bypasses on real devices. Biometrics are not a substitute for server-side authorization.

### F19 — High: plaintext CVV persistence and PAN fallback

**Evidence:** both editions `func/bc-virtualcard-flutterwave.php:59–75`: provider `cvv` is inserted into the local card row; `masked_pan` falls back to provider `card_pan`, which may contain an unmasked PAN. `web/virtual-card-ajax.php:24–34` retrieves the stored CVV after PIN verification with incomplete tenant scoping.

**Impact:** database backups, SQL injection or an overly broad reveal query may expose card security data. PIN checks do not protect database copies. This requires urgent PCI/provider compliance review; storage of sensitive authentication data such as CVV may be prohibited even when encryption is used.

**Fix:** do not persist CVV; remove any retained sensitive data through an evidence-preserving compliant process and consult provider/compliance guidance. Store only permitted tokenized/masked card metadata. Reveal via an approved provider-hosted/tokenized flow with scoped strong authentication.

## Existing controls observed — do not remove these

- Withdrawal approval has a pending-to-processing conditional update and an own-email approval check. These are useful but do not address rejection races, F01 or ambiguous external outcomes.
- Shared OTP verification consumes successful OTPs with a conditional `used_at IS NULL` update and checks affected rows (`func/bc-security.php:782–817`). This part is not described as an OTP-replay bug.
- The Flutterwave invoice return page does not trust callback query data to credit balances. Keep verified-webhook credit as the only completion mechanism.
- New mobile portal code uses single-use handoff tokens and restrictive WebView transport policies. Android networking disables cleartext by default and production HTTP logging is NONE in the reviewed client.
- KYC uploads include extension/size checks and image validation, with generated deny rules for private evidence. Deployment must verify those rules are honored on the actual web server; this review does not certify Nginx/AllowOverride behavior.

## Remediation and release gates

**P0: contain now:** provider-side payout freeze, suspect-account/token suspension, evidence preservation and credential rotation. Disable legacy virtual-card financial actions and unauthenticated checkout creation until corrected.

**P1: before reopening money movement:** fix F01–F13 and F19 on **both editions**; remove public purchase/test tools; enforce TLS for every active financial provider. Use one auditable financial execution pipeline rather than duplicate legacy/new routes.

**P2: before full production acceptance:** fix catalogue poisoning/XSS and CSRF coverage, harden mobile storage, review dependency CVEs and all configurable outbound URLs, inspect deployed filesystem for webshells/new cron jobs, verify session/cookie configuration, and complete an independent security review.

Required regression scenarios include:

- Extra request fields cannot reduce factor requirements; missing/wrong/reused factors fail on every approval and credit route.
- Tenant A cannot query/update tenant B's account/card/transaction even with duplicate references/usernames.
- SQL metacharacters are treated only as data in all provider/search/filter fields.
- Payment owner, tenant, provider, canonical ID, amount and currency must match immutable server-created intent.
- A simultaneous webhook + API verification produces exactly one ledger credit.
- Parallel approval/rejection/cancel and parallel debits cannot mint refunds or pay twice.
- Provider accepted-but-response-lost leaves an unknown/reconciling state, not a retryable new payout.
- Card issuance/funding is preceded by a successful durable reservation; liquidation credits only provider-confirmed settled value.
- Financial mutations require POST, CSRF and action-specific authorization; malicious catalogue metadata renders as inert text.
- No secret-bearing test script is deployed; provider TLS validation fails closed; mobile logout/backup/lock behavior is verified on devices.

## Evidence needed to investigate the reported theft

Obtain a time-bounded export of: `sas_transactions`, vendor transactions, users/vendors balances and settings, bank-transfer history, checkout/payment intents, OTP/token issuance metadata (not plaintext secrets), security audit logs, admin login/access logs, API access logs and provider transfer/settlement records. Record before/after deployment versions and provider keys' rotation timestamps.

Search for abnormal withdrawal references, repeated credits/refunds, same-reference entries across tenants, unexpected changes to approval settings or PIN/TOTP enrollment, new beneficiaries, repeated verify/checkout requests, catalogue search anomalies and virtual-card liquidation requests. These are investigative leads, **not proof that this vendor performed them**. Preserve raw requests in restricted evidence storage; do not publish credential-bearing query strings or payloads.

## Re-running the offline characterization

From the repository root, after installing `VTU/tests/harness` dependencies:

```sh
node VTU/tests/harness/run-test.mjs tests/security_audit_stepup_probe.php
node VTU/tests/harness/run-test.mjs tests/security_audit_stepup_nonsaas_probe.php
```

Each currently reports three vulnerable helpers. Exit zero means the characterization ran; **it is not a passing security test**. After remediation, replace/extend these probes with assertions requiring second-factor denial and full route-level regression tests. Do not execute webroot purchase tests against production.

Supporting inventory: `VTU/docs/security/SOURCE_AUDIT_INVENTORY.json`. Findings are a prioritized, evidence-backed review, not a guarantee that all possible breaches have been found.

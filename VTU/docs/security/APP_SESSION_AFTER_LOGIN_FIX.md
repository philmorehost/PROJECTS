# Immediate session-expired message on Android security-question screen

## Source-confirmed failure path

The screenshot's message is emitted by Android `LockActivity` for every HTTP 401 from `web/api/security-quest.php`. It did not distinguish a missing session from a genuinely invalid/expired session.

The current Android login repository persists `data.app_token` (not the developer API key). However, all four security-question requests sent that value under the JSON name `api_key`. The protected endpoint extracts a session from Authorization / forwarded Authorization / X-App-Token or the explicit JSON `app_token` fields, not `api_key`. Android supplied Authorization only. The checked-in Apache configuration had no rule to preserve that header for PHP/FastCGI.

Consequently, a PHP hosting path that strips Authorization reproduces: successful login -> token persisted -> security-question JSON contains `api_key` -> no recognized session -> 401 APP_TOKEN_REQUIRED -> misleading "session expired" screen. The regression test reproduces this extraction mismatch. We have NOT inspected the live server's raw requests or authenticated database, so header stripping is a plausible deployment-specific trigger, not proven live incident attribution. APP_SESSION_INVALID can also mean revoked credentials, mismatched backend/tenant, incompatible deployed files or actual expiry; those checks must not be bypassed.

## Fixes

- Both editions' `.htaccess` preserve an existing Authorization header for PHP without putting credentials in URLs/logs. This helps already-built clients when the web server honors the rule.
- A narrowly scoped compatibility normalizer in `security-quest.php` accepts the released Android body field only when it contains the exact versioned app-session token shape, no explicit token field/header was supplied, and the ordinary mandatory `bc_fintech_require_app_account` validation follows. This can repair the affected already-built current Android clients without accepting developer API keys. Format alone NEVER authenticates a session.
- All three Android brands now send `app_token` for get/set/verify/reset and a redundant X-App-Token header for protected API calls alongside Authorization. Public login/register/recovery requests do not receive that stored session.
- Android redirects are disabled so the custom session header cannot be forwarded to another origin. Installations must use their canonical HTTPS backend origin, not rely on redirects carrying secrets.
- Missing-token HTTP 401 shows a transport/update/support retry message without clearing the saved login. Invalid-session 401 retains the sign-in recovery action; unknown 401 is not described as definite expiry. The lock is not dismissed on errors.

## Security invariants

Persisted hashed session lookup, tenant scope, expiry, credential-bound signature, account block, account-type/app eligibility, revocation and compliance checks remain unchanged. Invalid explicit credentials are never replaced with another credential. Developer API keys and unbound legacy tokens remain refused. No automatic token renewal/reissue, lifetime extension, security-question bypass, data deletion or financial activation is introduced. iOS already uses explicit app_token request fields; no iOS source change is needed for this mismatch.

## Validation and rollout

- `app_session_transport_test.php`: reproduce headerless old Android body, normalize exact versioned tokens, reject API keys, preserve explicit credential precedence, forwarded/alternate headers and endpoint ordering.
- `app_credential_mysql_test.php`: newly issued persisted sessions survive headerless security get/set/verify/reset normalization; developer keys, cross-tenant/credential-revoked sessions and invalid explicit bearer remain refused. Disposable CI database only; no live provider.
- `check-mobile-session.py`: both token headers, four explicit body-token calls, diagnostic distinction and existing OTP/session contracts for all brands.
- `.htaccess` context/parity checks passed (236 checks). All three Android debug and all three iOS simulator builds passed. These are not physical-device acceptance.

Deploy BOTH the compatibility helper and matching endpoint, plus `.htaccess`, to the applicable backend. Confirm the server honors the forwarding rule. That backend-only repair is designed for the already-built Android version which saved a real app token under api_key; it cannot upgrade an older app that only knows developer API keys. Do not enable personal API-key authentication as a workaround. Rebuild Android to include the permanent request fix and clearer error messages. Do not uninstall a live app or clear user storage merely to investigate.

Acceptance on an isolated account: password login and OTP login -> security question get -> verify/setup/reset -> authenticated dashboard/profile call, with headers present and with Authorization deliberately absent in a controlled staging proxy. Confirm an invalid or revoked session still fails. Verify the actual canonical origin and matching backend source. Keep screenshots/HTTP status and machine code only; do not share tokens, passwords, PINs, answers or raw authentication payloads. This work does not deploy the live backend or prove the actual affected user's session state.


## Completed build and merge evidence

- PR #281: https://github.com/philmorehost/PROJECTS/pull/281 — merged.
- Tested source commit: `46444e6969aa3cbb29f6b0e16159c5622eb87a65`.
- Main merge commit: `c33a715763d69372cda79591236524334e74f25c`.
- Fresh requested build: https://github.com/philmorehost/PROJECTS/actions/runs/45369865719 — SUCCESS, all six debug/simulator jobs.
- Android downloads in the run's Artifacts: `DG7-android-debug-apk`, `JIKABIZ-android-debug-apk`, `MZEEVTU-android-debug-apk`.
- iOS artifacts are simulator bundles, NOT installable iPhone or App Store packages.
- Both editions' native MySQL session/credential and security-question policy checks passed in https://github.com/philmorehost/PROJECTS/actions/runs/45366960068.

## Operator deployment checklist — NOT executed

No hosting deployment workflow was found in `.github/workflows`. This environment has no verified connection to the app backend hosts and cannot reach those hosts under its current outbound network policy. Do not infer live deployment from the main merge or successful build.

1. Identify each app's actual configured canonical HTTPS origin and the installed backend edition. The three brands can point to different installations; update each applicable installation, not an arbitrary similarly named site.
2. Confirm the installed backend matches the merged source's session/policy dependencies. If it predates those features, plan a reviewed complete backend upgrade and required migrations separately; do not copy just these three files onto an unknown older version.
3. Back up the affected files and existing server configuration outside the public document root. Keep credentials/configuration, uploads, database and tenant settings untouched. Record a rollback point.
4. On a compatible existing backend, deploy the matching `func/bc-app-request-compat.php` helper BEFORE (or atomically with) `web/api/security-quest.php`. Merge the Authorization forwarding rule into the site's `.htaccess` without erasing hosting-specific rules. Do not mix SAAS and NON-SAAS files. Confirm ownership and syntax using the host's PHP/Apache validation tools; invalidate the relevant PHP OPcache if needed.
5. This transport fix itself adds NO database migration. PR #280's separate App/Website setting has its own documented migration and must not be confused with this repair.
6. Run the acceptance matrix below on an isolated non-financial account. Only change the intended tenant's policy with approval; restore its intended final settings. Do not activate providers, fund wallets, replay deposits or remove financial holds.
7. If HTTP 401 persists, record only HTTP status, machine-readable error code, app version and origin. Check whether the response is APP_TOKEN_REQUIRED versus APP_SESSION_INVALID. Never log or share Authorization, X-App-Token, raw request bodies, answers or login tokens. Fix deployment/session state rather than weakening authentication.
8. If deployment causes an error, restore the coherent backed-up endpoint/helper/configuration set, validate it, and investigate in staging. Do not restore stale database snapshots or disable authentication to recover.

### File integrity reference (source files, not a complete backend upgrade)

For a manually merged `.htaccess`, its whole-file hash may differ legitimately; review the forwarding rule instead. SHA-256 references below identify the source versions of the three changed server files in each edition.

| Source path | SHA-256 |
| --- | --- |
| `VTU/DGV7.0-SAAS/.htaccess` | `90c8274a30dbdbf9c64a82fb2d46727e5c0690b9d0604fa049b7add56f8f2d6b` |
| `VTU/DGV7.0-SAAS/func/bc-app-request-compat.php` | `e8296fa1159a743f59d1f935d3a423c1c42e27947e06d55e655c37ca39048153` |
| `VTU/DGV7.0-SAAS/web/api/security-quest.php` | `b1764552d0c5818db4a7f3446302f033af6117bd229821554e2e48808e834f66` |
| `VTU/DGV7.0-NON-SAAS/.htaccess` | `90c8274a30dbdbf9c64a82fb2d46727e5c0690b9d0604fa049b7add56f8f2d6b` |
| `VTU/DGV7.0-NON-SAAS/func/bc-app-request-compat.php` | `e8296fa1159a743f59d1f935d3a423c1c42e27947e06d55e655c37ca39048153` |
| `VTU/DGV7.0-NON-SAAS/web/api/security-quest.php` | `b1764552d0c5818db4a7f3446302f033af6117bd229821554e2e48808e834f66` |

### Required device/backend acceptance — all pending live execution

Run for DG7, JIKABIZ and MZEEVTU against their respective compatible deployed backends. Builds and automated fixtures are not substitutes for these checks.

| Case | Expected result |
| --- | --- |
| Personal account; App questions OFF; password login | Authenticated state check succeeds, question screen closes without asking for an answer; dashboard/profile loads. A brief loading screen is not a verification requirement. |
| App questions OFF; OTP login | Same skip behaviour after successful OTP authentication. |
| Web OFF / App ON | Website does not require questions; app does. Web and app switches remain independent. |
| Web ON / App OFF | Website requires questions; authenticated app skips them. |
| App ON; existing question | Question loads; incorrect answer is rejected; correct answer unlocks. |
| App ON; no saved question | Valid question bank/setup loads; save succeeds under existing rules. |
| App ON; forgotten-answer reset | Existing password/reset requirements remain enforced; valid reset succeeds. |
| Staging proxy strips Authorization | New Android app still authenticates via explicit app_token/X-App-Token. Released compatible app with a real v2 token in api_key passes only the endpoint's narrow normalization and full session validation. |
| Invalid/revoked/expired token, including with questions OFF | Session remains rejected. Turning off security questions never turns off login authentication. |
| Cross-tenant token, developer API key, blocked/Business account | Existing rejection policies remain enforced. |
| Network/server failure while reading policy | Retry/error displayed; no unauthorized unlock. |

Remaining external prerequisites: an operator with hosting access must deploy the compatible backend; a tester must install the debug APKs on suitable devices and run this matrix. Debug signing may differ from an installed release; do not uninstall a live app or discard user data to force an update. Use a dedicated test device/profile if signatures differ. The job is NOT declared live-incident-resolved until this evidence is recorded.

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
- `.htaccess` context/parity validation and Android debug compilation remain required. These are not physical-device acceptance.

Deploy BOTH the compatibility helper and matching endpoint, plus `.htaccess`, to the applicable backend. Confirm the server honors the forwarding rule. That backend-only repair is designed for the already-built Android version which saved a real app token under api_key; it cannot upgrade an older app that only knows developer API keys. Do not enable personal API-key authentication as a workaround. Rebuild Android to include the permanent request fix and clearer error messages. Do not uninstall a live app or clear user storage merely to investigate.

Acceptance on an isolated account: password login and OTP login -> security question get -> verify/setup/reset -> authenticated dashboard/profile call, with headers present and with Authorization deliberately absent in a controlled staging proxy. Confirm an invalid or revoked session still fails. Verify the actual canonical origin and matching backend source. Keep screenshots/HTTP status and machine code only; do not share tokens, passwords, PINs, answers or raw authentication payloads. This work does not deploy the live backend or prove the actual affected user's session state.

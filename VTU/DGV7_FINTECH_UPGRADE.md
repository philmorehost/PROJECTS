# DGV7 fintech upgrade — implementation baseline

Blueprint: *Project Kickoff Blueprint — DGV7.0 Scripts Fintech Platform Upgrade* (18 November 2026). This is an implementation baseline, not a claim that any of the new financial services is operational.

## Phase 1 foundation in this change

- `DGV7.0-SAAS/func/bc-fintech-policy.php` and `DGV7.0-NON-SAAS/func/bc-fintech-policy.php` provide identical, side-effect-free account and new-service policy decisions.
- Legacy `account_level` 1 and 2 map to personal, 3 to business, without changing that level or its current pricing. Once a properly approved and persisted `account_type` exists, it takes precedence. Unknown values fail closed.
- The policy requires explicit platform, vendor, module entitlement, provider-readiness, and account-tier controls for **new** services. Personal developer API access is always denied in the decision primitive.
- `tests/fintech_policy_test.php` tests the mappings, independent controls, and fail-closed behavior across both copies.
- `bc_fintech_mobile_app_allowed($user)` allows Android/iOS sign-in and first-party token issuance for **personal accounts only**. Business accounts are web-only, including accounts grandfathered from legacy level 3 and users who upgrade from personal to business; access must be rechecked when tokens are issued and refreshed.
- `bc_fintech_android_app_url_valid($androidAppUrl)` validates a website setting: unset/empty is valid and disables the redirect; otherwise only HTTP(S) URLs (including localhost staging URLs) or same-origin absolute APK paths are accepted. Protocol-relative URLs, unsafe schemes, URL credentials, control characters/whitespace, backslashes, dot-segment (`..`/`%2e%2e`) traversal and malformed values are rejected. Use it in bc-admin before saving.
- `bc_fintech_web_redirect_target($user, $androidAppUrl)` returns the destination a personal website login must follow, or `null` when none applies. Business and unknown accounts always get `null`; a blank setting also gets `null`, so personal users keep normal website access. A non-empty but invalid setting also gets `null`: callers must detect that state with `bc_fintech_android_app_url_valid()` and fail closed with a configuration error, never fall through to the dashboard or emit an unsafe `Location` header.
- Tested in `tests/fintech_policy_test.php` across both copies: legacy/persisted tiers, business grandfathering and upgrades, unknown users, blank/whitespace/null settings, Play Store/localhost/same-origin URLs, and rejection of unsafe or malformed destinations.

**Wiring status:** Phase 1B wires the policy into app login, app token issue/refresh, profile, website login and page access (see the Phase 1B section below). It is still **not deployed**, and personal developer-API lockdown remains unimplemented.

## Phase 1B in this change (implemented, not yet active in production)

**Schema — additive and lazy**
- `func/bc-fintech-schema.php` adds `sas_users.account_type` (NULLable — NULL keeps the legacy `account_level` mapping), `account_type_source/at`, and KYB state (`kyb_status`, `kyb_reference`, `kyb_submitted_at/reviewed_at/reviewed_by`). New columns are NULL/default so existing INSERTs keep working.
- `sas_site_details.android_app_url` is a new, separate setting. It never inherits `apk_download_url` or `app_redirect_mode`.
- `sas_service_control` gains `personal_enabled`, `business_enabled`, `provider_ready`. The existing `status` column keeps its current meaning, so no live VTU service changes behaviour.
- `sas_app_tokens` stores only a SHA-256 of each first-party app session token.
- Everything runs lazily from `bc-connect.php` with per-column `SHOW COLUMNS` guards. **`SYSTEM_VERSION` was deliberately not touched**: changing that marker re-runs the large legacy migration block (the OTP outage). No `DROP`/`TRUNCATE`, no row rewrites, no backfill of `account_type`.

**First-party app tokens**
- `func/bc-app-token.php`: 32-byte opaque token, hash-at-rest, 30-day expiry, `last_used_at`, per-device revoke and revoke-all. Issued on app login, returned as `data.app_token` with `data.app_token_expires_at`.
- `api_key` is **not** rotated or invalidated; existing app builds and merchant integrations keep working during migration.
- Every lookup re-reads the account and re-applies the policy, so an upgrade to Business kills live sessions immediately. `web/api/logout.php` revokes exactly the presented token.

**Enforcement (`func/bc-fintech-gate.php`)**
- App login (`web/api/login.php`, SAAS `login-otp.php`) refuses **before** any credential, balance or token is returned: HTTP 403 `Business accounts are accessible via web only`.
- `profile.php` re-applies the policy when an app token is presented; merchant requests without one are unaffected.
- Website login applies `bc_fintech_web_login_flow()` only **after** the session exists and OTP (if enabled) has passed.
- Ordinary pages are gated by `bc_fintech_web_access_blocked()` in `bc-config.php`, active only when a valid `android_app_url` is saved. Logout, login, lockout, API and AJAX routes stay reachable; super-admin impersonation keeps the website.
- A saved URL that fails validation shows a configuration error instead of the dashboard — never treated as "no redirect configured".

**Known limits of this phase**
- The **other** ~60 app endpoints still authenticate with `api_key`; they do not yet require an app token, so a Business account holding its old key could still call them until they are migrated. `profile.php` + login/token issue/refresh are done.
- Personal developer-API lockdown is still **not** enforced, and must not be until those endpoints accept app tokens.
- No `account_type` backfill has run; until then policy resolves entirely from `account_level`.
- Not deployed. No staging database, provider sandbox or live traffic has exercised any of it.

## Existing implementation facts that affect rollout

1. Both editions primarily use `sas_users.account_level` for legacy user tiers and the three pricing tables. Existing account-level and prices must remain intact during migration and upgrades. `sas_user_upgrade_price.account_type` is a different field: it stores legacy level identifiers, not the new account tier.
2. Both editions currently use `sas_service_control` keyed by actual `vendor_id`. The standalone edition also calls `resolveVendorID()` and uses the site owner's vendor ID; forcing `vendor_id = 0` without adapting those paths would lose existing controls. Migrate or map deliberately after inspecting a standalone staging database.
3. The SAAS `isServiceEnabled()` currently lets a vendor-local setting override the global default. New marketplace modules need a distinct global **ceiling** and entitlement check; do not silently change current VTU service availability.
4. Existing first-party app endpoints such as `web/api/profile.php` authenticate using `sas_users.api_key`, which is also a merchant/developer credential. The same is true of purchase endpoints such as `web/api/airtime.php`, while `web/APIDocs.php` handles developer key regeneration. An `X-App-Source` header is client-controlled and is not authentication. Simply blocking all personal API-key requests or trusting that header would break the mobile apps or allow the restriction to be bypassed. Separate revocable, expiring first-party app sessions/tokens from developer credentials, migrate apps safely, then enforce developer permissions server-side across every applicable route.
5. Public pages and admin/API bootstraps run different migrations. The previous OTP update showed why changing a global schema marker can re-run large unrelated migrations. Use additive, versioned, idempotent, independently tested migrations and back up/restore-test staging databases. Do not overwrite balances, API keys, legacy levels, or tenant IDs.

## Required account access and redirect rollout

- Add a tenant-scoped `android_app_url` site setting with a bc-admin control in **both** editions, validated with `bc_fintech_android_app_url_valid()`. Empty means personal web login remains available; a valid configured URL routes the personal user to the Android app/download screen. Business users remain on the web. Enforce the routing only after all required authentication, including login OTP, and never let the redirect run before the session is established (otherwise it becomes an unauthenticated open redirect). Do not issue mobile tokens to business accounts: respond HTTP 403 with `Business accounts are accessible via web only`. Recheck account type when issuing and refreshing revocable app tokens, including after an upgrade to Business.
- Existing `sas_site_details.apk_download_url` and `app_redirect_mode` already power a separate anonymous landing-page prompt/force feature. Do not silently interpret an existing APK URL as consent to the new mandatory post-login policy. Use the explicitly configured new setting or a deliberate, reviewed migration, and reconcile or disable anonymous forced redirects where they could prevent Business users from reaching web login. Preserve the Business-only web path and prevent redirect loops.
- Apply the personal-only restriction to both Android and iOS for DG7, JIKABIZ and MZEEVTU. The requested web redirect is specifically the configured Android link, including when a user uses an iOS browser.
- Route enforcement must use authenticated server-side account data, not client-supplied account type or the spoofable app-source header. Prevent bypass through existing web routes, refresh tokens and alternate login flows. Explicitly define logout, recovery and upgrade paths so mandatory redirects do not trap users. Do not deploy these gates until account migration and first-party token separation are ready.

## Delivery sequence

1. **Account and authorization foundation:** inventory all registration, login, developer, merchant and app routes in both editions; design separate first-party authentication; additive account-type/KYB schema and controlled backfill; per-account service matrix and SAAS marketplace entitlements; Business upgrade and approval with a server-validated payment ledger; enforce access consistently across web and mobile. Migrate and test existing users, agent pricing and API-earner keys before enabling.
2. **Design and experience:** create an original fintech design inspired by the requested references, not copied assets or representations of unimplemented services. Apply responsive landing, customer dashboard, support flows and agreed mobile variants while retaining working purchase flows and accessibility.
3. **Provider adapters:** confirm the contracted provider product, environment-specific official API specification, merchant entitlements, settlement regions/assets, webhook signatures and idempotency. Start in sandbox; do not assume that a listed endpoint or asset is available. Keep credentials in deployment secret storage, never source code or chat. Reconcile idempotently, use exact decimal/integer money units and auditable ledger entries, and never credit on an unverified webhook or request alone.
4. **Tuition, eSIM, referrals and support:** only offer supported institutions/products after a verified provider catalog exists. The referral reward must be based on final, eligible, non-reversed transfers, with unique awards and fraud controls. Human chat needs tenant-scoped authorization and auditable escalation. Treat KYB documents and eSIM details as sensitive.
5. **Staging and release:** separately stage both editions, replay webhooks, test refund/reversal and multi-tenant isolation, migrate a representative snapshot and verify backups. Complete security and compliance review, mobile signing and release, then explicitly approve production rollout. Never label sandbox integrations as live.

## Current prerequisites and decisions

- **Confirmed scope:** redesign all DG7, JIKABIZ and MZEEVTU Android and iOS apps.
- **Confirmed market target:** all blueprint markets and corridors, subject to provider entitlement, settlement support, and compliance approval in each jurisdiction.
- Supply an accessible, isolated staging setup and sandbox documentation/entitlements through the normal secure deployment process. Do not paste secrets in chat. Live rollout requires the providers' confirmed product access, compliance review, signing configuration and explicit release approval.
- Confirm operating jurisdictions, allowed assets and custody/ledger ownership before designing or launching cross-border, stablecoin and virtual-account features. A blueprint endpoint list is not evidence of provider support or regulatory approval.

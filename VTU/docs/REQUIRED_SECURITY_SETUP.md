# Required password and Google Authenticator setup

Google Auth means time-based authenticator codes (TOTP), not Google OAuth sign-in.

## Controls

- Vendor: **bc-admin → Account Settings → Security → Required Google Authenticator setup**. Require setup for all that vendor's users.
- Platform (SAAS): **bc-spadmin → Account Settings → Security → Required Google Authenticator setup**. Independent switches require setup for all vendors and all users across vendors.
- Each policy save, whether enabling or disabling, requires CSRF validation and a **fresh** code from the actor's enrolled authenticator. Existing ten-minute settings unlocks and email OTPs cannot substitute. Attempts are actor-rate-limited; successful changes are audited.
- Effective user mandate is platform OR tenant. A vendor cannot override a platform requirement. Turning off enforcement does not delete enrolled secrets or switch off existing TOTP protection. Platform and tenant controls are separate: removing a platform requirement leaves explicit tenant mandates in place.
- Controls are opt-in; existing platform `force_vendor_totp` values are preserved. New options are `force_user_totp` in `sas_super_admin_options`, and `require_user_totp` per vendor in `sas_settings`. Existing unique indexes support the upserts; no new columns, migration version bumps or bulk user updates are needed.

## Enforcement

The shared connection bootstrap checks signed-in sessions before page handlers, including public pages and direct AJAX endpoints. A confined account may reach only its dedicated `/web/SecuritySetup.php` or `/bc-admin/SecuritySetup.php`, and the actual root logout route. The old whole-Account-Settings exception no longer permits unrelated submissions.

The dedicated page loads no full dashboard, billing, KYC, funding or Personal-account mobile redirect flow, preventing redirect loops. Forced password change wins first; if authenticator enrolment is also required, that is the next step. Users enter the current password and a different confirmed 12–72-character password. Bcrypt/legacy MD5 verification is used correctly, and flag clearing is conditional on a successful atomic old-hash-bound database update. Account status and session epoch restrictions are retained.

Authenticator setup uses a manual time-based setup key and six-digit proof. Secrets are sealed using the existing host-capability-dependent helper. Merely starting setup is not sufficient. Confirmation is bound to the pending secret and atomically claims the update. Active secrets cannot be overwritten through the begin-enrolment action. Enrolled accounts cannot self-disable while their effective mandate is enabled.

Bearer-token resolvers, merchant API-key adapters, legacy password-authenticated app adapters, and authenticated AI/device endpoints also enforce required setup before actions. API refusals are JSON 403 responses with `PASSWORD_CHANGE_REQUIRED` or `AUTHENTICATOR_SETUP_REQUIRED`, and a website `setup_url`. This deliberately closes API operations while mandatory setup is incomplete; clients must direct customers to the website. Existing forced-password app-session revocation is preserved. This does not stop verified provider webhooks/settlements or unrelated anonymous public access.

## Load cost

No network calls, background scans, bulk account updates, per-page schema changes, or cross-request/session policy TTLs are introduced. The existing authenticated actor read is performed in the shared gate and reused by web/admin bootstraps. Effective policy uses one combined indexed options read per vendor per request, memoized only for that request, so changes reach existing sessions on their next request. Anonymous requests do no new policy/actor queries. API key expiry lookups reuse their fetched owner where available. TOTP cryptography/password hashing runs on explicit security submissions, not normal page loads. Production latency has not been benchmarked.

## Validation

- `node VTU/tests/harness/run-test.mjs tests/enrollment_policy_test.php`: 120 policy, hierarchy, route allowlist, replay, enrolment-state and edition-parity checks.
- `node VTU/tests/harness/run-test.mjs tests/enrollment_password_test.php`: 60 checks with actual password helpers (legacy MD5 and modern hashes, unchanged/wrong/mismatched/weak passwords, failed and concurrent writes).
- `node VTU/tests/harness/enrollment-api.test.mjs`: both real PHP API guard fixtures emit clean JSON and exit before simulated side effects.
- Existing regressions: security33 86, user-action gate 162, TOTP claims 26, fintech gate 1077 checks pass.
- The older security34 suite fails its unrelated historical referral-default assertion; referral implementation was not changed.
- Older security29 suite still assumes second-factor verification succeeds without a database replay claim and fails at that obsolete assumption. The dedicated TOTP claim test verifies the actual database-required behavior; replay protection was not weakened to satisfy the old assertion.

No live database, production latency, real-device or production authenticator enrolment verification was performed. No production deployment is included. Deploy all changed shared helpers, bootstrap files and setup pages together; test a disposable vendor/user session before enabling platform-wide requirements.

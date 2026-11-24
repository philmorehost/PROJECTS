# Tenant-controlled App or Website access

## Administrator instructions

In **bc-admin → Account Settings**, find the website/SEO settings containing the Android app URLs. Set **PERSONAL ACCOUNT ACCESS** to:

- **App or Website — user choice** (`optional`, default): personal users may use the website even when an app URL is saved. Recommended while the app is in testing. The app is not disabled by this preference; existing app eligibility/security checks still apply.
- **App only** (`app_only`): personal website login and ordinary signed-in website pages direct the user to the configured Android App URL. A non-empty valid destination is required to save this mode. Business accounts remain website-only.

Save **Site Details / Update SEO & Settings** using the existing authenticated, CSRF-protected and Authenticator-unlocked settings flow. The preference is scoped to the logged-in vendor; a posted vendor/user ID cannot choose its scope. Existing sessions read the preference on their next ordinary website request; no app rebuild or customer logout is required.

The anonymous **APP REDIRECT MODE** remains a separate presentation setting. In App or Website mode, a saved anonymous `force` setting becomes a dismissible `prompt` at runtime; it must not prevent customers reaching the website. The stored landing preference is preserved. Switching to App only restores the selected forced landing behavior. Invalid download links are not presented/followed. Saving a download link alone never forces a personal account to leave the website.

## Default and deployment

The additive `sas_site_details.personal_web_access` column defaults to `optional`, including for existing rows with saved Android links. This intentionally changes the old behavior in which merely saving `android_app_url` forced personal users into the app. Operators who genuinely need app-only behavior must select it explicitly after app acceptance.

Deploy matching code to the applicable PHP edition(s), and apply `migrations/20261118_personal_web_access.sql` once after inspecting the schema. Skip the ALTER if the column exists; the existing lazy fintech schema also ensures it during its normal initialization. No global legacy migration-version bump or historical data rewrite is used. Apply the schema before serving the updated code to existing sessions. Missing/unavailable/corrupt policy reads are not silently treated as permission to bypass explicit app-only policy.

Both SAAS and NON-SAAS have the UI, persistence and gates. Duplicate legacy SAAS settings rows are updated together in the existing tenant-scoped save path rather than preventing the choice from being saved. Readers use fresh tenant settings, not a session-cached access preference.

This work does not deploy production changes or select a mode on behalf of any live tenant.

## Security and scope

- No authentication, setup, account block, PIN/OTP, KYC/KYB, service entitlement or financial safety gate is disabled.
- Website access does not imply every transaction is enabled. Existing provider/payment/withdrawal/card holds remain, including the PayHub rollout flag and historical-payment reconciliation requirements.
- App eligibility and session tokens are unchanged. This is not a switch to allow business accounts in personal-account apps.
- Business website access, recovery/logout, machine-readable API/AJAX endpoints and the existing tightly scoped mobile service portal retain their existing behavior.
- Optional access does not redirect to an invalid app link. App-only with a missing/invalid destination fails with a configuration message; no arbitrary URL is followed.
- Settings accept only scalar `optional` or `app_only` values, require CSRF and retain the existing admin/demo/Authenticator controls. URLs retain their existing validation. No balance/provider execution/money mutation is introduced.

## Validation

- `fintech_gate_test.php` covers both post-login and ongoing website decisions, optional mode with valid/blank/invalid links, Fund/Airtime/Dashboard access, app-only/unknown/missing destination cases and existing mobile/business/security contracts.
- `app_website_choice_mysql_test.php` applies the real additive migration to a disposable database and checks existing-row defaults, tenant isolation, live session preference changes, the actual shipped anonymous landing helper and failure behavior in each edition. It does not contact providers or alter production.

```
node VTU/tests/harness/run-test.mjs tests/fintech_gate_test.php
BC_APP_CHOICE_CI=1 BC_TEST_EDITION=SAAS php VTU/tests/app_website_choice_mysql_test.php
BC_APP_CHOICE_CI=1 BC_TEST_EDITION=NON-SAAS php VTU/tests/app_website_choice_mysql_test.php
```

Acceptance after deployment: with a personal staging account and an app URL saved, select App or Website, log in and navigate Dashboard/Fund/Airtime without diversion; repeat from an already-signed-in session. Verify optional anonymous prompts can be dismissed. Test a second tenant and Business accounts separately. Switch to App only with a safe app URL to confirm intentional enforcement, then return to optional while the app is being tested. Do not use real-money transactions solely to test this access preference.

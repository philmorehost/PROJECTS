# Platform remediation request — progress and outstanding acceptance

Crypto staging is paused at the user's request. No production deployment, live
financial activation, data cleanup or device-verification claim is authorized by
this repair batch. Preserve holds, security/compliance, rewards/referrals,
support chat, PIN, biometrics and existing automation. User builds native apps
locally; repository source and regression checks are not on-device verification.

## Batch 1: implemented, awaiting/requiring CI and local app rebuild

- All three Android and all three iOS client variants now persist the issued
  `app_token`, not the intentionally empty/redacted developer `api_key`. Missing
  tokens cannot establish logged-in state. New secure storage namespaces require
  one fresh sign-in rather than inheriting old empty/developer credentials.
- Android attaches the current secure token as Bearer centrally, including JSON
  service/security-question/profile/history and multipart calls. iOS form request
  variants attach Bearer plus `app_token`; existing JSON Bearer flow is retained.
  Public password/OTP/registration calls do not inherit a stale session header.
  Login OTP challenge handling still precedes credential persistence, and native
  security-question action fields and setup gates remain in place.
- Numeric/string profile balances are read consistently; missing balance does not
  overwrite a cached balance with a fabricated zero. History denials/malformed
  responses no longer become a successful empty history in Android repository.
- `mobile-home.php` does not apply developer-key expiry to an authenticated app
  session. Session validity, tenant scope, account restrictions, Personal/mobile
  eligibility and mandatory security setup remain enforced by the existing bridge.
- Shared platform/vendor user-action confirmation cards send email OTP in-place,
  with CSRF, same-origin POST and inline status; no page reload, extra modal, lost
  PIN/code fields or duplicate in-flight request. Final protected mutations remain
  ordinary verified POSTs. This does NOT yet cover every OTP form site-wide.

**Not claimed:** all service integrations/device flows verified, full website/app
security audit complete, or user API toggle root cause resolved. A session fix
repairs authentication for many service requests but does not certify every
provider, navigation target or compliance path.

## Remaining requested work (not completed in this batch)

1. SAAS Marketplace: list all actually preinstalled adapters by category, seed new
   entries inactive by default, platform edit/pricing with explicit free/zero-price
   option. Do not modify NON-SAAS Marketplace or its free behavior. Preserve
   commercial entitlement enforcement; zero price must not imply auto-activation.
2. Account switches: Personal/Business switches must not debit wallets; Business
   requires Business KYC/review. Personal Smart(default new-user level) remains
   free; Agent/Reseller pricing applies to Personal pricing-level upgrades.
   Accept zero in admin price settings. Audit charged legacy level-3 paths separately
   from account type, with transactional/idempotent financial handling.
3. User API enable: reproduce and fix security-confirmed switch not persisting,
   with explicit failure/effective-policy notices. Do not bypass Business-only
   developer API eligibility, tenant scoping, CSRF or step-up to make a toggle work.
4. Forced security reset: allow correct old-password login into restricted setup;
   require BOTH a new password and a new security PIN before normal activity.
   Audit web/API/enrollment and revoked sessions; do not simply clear all flags or
   disable authentication. Existing forced password/authenticator gates stay on.
5. Receipts site-wide/app: credited destination number on its own line; utility
   customer name/address/meter and prepaid token, no token for postpaid; structured
   separate lines in screens, PDF and image receipts. Use stored real data only.
6. OTP/PIN/TOTP UX everywhere: extend no-reload inline sending/save feedback to
   remaining forms/cards/modals without weakening server checks, throttling or
   authenticator-vs-email requirements.
7. Dashboard upgrade/switch entry: Personal pricing-level upgrade and Business
   application entry, redirect Business applicants to compliance/KYB, no bypass.
8. SAAS vendor temporary URL: restore portal token link and approval email;
   honor configured number of days, expiry, revocation and correct tenant scope.
9. AppUpdateBroadcast.php: simplify configuration and automate update notifications
   safely, with explicit delivery/error status and no duplicate sends.
10. Restore/enhance floating enabled-service shortcuts. Respect service entitlement,
    account policy and real available routes; do not show dead financial actions.
11. Bybit settings on bc-admin/PaymentGateway.php: commercial entitlement for SAAS,
    free entitlement for NON-SAAS; no insecure credential display/storage and no
    implication that configuration activates production crypto.
12. Advanced crypto fintech page: exact target page was not specified in the user
    sentence; likely CryptoHub/overview, but confirm target before a redesign.
    Preserve all non-live gates, legacy history and honest balance states.
13. Remaining app functionality: inventory and test every home/service action,
    service handoff, history/receipts and wallet/API contract against website in
    each brand. No fake balances/history or enabled unconfigured providers.
14. Authentication/compliance flow audit: password/email OTP, security-question,
    KYC, required PIN/authenticator enrollment, biometric/idle lock and website
    handoff must have ordered recoverable transitions, no loops, omitted params,
    stale-token fallback or accidental bypass. Real device testing remains needed.


## Batch 2: source implemented, validation in progress

- SAAS bundled **VTU/SMS** adapter discovery now deduplicates transport variants,
  categorizes the real files, excludes retired providers/local dispatchers, and
  inserts only missing listings as **Private**, price zero. Existing prices,
  visibility and descriptions are not overwritten. No tenant API/entitlement is
  activated. This is NOT yet a complete inventory of every fintech integration.
- SAAS Create/Edit accepts explicit zero; malformed and negative prices are rejected
  instead of stripped into different values. Mutation forms and marketplace deletion
  use CSRF-checked POST. Bundled inventory is rediscovered if removed; keep it Private
  instead of deleting to leave it inactive.
- Both editions' upgrade-price settings accept zero, reject malformed prices and
  persist Smart Earner price as zero. Admin-selected free pricing-level upgrades
  skip wallet debit. Legacy user-level charging and financial atomicity still need
  separate remediation; this does NOT certify the complete account-switch request.
- Required setup in both editions now verifies the old password and requires BOTH
  a different new password and a different, confirmed nontrivial PIN before one
  compare-and-set writes both and clears force_password_change. Password-only
  recovery no longer clears the mandatory setup flag. Vendor and user gates remain
  in place, followed by mandated authenticator enrollment. API/device end-to-end
  compliance testing remains outstanding.
- Floating shortcuts now render in the customer shell from saved tenant selection,
  intersected with actual navigation visibility and existing routes. Admin save is
  CSRF-checked, allowlisted and transactional, with failure messaging. Financial
  routes keep their normal independent authorization. No provider is enabled.
- Mobile structural regression now checks MZEEVTU's actual AIAssistantView destination
  rather than requiring another brand's AIChatView symbol.

Tests added/expanded: strict-price/catalog source/inventory regression, floating
shortcut rendering behavior, forced reset atomicity/weak or reused PIN rejection.
Native PHP 8.2/8.3 CI is wired in platform-remediation.yml. Native builds/devices,
real database migrations and production provider behavior are not proven locally.
All remaining Batch 1 scope limits still apply unless explicitly superseded above.

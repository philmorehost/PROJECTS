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


## Shared web utility receipt follow-up (partial receipt request)

The shared tenant/user/platform web receipt fetch now retrieves a matching stored
utility purchase by transaction tenant + username + reference. Payloads expose
separate allowlisted name/address/meter/type/token/units, not provider raw text.
Missing stored customer identity is honestly shown as not supplied. Tokens/units
are emitted only for successful prepaid purchases, never postpaid, unknown-type,
pending or failed ones. The shared line-based receipt renders these fields in the
same card exported to PDF/image. Credited number/destination has its own row.

This does not yet populate missing customer identity in historic/provider records,
update every legacy dedicated receipt template, or extend native receipt models.
The full site-wide/app receipt request is therefore still **partial**.


## Temporary vendor portal follow-up

Restored the vendor's own tokenized secure portal URL as a selectable read-only
field in the portal. Existing approval emails already substitute/append that URL;
no duplicate welcome emails are sent automatically. Portal responses are no-store,
noindex and no-referrer. Temporary dashboard login now requires CSRF and an active,
unblocked vendor. It rejects invalid/future registration timestamps and expiry at
the exact configured-day boundary. Temporary admin sessions bind a token digest to
the current vendor token, so link rotation/revocation also rejects old sessions.
Existing temporary sessions must reopen their valid secure portal link once.
13 mocked session/expiry/rotation/portal-source checks added. Actual configured
DNS, queued email delivery and browser access are not production-verified.


## Native history and receipt continuation

- Both editions' transactions API now includes allowlisted `receipt_details` label /
  value lines and the credited destination. It uses one bounded, owner/tenant-scoped
  utility lookup per page, caps page size at 100 and rejects database failures rather
  than claiming a successful empty history. Existing NON-SAAS filters are retained.
- Raw upstream transaction descriptions are replaced by the friendly service name in
  customer history responses, avoiding postpaid token leaks through raw descriptions.
  Stored transaction descriptions remain unchanged for staff investigation.
- All three Android brands parse details in dashboard and full-history paths; receipt
  screens/images include individual fields, and PDF text wraps into an appropriately
  sized receipt page instead of clipping long identity/token fields.
- DG7/JIKABIZ iOS history previously expected `transactions`/string statuses/int IDs
  instead of the actual `data`/numeric-status/reference response. It now consumes the
  actual contract, surfaces errors, supports paging/refresh and opens shared receipts.
  MZEEVTU's existing history filters and CSV export are retained, with receipt opening
  and explicit failed-response handling added. All three iOS receipt exports measure
  actual text height rather than clipping a fixed-height page.
- Source/fixture tests added for endpoint scope, two-query pagination, leading-zero
  destinations, prepaid token inclusion, postpaid/raw-text suppression, failure states
  and native receipt wiring. Native compilation and device rendering are separate.

This does NOT complete the entire original request. Missing historic customer
name/address cannot be invented; legacy standalone receipt templates, API-enable
persistence, remaining billing/upgrade paths, broadcasts, Bybit backend settings,
site-wide OTP UX and the complete compliance/service audit remain outstanding.
Crypto staging remains paused; no financial integration is activated or deployed.


## User API status persistence continuation

- Tenant and platform user API switches now share a tenant+primary-key scoped writer
  called only after existing CSRF/PIN/second-factor verification. Platform confirmation
  carries the row ID; username fallback is escaped and rejects ambiguous matches.
- Enabling is Business-only and requires an active/unblocked account. Personal pricing
  level 3 cannot bypass explicit Personal account type. Disabling remains possible for
  restricted users. This changes neither account type/pricing nor wallet balances.
- Enable writes compare account type/level/status/block snapshots to avoid racing a
  type conversion/suspension. A successful UPDATE alone no longer means success:
  the row is independently read back and eligibility checked before success/email.
- Read/write/readback errors and policy restrictions are explicit rather than silent.
  A readback failure is an **unconfirmed outcome**, not a promise of database rollback;
  administrators are told to refresh before retrying. No raw DB exceptions are shown.
- 48 fixture checks added for wrong scope, denied policy, suppressed writes, idempotent
  saves, errors, quoted usernames and concurrency snapshot SQL. Disposable MySQL CI
  also checks actual persistence, zero-affected-row idempotency, nullable legacy
  account fields and a trigger-suppressed write in both editions.

This hardens/validates status persistence; it does not certify every downstream legacy
API-docs/level check or production database installation. Existing API-key expiry,
Business/tenant service/compliance policies still apply after the flag is enabled.
Other remaining remediation work stays open, no deployment, crypto staging paused.


## App-update configuration security continuation (not automation completion)

- Both editions' AppUpdateBroadcast mutations now require CSRF + purpose-bound
  PIN/second-factor verification. Email OTP sends in-place with inline status,
  preserving form values and never including the Firebase key/PIN in the send request.
- Strict release validation: positive Android version code, bounded version/changelog,
  safe APK basename and canonical official HTTPS Google Play app-details URL. The
  public update endpoint also rejects unsafe legacy configuration instead of returning
  arbitrary links/files. Existing public response contract otherwise remains intact.
- Saved Firebase private keys are no longer printed into HTML. Blank retains the key;
  replacement requires a valid matching-project service account and RSA >=2048-bit key.
  New replacements must seal as GCM with a server secret; no plaintext fallback save.
  Existing plaintext rows remain readable for compatibility until explicitly rotated.
  Do not interpret this as a completed migration of all existing stored keys.
- Database execution failures no longer produce a success message. FCM requests have
  bounded connect/overall timeouts, fixed Google endpoints and validated credentials.
  Accepted sends require a message ID; ambiguous/failed sends tell admins to inspect
  Firebase logs before retrying, rather than promising delivery to every device.
- Because existing Android apps subscribe to a project-wide topic, broadcasts refuse
  known Firebase projects shared by multiple tenant settings. This is not proof that a
  project is exclusive across other installations; operators must use dedicated projects.
- No automatic broadcasts, provider calls during page load, scheduler changes, or
  claims of production FCM delivery. Automated notification outbox/idempotency/worker
  remains outstanding and must be designed before switching automation on.
- Local release/security/source and inline OTP behavior tests added; RSA/OpenSSL tests
  run in native PHP8.2/8.3 CI. No real key, live Firebase call or deployment used.

SAAS System Update remains platform-only; NON-SAAS Marketplace untouched; crypto
staging paused. Remaining requests outside this scoped configuration repair stay open.


## App-update duplicate-attempt protection continuation

- Manual topic sends now require a durable InnoDB claim uniquely keyed by vendor
  and Android version code BEFORE invoking the Firebase message-send transport.
  Schema version bumped and an additive `sas_app_update_dispatches` table introduced.
  No production migration has been executed by this agent.
- Repeated/overlapping requests, changed URLs or changed Firebase projects cannot
  reset the same version's claim. No delete/reset/reclaim/automatic retry path added.
- Autocommit-off, active outer transactions, absent/non-InnoDB claim storage and
  failed claim inserts block sends. OAuth may still run before the claim, but message
  sending cannot run without it. The claim has no credentials or device tokens.
- Accepted provider responses require the expected project/message name; only its
  digest is retained. Provider failure/exception or failed finalization is unconfirmed
  and cannot trigger a resend. A crashed request can remain `dispatching`; this is
  intentionally treated as uncertain rather than stale/retryable.
- The settings page shows the last 10 tenant-owned attempts and explanatory states.
  Operators must inspect Firebase logs for uncertainty. Version-code increments are
  for actual new releases, NOT an approved workaround to blindly repeat an unknown send.
- This is **at-most-one local transport attempt**, not exactly-once device delivery.
  Firebase/client duplicate delivery, project exclusivity outside this installation,
  disaster restore/loss of claim records and actual live delivery are not proven.
- Fixture tests plus disposable MySQL two-connection tests cover visibility of the
  committed claim before sending, overlap, idempotency, lost responses, finalization
  failure, unavailable schema and outer transactions. No live Firebase calls.

Automatic scheduling/outbox/worker remains off and unfinished. No provider credentials,
financial holds or crypto flags changed; no deployment or destructive live cleanup.


## Exact-release automatic notification worker implementation

- Added explicit seven-day, exact-release opt-in in the existing step-up-protected
  release form and an independently verified approval-removal action. Approval is
  stored in the existing tenant settings table; no new schema migration is needed.
  Old approval is removed BEFORE saving release or replacement Firebase credentials.
  Failed saves/approval grants do not silently carry old approval into a new release.
- Approval binds normalized release, persisted vendor host, Firebase project/stored
  credential digest, local APK digest, and vendor password/PIN/authenticator hashes.
  Modified settings/files/keys, expired/future approval and restricted vendors fail closed.
  This is point-in-time revalidation, not an immutable APK hosting/signature guarantee.
- Added CLI-only `cron/process_app_updates.php --vendor=ID`, dry-run by default.
  Sending requires BOTH `--send` and `BC_APP_UPDATE_WORKER_ENABLED=1`. The disabled
  send path exits before database/bootstrap/credential/provider use. No HTTP trigger,
  auto-installed cron, environment file, global tenant sweep or schema cleanup.
- Fixed-endpoint Google transport extracted from the admin page for shared reuse.
  One vendor per invocation, bounded transports, pre-send freshness checks, active/
  subscription/enrollment validation, and repeated snapshot/approval checks around
  OAuth and at the message-send boundary. Already-attempted releases avoid OAuth too.
- Worker reuses manual sends' durable at-most-one release claim. Uncertainty is never
  automatically retried. Callback aborts after a claim remain uncertain/nonretryable.
- Added source/approval tests, disabled-CLI tests and real MySQL fixture tests using
  generated disposable RSA keys and MOCKED provider callbacks. No real Firebase call,
  production configuration, deployment, scheduler installation or enablement performed.

Operator guide: `VTU/docs/APP_UPDATE_WORKER.md`. Approval alone does not enable
sending. Runtime deployment/preflight/provider delivery verification remains a
separate operator step; do not mark actual production automation as enabled.
Other billing, Bybit and site-wide OTP/compliance work remains open. Crypto staging
stays paused and NON-SAAS Marketplace is untouched.


## Paid gateway cards and atomic pricing-level billing

Added dedicated Klasha/Bybit API configuration cards independent of withdrawal
module visibility; SAAS catalogue defaults Klasha NGN50,000 and Bybit NGN150,000,
with PIN+TOTP protected platform pricing and NON-SAAS included access. New vendor
purchases and self/vendor-admin pricing-level changes commit wallet, receipt,
replay event and entitlement together under row locks. Free Smart Earner and zero
configured prices supported; account type/KYB/API eligibility never promoted by
pricing changes. New API secrets encrypted, write-only in UI, tenant-bound and
revalidated against commercial access. Existing Bybit staging/live gates untouched.
Async OTP/purchase/settings feedback retains inputs and avoids reload/alerts.
Operator guide: `PAID_GATEWAY_ACCESS.md`. Source only: no deployment, production
migration, credential entry or provider calls. Super-admin complimentary overrides,
isolated clone upgrade endpoint and the remaining site-wide OTP/KYC audit are not
claimed complete by this batch. Crypto staging remains paused.

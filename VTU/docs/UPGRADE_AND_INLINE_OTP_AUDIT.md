# Upgrade and inline OTP follow-up

## This batch

- SAAS platform complimentary pricing-level changes now need CSRF, a fresh active
  platform actor, PIN and configured second factor, a short-lived actor/recipient/
  tenant/current-level-bound session nonce and a compare-and-set update. Nonces are
  consumed before SQL mutation; stale targets and uncertain retries cannot report
  another successful grant. The operation changes only pricing level, never wallet,
  Personal/Business type, KYC, API permission or forced-reset flags. Audit records
  remain best effort, consistent with existing security logging; this is not a
  transactional audit/outbox guarantee.
- Both editions' isolated `api/app-backend/clone/upgrade-user.php` now return HTTP
  410 `LEGACY_UPGRADE_RETIRED` without loading the old database or billing code.
  This intentionally breaks clients using that insecure route. It used static
  passkeys against a separate `users` schema, non-atomic billing and referral
  writes, without modern PIN/replay/compliance checks. No automatic redirect or
  cross-schema/session bridge is safe. Use the authenticated current upgrade UI;
  modern clients must not retry the clone route or infer a payment occurred.
- Administrative inline email sending added to bc-admin APIRequests,
  KYCManagement and BruteForceSecurity in both editions, and SAAS bc-spadmin
  KYCManagement, BruteForceSecurity, UnblockRequests, VendorRegistrations and
  UserUpgrade. The dispatch handler runs after authentication and before any legacy
  mutation code. It requires CSRF, checks email policy/account restrictions, fixes
  the email purpose on the server and exits with no-store JSON.
- Buttons send only CSRF plus the send marker, never mutation fields, passwords,
  PINs, files or rejection reasons. They block concurrent sends, show SENT, keep
  modal/form inputs, and report JSON/session/network errors inline. Email choice
  is explicitly passed into server verification, including separate KYC decision
  forms on the same page. Normal final action POST/redirect behavior is unchanged.

## Not a claim of everywhere coverage

Previously covered: funding, standalone user actions, vendor management modal,
paid-action/app-update components, channel-policy settings/unlock. This batch adds
the specific admin screens above. Remaining known surfaces to audit include:

- user Login, Register, PasswordRecovery and admin/platform PasswordRecovery;
- SendFund and administrative Withdrawals;
- GiftCard, CryptoHub and specialist custody/repair confirmations;
- VendorKYC and other feature-specific OTP implementations;
- Personal conversion inside bc-admin UserUpgrade.

Login/recovery and custody must retain their distinct identity/policy requirements;
shared action-channel preferences must not silently disable those checks. “Found by
source search” is not a browser/device verification. Until those routes have their
own handler/UI/error-path fixtures, do not describe Send Code as inline everywhere.

## Validation and deployment

Controller contract tests exercise CSRF, target binding, second-factor refusal,
nonce replay, stale compare-and-set, restricted actors and bounded session storage.
Native subprocess tests exercise purpose/scope, disabled email, blocked actor,
throttled mail and CSRF refusal without sending mail. Browser-script fixtures cover
both editions, success/error/invalid JSON, duplicate sends and preservation of
entered PIN/code/reason. These are synthetic tests, not real email/device/provider
or production-wallet verification. No deployment, migration or live account changes
are authorized or performed. Crypto staging remains paused. The broader forced
password-and-PIN / KYC audit remains unfinished.

## Follow-up: conversion, vendor KYC and sign-in resend

- Both-edition bc-admin Personal conversion and SAAS bc-spadmin VendorKYC now use
  the authenticated JSON-only dispatch helper and shared inline SENT UI. Conversion
  renders enabled channels, makes the code optional only for explicit PIN-only
  policy and carries the selected factor method to verification. Legacy
  `send-personal-code` POSTs are intercepted by the same guarded dispatcher before
  the old redirect branch. Sending never performs a conversion or KYC decision.
- Administrative Withdrawals remains paused: the non-GET HTTP 503 barrier stays
  before all legacy dispatch/mutation handlers. Its Send Code control now reads
  Paused and is disabled. This is intentional unavailability, not an inline OTP
  success. No transfer/refund/withdrawal gate is enabled by this work.
- SAAS web Login's existing sign-in-code resend now sends only CSRF + resend marker
  asynchronously, shows SENT without clearing the entered code, and reports errors
  inline. Pending state must be within 15 minutes and belong to the current tenant;
  a fresh same-tenant active/unblocked user is checked before dispatch. Existing
  throttle and login OTP issuance are retained. Resend neither grants a session nor
  extends the pending attempt's lifetime. The verify POST also requires CSRF, with a
  token in the OTP form. No action-channel preference is used to bypass login OTP.
- NON-SAAS web Login does not currently expose that SAAS login-OTP resend handler;
  no untested new login flow or unused mirrored helper is introduced there.

New coverage: controller tests for expired/missing/cross-tenant pending state,
restricted user, rate limiting, mail failure and CSRF; dispatch tests extended to
conversion/platform KYC purposes; browser-script tests for login resend and for
separate KYC decision forms. Source checks assert the withdrawal pause stays ahead
of all POST handlers. Synthetic tests do not verify production mail or devices.

Still not completed: initial login/recovery/registration flow review, SendFund,
GiftCard/CryptoHub and specialist custody/repair OTP. PasswordRecovery code-send
currently changes server-rendered recovery stages and needs an explicit staged UI
and security review, not a generic no-reload patch. No claim of everywhere coverage.
No deployment or production changes. Crypto staging remains paused.

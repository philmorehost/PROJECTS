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

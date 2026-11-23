# Google-backed administrator recovery hardening

## Scope

Both editions' bc-admin PasswordRecovery and SAAS bc-spadmin PasswordRecovery.
NON-SAAS has no equivalent platform-admin recovery page. This does not modify normal
Google login, app sign-in, financial policies, provider gates or production settings.

## Changes

- Google credential POSTs join the existing recovery CSRF and mixed-action guard.
  The hidden Google callback form contains the session CSRF token.
- Google Identity Services is given a cryptographically random, role/tenant/session
  nonce with a ten-minute lifetime. Callback tokens must contain that nonce. It is
  consumed even on a failed callback, so retry requires a refreshed recovery page.
  Signature verification remains in the existing verifyGoogleToken implementation;
  nonce/claim checks are additional checks, not a substitute for signature verification.
- Tokens must pass the existing issuer/audience/verified-email checks and now also
  have exp and iat, be unexpired, and have been issued within five minutes (up to
  sixty seconds future skew). A token from another page/session cannot authorize
  recovery through a missing or reused nonce.
- Recovery selects a unique current mailbox match, within the current vendor for
  bc-admin. A linked Google subject must also match. A subject-only OR query can no
  longer recover an account with a different mailbox. Recovery no longer writes
  google_id or silently links an identity. Google linking needs its own flow.
- Approval is a five-minute (or earlier token-expiry) scoped record, not a boolean.
  Role, tenant, client configuration and recovery email must match. A fingerprint
  binds account ID, mailbox, linked subject, password, PIN, status, block and forced
  reset fields. Legacy booleans fail closed; changed/expired approvals require restart.
- The fresh target row is compared against that fingerprint before resetting.
  Password update is a prepared compare-and-set against the selected identity/security
  fields; a concurrent password/PIN/link/block/status change refuses the write rather
  than overwriting it. Exactly one affected row is needed for reported success.
- Approval is consumed before the write (as for email recovery), and PHP session ID
  is regenerated on successful Google approval. The reset does NOT clear the
  forced password-and-PIN setup flag, unblock accounts or modify balances.

## Validation and rollout limits

Synthetic tests cover nonce replay/expiry, role/tenant/client binding, stale-token
claims, conflicting linked subjects, legacy approval flags, fingerprint changes,
consumption, CAS refusal and callback integration. Disposable native MySQL tests
exercise the CAS update, stale password/PIN refusal and retention of forced setup.
They do not contact Google or verify a real browser/device callback. Existing
Google signature-validation transport was not replaced or production-tested here.

Old open recovery pages and old pending Google approval sessions must refresh or
restart. Missing nonce/CSRF metadata is intentionally refused. Account-enumeration
messages, Google login outside recovery, per-account session/token revocation,
password normalization/strength and full web/app KYC/enrolment sequencing still
need separate audit. No claim of complete authentication remediation is made.

No deployment, production reset, live Google/mail request or schema migration.
Gift-card settlement hold, bank payout/withdrawal blocks and crypto staging remain
unchanged. See GIFTCARD_SETTLEMENT_SAFETY.md for the still-unimplemented financial
settlement replacement; this auth work does not lift that hold.

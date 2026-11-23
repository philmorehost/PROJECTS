# Email password recovery: inline send and bounded challenges

Applies to web/user and bc-admin/vendor PasswordRecovery in both editions, plus
SAAS bc-spadmin PasswordRecovery. NON-SAAS has no corresponding spadmin page.

## Behavior

Send recovery code sends only the identity field, CSRF token and send marker using
same-origin POST. No password or entered code is sent to the dispatch action.
On confirmed mail success the server returns fixed, same-origin reset-form markup
with CSRF/code/password/confirmation fields and a disabled **SENT** button. JavaScript
advances only the form, without navigating or replacing the page. Dispatch errors,
invalid JSON, missing form data and network failures preserve the original inputs.
Duplicate in-flight clicks are blocked. Non-JavaScript sending keeps POST/redirect
behavior and the existing server-rendered reset stage. Completing the reset is still
a normal protected POST, not an asynchronous login/session grant. This patch does
not add a resend control within the second stage; restart recovery is available.

## Security

- Send, verify and restart require CSRF; mixed action markers are refused before
  processing. The existing Google identity callback is not modified by this guard.
- Email codes use random_int, not str_shuffle. Successful dispatch records a
  ten-minute session challenge bound to role, tenant and recipient. Code validation
  is strict six digits with constant-time comparison and at most five checks per
  challenge. Existing reset kill switches and lockout counters are retained.
- A session-wide sixty-second send cooldown covers all these recovery roles and
  attempted recipients, including unknown accounts. It supplements, not replaces,
  existing account/IP controls; it is not a new distributed/IP-wide limiter.
- A failed mail return clears the email challenge rather than exposing a reset
  form. Starting email recovery removes stale Google recovery authorization.
- A matching code is consumed before a password-write attempt when passwords match.
  SQL failure reports an unconfirmed reset and requires starting over, rather than
  reporting a successful password change. Session serialization provides same-session
  one-use handling; no claim of cross-session/database OTP claims is made.
- Old pending recovery sessions lacking the new metadata fail closed. Restart and
  request a new code. Expired/budget-exhausted codes likewise require restart.
- The existing forced password-and-PIN setup flag is not cleared by recovery. No
  wallet, KYC/KYB, account block or withdrawal/crypto pause is changed.

## Boundaries

This is not a full rewrite of legacy password recovery or Google identity recovery.
The existing known-account/unknown-account response distinction remains (this batch
is not an account-enumeration remediation). Google-proof expiry and account-wide
session revocation remain separate audit work. Existing password validation and
notification transport are retained; a mail function's positive return is not proof
of inbox delivery. No real mail, production account reset, provider or device test
was performed. Controller/source and isolated JavaScript fixtures use synthetic data.

Remaining original audit scope includes registration, SendFund, specialist crypto/
gift-card OTP and the broader web/app forced-reset/enrolment/compliance sequencing.
No deployment or live changes. Crypto staging stays paused.

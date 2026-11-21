# User API / account-action confirmation repair

## Cause and scope

The Users panels tried to open `bootstrap.Modal`, but the redesigned admin shell
no longer loads Bootstrap JavaScript. Some entry points also required a separate
SweetAlert confirmation before reaching that broken modal. The email-code button
redirected without preserving the selected action/target.

SAAS vendor and super-admin panels and the NON-SAAS admin panel now use a native
`dialog` confirmation, without Bootstrap/SweetAlert dependencies for API, status,
archive, permanent-delete, and impersonation confirmations. API badges in the
vendor panels are keyboard-operable buttons. Archive remains status 3; permanent
delete remains a separate action restricted to archived accounts.

Every protected action still requires CSRF, the existing Security PIN, and the
server-controlled second-factor policy: Google Authenticator for enrolled actors,
otherwise email OTP. Email cannot bypass enrolled TOTP. This change does not add
Google OAuth sign-in or weaken the existing authenticator policy. All verification
continues through existing throttled, single-use step-up helpers.

OTP requests and verification errors restore only the action/target from a
one-use session draft, never the PIN or verification code. Draft JSON is escaped
for script embedding. The gate rejects unknown actions/statuses, fails closed
without CSRF support, and discards unrelated legacy action query parameters.
NON-SAAS status/API updates now use the same authenticated-vendor-scoped lookup
and whitelisted update helpers as SAAS rather than re-resolving the vendor from
the hostname. Super-admin permanent delete no longer reports success on a no-op.

## Offline validation

```
npm ci --prefix VTU/tests/harness --ignore-scripts
node VTU/tests/harness/user-actions.test.mjs
node VTU/tests/harness/run-test.mjs tests/user_action_gate_test.php
node VTU/tests/harness/run-test.mjs tests/security33_test.php
node VTU/tests/harness/run-test.mjs tests/security_audit_stepup_probe.php
node VTU/tests/harness/run-test.mjs tests/security_audit_stepup_nonsaas_probe.php
```

The browser tests exercise the real dialog markup and extracted page functions at
390px and 1280px with no external modal libraries. Gate tests execute extracted
production gate code with stub verifiers, including missing factors, invalid PINs,
CSRF failures and invalid actions. They are not production database tests.

No deployment, live user deletion, SMTP delivery, or live Google Authenticator
verification was performed. After deployment, verify on a test account: both API
directions, archive/restore, and (only on a disposable archived account) permanent
delete. Test email delivery for an actor without enrolled TOTP and a fresh
Authenticator code for an enrolled actor. Existing financial holds remain unchanged.

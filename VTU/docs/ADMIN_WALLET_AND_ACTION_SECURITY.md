# Administrative wallets and action verification choices

## Status

Source changes only: no deployment, live wallet adjustment, production migration,
mail/provider request or crypto activation is performed by this change. Financial
holds, blocked vendors and disabled withdrawals remain in place.

## Funding fixes

- `bc-spadmin/ShareFund.php` accepts a vendor **email**, resolves exactly one vendor
  row, then passes its numeric ID to the durable wallet implementation. Previously
  the email was passed to a function expecting an integer ID.
- Platform funding of a vendor's user now always supplies the explicitly selected
  tenant ID. It no longer mutates a global override and relies on a host resolver
  whose static/session cache may already identify a different tenant.
- `bc-admin/ShareFund.php` always targets the authenticated vendor's own user.
  Submitted target/tenant overrides cannot choose another tenant or a vendor wallet.
- Amounts are validated as positive decimal values, not sanitized by stripping a
  minus sign or other input into a different amount. Unknown types are refused.
- Credit/debit use the existing InnoDB wallet mutation/event/receipt transaction.
  No schema guard, financial hold, insufficient-balance protection or replay claim
  is disabled to make funding work. An unavailable migration produces an actual
  wallet diagnostic instead of a false success. Missing/non-InnoDB storage must be
  reviewed in a separately authorized deployment; this patch does not convert tables.
- Each rendered form has a bounded server-issued request nonce, bound to its
  normalized action details on first execution. Reusing it cannot credit/debit
  again. Changed details require refreshing for a new request. An uncertain network
  response is NOT permission to create a second request: check history first.
- Refund is now a source-bound reversal, not a renamed arbitrary credit. Enter the
  original debit reference and the exact full amount. The source debit must have a
  durable event for the same tenant/recipient. Already-refunded source debits cannot
  be refunded again, even from a new form. Partial and unmatched legacy refunds fail
  closed. Reconcile historical manual credits/refunds before refunding old activity;
  this change does not infer links for old unreferenced manual adjustments.

Funding success means the local wallet/receipt transaction committed, not any bank
or external-provider payment. Reload history to verify balances after an uncertain
response. Never replay production transactions merely as a smoke test.

## Inline email OTP

The funding forms send only CSRF plus the send-code action for email dispatch.
The page stays open, all recipient/amount/PIN/code fields stay intact, and the button
shows **SENT** on a successful dispatch response. Duplicate in-flight requests are
blocked. Sending a code selects Email OTP on that form, so an enrolled authenticator
no longer causes a deliberately selected email code to be interpreted as TOTP.

Wallet execution also uses an inline result; success disables execution until a
manual refresh. Errors preserve input. A network/parse failure warns that the
outcome is uncertain rather than declaring the wallet operation failed.
The vendor-management modal's OTP send is also inline. Existing standalone user
account confirmations show enabled channels and use inline SENT feedback. Paid API
and app-update confirmation components now reflect the selected action policy too.

## Independent channel choices

Open **Account Settings -> Security -> Action verification**:

- SAAS bc-spadmin controls its own action policy and the default for tenants that
  have no override.
- SAAS bc-admin may choose independent Google Authenticator / Email OTP action
  switches for its own tenant and users, or explicitly inherit platform defaults.
  This does not change another vendor's policy. Platform choices are defaults,
  not an imposed ceiling on tenant channel preferences.
- NON-SAAS bc-admin has the same independent choices for its owner and users.
- Missing settings preserve the previous secure default: both channels enabled,
  preferring an enrolled authenticator. When both are enabled, an action form can
  explicitly select email. An unavailable/malformed policy fails closed.
- Turning both OFF requires an explicit risk acknowledgement and enables **PIN-only
  action verification**. It is less secure. Authentication, CSRF, PIN throttling,
  account blocks, KYC/KYB and financial restrictions are not turned off.
- Changing the policy itself ALWAYS requires current password + PIN + an existing
  enrolled authenticator, or email OTP if not enrolled. The old factor is not
  erased. PIN-only mode never authorizes its own policy downgrade without a factor.
- Existing forced-authenticator **setup** mandates are separate controls on the
  same Security tab. Turning off an action channel does not erase enrolment or
  silently override a mandatory setup/reset/compliance requirement. Review those
  explicit mandate controls separately if changing the enrolment requirement.
- Account Settings unlock follows the action policy when Google action checks are
  OFF: PIN + email, or explicitly selected PIN-only, for at most ten minutes. A
  policy/security-identity fingerprint invalidates incompatible unlock windows.
  CSRF is required on settings POSTs; their HTML forms now include tokens. Google
  unlock also atomically claims a TOTP step, preventing concurrent code reuse.

These are **shared PIN/step-up action verification** preferences, not a switch to
turn off login identity proof, password recovery/verification emails, forced-reset
completion, or specialist crypto-custody approvals. Some sensitive feature setup
requirements (such as platform gateway-price authenticator enrolment) remain
explicit prerequisites. Do not interpret the toggles as a site-wide bypass of all
security. Crypto staging is still paused and no live switches are changed.

## Scope still requiring follow-up

This is not a claim that the entire original web/app authentication/KYC backlog is
finished. The isolated legacy clone upgrade backend, complimentary super-admin
upgrade route and remaining unrelated OTP/login surfaces still require their own
focused changes/tests. No signed app/device or live mail/provider behavior is
verified here. Native CI fixtures use disposable MySQL databases, synthetic actors,
local TOTP and a deterministic email-code verifier; they never send real mail.

# Registration OTP follow-up

Both editions' web registration already used AJAX. This batch fixes remaining
resend behavior rather than claiming a new universal OTP implementation.

- Extracts the shared registration browser script into `asset/bc-registration-otp.js`.
- Serializes send/resend against each other and verification. Closing the modal
  cannot interrupt an in-flight send/verification and allow another attempt.
- Resend never clears entered digits before or after its response. Successful
  dispatch shows **SENT** / “use the newest code” and restarts the countdown. A
  retained old code is not silently changed into the newly issued code.
- Refused requests, invalid JSON and network failures keep the code and registration
  inputs. Initial dispatch errors are inline instead of extra alert dialogs.
- Registration fields (including the password) still go to the same-origin send
  endpoint to construct/replace the pending registration; this is not a purpose-only
  resend API. The browser does not place them in URLs. Verification sends only the
  code and CSRF. Successful account creation still redirects to security-PIN setup.

## Server protections

Both send/verify require CSRF, no-store responses, a current active tenant and the
current registration service switch. Verification requires the pending tenant to
match the current host-resolved tenant, keeps the ten-minute expiry and constant-time
comparison, and caps a challenge at five checks. The session send cooldown no longer
resets merely by changing email. Mail failure invalidates the challenge and does not
report SENT. The challenge is consumed before INSERT; an INSERT failure cannot send
welcome mail, grant a session or report successful account creation. Successful
AJAX registration regenerates the PHP session ID.

The old JavaScript-disabled Register.php fallback cannot create an account without
email verification when OTP is enabled; it asks for the supported JavaScript flow.
It also requires CSRF. Vendors explicitly allowing OTP-disabled registration retain
that mode. This batch does not introduce a no-JavaScript OTP UI.

## Validation and limits

Synthetic controller/source fixtures cover binding, expiry, attempt exhaustion,
consumption and handler guards. Browser-script fixtures cover both editions' resend
success/refusal/invalid JSON/network paths, duplicate suppression, entered-code and
password preservation and no dispatch-time redirect. These do not prove inbox
receipt, device behavior or production account creation. Database-wide registration
uniqueness races and the legacy normal-form fallback implementation require separate
work; no claim of global exactly-once registration is made. Native app registration
uses separate endpoints and is not rewritten by these browser changes.

No deployment, production registration, mail/provider requests or migrations.
Withdrawal and crypto staging pauses stay unchanged. Remaining original audit scope:
SendFund, specialist gift-card/crypto confirmations, Google recovery/session revocation
and the broader web/app forced password+PIN/enrolment/KYC sequencing.

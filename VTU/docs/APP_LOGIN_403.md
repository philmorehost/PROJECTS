# App login 403 after email OTP

## Confirmed source behaviour, not a live-account diagnosis

The SAAS OTP endpoint returns HTTP 403 with `code: BUSINESS_WEB_ONLY` and
`message: Business accounts are accessible via web only` when a correct OTP is
submitted for a Business account. This is an existing access policy, not an OTP
validation failure. Unknown/ineligible account tiers can also be denied. Android's
three app variants discarded non-2xx response bodies and displayed only
`Server error: 403`, hiding the actual reason. A proxy/WAF could independently
return 403; production logs are needed to distinguish that from policy denial.

## Corrections

- SAAS password login now checks mobile eligibility after valid credentials and
  active status but before issuing/emailing an OTP. Ineligible users are no longer
  sent through an OTP step that cannot admit them. The OTP endpoint still checks
  eligibility again, so changing tiers while a challenge is outstanding cannot
  bypass the restriction.
- DG7, JIKABIZ and MZEEVTU Android login/OTP repositories now decode structured
  `message`/`desc` fields on failed HTTP responses. Malformed, HTML, or empty bodies
  receive a useful status-specific fallback; raw proxy HTML is not displayed.
- No 403 was converted to 200, no Business account was allowed into the app, and
  no OTP, lockout, account-status or financial hold check was removed. iOS already
  decodes the structured message from these login responses, so it was not changed.

## Validation and rollout

```
node VTU/tests/harness/run-test.mjs tests/fintech_gate_test.php
python3 VTU/tests/mobile_login_error_test.py
```

Native JUnit cases are included beside each Android variant's source for Business
policy errors, outage descriptions, HTML rejection and empty/malformed responses.
They were not executed here: no Android SDK/Kotlin build toolchain was available.
No APK/IPA was built and no device, live OTP, production account or WAF was tested.

Deploy the server change and rebuild/install Android apps for the improved error
text. Existing installed apps retain their old error handling until updated.
Business accounts must continue to use the website unless the owner explicitly
requests an access-policy change. For a Personal account still receiving 403,
collect the response `code`/`message` and correlate the route/time in access/error
logs. Do not collect passwords, OTPs, login challenge tokens or app tokens.

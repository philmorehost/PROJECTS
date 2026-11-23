# SendFund / gift-card / CryptoHub email OTP follow-up

## Both editions

SendFund and GiftCard code sending uses `bc-feature-email-otp.js`: same-origin POST
with CSRF and only the fixed dispatch marker (no PIN, code, recipient, amount or
financial submit fields). It never submits or resets a financial form, reloads the
page or displays modal alerts. Success shows SENT, errors remain inline, concurrent
clicks are blocked and the button is released after network/JSON/server errors.
The server enforces a sixty-second per-session actor/tenant/feature cooldown, active
unblocked account checks, mandatory-reset restriction, valid email, cryptographic
six-digit code and mail return handling. Failed dispatch invalidates the old code
rather than claiming success. A positive mail return is not proof of inbox delivery.

The existing ten-minute legacy code/time keys remain compatible with the payment
controllers. Added scope markers bind newly issued codes to user ID, vendor ID and
feature; corresponding legacy verification conditions now reject mismatched scopes.
Old sessions lacking this marker must request a new code. No change is made to the
existing PIN, authenticator, financial holds, service gates or provider provisioning.
Feature-specific mandatory email requirements are not silently disabled by general
action-channel choices.

CryptoHub's existing unprovisioned-provider server gate is unchanged. The misleading
code button is now disabled and reads Unavailable. It does not send a request/mail,
report SENT or activate any service. Administrative Withdrawals' non-GET pause and
Paused UI remain unchanged. SendFund's existing withdraw service toggle is retained;
this patch does not enable it or any production configuration.

## Validation / remaining work

Synthetic helper/source tests cover valid dispatch, cooldown, failed mail, actor
restrictions, CSRF and scope mismatch. Isolated JavaScript tests cover both features
and editions: success, refused response, invalid JSON, network failure and duplicate
send. They assert only CSRF/dispatch fields are sent. No real mail/provider, device,
production financial action or deployment was performed.

This is a dispatch/UI and challenge-scope patch, NOT a certification of legacy
financial controllers. SendFund/GiftCard final mutations, legacy OTP replay/attempt
handling and other non-OTP action CSRF paths require a separate focused audit before
any paused financial service is enabled. Existing provider/order idempotency and
wallet accounting are not replaced by this helper. No claim of complete site-wide
OTP or web/app authentication/KYC coverage is made. Google recovery and session
revocation also remain separate work. Crypto staging remains paused.

# Email-verified authenticator setup (no reload)

## User flow

Both editions now share one setup card for users and vendors, plus the SAAS Super Admin account. It is used on Account Settings and the mandatory SecuritySetup page:

1. Send the code to the existing account email (no editable recipient).
2. Verify the six-digit email OTP.
3. Generate/show the setup key and local QR code; add it to Google Authenticator as a time-based entry.
4. Enter the six-digit **app** code and choose **Save & enable Google Authenticator**.
5. A persistent inline **Saved — Google Authenticator is enabled** message replaces the form. The displayed key/QR and input codes are cleared. Continue is an explicit link, never an automatic reload.

Generating a key is labelled **not yet saved**. Retrying a pending setup resumes its existing encrypted key rather than rotating it. Invalid email/app codes, network failures and session errors remain on the same card. Clicking or pressing Enter cannot submit the setup form as a page navigation; buttons disable while a request is in progress. No new provider/crypto operations are introduced in this increment.

## Security boundary

The handler is invoked only from authenticated area bootstraps or the existing mandatory setup page; table/actor cannot be supplied by the browser. Every setup request requires POST/CSRF and a fresh database actor, current email/scope/session binding, active/unblocked account and completed required password change. The existing session epoch remains checked. An email OTP is scoped to role/table, actor, tenant, email and session; five-attempt single-use OTP verification is retained. Successful verification grants ten minutes for generating/resuming and confirming the key, not for changing settings or moving funds. Grants are session-local, invalidated by resend and consumed after successful setup. Per-actor send and verify/generate/confirm throttles apply.

Super Admin codes use the platform mail sender; vendor/user codes use the tenant sender. Failed delivery is reported instead of claiming the code was sent. Email delivery itself still depends on configured working SMTP/mail; no live email was sent in these tests. The existing sealed pending-secret store, TOTP proof and compare-and-set activation remain in use. Already-enrolled requests are idempotent and cannot overwrite or disclose the secret. Legacy begin/confirm page POSTs are intercepted, preventing an alternate email-free enrollment path. Mandatory password confinement and existing PIN/TOTP requirements, enrollment policies, settings unlock window and disable rules are unchanged. Enrollment does not silently unlock financial actions or settings.

QR generation uses a same-origin copy of qrcode-generator 1.4.4 (MIT attribution retained in the asset), not a remote QR service or setup-time CDN. Secrets are returned only in protected no-store POST responses and displayed locally; no secrets in URL/storage/console. Unexpected server errors return generic messages, not SQL or setup payloads. As before, privileged browser extensions, shared devices and compromised application servers remain outside this UI's protection.

## Other requested fixes

- SAAS vendor System Function navigation no longer advertises System Update. Super Admin retains it. Standalone owners retain their own updater. A deny-only SAAS `bc-admin/UpdateSystem.php` responds 403 without loading updater code, also replacing stale copies on later source uploads. No update or deployment was run.
- SAAS PaymentGateway's optional webhook clipboard button previously dereferenced a missing array key inside a JavaScript string near line 666. Warnings could spill into the HTML as the reported `')">` artifact. Optional URLs now use a guarded, HTML-escaped value; no copy button when absent. Clipboard code copies the input's DOM value rather than embedding PHP-generated strings in JavaScript. The standalone clipboard also uses the DOM value.

## Validation

New PHP contracts exercise all three actor scopes, email grant requirement/expiry/isolation/consumption, retry key stability, app proof, active-state idempotency, password/block/rate/delivery refusals, wiring/parity and mandatory-page integration. UI tests in jsdom exercise send/verify/generate/save, invalid input, wrong app code, network failure, in-place status and secret clearing. Regression tests exercise actual menu data and render the exact SAAS webhook fragment with a missing key and hostile URL under warnings-as-exceptions. Existing enrollment/password, Google SSO, settings gate and admin shell suites must still pass.

Only the intentionally changed AccountSettings authenticator pending/begin blocks were updated in the body snapshots; unrelated bodies and assertions remain intact. A previously known broader migration-contract failure on CoinConversion is not suppressed by this change. CI results must be recorded before merge. No live SMTP, browser/device scan, production migration, deployment or system update is claimed.

# Email assets

Both editions normalize outgoing image URLs to HTTPS, remove inline/CSS/VML background imagery, and replace unsupported SVG images with their linked alt text. Saved template rows are not rewritten. Solid background colours remain.

The shared header resolves the canonical stored vendor domain (including legacy values saved with https://), checks the actual uploaded logo, and falls back to readable site-name branding if absent. PNG, legacy JPEG and GIF content is recognized by signature even when historically saved under `_logo.png`. Logo URLs carry an upload-mtime cache version. Both PHPMailer send paths embed that local logo as a MIME-related CID attachment; mail() fallback retains the public HTTPS URL. No remote fetching or arbitrary local-path embedding is performed.

The app CTA is ordinary linked text, not the former remote Wikimedia SVG. Relative APK links become absolute. Custom template wording, links and transaction details are retained.

Validation: `node VTU/tests/harness/run-test.mjs tests/email_assets_test.php` (53 checks), `tests/security33_test.php` (86), `tests/totp_claim_test.php` (26). Existing `brandemail46_test.php` still has six unrelated transaction-source assertions failing (phone/reference counts and verify-funding wording); email/OTP/default-template assertions pass.

No live SMTP/Gmail delivery was performed. Missing custom remote images cannot be recreated, and remote hosts can still deny email-client requests. Recipient image-blocking preferences still apply. Existing delivered emails cannot be changed; send fresh test messages after deployment. Include `func/bc-email-assets.php` when deploying. No database migration is needed.

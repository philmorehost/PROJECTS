# Console migration test reconciliation

The original `plate_migration_test.php` asserted byte-identical pre-security-remediation bodies. That is no longer valid for BusinessUpgrade (KYB submission helper), CoinConversion (atomic conversion helper), SendFund (shared inline email OTP), handle_upgrade (verified atomic paid entitlement), InvoiceReturn (sessionless non-authoritative return), Receive (invoice intent) and LiveRates (indicative-only FX).

The test now pins explicit positive and negative boundaries for these pages in both editions rather than regenerating old snapshots or blanket-exempting them. Existing shell/chrome checks and unchanged-body checks for unrelated pages remain. Runtime authorization, transactional and provider tests are still required; source contracts alone do not establish production safety.

Local PHP-WASM result: 1,258 migration contracts pass. The broader suite has additional debt discovered after this first failure was removed: backoffice CDN icon-version assumptions, upgrade25 obsolete dollar-reward/legacy-card-route contracts, and provider_live Klasha credential fixture incompatibility. Those are not waived by this patch. Native CI remains authoritative, and passing this one test is not a claim of a green release suite.

## Follow-on contracts reconciled

Backoffice now verifies the actual vendored Bootstrap Icons v1.11.3 CSS and `bi-ban` glyph for the app shell, while retaining CDN-version checks for shells still using the CDN. The WASM harness mirrors that specific stylesheet; no production asset changes.

Upgrade25 now checks the intended 1,000-coin/$100-volume referral defaults and legacy-card route retirement/security holds instead of expecting dollar payouts or reactivated routes. Provider adapter tests explicitly double the entitlement/storage boundaries (real SQL/encryption are covered separately), test unentitled refusal with zero transport calls and saved-credential preference, and retain the card settlement hold. These adapter tests use a captured transport, not live provider verification. No production provider paths, entitlements or security holds were loosened.

Focused local checks: migration 1,258; backoffice 186; upgrade25 264; provider adapter 76. Native PHP CI is requested for both versions; this note is not an assertion of its outcome.

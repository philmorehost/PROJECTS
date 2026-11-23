# Console migration test reconciliation

The original `plate_migration_test.php` asserted byte-identical pre-security-remediation bodies. That is no longer valid for BusinessUpgrade (KYB submission helper), CoinConversion (atomic conversion helper), SendFund (shared inline email OTP), handle_upgrade (verified atomic paid entitlement), InvoiceReturn (sessionless non-authoritative return), Receive (invoice intent) and LiveRates (indicative-only FX).

The test now pins explicit positive and negative boundaries for these pages in both editions rather than regenerating old snapshots or blanket-exempting them. Existing shell/chrome checks and unchanged-body checks for unrelated pages remain. Runtime authorization, transactional and provider tests are still required; source contracts alone do not establish production safety.

Local PHP-WASM result: 1,258 migration contracts pass. The broader suite has additional debt discovered after this first failure was removed: backoffice CDN icon-version assumptions, upgrade25 obsolete dollar-reward/legacy-card-route contracts, and provider_live Klasha credential fixture incompatibility. Those are not waived by this patch. Native CI remains authoritative, and passing this one test is not a claim of a green release suite.

# Server-bound checkout intent lifecycle — held foundation

This increment is an INTERNAL library (`func/bc-checkout-intents.php`) and
unapplied DDL (`VTU/migrations/20261118_checkout_intent_lifecycle.sql`) for both
editions. `PAYMENT_INTENT_REQUIRED` (HTTP 503) remains active on
`web/api/create-checkout.php` and `web/api/payhub-checkout.php`.

## Contract

- **Paired immutable intent creation (`bc_chk_create_intent`)**: verifies
  active/unblocked tenant user, enforces `(vendor_id, user_id, request_key_hash)`
  idempotency/conflict protection, and atomically inserts both
  `sas_checkout_intents` (with bounded TTL `expires_at` and versioned state) and
  `sas_secure_payment_intents` (consumed by `bc-fintech-webhook.php`).
- **Provider attachment & expiry (`bc_chk_attach_provider`, `bc_chk_close_intent`)**:
  attaches a provider session/reference before expiry (`created` ->
  `provider_pending`), or cleanly transitions unsettled intents to `expired` /
  `cancelled`.
- **Authoritative settlement binding (`bc_chk_reconcile_settlement`)**: checks
  that verified `provider`, `currency`, exact 2-decimal `gross_amount`, and
  `provider_reference` match the server-created intent and are within expiry;
  any mismatch or expired settlement transitions the intent to `mismatch_held`
  for manual review rather than auto-crediting.

## Remaining gates before reopening hosted/card checkout

1. Operator execution of `20261118_checkout_intent_lifecycle.sql` and live InnoDB verification.
2. Provider checkout-session creation & callback wiring through `bc-fintech-webhook.php`
   plus historical checkout obligation reconciliation.
3. End-to-end sandbox/live provider acceptance across `SAAS` and `NON-SAAS`.

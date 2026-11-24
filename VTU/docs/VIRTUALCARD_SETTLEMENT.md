# Virtual-card durable reservation & settlement foundation — held

This increment is an INTERNAL library (`func/bc-virtualcard-settlement.php`) and
unapplied DDL (`VTU/migrations/20261118_virtualcard_settlement.sql`) for both
editions. Existing HTTP/UI and provider adapter holds (`CARD_SECURITY_HOLD` on
`web/api/virtual-card.php` and `security_review_required` on
`func/bc-virtualcard-flutterwave.php`) remain active and are not modified here.

## Contract

- **Fixed-point USD -> NGN quotes (`bc_vc_compute_ngn`)**: computes
  `(amount_usd + fee_usd) * fx_rate_ngn_per_usd` with half-up 2-decimal rounding
  using pure string integer math.
- **Atomic reservation (`bc_vc_reserve_issue`, `bc_vc_reserve_fund`)**: locks
  `sas_users` (and `sas_virtual_cards_v2` for top-ups, enforcing active
  Flutterwave card ownership), verifies active/unblocked status, checks
  `(vendor_id, user_id, request_key_hash)` idempotency/payload-hash conflict, and
  atomically debits NGN + writes `sas_wallet_mutation_events`, `sas_transactions`
  (`status=2`), and `sas_virtualcard_orders` (`state='held'`) inside one InnoDB
  transaction.
- **PCI-safe outcome reconciliation (`bc_vc_observe_outcome`)**: refuses
  completing `held` orders without dispatch, preserves `unknown` outcomes without
  auto-refund, validates `**** **** **** NNNN` masked PAN + empty `cvv` before
  inserting `sas_virtual_cards_v2` on `issue` success, atomically increments
  `balance_usd` on `fund` success, and refunds NGN at most once (`refunded_at`)
  on `confirmed_failure` or pre-dispatch `bc_vc_cancel_held`.

## Remaining gates before reopening virtual cards

1. Operator execution of `20261118_virtualcard_settlement.sql` plus `SHOW TABLE STATUS`
   verification that `sas_virtual_cards_v2` and related tables run on `InnoDB`.
2. Historical reconciliation of legacy card balances/transactions and CVV/PAN scrub
   verification.
3. Confirmed Flutterwave Virtual Cards API documentation, endpoint configuration,
   webhook/status verification, and authorized dispatch worker wiring.

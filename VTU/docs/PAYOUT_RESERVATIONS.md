# Durable payout reservation and reconciliation foundation — held

This increment adds an internal library (`func/bc-payout-reservations.php`) and explicit operator migration (`migrations/20261118_payout_reservations.sql`). It is **not** a live payout worker, public HTTP API, or hold release: `web/func/bank-transfer.php`, `web/api/withdrawal.php`, and `bc-admin/Withdrawals.php` remain held (`PAYOUT_SECURITY_HOLD` / HTTP 503).

## Contract

1. Apply `VTU/migrations/20261118_payout_reservations.sql` outside application transactions. Runtime functions execute zero DDL and fail closed when tables are missing, non-InnoDB, or called inside an active outer transaction (`@@session.autocommit != 1` or failed `SET TRANSACTION ISOLATION LEVEL REPEATABLE READ`).
2. `bc_payout_reserve(...)` atomically locks `sas_users` (`FOR UPDATE`), checks `status=1`, `is_blocked=0`, `force_password_change=0`, enforces `balance >= amount + fee` in SQL, writes a `sas_wallet_mutation_events` debit claim, records a pending `sas_transactions` row (`status=2`), and inserts `sas_payout_reservations` in `state='held'` with a server-generated `reference` and 64-hex `provider_key`.
   - Replaying the same `(vendor_id, user_id, request_key)` with identical payload returns the original reservation without debiting again.
   - Reusing the same key with altered payload returns `conflict`.
3. `bc_payout_observe_outcome(...)` requires caller-authenticated provider evidence.
   - Observations against `state='held'` are journaled with `disposition='held'` and cannot mark a held payout succeeded or dispatched.
   - `unknown` outcomes transition `dispatched -> unknown` without refunding or retrying.
   - `confirmed_success` transitions `dispatched|unknown -> confirmed_success` and updates `sas_transactions.status = 1`.
   - `confirmed_failure` transitions `dispatched|unknown -> confirmed_failure`, updates `sas_transactions.status = 3`, and atomically refunds `total_debit` ONCE via `sas_wallet_mutation_events` (`refund:<reference>`) + `sas_transactions` refund receipt.
   - Provider references are immutable once bound; stale expected versions and conflicting terminal outcomes are preserved in `sas_payout_reservation_events` without mutating state or double-refunding.
4. `bc_payout_cancel_held(...)` explicitly cancels and refunds ONLY pre-dispatch (`state='held'`) reservations. Dispatched or `unknown` payouts are refused (`not_held`).

## Remaining gates before lifting payout holds

- Independent historical bank-transfer reconciliation for pre-hold records.
- Authorized payout dispatch worker with lease management, step-up/separation-of-duties approval integration, and verified provider sandbox acceptance for transfer initiation, webhook/status verification, and idempotency-key retention.
- Deployment of `migrations/20261118_payout_reservations.sql` and live InnoDB verification.

# Held Replacement Crypto Buy/Sell Foundation

## Boundary
- **Bybit remains retired** (`410` / `bybit_retired`). This foundation is for the replacement Admin ↔ User Buy/Sell architecture under `VTU/docs/CRYPTO_REPLACEMENT_AND_TEMPLATE_SCOPE.md`.
- **Held by default:** `CRYPTO_BUYSELL_EXECUTION_ENABLED=1` is unset, so buy orders park in `held` and signer outbox rows park in `held`. No live keys, seeds, or chain broadcasts exist in PHP request handlers.

## Schema & Paired Helper
- Migration: `VTU/migrations/20261118_crypto_buysell_foundation.sql` (`sas_crypto_quote_locks`, `sas_crypto_trade_orders`, `sas_crypto_trade_events`, `sas_crypto_chain_deposits`, `sas_crypto_signer_outbox`, all `ENGINE=InnoDB`).
- Identical helper: `VTU/DGV7.0-{SAAS,NON-SAAS}/func/bc-crypto-buysell.php`.

## Invariants Verified
1. **Fixed-point / string integer math:** `bc_cbs_compute_fiat_total()` multiplies `crypto_units_1e8` by `rate_fiat_minor` without floats (32-bit/php-wasm safe), rounding up on BUY and down on SELL.
2. **2-minute quote lock:** Quotes expire at `<= 120s`, require `kyc_verified=1`, active non-blocked user (`sas_users.is_blocked=0`), and per-network destination validation (`bc_cbs_validate_destination`).
3. **Atomic reservation & signer outbox:** `bc_cbs_execute_buy_order()` locks user + quote `FOR UPDATE`, creates a `sas_wallet_mutation_events` claim, debits with `AND balance >= ...`, inserts `sas_transactions`, creates the order, and writes `sas_crypto_signer_outbox`.
4. **Timeout / `unknown` non-refund:** `bc_cbs_observe_payout_outcome()` only refunds once on `rejected_before_broadcast` from `held`/`never_attempted`; once marked `unknown`, refunds and automatic retries are refused.
5. **SELL deposit finality & reorg safety:** `bc_cbs_record_sell_deposit()` deduplicates `(vendor_id, network, tx_hash, log_index)`, classifies `pending_confirmations`, `wrong_network_held`, `reorged_held`, `underpaid_review`, `overpaid_review`, and `matched_final`, and credits Naira once via `bc_cbs_settle_sell_deposit()` only when `matched_final`.

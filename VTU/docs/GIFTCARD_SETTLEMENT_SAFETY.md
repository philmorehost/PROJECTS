# Gift-card settlement: containment pending a durable implementation

## Critical findings

Both editions exposed the same legacy helpers to web and app/API routes:

1. `holdP2PFunds` selected a user by username without vendor scope, then updated
   every matching username without locking, checked results, receipt or replay key.
2. `releaseP2PFunds` separately decremented escrow, called a wallet helper with its
   own transaction, credited the vendor, inserted earnings, changed card ownership
   and marked the trade released. Results were unchecked, and no single transaction
   or state claim protected concurrent callers or partial failure.
3. Provider purchase placed a Reloadly order before checking whether the subsequent
   local debit succeeded. A seconds-based provider identifier was not a durable
   idempotency claim; repeated/uncertain requests could issue more than one order.

Moving the debit before the provider call is NOT sufficient: a timeout might mean
an issued card with an unknown response. Automatically refunding or retrying that
request can create loss. Existing balances/escrow/trade records cannot be safely
reconstructed from this audit alone.

## Source-level containment in this change

**New gift-card purchases and P2P funding/release are held in both editions.** This
is intentionally a temporary service restriction, not a claim of implemented safe
settlement. No production switch is changed and nothing is deployed by this work.

- Authenticated web and app/API purchase/P2P actions return HTTP 503, JSON
  `status=error`, `code=GIFTCARD_SETTLEMENT_HOLD`, and no-store headers before
  provider token/order, debit, new escrow/trade or ownership work in those branches.
- Purchase OTP sending is held too, to avoid sending a code for an unavailable action.
- Shared `placeReloadlyOrder` returns FAILED with the hold code without cURL. Shared
  `holdP2PFunds` and `releaseP2PFunds` return false without SQL/wallet calls. The unsafe
  implementations were removed, not left behind an environment/config override.
- Catalogue, owned-card display/history, market browsing and existing non-financial
  listing/delisting/transfer code are retained. They are NOT certified as fully audited.
- Visible purchase, P2P and purchase-code controls are disabled, with a safety notice.
- Existing funded trades are neither released nor refunded; reconcile them separately.
  Never invoke the old release implementation as a repair procedure.

Read-only catalogue/API authentication may still use existing network/database
helpers. This is a financial-mutation hold, not a promise that every read is offline.
There are no new migrations, automatic data repair or production account mutations.
The separate payout, administrative withdrawal and crypto-provisioning blocks remain.

## Requirements before a future implementation replaces this hold

- Explicit tenant/actor/recipient IDs and immutable server-side product/denomination,
  quote, price and fee snapshots; authenticated PIN/step-up/compliance checks.
- Durable intent/idempotency key, payload hash and state machine. Claim and reserve
  funds in one InnoDB transaction, including receipt/event records. No nested use of
  wallet helpers that start independent transactions.
- No provider I/O with SQL row locks held. Stable provider request ID, response
  reconciliation and unknown/manual-review states. No timeout-triggered auto-refund
  or blind retry; confirm provider idempotency/query semantics before activation.
- Persist delivery and final wallet/inventory/receipt transitions with checked writes.
  No synthetic fallback card codes. Cross-session/concurrent duplicate requests must
  resolve to the same durable intent instead of executing another provider order.
- P2P must atomically lock tenant, listing/card, buyer and seller in a consistent
  order, enforce ownership/status and sufficient spendable funds, and commit all
  balances/fees/receipts/ownership/trade state together with a unique claim.
- Missing/non-InnoDB schema must fail closed. No live migration, release/refund or
  reconciliation should be performed without a separately reviewed deployment plan.
- Disposable MySQL concurrency/fault-injection tests: duplicate buys/releases,
  ownership changes, same usernames in different tenants, insufficient funds,
  statement/commit failure, blocked actors and provider unknown outcomes.

## Held settlement foundation (unactivated)

Both editions now include `func/bc-giftcard-settlement.php` and unapplied DDL
`VTU/migrations/20261118_giftcard_settlement.sql` (`sas_giftcard_orders`,
`sas_giftcard_order_events`, `sas_giftcard_escrows`, `sas_giftcard_escrow_events`):

- **Direct Reloadly orders (`bc_gc_reserve_order`, `bc_gc_observe_order`,
  `bc_gc_cancel_held_order`)**: server-side fixed-point NGN quote snapshots using
  `sas_vendor_giftcard_products.vendor_markup`, atomic InnoDB debit +
  `sas_wallet_mutation_events` claim + `sas_transactions` receipt (`status=2`),
  `unknown` state preservation without auto-refund, and atomic
  `sas_giftcard_inventory` delivery on `confirmed_success` (refusing empty card codes).
- **P2P escrow (`bc_gc_p2p_hold`, `bc_gc_p2p_release`, `bc_gc_p2p_refund`)**:
  ascending user-ID row lock ordering (`min/max`) alongside inventory row lock,
  atomic listing lock (`is_for_sale=1 -> 0`) + buyer debit, and single atomic release
  (net seller credit + ownership transfer) or single refund + relist.

**`GIFTCARD_SETTLEMENT_HOLD` remains active on all HTTP/UI routes.** These functions
are not wired into `GiftCard.php`, `web/api/gift-card.php`, or `placeReloadlyOrder()`;
production activation still requires operator schema migration, historical escrow/trade
reconciliation, Reloadly live/sandbox idempotency acceptance, and encrypted card code
key-management review.

## Validation and limits

Synthetic tests call held low-level helpers with no database/provider available,
verify that they refuse every request without touching session/auth state, and check
that both web/API gates precede the legacy purchase branches. Additionally,
`VTU/tests/giftcard_settlement_foundation_test.php` and
`VTU/tests/giftcard_settlement_mysql_test.php` test the held settlement foundation
(fixed-point order/P2P split math, concurrency races, trigger-injected rollbacks,
outer-transaction/MyISAM refusal, and atomic release/refund invariants) across both
`SAAS` and `NON-SAAS` on disposable MySQL 8.0. These do not execute live Reloadly
API calls, run live migrations, or authorize lifting `GIFTCARD_SETTLEMENT_HOLD`.

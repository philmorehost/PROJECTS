# Referral coins and qualification

Applies to both editions. Loyalty Settings now has `referral_reward_coins`
(default **1,000**, zero disables rewards). Only the referrer earns coins.
The qualifying principal is fixed at **USD 100**, enforced server-side regardless
of the old configurable threshold. Paid rewards snapshot their coin amount;
pending estimates use current settings until qualification.

## What counts

Only completed **Crypto trades** and **International Transfers**, excluding fees,
funding, internal transfers, deposits, quotes, failed/pending activity and
reversals. Dollar principal is recorded in integer cents; no guessed live-FX
conversion, browser-supplied volume or amount-only hook can award coins.

The existing international-transfer screen records *requests*, not completed
provider settlements; the old referral volume function had no production caller.
Accordingly, this release uses an **explicit admin settlement audit**, linked from
Loyalty Settings (`bc-admin/ReferralSettlements.php`). An authenticated vendor
admin must check completion and USD principal against the actual provider, then
confirm using CSRF + Security PIN + authenticator/email OTP. The audit records
provider reference, actor, tenant, referee and amount. References cannot repeat
across tenants for the same provider. A request alone never qualifies.

**Operational dependency:** progress updates after this audit, not directly from
provider webhooks. This is not an automated provider settlement integration.
Admins must promptly audit refunds/reversals as well. Future adapters must call
the trusted settlement function only after authenticating provider evidence and
binding the reference, user, tenant, category and USD principal to the real order.
Never expose this function as a public volume-submission API.

## Accounting and abuse protections

- Locked rewards do not enter `sas_points_log`; there is nothing to withdraw.
- At $100, active accounts with distinct email/phone and verified emails are
  eligible. Obvious self-referrals are rejected; admins must check identity and
  abuse concerns. This is not a guarantee against sophisticated multi-account
  fraud or provider chargebacks.
- One award row per tenant/referee. Settlement insertion, points credit and the
  awarded flag commit together. SQL failures roll back; duplicate references
  fail closed. A successful retry cannot issue a second award.
- A reversal dropping the total below $100 writes one negative coin adjustment.
  Requalification never issues a fresh reward. If coins have already been
  converted, negative coin debt blocks further conversion; it does **not** undo
  completed bank withdrawals or claw back main-wallet cash automatically.
- Web/API conversion submissions reserve unlocked coins under the user's row
  lock. Approvals lock the same user then request, recheck all reservations and
  available coins, debit once and credit the wallet in the same transaction.
  GET mutations are removed, and browser mutations require CSRF tokens.
- InnoDB is mandatory for all five participating tables; otherwise reward writes
  and conversions fail closed. Missing coin schema also fails closed.

## Legacy rollout

The first-purchase coin shortcut and the separate two-sided USD reward are
retired. Old volume counters are not trusted or imported as settlement evidence.
Already-awarded referrals cannot earn another new reward. Old first-purchase
coin logs have no per-referee reference, so their aggregate coin bucket is held
until **all** previously rewarded friends for that referrer have audited $100
qualification. Verifying them releases existing coins without minting new ones.
This conservative hold is shown separately in the web/API growth data. It may
reduce balances on rollout: notify users and reconcile legacy evidence first.
Historical cash rewards/conversions are not retroactively undone.

New tables are additive and created lazily. Deploy all changed files together;
back up and check storage engines before rollout. No old reward rows are deleted.

## Validation

`node VTU/tests/harness/run-test.mjs tests/referral_coins_test.php`

Uses a transactional SQL test double for cents validation, threshold boundary,
once-only award/reversal, rollback, legacy handling, storage-engine failure,
conversion reservations/replay and source-wiring checks. It is **not** a real
MySQL concurrency or end-to-end provider test. Before production, run concurrent
settlement/approval/refund requests against staging MySQL, verify emails/step-up,
review legacy holds, and audit actual provider evidence. Test $99.99 + $0.01,
duplicate references, cross-tenant requests, pending/failed transactions,
insufficient balance, double approvals and refunds after conversion.

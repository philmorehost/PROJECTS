# Webhook investigation journal (not settlement authority)

## Scope

This is the first evidence-recording layer for manual reconciliation, **not an automated reconciliation/settlement product**. It appends operator assertions against an inspected observation, without altering the payment, merchant configuration, immutable outbox, claim or delivery log. No sending/settlement path reads the journal. `recipient_reports_received` records a claim made by a recipient; it never means independently verified credit or authorization to lift a hold.

Missing evidence, uncertain HTTP, changed endpoint/key/payment or differing recipient records still require authoritative investigation and separately reviewed accounting resolution. Never delete/reset/reclaim a delivery claim, overwrite queued bytes, or use this journal to manufacture a historical webhook or wallet credit.

## Operator controls and migration

Apply `install/20261118_webhook_reviews.sql` outside application transactions after the log/retry/initial/outbox migration chain, with backup and normal schema review. It is additive/idempotent, has no cascade or backfill, and is not applied by the command. No live migration or enable flag is installed by this increment.

This is **CLI-only**, not an admin HTTP endpoint. Access is controlled by the local OS account and database grants. The actor reference is an assertion supplied by that operator, not independent proof of identity. Restrict the runner to individually attributable accounts; map opaque actor IDs to authenticated operators in a separate approved access register. Production multi-party authorization and tamper-evident external audit retention remain acceptance gates. Do not expose the CLI through a web wrapper or let tenants invoke it.

For recording, use SELECT privileges on required evidence tables and SELECT/INSERT on the journal only; explicitly withhold journal UPDATE/DELETE and schema privileges from the runtime operator account. Append-only is an application invariant, not resistance to a privileged database administrator. Back up/export the journal through existing restricted audit-retention controls. Correction means a **new** request/evidence record; never edit the old row. Do not place raw keys, customer data, URLs or free-text notes into arguments. Opaque references are constrained but cannot detect all personal data; operators must keep them non-identifying. Command-line values may appear in process listings/shell history: only opaque IDs and digests are permitted.

## Inspect, investigate, record

```sh
php PAYHUB/bin/review-webhook-outbox.php --transaction-id=123
```

Inspection returns a local ID, observation digest, claim state and log count; it rolls back/releases locks and makes no journal entry. It requires an existing payment/merchant/outbox; no historical event is fabricated. Bounded to 200 delivery logs per transaction; larger histories refuse review and require separately designed tooling. Enforce external database/query/execution budgets—row limits do not guarantee query time. Snapshot hashes cover current transaction values, selected-mode endpoint/key digests, stored/actual payload hashes, immutable binding digests, claim state/metadata and log body/response digests. Raw bodies, responses and secrets are never output. A digest is a concurrency check, not proof of evidence correctness or a signature of an external attestation.

Investigate with authoritative recipient/provider/account evidence through approved restricted processes. Retain that evidence separately under normal encryption/access/retention controls, hash its exact retained bytes locally with an approved tool, and use only its opaque case ID and SHA-256 digest here. The journal **cannot fetch or verify the referenced evidence**. No URL/network/file upload is accepted.

After review and separate authorization, recording uses explicit opt-in:

```sh
PAYHUB_WEBHOOK_REVIEW_ENABLED=1 php PAYHUB/bin/review-webhook-outbox.php \
  --transaction-id=123 --record \
  --request-id=review-unique-request-0001 \
  --actor-ref=operator-opaque-0001 \
  --evidence-ref=case-opaque-0001 \
  --evidence-sha256=REPLACE_WITH_64_LOWERCASE_HEX \
  --observation-sha256=REPLACE_WITH_INSPECTED_64_LOWERCASE_HEX \
  --finding=escalated
```

The placeholders are intentionally invalid. Valid findings: `recipient_reports_received`, `receipt_not_confirmed`, `escalated`. There is deliberately no `settled`, `resolved`, `retry` or balance-changing action.

Recording re-reads/locks evidence and compares the observation digest inside the journal insert transaction. Changed observations fail closed; inspect again and reassess evidence rather than blindly replacing the digest. A request ID is globally unique. Identical ID/fields returns `already_recorded` without making a new claim about current state; reused IDs with differing fields/transaction fail. Concurrent duplicate/deadlock/commit uncertainty may produce a generic failure: retry only the **identical** request and fields to determine whether it was committed, never invent a new ID merely to bypass uncertainty. The journal uses InnoDB and recording refuses nontransactional required tables.

Exit 0 means inspected/recorded/already-recorded as reported, NOT financially reconciled. Exit 2 means invalid/disabled request (before configuration/database access); exit 1 means unavailable/conflict/changed/uncertain outcome. Errors do not expose raw exceptions. No automated sender, scheduler, claim modification, recipient notification or ledger entry is attached.

## Multi-party reconciliation resolution layer (`reconcile-webhook-outbox.php`)

After investigation, `PAYHUB/install/20261118_webhook_reconciliations.sql`,
`PAYHUB/includes/webhook-reconcile.php`, and `PAYHUB/bin/reconcile-webhook-outbox.php`
provide an append-only dual-operator resolution layer (`PAYHUB_WEBHOOK_RECONCILE_ENABLED=1`
required for `--record`):
- Enforces distinct `preparer_ref !== approver_ref` operator assertions and stale-state refusal via `observation_sha256`.
- Matches exact `merchant_id`, `reference`, `currency`, fixed-point 2-decimal `amount`, and `account_number` against `transactions`; mismatches can only be recorded as `mismatch_held`.
- Never modifies `webhook_retry_claims`, `webhook_outbox.payload`, or account balances.

## Remaining reconciliation work

- Authenticated multi-party case assignment/approval, externally verifiable evidence ingress and retention, independent audit validation.
- Recipient/provider evidence matching with exact account, tenant, currency, amount and reference scope, including conflicting and unavailable evidence.
- Separately designed idempotent financial correction/settlement authorization and accounting invariants; this journal is never sufficient authorization.
- Real paired-stack/provider acceptance and independently verified historical obligations. Existing financial holds stay in place.

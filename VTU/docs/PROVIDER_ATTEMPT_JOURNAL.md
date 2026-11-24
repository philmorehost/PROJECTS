# Durable provider attempt/outcome journal — held foundation

This increment is an INTERNAL library and explicit SQL migration, not a worker, public API or provider activation. Existing request pages do not invoke it. It performs no HTTP calls, wallet debit, reservation, refund, fulfilment-status write or settlement. It does not establish merchant/product/currency eligibility or verify a webhook. No provider-backed service is declared complete.

## Contract

Apply `migrations/20261118_service_attempt_journal.sql` through an operator-managed migration outside application transactions. Both editions use the same library. Runtime functions contain no DDL and reject missing/non-InnoDB tables or existing caller transactions. No table engine conversion or historical evidence rewrite is automatic.

`bc_saj_hold(vendor, request_id)` locks the exact tenant request and registers ONE held attempt with a random durable provider key. Repeated/concurrent calls recover the same key. Canonical payload, provider, type, owner, tenant, request id and reference are fingerprinted from database rows; changed routing/details conflict rather than producing another attempt. The provider key is only a stable candidate key: whether a chosen provider supports idempotency, key scope and retention must still be verified before dispatch.

`bc_saj_observe(...)` accepts an internal observation whose caller MUST already have authenticated/verified the provider status/evidence. An evidence digest is a reference to evidence, NOT proof of authenticity. This library must never be exposed directly to untrusted webhook payloads or browser fields. Authentication, actual provider-reference/amount/currency binding, secured evidence retention and trusted ingress are still pending.

Observation keys are scoped to tenant/request and bind outcome, evidence digest, provider reference and expected version. The same receipt replays its ORIGINAL result; changed reuse conflicts. State/event persistence is atomic, row-locked and versioned. Unknown outcomes remain unknown; no resend transition exists. Provider references cannot be rebound and are unique per tenant/provider once attached. Conflicting terminal observations are retained with conflict disposition without rewriting a terminal state. Stale version observations are retained without changing state; an authorized reconciliation process must reread and assess rather than blindly resend. Event rows retain digests/opaque references, not raw identity/banking payloads or secrets. Conflicting reuse of the SAME event key is refused without replacing its original receipt.

## Financial hold

NEW attempts are ALWAYS `held`. No library operation releases held attempts or sets `in_flight`. Observations against held attempts are journaled but cannot activate them or mark them successful. The state reducer accepts pre-existing `in_flight`/`unknown` records solely as a future integration seam. Native tests explicitly seed such records in disposable databases; this is NOT an operator instruction to change production rows or permission to bypass activation controls. No dispatch-enabling configuration/UI/migration is included.

Terminal names represent journaled observations only. They do NOT change the intake request status or authorize wallet/ledger/settlement operations. Reconciliation for already-dispatched work must remain possible even when a customer later becomes restricted; future EXECUTION must independently check current user, tenant, compliance, capability and authorization before sending. This helper does not authenticate a calling operator, worker or merchant on its own.

## Evidence and remaining integration

Contracts cover canonical fingerprints, default holds, no resend and terminal preservation. Disposable native MySQL tests in both editions race independent processes, recover one key/receipt, isolate tenants, reject changed details, preserve unknown outcomes, retain stale/conflicting observations, inject an event-insert failure to prove state rollback and refuse outer/nontransactional storage. They use NO live provider calls and seed future execution states only as fixtures.

Still required before any live execution: approved merchant/product/currency eligibility evidence; provider-specific idempotency/status/webhook semantics; a reviewed authorization/dispatch boundary and lease/crash handling; verified inbound evidence; amount/currency/reference matching; secured raw evidence retention; operational reconciliation UI/jobs; account/financial-policy checks; settlement-ledger integration; failure/reversal policy and approved end-to-end testing. No deployment, activation or settlement is performed. All holds remain.

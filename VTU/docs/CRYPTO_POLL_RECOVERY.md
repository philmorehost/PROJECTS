# Explicit expired-worker-attempt recovery

The protected platform custody page now offers an explicit POST recovery action for an expired unknown worker attempt. It uses the existing session-derived fresh active Super Admin, CSRF, PIN, replay-protected TOTP and sensitive-action throttle. Internal role arrays are not authentication. No worker process or scheduler is enabled by this feature.

## Preconditions and effects

An operator must inspect a fresh protected report, then supply grant ID and expected unchanged stream revision. Under custody → stream → grant → lease locks, recovery requires:
- Selected current custody revision and exact account/environment match.
- Stream revision unchanged; grant revision matches it.
- Grant running or already revoked, with an exact bound lease-token fingerprint.
- Lease belongs to service actor zero at that revision, matches the grant fingerprint, and is expired but not already closed (positive expiry at or before current time).

Recovery fences a running grant by revoking it (preserving any prior revocation receipt), writes a unique human recovery receipt, closes that exact expired lease, appends an existing failed-attempt closure and captures retry escalation if appropriate. These writes are atomic. Any failure rolls all of them back. Unknown claim plus closure counts as one attempt by token fingerprint, not two. Closure time conservatively starts fresh retry backoff; it does not clear previous failures. The receipt explicitly labels the outcome `unknown_expired_attempt`: the existing failed lease-event vocabulary denotes unsuccessful local completion, NOT a definitive provider failure.

The stream row, cursor/ciphertext, checkpoint revision/page count, evidence, observed identities and financial records are untouched. Recovery does not renew a grant, retry, create a window, clear backoff/escalation, declare history complete or authorize settlement. A replacement grant still requires separate fresh step-up, eligibility, config, retry and lease checks.

## Lost response and race safety

A worker that already committed has advanced the stream revision and consumed its grant; recovery refuses both stale revision and consumed-grant cases. Do not convert a lost success response into a failed deposit. A stopped stream has changed revision; use current report/evidence rather than recovering an old form. A superseding lease with another fingerprint cannot be closed by recovery of an old grant. Unexpired leases refuse recovery; use existing explicit revoke/stop if immediate containment is needed.

A stalled old process may finish a remote read after expiry. Recovery cannot cancel HTTP already sent; grant revocation and existing lease/revision fencing prevent that attempt from advancing the checkpoint afterward. Shared locks serialize recovery with commit, revoke, claim and other recovery. Repeated/concurrent recovery cannot add multiple failure closures or overwrite the first human receipt.

Already cleanly released attempts are ineligible. Pending, never-claimed grants need ordinary revocation, not crash recovery. No automatic scanner, heartbeat, process-kill, inferred provider outcome, cursor reset, credential bypass or evidence deletion is introduced. Existing approvals/encryption keys/provider configuration are not needed to close an expired attempt; current authenticated scope and all transactional supporting schema are still required.

## Schema, visibility and validation

One additive InnoDB table, `sas_crypto_v2_poll_recoveries`, stores grant ID, expected stream revision, server-derived recovering human/time and fixed unknown-outcome label. The explicit CLI installer now includes **27 production tables**. No request performs DDL. The existing exact-report-hash audited grant report includes recovery receipt metadata; no credential/lease token or plaintext provider record is exposed. The report remains bounded to twenty grant entries with explicit truncation. Recovery notices contain no tokens/records and no grant secret is accepted from a form.

Management boundaries now include eight recovery override refusals (82 total checks). Disposable native tests cover unexpired lease refusal, scope/current-revision checks, receipt-write rollback, exact unchanged checkpoint, human provenance, late-worker prepare/commit refusal, duplicate and concurrent one-winner recovery, retained one-attempt backoff, superseded lease refusal, committed/lost-response refusal and unchanged financial objects. Existing worker/lease/overlap/audit tests remain required. Synthetic reader fixtures are not live provider/device verification.

No production migration, deployment, worker enablement, provider request, import/credit, route activation, balance/hold or withdrawal-policy change was performed. Remaining gates include process/secret/DB isolation, external authenticated alert delivery, staging crash drills and explicit window/completeness/reconciliation policy before unattended operation.

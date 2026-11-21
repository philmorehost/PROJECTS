# Bounded manual retry admission and escalation

Operator fetch now enforces retry admission inside the existing custody/stream/lease claim transaction, after current eligibility/config guards and busy-lease refusal, before token creation or provider access. The protected audited health report exposes the same decision using recent lease metadata. This is a safety gate on manual collection, NOT unattended execution authority.

## Policy

Lease history is read newest-first with current locking reads, capped at 101 events. A claimed token with no successful checkpoint/release counts as an unsuccessful/unknown attempt. Its failed-release event and claim are counted once by token hash. `failed` means local collection did not complete, not proof that the provider request failed. A normal successful checkpoint/released event ends the consecutive sequence. Incomplete history with no success is treated conservatively; it is never silently cleared by truncation.

- Zero consecutive unsuccessful attempts: only an explicit stepped-up manual attempt may proceed.
- One unsuccessful/unknown attempt: wait at least 60 seconds from its latest relevant event.
- Two: wait at least 120 seconds.
- Three or more: further **operator** claims are blocked pending investigation; merely waiting does not clear escalation.

There is no reset/override UI and no automatic retry. Stop remains available. A separately reviewed replacement window under fresh approvals must preserve old evidence and explicitly address overlap and unresolved coverage gaps; it must not be used to evade investigation. The existing explicit create control is not disabled by this per-stream policy; it does not make unresolved history safe. Successful old in-flight work can still commit under a valid token/revision, but expired/replaced/stopped attempts remain fenced by the existing protocol.

The policy is applied by the operator fetch wrapper with `bc_crypto_poll_claim(..., true)`. The low-level internal claim retains its previous default for existing protocol fixtures/internal callers; it is NOT a public boundary. No public route can select that flag. Do not wire a future worker directly to the unguarded internal claim: isolated authorization, mandatory admission, revocation and alerts must be separately reviewed. Role arrays alone do not authenticate workers.

## Monitoring and privacy

Report retry metadata includes consecutive unsuccessful count, not-before epoch, admission result, escalation signal and `automatic_retry_authorized=false`. Admission allowed never bypasses stream expiry, current approvals, vendor restrictions, configuration pinning, lease/revision fences, PIN/TOTP or throttle. A report is point-in-time and may race later activity; actual claim rechecks inside the transaction. Token hashes are read only internally and are never included in report/results/logs. Hashes are not provider identity or response authentication.

Escalation is operator-visible in the existing stepped-up report and sanitized refusal codes. There is no email/webhook/SMS dispatch, alert delivery guarantee, on-call acknowledgement, scheduler or process monitor. Those operational capabilities remain future work; no user mailbox or connected app is accessed.

## Validation and rollout

Pure contracts cover exact delay boundaries, unknown attempts, token grouping, persistent three-attempt escalation, successful checkpoint sequence termination, invalid history and mirrored source parity. Real InnoDB tests cover backoff refusal without a new claim, delayed retries, escalation after three attempts and stop availability. Existing audit/concurrency/fencing/financial-isolation suites remain required.

No new schema is added (24 production tables). No production deployment/migration, real provider call, unattended worker, window rollover, import/credit, active route, balance/hold or withdrawal policy change is made. Readiness still requires isolated worker identity/revocation, monitored alert transport, explicit overlap/completeness/reconciliation policies and independent operational/security review.

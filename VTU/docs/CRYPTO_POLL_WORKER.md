# Disabled-by-default single-attempt polling worker

## Scope and trust boundary

Both editions now include `func/crypto-poll-worker.php`, a CLI-only, one-shot worker. It attempts one existing fixed-window stream page under a fresh narrowly scoped grant. There is NO scheduler, daemon, sleep/retry loop, automatic grant renewal, new-window creation, rollover, import or settlement. Enabling/deploying it is a separate operator/security decision; no production migration/deployment or provider call was performed.

The worker uses its own service credential, NOT a Super Admin password, PIN, authenticator or ID. Server secret management must supply:
- `BC_CRYPTO_POLL_WORKER_NAME`: lowercase ASCII identifier, 3–32 characters (`[a-z][a-z0-9_]{2,31}`).
- `BC_CRYPTO_POLL_WORKER_KEY`: cryptographically random 256-bit secret represented as 64 lowercase hex characters. Provision out of band; never in chat, source, URLs, command arguments, form fields or logs.
- `BC_CRYPTO_POLL_WORKER_ENABLED=1`: required on the invoking process for authentication, claim, prepare and commit. Absent/other values disable execution. This is a local-process gate, not a remote kill switch; revoke a grant to fence another process.

The control-plane server and worker must have matching configured credential identity when granting/executing. Only a SHA-256 fingerprint is persisted in grants. There is no credential input/display UI. A grant ID is an identifier, not a bearer credential. Rotation invalidates authentication/new phases under the new configured key, but an already running old process retains its old environment; use database grant revocation for cross-process revocation. Random-secret fingerprint storage is not encryption or managed KMS.

CLI invocation is `php func/crypto-poll-worker.php <grant-id>` from the edition directory. Its only argument is the non-secret grant identifier. Output is stream ID/revision/state plus `settlement_enabled=false`; failure is generic with nonzero exit status. No raw exception, record, cursor, key or lease token is printed. HTTP execution returns 404 before configuration/database loading. The CLI loads the existing application DB/bootstrap and uses the existing authenticated Bybit read-only transport; it is not OS/container isolation or a reduced-privilege DB role. Production operation still needs an isolated service account/process/secret boundary and reviewed filesystem/database/network permissions. Anyone with application-code execution or database write access is outside this in-process authorization boundary.

## Short-lived human-authorized grants

Existing platform custody controls add explicit grant/revoke POST actions. They require current platform session, CSRF, fresh active/unblocked/password-complete Super Admin, PIN, replay-protected TOTP and sensitive-action throttle. Posted worker identity/key/state/query/cursor/evidence cannot select authority. Internal authority arrays are not authentication.

A grant pins one existing stream ID and expected revision, configured worker name/credential fingerprint, issuing human and creation time. Expiry is the earlier of five minutes or stream expiry. Creation repeats current custody revision/scope, query/config/vendor/policy/catalogue guards and retry admission. It cannot renew a stream, widen a query or bypass an unresolved backoff. Multiple pending grants are possible, but the existing stream lease and revision fences still permit only one effective claim/commit; grants are not a scheduling queue.

The explicit installer adds `sas_crypto_v2_poll_worker_grants` (26 production tables total). Supporting InnoDB schema is mandatory; request paths perform no DDL. The grant row is application-level issuance/revocation/completion provenance, not an off-host immutable audit or independent dual-control approval. The protected exact-hash-audited report returns at most twenty grant metadata rows with explicit truncation, excluding credential and lease hashes.

## Execution and revocation

A final, privately constructed, non-serializable service-authority object can be obtained only through CLI authentication. Worker role arrays cannot impersonate it. Service operations use audit actor **0**, explicitly reserved here for non-human polling; the linked stream/revision grant row and its worker identity supply provenance. No human issuer is recorded as the worker execution actor. Existing historical human IDs remain unchanged. Operational audit consumers must understand this sentinel rather than looking up a human with ID zero.

Claim locks custody → stream → grant, checks exact grant revision, credential, pending state and expiry, and atomically changes pending → running with the existing lease/audit. It pins the grant to the exact random lease-token hash. Service retry admission is mandatory even if an internal caller passes the old optional retry flag as false. A rejected claim rolls back the grant transition. Replay/concurrent invocation cannot consume a running grant again.

Prepare rechecks running grant/credential/configuration/expiry and exact bound lease before returning its audited server-decrypted query. Commit rechecks the same grant and consumes it at the end of the checkpoint transaction; evidence, seen/overlap identities, cursor/revision, audit, grant consumption and lease release succeed or roll back together. Current provider/config/custody guards and all existing row/body/window/page limits still apply. The service cannot create streams, stop streams, view protected evidence, acknowledge alerts, change custody or issue/revoke grants through these APIs.

Revoke locks the same custody/stream/grant scope and records first revoking human/time for pending/running grants. It remains available after stream stop/progress/config changes under the current selected custody revision. Revocation before prepare/commit fencing refuses disclosure/progress. It cannot cancel HTTP already sent or undo an already committed checkpoint; transaction lock ordering determines which operation wins. A grant that expires/is revoked during a read prevents checkpoint advancement. Stop still changes stream revision and fences the attempt.

## Crash and lost-response behavior

One grant authorizes at most one claim, not exactly-once remote delivery. Failure invokes existing audited failed-lease release and local escalation capture without advancing the cursor. Running grants remain running (not reusable) after failure/crash until expiry/revocation; running is not proof a process is still alive. No implicit restart/renewal is provided.

Before a replacement grant, inspect the protected stream revision, grant state, committed evidence/events, lease expiry, retry/backoff and conflict history. Lost response after successful commit leaves consumed grant and advanced stream revision; do not replay the old revision. Crash before commit leaves old checkpoint and a potentially held lease; wait for expiry or stop as appropriate. New issuance rechecks backoff/current approvals, and a new claim still refuses a live old lease. Do not clear history or mint repeated windows/grants to evade escalation.

## Validation and remaining gates

Pure tests cover disabled execution, credential format/fingerprint, invalid service role arrays, private/final authority construction, forbidden credential/query overrides and edition parity. Management boundaries total 74 checks. Native disposable tests cover service actor provenance, one-use claim/commit, exact lease binding, consumed grant replay, expiry, key change/disable, cross-stream refusal, revocation during an attempt, grant-write failure, atomic checkpoint/consumption rollback, report secrecy, independent concurrent one-winner claims and mandatory service backoff. Fixtures assert internal reader results and do NOT verify a real provider account or device/browser flow.

External alert delivery, autonomous crash reconciliation, scheduled execution, cross-host credential distribution/revocation operations, explicit overlapping-window lifecycle/completeness/reconciliation, customer allocation, risk/finality/amount semantics, balanced settlement, controlled pilot and independent operational/security review remain gates. No routes, journal/balances, holds, withdrawals or live-crypto enablement are changed.

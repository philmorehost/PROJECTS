# CryptoHub protected polling operator and lease fencing

## User-visible scope

The existing SAAS Super Admin custody page now offers explicit **create stream**, **fetch one page**, and **stop stream** controls. It lists at most twenty streams for the selected account/environment, exposing only stream/source IDs, pinned/current revisions, page count, ready/exhausted/stopped state, expiry and whether a lease is currently held. It never selects ciphertext, records, cursors or lease-token hashes for the list.

No scheduler, unattended cron, automatic retry/continuation, import or wallet credit is enabled. One fetch performs the existing identity → one bounded page → identity read, then atomic evidence/checkpoint commit. It retains the five-row, past whole-second window ≤24h, 100-page/stream-expiry, read-only/IP-bound credentials, TLS/no-redirect/time/body limits and strict record/status semantics. Normal page loads do not contact a provider. Existing single-page inspection remains separate and cannot advance these streams.

The new forms require the existing platform POST/CSRF/session, fresh active/unblocked/no-required-password-change actor, PIN, enrolled replay-protected TOTP and five-sensitive-attempts/300s throttle. Vendor and standalone mutation remain denied. Internal role arrays are not authentication; only the existing session-derived controller authority reaches the wrapper. Source vendor/custody/binding/policy/catalogue/config/scale guards remain active.

## Immutable scope and safe output

Create starts only at an empty cursor with the selected current custody revision. Fetch/stop must name a stream belonging to that exact selected account/environment and use its expected stream revision. Posted query, page, evidence, cursor or lease token are refused; fetch/stop also refuse source vendor, coin or time-window overrides. The server decrypts its own cursor and query. Posted actor/role cannot select authority. Stop can address an old pinned stream under a fresh current custody selection so custody/config/policy changes cannot trap an operator in an unstoppable job.

Successful results/redirects/session notices contain only existing account selection, stream ID/revision and optional encrypted evidence digest. Raw records and cursors remain in protected encrypted storage. Evidence viewing still requires separate stepped-up no-store audited access. No untrusted provider error or SQL text is reflected: the page retains its existing sanitized code/generic-error boundary. A lease token is never included in ordinary results, URLs, HTML, sessions, provider requests or logs.

## Lease protocol

Two additive InnoDB tables store per-stream lease state and append-only application-level claimed/released/failed events. Only a SHA-256 fingerprint of a cryptographically random 256-bit bearer token is stored. A lease binds stream revision and server-derived actor and expires after sixty seconds. This exceeds the existing normal maximum thirty-second inspection duration, but is not a guarantee against arbitrary process/OS pauses.

Claim runs under the existing custody → stream → vendor/policy/lease lock order, rechecks current guards and refuses an unexpired lease. The lease and claim audit commit together before any provider read. A failed claim audit returns no token and leaves no unaudited lease. The operator then supplies the token internally to audited prepare and checkpoint commit.

Prepare and commit verify token, actor, expected stream revision and expiry. Successful commit checks expiry again near final release; checkpoint, evidence, seen identities, poll audit, lease release and release audit commit in the same transaction. A release-audit failure rolls back all page progress. Once a stream has any lease row, legacy internal prepare/commit without a token is permanently refused, even after release/expiry; it cannot silently downgrade back to the unleased internal foundation path. There is no public unleased fetch path.

A failed provider attempt triggers a separate audited failed release, with no stream/evidence/seen progress. If release fails (for example due to audit/database outage or token replacement), the original failure remains and the old lease expires naturally. No exception payload is logged and no unsafe automatic retry is made. Retry requires another explicit stepped-up action and a fresh lease; it resumes the last committed cursor. An expired/replaced token cannot release a newer lease. Claim/commit crash recovery remains expected-revision based: inspect metadata after a lost response rather than replaying an old revision.

Leases prevent overlapping normal claims, not mathematically exactly-once remote delivery. After expiry, a stalled old process might still finish a read; revision/token fencing prevents it from overwriting the current checkpoint. Network requests occur outside DB transactions. There is no heartbeat/renewal, pooled worker scheduler or exactly-once provider-delivery claim.

An authorized stop bypasses a busy lease and changes the stream revision. An old in-flight page then cannot commit. Stop neither releases financial holds nor changes balances; it does not need provider access or evidence keys. Transactional supporting schema is still required. A stale stop form refuses rather than stopping a newer revision without the operator seeing it.

## Schema, validation and remaining gates

The explicit CLI schema preview/approved installer now contains **twenty-one** production tables: nineteen previous tables plus leases/lease events. Existing tables are not ALTERed or repaired on requests. Missing supporting schema fails closed and the operator panel gives a migration notice when its listing cannot load. Operator authorization, backup/restore and separate migration/deployment review remain mandatory. No production migration or deployment was performed.

54 pure operator/lease contracts exercise strict token/actor/revision/expiry fences, forbidden overrides, scope/wiring, edition parity, no funds/import calls and metadata-only UI. Management boundary contracts now total 45 and retain GET/CSRF/fresh session/PIN/TOTP/role refusal coverage. Disposable native MySQL cases exercise held lease exclusion, invalid/unleased/wrong-actor/expired/replaced tokens, audited retry release, stop during an in-flight attempt, independent one-winner claims/commits, claim/release-audit rollback, no token/cursor in results and unchanged observations/routes/journal/balances/holds. Actual provider reads remain covered by existing synthetic-cURL transport/identity/parser suites; no real Bybit account, browser-device flow or permissions were verified.

Ready/exhausted labels remain collection metadata, never proof of complete history, customer attribution, finality, risk clearance, custody/business eligibility or settlement. Existing live-crypto disablement, withdrawal stops and all holds remain unchanged. Next gates are isolated worker/scheduler authorization and monitoring if unattended collection is desired; explicit overlapping-window lifecycle and cross-stream identity recovery; provider-verified customer allocation/active routes; independent finality/risk/amount semantics, balanced settlement, reconciliation, controlled pilot and security/operations readiness.

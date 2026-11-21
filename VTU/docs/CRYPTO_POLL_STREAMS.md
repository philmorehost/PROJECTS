# CryptoHub durable bounded-window polling protocol

## What this increment supplies

Both editions now have internal `bc-crypto-poll.php` APIs and three additive InnoDB tables for resumable, bounded deposit-page evidence collection. A stream pins one custody account/environment/revision, source vendor/server-config fingerprint (including secret rotation), coin and explicit past whole-second window of at most 24 hours. It begins at an empty cursor; callers cannot seed/reset/reassign a cursor or widen the window. Pages still use the existing five-record authenticated inspection reader.

**The later protected manual Super Admin controls now use this protocol through expiring token-fenced leases (see `CRYPTO_POLL_OPERATOR.md`). No cron, scheduler or unattended worker entry point is enabled.** The spadmin authority array is an internal capability convention, NOT authentication. Never pass browser-supplied authority, query state or a caller-supplied provider snapshot to these APIs. Future integration must provide fresh platform authentication/step-up or an independently reviewed isolated worker boundary, throttling/leases/monitoring and safe error recovery. This increment does not authorize that deployment.

The supplied `bc_crypto_poll_fetch` orchestrator performs audited prepare → existing authenticated identity/page/identity reader → atomic commit. Network calls occur outside database transactions. Its lower-level commit API accepts only a trusted internal reader result; structural checks, encrypted state and hashes do not authenticate forged snapshots. There is no external callback/import endpoint and no automated settlement consumer.

## Durable state, encrypted evidence and atomicity

- `sas_crypto_v2_poll_streams`: immutable account/source/query context, current expected revision/page count, ready/exhausted/stopped label and encrypted state. Exact query, cursor, cursor-cycle hashes and pinned account-kind/parent metadata are encrypted with the existing evidence cipher/AAD/key mechanism. Ordinary metadata contains no address, transaction ID or cursor plaintext. Configuration hashes include server key/secret without storing their plaintext.
- `sas_crypto_v2_poll_events`: append-only application-level created/read_requested/page_committed/stopped audit. Read-request events do not increment the stream revision; they cannot authorize progress. They contain no raw records/cursors. Internal cursor decryption creates a stream audit, **never a human evidence-view receipt** for independent custody review.
- `sas_crypto_v2_poll_seen`: per-stream unique hashes for both provider record keys and reported transaction/index fingerprints. Cross-page duplicate IDs and alternative IDs for a previously reported transfer fail closed; they are not silently skipped or reassigned. These are not global cross-stream deposit-credit deduplication or proof of output/log identity.

The protocol rejects nested/autocommit-off callers. Supporting custody/evidence/poll/vendor/governance/catalogue tables must be InnoDB. Custody locks precede stream/vendor/policy locks; stream revision checks serialize commits. Fresh current vendor, independently reviewed unexpired custody/account binding, platform/vendor control and deposit-direction policies, catalogue freshness and exact accounting scale are revalidated before reads and again before progress. Config/custody changes refuse continuation; they do not rebind a stream.

Preparing a read commits an audit before returning the server-decrypted cursor. Only then does the fetch orchestrator perform provider requests. A failed read can leave an attempt audit, but no cursor advances. Concurrent attempts may both make the same bounded read; only one expected-revision commit can win. No lease, scheduler or claim of exactly-once network delivery is provided.

One commit transaction validates exact query/account/config/freshness and conservative normalized records, checks durable duplicate identities and longer cursor cycles, then writes all of:
1. Exact encrypted generated page evidence plus protected evidence-storage event.
2. Per-stream seen record/transfer hashes.
3. New encrypted cursor/cycle history, page count and stream revision.
4. The page-committed audit linked to its evidence digest.

Any storage/encryption/duplicate/audit failure rolls everything back. Oversized evidence is refused, never truncated. Stale concurrent/replayed commits refuse rather than increment twice. Crash before commit leaves no progress; crash after commit can be recovered by inspecting the current stream revision and committed event/evidence rather than replaying an old revision. Commit returns IDs/digest/state only, never records/cursor. Internal prepare returns sensitive server query/cursor only to the trusted orchestrator; it must never be returned to an ordinary page, redirect, session flash or log.

## Limits and terminal labels

A stream has at most 100 pages (500 parsed records); the final allowed page must have an empty next cursor. More advertised data refuses the commit instead of marking history complete. A stream expires at the earliest initial custody/binding, approval/catalogue expiry or one hour; it never auto-renews. Current guards are still rechecked on every attempt.

Every nonempty cursor is tracked as a hash in encrypted state. Longer loops (A → B → A), not only immediate repeats, refuse progress. A stream also pins the first successful reader's reported master/subaccount kind and fingerprinted parent for subsequent pages. Exact UID/config is separately rechecked by the reader and guards.

`exhausted` means only that this fixed-window query returned an empty next token. It does not prove complete account history, query-window membership, chain finality, eventual-consistency convergence or all pending deposits. Empty rows with a next cursor remain ready. There is no automatic next-window rollover, overlap/watermark policy, manual resume/reset or cross-stream deduplication. A future coordinator must bind complete cursor/query context, preserve overlapping-window identities and retained evidence, and explicitly handle limit/cycle/unsupported-network/unknown-status failures without skipping records.

`stop` is revisioned and audited, and bypasses stale custody/config/policy approval and unavailable encryption keys (supporting transactional tables still required). It never clears a seen identity, releases funds or restarts a terminal stream. Suspended/restricted/expired contexts cannot fetch or advance. Failed audit means no unaudited stop is claimed; missing guard infrastructure already prevents reads/progress.

## No financial authority

Durable collection does NOT activate routes, import deposit observations, credit accounts, mutate journals/balances/holds, settle deposits, lift withdrawal stops or approve custody/customer attribution. Provider success still has unknown/blocked risk from the existing parser. Evidence flags for completeness, allocation, import and settlement remain false; local checkpoint progress means page evidence was retained, not that deposits were credited or reconciled. Source status/reorg/funding-pool semantics are unchanged.

## Schema and validation

The explicit CLI schema preview/approved installer now includes **twenty-one** additive production tables: the existing sixteen plus streams/events/seen and two later lease tables. Request/intake performs no DDL. CREATE IF NOT EXISTS is not schema repair; migration/backup/restore/security review and separate operator authorization are still required. No production migration, deployment or live provider request was performed. Encryption/key recovery and off-host immutable audit obligations remain.

36 pure contracts cover immutable query/account binding, freshness, closed flags, re-parsed exact records/risk, cursor loops, limits, terminal states, account-kind drift, authority refusal and edition parity. Disposable native MySQL cases exercise encrypted resume, no human review receipt, exact revision conflicts, duplicate/alias refusal, audit-trigger rollback of checkpoint/evidence/seen identities, concurrent one-winner commits, configuration/vendor/global-stop/custody changes, expiry, key-outage stopping, nested-transaction refusal and unchanged observations/routes/journal/balances/holds. The authenticated reader's existing synthetic transport tests still apply. Native CI must pass before merge; fixtures assert internal-reader results, not real provider authentication.

Remaining: protected operator/isolated worker integration with authorization/leases/throttling and monitoring; explicit overlapping-window lifecycle/recovery and cross-stream alias handling; provider-verified customer allocation and audited active routes; independent risk/finality/amount semantics and balanced settlement; reconciliation, controlled tiny-value pilot and independent security/operations readiness. This is a source-level durable collection foundation, not a production CryptoHub launch.

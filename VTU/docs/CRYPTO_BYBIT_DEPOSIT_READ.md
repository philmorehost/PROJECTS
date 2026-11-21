# CryptoHub authenticated deposit-page inspection

## What is now connected

The existing platform-only custody management page adds **Inspect one deposit-record page**. It requests one page through signed GET `/v5/asset/deposit/query-record` between authenticated account identity checks, then stores generated evidence through the existing encrypted revision-pinned store. Source credentials remain server-configured, personal, read-only and exactly IP-bound. Main and subaccount keys inspect only their own UID's records; there is no parent/child substitution.

This is **manual bounded inspection**, not a scheduled poller, complete history, importer, customer allocation or settlement. No checkpoint/cursor progress is persisted. Deposit observations, accounting entries, customer routes/balances, existing holds and withdrawals are untouched. No calls occur on normal page load. Vendor/standalone custody-page POSTs remain denied; this is not a self-service vendor action.

## Request limits and authentication

The request inherits existing authenticated platform session, POST/CSRF, fresh active/unblocked/no-forced-password-change actor, mandatory enrolled replay-protected TOTP plus Security PIN and sensitive-action throttling. Source vendor status, custody environment and expected revision are checked. Evidence InnoDB/key/OpenSSL readiness and a successful sanitized request audit are required before provider reads.

The new fixed operation accepts only:
- Exact coin TRX or USDT; strict downstream parser accepts TRON records at scale 6 only. Mixed/unsupported networks refuse the whole page rather than skip rows.
- Explicit start/end UTC Unix millisecond **strings**, exactly 13 digits and whole-second boundaries ending 000. Start must precede end, end must not be in the future, and interval must be at most 24 hours. This is narrower than the provider's less-than-30-day maximum. Exact string subtraction avoids 13-digit native-integer casts, including on 32-bit PHP.
- Fixed page limit `5`, not a posted selectable larger limit. A provider response exceeding five rows is refused, never truncated.
- Optional bounded opaque cursor. Explicit null, control/space/non-ASCII characters or excessive length are refused. Opaque content is preserved and RFC3986-encoded as query data, never decoded or concatenated into a URL. No id/txID filter (which could override other query parameters) or browser-controlled endpoint/host/method is accepted.

One captured source config is used for identity → page → identity. UID, master/sub kind, parent fingerprint, exact key/read-only/IP binding and config fingerprint must remain consistent. Configuration changes, including secret rotation, fail closed. Every call retains fixed HTTPS origin, TLS peer/host verification, no redirects/retries, 3-second connect/8-second total timeout and 2 MiB response cap. Three reads maximum; total wall-clock inspection must be nonnegative and at most 30 seconds. First error stops the sequence. An explicit inspection can therefore wait for up to 24 seconds of network timeout budget; page loads have no new remote dependency.

The existing strict parser handles all rows/status/risk/fees/identities, page bounds and immediate cursor repeats. A malformed row, unknown status, unsupported network or duplicate fails the whole inspection. Provider success is not risk-clear or spendable. No source status can create an import/settlement authorization through this action.

## Cursor and history caveats

A user-supplied opaque cursor is bounded query data, **not a server-verified continuation chain**. Provider cursor semantics might influence returned rows; this action does not establish query-window membership, completeness, stable ordering or cross-page continuity. It explicitly records `query_window_membership_verified=false`, `cursor_chain_verified=false`, `history_complete=false` and `cursor_advance_authorized=false`. Empty rows/empty cursor are not proof that all historical or pending deposits were retrieved.

No loop follows nextPageCursor. Inspecting another page is a separate stepped-up action and does not advance a durable watermark. The future worker must bind cursors to immutable account/environment/config/query context, track longer loops, use overlaps for second-granularity provider windows, retain cross-page record/transfer identity, and atomically commit all evidence/outcomes before progress. Do not turn this inspection action into a polling cron.

## Protected evidence boundary

After successful reads, source vendor status and custody revision are checked again. Generated JSON includes account/environment fingerprints, source vendor/config fingerprint, account kind/fingerprinted parent, exact bounded request/opaque cursors, parsed page, actor, pinned registry revision and inspection/freshness times. It omits API key/secret/IPs, raw UID/parent, permission arrays, provider notes and unnecessary raw fields. The parser's row/page flags remain false: inspecting a TLS-authenticated envelope does not turn standalone normalized row data into independent provider proof or operating authority.

Exact records (including addresses/transaction IDs) and request/response cursors are stored only as encrypted evidence, not in redirects, flash/session messages, logs or ordinary page output. The ordinary response exposes only the evidence digest, existing non-address account selection and an inspection-only notice. Opening it requires the existing separate no-store, stepped-up audited evidence-view operation. Generated evidence exceeding the store's 16 KiB plaintext limit is refused, never truncated; storage also validates the final actor/revision-annotated document.

Expected custody revision is rechecked under the evidence writer's transaction lock. Encryption/store/audit failures cannot return successful evidence. Request-attempt audits can remain after a failed inspection; they are not success records. No schema addition, request-time DDL, deployment or production migration is introduced here. Stored evidence is only a point-in-time label, not an atomic provider snapshot, approved legal custody model, reserved customer allocation or continuing credential permission.

## Reference and validation

Official reference reviewed at revision `621656acf9096557c9ff352e5fe791d1e2cd5c85`:
https://github.com/bybit-exchange/docs/blob/621656acf9096557c9ff352e5fe791d1e2cd5c85/docs/v5/asset/deposit/deposit-record.mdx

It documents master/sub UID keys querying respective records, optional filters, id precedence, cursor pagination, limits 1–50, second-granularity start/end behavior and a window strictly less than 30 days. Successful signed reads are not live permission/custody eligibility verification for this implementation; no real account was queried during development.

80 synthetic-cURL contracts run the actual request builder/signer, bounded transport/decoder, before/after identity normalization and existing record parser. They cover exact URLs/query signatures, RFC3986 opaque cursors, past/whole-second/day-boundary/string-safe windows, fixed limits/forbidden filters, credential/environment isolation, own-subaccount reads, identity/read-only/IP/config changes, over-limit/malformed pages, errors/no retries, false progress/authority labels, secret omission, edition parity and protected evidence/UI wiring. Management boundary tests now total 36. Existing parser, identity/address/custody/evidence/route/deposit and real MySQL transaction/concurrency suites remain required before merge.

Remaining: approved custody/business capabilities; verified customer-specific allocation and controlled route activation; durable identity/query-bound polling streams, encrypted evidence/checkpoints and recovery; independent finality/risk/amount semantics and balanced settlement; reconciliation, controlled pilot and security/operations readiness. Live-crypto disablement, withdrawal stops and financial holds remain unchanged. No real provider requests, migration or deployment were performed.

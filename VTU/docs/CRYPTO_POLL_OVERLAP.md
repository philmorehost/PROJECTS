# Cross-stream polling identity sightings

This increment adds account/environment-scoped identity metadata to every successful page checkpoint. It supports manually overlapping fixed windows without silently discarding repeated or changed provider observations. It does not add automatic window creation/rollover, a scheduler, reconciliation completeness, deposit intake or financial authority.

## Semantics

Two additive InnoDB tables are installed only by the existing explicit CLI schema installer (now **23 production tables**):

- `sas_crypto_v2_poll_identities`: distinct custody account/environment/provider-record-hash/reported-transfer-fingerprint pairs. Scope deliberately excludes vendor credential ID, key rotation, stream and query window; these must not make an existing identity look new within the same custody account. Provider is implicitly Bybit, currently the only supported reader. No claim of global cross-account transfer ownership is made.
- `sas_crypto_v2_poll_sightings`: per-stream committed revision and record hash, transfer fingerprint, normalized snapshot hash, exact encrypted page evidence digest and classification. It contains no raw address, txid, status or amount. The encrypted evidence retains the exact page and query. No mutable latest status replaces prior observations.

Classification is conservative:

1. `conflict`: either this provider record has appeared with another transfer fingerprint, or this transfer fingerprint has appeared with another provider record. All competing pairs and sightings are retained, never merged or credited. Future sightings of an original pair remain conflict while competing associations exist. This is not a resolution mechanism.
2. `first_seen`: neither this pair nor a conflicting identity association exists in retained overlap metadata.
3. `repeat`: the pair exists and this exact normalized record snapshot has appeared before in this account/environment.
4. `changed`: the pair exists, but the normalized snapshot has not appeared before. Confirmation/status/risk/amount/address changes are retained, not trusted as corrections. Returning to a previously observed snapshot is repeat, not a claim that it is newer or authoritative.

Conflict classification intentionally does NOT abort a cross-stream page: doing so would prevent retaining rollback/status-change evidence and could trap an overlap window forever. Existing strict same-page and same-stream duplicate identity refusal is unchanged. Cross-stream sightings are evidence only, not a downstream approval or globally unique settlement key. No consumer may treat `first_seen`, repeat, changed or lack of conflict as risk clearance, ownership, finality or credit eligibility. The parser's false authority flags remain unchanged.

The complete normalized record (including confirmations and status) is hashed using the fixed parser-generated field ordering. Snapshot hashes are not hashes of provider response bytes, not signatures, not independently authenticated evidence, and not robust across a future normalization version change. Such a parser migration must explicitly version/migrate identity handling before making comparisons. Hashes can reveal equality and allow guesses of low-entropy provider IDs; protect database access as sensitive metadata.

## Atomicity and concurrency

Checkpoint commit already owns the custody account lock before the stream lock. This serializes identity classification across streams of the same physical account. New identity associations and sightings are inserted in the same transaction as encrypted evidence, local seen identities, cursor/revision, audit and optional lease release. Any failure rolls all progress back. Two independent overlapping streams can both retain their evidence, but only one can classify an identical newly seen pair as first_seen. Different accounts remain separate. All supporting tables are required to be InnoDB before reads/progress; no request performs DDL.

Metadata is internal only. The operator stream list continues to show metadata-only progress, not records or a conflict-review workflow. Protected evidence review remains separately stepped-up and audited. No new public endpoint or caller-supplied evidence path is introduced.

## Legacy coverage and recovery limitations

There is **no backfill** of pages committed before this schema/code was installed. First_seen means first in this retained overlap index, not first on chain or first ever in the application. Old stream rows/evidence/seen identities are not rewritten. A deliberate separately reviewed offline recovery/backfill would be needed to incorporate old encrypted evidence. Do not authorize settlement based on this index.

Windows remain manually specified and immutable. There is no automatic rollover, completeness watermark, guarantee that a provider returned every record in a window, unknown-response retry scheduler, or time-order guarantee for snapshots. Empty cursors still mean only local query exhaustion. An automatic lifecycle/reconciliation policy and operational conflict-resolution UI remain separate gates, along with customer allocation, finality/risk/amount semantics, balanced settlement, controlled pilot and security/operations review.

## Validation and deployment

Ten pure classification/parity cases cover the complete boolean classification matrix. Native disposable MySQL cases cover repeated overlapping windows, confirmation changes, both identity conflict directions, sticky conflicts, evidence linkage, full identity/evidence/checkpoint rollback on sighting failure and concurrent overlapping first/repeat commits. Existing lease/stream/identity/parser and financial isolation tests remain required. Fixtures are internal synthetic reader assertions, not live provider verification.

No production migration, deployment, provider request, active-route change, deposit import, balance/hold mutation or withdrawal policy change is authorized or performed by this increment.

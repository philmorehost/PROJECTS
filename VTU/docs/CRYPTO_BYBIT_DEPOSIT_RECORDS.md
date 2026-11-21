# CryptoHub offline Bybit on-chain deposit-record validation

## Increment and limits

`func/bc-crypto-bybit-deposit-records.php` is a pure decoded-result parser in both editions. The separate protected inspection reader now calls this parser; see `CRYPTO_BYBIT_DEPOSIT_READ.md`. The parser itself has no I/O and is **not a scheduler, checkpoint store or observation importer**. It adds no provider operation to the HTTP allowlist, no schema, no customer funding display and no account/balance/hold/withdrawal changes. No provider calls are made by this parser or its tests.

This is preparation for authenticated polling, not a live poller. All output states provider authentication, customer attribution, chain finality, amount semantics and settlement are unverified/disabled. Page output additionally sets `cursor_advance_authorized=false` and `import_enabled=false`. There is no risk-clear output. Even provider success, high confirmations and approved travel/tax fields leave local risk unknown, so those flags cannot open the existing deposit intake's review-ready state.

## Narrow accepted contract

- Only exact `USDT:TRX` / `TRX:TRX` and integer accounting scale 6. This is a narrow initial adapter constraint, not approval of a token contract or customer route. Other networks, mixed-network pages and different accounting scales fail closed; a future multi-network dispatcher must be separately reviewed rather than skipping unsupported rows.
- Provider internal `id` must be a canonical positive decimal string up to 64 digits. Output key is `bybit:onchain:v1:<id>`. Missing IDs never fall back to transaction hash, requestId, array position, address or a generated random ID. Real identifier encodings outside this strict contract require explicit adapter revision.
- Exact lower-case 64-hex TRON txID and canonical decimal-string txIndex are retained separately. A reported transfer fingerprint binds coin/network, transaction and index. Duplicate provider IDs or duplicate reported transfer tuples within one page are refused, including aliases with different IDs. This is not proof of on-chain output/log identity or a durable cross-page uniqueness guarantee; future authenticated import must verify semantics, retain both identities and prevent aliases/replays across pages/accounts/environments.
- Target address must pass the existing TRON Base58Check/version validator. Explicit string empty tag is required. No trimming, case-folding, dropping a nonempty tag or network aliasing is allowed.
- Amounts are canonical positive decimal strings converted to exact integer units without floats. Excess fractional precision, exponent notation, negative/zero amounts and malformed types are refused. This deliberately rejects encodings beyond the initial contract, including excessive trailing fractional digits. It does not silently round.
- depositFee must be an explicit string. Empty means **unknown** (`fee_units=null`), not zero. Known fees are parsed separately with exact units. Nothing subtracts/adds fees or claims the provider amount is gross/net or ready for liability recognition.
- Confirmations remain bounded canonical decimal strings; they are not chain finality proof. successAt remains a decimal-string millisecond value, empty or zero only for non-success records. Status 3 requires a nonempty/nonzero 13-digit success timestamp, but the parser does not authenticate a clock, infer query-window membership or checkpoint from successAt.
- depositType must be one of string `0`, `10`, `20`, `50`. Travel/tax fields may be absent (reported as unknown/null), but explicit malformed or unknown enum values fail closed. Travel Rule rejection or depositType 20/50 sets risk blocked. Other values, including approved travel or tax reporting, never set risk clear. Daily-limit type 10 remains explicit metadata needing operational review.

Unneeded fromAddress, provider notes, requestId, truncated/irrelevant asset metadata and arbitrary extra fields are not returned. Returned addresses/transaction IDs remain sensitive internal data: a future adapter must use protected evidence storage and never put records into ordinary UI, redirects, logs or browser storage.

## Conservative status mapping

Input status must be a JSON/PHP integer, not numeric string, float or boolean. Unknown statuses refuse the whole page, never silently become success or definitive failure.

| Provider status | Parser status | Interpretation |
| --- | --- | --- |
| 0 | pending | Unknown provider state |
| 1 | pending | Awaiting confirmation |
| 2 | pending | Provider processing |
| 3 | complete | Provider-reported success **only**; risk still unknown, no settlement |
| 4 | failed | Provider-reported failure; no refunds or hold release |
| 7 | reorged | Rollback processing |
| 70011 | reorged | Provider reports rollback/reversal |
| 70012 | reorged | Provider reports success retained after rollback review; manual review retained locally |
| 70013 | reorged | Automatic rollback failed; manual review |
| 10011 | pending | Funding-pool credit pending |
| 10012 | pending | Funding-pool credited, not customer settlement |

`reorged` is deliberately conservative for every rollback-related code, including 70012. It is not an instruction to debit/refund/release funds. Later independently reviewed chain/provider/accounting workflows must resolve such cases with immutable evidence; no automatic unquarantine is introduced.

## Page and cursor handling

The input is a decoded `result`, NOT an HTTP envelope or authenticated assertion. rows must be a zero-based list of 0–50 records. Every row must validate before any page is returned. A malformed/unknown/duplicate row cannot be skipped while returning a successful next cursor.

nextPageCursor must be an explicit bounded printable ASCII string, including explicit empty when finished. Tokens are opaque and preserved exactly, including `+`, `/`, `=`; no decoding, rebuilding or trimming. An immediate repeat of a nonempty requested cursor fails. An empty row list with a nonempty next cursor still means another page is advertised. The parser does not detect longer cursor cycles or authorize progress: a future worker must track complete cursor history/bounds, durably commit evidence and all outcomes before checkpoint advancement, and implement safe overlap/recovery for second-granularity provider windows. No end-of-stream or watermark is inferred from row count, status or successAt.

## Official sources and tests

Official sources inspected at revision `621656acf9096557c9ff352e5fe791d1e2cd5c85`:
- https://github.com/bybit-exchange/docs/blob/621656acf9096557c9ff352e5fe791d1e2cd5c85/docs/v5/asset/deposit/deposit-record.mdx
- https://github.com/bybit-exchange/docs/blob/621656acf9096557c9ff352e5fe791d1e2cd5c85/docs/v5/enum.mdx (depositStatus)

The record reference documents internal id as unique/filterable, main/sub UID keys querying their respective records, 1–50 page limit, cursor pagination, second-granularity query logic and a window strictly less than 30 days. These API details do not prove provider permissions, custody eligibility, customer allocation or complete/eventually consistent history. The parser itself calls/allows no endpoint; the later inspection action separately allowlists a bounded deposit-record read (see companion document).

111 offline contracts cover all listed statuses, strict types, blocked-vs-unknown risk, missing fields, exact huge amounts, fee ambiguity, immutable record/transfer identity, TRON/memo validation, malformed pages, duplicates/cursor loops, empty pages, no risk-clear route to review-ready, raw-field omission, edition parity and absence of transport/import/database/funds calls. Existing deposit state-machine and native MySQL custody/evidence/concurrency suites remain required before merge. Fixtures are synthetic and no real account/deposit was queried.

Remaining gates: approved custody/business model, provider-verified customer-specific allocation and chain/token semantics; controlled active-route provisioning; fresh authenticated identity-bound record retrieval, bounded time windows, durable encrypted evidence/checkpoints and complete error/recovery handling; separate finality/risk/accounting settlement and reconciliation; controlled pilot and independent security/operations readiness. Live crypto/withdrawal disablement and all financial holds remain unchanged. No deployment or production migration was performed.

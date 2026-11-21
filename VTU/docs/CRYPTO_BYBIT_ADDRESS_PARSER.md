# Offline Bybit deposit-address parser foundation

## Deliberately narrow scope

`bc-crypto-bybit-address.php` remains a pure library in both editions. The separate authenticated master-inventory reader now uses it through protected custody management; see `CRYPTO_BYBIT_ADDRESS_READ.md`. The parser itself performs no I/O. It adds no HTTP endpoint or transport operation, provider requests, schema, address display, custody approval, route activation, polling, wallet credit or funds movement. Existing candidates still accept unverified exact manual claims; this parser does not retroactively validate them or confer provider proof.

The result parser accepts only a decoded `result` object supplied by a future trusted adapter. It does NOT authenticate a provider response or inspect HTTP status, retCode, signatures, timestamps, credentials, environment or UID. Passing attacker-created data can produce a syntactically accepted result. All output explicitly marks provider authentication, custody eligibility, customer attribution and live activation false. Never persist/consume its return as authorization or display its address as a funding destination.

## Supported syntax

Only exact `USDT:TRX` and `TRX:TRX` are supported. Other networks fail closed until their own reviewed adapters exist. It selects the exact `chains[].chain` identifier, not `chainType` (a human-readable label). Coin must match exactly; no aliasing or case folding. Arrays must be bounded lists of 1–64 rows with unique well-formed chain IDs; duplicate matches and missing chains are refused.

The selected address must be a 34-character TRON Base58Check string with version byte 0x41, 20-byte payload and correct double-SHA256 four-byte checksum. Bounded byte arithmetic needs neither GMP nor integer-wide decoding. Exact input is preserved. Explicit string empty `tagDeposit` is required; missing/null/numeric/zero/nonempty tags are not silently discarded. No address or provider payload is included in error messages.

Checksum/version validation proves only syntax, not account ownership, mainnet/testnet identity, token contract, custody eligibility, allocation/exclusivity, key control, deposit readiness or customer attribution. The provider's truncated contractAddress and batchReleaseLimit are not used as asset proof or deposit limits. Neither display labels nor unselected addresses are normalized as trusted destinations.

## Reference and validation

Official Bybit documentation reviewed via GitHub at revision `621656acf9096557c9ff352e5fe791d1e2cd5c85`:
https://github.com/bybit-exchange/docs/blob/621656acf9096557c9ff352e5fe791d1e2cd5c85/docs/v5/asset/deposit/master-deposit-addr.mdx

The reference describes GET `/v5/asset/deposit/query-address` as returning the **master** account's address. This does not establish per-customer subaccount allocation. It distinguishes request `chainType` (the coin-info chain ID) from response `chainType` display label and response `chain` ID. No endpoint is newly allowlisted or called by this change. A shared master address without unique attribution cannot safely become many customer routes.

42 offline contracts cover checksum/version vectors, whitespace/case/invalid alphabet, explicit empty memo, strict result shape and list bounds, duplicate chains, exact coin/network, display-label confusion, unsupported chains, false authority flags, edition parity and no transport/database calls. CI runs without cURL via `php -n` plus tokenizer; the existing native MySQL and security suites remain required before merge. Local WASM also runs these contracts. Fixtures are syntax-only and must never be funded.

## Next gates

Before using parsed master inventory for any funding or allocation: verify approved custody/business/account model and operation capability; implement bounded authenticated read transport and fresh UID/config/revision binding; protect and audit exact provider evidence without plaintext log/UI leakage; independently review customer-specific allocation and chain/token identity. Active provisioner must lock/recheck both candidate and route stores, current stops, KYC, custody and policies. Authenticated checkpointed polling, finality, balanced settlement, reconciliation and controlled pilot remain separate reviewed stages. Do not lift live-crypto disablement, withdrawal stops or financial holds to test this library. No deployment or production migration is authorized/performed here.

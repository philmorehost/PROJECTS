# CryptoHub: authenticated master address inventory evidence

## Scope, not activation

Super Admin custody management now has an explicit **Inspect master deposit-address inventory** POST action. It performs read-only Bybit queries using the selected source vendor's existing server-configured personal credentials. Ordinary page loads, vendor/standalone management and customer pages make no new provider calls. Supported syntax is deliberately limited to exact `USDT:TRX` and `TRX:TRX` with an explicit empty tag.

This produces **master-account inventory evidence only**. A returned address is NOT a customer allocation, verified ownership, business/custody eligibility, approved token contract, proof of reserves, write capability or active funding route. Do not send funds to inspected inventory. No candidate is auto-proposed/reviewed, no active route is written/displayed, and observation intake does not consume this evidence. Polling, journal/balance/hold changes, settlement and withdrawals are untouched.

## Authenticated read sequence

The action inherits existing platform-only POST/CSRF, session-bound active actor, completed required password change, mandatory enrolled replay-protected TOTP plus PIN, and rate limits. Source vendor must exist and be active/unblocked. The selected custody account/environment/revision must already exist and match. Evidence InnoDB tables and the active encryption key are preflighted, and a sanitized request audit must succeed **before** any provider call.

`bc-crypto-bybit-address-read.php` captures one source configuration and:

1. Calls existing signed GET `/v5/user/query-api`, verifying readOnly integer 1, exact expected IP set, personal key/type, exact API key, canonical UID/parent semantics and the selected registry fingerprint. Refuses subaccounts before querying the master address endpoint.
2. Calls signed GET `/v5/asset/deposit/query-address?chainType=TRX&coin=USDT` (or TRX coin). Transport requires exactly the supported coin/chain parameters; the path, method and HTTPS origin cannot be posted or overridden.
3. Validates the exact coin/chain, explicit empty memo, bounded/unique response rows and TRON Base58Check/version through the existing pure parser.
4. Requeries key identity and compares it to the initial snapshot (apart from inspection timestamps). A changed UID/account kind/read-only/IP binding fails closed. Re-reads the server configuration and refuses changes, including secret rotation. Total wall-clock read duration must be nonnegative and at most 30 seconds.

Each call retains TLS peer/host verification, fixed mainnet/testnet origins, no redirects/retries, 3-second connect/8-second total timeout and 2 MiB body cap. At most three requests are made (24 seconds of network timeout budget); failures stop immediately. There is no new normal-page startup delay or automatic background request. Successful TLS/provider envelopes and the surrounding identity checks are a point-in-time authenticated read, not a provider-signed ownership attestation or atomic server snapshot. Credentials might be revoked after inspection; future consumers must not treat stored evidence as continuing authorization.

## Evidence and privacy

After the reads, the source vendor and custody revision are rechecked. The generated evidence includes exact address/empty tag, environment, account fingerprint, coin/network, validation version, source vendor/config fingerprint, before/after identity label, actor, pinned registry revision and bounded freshness times. Raw UID, API keys/secrets/IP bindings, permission arrays, arbitrary provider notes, display labels, truncated contractAddress and batchReleaseLimit are omitted. No token contract or operational limit is inferred from those omitted fields.

The exact JSON is stored through the existing encrypted evidence store with transactional storage audit and an in-transaction expected-revision check. A concurrent custody mutation refuses the write. Audit/encryption/storage errors cannot return successful evidence. On failure, a sanitized request audit may remain, showing that inspection was attempted; it is not a successful inspection record. No new schema is required.

The ordinary page result/redirect/session contains only the evidence digest, selected non-address account metadata and a warning that this is not allocation. Opening the address requires the existing separate stepped-up, no-store, audited evidence-view action. The evidence view receipt and local custody review are not funding permission. App encryption/key-recovery, off-host audit and privileged database/browser risks remain as previously documented.

## Official reference

Reviewed official Bybit documentation at revision `621656acf9096557c9ff352e5fe791d1e2cd5c85`:
https://github.com/bybit-exchange/docs/blob/621656acf9096557c9ff352e5fe791d1e2cd5c85/docs/v5/asset/deposit/master-deposit-addr.mdx

It explicitly describes the endpoint as MASTER account address information. Request chainType uses the coin-info chain identifier; response chainType is a display label while response chain is the identifier. This change does not add or assume subaccount/customer address allocation or permit write-enabled keys when the read-only endpoint rejects them. Permission/eligibility or unsupported-account errors must be resolved separately, never by broadening credentials or bypassing identity checks.

## Validation and next gates

67 synthetic cURL contracts run the actual request builder, signer, bounded transport, decoder, identity normalizer and address parser without any network. They cover ordered identity/address/identity reads, master-only refusal, exact scope, canonical signed query, credentials/environment isolation, read-only/IP/UID changes, malformed addresses/tags/envelopes, transport/rate errors, no retries, config rotation, secret omission, false eligibility/ownership/allocation/live flags, edition parity and revision-pinned evidence wiring. Management boundary contracts additionally refuse stale revisions, blocked vendors and unsupported scope before provider/storage work. Existing encrypted evidence/native MySQL transactional, rollback and competing-connection suites remain required before merge. Synthetic tests are not live Bybit permission, custody or address ownership verification.

Remaining: approved legal/business custody model and operation-specific capabilities; customer-specific provider allocation/attribution and complete chain/token validation; independently reviewed, audited active-route provisioner checking both candidate and route stores; authenticated paginated/checkpointed deposit record polling; finality/risk revalidation and balanced settlement; reconciliation, tiny-value controlled pilot and independent security/operations readiness. No deployment, production migration, live provider calls, funding address activation, withdrawal-stop changes or movement of funds was performed here.

# Staging alert-receiver reference and failure drills

This directory is **not a deployed service or production HTTP endpoint**. It contains a standalone receiver verifier/durable inbox and a disabled-by-default CLI stdin adapter. It does not load application configuration, hold provider credentials, acknowledge operator alerts or perform financial actions. The production installer remains at **29 tables**; the two `staging_alert_*` tables belong in a separate disposable/staging receiver database, not the application database. CI uses them only inside its disposable database after production-schema assertions.

## Receiver contract

`receiver.php` implements the sender contract in `VTU/docs/CRYPTO_POLL_DELIVERY.md`:
- Exact request-byte HMAC with a separately provisioned random 256-bit hex key (literal hex ASCII as HMAC key).
- Matching 32-hex idempotency/delivery ID, strict timestamp string and ±120-second replay window inclusive. System clocks must be synchronized.
- Maximum 1024-byte canonical compact JSON body, exactly six supported fields, version/kind/type/range checks. Duplicate-key/noncanonical JSON refuses; current production sender output is compatible.
- Validation/signature verification on **every retry**, even if an ID already exists. A duplicate does not bypass authentication or replay limits. A delayed legitimate retry needs a fresh signed header timestamp, as the sender already supplies.
- Durable inbox keyed by delivery ID, pinned exact payload hash and signing-key fingerprint. Same ID/different bytes or key refuses without replacing the original. Key rotation requires an explicitly reviewed migration/retention plan; no implicit reset/rebind.
- Unique acceptance event stored atomically with inbox. No signed receipt is returned before commit. Duplicates return the same signed receipt after confirming durable acceptance. No raw payload, stream metadata, key or signature is stored; hashes are sensitive operational metadata.

Durable acceptance means the receiver committed an inbox receipt. It is **not** email/SMS delivery, forwarding to an on-call person, evidence that a human investigated, settlement authority or remote operator acknowledgement. No forwarding, inbox UI, alert-resolution workflow or receiver cleanup policy is implemented.

## Local CLI adapter

Preview standalone schema without a DB connection:

```
php VTU/staging/crypto-alert-receiver/receiver-cli.php schema
```

A separately authorized operator must review and apply that additive SQL in an isolated staging DB with backup/retention ownership. There is no automatic install or application migration hook. Do not apply it to a production application DB as part of these drills.

To accept a signed packet through stdin, separately provision environment variables through a secret manager, not command arguments, chat, tickets or committed files:
- `BC_ALERT_RECEIVER_STAGING_ENABLED=1` (otherwise refusal).
- `BC_ALERT_RECEIVER_KEY` (matching sender test key, 64 lowercase hex).
- `BC_ALERT_RECEIVER_DB_HOST`, `BC_ALERT_RECEIVER_DB_NAME`, `BC_ALERT_RECEIVER_DB_USER`, `BC_ALERT_RECEIVER_DB_PASSWORD` (isolated receiver-only credentials).

```
php VTU/staging/crypto-alert-receiver/receiver-cli.php accept < protected-synthetic-packet.json
```

The packet object has exactly `body` (the original JSON as a string), `timestamp` (string), `idempotency_key` and `signature`. Packet input is capped at 4096 bytes. Authentication precedes DB connection. Successful stdout contains only the signed acceptance receipt. Failures emit a generic message/nonzero status; HTTP invocation exits 404 before config/database access. Stdin files must be synthetic, access-restricted, outside source control and removed according to staging policy. There is no production sender override to send to localhost/private IPs.

The library receives a separate mysqli connection, requires autocommit/no caller transaction and both tables InnoDB, and performs no DDL on acceptance. It requires MySQL 8-compatible transaction introspection. A real deployment must configure encrypted DB transport/host verification or an approved private local socket arrangement, least-privilege users, connection/query/resource bounds and fail-closed exception handling. The stdin adapter is a **reference**, not an isolated/containerized production daemon.

## CI drills (no network)

`crypto_poll_receiver_test.php` covers signature/schema validation, exact replay boundaries, incorrect signatures/IDs, unknown fields/types/versions and duplicate JSON keys. `crypto_poll_receiver_mysql_cases.php` runs inside the existing disposable MySQL runner for both editions and exercises:
1. Real sender claim → receiver signature validation → durable acceptance → production sender receipt verification.
2. Drop the receipt after receiver commit: sender remains sending/unknown. Expire sender lease in the disposable fixture and retry the same delivery ID/payload with a fresh timestamp. Receiver returns the same receipt and stores exactly one inbox/event; sender confirms the new attempt and refuses stale completion.
3. Signed same-ID/different-content conflict and stale-timestamp refusal.
4. Inject receiver acceptance-audit failure before commit: no receipt/no partial inbox. Remove injected failure and accept retry safely.
5. Independent concurrent receiver processes: identical receipts and one acceptance per delivery.
6. Nested transaction refusal and unchanged application financial objects.

These are in-process/protocol plus real transactional database drills. Deliberately withholding a return or injecting a transaction error is **not an actual OS process kill, network outage, TLS or remote staging test**. Production sender DNS/IP/TLS restrictions are not relaxed for CI.

## Required real-staging checklist before any enablement

No real endpoint or infrastructure was supplied/configured in this task. An operator must choose and approve an isolated staging host, authenticated receiver adapter and ownership. Keep production flags off while doing so.

- Dedicated OS/container identity, filesystem permissions, secret distribution/rotation/revocation and least-privilege receiver DB; no shared production financial DB or provider signing credentials.
- HTTPS reverse-proxy/adapter that preserves exact request bytes, extracts exactly one value for each required signature/timestamp/idempotency header, rejects ambiguous duplicate headers, enforces POST/content type/body limits before buffering, rate/concurrency limits, no redirects, and generic error responses. Validate before database access. Do not expose this PHP directory directly as an assumed working endpoint.
- Public IPv4 host/egress approval, valid certificate/hostname, time synchronization, bounded resolver/DB behavior and log redaction. The sender rejects private/loopback destinations, proxies and unsafe URLs.
- Verify sender→HTTPS adapter→inbox→signed response with synthetic alerts and a staging-only key. Check request tampering, invalid signatures, stale headers, wrong receipt key, redirects, oversized response and TLS failures.
- Kill the receiver before commit, then after commit/before response. Kill the sender after claim and after receiver receipt/before sender confirmation. Verify no false confirmation, no duplicate inbox acceptance, bounded attempt budget, stable ID and accountable unknown-outcome investigation.
- Test destination/key rotation refusal, exhausted retry budget, database outage/restore, disk/quota failure and concurrent senders. Preserve deduplication records for at least the complete retry/incident horizon; never purge an ID just to bypass escalation.
- Assign on-call ownership and independently verify forwarding/escalation and human response. Receiver acceptance alone does not satisfy that gate.

Record actual staging observations and deployment approvals separately. No production migration/deployment, network endpoint, secret provisioning, worker scheduling, credits, balances, holds or withdrawal controls are changed by this reference implementation.

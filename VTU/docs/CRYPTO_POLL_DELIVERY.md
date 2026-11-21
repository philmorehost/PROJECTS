# Disabled one-shot authenticated local-alert delivery

Both editions add CLI-only `func/crypto-poll-alert-deliver.php <stream-id> <alert-revision>`. It delivers one already-persisted local retry-escalation alert; it does not discover alerts, schedule retries or run a polling worker. No endpoint is supplied by HTTP/arguments. It is disabled unless `BC_CRYPTO_POLL_ALERT_DELIVERY_ENABLED=1`. No production endpoint was contacted, migration/deployment performed or process enabled.

## Out-of-band configuration and receiver contract

Secret-managed server environment supplies `BC_CRYPTO_POLL_ALERT_URL` and `BC_CRYPTO_POLL_ALERT_KEY`. The URL must be lowercase-host HTTPS with an explicit simple path, no userinfo, query, fragment, port override or literal IP. The key must be random 256-bit entropy encoded as 64 lowercase hex characters; the HMAC uses these literal ASCII hex characters. Never put it in chat, source, argv, forms or logs. Configuration is local operational authority, not a remote API. The dispatcher pins URL+key fingerprint on first claim; changing either refuses further attempts on that alert rather than silently rerouting/resetting its delivery budget. There is no reset/rebind UI. Rotation/recovery needs a separately reviewed migration/operational plan.

The exact JSON request is stable across retries and contains only `version:1`, random stable `delivery_id`, stream ID, alert revision, kind and original alert creation epoch. It has no custody fingerprint, raw provider records, addresses, amounts, credentials, cursor, exception or operator identity. Stream/alert IDs are nevertheless sensitive operational metadata.

Headers:
- `Content-Type: application/json`
- `Idempotency-Key: <delivery_id>`
- `X-Crypto-Timestamp: <current Unix seconds>`
- `X-Crypto-Signature: <lowercase HMAC-SHA256(timestamp + newline + delivery_id + newline + exact request bytes, key)>`

The receiver must verify the signature in constant time, enforce a short timestamp tolerance/replay policy, authenticate this sender key separately from unrelated integrations, and deduplicate durable acceptance by delivery ID. Same ID with different content must be rejected. Repeated identical deliveries must return the same valid receipt. Receivers should store/forward only the necessary minimal metadata and apply their own access/retention controls.

Confirmation requires HTTP 2xx and a JSON object with exactly these three fields:
`delivery_id` (matching ID), `accepted` (boolean true), `signature` (lowercase HMAC-SHA256 of `receipt` + newline + delivery_id + newline + lowercase SHA256 of exact request bytes, with the same key).

An unsigned 2xx, mismatched payload/ID/key, redirect, timeout, transport failure or oversized body is **unconfirmed**, not proof of nondelivery. Signed acceptance means only that the key holder accepted this alert, not that an on-call human saw it. Acknowledgement remains a separate PIN/TOTP action; delivery never sets or clears it. No receiver implementation, Gmail access, email/SMS transport or actual end-to-end receiver verification is included.

## Network controls

Before sending, all returned A records must be validated public IPv4; private, loopback, link-local, CGNAT, documentation, benchmark, multicast, unspecified/reserved ranges and IPv6 literals are refused. One validated IP is pinned with cURL RESOLVE while retaining hostname TLS verification. Proxies and redirects are disabled, only HTTPS allowed, TLS >=1.2 required, certificate/hostname verification enabled, connect timeout 3s/total 8s, response capped at 4096 bytes. No response/error body or URL/key is persisted or printed. DNS resolution itself uses the system resolver and is not bounded by cURL's timeout; OS resolver limits and outbound firewall/dedicated process isolation remain required. Only IPv4 delivery is supported. Server-controlled public destinations still need an operational allowlist/egress policy before enablement; public-IP validation is not organizational recipient authorization.

## Durable bounded attempts and unknown outcomes

Two explicit additive InnoDB tables store delivery state and per-attempt claimed/confirmed/unconfirmed audit, bringing the production installer to **29 tables**. Requests/dispatch do no DDL. Claim serializes on custody/stream/alert/delivery rows, commits an attempt audit before network and never holds DB locks during network. The same delivery ID and exact payload are reused. At most three total claims are allowed; no lifetime budget reset. A 60-second sending lease fences concurrent attempts. Next-attempt delay is 60/120/240 seconds from each claim, also constrained by the lease. This is an admission gate, not an automatic retry loop.

Confirmation/failure finalization requires the exact current attempt and unexpired lease. State+outcome audit are atomic; audit failure leaves sending/unknown state rather than falsely confirmed. A crash/lost response can leave sending past expiry; the next explicit CLI attempt after both deadlines consumes another attempt using the same ID, never assuming the receiver did not accept. A late old result cannot finalize a newer attempt. After three attempts, or any confirmation, further claims refuse. There is no override/reset/automatic reconciliation. Delivery outbox terminal exhaustion requires operator investigation; local alerts are retained.

The protected stepped-up exact-hash-audited report adds delivery state/attempt/lease/backoff metadata to its already bounded alert list. No credential/destination fingerprint or response body is exposed. CLI returns only delivery ID, confirmed boolean and `operator_acknowledged:false`; nonconfirmation returns nonzero. HTTP invocation exits 404 before configuration/database loading. Application DB/bootstrap is still used: this is not an OS sandbox or least-privilege database deployment.

## Validation and gates

36 pure contracts cover disabled execution, URL/IP refusal, payload-bound signed receipts, malformed/oversized responses and edition parity. Nine synthetic cURL contracts verify TLS/DNS pinning, redirect/proxy disablement, signed headers (not raw key), timeouts, unsigned/redirect/oversized refusal. Native disposable cases cover claim exclusion, stable retry payload/ID, expired unknown retry budget, late result refusal, attempt cap, confirmation+audit rollback, no human acknowledgement mutation, independent one-winner claims, destination pinning and unchanged financial objects. No network was used in tests.

Before production enablement: provision process/secret/DB/network isolation; implement and verify the receiver/replay/idempotency contract and on-call ownership; test real staging DNS/TLS/timeout/crash/lost-response/rotation behavior; approve schema/backup/restore and delivery-budget recovery. Polling worker enablement, automatic scheduling/window rollover, completeness/reconciliation, customer attribution, risk/finality and financial settlement remain separate gates. Existing balances, holds, withdrawal restrictions and crypto live-disablement are unchanged.

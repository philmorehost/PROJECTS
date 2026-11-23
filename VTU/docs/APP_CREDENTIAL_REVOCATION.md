# First-party app tokens: credential-change invalidation

Both editions' opaque `bc_app_token_issue` / `bc_app_session_user` tokens now carry
an additional credential-binding tag. The token remains a URL-safe opaque string
under the existing 200-character server limit. There are 32 random bytes plus a
version marker and HMAC-SHA256 tag; the key derives from the authoritative account's
stored password/PIN hashes, identity and tenant. No password, PIN or hash is sent
in cleartext or stored in the token row. Only SHA-256 of the complete token is
persisted, as before. The tag alone NEVER authenticates a request: a live persisted
hash row, matching tenant, expiry, revocation and account-policy checks are still
mandatory. Signature comparison uses hash_equals.

Every token resolution re-reads the account. Password hash, hashed/legacy PIN,
mailbox or linked-Google identity changes invalidate the binding for every device
of that account on the next request. Cross-tenant/account bindings cannot be used.
Blocked accounts are explicitly refused at issuance and resolution. Unrelated
balance/pricing changes do not invalidate a token. A stale token cannot use the
normal daily refresh/exchange to obtain a new one, because exchange first resolves
it through the same checks. Existing logout/revoke-all, expiry, enrollment and
Personal/Business policy enforcement remain in place. Developer API keys are NOT
rotated by this change.

## Rollout impact

**Old first-party bearer tokens have no credential-binding tag and are refused.**
Users must sign in again after deploying this source. No lazy upgrade/refresh of an
unbound token is allowed. The API field/storage contract stays a string, but no real
Android/iOS device or third-party client compatibility is claimed tested. No native
app source was changed; device testing is still needed in a separate rollout.
Missing/empty stored passwords fail closed at issuance/resolution.

There is no new schema, installation migration or credential column. Existing
persisted token rows are not bulk-updated or deleted. Rejected binding tokens are
not necessarily marked revoked in storage; this is a current-credential check,
not a monotonic account revocation epoch. Restoring identical old credential hashes
and unrevoked token rows from a backup can restore validity; existing explicit
revoke-all remains necessary for incident response/backup restoration procedures.

## Deliberate boundaries

This batch covers first-party bearer tokens only. It does NOT revoke legacy app
requests authenticated through developer api_key paths, or PHP browser/admin
sessions. Their stamping/migration/authentication paths still need separate work.
It does not implement a new account-wide “logout all” UI, rotate developer keys,
fix every login/password-reset race or lift any financial/crypto safety hold.

Synthetic tests exercise every bound field, tampering, legacy tokens, missing data
and unrelated-field stability. Existing fake-database token lifecycle tests now
load the new dependency and test persisted-token credential changes/legacy refusal.
Disposable MySQL tests cover multiple devices, password/PIN changes, rejected stale
refresh, tenant isolation, fresh reissue, unrelated-user survival and unchanged API
keys. No production credentials, device/provider calls, deployment or live resets.

Next: carefully stamp every authenticated browser entry point and reject stale
sessions without trusting a first post-reset request to establish its own binding;
then audit remaining legacy API-key app authentication separately.

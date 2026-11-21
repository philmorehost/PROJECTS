# CryptoHub custody management and protected evidence

## Delivered scope

SAAS `bc-spadmin/CryptoCustody.php` now exposes local custody proposal/review, suspension and tenant binding review/revocation. Links are available from Super Admin Account Settings and Crypto Policies. Both editions ship the matching libraries and explicit schema. Vendor `bc-admin/CryptoCustody.php` is an authenticated requirements-only page; it exposes no registry data/evidence and denies POST. Standalone self-approval remains deliberately unavailable until a separately reviewed independent-operator authorization model exists.

This is local governance, NOT proof of Bybit account ownership, customer attribution, legal eligibility or reserves. It does not call a provider, allocate routes, credit/settle deposits, enable withdrawals/conversions or release financial holds.

## Request and actor boundary

- Session identity comes from the existing authenticated Super Admin config. Posted actor IDs, roles and tenant authority cannot select the acting administrator.
- Every mutation and evidence view requires POST, session CSRF, a new rate-limit attempt (5/5 minutes), a fresh DB reload of the exact session actor, active/unblocked/no-forced-password-change status, enrolled authenticator, then the existing throttled Security PIN + replay-protected TOTP verifier. Enrolled TOTP cannot be replaced with email OTP.
- GET loads only bounded registry/audit metadata for platform actors. Evidence plaintext is never available by GET, query-string URL or unauthenticated download.
- All output is HTML-escaped; sensitive responses use private/no-store and no-referrer headers. New wrappers cap request length at 64KiB. No evidence plaintext is placed into sessions, flash messages, redirect URLs or application logs. Response history/browser extensions/screen capture and compromised administrator devices remain risks; do not assume no-store defeats them.
- Registry actions retain expected revisions and transactional audit. Existing identities can be managed without resubmitting/storing raw UID. Only canonical UID creation can create a registry record; an arbitrary new fingerprint cannot be provisioned through the identity-management path.
- Active vendor existence is checked before new binding proposals/reviews; an inactive vendor's existing binding can still be revoked. No tenant fallback or live activation capability is exposed.

## Evidence material and encryption

The workflow accepts only bounded UTF-8 narrative notes, 20–16,384 bytes: documentary approval references, account/model verification method, scope, reasoning and review context. It does not implement binary uploads, document hosting or fetching arbitrary URLs. Do not enter API secrets, recovery phrases, passwords or customer identity documents. A small sensitive-material pattern filter exists but is not comprehensive DLP; the operator is responsible for classification and minimization.

`BC_CRYPTO_CUSTODY_EVIDENCE_KEY_V1` must contain a base64-encoded **32-byte cryptographically random dedicated encryption key**, provisioned by the server secret manager. `BC_CRYPTO_CUSTODY_EVIDENCE_ACTIVE_KEY` defaults to `V1`. Key IDs are bounded `V1`–`V999`; rotation can select `V2` while retaining the older `...KEY_V1` for existing records. Never paste real keys into chat or commit them. There is no DB-password-derived key and **no plaintext fallback** when OpenSSL or the selected key is unavailable. This is application envelope encryption, not a managed KMS implementation; deployment key protection, backups, rotation, recovery and environment isolation remain operator obligations.

AES-256-GCM uses a random 96-bit nonce and 128-bit tag. Authenticated associated data binds the exact custody fingerprint, environment and SHA-256 evidence digest. Wrong account/environment/digest, damaged ciphertext or missing old key fail closed. Key IDs and nonces are not secrets. Evidence digests are identifiers, not authentication and not encryption; avoid low-entropy confidential statements.

Two new additive InnoDB tables:
- `sas_crypto_v2_custody_evidence`: encrypted immutable-through-this-API document, original actor and digest/account/environment metadata. Exact duplicate submissions never overwrite original ciphertext/actor.
- `sas_crypto_v2_custody_evidence_events`: transactional stored/viewed audit and revision-bound review receipt.

Evidence can be stored before the registry proposal so creation never requires a naked, inaccessible evidence hash. Storage/audit commit together; a failed audit rolls back insertion. A successful decrypt is not returned to the controller until its view event commits. Evidence edits are a new exact digest; old evidence is retained. Database superusers can still modify/delete data; least-privilege DB grants, encrypted backups and off-host immutable export/retention policy remain required before live custody.

## Independent review sequence

1. First platform reviewer stores evidence (fresh PIN/TOTP). For a new account they enter the provider UID as a string; it is not persisted or placed in URLs by these modules. This is a claim awaiting independently verified source evidence, not a provider integration.
2. Propose using the returned evidence digest, same physical UID/environment and model. The registry holds the canonical fingerprint; creation does not enable anything.
3. A different authorized reviewer opens the exact proposed evidence at the current revision (fresh PIN/TOTP). An audited view receipt binds actor/account/digest/revision for 15 minutes.
4. With a **new authenticator code**, that reviewer records review (another PIN/TOTP). Missing, stale, wrong-actor or wrong-revision view receipts fail. Registry independent-actor and evidence-match checks still run inside its locked atomic update.
5. Store tenant-binding evidence, propose the explicit vendor binding, then have a different reviewer view it at the new revision and review it. Bindings expire no later than account review (24-hour ceiling).
6. Suspension/revocation remains available without needing to decrypt evidence. Reproposal resets all old account and binding approvals. A later good review never unfreezes sticky deposit quarantine or releases funds.

Different actor IDs are enforced; actual independence of humans, administrator account creation/recovery control and segregation of duties are organizational obligations, not proven by a numeric ID comparison. Review labels and notes are not provider-authenticated facts.

## Migration, validation and next gate

The explicit `crypto-v2-manage.php schema` preview/approved installer now includes sixteen additive production tables. There is no new DDL on request/intake. Separate migration/backup review and deployment authorization are required; neither was performed by the agent. Missing or incompatible schema fails closed.

Tests cover encryption/AAD/tamper/key rotation, source/edition boundaries, mocked management CSRF/session/fresh-actor/rate/step-up gates, and disposable MySQL immutable evidence, access receipts, actor/revision/expiry scope, transactional view/store audit failure and outer-transaction refusal. Mocked authorization tests do not constitute device/session penetration testing. Native OpenSSL/MySQL CI must pass before merge. No live provider account was inspected and no real evidence/key was used.

Read-only provider UID comparison is now implemented as documented in `CRYPTO_BYBIT_IDENTITY.md`; it records sanitized encrypted point-in-time identity evidence, not custody eligibility or write capability approval. Local exclusive route candidates are now documented in `CRYPTO_DEPOSIT_ROUTE_CANDIDATES.md`; candidates never activate a funding route. Next gates: verified master/subaccount custody structure and operation-specific capabilities against fresh official docs; approved custody/business model; audited exclusive deposit-route provisioning; trusted read-only deposit polling with exact records/status mapping, pagination/checkpoints and protected evidence retention. Settlement remains a separately reviewed accounting and controlled-pilot milestone. Existing crypto disablement, withdrawal stops and holds remain unchanged.

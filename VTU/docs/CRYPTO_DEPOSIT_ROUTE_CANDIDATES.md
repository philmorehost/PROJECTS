# CryptoHub exclusive deposit-route candidates

## Milestone scope

This adds a platform-only, dual-review **candidate** workflow, not a Bybit address allocator or an active deposit route. A reviewed candidate cannot receive customer funds through this code: no writer to `sas_crypto_v2_deposit_routes`, no customer address display, provider polling, wallet credit, settlement or withdrawal/hold changes are introduced. The existing deposit-observation intake does not read the new candidate tables.

SAAS Super Admin custody management now offers proposal, independent review and revocation. Vendor/standalone mutation remains denied by the existing management boundary. Every action inherits POST/CSRF, fresh active session-bound platform authority, forced-password completion, mandatory PIN/TOTP and throttling. There are no provider calls in this workflow.

## Exact immutable attribution claim

A proposal binds environment, canonical custody account/revision, vendor/customer IDs, exact coin/network key and accounting scale to `SHA256(JSON([asset_network,address,tag]))`, the same exact route hash contract used by observation intake. Address and memo/tag are case-sensitive; explicit empty memo differs from `0`, and missing/null memo is refused. No chain/address normalization, alias collapse, checksum validation or ownership verification is inferred from ASCII validation. Those are future reviewed provider/chain-adapter obligations.

The exact address/memo and a bounded attribution note are automatically encoded with the immutable customer/scope metadata into an encrypted manifest in the existing protected evidence store. The candidate table/audit contains only hashes and non-address metadata. No address/note plaintext enters sessions, redirects, application logs or registry metadata. Operator/device/browser and privileged-database risks remain as documented in `CRYPTO_CUSTODY_MANAGEMENT.md`.

Different customers cannot claim the same exact account/environment/network/address/memo tuple through this workflow, including after candidate expiry/revocation. This is enforced by a global unique key, not a tenant-scoped key. Candidates cannot be edited/reassigned, auto-renewed or resurrected. Future correction/renewal needs its own reviewed evidence-preserving workflow; do not delete history to release a claim. Existing active/inactive deposit-route rows also block a candidate on proposal/review. This is not a cross-table database uniqueness constraint: any future active-route provisioner must explicitly consult and lock both stores. Privileged direct SQL and unreviewed future writers are not constrained by this application API.

## Guards, independent review and stops

Proposal and review recheck, under locks:
- exact custody environment/current revision;
- current independently reviewed, unexpired custody account AND explicit vendor binding;
- active/unblocked vendor and eligible same-tenant customer, no forced-password change, KYC level 2;
- current platform/vendor controls, deposit-direction approvals, fresh discovered network availability, matching policy fingerprints and accounting scale.

A different platform actor must open the exact generated encrypted manifest using the existing freshly stepped-up, audited view flow. Review requires that actor's recent account/evidence/custody-revision view receipt, plus the expected candidate revision. It cannot substitute a different narrative evidence digest. Candidate review expiry is capped by the requested 24-hour maximum and the earliest account/binding/asset-approval/catalogue expiry. It remains only a local review label. There is no background sweeper; future consumers must revalidate current state rather than trust a stored label.

Revocation is available without current customer/KYC/asset approvals or evidence decryption, but still needs authenticated step-up, current custody revision and candidate expected revision. It never frees the attribution tuple for someone else and never refunds or alters a balance. New custody proposals/reviews change the custody revision; old candidates cannot be independently reviewed under that new revision.

No provider-issued ownership/allocation proof or approved legal custody model is asserted by these local checks. The earlier read-only UID comparison does not prove address attribution, master/subaccount suitability, third-party custody permission or reserves. Do not deposit to manually entered address claims.

## Atomicity and explicit migration

Two additive InnoDB tables (`sas_crypto_v2_route_candidates`, `sas_crypto_v2_route_candidate_audit`) are added to the explicit CLI schema preview/approved installer, bringing the production schema to sixteen tables. No DDL is performed on requests or intake. No production schema migration/deployment was performed.

Custody, candidate and safety rows are locked in the owner transaction. New candidate + encrypted manifest + stored-evidence audit + candidate audit commit together. Audit failure rolls everything back. Review/revocation changes and revisioned audit commit atomically. Nested/autocommit-off callers and missing/non-InnoDB supporting tables fail closed. Independent concurrent proposals serialize at the custody lock and global unique tuple; stale edits are refused. Evidence view is a separately audited operation as before.

## Validation and next gates

Pure contracts cover exact route hashing, empty/zero memo distinction, scope/scale syntax, independent review, expiry, terminal revocation, edition/source boundaries and absence of active-route/wallet writes. Disposable MySQL cases exercise encrypted manifests, customer assignment conflicts, global duplicate reservation, stale revisions, receipt-gated review, policy stops, atomic proposal/review audit failure, competing processes and unchanged active routes/journal/balances/holds. Native CI must pass before merge. No live addresses, credentials or provider calls are used.

Next: verified custody business/account model and operation-specific capability approval; trusted provider address retrieval/attribution with chain-specific validation; audited promotion/provisioning that consults both candidate and route stores; authenticated read-only record polling with exact identities, status mappings, checkpoints and retained evidence. Deposit settlement and tiny-value controlled pilot remain separate accounting/security gates. Existing live-crypto disablement, withdrawals stops and holds remain intact.

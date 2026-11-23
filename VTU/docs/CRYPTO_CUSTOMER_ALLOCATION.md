# Customer allocation gate — implementation status

## Provider contract checked

Bybit documentation revision `66ed2e2167bbf835ac9c86ec1d40023528ba3684`:
https://github.com/bybit-exchange/docs/blob/66ed2e2167bbf835ac9c86ec1d40023528ba3684/docs/v5/asset/deposit/sub-deposit-addr.mdx

`GET /v5/asset/deposit/query-sub-member-address` queries a subaccount address;
it does not create a customer allocation. It requires the master UID's key.
The documentation explicitly excludes custodial subaccounts. Request keys are
`coin`, canonical `chainType`, and `subMemberId`. Unlike master-address inventory,
the documented response has a single object at `result.chains`, not an array.
The response does not echo a verified subaccount UID or prove its parent relation.
The last six contract characters are not token-contract verification.

## Implemented, non-live

- Offline, dual-edition parser for USDT/TRX and TRX/TRX sub-address evidence.
  Validates TRON Base58Check; distinguishes canonical chain from display name;
  rejects master/sub UID equality and malformed string IDs. It never claims that
  caller-supplied UIDs, a parsed address, or its checksum prove allocation.
- Existing encrypted route-candidate workflow remains authoritative for local
  independent review only. New admin overview queue exposes bounded, tenant-scoped
  metadata, not addresses, memos, ciphertext or evidence documents. Candidate
  review does not provision or enable a deposit route.
- Existing attribution engine quarantines unapproved/changed attribution and
  provider regressions. Review-ready observations still require manual settlement.
- Deposit links open current local observation receipts in the authenticated
  overview. Lookup binds environment, tenant and customer before reading the ID;
  platform access binds environment. No provider IDs, custody IDs or raw evidence
  are exposed. Missing/foreign records share 404; database failures are 503.
  Receipts explicitly are not payment confirmations, immutable signed receipts,
  successful credits, or instructions to send funds.

## Still blocked / not implemented here

Subaccount creation, provider write-key expansion, active route publication,
customer funding-address presentation, risk clearance, financial posting and
receipt of settled funds remain blocked. Protected read-only transport and local
customer-binding review/renewal are implemented as described below.
No claim of live Bybit eligibility or testing. Customer service gate and existing
session/enrollment controls continue to apply. No automatic migration or deploy.

Before any provider read, verify the actual supported account model, provider
terms/region eligibility, master/subaccount identity and parent relation using
provider-authenticated evidence; bind that evidence to a stable server-side
customer/tenant allocation, configuration fingerprint and expiry. Re-check
identity/configuration before and after the read. Encrypt exact address evidence
and independently review exclusive route ownership. Do not fall back to a shared
master address, infer customer identity from address alone, or silently expand
key permissions. Custody/tenant/policy stops, attribution, risk/finality,
idempotent balanced credits, reconciliation and operational readiness remain
separate gates before live funding.

## Authenticated relationship comparison follow-up

The platform-only Custody page now offers `verify_parent` as an explicit
POST/CSRF/rate-limited fresh authenticator step-up action. It requires existing
child and master registry fingerprints and expected revisions, plus distinct
server-side configuration IDs for the subaccount and master keys. Both source
vendors must remain active/unblocked before and after the reads. No credentials
or provider URLs are accepted from the browser.

Four bounded existing `query-api` GETs compare sub/master/sub/master identity:
read-only/IP-bound personal keys, matching UID fingerprints, subaccount-reported
parent fingerprint, and master account kind. Both configurations and identity
results must remain stable; evidence expires 15 minutes after start and overall
completion must be within 30 seconds. No retries, new endpoint or expanded key
permissions. Sanitized request audit precedes the reads; encrypted evidence
storage checks the child revision again under lock. Parent revision is recorded
as point-in-time context, not a lock-held authorization or durable binding.

This is evidence of what the provider reported, not a guarantee of legal custody,
subaccount product eligibility, ownership or customer allocation. Raw UIDs,
keys, secrets and IPs are excluded from the evidence manifest. No address is
queried or displayed. No candidate, active route, registry approval or balance is
changed. Missing separate configurations or unsupported key/account models fail
closed; do not create new keys or change their permissions merely to bypass a
failure. This check has synthetic transport coverage, not live-provider proof.

## Customer/subaccount review reservations

The platform Custody page supports proposing, independently reviewing and revoking
one immutable customer reservation per subaccount fingerprint. Two new additive
InnoDB tables hold reservations and revisioned audits; install only through the
existing explicit reviewed CLI schema process. Requests perform no DDL.

Before proposal or review, the workflow requires a fresh audited view of exact
relationship evidence by that actor, approved dedicated-subaccount custody and
tenant context, active vendor/customer and completed KYC, unchanged child/parent
registry revisions, active source vendors and matching configured key/IP
fingerprints. The relationship manifest must carry a domain-separated server
HMAC attestation using the protected evidence key: manually stored JSON cannot
assert that an authenticated provider comparison took place. Old unattested
relationship evidence must be recollected; do not manually edit it.

Proposal reserves the subaccount permanently for the specified vendor/customer.
Review must be by a different platform actor. Review expiry is capped by the
relationship evidence's original 15-minute expiry and current custody/tenant
expiry. Revocation is still available with stale/missing evidence. Revocation
or expiry does not permit reassignment. Renewal is now supported as a new independently reviewed proposal with fresh
evidence; revoked reservations cannot be renewed. The stored review state is not a current eligibility or funding grant.

All changes and audit rows commit together, under custody/reservation and
customer/vendor locks. Nothing in this workflow queries an address, grants
custody, updates deposit routes, clears risk or posts money. Independent reviewers
must inspect the exact vendor/customer mapping shown beside the evidence digest;
provider parenthood does not itself establish customer ownership. Native tests
use synthetic attestations in disposable databases, not real provider accounts.


## Renewal and protected bound-address reads

Renewal is a revision-checked proposal on the existing immutable vendor/customer
mapping. It rejects revoked reservations, posted customer overrides and reuse of
the exact prior evidence digest. Fresh server-attested relationship evidence and
an audited evidence view are required. It resets review state/reviewer/expiry,
records the renewing actor as proposer, and requires a different reviewer. A
custody revision change can be adopted only through this complete re-review.

The platform-only `inspect_bound_sub_address` action takes coin/network and
expected registry/binding revisions, not customer IDs, UIDs, credentials or
configuration selectors. Two short transactions validate current reviewed
binding, custody/tenant context, KYC, sources, evidence and parent registry before
and after network reads. No database locks are held across the network. A changed
or expired context refuses storage. Exact evidence and its stored audit event
commit in the post-read transaction; failures publish no successful result.

The internal adapter uses subaccount and master configurations from the reviewed
relationship evidence, authenticates both identities, then uses ONLY the master
key for `/v5/asset/deposit/query-sub-member-address`. The requested sub UID comes
from the authenticated subaccount key-info response, never the browser. It then
checks both identities and captured configurations again: five bounded GETs,
three-second connect/eight-second per-request timeouts, 45-second total freshness
ceiling, no retries, redirects, writes or master-inventory fallback. A shorter
PHP/web-server timeout may abort safely before a result is stored; it is not
relaxed here. Unsupported/custodial account models fail closed.

Only TRX/TRX and USDT/TRX are parsed. Raw UIDs/keys/IPs are not propagated to the
stored manifest. Exact address and memo remain encrypted; UI returns only an
evidence digest for protected review. The manifest records immutable customer,
binding revision and source relationship digest, with expiry capped by binding
expiry. The ordinary dashboard/customer funding screens receive no addresses.
This is inventory evidence, not a verified allocation, exclusive-route proof,
provider support certification, receipt of funds or funding authorization. No
schema changes or provider writes are introduced by this follow-up. No live
provider verification or deployment has occurred.

## Evidence-derived disabled route candidates

`propose_bound_route` is an explicit platform POST/CSRF/fresh-step-up action. It
accepts only the stored address-evidence digest and expected customer-binding
revision in addition to the normal custody context. Address, memo, asset/scale,
tenant and customer overrides are rejected. The server derives these from the
exact encrypted bound-address evidence and immutable current binding.

Bound-address evidence now carries a separate domain-separated server HMAC;
manual JSON and old unattested address evidence cannot enter this path. Re-run
the protected address inspection for old evidence. Both proposal and review
revalidate the attestation, original expiry, exact relationship digest,
child/parent registry revisions, current reviewed binding, source configurations,
customer/KYC, custody/tenant eligibility and policy/catalogue gates. TRON Base58Check
and empty-memo restrictions are rechecked; TRX and USDT on TRON use scale six.
Each actor must have fresh audited views of relationship and address evidence;
the independent reviewer must additionally open the exact candidate manifest.
The manifest pins the source digest and binding revision. Renewal/revocation
invalidates review eligibility of any still-pending candidate on the old binding.

The existing candidate workflow is reused; no active deposit-route row is
created, even after independent review. Review expiry cannot exceed the address,
binding/custody or policy/catalogue ceilings. Candidate metadata is not proof of
current eligibility after later changes; there is no funding-address publication.
Exact addresses/memos remain encrypted, not listed in dashboard tables.

Candidate proposals and reviews now reject a matching canonical
(environment, asset/network, exact address/memo routing hash) in any custody
account's candidates or deposit routes. Revoked/expired reservations also block
reuse. Existing route_guard locks the global environment control row before
these global conflict checks, serializing admitted application candidate writers.
This is not a cross-asset ownership check, a repair for existing duplicate rows,
or protection against out-of-band SQL writers; no application path here writes
enabled routes. All future active-route writers must enforce compatible global
exclusivity/locking before live use. No DDL or live data cleanup is performed.

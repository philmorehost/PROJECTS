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

No network transport for this sub-address endpoint, subaccount creation, customer
binding approval, provider write-key expansion, active route publication, address
presentation, risk clearance, financial posting or receipt of settled funds.
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

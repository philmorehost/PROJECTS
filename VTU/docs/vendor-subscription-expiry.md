# SaaS vendor subscription suspension

Applies to `DGV7.0-SAAS` only. It does not suspend the platform licence or
change vendor `status`, so successful renewal restores access without a cron
job or manual reactivation.

- `sas_vendors.expiry_date` is valid through the end of that date in Africa/Lagos.
- NULL, blank and `0000-00-00` retain the legacy unlimited-subscription meaning.
- The shared database bootstrap checks expiry from the database on every HTTP
  request, not the landing-page vendor cache.
- Expired public pages return a responsive, self-contained suspension page with
  HTTP 503 and no-store headers. APIs return JSON with
  `VENDOR_SUBSCRIPTION_EXPIRED` instead of HTML.
- Vendor admin routes are restricted to login, password recovery, renewal and
  logout. The authenticated-vendor check also confines existing admin sessions.
- The renewal-only screen omits operational navigation. Billing/KYC/enrolment
  redirect gates do not trap an expired vendor away from renewal. Existing
  login authentication, account/IP blocks and revoked-session checks remain.
- Renewal purchases use CSRF-protected POST requests (old `?buy=` links no
  longer charge wallets).
- Funding screens remain locked as requested. Vendors without enough wallet
  balance must contact the platform administrator to arrange funding.
- Super-admin administration, CLI jobs and the explicitly listed incoming
  vendor payment settlement scripts remain available. These are not storefront
  access exemptions.

## Verification

Run from the repository root:

```
node VTU/tests/harness/run-test.mjs tests/vendor_expiry_test.php
```

The regression test covers expiry boundaries, unlimited dates, renewal,
route policy, rendering and integration wiring. It does not replace a live
MySQL/payment smoke test. On staging, set a test vendor to yesterday, check
landing/customer/API/admin direct URLs and an existing logged-in session, then
renew with a funded wallet and confirm immediate restoration. Also test an
insufficient wallet and a real sandbox payment callback.

Deploy all changed SaaS files together. No database migration is needed.

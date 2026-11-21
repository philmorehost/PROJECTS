# bc-admin pricing selection and Business-to-Personal conversion

## Why a lower selection was unavailable

`bc-admin/UserUpgrade.php` disabled all options below the user's current legacy
`account_level`, even though its POST logic already accepted lower levels.
Removing that disabled attribute alone is insufficient for app login: a saved
`account_type=business` takes precedence over legacy account_level for access.

## Current interface

The pricing dropdown now permits all three legacy pricing levels and labels them
Smart Earner / Agent Vendor / API Vendor, rather than misleadingly describing
these prices as a Personal/Business account type. Higher-price changes retain
existing upgrade-fee behaviour; lower changes do not charge/refund a fee. The
pricing form is CSRF-protected and preserves the resolved account type explicitly,
including on legacy rows, so editing pricing cannot bypass KYB or change app
eligibility accidentally.

A separate form on the same page is shown for Business accounts:
**Convert Business to Personal**. The administrator must supply a Security PIN
and the existing server-enforced Authenticator factor (when enrolled) or email
OTP otherwise. The OTP purpose is `user_personal_conversion`. Promotion back to
Business remains exclusively through KYB approval; this form cannot promote.

## Conversion effects

The new shared helper scopes the target to the authenticated vendor, locks its
row, and in a checked transaction:
- explicitly stores Personal type, with source/timestamp;
- disables developer API status;
- resets KYB status to cancel pending promotion (documents/history retained);
- revokes app sessions and writes a tenant-scoped audit entry.

Any failed update/revocation/audit/commit rolls back. Schema setup is done before
the transaction so schema DDL is not introduced during it. Wallet balances,
account blocks/status, KYC approval and legacy pricing level are untouched.
Normal account/security/KYC checks still apply at subsequent app sign-in. The
user is not automatically signed in and existing financial holds remain intact.

## Validation and deployment

```
node VTU/tests/harness/run-test.mjs tests/admin_personal_conversion_test.php
node VTU/tests/harness/pricing-options.test.mjs
node VTU/tests/harness/run-test.mjs tests/fintech_gate_test.php
node VTU/tests/harness/run-test.mjs tests/security33_test.php
```

The transaction tests use isolated DB-boundary doubles, not a live database. DOM
checks exercise actual PHP-generated dropdown markup. No production conversion,
wallet debit, email delivery, mobile build, or device testing was performed.

Deploy both `bc-admin/UserUpgrade.php` and `func/bc-admin-user-tier.php` for the
installed edition, invalidating PHP OPcache as appropriate. Open the user's
change-level page, use the separate conversion form, then have the user sign in
again. Changing only pricing is intentionally not a Business-to-Personal change.

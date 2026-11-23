# Paid gateway access and atomic pricing-level changes

## Source implementation, not activation

No production deployment, schema migration, credential entry, purchase or provider
request is performed by this change. Crypto staging remains paused. Existing
withdrawal disables, vendor blocks, financial holds, KYC and service controls remain
in force. An API access purchase is a software entitlement, not provider onboarding,
licensing, verified custody or permission to move funds.

## Access and defaults

`bc-admin/PaymentGateway.php` lists Bybit and Klasha under Fintech API Access and
renders independent API Settings cards even when the Withdrawal Module is disabled.
SAAS defaults are **NGN150,000 for Bybit** and **NGN50,000 for Klasha**. Existing
configured prices and purchased grants are retained. Flutterwave/Platnova remain
in the same paid catalogue with NGN50,000 defaults. NON-SAAS includes access to
these APIs without vendor billing; its marketplace is unchanged.

The SAAS platform owner edits prices in the dedicated **Fintech API Access Prices**
form on `bc-spadmin/PaymentGateway.php`. Saving requires CSRF, security PIN and an
enrolled Google Authenticator. Prices must be nonnegative decimal amounts with
at most two decimal places. Zero allows free access. The old KYC form no longer
accepts/writes gateway price fields. Changing a price does not revoke grants.

Vendor purchases require CSRF, PIN and the account's required second factor. The
quoted price is checked against the current database value inside the transaction.
Stale quotes are refused, never silently charged at a different price. Wallet,
receipt, replay event and tenant access grant commit together. Vendor row locking
serializes overlapping purchases; a request finding an existing grant does not
charge again. Missing/non-InnoDB tables, active outer transactions, duplicate
settings or an unconfirmed grant fail closed.

## Credential storage and adapter selection

New saves use `sas_gateway_api_credentials`, an additive InnoDB table with a primary
key `(vendor_id,gateway_slug)`. Its encrypted JSON is bound to tenant and provider.
An installed nonempty database password and OpenSSL AES-GCM are required; no
plaintext fallback is accepted for new saves. No key/password/token is prefilled
back into the browser, and blank fields retain previously saved values. Invalid
saves leave the old encrypted configuration intact.

Klasha fields map to merchant public key, account password/token, encryption key,
login email and optional business ID. Its fintech adapter prefers the encrypted
configuration, with existing legacy credential/option fallback only when there is
no new configuration. The old combined withdrawal-key form no longer edits Klasha.
Normal withdrawal credentials for other providers are not migrated by this change.

Bybit fields are API key, API secret, testnet/mainnet configuration and expected
server IPs. Its existing read-only adapter uses the saved configuration when no
server-environment KEY or SECRET is supplied. Explicit per-vendor environment
credentials retain precedence; partial environment credentials fail validation
rather than mixing sources. Database-backed runtime access enforces the commercial
entitlement for environment credentials too. No remote test/verification runs on
save. Existing staging/governance/live gates are not modified or enabled. Stored
mainnet configuration is **not** approval for live operation.

## User pricing levels

`web/handle_upgrade.php` now provides a PIN + second-factor confirmed pricing-level
form, reachable from Tools & API -> Pricing Level. The existing vendor-admin
`UserUpgrade.php` also uses the atomic transaction helper and step-up verification.
A fresh expected source level and quoted fee are checked under the user lock.
Smart Earner is always free, including when an obsolete price row is nonzero.
Configured zero-price higher-level upgrades work; downgrades have no charge/refund,
matching the existing admin policy. Missing/invalid paid prices fail closed.

Pricing changes never modify Personal/Business type, KYB status, API eligibility,
KYC or balances except the authorized fee. Business approval/conversion remains a
separate free compliance-controlled flow. User incident transaction locks are
conservatively respected (no auto-unlock here). Existing super-admin complimentary
pricing overrides and the old isolated clone backend are not rewritten in this batch.

OTP sending and the new purchase/settings saves use inline asynchronous results:
no page reload or second alert, no lost form input on error. OTP requests contain
only CSRF and purpose, never PIN, factor or API credentials. On successful saves
password inputs are cleared. After buying access or changing level, the button is
marked complete and asks for a manual refresh before another operation.

## Deployment review

The additive schema marker is `2026.11.18-paid-gateways-1`. Use the normal separately
authorized deployment process; do not clean/delete historical settings, grants or
receipts. Existing wallet/history/settings/options tables must already be InnoDB;
this patch refuses unsafe writes instead of silently converting legacy tables.
Back up and review table engines before any separately approved deployment.

Native CI uses disposable databases and synthetic credentials: price overrides,
zero fees, tenant isolation, rollback on failed grant, nontransactional/outer
transaction refusal, concurrent Bybit purchases, encrypted saves, adapter reads,
blank retention and no provider transport calls. This does not establish device
or live-provider behavior. The broader site-wide OTP/KYC/security audit remains open.

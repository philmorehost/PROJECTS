# Independent provider request routes — first integration

Both editions now support separate vendor settings for international-transfer, tuition and POS **request intake**. Service Control exposes one independently saved route per service with PIN and configured Google/email action verification, CSRF, fresh owner checks, rate limits, demo restrictions and impersonation refusal. OTP sends use the existing purpose-bound inline component and send no PIN/factor/route payload.

Each route is limited to a currently entitled provider whose existing catalogue includes the destination page. An explicit disabled, invalid, unsupported or revoked setting fails closed; it never substitutes another provider. When no independent setting exists, international transfers retain the legacy active-provider default; tuition retains Platnova and POS retains Flutterwave, subject to current entitlement/support checks. Changing the legacy default cannot overwrite independent settings.

The three customer request pages now use those server-selected routes, not a posted provider. International-transfer services are filtered to that page, so card/crypto/POS catalogue entries do not appear as transfer request types. Submitted service slugs are validated. CSRF is explicit, and a rendered route snapshot must still match at submission; a route change returns409 instead of silently redirecting an open form to a different provider. Existing request records remain pinned to their stored provider.

This increment does **not** claim completed automatic payment settlement, device provisioning, student validation or crypto custody. These existing pages record review requests; they now explicitly say that no wallet debit, executed payment or provider acceptance is confirmed. Disabled routing is visible and cannot submit. Existing transfer indicative-quote calls are unchanged and never trigger provider failover. Catalogue support is not proof of live provider/product eligibility.

Crypto, cards, withdrawals, eSIM and other services are not redirected through this request-routing map. Financial/security holds remain. Replacement Buy/Sell still needs its own durable orders/accounting/custody and real-provider verification. No activation, deployment or live provider call was performed by this change. Vendor PHP hosting does not need Node.

Automated coverage: independent settings/default compatibility, tenant/entitlement isolation, revoked/disabled/unsupported refusal, no silent fallback, page-specific catalogue, fresh actor/step-up/demo/CSRF/rate gates, persistence acknowledgement and both-edition page/snapshot guards. These boundary tests use explicit doubles and do not certify live provider behavior or production rollout.

## Durable-storage verification

`service_provider_routing_mysql_test.php` runs only against a disposable CI database for each edition. It exercises the production route resolver/save handler, actual settings helper implementations, prepared owner reads and edition-specific SQL entitlement checks. A second MySQL connection verifies committed readback. Injected INSERT/UPDATE trigger failures must return refusal, preserve any existing route and permit a later successful retry. Tenant settings/global default isolation and fresh blocked-owner refusal are checked.

Factor verification, rate-limit and demo policy are explicit doubles in this storage test; actual security/component behavior remains separate coverage. It makes no external provider request, creates no financial transaction and does not establish live-product availability. No production database or credentials are used.

## Request storage boundary hardening

Request insertion now obtains username from a fresh, tenant-scoped active-user SQL row, rather than trusting the page's cached username. Blocked/forced-password-change/inactive users, mismatched tenant IDs, unknown request types and unsupported provider/type combinations cannot insert. The three independently routed request types recheck the current route and transfer service catalogue before storage; eSIM retains its existing two-provider request mapping and is not newly activated or independently routed here.

References use 128 random bits instead of second-resolution timestamps. This prevents routine same-second reference collisions but **is not idempotency**: separate valid submissions still create separate review requests. No automatic execution, retry, refund or wallet mutation is introduced. Durable idempotency/unknown-outcome recovery and full settlement orchestration remain outstanding.

JSON encoding errors, excessive payloads and SQL/schema/insert errors return no success reference. No existing record, schema column, provider pin or audit history is rewritten. Both-edition disposable MySQL tests cover independent-connection request readback, canonical identity, fresh restrictions, tenant forgery, route disabling, malformed/oversized payloads, same-second reference separation, injected INSERT failure and unchanged wallet balances. Provider/factor doubles remain as documented above.

# Incident remediation — working-source status

Date: 2026-11-18. Applies to both PHP editions and six mobile source trees.

**Incomplete; not a release approval.** These changes have not been deployed, run against a real MySQL database, or verified with payment providers or native builds. Keep withdrawals disabled at application and provider levels. The original audit describes the reviewed base; this document records subsequent source changes, not incident attribution.

## Findings disposition

| Audit | Working-source treatment | Remaining release gate |
|---|---|---|
| F01 | Client PIN-only bypass removed in role helpers; conditional TOTP consume | Real authentication and second-factor UI/session regression tests |
| F02 | Unsafe funding-verification route paused | Replace with bound, scoped, reconciled funding workflow |
| F03 | Catalogue/setup search binding and escaping | Database and browser injection regression tests |
| F04 | Simulated virtual-card wallet-credit routes fail closed | Genuine provider integration and reconciliation before reopening |
| F05–F06 | Unsafe checkout creation paused; protected server intents bind beneficiary/provider/currency/gross amount | Review every remaining funding entry point; actual MySQL/provider integration |
| F07–F08 | Withdrawal handler and unsafe approval routes paused | Durable payout reservations, unknown-outcome reconciliation, status transitions and concurrent worker tests |
| F09 | NGN wallet mutation events and provider settlement-reference claims | Real transaction/engine/locking/failure tests; review fintech ledger transaction guards |
| F10–F11 | Legacy wallet helper callers use guarded transactional writer | Real MySQL tests, historical reconciliation, complete caller regression |
| F12 | Legacy virtual-card paths held | Replace unsafe implementation before reopening |
| F13 | Transaction mutation helpers tenant/owner scoped | Role-matrix database tests for every caller |
| F14 | GiftCardSetup bound search/escaping; marketplace escaping | Stored-XSS browser testing, catalogue authorization review |
| F15 | Runtime TLS verification hardened | Valid CA bundle, endpoint compatibility, negative TLS tests |
| F16 | Retired provider and purchase harness removal; development routes guarded | Verify deployed file deletion and rotate exposed credentials |
| F17 | Unsafe transaction GET actions held; secure session bootstrap added | Full route-by-route CSRF and session review remains required |
| F18 | Android encrypted preferences/backup exclusion; iOS Keychain migration | Native compile/device, upgrade and logout tests |
| F19 | Card routes held; migration scrubs CVV and masks numerical PAN | Check live schema/scrub results, remove sensitive logs/backups per retention policy |

Containment is not replacement implementation. No finding above is certified closed solely by this table.

## Intentional financial restrictions

- Virtual-card API and AJAX routes reject mutations. Provider virtual-card call is fail-closed.
- Bank-transfer withdrawal processing returns before mutation; unsafe approval POSTs are held. Do not enable an alternate payout route to bypass this hold.
- Funding verification/finance AJAX and create-checkout/payhub-checkout routes are held.
- Most legacy `users-*`, `vendors-*` financial callbacks and API webhook routes are held. Preserved exceptions include canonical Flutterwave, Klasha, Plisio and identity-webhook routes; preservation is not production approval.
- Canonical settlement requires a server-generated secure payment intent. Old checkout references, account-name fallbacks and payment history are not automatically authorized for credit. Manually reconcile provider-confirmed historical obligations rather than creating synthetic intents to force replay.
- Historical wallet operations without reliable matching mutation events may require reconciliation. Do not retry credits/refunds blindly.
- Browser execution of cleanup/development utilities is denied. CLI cleanup still needs separate operator review and evidence retention.
- Secure session cookies require HTTPS. Do not disable Secure cookies to accommodate an HTTP installation.

## Deployment prerequisites — not executed here

1. Preserve database/files/logs/provider exports and investigate the incident. Revoke compromised sessions/tokens and rotate exposed secrets from a clean device. Keep vendor/payout containment active.
2. Review the entire patch and inventory every paused route. Stage on a copy of the production schema with representative, sanitized records. Do not run a source migration against production merely because offline tests pass.
3. Review schema version `2026.11.18-security-2` in `func/bc-tables.php`: wallet mutation events, protected payment intents, provider settlement claims, card-data scrub. Validate privileges, indexes, supported currency lengths and storage engines. Ledger/claim/balance/receipt operations must share a transaction on InnoDB tables; nested transactions are unsafe. The fintech settlement writer still needs explicit engine/transaction-state guard review.
4. Run concurrent credits/debits/refunds, duplicate/different event IDs, same provider reference across tenants, changed payloads, commit failures and ambiguous provider outcomes on real MySQL. Verify exact monetary rounding and rollback of every related row.
5. Test login/logout/session renewal, CSRF, all roles, tenant isolation, PIN/TOTP/biometrics, KYC, referral/reward qualification and support chat. Compile all native variants and test secure-store migration on devices.
6. Reconcile opening balances, unsettled provider transactions, refunds and pending payouts. Reopen individual features only after their replacement workflows and operational reconciliation pass review. Never restore withdrawals based on source-only tests.
7. Deploy actual deletions as well as additions. Follow `RETIRED_PROVIDER_REMOVAL.md` separately for the guarded config-only repair; no live cleanup has been executed.

## Latest offline checks

- PHP parser: 971 changed/new PHP files parsed (no application execution).
- Fintech gate: 1,073 checks, including protected-intent amount/currency rejection and same-payment/different-delivery replay.
- NGN wallet SQL-double suite: 30 checks.
- Role step-up probes: both editions reject client bypass flags for all three roles.
- Policy: 70; gateway verdict: 117; mobile source regression: 76; retired-provider repair: 66.

These use PHP-wasm and SQL doubles/source assertions, not a real MySQL server or provider sandbox. The harness now exits unsuccessfully on reported PHP fatal/parse errors instead of silently returning success. Passing these checks does not establish that all 19 findings are fully remediated.

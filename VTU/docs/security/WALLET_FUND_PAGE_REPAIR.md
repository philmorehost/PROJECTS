# web/Fund.php interaction repair and scoped security review

## Confirmed failures in the previous page

- The button began with `pointer-events:none` and no accessible explanation. Image clicks/client-side code were required to enable it.
- The shared script assigned `<gateway>PaymentGateway()` to the button, but those payment functions were not defined in the current runtime source. Selection could appear valid while clicking failed.
- The legacy `create-checkout.php` and `payhub-checkout.php` handlers are intentionally held (`PAYMENT_INTENT_REQUIRED`). Restoring old popup scripts alone would not provide a safe, working settlement flow.
- Gateway encryption configuration was rendered into raw HTML attributes/hidden fields; several configuration/profile attributes were unescaped. Hidden fields are not secret storage. There was no explicit page CSRF field, named funding POST contract or server amount validation in this page. Query-string retry amounts could lock the amount input and client references were treated as flow inputs.

## Implemented behavior (both editions)

The replacement is a progressively enhanced native POST form. **Pay Now — View Bank Details** requests this authenticated customer's existing, approved PayHub virtual-account instructions. It does NOT debit a card/bank, initialize hosted checkout, generate/sync an account, fabricate an intent, create a receipt or credit a wallet. The button makes its behavior explicit rather than pretending a payment has completed.

Supported path: bank transfer into an existing ready PayHub account, with settlement through the independently verified receiver described in `PAYHUB_DEPOSIT_ROLLOUT.md`. Card/other legacy checkout methods are clearly unavailable; their handlers and security holds are NOT reopened. This repair is not full multi-gateway card-checkout support.

When rollout/configuration/schema/KYC/ownership checks fail, the page shows a clear reason, disables the payment-instructions button, withholds bank details and advises against new transfers. Existing-payment/retry links never initiate another charge; an informational warning directs already-paid users to reconciliation. Query amount is an editable validated hint only.

## Security boundaries

- Existing secure session/authentication, browser binding and global KYC/abuse gates remain in `bc-config.php`. An additional controller guard requires a matching active customer session/actor, positive tenant/user IDs, no block and no forced password change; no admin-only session fallback.
- POST validates a scalar session-bound CSRF token with `hash_equals`, an allowlisted method (`payhub`), current KYC and a bounded exact decimal amount (NGN 100.00–1,000,000.00, max two decimal places). Missing/array tokens and posted user/vendor/reference fields cannot bypass it. Authorization/CSRF failures return 403, input failures 422 and readiness failures 503.
- The planned amount is not a provider payment intent or settlement authorization. A standing account can receive a different amount; only independent provider evidence can determine the actual net wallet credit.
- Both PayHub and virtual-bank-display service checks use the authenticated customer's vendor ID explicitly. The page requires the existing deployment flag, full transactional storage readiness, the approved provider endpoint, an active/unblocked vendor, one active stored PayHub account and an unambiguous global account binding to the current customer. It uses the same unique enabled live merchant-key validation as settlement, with no cross-tenant/platform fallback.
- Read-only readiness checking is separated from the settlement transaction guard. Viewing instructions never begins/commits a transaction, runs DDL, calls a provider, updates a balance or changes receipt/claim records.
- No public/encryption/secret keys, merchant percentages, hidden customer PII or client-generated payment references are rendered. Bank names, account names, messages, CSRF and input values are escaped with quote/substitution handling. Unexpected SQL/provider/config exceptions map to a generic message, not raw error output.
- Responses are private/no-store with same-origin referrer policy. The form posts to a fixed same-origin path. JavaScript uses text-only status updates, handles decimal input/paste, shows validation feedback and prevents duplicate submissions briefly. Server controls remain authoritative; native submission works without JavaScript.

This is a scoped page review, not certification of the entire application or every linked page. In particular, `VirtualBanks.php` has separate legacy account-sync/manual-notification behavior and is not used as a shortcut by this form. Review that workflow separately before expanding funding methods.

## Operator actions / remaining work

- Deploy matching page/helper/script files to both editions as applicable. Production rollout was not executed here.
- Complete the paired PayHub/DataGifting migrations and controlled end-to-end acceptance before enabling automatic deposits. Do not enable the flag merely to make the button clickable.
- Verify stored bank ownership, unique live credential configuration, KYC state, provider host and provider-net fee policy. Check that real configured bank details appear only for their authenticated owner.
- Review/rotate any sensitive gateway encryption credential that was previously exposed to browser HTML, using the provider's approved rotation process. This page no longer outputs it; this work did not rotate live keys or audit every other page that may display them.
- Reconcile historical payments separately, with duplicate protection. Full card/hosted checkout requires a reviewed server-created payment-intent/initiation/settlement implementation and must not be restored through the old held handlers.

## Tests

```
node VTU/tests/harness/run-test.mjs tests/wallet_funding_page_test.php
node VTU/tests/harness/wallet-funding-browser.test.mjs
```

The PHP suite covers exact amount bounds, actor restrictions, CSRF arrays/mismatch, held gateway submissions, KYC expiry/refresh, escaping, no-store/controller wiring and edition parity. Chromium uses the actual PHP-rendered form and tests native POST/CSRF/named fields, visible bank/error focus, blocked state, invalid amounts, escaped details and responsive widths, with JavaScript ON and OFF. The browser's POST response is a fixture, not a real provider/database.

`payhub_deposits_mysql_test.php` additionally checks actual read-only instruction lookup on disposable native MySQL in both editions, with scoped service/config/bank checks and unchanged balances/receipts/caller transactions. Its provider verification remains mocked. These checks are not real-bank or production acceptance.

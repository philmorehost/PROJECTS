# Console history modal and loading fixes

## Root cause

Both shared footers (`func/bc-plate-app.php` and `func/bc-plate-admin.php`) printed `bcReceiptModal` after closing the content wrapper. Modal CSS is deliberately scoped to `.pk-app__wrap .modal`. The receipt therefore missed `position: fixed`, backdrop, centering and dialog styling. Setting `display:flex` on click could insert an ordinary block below the page, causing a small layout shift instead of a visible overlay. Increasing z-index alone would not solve this.

## Changes in both editions

- Put the footer receipt in its own `.pk-app__wrap` host, outside the history table's clipping/scrolling containers. Preserve initial inline `display:none` for standalone footers.
- Use the shell overlay directly, without invoking another Bootstrap modal/backdrop manager. Add dialog semantics, close via Escape/backdrop/buttons, keyboard focus containment/return and scrollable tall receipts.
- Abort superseded receipt requests and ignore stale responses, including those arriving after close. Display request/HTTP failures inside the overlay. Preserve the existing scoped receipt endpoints, provider-field authorization and PDF/image controls.
- Safely encode history-row JavaScript references and provide Enter/Space activation. Batch rows still open BatchDetails; no approval, refund or withdrawal behavior is changed.
- Stop loading three chart engines on Transactions pages that render no charts (about 1.7 MiB of uncompressed source per cold page load). Dashboard chart dependencies remain unchanged. Remove unused Quill styles on history pages, a duplicate footer editor load on SendMail (which initializes its own Quill), and the unused template contact-form validation script.
- Replace the whole-document search MutationObserver with one delegated input listener. Chat/cart/receipt updates no longer cause a full-document input scan. Dynamic search forms and debouncing remain supported; a manual submit cancels the pending automatic submission.
- Permit only one support-chat poll in flight, retaining hidden-tab suspension and closed-panel unread notifications. Chat is not disabled.

## Offline validation

Commands from repository root (install harness dependencies with `npm ci --prefix VTU/tests/harness`):

```sh
node VTU/tests/harness/receipt-modal.test.mjs
node VTU/tests/harness/console-search.test.mjs
node VTU/tests/harness/run-test.mjs tests/console_assets_test.php
node VTU/tests/harness/run-test.mjs tests/plate_app_test.php
node VTU/tests/harness/run-test.mjs tests/plate_admin_test.php
node VTU/tests/harness/run-test.mjs tests/security36_test.php
node VTU/tests/harness/run-test.mjs tests/gateway_verdict_test.php
```

Results: 96 receipt DOM assertions across four edition/shell combinations; delegated search tests pass in both editions; 22 console asset checks; existing shell suites (156 app, 731 admin), hidden-modal regressions (28), gateway/receipt suite (117) and landing41 pass. Fifteen changed/new PHP files parsed; modified shared JS passes Node syntax checking; `git diff --check` passes.

DOM tests execute the production receipt script and modal CSS selectors with mocked receipt responses, checking positioning scope, visibility, escaping, focus, errors, cancellation and out-of-order replies. They are not full browser/layout or production tests. No live database, logged-in production browser or actual PDF/image downloads were exercised; no end-to-end timing benchmark was taken.

## Deployment check

Deploy both PHP and JS changes and invalidate any JS/CDN caches. In the deployed user, admin and SAAS super-admin consoles, check Transactions, funding/payment orders, service-specific histories and batch details, including narrow screens and long receipts. Confirm close/reopen, fast row changes, print/PDF/image downloads and support-chat notifications. Inspect the browser Network panel for failed/blocked requests and compare cold/warm load timing. If remaining sluggishness is server-side, profile database queries and PHP response times separately rather than disabling security or chat.

# Customer web shell loading

## Confirmed source-level issues addressed

- The shell requested Google font CSS and remote icon CSS as render-blocking
  stylesheets. Optional Google font CSS now loads with a non-screen media query
  and switches on load; the existing system-font fallback remains usable. A
  noscript fallback retains typography when JavaScript is disabled. Icons use
  the already-shipped Bootstrap Icons 1.11.3 files and relative local font URLs.
- Every shell page fetched the alert library from a third-party CDN. The exact
  existing SweetAlert2 11.0.16 all-in-one distribution is now shipped locally
  with its MIT license under `assets-2/vendor/sweetalert2/`. No version upgrade.
- The shared helper script is now deferred, avoiding a parser pause at the
  footer. It retains its existing DOMContentLoaded initialization and globals.
- Flash messages previously ran immediately while SweetAlert was deferred.
  This could produce a synchronous browser `alert()` during document parsing
  (notably on dashboard status-message pages). Flash presentation now waits for
  DOMContentLoaded, after deferred scripts. If the local alert library fails,
  feedback renders as a dismissible in-page status notice, with receipt access
  retained. No native alert blocks parsing or requires dismissal to finish load.
- Corrected the shell base URL fallback's accidental variable-variable access
  (`$$_SERVER`) which could emit warnings and malformed asset URLs on pages
  without the configured host global.

Both SAAS and NON-SAAS share identical changes. Existing security/authentication,
PIN/biometric, KYC, financial holds, referral, chat and payment processing remain
unchanged. No server-side policy or security query was skipped or cached.

## Validation

```
npm ci --prefix VTU/tests/harness --ignore-scripts
node VTU/tests/harness/web-loading.test.mjs
node VTU/tests/harness/run-test.mjs tests/web_loading_test.php
node VTU/tests/harness/run-test.mjs tests/plate_app_test.php
node VTU/tests/harness/run-test.mjs tests/plate_admin_test.php
node VTU/tests/harness/run-test.mjs tests/surface_readability_test.php
```

The real Chromium test renders the actual PHP shell, serves real local assets,
and deliberately stalls all external requests. It verifies DOMContentLoaded,
visible usable controls, local feedback, receipt access, and absence of blocking
native alerts/script errors, including a deliberately failed local alert script.
It does not use arbitrary network timing as a production speed measurement.

## Rollout and remaining diagnosis

Deploy both the PHP shell and local alert assets, retaining their directory
structure. No database migration is required for this change. Normal cache
invalidation/OPcache reload should accompany PHP deployment.

This is an offline browser-path correction, not a measured production TTFB
improvement. There was no live authenticated access, production query profiling,
provider verification or deployment. If navigation still stalls before the HTML
response arrives, inspect server access logs, PHP slow logs and MySQL slow-query
logs for the exact affected routes. Existing bootstrap migrations, license
checks, account-policy queries, and page-specific provider calls were inspected
but not bypassed: changing those without live timing evidence risks weakening
security or financial behaviour. Page-specific third-party scripts and
administrator-injected scripts may still require route-specific profiling.

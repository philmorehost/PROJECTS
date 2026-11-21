# Independent public currency converter

The home page's former Send money calculator now displays **Currency converter**.
It is informational only, unrelated to the website's transfer quotes, fees,
settlement routes, availability switches, wallets, credentials or ledger.
The requested top-of-page illustration/security paragraph has been removed;
other existing page content and legal/service terms remain unchanged.

## Source and currency list

Source: https://github.com/fawazahmed0/exchange-api

The upstream README and repository metadata were checked via GitHub: the project
is CC0-1.0 and publishes daily currency data with these documented mirrors:

- `https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/usd.min.json`
- `https://latest.currency-api.pages.dev/v1/currencies/usd.min.json`

The browser fetches only a public USD reference table. Amounts and selected pairs
are NOT sent to either source. Requests omit credentials and referrers. No
website `/web/`, exchange-rate, quote, payment or authenticated API endpoint is
used. No server-side proxy, API key or transaction provider setting is needed.

Supported choices are built from positive finite rates present in the fetched
table, filtered to browser-recognized ISO fiat currencies (with an ISO fallback
list for older browsers). Names come from Intl.DisplayNames where available;
otherwise currency codes remain usable. Cryptocurrencies are not listed as fiat.
The default is GBP to NGN when both exist; source changes update the options.
Cross-rates are calculated locally from the same dated USD table.

## Honest freshness and failure behaviour

"Latest" is the source's latest DAILY reference data, not streaming FX. The
actual source date is visible; data older than three days is labelled Older data.
The card is expressly not a transaction quote and cannot execute payments. Fee,
arrival-time and send-payment controls were removed from the calculator.

A successful table is kept in page memory. Typing, choosing currencies and
swapping calculate immediately without network calls. Refresh requests new data;
a visible page checks every 15 minutes and after returning from a stale tab.
Each mirror has a six-second timeout. Failed or invalid primary responses try
the second mirror. Refresh clears prior results immediately; a full outage shows
no number, disables currency selection and offers retry. There are no sample
rates disguised as live data and no persistent rate cache.

The home script URL has a version suffix so browsers fetch the new independent
implementation rather than an old tenant-gated calculator.

## Validation

```
node VTU/tests/harness/public-home.test.mjs
node VTU/tests/harness/public-converter-browser.test.mjs
node VTU/tests/harness/run-test.mjs tests/public_home_test.php
node VTU/tests/harness/run-test.mjs tests/public_landing_links_test.php
node VTU/tests/harness/run-test.mjs tests/dynamic_theme_test.php
```

Tests cover both edition scripts; source success, generated currency choices,
conversion, same-currency/swap, empty/negative/zero/overflow amounts, malformed
responses, mirror fallback, outage clearing/retry, stale-date labels and request
privacy/isolation. Chromium renders the real home at 390px and 1280px, checks card
layout and interacts with its actual controls using a stubbed upstream response.
These are offline fixture tests, not a live CDN availability/latency guarantee.
The sandbox cannot directly reach the production CDN hosts. Upstream API format
was verified from its published GitHub documentation, not a production transaction.

Deploy the home PHP and `jsfile/bc-public-home.js` together in the installed
edition. Both editions have identical home/converter source. No financial
service holds, security checks, provider integrations, balances or rates used
for actual transactions were modified. No deployment was performed here.

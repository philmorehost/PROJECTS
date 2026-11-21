# Public home and Freelancers page

## Routing and content

Both editions now render the supplied `Mzeevtu — Home.html` design through `index-bc-style-template-home.php` at `/`. The saved design's rendered HTML, inline illustrations and styling are retained, but local-file URLs, missing React/editor dependencies and editor scripts are not shipped. The original canvas depended on that missing runtime; it is replaced with a lightweight global-connections SVG. Decorative examples are labelled as examples, rather than live balances or promised coverage.

`/Freelancers.php` reuses the existing index host-resolution/bootstrap code and renders `index-freelancers-template.php`. That template preserves the previous template-1 content exactly, with a shared link directory added to its footer. Its existing vendor settings, service gates, live-FX gate and signup flow are retained. App force/prompt redirects are not applied to this informational page.

The main index now deliberately selects the new home regardless of previously selected landing template. Template-1 previews delegate to it. Templates 2/3/6 remain in the repository and have the shared footer directory, but are no longer selected for the main index. No database template settings are rewritten.

The new home uses the saved site name, existing host-logo convention and SEO/analytics renderer. Existing anonymous app redirect policy remains unchanged on `/`.

## Destinations

New dedicated pages: BillsAndAirtime, MultiCurrencyWallet, Savings, Business, PaymentLinks, Invoices, Payouts, AboutUs, Careers, Contact, CookiePolicy, POSTerminals, Tuition and DownloadApp (all `.php` at the site root).

International transfers, cards and gift cards retain their existing dedicated service pages. Blog, Privacy, Terms, services, referrals, coin conversion, login, registration and account deletion retain their existing routes. Both the new home and previous public templates/service/referral/legal pages expose the combined directory. Public marketing links do not enable or bypass paused financial workflows.

## App downloads

All home download/store buttons lead to `/DownloadApp.php`. Its official-download button uses the current tenant's `sas_site_details.apk_download_url`, accepting HTTPS URLs or site-root-relative URLs and rejecting unsafe schemes, protocol-relative URLs, credentials and control characters. It does not use another tenant's download or invent store listings.

If no valid URL is configured, the page clearly says so and provides browser login/support options. There is no supplied or existing dedicated iOS store URL to publish: App Store/Google Play availability must be confirmed by the operator. Configure the official published destination in the existing site settings before deployment; do not use the billing add-on `DownloadService.php` as a consumer app link.

## Currency quotes and availability

The exported frozen rate is removed. Public FX requests are allowed only if the vendor's currency-conversion service and API key are configured, matching the previous landing gate. The client uses `/web/api/exchange-rate.php`, debounces edits, invalidates old replies and never substitutes a sample rate on failure. Final fees, eligibility and settlement estimates must be reviewed in the authenticated workflow. No provider integration or financial hold was altered.

## Validation

- New public-home/page suite: **2,900 checks**.
- Existing rendered landing-link suite, extended automatically by the shared directory: **4,217 checks**; all rendered local destinations checked in both editions.
- Public FX JavaScript DOM tests pass in both editions (disabled gate, valid rate, invalid/missing rate, stale replies, swap and empty amount).
- Existing landing41, backoffice regression (184 checks) and deletion43 suites pass. Template-specific assertions now inspect the preserved Freelancers template; legal-link assertions reflect the already-existing root-relative URLs.
- Original freelancer content compared byte-for-byte against the branch base after removing only the added footer directory.
- PHP parsing and JS syntax/diff checks passed during implementation.

Two older broad suites still fail on the same assertions as an independently extracted, unmodified branch baseline: upgrade25 has eight referral/held-virtual-card expectations; brandemail46 has six mail/held-funding expectations. Those unrelated source paths were not changed or financial holds reopened to satisfy stale tests.

No production deployment, live database test, store listing validation or full browser/device visual test has been performed. Deploy the new root pages, helpers, template and JS together; clear public-page/CDN caches, verify the tenant branding and configured app destination, and check mobile navigation plus the Freelancers and all footer routes.

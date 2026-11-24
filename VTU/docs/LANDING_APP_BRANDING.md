# Tenant landing-page branding and Android download badges

Both editions retain their distinct Home/Template 1, 2, 3 and 6 layouts.

- The card and phone illustrations on Home/Template 1 use the first whitespace-separated word of the configured site title (Unicode whitespace supported). Full site names remain in headings/navigation. Card labels are escaped and visually ellipsized for a very long single-word brand.
- Template 1 CSS adds scoped card sizing, narrow-screen spacing, focus styling and reduced-motion support. No external badge image or icon font is required.
- The shared badge reads the current tenant's `android_app_url`, using the existing tenant-setting reader when available. The previously supported `apk_download_url` is a fallback when the modern setting is empty. No vendor ID means no tenant download badge. Existing template settings caches retain their normal lifetime.
- A valid HTTPS `play.google.com/store/apps/details?id=package.name` destination shows the Google Play triangle and label. Other HTTPS or safe site-relative destinations use an Android download label, not a false store listing claim. HTTP-only, credential-bearing, unsafe, invalid and empty URLs do not produce a badge. Credentials and control characters are rejected; destinations and labels are HTML-escaped.
- Home's unconditional placeholder App Store/Google Play links are replaced with the conditional badge in its download section. Other designs receive the badge in their footer, preserving their existing hero CTAs.

## Checks

```
node VTU/tests/harness/run-test.mjs tests/public_app_brand_test.php
node VTU/tests/harness/public-navigation-browser.test.mjs
```

The contract suite covers both editions, short-brand extraction/escaping wiring, valid store versus direct-download labels, unsafe URLs, unset settings and legacy-setting fallback. Chromium checks navigation and card-brand overflow at 320–1440px, keyboard interaction, reduced motion and no-JavaScript navigation. No store publishing, app release, deployment, provider activation or financial changes are included.

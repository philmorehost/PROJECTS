# Centralised website/mobile branding

## Operator workflow

1. Deploy the changes to the matching SAAS or NON-SAAS website, including `func/bc-theme.php` and `web/api/theme.php`.
2. Build/install the updated DG7, JIKABIZ and MZEEVTU Android/iOS sources once. Existing installed binaries cannot gain this feature without that initial update.
3. In **bc-admin → Account Settings → Theme**, save the primary colour normally. The existing `sas_vendor_style_templates.primary_color` column remains the source of truth; no separate mobile-brand setting or new database migration is needed.
4. Updated apps refresh on launch/resume and at most once every 60 seconds while active. Subsequent colour changes do not need a new binary. Offline apps keep the last valid palette for their configured platform origin.

## API contract

`GET /web/api/theme.php` is a public, read-only endpoint returning `status: success` and `data` containing:

- `schema_version: 1`
- `version`: deterministic SHA-256 content version
- `primary_color`: canonical six-digit hex value
- `refresh_after_seconds: 60`
- `modes.light` and `modes.dark`, each with `primary`, `on_primary`, `primary_text`, `background`, `surface`, `on_surface`, and `outline`.

The endpoint resolves only the active vendor matching the request host (normalising `www` and a port). It uses a bound lookup; a query parameter, app-supplied vendor ID, session override or first-vendor fallback cannot select another tenant. Unknown hosts return 404. Only GET is allowed. Responses are marked `no-store`; the API reads the database on each refresh. It returns no credentials or account information. The existing site-info response also exposes the shared palette for compatible clients.

The theme setting accepts only `#RRGGBB`. Invalid historical settings produce a safe default, not CSS/JavaScript from stored input. Invalid colours are not saved by the updated admin theme form.

## Contrast and colour roles

The exact primary colour is retained for filled controls. On-primary text/icons use whichever of pure black/white provides greater WCAG relative-luminance contrast. On neutral light/dark surfaces, primary text/icons use a minimally adjusted brand variant with at least **4.5:1** contrast against both palette surfaces. Boundaries meet at least **3:1**; surface foregrounds meet **4.5:1**.

This is intentionally not a blanket replacement of every colour: transaction status colours, illustrations, photographs and external brand logos have meanings of their own. Accessible roles, not the raw primary hex, drive brand buttons, links and icons. The same contrast helper now feeds website console/auth/public landing controls, the new home and the preserved Freelancers buttons. Incompatible brand gradients were replaced with a solid fill where they could invalidate foreground contrast.

## Android implementation

- `util/BrandTheme.kt` is installed in each existing `DGApp`; no new dependency is needed beyond the existing Material/OkHttp/Core libraries.
- An independent HTTPS GET client sends no bearer/PIN credentials, does not follow redirects, has an eight-second timeout and permits only one refresh at a time.
- Valid palettes are cached in public, origin-keyed preferences, separate from secure login storage. Malformed responses, unsupported schema, invalid colours or inadequate contrast do not replace the cache.
- Activity lifecycle/layout integration applies semantic XML roles to existing/new screen views without recreating the account session or purchase flow. Per-view signatures avoid repeatedly writing unchanged styles. Destroyed activity listeners are removed; foreground timers stop when no activity is resumed.
- Programmatic dashboard, meter/print/gift-card selection controls, refresh indicators and receipt UI use the manager. Navigation icons and labels have separate foreground roles for the selected indicator. System-bar icon appearance follows its surface. Receipt PDF ink uses the light palette because paper remains white even in dark mode.
- Resource colours remain boot/offline seeds, not the source of the tenant brand after configuration arrives. A cached valid palette is available before network refresh.

## iOS implementation

- The shared observable `BrandTheme` is appended to each existing `AppNetworkService.swift`, so it does not depend on adding a new file to an Xcode target.
- Root apps observe the palette and refresh only while active. Brand-bearing views observe updates, use adaptive light/dark colour providers and consume distinct fill/foreground roles.
- HTTPS responses are validated for origin, schema, hex format and contrast; invalid/failed requests retain the origin-keyed last-known-good document. No account credentials or cookies are attached to the public request.
- Authentication, session/PIN, biometrics, KYC, rewards and purchasing code paths have not been replaced by the theme feature.

## Validation performed

- `node VTU/tests/harness/run-test.mjs tests/dynamic_theme_test.php`: **45,176 checks** across 4,096 RGB samples plus boundary colours, invalid inputs, versioning and endpoint/source invariants.
- `python3 VTU/tests/dynamic_theme_mobile_test.py`: **1,829 source/XML checks**, including all 186 Android layouts, lifecycle hooks, public-request separation and implementation parity across brands.
- PHP parser checks passed for the changed/new PHP files during implementation.
- Existing app/admin shell tests (156/731), public home (2,900), landing links (4,217), mobile redesign (76), landing41 and backoffice regression (184) passed.
- Git whitespace/diff checks passed.

These are offline tests and source checks, **not Android SDK builds, Xcode builds, device screenshots or live database/provider tests**. No production deployment has occurred.

## Required deployment/device checks

Verify that each app build points at its intended HTTPS platform origin. On a staging copy, save white, black, yellow, green, mid-grey and dark-blue primaries; check a fresh install, a warm cached launch, a foreground refresh, an offline launch and light/dark mode. Exercise login, PIN/biometrics, home actions, navigation, selected/unselected controls, dialogs, receipts/PDFs, service forms and web portals. Check text scaling and platform-specific widgets on supported OS versions. Validate unknown-host isolation and DB failures against a real server. Existing financial holds must stay in place; theming does not certify or re-enable financial integrations.

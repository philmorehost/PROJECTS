# Mobile required setup and splash branding

## Coverage

PR193 already enforces Google Authenticator enrolment policy on the backend: app-token resolvers, login eligibility, API-key adapters, and authenticated legacy app adapters refuse access while required setup is incomplete. This remains the security boundary; a modified or old client cannot bypass it.

All six shipped native source projects (DG7, JIKABIZ, MZEEVTU, Android and iOS) now also handle `AUTHENTICATOR_SETUP_REQUIRED` and `PASSWORD_CHANGE_REQUIRED` explicitly:

- Common API response paths detect the structured response before normal decoding. Android peeks at a bounded response copy, preserving the body for existing callers.
- The app persists a non-secret, tenant-origin-scoped setup block and invalidates its local app-session credential. PIN settings, biometrics and KYC are not removed.
- A dedicated blocking screen replaces/clears the dashboard navigation. It opens the configured trusted HTTPS website's security setup page in the browser, never a response-supplied URL and never with app credentials in the URL.
- After website completion, the customer returns to sign-in and authenticates again; pressing retry does not mark server setup complete. Incomplete setup is refused again by the server.
- Returning sessions are checked using profile before opening cached dashboard UI at startup. Android performs this in parallel with its existing branding request, bounded to five seconds. iOS also checks on foreground without duplicate concurrent checks. Subsequent authenticated requests still enforce policy server-side. An offline client cannot discover new remote policy changes until it reconnects; no server operation can complete offline.

## Saved splash colour

Android paints its activity splash immediately from the existing validated, origin-scoped public theme cache. It also accepts the full validated palette from the existing site-info response, so fresh branding can repaint the splash without an additional colour-only request. The splash window, status/navigation bars and canvas use the saved `primary`; all splash text uses the contrast-safe `on_primary` instead of hardcoded blue/white tints. Normal non-splash screens keep their surface-coloured bars.

iOS has an in-app startup view observing the existing cached/live BrandTheme. Its full-bleed background is website `primary`, with `onPrimary` text/progress contrast, including dark mode.

The operating-system launch/starting window exists before application code runs and cannot read remote or application preferences. Its bundled fallback remains. A first-ever offline launch also cannot know an unfetched website colour. Runtime startup/splash views use the last known good website colour; normal live public-theme refreshes pick up saved changes. No artificial network delay is added solely to wait for a colour.

## Validation

- 113 mobile required-setup/splash source and Android XML checks.
- 1,838 existing mobile dynamic-theme source/XML checks.
- 138 existing mobile structural checks and 19 mobile login-error checks.
- 122 backend policy/enrolment checks and both real PHP API side-effect-blocking fixtures pass.

These are source/XML and PHP regression tests, not native compilation or device verification. Android/iOS SDK toolchains were unavailable in this environment. Build all affected apps locally and test returning sessions, policy enable/disable, website setup/re-login, light/dark saved colours, and offline cached startup. No APK/IPA was generated and no production deployment was performed.

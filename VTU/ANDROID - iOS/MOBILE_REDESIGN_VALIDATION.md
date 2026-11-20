# Mobile dashboard and email refresh — validation status

Date: 2026-11-18. Source changes only; not deployed or released.

## Implemented

- DG7, JIKABIZ and MZEEVTU Android/iOS dashboards use orange/black styling, actual wallet balances, existing-wallet currency selection, hide-balance control, hero actions and categorized services.
- Send exposes local/global transfer options; Receive creates a shareable NGN Flutterwave payment request; Topup opens funding. Crypto, Virtual Card, Gift Card and VTU shortcuts replace summary tiles. Transaction history replaces the spending card.
- Existing native forms remain routed where available. Other services use a short-lived, single-use POST handoff to the tenant website. Server checks tenant, account and service availability; the web session does not bypass transaction policy/PIN/KYC enforcement.
- PDF/image receipt sharing, referral, rewards, KYC, support, PIN and existing security routes remain available. Native device behavior has not been verified.
- FX calculator fetches fresh indicative quotes and fails closed without a fresh quote. It does **not** execute currency exchange or create new currency wallets.
- Central email transports normalize HTML documents and fragments into the current branded shell; cron notification bypasses were updated. Existing OTP/receipt content is retained.
- DG7 iOS default API origin is aligned with DG7 Android (`https://v6.datagifting.com.ng/`). All iOS builds can override it via a build-time HTTPS `PlatformBaseURL` Info.plist value. Confirm the deployed DG7 hostname before shipping.

## Checks performed

- All modified/new PHP files syntax-checked using the PHP-WASM harness.
- `tests/mobile_redesign_test.php`: 76 checks pass (catalogue allowlist, invalid FX input, email normalization, source-level security assertions).
- Existing `brandemail46_test.php`: ALL PASS.
- Existing `fintech_gate_test.php`: 1,066 checks pass.
- Existing `fintech_policy_test.php`: 70 checks pass.
- `tools/check-mobile-redesign.py`: 138 structural checks pass.
- Tree-sitter parsed 24 changed Kotlin/Swift files without grammar errors. This is **not** native compilation or type checking.
- `git diff --check` passes.

## Required before release

1. Deploy matching PHP files from the chosen edition to each tenant; confirm schema prerequisites and enabled services/provider credentials.
2. Compile all six apps using the existing Android/Xcode workflow. Android SDK and Xcode were unavailable in this workspace. Confirm Swift constructors/types, minimum OS behavior, signing and real-device layout. Versions have not been bumped; allocate fresh store build numbers before publishing.
3. Device-test login/PIN/OTP, foreground/background biometric locking, logout, account restrictions and session expiry; exercise uploads and external HTTPS/mailto links within the portal.
4. Verify every service against its live provider and account/tier entitlements. Existing international-transfer request recording is not proof of settlement; retired/unconfigured crypto execution is not made functional by dashboard routing. Do not claim every service works based only on these checks.
5. Test an invoice end to end using Flutterwave test credentials, then a controlled live payment: link creation, payer email sharing, valid webhook credit, duplicate webhook idempotence and rejected forged callbacks. Return URLs never credit funds. This is a payment link, not a tax-invoice/accounting subsystem.
6. Test multi-currency balances against ledger records. FX conversion is display-only; no real wallet exchange is implemented.
7. Verify filtered full history, CSV reports and PDF/PNG sharing with real transaction payloads; inspect new email layouts in Gmail/Outlook and verify queued mail branding across tenants.
8. Compare all six device screenshots against the requested design and check accessibility, keyboard and small screens. No pixel-match/device screenshot claim is made.

Source changes require app rebuilds plus backend deployment. No deployment, store upload, native artifact generation or live-provider certification was performed.

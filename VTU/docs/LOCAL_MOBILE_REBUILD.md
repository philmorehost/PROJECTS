# Current mobile rebuild and acceptance guide

## Decision

**Local debug/simulator builds: proceed for testing. Production/store release: not cleared.**

PR #278 was merged at `a83954429b7c1f3b9acf86bfa519e5679cc49912`. Its application source head was `3a7162e6cc8a8368fbf9bcbfc21bacbc49fcc907`. This guide supersedes readiness/signing/version claims in older `ANDROID - iOS/REBUILD_OR_NOT.md` notes; those notes describe historical builds, not current production approval.

A fresh **debug-only** native build was requested for the source head above: https://github.com/philmorehost/PROJECTS/actions/runs/45361015057 . Check the actual job results/artifacts; requesting a run is not evidence of success. This request does not build signed Android releases, archive/sign iOS for devices, contact payment providers or publish apps.

## Critical: debug does not mean staging

The checked-in defaults point at LIVE origins:

| Brand | Default Android and iOS origin |
|---|---|
| DG7 | `https://v6.datagifting.com.ng/` |
| JIKABIZ | `https://jikabiz.com/` |
| MZEEVTU | `https://app.mzeevtu.com/` |

**Do not install and transact assuming a debug build uses a sandbox.** CI compilation does not launch the app or validate its backend.

For isolated functional testing, use an approved HTTPS staging installation with test identities and matching backend source/migrations. In a local testing copy:

- Android: set the brand's `app/src/main/java/<package>/util/Constants.kt` `BASE_URL` to the approved staging URL with a trailing slash. Packages are `com/datagifting/app`, `com/jikabiz/app`, and `com/mzeevtu`. There is no automatic debug-only staging override in these projects.
- iOS: `AppNetworkService` reads `PlatformBaseURL` from the built Info.plist. In the local XcodeGen target settings, set `INFOPLIST_KEY_PlatformBaseURL` to the approved HTTPS staging root, then regenerate the project. An absent/invalid override falls back to the live default; inspect the BUILT Info.plist and verify destination traffic before using credentials. Do not invent a hostname or use a production identity as a staging fixture.
- Keep production origin configuration unchanged in release source. Review local diffs before publishing any build. Do not weaken certificate validation, add HTTP exceptions, or introduce an arbitrary runtime URL picker.
- Use an emulator/simulator, spare device or isolated test profile. Debug packages retain the production application IDs. Do not uninstall an existing production installation merely to bypass a signing conflict: that can destroy local evidence/session state. iOS Keychain state may survive uninstall; verify account/environment isolation explicitly.

## Android: local debug builds

Prerequisites: Git, JDK **17**, Android SDK platform **36**, build-tools **36.0.0**, and SDK licenses accepted. Configure `ANDROID_HOME`/`ANDROID_SDK_ROOT` or the project's ignored `local.properties` as appropriate for your workstation. Do not commit local SDK paths or credentials.

From the repository root in PowerShell:

```powershell
$root = (Get-Location).Path
foreach ($brand in @('DG7', 'JIKABIZ', 'MZEEVTU')) {
    Push-Location (Join-Path $root "VTU/ANDROID - iOS/$brand-Android")
    try {
        .\gradlew.bat --no-daemon :app:assembleDebug
        if ($LASTEXITCODE -ne 0) { throw "$brand debug build failed" }
    } finally { Pop-Location }
}
```

On macOS/Linux, for one brand:

```sh
cd "VTU/ANDROID - iOS/DG7-Android"
bash ./gradlew --no-daemon :app:assembleDebug
```

Repeat in `JIKABIZ-Android` and `MZEEVTU-Android`. The output is normally `app/build/outputs/apk/debug/app-debug.apk` within each project. **No release keystore is needed for assembleDebug.** Never distribute these APKs as store release artifacts.

## iOS: local simulator build (macOS only)

Install/select Xcode with the iOS simulator SDK, accept its license, and install XcodeGen. The source targets iOS 15 or later; use a compatible current toolchain.

```sh
cd "VTU/ANDROID - iOS/DG7-iOS"
xcodegen generate --spec project.yml
xcodebuild -project DG7-iOS.xcodeproj -scheme DG7-iOS \
  -sdk iphonesimulator -configuration Debug \
  -destination 'generic/platform=iOS Simulator' \
  -derivedDataPath /tmp/DG7-simulator-build CODE_SIGNING_ALLOWED=NO build
```

Other directories/schemes: `JIKABIZ-iOS` / `JIKABIZ-iOS`, and `MZEEVTU-iOS` / `MZEEVTU-iOS`. Simulator `.app` products are not installable device IPAs. Device/archive builds require the appropriate Apple team, provisioning and signing; disabling signing is not a device-release workaround.

## Source versions are NOT store availability

Values inspected in the current source (not queried from either store):

| Brand | Android versionName / versionCode | iOS marketing / build |
|---|---|---|
| DG7 | 1.0.12 / 13 | 1.0.7 / 8 |
| JIKABIZ | 1.0.12 / 13 | 1.0.6 / 7 |
| MZEEVTU | 1.0.18 / 19 | 1.0.12 / 13 |

Android and iOS versions are not currently in lockstep. Before any store upload, independently inspect the highest uploaded build/version in each store, including internal/closed tracks and TestFlight. Select an unused greater build number. Do not assume a historical document's "next version" is still available. This work does not bump versions, resolve upload-key resets, rotate signing keys or validate store ownership. Reuse the accepted signing identity; never generate a replacement key just to make a release task pass.

## Device acceptance record (each brand and each platform)

Record source SHA, build mode/version, device/OS, backend origin/revision/migrations, test account, expected/actual result and a non-sensitive evidence location. Leave untested items PENDING, not PASS. No real secrets, KYC evidence, full bank data or customer dumps belong in Git/chat.

- [ ] Verify the actual backend origin before login; confirm HTTPS and tenant/brand identity.
- [ ] Login/password/OTP, required setup, security question/PIN/biometric lock, session expiry/revocation, logout and re-login. Old session material must not be reused across environments.
- [ ] KYC live photo/video: permissions, denied permission, cancel/retake, size/length limits, interrupted upload and retry. Confirm server acknowledgments before local capture deletion. Physical camera testing is mandatory; simulator success is insufficient.
- [ ] Deploy backend KYC acknowledgment support and patched media-inspection dependencies before relying on new app behavior. See `IOS_KYC_CAPTURE_ACCEPTANCE.md`, `NATIVE_KYC_VIDEO_ACCEPTANCE.md`, and `SERVER_VIDEO_EVIDENCE.md`.
- [ ] Dashboard balance/history, pending versus successful transaction presentation, receipt share/export, offline/timeouts and app restart. Do not perform money-moving service acceptance without separate authorization.
- [ ] Mobile browser/service portal: correct tenant, login/session/HTTPS restrictions, no certificate bypass, no cross-tenant account information.
- [ ] Funding shows the correct availability/hold states. `web/Fund.php` supports existing approved PayHub bank-transfer instructions only; card/other hosted checkout remains held. No button/receipt should claim that viewing bank details charged or credited money.
- [ ] Upgrade/migration on an isolated device from an approved prior test build; verify session handling, permissions and Keychain/encrypted-preference behavior. Preserve the live user's installation/evidence.
- [ ] Review crash logs and captured evidence for credentials/PII before sharing diagnostics.

## Backend and release blockers (remain separate from rebuilding)

- Follow `security/PAYHUB_DEPOSIT_ROLLOUT.md` for the paired migrations, matching provider/backend code, verified bank ownership, unique live merchant configuration, provider-net fee policy, authorized end-to-end acceptance and explicit activation. `BC_PAYHUB_DEPOSITS_ENABLED` stays off until that acceptance. CI with mocked provider verification is not bank acceptance.
- Reconcile already-paid customer obligations with independent evidence and duplicate protection. No blind webhook replay, synthetic intent or direct SQL balance increment.
- `security/WALLET_FUND_PAGE_REPAIR.md` documents remaining hosted/card checkout work and review/rotation of any previously browser-exposed sensitive encryption credential.
- Complete current signing, physical-device, security/compliance, provider/reconciliation and operational rollout gates. Unrelated financial holds remain. Bybit staging stays cancelled.

No deployment, production migration, provider activation, historical credit, signed release or store publication is performed by these build instructions.

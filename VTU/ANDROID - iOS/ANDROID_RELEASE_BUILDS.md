# Android release APK/AAB builds

The `Build Android and iOS apps` GitHub Actions workflow builds release-signed Android APK and AAB files for DG7, JIKABIZ, and MZEEVTU. Release builds run on pushes to this mobile-app tree and can be started manually from **Actions → Build Android and iOS apps → Run workflow**, selecting `release` (the default).

## Required GitHub Actions secrets

Add these under **Repository Settings → Secrets and variables → Actions → New repository secret**. Each app must use its existing Play Store signing key; do not generate a replacement key for an app that has already been published.

| App | Keystore (base64) | Store password | Key password | Alias |
| --- | --- | --- | --- | --- |
| DG7 | `DG7_KEYSTORE_BASE64` | `DG7_KEYSTORE_PASSWORD` | `DG7_KEY_PASSWORD` | `philmore-datagifting` |
| JIKABIZ | `JIKABIZ_KEYSTORE_BASE64` | `JIKABIZ_KEYSTORE_PASSWORD` | `JIKABIZ_KEY_PASSWORD` | `philmore-jikabiz` |
| MZEEVTU | `MZEEVTU_KEYSTORE_BASE64` | `MZEEVTU_STORE_PASSWORD` | `MZEEVTU_KEY_PASSWORD` | `philmore-mzeevtu` |

The `*_KEYSTORE_BASE64` value is the **single-line base64 encoding of the app's existing `.jks`/`.keystore` file**. Keep all passwords and encoded signing material in Actions secrets, never in source code, workflow YAML, artifacts, or chat. A release job checks that its required secrets exist before Gradle runs and fails with the missing secret names if not.

## Downloaded artifacts

Each successful release job verifies the signatures and uploads two 30-day artifacts:

- `<APP>-android-release-apk`
- `<APP>-android-release-aab`

Push and pull-request builds continue to produce debug APKs; pull requests do not receive signing secrets or run release jobs. Manual workflow runs can select `debug`, `release`, or `both`.

The workflow rebuilds the version codes already defined in each app's Gradle file; it does not increment Play Store version codes or upload releases to Google Play.

## Version codes must be bumped for every release

Play permanently consumes a version code once it is uploaded, so every new build needs a fresh one
in `app/build.gradle`. Highest codes already consumed and the current builds:

| App | Current build | Next code to use |
| --- | --- | --- |
| DG7 | `1.0.14` / `versionCode 15` | 16 |
| JIKABIZ | `1.0.14` / `versionCode 15` | 16 |
| MZEEVTU | `1.0.20` / `versionCode 21` | 22 |

## Self-updating (weekly Play in-app updates)

Each app ships `util/AppUpdateChecker.kt`, called from `MainActivity`. It uses the Play In-App
Updates API (`com.google.android.play:app-update:2.1.0`) and asks Play for a newer version of its own
package at most once every 7 days (timestamp in `SharedPreferences` under `last_update_check_at`).
When Play reports one, the app opens Play's update flow: `IMMEDIATE` if Play marks the update as
high-priority, otherwise `FLEXIBLE`. `onResume` re-arms an interrupted `IMMEDIATE` update.

No Firebase, no APK hosting, and no server-side version manifest are involved - publishing the AAB
is the only step needed to make the prompt appear. Notes:

- Only Play-installed copies self-update; sideloaded APKs report `UPDATE_NOT_AVAILABLE` and keep
  using `web/api/app-update.php`.
- `REQUEST_INSTALL_PACKAGES` is deliberately not declared; the app never installs anything itself.
- The mechanism only exists from these builds onward, so anything older than `vc12`/`vc18` will
  never prompt and must be updated once manually.

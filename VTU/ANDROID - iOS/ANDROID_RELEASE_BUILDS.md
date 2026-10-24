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

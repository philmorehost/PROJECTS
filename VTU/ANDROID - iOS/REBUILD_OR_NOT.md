# Do the apps need rebuilding?

Short answer, by concern:

| Concern | Rebuild needed? | Why |
| --- | --- | --- |
| **Refer and Earn HTTP 500** | **No** | Fixed entirely in PHP: `web/api/referral.php` and `func/bc-tables.php`. The apps only call the endpoint, and their code was already correct and identical across DG7/JIKABIZ/MZEEVTU. Deploy the PHP and the screen loads. |
| **16 KB page size error on Play** | Already done | The published-candidate AABs are built with AGP 8.7.3 + `useLegacyPackaging = false`. See below — this fix had been lost from the repo and was restored. |
| **Version codes** | Already done | DG7 `8`, JIKABIZ `7`, MZEEVTU `13`. |
| **MZEEVTU / JIKABIZ signing** | **Blocked on Play** | Their upload-key resets are not approved yet. No rebuild helps until Play accepts the key. |
| **DG7 signing** | Nothing to do | DG7's key is already accepted by Play (the vc 7 upload passed the signing check). |

So: **the referral fix needs no app release.** Upload the two PHP files and the screen works.

---

## This fix had been lost from the repository

When the app-side work was first done it was never committed, and the working tree later reverted to
the pre-fix state. The result was a repository that produced bundles Play rejects:

| App | versionCode in repo | AGP | 16 KB packaging |
| --- | --- | --- | --- |
| DG7 | 4 | 8.1.1 | missing |
| JIKABIZ | 4 | 8.1.1 | missing |
| MZEEVTU | 10 | 8.1.1 | missing |

AGP below 8.5.1 packs native libraries at 4/8/12 KB offsets, which is what triggered

> Your app does not support 16 KB memory page sizes

Measured on the old configuration:

```
lib/arm64-v8a/libPhoenixAndroid.so        (BAD - 12288)
lib/armeabi-v7a/libcffa.so                (BAD - 4096)
lib/x86_64/libmlkitcommonpipeline.so      (BAD - 8192)
Verification FAILED
```

### Restored in commit `b9bf1fc` (now on `main` and on the cPanel branch)

* AGP `8.1.1` -> **`8.7.3`** (aligns uncompressed native libraries to 16 KB)
* removed the obsolete `resolutionStrategy.force aapt2:8.1.0` override and `android.aapt2Version`
* added

  ```groovy
  packaging {
      jniLibs {
          useLegacyPackaging = false
      }
  }
  ```

* versionCode/versionName: DG7 **8 / 1.0.7**, JIKABIZ **7 / 1.0.6**, MZEEVTU **13 / 1.0.12**
  (iOS `CURRENT_PROJECT_VERSION` / `MARKETING_VERSION` kept in lockstep)
* unified release signing: `key.properties` -> `KEYSTORE_FILE`/`KEYSTORE_BASE64` -> `keystore/*.jks`,
  with a hand-rolled properties parser because `java.util.Properties` treats `\` as an escape and
  silently destroys Windows paths like `C:\keys\app.jks`
* fail-fast `gradle.taskGraph.whenReady` guard: no release build without a real keystore, so a
  debug-signed bundle can never be produced by accident

### Verified after restoring

| App | versionCode | versionName | 16 KB | signed | AAB |
| --- | --- | --- | --- | --- | --- |
| DG7 | 8 | 1.0.7 | OK | OK | 38.1 MB |
| JIKABIZ | 7 | 1.0.6 | OK | OK | 38 MB |
| MZEEVTU | 13 | 1.0.12 | OK | OK | 35.7 MB |

All three were rebuilt from the restored sources; `zipalign -c -P 16` and `apksigner verify` both
exit 0.

---

## Checking a build before uploading

```powershell
cd "VTU\ANDROID - iOS"

# signing certificate + 16 KB alignment in one pass
.\tools\verify-play-signing.ps1 -Apk ".\release-output\DG7\DG7-1.0.7-vc8-release.apk"

# hard check against the fingerprint Play expects
.\tools\verify-play-signing.ps1 -Bundle ".\release-output\DG7\DG7-1.0.7-vc8-release.aab" `
    -ExpectedSha1 "1E:92:6C:07:B9:81:A7:31:4F:F6:BE:03:87:91:C9:F9:95:57:40:0E"
```

Expected tail: `MATCH : signed with the expected Play certificate` followed by
`Alignment: OK - every native library is 16 KB aligned`.

## Rebuilding if you ever need to

```powershell
cd "VTU\ANDROID - iOS\DG7-Android"
.\gradlew.bat :app:assembleRelease :app:bundleRelease
```

Requires `key.properties` at the project root (git-ignored) or the `KEYSTORE_*` environment
variables; the build stops with an explanatory message if neither is present.

## Known non-blocking Play warnings

| Warning | Action |
| --- | --- |
| No deobfuscation file | Only relevant with R8. MZEEVTU uses it: upload `app/build/outputs/mapping/release/mapping.txt`. DG7/JIKABIZ use `minifyEnabled false`, so ignore. |
| No native debug symbols | The `.so` files come from vendor AARs (VoveSDK, face-tec, ML Kit); symbols must come from those vendors. |
| Fewer supported devices | `minSdk 24` is unchanged, so the delta comes from the newer toolchain. Review the device list before rollout. |

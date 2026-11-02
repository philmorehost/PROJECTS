# DG7 (DataGifting) - upload this build

Package `com.datagifting.app` | versionCode **8** | versionName **1.0.7**

## Why 8 and not 7

Version code **7 was already consumed** by the first upload. Play accepted the bundle, then blocked
the release with *"Your app does not support 16 KB memory page sizes"*. You cannot reuse a version
code once uploaded, so the corrected build is **8**.

## Upload

Test and release -> Testing -> Internal testing -> Create new release -> upload:

    DG7-1.0.7-vc8-release.aab      38.1 MB

The `.apk` is for sideloading only; Play accepts `.aab`.

## What changed since the blocked upload

* AGP 8.1.1 -> 8.7.3, plus `packaging { jniLibs { useLegacyPackaging = false } }`.
  All native libraries are now uncompressed and 16 KB aligned.
* `zipalign -c -P 16` now reports **Verification successful**.
* Signing key unchanged: SHA-1 `1E:92:6C:07:B9:81:A7:31:4F:F6:BE:03:87:91:C9:F9:95:57:40:0E`.

Verify before uploading:

    ..\tools\verify-play-signing.ps1 -Apk .\DG7-1.0.7-vc8-release.apk

## Remaining warnings (non-blocking)

* **Fewer supported devices** - review the list before rollout; `minSdk 24` is unchanged, so the
  delta comes from the newer toolchain.
* **No deobfuscation file** - expected, `minifyEnabled false` for DG7.
* **No native debug symbols** - the `.so` files come from vendor AARs (VoveSDK, face-tec, ML Kit).

See `..\PLAY_RELEASE_ERRORS.md` for details.

## Checksums

    DG7-1.0.7-vc8-release.aab  15D5E5E1DF13CD80891DF91F568048199871010F3EA9C246B0E0C0FEB76537E9
    DG7-1.0.7-vc8-release.apk  5B020A840EAFBC3B299218CA755A5A5EC7E3D1051C939C5033AAD0B0BC03F4D7
# JIKABIZ - upload this build

Package `com.jikabiz.app` | versionCode **7** | versionName **1.0.6**

## Signing

This bundle is signed with a newly generated key (no original JIKABIZ keystore existed in either
tree), so Play will reject the first upload until that key is registered:

SHA-1 `3F:2C:FB:5A:A1:D9:67:7A:5E:76:7B:B1:10:14:11:EA:81:E6:87:46`

1. Setup -> App integrity -> App signing (or search `app signing`)
2. **Upload key -> Request upload key reset** -> reason "I lost my upload key"
3. Upload `C:\Users\User\play-signing-keys\jikabiz-upload-cert.der`
   (fallback: `jikabiz-upload-cert.pem`)
4. Wait for Google's confirmation email
5. Upload `JIKABIZ-1.0.6-vc7-release.aab` (38 MB)

Alternatively, try uploading first: if Play reports an *expected* fingerprint you can match with a
keystore you already have, use that key instead of resetting.

## 16 KB page size - already fixed

Built with AGP 8.7.3 and `useLegacyPackaging = false`; `zipalign -c -P 16` reports
**Verification successful**. No 16 KB error will occur.

## Remaining warnings (non-blocking)

* **Fewer supported devices** - review before rollout; `minSdk 24` unchanged.
* **No deobfuscation file** - expected, `minifyEnabled false` for JIKABIZ.
* **No native debug symbols** - `.so` files come from vendor AARs.

See `..\PLAY_RELEASE_ERRORS.md`.

## Verify

    ..\tools\verify-play-signing.ps1 -Apk .\JIKABIZ-1.0.6-vc7-release.apk

## Checksums

    JIKABIZ-1.0.6-vc7-release.aab  EA7C6B494858731F6720CBF984713AD5CAF2F5E57250323D84F424A924BEF9B6
    JIKABIZ-1.0.6-vc7-release.apk  B8651272969503E0AD5209BED03555E1C947FDB6E9BB8B4353639E0D5E16D58A
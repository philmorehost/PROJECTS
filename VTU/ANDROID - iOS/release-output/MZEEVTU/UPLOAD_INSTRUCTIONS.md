# MZEEVTU - upload this build

Package `com.mzeevtu.apprelease` | versionCode **13** | versionName **1.0.12**

## Signing - reset required

Play rejected the first upload: expected `84:98:37:AD:84:F9:17:D8:E7:4C:46:0D:D8:72:58:61:D5:5A:3B:7D`,
received `4B:9C:00:06:2D:2B:D5:8D:82:E0:6D:5B:BB:1A:B3:56:59:D2:56:D9`. The original private key is
not on this machine (searched by filename, JKS magic bytes, alias text, all git history, Recycle Bin,
archives, OneDrive), so a reset is the only route.

1. Setup -> App integrity -> App signing (or search `app signing`)
2. **Upload key -> Request upload key reset** -> reason "I lost my upload key"
3. Upload `C:\Users\User\play-signing-keys\mzeevtu-upload-cert.der`
   (fallback: `mzeevtu-upload-cert.pem`)
4. Wait for Google's confirmation email
5. Upload `MZEEVTU-1.0.12-vc13-release.aab` (35.7 MB) - no rebuild needed

## 16 KB page size - already fixed

`zipalign -c -P 16` reports **Verification successful**.

## Deobfuscation file - upload this

MZEEVTU uses R8, so upload the mapping file with the release:

    mapping\mapping-vc13.txt

Play Console -> the release -> **App bundle explorer** -> the version -> Downloads ->
*Deobfuscation file*. Keep it: it is only valid for this exact build.

## Remaining warnings (non-blocking)

* **Fewer supported devices** - review before rollout.
* **No native debug symbols** - `.so` files come from vendor AARs (VoveSDK, face-tec, ML Kit).

See `..\PLAY_RELEASE_ERRORS.md`.

## Verify

    ..\tools\verify-play-signing.ps1 -Apk .\MZEEVTU-1.0.12-vc13-release.apk

## Checksums

    MZEEVTU-1.0.12-vc13-release.aab   4AE67A4882DA2957F5F3BDBD8B006435D53521426AA3D43BB205D5A8806F65F7
    MZEEVTU-1.0.12-vc13-release.apk   25319165086AA2F6F4B3E35DA892D55EFD402A60D4D291EBF702931F2B04CD06
    mapping\mapping-vc13.txt          7C48E99B674DE302D8B00C4E1F9E6F541C7A1F9A6BF629E2D305C10BAFC69987
# MZEEVTU Android App

The `MZEEVTU` folder contains the professional source code for the MZEEVTU VTU and Digital Banking mobile application, rebranded from DGV6.7.

## Branding & Configuration
- **App Name:** MZEEVTU
- **Base URL:** https://app.mzeevtu.com/
- **App Logo:** `logo.jpg` → compiled into `drawable/logo.png` and all mipmap densities

## Key Technical Features
1. **Architecture:** Clean Architecture with MVVM Pattern.
2. **Language:** 100% Kotlin.
3. **UI/UX Style:** Futuristic Glassmorphism with Material Design 3.
4. **Networking:** Retrofit 2 + OkHttp 4 (with JSON Interceptors).
5. **Image Processing:** Glide for dynamic logo fetching.
6. **Persistence:** EncryptedSharedPreferences for secure API key storage.

## Signing Configuration
The release keystore is stored at `keystore/mzeevtu-release.jks`.

**For local builds**, create `keystore/keystore.properties` (gitignored):
```
STORE_PASSWORD=<your-store-password>
KEY_ALIAS=philmore-mzeevtu
KEY_PASSWORD=<your-key-password>
```

**For CI (GitHub Actions)**, add the following repository secrets:
- `MZEEVTU_STORE_PASSWORD` — keystore/store password
- `MZEEVTU_KEY_PASSWORD` — key password

The base64-encoded keystore is also stored at `keystore/mzeevtu-release.b64` for reference.

## Building

### GitHub Actions (Recommended)
Push to the branch — the **MZEEVTU Android Build** workflow triggers automatically.
Download the signed APK and AAB from the **Actions → Artifacts** tab.

### Android Studio (Local)
1. Import this folder into **Android Studio (Flamingo or later)**.
2. Create `keystore/keystore.properties` as shown above.
3. Click **Build → Build Bundle/APK → Build APK(s)** for APK, or **Build → Generate Signed Bundle/APK** for AAB.

## Folder Structure
- `api/`: Retrofit interfaces for all endpoints.
- `data/`: Data models and repositories.
- `ui/`: Fragments and ViewModels for each feature (Auth, Dashboard, Cards, Gift Cards).
- `util/`: Helper classes for currency formatting, validators, and theme management.
- `keystore/`: Release keystore and signing configuration template.

## Screen Descriptions
- **Home Dashboard:** Shows real-time balance, quick action buttons, and transaction summaries.
- **Virtual Cards:** Interactive card carousel with flip animation and balance display.
- **Gift Card Store:** Categorized grid of 2,000+ brands with real-time conversion rates.
- **Service Hub:** Unified screen for Airtime, Data, Cable, and Utility payments.


# JIKABIZ Mobile App (Android)

The `JIKABIZ-Android` folder contains the source code for the JIKABIZ VTU and Digital Banking mobile application (package `com.jikabiz.app`).

## Key Technical Features
1. **Architecture:** Clean Architecture with MVVM Pattern.
2. **Language:** 100% Kotlin.
3. **UI/UX Style:** Futuristic Glassmorphism with Material Design 3.
4. **Networking:** Retrofit 2 + OkHttp 4 (with JSON Interceptors).
5. **Image Processing:** Glide for dynamic logo fetching.
6. **Persistence:** EncryptedSharedPreferences for secure API key storage.

## Folder Structure
- `api/`: Retrofit interfaces for all endpoints.
- `data/`: Data models and repositories.
- `ui/`: Fragments and ViewModels for each feature (Auth, Dashboard, Cards, Gift Cards).
- `util/`: Helper classes for currency formatting, validators, and theme management.

## Compilation Guide
1. Import this folder into **Android Studio (Flamingo or later)**.
2. In `app/src/main/java/com/jikabiz/app/util/Constants.kt`, update the `BASE_URL` to point to your vendor domain.
3. **App Icons & Branding:**
   - **App Icon:** still the template artwork. Either right-click the `app` folder -> `New` -> `Image Asset`
     in Android Studio and pick the JIKABIZ logo, or drop the PNGs into `app/src/main/res/mipmap-*`
     (`ic_launcher.png` / `ic_launcher_round.png`) and refresh the adaptive icon at
     `app/src/main/res/mipmap-anydpi-v26/` + `app/src/main/res/drawable-v24/ic_launcher_foreground.xml`.
   - **Splash:** `app/src/main/res/drawable/splash_logo.xml` (the vector/bitmap the splash layout draws)
     and `app/src/main/res/drawable/logo.png` (in-app logo fallback).
   - The app also fetches `logo_url`, `site_title` and `primary_color` from the API at runtime, so
     set those in the JIKABIZ DGV7 admin or the header will look unbranded.
4. **Release signing:** `app/build.gradle` reads `key.properties` (gitignored) for
   `storeFile` / `storePassword` / `keyAlias` / `keyPassword`, and the release build type is wired to
   that `signingConfig` on purpose - a debug-signed AAB is rejected by Play. The keystore and its
   password are NOT in version control; keep them in a password manager. Losing the keystore means
   you can never update this app listing again.
5. **Local Build:** **Build -> Build Bundle/APK -> Build APK(s)**.

> No GitHub Actions workflow ships with this project - the `README` used to claim one. Add
> `.github/workflows/` yourself if you want CI builds.

## Screen Descriptions
- **Home Dashboard:** Shows real-time balance, quick action buttons, and transaction summaries.
- **Virtual Cards:** Interactive card carousel with flip animation and balance display.
- **Gift Card Store:** Categorized grid of 2,000+ brands with real-time conversion rates.
- **Service Hub:** Unified screen for Airtime, Data, Cable, and Utility payments.

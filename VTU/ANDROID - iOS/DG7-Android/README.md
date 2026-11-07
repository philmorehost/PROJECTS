# Full Fintech Mobile App Architecture (DGV6.7)

The `DG6-Android` folder contains the professional source code for a modern VTU and Digital Banking mobile application.

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
2. In `app/src/main/java/com/dgv6/app/util/Constants.kt`, update the `BASE_URL` to point to your vendor domain.
3. **App Icons & Branding:**
   - **App Icon:** Right-click on the `app` folder -> `New` -> `Image Asset`. Choose your logo file and Android Studio will generate all sizes automatically.
   - **Splash Screen:** Replace `app/src/main/res/drawable/splash_logo.png` with your logo.
4. **Automated Build (GitHub):**
   - Push this folder to your GitHub repository.
   - Go to the **Actions** tab on GitHub.
   - The "Android CI" workflow will start automatically.
   - Once finished, you can download the ready-to-install **APK** from the build artifacts.
5. **Local Build:** Click **Build -> Build Bundle/APK -> Build APK(s)**.

## Screen Descriptions
- **Home Dashboard:** Shows real-time balance, quick action buttons, and transaction summaries.
- **Virtual Cards:** Interactive card carousel with flip animation and balance display.
- **Gift Card Store:** Categorized grid of 2,000+ brands with real-time conversion rates.
- **Service Hub:** Unified screen for Airtime, Data, Cable, and Utility payments.

# Mobile App API Specification (Fintech Style)

This document provides the technical requirements and API specifications for developing the VTU Mobile APK.

## 1. Professional App Distribution Models

### A. Branded Build (Highly Recommended)
This provides the most professional user experience. Each vendor (bc-admin) receives an APK/AAB specifically built for their domain.
- **Implementation:** Hardcode the `BASE_URL` in the app's network configuration (e.g., `Constants.BASE_URL = "https://vendor-domain.com/";`).
- **User Experience:** The user downloads the app, opens it, and immediately sees the vendor's login screen. No "Enter Domain" prompt is shown.
- **Dynamic Themes:** Even with a hardcoded URL, the app **must** call `/web/api/site-info.php` on every launch to fetch and apply the latest primary colors, logos, and support contact details configured in the dashboard.

### B. "Save-Once" Universal Build
If you distribute a single APK for all vendors:
- **Implementation:** On the very first launch, show a professional "Platform Setup" screen.
- **Permanence:** Once the user enters their domain, save it in `EncryptedSharedPreferences`.
- **Logic:** In `MainActivity.onCreate()`, check if the domain is saved. If yes, skip the setup screen and go straight to Login/Home. The user only enters the domain **once in the app's lifetime**.
- **Branding:** Immediately after the domain is saved, call `/web/api/site-info.php` to theme the app.

- **Design Requirements (Futuristic UI/UX)**
- **Theme:** "Crystal Glassmorphism" (Blur effects, semi-transparent overlays).
- **Architecture:** MVVM with real-time UI state observers.
- **Micro-Interactions:** Shimmer loading effects, haptic feedback on success, 3D card flips.
- **Color Palette:** Deep Indigo (`#1e3c72`), Neon Cyan (`#00f2fe`), and High-Contrast White.
- **Navigation:** Floating Bottom Nav with animated Lottie icons.

## 2. Authentication Endpoints

### Login
- **URL:** `/web/api/login.php`
- **Method:** `POST`
- **Payload:**
  ```json
  {
    "user": "username",
    "pass": "password"
  }
  ```
- **Response (Success):**
  ```json
  {
    "status": "success",
    "message": "Login successful",
    "data": {
      "username": "johndoe",
      "firstname": "John",
      "lastname": "Doe",
      "api_key": "SECURE_API_KEY_HERE",
      "balance": "5000.00",
      "account_level": "1",
      "email": "user@example.com",
      "phone": "08012345678",
      "kyc_verified": "Yes",
      "kyc_status": 2
    }
  }
  ```

### Registration
- **URL:** `/web/api/register.php`
- **Method:** `POST`
- **Payload:**
  ```json
  {
    "user": "username",
    "pass": "password",
    "first": "Firstname",
    "last": "Lastname",
    "email": "user@example.com",
    "phone": "08012345678",
    "address": "123 Street Address",
    "referral": "optional_referrer"
  }
  ```

## 3. Core Services API

### Fetch Services & Plans
- **URL:** `/web/api/services.php`
- **Method:** `POST`
- **Payload:** `{"api_key": "YOUR_API_KEY"}`
- **Usage:** Call this on App launch or Refresh to populate the dashboard and plan selectors.

### Purchase Airtime (Supports Bulk & Background)
- **URL:** `/web/api/airtime.php`
- **Payload:**
  ```json
  {
    "api_key": "YOUR_API_KEY",
    "network": "mtn",
    "amount": "500",
    "phone_no": "08012345678"
  }
  ```

### Share Fund (Inter-wallet transfer)
- **URL:** `/web/api/share-fund.php`
- **Payload:**
  ```json
  {
    "api_key": "YOUR_API_KEY",
    "user": "recipient_username",
    "amount": "1000",
    "pin": "1234"
  }
  ```
- **Note:** `pin` (4-digit Transaction PIN) is mandatory.

### Wallet Withdrawal (to Bank)
- **URL:** `/web/api/withdrawal.php`
- **Payload:**
  ```json
  {
    "api_key": "YOUR_API_KEY",
    "bank_code": "011",
    "account_number": "3012345678",
    "amount": "5000",
    "pin": "1234"
  }
  ```
- **Note:** `pin` (4-digit Transaction PIN) is mandatory for withdrawals.
- **Professional Background Processing:**
  - For single numbers, the response is instant.
  - For multiple numbers (comma-separated), the server will process them in a loop.
  - **App Recommendation:** The App should first call `/web/api/identify-network.php` to detect the ISP for each number. Then, it can either send a bulk string to `/web/api/airtime.php` OR send individual requests in a background thread/WorkManager.
  - **Batch Tracking:** If the server receives a bulk string, it returns a `batch_number`. The user can view the progress in the "Bulk Batch" section of the Dashboard.

### Server-Side Phonebook (Contacts)

- **URL:** `/web/api/contacts.php`
- **Actions:**
  - **List:** `{"api_key": "...", "action": "list"}`
  - **Add:** `{"api_key": "...", "action": "add", "name": "John", "phone": "0801"}`
  - **Delete:** `{"api_key": "...", "action": "delete", "id": 123}`

### Bulk SMS

#### Submit New Sender ID for Review
- **URL:** `/web/api/submit-sender-id.php`
- **Payload:** `{"api_key": "...", "sender_id": "BRAND", "sample_message": "Hello world"}`

#### Fetch Approved Sender IDs
- **URL:** `/web/api/sms-sender-ids.php`
- **Payload:** `{"api_key": "YOUR_API_KEY"}`
- **Response:**
  ```json
  {
    "status": "success",
    "data": [
      {"sender_id": "DG-V6", "status": "Approved"},
      {"sender_id": "TEST", "status": "Pending"}
    ]
  }
  ```

#### Send Bulk SMS
- **URL:** `/web/api/sms.php`
- **Payload:**
  ```json
  {
    "api_key": "YOUR_API_KEY",
    "network": "mtn",
    "phone_number": "08012345678,08098765432",
    "sender_id": "DG-V6",
    "message": "Hello from APP",
    "type": "plain",
    "date": "2024-12-25 10:00:00"
  }
  ```
- **Scheduled Delivery:** Use the `date` parameter (YYYY-MM-DD HH:MM:SS) to schedule SMS for later.
- **Multiple Recipients:** The App should allow users to select multiple contacts from their phonebook and automatically join them with commas for the `phone_number` field.

### Purchase Data (Supports Bulk & Background)
- **URL:** `/web/api/data.php`
- **Payload:**
  ```json
  {
    "api_key": "YOUR_API_KEY",
    "network": "mtn",
    "data_type": "sme-data",
    "plan_code": "1000",
    "phone_no": "08012345678"
  }
  ```
- **Bulk Data:** Supports comma-separated numbers. User must ensure all numbers in a single bulk request belong to the same network.

### Gift Card Hub (v2.0)
- **URL:** `/web/api/gift-card.php`
- **Actions:**
  - `list_products`: Fetches 2,000+ brands with categories.
  - `purchase`: vends card code instantly. (Requires `pin` and `amount`).
  - `my_cards`: retrieves user assets.
  - `p2p_market`: accesses community marketplace.

### Virtual Card Engine (Chimoney)
- **URL:** `/web/api/virtual-card.php`
- **Actions:**
  - `list_cards`: renders Glassmorphism cards with real-time balance.
  - `issue`: Creates new Visa/Mastercard ($5 min). (Requires `pin`, `amount_usd`, `card_name`).
  - `fund`: Instant top-up from NGN wallet. (Requires `pin`, `amount_usd`, `card_ref`).
  - `reveal`: Fetches full PAN/CVV. (Requires `pin`, `card_ref`).
  - `withdraw`: Liquidates balance back to NGN. (Requires `card_ref`).

### Real-Time Security & Utility

#### Check Daily Purchase Limit
- **URL:** `/web/api/check-limit.php`
- **Payload:** `{"api_key": "...", "type": "airtime", "id": "08012345678"}`
- **Usage:** Call this immediately after the user enters a Phone/Meter/IUC number. If `limit_reached` is `true`, the App **MUST** disable the "Buy" button and show the `message` to the user to prevent transaction failure.

#### Identify Network Carrier
- **URL:** `/web/api/identify-network.php`
- **Payload:** `{"phone": "08012345678"}`

### Verify Utility (Cable/Electric/Betting)
- **URLs:** `/web/api/verify-cable.php`, `/web/api/verify-electric.php`, `/web/api/verify-betting.php`
- **Usage:** Must be called before purchase to validate the ID and display Account Holder Name.

### Requery Transaction
- **URL:** `/web/api/requery.php`
- **Payload:** `{"api_key": "YOUR_API_KEY", "reference": "TRANSACTION_REF"}`

### Batch Transaction Status (Background tracking)
- **URL:** `/web/api/batch-status.php`
- **Payload:** `{"api_key": "YOUR_API_KEY", "batch_number": "123456"}`
- **Usage:** Call this to monitor the individual statuses of a bulk/background transaction.

### Add Fund (Wallet Funding)

#### Fetch Virtual Banks (Automated Funding)
- **URL:** `/web/api/virtual-banks.php`
- **Payload:** `{"api_key": "YOUR_API_KEY"}`
- **Response:** Returns dedicated accounts from Monnify, Paystack, Payvessel, and PayHub.

#### Fetch Configuration
- **URL:** `/web/api/funding-config.php`
- **Payload:** `{"api_key": "YOUR_API_KEY"}`
- **Response:** Returns active gateways, public keys, and contract codes.

#### Fetch Gateway Charges
- **URL:** `/web/api/get-charges.php`
- **Payload:** `{"api_key": "YOUR_API_KEY"}`
- **Response:** `{"status": "success", "data": {"monnify": 1.5, "paystack": 2.0, ...}}`

#### Initialize Checkout
- **URL:** `/web/api/create-checkout.php`
- **Payload:** `{"api_key": "YOUR_API_KEY", "reference": "REF", "amount": 500}`
- **Usage:** Call this BEFORE launching the Monnify/Paystack SDK. This registers the transaction for webhook processing.

#### Fetch Platform Bank Details (Manual Funding)
- **URL:** `/web/api/platform-banks.php`
- **Payload:** `{"api_key": "YOUR_API_KEY"}`
- **Response:** Returns a list of banks and min/max limits for manual deposit notifications.

#### Manual Notification
- **URL:** `/web/api/fund-manual.php`
- **Payload:** `{"api_key": "YOUR_API_KEY", "amount": 1000, "gateway": "Bank Name"}`

### Dynamic Branding & Support

#### Fetch Site Information
- **URL:** `/web/api/site-info.php`
- **Method:** `GET` (No Auth required)
- **Response:**
  ```json
  {
    "status": "success",
    "data": {
      "site_title": "Mega VTU",
      "logo_url": "https://domain.com/logo.png",
      "primary_color": "#287bff",
      "secondary_color": "#f6f9fc",
      "services": { "airtime": 1, "data": 1, "cable": 0 },
      "currency_symbol": "₦",
      "support": {
        "email": "support@domain.com",
        "phone": "2348012345678",
        "address": "123 Main St"
      }
    }
  }
  ```
- **Usage:** Use this to set the app theme color, toolbar logo, and populate the "Contact Support" page dynamically.

### Security PIN Management

#### Set or Update Transaction PIN
- **URL:** `/web/api/set-pin.php`
- **Method:** `POST`
- **Payload:**
  ```json
  {
    "api_key": "YOUR_API_KEY",
    "pin": "1234"
  }
  ```
- **Requirements:** PIN must be exactly 4 digits and numeric.

## 4. Webhooks & Callbacks
The App should support standard JSON webhooks for payment notifications if using automated funding gateways.
- **Monnify:** `/users-monnify.php`
- **Paystack:** `/users-paystack.php`
- **Flutterwave:** `/web/api/flutterwave_webhook.php`
- **PayHub:** `/users-payhub.php`

## 5. Security Features
- **Anti-BruteForce:** The API enforces strict account/IP locking on failed attempts.
- **Transaction PIN:** While not mandatory for per-transaction API authorization, the 4-digit PIN is required for specific high-security actions (like Share Fund) and for account lockout resolution.
- **KYC:** Identity verification is disabled/hardcoded as "Verified" across the platform. Developers should not implement KYC submission flows.
- **SSL:** All requests must be over HTTPS.

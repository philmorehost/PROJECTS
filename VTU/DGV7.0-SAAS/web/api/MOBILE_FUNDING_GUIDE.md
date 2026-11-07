# Mobile App Funding Implementation Guide

To ensure users can fund their wallets via the Android APK (developed with Gemini/Firebase), follow this implementation flow.

## 1. Fetch Funding Configuration
Call `GET /web/api/funding-config.php?api_key=USER_API_KEY`

**Response will include:**
- `vendor_id`: Required for checkout logging.
- `username`, `email`, `name`: For Gateway customer details.
- `gateways`: Array of active gateways (Monnify, Paystack, etc.) with their `public_key` and `contract_code`.

## 2. Fetch Gateway Charges
Call `GET /web/api/get-charges.php?api_key=USER_API_KEY`
Returns a dictionary of percentage charges for each gateway (e.g., `{"monnify": 1.5, "paystack": 2.0}`). Use this to inform the user about potential fees before they pay.

## 3. Initialize Checkout (CRITICAL)
Before invoking any Payment SDK (Monnify, Paystack, etc.), you **MUST** register the transaction on our server. This ensures the asynchronous webhook can correctly credit the wallet.

**Call:** `POST /web/api/create-checkout.php`
**Body (JSON):**
```json
{
  "api_key": "USER_API_KEY",
  "reference": "YOUR_UNIQUE_TRANSACTION_REF",
  "amount": 500.00
}
```

## 4. Launch Payment SDK
Once the server returns `success`, proceed to launch the SDK.

### Monnify Android SDK
- **apiKey**: From `funding-config.php`
- **contractCode**: From `funding-config.php`
- **paymentReference**: The same reference used in Step 2.
- **customerName/Email**: From `funding-config.php`.

### Paystack Android SDK
- **PublicKey**: From `funding-config.php`.
- **Reference**: The same reference used in Step 2.

## 5. Virtual Bank Funding
To display permanent virtual accounts for automated bank transfers:
**Call:** `GET /web/api/virtual-banks.php?api_key=USER_API_KEY`
Display the returned array of banks (`bank_name`, `account_number`, `account_name`).

## 6. Platform Bank Details (for Manual Funding)
Call `GET /web/api/platform-banks.php?api_key=USER_API_KEY`
Returns the list of the platform's own bank accounts (`bank_name`, `account_number`, `account_name`) and the allowed `min`/`max` limits for manual funding.

## 7. Manual Funding Notification
If the user pays via a gateway not supported by an SDK, they can notify the admin:
**Call:** `POST /web/api/fund-manual.php`
**Body:**
```json
{
  "api_key": "USER_API_KEY",
  "amount": 1000,
  "gateway": "Bank Name / Transfer Method"
}
```

## Summary for Gemini
"Provide the USER_API_KEY and the BASE_URL to these endpoints. Use Retrofit for network calls. Use the Monnify/Paystack official Android SDKs for the actual payment UI. Always call `create-checkout.php` before the SDK to ensure webhook compatibility."
